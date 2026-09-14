<?php
/**
 * International Telephone Input for the checkout phone field.
 *
 * @package SwedbankPay\Checkout\WooCommerce
 */

namespace SwedbankPay\Checkout\WooCommerce;

use Krokedil\Swedbank\Pay\Utility\BlocksUtility;

defined( 'ABSPATH' ) || exit;

/**
 * Enhances the checkout phone field with the intl-tel-input library.
 */
class Swedbank_Intl_Tel {
	/**
	 * Version of the bundled intl-tel-input library.
	 *
	 * Keep in sync with the pinned version in package.json; run
	 * `npm run build:vendor` after changing it.
	 *
	 * @var string
	 */
	const LIB_VERSION = '29.2.3';

	/**
	 * Script handle for the checkout wrapper.
	 *
	 * @var string
	 */
	const HANDLE = 'swedbank-wc-intl-tel-js';

	/**
	 * Locale overrides for the intl-tel-input library.
	 *
	 * @var array<string, string>
	 */
	private const LOCALE_OVERRIDES = array(
		'nb_NO' => 'no',
		'nn_NO' => 'no',
		'zh_HK' => 'zh-hk',
		'tl'    => 'fil',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// JS Scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

		// Load the wrapper as an ES module. See scripts() for why.
		add_filter( 'script_loader_tag', array( $this, 'script_loader_tag' ), 10, 3 );

		// Add settings.
		add_action( 'woocommerce_after_register_post_type', array( $this, 'woocommerce_init' ), 100 );
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init() {
		add_filter(
			'woocommerce_settings_api_form_fields_payex_checkout',
			array(
				$this,
				'add_settings',
			)
		);
	}

	/**
	 * Add settings
	 *
	 * @param array $form_fields The gateway's form fields.
	 *
	 * @return array
	 */
	public function add_settings( $form_fields ) {
		$form_fields['enable_intl_tel'] = array(
			'title'       => __( 'Enable International Telephone Input', 'swedbank-pay-payment-menu' ),
			'label'       => __( 'Enable International Telephone Input', 'swedbank-pay-payment-menu' ),
			'type'        => 'checkbox',
			'description' => __( 'Improves phone field using International Telephone Input. A JavaScript plugin for entering and validating international telephone numbers. It adds a flag dropdown to any input, detects the user\'s country, displays a relevant placeholder and provides formatting/validation methods.', 'swedbank-pay-payment-menu' ),
			'desc_tip'    => true,
			'default'     => 'no',
		);

		return $form_fields;
	}

	/**
	 * Whether the phone field should be enhanced on the current request.
	 *
	 * @return bool
	 */
	private function is_enabled() {

		if ( ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) {
			return false;
		}

		if ( BlocksUtility::is_checkout_block_enabled() ) {
			return false;
		}

		$settings = get_option( 'woocommerce_payex_checkout_settings', array() );

		if ( ! wc_string_to_bool( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}

		return wc_string_to_bool( $settings['enable_intl_tel'] ?? 'no' );
	}

	/**
	 * Enqueue the checkout assets.
	 *
	 * @return void
	 */
	public function scripts() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'swedbank-intl-tel-css',
			$this->vendor_url( "css/intlTelInput{$suffix}.css" ),
			array(),
			self::LIB_VERSION,
			'all'
		);

		wp_register_script(
			self::HANDLE,
			SWEDBANK_PAY_PLUGIN_URL . "/assets/js/wc-intl-tel{$suffix}.js",
			array( 'jquery' ),
			SWEDBANK_PAY_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'WC_Gateway_Swedbank_Pay_Intl_Tel', $this->get_params( $suffix ) );

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Data handed to the wrapper script.
	 *
	 * @param string $suffix Minified file suffix.
	 *
	 * @return array
	 */
	private function get_params( $suffix ) {
		$locale = $this->get_locale_file();

		$params = array(
			// Dynamic imports bypass the `ver` query argument that
			// wp_enqueue_script() would normally add, so carry it ourselves --
			// otherwise a library upgrade never reaches returning customers.
			'lib_script'    => $this->vendor_url( "js/intlTelInput{$suffix}.js" ),
			'utils_script'  => $this->vendor_url( 'js/utils.js' ),
			'locale_script' => $locale ? $this->vendor_url( "js/locale/{$locale}.js" ) : '',
			'name_locale'   => $this->get_country_name_locale(),
			'country'       => $this->get_country(),
			'country_order' => $this->get_country_order(),
		);

		/**
		 * Filter the options handed to intl-tel-input on the checkout.
		 *
		 * @param array $params
		 */
		return apply_filters( 'swedbank_pay_intl_tel_params', $params );
	}

	/**
	 * Build a URL to a bundled third-party asset, carrying the library version.
	 *
	 * @param string $path Path relative to the intl-tel-input vendor directory.
	 *
	 * @return string
	 */
	private function vendor_url( $path ) {
		return add_query_arg(
			'ver',
			self::LIB_VERSION,
			SWEDBANK_PAY_PLUGIN_URL . '/assets/vendor/intl-tel-input/' . $path
		);
	}

	/**
	 * Load the wrapper as an ES module so it can import the library.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 *
	 * @return string
	 */
	public function script_loader_tag( $tag, $handle, $src ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This rewrites the tag of an already enqueued script.
		return sprintf( '<script type="module" src="%s" id="%s-js"></script>' . "\n", esc_url( $src ), esc_attr( $handle ) );
	}

	/**
	 * Get the locale file name for the current site language, or null if none exists.
	 *
	 * @return string|null
	 */
	private function get_locale_file() {
		$locale = get_locale();
		$name   = self::LOCALE_OVERRIDES[ $locale ] ?? strtolower( strtok( $locale, '_-' ) );

		if ( ! preg_match( '/^[a-z-]{2,5}$/', $name ) ) {
			return null;
		}

		$path = SWEDBANK_PAY_PLUGIN_PATH . '/assets/vendor/intl-tel-input/js/locale/' . $name . '.js';

		return file_exists( $path ) ? $name : null;
	}

	/**
	 * Locale used to translate the country names via Intl.DisplayNames.
	 *
	 * @return string
	 */
	private function get_country_name_locale() {
		$locale = str_replace( '_', '-', get_locale() );
		$parts  = explode( '-', $locale );
		$tag    = strtolower( $parts[0] );

		if ( isset( $parts[1] ) && 2 === strlen( $parts[1] ) ) {
			$tag .= '-' . strtoupper( $parts[1] );
		}

		return preg_match( '/^[a-z]{2,3}(-[A-Z]{2})?$/', $tag ) ? $tag : 'en';
	}

	/**
	 * Countries to float to the top of the list.
	 *
	 * @return array
	 */
	private function get_country_order() {
		$countries = array_merge( array( $this->get_country() ), array( 'SE', 'NO', 'FI', 'DK' ) );

		return array_values( array_unique( array_filter( $countries ) ) );
	}

	/**
	 * Get the country ISO code to preselect in the country dropdown.
	 *
	 * @return string The country ISO code.
	 */
	private function get_country() {
		$customer = WC()->customer ?? null;
		if ( $customer && ! empty( $customer->get_billing_country() ) ) {
			return $customer->get_billing_country();
		}

		if ( function_exists( 'geoip_detect2_get_info_from_ip' ) ) {
			return geoip_detect2_get_info_from_ip( geoip_detect2_get_client_ip() )->country->isoCode;
		}

		$default = wc_get_customer_default_location();
		if ( ! empty( $default['country'] ) ) {
			return $default['country'];
		}

		return \WC_Geolocation::geolocate_ip( '', false, false )['country'];
	}
}

new Swedbank_Intl_Tel();
