<?php // phpcs:disable
/*
 * Plugin Name: Swedbank Pay Payment Menu
 * Plugin URI: https://www.swedbankpay.com/
 * Description: Provides the Swedbank Pay Payment Menu for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 3.0.0
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
		// Check if WooCommerce is missing
		if ( ! class_exists( 'WooCommerce', false ) || ! defined( 'WC_ABSPATH' ) ) {
			add_action( 'admin_notices', __CLASS__ . '::missing_woocommerce_notice' );

			return;
		}

		include_once( dirname( __FILE__ ) . '/includes/class-swedbank-pay-payment-gateway-checkout.php' );

		// Register Gateway
		Swedbank_Pay_Payment_Menu::register_gateway( Swedbank_Pay_Payment_Gateway_Checkout::class );
	}

	/**
	 * Check if WooCommerce is missing, and deactivate the plugin if needs
	 */
	public static function missing_woocommerce_notice() {
		?>
		<div id="message" class="error">
			<p class="main">
				<strong><?php echo esc_html__( 'WooCommerce is inactive or missing.', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
			</p>
			<p>
				<?php
				echo esc_html__( 'WooCommerce plugin is inactive or missing. Please install and active it.', 'swedbank-pay-woocommerce-checkout' );
				echo '<br />';
				echo sprintf(
				/* translators: 1: plugin name */                        esc_html__(  //phpcs:ignore
					'%1$s will be deactivated.',
					'swedbank-pay-woocommerce-checkout'
				),
					self::PLUGIN_NAME
				);

				?>
			</p>
		</div>
		<?php

		// Deactivate the plugin
		deactivate_plugins( plugin_basename( __FILE__ ), true );
	}
}

new Swedbank_Pay_Payment_Menu();
