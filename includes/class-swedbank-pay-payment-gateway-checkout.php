<?php

use Krokedil\Swedbank\Pay\CheckoutFlow\InlineEmbedded;
use Krokedil\Swedbank\Pay\Utility\BlocksUtility;

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Instant_Capture;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Payment_Actions;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Scheduler;
use Krokedil\Swedbank\Pay\CheckoutFlow\CheckoutFlow;

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Swedbank_Pay_Payment_Gateway_Checkout extends WC_Payment_Gateway {
	/**
	 * Access Token
	 *
	 * @var string
	 */
	public $access_token = '';

	/**
	 * Payee Id
	 *
	 * @var string
	 */
	public $payee_id = '';

	/**
	 * Test Mode
	 *
	 * @var string
	 */
	public $testmode = 'no';

	/**
	 * Debug Mode
	 *
	 * @var string
	 */
	public $debug = 'yes';

	/**
	 * Locale
	 *
	 * @var string
	 */
	public $culture = 'en-US';

	/**
	 * Url of Merchant Logo.
	 *
	 * @var string
	 */
	public $logo_url = '';

	/**
	 * Instant Capture
	 *
	 * @var array
	 */
	public $instant_capture = array();

	/**
	 * @var string
	 */
	public $terms_url = '';

	/**
	 * @var string
	 */
	public $autocomplete = 'no';

	/**
	 * @var bool
	 */
	public $block_checkout_enabled = false;

	/**
	 * @var string
	 */
	public $checkout_flow = 'redirect';

	/**
	 * @var bool
	 */
	public $exclude_order_lines = false;

	/**
	 * @var Swedbank_Pay_Api
	 */
	public $api;

	/**
	 * @var Swedbank_Pay_Payment_Actions
	 */
	public $payment_actions_handler;

	/**
	 * Init
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function __construct() {
		$this->id                 = 'payex_checkout';
		$this->has_fields         = true;
		$this->method_title       = __( 'Swedbank Pay Payment Menu', 'swedbank-pay-payment-menu' );
		$this->method_description = __( 'Provides the Swedbank Pay Payment Menu for WooCommerce', 'swedbank-pay-payment-menu' );
		// $this->icon         = apply_filters( 'woocommerce_swedbank_pay_payments_icon', plugins_url( '/assets/images/checkout.svg', dirname( __FILE__ ) ) );
		$this->supports = array(
			'products',
			'refunds',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Update access_token if merchant_token is exists.
		if ( empty( $this->settings['access_token'] ) && ! empty( $this->settings['merchant_token'] ) ) {
			$this->settings['access_token'] = $this->settings['merchant_token'];
			$this->update_option( 'access_token', $this->settings['access_token'] );
		}

		// Define user set variables.
		$this->enabled                = $this->settings['enabled'] ?? 'yes';
		$this->title                  = $this->settings['title'] ?? '';
		$this->description            = $this->settings['description'] ?? '';
		$this->access_token           = $this->settings['access_token'] ?? $this->access_token;
		$this->payee_id               = $this->settings['payee_id'] ?? $this->payee_id;
		$this->testmode               = $this->settings['testmode'] ?? $this->testmode;
		$this->culture                = $this->settings['culture'] ?? $this->culture;
		$this->logo_url               = $this->settings['logo_url'] ?? $this->logo_url;
		$this->instant_capture        = $this->settings['instant_capture'] ?? $this->instant_capture;
		$this->terms_url              = $this->settings['terms_url'] ?? get_site_url();
		$this->autocomplete           = $this->settings['autocomplete'] ?? 'no';
		$this->exclude_order_lines    = wc_string_to_bool( $this->settings['exclude_order_lines'] ?? false );
		$this->block_checkout_enabled = BlocksUtility::is_checkout_block_enabled();
		$this->checkout_flow          = $this->block_checkout_enabled ?
			( $this->settings['checkout_flow'] ?? 'redirect' ) : 'redirect'; // Use the setting only if the block checkout is enabled, otherwise force 'redirect'.

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== wp_parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		add_action( 'woocommerce_before_thankyou', array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_order_get_payment_method_title', array( $this, 'payment_method_title' ), 2, 10 );

		// Payment listener/API hook.
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'return_handler' ) );

		add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'update_order_received_title' ), 3 );
		add_filter( 'wc_order_is_editable', array( $this, 'is_editable' ), 2, 10 );

		$this->api = new Swedbank_Pay_Api( $this );
		$this->api->set_access_token( $this->access_token )
			->set_payee_id( $this->payee_id )
			->set_mode( wc_string_to_bool( $this->testmode ) ? Swedbank_Pay_Api::MODE_TEST : Swedbank_Pay_Api::MODE_LIVE );

		$this->payment_actions_handler = new Swedbank_Pay_Payment_Actions( $this );
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @return string|void
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function init_form_fields() {
		$portal_url = 'yes' === $this->testmode ? 'https://merchantportal.externalintegration.swedbankpay.com' :
			'https://merchantportal.swedbankpay.com';

		// Define checkout flow options.
		$flow_options = array(
			'redirect' => __( 'Redirect Menu', 'swedbank-pay-payment-menu' ),
		);

		// Add embedded option if block checkout is not enabled since it does not support it yet.
		if ( ! $this->block_checkout_enabled ) {
			$flow_options['embedded_inline'] = __( 'Seamless Menu', 'swedbank-pay-payment-menu' );
		}

		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'swedbank-pay-payment-menu' ),
				'default' => 'yes',
			),
			'testmode'             => array(
				'title'   => __( 'Test Mode', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', 'swedbank-pay-payment-menu' ),
				'default' => $this->testmode,
			),
			'title'                => array(
				'title'       => __( 'Title', 'swedbank-pay-payment-menu' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'swedbank-pay-payment-menu'
				),
				'default'     => __( 'Swedbank Pay', 'swedbank-pay-payment-menu' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'swedbank-pay-payment-menu' ),
				'type'        => 'text',
				'description' => __(
					'Describe the methods available in your Checkout through Swedbank Pay. Example, “We accept transactions made with Cards (VISA, MasterCard) and Swish”.',
					'swedbank-pay-payment-menu'
				),
				'default'     => __( 'Swedbank Pay', 'swedbank-pay-payment-menu' ),
			),
			'payee_id'             => array(
				'title'             => __( 'Payee Id', 'swedbank-pay-payment-menu' ),
				'type'              => 'text',
				'description'       => /* translators: 1: url */                        sprintf( __( 'Your Payee ID can be found in our Merchant-portal <a href="%1$s" target="_blank">here</a>', 'swedbank-pay-payment-menu' ), $portal_url ),
				'default'           => $this->payee_id,
				'custom_attributes' => array(
					'required' => 'required',
				),
				'sanitize_callback' => function ( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( esc_html__( '"Payee Id" field can\'t be empty.', 'swedbank-pay-payment-menu' ) );
					}

					return $value;
				},
			),
			'access_token'         => array(
				'title'             => __( 'Access Token', 'swedbank-pay-payment-menu' ),
				'type'              => 'text',
				'description'       => /* translators: 1: url */                        sprintf( __( 'Your Access Token can be found in our Merchant-portal <a href="%1$s" target="_blank">here</a>', 'swedbank-pay-payment-menu' ), $portal_url ),
				'default'           => $this->access_token,
				'custom_attributes' => array(
					'required' => 'required',
				),
				'sanitize_callback' => function ( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( esc_html__( '"Access Token" field can\'t be empty.', 'swedbank-pay-payment-menu' ) );
					}

					return $value;
				},
			),
			'culture'              => array(
				'title'       => __( 'Language', 'swedbank-pay-payment-menu' ),
				'type'        => 'select',
				'options'     => array(
					'da-DK' => 'Danish',
					'en-US' => 'English',
					'fi-FI' => 'Finnish',
					'nb-NO' => 'Norway',
					'sv-SE' => 'Swedish',
				),
				'description' => __(
					'Language of pages displayed by Swedbank Pay during payment.',
					'swedbank-pay-payment-menu'
				),
				'default'     => $this->culture,
			),
			'instant_capture'      => array(
				'title'          => __( 'Instant Capture', 'swedbank-pay-payment-menu' ),
				'description'    => __( 'Capture payment automatically depends on the product type. It\'s working when Auto Capture Intent is off.', 'swedbank-pay-payment-menu' ),
				'type'           => 'multiselect',
				'css'            => 'height: 150px',
				'options'        => array(
					Swedbank_Pay_Instant_Capture::CAPTURE_VIRTUAL  => __( 'Virtual products', 'swedbank-pay-payment-menu' ),
					Swedbank_Pay_Instant_Capture::CAPTURE_PHYSICAL => __( 'Physical  products', 'swedbank-pay-payment-menu' ),
					Swedbank_Pay_Instant_Capture::CAPTURE_FEE      => __( 'Fees', 'swedbank-pay-payment-menu' ),
				),
				'select_buttons' => true,
				'default'        => $this->instant_capture,
			),
			'terms_url'            => array(
				'title'       => __( 'Terms & Conditions Url', 'swedbank-pay-payment-menu' ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url. HTTPS is required.', 'swedbank-pay-payment-menu' ),
				'default'     => get_site_url(),
			),
			'logo_url'             => array(
				'title'             => __( 'Logo Url', 'swedbank-pay-payment-menu' ),
				'type'              => 'text',
				'description'       => __( 'The URL that will be used for showing the customer logo. Must be a picture with maximum 50px height and 400px width. Require https.', 'swedbank-pay-payment-menu' ),
				'desc_tip'          => true,
				'default'           => '',
				'sanitize_callback' => function ( $value ) {
					if ( ! empty( $value ) ) {
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							throw new Exception( esc_html__( 'Logo Url is invalid.', 'swedbank-pay-payment-menu' ) );
						} elseif ( 'https' !== wp_parse_url( $value, PHP_URL_SCHEME ) ) {
							throw new Exception( esc_html__( 'Logo Url should use https scheme.', 'swedbank-pay-payment-menu' ) );
						}
					}

					return $value;
				},
			),
			'autocomplete'         => array(
				'title'   => __( 'Automatic order status', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Set order in completed status immediately after payment', 'swedbank-pay-payment-menu' ),
				'default' => $this->autocomplete,
			),
			'exclude_order_lines'  => array(
				'title'       => __( 'Exclude Order Lines', 'swedbank-pay-payment-menu' ),
				'type'        => 'checkbox',
				'label'       => __( 'Exclude order lines from the payment request', 'swedbank-pay-payment-menu' ),
				'description' => __( 'Enable this setting to prevent order line data from being sent.', 'swedbank-pay-payment-menu' ),
				'default'     => 'no',
			),
			'checkout_flow'        => array(
				'title'       => __( 'Checkout Flow', 'swedbank-pay-payment-menu' ),
				'type'        => 'select',
				'options'     => $flow_options,
				'description' => __(
					'Choose your preferred checkout flow. When using the Block Checkout, only Redirect Menu is supported currently.',
					'swedbank-pay-payment-menu'
				),
				'default'     => 'redirect',
				'disabled'    => $this->block_checkout_enabled,
			),
			'order_management'     => array(
				'title' => __( 'Order management', 'swedbank-pay-payment-menu' ),
				'type'  => 'title',
			),
			'enable_order_capture' => array(
				'title'   => __( 'Capture on status change', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Capture payment on order status change to Completed', 'swedbank-pay-payment-menu' ),
				'default' => 'yes',
			),
			'enable_order_cancel'  => array(
				'title'   => __( 'Cancel on status change', 'swedbank-pay-payment-menu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Cancel payment on order status change to Cancelled', 'swedbank-pay-payment-menu' ),
				'default' => 'yes',
			),

		);

		// Extend with settings with logging option.
		$this->form_fields = Swedbank_Pay()->logger()->add_settings_fields( $this->form_fields );
	}

	/**
	 * @param $key
	 * @param $value
	 *
	 * @return false|string
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function generate_advanced_html( $key, $value ) {
		ob_start();
		?>
		<tr valign="top" class="">
			<th class="titledesc" scope="row">
				&nbsp;
			</th>
			<td class="forminp">
				<h4><?php esc_html_e( 'Advanced', 'swedbank-pay-payment-menu' ); ?></h4>
			</td>
		</tr>
		<?php
		$html = ob_get_contents();
		ob_clean();

		return $html;
	}

	/**
	 * Check if payment method should be available.
	 *
	 * @hook swedbank_pay_is_available
	 * @return boolean
	 */
	public function is_available() {
		return apply_filters( 'swedbank_pay_is_available', $this->check_availability(), $this );
	}

	/**
	 * Check if the gateway should be available.
	 *
	 * This function is extracted to create the 'swedbank_pay_is_available' filter.
	 *
	 * @return bool
	 */
	private function check_availability() {
		return wc_string_to_bool( $this->enabled );
	}

	/**
	 * Return the gateway's title.
	 *
	 * @return string
	 */
	public function get_title() {
		$title = __( 'Swedbank Pay Payment Menu', 'swedbank-pay-payment-menu' );

		return apply_filters( 'woocommerce_gateway_title', $title, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Output the gateway settings screen.
	 *
	 * @return void
	 */
	public function admin_options() {
		$this->display_errors();

		parent::admin_options();
	}

	/**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
	public function process_admin_options() {
		$result = parent::process_admin_options();

		// Reload settings.
		$this->init_settings();
		$this->access_token = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token; // phpcs:ignore
		$this->payee_id     = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;

		// Test API Credentials.
		try {
			new KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Request\Test(
				$this->access_token,
				$this->payee_id,
				'yes' === $this->testmode
			);
		} catch ( \Exception $e ) {
			WC_Admin_Settings::add_error( $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Thank you page
	 *
	 * @param string $order_id The WooCommerce order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( empty( $gateway ) || $gateway->id !== $this->id ) {
			return;
		}

		$this->api->log( WC_Log_Levels::INFO, __METHOD__, array( $order_id ) );
		$is_finalized = $order->get_meta( '_payex_finalized' ); // Checks if the order has already been processed.
		if ( ! empty( $is_finalized ) ) {
			return;
		}

		// Clear any potential embedded session data.
		if ( WC()->session !== null ) {
			InlineEmbedded::unset_embedded_session_data();
		}

		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( $payment_order_id ) {
			$order->update_meta_data( '_payex_finalized', 1 );
			$order->save_meta_data();
		}

		// WC will always capture an order that doesn't need processing. Therefore, we only have to set it is as completed if it needs it.
		if ( wc_string_to_bool( $this->autocomplete ) ) {
			$this->api->finalize_payment( $order, null );
			$order->update_status( 'completed', __( 'Order automatically captured after payment.', 'swedbank-pay-payment-menu' ) );
			$order->save();

		} else {
			$response = $gateway->api->request( 'GET', "$payment_order_id/paid" );
			if ( ! is_wp_error( $response ) ) {
				$order->payment_complete( $response['paid']['number'] );
				$order->add_order_note( __( 'Payment completed successfully.', 'swedbank-pay-payment-menu' ) );
			} else {
				$order->payment_complete();
				$order->add_order_note( __( 'Payment completed successfully. Transaction number will soon be updated through callback.', 'swedbank-pay-payment-menu' ) );
			}
		}
	}

	/**
	 * Get the transaction URL.
	 *
	 * @param  WC_Order $order Order object.
	 * @return string
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	public function get_transaction_url( $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return parent::get_transaction_url( $order );
		}

		if ( wc_string_to_bool( $this->testmode ) ) {
			$view_transaction_url = 'https://merchantportal.externalintegration.swedbankpay.com/ecom/paymentorders;id=%s';
		} else {
			$view_transaction_url = 'https://merchantportal.swedbankpay.com/ecom/payments/details;id=%s';
		}

		return sprintf( $view_transaction_url, rawurlencode( $payment_order_id ) );
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array|false
	 */
	public function process_payment( $order_id ) {
		return CheckoutFlow::process_payment( $order_id );
	}

	/**
	 * IPN Callback
	 *
	 * @return void
	 * @throws \Exception
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public function return_handler() {
		$raw_body = wp_kses_post( sanitize_text_field( file_get_contents( 'php://input' ) ) );  // WPCS: input var ok, CSRF ok.

		$this->api->log(
			WC_Log_Levels::INFO,
			sprintf( 'Incoming Callback. Post data: %s', wp_json_encode( $raw_body ) )
		);

		// Decode raw body.
		$data = json_decode( $raw_body, true );
		if ( empty( $data ) ) {
			throw new Exception( 'Invalid webhook data' );
		}

		try {
			$type = filter_input( INPUT_GET, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( 'inline_embedded' === $type ) {
				$order = $this->get_inline_embedded_callback_order();
			} else {
				// Verify the order key.
				$order_id  = filter_input( INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT );
				$order_key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					throw new Exception( 'Unable to load an order.' );
				}

				if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
					throw new Exception( 'A provided order key has been invalid.' );
				}
			}

			// Validate fields.
			if ( ! isset( $data['paymentOrder'] ) || ! isset( $data['paymentOrder']['id'] ) ) {
				throw new \Exception( 'Error: Invalid paymentOrder value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			// Schedule the payment for later processing.
			$schedule_id = as_schedule_single_action(
				time() + 30,
				Swedbank_Pay_Scheduler::ACTION_ID,
				array(
					'payment_method_id' => $this->id,
					'webhook_data'      => $raw_body,
				)
			);

			if ( 0 === $schedule_id ) {
				$this->api->log(
					WC_Log_Levels::ERROR,
					sprintf( 'Error: Unable to schedule a task for %s', $this->id )
				);
				throw new \Exception( 'Unable to schedule a task.' );
			}

			$this->api->log(
				WC_Log_Levels::INFO,
				sprintf( 'Incoming Callback: payment scheduled as %s. Transaction ID: %s', $schedule_id, $data['transaction']['number'] )
			);
		} catch ( \Exception $e ) {
			$this->api->log( WC_Log_Levels::INFO, sprintf( 'Incoming Callback: %s', $e->getMessage() ) );

			return;
		}
	}

	/**
	 * Get the order for a inline embedded checkout.
	 *
	 * @throws Exception
	 * @return \WC_Order|bool
	 */
	public function get_inline_embedded_callback_order() {
		// Get the payee reference from the input.
		$payee_reference = filter_input( INPUT_GET, 'payee_reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// Search for the order with the payee reference saved.
		$args   = array(
			'limit'        => 1,
			'type'         => 'shop_order',
			'meta_key'     => '_payex_payee_reference', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- We need to query by meta.
			'meta_value'   => $payee_reference, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- We need to query by meta.
			'meta_compare' => '=',
			'status'       => array_keys( wc_get_order_statuses() ),
		);
		$orders = wc_get_orders( $args );
		if ( empty( $orders ) || ! is_array( $orders ) ) {
			throw new Exception( 'Unable to load an order.' );
		}

		$order = $orders[0] ?? false;
		if ( ! $order ) {
			throw new Exception( 'Unable to load an order.' );
		}

		// Ensure the payee reference matches the order.
		$order_payee_reference = $order->get_meta( '_payex_payee_reference' );
		if ( ! hash_equals( $order_payee_reference, $payee_reference ) ) {
			throw new Exception( 'A provided payee reference has been invalid.' );
		}

		return $order;
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param int    $order_id The WC_Order ID to refund.
	 * @param float  $amount The amount to refund. If null, a full refund is assumed.
	 * @param string $reason The reason for the refund.
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Full Refund.
		if ( is_null( $amount ) ) {
			return new WP_Error( 'refund', __( 'Amount must be specified.', 'swedbank-pay-payment-menu' ) );
		}

		if ( 0 === absint( $amount ) ) {
			return new WP_Error( 'refund', __( 'Amount must be positive.', 'swedbank-pay-payment-menu' ) );
		}

		// It uses transient `sb_refund_parameters_` to get items.
		$args = get_transient( 'sb_refund_parameters_' . $order->get_id() );
		if ( empty( $args ) ) {
			$args = array();
		}
		$lines = isset( $args['line_items'] ) ? $args['line_items'] : array();

		// Remove transient if exists.
		delete_transient( 'sb_refund_parameters_' . $order->get_id() );

		$refund_by_amount = true;
		if ( count( $lines ) > 0 ) {
			foreach ( $lines as $line ) {
				if ( $line['qty'] >= 0.1 ) {
					$refund_by_amount = false;
					break;
				}
			}
		}

		if ( ! $refund_by_amount ) {
			// Refund by items.
			$result = $this->payment_actions_handler->refund_payment( $order, $lines, $reason, false );
			if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
				return new WP_Error( 'refund', join( '; ', $result->get_error_messages() ) );
			}

			$order->update_meta_data( '_sb_refund_mode', 'items' );
			$order->save_meta_data();

			return true;
		}

		// Refund by amount.
		$result = $this->payment_actions_handler->refund_payment_amount( $order, $amount );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			return new WP_Error( 'refund', join( '; ', $result->get_error_messages() ) );
		}

		$order->update_meta_data( '_sb_refund_mode', 'amount' );
		$order->save_meta_data();

		return true;
	}

	/**
	 * Override payment method title.
	 *
	 * @param string   $value Current payment method title.
	 * @param WC_Order $order Order object.
	 *
	 * @return string
	 */
	public function payment_method_title( $value, $order ) {
		if ( is_admin() ) {
			return $value;
		}

		if ( $this->id !== $order->get_payment_method() ) {
			return $value;
		}

		// Prevent the filter from running more than once on a page load.
		if ( did_filter( 'woocommerce_order_get_payment_method_title' ) > 1 ) {
			return $value;
		}

		$instrument = $order->get_meta( '_swedbank_pay_payment_instrument' );
		if ( empty( $instrument ) ) {
			$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
			if ( ! empty( $payment_order_id ) ) {
				// Fetch payment info.
				$result = $this->api->request( 'GET', $payment_order_id . '/paid' );
				if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
					// Request failed.
					return $value;
				}
				$instrument = $result['paid']['instrument'];
				$order->update_meta_data( '_swedbank_pay_payment_instrument', $instrument );
				$order->save();

				return sprintf( '%s (%s)', $value, $instrument );
			}

			return $value;
		}

		return sprintf( '%s (%s)', $value, $instrument );
	}

	/**
	 * Override title.
	 *
	 * @param string $title The thankyou-page title.
	 *
	 * @return string|null
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function update_order_received_title( $title ) {
		$order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
				return __( 'Order has been cancelled.', 'swedbank-pay-payment-menu' );
			}
		}

		return $title;
	}

	/**
	 * Whether the order should be editable.
	 *
	 * @param bool     $is_editable Whether the order is editable.
	 * @param WC_Order $order Order object.
	 *
	 * @return bool
	 */
	public function is_editable( $is_editable, $order ) {
		if ( $order->get_payment_method() !== $this->id ) {
			return $is_editable;
		}

		// Allow editing if the order is a subscription and is editable.
		if ( class_exists( 'WC_Subscription' ) && $order instanceof WC_Subscription && $is_editable ) {
			return true;
		}

		// Otherwise, do not allow editing for orders paid with this gateway.
		return false;
	}

	/**
	 * Print payment fields...
	 *
	 * @return void
	 */
	public function payment_fields() {
		CheckoutFlow::payment_fields();
	}
}
