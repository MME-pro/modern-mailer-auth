<?php
/**
 * Postmark provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Postmark's /email endpoint.
 *
 * Structured only, like SendGrid. Postmark differs in two ways that matter:
 * recipients are comma-separated strings rather than arrays of objects, and it
 * insists on knowing which message stream to use - a transactional receipt sent
 * down a broadcast stream is rejected rather than quietly reclassified.
 */
class Postmark extends Abstract_Api_Provider {

	private const API_URL = 'https://api.postmarkapp.com/email';

	/** Postmark caps a message at 10 MB including base64 attachments. */
	private const MAX_MIME_BYTES = 7340032;

	public function get_label(): string {
		return __( 'Postmark', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'postmark';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Postmark', 'modern-mailer-oauth' ),
			'summary'  => __( 'Transactional-only by design, which is why its delivery rates are what they are. The From address must be a verified Sender Signature or on a verified domain.', 'modern-mailer-oauth' ),
			'docs'     => 'https://postmarkapp.com/developer/api/email-api',
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
				'postmark_token',
				__( 'Server API token', 'modern-mailer-oauth' ),
				__( 'The Server token, not the Account token. Found under API Tokens on the server you want to send from.', 'modern-mailer-oauth' )
			),
			new Field(
				key: 'postmark_stream',
				label: __( 'Message stream', 'modern-mailer-oauth' ),
				help: __( 'Leave as outbound unless you have created another stream. Postmark rejects a transactional message sent down a broadcast stream.', 'modern-mailer-oauth' ),
				placeholder: 'outbound',
				default: 'outbound'
			),
		];
	}

	protected function endpoint(): string {
		return self::API_URL;
	}

	protected function required_credentials(): array {
		return [ 'postmark_token' ];
	}

	protected function headers(): array {
		return [
			'X-Postmark-Server-Token' => $this->credential( 'postmark_token' ),
			'Accept'                  => 'application/json',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload( Message $message ): array {
		$from   = $message->from();
		$stream = $this->credential( 'postmark_stream' );

		$payload = [
			'From'          => '' !== $from['name']
				? sprintf( '"%s" <%s>', str_replace( '"', '', $from['name'] ), $from['email'] )
				: $from['email'],
			'To'            => $this->joined( $message->to() ),
			'Subject'       => $message->subject(),
			'MessageStream' => '' !== $stream ? $stream : 'outbound',
		];

		if ( [] !== $message->cc() ) {
			$payload['Cc'] = $this->joined( $message->cc() );
		}

		if ( [] !== $message->bcc() ) {
			$payload['Bcc'] = $this->joined( $message->bcc() );
		}

		if ( [] !== $message->reply_to() ) {
			$payload['ReplyTo'] = $this->joined( $message->reply_to() );
		}

		$html = $message->html();
		$text = $message->text();

		if ( '' !== trim( $html ) ) {
			$payload['HtmlBody'] = $html;
		}

		if ( '' !== trim( $text ) ) {
			$payload['TextBody'] = $text;
		}

		if ( ! isset( $payload['HtmlBody'], $payload['TextBody'] ) && ! isset( $payload['HtmlBody'] ) && ! isset( $payload['TextBody'] ) ) {
			$payload['TextBody'] = ' ';
		}

		$attachments = $this->attachments( $message );

		if ( [] !== $attachments ) {
			$payload['Attachments'] = $attachments;
		}

		$headers = $message->custom_headers();

		if ( [] !== $headers ) {
			$payload['Headers'] = array_map(
				static fn( string $name, string $value ): array => [
					'Name'  => $name,
					'Value' => $value,
				],
				array_keys( $headers ),
				array_values( $headers )
			);
		}

		return $payload;
	}

	/**
	 * Postmark takes recipients as one comma-separated header-style string.
	 *
	 * @param array<int,array{name:string,email:string}> $addresses Addresses.
	 */
	private function joined( array $addresses ): string {
		$parts = [];

		foreach ( $addresses as $addr ) {
			$parts[] = '' !== $addr['name']
				? sprintf( '"%s" <%s>', str_replace( '"', '', $addr['name'] ), $addr['email'] )
				: $addr['email'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function attachments( Message $message ): array {
		$out = [];

		foreach ( $message->attachments() as $file ) {
			$one = [
				'Name'        => $file['name'],
				'Content'     => base64_encode( $file['content'] ),
				'ContentType' => $file['type'],
			];

			// Postmark signals an inline part by the presence of ContentID, and
			// wants it bare rather than wrapped in angle brackets.
			if ( $file['inline'] && '' !== $file['cid'] ) {
				$one['ContentID'] = 'cid:' . $file['cid'];
			}

			$out[] = $one;
		}

		return $out;
	}

	/**
	 * Postmark returns a numeric ErrorCode that is far more specific than the
	 * HTTP status, and several of its codes are things an admin can fix in a
	 * minute if told plainly what they are.
	 */
	protected function map_error( int $status, string $body ): WP_Error {
		$data = json_decode( $body, true );
		$code = is_array( $data ) ? (int) ( $data['ErrorCode'] ?? 0 ) : 0;

		$hints = [
			10  => __( 'Postmark rejected the server API token. Check that it is the Server token and not the Account token.', 'modern-mailer-oauth' ),
			300 => __( 'Postmark rejected the message as invalid. Most often the From address is not a verified Sender Signature.', 'modern-mailer-oauth' ),
			400 => __( 'This Postmark server is not activated for sending. Approve the account or request production access.', 'modern-mailer-oauth' ),
			401 => __( 'The Postmark account is pending approval and can only send to verified addresses.', 'modern-mailer-oauth' ),
			402 => __( 'The From address is not a verified Sender Signature in Postmark.', 'modern-mailer-oauth' ),
			406 => __( 'The recipient is on this Postmark server\'s suppression list, so it refused to send. Remove the suppression if the address is genuinely valid.', 'modern-mailer-oauth' ),
			429 => __( 'Postmark is rate limiting this account. The message was not sent; it will be retried.', 'modern-mailer-oauth' ),
			605 => __( 'The message stream named here does not exist on this Postmark server.', 'modern-mailer-oauth' ),
		];

		if ( isset( $hints[ $code ] ) ) {
			return new WP_Error(
				'mmoa_postmark_' . $code,
				$hints[ $code ],
				[ 'status' => $status ]
			);
		}

		return parent::map_error( $status, $body );
	}
}
