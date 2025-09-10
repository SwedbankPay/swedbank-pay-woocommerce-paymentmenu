<?php //phpcs:ignore
/**
 * Class for handling Woo subscriptions.
 *
 * @package SwedbankPay\Checkout\WooCommerce
 */

namespace SwedbankPay\Checkout\WooCommerce;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Request\UnscheduledPurchase;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Request\Verify;

use WP_Error;
use WC_Order;
use Swedbank_Pay_Payment_Gateway_Checkout;
use SwedbankPay\Checkout\WooCommerce\Helpers\Order;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Exception as ClientException;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderObject;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Class for handling subscriptions.
 */
class Swedbank_Pay_Subscription {
	public const GATEWAY_ID        = 'payex_checkout';
	public const RECURRENCE_TOKEN  = '_' . self::GATEWAY_ID . '_recurrence_token';
	public const UNSCHEDULED_TOKEN = '_' . self::GATEWAY_ID . '_unscheduled_token';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_scheduled_subscription_payment_' . self::GATEWAY_ID, array( $this, 'process_scheduled_payment' ), 10, 2 );

		// Set the return_url for change payment method.
		add_filter( 'swedbank_pay_urls', array( $this, 'set_subscription_order_redirect_urls' ), 10, 2 );

		// Whether the gateway should be available when handling subscriptions.
		add_filter( 'swedbank_pay_is_available', array( $this, 'is_available' ) );

		// On successful payment method change, the customer is redirected back to the subscription view page. We need to handle the redirect and create a recurring token.
		add_action( 'woocommerce_account_view-subscription_endpoint', array( $this, 'handle_redirect_from_change_payment_method' ) );

		// Show the recurring token on the subscription page in the billing fields.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show_payment_token' ) );

		// Adds supports for free (or trial-only) subscriptions.
		add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ) );

		// Ensure wp_safe_redirect do not redirect back to default dashboard or home page on change_payment_method.
		add_filter( 'allowed_redirect_hosts', array( $this, 'extend_allowed_domains_list' ) );

		// Save payment token to the subscription when the merchant updates the order from the subscription page.
		add_action( 'woocommerce_saved_order_items', array( $this, 'subscription_updated_from_order_page' ), 10, 2 );

		// Add the generateUnscheduledToken to the payment order if the cart contains a subscription.
		add_filter( 'swedbank_pay_payment_order', array( $this, 'maybe_generate_unscheduled_token' ), 10, 2 );

		// Retrieve and save the unscheduled token when the customer is redirected back to the order received page.
		add_action( 'template_redirect', array( $this, 'on_redirect_to_thankyou_page' ) );

		// Saves the subscription token, if missing, when the webhook is received.
		add_action( 'swedbank_pay_scheduler_run_after', array( $this, 'callback_received' ), 10, 2 );
	}

	/**
	 * Process subscription renewal.
	 *
	 * @param float     $amount_to_charge Amount to charge.
	 * @param \WC_Order $renewal_order The Woo order that will be created as a result of the renewal.
	 * @return void
	 */
	public function process_scheduled_payment( $amount_to_charge, $renewal_order ) {
		$gateway = swedbank_pay_get_payment_method( $renewal_order );
		if ( ! $gateway ) {
			$renewal_order->add_order_note( __( 'Failed to process subscription renewal. No Swedbank Pay payment gateway found.', 'swedbank-pay-woocommerce-checkout' ) );
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$token         = self::get_token_from_order( $renewal_order );

		$response = self::charge_customer( $renewal_order, $token );
		if ( is_wp_error( $response ) ) {
			// translators: Error message.
			$message = sprintf( __( 'Failed to process subscription renewal. Reason: %s', 'swedbank-pay-woocommerce-checkout' ), $response->get_error_message() );

			foreach ( $subscriptions as $subscription ) {
				$subscription->add_order_note( $message );
				$subscription->payment_failed();
			}

			$renewal_order->add_order_note( $message );
			return;
		}

		$message = sprintf(
			/* translators: %s: subscription id */
			__( 'Subscription renewal was made successfully via Swedbank Pay. Recurring token: %s', 'swedbank-pay-woocommerce-checkout' ),
			$token
		);
		$renewal_order->add_order_note( $message );

		$parent_order   = self::get_parent_order( $renewal_order, 'renewal' );
		$transaction_id = empty( $parent_order ) ? '' : $parent_order->get_transaction_id();
		$payment_order  = $response->getResponseData()['payment_order'];

		foreach ( $subscriptions as $subscription ) {
			// Save the transaction ID to the renewal order.
			$subscription->update_meta_data( '_payex_paymentorder_id', $payment_order['id'] );
			$subscription->update_meta_data( self::UNSCHEDULED_TOKEN, $token );
			$subscription->add_order_note( $message );

			$subscription->save_meta_data();
		}

		$renewal_order->payment_complete( $transaction_id );
		$renewal_order->update_meta_data( '_payex_paymentorder_id', $payment_order['id'] );
		$renewal_order->save_meta_data();
	}

	/**
	 * Charge the customer using the unscheduled token.
	 *
	 * @see https://developer.swedbankpay.com/checkout-v3/features/optional/unscheduled#performing-the-unscheduled-purchase.
	 *
	 * @param \WC_Order $order The WooCommerce order containing a subscription.
	 * @param string    $token The unscheduled token to charge.
	 * @return ResponseServiceInterface|WP_Error
	 */
	public static function charge_customer( $order, $token ) {
		$helper = new Order( $order );

		$payment_order = $helper->get_payment_order()
		->setUnscheduledToken( $token );

		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $payment_order );

		$purchase_request = new UnscheduledPurchase( $payment_order_object );
		$purchase_request->setClient( Order::get_client() );

		try {
			$response_service = $purchase_request->send();

			Swedbank_Pay()->logger()->debug( $purchase_request->getClient()->getDebugInfo() );

			return $response_service;
		} catch ( ClientException $e ) {

			Swedbank_Pay()->logger()->error( $purchase_request->getClient()->getDebugInfo() );
			Swedbank_Pay()->logger()->error(
				sprintf( '[SUBSCRIPTION RENEWAL] %s: API Exception: %s', __METHOD__, $e->getMessage() ),
				array(
					'order_id' => $order->get_id(),
					'token'    => $token,
				)
			);

			$error_body = json_decode( $purchase_request->getClient()->getResponseBody(), true );

			$errors   = array();
			$problems = $error_body['problems'] ?? array();
			foreach ( $problems as $problem ) {
				$errors[] = "{$problem['name']}: {$problem['description']}";
			}

			if ( empty( $errors ) ) {
				// translators: %s: Unscheduled token.
				$errors[] = sprintf( __( 'something went wrong. Check the plugin log for more information related to %s.', 'swedbank-pay-woocommerce-checkout' ), $token );
			}

			return Swedbank_Pay()->system_report()->request(
				new WP_Error(
					$error_body['status'] ?? $e->getCode(),
					join( $errors ),
					$error_body
				)
			);
		}
	}

	/**
	 * Verify the payment order to approve it for renewal. This is similar to a Purchase, but without any payment.
	 *
	 * @see https://developer.swedbankpay.com/checkout-v3/get-started/recurring#post-purchase--post-verify.
	 *
	 * @param \WC_Order $order The WooCommerce order containing a subscription.
	 * @return ResponseServiceInterface|WP_Error
	 */
	public static function approve_for_renewal( $order ) {
		$helper = new Order( $order );

		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $helper->get_payment_order( true ) );

		$verify_request = new Verify( $payment_order_object );
		$verify_request->setClient( Order::get_client() );

		try {
			$response_service = $verify_request->send();
			Swedbank_Pay()->logger()->debug( $verify_request->getClient()->getDebugInfo() );

			return $response_service;
		} catch ( ClientException $e ) {

			Swedbank_Pay()->logger()->error( $verify_request->getClient()->getDebugInfo() );
			Swedbank_Pay()->logger()->error(
				sprintf( '[VERIFY] %s: API Exception: %s', __METHOD__, $e->getMessage() ),
				array(
					'order_id' => $order->get_id(),
				)
			);

			$error_body = json_decode( $verify_request->getClient()->getResponseBody(), true );

			$errors   = array();
			$problems = $error_body['problems'] ?? array();
			foreach ( $problems as $problem ) {
				$errors[] = "{$problem['name']}: {$problem['description']}";
			}

			if ( empty( $errors ) ) {
				// translators: %s: The WC order id.
				$errors[] = sprintf( __( 'something went wrong. Check the plugin log for more information related to %s.', 'swedbank-pay-woocommerce-checkout' ), $order->get_id() );
			}

			return Swedbank_Pay()->system_report()->request(
				new WP_Error(
					$error_body['status'] ?? $e->getCode(),
					join( $errors ),
					$error_body
				)
			);
		}
	}

	/**
	 * Retrieves from Swedbank, and saves the unscheduled token to the order. Existing token will be overwritten.
	 *
	 * @see https://developer.swedbankpay.com/checkout-v3/get-started/recurring#post-purchase--post-verify.
	 *
	 * @param  \WC_Order                              $order The WC order containing a subscription.
	 * @param  \Swedbank_Pay_Payment_Gateway_Checkout $gateway The Swedbank Pay payment gateway instance.
	 * @return WP_Error|array The API response or WP_Error on failure.
	 */
	private function save_subscription_token( $order, $gateway ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		$action_urls      = $gateway->api->request( 'GET', $payment_order_id );
		$paid_response    = ! is_wp_error( $action_urls ) ? $gateway->api->request( 'GET', $action_urls['paymentOrder']['paid']['id'] ) : $action_urls;

		if ( ! is_wp_error( $paid_response ) ) {
			$paid              = $paid_response['paid'];
			$unscheduled_token = false;
			foreach ( $paid['tokens'] as $token ) {
				// From the collection of tokens, retrieve the 'unscheduled' token. There cannot be more than one unscheduled token at any time.
				if ( 'unscheduled' === $token['type'] ) {
					$unscheduled_token = $token['token'];

					$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
					foreach ( $subscriptions as $subscription ) {
						$subscription->update_meta_data( self::UNSCHEDULED_TOKEN, $unscheduled_token );
						$subscription->save_meta_data();
					}

					// translators: 1: Unscheduled token.
					$order->add_order_note( sprintf( __( 'Recurring token: %s', 'swedbank-pay-woocommerce-checkout' ), $unscheduled_token ) );

					$order->update_meta_data( self::UNSCHEDULED_TOKEN, $unscheduled_token );
					$order->save();

					break;
				}
			}

			Swedbank_Pay()->logger()->debug( "[SUBSCRIPTIONS]: Retrieved unscheduled token for order #{$order->get_id()}. Token: {$unscheduled_token}" );
		} else {
			Swedbank_Pay()->logger()->error(
				"[SUBSCRIPTIONS]: Failed to retrieve unscheduled token for order #{$order->get_id()}. Error: {$paid_response->get_error_message()}",
				array(
					'payment_order_id' => $payment_order_id,
					'order_id'         => $order->get_id(),
				)
			);

		}

		return $paid_response;
	}

	/**
	 * Retrieve the unscheduled token from the order, its parent order, or any subscriptions associated with the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return string|false The unscheduled token if found, false otherwise.
	 */
	public static function get_token_from_order( $order ) {
		$token = $order->get_meta( self::UNSCHEDULED_TOKEN );
		if ( ! empty( $token ) ) {
			return $token;
		}

		$parent_order = self::get_parent_order( $order );
		if ( ! empty( $parent_order ) ) {
			$token = $parent_order->get_meta( self::UNSCHEDULED_TOKEN );
			if ( ! empty( $token ) ) {
				return $token;
			}
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		foreach ( $subscriptions as $subscription ) {
			$token = $subscription->get_meta( self::UNSCHEDULED_TOKEN );
			if ( ! empty( $token ) ) {
				return $token;
			}
		}

		return false;
	}



	/**
	 * Perform post-purchase subscriptions actions.
	 *
	 * @return void
	 */
	public function on_redirect_to_thankyou_page() {
		$order_id = absint( get_query_var( 'order-received', 0 ) );
		$order    = wc_get_order( $order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( empty( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return;
		}

		if ( ! self::order_has_subscription( $order ) ) {
			return;
		}
		if ( ! empty( $order->get_meta( self::UNSCHEDULED_TOKEN ) ) ) {
			return;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return;
		}

		$this->save_subscription_token( $order, $gateway );
	}

	/**
	 * Saves the subscription token, if missing, when the webhook is received.
	 *
	 * @param \WC_Order                              $order The WooCommerce order.
	 * @param \Swedbank_Pay_Payment_Gateway_Checkout $gateway The Swedbank Pay payment gateway instance.
	 * @return void
	 */
	public function callback_received( $order, $gateway ) {
		if ( ! self::order_has_subscription( $order ) ) {
			return;
		}

		if ( ! empty( $order->get_meta( self::UNSCHEDULED_TOKEN ) ) ) {
			return;
		}

		$this->save_subscription_token( $order, $gateway );
	}

	/**
	 * Check if the order has a subscription, and then set the generateUnscheduledToken to true.
	 *
	 * @param Paymentorder $payment_order The Swedbank Pay payment order.
	 * @param Order        $helper The Order helper.
	 */
	public function maybe_generate_unscheduled_token( $payment_order, $helper ) {
		if ( ! self::order_has_subscription( $helper->get_order() ) ) {
			return $payment_order;
		}

		// Do not generate unscheduled token if the order already has one. Most likely this is a renewal order.
		// On the 'change payment method' page, we'll generate a new recurring token even if it already exists.
		if ( ! self::is_change_payment_method() && ! empty( $helper->get_order()->get_meta( self::UNSCHEDULED_TOKEN ) ) ) {
			return $payment_order;
		}

		return $payment_order->setGenerateUnscheduledToken( true );
	}

	/**
	 * Whether the gateway should be available if it contains a subscriptions.
	 *
	 * @param bool $is_available Whether the gateway is available.
	 * @return bool
	 */
	public function is_available( $is_available ) {
		// If no subscription is found, we don't need to do anything.
		if ( ! self::cart_has_subscription() ) {
			return $is_available;
		}

		// Allow free orders when changing subscription payment method.
		if ( self::is_change_payment_method() ) {
			return true;
		}

		// Mixed checkout not allowed.
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			foreach ( WC()->cart->cart_contents as $key => $item ) {
				if ( ! \WC_Subscriptions_Product::is_subscription( $item['data'] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether the cart needs payment. Since Swedbank supports free subscriptions, we must check if the cart contains a subscription.
	 *
	 * @param bool $needs_payment Whether the cart needs payment.
	 * @return bool
	 */
	public function cart_needs_payment( $needs_payment ) {
		if ( self::GATEWAY_ID !== WC()->session->chosen_payment_method ) {
			return $needs_payment;
		}

		return $this->is_available( $needs_payment );
	}
	/**
	 * Set the session URLs for change payment method request.
	 *
	 * Used for changing payment method.
	 *
	 * @see Swedbank_Checkout_Payment_Token
	 *
	 * @param PaymentorderUrl $url_data The URL data.
	 * @param Order           $helper The Order helper.
	 * @return PaymentorderUrl The modified URL data.
	 */
	public function set_subscription_order_redirect_urls( $url_data, $helper ) {
		if ( ! self::is_change_payment_method() ) {
			return $url_data;
		}

		$subscription = self::get_subscription( $helper->get_order() );
		$url_data->setCompleteUrl( add_query_arg( 'swedbank_pay_redirect', 'subscription', $subscription->get_view_order_url() ) )
		->setCancelUrl( $subscription->get_change_payment_method_url() );

		return $url_data;
	}

	/**
	 * Handle the redirect from the change payment method page.
	 *
	 * @param int $subscription_id The subscription ID.
	 * @return void
	 */
	public function handle_redirect_from_change_payment_method( $subscription_id ) {
		// We use the 'swedbank_pay_redirect' query var to determine if we are redirected from Swedbank Pay after changing payment method, otherwise the customer is viewing a subscription.
		if ( wc_get_var( $_GET['swedbank_pay_redirect'], '' ) !== 'subscription' ) {
			return;
		}

		$subscription = self::get_subscription( $subscription_id );
		if ( self::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}

		$gateway = swedbank_pay_get_payment_method( $subscription );
		if ( ! $gateway ) {
			return;
		}

		$result = $this->save_subscription_token( $subscription, $gateway );
		if ( is_wp_error( $result ) && function_exists( 'wc_print_notice' ) ) {
			// translators: Error message.
			wc_print_notice( sprintf( __( 'Failed to update payment method. Reason: %s', 'swedbank-pay-woocommerce-checkout' ), $result->get_error_message() ), 'error' );
		}
	}

	/**
	 * Add an order note to the subscription(s).
	 *
	 * @param WC_Subscription|WC_Subscription[] $subscriptions The WooCommerce subscription(s).
	 * @param string                            $note The note to add.
	 * @return void
	 */
	public static function add_order_note( $subscriptions, $note ) {
		if ( ! is_array( $subscriptions ) ) {
			$subscriptions->add_order_note( $note );
			$subscriptions->save();

			return;
		}

		foreach ( $subscriptions as $subscription ) {
			$subscription->add_order_note( $note );
			$subscription->save();
		}
	}

	/**
	 * Get a subscription's parent order.
	 *
	 * @param WC_Order $order The WooCommerce order id.
	 * @param string   $order_type The order type to check for. Default is 'any'. Other options are 'renewal', 'switch', 'resubscribe' and 'parent'.
	 * @return WC_Order|false The parent order or false if none is found.
	 */
	public static function get_parent_order( $order, $order_type = 'any' ) {
		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => $order_type ) );
		foreach ( $subscriptions as $subscription ) {
			$parent_order = $subscription->get_parent();
			if ( ! empty( $parent_order ) ) {
				return $parent_order;
			}
		}

		return false;
	}

	/**
	 * Check if the current request is for changing the payment method.
	 *
	 * @return bool
	 */
	public static function is_change_payment_method() {
		return isset( $_GET['change_payment_method'] );
	}

	/**
	 * Check if an order contains a subscription.
	 *
	 * @param \WC_Order $order The WooCommerce order or leave empty to use the cart (default).
	 * @return bool
	 */
	public static function order_has_subscription( $order ) {
		if ( empty( $order ) ) {
			return false;
		}

		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array( 'parent', 'resubscribe', 'switch', 'renewal' ) ) ) {
			return true;
		}

		return function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );
	}

	/**
	 * Check if a cart contains a subscription.
	 *
	 * @return bool
	 */
	public static function cart_has_subscription() {
		if ( ! is_checkout() ) {
			return false;
		}

		return ( class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) || ( function_exists( 'wcs_cart_contains_failed_renewal_order_payment' ) && wcs_cart_contains_failed_renewal_order_payment() );
	}

	/**
	 * Whether the cart contains only free trial subscriptions.
	 *
	 * If invoked from anywhere but the checkout page, this will return FALSE.
	 *
	 * @return boolean
	 */
	public static function cart_has_only_free_trial() {
		if ( ! is_checkout() ) {
			return false;
		}

		return ( class_exists( 'WC_Subscriptions_Cart' ) ) ? \WC_Subscriptions_Cart::all_cart_items_have_free_trial() : false;
	}

	/**
	 * Retrieve a WC_Subscription from order ID.
	 *
	 * @param \WC_Order|int $order  The WC order or id.
	 * @return bool|\WC_Subscription The subscription object, or false if it cannot be found.
	 */
	public static function get_subscription( $order ) {
		return ! function_exists( 'wcs_get_subscription' ) ? false : wcs_get_subscription( $order );
	}

	/**
	 * Add Swedbank Pay redirect payment page as allowed external url for wp_safe_redirect.
	 * We do this because WooCommerce Subscriptions use wp_safe_redirect when processing a payment method change request (from v5.1.0).
	 *
	 * @param array $hosts Domains that are allowed when wp_safe_redirect is used.
	 * @return array
	 */
	public function extend_allowed_domains_list( $hosts ) {
		$hosts[] = 'ecom.externalintegration.payex.com';
		return $hosts;
	}

	/**
	 * Save the payment token to the subscription when the merchant updates the order from the subscription page.
	 *
	 * @param int   $order_id The Woo order ID.
	 * @param array $items The posted data (includes even the data that was not updated).
	 * @return bool True if the payment token was updated, false otherwise.
	 */
	public function subscription_updated_from_order_page( $order_id, $items ) {
		$order = wc_get_order( $order_id );

		// The action hook woocommerce_saved_order_items is triggered for all order updates, so we must check if the payment method is Swedbank Pay.
		if ( self::GATEWAY_ID !== $order->get_payment_method() ) {
			return false;
		}

		// Are we on the subscription page?
		if ( 'shop_subscription' === $order->get_type() ) {
			// Retrieve the stored token, and the one included in the posted data.
			$payment_token  = wc_get_var( $items[ self::UNSCHEDULED_TOKEN ] );
			$existing_token = $order->get_meta( self::UNSCHEDULED_TOKEN );

			// Did the customer update the subscription's payment token?
			if ( ! empty( $payment_token ) && $existing_token !== $payment_token ) {
				$order->update_meta_data( self::UNSCHEDULED_TOKEN, $payment_token );
				$order->add_order_note(
					sprintf(
					// translators: 1: User name, 2: Existing token, 3: New token.
						__( '%1$s updated the subscription payment token from "%2$s" to "%3$s".', 'swedbank-pay-woocommerce-checkout' ),
						ucfirst( wp_get_current_user()->display_name ),
						$existing_token,
						$payment_token
					)
				);

				$order->save();
				return true;
			}
		}

		return true;
	}

	/**
	 * Shows the recurring token for the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return void
	 */
	public function show_payment_token( $order ) {
		$subscription_token = $order->get_meta( self::UNSCHEDULED_TOKEN );
		if ( 'shop_subscription' === $order->get_type() ) {
			?>
			<div class="order_data_column" style="clear:both; float:none; width:100%;">
				<div class="address">
					<p>
						<strong><?php echo esc_html( 'Subscription token' ); ?>:</strong><?php echo esc_html( $subscription_token ); ?>
					</p>
				</div>
				<div class="edit_address">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'            => self::UNSCHEDULED_TOKEN,
						'label'         => __( 'Subscription token', 'swedbank-pay-woocommerce-checkout' ),
						'wrapper_class' => '_billing_company_field',
						'value'         => $subscription_token,
					)
				);
				?>
				</div>
			</div>
				<?php
		}
	}
}

	new Swedbank_Pay_Subscription();
