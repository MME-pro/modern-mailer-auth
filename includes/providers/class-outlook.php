<?php
	/**
 * Outlook and Microsoft 365, connected in one click.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Broker;
use ModernMailer\Site_Identity;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

	/**
 * Sends as the signed-in user through Microsoft Graph.
 *
 * This is the delegated counterpart to Graph, and the two are not
 * interchangeable:
 *
 * - Graph is app-only. An administrator registers an Azure application, grants
 *   Mail.Send application permission, and the site mints tokens from a client
 *   credential. Nothing expires except the secret, it can send as any mailbox
 *   in the tenant, and it needs somebody with the authority to consent.
 * - This connects one person's mailbox by signing in as them, and sends only
 *   as that account. It holds a refresh token, so it inherits the mortality
 *   that comes with one - but it needs no Azure registration whatsoever, works
 *   for personal Outlook accounts that have no tenant at all, and takes about
 *   twenty seconds to set up.
 *
 * Neither replaces the other. A business sending from a shared address wants
 * Graph; someone connecting their own Outlook mailbox wants this.
 *
 * The credential always comes from the setup service here. A site that would
 * rather not depend on that service already has the better Microsoft option in
 * Graph, which depends on nothing of ours - so a second hand-registered
 * delegated path would add a third way to do the same job without adding a
 * capability, and every one of them is a path that has to keep working.
 */
class Outlook extends Abstract_Provider {

	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Graph caps a sendMail payload at 4 MB, and base64 inflates by four
	 * thirds, so the MIME message underneath has to be smaller than that.
	 */
	private const MAX_MIME_BYTES = 3145728;

	public function get_label(): string {
		return __( 'Outlook', 'modern-mailer-oauth' );
	}

	public static function slug(): string {
		return 'outlook';
	}

	/**
	 * Not listed in the chooser: reached through its merged tile instead.
	 *
	 * Still registered, so a connection storing this slug stays constructible -
	 * which matters both for sites that have not run the migration yet and for
	 * anything setting the slug directly through the mmoa_providers filter.
	 */
	public static function is_listed(): bool {
		return false;
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Outlook', 'modern-mailer-oauth' ),
			'summary'  => __( 'Sign in with a Microsoft account and send as that mailbox. Nothing to register in Azure. For sending as a shared address across a tenant, use Microsoft 365 instead.', 'modern-mailer-oauth' ),
			'docs'     => 'https://learn.microsoft.com/en-us/graph/api/user-sendmail',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		// Genuinely nothing to fill in - which is the entire point of this
		// provider. The mailbox is chosen at Microsoft's own prompt, and the
		// connection screen reports which one from the sign-in block rather
		// than from a form field nobody can usefully edit.
		return [];
	}

	public static function is_available(): bool {
		return Broker::is_available();
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	protected function token_cache_key(): string {
		return 'outlook:' . md5( $this->settings->secrets()->get( 'ms_refresh' ) );
	}

	protected function request_token() {
		$broker = new Broker( $this->http, new Site_Identity() );

		return $broker->token_for( Broker::MICROSOFT, $this->settings );
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		unset( $mailer );

		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->send_mime( $token, $raw_mime );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// A token can be refused mid-life - the grant revoked from the
		// account's security settings, or a policy change invalidating it. One
		// retry with a freshly minted token distinguishes that from a
		// credential that is genuinely dead, and costs one round trip.
		if ( 401 === $response['code'] ) {
			$this->invalidate_token();

			$token = $this->access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->send_mime( $token, $raw_mime );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		// Graph answers a successful sendMail with 202 Accepted and no body.
		if ( 202 === $response['code'] || 200 === $response['code'] ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'] );
	}

	public function verify_connection() {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->http->request(
			self::GRAPH_BASE . '/me?$select=mail,userPrincipalName',
			[
				'method'  => 'GET',
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return $this->map_error( $response['code'], $response['body'] );
		}

		$data    = $this->decode( $response['body'] );
		$address = (string) ( $data['mail'] ?? $data['userPrincipalName'] ?? '' );

		// Say what could not be checked rather than implying more than was
		// tested. Reading the profile proves the credential is live and the
		// mailbox exists; it does not prove Microsoft will accept a message,
		// which only sending one can.
		if ( '' !== $address ) {
			return sprintf(
				/* translators: %s: email address of the connected mailbox. */
				__( 'Connected to %s. The credential is valid and the mailbox is reachable; only a test message can confirm delivery.', 'modern-mailer-oauth' ),
				$address
			);
		}

		return true;
	}

	/**
	 * @return array{code:int,body:string}|WP_Error
	 */
	private function send_mime( string $token, string $raw_mime ) {
		return $this->http->request(
			self::GRAPH_BASE . '/me/sendMail',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'text/plain',
				],
				'body'    => base64_encode( $raw_mime ),
			]
		);
	}

	/**
	 * Turn a Graph failure into something an admin can act on.
	 */
	private function map_error( int $status, string $body ): WP_Error {
		$data    = $this->decode( $body );
		$code    = (string) ( $data['error']['code'] ?? '' );
		$message = (string) ( $data['error']['message'] ?? '' );

		if ( 401 === $status || 'InvalidAuthenticationToken' === $code ) {
			return new WP_Error(
				'mmoa_outlook_unauthorized',
				__( 'Microsoft rejected the credential. The account may have revoked access, or its password may have changed. Sign in again to reconnect.', 'modern-mailer-oauth' )
			);
		}

		if ( 'ErrorAccessDenied' === $code || 403 === $status ) {
			return new WP_Error(
				'mmoa_outlook_forbidden',
				__( 'The signed-in account is not allowed to send mail. If this is a work or school account, an administrator may have restricted it.', 'modern-mailer-oauth' )
			);
		}

		if ( 'ErrorSendAsDenied' === $code ) {
			return new WP_Error(
				'mmoa_outlook_send_as',
				__( 'The From address does not belong to the signed-in mailbox, and this connection can only send as itself. Set the From address to the connected account, or use Microsoft 365 with an application registration to send as another address.', 'modern-mailer-oauth' )
			);
		}

		if ( 'ErrorMessageSizeExceeded' === $code || 413 === $status ) {
			return new WP_Error(
				'mmoa_outlook_too_large',
				__( 'The message is larger than Microsoft accepts in a single request. Send large files as a link rather than an attachment.', 'modern-mailer-oauth' )
			);
		}

		if ( 429 === $status ) {
			return new WP_Error(
				'mmoa_outlook_throttled',
				__( 'Microsoft is throttling this mailbox. The message will be retried.', 'modern-mailer-oauth' )
			);
		}

		return new WP_Error(
			'mmoa_outlook_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: error text from Microsoft. */
				__( 'Microsoft Graph refused the message (HTTP %1$d): %2$s', 'modern-mailer-oauth' ),
				$status,
				'' !== $message ? $message : __( 'no reason given', 'modern-mailer-oauth' )
			)
		);
	}
}
