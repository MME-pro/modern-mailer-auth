<?php
/**
 * Microsoft OAuth authorization-code flow.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Auth;

use ModernMailer\Connections;
use ModernMailer\Http;
use ModernMailer\Providers\Microsoft_OAuth;
use ModernMailer\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Walks an admin through Microsoft's sign-in prompt and banks the refresh token.
 *
 * The delegated counterpart to Graph, and the two are not competing versions of
 * one thing. Graph is app-only: an administrator registers an application,
 * grants it Mail.Send across the tenant, and the site mints tokens from a client
 * credential with nothing to expire but the secret. That is the better path
 * whenever it is available, and it stays the default.
 *
 * What it needs is an administrator. Granting an application permission requires
 * tenant-wide admin consent, and a great many people who want to send mail from
 * their own mailbox neither have that authority nor should. It also needs a
 * tenant, which a personal Outlook or Hotmail account does not have at all.
 *
 * This path asks for neither. An app registration on the /common authority takes
 * a client ID and a secret, the person signing in consents for themselves, and
 * mail goes out as the mailbox that signed in.
 *
 * ## The cost, stated plainly
 *
 * It holds a refresh token, and this plugin exists largely because refresh
 * tokens die - after roughly ninety days idle, and immediately on a password
 * change, an MFA enrolment or a Conditional Access change. When that happens
 * sending stops.
 *
 * So this is not offered as the easy alternative to Graph. It is offered for the
 * case Graph genuinely cannot serve, and the form says so.
 *
 * ## Whose application
 *
 * The site's own, exactly as with Google. Nothing is proxied through a shared or
 * vendor-registered application: the admin registers an app in their own Entra
 * tenant, pastes the ID and secret, and the consent prompt is between them and
 * Microsoft. No third party can ever see the resulting tokens. The one-click
 * mode is the path that uses a brokered application, and it is a separate
 * choice made deliberately.
 */
class Microsoft_Consent {

	/**
	 * The /common authority, which is what makes a tenant ID unnecessary.
	 *
	 * A delegated flow can authenticate anybody - a work or school account in
	 * any tenant, or a personal Microsoft account - and Microsoft resolves which
	 * from the credentials entered at the prompt. The app-only flow cannot do
	 * this: client credentials name a single tenant in the token URL and /common
	 * is rejected outright, which is why Graph asks for a tenant ID and this
	 * does not.
	 */
	private const AUTHORITY = 'https://login.microsoftonline.com/common/oauth2/v2.0';

	/**
	 * Where a person goes to revoke this by hand.
	 *
	 * Unlike Google, Microsoft publishes no endpoint that revokes a single
	 * grant, so disconnecting can only forget the token locally and say where
	 * the other half lives.
	 */
	public const REVOKE_HELP_URL = 'https://myapps.microsoft.com';

	/** The admin-post action the callback is ultimately handled by. */
	public const CALLBACK_ACTION = 'mmoa_microsoft_callback';

	/**
	 * The public path Microsoft is told to return to.
	 *
	 * A path rather than a query string, and that is the entire reason it
	 * exists. Entra refuses to register a redirect URI containing a query
	 * string for any app whose sign-in audience includes personal Microsoft
	 * accounts - which is precisely the audience to choose if Outlook.com and
	 * Hotmail mailboxes are meant to work. The admin-post URL this used to hand
	 * out could therefore not be registered by the apps most likely to want it,
	 * and the portal rejects it outright: "url may not contain a query string".
	 *
	 * Google has no such restriction, which is why the Gmail flow can point
	 * straight at admin-post.php and this one cannot.
	 */
	public const ROUTE = 'mmoa-microsoft-callback';

	/** The query var the rewrite rule sets, private to the forwarder. */
	private const QUERY_VAR = 'mmoa_ms_callback';

	/**
	 * Exactly what is needed and nothing more.
	 *
	 * Mail.Send sends. User.Read reads the signed-in account's own address, so
	 * the screen can say which mailbox is connected - without it the admin has a
	 * connection that works but cannot be identified, which matters once there
	 * is more than one. offline_access is what makes Microsoft return a refresh
	 * token at all; without it sending dies within the hour.
	 *
	 * Note there is no Mail.Read here, and there never should be. Sending mail
	 * does not require the ability to read the mailbox.
	 */
	private const SCOPE = 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read';

	/** The state transient lives only as long as a person needs to click through. */
	private const STATE_TTL = 900;

	public function __construct(
		private Settings $settings,
		private Http $http,
		private Connections $connections
	) {}

	/**
	 * The redirect URI the Entra app registration must have registered.
	 *
	 * Same two properties as the Google one, for the same two reasons. It points
	 * at admin-post.php rather than at one of our screens, so reorganising the
	 * menu cannot break every existing connection at once. And it is identical
	 * for every connection slot, so an admin registers one URI rather than one
	 * per connection; which slot a callback belongs to travels in `state`.
	 *
	 * Entra requires the platform be registered as Web. A Single-page
	 * application registration refuses to issue a token to a confidential client
	 * and returns an error naming CORS, which explains nothing.
	 */
	public static function redirect_uri(): string {
		return self::has_clean_route()
			? home_url( '/' . self::ROUTE . '/' )
			: admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Whether this site can serve a path-shaped callback at all.
	 *
	 * Rewrite rules need a permalink structure. With permalinks set to Plain
	 * there is nothing to rewrite, so the only address available is the
	 * admin-post one - which works, but only for an app registration limited to
	 * work or school accounts. The screen says so rather than handing over a URI
	 * that Entra is going to refuse.
	 */
	public static function has_clean_route(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Serve the path, and forward it into the handler that already exists.
	 *
	 * The forwarder is deliberately thin. Handling the callback here would mean
	 * a second copy of the capability check, the state check and the redirect
	 * back into the app - and this request arrives on the front end, where the
	 * admin classes are not loaded at all. Bouncing into admin-post.php instead
	 * puts it back where the tested handler already lives, with cookies and
	 * is_admin() behaving normally, and keeps one implementation of the part
	 * that matters.
	 *
	 * The authorization code survives the hop because it travels in the query
	 * string, which the redirect carries over untouched. Nothing is exchanged
	 * here, and nothing is stored.
	 */
	public static function register_routes(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );

		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = self::QUERY_VAR;

				return $vars;
			}
		);

		add_action( 'template_redirect', [ self::class, 'forward' ] );
	}

	/**
	 * Register the rule, and rebuild the rewrite table only when it is missing.
	 *
	 * Flushing unconditionally on init is a well-known way to make every
	 * request on a site rebuild its rewrite table, and the cost of that lands
	 * on visitors rather than on whoever wrote the line. Checking first means
	 * it happens once, after the update that introduced the rule.
	 */
	public static function add_rewrite(): void {
		$pattern = self::rewrite_pattern();

		add_rewrite_rule( $pattern, 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		$rules = get_option( 'rewrite_rules' );

		if ( is_array( $rules ) && ! isset( $rules[ $pattern ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Built rather than written out, so the rule and the check that looks for
	 * it can never disagree about what was registered.
	 */
	public static function rewrite_pattern(): string {
		return '^' . self::ROUTE . '/?' . '$';
	}

	public static function forward(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Everything Microsoft sent, carried across unchanged. Sanitizing here
		// would be the wrong place: the handler this lands on validates every
		// parameter it uses and ignores the rest, and re-encoding a code or a
		// state on the way past could only corrupt them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = (array) wp_unslash( $_GET );

		unset( $query[ self::QUERY_VAR ] );

		// Scalars only, and this is not fussiness. This route is public, so
		// anyone can request it with ?code[]=x, and rawurlencode() handed an
		// array is a TypeError in PHP 8 - a fatal on a URL a stranger controls.
		// Microsoft only ever sends scalars, so dropping the rest costs nothing
		// real and the handler ignores anything it does not recognise anyway.
		$forward = [];

		foreach ( $query as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$forward[ $key ] = rawurlencode( (string) $value );
			}
		}

		$forward['action'] = self::CALLBACK_ACTION;

		wp_safe_redirect( add_query_arg( $forward, admin_url( 'admin-post.php' ) ) );
		exit;
	}

	/**
	 * Where to send the admin to approve access.
	 *
	 * @return string|WP_Error
	 */
	public function authorization_url( string $slot ) {
		$scoped    = $this->settings->for_slot( $slot );
		$client_id = trim( (string) $scoped->get( 'msoauth_client_id' ) );

		if ( '' === $client_id || '' === $scoped->secrets()->get( 'msoauth_client_sec' ) ) {
			return new WP_Error(
				'mmoa_ms_oauth_incomplete',
				__( 'Enter and save the application ID and client secret before signing in.', 'modern-mailer-oauth' )
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

		// Everything encoded by hand, because add_query_arg() expects
		// pre-encoded input and passes values straight through. It matters most
		// for redirect_uri, which carries its own query string: left raw, its &
		// ends the redirect_uri parameter and Microsoft receives a truncated URI
		// matching nothing it has registered.
		return add_query_arg(
			[
				'client_id'     => rawurlencode( $client_id ),
				'response_type' => 'code',
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_mode' => 'query',
				'scope'         => rawurlencode( self::SCOPE ),

				// select_account rather than consent. Microsoft returns a
				// refresh token whenever offline_access is granted, so there is
				// no need to force re-consent the way Google's flow does - and
				// forcing it would re-prompt on every reconnection for nothing.
				// What is worth forcing is the account chooser: signing in
				// silently as whoever the browser happens to be logged in as is
				// how a connection ends up sending from the wrong mailbox.
				'prompt'        => 'select_account',

				'state'         => rawurlencode( $state ),
			],
			self::AUTHORITY . '/authorize'
		);
	}

	/**
	 * Handle Microsoft's redirect back: verify state, swap the code for tokens.
	 *
	 * @param array<string,mixed> $request Raw query parameters.
	 * @return string|WP_Error The slot that was connected.
	 */
	public function handle_callback( array $request ) {
		$state = isset( $request['state'] ) ? sanitize_text_field( (string) $request['state'] ) : '';
		$saved = '' === $state ? false : get_transient( $this->state_key( $state ) );

		// One-shot: deleted before any work, so a replayed callback cannot be
		// used twice even if the exchange below fails.
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
			return new WP_Error(
				'mmoa_oauth_denied',
				sprintf(
					/* translators: 1: error code from Microsoft, 2: error description. */
					__( 'Microsoft did not grant access: %1$s %2$s', 'modern-mailer-oauth' ),
					sanitize_text_field( (string) $request['error'] ),
					sanitize_text_field( (string) ( $request['error_description'] ?? '' ) )
				)
			);
		}

		$code = isset( $request['code'] ) ? sanitize_text_field( (string) $request['code'] ) : '';

		if ( '' === $code ) {
			return new WP_Error(
				'mmoa_oauth_no_code',
				__( 'Microsoft did not return an authorization code.', 'modern-mailer-oauth' )
			);
		}

		// Resolved rather than assumed, for the reason the Google flow records:
		// mapping anything that is not the backup onto the primary quietly
		// banked a third connection's grant over the primary's, and the
		// connection actually being configured went on reporting itself
		// disconnected however many times it was tried.
		$slot = $this->connections->slot_for( (string) $saved['slot'] );

		if ( null === $slot ) {
			return new WP_Error(
				'mmoa_oauth_gone',
				__( 'That connection no longer exists, so the sign-in could not be saved. Start again from the connection you want to use.', 'modern-mailer-oauth' )
			);
		}

		$scoped = $this->settings->for_slot( $slot );

		$response = $this->http->request(
			self::AUTHORITY . '/token',
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => trim( (string) $scoped->get( 'msoauth_client_id' ) ),
					'client_secret' => $scoped->secrets()->get( 'msoauth_client_sec' ),
					'redirect_uri'  => self::redirect_uri(),
					'scope'         => self::SCOPE,
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

		$scoped->secrets()->set( 'msoauth_refresh', (string) $data['refresh_token'] );

		// Recorded now, while there is a token in hand, rather than looked up
		// later. It is only ever used to label the connection on screen, and an
		// address that needs a live API call to display is an address the screen
		// cannot show when the connection is broken - which is exactly when
		// somebody is looking at it.
		$account = $this->account_for( (string) ( $data['access_token'] ?? '' ) );

		if ( '' !== $account ) {
			$scoped->update( [ 'msoauth_account' => $account ] );
		}

		return $slot;
	}

	/**
	 * Forget the stored grant.
	 *
	 * Local only, and that is a real limitation rather than an oversight.
	 * Microsoft publishes no per-grant revocation endpoint - the nearest
	 * equivalents revoke every refresh token the user holds, for every
	 * application - so revoking this one alone is something only the account
	 * owner can do, from the portal.
	 *
	 * @return true|WP_Error
	 */
	public function disconnect( string $slot ) {
		$scoped  = $this->settings->for_slot( $slot );
		$secrets = $scoped->secrets();

		if ( '' === $secrets->get( 'msoauth_refresh' ) ) {
			return true;
		}

		$secrets->set( 'msoauth_refresh', '' );
		$scoped->update( [ 'msoauth_account' => '' ] );

		return true;
	}

	/**
	 * Is a slot holding a usable Microsoft grant?
	 */
	public function is_connected( string $slot ): bool {
		return '' !== $this->settings->for_slot( $slot )->secrets()->get( 'msoauth_refresh' );
	}

	/**
	 * The mailbox a slot is signed in as, for display.
	 */
	public function account( string $slot ): string {
		return trim( (string) $this->settings->for_slot( $slot )->get( 'msoauth_account' ) );
	}

	/**
	 * Ask Graph who just signed in.
	 *
	 * Best effort by design. A failure here costs a label on a screen and
	 * nothing else, so it must never be allowed to fail the sign-in that has
	 * otherwise just succeeded.
	 */
	private function account_for( string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		$response = $this->http->request(
			Microsoft_OAuth::GRAPH_BASE . '/me?$select=mail,userPrincipalName',
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
		);

		if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
			return '';
		}

		$data = json_decode( $response['body'], true );
		$data = is_array( $data ) ? $data : [];

		// mail is the routable address and is what a recipient sees. It is null
		// on accounts that have never been assigned one, where the user
		// principal name is the best available answer.
		$address = (string) ( $data['mail'] ?? '' );

		return '' !== $address ? $address : (string) ( $data['userPrincipalName'] ?? '' );
	}

	/**
	 * Explain a failed code exchange in terms the admin can act on.
	 *
	 * AADSTS codes are the single most common thing anybody hits setting this
	 * up, and Microsoft's own text is long, repeats the correlation ID twice and
	 * buries the actionable sentence. Naming the misconfiguration saves a
	 * support round-trip.
	 *
	 * @param array<string,mixed> $data            Decoded response body.
	 * @param bool                $missing_refresh Exchange succeeded but returned no refresh token.
	 */
	private function exchange_error( array $data, bool $missing_refresh ): WP_Error {
		if ( $missing_refresh ) {
			return new WP_Error(
				'mmoa_oauth_no_refresh_token',
				__( 'Microsoft authorized the connection but returned no refresh token, so sending would stop within the hour. Confirm that offline_access is among the delegated permissions on the app registration.', 'modern-mailer-oauth' )
			);
		}

		$description = (string) ( $data['error_description'] ?? '' );
		$error       = (string) ( $data['error'] ?? '' );

		$hints = [
			'AADSTS50011'   => __( 'The redirect URI does not match the app registration. Copy the exact value shown on this screen into the Entra app under Authentication, as a Web platform - not a Single-page application.', 'modern-mailer-oauth' ),
			'AADSTS7000215' => __( 'Microsoft rejected the client secret. Copy the secret Value rather than the Secret ID - Entra shows the Value only once, immediately after you create it.', 'modern-mailer-oauth' ),
			'AADSTS700016'  => __( 'Microsoft could not find that application. Check the Application (client) ID, and confirm the app registration allows accounts outside its own tenant if you are signing in with a personal account.', 'modern-mailer-oauth' ),
			'AADSTS65001'   => __( 'The account declined to grant the permissions requested, or an administrator has restricted user consent in this tenant. An administrator can grant it on the app registration instead.', 'modern-mailer-oauth' ),
			'AADSTS900971'  => __( 'The app registration has no reply URL. Add the redirect URI shown on this screen under Authentication in Entra.', 'modern-mailer-oauth' ),
			'AADSTS54005'   => __( 'The authorization code was already used. Start the connection again.', 'modern-mailer-oauth' ),
			'AADSTS70008'   => __( 'The authorization code expired before it could be exchanged. Start the connection again.', 'modern-mailer-oauth' ),
		];

		foreach ( $hints as $code => $hint ) {
			if ( false !== strpos( $description, $code ) ) {
				return new WP_Error( 'mmoa_oauth_' . strtolower( $code ), $hint );
			}
		}

		return new WP_Error(
			'mmoa_oauth_exchange_failed',
			sprintf(
				/* translators: %s: error text returned by Microsoft. */
				__( 'Microsoft refused the authorization: %s', 'modern-mailer-oauth' ),
				'' !== $description ? $description : ( '' !== $error ? $error : __( 'no details supplied', 'modern-mailer-oauth' ) )
			)
		);
	}

	private function state_key( string $state ): string {
		return 'mmoa_msoauth_state_' . md5( $state );
	}
}
