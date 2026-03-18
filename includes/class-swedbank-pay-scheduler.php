<?php
/**
 * Swedbank Pay Scheduler Class.
 * Handles scheduled tasks for Swedbank Pay.
 *
 * @package SwedbankPay\Checkout\WooCommerce
 */

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;


/**
 * Swedbank_Pay_Scheduler Class.
 */
class Swedbank_Pay_Scheduler {
	public const ACTION_ID = 'swedbank_pay_scheduler_run';

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
		add_action( self::ACTION_ID, array( $this, 'run' ), 10, 2 );
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
		$context = array(
			'payment_method_id' => $payment_method_id,
			'webhook_data'      => $webhook_data,
		);

		Swedbank_Pay()->logger()->info( sprintf( '[SCHEDULER]: Start task: %s', wp_json_encode( array( $payment_method_id, $webhook_data ) ) ), $context );

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

			$transaction_number          = $data['transaction']['number'];
			$payment_order_id            = $data['paymentOrder']['id'];
			$context['transaction_id']   = $transaction_number;
			$context['payment_order_id'] = $payment_order_id;

			if ( isset( $data['orderReference'] ) ) {
				// Use the order reference for quicker lookup.
				$order = wc_get_order( $data['orderReference'] );
				if ( $order->get_meta( '_payex_paymentorder_id' ) !== $payment_order_id ) {

					// Fallback to payment order ID if the order reference does not match.
					$order = swedbank_pay_get_order( $payment_order_id );
					if ( ! $order ) {
						Swedbank_Pay()->logger()->error( "[SCHEDULER]: Failed to find order with order reference: {$data['orderReference']} and payment order ID: $payment_order_id", $context );
						throw new \Exception( "[SCHEDULER]: Failed to find order with payment order ID: $payment_order_id" );
					}
				}
			} else {
				$order = swedbank_pay_get_order( $payment_order_id );
				if ( ! $order ) {
					throw new \Exception( "[SCHEDULER]: Failed to find order with payment order ID: $payment_order_id" );
				}
			}

			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				throw new \WP_Exception( "Cannot retrieve payment gateway instance: $payment_method_id" );
			}

			$context['order_id']     = $order->get_id();
			$context['order_number'] = $order->get_order_number();

			if ( ! property_exists( $gateway, 'api' ) ||
				! swedbank_pay_is_payment_swedbank_method( $order->get_payment_method() )
			) {
				Swedbank_Pay()->logger()->error( "[SCHEDULER]: Order #{$order->get_order_number()} has not been paid with the swedbank pay. Payment method: {$order->get_payment_method()}", $context );
				return false;
			}

			$transactions = (array) $order->get_meta( '_swedbank_pay_transactions' );
			if ( in_array( $transaction_number, $transactions, true ) ) {
				Swedbank_Pay()->logger()->info( "[SCHEDULER]: The order #{$order->get_order_number()} with transaction ID #{$transaction_number} has already been processed.", $context );
				return false;
			}
		} catch ( \WP_Exception $e ) {
			$context['error'] = $e->getMessage();
			Swedbank_Pay()->logger()->error( "[SCHEDULER]: Validation error: {$e->getMessage()}", $context );
			return false;
		}

		// @todo Use https://developer.swedbankpay.com/checkout-v3/features/core/callback
		Swedbank_Pay()->logger()->info( "[SCHEDULER]: Attempting to finalize payment for order #{$context['order_number']} with transaction ID #{$context['transaction_id']}.", $context );
		$result = $gateway->api->finalize_payment( $order, $transaction_number );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			$context['error'] = join( '; ', $result->get_error_messages() );
			Swedbank_Pay()->logger()->error( '[SCHEDULER]: Failed to finalize payment.', $context );
			return false;
		}

		do_action( 'swedbank_pay_scheduler_run_after', $order, $gateway, $webhook_data );

		Swedbank_Pay()->logger()->info( "[SCHEDULER]: Successfully processed payment for order #{$order->get_order_number()} with transaction ID #{$transaction_number}.", $context );
		return false;
	}
}
