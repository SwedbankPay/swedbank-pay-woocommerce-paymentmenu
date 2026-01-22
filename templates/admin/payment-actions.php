<?php
/** @var Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<strong><?php esc_html_e( 'Payment Info', 'swedbank-pay-payment-menu' ); ?></strong>
	<br />
	<?php if ( isset( $info['paid']['number'] ) ) : ?>
		<strong><?php esc_html_e( 'Number', 'swedbank-pay-payment-menu' ); ?>
			:</strong> <?php echo esc_html( $info['paid']['number'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['instrument'] ) ) : ?>
		<strong><?php esc_html_e( 'Instrument', 'swedbank-pay-payment-menu' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['instrument'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['transactionType'] ) ) : ?>
		<strong><?php esc_html_e( 'Transaction type', 'swedbank-pay-payment-menu' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['transactionType'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( $gateway->api->can_capture( $order ) ) : ?>
		<button id="swedbank_pay_capture"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php esc_html_e( 'Capture Payment', 'swedbank-pay-payment-menu' ); ?>
		</button>
	<?php endif; ?>

	<?php if ( $gateway->api->can_cancel( $order ) ) : ?>
		<button id="swedbank_pay_cancel"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
				data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
			<?php esc_html_e( 'Cancel Payment', 'swedbank-pay-payment-menu' ); ?>
		</button>
	<?php endif; ?>
</div>
