<?php
/**
 * Brevo provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Brevo's v3 transactional API - the service formerly called
 * Sendinblue.
 *
 * Structured JSON only. Worth knowing before choosing it: Brevo requires the
 * sender to be a verified sender or on a verified domain, and refuses anything
 * else outright rather than quietly rewriting it.
 */
class Brevo extends Abstract_Api_Provider {

	private const API_URL = 'https://api.brevo.com/v3/smtp/email';

	/** Brevo caps a request at 10 MB including base64 attachments. */
	private const MAX_MIME_BYTES = 7340032;

	public function get_label(): string {
		return __( 'Brevo', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'brevo';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Brevo', 'modern-mailer-oauth' ),
			'summary'  => __( 'Formerly Sendinblue. The From address must be a verified sender or on a verified domain, or Brevo refuses the message.', 'modern-mailer-oauth' ),
			'docs'     => 'https://developers.brevo.com/reference/sendtransacemail',
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
				'brevo_api_key',
				__( 'API key', 'modern-mailer-oauth' ),
				__( 'From SMTP & API, API keys in your Brevo account. A v3 API key, not an SMTP password.', 'modern-mailer-oauth' ),
				'xkeysib-...'
			),
		];
	}

	protected function endpoint(): string {
		return self::API_URL;
	}

	protected function required_credentials(): array {
		return [ 'brevo_api_key' ];
	}

	protected function headers(): array {
		return [
			'api-key' => $this->credential( 'brevo_api_key' ),
			'accept'  => 'application/json',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload( Message $message ): array {
		$from = $message->from();

		$payload = [
			'sender'  => array_filter(
				[
					'email' => $from['email'],
					'name'  => $from['name'],
				]
			),
			'to'      => $this->recipients( $message->to() ),
			'subject' => $message->subject(),
		];

		// Brevo rejects an empty htmlContent rather than falling back to the
		// text part, so the key is omitted entirely for a plain-text message.
		if ( '' !== trim( $message->html() ) ) {
			$payload['htmlContent'] = $message->html();
		}

		if ( '' !== trim( $message->text() ) ) {
			$payload['textContent'] = $message->text();
		}

		if ( ! isset( $payload['htmlContent'] ) && ! isset( $payload['textContent'] ) ) {
			$payload['textContent'] = ' ';
		}

		if ( [] !== $message->cc() ) {
			$payload['cc'] = $this->recipients( $message->cc() );
		}

		if ( [] !== $message->bcc() ) {
			$payload['bcc'] = $this->recipients( $message->bcc() );
		}

		if ( [] !== $message->reply_to() ) {
			$reply = $message->reply_to()[0];

			$payload['replyTo'] = array_filter(
				[
					'email' => $reply['email'],
					'name'  => $reply['name'],
				]
			);
		}

		$attachments = [];

		foreach ( $message->attachments() as $file ) {
			$attachments[] = [
				'name'    => $file['name'],
				'content' => base64_encode( $file['content'] ),
			];
		}

		if ( [] !== $attachments ) {
			$payload['attachment'] = $attachments;
		}

		$headers = $message->custom_headers();

		if ( [] !== $headers ) {
			$payload['headers'] = $headers;
		}

		return $payload;
	}
}
