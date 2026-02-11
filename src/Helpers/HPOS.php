<?php
/**
 * Class for adding HPOS compatibility while maintaining legacy functionality.
 *
 * Assets management.
 */

namespace Krokedil\Swedbank\Pay\Helpers;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Class HPOS.
 */
class HPOS {
	/**
	 * Equivalent to WP's get_the_ID() with HPOS support.
	 *
	 * @return int|false the order ID or false.
	 */
    //phpcs:ignore
    public static function get_the_ID() {
		$hpos_enabled = self::is_hpos_enabled();
		$order_id     = $hpos_enabled ? filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT ) : \get_the_ID();
		if ( empty( $order_id ) ) {
			return false;
		}

		return \absint( $order_id );
	}

	/**
	 * Whether HPOS is enabled.
	 *
	 * @return bool
	 */
	public static function is_hpos_enabled() {
		if ( class_exists( OrderUtil::class ) ) {
			return OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	/**
	 * Retrieves the post type of the current post or of a given post.
	 *
	 * Compatible with HPOS.
	 *
	 * @param int|\WP_Post|\WC_Order|null $post Order ID, post object or order object.
	 * @return string|null|false Return type of passed id, post or order object on success, false or null on failure.
	 */
	public static function get_post_type( $post = null ) {
		if ( ! self::is_hpos_enabled() ) {
			return get_post_type( $post );
		}

		return ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ? false : OrderUtil::get_order_type( $post );
	}

	/**
	 * Retrieves the post type of the current post or of a given post.
	 *
	 * @param int|\WP_Post|\WC_Order|null $post Order ID, post object or order object.
	 * @return true if order type, otherwise false.
	 */
	public static function is_order_type( $post = null ) {
		return in_array( get_post_type( $post ), array( 'woocommerce_page_wc-orders', 'shop_order' ), true );
	}

	/**
	 * Whether the current page is an order page.
	 *
	 * @return bool
	 */
	public static function is_order_page() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( ! empty( $screen ) ) {
				return 'shop_order' === $screen->post_type;
			}
		}

		return self::is_order_type( get_the_ID() );
	}
}
