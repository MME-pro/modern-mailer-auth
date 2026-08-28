<?php
/**
 * Generic SMTP provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use ModernMailer\Message;
use PHPMailer\PHPMailer\PHPMailer;
// Aliased deliberately. PHP class names are case-insensitive, so importing
// SMTP unaliased collides with the Smtp class declared below - a fatal error
// the moment the file loads, not a subtle one.
use PHPMailer\PHPMailer\SMTP as Smtp_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends over plain SMTP, using a host, a username and a password.
 *
 * The catch-all. Every service in this plugin's list also offers an SMTP
 * endpoint, and so does every service that is not on it - a company relay, a
 * host's own smarthost, a self-run Postfix. One provider therefore covers more
 * real installations than the rest put together.
 *
 * What is given up by choosing it over a native API: per-service error mapping.
 * SMTP hands back a three-digit code and a line of text the operator wrote, so
 * "550 5.7.1 Unauthenticated senders not allowed" is as specific as this can
 * get, where the Graph provider would name the Exchange policy. Delivery is
 * identical; diagnosis is worse. Worth saying on the settings screen rather
 * than letting someone discover it during an incident.
 *
 * ## Why the SMTP class directly, rather than PHPMailer
 *
 * PHPMailer would happily do this whole conversation. Handing it the job means
 * handing it the message to rebuild as well, and the message has already been
 * built - that is the entire premise of this plugin. So the transport is driven
 * one command at a time and the bytes PHPMailer produced go out verbatim.
 *
 * That also fixes Bcc properly. The envelope recipients come from the Message
 * object, so a Bcc address is delivered to without ever appearing in the
 * headers, which is what Bcc is supposed to mean.
 */
class Smtp extends Abstract_Provider {

	/**
	 * Most SMTP servers cap a message somewhere between 10 and 50 MB, and
	 * advertise it in the EHLO SIZE extension. 25 MB is the common floor.
	 */
	private const MAX_MIME_BYTES = 26214400;

	private const TIMEOUT = 20;

	/**
	 * The protocol conversation from the most recent attempt.
	 *
	 * Kept per attempt rather than accumulated: a log entry describes one send,
	 * and a transcript carrying an earlier message's exchange would be actively
	 * misleading about which recipient was rejected.
	 */
	private string $transcript = '';

	/**
	 * What was said on the wire, for the log.
	 *
	 * Not on Provider_Interface. Only a protocol with a conversation has one to
	 * report, and the three API providers have nothing to say here that the
	 * HTTP status does not already say - so the logger asks for this when a
	 * provider offers it rather than every provider implementing an empty
	 * method to satisfy a contract.
	 */
	public function transcript(): string {
		return $this->transcript;
	}

	public function get_label(): string {
		return __( 'SMTP', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'smtp';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Other SMTP', 'modern-mailer-oauth' ),
			'summary'  => __( 'Any server that speaks SMTP - your host\'s relay, a company mail server, or the SMTP endpoint of a service not listed here. Widest compatibility; least specific error messages.', 'modern-mailer-oauth' ),
			'docs'     => 'https://datatracker.ietf.org/doc/html/rfc5321',
			'category' => 'smtp',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		return [
			// Server, username and password share one row: they are the three
			// values a provider hands over together, and they get copied across
			// together.
			new Field(
				key: 'smtp_host',
				label: __( 'Server', 'modern-mailer-oauth' ),
				required: true,
				help: __( 'The hostname your provider gave you.', 'modern-mailer-oauth' ),
				placeholder: 'smtp.example.com',
				width: Field::THIRD
			),
			new Field(
				key: 'smtp_username',
				label: __( 'SMTP username', 'modern-mailer-oauth' ),
				help: __( 'Often the full email address.', 'modern-mailer-oauth' ),
				placeholder: 'you@example.com',
				width: Field::THIRD,
				depends: [
					'field' => 'smtp_auth',
					'value' => 'yes',
				]
			),
			new Field(
				key: 'smtp_password',
				label: __( 'SMTP password', 'modern-mailer-oauth' ),
				type: Field::PASSWORD,
				secret: true,
				help: __( 'With two-factor authentication this is an app password, not the account password.', 'modern-mailer-oauth' ),
				width: Field::THIRD,
				depends: [
					'field' => 'smtp_auth',
					'value' => 'yes',
				]
			),

			// Choosing an encryption also sets the port, because the pairing is
			// fixed and a mismatched port is the single most common way an SMTP
			// connection is misconfigured. The port stays editable for the
			// servers that do something unusual.
			new Field(
				key: 'smtp_encryption',
				label: __( 'Encryption', 'modern-mailer-oauth' ),
				type: Field::RADIO,
				required: true,
				help: __( 'Never choose None on a link you do not control - the password crosses it in the clear.', 'modern-mailer-oauth' ),
				options: [
					'tls'  => __( 'TLS', 'modern-mailer-oauth' ),
					'ssl'  => __( 'SSL', 'modern-mailer-oauth' ),
					'none' => __( 'None', 'modern-mailer-oauth' ),
				],
				default: 'tls',
				width: Field::HALF,
				sets: [
					'tls'  => [ 'smtp_port' => 587 ],
					'ssl'  => [ 'smtp_port' => 465 ],
					'none' => [ 'smtp_port' => 25 ],
				]
			),
			new Field(
				key: 'smtp_port',
				label: __( 'SMTP port', 'modern-mailer-oauth' ),
				type: Field::NUMBER,
				required: true,
				help: __( 'Set automatically from the encryption. Change it only if your provider says otherwise - port 25 is blocked outbound by most hosts.', 'modern-mailer-oauth' ),
				placeholder: '587',
				default: 587,
				width: Field::HALF
			),

			new Field(
				key: 'smtp_auth',
				label: __( 'Authentication', 'modern-mailer-oauth' ),
				type: Field::RADIO,
				required: true,
				help: __( 'Leave this on unless the server accepts unauthenticated mail from this machine, which is rare outside an internal relay.', 'modern-mailer-oauth' ),
				options: [
					'yes' => __( 'On', 'modern-mailer-oauth' ),
					'no'  => __( 'Off', 'modern-mailer-oauth' ),
				],
				default: 'yes',
				width: Field::HALF
			),
		];
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		$message = Message::from_mailer( $raw_mime, $mailer );
		$from    = $message->from();

		$recipients = array_values(
			array_unique( array_column( $message->all_recipients(), 'email' ) )
		);

		if ( [] === $recipients ) {
			return new WP_Error(
				'mmoa_no_recipient',
				__( 'The message has no recipient.', 'modern-mailer-oauth' )
			);
		}

		$smtp = $this->connect();

		if ( is_wp_error( $smtp ) ) {
			return $smtp;
		}

		try {
			if ( ! $smtp->mail( $from['email'] ) ) {
				return $this->smtp_error( $smtp, __( 'The server rejected the sender address.', 'modern-mailer-oauth' ) );
			}

			foreach ( $recipients as $recipient ) {
				// One rejected recipient does not spoil the rest: reporting a
				// partial failure as a total one would have the queue retry a
				// message most of its recipients already received.
				if ( ! $smtp->recipient( $recipient ) ) {
					return $this->smtp_error(
						$smtp,
						sprintf(
							/* translators: %s: recipient email address. */
							__( 'The server rejected the recipient %s.', 'modern-mailer-oauth' ),
							$recipient
						)
					);
				}
			}

			if ( ! $smtp->data( $raw_mime ) ) {
				return $this->smtp_error( $smtp, __( 'The server rejected the message.', 'modern-mailer-oauth' ) );
			}

			return true;
		} finally {
			// QUIT politely even on failure, so the server is not left holding a
			// half-finished transaction until it times out.
			$smtp->quit();
			$smtp->close();
		}
	}

	/**
	 * Open a session and stop, which proves host, port, encryption and
	 * credentials in one go.
	 *
	 * Unlike the API-key providers this is a genuine check rather than a
	 * presence test - almost every way an SMTP connection is misconfigured
	 * shows up before the first message is ever sent.
	 *
	 * @return true|WP_Error
	 */
	public function verify_connection() {
		$smtp = $this->connect();

		if ( is_wp_error( $smtp ) ) {
			return $smtp;
		}

		$smtp->quit();
		$smtp->close();

		return true;
	}

	/**
	 * Connect, secure the link, and authenticate.
	 *
	 * @return Smtp_Client|WP_Error
	 */
	private function connect() {
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$host       = trim( (string) $this->settings->get( 'smtp_host' ) );
		$port       = (int) $this->settings->get( 'smtp_port' );
		$encryption = (string) $this->settings->get( 'smtp_encryption' );
		$username   = trim( (string) $this->settings->get( 'smtp_username' ) );
		$password   = $this->settings->secrets()->get( 'smtp_password' );

		if ( '' === $host ) {
			return new WP_Error(
				'mmoa_provider_incomplete',
				__( 'No SMTP server is configured.', 'modern-mailer-oauth' )
			);
		}

		// Authentication is a deliberate choice rather than an inference from
		// whether a username happens to be filled in: a half-entered credential
		// should be reported, not silently downgraded to an unauthenticated send
		// that the recipient's spam filter rejects later.
		//
		// Checked before dialling, because a configuration contradiction is free
		// to catch and an unreachable host would otherwise mask it behind a
		// connection error that names the wrong problem.
		$authenticate = 'no' !== (string) $this->settings->get( 'smtp_auth' );

		if ( $authenticate && '' === $username ) {
			return new WP_Error(
				'mmoa_provider_incomplete',
				__( 'Authentication is switched on but no SMTP username is set.', 'modern-mailer-oauth' )
			);
		}

		if ( $port <= 0 ) {
			$port = 'ssl' === $encryption ? 465 : 587;
		}

		$smtp = new Smtp_Client();
		$smtp->setTimeout( self::TIMEOUT );

		// PHPMailer's SMTP writes its debug output to stdout by default, which
		// in a web request means straight into the page. Pointed at a buffer
		// instead, so the conversation can be attached to a failed send - the
		// server's own replies are the only thing that explains most SMTP
		// failures, and without them an admin is left guessing at a message
		// like "Recipient address rejected".
		//
		// DEBUG_SERVER, deliberately not higher. At DEBUG_LOWLEVEL and above
		// PHPMailer prints the raw AUTH exchange; below it, the credential is
		// replaced with "[credentials hidden]" by PHPMailer itself.
		$this->transcript = '';
		$smtp->do_debug   = Smtp_Client::DEBUG_SERVER;

		$smtp->Debugoutput = function ( $line, $level ): void {
			unset( $level );

			$this->transcript .= rtrim( (string) $line ) . "\n";
		};

		// Implicit TLS wraps the socket from the first byte, so the scheme is
		// part of the address rather than a later command.
		$address = 'ssl' === $encryption ? 'ssl://' . $host : $host;

		if ( ! $smtp->connect( $address, $port, self::TIMEOUT ) ) {
			return new WP_Error(
				'mmoa_smtp_connect_failed',
				sprintf(
					/* translators: 1: host, 2: port, 3: error detail. */
					__( 'Could not connect to %1$s on port %2$d. %3$s', 'modern-mailer-oauth' ),
					$host,
					$port,
					$this->last_error( $smtp )
				)
			);
		}

		if ( ! $smtp->hello( $this->client_name() ) ) {
			$detail = $this->last_error( $smtp );
			$smtp->close();

			return new WP_Error( 'mmoa_smtp_connect_failed', $detail );
		}

		if ( 'tls' === $encryption ) {
			if ( ! $smtp->startTLS() ) {
				$detail = $this->last_error( $smtp );
				$smtp->close();

				return new WP_Error(
					'mmoa_smtp_tls_failed',
					sprintf(
						/* translators: %s: error detail from the server. */
						__( 'The server refused to start TLS. If it only offers implicit TLS, choose that and use port 465. %s', 'modern-mailer-oauth' ),
						$detail
					)
				);
			}

			// The EHLO before STARTTLS is not trustworthy - it was sent in the
			// clear and the server advertises a different capability list once
			// the link is secured. RFC 3207 requires asking again.
			if ( ! $smtp->hello( $this->client_name() ) ) {
				$detail = $this->last_error( $smtp );
				$smtp->close();

				return new WP_Error( 'mmoa_smtp_connect_failed', $detail );
			}
		}

		if ( $authenticate && ! $smtp->authenticate( $username, $password ) ) {
			$detail = $this->last_error( $smtp );
			$smtp->close();

			return new WP_Error(
				'mmoa_smtp_auth_failed',
				sprintf(
					/* translators: %s: error detail from the server. */
					__( 'The server rejected the username or password. If the account has two-factor authentication, you need an app password rather than the account password. %s', 'modern-mailer-oauth' ),
					$detail
				)
			);
		}

		return $smtp;
	}

	/**
	 * The name given in EHLO.
	 *
	 * Some servers reject a HELO name that is not a resolvable hostname, so the
	 * site's own host is used rather than "localhost".
	 */
	private function client_name(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return '' !== $host ? $host : 'localhost';
	}

	/**
	 * Turn a failed command into an error, splitting transient from permanent.
	 *
	 * SMTP inverts the convention this plugin's HTTP providers use: a 4xx is the
	 * temporary one - greylisting, "try again later", over quota - and a 5xx is
	 * the permanent refusal. Getting that backwards would retry a rejected
	 * recipient for two days and drop a greylisted message on the first attempt,
	 * which is why the reply code is read here rather than reusing the HTTP
	 * status mapping.
	 */
	private function smtp_error( Smtp_Client $smtp, string $context ): WP_Error {
		$reply = $this->last_error( $smtp );
		$code  = (int) ( $smtp->getError()['smtp_code'] ?? 0 );

		$transient = $code >= 400 && $code < 500;

		return new WP_Error(
			$transient ? 'mmoa_smtp_temporary' : 'mmoa_smtp_rejected',
			trim( $context . ' ' . $reply ),
			[ 'smtp_code' => $code ]
		);
	}

	/**
	 * The most useful sentence available about the last failure.
	 */
	private function last_error( Smtp_Client $smtp ): string {
		$error = $smtp->getError();

		// getError() returns exactly these keys. `detail` is the server's own
		// reply line and is usually the only part worth reading - "550 5.7.1
		// Unauthenticated senders not allowed" says more than any wording of
		// ours could.
		$parts = array_filter(
			[
				trim( (string) ( $error['error'] ?? '' ) ),
				trim( (string) ( $error['detail'] ?? '' ) ),
			]
		);

		return implode( ' ', array_unique( $parts ) );
	}

	protected function token_cache_key(): string {
		return 'smtp';
	}

	/**
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	protected function request_token() {
		return new WP_Error(
			'mmoa_not_applicable',
			__( 'SMTP authenticates with a username and password and mints no tokens.', 'modern-mailer-oauth' )
		);
	}
}
