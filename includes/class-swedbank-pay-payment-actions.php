<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;


use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use WC_Product;
use WC_Payment_Gateway;
use WC_Log_Levels;
use WC_Order;
use Swedbank_Pay_Payment_Gateway_Checkout;

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
class Swedbank_Pay_Payment_Actions {
	/**
	 * @var Swedbank_Pay_Payment_Gateway_Checkout|WC_Payment_Gateway
	 */
	private $gateway;

	/**
	 * @param Swedbank_Pay_Payment_Gateway_Checkout|WC_Payment_Gateway $gateway
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Capture.
	 *
	 * @param WC_Order $order
	 *
	 * @return \WP_Error|array
	 */
	public function capture_payment( $order ) {
		$order_lines = swedbank_pay_get_order_lines( $order );

		$captured = $order->get_meta( '_payex_captured_items' );
		$captured = empty( $captured ) ? array() : (array) $captured;
		if ( count( $captured ) > 0 ) {
			// Remove captured items from order items list
			foreach ( $order_lines as $key => &$order_item ) {
				foreach ( $captured as &$captured_item ) {
					if ( $order_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] ===
						 $captured_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE]
					) {
						$unit_vat = $order_item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] / $order_item[Swedbank_Pay_Order_Item::FIELD_QTY]; //phpcs:ignore
						$order_item[Swedbank_Pay_Order_Item::FIELD_QTY] -= $captured_item[Swedbank_Pay_Order_Item::FIELD_QTY];
						$order_item[Swedbank_Pay_Order_Item::FIELD_AMOUNT] = $order_item[Swedbank_Pay_Order_Item::FIELD_QTY] * $order_item[Swedbank_Pay_Order_Item::FIELD_UNITPRICE]; //phpcs:ignore
						$order_item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] = $order_item[Swedbank_Pay_Order_Item::FIELD_QTY] * $unit_vat; //phpcs:ignore

						$captured_item[Swedbank_Pay_Order_Item::FIELD_QTY] += $order_item[Swedbank_Pay_Order_Item::FIELD_QTY];

						if ( 0 === $order_item[Swedbank_Pay_Order_Item::FIELD_QTY] ) {
							unset( $order_lines[$key] );
						}
					}
				}
			}
		}

		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		// @todo Log capture items $order_lines

		/** @var \WP_Error|array $result */
		$result = $this->gateway->api->capture_checkout( $order, $order_lines );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Append to exists list if applicable
		$current_items = $order->get_meta( '_payex_captured_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;

		foreach ( $order_lines as $captured_line ) {
			$is_found = false;
			foreach ( $current_items as &$current_item ) {
				if ( $current_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] === $captured_line[Swedbank_Pay_Order_Item::FIELD_REFERENCE] ) {
					// Update
					$current_item[Swedbank_Pay_Order_Item::FIELD_QTY] += $captured_line[Swedbank_Pay_Order_Item::FIELD_QTY];
					$is_found = true;

					break;
				}
			}

			if ( ! $is_found ) {
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_NAME] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_TYPE] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_CLASS] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_ITEM_URL] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_IMAGE_URL] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_DESCRIPTION] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_UNITPRICE] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_QTY_UNIT] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_AMOUNT] );
				unset( $captured_line[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] );

				$current_items[] = $captured_line;
			}
		}

		$order->update_meta_data( '_payex_captured_items', $current_items );
		$order->save_meta_data();

		return $result;
	}

	/**
	 * Cancel.
	 *
	 * @param WC_Order $order
	 *
	 * @return \WP_Error|array
	 */
	public function cancel_payment( $order ) {
		// @todo Add more cancellation logic
		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		/** @var \WP_Error|array $result */
		return $this->gateway->api->cancel_checkout( $order );
	}

	/**
	 * Perform Refund.
	 *
	 * @param \WC_Order $order
	 * @param $reason
	 *
	 * @return \WP_Error|array
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function refund_payment( $order, $reason ) {
		$args = get_transient( 'sb_refund_parameters_' . $order->get_id() );
		if ( empty( $args ) ) {
			$args = array();
		}

		// Remove transient if exists
		delete_transient( 'sb_refund_parameters_' . $order->get_id() );

		$lines = isset( $args['line_items'] ) ? $args['line_items'] : array();
		$items = array();

		// Order lines
		$line_items = $order->get_items( array( 'line_item', 'shipping', 'fee' ) );

		// Captured items
		$captured = $order->get_meta( '_payex_captured_items' );
		$captured = empty( $captured ) ? array() : (array) $captured;

		// Refunded items
		$refunded = $order->get_meta( '_payex_refunded_items' );
		$refunded = empty( $refunded ) ? array() : (array) $refunded;

		// Get captured items if applicable
		if ( 0 === count( $lines ) ) {
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

					$row_price          = $order->get_line_total( $item, false, false );
					$row_price_with_tax = $order->get_line_total( $item, true, false );
					$tax                = $row_price_with_tax - $row_price;

					if ( $reference === $captured_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] ) {
						$qty = $captured_item[Swedbank_Pay_Order_Item::FIELD_QTY];

						// Check refunded items
						foreach ( $refunded as $refunded_item ) {
							if ( $reference === $refunded_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] ) {
								$qty -= $refunded_item[Swedbank_Pay_Order_Item::FIELD_QTY];

								break;
							}
						}

						$lines[ $item_id ] = array(
							'qty'          => $qty,
							'refund_total' => $row_price,
							'refund_tax'   => array(
								$tax,
							),
						);

						break;
					}
				}
			}
		}

		// Get order lines
		if ( 0 === count( $lines ) ) {
			// @todo Use swedbank_pay_get_order_lines()
			$line_items = $order->get_items( array( 'line_item', 'shipping', 'fee', 'coupon' ) );
			foreach ( $line_items as $item_id => $item ) {
				switch ( $item->get_type() ) {
					case 'line_item':
						/** @var WC_Order_Item_Product $item */
						// Use subtotal to get amount without discounts
						$lines[ $item_id ] = array(
							'qty'          => $item->get_quantity(),
							'refund_total' => $item->get_subtotal(),
							'refund_tax'   => array(
								$item->get_subtotal_tax(),
							),
						);

						break;
					case 'fee':
					case 'shipping':
						/** @var WC_Order_Item_Fee|WC_Order_Item_Shipping $item */
						$lines[ $item_id ] = array(
							'qty'          => $item->get_quantity(),
							'refund_total' => $item->get_total(),
							'refund_tax'   => array(
								$item->get_total_tax(),
							),
						);

						break;
					case 'coupon':
						/** @var WC_Order_Item_Coupon $item */
						$lines[ $item_id ] = array(
							'qty'          => $item->get_quantity(),
							'refund_total' => -1 * $item->get_discount(),
							'refund_tax'   => array(
								-1 * $item->get_discount_tax(),
							),
						);

						break;
				}
			}
		}

		// Verify the captured
		$this->validate_items( $order, $lines );

		// Refund with specific items
		// Build order items list
		foreach ( $lines as $item_id => $line ) {
			/** @var WC_Order_Item $item */
			$item = $order->get_item( $item_id );
			if ( ! $item ) {
				return new \WP_Error( 'error', 'Unable to retrieve order item: ' . $item_id );
			}

			$product_name = trim( $item->get_name() );
			if ( empty( $product_name ) ) {
				$product_name = '-';
			}

			$qty = (int) $line['qty'];
			if ( $qty < 1 ) {
				$qty = 1;
			}

			$refund_total  = (float) $line['refund_total'];
			$refund_tax    = (float) array_shift( $line['refund_tax'] );
			$tax_percent   = ( $refund_total > 0 && $refund_tax > 0 ) ?
				round( 100 / ( $refund_total / $refund_tax ) ) : 0;

			if ( 'excl' === get_option( 'woocommerce_tax_display_shop' ) ) {
				$unit_price    = $qty > 0 ? ( ( $refund_total + $refund_tax ) / $qty ) : 0;
				$refund_amount = $refund_total + $refund_tax;
			} else {
				$unit_price    = $qty > 0 ? ( $refund_total / $qty ) : 0;
				$refund_amount = $refund_total + $refund_tax;
			}

			if ( empty( $refund_total ) ) {
				// Skip zero items
				continue;
			}

			$this->gateway->api->log(
				WC_Log_Levels::INFO,
				sprintf(
					'Refund item %s. qty: %s, total: %s. tax: %s. amount: %s',
					$item_id,
					$qty,
					$refund_total,
					$refund_tax,
					$refund_amount
				)
			);

			$order_item = array(
				Swedbank_Pay_Order_Item::FIELD_NAME        => $product_name,
				Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => $product_name,
				Swedbank_Pay_Order_Item::FIELD_UNITPRICE   => (int) bcmul( 100, $unit_price ),
				Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT => (int) bcmul( 100, $tax_percent ),
				Swedbank_Pay_Order_Item::FIELD_AMOUNT      => (int) bcmul( 100, $refund_amount ),
				Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT  => (int) bcmul( 100, $refund_tax ),
				Swedbank_Pay_Order_Item::FIELD_QTY         => $qty,
				Swedbank_Pay_Order_Item::FIELD_QTY_UNIT    => 'pcs',
			);

			switch ( $item->get_type() ) {
				case 'line_item':
					/** @var WC_Order_Item_Product $item */

					$image         = null;
					$product_class = 'ProductGroup1';

					/**
					 * @var WC_Product $product
					 */
					$product = $item->get_product();
					if ( $product ) {
						// Get Product Sku
						$reference = trim(
							str_replace(
								array( ' ', '.', ',' ),
								'-',
								$product->get_sku()
							)
						);

						// Get Product image
						$image = wp_get_attachment_image_src( $product->get_image_id(), 'full' );
						if ( $image ) {
							$image = array_shift( $image );
						}

						// Get Product Class
						$product_class = $product->get_meta( '_swedbank_pay_product_class' );

						if ( empty( $product_class ) ) {
							$product_class = apply_filters(
								'swedbank_pay_product_class',
								'ProductGroup1',
								$product
							);
						}
					}

					if ( empty( $reference ) ) {
						$reference = wp_generate_password( 12, false );
					}

					if ( empty( $image ) ) {
						$image = wc_placeholder_img_src( 'full' );
					}

					if ( null === parse_url( $image, PHP_URL_SCHEME ) &&
						 mb_substr( $image, 0, mb_strlen( WP_CONTENT_URL ), 'UTF-8' ) === WP_CONTENT_URL
					) {
						$image = wp_guess_url() . $image;
					}

					// The field Reference must match the regular expression '[\\w-]*'
					$order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] = $reference;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_TYPE ]      = Swedbank_Pay_Order_Item::TYPE_PRODUCT;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_CLASS ]     = $product_class;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_ITEM_URL ]  = $product->get_permalink();
					$order_item[ Swedbank_Pay_Order_Item::FIELD_IMAGE_URL ] = $image;

					break;
				case 'shipping':
					/** @var WC_Order_Item_Shipping $item */
					$order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] = 'shipping';
					$order_item[ Swedbank_Pay_Order_Item::FIELD_TYPE ]      = Swedbank_Pay_Order_Item::TYPE_SHIPPING;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_CLASS ]     = apply_filters(
						'swedbank_pay_product_class_shipping',
						'ProductGroup1',
						$order
					);

					break;
				case 'fee':
					/** @var WC_Order_Item_Fee $item */
					$order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] = 'fee';
					$order_item[ Swedbank_Pay_Order_Item::FIELD_TYPE ]      = Swedbank_Pay_Order_Item::TYPE_OTHER;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_CLASS ]     = apply_filters(
						'swedbank_pay_product_class_fee',
						'ProductGroup1',
						$order
					);

					break;
				case 'coupon':
					/** @var WC_Order_Item_Coupon $item */
					$order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] = 'coupon';
					$order_item[ Swedbank_Pay_Order_Item::FIELD_TYPE ]      = Swedbank_Pay_Order_Item::TYPE_OTHER;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_CLASS ]     = apply_filters(
						'swedbank_pay_product_class_coupon',
						'ProductGroup1',
						$order
					);

					break;
				default:
					/** @var WC_Order_Item $item */
					$order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] = 'other';
					$order_item[ Swedbank_Pay_Order_Item::FIELD_TYPE ]      = Swedbank_Pay_Order_Item::TYPE_OTHER;
					$order_item[ Swedbank_Pay_Order_Item::FIELD_CLASS ]     = apply_filters(
						'swedbank_pay_product_class_other',
						'ProductGroup1',
						$order
					);

					break;
			}

			$items[] = $order_item;
		}

		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		$result = $this->gateway->api->refund_checkout( $order, $items );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				'Refund has been failed. Error: ' . $result->get_error_message()
			);

			return $result;
		}

		$transaction_id = $result['number'];

		$order->add_order_note(
			sprintf(
			/* translators: 1: transaction 2: state 3: reason */                __(
				'Refund process has been executed from order admin. Transaction ID: %1$s. State: %2$s. Reason: %3$s', //phpcs:ignore
				'swedbank-pay-woocommerce-checkout' //phpcs:ignore
			), //phpcs:ignore
				$transaction_id,
				$result['state'],
				$reason
			)
		);

		// Add transaction id
		$refund_id = get_transient( 'sb_current_refund_id_' . $order->get_id() );
		if ( $refund_id && $transaction_id ) {
			// Save transaction id
			$refund = new WC_Order_Refund( $refund_id );
			if ( $refund->get_id() ) {
				$refund->update_meta_data( '_transaction_id', $transaction_id );
				$refund->save();
			}
		}

		$this->save_refunded_items( $order, $lines );

		// @todo Create Refund, and mark order items refunded.

		return $lines;
	}

	/**
	 * Validate order lines.
	 *
	 * @param WC_Order $order
	 * @param array $lines
	 *
	 * @return void
	 * @throws \Exception
	 */
	private function validate_items( WC_Order $order, array $lines ) {
		// @todo Add `_payex_refunded_items` validation
		$captured = $order->get_meta( '_payex_captured_items' );
		$captured = empty( $captured ) ? array() : (array) $captured;

		if ( count( $captured ) > 0 ) {
			foreach ( $lines as $item_id => $line ) {
				/** @var WC_Order_Item $item */
				$item = $order->get_item( $item_id );
				if ( ! $item ) {
					throw new \Exception( 'Unable to retrieve order item: ' . $item_id );
				}

				$qty = (int) $line['qty'];
				if ( $qty < 1 ) {
					continue;
				}

				// Skip zero products
				$price_with_tax = (float) $order->get_line_subtotal( $item, true, false );
				if ( $price_with_tax >= 0 && $price_with_tax <= 0.01 ) {
					continue;
				}

				switch ( $item->get_type() ) {
					case 'line_item':
						/** @var WC_Order_Item_Product $item */
						foreach ( $captured as $order_item ) {
							$sku = $item->get_product()->get_sku();
							if ($order_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] === $sku &&
								$qty > $order_item[Swedbank_Pay_Order_Item::FIELD_QTY]
							) {
								throw new \Exception(
									sprintf(
										'Product "%s" with quantity "%s" is not able to be captured.',
										$sku,
										$qty
									)
								);
							}
						}

						break;
					case 'shipping':
						/** @var WC_Order_Item_Shipping $item */
						$isCaptured = false;
						foreach ( $captured as $order_item ) {
							if ( $order_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] === 'shipping' ) {
								$isCaptured = true;
								break;
							}
						}

						if ( ! $isCaptured ) {
							throw new \Exception(
								sprintf(
									'Order item "%s" with quantity "%s" is not able to be captured.',
									$item->get_name(),
									$qty
								)
							);
						}

						break;
					case 'fee':
						/** @var WC_Order_Item_Fee $item */
						$isCaptured = false;
						foreach ( $captured as $order_item ) {
							if ( $order_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] === 'fee' ) {
								$isCaptured = true;
								break;
							}
						}

						if ( ! $isCaptured ) {
							throw new \Exception(
								sprintf(
									'Order item "%s" with quantity "%s" is not able to be captured.',
									$item->get_name(),
									$qty
								)
							);
						}

						break;
				}
			}
		}
	}

	private function save_refunded_items( WC_Order $order, array $lines ) {
		$order_lines = [];
		foreach ( $lines as $item_id => $line ) {
			$qty = (int) $line['qty'];
			if ( $qty < 1 ) {
				$qty = 1;
			}

			/** @var WC_Order_Item $item */
			$item = $order->get_item( $item_id );

			switch ( $item->get_type() ) {
				case 'line_item':
					$reference = $item->get_product()->get_sku();
					break;
				case 'shipping':
				case 'fee':
				case 'coupon':
					$reference = $item->get_type();
					break;
				default:
					$reference = 'other';
					break;
			}

			$order_lines[] = array(
				Swedbank_Pay_Order_Item::FIELD_REFERENCE => $reference,
				Swedbank_Pay_Order_Item::FIELD_QTY => $qty
			);
		}

		// Append to exists list if applicable
		$current_items = $order->get_meta( '_payex_refunded_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;
		if ( count( $current_items ) > 0 ) {
			foreach ( $current_items as &$current_item ) {
				foreach ( $order_lines as $order_line ) {
					if ( $order_line[Swedbank_Pay_Order_Item::FIELD_REFERENCE] === $current_item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] ) {
						$current_item[Swedbank_Pay_Order_Item::FIELD_QTY] += $order_line[Swedbank_Pay_Order_Item::FIELD_QTY];
						break;
					} else {
						$current_items[] = $order_line;
					}
				}
			}

			$order->update_meta_data( '_payex_refunded_items', $current_items );
			$order->save_meta_data();

			return;
		}

		$order->update_meta_data( '_payex_refunded_items', $order_lines );
		$order->save_meta_data();
	}
}
