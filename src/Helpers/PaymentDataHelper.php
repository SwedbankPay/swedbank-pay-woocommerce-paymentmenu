<?php
namespace Krokedil\Swedbank\Pay\Helpers;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Collection\OrderItemsCollection;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Collection\Item\OrderItem;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract helper class to generate payment data.
 */
abstract class PaymentDataHelper {
	public const OPERATION_PURCHASE    = 'Purchase';
	public const OPERATION_VERIFY      = 'Verify';
	public const OPERATION_UNSCHEDULED = 'UnscheduledPurchase';

	/**
	 * This is the Swedbank Pay payment gateway instance.
	 *
	 * @var \Swedbank_Pay_Payment_Gateway_Checkout
	 */
	protected $gateway;

	/**
	 * User agent for the order.
	 *
	 * @var string
	 */
	protected $user_agent;

	/**
	 * If isset, will be used for generating the OrderItemsCollection instead of using the WC order items.
	 *
	 * Set to `null` by default, meaning it will use the WC order items.
	 *
	 * @var array|null
	 */
	protected $formatted_items = null;

	/**
	 * Get the formatted items.
	 *
	 * @return array
	 */
	abstract protected function get_formatted_items();

	/**
	 * Get the order items.
	 *
	 * This method retrieves the order items formatted for Swedbank Pay.
	 *
	 * @hook swedbank_pay_order_items
	 * @return OrderItemsCollection
	 */
	public function get_order_items() {
		$items = empty( $this->formatted_items ) ? $this->get_formatted_items() : $this->formatted_items;

		$order_items = new OrderItemsCollection();
		foreach ( $items as $item ) {
			$order_item = ( new OrderItem() )
			->setReference( $item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] )
			->setName( $item[ Swedbank_Pay_Order_Item::FIELD_NAME ] )
			->setType( $item[ Swedbank_Pay_Order_Item::FIELD_TYPE ] )
			->setItemClass( $item[ Swedbank_Pay_Order_Item::FIELD_CLASS ] )
			->setItemUrl( $item[ Swedbank_Pay_Order_Item::FIELD_ITEM_URL ] ?? '' )
			->setImageUrl( $item[ Swedbank_Pay_Order_Item::FIELD_IMAGE_URL ] ?? '' )
			->setDescription( mb_substr( trim( $item[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] ?? '' ), 0, 40 ) )
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
	 * Retrieves the total amount from the order items.
	 *
	 * @see Order::get_formatted_items
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
	 * @see Order::get_formatted_items
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

	/**
	 * Get the order for the helper.
	 *
	 * @return \WC_Order|\WC_Order_Refund|null
	 */
	public function get_order() {
		return null;
	}

	/**
	 * Format the phonenumber according to the E.164 standard.
	 *
	 * @param string $phone_number The phone number to format.
	 * @param string $country_code The country code for the phone number.
	 *
	 * @return string The formatted phone number.
	 */
	public static function format_phone_number( $phone_number, $country_code ) {
		// Ensure the string is not empty, and does not already start with a '+'.
		if ( ! empty( $phone_number ) && strpos( $phone_number, '+' ) !== 0 ) {
			$country_calling_code  = WC()->countries->get_country_calling_code( $country_code );
			if ( ! empty( $country_calling_code ) ) {
				// Remove leading zeros and prepend the country calling code.
				$phone_number = $country_calling_code . ltrim( $phone_number, '0' );
			}
		}

		// Remove any spaces from the phone number.
		$phone_number = str_replace( ' ', '', $phone_number );

		return $phone_number;
	}
}
