<?php
namespace Krokedil\Swedbank\Pay\Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for helper functions related to the instruments and separate payment methods.
 */
class InstrumentsUtility {
	/**
	 * Get all the instruments for Swedbank Pay: the known ones, plus any other the account reports as activated.
	 *
	 * @return array{string: array{instrument: string, name: string, supports: string[] } }
	 */
	public static function get_instruments() {
		$instruments = self::get_known_instruments();

		$known_base_names = array_map( array( self::class, 'get_base_instrument' ), array_column( $instruments, 'instrument' ) );

		// Offer instruments the account reports but this plugin doesn't know yet, labelled with their API name.
		foreach ( self::get_account_instruments() ?? array() as $account_instrument ) {
			$key = sanitize_key( strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $account_instrument ) ) );
			if ( empty( $key ) || isset( $instruments[ $key ] ) || in_array( $account_instrument, $known_base_names, true ) ) {
				continue;
			}

			$instruments[ $key ] = array(
				'instrument' => $account_instrument,
				'name'       => $account_instrument,
			);
		}

		return $instruments;
	}

	/**
	 * Get the instruments this plugin has a translated name and supports list for.
	 *
	 * The keys form the gateway id and settings keys, so they must never change.
	 *
	 * @return array{string: array{instrument: string, name: string, supports: string[] } }
	 */
	private static function get_known_instruments() {
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
	 * The option name used to store the instruments activated on the Swedbank Pay account.
	 *
	 * @var string
	 */
	const ACCOUNT_INSTRUMENTS_OPTION = 'swedbank_pay_account_instruments';

	/**
	 * Instruments the configurations endpoint never reports, so they are never locked or filtered out.
	 *
	 * BankLink is offered today but absent from the response; pending Swedbank Pay on what it is called there.
	 *
	 * @var string[]
	 */
	const UNREPORTED_INSTRUMENTS = array( 'BankLink' );

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
	 * Get all enabled instruments based on the settings, limited to the ones activated on the Swedbank Pay account.
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
			if ( self::is_instrument_enabled( $key ) && self::is_instrument_available( $instrument['instrument'] ) ) {
				$enabled_instruments[ $key ] = $instrument;
			}
		}

		return $enabled_instruments;
	}

	/**
	 * Get the instruments enabled in the settings but not activated on the Swedbank Pay account.
	 *
	 * @return array Instruments keyed like get_instruments().
	 */
	public static function get_enabled_unavailable_instruments() {
		// Checked once up front: it parses the checkout page's blocks, and is_instrument_enabled() repeats it per key.
		if ( ! SettingsUtility::is_separate_instruments_enabled() ) {
			return array();
		}

		$unavailable_instruments = array();

		foreach ( self::get_instruments() as $key => $instrument ) {
			if ( wc_string_to_bool( SettingsUtility::get_setting( "enable_instrument_$key", 'no' ) ) && ! self::is_instrument_available( $instrument['instrument'] ) ) {
				$unavailable_instruments[ $key ] = $instrument;
			}
		}

		return $unavailable_instruments;
	}

	/**
	 * Get the instruments activated on the Swedbank Pay account, as stored from the last successful fetch.
	 *
	 * Null means unknown (never fetched, or fetched for another payee id/mode) and callers should fail open.
	 *
	 * @return string[]|null Base instrument names (e.g. 'CreditCard', 'Invoice'), or null if unknown.
	 */
	public static function get_account_instruments() {
		$stored = get_option( self::ACCOUNT_INSTRUMENTS_OPTION, null );
		if ( ! is_array( $stored['instruments'] ?? null ) || ( $stored['cache_key'] ?? null ) !== self::get_account_instruments_cache_key() ) {
			return null;
		}

		return $stored['instruments'];
	}

	/**
	 * Check whether the given instrument is activated on the Swedbank Pay account.
	 *
	 * Returns true when the account's instruments are unknown, so a failed fetch never removes a payment method.
	 *
	 * @param string $instrument The instrument name, e.g. 'Invoice-PayExFinancingSe'.
	 *
	 * @return bool
	 */
	public static function is_instrument_available( $instrument ) {
		$account_instruments = self::get_account_instruments();
		$base_instrument     = self::get_base_instrument( $instrument );
		if ( null === $account_instruments || in_array( $base_instrument, self::UNREPORTED_INSTRUMENTS, true ) ) {
			return true;
		}

		return in_array( $base_instrument, $account_instruments, true );
	}

	/**
	 * Strip the sub-type from an instrument name, e.g. 'Invoice-PayExFinancingSe' becomes 'Invoice'.
	 *
	 * The account configuration reports base names, while this plugin stores and sends sub-typed ones.
	 *
	 * @param string $instrument The instrument name.
	 *
	 * @return string
	 */
	private static function get_base_instrument( $instrument ) {
		return strtok( $instrument, '-' );
	}

	/**
	 * Fetch and store the instruments activated on the Swedbank Pay account, keeping the old value on failure.
	 *
	 * @return void
	 */
	public static function refresh_account_instruments() {
		$gateway = SettingsUtility::get_gateway_class();
		if ( ! $gateway || empty( $gateway->access_token ) || empty( $gateway->payee_id ) ) {
			return;
		}

		$result = $gateway->api->request( 'GET', '/psp/paymentorders/configurations' );
		if ( is_wp_error( $result ) ) {
			// The request already logged the failure; keep the last known-good value.
			return;
		}

		$operations         = is_array( $result['operations'] ?? null ) ? $result['operations'] : array();
		$purchase_operation = current( wp_list_filter( $operations, array( 'rel' => 'Purchase' ) ) );
		$instruments        = $purchase_operation['availableInstruments'] ?? null;

		// Keep only name-shaped strings: the list is rendered in the settings and read on every gateway build.
		$valid_instruments = is_array( $instruments ) ? array_values( array_filter( $instruments, array( self::class, 'is_instrument_name' ) ) ) : array();

		// An explicit empty list is a valid answer; a missing list, or one emptied by the filter, is not.
		if ( ! is_array( $instruments ) || ( empty( $valid_instruments ) && ! empty( $instruments ) ) ) {
			Swedbank_Pay()->logger()->error(
				'[INSTRUMENTS]: Unexpected configurations response, keeping the last known activated instruments.',
				array( 'response' => wp_json_encode( $result ) )
			);

			return;
		}

		update_option(
			self::ACCOUNT_INSTRUMENTS_OPTION,
			array(
				'cache_key'   => self::get_account_instruments_cache_key(),
				'instruments' => $valid_instruments,
			)
		);
	}

	/**
	 * Check that a value from the configurations response looks like an instrument name, e.g. 'CreditCard'.
	 *
	 * @param mixed $value The value to check.
	 *
	 * @return bool
	 */
	private static function is_instrument_name( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9-]{1,64}$/', $value );
	}

	/**
	 * Build the cache key identifying which payee id/mode a stored account-instruments value belongs to.
	 *
	 * @return string
	 */
	private static function get_account_instruments_cache_key() {
		// Read the option directly: this runs while the gateway is constructed, and SettingsUtility's first load builds the gateway list.
		$settings = get_option( 'woocommerce_payex_checkout_settings', array() );

		return md5( ( $settings['payee_id'] ?? '' ) . '|' . wc_bool_to_string( $settings['testmode'] ?? 'no' ) );
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
