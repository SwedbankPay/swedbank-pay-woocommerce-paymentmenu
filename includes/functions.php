<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

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
