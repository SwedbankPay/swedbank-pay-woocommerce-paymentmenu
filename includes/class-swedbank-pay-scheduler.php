<?php
/**
 * Swedbank Pay Scheduler Class.
 * Handles scheduled tasks for Swedbank Pay.
 *
 * @package SwedbankPay\Checkout\WooCommerce
 */

namespace SwedbankPay\Checkout\WooCommerce;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\V3\Resource\Response\CallbackPayload;

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
	 * @throws \WP_Exception If there is an error with the payment gateway or if the payment cannot be finalized.
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
			try {
				$payload = new CallbackPayload( $webhook_data );
			} catch ( \Throwable $e ) {
				throw new \WP_Exception( 'Invalid webhook data' );
			}

			$payment_order = $payload->getPaymentOrder();
			if ( ! $payment_order || ! $payment_order->getId() ) {
				throw new \WP_Exception( 'Error: Invalid paymentOrder value' );
			}

			$payment_order_id            = $payment_order->getId();
			$payment_number              = $payment_order->getNumber();
			$order_reference             = $payload->getOrderReference();
			$context['payment_order_id'] = $payment_order_id;
			$context['payment_number']   = $payment_number;
			$context['order_reference']  = $order_reference;

			// Get the WooCommerce order using the order reference or the payment order id.
			$order = $this->get_woocommerce_order( $order_reference, $payment_order_id );

			// If the order is a refund order, skip processing.
			if ( $order instanceof \WC_Order_Refund ) {
				Swedbank_Pay()->logger()->info( "[SCHEDULER]: Callback for payment order id #{$payment_order_id} is a WooCommerce refund order. Skipping.", $context );
				return false;
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
		} catch ( \WP_Exception $e ) {
			$context['error'] = $e->getMessage();
			Swedbank_Pay()->logger()->error( "[SCHEDULER]: Validation error: {$e->getMessage()}", $context );
			return false;
		}

		// v3.1 callbacks no longer carry a transaction.number; finalize_payment falls back
		// to the paymentOrder's `paid` resource to discover the right transaction.
		// process_transaction() dedupes by financial transaction id internally.
		Swedbank_Pay()->logger()->info( "[SCHEDULER]: Attempting to finalize payment for order #{$context['order_number']} with payment number #{$context['payment_number']}.", $context );
		$result = $gateway->api->finalize_payment( $order );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			$context['error'] = join( '; ', $result->get_error_messages() );
			Swedbank_Pay()->logger()->error( '[SCHEDULER]: Failed to finalize payment.', $context );
			return false;
		}

		do_action( 'swedbank_pay_scheduler_run_after', $order, $gateway, $webhook_data );

		Swedbank_Pay()->logger()->info( "[SCHEDULER]: Successfully processed payment for order #{$order->get_order_number()} with payment number #{$context['payment_number']}.", $context );
		return false;
	}

	/**
	 * Try to get the WooCommerce order using the order reference or the payment order id.
	 *
	 * @param string $order_reference The order reference to find the order by.
	 * @param string $payment_order_id The payment order ID to find the order by if the order was not found by the order reference.
	 *
	 * @throws \WP_Exception
	 *
	 * @return \WC_Order|\WC_Order_Refund
	 */
	private function get_woocommerce_order( $order_reference, $payment_order_id ) {
		$order = null;

		// If we have an order reference, try to find the order by the order reference.
		$order = wc_get_order( $order_reference );

		// If we don't have an order, or the order we have does not match the payment order ID, try to find the order by payment order ID.
		if ( ! $order || $order->get_meta( '_payex_paymentorder_id' ) !== $payment_order_id ) {
			$order = swedbank_pay_get_order( $payment_order_id );
		}

		// If the order is still not found, throw an error and exit.
		if ( ! $order ) {
			throw new \WP_Exception( "[SCHEDULER]: Failed to find order with payment order ID: $payment_order_id" );
		}

		return $order;
	}
}
