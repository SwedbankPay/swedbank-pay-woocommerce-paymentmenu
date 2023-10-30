<?php // phpcs:disable
/*
 * Plugin Name: Swedbank Pay Payment Menu
 * Plugin URI: https://www.swedbankpay.com/
 * Description: Provides the Swedbank Pay Payment Menu for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 1.0.2
 * Text Domain: swedbank-pay-woocommerce-checkout
 * Domain Path: /languages
 * WC requires at least: 5.5.1
 * WC tested up to: 8.1.1
 */

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Plugin;

defined( 'ABSPATH' ) || exit;

include_once( dirname( __FILE__ ) . '/includes/class-swedbank-pay-plugin.php' );

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Payment_Menu extends Swedbank_Pay_Plugin {
	const TEXT_DOMAIN = 'swedbank-pay-woocommerce-checkout';
	// phpcs:enable

	/**
	 * Constructor
	 */
	public function __construct() {
		define( 'SwedbankPay\Checkout\WooCommerce\PLUGIN_PATH', plugin_basename( __FILE__ ) );

		if ( in_array( 'swedbank-pay-checkout/swedbank-pay-woocommerce-checkout.php', get_option( 'active_plugins' ) ) ) { //phpcs:ignore
			return;
		}

		parent::__construct();

		// Activation
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Actions
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_loaded', array( $this, 'woocommerce_loaded' ), 30 );
		add_action(
			'before_woocommerce_init',
			function() {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						__FILE__,
						true
					);
				}
			}
		);
	}

	/**
	 * Init localisations and files
	 * @return void
	 */
	public function init() {
		// Localization
		load_plugin_textdomain(
			'swedbank-pay-woocommerce-checkout',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * WooCommerce Loaded: load classes
	 * @return void
	 */
	public function woocommerce_loaded() {
		include_once( dirname( __FILE__ ) . '/includes/class-swedbank-pay-payment-gateway-checkout.php' );

		// Register Gateway
		Swedbank_Pay_Payment_Menu::register_gateway( Swedbank_Pay_Payment_Gateway_Checkout::class );
	}
}

new Swedbank_Pay_Payment_Menu();
