<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Background_Queue;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Transactions;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Instant_Capture;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Payment_Actions;

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
	 * @var Swedbank_Pay_Transactions
	 */
	public $transactions;

	/**
	 * Access Token
	 * @var string
	 */
	public $access_token = '';

	/**
	 * Payee Id
	 * @var string
	 */
	public $payee_id = '';

	/**
	 * Test Mode
	 * @var string
	 */
	public $testmode = 'no';

	/**
	 * Debug Mode
	 * @var string
	 */
	public $debug = 'yes';

	/**
	 * IP Checking
	 * @var string
	 */
	public $ip_check = 'yes';

	/**
	 * Locale
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
	 * Swedbank Pay ip addresses
	 * @var array
	 */
	public $gateway_ip_addresses = array( '91.132.170.1' );

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function __construct() {
		$this->transactions = Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_checkout';
		$this->has_fields   = true;
		$this->method_title = __( 'Swedbank Pay Payment Menu', 'swedbank-pay-woocommerce-checkout' );
		$this->method_description = __( 'Provides the Swedbank Pay Payment Menu for WooCommerce', 'swedbank-pay-woocommerce-checkout' );
		//$this->icon         = apply_filters( 'woocommerce_swedbank_pay_payments_icon', plugins_url( '/assets/images/checkout.svg', dirname( __FILE__ ) ) );
		$this->supports     = array(
			'products',
			'refunds',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Update access_token if merchant_token is exists
		if ( empty( $this->settings['access_token'] ) && ! empty( $this->settings['merchant_token'] ) ) {
			$this->settings['access_token'] = $this->settings['merchant_token'];
			$this->update_option( 'access_token', $this->settings['access_token'] );
		}

		// Define user set variables
		$this->enabled         = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
		$this->title           = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->description     = isset( $this->settings['description'] ) ? $this->settings['description'] : '';
		$this->access_token    = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token;
		$this->payee_id        = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;
		$this->testmode        = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->ip_check        = defined( 'SWEDBANK_PAY_IP_CHECK' ) ? SWEDBANK_PAY_IP_CHECK : $this->ip_check;
		$this->culture         = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->logo_url        = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;
		$this->instant_capture = isset( $this->settings['instant_capture'] ) ? $this->settings['instant_capture'] : $this->instant_capture;
		$this->terms_url       = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->autocomplete    = isset( $this->settings['autocomplete'] ) ? $this->settings['autocomplete'] : 'no';

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		add_action( 'woocommerce_before_thankyou', array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_order_get_payment_method_title', array( $this, 'payment_method_title' ), 2, 10 );

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'return_handler' ) );

		add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'update_order_received_title' ), 3, 10 );
		add_filter( 'wc_order_is_editable', array( $this, 'is_editable' ), 2, 10 );

		$this->api = new Swedbank_Pay_Api( $this );
		$this->api->set_access_token( $this->access_token )
			->set_payee_id( $this->payee_id )
			->set_mode( $this->testmode == 'yes' ? Swedbank_Pay_Api::MODE_TEST : Swedbank_Pay_Api::MODE_LIVE );

		$this->payment_actions_handler = new Swedbank_Pay_Payment_Actions( $this );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function init_form_fields() {
		$portal_url = 'yes' === $this->testmode ? 'https://merchantportal.externalintegration.swedbankpay.com' :
			'https://merchantportal.swedbankpay.com';

		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-woocommerce-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'swedbank-pay-woocommerce-checkout' ),
				'default' => 'yes',
			),
			'testmode'        => array(
				'title'   => __( 'Test Mode', 'swedbank-pay-woocommerce-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', 'swedbank-pay-woocommerce-checkout' ),
				'default' => $this->testmode,
			),
			'title'            => array(
				'title'       => __( 'Title', 'swedbank-pay-woocommerce-checkout' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'swedbank-pay-woocommerce-checkout'
				),
				'default'     => __( 'Swedbank Pay', 'swedbank-pay-woocommerce-checkout' ),
			),
			'description'     => array(
				'title'       => __( 'Description', 'swedbank-pay-woocommerce-checkout' ),
				'type'        => 'text',
				'description' => __(
					'Describe the methods available in your Checkout through Swedbank Pay. Example, “We accept transactions made with Cards (VISA, MasterCard) and Swish”.',
					'swedbank-pay-woocommerce-checkout'
				),
				'default'     => __( 'Swedbank Pay', 'swedbank-pay-woocommerce-checkout' ),
			),
			'payee_id'        => array(
				'title'             => __( 'Payee Id', 'swedbank-pay-woocommerce-checkout' ),
				'type'              => 'text',
				'description'       => /* translators: 1: url */                        sprintf( __( 'Your Payee ID can be found in our Merchant-portal <a href="%1$s" target="_blank">here</a>', 'swedbank-pay-woocommerce-checkout' ), $portal_url ),
				'default'           => $this->payee_id,
				'custom_attributes' => array(
					'required' => 'required',
				),
				'sanitize_callback' => function( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( __( '"Payee Id" field can\'t be empty.', 'swedbank-pay-woocommerce-checkout' ) );
					}

					return $value;
				},
			),
			'access_token'    => array(
				'title'             => __( 'Access Token', 'swedbank-pay-woocommerce-checkout' ),
				'type'              => 'text',
				'description'       => /* translators: 1: url */                        sprintf( __( 'Your Access Token can be found in our Merchant-portal <a href="%1$s" target="_blank">here</a>', 'swedbank-pay-woocommerce-checkout' ), $portal_url ),
				'default'           => $this->access_token,
				'custom_attributes' => array(
					'required' => 'required',
				),
				'sanitize_callback' => function( $value ) {
					if ( empty( $value ) ) {
						throw new Exception( __( '"Access Token" field can\'t be empty.', 'swedbank-pay-woocommerce-checkout' ) );
					}

					return $value;
				},
			),
			'culture'         => array(
				'title'       => __( 'Language', 'swedbank-pay-woocommerce-checkout' ),
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
					'swedbank-pay-woocommerce-checkout'
				),
				'default'     => $this->culture,
			),
			'instant_capture' => array(
				'title'          => __( 'Instant Capture', 'swedbank-pay-woocommerce-checkout' ),
				'description'    => __( 'Capture payment automatically depends on the product type. It\'s working when Auto Capture Intent is off.', 'swedbank-pay-woocommerce-checkout' ),
				'type'           => 'multiselect',
				'css'            => 'height: 150px',
				'options'        => array(
					Swedbank_Pay_Instant_Capture::CAPTURE_VIRTUAL  => __( 'Virtual products', 'swedbank-pay-woocommerce-checkout' ),
					Swedbank_Pay_Instant_Capture::CAPTURE_PHYSICAL => __( 'Physical  products', 'swedbank-pay-woocommerce-checkout' ),
					Swedbank_Pay_Instant_Capture::CAPTURE_FEE      => __( 'Fees', 'swedbank-pay-woocommerce-checkout' ),
				),
				'select_buttons' => true,
				'default'        => $this->instant_capture,
			),
			'terms_url'       => array(
				'title'       => __( 'Terms & Conditions Url', 'swedbank-pay-woocommerce-checkout' ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url. HTTPS is required.', 'swedbank-pay-woocommerce-checkout' ),
				'default'     => get_site_url(),
			),
			'logo_url'        => array(
				'title'             => __( 'Logo Url', 'swedbank-pay-woocommerce-checkout' ),
				'type'              => 'text',
				'description'       => __( 'The URL that will be used for showing the customer logo. Must be a picture with maximum 50px height and 400px width. Require https.', 'swedbank-pay-woocommerce-checkout' ),
				'desc_tip'          => true,
				'default'           => '',
				'sanitize_callback' => function( $value ) {
					if ( ! empty( $value ) ) {
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							throw new Exception( __( 'Logo Url is invalid.', 'swedbank-pay-woocommerce-checkout' ) );
						} elseif ( 'https' !== parse_url( $value, PHP_URL_SCHEME ) ) {
							throw new Exception( __( 'Logo Url should use https scheme.', 'swedbank-pay-woocommerce-checkout' ) );
						}
					}

					return $value;
				},
			),
			'autocomplete'        => array(
				'title'   => __( 'Automatic order status', 'swedbank-pay-woocommerce-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Set order in completed status immediately after payment', 'swedbank-pay-woocommerce-checkout' ),
				'default' => $this->autocomplete,
			),
		);
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
				<h4><?php _e( 'Advanced', 'swedbank-pay-woocommerce-checkout' ); ?></h4>
			</td>
		</tr>
		<?php
		$html = ob_get_contents();
		ob_clean();

		return $html;
	}

	/**
	 * Return the gateway's title.
	 *
	 * @return string
	 */
	public function get_title() {
		$title = __( 'Swedbank Pay Payment Menu', 'swedbank-pay-woocommerce-checkout' );

		return apply_filters( 'woocommerce_gateway_title', $title, $this->id );
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

		// Reload settings
		$this->init_settings();
		$this->access_token = isset( $this->settings['access_token'] ) ? $this->settings['access_token'] : $this->access_token; // phpcs:ignore
		$this->payee_id     = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;

		// Test API Credentials
		try {
			new SwedbankPay\Api\Service\Paymentorder\Request\Test(
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
	 * @param $order_id
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$this->api->log( WC_Log_Levels::INFO, __METHOD__ );
		$is_finalized     = $order->get_meta( '_payex_finalized' );
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $is_finalized ) && $payment_order_id ) {
			$this->api->finalize_payment( $order, $payment_order_id );

			$order = wc_get_order( $order_id ); // reload order
			$order->update_meta_data( '_payex_finalized', 1 );
			$order->save_meta_data();
		}

		// Set `completed` status if it is `processing`
		if ( 'yes' === $this->autocomplete && $order->has_status( 'processing' ) ) {
			$order->payment_complete();
			$order->update_status( 'completed' );
		}

		// Capture payment is applicable
		if ( 'yes' === $this->autocomplete && $order->has_status( 'on-hold' )  ) {
			$result = $this->payment_actions_handler->capture_payment( $order );
			if ( ! is_wp_error( $result ) ) {
				$order->payment_complete();
				$order->update_status( 'completed' );
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

		if ( 'yes' === $this->testmode ) {
			$view_transaction_url = 'https://merchantportal.externalintegration.swedbankpay.com/ecom/paymentorders;id=%s';
		} else {
			$view_transaction_url = 'https://merchantportal.swedbankpay.com/ecom/payments/details;id=%s';
		}

		return sprintf( $view_transaction_url, urlencode( $payment_order_id ) );
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array|false
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( (float) $order->get_total() < 0.01 ) {
			throw new Exception( 'Zero order is not supported.' );
		}

		// Initiate Payment Order
		$result = $this->api->initiate_purchase( $order );
		if ( is_wp_error( $result ) ) {
			throw new Exception(
				$result->get_error_message(),
				$result->get_error_code()
			);
		}

		$redirect_url = $result->getOperationByRel( 'redirect-checkout', 'href' );

		// Save payment ID
		$order->update_meta_data( '_payex_paymentorder_id', $result['response_resource']['payment_order']['id'] );
		$order->save_meta_data();

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url
		);
	}

	/**
	 * IPN Callback
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
			sprintf(
				'Incoming Callback: Initialized %s from %s',
				wp_kses_post( sanitize_text_field( $_SERVER['REQUEST_URI'] ) ), // WPCS: input var ok, CSRF ok.
				wp_kses_post( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) ) // WPCS: input var ok, CSRF ok.
			)
		);
		$this->api->log(
			WC_Log_Levels::INFO,
			sprintf( 'Incoming Callback. Post data: %s', var_export( $raw_body, true ) )
		);

		// Check IP address of Incoming Callback
		if ( 'yes' === $this->ip_check ) {
			if ( ! in_array(
				WC_Geolocation::get_ip_address(),
				apply_filters( 'swedbank_pay_gateway_ip_addresses', $this->gateway_ip_addresses ),
				true
			) ) {
				$this->api->log(
					WC_Log_Levels::INFO,
					sprintf( 'Error: Incoming Callback has been rejected. %s', WC_Geolocation::get_ip_address() )
				);

				throw new Exception( 'Incoming Callback has been rejected' );
			}
		}

		// Decode raw body
		$data = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Invalid webhook data' );
		}

		try {
			// Verify the order key
			$order_id  = absint( wc_clean( $_GET['order_id'] ) ); // WPCS: input var ok, CSRF ok.
			$order_key = empty( $_GET['key'] ) ? '' : sanitize_text_field( wp_unslash( $_GET['key'] ) ); // WPCS: input var ok, CSRF ok.

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( 'Unable to load an order.' );
			}

			if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
				throw new Exception( 'A provided order key has been invalid.' );
			}

			// Validate fields
			if ( ! isset( $data['paymentOrder'] ) || ! isset( $data['paymentOrder']['id'] ) ) {
				throw new \Exception( 'Error: Invalid paymentOrder value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new \Exception( 'Error: Invalid transaction number' );
			}

			sleep(20);

			// Create Background Process Task
			$background_process = new Swedbank_Pay_Background_Queue();
			$background_process->push_to_queue(
				array(
					'payment_method_id' => $this->id,
					'webhook_data'      => $raw_body,
				)
			);
			$background_process->save();

			$this->api->log(
				WC_Log_Levels::INFO,
				sprintf( 'Incoming Callback: Task enqueued. Transaction ID: %s', $data['transaction']['number'] )
			);
		} catch ( \Exception $e ) {
			$this->api->log( WC_Log_Levels::INFO, sprintf( 'Incoming Callback: %s', $e->getMessage() ) );

			return;
		}
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			return new WP_Error( 'refund', __( 'Amount must be specified.', 'swedbank-pay-woocommerce-checkout' ) );
		}

		if ( 0 === absint( $amount ) ) {
			return new WP_Error( 'refund', __( 'Amount must be positive.', 'swedbank-pay-woocommerce-checkout' ) );
		}

		// It uses transient `sb_refund_parameters_` to get items
		$args = get_transient( 'sb_refund_parameters_' . $order->get_id() );
		if ( empty( $args ) ) {
			$args = array();
		}
		$lines = isset( $args['line_items'] ) ? $args['line_items'] : array();

		// Remove transient if exists
		delete_transient( 'sb_refund_parameters_' . $order->get_id() );

		$refundByAmount = true;
		if ( count( $lines ) > 0 ) {
			foreach ( $lines as $line ) {
				if ( $line['qty'] >= 0.1 ) {
					$refundByAmount = false;
					break;
				}
			}
		}

		if ( ! $refundByAmount ) {
			// Refund by items
			$result = $this->payment_actions_handler->refund_payment( $order, $lines, $reason, false );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'refund', join('; ', $result->get_error_messages() ) );
			}

			$order->update_meta_data( '_sb_refund_mode', 'items' );
			$order->save_meta_data();

			return true;
		}

		// Refund by amount
		$result = $this->payment_actions_handler->refund_payment_amount( $order, $amount );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'refund', join('; ', $result->get_error_messages() ) );
		}

		$order->update_meta_data( '_sb_refund_mode', 'amount' );
		$order->save_meta_data();

		return true;
	}

	/**
	 * Override payment method title.
	 *
	 * @param string $value
	 * @param WC_Order $order
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

		$instrument = $order->get_meta( '_swedbank_pay_payment_instrument' );
		if ( empty( $instrument ) ) {
			$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
			if ( ! empty( $payment_order_id ) ) {
				// Fetch payment info
				$result = $this->api->request( 'GET', $payment_order_id . '/paid' );
				if ( is_wp_error( $result ) ) {
					// Request failed
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
	 * @param $title
	 * @param $endpoint
	 * @param $action
	 *
	 * @return string|null
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function update_order_received_title( $title, $endpoint, $action ) {
		$order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
				return __( 'Order has been cancelled.' );
			}
		}

		return $title;
	}

	/**
	 * @param bool $is_editable
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function is_editable( $is_editable, $order ) {
		if ( $order->get_payment_method() === $this->id ) {
			return false;
		}

		return $is_editable;
	}
}
