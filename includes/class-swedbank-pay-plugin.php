<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use KrokedilSwedbankPayDeps\Ramsey\Uuid\Uuid;
/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Swedbank_Pay_Plugin {

	/** Payment IDs */
	const PAYMENT_METHODS = array(
		'payex_checkout',
	);

	const PLUGIN_NAME             = 'Swedbank Pay Payment Menu';
	const SUPPORT_EMAIL           = 'support.ecom@payex.com';
	const DB_VERSION              = '1.0.0';
	const DB_VERSION_SLUG         = 'swedbank_pay_menu_version';
	const ADMIN_UPGRADE_PAGE_SLUG = 'swedbank-pay-menu-upgrade';

	/**
	 * @var Swedbank_Pay_Background_Queue
	 */
	public static $background_process;

	/**
	 * Whether the composer autoloader has been initialized.
	 *
	 * @var bool
	 */
	protected $composer_initialized = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Check if the checkout plugin is active.
		if ( in_array( 'swedbank-pay-checkout/swedbank-pay-woocommerce-checkout.php', get_option( 'active_plugins' ) ) ) { //phpcs:ignore
			add_action( 'admin_notices', __CLASS__ . '::check_backward_compatibility', 40 );

			return;
		}

		// Includes.
		$this->includes();

		// Actions.
		add_filter(
			'plugin_action_links_' . constant( __NAMESPACE__ . '\PLUGIN_PATH' ),
			__CLASS__ . '::plugin_action_links'
		);
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_init', array( $this, 'woocommerce_init' ) );

		// Filters.
		add_filter( 'swedbank_pay_generate_uuid', array( $this, 'generate_uuid' ), 10, 1 );
		add_filter( 'swedbank_pay_order_billing_phone', __CLASS__ . '::billing_phone', 10, 2 );

		// Process swedbank queue.
		if ( ! is_multisite() ) {
			add_action( 'customize_save_after', array( $this, 'maybe_process_queue' ) );
			add_action( 'after_switch_theme', array( $this, 'maybe_process_queue' ) );
		}

		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );

		add_action( 'init', __CLASS__ . '::may_add_notice' );

		add_filter(
			'woocommerce_order_data_store_cpt_get_orders_query',
			array( $this, 'handle_custom_query_var' ),
			10,
			2
		);

		add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerce_blocks_support' ) );
	}

	/**
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
	 */
	public function includes() {
		$this->init_composer();

		require_once __DIR__ . '/functions.php';
		require_once __DIR__ . '/interface-swedbank-pay-order-item.php';
		require_once __DIR__ . '/class-swedbank-pay-api.php';
		require_once __DIR__ . '/class-swedbank-pay-instant-capture.php';
		require_once __DIR__ . '/class-swedbank-pay-payment-actions.php';
		require_once __DIR__ . '/class-swedbank-pay-admin.php';
		require_once __DIR__ . '/class-swedbank-pay-thankyou.php';
		require_once __DIR__ . '/class-swedbank-pay-intl-tel.php';
		require_once __DIR__ . '/class-swedbank-pay-scheduler.php';
		require_once __DIR__ . '/class-swedbank-pay-subscription.php';

		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once __DIR__ . '/class-swedbank-pay-blocks-support.php';
		}
	}

	/**
	 * Try to load the autoloader from Composer.
	 *
	 * @return bool Whether the autoloader was successfully loaded.
	 */
	protected function init_composer() {
		$autoloader              = SWEDBANK_PAY_PLUGIN_PATH . '/vendor/autoload.php';
		$autoloader_dependencies = SWEDBANK_PAY_PLUGIN_PATH . '/vendor/dependencies/scoper-autoload.php';

		// Check if the autoloaders was read.
		$autoloader_result              = is_readable( $autoloader ) && require $autoloader;
		$autoloader_dependencies_result = is_readable( $autoloader_dependencies ) && require $autoloader_dependencies;
		if ( ! $autoloader_result || ! $autoloader_dependencies_result ) {
			self::missing_autoloader();
			$this->composer_initialized = false;
		} else {
			$this->composer_initialized = true;
		}

		return $this->composer_initialized;
	}

	/**
	 * Print error message if the composer autoloader is missing.
	 *
	 * @return void
	 */
	protected static function missing_autoloader() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	        error_log( // phpcs:ignore
				esc_html__( 'Your installation of Swedbank Pay is not complete. If you installed this plugin directly from Github please refer to the readme.dev.txt file in the plugin.', 'swedbank-pay-payment-menu' )
			);
		}
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-error">
				<p>
					<?php echo esc_html__( 'Your installation of Swedbank Pay is not complete. If you installed this plugin directly from Github please refer to the readme.dev.txt file in the plugin.', 'swedbank-pay-payment-menu' ); ?>
				</p>
			</div>
				<?php
			}
		);
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param array $links
	 *
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payex_checkout' ) . '">' . __(
				'Settings',
				'swedbank-pay-payment-menu'
			) . '</a>',
			'<a href="' . esc_url( 'https://krokedil.com/support/?plugin=316450&&utm_source=swedbank-pay&utm_medium=wp-admin&utm_campaign=settings' ) . '">' . __(
				'Support',
				'swedbank-pay-payment-menu'
			) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init() {
		include_once __DIR__ . '/class-swedbank-pay-background-queue.php';
		self::$background_process = new Swedbank_Pay_Background_Queue();
		Swedbank_Pay_Scheduler::get_instance();
	}

	/**
	 * Generate UUID
	 *
	 * @param $node
	 *
	 * @return string
	 */
	public function generate_uuid( $node ) {
		return Uuid::uuid5( Uuid::NAMESPACE_OID, $node )->toString();
	}

	/**
	 * Dispatch Background Process
	 */
	public function maybe_process_queue() {
		self::$background_process->dispatch();
	}

	/**
	 * Add Upgrade notice
	 */
	public static function may_add_notice() {

		// Check dependencies
		add_action( 'admin_notices', __CLASS__ . '::check_dependencies' );

		if ( version_compare( get_option( self::DB_VERSION_SLUG, self::DB_VERSION ), self::DB_VERSION, '<' ) &&
			 current_user_can( 'manage_woocommerce' ) //phpcs:ignore
		) {
			add_action( 'admin_notices', __CLASS__ . '::upgrade_notice' );
		}

		// Check the decimal settings
		if ( 0 === wc_get_price_decimals() ) {
			add_action( 'admin_notices', __CLASS__ . '::wrong_decimals_notice' );
			remove_action(
				'admin_notices',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::wrong_decimals_notice'
			);
		}
	}

	/**
	 * Provide Admin Menu items
	 */
	public function admin_menu() {
		// Add Upgrade Page
		global $_registered_pages;

		$hookname = get_plugin_page_hookname( self::ADMIN_UPGRADE_PAGE_SLUG, '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::upgrade_page' );
		}

		$_registered_pages[ $hookname ] = true;
	}

	/**
	 * Upgrade Page
	 */
	public static function upgrade_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Run Database Update
		include_once __DIR__ . '/class-swedbank-pay-update.php';
		Swedbank_Pay_Update::update();

		echo esc_html__( 'Upgrade finished.', 'swedbank-pay-payment-menu' );
	}

	/**
	 * Upgrade Notice
	 */
	public static function upgrade_notice() {
		?>
		<div id="message" class="error">
			<p>
				<?php
				echo esc_html(
					sprintf(
					/* translators: 1: plugin name */                        esc_html__(
						'Warning! %1$s requires to update the database structure.', //phpcs:ignore
						'swedbank-pay-payment-menu' //phpcs:ignore
						), //phpcs:ignore
						self::PLUGIN_NAME
					)
				);

				echo esc_html(
					' ' . sprintf(
					/* translators: 1: start tag 2: end tag */                        esc_html__(
						'Please click %1$s here %2$s to start upgrade.', //phpcs:ignore
						'swedbank-pay-payment-menu' //phpcs:ignore
						), //phpcs:ignore
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::ADMIN_UPGRADE_PAGE_SLUG ) ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Check dependencies
	 */
	public static function check_dependencies() {
		$dependencies = array( 'curl', 'bcmath', 'json' );

		$errors = array();
		foreach ( $dependencies as $dependency ) {
			if ( ! extension_loaded( $dependency ) ) {
				/* translators: 1: plugin name */                        $errors[] = sprintf( esc_html__( 'Extension %s is missing.', 'swedbank-pay-payment-menu' ), $dependency );
			}
		}

		if ( count( $errors ) > 0 ) :
			?>
			<div id="message" class="error">
				<p class="main">
					<strong><?php echo esc_html__( 'Required extensions are missing.', 'swedbank-pay-payment-menu' ); ?></strong>
				</p>
				<p>
					<?php
					foreach ( $errors as $error ) {
						echo esc_html( $error );
					}
					echo '<br />';
					echo esc_html(
						sprintf(
						/* translators: 1: plugin name */                        esc_html__( //phpcs:ignore
							'%1$s requires that. Please configure PHP or contact the server administrator.',
							'swedbank-pay-payment-menu'
							), //phpcs:ignore
							self::PLUGIN_NAME
						)
					);

					?>
				</p>
			</div>
			<?php
		endif;
	}

	/**
	 * Check if "Number of decimals" of WooCommerce is configured incorrectly
	 */
	public static function wrong_decimals_notice() {
		?>
		<div id="message" class="error">
			<p class="main">
				<strong><?php echo esc_html__( 'Invalid value of "Number of decimals" detected.', 'swedbank-pay-payment-menu' ); ?></strong>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
					/* translators: 1: start tag 2: end tag */                        esc_html__(
						'"Number of decimals" is configured with zero value. It creates problems with rounding and checkout. Please change it to "2" on %1$sSettings page%2$s.',
						'swedbank-pay-payment-menu'
						), //phpcs:ignore
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Compatibility check.
	 *
	 * @return void
	 */
	public static function check_backward_compatibility() {
		?>
		<div id="message" class="updated woocommerce-message">
			<p class="main">
				<strong><?php echo esc_html__( 'Problems with plugin compatibility.', 'swedbank-pay-payment-menu' ); ?></strong>
			</p>
			<p>
				<?php
				echo esc_html__( 'We\'ve detected that you\'ve used an older version of the Swedbank Pay Checkout integration.', 'swedbank-pay-payment-menu' );
				echo '<br />';
				echo esc_html__( 'Please disable "Swedbank Pay Checkout" plugin.', 'swedbank-pay-payment-menu' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle a custom '_payex_paymentorder_id' query var to get orders with the '_payex_paymentorder_id' meta.
	 *
	 * @see https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function handle_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['_payex_paymentorder_id'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_payex_paymentorder_id',
				'value' => esc_attr( $query_vars['_payex_paymentorder_id'] ),
			);
		}

		return $query;
	}

	/**
	 * Add WooCommerce Blocks support.
	 *
	 * @return void
	 */
	public function woocommerce_blocks_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once __DIR__ . '/class-swedbank-pay-blocks-support.php';

			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new Swedbank_Pay_Blocks_Support() );
				}
			);
		}
	}
}
