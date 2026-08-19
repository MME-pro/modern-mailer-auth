<?php
/**
 * Shared Gmail API transport.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Jwt_Signer;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the two Gmail providers share: the send call and error mapping.
 *
 * They differ only in how they obtain a token, which is the one method left
 * abstract by Abstract_Provider.
 */
abstract class Abstract_Gmail extends Abstract_Provider {

	protected const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	protected const API_BASE   = 'https://gmail.googleapis.com/gmail/v1';
	protected const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

	/**
	 * messages.send accepts 5 MB in a simple request; base64url costs 4/3, so
	 * the raw message must stay under ~3.75 MB. Held at 3.5 MB for headroom.
	 */
	private const MAX_MIME_BYTES = 3670016;

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	/**
	 * The mailbox to send as, in Gmail API path terms.
	 */
	abstract protected function mailbox(): string;

	public function send( string $raw_mime, PHPMailer $mailer ) {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->send_raw( $token, $raw_mime );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === $response['code'] ) {
			$this->invalidate_token();

			$token = $this->access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->send_raw( $token, $raw_mime );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['code'] >= 200 && $response['code'] < 300 ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'] );
	}

	public function verify_connection() {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->http->request(
			self::API_BASE . '/users/' . rawurlencode( $this->mailbox() ) . '/profile',
			[
				'method'  => 'GET',
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 === $response['code'] ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'] );
	}

	/**
	 * POST the message.
	 *
	 * The `raw` field is base64url - the URL-safe alphabet with padding
	 * stripped. Plain base64 is accepted often enough to look like it works
	 * and then corrupts messages containing particular byte sequences, so this
	 * distinction is worth being explicit about.
	 *
	 * @return array{code:int,body:string,headers:array}|WP_Error
	 */
	private function send_raw( string $token, string $raw_mime ) {
		return $this->http->request(
			self::API_BASE . '/users/' . rawurlencode( $this->mailbox() ) . '/messages/send',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode(
					[ 'raw' => Jwt_Signer::base64url( $raw_mime ) ]
				),
			]
		);
	}

	protected function map_error( int $status, string $body ): WP_Error {
		$data    = $this->decode( $body );
		$message = (string) ( $data['error']['message'] ?? ( $data['error_description'] ?? '' ) );
		$reason  = (string) ( $data['error']['errors'][0]['reason'] ?? ( $data['error'] ?? '' ) );

		if ( 'invalid_grant' === $reason || false !== strpos( $message, 'invalid_grant' ) ) {
			return new WP_Error(
				'mmoa_gmail_invalid_grant',
				__( 'Google rejected the credentials. For a service account, confirm domain-wide delegation is authorized for this client ID and the gmail.send scope. For a user connection, the refresh token has been revoked and the account must be reconnected.', 'modern-mailer-oauth' )
			);
		}

		if ( 'unauthorized_client' === $reason ) {
			return new WP_Error(
				'mmoa_gmail_unauthorized_client',
				__( 'The service account is not authorized to impersonate this mailbox. Add its client ID to Google Workspace Admin under Security, API Controls, Domain-wide Delegation, with the gmail.send scope.', 'modern-mailer-oauth' )
			);
		}

		if ( 403 === $status && ( 'accessNotConfigured' === $reason || false !== strpos( $message, 'has not been used' ) ) ) {
			return new WP_Error(
				'mmoa_gmail_api_disabled',
				__( 'The Gmail API is not enabled on this Google Cloud project. Enable it, then wait a minute for the change to propagate.', 'modern-mailer-oauth' )
			);
		}

		if ( 429 === $status || 'rateLimitExceeded' === $reason || 'userRateLimitExceeded' === $reason ) {
			return new WP_Error(
				'mmoa_gmail_rate_limited',
				__( 'Google is rate limiting this account. The message was not sent; try again shortly.', 'modern-mailer-oauth' )
			);
		}

		if ( 400 === $status && false !== strpos( $message, 'Recipient address required' ) ) {
			return new WP_Error(
				'mmoa_gmail_no_recipient',
				__( 'Gmail rejected the message because it had no recipient.', 'modern-mailer-oauth' )
			);
		}

		return new WP_Error(
			'mmoa_gmail_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message from Google. */
				__( 'The Gmail API returned HTTP %1$d: %2$s', 'modern-mailer-oauth' ),
				$status,
				'' !== $message ? $message : __( 'no details supplied', 'modern-mailer-oauth' )
			),
			[ 'reason' => $reason ]
		);
	}
}
