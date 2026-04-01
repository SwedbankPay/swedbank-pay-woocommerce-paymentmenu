<?php
namespace Krokedil\Swedbank\Pay\Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for helper functions related to the instruments and separate payment methods.
 */
class InstrumentsUtility {
	/**
	 * Get all the instruments for Swedbank pay.
	 *
	 * @return array{string: array{instrument: string, name: string, supports: string[] } }
	 */
	public static function get_instruments() {
		return array(
			'credit_card'                      => array(
				'instrument' => 'CreditCard',
				'name'       => __( 'Card', 'swedbank-pay-payment-menu' ),
				'supports'   => array(
					'products',
					'refunds',
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin',
					'subscription_payment_method_change',
					'multiple_subscriptions',
				),
			),
			'invoice_payex_financing_se'       => array(
				'instrument' => 'Invoice-PayExFinancingSe',
				'name'       => __( 'Invoice', 'swedbank-pay-payment-menu' ),
			),
			'swish'                            => array(
				'instrument' => 'Swish',
				'name'       => __( 'Swish', 'swedbank-pay-payment-menu' ),
			),
			'credit_account_credit_account_se' => array(
				'instrument' => 'CreditAccount-CreditAccountSe',
				'name'       => __( 'Installment account', 'swedbank-pay-payment-menu' ),
			),
			'trustly'                          => array(
				'instrument' => 'Trustly',
				'name'       => __( 'Trustly (Bank transfer)', 'swedbank-pay-payment-menu' ),
			),
			'mobile_pay'                       => array(
				'instrument' => 'MobilePay',
				'name'       => __( 'MobilePay', 'swedbank-pay-payment-menu' ),
			),
			'apple_pay'                        => array(
				'instrument' => 'ApplePay',
				'name'       => __( 'Apple Pay', 'swedbank-pay-payment-menu' ),
			),
			'google_pay'                       => array(
				'instrument' => 'GooglePay',
				'name'       => __( 'Google Pay', 'swedbank-pay-payment-menu' ),
			),
			'click_to_pay'                     => array(
				'instrument' => 'ClickToPay',
				'name'       => __( 'Click to Pay', 'swedbank-pay-payment-menu' ),
			),
			'vipps'                            => array(
				'instrument' => 'Vipps',
				'name'       => __( 'Vipps', 'swedbank-pay-payment-menu' ),
			),
			'bank_link'                        => array(
				'instrument' => 'BankLink',
				'name'       => __( 'Pay by bank', 'swedbank-pay-payment-menu' ),
			),
		);
	}

	/**
	 * See if the given instrument is enabled in the settings or not.
	 *
	 * @param string $instrument_key The key of the instrument to check, e.g. 'credit_card'.
	 *
	 * @return bool True if the instrument is enabled, false otherwise.
	 */
	public static function is_instrument_enabled( $instrument_key ) {
		// If separate instruments are not enabled at all, we can return false directly.
		if ( ! SettingsUtility::is_separate_instruments_enabled() ) {
			return false;
		}

		return wc_string_to_bool( SettingsUtility::get_setting( "enable_instrument_$instrument_key", 'no' ) );
	}

	/**
	 * Get all enabled instruments based on the settings.
	 *
	 * @return array An array of enabled instruments, each instrument is an array with 'instrument' and 'name' keys.
	 */
	public static function get_enabled_instruments() {
		// If separate instruments are not enabled at all, we can return an empty array directly.
		if ( ! SettingsUtility::is_separate_instruments_enabled() ) {
			return array();
		}

		$enabled_instruments = array();

		foreach ( self::get_instruments() as $key => $instrument ) {
			if ( self::is_instrument_enabled( $key ) ) {
				$enabled_instruments[ $key ] = $instrument;
			}
		}

		return $enabled_instruments;
	}

	/**
	 * Get the instrument key by the given method id.
	 *
	 * @param string $method_id The method id to get the instrument key for, e.g. 'swedbank_pay_credit_card'.
	 *
	 * @return string|null The instrument key if found, null otherwise.
	 */
	public static function get_instrument_id_by_method_id( $method_id ) {
		$instrument = str_replace( 'swedbank_pay_', '', $method_id );

		return self::get_instruments()[ $instrument ]['instrument'] ?? null;
	}
}
