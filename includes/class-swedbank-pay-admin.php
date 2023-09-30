<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Exception;
use SwedbankPay\Core\Log\LogLevel;

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Admin {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Add statuses for payment complete
		add_filter(
			'woocommerce_valid_order_statuses_for_payment_complete',
			array( $this, 'add_valid_order_statuses' ),
			10,
			2
		);

		// Add meta boxes
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes', 10, 2 );

		// Add action buttons
		add_action( 'woocommerce_order_item_add_action_buttons', __CLASS__ . '::add_action_buttons', 10, 1 );

		// Add scripts and styles for admin
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::admin_enqueue_scripts' );

		// Add Admin Backend Actions
		add_action( 'wp_ajax_swedbank_pay_capture', array( $this, 'ajax_swedbank_pay_capture' ) );
		add_action( 'wp_ajax_swedbank_pay_cancel', array( $this, 'ajax_swedbank_pay_cancel' ) );
		add_action( 'wp_ajax_swedbank_pay_refund', array( $this, 'ajax_swedbank_pay_refund' ) );

		// Remove "Order fully refunded" hook. See wc_order_fully_refunded()
		remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::order_status_changed_transaction', 0, 3 );
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param array $statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function add_valid_order_statuses( $statuses, $order ) {
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			$statuses = array_merge(
				$statuses,
				array(
					'processing',
					'completed',
				)
			);
		}

		return $statuses;
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
						'swedbank_payment_actions',
						__( 'Swedbank Pay Payments Actions', 'swedbank-pay-woocommerce-checkout' ),
						__CLASS__ . '::order_meta_box_payment_actions',
						$screen,
						'side',
						'high'
					);
				}
			}
		}
	}

	/**
	 * MetaBox for Payment Actions
	 * @param WC_Order|\WP_Post $order
	 * @return void
	 */
	public static function order_meta_box_payment_actions( $order ) {
		$order            = wc_get_order( $order );
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return;
		}

		// Get Payment Gateway
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return;
		}

		// Fetch payment info
		try {
			/** @var \Swedbank_Pay_Payment_Gateway_Checkout $gateway */
			$result = $gateway->core->fetchPaymentInfo( $payment_order_id . '/paid' );
		} catch ( \Exception $e ) {
			// Request failed
			return;
		}

		wc_get_template(
			'admin/payment-actions.php',
			array(
				'gateway'  => $gateway,
				'order'    => $order,
				'info'     => $result,
			),
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Add action buttons to Order view
	 *
	 * @param WC_Order $order
	 */
	public static function add_action_buttons( $order ) {
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			// Buttons are available for orders only
			return;
		}

		// Get Payment Gateway
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			// Get Payment Gateway
			$gateway = swedbank_pay_get_payment_method( $order );
			if ( ! $gateway ) {
				return;
			}

			/** @var \Swedbank_Pay_Payment_Gateway_Checkout $gateway */
			wc_get_template(
				'admin/action-buttons.php',
				array(
					'gateway' => $gateway,
					'order'   => $order,
				),
				'',
				dirname( __FILE__ ) . '/../templates/'
			);
		}
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
			// Scripts
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script(
				'swedbank-pay-admin-js',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin' . $suffix . '.js'
			);

			// Localize the script
			$translation_array = array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'text_wait' => __( 'Please wait...', 'swedbank-pay-woocommerce-checkout' ),
			);
			wp_localize_script( 'swedbank-pay-admin-js', 'SwedbankPay_Admin', $translation_array );

			// Enqueued script with localized data
			wp_enqueue_script( 'swedbank-pay-admin-js' );
		}
	}

	/**
	 * Action for Capture
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_capture() {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // WPCS: input var ok, CSRF ok.
		if ( ! wp_verify_nonce( $nonce, 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = (int) $_REQUEST['order_id']; // WPCS: input var ok, CSRF ok.
		$order    = wc_get_order( $order_id );

		// Get Payment Gateway
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( $gateway ) {
			try {
				$gateway->capture_payment( $order_id );
				wp_send_json_success( __( 'Capture success.', 'swedbank-pay-woocommerce-checkout' ) );
			} catch ( Exception $e ) {
				wp_send_json_error( $e->getMessage() );
			}
		}
	}

	/**
	 * Action for Cancel
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_cancel() {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // WPCS: input var ok, CSRF ok.
		if ( ! wp_verify_nonce( $nonce, 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = (int) $_REQUEST['order_id']; // WPCS: input var ok, CSRF ok.
		$order    = wc_get_order( $order_id );

		// Get Payment Gateway
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( $gateway ) {
			try {
				$gateway->cancel_payment( $order_id );
				wp_send_json_success( __( 'Cancel success.', 'swedbank-pay-woocommerce-checkout' ) );
			} catch ( Exception $e ) {
				wp_send_json_error( $e->getMessage() );
			}
		}
	}

	/**
	 * Action for Full Refund
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_refund() {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // WPCS: input var ok, CSRF ok.
		if ( ! wp_verify_nonce( $nonce, 'swedbank_pay' ) ) {
			exit( 'No naughty business' );
		}

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = (int) $_REQUEST['order_id']; // WPCS: input var ok, CSRF ok.
		$order    = wc_get_order( $order_id );

		try {
			// Create the refund object.
			$refund = wc_create_refund(
				array(
					'amount'         => $order->get_total(),
					'reason'         => __( 'Full refund.', 'swedbank-pay-woocommerce-checkout' ),
					'order_id'       => $order_id,
					'refund_payment' => true,
				)
			);

			if ( is_wp_error( $refund ) ) {
				throw new Exception( $refund->get_error_message() );
			}

			wp_send_json_success( __( 'Refund has been successful.', 'swedbank-pay-woocommerce-checkout' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * @param $order_id
	 * @param $old_status
	 * @param $new_status
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function order_status_changed_transaction( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );
		if ( in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			$gateway = swedbank_pay_get_payment_method( $order );

			$gateway->core->log(
				LogLevel::INFO,
				'Order status change trigger: ' . $new_status . ' OrderID: ' . $order_id
			);

			try {
				switch ( $new_status ) {
					case 'processing':
					case 'completed':
						$gateway->core->log( LogLevel::INFO, 'Try to capture...' );

						$gateway->capture_payment( $order );
						$order->add_order_note(
							__( 'Payment has been captured by order status change.', 'swedbank-pay-woocommerce-checkout' )
						);
						break;
					case 'cancelled':
						$gateway->core->log( LogLevel::INFO, 'Try to cancel...' );

						$gateway->cancel_payment( $order );
						$order->add_order_note(
							__( 'Payment has been cancelled by order status change.', 'swedbank-pay-woocommerce-checkout' )
						);
						break;
					case 'refunded':
						$gateway->core->log( LogLevel::INFO, 'Try to refund...' );

						$gateway->core->refundCheckout( $order->get_id() );
						$order->add_order_note(
							__( 'Payment has been refunded by order status change.', 'swedbank-pay-woocommerce-checkout' )
						);
						break;
				}
			} catch ( \Exception $exception ) {
				\WC_Admin_Meta_Boxes::add_error( $exception->getMessage() );

				// Rollback status
				remove_action(
					'woocommerce_order_status_changed',
					__CLASS__ . '::order_status_changed_transaction',
					0
				);
				$order->update_status(
					$old_status,
					sprintf( 'Rollback status. Error: %s', $exception->getMessage() )
				);
			}
		}
	}
}

new Swedbank_Pay_Admin();
