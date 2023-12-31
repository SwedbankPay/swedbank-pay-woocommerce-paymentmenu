<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;

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
 * @uses woocommerce_order_data_store_cpt_get_orders_query hook
 * @param string $paymentOrderId
 *
 * @return WC_Order|null
 */
function swedbank_pay_get_order( $paymentOrderId ) {
	$orders = wc_get_orders(
		array(
			'_payex_paymentorder_id' => $paymentOrderId
		)
	);

	foreach ($orders as $order) {
		return $order;
	}

	return null;
}

/**
 * Get Payment Method.
 *
 * @param WC_Order $order
 *
 * @return null|\WC_Payment_Gateway
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
 * @param WC_Order $order
 *
 * @return array
 */
function swedbank_pay_get_order_lines( WC_Order  $order ) {
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

		if ( null === parse_url( $image, PHP_URL_SCHEME ) &&
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

		$items[] = array(
			// The field Reference must match the regular expression '[\\w-]*'
			Swedbank_Pay_Order_Item::FIELD_REFERENCE   => $product_reference,
			Swedbank_Pay_Order_Item::FIELD_NAME        => ! empty( $product_name ) ? $product_name : '-',
			Swedbank_Pay_Order_Item::FIELD_TYPE        => Swedbank_Pay_Order_Item::TYPE_PRODUCT,
			Swedbank_Pay_Order_Item::FIELD_CLASS       => $product_class,
			Swedbank_Pay_Order_Item::FIELD_ITEM_URL    => $order_item->get_product()->get_permalink(),
			Swedbank_Pay_Order_Item::FIELD_IMAGE_URL   => $image,
			Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $order_item->get_name(),
			Swedbank_Pay_Order_Item::FIELD_QTY          => $qty,
			Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
			Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => round( $price_with_tax / $qty * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => round( $tax_percent * 100 ),
			Swedbank_Pay_Order_Item::FIELD_AMOUNT      => round( $price_with_tax * 100 ),
			Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => round( $tax * 100 ),
		);
	}

	// Add Shipping Total
	if ( (float) $order->get_shipping_total() > 0 ) {
		$shipping          = (float) $order->get_shipping_total();
		$tax               = (float) $order->get_shipping_tax();
		$shipping_with_tax = $shipping + $tax;
		$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;
		$shipping_method   = trim( $order->get_shipping_method() );

		$items[] = array(
			Swedbank_Pay_Order_Item::FIELD_REFERENCE   => 'shipping',
			Swedbank_Pay_Order_Item::FIELD_NAME        => ! empty( $shipping_method ) ? $shipping_method : __(
				'Shipping',
				'woocommerce'
			),
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

	// Add fees
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

	return $items;
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
	shuffle($arr);
	$reference = $order_id . 'x' . substr( implode( '', $arr ), 0, 5 );

	return apply_filters( 'swedbank_pay_payee_reference', $reference, $order_id );
}
