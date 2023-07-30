<?php
/** @var Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */
/** @var int $order_id */
/** @var string $payment_id */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<strong><?php _e( 'Payment Info', 'swedbank-pay-woocommerce-checkout' ); ?></strong>
	<br />
	<?php if ( isset( $info['paid']['number'] ) ) : ?>
		<strong><?php _e( 'Number', 'swedbank-pay-woocommerce-checkout' ); ?>
			:</strong> <?php echo esc_html( $info['paid']['number'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['instrument'] ) ) : ?>
		<strong><?php _e( 'Instrument', 'swedbank-pay-woocommerce-checkout' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['instrument'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['transactionType'] ) ) : ?>
		<strong><?php _e( 'Transaction type', 'swedbank-pay-woocommerce-checkout' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['transactionType'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( $gateway->core->canCapture( $order->get_id() ) ) : ?>
		<button id="swedbank_pay_capture"
		        data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
		        data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Capture Payment', 'swedbank-pay-woocommerce-checkout' ); ?>
		</button>
	<?php endif; ?>

	<?php if ( $gateway->core->canCancel( $order->get_id() ) ) : ?>
		<button id="swedbank_pay_cancel"
		        data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
		        data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Cancel Payment', 'swedbank-pay-woocommerce-checkout' ); ?>
		</button>
	<?php endif; ?>

	<?php if ( $gateway->core->canRefund( $order->get_id() ) ) : ?>
		<button id="swedbank_pay_refund"
		        data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
		        data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php _e( 'Full refund', 'swedbank-pay-woocommerce-checkout' ); ?>
		</button>
	<?php endif; ?>
</div>
