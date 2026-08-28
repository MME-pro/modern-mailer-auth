<?php
/**
 * SMTP2GO provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through SMTP2GO's /email/send API.
 *
 * Named for SMTP but used here over HTTP, which is the better path on a shared
 * host: outbound SMTP ports are frequently blocked, and an HTTPS call is not.
 */
class Smtp2go extends Abstract_Api_Provider {

	private const API_URL = 'https://api.smtp2go.com/v3/email/send';

	/** SMTP2GO caps a request at 50 MB; held well under it. */
	private const MAX_MIME_BYTES = 20971520;

	public function get_label(): string {
		return __( 'SMTP2GO', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'smtp2go';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'SMTP2GO', 'modern-mailer-oauth' ),
			'summary'  => __( 'Used over HTTPS rather than SMTP, which matters on a shared host: outbound mail ports are often blocked and an API call is not.', 'modern-mailer-oauth' ),
			'docs'     => 'https://apidoc.smtp2go.com/documentation/#/POST%20/email/send',
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
				'smtp2go_api_key',
				__( 'API key', 'modern-mailer-oauth' ),
				__( 'From Settings, API Keys in SMTP2GO. It needs the Email Send permission.', 'modern-mailer-oauth' ),
				'api-...'
			),
		];
	}

	protected function endpoint(): string {
		return self::API_URL;
	}

	protected function required_credentials(): array {
		return [ 'smtp2go_api_key' ];
	}

	protected function headers(): array {
		return [
			'X-Smtp2go-Api-Key' => $this->credential( 'smtp2go_api_key' ),
			'accept'            => 'application/json',
		];
	}

	/**
	 * Addresses in the "Name <email>" form SMTP2GO expects.
	 *
	 * @param array<int,array{name:string,email:string}> $addresses Addresses.
	 * @return array<int,string>
	 */
	private function formatted( array $addresses ): array {
		return array_map(
			static fn( array $addr ): string => '' !== $addr['name']
				? sprintf( '%s <%s>', $addr['name'], $addr['email'] )
				: $addr['email'],
			$addresses
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload( Message $message ): array {
		$from = $message->from();

		$payload = [
			'sender'  => '' !== $from['name']
				? sprintf( '%s <%s>', $from['name'], $from['email'] )
				: $from['email'],
			'to'      => $this->formatted( $message->to() ),
			'subject' => $message->subject(),
		];

		if ( '' !== trim( $message->html() ) ) {
			$payload['html_body'] = $message->html();
		}

		if ( '' !== trim( $message->text() ) ) {
			$payload['text_body'] = $message->text();
		}

		if ( ! isset( $payload['html_body'] ) && ! isset( $payload['text_body'] ) ) {
			$payload['text_body'] = ' ';
		}

		if ( [] !== $message->cc() ) {
			$payload['cc'] = $this->formatted( $message->cc() );
		}

		if ( [] !== $message->bcc() ) {
			$payload['bcc'] = $this->formatted( $message->bcc() );
		}

		$attachments = [];

		foreach ( $message->attachments() as $file ) {
			$attachments[] = [
				'filename' => $file['name'],
				'fileblob' => base64_encode( $file['content'] ),
				'mimetype' => $file['type'],
			];
		}

		if ( [] !== $attachments ) {
			$payload['attachments'] = $attachments;
		}

		$headers = $message->custom_headers();

		if ( [] !== $message->reply_to() ) {
			$headers['Reply-To'] = $message->reply_to()[0]['email'];
		}

		if ( [] !== $headers ) {
			$payload['custom_headers'] = array_map(
				static fn( string $name, string $value ): array => [
					'header' => $name,
					'value'  => $value,
				],
				array_keys( $headers ),
				array_values( $headers )
			);
		}

		return $payload;
	}
}
