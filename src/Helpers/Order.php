<?php

namespace Krokedil\Swedbank\Pay\Helpers;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction as TransactionData;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order
 *
 * This class represents a Swedbank Pay order, providing methods to retrieve and format order details,
 * including items, metadata, payee information, URLs, and payer information.
 * It also provides methods to calculate total amounts and VAT amounts for the order.
 */
class Order extends PaymentDataHelper {
	/**
	 * This is the WooCommerce order or refund object.
	 *
	 * @var \WC_Order|\WC_Order_Refund
	 */
	private $order;

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
	public function __construct( $order, ?array $items = null ) {
		$this->order           = $order;
		$this->formatted_items = $items;

		if ( $order instanceof \WC_Order_Refund ) {
			$order = wc_get_order( $order->get_parent_id() );
		}
		$this->gateway = swedbank_pay_get_payment_method( $order );

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
	public function get_formatted_items() {
		$items           = array();
		$formatted_items = swedbank_pay_get_order_lines( $this->order );
		foreach ( $formatted_items as $item ) {
			// Swedbank does not allow negative values in any numeric field which will always be the case for WC_Order_Refund.
			$items[] = array_map(
				fn( $value ) => is_numeric( $value ) ? ( $item[ Swedbank_Pay_Order_Item::FIELD_TYPE ] === Swedbank_Pay_Order_Item::TYPE_DISCOUNT ? $value : abs( $value ) ) : $value,
				$item
			);

		}

		return $items;
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
					$this->order->get_order_number()
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
				Swedbank_Pay_Api::get_host_urls(
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
				->setMsisdn( self::format_phone_number( $this->order->get_billing_phone(), $this->order->get_billing_country() ) );

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
	 * @param bool $verify Optional. If true, the operation will be set to 'Verify' and amount and VAT amount will not be set. Default is false.
	 * @return Paymentorder
	 */
	public function get_payment_order( $verify = false ) {
		$payment_order = ( new Paymentorder() )
			->setOperation( $verify ? self::OPERATION_VERIFY : self::OPERATION_PURCHASE )
			->setCurrency( $this->order->get_currency() )
			->setDescription(
				apply_filters(
					'swedbank_pay_payment_description',
					sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$s', 'swedbank-pay-payment-menu' ),
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

		// The Verify operation does not support amount and vatAmount.
		if ( ! $verify ) {
			$items = $this->get_formatted_items();

			$payment_order->setAmount(
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
			);

			if ( ! $this->gateway->exclude_order_lines ) {
				$payment_order->setOrderItems( $this->get_order_items() );
			}
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
		$items       = $this->get_formatted_items();

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
}
