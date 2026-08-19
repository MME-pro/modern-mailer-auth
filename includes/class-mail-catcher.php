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
}
