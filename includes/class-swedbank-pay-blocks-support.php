<?php

namespace SwedbankPay\Checkout\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

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
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Swedbank_Pay_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Name of the payment method.
	 *
	 * @var string
	 */
	protected $name = 'payex_checkout';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_payex_checkout_settings', array() );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_script(
			'wc-swedbank-pay-blocks-integration',
			plugin_dir_url( __FILE__ ) . '../assets/js/gutenberg-blocks' . $suffix . '.js',
			array( 'wp-hooks' ),
			'1.0.0',
			true
		);

		// Localize the script
		$translation_array = array(
			'proceed_to'  => sprintf(
			/* translators: 1: title */                __( 'Proceed to %s', 'swedbank-pay-woocommerce-checkout' ),
				$this->settings['title']
			),
			'payment_via' => sprintf(
			/* translators: 1: title */                __( 'Payment via %s', 'swedbank-pay-woocommerce-checkout' ),
				$this->settings['title']
			),
			'logo_src'    => plugin_dir_url( __FILE__ ) . '../assets/images/checkout.svg',
		);

		wp_localize_script(
			'wc-swedbank-pay-blocks-integration',
			'SwedbankPay_Blocks_Integration',
			$translation_array
		);

		return array( 'wc-swedbank-pay-blocks-integration' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->get_supported_features(),
		);
	}
}
