<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api;

/**
 * Checks if High-Performance Order Storage is enabled.
 *
 * @see https://woocommerce.com/document/high-performance-order-storage/
 * @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
 * @return bool
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function swedbank_pay_is_hpos_enabled() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return false;
	}

	if ( ! method_exists( OrderUtil::class, 'custom_orders_table_usage_is_enabled' ) ) {
		return false;
	}

	return OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Get Post Id by Meta
 *
 * @deprecated Use `swedbank_pay_get_order` instead of
 * @param $key
 * @param $value
 *
 * @return null|string
 */
function swedbank_pay_get_post_id_by_meta( $key, $value ) {
	if ( swedbank_pay_is_hpos_enabled() ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s;",
				$key,
				$value
			)
		);
	}

	$orders = wc_get_orders(
		array(
			'return'     => 'ids',
			'limit'      => 1,
			'meta_query' => array(
				array(
					'key'   => $key,
					'value' => $value,
				),
			),
		)
	);

	if ( count( $orders ) > 0 ) {
		$order = array_shift( $orders );

		if ( is_int( $order ) ) {
			return $order;
		}

		return $order->get_id();
	}

	return null;
}

/**
 * Get Order by Payment Order ID.
 *
 * @uses woocommerce_order_data_store_cpt_get_orders_query hook
 * @param string $paymentOrderId
 *
 * @return WC_Order|null
 */
function swedbank_pay_get_order( $paymentOrderId ) {
	$orders = wc_get_orders(
		array(
			'_payex_paymentorder_id' => $paymentOrderId,
		)
	);

	foreach ( $orders as $order ) {
		return $order;
	}

	return null;
}

/**
 * Get Payment Method.
 *
 * @param WC_Order $order
 *
 * @return null|\WC_Payment_Gateway|\Swedbank_Pay_Payment_Gateway_Checkout
 */
function swedbank_pay_get_payment_method( WC_Order $order ) {
	// Get Payment Gateway
	$gateways = WC()->payment_gateways()->payment_gateways();
	if ( ! isset( $gateways[ $order->get_payment_method() ] ) ) {
		return null;
	}

	/** @var \WC_Payment_Gateway $gateway */
	return $gateways[ $order->get_payment_method() ];
}

/**
 * Get Order Lines.
 *
 * @param WC_Order|WC_Order_Refund $order The order object to retrieve the items from.
 *
 * @return array
 */
function swedbank_pay_get_order_lines( $order ) {
	$items = array();
	foreach ( $order->get_items() as $order_item ) {
		/** @var \WC_Order_Item_Product $order_item */
		/** @var \WC_Product $product */
		$product        = $order_item->get_product();
		$price          = $order->get_line_subtotal( $order_item, false, false );
		$price_with_tax = $order->get_line_subtotal( $order_item, true, false );
		$tax            = $price_with_tax - $price;
		$tax_percent    = $tax > 0 ? round( 100 / ( $price / $tax ) ) : 0;
		$qty            = $order_item->get_quantity();

		// Get Product Class.
		$product_class = $product->get_meta( '_swedbank_pay_product_class' );
		if ( empty( $product_class ) ) {
			$product_class = 'ProductGroup1';
		}

		// Get Product Sku.
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

		$product_name = trim( $product->get_name() );

		$image_url = wc_placeholder_img_src( 'shop_single' );
		if ( $product->get_image_id() > 0 ) {
			$image_id  = $product->get_image_id();
			$image_url = wp_get_attachment_image_url( $image_id, 'shop_single', false );
		}

		$items[] = array(
			// The field Reference must match the regular expression '[\\w-]*'.
			Swedbank_Pay_Order_Item::FIELD_REFERENCE   => $product_reference,
			Swedbank_Pay_Order_Item::FIELD_NAME        => ! empty( $product_name ) ? $product_name : '-',
			Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
			Swedbank_Pay_Order_Item::FIELD_CLASS       => $product_class,
			Swedbank_Pay_Order_Item::FIELD_ITEM_URL    => $product->get_permalink(),
			Swedbank_Pay_Order_Item::FIELD_IMAGE_URL   => $image_url,
			Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $product->get_name(),
			Swedbank_Pay_Order_Item::FIELD_QTY         => $qty,
			Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
			Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $price_with_tax / $qty * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
			Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $price_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
		);
	}

	// Add Shipping Total.
	if ( (float) $order->get_shipping_total() > 0 ) {
		$shipping          = (float) $order->get_shipping_total();
		$tax               = (float) $order->get_shipping_tax();
		$shipping_with_tax = $shipping + $tax;
		$tax_percent       = $tax > 0 ? round( 100 / ( $shipping / $tax ) ) : 0;
		$shipping_method   = trim( $order->get_shipping_method() );
		$name              = ! empty( $shipping_method ) ? $shipping_method : __( 'Shipping', 'woocommerce' );

		$items[] = array(
			Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'shipping',
			Swedbank_Pay_Order_Item::FIELD_NAME        => $name,
			Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $name,
			Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_SHIPPING,
			Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
			Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
			Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
			Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $shipping_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
			Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $shipping_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
		);
	}

	// Add fees.
	foreach ( $order->get_fees() as $order_fee ) {
		/** @var \WC_Order_Item_Fee $order_fee */
		$fee          = (float) $order_fee->get_total();
		$tax          = (float) $order_fee->get_total_tax();
		$fee_with_tax = $fee + $tax;
		$tax_percent  = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

		$items[] = array(
			Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'fee',
			Swedbank_Pay_Order_Item::FIELD_NAME        => $order_fee->get_name(),
			Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_OTHER,
			Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
			Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
			Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
			Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $fee_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
			Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $fee_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
		);
	}

	// Add discounts.
	if ( $order->get_total_discount( false ) > 0 ) {
		$discount          = abs( $order->get_total_discount( true ) );
		$discount_with_tax = abs( $order->get_total_discount( false ) );
		$tax               = $discount_with_tax - $discount;
		$tax_percent       = $tax > 0 ? round( 100 / ( $discount / $tax ) ) : 0;

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

	// YITH WooCommerce Gift Cards.
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
					Swedbank_Pay_Order_Item::FIELD_NAME   => __( 'Gift card: ' . esc_html( $code ), 'yith-woocommerce-gift-cards' ),
					Swedbank_Pay_Order_Item::FIELD_TYPE   => Swedbank_Pay_Order_Item::TYPE_DISCOUNT,
					Swedbank_Pay_Order_Item::FIELD_CLASS  => 'ProductGroup1',
					Swedbank_Pay_Order_Item::FIELD_QTY    => 1,
					Swedbank_Pay_Order_Item::FIELD_QTY_UNIT => 'pcs',
					Swedbank_Pay_Order_Item::FIELD_UNITPRICE => round( 100 * $discount_with_tax ),
					Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => 100 * round( 100 * $rate_aux ),
					Swedbank_Pay_Order_Item::FIELD_AMOUNT => round( 100 * $discount_with_tax ),
					Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT => 100 * round( $discount_with_tax - $discount ),
				);
			}
		}
	}

	return Swedbank_Pay_Api::prepare_for_api( $items );
}

function swedbank_pay_get_available_line_items_for_refund( WC_Order $order ) {
	// Captured items
	$captured = $order->get_meta( '_payex_captured_items' );
	$captured = empty( $captured ) ? array() : (array) $captured;

	// Refunded items
	$refunded = $order->get_meta( '_payex_refunded_items' );
	$refunded = empty( $refunded ) ? array() : (array) $refunded;

	// Order lines
	$lines      = array();
	$line_items = $order->get_items( array( 'line_item', 'shipping', 'fee' ) );
	foreach ( $captured as $captured_item ) {
		foreach ( $line_items as $item_id => $item ) {
			// Get reference
			switch ( $item->get_type() ) {
				case 'line_item':
					$reference = trim(
						str_replace(
							array( ' ', '.', ',' ),
							'-',
							$item->get_product()->get_sku()
						)
					);

					break;
				case 'fee':
					$reference = 'fee';
					break;
				case 'shipping':
					$reference = 'shipping';
					break;
				case 'coupon':
					$reference = 'discount';
					break;
				default:
					$reference = null;
					break;
			}

			if ( ! $reference ) {
				continue;
			}

			$ordered_qty        = $item->get_quantity();
			$row_price          = $order->get_line_total( $item, false, false );
			$row_price_with_tax = $order->get_line_total( $item, true, false );
			$row_tax            = $row_price_with_tax - $row_price;

			if ( $reference === $captured_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ) {
				$qty = $captured_item[ Swedbank_Pay_Order_Item::FIELD_QTY ];

				// Exclude refunded items
				foreach ( $refunded as $refunded_item ) {
					if ( $reference === $refunded_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ) {
						$qty -= $refunded_item[ Swedbank_Pay_Order_Item::FIELD_QTY ];

						break;
					}
				}

				$lines[ $item_id ] = array(
					'qty'          => $qty,
					'refund_total' => $row_price / $ordered_qty * $qty,
					'refund_tax'   => array(),
				);

				// Add tax column
				$order_taxes = wc_tax_enabled() ? $order->get_taxes() : array();
				if ( count( $order_taxes ) > 0 ) {
					/** @var WC_Order_Item_Tax $tax */
					$tax_item = reset( $order_taxes );
					$lines[ $item_id ]['refund_tax'][ $tax_item->get_rate_id() ] = $row_tax / $ordered_qty * $qty;
				}

				break;
			}
		}
	}

	return $lines;
}

/**
 * @param WC_Order $order
 * @param float    $amount
 *
 * @return WC_Order_Refund|null
 */
function swedbank_pay_get_last_refund( WC_Order $order, $amount ) {
	$refunds = $order->get_refunds();
	foreach ( $refunds as $refund ) {
		/** @var WC_Order_Refund $refund */
		if ( round( $amount, 2 ) == round( $refund->get_amount(), 2 ) ) {
			// Check the creation time
			$created_at = $refund->get_date_created();
			if ( ! $created_at ) {
				return $refund;
			}

			if ( $created_at > ( new \WC_DateTime( '-10 minutes' ) ) && $created_at < ( new \WC_DateTime( 'now' ) ) ) {
				return $refund;
			}
		}
	}

	return null;
}

/**
 * Generate Payee Reference for Order.
 *
 * @param mixed $orderId
 *
 * @return string
 */
function swedbank_pay_generate_payee_reference( $order_id ) {
	$arr = range( 'a', 'z' );
	shuffle( $arr );
	$reference = $order_id . 'x' . substr( implode( '', $arr ), 0, 5 );

	return apply_filters( 'swedbank_pay_payee_reference', $reference, $order_id );
}


/**
 * Check if a numeric value can be considered zero.
 *
 * @requires PHP 7.2
 * @param float|integer $value The numeric value to check for.
 *
 * @return bool
 */
function swedbank_pay_is_zero( $value ) {
	return 0 === $value || 0.0 === round( floatval( $value ), wc_get_price_decimals() );
}

/**
 * Format a single cart item to Swedbank.
 *
 * @param array $cart_item The cart item from the cart.
 *
 * @return array
 */
function swedbank_pay_get_cart_item_product( $cart_item ) {
	/** @var \WC_Product $product */
	$product        = $cart_item['data'];
	$price          = $cart_item['line_subtotal'];
	$price_with_tax = $cart_item['line_subtotal'] + $cart_item['line_subtotal_tax'];
	$tax            = $cart_item['line_subtotal_tax'];
	$tax_percent    = $tax > 0 ? round( 100 / ( $price / $tax ) ) : 0;
	$qty            = $cart_item['quantity'];

	$product_class = $product->get_meta( '_swedbank_pay_product_class' );
	if ( empty( $product_class ) ) {
		$product_class = 'ProductGroup1';
	}

	// Get Product Sku.
	$product_reference = trim(
		str_replace(
			array( ' ', '.', ',' ),
			'-',
			$product->get_sku()
		)
	);

	if ( empty( $product_reference ) ) {
		$product_reference = wp_generate_password( 12, false );
	}

	$product_name = trim( $product->get_name() );

	$image_url = wc_placeholder_img_src( 'shop_single' );
	if ( $product->get_image_id() > 0 ) {
		$image_id  = $product->get_image_id();
		$image_url = wp_get_attachment_image_url( $image_id, 'shop_single', false );
	}

	return array(
		// The field Reference must match the regular expression '[\\w-]*'.
		Swedbank_Pay_Order_Item::FIELD_REFERENCE   => $product_reference,
		Swedbank_Pay_Order_Item::FIELD_NAME        => ! empty( $product_name ) ? $product_name : '-',
		Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
		Swedbank_Pay_Order_Item::FIELD_CLASS       => $product_class,
		Swedbank_Pay_Order_Item::FIELD_ITEM_URL    => $product->get_permalink(),
		Swedbank_Pay_Order_Item::FIELD_IMAGE_URL   => $image_url,
		Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => mb_substr( trim( $product->get_name() ), 0, 40 ), // limited to 40 characters in the API.
		Swedbank_Pay_Order_Item::FIELD_QTY         => $qty,
		Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
		Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $price_with_tax / $qty * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
		Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $price_with_tax * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
	);
}

/**
 * Format the cart shipping for Swedbank pay.
 *
 * @return array
 */
function swedbank_pay_get_cart_item_shipping() {
	$shipping_methods = WC()->shipping->get_shipping_methods();
	$chosen_methods   = WC()->session->get( 'chosen_shipping_methods' );

	if ( is_array( $chosen_methods ) && ! empty( $chosen_methods ) ) {
		$chosen_shipping = $chosen_methods[0];
		if ( isset( $shipping_methods[ $chosen_shipping ] ) ) {
			$shipping_method = $shipping_methods[ $chosen_shipping ];
			$cost            = WC()->cart->get_shipping_total();
			$tax             = WC()->cart->get_shipping_tax();
			$cost_with_tax   = $cost + $tax;
			$tax_percent     = $tax > 0 ? round( 100 / ( $cost / $tax ) ) : 0;
			$name            = ! empty( $shipping_method->get_method_title() ) ? $shipping_method->get_method_title() : __( 'Shipping', 'woocommerce' );

			return array(
				Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'shipping',
				Swedbank_Pay_Order_Item::FIELD_NAME        => $name,
				Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $name,
				Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_SHIPPING,
				Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
				Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
				Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
				Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $cost_with_tax * 100 ),
				Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
				Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $cost_with_tax * 100 ),
				Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
			);
		}
	}

	// If we did not get any shipping method, add a generic one since we need shipping.
	$cost          = WC()->cart->get_shipping_total();
	$tax           = WC()->cart->get_shipping_tax();
	$cost_with_tax = $cost + $tax;
	$tax_percent   = $tax > 0 ? round( 100 / ( $cost / $tax ) ) : 0;
	$name          = __( 'Shipping', 'woocommerce' );

	return array(
		Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'shipping',
		Swedbank_Pay_Order_Item::FIELD_NAME        => $name,
		Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $name,
		Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_SHIPPING,
		Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
		Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
		Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
		Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $cost_with_tax * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
		Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $cost_with_tax * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
	);
}

/**
 * Format the cart fee for Swedbank pay.
 *
 * @param object $cart_fee The cart fee from the cart.
 *
 * @return array
 */
function swedbank_pay_get_cart_item_fee( $cart_fee ) {
	$fee          = (float) $cart_fee->amount;
	$tax          = (float) $cart_fee->tax;
	$fee_with_tax = $fee + $tax;
	$tax_percent  = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

	return array(
		Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'fee',
		Swedbank_Pay_Order_Item::FIELD_NAME        => $cart_fee->name,
		Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_OTHER,
		Swedbank_Pay_Order_Item::FIELD_CLASS       => 'ProductGroup1',
		Swedbank_Pay_Order_Item::FIELD_QTY         => 1,
		Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
		Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $fee_with_tax * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
		Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $fee_with_tax * 100 ),
		Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
	);
}

/**
 * Format the cart discount for Swedbank pay.
 *
 * @param float $discount_total The total discount from the cart.
 *
 * @return array
 */
function swedbank_pay_get_cart_item_discount( $discount_total ) {
	$discount          = abs( $discount_total );
	$discount_with_tax = abs( $discount_total + WC()->cart->get_discount_tax() );
	$tax               = $discount_with_tax - $discount;
	$tax_percent       = $tax > 0 ? round( 100 / ( $discount / $tax ) ) : 0;

	return array(
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

/**
 * Get the formatted cart data to send to Swedbank pay.
 *
 * @return array
 */
function swedbank_pay_get_cart_lines() {
	if ( ! WC()->cart ) {
		return array();
	}
	$items = array();

	// Loop each cart item and format it for Swedbank Pay.
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$items[] = swedbank_pay_get_cart_item_product( $cart_item );
	}

	// Process cart shipping.
	if ( WC()->cart->needs_shipping() ) {
		$items[] = swedbank_pay_get_cart_item_shipping();
	}

	// Process cart fees.
	foreach ( WC()->cart->get_fees() as $cart_fee ) {
		$items[] = swedbank_pay_get_cart_item_fee( $cart_fee );
	}

	// Process cart discounts.
	$discount_total = WC()->cart->get_discount_total();
	if ( $discount_total > 0 ) {
		$items[] = swedbank_pay_get_cart_item_discount( $discount_total );
	}

	return $items;
}

/**
 * Get a WooCommerce order by the Payee reference.
 *
 * @param string $payee_reference The Payee reference.
 *
 * @return WC_Order|null The WooCommerce order or null if not found.
 */
function swedbank_pay_get_order_by_payee_reference( $payee_reference ) {
	$orders = wc_get_orders(
		array(
			'meta_key'     => '_payex_payee_reference', // phpcs:ignore WordPress.DB.SlowDBQuery -- We need to query by meta key.
			'meta_value'   => $payee_reference, // phpcs:ignore WordPress.DB.SlowDBQuery -- We need to query by meta value.
			'meta_compare' => '=',
			'order'        => 'DESC',
			'orderby'      => 'date',
			'limit'        => 1,
		)
	);

	foreach ( $orders as $order ) {
		// Compare the metadata to the search value to be sure its the correct one.
		$stored_reference = $order->get_meta( '_payex_payee_reference' );
		if ( $stored_reference !== $payee_reference ) {
			continue;
		}

		return $order;
	}

	return null;
}
