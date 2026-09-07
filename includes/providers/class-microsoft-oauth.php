<?php
/**
 * Microsoft delegated OAuth provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Microsoft_Consent;
use ModernMailer\Field;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Microsoft Graph as the mailbox that signed in.
 *
 * The delegated sibling of Graph. Both talk to the same API and post the same
 * bytes to it; what differs is whose authority the token carries, and that
 * changes three things.
 *
 * - **What it needs.** An application ID and a client secret, and no tenant ID.
 *   The /common authority resolves the tenant from whoever signs in, which is
 *   also why this is the only Microsoft path that works for a personal Outlook
 *   or Hotmail account.
 * - **Who has to agree.** The person signing in, for their own mailbox. Graph's
 *   app-only permissions need tenant-wide administrator consent, which is a
 *   different order of request and one plenty of people cannot make.
 * - **What it sends as.** The mailbox that signed in, and only that mailbox.
 *   There is no sender field here because there is nothing to choose: /me is
 *   whoever holds the token. Graph is the path for sending as a shared address.
 *
 * ## The refresh token
 *
 * This holds one, which is the thing this plugin was built to avoid. It expires
 * after roughly ninety days idle and is revoked outright by a password change,
 * an MFA enrolment or a Conditional Access policy change - and when it goes,
 * sending stops. Graph has no such failure and remains the better path wherever
 * an administrator can register an application.
 *
 * That is a reason to prefer Graph, not a reason to withhold this. A refresh
 * token that occasionally needs renewing beats no way to send at all, which is
 * the alternative for anybody without tenant admin.
 */
class Microsoft_OAuth extends Abstract_Provider {

	public const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	/** The merged tile's value for this mode. */
	public const MODE = 'own_signin';

	private const AUTHORITY = 'https://login.microsoftonline.com/common/oauth2/v2.0';

	/**
	 * Graph refuses a sendMail request body over 4 MB. Held under it, matching
	 * the app-only path exactly - the limit belongs to the endpoint, not to the
	 * kind of token presented to it.
	 */
	private const MAX_MIME_BYTES = 3145728;

	public function get_label(): string {
		return __( 'Microsoft (legacy)', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public static function slug(): string {
		return 'ms_oauth';
	}

	/**
	 * Behind the Microsoft tile, like the other transports.
	 */
	public static function is_listed(): bool {
		return false;
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Microsoft (legacy)', 'modern-mailer-oauth' ),
			'summary'  => __( 'Your own Entra app plus a one-time sign-in. Needs an application ID and a client secret but no tenant ID and no administrator, and sends as the mailbox that signed in. It holds a refresh token, which expires if left idle and is revoked by a password or MFA change.', 'modern-mailer-oauth' ),
			'docs'     => 'https://learn.microsoft.com/graph/auth-v2-user',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	/**
	 * Two values, and deliberately not three.
	 *
	 * No tenant ID, because /common does not take one. No sender, because the
	 * token names the mailbox. No permission acknowledgement of the kind Graph
	 * carries, because a delegated grant can only ever reach the one mailbox
	 * that consented - the tenant-wide exposure that acknowledgement exists to
	 * flag is not reachable from here.
	 *
	 * And no "signed in as" either. The mailbox is stored - the sign-in writes
	 * it and the connection screen shows it - but it is not a field, because a
	 * field is something a person fills in. Publishing it as a read-only one
	 * put the same address on screen twice and invited the question of what it
	 * would mean for the two to differ, which is nothing: the token decides.
	 */
	public static function fields(): array {
		return [
			new Field(
				key: 'msoauth_client_id',
				label: __( 'Application (client) ID', 'modern-mailer-oauth' ),
				required: true,
				help: __( 'From the Overview page of your Entra app registration. Register it as a Web platform and add the redirect URI shown below.', 'modern-mailer-oauth' )
			),
			new Field(
				key: 'msoauth_client_sec',
				label: __( 'Client secret', 'modern-mailer-oauth' ),
				type: Field::PASSWORD,
				secret: true,
				required: true,
				help: __( 'Copy the secret Value, not the Secret ID. Entra shows the Value only once.', 'modern-mailer-oauth' )
			),
		];
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

		// A token can be rejected mid-life - the secret rotated, the grant
		// revoked, the password changed. Drop it and make exactly one more
		// attempt with a fresh one, so a single administrative action does not
		// cost the site an email. If the refresh token itself is dead the
		// second attempt fails too, and says so properly.
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

		if ( 202 === $response['code'] || 200 === $response['code'] ) {
			return true;
		}

		return $this->map_send_error( $response['code'], $response['body'] );
	}

	/**
	 * Confirm the grant by asking Graph who we are.
	 *
	 * A real check rather than a presence test: it exercises the stored refresh
	 * token, the client credentials behind it and the network path to Graph, and
	 * it costs one cheap request with no side effects.
	 *
	 * @return true|WP_Error
	 */
	public function verify_connection() {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->http->request(
			self::GRAPH_BASE . '/me?$select=mail,userPrincipalName',
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return $this->map_send_error( $response['code'], $response['body'] );
		}

		$data    = $this->decode( $response['body'] );
		$address = (string) ( $data['mail'] ?? '' );
		$address = '' !== $address ? $address : (string) ( $data['userPrincipalName'] ?? '' );

		// Kept in step on every verify, not only at sign-in. A mailbox can be
		// renamed, and a screen still showing the old address after that is
		// worse than one showing none.
		if ( '' !== $address && $address !== (string) $this->settings->get( 'msoauth_account' ) ) {
			$this->settings->update( [ 'msoauth_account' => $address ] );
		}

		$from = trim( (string) $this->settings->get( 'from_email' ) );

		// A delegated token can only send as its own mailbox or an address that
		// mailbox has Send As rights over. A mismatch here is not necessarily
		// wrong, so it is reported rather than refused - but it is the single
		// likeliest reason a connection that verifies still cannot send.
		if ( '' !== $from && '' !== $address && 0 !== strcasecmp( $from, $address ) ) {
			return sprintf(
				/* translators: 1: configured From address, 2: signed-in mailbox. */
				__( 'Signed in as %2$s, but this connection sends from %1$s. Microsoft will refuse that unless %2$s has Send As permission for it.', 'modern-mailer-oauth' ),
				$from,
				$address
			);
		}

		return true;
	}

	/**
	 * POST the MIME message as the signed-in mailbox.
	 *
	 * Graph accepts a base64-encoded RFC 822 message when Content-Type is
	 * text/plain. Using that rather than building a JSON message object by hand
	 * is what makes attachments, inline cid: images, custom headers, Reply-To
	 * and Cc/Bcc work without any code here understanding them.
	 *
	 * /me rather than /users/{upn}: under a delegated token "me" is exactly the
	 * mailbox that consented, and naming it explicitly would only invite a
	 * mismatch between the address stored here and the one the token grants.
	 *
	 * @return array{code:int,body:string,headers:array}|WP_Error
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

	protected function token_cache_key(): string {
		// The refresh token is part of the key, so reconnecting a different
		// account cannot be served a cached access token belonging to the
		// previous one.
		return 'ms_oauth:' . md5(
			(string) $this->settings->get( 'msoauth_client_id' ) . '|' .
			$this->settings->secrets()->get( 'msoauth_refresh' )
		);
	}

	/**
	 * Trade the stored refresh token for an access token.
	 *
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	protected function request_token() {
		$client_id = trim( (string) $this->settings->get( 'msoauth_client_id' ) );
		$secret    = $this->settings->secrets()->get( 'msoauth_client_sec' );
		$refresh   = $this->settings->secrets()->get( 'msoauth_refresh' );

		if ( '' === $client_id || '' === $secret ) {
			return new WP_Error(
				'mmoa_ms_oauth_incomplete',
				__( 'The Microsoft application ID or client secret is missing.', 'modern-mailer-oauth' )
			);
		}

		if ( '' === $refresh ) {
			return new WP_Error(
				'mmoa_ms_not_connected',
				__( 'No Microsoft account is connected. Use the sign-in button on the connection screen.', 'modern-mailer-oauth' )
			);
		}

		$response = $this->http->request(
			self::AUTHORITY . '/token',
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $client_id,
					'client_secret' => $secret,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response['body'] );

		if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
			return $this->map_refresh_error( $data );
		}

		// Microsoft rotates the refresh token on most redemptions and the old
		// one stops working. Not banking the new one is how a connection sends
		// perfectly for a day and then dies with "invalid_grant" - so the
		// replacement is stored whenever one comes back.
		if ( ! empty( $data['refresh_token'] ) && (string) $data['refresh_token'] !== $refresh ) {
			$this->settings->secrets()->set( 'msoauth_refresh', (string) $data['refresh_token'] );
		}

		return [
			'token'      => (string) $data['access_token'],
			'expires_in' => (int) ( $data['expires_in'] ?? 3600 ),
		];
	}

	/**
	 * A dead grant is the failure worth naming, because it is the one that will
	 * actually happen and the one with a specific remedy.
	 *
	 * @param array<string,mixed> $data Decoded token-endpoint body.
	 */
	private function map_refresh_error( array $data ): WP_Error {
		$description = (string) ( $data['error_description'] ?? '' );
		$error       = (string) ( $data['error'] ?? '' );

		if ( 'invalid_grant' === $error || false !== strpos( $description, 'AADSTS50173' ) || false !== strpos( $description, 'AADSTS700082' ) ) {
			return new WP_Error(
				'mmoa_ms_grant_expired',
				__( 'The Microsoft sign-in for this connection is no longer valid, so nothing can be sent through it. This happens when the account password changed, multi-factor authentication was enrolled, a Conditional Access policy changed, or the connection went unused for about ninety days. Sign in again on the connection screen.', 'modern-mailer-oauth' ),
				[ 'reconnect' => true ]
			);
		}

		if ( 'invalid_client' === $error || false !== strpos( $description, 'AADSTS7000215' ) ) {
			return new WP_Error(
				'mmoa_ms_bad_secret',
				__( 'Microsoft rejected the client secret. If it was rotated in Entra, paste the new Value here and save.', 'modern-mailer-oauth' )
			);
		}

		return new WP_Error(
			'mmoa_ms_token_failed',
			sprintf(
				/* translators: %s: error text returned by Microsoft. */
				__( 'Microsoft would not issue an access token: %s', 'modern-mailer-oauth' ),
				'' !== $description ? $description : ( '' !== $error ? $error : __( 'no details supplied', 'modern-mailer-oauth' ) )
			)
		);
	}

	/**
	 * Turn a Graph rejection into something an admin can act on.
	 */
	private function map_send_error( int $status, string $body ): WP_Error {
		$data    = $this->decode( $body );
		$code    = (string) ( $data['error']['code'] ?? '' );
		$message = (string) ( $data['error']['message'] ?? '' );

		if ( 'ErrorSendAsDenied' === $code || 'ErrorAccessDenied' === $code ) {
			return new WP_Error(
				'mmoa_ms_send_as_denied',
				sprintf(
					/* translators: %s: the From address this connection is configured with. */
					__( 'Microsoft refused to send as %s. A signed-in connection may only send as its own mailbox, or as an address that mailbox holds Send As permission for.', 'modern-mailer-oauth' ),
					trim( (string) $this->settings->get( 'from_email' ) )
				)
			);
		}

		if ( 'MailboxNotEnabledForRESTAPI' === $code ) {
			return new WP_Error(
				'mmoa_ms_no_mailbox',
				__( 'That account has no Exchange Online mailbox, so Graph cannot send from it. It needs a licence that includes Exchange.', 'modern-mailer-oauth' )
			);
		}

		if ( 429 === $status ) {
			return new WP_Error(
				'mmoa_ms_throttled',
				__( 'Microsoft is throttling this mailbox. The message stays in the queue and will be retried.', 'modern-mailer-oauth' ),
				[ 'status' => $status ]
			);
		}

		return new WP_Error(
			'mmoa_ms_send_failed',
			sprintf(
				/* translators: 1: HTTP status, 2: error text from Microsoft. */
				__( 'Microsoft refused the message (HTTP %1$d): %2$s', 'modern-mailer-oauth' ),
				$status,
				'' !== $message ? $message : ( '' !== $code ? $code : __( 'no details supplied', 'modern-mailer-oauth' ) )
			),
			[ 'status' => $status ]
		);
	}

	/**
	 * The redirect URI belongs to the consent flow, and is republished here so
	 * the field help and the setup docs have one place to point at.
	 */
	public static function redirect_uri(): string {
		return Microsoft_Consent::redirect_uri();
	}
}
