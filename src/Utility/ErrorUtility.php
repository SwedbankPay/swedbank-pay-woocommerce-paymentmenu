<?php
namespace Krokedil\Swedbank\Pay\Utility;

use WP_Error;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for turning a failed API call into a shopper-safe message.
 */
class ErrorUtility {
	/**
	 * Message shown when the shopper's phone number format is invalid.
	 *
	 * @hook swedbank_pay_invalid_phone_message
	 * @return string
	 */
	public static function get_invalid_phone_message() {
		/**
		 * Filters the message shown to the customer when the phone number has an invalid format.
		 *
		 * @param string $message The error message.
		 */
		return apply_filters( 'swedbank_pay_invalid_phone_message', __( 'Your phone number format is wrong. Please input with country code, for example like this +46707777777', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Message shown when the shopper's street address is too long.
	 *
	 * @hook swedbank_pay_invalid_street_address_message
	 * @return string
	 */
	public static function get_invalid_street_address_message() {
		/**
		 * Filters the message shown to the customer when the street address is too long or contains invalid characters.
		 *
		 * @param string $message The error message.
		 */
		return apply_filters( 'swedbank_pay_invalid_street_address_message', __( 'Street address can have a max length of 40 and only contain normal characters', 'swedbank-pay-payment-menu' ) );
	}

	/**
	 * Build the message to show the shopper for a failed API call.
	 *
	 * @param WP_Error      $error The error returned by the API.
	 * @param WC_Order|null $order The order being processed, if one exists yet.
	 *
	 * @return string
	 */
	public static function customer_message( $error, $order = null ) {
		$message = $error->get_error_message();

		// Swedbank_Pay_Api::format_error_message() already produces a safe, actionable
		// message for these two problems (invalid phone number, street address too long).
		$actionable_messages = array(
			self::get_invalid_phone_message(),
			self::get_invalid_street_address_message(),
		);

		if ( in_array( $message, $actionable_messages, true ) ) {
			return $message;
		}

		if ( null !== $order ) {
			return sprintf(
				// translators: %s: order number.
				__( 'Something went wrong. Please try again, or contact the store and provide the order number %s if the problem continues.', 'swedbank-pay-payment-menu' ),
				$order->get_order_number()
			);
		}

		return __( 'Something went wrong. Please try again.', 'swedbank-pay-payment-menu' );
	}
}
