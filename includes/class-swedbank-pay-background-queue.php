<?php

namespace SwedbankPay\Checkout\WooCommerce;

use WC_Background_Process;
use WC_Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Background_Process', false ) ) {
	include_once WC_ABSPATH . '/includes/abstracts/class-wc-background-process.php';
}

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Background_Queue extends WC_Background_Process {

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		// Uses unique prefix per blog so each blog has separate queue.
		$this->prefix = 'wp_' . get_current_blog_id();
		$this->action = 'swedbank_pay_queue';

		// Dispatch queue after shutdown.
		add_action( 'shutdown', array( $this, 'dispatch_queue' ), 100 );

		parent::__construct();
	}

	/**
	 * Schedule fallback event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event(
				time() + MINUTE_IN_SECONDS,
				$this->cron_interval_identifier,
				$this->cron_hook_identifier
			);
		}
	}

	/**
	 * Get batch.
	 *
	 * @return \stdClass Return the first batch from the queue.
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$results = array();

		// phpcs:disable
		$data = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $table ) . ' WHERE ' . esc_sql( $column ) . ' LIKE %s ORDER BY ' . esc_sql( $key_column ) . ' ASC',
				$key
			)
		); // @codingStandardsIgnoreLine.
		// phpcs:enable

		// Check the records
		$sorting_flow = array();
		foreach ( $data as $id => $result ) {
			$task = array_filter( (array) maybe_unserialize( $result->$value_column ) );
			if ( ! is_array( $task ) ||
				empty( $task[0]['webhook_data'] ) ||
				null === json_decode( $task[0]['webhook_data'], true )
			) {
				// Remove invalid record from the database.
				// phpcs:disable
				$query = $wpdb->prepare(
					'DELETE FROM ' . esc_sql( $table ) . ' WHERE ' . esc_sql( $key_column ) . ' = %s',
					$data[$key_column]
				);

				$wpdb->query( $query );
				// phpcs:enable

				continue;
			}

			// Check the payment method ID.
			if ( ! swedbank_pay_is_payment_swedbank_method( $task[0]['payment_method_id'] ) ) { //phpcs:ignore
				// Try with another queue processor.
				continue;
			}

			$batch       = new \stdClass();
			$batch->key  = $result->$column;
			$batch->data = $task;

			// Create Sorting Flow by Transaction Number.
			$webhook             = json_decode( $task[0]['webhook_data'], true );
			$sorting_flow[ $id ] = $webhook['transaction']['number'];
			$results[ $id ]      = $batch;
		}

		// Sorting
		array_multisort( $sorting_flow, SORT_ASC, SORT_NUMERIC, $results );
		unset( $data, $sorting_flow );

		$batch = array_shift( $results ); // Get first result.
		if ( ! $batch ) {
			$batch       = new \stdClass();
			$batch->key  = null;
			$batch->data = array();
		}

		return $batch;
	}

	/**
	 * Code to execute for each item in the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return mixed
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	protected function task( $item ) {
		$context = array(
			'payment_method_id' => $item['payment_method_id'],
		);

		Swedbank_Pay()->logger()->info( \sprintf( '[BQ]: Start task: %s', wp_json_encode( $item ) ), $context );

		try {
			$payload = $item['webhook_data'];
			$data    = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw new \Exception( 'Invalid webhook data' );
			}

			if ( ! isset( $data['paymentOrder'] ) || ! isset( $data['paymentOrder']['id'] ) ) {
				throw new \Exception( 'Error: Invalid paymentOrder value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			$transaction_number            = $data['transaction']['number'];
			$payment_order_id              = $data['paymentOrder']['id'];
			$context['payment_order_id']   = $payment_order_id;
			$context['transaction_number'] = $transaction_number;

			// Get order by `orderReference`.
			if ( isset( $data['orderReference'] ) ) {
				$context['order_reference'] = $data['orderReference'];

				$order = wc_get_order( $data['orderReference'] );
				if ( ! $order ) {
					Swedbank_Pay()->logger()->error( "[BQ]: Failed to find order with order reference: #{$context['order_reference']}", $context );
					throw new \Exception( "Failed to find order: {$context['order_reference']}" );
				}

				$context['order_id']     = $order->get_id();
				$context['order_number'] = $order->get_order_number();

				Swedbank_Pay()->logger()->debug( "[BQ]: Found order #{$context['order_number']} by order reference {$context['order_reference']}.", $context );

			} else {
				// Get Order by Payment Order Id.
				$order = swedbank_pay_get_order( $payment_order_id );
				if ( ! $order ) {
					throw new \Exception( "Failed to find order: {$context['payment_order_id']}" );
				}

				$context['order_id']     = $order->get_id();
				$context['order_number'] = $order->get_order_number();

				Swedbank_Pay()->logger()->debug( "[BQ]: Found order #{$context['order_number']} by payment Order ID {$context['payment_order_id']}.", $context );
			}

			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				Swedbank_Pay()->logger()->error( "[BQ]: Failed to retrieve payment gateway instance for order #{$order->get_order_number()}. Payment method: {$item['payment_method_id']}", $context );
				throw new \Exception( "Can't retrieve payment gateway instance: {$item['payment_method_id']}" );
			}

			if ( ! property_exists( $gateway, 'api' ) ||
				! swedbank_pay_is_payment_swedbank_method( $order->get_payment_method() )
			) {
					Swedbank_Pay()->logger()->error(
						"[BQ]: Order #{$context['order_number']} has not been paid with the swedbank pay. Payment method: {$order->get_payment_method()}",
						$context
					);

				// Remove from queue.
				return false;
			}

			$transactions = (array) $order->get_meta( '_swedbank_pay_transactions' );
			if ( in_array( $transaction_number, $transactions ) ) { //phpcs:ignore
				Swedbank_Pay()->logger()->info( "[BQ]: Transaction #{$context['transaction_number']} for order #{$context['order_number']} has already been processed.", $context );

				// Remove from queue.
				return false;
			}
		} catch ( \Exception $e ) {
			$context['error'] = $e->getMessage();
			Swedbank_Pay()->logger()->error( sprintf( '[BQ]: Validation error: %s', $e->getMessage() ), $context );

			// Remove from queue.
			return false;
		}

		// @todo Use https://developer.swedbankpay.com/checkout-v3/features/core/callback
		Swedbank_Pay()->logger()->info( "[BQ]: Attempting to finalize payment for order #{$context['order_number']} with transaction ID #{$context['transaction_number']}.", $context );
		$result = $gateway->api->finalize_payment( $order, $transaction_number );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			$context['error'] = join( '; ', $result->get_error_messages() );
			Swedbank_Pay()->logger()->error( "[BQ]: Failed to finalize payment for order #{$context['order_number']}", $context );

			// Remove from queue.
			return false;
		}

		Swedbank_Pay()->logger()->info( "[BQ]: Successfully finalized payment for order #{$context['order_number']} with transaction ID #{$context['transaction_number']}.", $context );
		// Remove from queue.
		return false;
	}

	/**
	 * This runs once the job has completed all items on the queue.
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();
		Swedbank_Pay()->logger()->info( "[BQ]: All items in {$this->action} queue have been processed." );
	}

	/**
	 * Save and run queue.
	 */
	public function dispatch_queue() {
		if ( ! empty( $this->data ) ) {
			$this->save();
			if ( apply_filters( 'swedbank_pay_dispatch_queue_at_shutdown', true, $this ) ) {
				$this->dispatch();
			} else {
				$this->schedule_event();
			}
		}
	}
}
