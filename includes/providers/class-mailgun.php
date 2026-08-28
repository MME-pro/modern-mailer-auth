<?php
/**
 * Mailgun provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Mailgun's /messages.mime endpoint.
 *
 * Mailgun is the one API service here that will take a complete RFC 822
 * message, so it gets the bytes PHPMailer produced rather than a JSON
 * reconstruction of them. That is worth going out of the way for: it means
 * attachments, inline cid: images, custom headers and every encoding decision
 * are exactly what WordPress built, with nothing in this file needing to
 * understand any of them.
 *
 * The cost is that /messages.mime is multipart/form-data rather than JSON, so
 * this cannot use Abstract_Api_Provider's send path and builds the request body
 * by hand.
 */
class Mailgun extends Abstract_Provider {

	private const REGIONS = [
		'us' => 'https://api.mailgun.net/v3/',
		'eu' => 'https://api.eu.mailgun.net/v3/',
	];

	/** Mailgun accepts up to 25 MB per message. */
	private const MAX_MIME_BYTES = 26214400;

	public function get_label(): string {
		return __( 'Mailgun', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'mailgun';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Mailgun', 'modern-mailer-oauth' ),
			'summary'  => __( 'Accepts the complete message rather than a reconstruction of it, so attachments and inline images pass through untouched. Pick the region your domain was created in.', 'modern-mailer-oauth' ),
			'docs'     => 'https://documentation.mailgun.com/docs/mailgun/api-reference/openapi-final/tag/Messages/',
			'category' => 'api',
			'raw_mime' => true,

			// Listed but not selectable yet. Kept in the registry rather than
			// removed, so a site already sending through it carries on doing so -
			// withdrawing a working transport in an update would stop that site's
			// mail, which is never an acceptable way to narrow a feature set.
			'coming_soon' => true,
		];
	}

	public static function fields(): array {
		return [
			Field::secret(
				'mailgun_api_key',
				__( 'Sending API key', 'modern-mailer-oauth' ),
				__( 'From Send, Sending, Domain settings. A sending key, not the account API key.', 'modern-mailer-oauth' )
			),
			Field::required(
				'mailgun_domain',
				__( 'Sending domain', 'modern-mailer-oauth' ),
				__( 'The verified domain in Mailgun, for example mg.yourdomain.com.', 'modern-mailer-oauth' ),
				'mg.yourdomain.com'
			),
			new Field(
				key: 'mailgun_region',
				label: __( 'Region', 'modern-mailer-oauth' ),
				type: Field::SELECT,
				required: true,
				help: __( 'Must match where the domain was created. A EU domain called on the US endpoint returns a confusing "domain not found".', 'modern-mailer-oauth' ),
				options: [
					'us' => 'US',
					'eu' => 'EU',
				],
				default: 'us'
			),
		];
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		$key    = $this->settings->secrets()->get( 'mailgun_api_key' );
		$domain = trim( (string) $this->settings->get( 'mailgun_domain' ) );

		if ( '' === $key || '' === $domain ) {
			return new WP_Error(
				'mmoa_provider_incomplete',
				__( 'Mailgun is missing its sending API key or domain.', 'modern-mailer-oauth' )
			);
		}

		$message   = Message::from_mailer( $raw_mime, $mailer );
		$boundary  = 'mmoa' . wp_generate_password( 24, false );
		$recipients = array_column( $message->all_recipients(), 'email' );

		if ( [] === $recipients ) {
			return new WP_Error(
				'mmoa_no_recipient',
				__( 'The message has no recipient.', 'modern-mailer-oauth' )
			);
		}

		$response = $this->http->request(
			$this->base() . rawurlencode( $domain ) . '/messages.mime',
			[
				'method'  => 'POST',
				'headers' => [
					// Mailgun uses HTTP basic auth with the literal username
					// "api" and the key as the password.
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				],
				'body'    => $this->multipart( $boundary, $recipients, $raw_mime ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] >= 200 && $response['code'] < 300 ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'], $domain );
	}

	public function verify_connection() {
		$key    = $this->settings->secrets()->get( 'mailgun_api_key' );
		$domain = trim( (string) $this->settings->get( 'mailgun_domain' ) );

		if ( '' === $key || '' === $domain ) {
			return new WP_Error(
				'mmoa_provider_incomplete',
				__( 'Mailgun is missing its sending API key or domain.', 'modern-mailer-oauth' )
			);
		}

		// Reading the domain confirms the key, the domain and the region in one
		// call - and the region is the setting people get wrong.
		$response = $this->http->request(
			$this->base() . rawurlencode( $domain ),
			[
				'method'  => 'GET',
				'headers' => [ 'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ) ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 === $response['code'] ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'], $domain );
	}

	/**
	 * Build the multipart body: the recipient list, then the message itself.
	 *
	 * `to` is sent separately from the MIME because it is the envelope - it is
	 * what decides where Mailgun actually delivers. Bcc recipients appear here
	 * and deliberately do not appear in the message headers, which is exactly
	 * how Bcc is supposed to work and is why they are read from the Message
	 * rather than parsed back out of the MIME.
	 *
	 * @param array<int,string> $recipients Envelope recipients.
	 */
	private function multipart( string $boundary, array $recipients, string $raw_mime ): string {
		$body = '';

		foreach ( $recipients as $recipient ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= "Content-Disposition: form-data; name=\"to\"\r\n\r\n";
			$body .= $recipient . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= "Content-Disposition: form-data; name=\"message\"; filename=\"message.mime\"\r\n";
		$body .= "Content-Type: message/rfc822\r\n\r\n";
		$body .= $raw_mime . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}

	private function base(): string {
		$region = (string) $this->settings->get( 'mailgun_region' );

		return self::REGIONS[ $region ] ?? self::REGIONS['us'];
	}

	protected function token_cache_key(): string {
		return 'mailgun';
	}

	/**
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	protected function request_token() {
		return new WP_Error(
			'mmoa_not_applicable',
			__( 'Mailgun authenticates with an API key and mints no tokens.', 'modern-mailer-oauth' )
		);
	}

	private function map_error( int $status, string $body, string $domain ): WP_Error {
		$data    = json_decode( $body, true );
		$detail  = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';
		$region  = 'eu' === (string) $this->settings->get( 'mailgun_region' ) ? 'EU' : 'US';

		if ( 401 === $status ) {
			return new WP_Error(
				'mmoa_mailgun_unauthorized',
				__( 'Mailgun rejected the API key. Note that a sending key and the account API key are different things, and only a sending key works here.', 'modern-mailer-oauth' ),
				[ 'status' => $status ]
			);
		}

		if ( 404 === $status ) {
			return new WP_Error(
				'mmoa_mailgun_no_domain',
				sprintf(
					/* translators: 1: domain name, 2: selected region. */
					__( 'Mailgun has no domain %1$s in the %2$s region. This is almost always the region setting rather than the domain name - a domain created in one region does not exist in the other.', 'modern-mailer-oauth' ),
					$domain,
					$region
				),
				[ 'status' => $status ]
			);
		}

		return new WP_Error(
			'mmoa_mailgun_error',
			sprintf(
				/* translators: 1: HTTP status, 2: error message from Mailgun. */
				__( 'Mailgun returned HTTP %1$d: %2$s', 'modern-mailer-oauth' ),
				$status,
				'' !== $detail ? $detail : __( 'no details supplied', 'modern-mailer-oauth' )
			),
			[ 'status' => $status ]
		);
	}
}
