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

		// This runs while the gateway is constructed, so read the settings without defaults.
		$settings = SettingsUtility::get_stored_settings();

		// An instrument the account has since dropped stays listed while enabled, so its gateway remains for refunds and it can be disabled.
		$derived = array_filter(
			(array) get_option( self::DERIVED_INSTRUMENTS_OPTION, array() ),
			function ( $name, $key ) use ( $settings ) {
				return self::is_instrument_name( $name ) && wc_string_to_bool( $settings[ "enable_instrument_$key" ] ?? 'no' );
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Offer instruments the account reports but this plugin doesn't know yet, labelled with their API name.
		foreach ( array_merge( self::get_account_instruments() ?? array(), array_values( $derived ) ) as $name ) {
			$key = self::get_derived_key( $name );
			if ( isset( $instruments[ $key ] ) || ! self::is_derived_instrument( $name, $key ) ) {
				continue;
			}

			$instruments[ $key ] = array(
				'instrument' => $name,
				'name'       => $name,
			);
		}

		return $instruments;
	}

	/**
	 * Derive the key for an instrument this plugin doesn't list, e.g. 'PayByBank' becomes 'pay_by_bank'.
	 *
	 * The key forms the gateway id and settings keys, so an entry added to get_known_instruments() later must reuse it.
	 *
	 * @param string $instrument The instrument name from the configurations response.
	 *
	 * @return string
	 */
	private static function get_derived_key( $instrument ) {
		return sanitize_key( strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $instrument ) ) );
	}

	/**
	 * Check whether a reported instrument is one this plugin doesn't list, and its derived key is usable.
	 *
	 * @param string $instrument The instrument name from the configurations response.
	 * @param string $key        Its key from get_derived_key().
	 *
	 * @return bool
	 */
	private static function is_derived_instrument( $instrument, $key ) {
		return ! empty( $key ) && ! isset( self::get_known_instruments()[ $key ] ) && ! self::is_known_instrument( $instrument );
	}

	/**
	 * Check whether an instrument name from the configurations response is one this plugin lists, by full or base name.
	 *
	 * @param string $instrument The instrument name, e.g. 'Invoice' or 'Invoice-PayExFinancingSe'.
	 *
	 * @return bool
	 */
	private static function is_known_instrument( $instrument ) {
		$known_names = array_column( self::get_known_instruments(), 'instrument' );

		return in_array( $instrument, $known_names, true ) || in_array( $instrument, array_map( array( self::class, 'get_base_instrument' ), $known_names ), true );
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
	 * The option name used to store every derived instrument, i.e. one the account reported that this plugin doesn't list.
	 *
	 * @var string
	 */
	const DERIVED_INSTRUMENTS_OPTION = 'swedbank_pay_derived_instruments';

	/**
	 * How long a stored account-instruments value is trusted when refreshes keep failing, in seconds.
	 *
	 * @var int
	 */
	const ACCOUNT_INSTRUMENTS_MAX_AGE = 7 * DAY_IN_SECONDS;

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
	 * Get all enabled instruments based on the settings, which decides the separate gateways that get registered.
	 *
	 * An instrument not activated on the account is included so refunds on its orders still work; is_available() hides it at checkout.
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
	 * Null means unknown (never fetched, fetched for another payee id/mode, or not refreshed for a week) and callers should fail open.
	 *
	 * @return string[]|null Instrument names (e.g. 'CreditCard', 'Invoice'), or null if unknown.
	 */
	public static function get_account_instruments() {
		$stored = get_option( self::ACCOUNT_INSTRUMENTS_OPTION, null );
		if ( ! is_array( $stored['instruments'] ?? null ) || ( $stored['cache_key'] ?? null ) !== self::get_account_instruments_cache_key() ) {
			return null;
		}

		if ( time() - (int) ( $stored['fetched_at'] ?? 0 ) > self::ACCOUNT_INSTRUMENTS_MAX_AGE ) {
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

		// The endpoint has reported base names so far, e.g. 'Invoice'; accept the exact sub-typed name too, should it report those.
		return in_array( $instrument, $account_instruments, true ) || in_array( $base_instrument, $account_instruments, true );
	}

	/**
	 * Strip the sub-type from an instrument name, e.g. 'Invoice-PayExFinancingSe' becomes 'Invoice'.
	 *
	 * The account configuration has reported base names, while this plugin stores and sends sub-typed ones.
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

		LogUtility::$title = '[INSTRUMENTS]: Fetch the instruments activated on the account';
		$result            = $gateway->api->request( 'GET', '/psp/paymentorders/configurations' );
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
				'fetched_at'  => time(),
			)
		);

		self::remember_derived_instruments( $valid_instruments );
	}

	/**
	 * Store the derived instruments the account reports, so they stay known once the account drops them.
	 *
	 * @param string[] $instruments Instrument names from the configurations response.
	 *
	 * @return void
	 */
	private static function remember_derived_instruments( $instruments ) {
		$derived = (array) get_option( self::DERIVED_INSTRUMENTS_OPTION, array() );
		$updated = $derived;

		foreach ( $instruments as $name ) {
			$key = self::get_derived_key( $name );
			if ( ! isset( $updated[ $key ] ) && self::is_derived_instrument( $name, $key ) ) {
				$updated[ $key ] = $name;
			}
		}

		if ( $updated !== $derived ) {
			update_option( self::DERIVED_INSTRUMENTS_OPTION, $updated );
		}
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
		$settings = SettingsUtility::get_stored_settings();

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
