<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

class Swedbank_Intl_Tel {
	const VENDOR_VERSION = '25.15.1';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'woocommerce_after_register_post_type', array( $this, 'woocommerce_init' ), 100 );
	}

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
	 * @param array $form_fields
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

	public function scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		$settings = get_option( 'woocommerce_payex_checkout_settings', array( 'enable_intl_tel' => 'no' ) );
		if ( ! isset( $settings['enable_intl_tel'] ) ) {
			$settings['enable_intl_tel'] = 'no';
		}

		if ( 'no' === $settings['enable_intl_tel'] ) {
			return;
		}

		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$assets_url = untrailingslashit( plugins_url( '/', __FILE__ ) ) . '/../assets';

		wp_enqueue_style(
			'swedbank-intl-tel-css',
			$assets_url . '/css/intlTelInput' . $suffix . '.css',
			array(),
			self::VENDOR_VERSION,
			'all'
		);

		wp_register_script(
			'swedbank-intl-tel-js',
			$assets_url . '/js/intlTelInput' . $suffix . '.js',
			array(),
			self::VENDOR_VERSION,
			true
		);

		wp_register_script(
			'swedbank-wc-intl-tel-js',
			$assets_url . '/js/wc-intl-tel' . $suffix . '.js',
			array( 'swedbank-intl-tel-js' ),
			SWEDBANK_PAY_VERSION,
			true
		);

		wp_localize_script(
			'swedbank-intl-tel-js',
			'WC_Gateway_Swedbank_Pay_Intl_Tel',
			array(
				'utils_script' => $assets_url . '/js/utils.js',
				'country'      => $this->get_country(),
				'country_order' => $this->get_country_order(),
				'i18n'         => $this->get_i18n_strings(),
			)
		);

		wp_enqueue_script( 'swedbank-intl-tel-js' );
		wp_enqueue_script( 'swedbank-wc-intl-tel-js' );
	}

	/**
	 * Country ISO code based on the IP address of the client.
	 *
	 * @return string
	 */
	private function get_country() {
		if ( function_exists( 'geoip_detect2_get_info_from_ip' ) ) {
			return geoip_detect2_get_info_from_ip( geoip_detect2_get_client_ip() )->country->isoCode;
		}

		return \WC_Geolocation::geolocate_ip()['country'];
	}

	/**
	 * Preferred countries shown first in the dropdown, ordered with the geo-detected country first.
	 *
	 * @return string[]
	 */
	private function get_country_order() {
		$preferred = array( 'SE', 'NO', 'FI', 'DK' );
		$detected  = $this->get_country();

		if ( $detected && ! in_array( $detected, $preferred, true ) ) {
			array_unshift( $preferred, $detected );
		} elseif ( $detected ) {
			$preferred = array_merge( array( $detected ), array_diff( $preferred, array( $detected ) ) );
		}

		return $preferred;
	}

	/**
	 * Translated UI strings for the current site locale. Country names remain
	 * in the library default (English) — those translations are ES modules
	 * upstream and need a JS bundler to ship, which this plugin does not have.
	 * The strings here cover the parts the user directly reads during the
	 * checkout flow (search, aria labels). Wrapped in __() so translators can
	 * override per WP-locale via the regular .po workflow.
	 *
	 * @return array<string,string>
	 */
	private function get_i18n_strings() {
		$wp_locale = get_locale();
		$lang      = substr( $wp_locale, 0, 2 );

		if ( in_array( $wp_locale, array( 'nb_NO', 'nn_NO' ), true ) ) {
			$lang = 'no';
		}

		$translations = array(
			'sv' => array(
				'searchPlaceholder'        => __( 'Sök', 'swedbank-pay-payment-menu' ),
				'selectedCountryAriaLabel' => __( 'Valt land', 'swedbank-pay-payment-menu' ),
				'countryListAriaLabel'     => __( 'Lista över länder', 'swedbank-pay-payment-menu' ),
				'oneSearchResult'          => __( '1 resultat hittades', 'swedbank-pay-payment-menu' ),
				'multipleSearchResults'    => __( '${count} resultat hittades', 'swedbank-pay-payment-menu' ),
				'zeroSearchResults'        => __( 'Inga resultat hittades', 'swedbank-pay-payment-menu' ),
				'noCountrySelected'        => __( 'Inget land valt', 'swedbank-pay-payment-menu' ),
			),
			'no' => array(
				'searchPlaceholder'        => __( 'Søk', 'swedbank-pay-payment-menu' ),
				'selectedCountryAriaLabel' => __( 'Valgt land', 'swedbank-pay-payment-menu' ),
				'countryListAriaLabel'     => __( 'Liste over land', 'swedbank-pay-payment-menu' ),
				'oneSearchResult'          => __( '1 resultat funnet', 'swedbank-pay-payment-menu' ),
				'multipleSearchResults'    => __( '${count} resultater funnet', 'swedbank-pay-payment-menu' ),
				'zeroSearchResults'        => __( 'Ingen resultater funnet', 'swedbank-pay-payment-menu' ),
				'noCountrySelected'        => __( 'Ingen land er valgt', 'swedbank-pay-payment-menu' ),
			),
			'da' => array(
				'searchPlaceholder'        => __( 'Søg', 'swedbank-pay-payment-menu' ),
				'selectedCountryAriaLabel' => __( 'Valgt land', 'swedbank-pay-payment-menu' ),
				'countryListAriaLabel'     => __( 'Liste over lande', 'swedbank-pay-payment-menu' ),
				'oneSearchResult'          => __( '1 resultat fundet', 'swedbank-pay-payment-menu' ),
				'multipleSearchResults'    => __( '${count} resultater fundet', 'swedbank-pay-payment-menu' ),
				'zeroSearchResults'        => __( 'Ingen resultater fundet', 'swedbank-pay-payment-menu' ),
				'noCountrySelected'        => __( 'Intet land er valgt', 'swedbank-pay-payment-menu' ),
			),
			'fi' => array(
				'searchPlaceholder'        => __( 'Haku', 'swedbank-pay-payment-menu' ),
				'selectedCountryAriaLabel' => __( 'Valittu maa', 'swedbank-pay-payment-menu' ),
				'countryListAriaLabel'     => __( 'Luettelo maista', 'swedbank-pay-payment-menu' ),
				'oneSearchResult'          => __( '1 tulos löytyi', 'swedbank-pay-payment-menu' ),
				'multipleSearchResults'    => __( '${count} tulosta löytyi', 'swedbank-pay-payment-menu' ),
				'zeroSearchResults'        => __( 'Ei tuloksia', 'swedbank-pay-payment-menu' ),
				'noCountrySelected'        => __( 'Maata ei ole valittu', 'swedbank-pay-payment-menu' ),
			),
		);

		return isset( $translations[ $lang ] ) ? $translations[ $lang ] : array();
	}
}

new Swedbank_Intl_Tel();
