<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

class Swedbank_Pay_Admin_Refund {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Add meta boxes
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes', 10, 2 );

		// Add scripts and styles for admin
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::admin_enqueue_scripts' );

		// Add Admin Backend Actions
		add_action( 'wp_ajax_swedbank_pay_refund_partial', array( $this, 'ajax_swedbank_pay_refund_partial' ) );
	}

	/**
	 * Add meta boxes in admin
	 * @param $screen_id
	 * @param WC_Order|\WP_Post $order
	 * @return void
	 */
	public static function add_meta_boxes( $screen_id, $order ) {
		$hook_to_check = swedbank_pay_is_hpos_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $hook_to_check === $screen_id ) {
			$order          = wc_get_order( $order );
			$payment_method = $order->get_payment_method();
			if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
				$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
				if ( ! empty( $payment_order_id ) ) {
					$screen = swedbank_pay_is_hpos_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

					add_meta_box(
						'swedbank_payment_refunds',
						__( 'Refund by amount', 'swedbank-pay-woocommerce-checkout' ),
						__CLASS__ . '::order_meta_box_payment_refunds',
						$screen,
						'side',
						'high'
					);
				}
			}
		}
	}

	/**
	 * MetaBox for Refunds
	 * @param WC_Order|\WP_Post $order
	 * @return void
	 */
	public static function order_meta_box_payment_refunds( $order ) {
		$order            = wc_get_order( $order );
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return;
		}

		// Get Payment Gateway
		$payment_method = $order->get_payment_method();
		if ( ! in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		// Get Payment Gateway
		/** @var \Swedbank_Pay_Payment_Gateway_Checkout $gateway */
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return;
		}

		$total_refunded = (float) $order->get_total_refunded();

		wc_get_template(
			'admin/payment-refunds.php',
			array(
				'total_refunded'       => $total_refunded,
				'available_for_refund' => $order->get_total() - $total_refunded,
				'can_refund'           => $gateway->api->can_refund( $order ),
				'order'                => $order
			),
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Enqueue Scripts in admin
	 *
	 * @param $hook
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook ) {
		$hook_to_check = swedbank_pay_is_hpos_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'post.php';
		if ( $hook_to_check === $hook ) {
			global $post_id;
			global $theorder;

			if (!$post_id && !$theorder) {
				return;
			}

			$order          = $theorder ?: wc_get_order( (int) $post_id );
			$payment_method = $order->get_payment_method();
			if ( ! in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
				return;
			}

			// Scripts
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script(
				'swedbank-pay-admin-refund-js',
				plugin_dir_url( __FILE__ ) . '../assets/js/refund' . $suffix . '.js'
			);

			// Localize the script
			$translation_array = array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'text_wait' => __( 'Please wait...', 'swedbank-pay-woocommerce-checkout' ),
				'nonce'     => wp_create_nonce( 'swedbank_pay' ),
				'order_id'  => $order->get_id()
			);
			wp_localize_script( 'swedbank-pay-admin-refund-js', 'SwedbankPay_Admin_Refund', $translation_array );

			// Enqueued script with localized data
			wp_enqueue_script( 'swedbank-pay-admin-refund-js' );
		}
	}

	/**
	 * Action for Full Refund.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_refund_partial() {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // WPCS: input var ok, CSRF ok.
		if ( ! wp_verify_nonce( $nonce, 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id   = (int) $_REQUEST['order_id']; // WPCS: input var ok, CSRF ok.
		$amount     = (float) $_REQUEST['amount']; // WPCS: input var ok, CSRF ok.
		$vat_amount = (float) $_REQUEST['vat_amount']; // WPCS: input var ok, CSRF ok.
		$order      = wc_get_order( $order_id );
		$gateway    = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			throw new \Exception( 'Payment gateway is not available');
		}

		// Do refund
		$result = $gateway->payment_actions_handler->refund_payment_amount( $order, $amount, $vat_amount );
		if ( is_wp_error( $result ) ) {
			/** @var \WP_Error $result */
			wp_send_json_error( join('; ', $result->get_error_messages() ) );

			return;
		}

		$order->update_meta_data(
			'_payex_total_refunded',
			(float) $order->get_meta( '_payex_total_refunded' ) + (float) $amount
		);
		$order->update_meta_data(
			'_payex_total_refunded_vat',
			(float) $order->get_meta( '_payex_total_refunded_vat' ) + (float) $vat_amount
		);
		$order->save_meta_data();

		ob_start();
		wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => sprintf('Refunded %s manually', $amount),
				'refund_payment' => false
			)
		);
		ob_end_clean();

		// Refund will be created on transaction processing
		wp_send_json_success( __( 'Refund has been successful.', 'swedbank-pay-woocommerce-checkout' ) );
	}

}

new Swedbank_Pay_Admin_Refund();
