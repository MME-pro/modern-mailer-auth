<?php
/**
 * Google OAuth authorization-code flow.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Auth;

use ModernMailer\Http;
use ModernMailer\Providers\Abstract_Gmail;
use ModernMailer\Secrets;
use ModernMailer\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Walks an admin through Google's sign-in prompt and banks the refresh token.
 *
 * This is the consumer-Gmail path, and it exists because a @gmail.com account
 * has no tenant, no admin console and no service account - so there is no way to
 * send on its behalf without a human granting consent once.
 *
 * The OAuth client is the site's own. Nothing here is proxied through a shared
 * or vendor-registered application: the admin creates a client in their own
 * Google Cloud project, pastes the ID and secret, and the consent prompt is
 * between them and Google. That means no third party can ever see the resulting
 * tokens, and it also means the Google Cloud consent screen is the admin's to
 * configure - which is why the setup copy is so insistent about publishing it.
 */
class Google_Consent {

	private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/** The admin-post action Google's redirect lands on. */
	public const CALLBACK_ACTION = 'mmoa_google_callback';

	/** The state transient lives only as long as a person needs to click through. */
	private const STATE_TTL = 900;

	public function __construct( private Settings $settings, private Http $http ) {}

	/**
	 * The redirect URI Google must have registered.
	 *
	 * Two properties matter here, and both are about a string a human has to
	 * copy into the Google Cloud console and that Google then matches character
	 * for character.
	 *
	 * It points at admin-post.php rather than at one of our admin screens, so it
	 * does not change when the menu is reorganised. A redirect URI that moves
	 * when a page moves breaks every existing connection at once, and the error
	 * Google returns names the URI rather than the rename that caused it.
	 *
	 * It is also identical for both connection slots, so the admin registers one
	 * URI rather than two and cannot register only half of what is needed. Which
	 * slot a callback belongs to travels in the state parameter instead.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Where to send the admin to approve access.
	 *
	 * @return string|WP_Error
	 */
	public function authorization_url( string $slot ) {
		$scoped    = $this->settings->for_slot( $slot );
		$client_id = trim( (string) $scoped->get( 'google_client_id' ) );

		if ( '' === $client_id || '' === $scoped->secrets()->get( 'google_client_sec' ) ) {
			return new WP_Error(
				'mmoa_gmail_oauth_incomplete',
				__( 'Enter and save the OAuth client ID and client secret before connecting.', 'modern-mailer-oauth' )
			);
		}

		$state = wp_generate_password( 32, false );

		set_transient(
			$this->state_key( $state ),
			[
				'slot' => $slot,
				'user' => get_current_user_id(),
			],
			self::STATE_TTL
		);

		// Every value is encoded here because add_query_arg() does not do it -
		// it expects pre-encoded input and passes values through untouched.
		// This matters most for redirect_uri, which contains its own query
		// string: left raw, its `&` terminates the redirect_uri parameter and
		// Google receives a truncated URI that matches nothing it has
		// registered.
		return add_query_arg(
			[
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( Abstract_Gmail::SEND_SCOPE ),

				// Both of these are load-bearing. Without access_type=offline
				// Google returns only an access token and sending dies in an
				// hour; without prompt=consent it omits the refresh token on
				// every authorization after the first, so reconnecting an
				// account appears to succeed and then fails at the next
				// refresh. Together they are the difference between a
				// connection that survives and the single most common Gmail
				// integration bug.
				'access_type'   => 'offline',
				'prompt'        => 'consent',

				'state'         => rawurlencode( $state ),
			],
			self::AUTH_URL
		);
	}

	/**
	 * Handle Google's redirect back: verify state, swap the code for tokens.
	 *
	 * @param array<string,mixed> $request Raw query parameters.
	 * @return string|WP_Error The slot that was connected.
	 */
	public function handle_callback( array $request ) {
		$state = isset( $request['state'] ) ? sanitize_text_field( (string) $request['state'] ) : '';
		$saved = '' === $state ? false : get_transient( $this->state_key( $state ) );

		// One-shot: delete before doing any work, so a replayed callback cannot
		// be used twice even if the exchange below fails.
		if ( '' !== $state ) {
			delete_transient( $this->state_key( $state ) );
		}

		if ( ! is_array( $saved ) ) {
			return new WP_Error(
				'mmoa_oauth_bad_state',
				__( 'This authorization link has expired or did not originate here. Start the connection again.', 'modern-mailer-oauth' )
			);
		}

		if ( (int) $saved['user'] !== get_current_user_id() ) {
			return new WP_Error(
				'mmoa_oauth_wrong_user',
				__( 'This authorization was started by a different user account.', 'modern-mailer-oauth' )
			);
		}

		if ( ! empty( $request['error'] ) ) {
			// The admin declined, or Google refused. Its own wording is more
			// useful than anything we could invent.
			return new WP_Error(
				'mmoa_oauth_denied',
				sprintf(
					/* translators: %s: error code returned by Google. */
					__( 'Google did not grant access: %s', 'modern-mailer-oauth' ),
					sanitize_text_field( (string) $request['error'] )
				)
			);
		}

		$code = isset( $request['code'] ) ? sanitize_text_field( (string) $request['code'] ) : '';

		if ( '' === $code ) {
			return new WP_Error(
				'mmoa_oauth_no_code',
				__( 'Google did not return an authorization code.', 'modern-mailer-oauth' )
			);
		}

		$slot   = Settings::SLOT_BACKUP === $saved['slot'] ? Settings::SLOT_BACKUP : Settings::SLOT_PRIMARY;
		$scoped = $this->settings->for_slot( $slot );

		$response = $this->http->request(
			Abstract_Gmail::TOKEN_URL,
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => trim( (string) $scoped->get( 'google_client_id' ) ),
					'client_secret' => $scoped->secrets()->get( 'google_client_sec' ),
					'redirect_uri'  => self::redirect_uri(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( $response['body'], true );
		$data = is_array( $data ) ? $data : [];

		if ( 200 !== $response['code'] || empty( $data['refresh_token'] ) ) {
			return $this->exchange_error( $data, empty( $data['refresh_token'] ) && 200 === $response['code'] );
		}

		$scoped->secrets()->set( 'google_refresh', (string) $data['refresh_token'] );

		return $slot;
	}

	/**
	 * Revoke the stored grant at Google and forget it locally.
	 *
	 * @return true|WP_Error
	 */
	public function disconnect( string $slot ) {
		$secrets = $this->settings->for_slot( $slot )->secrets();
		$refresh = $secrets->get( 'google_refresh' );

		if ( '' === $refresh ) {
			return true;
		}

		// Clear our copy first. If the revoke call fails - offline, already
		// revoked at Google - the admin still ends up disconnected here, which
		// is what they asked for. Leaving a token we can no longer trust would
		// be the worse outcome.
		$secrets->set( 'google_refresh', '' );

		$response = $this->http->request(
			self::REVOKE_URL,
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [ 'token' => $refresh ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mmoa_oauth_revoke_failed',
				__( 'The account was disconnected here, but Google could not be reached to revoke the grant. Remove it manually under your Google account permissions.', 'modern-mailer-oauth' )
			);
		}

		return true;
	}

	/**
	 * Is a slot holding a usable Google grant?
	 */
	public function is_connected( string $slot ): bool {
		return '' !== $this->settings->for_slot( $slot )->secrets()->get( 'google_refresh' );
	}

	/**
	 * Explain a failed code exchange in terms the admin can act on.
	 *
	 * @param array<string,mixed> $data           Decoded response body.
	 * @param bool                $missing_refresh Exchange succeeded but returned no refresh token.
	 */
	private function exchange_error( array $data, bool $missing_refresh ): WP_Error {
		if ( $missing_refresh ) {
			return new WP_Error(
				'mmoa_oauth_no_refresh_token',
				__( 'Google authorized the connection but withheld a refresh token, so sending would stop within the hour. This happens when the account has already granted this client access. Remove this app under your Google account permissions, then connect again.', 'modern-mailer-oauth' )
			);
		}

		$error       = (string) ( $data['error'] ?? '' );
		$description = (string) ( $data['error_description'] ?? '' );

		$hints = [
			'redirect_uri_mismatch' => __( 'The redirect URI does not match the one registered on your OAuth client. Copy the exact value shown on this screen into the Google Cloud console, including the scheme and any trailing parameters.', 'modern-mailer-oauth' ),
			'invalid_client'        => __( 'Google rejected the OAuth client ID or client secret. Check both, and confirm they belong to a Web application client rather than a Desktop one.', 'modern-mailer-oauth' ),
			'invalid_grant'         => __( 'The authorization code was already used or has expired. Start the connection again.', 'modern-mailer-oauth' ),
			'access_denied'         => __( 'Access was declined at the consent screen.', 'modern-mailer-oauth' ),
		];

		if ( isset( $hints[ $error ] ) ) {
			return new WP_Error( 'mmoa_oauth_' . $error, $hints[ $error ] );
		}

		return new WP_Error(
			'mmoa_oauth_exchange_failed',
			sprintf(
				/* translators: %s: error text returned by Google. */
				__( 'Google refused the authorization: %s', 'modern-mailer-oauth' ),
				'' !== $description ? $description : ( '' !== $error ? $error : __( 'no details supplied', 'modern-mailer-oauth' ) )
			)
		);
	}

	private function state_key( string $state ): string {
		return 'mmoa_oauth_state_' . md5( $state );
	}
}
