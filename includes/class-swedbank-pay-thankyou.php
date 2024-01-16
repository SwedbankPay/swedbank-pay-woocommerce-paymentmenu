<?php


namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

class Swedbank_Thankyou {
	public function __construct() {
		add_filter( 'wc_get_template', array( $this, 'override_template' ), 5, 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'thankyou_scripts' ) );

		// Action for "Check payment"
		add_action( 'wp_ajax_swedbank_pay_check_payment_status', array( $this, 'ajax_check_payment_status' ) );
		add_action( 'wp_ajax_nopriv_swedbank_pay_check_payment_status', array( $this, 'ajax_check_payment_status' ) );
	}

	/**
	 * Override "checkout/thankyou.php" template
	 *
	 * @param $located
	 * @param $template_name
	 * @param $args
	 * @param $template_path
	 * @param $default_path
	 *
	 * @return string
	 */
	public function override_template( $located, $template_name, $args, $template_path, $default_path ) {
		if ( strpos( $located, 'checkout/thankyou.php' ) !== false ) {
			if ( ! isset( $args['order'] ) ) {
				return $located;
			}

			$order = wc_get_order( $args['order'] );
			if ( ! $order ) {
				return $located;
			}

			if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
				return $located;
			}

			$located = wc_locate_template(
				'checkout/thankyou.php',
				$template_path,
				dirname( __FILE__ ) . '/../templates/'
			);
		}

		return $located;
	}

	/**
	 * thankyou_scripts function.
	 *
	 * Outputs scripts used for "thankyou" page
	 *
	 * @return void
	 */
	public function thankyou_scripts() {
		$settings = get_option( 'woocommerce_payex_checkout_settings', array( 'enabled' => 'no' ) );
		if ( ! is_order_received_page() || 'no' === $settings['enabled'] ) {
			return;
		}

		global $wp;

		$order_id  = absint( $wp->query_vars['order-received'] );
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // WPCS: input var ok, CSRF ok.

		$order = wc_get_order( $order_id );
		if ( ! $order->get_id() || ! $order->key_is_valid( $order_key ) ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'swedbank-pay-payment-status-check',
			untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets/js/payment-status' . $suffix . '.js',
			array(
				'jquery',
				'jquery-blockui'
			),
			false,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'swedbank-pay-payment-status-check',
			'Swedbank_Pay_Payment_Status_Check',
			array(
				'order_id'      => $order_id,
				'order_key'     => $order_key,
				'nonce'         => wp_create_nonce( 'swedbank_pay' ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'check_message' => __(
					'Please wait. We\'re checking the order status.',
					'swedbank-pay-woocommerce-checkout'
				)
			)
		);

		wp_enqueue_script( 'swedbank-pay-payment-status-check' );
	}

	public function ajax_check_payment_status() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? wc_clean( $_POST['order_id'] ) : '';
		$order_key  = isset( $_POST['order_key'] ) ? wc_clean( $_POST['order_key'] ) : '';

		$order = wc_get_order( $order_id );
		if ( ! $order->get_id() || ! $order->key_is_valid( $order_key ) ) {
			wp_send_json_error( 'Invalid order' );
			return;
		}

		$payment_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_id ) ) {
			wp_send_json_error( 'Invalid payment' );
			return;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		$result = $gateway->api->request( 'GET', $payment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( 'Failed to get payment status' );

			return;
		}

		$status = $result['paymentOrder']['status'];
		switch ($status) {
			case 'Paid':
				wp_send_json_success( array(
					'state' => 'paid',
					'message' => 'Order has been paid'
				) );

				return;
			case 'Aborted':
				wp_send_json_success( array(
					'state' => 'failed',
					'message' => 'The payment has been aborted'
				) );

				return;
			default:
				// Check in `failedAttempts`
				$result = $gateway->api->request( 'GET', $payment_id . '/failedAttempts' );
				if ( is_wp_error( $result ) ) {
					wp_send_json_success( array(
						'state' => 'failed',
						'message' => 'Unable to verify the payment: ' . join('; ', $result->get_error_messages() )
					) );

					return;
				}

				$problems = array();
				$failed_attempts = $result['failedAttempts']['failedAttemptList'];
				foreach ( $failed_attempts as $attempt ) {
					$problems[] = $attempt['problem']['title'];
				}

				wp_send_json_success( array(
					'state' => 'failed',
					'message' => 'Transaction failed: ' . join('; ', $problems )
				) );

				return;
		}
	}
}

new Swedbank_Thankyou();
