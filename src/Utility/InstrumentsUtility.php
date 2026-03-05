<?php
namespace Krokedil\Swedbank\Pay\Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for helper functions related to blocks.
 */
class InstrumentsUtility {
	public static array $instruments = array(
		'credit_card' => array(
			'instrument' => 'CreditCard',
			'name' => 'Credit Card',
		),
		'invoice_payex_financing_se' => array(
			'instrument' => 'Invoice-PayExFinancingSe',
			'name' => 'Invoice PayEx Financing SE',
		),
		'swish' => array(
			'instrument' => 'Swish',
			'name' => 'Swish',
		),
		'credit_account_credit_account_se' => array(
			'instrument' => 'CreditAccount-CreditAccountSe',
			'name' => 'Credit Account SE',
		),
		'trustly' => array(
			'instrument' => 'Trustly',
			'name' => 'Trustly',
		),
		'mobile_pay' => array(
			'instrument' => 'MobilePay',
			'name' => 'Mobile Pay',
		),
		'apple_pay' => array(
			'instrument' => 'ApplePay',
			'name' => 'Apple Pay',
		),
		'google_pay' => array(
			'instrument' => 'GooglePay',
			'name' => 'Google Pay',
		),
		'click_to_pay' => array(
			'instrument' => 'ClickToPay',
			'name' => 'Click To Pay',
		),
	);

	/**
	 * See if the given instrument is enabled in the settings or not.
	 *
	 * @param string $instrument_key The key of the instrument to check, e.g. 'credit_card'.
	 *
	 * @return bool True if the instrument is enabled, false otherwise.
	 */
	public static function is_instrument_enabled( $instrument_key ) {
		// If the general setting for separate instruments is not enabled, we can return false directly.
		if ( ! wc_string_to_bool( SettingsUtility::get_setting( 'enable_separate_instruments', 'no' ) ) ) {
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
		// If the general setting for separate instruments is not enabled, we can return an empty array directly.
		if ( ! wc_string_to_bool( SettingsUtility::get_setting( 'enable_separate_instruments', 'no' ) ) ) {
			return array();
		}

		$enabled_instruments = array();

		foreach ( self::$instruments as $key => $instrument ) {
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

		return self::$instruments[ $instrument ]['instrument'] ?? null;
	}

}
