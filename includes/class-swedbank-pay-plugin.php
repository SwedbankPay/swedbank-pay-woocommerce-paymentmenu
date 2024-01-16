<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Automattic\Jetpack\Constants;
use Exception;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
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
	const ADMIN_SUPPORT_PAGE_SLUG = 'swedbank-pay-menu-support';
	const ADMIN_UPGRADE_PAGE_SLUG = 'swedbank-pay-menu-upgrade';

	/**
	 * @var Swedbank_Pay_Background_Queue
	 */
	public static $background_process;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Check if the checkout plugin is active
		if ( in_array( 'swedbank-pay-checkout/swedbank-pay-woocommerce-checkout.php', get_option( 'active_plugins' ) ) ) { //phpcs:ignore
			add_action( 'admin_notices', __CLASS__ . '::check_backward_compatibility', 40 );

			return;
		}

		// Includes
		$this->includes();

		// Actions
		add_filter(
			'plugin_action_links_' . constant( __NAMESPACE__ . '\PLUGIN_PATH' ),
			__CLASS__ . '::plugin_action_links'
		);
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'woocommerce_init', array( $this, 'woocommerce_init' ) );

		// Filters
		add_filter( 'swedbank_pay_generate_uuid', array( $this, 'generate_uuid' ), 10, 1 );
		add_filter( 'swedbank_pay_order_billing_phone', __CLASS__ . '::billing_phone', 10, 2 );

		// Process swedbank queue
		if ( ! is_multisite() ) {
			add_action( 'customize_save_after', array( $this, 'maybe_process_queue' ) );
			add_action( 'after_switch_theme', array( $this, 'maybe_process_queue' ) );
		}

		// Add admin menu
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );

		add_action( 'init', __CLASS__ . '::may_add_notice' );

		add_action( 'admin_post_' . self::ADMIN_SUPPORT_PAGE_SLUG, __CLASS__ . '::support_submit' );

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
		$vendors_dir = dirname( __FILE__ ) . '/../vendor';
		require_once $vendors_dir . '/autoload.php';
		require_once( dirname( __FILE__ ) . '/functions.php' );
		require_once( dirname( __FILE__ ) . '/interface-swedbank-pay-order-item.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-transactions.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-api.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-instant-capture.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-payment-actions.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-admin.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-thankyou.php' );
		require_once( dirname( __FILE__ ) . '/class-swedbank-pay-intl-tel.php' );

		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once( dirname( __FILE__ ) . '/class-swedbank-pay-blocks-support.php' );
		}
	}

	/**
	 * Install
	 */
	public function install() {
		// Install Schema
		Swedbank_Pay_Transactions::instance()->install_schema();

		// Set Version
		if ( ! get_option( self::DB_VERSION_SLUG ) ) {
			add_option( self::DB_VERSION_SLUG, self::DB_VERSION );
		}
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
				'swedbank-pay-woocommerce-checkout'
			) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::ADMIN_SUPPORT_PAGE_SLUG ) ) . '">' . __(
				'Support',
				'swedbank-pay-woocommerce-checkout'
			) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		// Functions
	}

	/**
	 * WooCommerce Init
	 */
	public function woocommerce_init() {
		include_once( dirname( __FILE__ ) . '/class-swedbank-pay-background-queue.php' );
		self::$background_process = new Swedbank_Pay_Background_Queue();
	}

	/**
	 * Register payment gateway
	 *
	 * @param string $class_name
	 */
	public static function register_gateway( $class_name ) {
		global $px_gateways;

		if ( ! $px_gateways ) {
			$px_gateways = array();
		}

		if ( ! isset( $px_gateways[ $class_name ] ) ) {
			// Initialize instance
			$gateway = new $class_name;

			if ( $gateway ) {
				$px_gateways[] = $class_name;

				// Register gateway instance
				add_filter(
					'woocommerce_payment_gateways',
					function ( $methods ) use ( $gateway ) {
						$methods[] = $gateway;

						return $methods;
					}
				);
			}
		}
	}

	/**
	 * Generate UUID
	 *
	 * @param $node
	 *
	 * @return string
	 */
	public function generate_uuid( $node ) {
		return \Ramsey\Uuid\Uuid::uuid5( \Ramsey\Uuid\Uuid::NAMESPACE_OID, $node )->toString();
	}

	/**
	 * Billing phone.
	 *
	 * @param string $billing_phone
	 * @param WC_Order $order
	 *
	 * @return string
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public static function billing_phone( $billing_phone, $order ) {
		$billing_country = $order->get_billing_country();
		$billing_phone   = preg_replace( '/[^0-9\+]/', '', $billing_phone );

		if ( ! preg_match( '/^((00|\+)([1-9][1-9])|0([1-9]))(\d*)/', $billing_phone, $matches ) ) {
			return null;
		}

		switch ( $billing_country ) {
			case 'SE':
				$country_code = '46';
				break;
			case 'NO':
				$country_code = '47';
				break;
			case 'DK':
				$country_code = '45';
				break;
			default:
				return '+' . ltrim( $billing_phone, '+' );
		}

		if ( isset( $matches[3] ) && isset( $matches[5] ) ) { // country code present
			$billing_phone = $matches[3] . $matches[5];
		}

		if ( isset( $matches[4] ) && isset( $matches[5] ) ) { // no country code present. removing leading 0
			$billing_phone = $country_code . $matches[4] . $matches[5];
		}

		return strlen( $billing_phone ) > 7 && strlen( $billing_phone ) < 16 ? '+' . $billing_phone : null;
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

		// Add Support Page
		$hookname = get_plugin_page_hookname( self::ADMIN_SUPPORT_PAGE_SLUG, '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::support_page' );
		}

		$_registered_pages[ $hookname ] = true;

		$hookname = get_plugin_page_hookname( self::ADMIN_UPGRADE_PAGE_SLUG, '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::upgrade_page' );
		}

		$_registered_pages[ $hookname ] = true;
	}

	/**
	 * Support Page
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public static function support_page() {
		wc_get_template(
			'admin/support.php',
			array(
				'form_url' => admin_url( 'admin-post.php' ),
				'action'   => self::ADMIN_SUPPORT_PAGE_SLUG,
				'error'    => isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : null, // WPCS: input var ok, CSRF ok.
				'message'  => isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : null, // WPCS: input var ok, CSRF ok.
			),
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Upgrade Page
	 */
	public static function upgrade_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Run Database Update
		include_once( dirname( __FILE__ ) . '/class-swedbank-pay-update.php' );
		Swedbank_Pay_Update::update();

		echo esc_html__( 'Upgrade finished.', 'swedbank-pay-woocommerce-checkout' );
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
						'swedbank-pay-woocommerce-checkout' //phpcs:ignore
						), //phpcs:ignore
						self::PLUGIN_NAME
					)
				);

				echo esc_html(
					' ' . sprintf(
					/* translators: 1: start tag 2: end tag */                        esc_html__(
						'Please click %1$s here %2$s to start upgrade.', //phpcs:ignore
						'swedbank-pay-woocommerce-checkout' //phpcs:ignore
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
				/* translators: 1: plugin name */                        $errors[] = sprintf( esc_html__( 'Extension %s is missing.', 'swedbank-pay-woocommerce-checkout' ), $dependency );
			}
		}

		if ( count( $errors ) > 0 ) :
			?>
			<div id="message" class="error">
				<p class="main">
					<strong><?php echo esc_html__( 'Required extensions are missing.', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
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
							'swedbank-pay-woocommerce-checkout'
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
				<strong><?php echo esc_html__( 'Invalid value of "Number of decimals" detected.', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
					/* translators: 1: start tag 2: end tag */                        esc_html__(
						'"Number of decimals" is configured with zero value. It creates problems with rounding and checkout. Please change it to "2" on %1$sSettings page%2$s.',
						'swedbank-pay-woocommerce-checkout'
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
				<strong><?php echo esc_html__( 'Problems with plugin compatibility.', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
			</p>
			<p>
				<?php
				echo esc_html__( 'We\'ve detected that you\'ve used an older version of the Swedbank Pay Checkout integration.', 'swedbank-pay-woocommerce-checkout' );
				echo '<br />';
				echo esc_html__( 'Please disable "Swedbank Pay Checkout" plugin.', 'swedbank-pay-woocommerce-checkout' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Send support message
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public static function support_submit() {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) ); // WPCS: input var ok, CSRF ok.
		if ( ! wp_verify_nonce( $nonce, 'support_submit' ) ) {
			exit( 'No naughty business' );
		}

		$redirect = wc_clean( wp_unslash( $_POST['_wp_http_referer'] ) );

		try {
			if ( ! extension_loaded( 'zip' ) ) {
				throw new \Exception(
					__( 'zip extension is required to perform this operation.', 'swedbank-pay-woocommerce-checkout' )
				);
			}

			// Validate the fields
			if ( empty( $_POST['email'] ) || empty( $_POST['message'] ) ) {
				throw new \Exception(
					__( 'Invalid form data', 'swedbank-pay-woocommerce-checkout' )
				);
			}

			$email = sanitize_email( wc_clean( $_POST['email'] ) ); // WPCS: input var ok, CSRF ok.

			// Validate email
			if ( ! is_email( $email ) ) {
				throw new \Exception( __( 'Invalid email', 'swedbank-pay-woocommerce-checkout' ) );
			}

			$message = wp_kses_post( sanitize_text_field( $_POST['message'] ) ); // WPCS: input var ok, CSRF ok.

			// Export settings
			$settings = array();
			foreach ( self::PAYMENT_METHODS as $payment_method ) {
				$conf = get_option( 'woocommerce_' . $payment_method . '_settings' );
				if ( ! is_array( $conf ) ) {
					$conf = array();
				}

				$settings[ $payment_method ] = $conf;
			}

			$json_settings = get_temp_dir() . '/settings.json';
			file_put_contents( $json_settings, json_encode( $settings, JSON_PRETTY_PRINT ) );

			// Export system information
			$json_report = get_temp_dir() . '/wc-report.json';
			$report      = wc()->api->get_endpoint_data( '/wc/v3/system_status' );
			file_put_contents( $json_report, json_encode( $report, JSON_PRETTY_PRINT ) );

			// Make zip
			$zip_file = WC_LOG_DIR . uniqid( 'swedbank_pay' ) . '.zip';

			$zip_archive = new \ZipArchive();
			$zip_archive->open( $zip_file, \ZipArchive::CREATE );

			// Add files
			$zip_archive->addFile( $json_settings, basename( $json_settings ) );
			$zip_archive->addFile( $json_report, basename( $json_report ) );

			// Add logs
			$files = self::get_log_files();
			foreach ( $files as $file ) {
				$zip_archive->addFile( WC_LOG_DIR . $file, basename( $file ) );
			}

			$zip_archive->close();

			// Get the plugin information
			$plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . constant( __NAMESPACE__ . '\PLUGIN_PATH' ) );

			// Make message
			$message = sprintf(
				"Date: %s\nFrom: %s\nMessage: %s\nSite: %s\nPHP version: %s\nWC Version: %s\nWordPress Version: %s\nPlugin Name: %s\nPlugin Version: %s",
				gmdate( 'Y-m-d H:i:s' ),
				$email,
				$message,
				get_option( 'siteurl' ),
				phpversion(),
				Constants::get_constant( 'WC_VERSION' ),
				get_bloginfo( 'version' ),
				$plugin['Name'],
				$plugin['Version']
			);

			// Send message
			$result = wp_mail(
				self::SUPPORT_EMAIL,
				'Site support: ' . get_option( 'siteurl' ),
				$message,
				array(
					'Reply-To: ' . $email,
					'Content-Type: text/plain; charset=UTF-8',
				),
				array( $zip_file )
			);

			// Remove temporary files
			@unlink( $json_settings );
			@unlink( $zip_file );
			@unlink( $json_report );

			if ( ! $result ) {
				throw new \Exception( __( 'Unable to send mail message.', 'swedbank-pay-woocommerce-checkout' ) );
			}
		} catch ( \Exception $exception ) {
			wp_redirect( add_query_arg( array( 'error' => $exception->getMessage() ), $redirect ) );
			return;
		}

		wp_redirect(
			add_query_arg(
				array( 'message' => __( 'Your message has been sent.', 'swedbank-pay-woocommerce-checkout' ) ),
				$redirect
			)
		);
	}

	/**
	 * Handle a custom '_payex_paymentorder_id' query var to get orders with the '_payex_paymentorder_id' meta.
	 * @see https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function handle_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['_payex_paymentorder_id'] ) ) {
			$query['meta_query'][] = [
				'key' => '_payex_paymentorder_id',
				'value' => esc_attr( $query_vars['_payex_paymentorder_id'] ),
			];
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
			require_once( dirname( __FILE__ ) . '/class-swedbank-pay-blocks-support.php' );

			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new Swedbank_Pay_Blocks_Support );
				}
			);
		}
	}

	/**
	 * Get log files.
	 *
	 * @return string[]
	 */
	private static function get_log_files() {
		$result = array();
		$files  = \WC_Log_Handler_File::get_log_files();
		foreach ( $files as $file ) {
			foreach ( self::PAYMENT_METHODS as $payment_method ) {
				if ( strpos( $file, $payment_method ) !== false ||
					 strpos( $file, 'swedbank' ) !== false ||  //phpcs:ignore
					 strpos( $file, 'fatal-errors' ) !== false  //phpcs:ignore
				) {
					$result[] = $file;
				}
			}
		}

		return $result;
	}
}
