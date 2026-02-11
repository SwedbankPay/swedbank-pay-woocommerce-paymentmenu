<?php
/** @var \Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>

<?php if ( $gateway->api->can_capture( $order ) ) : ?>
	<button id="swedbank_pay_capture"
			type="button" class="button button-primary"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
			data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
		<?php esc_html_e( 'Capture Payment', 'swedbank-pay-payment-menu' ); ?>
	</button>
<?php endif; ?>

<?php if ( $gateway->api->can_cancel( $order ) ) : ?>
	<button id="swedbank_pay_cancel"
			type="button" class="button button-primary"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
			data-order-id="<?php echo esc_html( $order->get_id() ); ?>">
		<?php esc_html_e( 'Cancel Payment', 'swedbank-pay-payment-menu' ); ?>
	</button>
<?php endif; ?>
