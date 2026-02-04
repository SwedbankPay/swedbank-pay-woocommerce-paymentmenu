<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Log_Levels;
use Exception;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;
use Krokedil\Swedbank\Pay\Helpers\HPOS;

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
		add_action( 'wp_ajax_swedbank_pay_get_refund_mode', array( $this, 'ajax_swedbank_pay_get_refund_mode' ) );

		// Remove "Order fully refunded" hook. See wc_order_fully_refunded()
		remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::order_status_changed_transaction', 0, 3 );

		// Refund actions
		add_action( 'woocommerce_create_refund', array( $this, 'save_refund_parameters' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'remove_refund_parameters' ), 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'prevent_online_refund' ), 10, 2 );

		add_filter(
			'woocommerce_admin_order_should_render_refunds',
			array( $this, 'order_should_render_refunds' ),
			10,
			3
		);
	}

	public function prevent_online_refund( $order_id, $refund_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			// Prevent online refund when order status changed to "refunded"
			set_transient(
				'sb_refund_prevent_online_refund_' . $order_id,
				$refund_id,
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param array    $statuses
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
	 *
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
						__( 'Swedbank Pay Payments Actions', 'swedbank-pay-payment-menu' ),
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
	 *
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

		// Fetch payment info
		$result = $gateway->api->request( 'GET', $payment_order_id . '/paid' );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			return;
		}

		wc_get_template(
			'admin/payment-actions.php',
			array(
				'gateway' => $gateway,
				'order'   => $order,
				'info'    => $result,
			),
			'',
			__DIR__ . '/../templates/'
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
				__DIR__ . '/../templates/'
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
			$order_id = HPOS::get_the_ID();
			$order    = wc_get_order( $order_id );

			if ( empty( $order ) || 'payex_checkout' !== $order->get_payment_method() ) {
				return;
			}

			// Scripts.
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script(
				'swedbank-pay-admin-js',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin' . $suffix . '.js',
				array( 'jquery' ),
				SWEDBANK_PAY_VERSION,
				true
			);

			// Localize the script.
			$translation_array = array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'text_wait' => __( 'Please wait...', 'swedbank-pay-payment-menu' ),
				'nonce'     => wp_create_nonce( 'swedbank_pay' ),
				'order_id'  => $order_id,
			);
			wp_localize_script( 'swedbank-pay-admin-js', 'SwedbankPay_Admin', $translation_array );

			// Enqueued script with localized data.
			wp_enqueue_script( 'swedbank-pay-admin-js' );
		}
	}

	/**
	 * Action for Capture
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_capture() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = filter_input( INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$order    = wc_get_order( $order_id );

		// Get Payment Gateway
		$gateway = swedbank_pay_get_payment_method( $order );
		$result  = $gateway->payment_actions_handler->capture_payment( $order );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			wp_send_json_error( join( '; ', $result->get_error_messages() ) );
			return;
		}

		wp_send_json_success( __( 'Capture success.', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Action for Cancel.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_cancel() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = filter_input( INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$order    = wc_get_order( $order_id );

		// Get Payment Gateway
		$gateway = swedbank_pay_get_payment_method( $order );
		$result  = $gateway->payment_actions_handler->cancel_payment( $order );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			wp_send_json_error( join( '; ', $result->get_error_messages() ) );
			return;
		}

		wp_send_json_success( __( 'Cancel success.', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Action for Full Refund.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function ajax_swedbank_pay_refund() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		remove_action(
			'woocommerce_order_status_changed',
			__CLASS__ . '::order_status_changed_transaction',
			0
		);

		$order_id = filter_input( INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$order    = wc_get_order( $order_id );
		$gateway  = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			throw new Exception( 'Payment gateway is not available' );
		}

		// Do refund
		$result = $gateway->payment_actions_handler->refund_payment(
			$order,
			swedbank_pay_get_available_line_items_for_refund( $order ),
			__( 'Full refund.', 'swedbank-pay-payment-menu' ),
			true
		);
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			/** @var \WP_Error $result */
			wp_send_json_error( join( '; ', $result->get_error_messages() ) );

			return;
		}

		// @todo Create credit memo with order lines

		// Refund will be created on transaction processing
		wp_send_json_success( __( 'Refund has been successful.', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Retrieves the refund mode.
	 *
	 * This method checks the refund mode for a given Swedbank Pay transaction based on the provided order ID.
	 * The refund mode determines how the refund amount should be calculated and processed.
	 *
	 * @return void
	 *
	 * @throws \WP_Error If the Swedbank Pay payment gateway is not available.
	 * @global array $_REQUEST The request data.
	 */
	public function ajax_swedbank_pay_get_refund_mode() {
		check_ajax_referer( 'swedbank_pay', 'nonce' );

		$order_id = filter_input( INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! $order_id ) {
			wp_send_json_success(
				array(
					'mode' => 'default',
				)
			);

			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_success(
				array(
					'mode' => 'default',
				)
			);

			return;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			wp_send_json_success(
				array(
					'mode' => 'default',
				)
			);

			return;
		}

		// If have YITH WooCommerce Gift Cards in Order, amount mode only
		if ( function_exists( 'YITH_YWGC' ) ) {
			$gift_amount      = 0;
			$order_gift_cards = $order->get_meta( '_ywgc_applied_gift_cards' );
			if ( empty( $order_gift_cards ) ) {
				$order_gift_cards = array();
			}

			// Compatibility: YITH WooCommerce Gift Cards v4.11
			if ( 0 === count( $order_gift_cards ) ) {
				foreach ( $order->get_items( 'yith_gift_card' ) as $gift_card ) {
					/** @var \YITH_Gift_Card_Order_Item $gift_card */
					$order_gift_cards[ $gift_card->get_code() ] = abs( $gift_card->get_amount() );
				}
			}

			foreach ( $order_gift_cards as $code => $amount ) {
				$amount = apply_filters( 'ywgc_gift_card_amount_order_total_item', $amount, YITH_YWGC()->get_gift_card_by_code( $code ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				if ( $amount > 0 ) {
					$gift_amount += $amount;
				}
			}

			if ( $gift_amount > 0 ) {
				wp_send_json_success(
					array(
						'mode' => 'amount',
					)
				);

				return;
			}
		}

		// If taxes are enabled, using this refund amount can cause issues due to taxes not being refunded also.
		// The refunds should be added to the line items, not the order as a whole.
		if ( wc_tax_enabled() ) {
			wp_send_json_success(
				array(
					'mode' => 'items',
				)
			);

			return;
		}

		$mode = $order->get_meta( '_sb_refund_mode' );
		if ( empty( $mode ) ) {
			wp_send_json_success(
				array(
					'mode' => 'default',
				)
			);

			return;
		}

		wp_send_json_success(
			array(
				'mode' => $mode,
			)
		);
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
		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		// Allow to change status from `processing` to `completed`.
		if ( 'processing' === $old_status && 'completed' === $new_status ) {
			return;
		}

		// Allow to change status from `pending` to `cancelled`.
		if ( 'pending' === $old_status && 'cancelled' === $new_status ) {
			return;
		}

		if ( Swedbank_Pay_Subscription::should_skip_order_management( $order ) ) {
			return;
		}

		if ( 'cancelled' === $new_status ) {
			$settings = get_option( 'woocommerce_payex_checkout_settings', array() );
			if ( ! wc_string_to_bool( $settings['enable_order_cancel'] ?? 'yes' ) ) {
				return;
			}
		}

		$gateway = swedbank_pay_get_payment_method( $order );

		Swedbank_Pay()->logger()->log(
			'Order status change trigger: ' . $new_status . ' OrderID: ' . $order_id
		);

		try {
			switch ( $new_status ) {
				case 'completed':
					$payment_id = $order->get_meta( '_payex_paymentorder_id' );
					if ( $payment_id && $gateway->api->is_captured( $payment_id ) ) {
						$gateway->api->log( WC_Log_Levels::INFO, "The order {$order->get_order_number()} is already captured." );
						return;
					}
					$gateway->api->log( WC_Log_Levels::INFO, 'Try to capture...' );
					$result = $gateway->payment_actions_handler->capture_payment( $order );
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been captured by order status change.', 'swedbank-pay-payment-menu' )
					);

					break;
				case 'cancelled':
					$gateway->api->log( WC_Log_Levels::INFO, 'Try to cancel...' );
					$result = $gateway->payment_actions_handler->cancel_payment( $order );
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been cancelled by order status change.', 'swedbank-pay-payment-menu' )
					);

					break;
				case 'refunded':
					$refund_id = get_transient( 'sb_refund_prevent_online_refund_' . $order_id );
					if ( ! empty( $refund_id ) ) {
						delete_transient( 'sb_refund_prevent_online_refund_' . $order_id );

						return;
					}

					$gateway->api->log( WC_Log_Levels::INFO, 'Try to refund...' );
					$lines  = swedbank_pay_get_available_line_items_for_refund( $order );
					$result = $gateway->payment_actions_handler->refund_payment(
						$order,
						$lines,
						__( 'Order status changed to refunded.', 'swedbank-pay-payment-menu' ),
						true
					);
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been refunded by order status change.', 'swedbank-pay-payment-menu' )
					);

					break;
			}
		} catch ( \Exception $exception ) {
			\WC_Admin_Meta_Boxes::add_error( 'Order status change action error: ' . $exception->getMessage() );

			// Rollback status
			remove_action(
				'woocommerce_order_status_changed',
				__CLASS__ . '::order_status_changed_transaction',
				0
			);

			$order->add_order_note(
				sprintf(
					'Order status change "%s->%s" action error: %s',
					$old_status,
					$new_status,
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * Save refund parameters to perform refund with specified products and amounts.
	 *
	 * @param \WC_Order_Refund $refund
	 * @param $args
	 */
	public function save_refund_parameters( $refund, $args ) {
		if ( ! isset( $args['order_id'] ) ) {
			return;
		}

		$order = wc_get_order( $args['order_id'] );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		// Save order items of refund
		set_transient(
			'sb_refund_parameters_' . $args['order_id'],
			$args,
			5 * MINUTE_IN_SECONDS
		);

		// Preserve refund
		$refund->save();
	}

	/**
	 * Remove refund parameters.
	 *
	 * @param $order_id
	 * @param $refund_id
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function remove_refund_parameters( $order_id, $refund_id ) {
		delete_transient( 'sb_refund_parameters_' . $order_id );
	}

	/**
	 * Controls native Refund button.
	 *
	 * @param bool     $should_render
	 * @param mixed    $order_id
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function order_should_render_refunds( $should_render, $order_id, $order ) {
		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return $should_render;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return $should_render;
		}

		$can_refund = $gateway->api->can_refund( $order );
		if ( ! $can_refund ) {
			return false;
		}

		return $should_render;
	}
}

new Swedbank_Pay_Admin();
