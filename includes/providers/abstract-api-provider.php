<?php
/**
 * Shared behaviour for API-key transports.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Message;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the providers that authenticate with a static API key.
 *
 * Most of the transports in this plugin are the same shape: POST a JSON body to
 * one endpoint with a key in a header, treat 2xx as success, and translate
 * anything else. Written out longhand that is roughly three hundred lines each,
 * almost all of it identical, which is how a mailer ends up with the same bug
 * fixed in four providers and missed in the other nine.
 *
 * Subclasses supply the endpoint, the auth header and the payload. Everything
 * else - retries, size limits, error translation, the credential plumbing - is
 * here.
 *
 * Note what this base does NOT do: it never touches Abstract_Provider's token
 * machinery. An API key does not expire, has no refresh, and needs no lock, so
 * a provider on this path has no token cache at all.
 */
abstract class Abstract_Api_Provider extends Abstract_Provider {

	/**
	 * Where to POST.
	 */
	abstract protected function endpoint(): string;

	/**
	 * The JSON body for this message.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	abstract protected function payload( Message $message );

	/**
	 * Headers, including authentication.
	 *
	 * @return array<string,string>
	 */
	abstract protected function headers(): array;

	/**
	 * The credential keys this provider cannot work without.
	 *
	 * @return array<int,string> Field keys.
	 */
	abstract protected function required_credentials(): array;

	/**
	 * An API-key provider has no token to mint.
	 */
	protected function token_cache_key(): string {
		return static::class;
	}

	/**
	 * Never called: access_token() is not used on this path.
	 *
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	protected function request_token() {
		return new WP_Error(
			'mmoa_not_applicable',
			__( 'This provider authenticates with an API key and mints no tokens.', 'modern-mailer-oauth' )
		);
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		$missing = $this->missing_credentials();

		if ( [] !== $missing ) {
			return $this->incomplete_error( $missing );
		}

		$message = Message::from_mailer( $raw_mime, $mailer );
		$payload = $this->payload( $message );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$response = $this->http->request(
			$this->endpoint(),
			[
				'method'  => 'POST',
				'headers' => array_merge( [ 'Content-Type' => 'application/json' ], $this->headers() ),
				'body'    => (string) wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] >= 200 && $response['code'] < 300 ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'] );
	}

	/**
	 * Confirm the credentials without sending.
	 *
	 * The default is deliberately conservative: it checks only that everything
	 * required is present, because most of these APIs have no cheap, side-effect
	 * free validation call, and the ones that do disagree about what it is.
	 * Providers with a real endpoint for this override it and get a much better
	 * answer; the rest tell the admin honestly that only a test send will
	 * settle it.
	 *
	 * @return true|WP_Error
	 */
	public function verify_connection() {
		$missing = $this->missing_credentials();

		if ( [] !== $missing ) {
			return $this->incomplete_error( $missing );
		}

		return true;
	}

	/**
	 * Recipients in the {email, name} shape most of these APIs use.
	 *
	 * @param array<int,array{name:string,email:string}> $addresses Addresses.
	 * @return array<int,array<string,string>>
	 */
	protected function recipients( array $addresses, string $email_key = 'email', string $name_key = 'name' ): array {
		$out = [];

		foreach ( $addresses as $addr ) {
			$one = [ $email_key => $addr['email'] ];

			if ( '' !== $addr['name'] ) {
				$one[ $name_key ] = $addr['name'];
			}

			$out[] = $one;
		}

		return $out;
	}

	/**
	 * A setting or credential for this provider.
	 */
	protected function credential( string $key ): string {
		foreach ( static::fields() as $field ) {
			if ( $field->key !== $key ) {
				continue;
			}

			return $field->secret
				? $this->settings->secrets()->get( $key )
				: trim( (string) $this->settings->get( $key ) );
		}

		return '';
	}

	/**
	 * @return array<int,string> Labels of anything required and empty.
	 */
	protected function missing_credentials(): array {
		$missing = [];

		foreach ( static::fields() as $field ) {
			if ( ! in_array( $field->key, $this->required_credentials(), true ) ) {
				continue;
			}

			if ( '' === $this->credential( $field->key ) ) {
				$missing[] = $field->label;
			}
		}

		return $missing;
	}

	/**
	 * @param array<int,string> $missing Field labels.
	 */
	protected function incomplete_error( array $missing ): WP_Error {
		return new WP_Error(
			'mmoa_provider_incomplete',
			sprintf(
				/* translators: 1: provider name, 2: comma-separated list of field labels. */
				__( '%1$s is missing: %2$s.', 'modern-mailer-oauth' ),
				$this->get_label(),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Turn a failed API response into something an admin can act on.
	 *
	 * Subclasses override to recognise their own error bodies. The fallback
	 * still carries the HTTP status in the error data, which is what lets
	 * Failure tell a transient 503 apart from a permanent 400 and decide
	 * whether the message is worth queueing.
	 */
	protected function map_error( int $status, string $body ): WP_Error {
		$detail = $this->extract_message( $body );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'mmoa_api_unauthorized',
				sprintf(
					/* translators: 1: provider name, 2: error detail. */
					__( '%1$s rejected the API key. %2$s', 'modern-mailer-oauth' ),
					$this->get_label(),
					$detail
				),
				[ 'status' => $status ]
			);
		}

		if ( 429 === $status ) {
			return new WP_Error(
				'mmoa_api_rate_limited',
				sprintf(
					/* translators: %s: provider name. */
					__( '%s is rate limiting this account. The message was not sent; it will be retried.', 'modern-mailer-oauth' ),
					$this->get_label()
				),
				[ 'status' => $status ]
			);
		}

		return new WP_Error(
			'mmoa_api_error',
			sprintf(
				/* translators: 1: provider name, 2: HTTP status, 3: error detail. */
				__( '%1$s returned HTTP %2$d. %3$s', 'modern-mailer-oauth' ),
				$this->get_label(),
				$status,
				$detail
			),
			[ 'status' => $status ]
		);
	}

	/**
	 * Dig a human-readable sentence out of whatever the API returned.
	 *
	 * There is no standard here - every one of these services nests its message
	 * somewhere different, and several return an array of them. Rather than
	 * teach each provider to unpick its own, the common shapes are tried in
	 * turn and the raw body is the last resort. An admin reading a truncated
	 * raw body still learns more than one reading "HTTP 400".
	 */
	protected function extract_message( string $body ): string {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return trim( substr( wp_strip_all_tags( $body ), 0, 300 ) );
		}

		$candidates = [
			$data['message'] ?? null,
			$data['Message'] ?? null,
			$data['error'] ?? null,
			$data['error_description'] ?? null,
			$data['detail'] ?? null,
			$data['errors'][0]['message'] ?? null,
			$data['errors'][0] ?? null,
			$data['messages'][0]['Errors'][0]['ErrorMessage'] ?? null,
			$data['results']['errors'][0]['message'] ?? null,
		];

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return trim( substr( (string) wp_json_encode( $data ), 0, 300 ) );
	}
}
