<?php
/**
 * Provider contract.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A transport that can put an RFC 822 message on the wire.
 *
 * The interface is deliberately narrow. Because both Microsoft Graph and the
 * Gmail API accept a raw MIME message, providers never need to know anything
 * about headers, attachments, or encoding - PHPMailer has already done that
 * work by the time send() is called. Adding a third transport means
 * implementing three methods, not re-solving MIME.
 */
interface Provider_Interface {

	/**
	 * Transmit a fully-built MIME message.
	 *
	 * @param string    $raw_mime Complete RFC 822 message.
	 * @param PHPMailer $mailer   Source mailer, for envelope details.
	 * @return true|WP_Error
	 */
	public function send( string $raw_mime, PHPMailer $mailer );

	/**
	 * Check credentials without sending anything.
	 *
	 * A string result means verified, but not everything could be checked -
	 * the string says what was skipped and why. It is reported as a success,
	 * because refusing a connection over a check the transport does not need
	 * in order to send would be worse than saying so plainly.
	 *
	 * @return true|string|WP_Error
	 */
	public function verify_connection();

	/**
	 * Largest message this transport accepts in a single request, in bytes.
	 *
	 * Callers check this before transmitting so an oversized attachment fails
	 * with a message a human can act on, rather than an opaque API rejection.
	 */
	public function get_max_message_bytes(): int;

	/**
	 * Human-readable name, for logs and admin notices.
	 */
	public function get_label(): string;

	/**
	 * Stable identifier, used as the stored provider value and in the REST API.
	 */
	public static function slug(): string;

	/**
	 * The configuration this provider needs, declared rather than hand-built.
	 *
	 * Static because the admin app has to list every provider's fields before
	 * any of them is configured, let alone instantiated.
	 *
	 * @return array<int,\ModernMailer\Field>
	 */
	public static function fields(): array;

	/**
	 * Everything the provider chooser needs to render an entry.
	 *
	 * `coming_soon` is optional and defaults to false. A provider that sets it
	 * is shown in the chooser but cannot be picked - the transport still works,
	 * and a connection already using it stays selectable and editable, because
	 * withdrawing a working transport in an update would stop that site's mail.
	 *
	 * @return array{label:string,summary:string,docs:string,category:string,raw_mime:bool,coming_soon?:bool}
	 */
	public static function describe(): array;
}
