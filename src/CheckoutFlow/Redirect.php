<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use Krokedil\Swedbank\Pay\Helpers\Order;
use Krokedil\Swedbank\Pay\Utility\ErrorUtility;
use Krokedil\Swedbank\Pay\Utility\LogUtility;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Data\ResponseInterface;
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
	 * @param \WC_Order   $order The WooCommerce order to be processed.
	 * @param string|null $instrument The instrument to use for the payment, e.g. 'CreditCard'. This is optional and may not be needed for all flows or gateways.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	public function process( $order, $instrument = null ) {

		if ( ! Swedbank_Pay_Subscription::is_change_payment_method() ) {
			$settled = $this->settle_previous_payment_order( $order );
			if ( null !== $settled ) {
				return $settled;
			}
		}

		$has_subscription = Swedbank_Pay_Subscription::order_has_subscription( $order );
		if ( $has_subscription || ( Swedbank_Pay_Subscription::is_change_payment_method() && $has_subscription ) ) {
			return $this->process_subscription( $order, $instrument );
		}

		if ( swedbank_pay_is_zero( $order->get_total() ) ) {
			throw new \Exception( 'Zero order is not supported.' );
		}

		// Initiate Payment Order.
		$result = $this->api->initiate_purchase( $order, $instrument );
		if ( is_wp_error( $result ) ) {
			throw new \Exception(
				esc_html( ErrorUtility::customer_message( $result, $order ) ),
				absint( $result->get_error_code() )
			);
		}

		$payment_order = $result->getResponseResource()->getPaymentOrder();

		// Save payment ID.
		$order->update_meta_data( '_payex_paymentorder_id', $payment_order->getId() );
		$order->save_meta_data();

		return $this->get_process_result( $order, $result );
	}

	/**
	 * Settle the order's previous payment order before a new one replaces it on a retry.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @throws \Exception If the previous payment order cannot be confirmed as unpaid and aborted.
	 * @return array{redirect: string, result: string}|null The result to return if it was already paid, otherwise null.
	 */
	private function settle_previous_payment_order( $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return null;
		}

		$context = array(
			'order_id'         => $order->get_id(),
			'payment_order_id' => $payment_order_id,
		);

		LogUtility::$title = "[PROCESS PAYMENT]: Get previous payment order for order #{$this->get_order_number( $order )}";
		$result            = $this->api->request( 'GET', $payment_order_id . '?$expand=payeeinfo' );
		$status            = is_wp_error( $result ) ? null : ( $result['paymentOrder']['status'] ?? null );

		$reference = is_wp_error( $result ) ? null : ( $result['paymentOrder']['payeeInfo']['orderReference'] ?? null );
		$expected  = ( new Order( $order ) )->get_payee_info()->getOrderReference();
		if ( null !== $status && (string) $expected !== (string) $reference ) {
			$context['order_reference'] = $reference;
			Swedbank_Pay()->logger()->warning( "[PROCESS PAYMENT]: Previous payment order does not belong to order #{$this->get_order_number( $order )}.", $context );
			return null;
		}

		if ( 'Paid' === $status ) {
			Swedbank_Pay()->logger()->info( "[PROCESS PAYMENT]: Previous payment order for order #{$this->get_order_number( $order )} is already paid.", $context );

			// The thank-you page finalizes the order.
			return array(
				'result'   => 'success',
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		}

		if ( in_array( $status, array( 'Aborted', 'Failed', 'Cancelled', 'Reversed' ), true ) ) {
			return null;
		}

		if ( null !== $status ) {
			$abort  = $this->api->abort_purchase( $payment_order_id );
			$status = is_wp_error( $abort ) ? $status : ( $abort['paymentOrder']['status'] ?? $status );
			if ( 'Aborted' === $status ) {
				return null;
			}
		}

		// Unknown state or the abort was refused, e.g. a payment is in progress.
		$context['status'] = $status;
		Swedbank_Pay()->logger()->warning( "[PROCESS PAYMENT]: Could not abort the previous payment order for order #{$this->get_order_number( $order )}.", $context );

		throw new \Exception( esc_html__( 'A payment for this order may still be in progress. Please wait a moment and try again.', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Process a subscription purchase.
	 *
	 * @param \WC_Order   $order The WooCommerce order to be processed.
	 * @param string|null $instrument The instrument to use for the payment, e.g. 'CreditCard'. This is optional and may not be needed for all flows or gateways.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	private function process_subscription( $order, $instrument = null ) {
		$result = swedbank_pay_is_zero( $order->get_total() ) ? Swedbank_Pay_Subscription::approve_for_renewal( $order ) : $this->api->initiate_purchase( $order, $instrument );
		if ( is_wp_error( $result ) ) {
			throw new \Exception(
				esc_html( ErrorUtility::customer_message( $result, $order ) ),
				absint( $result->get_error_code() )
			);
		}

		$payment_order = $result->getResponseResource()->getPaymentOrder();
		if ( swedbank_pay_is_zero( $order->get_total() ) ) {
			$order->add_order_note( __( 'The order was successfully verified.', 'swedbank-pay-payment-menu' ) );
			Swedbank_Pay_Subscription::set_skip_om( $order, $payment_order->getCreated() );
		} else {
			$order->add_order_note( __( 'The payment was successfully initiated.', 'swedbank-pay-payment-menu' ) );
		}

		$order->update_meta_data( '_payex_paymentorder_id', $payment_order->getId() );
		$order->save_meta_data();

		return $this->get_process_result( $order, $result );
	}

	/**
	 * Get the result to return from process_payment once the payment order is created.
	 *
	 * @param \WC_Order         $order The WooCommerce order.
	 * @param ResponseInterface $result The response from initiating the payment order.
	 *
	 * @return array{redirect: array|bool|string, result: string}
	 */
	protected function get_process_result( $order, $result ) {
		return array(
			'result'   => 'success',
			'redirect' => $result->getOperationByRel( 'redirect-checkout', 'href' ),
		);
	}

	/**
	 * Output the payment fields content for the handler.
	 *
	 * Mirrors WooCommerce's default WC_Payment_Gateway::payment_fields() behavior
	 * by rendering the gateway description, which the gateway's payment_fields()
	 * override no longer does on its own. When called from a split instrument
	 * gateway, the per-instrument description is rendered instead of the main
	 * gateway's description.
	 *
	 * @param string $gateway_id The gateway ID whose description should be rendered.
	 *
	 * @return void
	 */
	protected function payment_fields_content( $gateway_id = 'payex_checkout' ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $gateway_id ] ?? $this->gateway;

		$description = $gateway->get_description();
		if ( ! empty( $description ) ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
	}
}
