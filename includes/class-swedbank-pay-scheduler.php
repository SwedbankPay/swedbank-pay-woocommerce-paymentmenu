<?php
/**
 * Swedbank Pay Scheduler Class.
 * Handles scheduled tasks for Swedbank Pay.
 *
 * @package SwedbankPay\Checkout\WooCommerce
 */

namespace SwedbankPay\Checkout\WooCommerce;

use WC_Logger;

defined( 'ABSPATH' ) || exit;


/**
 * Swedbank_Pay_Scheduler Class.
 */
class Swedbank_Pay_Scheduler {
	public const ACTION_ID = 'swedbank_pay_scheduler_run';

	/**
	 * @var Swedbank_Pay_Scheduler
	 */
	private static $instance = null;

	/**
	 * Singleton pattern
	 *
	 * @return Swedbank_Pay_Scheduler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->logger = \wc_get_logger();

		add_action( 'swedbank_pay_scheduler_run', array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Log message.
	 *
	 * @param string $message The message to log.
	 */
	private function log( $message ) {
		$this->logger->info( $message, array( 'source' => 'swedbank_pay_queue' ) );
	}

	/**
	 * Code to execute for each item in the queue.
	 *
	 * @throws \Exception If the webhook data is invalid or if the order cannot be found.
	 *
	 * @param string $payment_method_id The payment method ID.
	 * @param string $webhook_data The webhook data in JSON format.
	 *
	 * @return false|null
	 */
	public function run( $payment_method_id, $webhook_data ) {
		$this->log( sprintf( 'Start task: %s', var_export( array( $payment_method_id, $webhook_data ), true ) ) );

		try {
			$payload = $webhook_data;
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

			$transaction_number = $data['transaction']['number'];
			$payment_order_id   = $data['paymentOrder']['id'];

			// Get order by `orderReference`.
			if ( isset( $data['orderReference'] ) ) {
				$order = wc_get_order( $data['orderReference'] );
				if ( ! $order ) {
					throw new \Exception( 'Failed to find order: ' . $data['orderReference'] );
				}

				$this->log(
					sprintf(
						'[SCHEDULER]: Found order #%s by order reference %s.',
						$order->get_id(),
						$data['orderReference']
					)
				);
			} else {
				// Get Order by Payment Order Id.
				$order = swedbank_pay_get_order( $payment_order_id );
				if ( ! $order ) {
					throw new \Exception( 'Failed to find order: ' . $payment_order_id );
				}

				$this->log( sprintf( '[SCHEDULER]: Found order #%s by payment Order ID %s.', $order->get_id(), $payment_order_id ) );
			}

			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				throw new \Exception(
					sprintf(
						'Can\'t retrieve payment gateway instance: %s',
						$payment_method_id
					)
				);
			}

			if ( ! property_exists( $gateway, 'api' ) ||
				! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
				$this->log(
					sprintf(
						'[ERROR]: Order #%s has not been paid with the swedbank pay. Payment method: %s',
						$order->get_id(),
						$order->get_payment_method()
					)
				);

				return false;
			}

			$transactions = (array) $order->get_meta( '_swedbank_pay_transactions' );
			if ( in_array( $transaction_number, $transactions, true ) ) {
				$this->log( sprintf( '[SCHEDULER]: Transaction #%s was processed before.', $transaction_number ) );
				return false;
			}
		} catch ( \Exception $e ) {
			$this->log( sprintf( '[ERROR]: Validation error: %s', $e->getMessage() ) );
			return false;
		}

		// @todo Use https://developer.swedbankpay.com/checkout-v3/features/core/callback
		$result = $gateway->api->finalize_payment( $order, $transaction_number );
		if ( is_wp_error( $result ) ) {
			$this->log( sprintf( '[ERROR]: %s', $result->get_error_message() ) );
			return false;
		}

		return false;
	}
}
