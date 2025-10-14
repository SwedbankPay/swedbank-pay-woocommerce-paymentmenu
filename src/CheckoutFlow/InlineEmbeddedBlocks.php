<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

/**
 * Class for processing the inline embedded checkout flow on the blocks checkout page.
 */
class InlineEmbeddedBlocks extends CheckoutFlow {
	/**
	 * Process the payment for the WooCommerce order.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	public function process( $order ) {

	}
}
