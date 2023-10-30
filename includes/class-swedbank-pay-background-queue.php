<?php

namespace SwedbankPay\Checkout\WooCommerce;

use SwedbankPay\Core\Api\FinancialTransaction;
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
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		$this->logger = wc_get_logger();

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
				// Remove invalid record from the database
				// phpcs:disable
				$query = $wpdb->prepare(
					'DELETE FROM ' . esc_sql( $table ) . ' WHERE ' . esc_sql( $key_column ) . ' = %s',
					$data[$key_column]
				);

				$wpdb->query( $query );
				// phpcs:enable

				continue;
			}

			// Check the payment method ID
			if ( ! in_array( $task[0]['payment_method_id'], Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) { //phpcs:ignore
				// Try with another queue processor
				continue;
			}

			$batch       = new \stdClass();
			$batch->key  = $result->$column;
			$batch->data = $task;

			// Create Sorting Flow by Transaction Number
			$webhook             = json_decode( $task[0]['webhook_data'], true );
			$sorting_flow[ $id ] = $webhook['transaction']['number'];
			$results[ $id ]      = $batch;
		}

		// Sorting
		array_multisort( $sorting_flow, SORT_ASC, SORT_NUMERIC, $results );
		unset( $data, $sorting_flow );

		$batch = array_shift( $results ); // Get first result
		if ( ! $batch ) {
			$batch       = new \stdClass();
			$batch->key  = null;
			$batch->data = array();
		}

		return $batch;
	}

	/**
	 * Log message.
	 *
	 * @param $message
	 */
	private function log( $message ) {
		$this->logger->info( $message, array( 'source' => $this->action ) );
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
		$this->log( sprintf( 'Start task: %s', var_export( $item, true ) ) );

		try {
			$payload = $item['webhook_data'];
			$data = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw new \Exception( 'Invalid webhook data' );
			}

			if ( ! isset( $data['paymentOrder'] ) || ! isset( $data['paymentOrder']['id'] ) ) {
				throw new \Exception( 'Error: Invalid paymentOrder value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			// Get Order by Payment Order Id
			$transaction_number = $data['transaction']['number'];
			$payment_order_id   = $data['paymentOrder']['id'];
			$order              = swedbank_pay_get_order( $payment_order_id );
			if ( ! $order ) {
				throw new \Exception( sprintf( 'Error: Failed to get Order by Payment Order Id %s', $payment_order_id ) );
			}

			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				throw new \Exception(
					sprintf(
						'Can\'t retrieve payment gateway instance: %s',
						$item['payment_method_id']
					)
				);
			}

			$transactions = (array) $order->get_meta( '_swedbank_pay_transactions' );
			if ( in_array( $transaction_number, $transactions ) ) { //phpcs:ignore
				$this->log( sprintf( 'Transaction #%s was processed before.', $transaction_number ) );

				// Remove from queue
				return false;
			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR]: Validation error: %s', $e->getMessage() ) );

			// Remove from queue
			return false;
		}

		// @todo Use https://developer.swedbankpay.com/checkout-v3/features/core/callback
		// @todo Save order lines for capture / refund

		try {
			// Finalize payment
			$transactions = $gateway->core->fetchFinancialTransactionsList( $payment_order_id );
			if ( count( $transactions ) > 0 ) {
				foreach ( $transactions as $transaction ) {
					if ( $transaction->getNumber() == $transaction_number ) {
						$this->log(
							sprintf(
								'Handle Transaction #%s for Order #%s.',
								$transaction_number,
								$order->get_id()
							)
						);
						$gateway->core->processFinancialTransaction( $order->get_id(), $transaction );
					}
				}
			} else {
				// Some Authorize, Sale transaction are not in the list
				$this->log( sprintf( 'Transaction List is empty. Run failback for Transaction #%s', $transaction_number ) );
				$gateway->core->finalizePaymentOrder( $payment_order_id );
			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR]: %s', $e->getMessage() ) );

			// Remove from queue
			return false;
		}

		$this->log( sprintf( 'Transaction #%s has been processed.', $transaction_number ) );

		// Remove from queue
		return false;
	}

	/**
	 * This runs once the job has completed all items on the queue.
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();

		$this->log( 'Completed ' . $this->action . ' queue job.' );
	}

	/**
	 * Save and run queue.
	 */
	public function dispatch_queue() {
		if ( ! empty( $this->data ) ) {
			$this->save()->dispatch();
		}
	}
}
