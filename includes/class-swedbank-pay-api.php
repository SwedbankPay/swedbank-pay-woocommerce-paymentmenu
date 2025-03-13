<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Exception;
use WP_Error;
use WC_Log_Levels;
use WC_Order;
use WC_Payment_Gateway;
use Swedbank_Pay_Payment_Gateway_Checkout;
use SwedbankPay\Api\Client\Client;
use SwedbankPay\Api\Client\Exception as ClientException;
use SwedbankPay\Api\Response;
use SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCaptureV3;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionCancelV3;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Request\TransactionReversalV3;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction as TransactionData;
use SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\TransactionObject;
use SwedbankPay\Api\Service\Paymentorder\Request\Purchase;
use SwedbankPay\Api\Service\Paymentorder\Resource\Collection\Item\OrderItem;
use SwedbankPay\Api\Service\Paymentorder\Resource\Collection\OrderItemsCollection;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderObject;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer;
use SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl;
use SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;

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
	const MODE_TEST = 'test';
	const MODE_LIVE = 'live';

	const INTENT_AUTOCAPTURE = 'AutoCapture';
	const INTENT_AUTHORIZATION = 'Authorization';
	const INTENT_SALE = 'Sale';

	const OPERATION_PURCHASE = 'Purchase';

	const TYPE_VERIFICATION = 'Verification';
	const TYPE_AUTHORIZATION = 'Authorization';
	const TYPE_CAPTURE = 'Capture';
	const TYPE_SALE = 'Sale';
	const TYPE_CANCELLATION = 'Cancellation';
	const TYPE_REVERSAL = 'Reversal';

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

	public function initiate_purchase( WC_Order $order ) {
		$gateway = swedbank_pay_get_payment_method( $order );

		$callbackUrl = add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key' => $order->get_order_key(),
			),
			WC()->api_request_url( get_class( $gateway ) )
		);

		$completeUrl = $gateway->get_return_url( $order );
		$cancelUrl = $order->get_cancel_order_url_raw();

		$user_agent = $order->get_customer_user_agent();
		if ( empty( $userAgent ) ) {
			$user_agent = 'WooCommerce/' . WC()->version;
		}

		$urlData = new PaymentorderUrl();
		$urlData
			->setHostUrls(
				$this->get_host_urls(
					array(
						$completeUrl,
						$cancelUrl,
						$callbackUrl,
						$gateway->terms_url,
						$gateway->logo_url
					)
				)
			)
			->setCompleteUrl( $completeUrl )
			->setCancelUrl( $cancelUrl )
			->setCallbackUrl( $callbackUrl )
			->setTermsOfService( $gateway->terms_url )
			->setLogoUrl( $gateway->logo_url );

		$payeeInfo = $this->get_payee_info( $order );

		// Add metadata
		$metadata = new PaymentorderMetadata();
		$metadata->setData( 'order_id', $order->get_id() );

		// Build items collection
		$items = swedbank_pay_get_order_lines( $order );

		$order_items = new OrderItemsCollection();
		foreach ( $items as $item ) {
			$orderItem = new OrderItem();
			$orderItem
				->setReference( $item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] )
				->setName( $item[Swedbank_Pay_Order_Item::FIELD_NAME] )
				->setType( $item[Swedbank_Pay_Order_Item::FIELD_TYPE] )
				->setItemClass( $item[Swedbank_Pay_Order_Item::FIELD_CLASS] )
				->setItemUrl( $item[Swedbank_Pay_Order_Item::FIELD_ITEM_URL] )
				->setImageUrl( $item[Swedbank_Pay_Order_Item::FIELD_IMAGE_URL] )
				->setDescription( $item[Swedbank_Pay_Order_Item::FIELD_DESCRIPTION] )
				->setQuantity( $item[Swedbank_Pay_Order_Item::FIELD_QTY] )
				->setUnitPrice( $item[Swedbank_Pay_Order_Item::FIELD_UNITPRICE] )
				->setQuantityUnit( $item[Swedbank_Pay_Order_Item::FIELD_QTY_UNIT] )
				->setVatPercent( $item[Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT] )
				->setAmount( $item[Swedbank_Pay_Order_Item::FIELD_AMOUNT] )
				->setVatAmount( $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] );

			$order_items->addItem($orderItem);
		}

		$payment_order = new Paymentorder();
		$payment_order
			->setOperation( self::OPERATION_PURCHASE )
			->setCurrency( $order->get_currency() )
			->setAmount(
				(int) bcmul(
					100,
					apply_filters(
						'swedbank_pay_order_amount',
						$order->get_total(),
						$items,
						$order
					)
				)
			)
			->setVatAmount(
				apply_filters(
					'swedbank_pay_order_vat',
					$this->calculate_vat_amount( $items ),
					$items,
					$order
				)
			)
			->setDescription(
				apply_filters(
					'swedbank_pay_payment_description',
					sprintf(
					/* translators: 1: order id */                    __('Order #%1$s', 'swedbank-pay-woocommerce-payments'),
						$order->get_order_number()
					),
					$order
				)
			)
			->setUserAgent( $user_agent )
			->setLanguage( $gateway->culture )
			->setProductName( 'Checkout3' )
			->setImplementation( 'PaymentsOnly' )
			->setDisablePaymentMenu( false )
			->setUrls( $urlData )
			->setPayeeInfo( $payeeInfo )
			->setMetadata( $metadata )
			->setOrderItems( $order_items );

		$payment_order->setPayer( $this->get_payer( $order ) );

		$payment_order_object = new PaymentorderObject();
		$payment_order_object->setPaymentorder( $payment_order );

		$purchaseRequest = new Purchase( $payment_order_object );
		$purchaseRequest->setClient( $this->get_client() );

		try {
			/** @var ResponseServiceInterface $responseService */
			$responseService = $purchaseRequest->send();

			$this->log(
				WC_Log_Levels::DEBUG,
				$purchaseRequest->getClient()->getDebugInfo()
			);

			return $responseService;
		} catch ( ClientException $e ) {
			$this->log(
				WC_Log_Levels::DEBUG,
				$purchaseRequest->getClient()->getDebugInfo()
			);

			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				400,
				$this->format_error_message( $purchaseRequest->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Do API Request
	 *
	 * @param       $method
	 * @param       $url
	 * @param array $params
	 *
	 * @return Response|\WP_Error
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function request( $method, $url, $params = array() ) {
		// Get rid of full url. There's should be an endpoint only.
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$parsed = parse_url($url);
			$url = $parsed['path'];
			if (!empty($parsed['query'])) {
				$url .= '?' . $parsed['query'];
			}
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'validation', 'Invalid url' );
		}

		// Process params
		array_walk_recursive(
			$params,
			function ( &$input ) {
				if ( is_object( $input ) && method_exists( $input, 'toArray' ) ) {
					$input = $input->toArray();
				}
			}
		);

		$start = microtime( true );
		$this->log(
			WC_Log_Levels::DEBUG,
			sprintf(
				'Request: %s %s %s',
				$method,
				$url,
				json_encode( $params, JSON_PRETTY_PRINT )
			)
		);

		try {
			/** @var \SwedbankPay\Api\Response $response */
			$client = $this->get_client()->request( $method, $url, $params );

			//$codeClass = (int)($this->client->getResponseCode() / 100);
			$response_body = $client->getResponseBody();
			$result = json_decode( $response_body, true );
			$time = microtime( true ) - $start;
			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '[%.4F] Response: %s', $time, $response_body )
			);

			return $result;
		} catch ( \SwedbankPay\Api\Client\Exception $exception ) {
			$httpCode = (int) $this->get_client()->getResponseCode();
			$time = microtime( true ) - $start;
			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf(
					'[%.4F] Client Exception. Check debug info: %s',
					$time,
					$this->get_client()->getDebugInfo()
				)
			);

			// https://tools.ietf.org/html/rfc7807
			$data = json_decode( $this->get_client()->getResponseBody(), true );
			if ( json_last_error() === JSON_ERROR_NONE &&
				isset( $data['title'] ) &&
				isset( $data['detail'] )
			) {
				// Format error message
				$message = sprintf( '%s. %s', $data['title'], $data['detail'] );

				// Get details
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
	 * Fetch Payment Info.
	 *
	 * @param string $payment_id_url
	 * @param string|null $expand
	 *
	 * @return Response
	 * @deprecated Use request()
	 */
	public function fetch_payment_info( $payment_id_url, $expand = null ) {
		if ($expand) {
			$payment_id_url .= '?$expand=' . $expand;
		}

		$result = $this->request( 'GET', $payment_id_url );
		if ( is_wp_error( $result ) ) {
			/** @var \WP_Error $result */
			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $result->get_error_message() )
			);

			return $result;
		}

		return $result;
	}

	// @todo Check if captured fully
	public function is_captured( $payment_id_url ) {
		// Fetch transactions list
		$result = $this->request( 'GET', $payment_id_url . '/financialtransactions' );
		$transactionsList = $result['financialTransactions']['financialTransactionsList'];

		// Check if have captured transactions
		foreach ( $transactionsList as $transaction ) {
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
		$this->log(
			WC_Log_Levels::DEBUG,
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

		$result = $this->request( 'GET', $payment_order_id . '/financialtransactions' );
		$transactions_list = $result['financialTransactions']['financialTransactionsList'] ?? [];
		// @todo Sort by "created" field using array_multisort
		foreach ( $transactions_list as $transaction ) {
			if ( $transaction_number === $transaction['number'] ) {
				$this->log(
					WC_Log_Levels::DEBUG,
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
		// Financial transaction list is empty, initiate workaround / failback
		if ( 0 === count( $transactions_list ) ) {
			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( 'Transaction List is empty. Run failback for Transaction #%s', $transaction_number )
			);

			$transaction = array(
				'id'             => $payment_order_id . '/financialtransactions/' . uniqid('fake'),
				'created'        => date( 'Y-m-d H:i:s' ),
				'updated'        => date( 'Y-m-d H:i:s' ),
				'type'           => $data['paid']['transactionType'],
				'number'         => $transaction_number,
				'amount'         => $data['paid']['amount'],
				'vatAmount'      => 0,
				'description'    => $data['paid']['id'],
				'payeeReference' => $data['paid']['payeeReference'],
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
	 * @param array $transaction
	 *
	 * @return true|WP_Error
	 */
	public function process_transaction( WC_Order $order, array $transaction ) {
		$transaction_id = $transaction['number'];
		
		// Don't update order status if transaction ID was applied before
		$transactions = $this->get_latest_swedbank_transactions($order);
		if ( in_array( $transaction_id, $transactions ) ) {
			$this->log(
				WC_Log_Levels::INFO,
				sprintf( 'Skip transaction processing #%s. Order ID: %s', $transaction_id, $order->get_id() )
			);

			return true;
		}

		$this->log(
			WC_Log_Levels::DEBUG,
			sprintf( 'Process transaction: %s', var_export( $transaction, true ) )
		);

		// Fetch payment info
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Payment order ID is unknown.' );
		}

		$payment_order = $this->request( 'GET', $payment_order_id );
		$payment_order = $payment_order['paymentOrder'];

		// Apply action
		switch ( $transaction['type'] ) {
			case self::TYPE_VERIFICATION:
				break;
			case self::TYPE_AUTHORIZATION:
				$message = sprintf( 'Payment has been authorized. Transaction: %s', $transaction_id );

				// Don't change the order status if it was captured before
				if ( $order->has_status( array( 'processing', 'completed', 'active' ) ) ) {
					$order->add_order_note( $message );
				} else {
					$this->update_order_status(
						$order,
						'on-hold',
						$transaction_id,
						sprintf( 'Payment has been authorized. Transaction: %s', $transaction_id )
					);
				}

				break;
			case self::TYPE_CAPTURE:
			case self::TYPE_SALE:
				$is_full_capture = false;

				// Check if the payment was captured fully
				// `remainingCaptureAmount` is missing if the payment was captured fully
				if ( ! isset( $payment_order['remainingCaptureAmount'] ) ) {
					$this->log(
						WC_Log_Levels::DEBUG,
						sprintf(
							'Warning: Payment Order ID: %s. Transaction %s. Transaction amount: %s. Order amount: %s. Field remainingCaptureAmount is missing. Full action?', //phpcs:ignore
							$payment_order_id,
							$transaction_id,
							$transaction['amount'] / 100,
							$order->get_total()
						)
					);

					$is_full_capture = true;
				}

				// Update order status
				if ( $is_full_capture ) {
					$this->update_order_status(
						$order,
						'processing',
						$transaction_id,
						sprintf(
							'Payment has been captured. Transaction: %s. Amount: %s',
							$transaction_id,
							$transaction['amount'] / 100
						)
					);
				} else {
					$remaining_amount = isset( $payment_order['remainingCaptureAmount'] ) ? $payment_order['remainingCaptureAmount'] / 100 : 0;

					$order->add_order_note(
						sprintf(
							'Payment has been partially captured: Transaction: %s. Amount: %s. Remaining amount: %s', //phpcs:ignore
							$transaction_id,
							$transaction['amount'] / 100,
							$remaining_amount
						)
					);
				}

				break;
			case self::TYPE_CANCELLATION:
				$this->update_order_status(
					$order,
					'cancelled',
					$transaction_id,
					sprintf('Payment has been cancelled. Transaction: %s', $transaction_id)
				);

				break;
			case self::TYPE_REVERSAL:
				// Check if the payment was refunded fully
				// `remainingReversalAmount` is missing if the payment was refunded fully
				$is_full_refund = false;
				if ( ! isset( $payment_order['remainingReversalAmount'] ) ) {
					$this->log(
						WC_Log_Levels::DEBUG,
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
				return new \WP_Error( sprintf('Error: Unknown type %s', $transaction['type']) );
		}

		// Save transaction ID
		$latest_transactions = $this->get_latest_swedbank_transactions($order);
        $latest_transactions[] = $transaction_id;
        $order->update_meta_data( '_swedbank_pay_transactions', $latest_transactions );
		$order->save_meta_data();
        $order->save();

		$this->log(
			WC_Log_Levels::DEBUG,
			sprintf( 'Transaction #%s has been processed.', $transaction['number'] )
		);

		return true;
	}

    /**
     * Reload order meta to ensure we have the latest changes and avoid conflicts from parallel scripts
     * @param WC_Order $order
     * @return array
     */
    private function get_latest_swedbank_transactions(WC_Order $order) {
        $order->read_meta_data();
        $transactions = $order->get_meta('_swedbank_pay_transactions');

        return empty($transactions) ? array() : (array) $transactions;
    }

    /**
	 * Update Order Status.
	 *
	 * @param WC_Order $order
	 * @param string $status
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
				// Set pending
				if ( ! $order->has_status( 'pending' ) ) {
					$order->update_status('pending', $message );
				} elseif ($message) {
					$order->add_order_note( $message );
				}

				break;
			case 'on-hold':
				// Set on-hold
				if ( ! $order->has_status( 'on-hold' ) ) {
					// Reduce stock
					wc_maybe_reduce_stock_levels( $order_id );
					$order->update_status( 'on-hold', $message );
				} elseif ( $message ) {
					$order->add_order_note( $message );
				}

				$order->set_transaction_id( $transaction_id );

				break;
			case 'processing':
			case 'completed':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $transaction_id );
					if ( $message ) {
						$order->add_order_note( $message );
					}
				} else {
					$order->update_status(
						apply_filters(
							'woocommerce_payment_complete_order_status',
							$order->needs_processing() ? 'processing' : 'completed',
							$order->get_id(),
							$order
						),
						$message
					);

					$order->set_transaction_id( $transaction_id );
				}

				break;
			case 'cancelled':
				// Set cancelled
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
					$order->add_order_note($message);
				}

				$order->set_transaction_id( $transaction_id );

				break;
			default:
				$order->update_status( $status, $message );
		}
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

		if ( empty( $this->payment_orders[$payment_order_id] ) ) {
			$this->payment_orders[$payment_order_id] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[$payment_order_id] ) ) {
			return false;
		}

		return isset( $this->payment_orders[$payment_order_id]['paymentOrder']['remainingCaptureAmount'] )
			   && (float) $this->payment_orders[$payment_order_id]['paymentOrder']['remainingCaptureAmount'] > 0.1;
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

		if ( empty( $this->payment_orders[$payment_order_id] ) ) {
			$this->payment_orders[$payment_order_id] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[$payment_order_id] ) ) {
			return false;
		}

		return isset( $this->payment_orders[$payment_order_id]['paymentOrder']['remainingCancellationAmount'] )
			   && (float)$this->payment_orders[$payment_order_id]['paymentOrder']['remainingCancellationAmount'] > 0.1;
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

		if ( empty( $this->payment_orders[$payment_order_id] ) ) {
			$this->payment_orders[$payment_order_id] = $this->request( 'GET', $payment_order_id );
		}

		if ( is_wp_error( $this->payment_orders[$payment_order_id] ) ) {
			return false;
		}

		return isset( $this->payment_orders[$payment_order_id]['paymentOrder']['remainingReversalAmount'] )
			   && (float) $this->payment_orders[$payment_order_id]['paymentOrder']['remainingReversalAmount'] > 0.1;
	}

	/**
	 * Get urls where hosts
	 *
	 * @return array
	 */
	private function get_host_urls( $urls ) {
		$result = [];
		foreach ( $urls as $url ) {
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$parsed = parse_url( $url );
				$result[] = sprintf( '%s://%s', $parsed['scheme'], $parsed['host'] );
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Capture Checkout.
	 *
	 * @param WC_Order $order
	 * @param array $items
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

		if ( count( $items ) === 0 ) {
			$items = swedbank_pay_get_order_lines( $order );
		}

		// Build items collection
		$order_items = new OrderItemsCollection();

		// Recalculate amount and VAT amount
		$amount = 0;
		$vat_amount = 0;
		foreach ($items as $item) {
			$amount += $item[Swedbank_Pay_Order_Item::FIELD_AMOUNT];
			$vat_amount += $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT];

			$order_item = new OrderItem();
			$order_item
				->setReference( $item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] )
				->setName( $item[Swedbank_Pay_Order_Item::FIELD_NAME] )
				->setType( $item[Swedbank_Pay_Order_Item::FIELD_TYPE] )
				->setItemClass( $item[Swedbank_Pay_Order_Item::FIELD_CLASS] )
				->setItemUrl( $item[Swedbank_Pay_Order_Item::FIELD_ITEM_URL] )
				->setImageUrl( $item[Swedbank_Pay_Order_Item::FIELD_IMAGE_URL] )
				->setDescription( $item[Swedbank_Pay_Order_Item::FIELD_DESCRIPTION] )
				->setQuantity( $item[Swedbank_Pay_Order_Item::FIELD_QTY] )
				->setUnitPrice( $item[Swedbank_Pay_Order_Item::FIELD_UNITPRICE] )
				->setQuantityUnit( $item[Swedbank_Pay_Order_Item::FIELD_QTY_UNIT] )
				->setVatPercent( $item[Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT] )
				->setAmount( $item[Swedbank_Pay_Order_Item::FIELD_AMOUNT] )
				->setVatAmount( $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] );

			$order_items->addItem( $order_item );
		}

		$transaction_data = new TransactionData();
		$transaction_data
			->setAmount( $amount )
			->setVatAmount( $vat_amount )
			->setDescription( sprintf( 'Capture for Order #%s', $order->get_order_number() ) )
			->setPayeeReference(
				apply_filters(
					'swedbank_pay_payee_reference',
					swedbank_pay_generate_payee_reference( $order->get_id() )
				)
			)
			->setOrderItems( $order_items );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$requestService = new TransactionCaptureV3( $transaction );
		$requestService->setClient( $this->get_client() )
			->setPaymentOrderId($payment_order_id);

		try {
			/** @var ResponseServiceInterface $responseService */
			$responseService = $requestService->send();

			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$result = $responseService->getResponseData();

			// Save transaction
			$transaction = $result['capture']['transaction'];
			$gateway = swedbank_pay_get_payment_method( $order );
			$gateway->transactions->import( $transaction, $order->get_id() );

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch ( ClientException $e ) {
			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'capture',
				$this->format_error_message( $requestService->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	public function cancel_checkout( WC_Order $order ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Unable to get the payment order ID' );
		}

		$transaction_data = new TransactionData();
		$transaction_data
			->setDescription( sprintf( 'Cancellation for Order #%s', $order->get_order_number() ) )
			->setPayeeReference(
				apply_filters(
					'swedbank_pay_payee_reference',
					swedbank_pay_generate_payee_reference( $order->get_id() )
				)
			);

		$transaction = new TransactionObject();
		$transaction->setTransaction($transaction_data);

		$requestService = new TransactionCancelV3($transaction);
		$requestService->setClient( $this->get_client() )
			->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $responseService */
			$responseService = $requestService->send();

			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$result = $responseService->getResponseData();

			// Save transaction
			$transaction = $result['cancellation']['transaction'];

			$gateway = swedbank_pay_get_payment_method( $order );
			$gateway->transactions->import( $transaction, $order->get_id() );

			$this->process_transaction( $order, $transaction );

			return $result;
		} catch ( ClientException $e ) {
			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'cancel',
				$this->format_error_message( $requestService->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Refund amount for an order
	 *
	 * @param WC_Order $order The order object
	 * @param float $amount The amount to refund
	 *
	 * @return TransactionObject|\WP_Error Returns the refunded transaction object or WP_Error on failure
	 * @throws ClientException
	 */
	public function refund_amount( WC_Order $order, $amount ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 0, 'Unable to get the payment order ID' );
		}


		$payee_refrence = apply_filters(
			'swedbank_pay_payee_reference',
			swedbank_pay_generate_payee_reference( $order->get_id() )
		);

		$transaction_data = new TransactionData();
		$transaction_data
			->setAmount( round( $amount * 100 ) )
			->setVatAmount( 0 )
			->setDescription( sprintf( 'Refund for Order #%s. Amount: %s', $order->get_order_number(), $amount ) ) //phpcs:ignore
			->setPayeeReference( $payee_refrence )
			->setReceiptReference( $payee_refrence );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$requestService = new TransactionReversalV3( $transaction );
		$requestService->setClient( $this->get_client() )
					   ->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $responseService */
			$responseService = $requestService->send();

			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$result = $responseService->getResponseData();

			// Save transaction
			$transaction = $result['reversal']['transaction'];

			$gateway = swedbank_pay_get_payment_method( $order );
			$gateway->transactions->import( $transaction, $order->get_id() );

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch (ClientException $e) {
			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'refund',
				$this->format_error_message( $requestService->getClient()->getResponseBody(), $e->getMessage() )
			);
		}
	}

	/**
	 * Refund Checkout.
	 *
	 * @param WC_Order $order
	 * @param array $items
	 *
	 * @return \WP_Error|array
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.MissingImport)
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 */
	public function refund_checkout( WC_Order $order, array $items = array() ) {
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 0, 'Unable to get the payment order ID' );
		}

		if ( count( $items ) === 0 ) {
			$items = swedbank_pay_get_order_lines( $order );
		}

		// Build items collection
		$order_items = new OrderItemsCollection();

		// Recalculate amount and VAT amount
		$amount = 0;
		$vat_amount = 0;
		foreach ($items as $item) {
			$amount += $item[Swedbank_Pay_Order_Item::FIELD_AMOUNT];
			$vat_amount += $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT];

			$order_item = new OrderItem();
			$order_item
				->setReference( $item[Swedbank_Pay_Order_Item::FIELD_REFERENCE] )
				->setName( $item[Swedbank_Pay_Order_Item::FIELD_NAME] )
				->setType( $item[Swedbank_Pay_Order_Item::FIELD_TYPE] )
				->setItemClass( $item[Swedbank_Pay_Order_Item::FIELD_CLASS] )
				->setItemUrl( $item[Swedbank_Pay_Order_Item::FIELD_ITEM_URL] )
				->setImageUrl( $item[Swedbank_Pay_Order_Item::FIELD_IMAGE_URL] )
				->setDescription( $item[Swedbank_Pay_Order_Item::FIELD_DESCRIPTION] )
				->setQuantity( $item[Swedbank_Pay_Order_Item::FIELD_QTY] )
				->setUnitPrice( $item[Swedbank_Pay_Order_Item::FIELD_UNITPRICE] )
				->setQuantityUnit( $item[Swedbank_Pay_Order_Item::FIELD_QTY_UNIT] )
				->setVatPercent( $item[Swedbank_Pay_Order_Item::FIELD_VAT_PERCENT] )
				->setAmount( $item[Swedbank_Pay_Order_Item::FIELD_AMOUNT] )
				->setVatAmount( $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT] );

			$order_items->addItem( $order_item );
		}

		$payee_refrence = apply_filters(
			'swedbank_pay_payee_reference',
			swedbank_pay_generate_payee_reference( $order->get_id() )
		);

		$transaction_data = new TransactionData();
		$transaction_data
			->setAmount( $amount )
			->setVatAmount( $vat_amount )
			->setDescription( sprintf( 'Refund for Order #%s. Amount: %s', $order->get_order_number(), ( $amount / 100) ) ) //phpcs:ignore
			->setPayeeReference( $payee_refrence )
			->setReceiptReference( $payee_refrence )
			->setOrderItems( $order_items );

		$transaction = new TransactionObject();
		$transaction->setTransaction( $transaction_data );

		$requestService = new TransactionReversalV3( $transaction );
		$requestService->setClient( $this->get_client() )
					   ->setPaymentOrderId( $payment_order_id );

		try {
			/** @var ResponseServiceInterface $responseService */
			$responseService = $requestService->send();

			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$result = $responseService->getResponseData();

			// Save transaction
			$transaction = $result['reversal']['transaction'];

			$gateway = swedbank_pay_get_payment_method( $order );
			$gateway->transactions->import( $transaction, $order->get_id() );

			$this->process_transaction( $order, $transaction );

			return $transaction;
		} catch (ClientException $e) {
			$this->log(
				WC_Log_Levels::DEBUG,
				$requestService->getClient()->getDebugInfo()
			);

			$this->log(
				WC_Log_Levels::DEBUG,
				sprintf( '%s: API Exception: %s', __METHOD__, $e->getMessage() )
			);

			return new \WP_Error(
				'refund',
				$this->format_error_message( $requestService->getClient()->getResponseBody(), $e->getMessage() )
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
			$message = var_export( $message, true );
		}

		$logger->log(
			$level,
			sprintf(
				'[%s] %s [%s]',
				$level,
				$message,
				count( $context ) > 0 ? var_export( $context, true ) : ''
			),
			array_merge(
				$context,
				array(
					'source' => 'payex_checkout',
					'_legacy' => true,
				)
			)
		);
	}

	/**
	 * Get `PaymentorderPayeeInfo`.
	 *
	 * @param WC_Order $order
	 *
	 * @return PaymentorderPayeeInfo
	 */
	private function get_payee_info( WC_Order $order ) {
		$gateway = swedbank_pay_get_payment_method( $order );

		return new PaymentorderPayeeInfo(
			array(
				'orderReference' => apply_filters(
					'swedbank_pay_order_reference',
					$order->get_id()
				),
				'payeeReference' => apply_filters(
					'swedbank_pay_payee_reference',
					swedbank_pay_generate_payee_reference( $order->get_id() )
				),
				'payeeId' => $gateway->payee_id,
				'payeeName' => apply_filters(
					'swedbank_pay_payee_name',
					get_bloginfo('name'),
					$gateway->id
				)
			)
		);
	}

	/**
	 * Get `PaymentorderPayer`.
	 *
	 * @param WC_Order $order
	 * @return PaymentorderPayer
	 */
	private function get_payer( WC_Order $order )
	{
		$payer = new PaymentorderPayer();
		$payer->setPayerReference( $this->get_customer_uuid( $order ) );
		$payer->setFirstName( $order->get_billing_first_name() )
			  ->setLastName( $order->get_billing_last_name() )
			  ->setEmail( $order->get_billing_email() )
			  ->setMsisdn( str_replace( ' ', '', $order->get_billing_phone() ) );

		// Does an order need shipping?
		$needs_shipping = false;
		foreach ( $order->get_items() as $order_item ) {
			$product = $order_item->get_product();
			// Check is product shippable
			if ( $product && $product->needs_shipping() ) {
				$needs_shipping = true;
				break;
			}
		}

		if ( ! $needs_shipping ) {
			$payer->setDigitalProducts( true );
		}

		return $payer;
	}

	private function get_customer_uuid( WC_Order $order ) {
		$user_id = $order->get_user_id();

		if ( $user_id > 0 ) {
			$payer_reference = get_user_meta( $user_id, '_payex_customer_uuid', true );
			if ( empty( $payer_reference ) ) {
				$payer_reference = apply_filters( 'swedbank_pay_generate_uuid', $user_id );
				update_user_meta( $user_id, '_payex_customer_uuid', $payer_reference );
			}

			return $payer_reference;
		}

		return apply_filters( 'swedbank_pay_generate_uuid', uniqid( $order->get_billing_email() ) );
	}

	/**
	 * @return Client
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	private function get_client() {
		$client = new Client();

		$user_agent = $client->getUserAgent() . ' ' . $this->get_initiating_system_user_agent();
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent .= ' ' . $_SERVER['HTTP_USER_AGENT'];
		}

		$client->setAccessToken( $this->access_token )
			   ->setPayeeId( $this->payee_id )
			   ->setMode( $this->mode === self::MODE_TEST ? Client::MODE_TEST : Client::MODE_PRODUCTION )
			   ->setUserAgent( $user_agent );

		return $client;
	}

	/**
	 * Get Initiating System User Agent.
	 *
	 * @return string
	 */
	private function get_initiating_system_user_agent() {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$plugins = get_plugins();
		foreach ( $plugins as $file => $plugin ) {
			if ( strpos( $file, 'swedbank-pay-payment-menu.php' ) !== false ) {
				return 'swedbank-pay-payment-menu/' . $plugin['Version'];
			}
		}

		return '';
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
		if ( isset( $response_body['problems'] ) && count( $response_body['problems'] ) > 0) {
			foreach ( $response_body['problems'] as $problem ) {
				// Specify error message for invalid phone numbers. It's such fields like:
				// Payment.Cardholder.Msisdn
				// Payment.Cardholder.HomePhoneNumber
				// Payment.Cardholder.WorkPhoneNumber
				// Payment.Cardholder.BillingAddress.Msisdn
				// Payment.Cardholder.ShippingAddress.Msisdn
				if ( ( strpos( $problem['name'], 'Msisdn' ) !== false) ||
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
			$message = ! empty( $err_msg ) ? $err_msg : __( 'Error', 'woocommerce' );
		}

		return $message;
	}

	/**
	 * Calculate VAT amount.
	 *
	 * @param array $items
	 *
	 * @return int|float
	 */
	private function calculate_vat_amount( array $items ) {
		$vat_amount = 0;
		foreach ( $items as $item ) {
			$vat_amount += $item[Swedbank_Pay_Order_Item::FIELD_VAT_AMOUNT];
		}

		return $vat_amount;
	}
}
