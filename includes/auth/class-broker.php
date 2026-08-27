<?php
/**
 * HTTP client for the hosted OAuth broker.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Auth;

use ModernMailer\Http;
use ModernMailer\Settings;
use ModernMailer\Site_Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the service that holds our OAuth client secrets.
 *
 * One-click setup exists because an OAuth client secret cannot ship inside a
 * plugin - anyone who installs it can read it. So a service we run holds the
 * secret, performs the code exchange, and hands the result back. That service
 * is the only thing standing between an admin and a fifteen-minute detour
 * through the Google Cloud or Azure console.
 *
 * What it deliberately is NOT is a mail relay. The broker brokers tokens and
 * nothing else: it returns real Google and Microsoft credentials to the site,
 * and every message afterwards goes straight from the site to Gmail or Graph.
 * WP Mail SMTP's Gmail integration takes the other road - `send_email()` there
 * POSTs the message body to `api.wpmailsmtp.com` and their servers send it -
 * which makes the vendor a processor of every customer's email, puts message
 * content on a third-party host, and means an outage of that service stops all
 * mail rather than merely stopping new connections. Their own Outlook
 * integration is the token-broker shape, and it is the better one.
 *
 * The practical consequence for us: if the broker is unreachable, nobody can
 * connect a new account and tokens cannot be refreshed - but a site with a
 * valid access token keeps sending, and every connection using its own OAuth
 * client is unaffected entirely.
 */
class Broker {

	/** Google, for Gmail. */
	public const GOOGLE = 'google';

	/** Microsoft, for Outlook and Microsoft 365. */
	public const MICROSOFT = 'microsoft';

	/**
	 * Where the broker lives.
	 *
	 * A site can point somewhere else without touching the plugin, which is what
	 * a staging environment and the test suite both do:
	 *
	 *     define( 'MMOA_BROKER_URL', 'https://api.example.com/oauth/v1/' );
	 *
	 * Filtering it to '' switches one-click off entirely and leaves only the
	 * own-client paths, which depend on no service at all.
	 *
	 * See docs/BROKER.md for the four routes it implements, and broker/ for the
	 * implementation.
	 */
	private const DEFAULT_URL = 'https://api.techyza.com/';

	public function __construct( private Http $http, private Site_Identity $identity ) {}

	/**
	 * The broker base URL, always with a trailing slash.
	 */
	public static function base_url(): string {
		$url = defined( 'MMOA_BROKER_URL' ) ? (string) MMOA_BROKER_URL : self::DEFAULT_URL;

		/**
		 * Filter the OAuth broker base URL.
		 *
		 * @param string $url Base URL, with trailing slash.
		 */
		$url = (string) apply_filters( 'mmoa_broker_url', $url );

		return '' === $url ? '' : trailingslashit( $url );
	}

	/**
	 * Whether one-click setup can be offered at all.
	 *
	 * A site that has filtered the broker away gets the own-client path only,
	 * and the UI says so rather than showing a button that cannot work.
	 */
	public static function is_available(): bool {
		return '' !== self::base_url();
	}

	/**
	 * Whether a real setup service has been named yet.
	 *
	 * Separate from is_available() on purpose. Availability decides whether the
	 * one-click *option* is offered at all; this decides whether it can actually
	 * do anything. While the placeholder stands the flow is visible and every
	 * call fails at once with a message naming the real reason - which beats
	 * both hiding a finished feature and letting someone wait out a DNS timeout
	 * to be told something vague.
	 */
	public static function is_configured(): bool {
		$host = (string) wp_parse_url( self::base_url(), PHP_URL_HOST );

		return '' !== $host && ! str_ends_with( strtolower( $host ), '.invalid' );
	}

	/**
	 * Whether a family name is one we broker.
	 */
	public static function is_family( string $family ): bool {
		return in_array( $family, [ self::GOOGLE, self::MICROSOFT ], true );
	}

	/**
	 * Where a family's credentials live within a connection.
	 *
	 * Google reuses `google_refresh` deliberately: Gmail_OAuth already reads
	 * that key, so a brokered connection is indistinguishable to it from a
	 * hand-made one and no sending code had to learn this mode exists.
	 *
	 * @return array{refresh:string,account:string,mode:string}
	 */
	public static function keys( string $family ): array {
		return self::MICROSOFT === $family
			? [ 'refresh' => 'ms_refresh', 'account' => 'ms_account', 'mode' => 'ms_setup_mode' ]
			: [ 'refresh' => 'google_refresh', 'account' => 'google_account', 'mode' => 'google_setup_mode' ];
	}

	/**
	 * An access token for a brokered connection, from its stored grant.
	 *
	 * Takes an already slot-scoped Settings rather than a slot name, because
	 * its two callers know different things: the admin flow knows which
	 * connection it is acting on, while a provider mid-send only ever holds a
	 * scoped view and has no idea which slot it belongs to. Scoping is the one
	 * thing they have in common, so it is what this asks for.
	 *
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	public function token_for( string $family, Settings $scoped ) {
		$key     = self::keys( $family )['refresh'];
		$refresh = $scoped->secrets()->get( $key );

		if ( '' === $refresh ) {
			return new WP_Error(
				'mmoa_one_click_not_connected',
				__( 'No account is connected. Use Connect on the settings screen.', 'modern-mailer-oauth' )
			);
		}

		$result = $this->refresh( $family, $refresh );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Microsoft issues a replacement refresh token on every use and retires
		// the old one. Failing to store it means the connection keeps working
		// until the current token's window closes and then dies with nothing to
		// explain it, so this is not an optimisation.
		if ( '' !== $result['refresh_token'] && $result['refresh_token'] !== $refresh ) {
			$scoped->secrets()->set( $key, $result['refresh_token'] );
		}

		return [
			'token'      => $result['access_token'],
			'expires_in' => $result['expires_in'],
		];
	}

	/**
	 * Where to send the browser to begin a one-click connection.
	 *
	 * The broker redirects on to Google or Microsoft using its own client and
	 * its own registered redirect URI, which is what spares every site from
	 * registering one of its own.
	 *
	 * @param string $family   GOOGLE or MICROSOFT.
	 * @param string $state    Opaque value echoed back to us, which we verify.
	 * @param string $callback Where the broker should return the browser.
	 */
	public function authorize_url( string $family, string $state, string $callback ): string {
		// Every value is encoded explicitly: add_query_arg() expects
		// pre-encoded input and passes values through untouched, and the
		// callback contains a query string of its own whose `&` would
		// otherwise terminate the parameter early.
		return add_query_arg(
			[
				'site_id'  => rawurlencode( $this->identity->get() ),
				'site_url' => rawurlencode( home_url() ),
				'callback' => rawurlencode( $callback ),
				'state'    => rawurlencode( $state ),
				'version'  => rawurlencode( \ModernMailer\VERSION ),
			],
			self::base_url() . $family . '/authorize'
		);
	}

	/**
	 * Trade a one-time handoff code for the provider's real tokens.
	 *
	 * The handoff is deliberately not the tokens themselves. Anything the
	 * broker puts in the redirect URL lands in the site's access log, the
	 * admin's browser history, and any Referer header the next page sends - so
	 * the redirect carries only a short-lived, single-use code, and the tokens
	 * come back over a server-to-server POST that nothing else can observe.
	 *
	 * @return array{access_token:string,refresh_token:string,expires_in:int,email:string}|WP_Error
	 */
	public function claim( string $family, string $handoff ) {
		$data = $this->post( $family . '/claim', [ 'handoff' => $handoff ] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
			// A grant with no refresh token works for an hour and then stops.
			// Refusing it now is the only way to fail while an admin is still
			// looking at the screen that caused it.
			return new WP_Error(
				'mmoa_broker_no_refresh_token',
				__( 'The setup service did not return a lasting credential, so the connection would have stopped working within the hour. Try connecting again, and if it repeats, use your own OAuth client instead.', 'modern-mailer-oauth' )
			);
		}

		return [
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => (string) $data['refresh_token'],
			'expires_in'    => (int) ( $data['expires_in'] ?? 3600 ),
			'email'         => (string) ( $data['email'] ?? '' ),
		];
	}

	/**
	 * Mint a fresh access token from a stored refresh token.
	 *
	 * @return array{access_token:string,expires_in:int,refresh_token:string}|WP_Error
	 */
	public function refresh( string $family, string $refresh_token ) {
		$data = $this->post( $family . '/refresh', [ 'refresh_token' => $refresh_token ] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'mmoa_broker_no_access_token',
				__( 'The setup service did not return an access token.', 'modern-mailer-oauth' )
			);
		}

		return [
			'access_token'  => (string) $data['access_token'],
			'expires_in'    => (int) ( $data['expires_in'] ?? 3600 ),

			// Microsoft rotates the refresh token on every use, so a response
			// may carry a replacement. Returning '' when it does not lets the
			// caller keep the one it already has without having to know which
			// provider rotates and which does not.
			'refresh_token' => (string) ( $data['refresh_token'] ?? '' ),
		];
	}

	/**
	 * Ask the broker to revoke a grant and forget it.
	 *
	 * @return true|WP_Error
	 */
	public function revoke( string $family, string $refresh_token ) {
		$data = $this->post( $family . '/revoke', [ 'refresh_token' => $refresh_token ] );

		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * POST to the broker and decode a JSON object from the response.
	 *
	 * @param array<string,mixed> $body Route-specific parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	private function post( string $route, array $body ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'mmoa_broker_disabled',
				__( 'One-click setup is switched off on this site. Connect using your own OAuth client instead.', 'modern-mailer-oauth' )
			);
		}

		if ( ! self::is_configured() ) {
			return new WP_Error(
				'mmoa_broker_unconfigured',
				__( 'One-click setup has no setup service to talk to yet. Define MMOA_BROKER_URL with the address of yours, or connect using your own OAuth client, which needs no service at all.', 'modern-mailer-oauth' )
			);
		}

		$response = $this->http->request(
			self::base_url() . $route,
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode(
					array_merge(
						$body,
						[
							'site_id'  => $this->identity->get(),
							'site_url' => home_url(),
							'version'  => \ModernMailer\VERSION,
						]
					)
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) $response['body'], true );
		$data = is_array( $data ) ? $data : [];

		if ( 200 !== (int) $response['code'] ) {
			return $this->error( (int) $response['code'], $data );
		}

		return $data;
	}

	/**
	 * Turn a broker error response into something an admin can act on.
	 *
	 * The broker's own `message` is preferred when it sends one, because it
	 * knows things this side cannot - which account was refused, whether a
	 * tenant requires administrator consent. The fallbacks below are for when
	 * it says nothing useful, and they are written to distinguish "the service
	 * is broken" from "you need to do something", because those send an admin
	 * to very different places.
	 *
	 * @param array<string,mixed> $data Decoded response body.
	 */
	private function error( int $status, array $data ): WP_Error {
		$code    = (string) ( $data['error'] ?? 'mmoa_broker_error' );
		$message = trim( (string) ( $data['message'] ?? '' ) );

		if ( '' === $message ) {
			if ( 404 === $status || 410 === $status ) {
				$message = __( 'That sign-in has already been used or has expired. Start the connection again.', 'modern-mailer-oauth' );
			} elseif ( 401 === $status || 403 === $status ) {
				$message = __( 'The setup service refused this site. If the account was disconnected from the provider, connect it again; otherwise use your own OAuth client.', 'modern-mailer-oauth' );
			} elseif ( 429 === $status ) {
				$message = __( 'The setup service is rate limiting this site. Wait a few minutes and try again.', 'modern-mailer-oauth' );
			} elseif ( $status >= 500 ) {
				$message = __( 'The setup service is unavailable. Existing connections keep sending; only connecting a new account is affected. Try again shortly, or use your own OAuth client, which does not depend on this service.', 'modern-mailer-oauth' );
			} else {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'The setup service returned an unexpected response (HTTP %d).', 'modern-mailer-oauth' ), $status );
			}
		}

		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
