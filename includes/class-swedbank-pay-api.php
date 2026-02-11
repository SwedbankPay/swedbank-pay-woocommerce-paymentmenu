<?php

namespace SwedbankPay\Checkout\WooCommerce;

use Krokedil\Swedbank\Pay\Helpers\Cart;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WC_Log_Levels;
use WC_Order;
use WC_Payment_Gateway;
use Swedbank_Pay_Payment_Gateway_Checkout;
use Krokedil\Swedbank\Pay\Helpers\Order;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Exception as ClientException;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCaptureV3;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCancelV3;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionReversalV3;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\TransactionObject;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Request\Purchase;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderObject;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Client;

use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Request\Verify;
/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Swedbank_Pay_Api {
	public const MODE_TEST = 'test';
	public const MODE_LIVE = 'live';

	public const INTENT_AUTOCAPTURE   = 'AutoCapture';
	public const INTENT_AUTHORIZATION = 'Authorization';
	public const INTENT_SALE          = 'Sale';

	public const OPERATION_PURCHASE = 'Purchase';

	public const TYPE_VERIFICATION  = 'Verification';
	public const TYPE_AUTHORIZATION = 'Authorization';
	public const TYPE_CAPTURE       = 'Capture';
	public const TYPE_SALE          = 'Sale';
	public const TYPE_CANCELLATION  = 'Cancellation';
	public const TYPE_REVERSAL      = 'Reversal';

	/**
	 * @var string
	 */
	private $access_token;

	/**
	 * @var string
	 */
	private $payee_id;

	/**
	 * @var string
	 */
	private $mode;

	private $payment_orders = array();

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
	 * Prepare item for API.
	 *
	 * Sanitize and apply necessary limitations for compatibility with the API.
	 *
	 * @param array $items The Swedbank Pay order items.
	 * @return array The prepared items.
	 */
	public static function prepare_for_api( $items ) {
		if ( isset( $item[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] ) ) {
			// limited to 40 characters in the API.
			$item[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] = mb_substr( trim( $items[ Swedbank_Pay_Order_Item::FIELD_DESCRIPTION ] ), 0, 40 );
		}

		return $items;
	}

	public function set_access_token( $access_token ) {
		$this->access_token = $access_token;

		return $this;
	}

	public function set_payee_id( $payee_id ) {
		$this->payee_id = $payee_id;

		return $this;
	}

	public function set_mode( $mode ) {
		$this->mode = $mode;

		return $this;
	}

	/**
	 * Extracts host URLs from an array of URLs.
	 *
	 * This method filters out invalid URLs and returns a unique list of host URLs.
	 *
	 * @param array $urls An array of URLs to extract host URLs from.
	 * @return array An array of unique host URLs.
	 */
	public static function get_host_urls( $urls ) {
		$result = array();
		foreach ( $urls as $url ) {
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$parsed   = wp_parse_url( $url );
				$result[] = sprintf( '%s://%s', $parsed['scheme'], $parsed['host'] );
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
		 * Get the Swedbank Pay client.
		 *
		 * This method creates a new Client instance, sets the access token, payee ID, mode (test or production),
		 * and user agent. It also applies a filter to allow modification of the client.
		 *
		 * @hook swedbank_pay_client
		 * @return Client
		 */
	public static function get_client() {
		$client = new Client();

		$user_agent = "{$client->getUserAgent()} swedbank-pay-payment-menu/" . SWEDBANK_PAY_VERSION;
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent .= ' ' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$settings = get_option( 'woocommerce_payex_checkout_settings' );
		$client->setAccessToken( $settings['access_token'] ?? '' )
				->setPayeeId( $settings['payee_id'] ?? '' )
				->setMode( wc_string_to_bool( $settings['testmode'] ?? 'no' ) ? Client::MODE_TEST : Client::MODE_PRODUCTION )
				->setUserAgent( $user_agent );

		return apply_filters( 'swedbank_pay_client', $client );
	}

	/**
	 * Create a Client for payment.
	 *
	 * @param WC_Order $order WC Order.
	 * @return WP_Error|ResponseServiceInterface
	 */
	public function initiate_purchase( WC_Order $order ) {
		$helper = new Order( $order );

		$payment_order        = $helper->get_payment_order();
		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $payment_order );

		$purchase_request = new Purchase( $payment_order_object );
		$purchase_request->setClient( self::get_client() );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $purchase_request->send();

			Swedbank_Pay()->logger()->debug( $purchase_request->getClient()->getDebugInfo() );

			return $response_service;
		} catch ( ClientException $e ) {

			Swedbank_Pay()->logger()->error( $purchase_request->getClient()->getDebugInfo() );
			Swedbank_Pay()->logger()->error( sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() ) );

			return Swedbank_Pay()->system_report()->request(
				new WP_Error(
					400,
					$this->format_error_message( $purchase_request->getClient()->getResponseBody(), $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Create a Client for payment.
	 *
	 * @return WP_Error|ResponseServiceInterface
	 */
	public function initiate_embedded_purchase() {
		$helper = new Cart();

		// Required for zero amount orders. Only checks for subscriptions.
		$is_verify = Swedbank_Pay_Subscription::cart_has_zero_order();

		$payment_order        = $helper->get_payment_order( $is_verify );
		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $payment_order );

		if ( $is_verify ) {
			$purchase_request = new Verify( $payment_order_object );
		} else {
			$purchase_request = new Purchase( $payment_order_object );
		}

		$purchase_request->setClient( self::get_client() );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $purchase_request->send();

			Swedbank_Pay()->logger()->debug( $purchase_request->getClient()->getDebugInfo() );

			return $response_service;
		} catch ( ClientException $e ) {

			Swedbank_Pay()->logger()->error( $purchase_request->getClient()->getDebugInfo() );
			Swedbank_Pay()->logger()->error( sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() ) );

			return Swedbank_Pay()->system_report()->request(
				new WP_Error(
					400,
					$this->format_error_message( $purchase_request->getClient()->getResponseBody(), $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Get a embedded payment.
	 *
	 * @return WP_Error|array
	 */
	public function get_embedded_purchase() {
		$view_session_url = WC()->session->get( 'swedbank_pay_view_session_url' );
		$result           = $this->request( 'GET', $view_session_url );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			/** @var \WP_Error $result */
			Swedbank_Pay()->logger()->debug(
				sprintf( '%s: API Exception: %s', __METHOD__, $result->get_error_message() )
			);

			return $result;
		}

		return $result;
	}

	/**
	 * Update a embedded payment.
	 *
	 * @return WP_Error|ResponseServiceInterface
	 */
	public function update_embedded_purchase() {
		$update_payment_url = WC()->session->get( 'swedbank_pay_update_order_url' );
		$helper             = new Cart();

		$payment_order        = $helper->get_update_payment_order();
		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $payment_order );

		$result = $this->request( 'PATCH', $update_payment_url, $payment_order_object );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			/** @var \WP_Error $result */
			Swedbank_Pay()->logger()->debug(
				sprintf( '%s: API Exception: %s', __METHOD__, $result->get_error_message() )
			);

			return $result;
		}

		return $result;
	}

	/**
	 * Do API Request
	 *
	 * @param       $method
	 * @param       $url
	 * @param array|string|object $params
	 *
	 * @return array|\WP_Error
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function request( $method, $url, $params = array() ) {
		// Get rid of full url. There's should be an endpoint only.
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$parsed = wp_parse_url( $url );
			$url    = $parsed['path'];
			if ( ! empty( $parsed['query'] ) ) {
				$url .= '?' . $parsed['query'];
			}
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'validation', 'Invalid url' );
		}

		// Process params.
		array_walk_recursive(
			$params,
			function ( &$input ) {
				if ( is_object( $input ) && method_exists( $input, 'toArray' ) ) {
					$input = $input->toArray();
				}
			}
		);

		$start = microtime( true );
		Swedbank_Pay()->logger()->debug(
			sprintf(
				'Request: %s %s %s',
				$method,
				$url,
				json_encode( $params, JSON_PRETTY_PRINT )
			)
		);

		try {
			/** @var \SwedbankPay\Api\Response $response */
			$client = self::get_client()->request( $method, $url, $params );

			// $codeClass = (int)($this->client->getResponseCode() / 100);
			$response_body = $client->getResponseBody();
			$result        = json_decode( $response_body, true );
			$time          = microtime( true ) - $start;
			Swedbank_Pay()->logger()->debug(
				sprintf( '[%.4F] Response: %s', $time, $response_body )
			);

			return $result;
		} catch ( \KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Exception $exception ) {
			$httpCode = (int) self::get_client()->getResponseCode();
			$time     = microtime( true ) - $start;
			Swedbank_Pay()->logger()->debug(
				sprintf(
					'[%.4F] Client Exception. Check debug info: %s',
					$time,
					self::get_client()->getDebugInfo()
				)
			);

			// https://tools.ietf.org/html/rfc7807
			$response_body = self::get_client()->getResponseBody() ?? '{}';
			$data          = json_decode( $response_body, true );
			if ( json_last_error() === JSON_ERROR_NONE &&
				isset( $data['title'] ) &&
				isset( $data['detail'] )
			) {
				// Format error message.
				$message = sprintf( '%s. %s', $data['title'], $data['detail'] );

				// Get details.
				if ( isset( $data['problems'] ) ) {
					$detailed = '';
					$problems = $data['problems'];
					foreach ( $problems as $problem ) {
						$detailed .= sprintf( '%s: %s', $problem['name'], $problem['description'] ) . "\r\n";
					}

					if ( ! empty( $detailed ) ) {
						$message .= "\r\n" . $detailed;
					}
				}

				return new \WP_Error( $httpCode, $message );
			}

			return new \WP_Error( 'api_generic', 'API Exception. Please check logs' );
		}
	}

	/**
	 * Check if payment is captured.
	 *
	 * @param string $payment_id_url
	 *
	 * @return bool
	 */
	public function is_captured( $payment_id_url ) {
		// Fetch transactions list
		$result           = $this->request( 'GET', $payment_id_url . '/financialtransactions' );
		$transactionsList = $result['financialTransactions']['financialTransactionsList'];

		// Check if have captured transactions
		foreach ( $transactionsList as $transaction ) {
			// TYPE_SALE is a so-called "1-phase" transaction, which means that the amount is captured immediately after the authorization.
			// Attempting to still capture these will result in an error from the API.
			if ( in_array( $transaction['type'], array( self::TYPE_CAPTURE, self::TYPE_SALE ) ) ) {
				// @todo Calculate captured amount
				return true;
			}
		}

		return false;
	}

	/**
	 * Finalize Payment Order.
	 *
	 * @param null $transaction_number
	 *
	 * @return true|WP_Error
	 */
	public function finalize_payment( WC_Order $order, $transaction_number = null ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		Swedbank_Pay()->logger()->debug(
			sprintf(
				'Finalize payment for Order #%s. Payment ID: %s. Transaction number: %s',
				$order->get_id(),
				$payment_order_id,
				$transaction_number
			)
		);
		if ( empty( $payment_order_id ) ) {
			return new WP_Error(
				'error',
				sprintf(
					'Payment Order ID is missing. Order ID: %s',
					$order->get_id()
				)
			);
		}

		$data = $this->request( 'GET', $payment_order_id . '/paid' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! $transaction_number ) {
			$transaction_number = $data['paid']['number'];
		}

		$result            = $this->request( 'GET', $payment_order_id . '/financialtransactions' );
		$transactions_list = $result['financialTransactions']['financialTransactionsList'] ?? array();
		// @todo Sort by "created" field using array_multisort
		foreach ( $transactions_list as $transaction ) {
			if ( $transaction_number === $transaction['number'] ) {
				Swedbank_Pay()->logger()->debug(
					sprintf(
						'Handle Transaction #%s for Order #%s.',
						$transaction_number,
						$order->get_id()
					)
				);

				return $this->process_transaction( $order, $transaction );
			}
		}

		// Some Authorize, Sale transaction are not in the list
		// Financial transaction list is empty, initiate workaround / failback.
		if ( 0 === count( $transactions_list ) ) {
			Swedbank_Pay()->logger()->debug(
				sprintf( 'Transaction List is empty. Run failback for Transaction #%s', $transaction_number )
			);

			$transaction = array(
				'id'             => $payment_order_id . '/financialtransactions/' . uniqid( 'fake' ),
				'created'        => date( 'Y-m-d H:i:s' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'updated'        => date( 'Y-m-d H:i:s' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'type'           => $data['paid']['transactionType'] ?? '',
				'number'         => $transaction_number,
				'amount'         => $data['paid']['amount'] ?? 0,
				'vatAmount'      => 0,
				'description'    => $data['paid']['id'],
				'payeeReference' => $data['paid']['payeeReference'] ?? '',
			);

			return $this->process_transaction( $order, $transaction );
		}

		return new WP_Error(
			'not_found',
			sprintf(
				'Transaction #%s not found in the transactions list.',
				$transaction_number
			)
		);
	}

	/**
	 * Process Transaction.
	 *
	 * @param WC_Order $order
	 * @param array    $transaction
	 *
	 * @return true|WP_Error
	 */
	public function process_transaction( WC_Order $order, array $transaction ) {
		$transaction_id = $transaction['number'];

		// Reload order meta to ensure we have the latest changes and avoid conflicts from parallel scripts.
		$order->read_meta_data();

		// Don't update order status if transaction ID was applied before.
		$transactions = $order->get_meta( '_swedbank_pay_transactions' );
		$transactions = empty( $transactions ) ? array() : (array) $transactions;

		// For free trial subscriptions orders, the list of transactions will always be empty. To allow still processing the transaction, we need to allow an empty list to continue.
		if ( ! empty( $transactions ) && in_array( $transaction_id, $transactions, true ) ) {
			Swedbank_Pay()->logger()->debug(
				sprintf( 'Skip transaction processing #%s. Order ID: %s', $transaction_id, $order->get_id() )
			);

			return true;
		}

		Swedbank_Pay()->logger()->debug(
			sprintf( 'Process transaction: %s', wp_json_encode( $transaction ) )
		);

		// Fetch payment info.
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Payment order ID is unknown.' );
		}

		$payment_order = $this->request( 'GET', $payment_order_id );
		$payment_order = $payment_order['paymentOrder'];

		// Apply action.
		switch ( $transaction['type'] ) {
			case self::TYPE_VERIFICATION: // This is always the case for free trial subscription orders.
			case self::TYPE_AUTHORIZATION:
			case self::TYPE_SALE:
				if ( empty( $order->get_date_paid() ) ) {
					// List of different messages based on the transaction type.
					$messages = array(
						// translators: 1: transaction ID.
						self::TYPE_SALE          => __( 'Payment has been completed. Transaction: %s', 'swedbank-pay-payment-menu' ),
						// translators: 1: transaction ID.
						self::TYPE_AUTHORIZATION => __( 'Payment has been authorized. Transaction: %s', 'swedbank-pay-payment-menu' ),
						// translators: 1: transaction ID.
						self::TYPE_VERIFICATION  => __( 'Payment has been verified. Transaction: %s', 'swedbank-pay-payment-menu' ),
					);
					$message  = sprintf( $messages[ $transaction['type'] ], $transaction_id );
					$order->payment_complete( $transaction_id );
					$order->add_order_note( $message );
				}
				break;
			case self::TYPE_CAPTURE:
				$is_full_capture = false;

				// Check if the payment was captured fully.
				// `remainingCaptureAmount` is missing if the payment was captured fully.
				if ( ! isset( $payment_order['remainingCaptureAmount'] ) ) {
					Swedbank_Pay()->logger()->debug(
						// translators: 1: payment order ID, 2: transaction ID, 3: transaction amount, 4: order amount.
						sprintf(
							'Warning: Payment Order ID: %s. Transaction %s. Transaction amount: %s. Order amount: %s. Field remainingCaptureAmount is missing. Full action?',
							$payment_order_id,
							$transaction_id,
							$transaction['amount'] / 100,
							$order->get_total()
						)
					);

					$is_full_capture = true;
				}

				$captured_amount = wc_price( $transaction['amount'] / 100, array( 'currency' => $order->get_currency() ) );

				// Add the order note for the capture based on if it was full or partial.
				if ( $is_full_capture ) {
					$message = sprintf(
						// translators: 1: transaction ID, 2: transaction amount.
						__( 'Payment has been fully captured. Transaction: %1$s. Amount: %2$s', 'swedbank-pay-payment-menu' ),
						$transaction_id,
						$captured_amount
					);
					$order->add_order_note( $message );
				} else {
					$remaining_amount = isset( $payment_order['remainingCaptureAmount'] ) ? $payment_order['remainingCaptureAmount'] / 100 : 0;
					$price            = wc_price( $remaining_amount, array( 'currency' => $order->get_currency() ) );
					$message          = sprintf(
						// translators: 1: transaction ID, 2: transaction amount, 3: remaining amount.
						__( 'Payment has been partially captured: Transaction: %1$s. Amount: %2$s. Remaining amount: %3$s', 'swedbank-pay-payment-menu' ),
						$transaction_id,
						$captured_amount,
						$price
					);
					$order->add_order_note( $message );
				}
				break;
			case self::TYPE_CANCELLATION:
				$this->update_order_status(
					$order,
					'cancelled',
					$transaction_id,
					// translators: 1: transaction ID.
					sprintf( __( 'Payment has been cancelled. Transaction: %s', 'swedbank-pay-payment-menu' ), $transaction_id )
				);

				break;
			case self::TYPE_REVERSAL:
				// Check if the payment was refunded fully
				// `remainingReversalAmount` is missing if the payment was refunded fully
				$is_full_refund = false;
				if ( ! isset( $payment_order['remainingReversalAmount'] ) ) {
					Swedbank_Pay()->logger()->debug(
						sprintf(
							'Warning: Payment Order ID: %s. Transaction %s. Transaction amount: %s. Order amount: %s. Field remainingReversalAmount is missing. Full action?', //phpcs:ignore
							$payment_order_id,
							$transaction_id,
							$transaction['amount'] / 100,
							$order->get_total()
						)
					);

					$is_full_refund = true;
				}

				// Update order status
				if ( $is_full_refund ) {
					remove_action(
						'woocommerce_order_status_changed',
						__CLASS__ . '::order_status_changed_transaction',
						0
					);

					// Prevent refund creation
					remove_action(
						'woocommerce_order_status_refunded',
						'wc_order_fully_refunded'
					);

					$message = sprintf(
						'Payment has been refunded. Transaction: %s. Amount: %s',
						$transaction_id,
						$transaction['amount'] / 100
					);

					$order->update_status(
						'refunded',
						$message
					);
				} else {
					$remaining_amount = isset( $payment_order['remainingReversalAmount'] )
						? $payment_order['remainingReversalAmount'] / 100 : 0;

					$message = sprintf(
						'Payment has been partially refunded: Transaction: %s. Amount: %s. Remaining amount: %s', //phpcs:ignore
						$transaction_id,
						$transaction['amount'] / 100,
						$remaining_amount
					);

					$order->add_order_note(
						$message
					);
				}

				// @todo Create Credit Memo
				// @todo Prent duplicated Credit Memo creation (by backend, by admin, by transaction callback)
				break;
			default:
				return new \WP_Error( sprintf( 'Error: Unknown type %s', $transaction['type'] ) );
		}

		// Save transaction ID
		$transactions[] = $transaction_id;
		$order->update_meta_data( '_swedbank_pay_transactions', $transactions );
		$order->save();

		Swedbank_Pay()->logger()->debug(
			sprintf( 'Transaction #%s has been processed.', $transaction['number'] )
		);

		return true;
	}

	/**
	 * Update Order Status.
	 *
	 * @param WC_Order    $order
	 * @param string      $status
	 * @param string|null $transaction_id
	 * @param string|null $message
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function update_order_status( WC_Order $order, $status, $transaction_id = null, $message = null ) {
		remove_action(
			'woocommerce_order_status_changed',
			Swedbank_Pay_Admin::class . '::order_status_changed_transaction',
			0
		);

		$order_id = $order->get_id();

		$this->log(
			WC_Log_Levels::INFO,
			sprintf(
				'Update order status #%s to %s. Transaction ID: %s',
				$order_id,
				$status,
				$transaction_id
			)
		);

		switch ( $status ) {
			case 'checkout-draft':
			case 'pending':
				// Set pending.
				if ( ! $order->has_status( 'pending' ) ) {
					$order->update_status( 'pending', $message );
				} elseif ( $message ) {
					$order->add_order_note( $message );
				}

				break;
			case 'on-hold':
				// Set on-hold.
				if ( ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', $message );
				} elseif ( $message ) {
					$order->add_order_note( $message );
				}

				$order->set_transaction_id( $transaction_id );

				break;
			case 'processing':
			case 'completed':
				if ( empty( $order->get_date_paid() ) ) {
					$order->payment_complete( $transaction_id );
					if ( $message ) {
						$order->add_order_note( $message );
					}
				} else {
					$order->update_status(
						apply_filters(
							'woocommerce_payment_complete_order_status', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
							$status,
							$order->get_id(),
							$order
						),
						$message
					);

					$order->set_transaction_id( $transaction_id );
				}

				break;
			case 'cancelled':
				// Set cancelled.
				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', $message );
				} elseif ( $message ) {
					$order->add_order_note( $message );
				}

				$order->set_transaction_id( $transaction_id );

				break;
			case 'refunded':
				$order->update_status( 'refunded', $message );
				$order->set_transaction_id( $transaction_id );

				break;
			case 'failed':
				if ( ! $order->is_paid() ) {
					$order->update_status( 'failed', $message );
				} elseif ( $message ) {
					$order->add_order_note( $message );
				}

				$order->set_transaction_id( $transaction_id );

				break;
			default:
				$order->update_status( $status, $message );
		}

		$order->save();
	}

	/**
	 * Can Capture.
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function can_capture( WC_Order $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return false;
		}

		if ( empty( $this->payment_orders[ $payment_order_id ] ) ) {
			$this->payment_orders[ $payment_order_id ] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[ $payment_order_id ] ) ) {
			return false;
		}

		return isset( $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingCaptureAmount'] )
				&& (float) $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingCaptureAmount'] > 0.1;
	}

	/**
	 * Can Cancel.
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function can_cancel( WC_Order $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return false;
		}

		if ( empty( $this->payment_orders[ $payment_order_id ] ) ) {
			$this->payment_orders[ $payment_order_id ] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[ $payment_order_id ] ) ) {
			return false;
		}

		return isset( $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingCancellationAmount'] )
				&& (float) $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingCancellationAmount'] > 0.1;
	}

	/**
	 * Can Refund.
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function can_refund( WC_Order $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return false;
		}

		if ( empty( $this->payment_orders[ $payment_order_id ] ) ) {
			$this->payment_orders[ $payment_order_id ] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[ $payment_order_id ] ) ) {
			return false;
		}

		return isset( $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingReversalAmount'] )
				&& (float) $this->payment_orders[ $payment_order_id ]['paymentOrder']['remainingReversalAmount'] > 0.1;
	}

	/**
	 * Capture Checkout.
	 *
	 * @param WC_Order $order
	 * @param array    $items
	 *
	 * @return \WP_Error|array
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.MissingImport)
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	public function capture_checkout( WC_Order $order, array $items = array() ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );

		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Unable to get the payment order ID' );
		}

		$helper           = new Order( $order, $items );
		$transaction_data = $helper->get_transaction_data()->setDescription( sprintf( 'Capture for Order #%s', $order->get_order_number() ) );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$request_service = ( new TransactionCaptureV3( $transaction ) )
			->setClient( self::get_client() )
			->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $request_service->send();

			Swedbank_Pay()->logger()->debug( $request_service->getClient()->getDebugInfo() );

			$result = $response_service->getResponseResource()->__toArray();

			// Save transaction.
			$transaction = $result['capture']['transaction'];

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch ( ClientException $e ) {
			Swedbank_Pay()->logger()->error( $request_service->getClient()->getDebugInfo() );

			Swedbank_Pay()->logger()->error(
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'capture',
				$this->format_error_message( $request_service->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Cancel the Swedbank payment.
	 *
	 * @param \WC_Order $order The order object.
	 * @return array|WP_Error
	 */
	public function cancel_checkout( WC_Order $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Unable to get the payment order ID' );
		}

		$helper = new Order( $order );

		$transaction_data =
		( $helper->get_transaction_data() )
			->setDescription( sprintf( 'Cancel Order #%s', $order->get_order_number() ) )
			->setPayeeReference(
				apply_filters(
					'swedbank_pay_payee_reference',
					swedbank_pay_generate_payee_reference( $order->get_id() )
				)
			);

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$request_service = ( new TransactionCancelV3( $transaction ) )
			->setClient( self::get_client() )
			->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $request_service->send();

			Swedbank_Pay()->logger()->debug( $request_service->getClient()->getDebugInfo() );

			$result = $response_service->getResponseResource()->__toArray();

			// Save transaction.
			$transaction = $result['cancellation']['transaction'];

			$this->process_transaction( $order, $transaction );

			return $result;
		} catch ( ClientException $e ) {
			Swedbank_Pay()->logger()->error( $request_service->getClient()->getDebugInfo() );

			Swedbank_Pay()->logger()->error(
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'cancel',
				$this->format_error_message( $request_service->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Refund amount for an order
	 *
	 * @param WC_Order $order The order object
	 * @param float    $amount The amount to refund
	 *
	 * @return TransactionObject|\WP_Error Returns the refunded transaction object or WP_Error on failure
	 * @throws ClientException
	 */
	public function refund_amount( WC_Order $order, $amount ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new WP_Error( 0, 'Unable to get the payment order ID' );
		}

		$helper           = new Order( $order );
		$transaction_data = $helper->get_transaction_data()
			->setAmount( round( $amount * 100 ) )
			->setVatAmount( 0 )
			->setDescription( sprintf( 'Refund Order #%s.', $order->get_order_number() ) );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$request_service = ( new TransactionReversalV3( $transaction ) )
			->setClient( self::get_client() )
			->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $request_service->send();

			Swedbank_Pay()->logger()->debug( $request_service->getClient()->getDebugInfo() );

			$result = $response_service->getResponseData();
			// FIXME: Remove this.
			$result = $response_service->getResponseResource()->__toArray();

			// Save transaction.
			$transaction = $result['reversal']['transaction'];

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch ( ClientException $e ) {
			Swedbank_Pay()->logger()->error( $request_service->getClient()->getDebugInfo() );

			Swedbank_Pay()->logger()->error(
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'refund',
				$this->format_error_message( $request_service->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Refund Checkout.
	 *
	 * @param \WC_Order_Refund $order
	 *
	 * @return \WP_Error|array
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.MissingImport)
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	public function refund_checkout( \WC_Order_Refund $refund_order ) {
		$order            = wc_get_order( $refund_order->get_parent_id() );
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new WP_Error( 0, 'Unable to get the payment order ID' );
		}
		$helper           = new Order( $refund_order );
		$transaction_data = $helper->get_transaction_data();
		$amount           = $transaction_data->getAmount();
		$transaction_data = $transaction_data
			->setDescription( sprintf( 'Refund Order #%s', $order->get_order_number() ) );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$request_service = ( new TransactionReversalV3( $transaction ) )
			->setClient( self::get_client() )
			->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $response_service */
			$response_service = $request_service->send();

			Swedbank_Pay()->logger()->debug( $request_service->getClient()->getDebugInfo() );

			$result = $response_service->getResponseData();
			$result = $response_service->getResponseResource()->__toArray();

			// Save transaction.
			$transaction = $result['reversal']['transaction'];

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch ( ClientException $e ) {
			Swedbank_Pay()->logger()->error( $request_service->getClient()->getDebugInfo() );

			Swedbank_Pay()->logger()->error(
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'refund',
				$this->format_error_message( $request_service->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Log a message.
	 *
	 * @param $level
	 * @param $message
	 * @param array $context
	 *
	 * @see WC_Log_Levels
	 */
	public function log( $level, $message, array $context = array() ) {
		$logger = wc_get_logger();

		if ( ! is_string( $message ) ) {
			$message = wp_json_encode( $message );
		}

		$logger->log(
			$level,
			sprintf(
				'[%s] %s [%s]',
				$level,
				$message,
				count( $context ) > 0 ? wp_json_encode( $context ) : ''
			),
			array_merge(
				$context,
				array(
					'source'  => 'payex_checkout',
					'_legacy' => true,
				)
			)
		);
	}

	/**
	 * Parse and format error response
	 *
	 * @param string $response_body
	 * @param string $err_msg
	 *
	 * @return string
	 */
	private function format_error_message( $response_body, $err_msg = '' ) {
		$response_body = json_decode( $response_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $response_body;
		}

		$message = isset( $response_body['detail'] ) ? $response_body['detail'] : '';
		if ( isset( $response_body['problems'] ) && count( $response_body['problems'] ) > 0 ) {
			foreach ( $response_body['problems'] as $problem ) {
				// Specify error message for invalid phone numbers. It's such fields like:
				// Payment.Cardholder.Msisdn
				// Payment.Cardholder.HomePhoneNumber
				// Payment.Cardholder.WorkPhoneNumber
				// Payment.Cardholder.BillingAddress.Msisdn
				// Payment.Cardholder.ShippingAddress.Msisdn
				if ( ( strpos( $problem['name'], 'Msisdn' ) !== false ) ||
					strpos( $problem['name'], 'HomePhoneNumber' ) !== false ||
					strpos( $problem['name'], 'WorkPhoneNumber' ) !== false
				) {
					$message = 'Your phone number format is wrong. Please input with country code, for example like this +46707777777'; //phpcs:ignore

					break;
				}

				if ( strpos( $problem['name'], 'StreetAddress' ) !== false ) {
					$message = 'Street address can have a max length of 40 and only contain normal characters';

					break;
				}

				$message .= "\n" . sprintf( '%s: %s', $problem['name'], $problem['description'] );
			}
		}

		if ( empty( $message ) ) {
			$message = ! empty( $err_msg ) ? $err_msg : __( 'Error', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		return $message;
	}
}
