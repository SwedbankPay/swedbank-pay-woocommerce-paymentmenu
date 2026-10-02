<?php
/**
 * Class AsyncReversal
 *
 * Some payment methods (e.g. Banklink in the Baltics) answer a reversal with HTTP 202 and report
 * the outcome later. Confirmed reversals appear in `financialTransactions`, rejected ones in
 * `postpurchaseFailedAttempts`. Reversal specific on purpose: nothing else returns 202 today.
 */

namespace Krokedil\Swedbank\Pay;

use Krokedil\Swedbank\Pay\Utility\LogUtility;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers reversals awaiting confirmation and resolves them against the payment order.
 */
class AsyncReversal {
	use Traits\Singleton;

	public const PENDING_REVERSALS_META = '_swedbank_pay_pending_reversals';

	/**
	 * Action Scheduler hook for rechecking a reversal whose callback has not arrived.
	 */
	public const RECHECK_HOOK = 'swedbank_pay_recheck_pending_reversals';

	/**
	 * Clock skew allowance, in seconds, when matching a failed attempt to a pending entry.
	 */
	private const FAILED_ATTEMPT_TOLERANCE = 300;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( self::RECHECK_HOOK, array( $this, 'handle_recheck' ), 10, 2 );
		add_filter( 'woocommerce_order_fully_refunded_status', array( $this, 'fully_refunded_status' ), 10, 2 );
	}

	/**
	 * Hold a fully refunded order rather than marking it refunded before Swedbank Pay confirms.
	 *
	 * @param string $status The status WooCommerce is about to set.
	 * @param int    $order_id The order being refunded.
	 *
	 * @return string
	 */
	public function fully_refunded_status( $status, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->has_pending( $order ) ) {
			return $status;
		}

		// Refunded would claim the money is back before anyone knows that. Confirmation moves the
		// order on to refunded; rejection and giving up both leave it on hold with a reason.
		return 'on-hold';
	}

	/**
	 * Delays between rechecks, in seconds, spanning the three day worst case.
	 *
	 * @return int[]
	 */
	private function get_recheck_delays() {
		return array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 8 * HOUR_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS );
	}

	/**
	 * Schedule the next recheck of an order's pending reversals.
	 *
	 * @param \WC_Order $order The order.
	 * @param int       $attempt The recheck attempt to schedule, zero based.
	 *
	 * @return bool Whether a recheck was scheduled.
	 */
	private function schedule_recheck( \WC_Order $order, $attempt ) {
		$delays = $this->get_recheck_delays();
		if ( ! isset( $delays[ $attempt ] ) ) {
			return false;
		}

		$action_id = as_schedule_single_action(
			time() + $delays[ $attempt ],
			self::RECHECK_HOOK,
			array(
				'order_id' => $order->get_id(),
				'attempt'  => $attempt,
			)
		);

		// Action Scheduler returns 0 when it could not store the action.
		if ( 0 === $action_id ) {
			Swedbank_Pay()->logger()->error(
				"[ASYNC REVERSAL]: Failed to schedule recheck attempt {$attempt} for order #{$order->get_order_number()}.",
				array(
					'action'   => 'schedule_recheck',
					'order_id' => $order->get_id(),
					'attempt'  => $attempt,
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * Recheck an order's pending reversals when the callback has not arrived.
	 *
	 * @param int $order_id The order to recheck.
	 * @param int $attempt The recheck attempt that is running, zero based.
	 *
	 * @return void
	 */
	public function handle_recheck( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->has_pending( $order ) ) {
			return;
		}

		$result = $this->check_pending_reversals( $order );
		if ( is_wp_error( $result ) ) {
			Swedbank_Pay()->logger()->error(
				"[ASYNC REVERSAL]: Recheck failed for order #{$order->get_order_number()}: {$result->get_error_message()}",
				array(
					'action'   => 'recheck_pending_reversals',
					'order_id' => $order->get_id(),
					'attempt'  => $attempt,
					'error'    => $result->get_error_message(),
				)
			);
		}

		// Resolved, either just now or by a callback that arrived in the meantime.
		if ( ! $this->has_pending( $order ) ) {
			return;
		}

		if ( ! $this->schedule_recheck( $order, (int) $attempt + 1 ) ) {
			$this->abandon_pending( $order );
		}
	}

	/**
	 * Give up on reversals that Swedbank Pay never reported an outcome for.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return void
	 */
	private function abandon_pending( \WC_Order $order ) {
		Swedbank_Pay()->logger()->error(
			"[ASYNC REVERSAL]: Giving up on unconfirmed reversal(s) for order #{$order->get_order_number()}.",
			array(
				'action'           => 'abandon_pending_reversals',
				'order_id'         => $order->get_id(),
				'order_number'     => $order->get_order_number(),
				'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
				'pending'          => array_keys( $this->get_pending( $order ) ),
			)
		);

		// Drop the entries too, so the order is not blocked from further payment actions forever.
		$this->save_pending( $order, array() );

		// Swedbank Pay deliberately withholds the reversal operation while it considers the
		// reversal unanswered, to prevent a duplicate refund, so a retry cannot work until then.
		$this->set_on_hold(
			$order,
			__( 'Swedbank Pay never confirmed whether the refund succeeded. Swedbank Pay blocks further refunds on the payment order while a reversal is unresolved, so the refund cannot be retried from WooCommerce. Please verify the refund with Swedbank Pay before taking any further action on this order.', 'swedbank-pay-payment-menu' )
		);
	}

	/**
	 * Register a reversal that was accepted (HTTP 202) but not yet confirmed.
	 *
	 * @param \WC_Order $order The order being refunded.
	 * @param object    $transaction_data The TransactionData object sent in the reversal request.
	 *
	 * @return array Synthetic transaction array so callers treat the refund as submitted.
	 */
	public function init_pending( \WC_Order $order, $transaction_data ) {
		$payee_reference = $transaction_data->getPayeeReference();
		$amount          = $transaction_data->getAmount();
		$created         = gmdate( 'c' );

		// Reload to avoid clobbering meta written while the reversal request was in flight.
		$order->read_meta_data();
		$pending                     = $this->get_pending( $order );
		$pending[ $payee_reference ] = array(
			'amount'  => $amount,
			'created' => $created,
		);
		$this->save_pending( $order, $pending );

		$order->add_order_note(
			sprintf(
				// translators: %s: refund amount.
				__( 'The refund of %s has been sent to Swedbank Pay and is awaiting confirmation.', 'swedbank-pay-payment-menu' ),
				wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) )
			)
		);

		// Safety net if the callback never arrives; non-fatal, schedule_recheck() logs a failure.
		$this->schedule_recheck( $order, 0 );

		Swedbank_Pay()->logger()->info(
			"[ASYNC REVERSAL]: Reversal accepted asynchronously (HTTP 202) for order #{$order->get_order_number()}. Awaiting confirmation via callback.",
			array(
				'action'           => 'init_pending_reversal',
				'order_id'         => $order->get_id(),
				'order_number'     => $order->get_order_number(),
				'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
				'payee_reference'  => $payee_reference,
				'amount'           => $amount,
			)
		);

		// `number` is empty because no transaction exists yet; the 202 branches return this
		// straight to the caller so it never reaches process_transaction().
		return array(
			'number'         => '',
			'type'           => OrderManagement::TYPE_REVERSAL,
			'state'          => 'Pending',
			'amount'         => $amount,
			'created'        => $created,
			'updated'        => $created,
			'description'    => sprintf( 'Refund Order #%s', $order->get_order_number() ),
			'payeeReference' => $payee_reference,
			'pending'        => true,
		);
	}

	/**
	 * Whether the order has reversals awaiting confirmation.
	 *
	 * @param \WC_Order $order The order to check.
	 *
	 * @return bool
	 */
	public function has_pending( \WC_Order $order ) {
		return ! empty( $this->get_pending( $order ) );
	}

	/**
	 * Resolve pending reversals against the payment order.
	 *
	 * Confirmed reversals are processed through the regular transaction handling; rejected ones
	 * put the order on hold. Entries appearing in neither list are left pending.
	 *
	 * @param \WC_Order $order The order to check.
	 *
	 * @return true|\WP_Error True when nothing is left to do, including nothing pending.
	 */
	public function check_pending_reversals( \WC_Order $order ) {
		$pending = $this->get_pending( $order );
		if ( empty( $pending ) ) {
			return true;
		}

		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Payment order ID is unknown.' );
		}

		$api = $this->get_api( $order );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$context = array(
			'action'           => 'check_pending_reversals',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'payment_order_id' => $payment_order_id,
			'pending'          => array_keys( $pending ),
		);

		LogUtility::$title = "[ASYNC REVERSAL]: Retrieve financial transactions for order #{$order->get_order_number()}";
		$result            = $api->request( 'GET', "{$payment_order_id}/financialtransactions" );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Unhook so moving the order to refunded does not trigger a second reversal. Only restore
		// it if it was hooked, since it is not registered in every context.
		$status_hook     = Swedbank_Pay_Admin::class . '::order_status_changed_transaction';
		$status_priority = has_action( 'woocommerce_order_status_changed', $status_hook );
		if ( false !== $status_priority ) {
			remove_action( 'woocommerce_order_status_changed', $status_hook, $status_priority );
		}

		$confirmed = false;

		// finally: leaving the handler unhooked because saving the order threw would silently
		// disable order management for the rest of the request.
		try {
			foreach ( $result['financialTransactions']['financialTransactionsList'] ?? array() as $transaction ) {
				if ( OrderManagement::TYPE_REVERSAL !== ( $transaction['type'] ?? '' ) ) {
					continue;
				}

				$payee_reference = $transaction['payeeReference'] ?? '';
				if ( ! isset( $pending[ $payee_reference ] ) ) {
					continue;
				}

				// process_transaction() adds the refund order note and moves the order status.
				$process_result = $api->process_transaction( $order, $transaction );
				if ( is_wp_error( $process_result ) ) {
					Swedbank_Pay()->logger()->error( "[ASYNC REVERSAL]: Failed to process confirmed reversal #{$transaction['number']} for order #{$order->get_order_number()}: {$process_result->get_error_message()}", $context );
					continue;
				}

				unset( $pending[ $payee_reference ] );
				$confirmed = true;

				Swedbank_Pay()->logger()->info( "[ASYNC REVERSAL]: Reversal confirmed for order #{$order->get_order_number()}. Transaction: #{$transaction['number']}.", $context );
			}
		} finally {
			if ( false !== $status_priority ) {
				add_action( 'woocommerce_order_status_changed', $status_hook, $status_priority, 3 );
			}
		}

		if ( $confirmed ) {
			$this->save_pending( $order, $pending );
		}

		if ( empty( $pending ) ) {
			return true;
		}

		$failed_attempts = $this->get_failed_attempts( $api, $order, $payment_order_id );
		if ( is_wp_error( $failed_attempts ) ) {
			return $failed_attempts;
		}

		foreach ( $failed_attempts as $attempt ) {
			$type = $attempt['transactionType'] ?? $attempt['type'] ?? '';
			if ( OrderManagement::TYPE_REVERSAL !== $type ) {
				continue;
			}

			// A failed attempt carries no payeeReference, so infer which entry it belongs to.
			$payee_reference = $this->match_pending_to_attempt( $pending, $attempt );
			if ( null === $payee_reference ) {
				continue;
			}

			$amount = $pending[ $payee_reference ]['amount'] ?? 0;
			unset( $pending[ $payee_reference ] );

			// Save before setting the status, so the on-hold transition does not persist a stale entry.
			$this->save_pending( $order, $pending );
			$this->handle_failed_reversal( $order, $amount, $attempt );
		}

		if ( ! empty( $pending ) ) {
			Swedbank_Pay()->logger()->debug( '[ASYNC REVERSAL]: Reversal(s) still awaiting confirmation: ' . implode( ', ', array_keys( $pending ) ), $context );
		}

		return true;
	}

	/**
	 * Retrieve the post purchase failed attempts for a payment order.
	 *
	 * Note the lowercase p in "purchase"; the SDK's link stub docblock spells it differently to
	 * the API.
	 *
	 * @param \SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api $api The API instance.
	 * @param \WC_Order                                          $order The order, used for logging.
	 * @param string                                             $payment_order_id The payment order ID.
	 *
	 * @return array|\WP_Error The failed attempts, or WP_Error on a failed request or unknown shape.
	 */
	private function get_failed_attempts( $api, \WC_Order $order, $payment_order_id ) {
		LogUtility::$title = "[ASYNC REVERSAL]: Retrieve postpurchasefailedattempts for order #{$order->get_order_number()}";
		$result            = $api->request( 'GET', "{$payment_order_id}/postpurchasefailedattempts" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Erroring keeps the reversal pending; reading an unknown shape as "no failures" would lose it.
		if ( ! isset( $result['postpurchaseFailedAttempts']['postpurchaseFailedAttemptList'] ) ) {
			return new \WP_Error(
				'unexpected_response',
				'The postpurchasefailedattempts response did not contain postpurchaseFailedAttempts.postpurchaseFailedAttemptList.'
			);
		}

		$list = $result['postpurchaseFailedAttempts']['postpurchaseFailedAttemptList'];

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Find the pending entry a failed attempt belongs to.
	 *
	 * @param array $pending The pending entries, keyed by payeeReference.
	 * @param array $attempt The failed attempt from Swedbank Pay.
	 *
	 * @return string|null The payeeReference of the matching entry, or null when none matches.
	 */
	private function match_pending_to_attempt( array $pending, array $attempt ) {
		$attempt_created = strtotime( $attempt['created'] ?? '' );

		foreach ( $pending as $payee_reference => $entry ) {
			$entry_created = strtotime( $entry['created'] ?? '' );

			// Ignore attempts predating the reversal; they belong to an earlier refund.
			if ( false !== $attempt_created && false !== $entry_created
				&& $attempt_created < $entry_created - self::FAILED_ATTEMPT_TOLERANCE
			) {
				continue;
			}

			return (string) $payee_reference;
		}

		return null;
	}

	/**
	 * Handle a reversal that Swedbank Pay reported as failed.
	 *
	 * @param \WC_Order $order The order.
	 * @param int       $amount The reversal amount, in minor units.
	 * @param array     $attempt The failed attempt data from Swedbank Pay.
	 *
	 * @return void
	 */
	private function handle_failed_reversal( \WC_Order $order, $amount, array $attempt ) {
		Swedbank_Pay()->logger()->error(
			"[ASYNC REVERSAL]: Reversal failed for order #{$order->get_order_number()}. Failed attempt: " . wp_json_encode( $attempt ),
			array(
				'action'           => 'handle_failed_reversal',
				'order_id'         => $order->get_id(),
				'order_number'     => $order->get_order_number(),
				'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
				'amount'           => $amount,
				'failed_attempt'   => $attempt,
			)
		);

		$message = sprintf(
			// translators: %s: refund amount.
			__( 'The refund of %s could not be completed by Swedbank Pay. No money has been returned to the customer. The order has been set to on hold so you can review it and try the refund again.', 'swedbank-pay-payment-menu' ),
			wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) )
		);

		// Sanitised: remote input rendered into an order note.
		$reason = isset( $attempt['problem']['detail'] ) ? sanitize_text_field( $attempt['problem']['detail'] ) : '';
		if ( ! empty( $reason ) ) {
			$message .= ' ' . sprintf(
				// translators: %s: the reason Swedbank Pay gave for rejecting the refund.
				__( 'Reason given by Swedbank Pay: %s', 'swedbank-pay-payment-menu' ),
				$reason
			);
		}

		$this->set_on_hold( $order, $message );
	}

	/**
	 * Get the API instance for an order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return \SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api|\WP_Error
	 */
	private function get_api( \WC_Order $order ) {
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway || ! property_exists( $gateway, 'api' ) ) {
			return new \WP_Error( 'missing_gateway', 'Unable to retrieve the payment gateway instance.' );
		}

		return $gateway->api;
	}

	/**
	 * Put the order on hold with a note.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $message The note to add.
	 *
	 * @return void
	 */
	private function set_on_hold( \WC_Order $order, $message ) {
		$api = $this->get_api( $order );
		if ( is_wp_error( $api ) ) {
			$order->update_status( 'on-hold', $message );
			return;
		}

		$api->update_order_status( $order, 'on-hold', $order->get_transaction_id(), $message );
	}

	/**
	 * Get the pending reversal entries for an order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array
	 */
	private function get_pending( \WC_Order $order ) {
		$pending = $order->get_meta( self::PENDING_REVERSALS_META );

		return empty( $pending ) ? array() : (array) $pending;
	}

	/**
	 * Persist the pending reversal entries, removing the meta when empty.
	 *
	 * @param \WC_Order $order The order.
	 * @param array     $pending The pending entries to save.
	 *
	 * @return void
	 */
	private function save_pending( \WC_Order $order, array $pending ) {
		if ( empty( $pending ) ) {
			$order->delete_meta_data( self::PENDING_REVERSALS_META );
		} else {
			$order->update_meta_data( self::PENDING_REVERSALS_META, $pending );
		}

		$order->save_meta_data();
	}
}
