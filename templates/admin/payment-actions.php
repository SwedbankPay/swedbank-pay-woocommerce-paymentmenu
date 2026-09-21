<?php
/** @var Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<?php $transaction_url = $gateway->get_transaction_url( $order ); ?>
	<?php if ( isset( $info['paid']['number'] ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Number', 'swedbank-pay-payment-menu' ); ?></strong>
			<?php echo wp_kses_post( wc_help_tip( esc_html__( 'The payment number assigned by Swedbank Pay. Use it when referring to the payment in the Merchant Portal or in support requests.', 'swedbank-pay-payment-menu' ) ) ); ?>
			<br/>
			<?php if ( $transaction_url ) : ?>
				<a href="<?php echo esc_url( $transaction_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					class="swedbank-pay-transaction-link">
					<?php echo esc_html( $info['paid']['number'] ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens the payment in the Merchant Portal in a new tab)', 'swedbank-pay-payment-menu' ); ?></span>
				</a>
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
			<?php else : ?>
				<?php echo esc_html( $info['paid']['number'] ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['instrument'] ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Instrument', 'swedbank-pay-payment-menu' ); ?></strong>
			<?php echo wp_kses_post( wc_help_tip( esc_html__( 'The payment instrument the customer paid with, for example Card, Swish or Invoice.', 'swedbank-pay-payment-menu' ) ) ); ?>
			<br/>
			<?php echo esc_html( $info['paid']['instrument'] ); ?>
		</p>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['transactionType'] ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Transaction type', 'swedbank-pay-payment-menu' ); ?></strong>
			<?php echo wp_kses_post( wc_help_tip( esc_html__( 'The type of transaction performed on the payment, for example Authorization or Sale.', 'swedbank-pay-payment-menu' ) ) ); ?>
			<br/>
			<?php echo esc_html( $info['paid']['transactionType'] ); ?>
		</p>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['payeeReference'] ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Payee Reference', 'swedbank-pay-payment-menu' ); ?></strong>
			<?php echo wp_kses_post( wc_help_tip( esc_html__( 'The unique reference sent to Swedbank Pay for this payment. It ties the order in the store to the payment at Swedbank Pay.', 'swedbank-pay-payment-menu' ) ) ); ?>
			<br/>
			<?php echo esc_html( $info['paid']['payeeReference'] ); ?>
		</p>
	<?php endif; ?>
	<?php
	$can_capture = $gateway->api->can_capture( $order );
	$can_cancel  = $gateway->api->can_cancel( $order );
	?>
	<?php if ( $can_capture || $can_cancel ) : ?>
		<details class="swedbank-pay-advanced">
			<summary class="swedbank-pay-advanced__summary">
				<?php esc_html_e( 'Advanced', 'swedbank-pay-payment-menu' ); ?>
			</summary>
			<div class="swedbank-pay-advanced__content">
				<?php if ( $can_capture ) : ?>
					<p class="swedbank-pay-advanced__action">
						<button id="swedbank_pay_capture"
								type="button" class="button"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
								data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
							<?php esc_html_e( 'Capture Payment', 'swedbank-pay-payment-menu' ); ?>
						</button>
					</p>
				<?php endif; ?>

				<?php if ( $can_cancel ) : ?>
					<p class="swedbank-pay-advanced__action">
						<button id="swedbank_pay_cancel"
								type="button" class="button"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'swedbank_pay' ) ); ?>"
								data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
							<?php esc_html_e( 'Cancel Payment', 'swedbank-pay-payment-menu' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>
		</details>
	<?php endif; ?>
</div>
