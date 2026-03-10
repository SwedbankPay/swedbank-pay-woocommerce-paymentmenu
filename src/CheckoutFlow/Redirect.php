<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class for processing the redirect checkout flow on the shortcode checkout page and pay for order pages.
 */
class Redirect extends CheckoutFlow {
	/**
	 * Process the payment for the WooCommerce order.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	public function process( $order ) {
		$has_subscription = Swedbank_Pay_Subscription::order_has_subscription( $order );
		if ( $has_subscription || ( Swedbank_Pay_Subscription::is_change_payment_method() && $has_subscription ) ) {
			return $this->process_subscription( $order );
		}

		if ( swedbank_pay_is_zero( $order->get_total() ) ) {
			throw new \Exception( 'Zero order is not supported.' );
		}

		// Initiate Payment Order.
		$result = $this->api->initiate_purchase( $order );
		if ( is_wp_error( $result ) ) {
			throw new \Exception(
				esc_html( $result->get_error_message() ?? __( 'The payment could not be initiated.', 'swedbank-pay-payment-menu' ) ),
				absint( $result->get_error_code() )
			);
		}

		$redirect_url  = $result->getOperationByRel( 'redirect-checkout', 'href' );
		$payment_order = $result->getResponseData()['payment_order'];

		// Save payment ID.
		$order->update_meta_data( '_payex_paymentorder_id', $payment_order['id'] );
		$order->save_meta_data();

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Process a subscription purchase.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	private function process_subscription( $order ) {
		$result = swedbank_pay_is_zero( $order->get_total() ) ? Swedbank_Pay_Subscription::approve_for_renewal( $order ) : $this->api->initiate_purchase( $order );
		if ( is_wp_error( $result ) ) {
			throw new \Exception(
				// translators: %s: order number.
				esc_html( sprintf( __( 'The payment change could not be initiated. Please contact store, and provide them the order number %s for more information.', 'swedbank-pay-payment-menu' ), $order->get_order_number() ) ),
				absint( $result->get_error_code() )
			);
		}

		$payment_order = $result->getResponseData()['payment_order'];
		if ( swedbank_pay_is_zero( $order->get_total() ) ) {
			$order->add_order_note( __( 'The order was successfully verified.', 'swedbank-pay-payment-menu' ) );
			Swedbank_Pay_Subscription::set_skip_om( $order, $payment_order['created'] );
		} else {
			$order->add_order_note( __( 'The payment was successfully initiated.', 'swedbank-pay-payment-menu' ) );
		}

		$order->update_meta_data( '_payex_paymentorder_id', $payment_order['id'] );
		$order->save_meta_data();

		return array(
			'result'   => 'success',
			'redirect' => $result->getOperationByRel( 'redirect-checkout', 'href' ),
		);
	}
}
