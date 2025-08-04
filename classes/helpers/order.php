<?php

namespace SwedbankPay\Checkout\WooCommerce\Helpers;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction as TransactionData;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Collection\OrderItemsCollection;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Collection\Item\OrderItem;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Client;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;

/**
 * Class Order
 *
 * This class represents a Swedbank Pay order, providing methods to retrieve and format order details,
 * including items, metadata, payee information, URLs, and payer information.
 * It also provides methods to calculate total amounts and VAT amounts for the order.
 */
class Order {
	public const OPERATION_PURCHASE    = 'Purchase';
	public const OPERATION_UNSCHEDULED = 'UnscheduledPurchase';

	/**
	 * This is the WooCommerce order or refund object.
	 *
	 * @var \WC_Order|\WC_Order_Refund
	 */
	private $order;

	/**
	 * This is the Swedbank Pay payment gateway instance.
	 *
	 * @var \Swedbank_Pay_Payment_Gateway_Checkout
	 */
	private $gateway;

	/**
	 * User agent for the order.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * If isset, will be used for generating the OrderItemsCollection instead of using the WC order items.
	 *
	 * Set to `null` by default, meaning it will use the WC order items.
	 *
	 * @var array|null
	 */
	private $formatted_items = null;

	/**
	 * Order constructor.
	 *
	 * Initializes the order with the provided WC order or WC order refund object.
	 * Retrieves the payment gateway and sets the user agent based on the order's customer user agent.
	 *
	 * @param \WC_Order|\WC_Order_Refund $order The WooCommerce order or refund object.
	 * @param array|null                 $items Optional. If provided, these items will be used for generating the OrderItemsCollection instead of using the WC order items.
	 *                                           Set to `null` (or empty) to retrieve from the WC order instead.
	 */
	public function __construct( \WC_Order|\WC_Order_Refund $order, ?array $items = null ) {
		$this->order           = $order;
		$this->formatted_items = $items;

		$this->gateway = swedbank_pay_get_payment_method( $this->order );

		$this->user_agent = $order->get_customer_user_agent();
		if ( empty( $this->user_agent ) ) {
			$this->user_agent = 'WooCommerce/' . WC()->version;
		}
	}

	/**
	 * Get the WC order.
	 *
	 * @return \WC_Order|\WC_Order_Refund
	 */
	public function get_order() {
		return $this->order;
	}

	/**
	 * Get the formatted items from the WC order.
	 *
	 * This method retrieves the order lines formatted for Swedbank Pay.
	 *
	 * @return array
	 */
	public function get_formatted_items_from_order() {
		return swedbank_pay_get_order_lines( $this->order );
	}

	/**
	 * Get the order items.
	 *
	 * This method retrieves the order items formatted for Swedbank Pay.
	 *
	 * @hook swedbank_pay_order_items
	 * @return OrderItemsCollection
	 */
	public function get_order_items() {
		$items = empty( $this->formatted_items ) ? $this->get_formatted_items_from_order() : $this->formatted_items;

		$order_items = new OrderItemsCollection();
		foreach ( $items as $item ) {
			$order_item = ( new OrderItem() )
			->setReference( $item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] )
			->setName( $item[ Swedbank_Pay_Order_Item::FIELD_NAME ] )
			->setType( $item[ Swedbank_Pay_Order_Item::FIELD_TYPE ] )
			->setItemClass( $item[ Swedbank_Pay_Order_Item::FIELD_CLASS ] )
			->setItemUrl( $item[ Swedbank_Pay_Order_Item::FIELD_ITEM_URL ] ?? '' )
			->setImageUrl( $item[ Swedbank_Pay_Order_Item::FIELD_IMAGE_URL ] ?? '' )
			->setDescription( $item[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] ?? '' )
			->setQuantity( $item[ Swedbank_Pay_Order_Item::FIELD_QTY ] )
			->setUnitPrice( $item[ Swedbank_Pay_Order_Item::FIELD_UNITPRICE ] )
			->setQuantityUnit( $item[ Swedbank_Pay_Order_Item::FIELD_QTY_UNIT ] )
			->setVatPercent( $item[ Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT ] )
			->setAmount( $item[ Swedbank_Pay_Order_Item::FIELD_AMOUNT ] )
			->setVatAmount( $item[ Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT ] );

			$order_items->addItem( $order_item );
		}

		return apply_filters( 'swedbank_pay_order_items', $order_items, $this );
	}

	/**
	 * Get the metadata for the order.
	 *
	 * This method retrieves the metadata for the order, including the order ID.
	 *
	 * @hook swedbank_pay_order_metadata
	 * @return PaymentorderMetadata
	 */
	public function get_metadata() {
		$metadata = ( new PaymentorderMetadata() )->setData( 'order_id', $this->order->get_id() );
		return apply_filters( 'swedbank_pay_metadata', $metadata, $this );
	}

	/**
	 * Get the payee information for the order.
	 *
	 * This method retrieves the payee information, including order reference, payee reference, payee ID, and payee name.
	 *
	 * @return PaymentorderPayeeInfo
	 */
	public function get_payee_info() {
		$payee = new PaymentorderPayeeInfo(
			array(
				'orderReference' => apply_filters(
					'swedbank_pay_order_reference',
					$this->order->get_id()
				),
				'payeeReference' => apply_filters(
					'swedbank_pay_payee_reference',
					swedbank_pay_generate_payee_reference( $this->order->get_id() )
				),
				'payeeId'        => $this->gateway->payee_id,
				'payeeName'      => apply_filters(
					'swedbank_pay_payee_name',
					get_bloginfo( 'name' ),
					$this->gateway->id
				),
			)
		);

		return apply_filters( 'swedbank_pay_payee', $payee, $this );
	}

	/**
	 * Get the URLs for the payment order.
	 *
	 * This method retrieves the URLs for the payment order, including complete URL, cancel URL, callback URL, terms of service URL, and logo URL.
	 *
	 * @hook swedbank_pay_urls
	 * @return PaymentorderUrl
	 */
	public function get_url_data() {
		$callback_url = add_query_arg(
			array(
				'order_id' => $this->order->get_id(),
				'key'      => $this->order->get_order_key(),
			),
			WC()->api_request_url( get_class( $this->gateway ) )
		);

		$complete_url = $this->gateway->get_return_url( $this->order );
		$cancel_url   = is_checkout() ? wc_get_checkout_url() : $this->order->get_cancel_order_url_raw();

		$url_data = ( new PaymentorderUrl() )
			->setHostUrls(
				$this->get_host_urls(
					array(
						$complete_url,
						$cancel_url,
						$callback_url,
						$this->gateway->terms_url,
						$this->gateway->logo_url,
					)
				)
			)
			->setCompleteUrl( $complete_url )
			->setCancelUrl( $cancel_url )
			->setCallbackUrl( $callback_url )
			->setTermsOfService( $this->gateway->terms_url )
			->setLogoUrl( $this->gateway->logo_url );

		return apply_filters( 'swedbank_pay_urls', $url_data, $this );
	}

	/**
	 * Get the payer information for the order.
	 *
	 * This method retrieves the payer information, including payer reference, first name, last name, email, and phone number.
	 * It also checks if the products in the order require shipping and sets the digital products flag accordingly.
	 *
	 * @hook swedbank_pay_payer
	 * @return PaymentorderPayer
	 */
	public function get_payer() {
		$payer = ( new PaymentorderPayer() )
				->setPayerReference( $this->get_customer_uuid() )
				->setFirstName( $this->order->get_billing_first_name() )
				->setLastName( $this->order->get_billing_last_name() )
				->setEmail( $this->order->get_billing_email() )
				->setMsisdn( str_replace( ' ', '', $this->order->get_billing_phone() ) );

		$needs_shipping = false;
		foreach ( $this->order->get_items() as $order_item ) {
			$product = $order_item->get_product();
			if ( $product && $product->needs_shipping() ) {
				$needs_shipping = true;
				break;
			}
		}

		if ( ! $needs_shipping ) {
			$payer->setDigitalProducts( true );
		}

		return apply_filters( 'swedbank_pay_payer', $payer, $this );
	}

	/**
	 * Get the payment order.
	 *
	 * This method constructs a Paymentorder object with the necessary details for the payment.
	 * It includes operation type, currency, amount, VAT amount, description, user agent, language, product name,
	 * implementation type, URLs, payee information, metadata, and payer information.
	 *
	 * @hook swedbank_pay_payment_order
	 * @return Paymentorder
	 */
	public function get_payment_order() {
		$items = $this->get_formatted_items_from_order();

		$payment_order = ( new Paymentorder() )
			->setOperation( self::OPERATION_PURCHASE )
			->setCurrency( $this->order->get_currency() )
			->setAmount(
				(int) bcmul(
					100,
					apply_filters(
						'swedbank_pay_order_amount',
						$this->order->get_total(),
						$items,
						$this->order
					)
				)
			)
			->setVatAmount(
				apply_filters(
					'swedbank_pay_order_vat',
					$this->calculate_vat_amount( $items ),
					$items,
					$this->order
				)
			)
			->setDescription(
				apply_filters(
					'swedbank_pay_payment_description',
					sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$s', 'swedbank-pay-woocommerce-payments' ),
						$this->order->get_order_number()
					),
					$this->order
				)
			)
			->setUserAgent( $this->user_agent )
			->setLanguage( $this->gateway->culture )
			->setProductName( 'Checkout3' )
			->setImplementation( 'PaymentsOnly' )
			->setDisablePaymentMenu( false )
			->setUrls( $this->get_url_data() )
			->setPayeeInfo( $this->get_payee_info() )
			->setMetadata( $this->get_metadata() );

		if ( ! $this->gateway->exclude_order_lines ) {
			$payment_order->setOrderItems( $this->get_order_items() );
		}

		$payment_order->setPayer( $this->get_payer() );

		return apply_filters( 'swedbank_pay_payment_order', $payment_order, $this );
	}

	/**
	 * Get the transaction data for the order.
	 *
	 * This method constructs a TransactionData object with the necessary details for the transaction.
	 * It includes amount, VAT amount, description, payee reference, and order items.
	 *
	 * @hook swedbank_pay_transaction_data
	 * @return TransactionData
	 */
	public function get_transaction_data() {
		$order_items = $this->get_order_items();
		$items       = $this->get_formatted_items_from_order();

		$payee_reference = apply_filters(
			'swedbank_pay_payee_reference',
			swedbank_pay_generate_payee_reference( $this->order->get_id() )
		);

		$transaction_data = ( new TransactionData() )
			->setAmount( $this->calculate_total_amount( $items ) )
			->setVatAmount( $this->calculate_vat_amount( $items ) )
			->setPayeeReference( $payee_reference )
			->setOrderItems( $order_items );

		if ( $this->order instanceof \WC_Order_Refund ) {
			$transaction_data->setReceiptReference( $payee_reference );
		}

		return apply_filters( 'swedbank_pay_transaction_data', $transaction_data, $this );
	}

	/**
	 * Extracts host URLs from an array of URLs.
	 *
	 * This method filters out invalid URLs and returns a unique list of host URLs.
	 *
	 * @param array $urls An array of URLs to extract host URLs from.
	 * @return array An array of unique host URLs.
	 */
	public function get_host_urls( $urls ) {
		$result = array();
		foreach ( $urls as $url ) {
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$parsed   = wp_parse_url( $url );
				$result[] = sprintf( '%s://%s', $parsed['scheme'], $parsed['host'] );
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Generates a unique customer UUID.
	 *
	 * If the user is logged in, it retrieves the UUID from user meta.
	 * If not, it generates a UUID based on the user's email or a unique ID.
	 *
	 * @return string
	 */
	public function get_customer_uuid() {
		$user_id = $this->order->get_user_id();

		if ( $user_id > 0 ) {
			$payer_reference = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $payer_reference ) ) {
				$payer_reference = $user_id;
				update_user_meta( $user_id, '_payex_customer_uuid', $payer_reference );
			}
		} else {
			$payer_reference = uniqid( $this->order->get_billing_email() );
		}

		return apply_filters( 'swedbank_pay_generate_uuid', $payer_reference, $user_id );
	}

	/**
	 * Get the Swedbank Pay client.
	 *
	 * This method creates a new Client instance, sets the access token, payee ID, mode (test or production),
	 * and user agent. It also applies a filter to allow modification of the client.
	 *
	 * @hook swedbank_pay_client
	 * @return Client
	 */
	public static function get_client() {
		$client = new Client();

		$user_agent = "{$client->getUserAgent()} swedbank-pay-payment-menu/" . SWEDBANK_PAY_VERSION;
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent .= ' ' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$settings = get_option( 'woocommerce_payex_checkout_settings' );
		$client->setAccessToken( $settings['access_token'] ?? '' )
				->setPayeeId( $settings['payee_id'] ?? '' )
				->setMode( wc_string_to_bool( $settings['testmode'] ?? 'no' ) ? Client::MODE_TEST : Client::MODE_PRODUCTION )
				->setUserAgent( $user_agent );

		return apply_filters( 'swedbank_pay_client', $client );
	}

	/**
	 * Retrieves the total amount from the order items.
	 *
	 * @see Order::get_formatted_items_from_order
	 * @param array $items The formatted order items from WC.
	 *
	 * @return int|float
	 */
	public function calculate_total_amount( array $items ) {
		$total_amount = 0;
		foreach ( $items as $item ) {
			$total_amount += $item[ Swedbank_Pay_Order_Item::FIELD_AMOUNT ];
		}

		return $total_amount;
	}

	/**
	 * Retrieves the total VAT amount from the order items.
	 *
	 * @see Order::get_formatted_items_from_order
	 * @param array $items The formatted order items from WC.
	 *
	 * @return int|float
	 */
	public function calculate_vat_amount( array $items ) {
		$vat_amount = 0;
		foreach ( $items as $item ) {
			$vat_amount += $item[ Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT ];
		}

		return $vat_amount;
	}

	/**
	 * Set the gateway.
	 *
	 * The gateway is automatically retrieved from the order, but can be set manually if needed.
	 *
	 * @param \Swedbank_Pay_Payment_Gateway_Checkout $gateway Swedbank Pay payment gateway instance.
	 * @return void
	 */
	public function set_gateway( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Set the user agent.
	 *
	 * The user agent is automatically retrieved from the order, but can be set manually if needed.
	 *
	 * @param string $user_agent The user agent string to set.
	 * @return void
	 */
	public function set_user_agent( $user_agent ) {
		$this->user_agent = $user_agent;
	}

	/**
	 * Set the order items.
	 *
	 * If set, these items will be used for generating the OrderItemsCollection instead of using the WC order items.
	 * Set to `null` (or empty) to retrieve from the WC order instead.
	 *
	 * @param array|null $items The order items to set.
	 */
	public function set_items( array $items ) {
		$this->formatted_items = $items;
	}
}
