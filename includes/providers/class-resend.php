<?php
/**
 * Resend provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Resend's /emails endpoint.
 *
 * The simplest API of the set - one key, one endpoint, and a payload that maps
 * almost directly onto a message. Like every structured provider here it needs
 * the sending domain verified before it will accept a From address on it.
 */
class Resend extends Abstract_Api_Provider {

	private const API_URL = 'https://api.resend.com/emails';

	/** Resend caps a request at 40 MB; held well under it. */
	private const MAX_MIME_BYTES = 20971520;

	public function get_label(): string {
		return __( 'Resend', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'resend';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Resend', 'modern-mailer-oauth' ),
			'summary'  => __( 'One API key and a verified sending domain. The From address has to be on a domain you have verified in Resend.', 'modern-mailer-oauth' ),
			'docs'     => 'https://resend.com/docs/api-reference/emails/send-email',
			'category' => 'api',
			'raw_mime' => false,

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
				'resend_api_key',
				__( 'API key', 'modern-mailer-oauth' ),
				__( 'From API Keys in your Resend dashboard. Sending access is enough.', 'modern-mailer-oauth' ),
				're_...'
			),
		];
	}

	protected function endpoint(): string {
		return self::API_URL;
	}

	protected function required_credentials(): array {
		return [ 'resend_api_key' ];
	}

	protected function headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->credential( 'resend_api_key' ) ];
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload( Message $message ): array {
		$from = $message->from();

		$payload = [
			'from'    => '' !== $from['name']
				? sprintf( '%s <%s>', $from['name'], $from['email'] )
				: $from['email'],
			'to'      => array_column( $message->to(), 'email' ),
			'subject' => $message->subject(),
		];

		if ( '' !== trim( $message->html() ) ) {
			$payload['html'] = $message->html();
		}

		if ( '' !== trim( $message->text() ) ) {
			$payload['text'] = $message->text();
		}

		// Resend refuses a message with neither part. A body-less notification
		// is legitimate, so it gets a single space rather than a rejection.
		if ( ! isset( $payload['html'] ) && ! isset( $payload['text'] ) ) {
			$payload['text'] = ' ';
		}

		if ( [] !== $message->cc() ) {
			$payload['cc'] = array_column( $message->cc(), 'email' );
		}

		if ( [] !== $message->bcc() ) {
			$payload['bcc'] = array_column( $message->bcc(), 'email' );
		}

		if ( [] !== $message->reply_to() ) {
			$payload['reply_to'] = array_column( $message->reply_to(), 'email' );
		}

		$attachments = [];

		foreach ( $message->attachments() as $file ) {
			$attachments[] = [
				'filename' => $file['name'],
				'content'  => base64_encode( $file['content'] ),
			];
		}

		if ( [] !== $attachments ) {
			$payload['attachments'] = $attachments;
		}

		$headers = $message->custom_headers();

		if ( [] !== $headers ) {
			$payload['headers'] = $headers;
		}

		return $payload;
	}
}
