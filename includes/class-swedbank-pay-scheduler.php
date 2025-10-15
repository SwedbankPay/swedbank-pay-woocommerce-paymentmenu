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
	 * Logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;


	/**
	 * Singleton instance of the class.
	 *
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
	 * Class constructor.
	 */
	public function __construct() {
		$this->logger = \wc_get_logger();

		add_action( self::ACTION_ID, array( $this, 'run' ), 10, 2 );
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
		$this->log( sprintf( '[SCHEDULER]: Start task: %s', wp_json_encode( array( $payment_method_id, $webhook_data ) ) ) );

		try {
			$data = json_decode( $webhook_data, true );
			if ( empty( $data ) ) {
				throw new \WP_Exception( 'Invalid webhook data' );
			}

			if ( ! isset( $data['paymentOrder']['id'] ) ) {
				throw new \WP_Exception( 'Error: Invalid paymentOrder value' );
			}

			if ( ! isset( $data['transaction']['number'] ) ) {
				throw new \WP_Exception( 'Error: Invalid transaction number' );
			}

			$transaction_number = $data['transaction']['number'];
			$payment_order_id   = $data['paymentOrder']['id'];

			// Get Order by Payment Order Id.
			$order = swedbank_pay_get_order( $payment_order_id );
			if ( ! $order ) {
				throw new \WP_Exception( "Failed to find order: $payment_order_id" );
			} else {
				$this->log( "[SCHEDULER]: Found order {$order->get_id()} by payment Order ID $payment_method_id." );
				// If the orderReference is provided, validate it matches the order ID.
				if ( isset( $data['orderReference'] ) ) {
					$order_reference = intval( $data['orderReference'] );

					if ( empty( $order_reference ) || ( $order->get_id() !== $order_reference ) ) {
						throw new \WP_Exception( "[SCHEDULER]: Order ID mismatch: received {$order_reference}, expected {$order->get_id()}" );
					}
				}
			}

			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				throw new \WP_Exception( "Cannot retrieve payment gateway instance: $payment_method_id" );
			}

			if ( ! property_exists( $gateway, 'api' ) ||
				! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
				$this->log( "[ERROR]: Order {$order->get_id()} has not been paid with the swedbank pay. Payment method: {$order->get_payment_method()}" );

				return false;
			}

			$transactions = (array) $order->get_meta( '_swedbank_pay_transactions' );
			if ( in_array( $transaction_number, $transactions, true ) ) {
				$this->log( "[SCHEDULER]: Transaction $transaction_number was processed before." );
				return false;
			}
		} catch ( \WP_Exception $e ) {
			$this->log( "[ERROR]: Validation error: {$e->getMessage()}" );
			return false;
		}

		// @todo Use https://developer.swedbankpay.com/checkout-v3/features/core/callback
		$result = $gateway->api->finalize_payment( $order, $transaction_number );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			$this->log( "[ERROR]: {$result->get_error_message()}" );
			return false;
		}

		do_action( 'swedbank_pay_scheduler_run_after', $order, $gateway, $webhook_data );

		return false;
	}
}
