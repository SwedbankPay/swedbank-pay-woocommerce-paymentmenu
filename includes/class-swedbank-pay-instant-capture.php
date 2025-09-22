<?php

namespace SwedbankPay\Checkout\WooCommerce;

use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;

defined( 'ABSPATH' ) || exit;

use WC_Log_Levels;

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Swedbank_Pay_Instant_Capture {
	/**
	 * CAPTURE Type options
	 */
	const CAPTURE_VIRTUAL   = 'online_virtual';
	const CAPTURE_PHYSICAL  = 'physical';
	const CAPTURE_RECURRING = 'recurring';
	const CAPTURE_FEE       = 'fee';

	/**
	 * @var \Swedbank_Pay_Payment_Gateway_Checkout
	 */
	private $gateway;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_capture_instantly' ), 50, 10 );
	}

	/**
	 * Maybe capture instantly.
	 *
	 * @param $order_id The WooCommerce order ID.
	 *
	 * @throws \Exception If the capture fails.
	 */
	public function maybe_capture_instantly( $order_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( ! in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return;
		}

		$this->gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $this->gateway ) {
			return;
		}

		// Disable this feature if "Autocomplete" is active.
		if ( 'yes' === $this->gateway->autocomplete ) {
			return;
		}

		// Capture if possible.
		if ( ! $this->gateway->api->is_captured( $payment_order_id ) ) {
			try {
				$this->instant_capture( $order );
			} catch ( \Exception $e ) {
				$this->gateway->api->log(
					WC_Log_Levels::INFO,
					sprintf( '%s: Warning: %s', __METHOD__, $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Capture order using "Instant Capture".
	 *
	 * @param \WC_Order $order
	 *
	 * @throws \Exception
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	private function instant_capture( $order ) {
		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		$items = $this->get_instant_capture_items( $order );
		$this->gateway->api->log( WC_Log_Levels::INFO, __METHOD__, array( $items ) );
		if ( count( $items ) > 0 ) {
			if ( ! Swedbank_Pay_Subscription::should_skip_order_management( $order ) ) {

				$result = $this->gateway->api->capture_checkout( $order, $items );
				if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
					/** @var \WP_Error $result */
					throw new \Exception( $result->get_error_message() );
				}
			}

			// Save captured order lines.
			$captured = array();
			foreach ( $items as $item ) {
				$captured[] = array(
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => $item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ],
					Swedbank_Pay_Order_Item::FIELD_QTY => $item[ Swedbank_Pay_Order_Item::FIELD_QTY ],
				);
			}

			$order->update_meta_data( '_payex_captured_items', $captured );
			$order->save_meta_data();
		}
	}

	/**
	 * Get items which should be captured instantly.
	 *
	 * @param \WC_Order $order
	 * @return array
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	private function get_instant_capture_items( $order ) {
		if ( ! is_array( $this->gateway->instant_capture ) || count( $this->gateway->instant_capture ) === 0 ) {
			return array();
		}

		$items = array();
		foreach ( $order->get_items() as $order_item ) {
			/** @var \WC_Order_Item_Product $order_item */
			/** @var \WC_Product $product */
			$product        = $order_item->get_product();
			$price          = $order->get_line_subtotal( $order_item, false, false );
			$price_with_tax = $order->get_line_subtotal( $order_item, true, false );
			$tax            = $price_with_tax - $price;
			$tax_percent    = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;
			$qty            = $order_item->get_quantity();
			$image          = wp_get_attachment_image_src( $order_item->get_product()->get_image_id(), 'full' );

			if ( $image ) {
				$image = array_shift( $image );
			} else {
				$image = wc_placeholder_img_src( 'full' );
			}

			if ( null === wp_parse_url( $image, PHP_URL_SCHEME ) &&
				mb_substr( $image, 0, mb_strlen( WP_CONTENT_URL ), 'UTF-8' ) === WP_CONTENT_URL
			) {
				$image = wp_guess_url() . $image;
			}

			// Get Product Class
			$product_class = $product->get_meta( '_swedbank_pay_product_class' );
			if ( empty( $product_class ) ) {
				$product_class = 'ProductGroup1';
			}

			// Get Product Sku
			$product_reference = trim(
				str_replace(
					array( ' ', '.', ',' ),
					'-',
					$order_item->get_product()->get_sku()
				)
			);

			if ( empty( $product_reference ) ) {
				$product_reference = wp_generate_password( 12, false );
			}

			$product_name = trim( $order_item->get_name() );

			if ( in_array( self::CAPTURE_PHYSICAL, $this->gateway->instant_capture, true ) &&
				 ( ! self::wcs_is_subscription_product( $product ) && $product->needs_shipping() && ! $product->is_downloadable() ) //phpcs:ignore
			) {
				$items[] = array(
					// The field Reference must match the regular expression '[\\w-]*'
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => $product_reference,
					Swedbank_Pay_Order_Item::FIELD_NAME   => ! empty( $product_name ) ? $product_name : '-',
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => $product_class,
					Swedbank_Pay_Order_Item::FIELD_ITEM_URL => $order_item->get_product()->get_permalink(),
					Swedbank_Pay_Order_Item::FIELD_IMAGE_URL => $image,
					Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $order_item->get_name(),
					Swedbank_Pay_Order_Item::FIELD_QTY    => $qty,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( $price_with_tax / $qty * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( $price_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => round( $tax * 100 ),
				);

				continue;
			} elseif ( in_array( self::CAPTURE_VIRTUAL, $this->gateway->instant_capture, true ) &&
					   ( ! self::wcs_is_subscription_product( $product ) && ( $product->is_virtual() || $product->is_downloadable() ) ) //phpcs:ignore
			) {
				$items[] = array(
					// The field Reference must match the regular expression '[\\w-]*'
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => $product_reference,
					Swedbank_Pay_Order_Item::FIELD_NAME   => ! empty( $product_name ) ? $product_name : '-',
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => $product_class,
					Swedbank_Pay_Order_Item::FIELD_ITEM_URL => $order_item->get_product()->get_permalink(),
					Swedbank_Pay_Order_Item::FIELD_IMAGE_URL => $image,
					Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $order_item->get_name(),
					Swedbank_Pay_Order_Item::FIELD_QTY    => $qty,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( $price_with_tax / $qty * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( $price_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => round( $tax * 100 ),
				);

				continue;
			} elseif ( in_array( self::CAPTURE_RECURRING, $this->gateway->instant_capture, true ) &&
					   self::wcs_is_subscription_product( $product ) //phpcs:ignore
			) {
				$items[] = array(
					// The field Reference must match the regular expression '[\\w-]*'
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => $product_reference,
					Swedbank_Pay_Order_Item::FIELD_NAME   => ! empty( $product_name ) ? $product_name : '-',
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => $product_class,
					Swedbank_Pay_Order_Item::FIELD_ITEM_URL => $order_item->get_product()->get_permalink(),
					Swedbank_Pay_Order_Item::FIELD_IMAGE_URL => $image,
					Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $order_item->get_name(),
					Swedbank_Pay_Order_Item::FIELD_QTY    => $qty,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( $price_with_tax / $qty * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( $price_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => round( $tax * 100 ),
				);

				continue;
			}
		}

		// Add Shipping Total
		if ( in_array( self::CAPTURE_PHYSICAL, $this->gateway->instant_capture, true ) ) {
			if ( (float) $order->get_shipping_total() > 0 ) {
				$shipping          = (float) $order->get_shipping_total();
				$tax               = (float) $order->get_shipping_tax();
				$shipping_with_tax = $shipping + $tax;
				$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;
				$shipping_method   = trim( $order->get_shipping_method() );

				$items[] = array(
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => 'shipping',
					Swedbank_Pay_Order_Item::FIELD_NAME   => ! empty( $shipping_method ) ? $shipping_method : __(
						'Shipping',
						'woocommerce'
					),
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_SHIPPING,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => 'ProductGroup1',
					Swedbank_Pay_Order_Item::FIELD_QTY    => 1,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( $shipping_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( $shipping_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => round( $tax * 100 ),
				);
			}
		}

		// Add fees
		if ( in_array( self::CAPTURE_FEE, $this->gateway->instant_capture, true ) ) {
			foreach ( $order->get_fees() as $order_fee ) {
				/** @var \WC_Order_Item_Fee $order_fee */
				$fee          = (float) $order_fee->get_total();
				$tax          = (float) $order_fee->get_total_tax();
				$fee_with_tax = $fee + $tax;
				$tax_percent  = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

				$items[] = array(
					Swedbank_Pay_Order_Item::FIELD_REFERENCE => 'fee',
					Swedbank_Pay_Order_Item::FIELD_NAME   => $order_fee->get_name(),
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_OTHER,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => 'ProductGroup1',
					Swedbank_Pay_Order_Item::FIELD_QTY    => 1,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( $fee_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( $fee_with_tax * 100 ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => round( $tax * 100 ),
				);
			}
		}

		// Add discounts
		if ( $order->get_total_discount( false ) > 0 ) {
			$discount          = abs( $order->get_total_discount( true ) );
			$discount_with_tax = abs( $order->get_total_discount( false ) );
			$tax               = $discount_with_tax - $discount;
			$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$items[] = array(
				Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'discount',
				Swedbank_Pay_Order_Item::FIELD_NAME        => __( 'Discount', 'swedbank-pay-woocommerce-checkout' ),
				Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_DISCOUNT,
				Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
				Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
				Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
				Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( -100 * $discount_with_tax ),
				Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( 100 * $tax_percent ),
				Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( -100 * $discount_with_tax ),
				Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( -100 * $tax ),
			);
		}

		// YITH WooCommerce Gift Cards
		if ( function_exists( 'YITH_YWGC' ) ) {
			$order_gift_cards = $order->get_meta( '_ywgc_applied_gift_cards' );
			if ( empty( $order_gift_cards ) ) {
				$order_gift_cards = array();
			}

			// Compatibility: YITH WooCommerce Gift Cards v4.11
			if ( 0 === count( $order_gift_cards ) ) {
				foreach ( $order->get_items( 'yith_gift_card' ) as $gift_card ) {
					/** @var \YITH_Gift_Card_Order_Item $gift_card */
					$order_gift_cards[ $gift_card->get_code() ] = abs( $gift_card->get_amount() );
				}
			}

			foreach ( $order_gift_cards as $code => $amount ) {
				$amount = apply_filters( 'ywgc_gift_card_amount_order_total_item', $amount, YITH_YWGC()->get_gift_card_by_code( $code ) );
				if ( $amount > 0 ) {
					// Calculate taxes
					$tax_items = $order->get_items( 'tax' );

					foreach ( $tax_items as $item_id => $item_tax ) {
						$tax_data = $item_tax->get_data();
						$tax_rate = $tax_data['rate_percent'];
					}

					$tax_rate          = isset( $tax_rate ) ? $tax_rate : '0';
					$rate_aux          = '0.' . $tax_rate;
					$discount_with_tax = -1 * $amount;
					$discount          = round( -1 * ( $amount / ( 1 + (float) $rate_aux ) ), 2 );

					$items[] = array(
						Swedbank_Pay_Order_Item::FIELD_REFERENCE => 'gift_card_' . esc_html( $code ),
						Swedbank_Pay_Order_Item::FIELD_NAME => __( 'Gift card: ' . esc_html( $code ), 'yith-woocommerce-gift-cards' ),
						Swedbank_Pay_Order_Item::FIELD_TYPE => Swedbank_Pay_Order_Item::TYPE_DISCOUNT,
						Swedbank_Pay_Order_Item::FIELD_CLASS => 'ProductGroup1',
						Swedbank_Pay_Order_Item::FIELD_QTY => 1,
						Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
						Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( 100 * $discount_with_tax ),
						Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => 100 * round( 100 * $rate_aux ),
						Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( 100 * $discount_with_tax ),
						Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => 100 * round( $discount_with_tax - $discount ),
					);
				}
			}
		}

		return $items;
	}


	/**
	 * Checks if there's Subscription Product.
	 *
	 * @param \WC_Product $product
	 *
	 * @return bool
	 */
	private static function wcs_is_subscription_product( $product ) {
		return class_exists( '\\WC_Subscriptions_Product', false ) &&
			   \WC_Subscriptions_Product::is_subscription( $product ); //phpcs:ignore
	}
}

new Swedbank_Pay_Instant_Capture();
