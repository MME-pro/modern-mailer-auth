<?php
/**
 * Google Workspace service account provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Jwt_Signer;
use ModernMailer\Field;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends via Gmail using a service account with domain-wide delegation.
 *
 * This is the Google equivalent of app-only auth on the Microsoft side, and it
 * has the same property that makes it worth preferring: no consent screen, no
 * refresh token, nothing that expires. The site signs a short-lived assertion
 * with a key it holds and exchanges it for an access token on demand.
 *
 * Google Workspace domains only - a service account cannot impersonate a
 * consumer @gmail.com account.
 */
class Gmail_Service_Account extends Abstract_Gmail {

	public function get_label(): string {
		return __( 'Google Workspace (service account)', 'modern-mailer-oauth' );
	}

	public static function slug(): string {
		return 'gmail_sa';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Google Workspace', 'modern-mailer-oauth' ),
			'summary'  => __( 'Service account with domain-wide delegation. Like app-only auth: no consent screen and no refresh token to expire. Workspace domains only.', 'modern-mailer-oauth' ),
			'docs'     => 'https://developers.google.com/identity/protocols/oauth2/service-account',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		return [
			Field::required( 'google_sa_email', __( 'Service account email', 'modern-mailer-oauth' ), __( 'The client_email value from the downloaded JSON key.', 'modern-mailer-oauth' ) ),
			new Field(
				key: 'google_sa_key',
				label: __( 'Private key', 'modern-mailer-oauth' ),
				type: Field::TEXTAREA,
				secret: true,
				required: true,
				help: __( 'The private_key value from the same JSON, including the BEGIN and END lines.', 'modern-mailer-oauth' )
			),
			new Field(
				key: 'google_sender',
				label: __( 'Send as mailbox', 'modern-mailer-oauth' ),
				type: Field::EMAIL,
				required: true,
				help: __( 'The Workspace user this service account impersonates.', 'modern-mailer-oauth' )
			),
		];
	}

	protected function mailbox(): string {
		$sender = trim( (string) $this->settings->get( 'google_sender' ) );

		return '' !== $sender ? $sender : trim( (string) $this->settings->get( 'from_email' ) );
	}

	protected function token_cache_key(): string {
		return 'gmail_sa:' . md5(
			(string) $this->settings->get( 'google_sa_email' ) . '|' .
			$this->mailbox() . '|' .
			$this->settings->secrets()->get( 'google_sa_key' )
		);
	}

	protected function request_token() {
		$issuer  = trim( (string) $this->settings->get( 'google_sa_email' ) );
		$subject = $this->mailbox();
		$key     = $this->settings->secrets()->get( 'google_sa_key' );

		if ( '' === $issuer || '' === $subject || '' === $key ) {
			return new WP_Error(
				'mmoa_gmail_sa_incomplete',
				__( 'The Google service account is missing its client email, private key, or the mailbox to send as.', 'modern-mailer-oauth' )
			);
		}

		$now       = time();
		$assertion = ( new Jwt_Signer() )->sign(
			[
				'iss'   => $issuer,
				// `sub` is what makes this impersonation rather than the
				// service account mailing from its own (nonexistent) mailbox.
				'sub'   => $subject,
				'scope' => self::SEND_SCOPE,
				'aud'   => self::TOKEN_URL,
				'iat'   => $now,
				'exp'   => $now + 3600,
			],
			$key
		);

		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$response = $this->http->request(
			self::TOKEN_URL,
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response['body'] );

		if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
			return $this->map_error( $response['code'], $response['body'] );
		}

		return [
			'token'      => (string) $data['access_token'],
			'expires_in' => (int) ( $data['expires_in'] ?? 3600 ),
		];
	}
}
