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
	 * @param string $instrument_id The id of the instrument for Swedbanks API, e.g. 'CreditCard'.
	 * @param string $name The name of the gateway, e.g. 'Credit Card'. This is used as the display title for the gateway in the admin pages,
	 * 					   and will be the default title shown to users in the checkout if not set otherwise in the settings.
	 *
	 * @return void
	 */
	public function __construct( $id, $instrument_id, $name = '' ) {
		// If the Id is empty, we cannot continue.
		if ( empty( $id ) ) {
			wc_doing_it_wrong( __METHOD__, __( 'When creating a split instrument gateway, the ID cannot be empty.', 'swedbank-pay-payment-menu' ), '1.0.0' );
			return;
		}

		$this->id            = "swedbank_pay_$id";
		$this->instrument_id = $instrument_id;
		// translators: %s the name of the payment method.
		$this->method_title = sprintf( __( 'Swedbank Pay %s', 'swedbank-pay-payment-menu' ), $name );
		// translators: %s the description of the payment method.
		$this->method_description = sprintf( __( 'Take payments with %s via Swedbank Pay', 'swedbank-pay-payment-menu' ), $name );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->settings['enabled'] ?? 'yes';
		$this->title       = $this->settings['title'] ?? $this->method_title;
		$this->description = $this->settings['description'] ?? '';


		$this->has_fields = true;
		$this->supports   = array(
			'products',
			'refunds',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_payment_method_change',
			'multiple_subscriptions',
		);

		//parent::__construct();
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
		return true;
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
		$available_instruments = InstrumentsUtility::get_enabled_instruments();
		// If the array is empty, we don't need to register any gateways.
		if ( empty( $available_instruments ) ) {
			return $gateways;
		}

		// Register a gateway for each enabled instrument.
		foreach ( $available_instruments as $id => $instrument ) {
			$gateways[] = new self( $id, $instrument['instrument'], $instrument['name'] );
		}

		return $gateways;
	}
}
