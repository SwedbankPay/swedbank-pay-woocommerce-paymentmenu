<?php
namespace Krokedil\Swedbank\Pay;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assets
 *
 * Handles the registration and enqueuing of assets.
 */
class Assets {
	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register admin assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register the admin settings script.
		wp_register_script(
			'swedbank-pay-admin-settings',
			SWEDBANK_PAY_PLUGIN_URL . "/assets/js/admin-settings{$suffix}.js",
			array( 'jquery' ),
			SWEDBANK_PAY_VERSION,
			true
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// If we are on the settings page for the Swedbank Pay plugin, enqueue the admin settings script.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission, just enqueueing script.
		if ( 'woocommerce_page_wc-settings' === $hook
			&& isset( $_GET['tab'] )
			&& 'checkout' === $_GET['tab']
			&& isset( $_GET['section'] )
			&& 'payex_checkout' === $_GET['section']
		) {
			// phpcs:enable
			$settings_params = array();
			wp_localize_script(
				'swedbank-pay-admin-settings',
				'swedbank_pay_admin_settings_params',
				$settings_params,
			);
			wp_enqueue_script( 'swedbank-pay-admin-settings' );
		}
	}
}
