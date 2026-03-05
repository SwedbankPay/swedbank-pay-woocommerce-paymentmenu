<?php
namespace Krokedil\Swedbank\Pay\Gateways;

use Krokedil\Swedbank\Pay\CheckoutFlow\CheckoutFlow;
use Krokedil\Swedbank\Pay\Utility\InstrumentsUtility;

defined( 'ABSPATH' ) || exit;

/**
 * Class for the split payment gateways.
 *
 * These will be registered dynamically based on the settings from the main gateway.
 */
class SplitInstrumentGateway extends \WC_Payment_Gateway {
	/**
	 * The id of the instrument for Swedbanks API, e.g. 'CreditCard'.
	 *
	 * @var string
	 */
	protected string $instrument_id;

	/**
	 * Class constructor.
	 *
	 * @param string $id The ID of the gateway, e.g. 'credit_card'.
	 * @param array $instrument The instrument details for Swedbanks API, e.g. array('instrument' => 'CreditCard', 'name' => 'Credit Card').
	 *
	 * @return void
	 */
	public function __construct( $id, $instrument ) {
		// If the Id is empty, we cannot continue.
		if ( empty( $id ) ) {
			wc_doing_it_wrong( __METHOD__, __( 'When creating a split instrument gateway, the ID cannot be empty.', 'swedbank-pay-payment-menu' ), '1.0.0' );
			return;
		}

		$this->id            = "swedbank_pay_$id";
		$this->instrument_id = $instrument['instrument'];
		// translators: %s the name of the payment method.
		$this->method_title = sprintf( __( 'Swedbank Pay %s', 'swedbank-pay-payment-menu' ), $instrument['name'] );
		// translators: %s the description of the payment method.
		$this->method_description = sprintf( __( 'Take payments with %s via Swedbank Pay', 'swedbank-pay-payment-menu' ), $instrument['name'] );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->settings['enabled'] ?? 'yes';
		$this->title       = $this->settings['title'] ?? $this->method_title;
		$this->description = $this->settings['description'] ?? '';

		$this->supports   = $instrument['supports'] ?? array( 'products', 'refunds' );
		$this->has_fields = false; // False for now. Once we add support for embedded version, we will need to set this to true.
	}

	/**
	 * Init form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'swedbank-pay-payment-menu' ),
				'default' => 'yes',
			),
			'title'   => array(
				'title'       => __( 'Title', 'swedbank-pay-payment-menu' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'swedbank-pay-payment-menu' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
				'placeholder' => __( 'Enter the title of the payment method to show in the checkout', 'swedbank-pay-payment-menu' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'swedbank-pay-payment-menu' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'swedbank-pay-payment-menu' ),
				'default'     => '',
				'desc_tip'    => true,
				'placeholder' => __( 'Enter the description of the payment method to show in the checkout', 'swedbank-pay-payment-menu' ),
			),
		);
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id The ID of the order being processed.
	 *
	 * @return array The result of the payment processing, including 'result' and 'redirect' keys.
	 */
	public function process_payment( $order_id ) {
		return CheckoutFlow::process_payment( $order_id, $this->instrument_id );
	}

	/**
	 * Process a refund.
	 *
	 * @param int $order_id The ID of the order being refunded.
	 * @param float $amount The amount to refund.
	 * @param string $reason The reason for the refund.
	 *
	 * @return bool True if the refund was successful, false otherwise.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		// Get the base gateway and use its refund method instead.
		$base_gateway = swedbank_pay_get_payment_method_by_id();
		if ( empty( $base_gateway ) || ! method_exists( $base_gateway, 'process_refund' ) ) {
			return false;
		}
		return $base_gateway->process_refund( $order_id, $amount, $reason );
	}

	/**
	 * Print payment fields...
	 *
	 * @return void
	 */
	public function payment_fields() {
		CheckoutFlow::payment_fields( $this->id );
	}

	/**
	 * If the payment method is available or not.
	 * Checks if the gateway is enabled, and applies a filter to allow modification of the availability.
	 *
	 * @return bool True if the gateway is available, false otherwise.
	 */
	public function is_available() {
		/**
		 * Filter the availability of the split instrument gateway.
		 *
		 * @param bool $is_available Whether the gateway is available or not in general.
		 * @param string $gateway_id The ID of the gateway being checked, e.g. 'swedbank_pay_credit_card'.
		 * @param SplitInstrumentGateway $gateway_instance The instance of the gateway being checked.
		 *
		 * @return bool Whether the gateway should be available or not.
		 */
		return apply_filters( 'swedbank_pay_split_instrument_gateway_is_available', parent::is_available(), $this->id, $this );
	}

	/**
	 * Get the instrument id for this gateway.
	 *
	 * @return string The instrument id for this gateway, e.g. 'CreditCard'.
	 */
	public function get_instrument_id() {
		return $this->instrument_id;
	}

	/**
	 * Helper method to register the gateway in WooCommerce.
	 *
	 * @param array $gateways The existing gateways to which the new gateway should be added.
	 *
	 * @return array
	 */
	public static function register_split_instrument_gateways( $gateways ) {
		$enabled_instruments = InstrumentsUtility::get_enabled_instruments();
		// If the array is empty, we don't need to register any gateways.
		if ( empty( $enabled_instruments ) ) {
			return $gateways;
		}

		// Register a gateway for each enabled instrument.
		foreach ( $enabled_instruments as $id => $instrument ) {
			$gateways[] = new self( $id, $instrument );
		}

		return $gateways;
	}
}
