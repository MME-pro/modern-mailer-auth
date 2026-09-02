<?php
/**
 * Zoho Mail provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Zoho Mail, over its own SMTP servers.
 *
 * Everything here is the generic SMTP transport - the conversation, the Bcc
 * handling, the transcript, the 4xx/5xx split that decides whether the queue
 * retries. What this class adds is knowing the answers.
 *
 * That is the reason it exists rather than telling people to pick "Other SMTP"
 * and type smtp.zoho.com. Zoho runs a separate mail estate per data centre, and
 * an account created in the European one cannot authenticate against the
 * American host at all. The failure is a flat authentication rejection, which
 * reads as a wrong password - so the usual response is to reset the password,
 * which does not help. Asking which region the account is in, once, turns that
 * into a dropdown.
 *
 * ## What still has to be typed
 *
 * The address and an application-specific password. Zoho requires the second
 * whenever two-factor authentication is on and refuses the account password
 * outright; the parent already says so when authentication fails.
 *
 * ## The From address
 *
 * Zoho only accepts a From address the authenticated account owns - its own
 * address, or an alias or send-mail-as address confirmed in Zoho Mail. Anything
 * else is rejected at MAIL FROM rather than silently rewritten, which is the
 * better of the two behaviours: it fails loudly and the log says why.
 */
class Zoho extends Smtp {

	/**
	 * Zoho caps a message at 20 MB on its lower plans and higher on the paid
	 * ones. The floor is what is checked, so a message that would be refused is
	 * refused here - with a sentence explaining it - rather than after the whole
	 * thing has been uploaded.
	 */
	private const MAX_MIME_BYTES = 20971520;

	/**
	 * Data centre => SMTP host.
	 *
	 * Zoho publishes these per region and they are not interchangeable: an
	 * account exists in exactly one of them.
	 */
	private const HOSTS = [
		'com' => 'smtp.zoho.com',
		'eu'  => 'smtp.zoho.eu',
		'in'  => 'smtp.zoho.in',
		'au'  => 'smtp.zoho.com.au',
		'jp'  => 'smtp.zoho.jp',
		'ca'  => 'smtp.zohocloud.ca',
		'sa'  => 'smtp.zoho.sa',
	];

	public function get_label(): string {
		return __( 'Zoho Mail', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'zoho';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Zoho Mail', 'modern-mailer-oauth' ),
			'summary'  => __( 'A Zoho mailbox, over Zoho\'s own SMTP servers. Needs an application-specific password rather than the account password, and the region the account was created in - Zoho runs a separate estate per data centre and they do not accept each other\'s logins.', 'modern-mailer-oauth' ),
			'docs'     => 'https://www.zoho.com/mail/help/zoho-smtp.html',
			'category' => 'smtp',
			'raw_mime' => true,
		];
	}

	/**
	 * Four values, and three of them are the account.
	 *
	 * The server, the port and the authentication mode are absent on purpose.
	 * They are not decisions - Zoho has one answer for each - and every field on
	 * this form that is not a decision is another way to misconfigure it.
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'zoho_region',
				label: __( 'Region', 'modern-mailer-oauth' ),
				type: Field::SELECT,
				required: true,
				help: __( 'The data centre the account belongs to. It is the domain you sign in on - zoho.eu, zoho.in, and so on.', 'modern-mailer-oauth' ),
				options: [
					'com' => __( 'United States (zoho.com)', 'modern-mailer-oauth' ),
					'eu'  => __( 'Europe (zoho.eu)', 'modern-mailer-oauth' ),
					'in'  => __( 'India (zoho.in)', 'modern-mailer-oauth' ),
					'au'  => __( 'Australia (zoho.com.au)', 'modern-mailer-oauth' ),
					'jp'  => __( 'Japan (zoho.jp)', 'modern-mailer-oauth' ),
					'ca'  => __( 'Canada (zohocloud.ca)', 'modern-mailer-oauth' ),
					'sa'  => __( 'Saudi Arabia (zoho.sa)', 'modern-mailer-oauth' ),
				],
				default: 'com',
				width: Field::THIRD
			),

			// The same two storage keys the generic SMTP provider uses, so
			// switching a connection between Zoho Mail and Other SMTP carries
			// the credential across instead of asking for it twice.
			new Field(
				key: 'smtp_username',
				label: __( 'Zoho address', 'modern-mailer-oauth' ),
				type: Field::EMAIL,
				required: true,
				help: __( 'The full mailbox address you sign in with.', 'modern-mailer-oauth' ),
				placeholder: 'you@yourdomain.com',
				width: Field::THIRD
			),
			new Field(
				key: 'smtp_password',
				label: __( 'App password', 'modern-mailer-oauth' ),
				type: Field::PASSWORD,
				secret: true,
				required: true,
				help: __( 'Generate one under Security, App Passwords in your Zoho account. The account password is refused once two-factor authentication is on.', 'modern-mailer-oauth' ),
				width: Field::THIRD
			),

			// Offered because one of the two ports is blocked outbound on a
			// noticeable share of shared hosting, and which one varies. Both are
			// equally valid at Zoho, so this is a fact about the server
			// WordPress runs on rather than about the mailbox.
			//
			// Its own key, unlike the credentials above. Sharing smtp_encryption
			// would mean sharing its default too - the registry keys fields by
			// name and the last provider to declare one wins - so an unconfigured
			// Zoho connection would quietly inherit the generic provider's TLS
			// default instead of the SSL this form says it has.
			new Field(
				key: 'zoho_encryption',
				label: __( 'Encryption', 'modern-mailer-oauth' ),
				type: Field::RADIO,
				required: true,
				help: __( 'SSL uses port 465 and TLS uses port 587. Both work at Zoho - switch only if your host blocks the port.', 'modern-mailer-oauth' ),
				options: [
					'ssl' => __( 'SSL (port 465)', 'modern-mailer-oauth' ),
					'tls' => __( 'TLS (port 587)', 'modern-mailer-oauth' ),
				],
				default: 'ssl',
				width: Field::HALF
			),
		];
	}

	/**
	 * The host for the chosen region, falling back to the American one.
	 *
	 * A stored region outside the table means the option was written by
	 * something other than this form. Defaulting is better than failing:
	 * zoho.com holds the large majority of accounts, and a connection that
	 * dials and reports a real authentication error is more useful than one
	 * that refuses to dial at all.
	 */
	protected function host(): string {
		$region = (string) $this->settings->get( 'zoho_region' );

		return self::HOSTS[ $region ] ?? self::HOSTS['com'];
	}

	/**
	 * Derived from the encryption rather than stored.
	 *
	 * The generic provider leaves the port editable because an unknown server
	 * may do something unusual. Zoho does not, so there is nothing to edit - and
	 * an smtp_port left behind by a connection that used to be a different
	 * provider must not be able to point this one at the wrong port.
	 */
	protected function port(): int {
		return 'tls' === $this->encryption() ? 587 : 465;
	}

	protected function encryption(): string {
		return 'tls' === (string) $this->settings->get( 'zoho_encryption' ) ? 'tls' : 'ssl';
	}

	/**
	 * Zoho has no anonymous relay. Ignoring a stored smtp_auth of "no" - which a
	 * connection switched over from the generic provider can be carrying - saves
	 * an unauthenticated attempt that could only ever be rejected.
	 */
	protected function authenticates(): bool {
		return true;
	}

	protected function token_cache_key(): string {
		return 'zoho';
	}
}
