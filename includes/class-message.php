<?php
/**
 * One built message, in both the shapes providers need.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * A message PHPMailer has already built, readable as raw MIME or as parts.
 *
 * Providers split into two families and there is no avoiding it:
 *
 * - Microsoft Graph, Gmail, Mailgun's /messages.mime, Amazon SES SendRawEmail
 *   and plain SMTP all accept a complete RFC 822 message. Handing them the
 *   bytes PHPMailer produced is exactly right, and is why attachments, inline
 *   cid: images and custom headers work here without any provider
 *   understanding them.
 * - SendGrid, Postmark, Brevo, Mailjet, SparkPost and the rest accept only a
 *   JSON object with named fields. They cannot be given MIME at all.
 *
 * The second family is where other mailers accumulate their long tail of
 * "the attachment vanished" and "the plain-text part is mangled" reports,
 * because the usual approach is to re-derive those fields - re-parsing the
 * message, or running strip_tags() over the HTML to invent a text part.
 *
 * This class does neither. Every value is read from the PHPMailer instance
 * that built the message, which is the same object core populated from the
 * wp_mail() arguments and filters. The two representations therefore describe
 * one message rather than two guesses at it.
 */
class Message {

	/** @var array<int,array{name:string,email:string}> */
	private array $to;

	/** @var array<int,array{name:string,email:string}> */
	private array $cc;

	/** @var array<int,array{name:string,email:string}> */
	private array $bcc;

	/** @var array<int,array{name:string,email:string}> */
	private array $reply_to;

	private function __construct(
		private string $raw_mime,
		private PHPMailer $mailer
	) {
		$this->to       = $this->addresses( $mailer->getToAddresses() );
		$this->cc       = $this->addresses( $mailer->getCcAddresses() );
		$this->bcc      = $this->addresses( $mailer->getBccAddresses() );
		$this->reply_to = $this->addresses( array_values( $mailer->getReplyToAddresses() ) );
	}

	/**
	 * Build from a PHPMailer that has already run preSend().
	 */
	public static function from_mailer( string $raw_mime, PHPMailer $mailer ): self {
		return new self( $raw_mime, $mailer );
	}

	/**
	 * The complete RFC 822 message, exactly as PHPMailer produced it.
	 */
	public function raw(): string {
		return $this->raw_mime;
	}

	public function bytes(): int {
		return strlen( $this->raw_mime );
	}

	public function subject(): string {
		return (string) $this->mailer->Subject;
	}

	/**
	 * @return array{name:string,email:string}
	 */
	public function from(): array {
		return [
			'name'  => (string) $this->mailer->FromName,
			'email' => (string) $this->mailer->From,
		];
	}

	/** @return array<int,array{name:string,email:string}> */
	public function to(): array {
		return $this->to;
	}

	/** @return array<int,array{name:string,email:string}> */
	public function cc(): array {
		return $this->cc;
	}

	/** @return array<int,array{name:string,email:string}> */
	public function bcc(): array {
		return $this->bcc;
	}

	/** @return array<int,array{name:string,email:string}> */
	public function reply_to(): array {
		return $this->reply_to;
	}

	/** Every recipient, for logging and for size checks. */
	public function all_recipients(): array {
		return array_merge( $this->to, $this->cc, $this->bcc );
	}

	public function is_html(): bool {
		return 'text/html' === $this->mailer->ContentType;
	}

	/**
	 * The HTML body, or '' for a plain-text message.
	 */
	public function html(): string {
		return $this->is_html() ? (string) $this->mailer->Body : '';
	}

	/**
	 * The plain-text body.
	 *
	 * For a plain-text message this is simply the body. For an HTML message it
	 * is PHPMailer's own AltBody when the sender supplied one - and that is the
	 * common case, because core and most plugins set it.
	 *
	 * Only when there is no AltBody do we derive one, and then via PHPMailer's
	 * html2text() rather than strip_tags(). The difference is not cosmetic:
	 * strip_tags() concatenates block elements into a single run-on line,
	 * silently keeps the contents of <style> and <script>, and drops every
	 * link target, so the resulting text part is close to unreadable. This is a
	 * real and common defect in other mailers' structured-API providers.
	 */
	public function text(): string {
		if ( ! $this->is_html() ) {
			return (string) $this->mailer->Body;
		}

		$alt = trim( (string) $this->mailer->AltBody );

		if ( '' !== $alt ) {
			return $alt;
		}

		return $this->mailer->html2text( (string) $this->mailer->Body, false );
	}

	/**
	 * Attachments, with their bytes read.
	 *
	 * PHPMailer stores an attachment as a positional array; the meaning of each
	 * index is stable but entirely undocumented at the call site, so it is
	 * unpacked into names here once instead of at every provider.
	 *
	 * An attachment whose file cannot be read is skipped rather than failing
	 * the send. The alternative - refusing to deliver a receipt because a logo
	 * has been moved - is worse than delivering it without the logo, and the
	 * raw-MIME providers behave the same way because PHPMailer made the same
	 * decision when it built the message.
	 *
	 * @return array<int,array{name:string,type:string,content:string,cid:string,inline:bool}>
	 */
	public function attachments(): array {
		$out = [];

		foreach ( $this->mailer->getAttachments() as $item ) {
			$body      = (string) ( $item[0] ?? '' );
			$name      = (string) ( $item[2] ?? '' );
			$type      = (string) ( $item[4] ?? 'application/octet-stream' );
			$is_string = (bool) ( $item[5] ?? false );
			$dispo     = (string) ( $item[6] ?? 'attachment' );
			$cid       = (string) ( $item[7] ?? '' );

			if ( $is_string ) {
				$content = $body;
			} else {
				if ( ! is_readable( $body ) ) {
					continue;
				}

				$content = (string) file_get_contents( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}

			$out[] = [
				'name'    => '' !== $name ? $name : basename( $body ),
				'type'    => $type,
				'content' => $content,
				'cid'     => $cid,
				'inline'  => 'inline' === $dispo,
			];
		}

		return $out;
	}

	public function has_attachments(): bool {
		return [] !== $this->mailer->getAttachments();
	}

	/**
	 * Headers added by the caller, excluding the ones every transport sets
	 * for itself.
	 *
	 * Passing Subject, From, To or MIME-Version through to a structured API
	 * either duplicates a field the API already owns or is rejected outright,
	 * so they are dropped here rather than in each provider.
	 *
	 * @return array<string,string>
	 */
	public function custom_headers(): array {
		$reserved = [
			'subject',
			'from',
			'to',
			'cc',
			'bcc',
			'reply-to',
			'content-type',
			'mime-version',
			'content-transfer-encoding',
			'date',
			'message-id',
		];

		$out = [];

		foreach ( $this->mailer->getCustomHeaders() as $header ) {
			$name  = trim( (string) ( $header[0] ?? '' ) );
			$value = trim( (string) ( $header[1] ?? '' ) );

			if ( '' === $name || in_array( strtolower( $name ), $reserved, true ) ) {
				continue;
			}

			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * The PHPMailer that built this, for the few places that need it.
	 */
	public function mailer(): PHPMailer {
		return $this->mailer;
	}

	/**
	 * Normalize PHPMailer's [ email, name ] pairs.
	 *
	 * @param array<int,array<int,string>> $list Raw address list.
	 * @return array<int,array{name:string,email:string}>
	 */
	private function addresses( array $list ): array {
		$out = [];

		foreach ( $list as $addr ) {
			$email = trim( (string) ( $addr[0] ?? '' ) );

			if ( '' === $email ) {
				continue;
			}

			$out[] = [
				'name'  => trim( (string) ( $addr[1] ?? '' ) ),
				'email' => $email,
			];
		}

		return $out;
	}
}
