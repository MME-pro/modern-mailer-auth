<?php
/**
 * PHPMailer subclass that diverts sending to an API provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the message with PHPMailer, then hands the raw MIME to a provider.
 *
 * Both target APIs accept a complete RFC 822 message, so there is no reason to
 * reassemble one field by field into a JSON object - which is where mailers
 * that do reassemble it accumulate their long tail of "the attachment vanished"
 * and "the inline image is broken" reports. preSend() runs the exact same
 * message construction core would have used, and we take the bytes.
 */
class Mail_Catcher extends PHPMailer {

	private ?Dispatcher $dispatcher = null;

	public function set_dispatcher( Dispatcher $dispatcher ): void {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Build the message and dispatch it, instead of transmitting it ourselves.
	 *
	 * @throws Exception When the provider reports a failure and exceptions are on.
	 */
	public function send(): bool {
		if ( null === $this->dispatcher ) {
			return parent::send();
		}

		try {
			// Pin the backend to mail() before building.
			//
			// This is not cosmetic. PHPMailer only writes a Bcc: header into
			// the message for the mail()/sendmail backends - for SMTP it omits
			// it, because there the envelope carries the recipients. We are
			// handing raw MIME to an API that derives its recipients from the
			// headers, so if another plugin has called isSMTP() during
			// phpmailer_init, every Bcc recipient would be silently dropped.
			// Forcing the backend here makes the generated MIME deterministic
			// no matter what else is on the site.
			$this->isMail();

			// Send HTML as HTML, even when the caller forgot to say so.
			$this->maybe_promote_to_html();

			if ( ! $this->preSend() ) {
				return false;
			}

			$result = $this->dispatcher->dispatch( $this->getSentMIMEMessage(), $this );

			if ( is_wp_error( $result ) ) {
				// Throwing is deliberate: core's wp_mail() catches this, fires
				// wp_mail_failed with the message, and returns false. That is
				// how a caller finds out the send failed - which is the whole
				// problem with mailers that fail quietly.
				throw new Exception( $result->get_error_message() );
			}

			return true;
		} catch ( Exception $e ) {
			$this->mailHeader = '';
			$this->setError( $e->getMessage() );

			if ( $this->exceptions ) {
				throw $e;
			}

			return false;
		}
	}

	/**
	 * Tags that mean a body is markup rather than prose.
	 *
	 * Deliberately a closed list of the elements that actually appear in mail,
	 * rather than a general "looks like a tag" pattern. `<user@example.com>` in
	 * a plain-text body matches the general shape of a tag and must not be
	 * mistaken for one.
	 */
	private const HTML_OPEN = '~<(?:a|b|blockquote|body|center|div|em|font|h[1-6]|html|i|li|ol|p|pre|small|span|strong|sub|sup|table|tbody|td|th|thead|tr|u|ul)\b[^>]*>~i';

	/** The closing halves of the same list. */
	private const HTML_CLOSE = '~</(?:a|b|blockquote|body|center|div|em|font|h[1-6]|html|i|li|ol|p|pre|small|span|strong|sub|sup|table|tbody|td|th|thead|tr|u|ul)\s*>~i';

	/** The three void elements common enough in mail to count on their own. */
	private const HTML_VOID = '~<(?:br|hr|img)\b[^>]*/?>~i';

	/**
	 * Switch a plain-text message to HTML when its body plainly is HTML.
	 *
	 * wp_mail() sends text/plain unless the caller passes a Content-Type
	 * header, and a great many callers do not - theme functions, form plugins,
	 * WooCommerce templates rendered by hand, anything that builds a body with
	 * a heredoc. The markup then arrives in the inbox as visible tags. Nothing
	 * about that is this plugin's doing, but this is the last place that sees
	 * the message before it is built, so it is the only place that can fix it.
	 *
	 * The detection is deliberately narrow. A body qualifies only when it holds
	 * an opening tag from a closed list of elements that appear in real mail,
	 * *and* a matching closing tag or one of <br>/<hr>/<img>. Prose does not do
	 * that by accident, and the two checks are each a single linear scan.
	 *
	 * An explicit Content-Type is never overridden - a caller that asked for
	 * text/plain and a caller that said nothing look identical by the time the
	 * message reaches here, so the escape hatch is the filter below rather than
	 * a guess about intent.
	 *
	 * A text alternative is generated at the same time. Having decided the body
	 * is HTML, leaving the message with no readable plain part would trade one
	 * defect for a smaller one: text-only clients and several spam filters both
	 * want that part to exist.
	 */
	private function maybe_promote_to_html(): void {
		// The header parser hands over a bare type, but a filter on
		// wp_mail_content_type can set anything, so the parameters are cut off
		// before comparing.
		$type = strtolower( trim( (string) strtok( (string) $this->ContentType, ';' ) ) );

		if ( static::CONTENT_TYPE_PLAINTEXT !== $type ) {
			return;
		}

		$body = (string) $this->Body;

		if ( ! preg_match( self::HTML_OPEN, $body ) ) {
			return;
		}

		if ( ! preg_match( self::HTML_CLOSE, $body ) && ! preg_match( self::HTML_VOID, $body ) ) {
			return;
		}

		/**
		 * Filters whether a plain-text message whose body looks like HTML is
		 * sent as HTML.
		 *
		 * Return false to leave the message exactly as the caller built it.
		 *
		 * @param bool         $promote Whether to switch the message to HTML.
		 * @param string       $body    The message body being judged.
		 * @param Mail_Catcher $mailer  The message itself.
		 */
		if ( ! apply_filters( 'mmoa_promote_html', true, $body, $this ) ) {
			return;
		}

		$this->isHTML( true );

		if ( '' === trim( (string) $this->AltBody ) ) {
			$this->AltBody = $this->text_from_html( $body );
		}
	}

	/**
	 * A readable plain-text rendering of an HTML body.
	 *
	 * PHPMailer's own html2text() is strip_tags() with the head and style
	 * elements removed first, which collapses an entire email into one
	 * unbroken line and drops every link target. That is fine as a last resort
	 * and poor as the text part of a message we chose to make multipart, so the
	 * structure the markup implies is turned into line breaks first, and link
	 * targets are kept.
	 */
	private function text_from_html( string $html ): string {
		$charset = '' !== trim( (string) $this->CharSet ) ? $this->CharSet : 'UTF-8';

		// Elements whose contents are never prose. Removed wholesale, or their
		// CSS ends up in the text part.
		$text = (string) preg_replace( '~<(head|title|style|script)\b[^>]*>.*?</\1\s*>~is', '', $html );

		// Keep the link target, which is usually the entire point of the mail,
		// unless the anchor text already is the URL.
		$text = (string) preg_replace_callback(
			'~<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>~is',
			static function ( array $m ): string {
				$href  = trim( $m[2] );
				$label = trim( strip_tags( $m[3] ) );

				if ( '' === $href || 0 === strpos( $href, '#' ) || false !== strpos( $label, $href ) ) {
					return $m[3];
				}

				// Parentheses rather than the conventional angle brackets: the
				// strip_tags() below would eat <https://example.com/> whole.
				return '' === $label ? $href : $label . ' (' . $href . ')';
			},
			$text
		);

		$text = (string) preg_replace( '~<br\b[^>]*/?>~i', "\n", $text );
		$text = (string) preg_replace( '~<hr\b[^>]*/?>~i', "\n---\n", $text );
		// Cells are separated within their row, rows and blocks by line breaks.
		// Without the first of these a table-based email - which is to say most
		// of them - collapses into one unreadable run of words.
		$text = (string) preg_replace( '~</(?:td|th)\s*>~i', ' ', $text );
		$text = (string) preg_replace( '~</(?:p|div|tr|li|h[1-6]|blockquote|table|ul|ol|pre)\s*>~i', "\n\n", $text );

		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES, $charset );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		// The substitutions above leave runs of blank lines wherever the markup
		// nested, which is everywhere in a table-based email.
		$text = (string) preg_replace( '~[ \t]+~', ' ', $text );
		$text = (string) preg_replace( '~ ?\n ?~', "\n", $text );
		$text = (string) preg_replace( '~\n{3,}~', "\n\n", $text );

		return trim( $text );
	}
}
