<?php
/**
 * Plugin Name: Swedbank Pay Payment Menu
 * Plugin URI: https://www.swedbankpay.com/
 * Description: Provides the Swedbank Pay Payment Menu for WooCommerce.
 * Author: Swedbank Pay
 * Author URI: https://profiles.wordpress.org/swedbankpay/
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 4.0.0-beta.1
 * Text Domain: swedbank-pay-woocommerce-checkout
 * Domain Path: /languages
 *
 * WC requires at least: 5.5.1
 * WC tested up to: 9.3.1
 * Requires Plugins: woocommerce
 *
 * @package SwedbankPay
 */

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Plugin;
use KrokedilSwedbankPayDeps\Krokedil\Support\Logger;
use KrokedilSwedbankPayDeps\Krokedil\Support\SystemReport;


defined( 'ABSPATH' ) || exit;
define( 'SWEDBANK_PAY_VERSION', '4.0.0-beta.1' );
define( 'SWEDBANK_PAY_MAIN_FILE', __FILE__ );
define( 'SWEDBANK_PAY_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'SWEDBANK_PAY_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'SwedbankPay\Checkout\WooCommerce\PLUGIN_PATH', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/class-swedbank-pay-plugin.php';

/**
 * This class is the main entry point for the Swedbank Pay Payment Menu plugin.
 *
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Payment_Menu extends Swedbank_Pay_Plugin {
	public const TEXT_DOMAIN = 'swedbank-pay-woocommerce-checkout';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Support instance.
	 *
	 * @var SystemReport
	 */
	private $system_report;


	/**
	 * Instance of the class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Get the instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	private function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Nope' ), '1.0' );
	}

	/**
	 * Private unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Nope' ), '1.0' );
	}

	/**
	 * Logger instance.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * SystemReport instance.
	 *
	 * @return SystemReport
	 */
	public function system_report() {
		return $this->system_report;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// If the plugin is already loaded, do not load it again.
		if ( in_array( 'swedbank-pay-checkout/swedbank-pay-woocommerce-checkout.php', get_option( 'active_plugins' ), true ) ) {
			return;
		}

		// Initialize the parent class before the plugin is loaded.
		// This is required since the 'install' method is dependant on classes being loaded by the parent class.
		parent::__construct();

		add_action( 'plugin_loaded', array( $this, 'init' ) );

		// Activation.
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Actions.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Declare feature compatibility. Anonymous function is OK in this case, since this should not be easily removable.
		add_action(
			'before_woocommerce_init',
			function () {
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
	 * Initialize and register the gateway.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::init_composer() ) {
			return;
		}

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once __DIR__ . '/includes/class-swedbank-pay-payment-gateway-checkout.php';

		$plugin_settings = get_option( 'woocommerce_payex_checkout_settings', array() );
		$this->logger    = new Logger( 'swedbank_pay', wc_string_to_bool( $plugin_settings['logger'] ?? true ) );

		$system_report_options = array(
			array(
				'type' => 'checkbox',
			),
			array(
				'type' => 'select',
			),
			array(
				'type'    => 'text',
				'exclude' => array(
					'title' => 'Access Token',
				),
			),
		);
		$this->system_report   = new SystemReport( 'payex_checkout', 'Swedbank Pay', $system_report_options );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
	}

	/**
	 * Add the gateways to WooCommerce.
	 *
	 * @param array $methods Payment methods.
	 * @return array Payment methods with Dintero added.
	 */
	public function add_gateways( $methods ) {
		$methods[] = Swedbank_Pay_Payment_Gateway_Checkout::class;

		return $methods;
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'swedbank-pay-woocommerce-checkout',
			false,
			SWEDBANK_PAY_PLUGIN_PATH . '/languages'
		);
	}


	/**
	 * Try to load the autoloader from Composer.
	 *
	 * @return bool Whether the autoloader was successfully loaded.
	 */
	public function init_composer() {
		$autoloader              = __DIR__ . '/vendor/autoload.php';
		$autoloader_dependencies = __DIR__ . '/dependencies/scoper-autoload.php';

		// Check if the autoloaders was read.
		$autoloader_result              = is_readable( $autoloader ) && require $autoloader;
		$autoloader_dependencies_result = is_readable( $autoloader_dependencies ) && require $autoloader_dependencies;
		if ( ! $autoloader_result || ! $autoloader_dependencies_result ) {
			self::missing_autoloader();
			return false;
		}

		return true;
	}

	/**
	 * Print error message if the composer autoloader is missing.
	 *
	 * @return void
	 */
	protected static function missing_autoloader() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	        error_log( // phpcs:ignore
				esc_html__( 'Your installation of Swedbank Pay is not complete. If you installed this plugin directly from Github please refer to the readme.dev.txt file in the plugin.', 'swedbank-pay-woocommerce-checkout' )
			);
		}
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-error">
				<p>
					<?php echo esc_html__( 'Your installation of Swedbank Pay is not complete. If you installed this plugin directly from Github please refer to the readme.dev.txt file in the plugin.', 'swedbank-pay-woocommerce-checkout' ); ?>
				</p>
			</div>
				<?php
			}
		);
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
				printf(
				/* translators: 1: plugin name */
					esc_html__(
						'%1$s will be deactivated.',
						'swedbank-pay-woocommerce-checkout'
					),
					self::PLUGIN_NAME //phpcs:ignore
				);

				?>
			</p>
		</div>
		<?php

		// Deactivate the plugin.
		deactivate_plugins( plugin_basename( __FILE__ ), true );
	}
}

/**
 * Get the instance of the plugin.
 *
 * @return Swedbank_Pay_Payment_Menu
 */
function Swedbank_Pay() {
	return Swedbank_Pay_Payment_Menu::get_instance();
}

Swedbank_Pay();
