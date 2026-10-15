<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Data\ResponseInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Class for processing the overlay checkout flow.
 *
 * The payment order is created from the WooCommerce order like the redirect flow, but the payment menu is opened in an overlay on the checkout page. On mobile devices the redirect flow is used.
 */
class Overlay extends Redirect {
	/**
	 * Enqueue the overlay assets when rendering the checkout page.
	 *
	 * @return void
	 */
	protected function init() {
		if ( null !== $this->order ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'swedbank-pay-overlay-checkout',
			SWEDBANK_PAY_PLUGIN_URL . '/assets/css/overlay-checkout.css',
			array(),
			SWEDBANK_PAY_VERSION
		);

		wp_register_script(
			'swedbank-pay-overlay-checkout',
			SWEDBANK_PAY_PLUGIN_URL . "/assets/js/overlay-checkout{$suffix}.js",
			array( 'jquery' ),
			SWEDBANK_PAY_VERSION,
			true
		);
		wp_localize_script(
			'swedbank-pay-overlay-checkout',
			'swedbank_pay_overlay_params',
			array(
				'culture'      => $this->gateway->culture,
				'close_label'  => __( 'Close', 'swedbank-pay-payment-menu' ),
				'dialog_label' => __( 'Swedbank Pay payment', 'swedbank-pay-payment-menu' ),
			)
		);
		wp_enqueue_script( 'swedbank-pay-overlay-checkout' );
	}

	/**
	 * Add the data needed to open the payment menu in the overlay.
	 *
	 * @param \WC_Order         $order The WooCommerce order.
	 * @param ResponseInterface $result The response from initiating the payment order.
	 *
	 * @return array{redirect: array|bool|string, result: string, swedbank_pay_view_checkout?: string, redirect_on_paid?: string}
	 */
	protected function get_process_result( $order, $result ) {
		$process_result = parent::get_process_result( $order, $result );

		// On mobile, the overlay is the same as the redirect flow.
		if ( wp_is_mobile() ) {
			return $process_result;
		}

		$process_result['swedbank_pay_view_checkout'] = $result->getOperationByRel( 'view-checkout', 'href' );
		$process_result['redirect_on_paid']           = $this->gateway->get_return_url( $order );

		return $process_result;
	}
}
