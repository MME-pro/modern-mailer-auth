<?php
	/**
 * Microsoft Graph provider (app-only).
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
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
	 * Any one of these makes GET /users readable, so the mailbox can be
	 * confirmed. None of them is needed to send.
	 */
	private const DIRECTORY_ROLES = [ 'User.Read.All', 'User.ReadBasic.All', 'Directory.Read.All', 'Directory.ReadWrite.All' ];

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

	public static function slug(): string {
		return 'graph';
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
			'label'    => __( 'Microsoft 365', 'modern-mailer-oauth' ),
			'summary'  => __( 'App-only authentication through Microsoft Graph. No sign-in prompt and no refresh token, so nothing expires except the client secret.', 'modern-mailer-oauth' ),
			'docs'     => 'https://learn.microsoft.com/graph/auth-v2-service',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		return [
			Field::required( 'ms_tenant_id', __( 'Directory (tenant) ID', 'modern-mailer-oauth' ), __( 'From the Overview page of your Entra app registration.', 'modern-mailer-oauth' ) ),
			Field::required( 'ms_client_id', __( 'Application (client) ID', 'modern-mailer-oauth' ) ),
			Field::secret( 'ms_client_secret', __( 'Client secret', 'modern-mailer-oauth' ), __( 'Copy the secret Value, not the Secret ID. Entra shows the Value only once.', 'modern-mailer-oauth' ) ),
			new Field(
				key: 'ms_sender',
				label: __( 'Send as mailbox', 'modern-mailer-oauth' ),
				type: Field::EMAIL,
				required: true,
				help: __( 'A licensed or shared mailbox. Not a distribution list or a bare alias.', 'modern-mailer-oauth' )
			),
			new Field(
				key: 'ms_secret_expires',
				label: __( 'Secret expires', 'modern-mailer-oauth' ),
				type: Field::TEXT,
				help: __( 'Entra secrets last at most 24 months. Record the date and you will be warned before it lapses instead of finding out when mail stops.', 'modern-mailer-oauth' )
			),
			new Field(
				key: 'ms_policy_ack',
				label: __( 'Access is scoped to specific mailboxes', 'modern-mailer-oauth' ),
				type: Field::CHECKBOX,
				help: __( 'Mail.Send lets this app send as any mailbox in the tenant until you restrict it with New-ApplicationAccessPolicy.', 'modern-mailer-oauth' )
			),
		];
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

	/**
	 * @return true|string|WP_Error True when everything was checked, a string
	 *                              when it passed but something was skipped.
	 */
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

		$roles = $this->granted_roles( $token );

		// A consent granted a minute ago does not appear in a token minted
		// before it, and these live for about an hour. Without this, an admin
		// who has just fixed the permission is told it is still missing and
		// reasonably concludes the fix did not work.
		if ( null !== $roles && ! in_array( 'Mail.Send', $roles, true ) ) {
			$this->invalidate_token();

			$token = $this->access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$roles = $this->granted_roles( $token );
		}

		if ( null !== $roles && ! in_array( 'Mail.Send', $roles, true ) ) {
			return $this->no_mail_send_error( $roles );
		}

		// Reading the mailbox confirms two more things: that it exists, and
		// that the access policy actually grants this app reach to it. But
		// /users needs User.Read.All, which sending does not - so the probe
		// runs only where the directory is already readable. Demanding it
		// would double the permission this plugin asks a tenant for, to check
		// something it does not need in order to work.
		if ( null !== $roles && ! array_intersect( self::DIRECTORY_ROLES, $roles ) ) {
			return $this->unchecked_mailbox_notice();
		}

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

		// Being refused the directory is not a sending fault, whatever the
		// roles claim led us to expect - RBAC for Applications can scope the
		// read away again. Report what could not be checked rather than
		// failing a connection that may well send perfectly well.
		if (
			403 === $response['code']
			&& 'Authorization_RequestDenied' === (string) ( $this->decode( $response['body'] )['error']['code'] ?? '' )
		) {
			return $this->unchecked_mailbox_notice();
		}

		return $this->map_error( $response['code'], $response['body'], $sender );
	}

	/**
	 * The Graph application permissions this app was actually granted.
	 *
	 * App-only tokens carry their consented permissions in the `roles` claim,
	 * so the most common setup failure - an app registration with no admin
	 * consent - can be named exactly instead of being inferred from whichever
	 * endpoint happens to refuse us first. That refusal is a 403 reading
	 * "Insufficient privileges to complete the operation", which says nothing
	 * about which privilege, or where to grant it.
	 *
	 * The claim is read, never trusted: nothing here is an authorization
	 * decision of ours. Microsoft enforces the permissions; this only decides
	 * what to tell the admin. The signature is therefore not checked, and an
	 * unreadable token returns null so verification falls back to asking Graph
	 * rather than guessing.
	 *
	 * @return string[]|null Granted roles, or null if the token cannot be read.
	 */
	private function granted_roles( string $token ): ?array {
		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ), true );

		if ( false === $payload ) {
			return null;
		}

		$claims = json_decode( $payload, true );

		if ( ! is_array( $claims ) || ! array_key_exists( 'roles', $claims ) ) {
			// No roles claim at all is not an unreadable token - it is an app
			// registration with nothing consented, which is exactly the case
			// worth naming.
			return is_array( $claims ) ? [] : null;
		}

		return array_values( array_filter( (array) $claims['roles'], 'is_string' ) );
	}

	/**
	 * @param string[] $roles Whatever the app was granted instead.
	 */
	private function no_mail_send_error( array $roles ): WP_Error {
		return new WP_Error(
			'mmoa_graph_no_mail_send',
			[] === $roles
				? __( 'This app registration has no Microsoft Graph application permissions at all. In Entra, open the app, go to API permissions, choose Add a permission, then Microsoft Graph, then Application permissions - not Delegated - and add Mail.Send. It only takes effect once you click Grant admin consent.', 'modern-mailer-oauth' )
				: sprintf(
					/* translators: %s: comma-separated list of granted permissions. */
					__( 'This app registration has admin consent for %s, but not for Mail.Send. Add Mail.Send under API permissions as an Application permission, then grant admin consent again.', 'modern-mailer-oauth' ),
					implode( ', ', $roles )
				)
		);
	}

	private function unchecked_mailbox_notice(): string {
		return __( 'Credentials are valid and Mail.Send is granted, so this connection can send. The mailbox itself was not checked - that needs the User.Read.All permission, which sending does not require and this plugin does not ask for.', 'modern-mailer-oauth' );
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
			// The status travels with the error so Failure can recognise a 5xx
			// or a 429 as transient even when we have no specific mapping for
			// the body Microsoft returned.
			[
				'graph_code' => $code,
				'status'     => $status,
			]
		);
	}
}
