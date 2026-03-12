<?php
namespace Krokedil\Swedbank\Pay\Utility;

use WC_Log_Levels;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Resource\Client;
use KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Request;

/**
 * LogUtility class for handling logging of requests and responses.
 */
class LogUtility {

	private const DEFAULT_TITLE = 'Swedbank Pay API Request';

	/**
	 * Default title for log entries if none is provided.
	 *
	 * The title can be set before logging, and will be reset to the default after logging to avoid affecting other log entries.
	 * This will be used for cases where the title cannot be determined at the time of logging, but can be set beforehand.
	 *
	 * @var string
	 */
	public static $title = self::DEFAULT_TITLE;

	/**
	 * Log the request and response data for a given request.
	 *
	 * @param string         $title The title of the log entry.
	 * @param Request|Client $request The request object containing the request and response data, or a client object.
	 * @param string         $log_level The log level (default: WC_Log_Levels::INFO).
	 * @param array          $context Additional context to include in the log entry (default: empty array).
	 *
	 * @return void
	 */
	public static function log_request( $title, $request, $log_level = WC_Log_Levels::INFO, $context = array() ) {
		$request_data = self::format_request_log( empty( $title ) ? self::$title : $title, $request );
		Swedbank_Pay()->logger()->log( wp_json_encode( $request_data ), $log_level, $context );
	}

	/**
	 * Format the request and response data for a given request.
	 *
	 * @param string         $title The title of the log entry.
	 * @param Request|Client $request The request object containing the request and response data, or a client object.
	 *
	 * @return array
	 */
	public static function format_request_log( $title, $request ) {
		$client = $request instanceof Client ? $request : $request->getClient();

		$log = array(
			'type'           => $client->getMethod(),
			'title'          => $title,
			'request'        => array(
				'user_agent' => $client->getUserAgent(),
				// Set the headers, but remove any sensitive information such as authorization tokens.
				'headers'    => self::get_sanitized_headers( $client->getHeaders() ),
				'body'       => json_decode( $client->getRequestBody(), true ),
			),
			'request_url'    => $client->getBaseUrl() . $client->getEndpoint(),
			'response'       => array(
				'body' => json_decode( $client->getResponseBody(), true ),
				'code' => $client->getResponseCode(),
			),
			'timestamp'      => date( 'Y-m-d H:i:s' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions -- Date is not used for display.
			'stack'          => wp_debug_backtrace_summary( __CLASS__, 0, false ),
			'plugin_version' => SWEDBANK_PAY_VERSION,
		);

		self::$title = self::DEFAULT_TITLE; // Reset the title to default after logging, so that it doesn't affect other log entries.
		return $log;
	}

	/**
	 * Get the sanitized headers from the request, masking any sensitive information such as authorization tokens.
	 *
	 * @param string[] $headers The original headers from the request.
	 *
	 * @return array The sanitized headers with sensitive information removed.
	 */
	public static function get_sanitized_headers( $headers ) {
		$sensitive_keywords = array( 'authorization', 'token', 'secret', 'key' );

		// Make a regex pattern to match any header that contains a sensitive keyword.
		$pattern = '/' . implode( '|', array_map( 'preg_quote', $sensitive_keywords ) ) . '/i';

		// Loop through the headers and mask any that match the pattern.
		foreach ( $headers as $index => $header ) {
			$normalized_header = strtolower( $header );

			if ( preg_match( $pattern, $normalized_header ) ) {
				$headers[ $index ] = preg_replace( '/:\s*(.*)/', ': [REDACTED]', $header );
			}
		}

		return $headers;
	}
}
