<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use Krokedil\Swedbank\Pay\Helpers\PaymentDataHelper;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;
use WP_Error;


/**
 * Class for processing the inline embedded checkout flow on the shortcode checkout page.
 */
class InlineEmbedded extends CheckoutFlow {
	/**
	 * If this is for the payment complete return.
	 *
	 * @var bool
	 */
	protected $is_payment_complete = false;

	/**
	 * The Payee reference for the completed payment.
	 *
	 * @var string|null
	 */
	protected $payee_reference = null;

	protected function set_is_payment_complete() {
		$payment_complete = filter_input( INPUT_GET, 'payex-payment-complete', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! empty( $payment_complete ) ) {
			$this->is_payment_complete = true;
			$this->payee_reference = sanitize_text_field( wp_unslash( $payment_complete ) );
			return;
		}

		// If we did not have it in the GET params, check the POST data. Needed to handle potential ajax requests.
		$post_data = isset( $_POST['post_data'] ) ? sanitize_text_field( wp_unslash( $_POST['post_data'] ) ) : null;
		wp_parse_str( $post_data, $post_data_array );
		if ( isset( $post_data_array['swedbank_pay_payee_reference'] ) && isset( $post_data_array['swedbank_pay_payment_complete'] ) ) {
			$this->is_payment_complete = true;
			$this->payee_reference     = sanitize_text_field( $post_data_array['swedbank_pay_payee_reference'] );
		}
	}

	/**
	 * Initialize any actions or filters needed for the checkout flow.
	 *
	 * @return void
	 */
	protected function init() {
		$this->set_is_payment_complete();
		if ( ! $this->is_payment_complete ) {
			// Create a payment from the cart contents.
			$result = $this->create_or_update_embedded_purchase();

			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
				return;
			}
		} else { // If this is on the payment complete return, verify the payment to make sure no errors occurred.
			$this->process_payment_complete_return();
		}

		if ( WC()->session->get( 'swedbank_pay_should_reset_session' ) ) {
			WC()->session->set( 'reload_checkout', true );
			self::unset_embedded_session_data();
			return;
		}

		// Get the src url for the script.
		$script_src = WC()->session->get( 'swedbank_pay_view_checkout_url' );

		if ( empty( $script_src ) ) {
			wc_add_notice( __( 'Failed to get the payment session URL.', 'swedbank-pay-woocommerce-checkout' ), 'error' );
			return;
		}

		$params = array(
			'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'payment_complete' => $this->is_payment_complete,
		);

		if ( $this->is_payment_complete ) {
			$order = swedbank_pay_get_order_by_payee_reference( $this->payee_reference );
			$params['thankyou_url']    = $this->gateway->get_return_url( $order );
			$params['payee_reference'] = $this->payee_reference;
		}

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
		WC()->session->__unset( 'swedbank_pay_should_reset_session' );
		WC()->session->__unset( 'swedbank_pay_operation' );
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

		if ( WC()->session->get( 'swedbank_pay_should_reset_session' ) ) {
			self::unset_embedded_session_data();
			WC()->session->set( 'reload_checkout', true );
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
	 * @throws \Exception If there is an error during the creation or update process.
	 * @return \WP_Error|Paymentorder|array
	 */
	public function create_or_update_embedded_purchase() {
		try {
			$payment_order_id = WC()->session->get( 'swedbank_pay_paymentorder_id' );
			$result           = new WP_Error( 'no_payment_order', 'Failed to get a payment.' );

			// If we have a payment order ID in the session, try to first get the session, and then update.
			if ( ! empty( $payment_order_id ) ) {
				// Try to get the payment to ensure it still exists.
				$get_purchase_result = $this->api->get_embedded_purchase();
				if ( is_wp_error( $get_purchase_result ) ) {
					throw new \Exception( $result->get_error_message() );
				}

				$session_operation = WC()->session->get( 'swedbank_pay_operation' );
				$is_zero_order     = Swedbank_Pay_Subscription::cart_has_zero_order();
				if ( ( PaymentDataHelper::OPERATION_PURCHASE === $session_operation && $is_zero_order ) || ( PaymentDataHelper::OPERATION_VERIFY === $session_operation && ! $is_zero_order ) ) {
					// clear the session.
					WC()->session->set( 'swedbank_pay_should_reset_session', true );
					return array();
				}

				// A verify operation cannot be updated which is the case for all zero amount order.
				if ( PaymentDataHelper::OPERATION_VERIFY === $session_operation ) {
					return array();
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
				$operation         = $result->getResponseData()['payment_order']['operation'];

				// Save payment ID to the session.
				WC()->session->set( 'swedbank_pay_paymentorder_id', $payment_order['id'] );
				WC()->session->set( 'swedbank_pay_view_session_url', $view_session_url );
				WC()->session->set( 'swedbank_pay_update_order_url', $update_order_url );
				WC()->session->set( 'swedbank_pay_view_checkout_url', $view_checkout_url );
				WC()->session->set( 'swedbank_pay_operation', $operation );
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
			$code = \is_int( $result->get_error_code() ) ? \intval( $result->get_error_code() ) : 500;
			throw new \Exception(
				$result->get_error_message() ?? __( 'The payment could not be initiated.', 'swedbank-pay-woocommerce-checkout' ),
				$code
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

		// Return success and redirect to the embedded container, and pass the redirect url for the thankyou page to be used in the onPaid event.
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
	 * Process the payment complete return to verify the payment and handle any potential issues.
	 * If any issues are found, the current session with Swedbank is cleared and the user is redirected back to the checkout page with an error message.
	 *
	 * @return void
	 */
	protected function process_payment_complete_return() {
		try{
			// Get the payment to verify its status.
			$get_purchase_result = $this->api->get_embedded_purchase();

			// If we could not get the payment, throw an error.
			if ( is_wp_error( $get_purchase_result ) ) {
				throw new \Exception( $get_purchase_result->get_error_message() );
			}

			// Get any potential problems from the payment response.
			$problem = $get_purchase_result['problem'] ?? null;
			if ( ! empty( $problem ) ) {
				$message = $problem['detail'] ?? __( 'An unknown error occurred during the payment process.', 'swedbank-pay-woocommerce-checkout' );
				throw new \Exception( $message );
			}
		} catch( \Exception $e ) {
			self::unset_embedded_session_data();
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Output the payment fields content for the handler.
	 *
	 * @return void
	 */
	protected function payment_fields_content() {
		?>
		<?php if ( $this->is_payment_complete ) : ?>
			<input type="hidden" id="swedbank_pay_payment_complete" name="swedbank_pay_payment_complete" value="1" />
			<input type="hidden" id="swedbank_pay_payee_reference" name="swedbank_pay_payee_reference" value="<?php echo esc_attr( $this->payee_reference ); ?>" />
		<?php endif; ?>
		<div id="payex_container"></div>
		<?php
	}
}
