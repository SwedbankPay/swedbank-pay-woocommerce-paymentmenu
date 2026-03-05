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
		// Register the admin settings script.
		wp_register_script(
			'swedbank-pay-admin-settings',
			SWEDBANK_PAY_PLUGIN_URL . '/assets/js/admin-settings.js',
			array( 'jquery' ),
			'1.0.0',
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
		if ( 'woocommerce_page_wc-settings' === $hook
			&& isset( $_GET['tab'] )
			&& 'checkout' === $_GET['tab']
			&& isset( $_GET['section'] )
			&& 'payex_checkout' === $_GET['section']
		) {
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
