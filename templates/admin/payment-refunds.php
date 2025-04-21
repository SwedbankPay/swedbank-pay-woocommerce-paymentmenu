<?php
/** @var Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<strong><?php _e( 'Refund Info', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
	<br />
	<strong><?php _e( 'Total refunded', 'swedbank-pay-woocommerce-checkout' ); ?>
		:</strong> <?php echo wc_price( $total_refunded, array( 'currency' => $order->get_currency()) ); ?>
	<br/>

	<?php if ( $can_refund ) : ?>
		<label for="swedbank_refund_amount">
			<?php _e( 'Total amount to refund:', 'swedbank-pay-woocommerce-checkout' ); ?>

			<input type="number"
				   name="swedbank_refund_amount"
				   id="swedbank_refund_amount"
				   min="0"
				   max="<?php echo esc_html( $available_for_refund ); ?>"
				   step="0.1"
				   value="<?php echo esc_html( $available_for_refund ); ?>"
			>
		</label>
		<br/>
		<label for="swedbank_refund_amount">
			<?php _e( 'Including VAT:', 'swedbank-pay-woocommerce-checkout' ); ?>

			<input type="number"
				   name="swedbank_refund_vat_amount"
				   id="swedbank_refund_vat_amount"
				   min="0"
				   step="0.1"
				   value="0"
			>
		</label>
		<br/>

		<button id="swedbank_pay_refund_partial"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Refund Payment', 'swedbank-pay-woocommerce-checkout' ); ?>
		</button>
	<?php endif; ?>
</div>
