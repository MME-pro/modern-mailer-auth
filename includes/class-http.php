<?php
/**
 * HTTP transport with retry and backoff.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wp_remote_request() that adds the retry behaviour every
 * caller in this plugin needs.
 *
 * All outbound traffic goes through here, which also gives tests a single
 * choke point: stubbing the core `pre_http_request` filter intercepts
 * everything without any of the providers knowing.
 */
class Http {

	/** Total attempts, including the first. */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Ceiling on cumulative sleep across retries, in seconds.
	 *
	 * We are usually inside a page request that a human is waiting on, so
	 * honouring a multi-minute Retry-After would be worse than failing. When
	 * the server asks for longer than this, give up and report it - the retry
	 * belongs to a queue, not to this request.
	 */
	private const MAX_TOTAL_SLEEP = 8;

	private const TIMEOUT = 20;

	/**
	 * Perform a request, retrying transient failures.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string,mixed>  $args wp_remote_request() arguments.
	 * @return array{code:int,body:string,headers:array}|WP_Error
	 */
	public function request( string $url, array $args ) {
		if ( ! $this->is_valid_url( $url ) ) {
			// Reaching here means we were about to request a URL that is empty
			// or has no host - which can only happen if the URL was derived
			// from something that failed. Report it as an internal fault
			// naming the real cause, rather than as a transport error.
			//
			// This guard exists because of a well-documented failure in
			// another mailer: emails with attachments take a multi-step path
			// where one request's URL is read out of the previous request's
			// response body. When an upstream call is throttled, the extracted
			// URL is empty, and the site is told "no valid URL was specified" -
			// which names the wrong problem entirely and sends admins hunting
			// through firewall and DNS settings for a rate-limit error.
			return new WP_Error(
				'mmoa_invalid_url',
				__( 'Internal error: tried to contact the mail API using an invalid address. This means an earlier API call failed without being handled. Check the send log for the preceding error.', 'modern-mailer-oauth' ),
				[ 'url' => $url ]
			);
		}

		$args = wp_parse_args(
			$args,
			[
				'method'  => 'POST',
				'timeout' => self::TIMEOUT,
				'headers' => [],
			]
		);

		$slept         = 0;
		$attempt       = 0;
		$last_response = null;
		$last_error    = null;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			++$attempt;

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				// A transport failure: DNS, TLS, connection refused. There is no
				// API response to interpret, so this is the only case where the
				// caller gets an error instead of a response.
				$last_error    = $response;
				$last_response = null;
				$wait          = $this->backoff( $attempt );
			} else {
				$code       = (int) wp_remote_retrieve_response_code( $response );
				$normalized = [
					'code'    => $code,
					'body'    => (string) wp_remote_retrieve_body( $response ),
					'headers' => $this->headers( $response ),
				];

				if ( $code < 500 && 429 !== $code ) {
					return $normalized;
				}

				$last_response = $normalized;
				$last_error    = null;
				$wait          = 429 === $code
					? $this->retry_after( $response, $attempt )
					: $this->backoff( $attempt );
			}

			if ( $attempt >= self::MAX_ATTEMPTS ) {
				break;
			}

			// Refuse to sleep past the budget; report the failure instead.
			if ( $slept + $wait > self::MAX_TOTAL_SLEEP ) {
				break;
			}

			$slept += $wait;
			sleep( $wait );
		}

		// Retries are exhausted, but a 429 or a 5xx is still an answer from the
		// API, and its body usually says exactly what went wrong. Hand it back so
		// the provider can translate it into something an admin can act on.
		//
		// Collapsing it into a generic "HTTP 429" here would throw away the one
		// piece of information that matters - which is precisely how a rate-limit
		// problem ends up being reported as something unrelated.
		if ( null !== $last_response ) {
			return $last_response;
		}

		return $last_error ?? new WP_Error(
			'mmoa_http_failed',
			__( 'The request to the mail API could not be completed.', 'modern-mailer-oauth' )
		);
	}

	/**
	 * Is this a URL we can actually request?
	 *
	 * Deliberately stricter than WordPress's own check, which only requires a
	 * scheme. A string like "https://" has a scheme and no host: it survives
	 * that check and fails later inside the transport, producing an error that
	 * describes the symptom instead of the cause.
	 */
	private function is_valid_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true );
	}

	/**
	 * Normalize response headers to a plain array.
	 *
	 * The real HTTP transport hands back a case-insensitive dictionary object,
	 * but anything hooking pre_http_request - test stubs, caching layers,
	 * request-mocking plugins - is free to return a plain array instead.
	 * Assuming the object shape here would fatal on those sites.
	 *
	 * @param array|\WP_Error $response Raw wp_remote response.
	 * @return array<string,mixed>
	 */
	private function headers( $response ): array {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return is_array( $headers ) ? $headers : [];
	}

	/**
	 * Seconds to wait before the next attempt: 1s, 2s, 4s.
	 */
	private function backoff( int $attempt ): int {
		return (int) min( 4, 2 ** ( $attempt - 1 ) );
	}

	/**
	 * Honour a Retry-After header, which Microsoft Graph and Google both send
	 * on 429. Falls back to plain backoff when absent or unparseable.
	 *
	 * @param array|\WP_HTTP_Requests_Response $response Raw wp_remote response.
	 */
	private function retry_after( $response, int $attempt ): int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_numeric( $header ) ) {
			return max( 1, (int) $header );
		}

		// Retry-After may also be an HTTP-date.
		if ( is_string( $header ) && '' !== $header ) {
			$when = strtotime( $header );

			if ( $when ) {
				return max( 1, $when - time() );
			}
		}

		return $this->backoff( $attempt );
	}
}
