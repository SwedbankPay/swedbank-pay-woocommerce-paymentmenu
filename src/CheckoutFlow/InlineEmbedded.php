<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;
use WP_Error;

/**
 * Class for processing the inline embedded checkout flow on the shortcode checkout page.
 */
class InlineEmbedded extends CheckoutFlow {
	/**
	 * Initialize any actions or filters needed for the checkout flow.
	 *
	 * @return void
	 */
	protected function init() {
		// Create a payment from the cart contents.
		$result = $this->create_or_update_embedded_purchase();

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}

		// Get the src url for the script.
		$script_src = WC()->session->get( 'swedbank_pay_view_checkout_url' );

		if ( empty( $script_src ) ) {
			wc_add_notice( __( 'Failed to get the payment session URL.', 'swedbank-pay-woocommerce-checkout' ), 'error' );
			return;
		}

		$params = array(
			'script_debug' => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
		);
		wp_register_script( 'payex_inline_embedded_sdk', $script_src, array(), SWEDBANK_PAY_VERSION, true );
		wp_localize_script( 'payex_inline_embedded_sdk', 'swedbank_pay_params', $params );
		wp_register_script( 'payex_inline_embedded', SWEDBANK_PAY_PLUGIN_URL . '/assets/js/inline-embedded-checkout.js', array( 'jquery', 'payex_inline_embedded_sdk' ), SWEDBANK_PAY_VERSION, true );
		wp_enqueue_script( 'payex_inline_embedded' );

		// Register the action to update the embedded purchase when the cart is updated.
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'update_embedded_purchase' ) );
	}

	/**
	 * Unset all sessions related to the embedded session.
	 *
	 * @return void
	 */
	public static function unset_embedded_session_data() {
		WC()->session->__unset( 'swedbank_pay_paymentorder_id' );
		WC()->session->__unset( 'swedbank_pay_view_session_url' );
		WC()->session->__unset( 'swedbank_pay_update_order_url' );
		WC()->session->__unset( 'swedbank_pay_view_checkout_url' );
		WC()->session->__unset( 'swedbank_pay_payee_reference' );
	}

	/**
	 * Update the embedded purchase when the cart has been updated.
	 *
	 * @return void
	 */
	public function update_embedded_purchase() {
		// Only if we are on the checkout page, and not the order received page.
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		$payment_order_id = WC()->session->get( 'swedbank_pay_paymentorder_id' );

		if ( empty( $payment_order_id ) ) {
			return;
		}

		$result = $this->api->update_embedded_purchase();

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
	}

	/**
	 * Create or update embedded purchase.
	 *
	 * @return \WP_Error|Paymentorder|array
	 */
	public function create_or_update_embedded_purchase() {
		try {
			$payment_order_id = WC()->session->get( 'swedbank_pay_paymentorder_id' );
			$result           = new WP_Error( 'no_payment_order', 'Failed to get a payment.' );

			// If we have a payment order ID in the session, try to first get the session, and then update.
			if ( ! empty( $payment_order_id ) ) {

				// A verify operation cannot be updated which is the case for all zero amount order.
				if ( Swedbank_Pay_Subscription::cart_has_zero_order() ) {
					return array();
				}

				// Try to get the payment to ensure it still exists.
				$get_purchase_result = $this->api->get_embedded_purchase();
				if ( is_wp_error( $get_purchase_result ) ) {
					throw new \Exception( $result->get_error_message() );
				}

				// Update the existing payment.
				$result = $this->api->update_embedded_purchase();
				// Check for errors.
				if ( is_wp_error( $result ) ) {
					throw new \Exception( $result->get_error_message() );
				}
			} else {
				// No payment order ID in the session, create a new payment.
				$result = $this->api->initiate_embedded_purchase();

				// Check for errors.
				if ( is_wp_error( $result ) ) {
					throw new \Exception( $result->get_error_message() );
				}

				// Get the payment order data.
				$payment_order     = $result->getResponseData()['payment_order'];
				$view_session_url  = $result->getOperationByRel( 'view-paymentsession', 'href' );
				$update_order_url  = $result->getOperationByRel( 'update-order', 'href' );
				$view_checkout_url = $result->getOperationByRel( 'view-checkout', 'href' );

				// Save payment ID to the session.
				WC()->session->set( 'swedbank_pay_paymentorder_id', $payment_order['id'] );
				WC()->session->set( 'swedbank_pay_view_session_url', $view_session_url );
				WC()->session->set( 'swedbank_pay_update_order_url', $update_order_url );
				WC()->session->set( 'swedbank_pay_view_checkout_url', $view_checkout_url );
			}

			if ( is_wp_error( $result ) ) {
				throw new \Exception( $result->get_error_message() );
			}

			return $result;
		} catch ( \Exception $e ) {
			self::unset_embedded_session_data();
			return new WP_Error( 'swedbank_pay_error', $e->getMessage() );
		}
	}

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
		if ( ! $has_subscription && swedbank_pay_is_zero( $order->get_total() ) ) {
			throw new \Exception( 'Zero order is not supported.' );
		}

		// Initiate Payment Order.
		$result = $this->api->get_embedded_purchase();
		if ( is_wp_error( $result ) ) {
			throw new \Exception(
				$result->get_error_message() ?? __( 'The payment could not be initiated.', 'swedbank-pay-woocommerce-checkout' ),
				$result->get_error_code()
			);
		}

		if ( $has_subscription ) {
			$this->process_subscription( $order );
		}

		$payee_reference = WC()->session->get( 'swedbank_pay_payee_reference' );
		$payment_session = $result['paymentSession'];

		// Save payment ID and payee reference.
		$order->update_meta_data( '_payex_paymentorder_id', $payment_session['id'] );
		$order->update_meta_data( '_payex_payee_reference', $payee_reference );
		$order->save_meta_data();

		return array(
			'result'           => 'success',
			'redirect'         => '#payex_container',
			'redirect_on_paid' => $this->gateway->get_return_url( $order ),
		);
	}

	/**
	 * Process a subscription purchase.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @return void
	 */
	private function process_subscription( $order ) {
		if ( Swedbank_Pay_Subscription::cart_has_zero_order() ) {
			$order->add_order_note( __( 'The order was successfully verified.', 'swedbank-pay-woocommerce-checkout' ) );
			Swedbank_Pay_Subscription::set_skip_om( $order, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		} else {
			$order->add_order_note( __( 'The payment was successfully initiated.', 'swedbank-pay-woocommerce-checkout' ) );
		}
	}

	/**
	 * Output the payment fields content for the handler.
	 *
	 * @return void
	 */
	protected function payment_fields_content() {
		?>
		<div id="payex_container" style="position:relative;z-index:9999;"></div>
		<?php
	}
}
