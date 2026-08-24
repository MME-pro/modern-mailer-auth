<?php
/**
 * SendGrid provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through SendGrid's v3 mail/send API.
 *
 * SendGrid accepts no raw MIME at all, so this is one of the providers that has
 * to describe the message field by field. Everything it needs comes off the
 * Message object, which read it from the PHPMailer that built the message -
 * so the text part is the caller's own AltBody rather than something derived by
 * running strip_tags() over the HTML.
 */
class Sendgrid extends Abstract_Api_Provider {

	private const API_URL = 'https://api.sendgrid.com/v3/mail/send';

	/**
	 * SendGrid rejects a total payload over 30 MB, and the attachments inside it
	 * are base64 in JSON. Held well under that.
	 */
	private const MAX_MIME_BYTES = 15728640;

	public function get_label(): string {
		return __( 'SendGrid', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'sendgrid';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'SendGrid', 'modern-mailer-oauth' ),
			'summary'  => __( 'Twilio SendGrid. Create an API key with Mail Send permission only - a full-access key is not needed and should not be used here.', 'modern-mailer-oauth' ),
			'docs'     => 'https://www.twilio.com/docs/sendgrid/api-reference/mail-send',
			'category' => 'api',
			'raw_mime' => false,
		];
	}

	public static function fields(): array {
		return [
			Field::secret(
				'sendgrid_api_key',
				__( 'API key', 'modern-mailer-oauth' ),
				__( 'Settings, API Keys in SendGrid. Restricted Access with Mail Send is enough; SendGrid shows the key only once.', 'modern-mailer-oauth' ),
				'SG.xxxxxxxx'
			),
		];
	}

	protected function endpoint(): string {
		return self::API_URL;
	}

	protected function required_credentials(): array {
		return [ 'sendgrid_api_key' ];
	}

	protected function headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->credential( 'sendgrid_api_key' ) ];
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload( Message $message ): array {
		$personalization = [ 'to' => $this->recipients( $message->to() ) ];

		if ( [] !== $message->cc() ) {
			$personalization['cc'] = $this->recipients( $message->cc() );
		}

		if ( [] !== $message->bcc() ) {
			$personalization['bcc'] = $this->recipients( $message->bcc() );
		}

		$from = $message->from();

		$payload = [
			'personalizations' => [ $personalization ],
			'from'             => array_filter(
				[
					'email' => $from['email'],
					'name'  => $from['name'],
				]
			),
			'subject'          => $message->subject(),
			'content'          => $this->content( $message ),
		];

		if ( [] !== $message->reply_to() ) {
			$reply = $message->reply_to()[0];

			$payload['reply_to'] = array_filter(
				[
					'email' => $reply['email'],
					'name'  => $reply['name'],
				]
			);
		}

		$attachments = $this->attachments( $message );

		if ( [] !== $attachments ) {
			$payload['attachments'] = $attachments;
		}

		$headers = $message->custom_headers();

		if ( [] !== $headers ) {
			$payload['headers'] = $headers;
		}

		return $payload;
	}

	/**
	 * SendGrid requires text/plain before text/html when both are present, and
	 * rejects an empty content string outright.
	 *
	 * @return array<int,array{type:string,value:string}>
	 */
	private function content( Message $message ): array {
		$text = $message->text();
		$html = $message->html();

		$content = [];

		if ( '' !== trim( $text ) ) {
			$content[] = [
				'type'  => 'text/plain',
				'value' => $text,
			];
		}

		if ( '' !== trim( $html ) ) {
			$content[] = [
				'type'  => 'text/html',
				'value' => $html,
			];
		}

		if ( [] === $content ) {
			// A body-less message is legitimate - a subject-only notification -
			// but SendGrid will not accept one, so send a single space.
			$content[] = [
				'type'  => 'text/plain',
				'value' => ' ',
			];
		}

		return $content;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function attachments( Message $message ): array {
		$out = [];

		foreach ( $message->attachments() as $file ) {
			$one = [
				'content'     => base64_encode( $file['content'] ),
				'filename'    => $file['name'],
				'type'        => $file['type'],
				'disposition' => $file['inline'] ? 'inline' : 'attachment',
			];

			// content_id is what keeps a cid: reference in the HTML pointing at
			// the right part. Omitting it turns every inline image into a
			// broken-image icon, which is the classic symptom of a mailer that
			// rebuilt the message without understanding it.
			if ( $file['inline'] && '' !== $file['cid'] ) {
				$one['content_id'] = $file['cid'];
			}

			$out[] = $one;
		}

		return $out;
	}
}
