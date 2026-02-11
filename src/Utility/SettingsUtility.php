<?php
namespace Krokedil\Swedbank\Pay\Utility;

use Swedbank_Pay_Payment_Gateway_Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for helper functions related to plugin settings.
 */
class SettingsUtility {
	/**
	 * Holds the settings for the plugin.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Get the settings for Swedbank gateway.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null === self::$settings ) {
			self::$settings = get_option( 'woocommerce_payex_checkout_settings', array() );

			// Merge with default values, and ensure all settings are present.
			$defaults       = self::get_default_values();
			self::$settings = wp_parse_args( self::$settings, $defaults );
		}

		return self::$settings;
	}

	/**
	 * Get the value of a specific setting.
	 *
	 * @param string $key           The key of the setting to retrieve.
	 * @param mixed  $default_value The default value to return if the setting is not found.
	 *
	 * @return mixed
	 */
	public static function get_setting( $key, $default_value = null ) {
		$settings = self::get_settings();

		$value = $settings[ $key ] ?? $default_value;

		return $value;
	}

	/**
	 * Check if the gateway is enabled or not.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = self::get_setting( 'enabled', 'no' );

		return wc_string_to_bool( $enabled );
	}

	/**
	 * Check if testmode is enabled or not.
	 *
	 * @return bool
	 */
	public static function is_testmode() {
		$testmode = self::get_setting( 'testmode', 'no' );

		return wc_string_to_bool( $testmode );
	}

	/**
	 * Check if the current flow is embedded.
	 *
	 * @return bool
	 */
	public static function is_embedded_inline_flow() {
		$flow = self::get_setting( 'checkout_flow', 'redirect' );

		return 'embedded_inline' === $flow;
	}

	/**
	 * Check if the current flow is redirect.
	 *
	 * @return bool
	 */
	public static function is_redirect_flow() {
		$flow = self::get_setting( 'checkout_flow', 'redirect' );

		return 'redirect' === $flow;
	}

	/**
	 * Get the gateway class.
	 *
	 * @return Swedbank_Pay_Payment_Gateway_Checkout|null
	 */
	public static function get_gateway_class() {
		// Get Payment Gateway
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['payex_checkout'] ) ) {
			return null;
		}

		/** @var \WC_Payment_Gateway $gateway */
		return $gateways['payex_checkout'];
	}

	/**
	 * Get the default values for the settings.
	 *
	 * @return array
	 */
	private static function get_default_values() {
		$gateway = self::get_gateway_class();
		// Get the WC Settings API definitions for the Swedbank Gateway.
		$form_fields = $gateway ? $gateway->form_fields : array();

		$defaults = array();
		foreach ( $form_fields as $key => $field ) {
			if ( isset( $field['default'] ) ) {
				$defaults[ $key ] = $field['default'];
			}
		}

		return $defaults;
	}
}
