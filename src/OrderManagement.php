<?php
/**
 * Class OrderManagement
 *
 * Handles order management actions.
 */

namespace Krokedil\Swedbank\Pay;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Plugin;

/**
 * Class OrderManagement
 */
class OrderManagement {
	use Traits\Singleton;

	public const TYPE_CAPTURE      = 'Capture';
	public const TYPE_SALE         = 'Sale';
	public const TYPE_CANCELLATION = 'Cancellation';
	public const TYPE_REVERSAL     = 'Reversal';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order' ), 10, 2 );
	}

	/**
	 * Capture the order when status changes to completed.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order The WC order.
	 * @throws \Exception If payment order ID is missing or gateway not found.
	 */
	public function capture_order( $order_id, $order ) {
		$payment_method = $order->get_payment_method();
		if ( ! in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			throw new \Exception( 'Missing payment order ID' );
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			throw new \Exception( 'Swedbank Pay gateway not found' );
		}

		if ( $this->is_captured( $order ) ) {
			$order->add_order_note( __( 'Payment already captured.', 'swedbank-pay-woocommerce-checkout' ) );
			return;
		}

		$result = $gateway->api->capture_checkout( $order );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		$captured_amount = wc_price( $result['amount'] / 100, array( 'currency' => $order->get_currency() ) );

		// translators: 1: the captured amount + currency symbol.
		$order->add_order_note( sprintf( __( 'Payment captured successfully. Captured amount %s.', 'swedbank-pay-woocommerce-checkout' ), $captured_amount ) );
	}

	/**
	 * Remotely queries Swedbank Pay's system to check if the payment has been captured.
	 *
	 * @param  \WC_Order $order The order to check.
	 * @param bool      $cached Whether to check the metadata for capture before performing a remote query.
	 * @return bool True if captured, false otherwise.
	 */
	public function is_captured( $order, $cached = false ) {
		$payment_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_id ) ) {
			return false;
		}

		if ( $cached ) {
			$captured = $order->get_meta( '_swedbank_pay_captured' );
			if ( $captured ) {
				return true;
			}
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return false;
		}

		$result = $gateway->api->request( 'GET', $payment_id . '/financialtransactions' );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$past_transactions = $result['financialTransactions']['financialTransactionsList'];
		foreach ( $past_transactions as $transaction ) {
			if ( in_array( $transaction['type'], array( self::TYPE_CAPTURE, self::TYPE_SALE ) ) ) {
				$order->update_meta_data( '_swedbank_pay_captured', $transaction['updated'] );
				$order->save_meta_data();
				return true;
			}
		}

		return false;
	}
}
