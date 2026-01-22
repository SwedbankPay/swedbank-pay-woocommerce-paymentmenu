<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;


use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;
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
	 * @param WC_Order $order The WC order.
	 *
	 * @return \WP_Error|array
	 */
	public function capture_payment( $order ) {
		$order_lines = swedbank_pay_get_order_lines( $order );

		$captured = $order->get_meta( '_payex_captured_items' );
		$captured = empty( $captured ) ? array() : (array) $captured;
		if ( count( $captured ) > 0 ) {
			// Remove captured items from order items list.
			foreach ( $order_lines as $key => &$order_item ) {
				foreach ( $captured as &$captured_item ) {
					if ( $order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ===
						$captured_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ]
					) {
						$unit_vat = $order_item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] / $order_item[Swedbank_Pay_Order_Item::FIELD_QTY]; //phpcs:ignore
						$order_item[ Swedbank_Pay_Order_Item::FIELD_QTY ]     -= $captured_item[ Swedbank_Pay_Order_Item::FIELD_QTY ];
						$order_item[Swedbank_Pay_Order_Item::FIELD_AMOUNT] = $order_item[Swedbank_Pay_Order_Item::FIELD_QTY] * $order_item[Swedbank_Pay_Order_Item::FIELD_UNITPRICE]; //phpcs:ignore
						$order_item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] = $order_item[Swedbank_Pay_Order_Item::FIELD_QTY] * $unit_vat; //phpcs:ignore

						$captured_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] += $order_item[ Swedbank_Pay_Order_Item::FIELD_QTY ];

						if ( 0 === $order_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] ) {
							unset( $order_lines[ $key ] );
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

		// @todo Log capture items $order_lines.

		/** @var \WP_Error|array $result */
		$result = $this->gateway->api->capture_checkout( $order, $order_lines );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			return $result;
		}

		// Append to exists list if applicable.
		$current_items = $order->get_meta( '_payex_captured_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;

		foreach ( $order_lines as $captured_line ) {
			$is_found = false;
			foreach ( $current_items as &$current_item ) {
				if ( $current_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === $captured_line[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ) {
					// Update
					$current_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] += $captured_line[ Swedbank_Pay_Order_Item::FIELD_QTY ];
					$is_found = true;

					break;
				}
			}

			if ( ! $is_found ) {
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_NAME ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_TYPE ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_CLASS ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_ITEM_URL ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_IMAGE_URL ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_UNITPRICE ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_QTY_UNIT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_AMOUNT ] );
				unset( $captured_line[ Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT ] );

				$current_items[] = $captured_line;
			}
		}

		$order->update_meta_data( '_payex_captured_items', $current_items );
		$order->add_order_note( __( 'Order captured through metabox action.', 'swedbank-pay-payment-menu' ) );
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
	 * Refund payment amount.
	 *
	 * @param WC_Order $order The order object.
	 * @param float    $amount The amount to refund.
	 *
	 * @return \WP_Error|array The refund result.
	 */
	public function refund_payment_amount( $order, $amount ) {
		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		$result = $this->gateway->api->refund_amount( $order, $amount );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			$order->add_order_note(
				'Refund has been failed. Error: ' . $result->get_error_message()
			);
		}

		return $result;
	}

	/**
	 * Perform Refund.
	 *
	 * @param \WC_Order $order
	 * @param array     $lines
	 * @param array     $items
	 * @param $reason
	 *
	 * @return \WP_Error|array
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function refund_payment( $order, $lines, $reason, $create_credit_memo ) {
		// Verify the captured
		$this->validate_items( $order, $lines );

		// Filter items
		foreach ( $lines as $item_id => $line ) {
			$qty          = (int) $line['qty'];
			$refund_total = (float) $line['refund_total'];
			if ( $qty === 0 || $refund_total <= 0.01 ) {
				unset( $lines[ $item_id ] );
			}
		}

		// Refund with specific items
		// Build order items list
		$items = array();
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

			$refund_tax = 0;
			foreach ( $line['refund_tax'] as $tax_id => $refund_tax_value ) {
				$refund_tax += (float) $refund_tax_value;
			}

			$refund_total = (float) $line['refund_total'];
			$tax_percent  = ( $refund_total > 0 && $refund_tax > 0 ) ?
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
				Swedbank_Pay_Order_Item::FIELD_DESCRIPTION => mb_substr( trim( $product_name ), 0, 40 ),
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

					if ( null === wp_parse_url( $image, PHP_URL_SCHEME ) &&
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

		$refund_order = $order->get_refunds();
		$refund_order = reset( $refund_order );
		$result       = $this->gateway->api->refund_checkout( $refund_order );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
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
				'swedbank-pay-payment-menu' //phpcs:ignore
			), //phpcs:ignore
				$transaction_id,
				$result['state'],
				empty( $reason ) ? '-' : $reason
			)
		);

		$this->save_refunded_items( $order, $lines );

		// Create Credit Memo
		if ( $create_credit_memo ) {
			$amount = 0;
			foreach ( $items as $item ) {
				$amount += ( $item[ Swedbank_Pay_Order_Item::FIELD_AMOUNT ] / 100 );
			}

			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => $reason,
					'line_items'     => $lines,
					'refund_payment' => false,
					'restock_items'  => true,
				)
			);
			if ( is_wp_error( $refund ) ) {
				$order->add_order_note(
					sprintf(
						'Refund could not be created. Error: %s',
						join( '; ', $refund->get_error_messages() )
					)
				);
			}
		}

		return null;
	}

	/**
	 * Validate order lines.
	 *
	 * @param WC_Order $order
	 * @param array    $lines
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
					throw new \Exception( 'Unable to retrieve order item: ' . absint( $item_id ) );
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
							if ( $order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === $sku &&
								$qty > $order_item[ Swedbank_Pay_Order_Item::FIELD_QTY ]
							) {
								throw new \Exception(
									esc_html(
										sprintf(
											'Product "%s" with quantity "%s" is not able to be captured.',
											$sku,
											$qty
										)
									)
								);
							}
						}

						break;
					case 'shipping':
						/** @var WC_Order_Item_Shipping $item */
						$isCaptured = false;
						foreach ( $captured as $order_item ) {
							if ( $order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === 'shipping' ) {
								$isCaptured = true;
								break;
							}
						}

						if ( ! $isCaptured ) {
							throw new \Exception(
								esc_html(
									sprintf(
										'Order item "%s" with quantity "%s" is not able to be captured.',
										$item->get_name(),
										$qty
									)
								)
							);
						}

						break;
					case 'fee':
						/** @var WC_Order_Item_Fee $item */
						$isCaptured = false;
						foreach ( $captured as $order_item ) {
							if ( $order_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === 'fee' ) {
								$isCaptured = true;
								break;
							}
						}

						if ( ! $isCaptured ) {
							throw new \Exception(
								esc_html(
									sprintf(
										'Order item "%s" with quantity "%s" is not able to be captured.',
										$item->get_name(),
										$qty
									)
								)
							);
						}

						break;
				}
			}
		}
	}

	private function save_refunded_items( WC_Order $order, array $lines ) {
		$order_lines = array();
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
				Swedbank_Pay_Order_Item::FIELD_QTY       => $qty,
			);
		}

		// Append to exists list if applicable
		$current_items = $order->get_meta( '_payex_refunded_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;
		if ( count( $current_items ) > 0 ) {
			foreach ( $current_items as &$current_item ) {
				foreach ( $order_lines as $order_line ) {
					if ( $order_line[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] === $current_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ) {
						$current_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] += $order_line[ Swedbank_Pay_Order_Item::FIELD_QTY ];
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
