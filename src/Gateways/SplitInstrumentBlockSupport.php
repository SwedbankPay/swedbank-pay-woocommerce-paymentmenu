<?php

namespace Krokedil\Swedbank\Pay\Gateways;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Krokedil\Swedbank\Pay\Utility\{InstrumentsUtility, SettingsUtility};

defined( 'ABSPATH' ) || exit;

/**
 * Class for handling the block checkout support for split instruments.
 */
class SplitInstrumentBlockSupport extends AbstractPaymentMethodType {
	/**
	 * The instruments to register the payment blocks for.
	 *
	 * @var array
	 */
	protected $instruments = array();

	/**
	 * The name for the payment block integration
	 *
	 * @var string
	 */
	protected $name = 'swedbank_split_instruments';


	/**
	 * Class constructor.
	 *
	 * @param array $instruments The instruments to register the payment blocks for.
	 * @return void
	 */
	public function __construct( $instruments ) {
		$this->instruments = $instruments;
	}

	public function initialize() {
		// Register scripts so they are available when they need to, and ensure they are only registered once.
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_script(
			'wc-swedbank-pay-blocks-split-instrument',
			SWEDBANK_PAY_PLUGIN_URL . '/assets/js/split-instruments-blocks' . $suffix . '.js',
			array( 'wp-hooks', 'wc-settings', 'wc-blocks-registry' ),
			'1.0.0',
			true
		);

		// Localize the script
		$translation_array = array(
			// translators: 1: payment method title.
			'proceed_to'  => __( 'Proceed to %s', 'swedbank-pay-payment-menu' ),
			// translators: 1: payment method title.
			'payment_via' => __( 'Payment via %s', 'swedbank-pay-payment-menu' ),
		);

		wp_localize_script(
			'wc-swedbank-pay-blocks-split-instrument',
			'SplitInstrumentBlockSupport',
			$translation_array
		);
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return SettingsUtility::is_separate_instruments_enabled();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		return array( 'wc-swedbank-pay-blocks-split-instrument' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$data = array();

		foreach ( $this->instruments as $id => $instrument ) {
			$gateway_id = "swedbank_pay_$id";

			// Get the settings for the gateway.
			$settings = get_option( "woocommerce_{$gateway_id}_settings", array() );

			$data[ $id ] = array(
				'gateway_id'  => $gateway_id,
				'name'        => $instrument['name'],
				'supports'    => $instrument['supports'] ?? array( 'products', 'refunds' ),
				'enabled'     => wc_string_to_bool( $settings['enabled'] ?? 'yes' ),
				'title'       => $settings['title'] ?? $instrument['name'],
				'description' => $settings['description'] ?? '',
			);
		}

		return $data;
	}

	/**
	 * Static helper method to register the support for blocks checkout for the split instruments.
	 *
	 * @return void
	 */
	public static function register_split_payment_blocks( PaymentMethodRegistry $payment_method_registry ) {
		$enabled_instruments = InstrumentsUtility::get_enabled_instruments();

		// If the array is empty we don't need to register anything, so we can just return early.
		if ( empty( $enabled_instruments ) ) {
			return;
		}

		$payment_method_registry->register( new SplitInstrumentBlockSupport( $enabled_instruments ) );
	}
}
