<?php
/**
 * Class OrderManagement
 *
 * Handles order management actions.
 */

namespace Krokedil\Swedbank\Pay;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Plugin;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;

defined( 'ABSPATH' ) || exit;

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
		$settings = get_option( 'woocommerce_payex_checkout_settings', array() );

		// Here we default to 'no' although the actual default in the settings is 'yes'. This is to preserve existing behavior, and is indicated by the settings absence.
		if ( wc_string_to_bool( $settings['enable_order_capture'] ?? 'no' ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order' ), 10, 2 );
		}
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

		if ( Swedbank_Pay_Subscription::should_skip_order_management( $order ) ) {
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
			$order->add_order_note( __( 'Payment already captured.', 'swedbank-pay-payment-menu' ) );
			return;
		}

		$result = $gateway->api->capture_checkout( $order );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		} else {
			$this->save_captured_items( $order );
		}

		$captured_amount = wc_price( $result['amount'] / 100, array( 'currency' => $order->get_currency() ) );

		// Translators: %1$s is the transaction number, %2$s is the captured amount.
		$order->add_order_note( sprintf( __( 'Payment has been captured. Transaction: %1$s. Amount: %2$s.', 'swedbank-pay-payment-menu' ), $result['number'], $captured_amount ) );
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

	/**
	 * Saves captured items to order meta.
	 *
	 * @param \WC_Order $order The order to save captured items for.
	 */
	private function save_captured_items( $order ) {
		// At time of writing (v4.1.1), the plugin is saving the captured items to order meta '_payex_captured_items'.
		// This is used in swedbank_pay_get_available_line_items_for_refund() to determine what can be refunded.
		// This is not desired behavior. Instead, we should retrieve the refund items directly from the WC_Order_Refund object when processing a refund.
		// Until then, we have to keep this functionality to avoid breaking existing setups.

		$order_lines   = swedbank_pay_get_order_lines( $order );
		$current_items = $order->get_meta( '_payex_captured_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;

		foreach ( $order_lines as $captured_line ) {
			$is_found = false;
			foreach ( $current_items as &$current_item ) {
				if ( $current_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === $captured_line[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ) {
					$current_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] += $captured_line[ Swedbank_Pay_Order_Item::FIELD_QTY ];
					$is_found = true;

					break;
				}
			}

			if ( ! $is_found ) {
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_NAME ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_TYPE ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_CLASS ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_ITEM_URL ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_IMAGE_URL ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_UNITPRICE ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_QTY_UNIT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_AMOUNT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT ] );

				$current_items[] = $captured_line;
			}
		}

		$order->update_meta_data( '_payex_captured_items', $current_items );
		$order->save_meta_data();
		$order_lines = swedbank_pay_get_order_lines( $order );
	}
}
