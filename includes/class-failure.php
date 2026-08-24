<?php
/**
 * Failure classification.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a failed send is worth trying again.
 *
 * This is the judgement the rest of the resilience machinery depends on, and
 * getting it wrong is expensive in both directions. Retrying a permanent
 * failure - a wrong client secret, an unlicensed mailbox - burns the queue on
 * something that will never succeed and delays the mail behind it. Not
 * retrying a transient one throws away a message that would have gone out
 * fine seconds later.
 *
 * The distinction is not "did it fail" but "would the identical request
 * plausibly succeed later with no human involved". Only transport faults and
 * explicit back-pressure qualify.
 */
class Failure {

	/**
	 * Error codes that describe a fault between us and the API, not a fault in
	 * what we asked for.
	 *
	 * `http_request_failed` is the one that matters most here: it is what
	 * WordPress returns for every cURL-level fault, which covers DNS
	 * resolution failure, connection refused, TLS handshake failure and
	 * timeout. A site whose host has flaky outbound DNS produces a long run of
	 * these while the credentials and configuration are entirely correct.
	 */
	private const TRANSPORT_CODES = [
		'http_request_failed',
		'http_request_timeout',
		'mmoa_http_failed',
	];

	/**
	 * Provider-level codes that mean "not now" rather than "not ever".
	 */
	private const BACKPRESSURE_CODES = [
		'mmoa_graph_throttled',
		'mmoa_gmail_rate_limited',

		// SMTP inverts the convention the HTTP providers use: a 4xx reply is the
		// temporary one - greylisting, "try again later", over quota - and a 5xx
		// is the permanent refusal. Reading these through the HTTP status rule
		// below would get it exactly backwards, retrying a rejected recipient
		// for two days and dropping a greylisted message on the first attempt.
		// The SMTP provider therefore classifies the reply itself and says so in
		// the error code.
		'mmoa_smtp_temporary',
		'mmoa_smtp_connect_failed',
	];

	/**
	 * Would this failure plausibly succeed on a later identical attempt?
	 */
	public static function is_retryable( WP_Error $error ): bool {
		$code = $error->get_error_code();

		if ( in_array( $code, self::TRANSPORT_CODES, true ) ) {
			return true;
		}

		if ( in_array( $code, self::BACKPRESSURE_CODES, true ) ) {
			return true;
		}

		// Providers annotate mapped API errors with the HTTP status where the
		// status alone is the deciding factor, so a 503 from an endpoint we
		// have no specific mapping for is still recognised as transient.
		$status = (int) ( $error->get_error_data()['status'] ?? 0 );

		if ( 429 === $status || ( $status >= 500 && $status <= 599 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Is this failure one a different connection might survive?
	 *
	 * Broader than is_retryable(): a backup connection has its own credentials,
	 * its own tenant and its own outbound endpoint, so it can get past things
	 * that are permanent for the primary. An expired Microsoft client secret is
	 * fatal on the primary forever, but says nothing about a Gmail backup.
	 *
	 * The exclusions are the failures that are properties of the *message*
	 * rather than of the connection. Retrying an oversized attachment or a
	 * malformed recipient list on a second provider just produces the same
	 * rejection twice and doubles the log noise.
	 */
	public static function should_try_backup( WP_Error $error ): bool {
		$fatal_for_any_connection = [
			'mmoa_message_too_large',
			'mmoa_graph_too_large',
			'mmoa_gmail_too_large',
			'mmoa_no_provider',
			'mmoa_invalid_url',
		];

		return ! in_array( $error->get_error_code(), $fatal_for_any_connection, true );
	}
}
