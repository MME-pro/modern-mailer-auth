<?php
/**
 * Microsoft Graph provider (app-only).
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Microsoft Graph using the OAuth 2.0 client credentials grant.
 *
 * This is the whole point of the plugin. The delegated authorization-code flow
 * that other mailers use stores a refresh token, and that token is mortal: it
 * dies after ~90 days idle, and is revoked outright by a password change, an
 * MFA enrolment, or a Conditional Access policy change. When it dies, sending
 * stops until a human logs into wp-admin and reauthorizes.
 *
 * App-only authentication has no refresh token at all. We hold a client
 * credential, mint a ~1 hour access token whenever we need one, and there is
 * nothing in the system left to expire.
 *
 * Trade-off worth knowing: this requires a Microsoft 365 / Entra work or
 * school account. Personal outlook.com accounts have no tenant and no admin
 * to consent, so they cannot use this flow.
 */
class Graph extends Abstract_Provider {

	private const AUTH_HOST  = 'https://login.microsoftonline.com';
	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
	private const SCOPE      = 'https://graph.microsoft.com/.default';

	/**
	 * Graph caps a request body at 4 MB. We base64 the MIME before sending,
	 * which costs 4/3, so the raw message must stay under ~3 MB.
	 *
	 * Note this is stricter than it looks: attachments are already base64 once
	 * inside the MIME, so the effective payload ceiling is roughly 2.2 MB of
	 * original file. Anything larger needs the upload-session path.
	 */
	private const MAX_MIME_BYTES = 3145728;

	public function get_label(): string {
		return __( 'Microsoft 365 (Graph)', 'modern-mailer-oauth' );
	}

	public function get_max_message_bytes(): int {
		return self::MAX_MIME_BYTES;
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		$sender = $this->sender();

		if ( '' === $sender ) {
			return new WP_Error(
				'mmoa_graph_no_sender',
				__( 'No sending mailbox is configured for Microsoft 365.', 'modern-mailer-oauth' )
			);
		}

		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->send_mime( $token, $sender, $raw_mime );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// A token can be rejected mid-life if the secret was rotated or the
		// app's permissions were changed. Drop it and make exactly one more
		// attempt with a fresh one, so an admin action does not cost the site
		// an email.
		if ( 401 === $response['code'] ) {
			$this->invalidate_token();

			$token = $this->access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = $this->send_mime( $token, $sender, $raw_mime );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( 202 === $response['code'] || 200 === $response['code'] ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'], $sender );
	}

	public function verify_connection() {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sender = $this->sender();

		if ( '' === $sender ) {
			return new WP_Error(
				'mmoa_graph_no_sender',
				__( 'No sending mailbox is configured for Microsoft 365.', 'modern-mailer-oauth' )
			);
		}

		// Reading the mailbox confirms three things at once: the token is
		// valid, the mailbox exists, and the access policy actually grants
		// this app reach to it.
		$response = $this->http->request(
			self::GRAPH_BASE . '/users/' . rawurlencode( $sender ) . '?$select=mail,userPrincipalName',
			[
				'method'  => 'GET',
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 === $response['code'] ) {
			return true;
		}

		return $this->map_error( $response['code'], $response['body'], $sender );
	}

	protected function token_cache_key(): string {
		return 'graph:' . md5(
			(string) $this->settings->get( 'ms_tenant_id' ) . '|' .
			(string) $this->settings->get( 'ms_client_id' ) . '|' .
			$this->settings->secrets()->get( 'ms_client_secret' )
		);
	}

	protected function request_token() {
		$tenant = trim( (string) $this->settings->get( 'ms_tenant_id' ) );
		$client = trim( (string) $this->settings->get( 'ms_client_id' ) );
		$secret = $this->settings->secrets()->get( 'ms_client_secret' );

		if ( '' === $tenant || '' === $client || '' === $secret ) {
			return new WP_Error(
				'mmoa_graph_incomplete',
				__( 'Microsoft 365 is missing a tenant ID, application ID, or client secret.', 'modern-mailer-oauth' )
			);
		}

		$response = $this->http->request(
			self::AUTH_HOST . '/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token',
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'client_credentials',
					'client_id'     => $client,
					'client_secret' => $secret,
					'scope'         => self::SCOPE,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response['body'] );

		if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
			return $this->map_aad_error( $data );
		}

		return [
			'token'      => (string) $data['access_token'],
			'expires_in' => (int) ( $data['expires_in'] ?? 3600 ),
		];
	}

	/**
	 * POST the MIME message.
	 *
	 * Graph accepts a base64-encoded RFC 822 message when Content-Type is
	 * text/plain. Using that instead of building a JSON `message` object by
	 * hand is what makes attachments, inline cid: images, custom headers,
	 * Reply-To and Cc/Bcc work without any code here understanding them.
	 *
	 * Note this uses /users/{upn}/sendMail rather than /me/sendMail. There is
	 * no "me" under app-only auth - the token represents the application, not
	 * a signed-in person.
	 *
	 * @return array{code:int,body:string,headers:array}|WP_Error
	 */
	private function send_mime( string $token, string $sender, string $raw_mime ) {
		return $this->http->request(
			self::GRAPH_BASE . '/users/' . rawurlencode( $sender ) . '/sendMail',
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

	private function sender(): string {
		$sender = trim( (string) $this->settings->get( 'ms_sender' ) );

		return '' !== $sender ? $sender : trim( (string) $this->settings->get( 'from_email' ) );
	}

	/**
	 * Turn an Entra token-endpoint failure into something actionable.
	 *
	 * AADSTS codes are the single most common thing an admin will hit during
	 * setup, and the raw message is long and unhelpful. Naming the actual
	 * misconfiguration saves a support round-trip.
	 *
	 * @param array<string,mixed> $data Decoded error body.
	 */
	private function map_aad_error( array $data ): WP_Error {
		$description = (string) ( $data['error_description'] ?? '' );
		$raw         = (string) ( $data['error'] ?? 'unknown_error' );

		$hints = [
			'AADSTS7000215' => __( 'The client secret is wrong. Note that Entra shows the secret Value and the secret ID side by side, and only the Value works here.', 'modern-mailer-oauth' ),
			'AADSTS7000222' => __( 'The client secret has expired. Create a new one in Entra and update it here.', 'modern-mailer-oauth' ),
			'AADSTS700016'  => __( 'No application with this ID exists in this tenant. Check the Application (client) ID and that you are pointing at the right tenant.', 'modern-mailer-oauth' ),
			'AADSTS900023'  => __( 'The tenant ID is not valid. Use the Directory (tenant) ID from your app registration overview.', 'modern-mailer-oauth' ),
			'AADSTS90002'   => __( 'The tenant was not found. Check the Directory (tenant) ID.', 'modern-mailer-oauth' ),
			'AADSTS500011'  => __( 'The requested scope was rejected. Confirm the app has the Mail.Send application permission with admin consent granted.', 'modern-mailer-oauth' ),
		];

		foreach ( $hints as $code => $hint ) {
			if ( false !== strpos( $description, $code ) ) {
				return new WP_Error( 'mmoa_aad_' . strtolower( $code ), $hint, [ 'aad_code' => $code ] );
			}
		}

		return new WP_Error(
			'mmoa_aad_error',
			sprintf(
				/* translators: %s: error description returned by Microsoft. */
				__( 'Microsoft rejected the credentials: %s', 'modern-mailer-oauth' ),
				'' !== $description ? $description : $raw
			)
		);
	}

	/**
	 * Turn a Graph API failure into something actionable.
	 */
	private function map_error( int $status, string $body, string $sender ): WP_Error {
		$data    = $this->decode( $body );
		$code    = (string) ( $data['error']['code'] ?? '' );
		$message = (string) ( $data['error']['message'] ?? '' );

		switch ( $code ) {
			case 'ErrorAccessDenied':
			case 'ErrorSendAsDenied':
			case 'AccessDenied':
				return new WP_Error(
					'mmoa_graph_access_denied',
					sprintf(
						/* translators: %s: sending mailbox address. */
						__( 'The application is not allowed to send as %s. This almost always means the Exchange access policy has not been applied, or was scoped to a group this mailbox is not in.', 'modern-mailer-oauth' ),
						$sender
					)
				);

			case 'ErrorInvalidUser':
			case 'ResourceNotFound':
			case 'Request_ResourceNotFound':
				return new WP_Error(
					'mmoa_graph_no_mailbox',
					sprintf(
						/* translators: %s: sending mailbox address. */
						__( 'Microsoft 365 has no mailbox for %s. Check the address, and note that it must be a licensed mailbox or a shared mailbox - not a distribution list or an alias on its own.', 'modern-mailer-oauth' ),
						$sender
					)
				);

			case 'MailboxNotEnabledForRESTAPI':
				return new WP_Error(
					'mmoa_graph_mailbox_unsupported',
					__( 'This mailbox cannot be used with the Graph API. That usually means it is on-premises or in a dedicated or hybrid deployment.', 'modern-mailer-oauth' )
				);

			case 'ErrorMessageSizeExceeded':
				return new WP_Error(
					'mmoa_graph_too_large',
					__( 'The message is larger than Microsoft 365 accepts in a single request. Reduce the attachment size.', 'modern-mailer-oauth' )
				);

			case 'ApplicationThrottled':
			case 'ErrorTooManyObjectsOpened':
				return new WP_Error(
					'mmoa_graph_throttled',
					__( 'Microsoft 365 is throttling this application. The message was not sent; try again shortly.', 'modern-mailer-oauth' )
				);
		}

		if ( 401 === $status ) {
			return new WP_Error(
				'mmoa_graph_unauthorized',
				__( 'Microsoft rejected the access token. Confirm the Mail.Send application permission is present and that admin consent has been granted.', 'modern-mailer-oauth' )
			);
		}

		if ( 413 === $status ) {
			return new WP_Error(
				'mmoa_graph_too_large',
				__( 'The message is too large for a single Graph request. Reduce the attachment size.', 'modern-mailer-oauth' )
			);
		}

		return new WP_Error(
			'mmoa_graph_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message from Microsoft. */
				__( 'Microsoft Graph returned HTTP %1$d: %2$s', 'modern-mailer-oauth' ),
				$status,
				'' !== $message ? $message : __( 'no details supplied', 'modern-mailer-oauth' )
			),
			[ 'graph_code' => $code ]
		);
	}
}
