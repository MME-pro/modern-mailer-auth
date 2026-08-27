<?php
/**
 * The four routes the plugin calls, and the one the providers call.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

/**
 * Holds the OAuth client secrets, and nothing else for longer than it must.
 *
 * The whole reason this service exists: a client secret cannot ship inside a
 * plugin, because anyone who installs it can read it. So a site that wants to
 * connect Gmail without registering its own Google Cloud project needs somebody
 * to perform the code exchange on its behalf.
 *
 * What that does NOT mean is relaying mail. This returns real Google and
 * Microsoft credentials to the site and every message afterwards goes straight
 * from the site to Gmail or Graph. The alternative - proxying the message body,
 * which is how WP Mail SMTP's Gmail integration works - would put customers'
 * email on this host, make its operator a processor of that mail under GDPR,
 * and mean an outage here stops all mail rather than only stopping new
 * connections. Nothing in this file ever sees a message.
 */
final class Broker {

	public function __construct(
		private Config $config,
		private Store $store
	) {}

	/**
	 * Begin: park what we know, then hand the browser to the provider.
	 *
	 * @param array<string,mixed> $query
	 */
	public function authorize( string $family, array $query ): never {
		$site_id  = trim( (string) ( $query['site_id'] ?? '' ) );
		$site_url = trim( (string) ( $query['site_url'] ?? '' ) );
		$callback = trim( (string) ( $query['callback'] ?? '' ) );
		$state    = trim( (string) ( $query['state'] ?? '' ) );

		if ( '' === $site_id || '' === $site_url || '' === $callback || '' === $state ) {
			Http::fail( 'bad_request', 'The setup request was incomplete. Start the connection again from the plugin.' );
		}

		// The callback is where a working credential ends up, so it is checked
		// rather than trusted. Without this, anyone could call this route with
		// a callback pointing at a host they control and have a handoff for our
		// OAuth client delivered to them - an open redirect that hands over
		// tokens rather than merely traffic.
		if ( ! $this->callback_belongs_to_site( $callback, $site_url ) ) {
			Http::fail( 'bad_callback', 'The return address did not belong to the site that asked. Nothing was sent.' );
		}

		if ( $this->store->recent_flows( $site_id ) > 30 ) {
			Http::fail(
				'rate_limited',
				'This site has started too many connections in a short time. Wait a few minutes and try again.',
				429
			);
		}

		$spec = Providers::spec( $family );
		$id   = $this->store->open_flow( $family, $site_id, $site_url, $callback, $state );

		Http::redirect(
			$spec['authorize'],
			[
				'client_id'     => $this->config->require( $spec['client_id'] ),
				'redirect_uri'  => Providers::redirect_uri( $this->config, $family ),
				'response_type' => 'code',
				'scope'         => $spec['scope'],
				'state'         => $id,
			] + $spec['extra_auth']
		);
	}

	/**
	 * The provider sends the browser back here. Exchange, park, hand on.
	 *
	 * @param array<string,mixed> $query
	 */
	public function callback( string $family, array $query ): never {
		$flow = $this->store->take_flow( (string) ( $query['state'] ?? '' ) );

		// Nothing to return the browser to. This is the one failure that cannot
		// be reported to the site, because which site it was is exactly what we
		// have lost, so it is answered here.
		if ( null === $flow || $flow['family'] !== $family ) {
			Http::fail( 'unknown_state', 'This setup link has expired or was already used. Start the connection again from the plugin.', 410 );
		}

		$return = static fn( array $params ) => Http::redirect(
			(string) $flow['callback'],
			$params + [ 'state' => (string) $flow['site_state'] ]
		);

		if ( ! empty( $query['error'] ) ) {
			$return( [
				'error'             => (string) $query['error'],
				'error_description' => (string) ( $query['error_description'] ?? '' ),
			] );
		}

		$code = (string) ( $query['code'] ?? '' );

		if ( '' === $code ) {
			$return( [ 'error' => 'no_code', 'error_description' => 'The provider returned no authorization code.' ] );
		}

		$spec  = Providers::spec( $family );
		$reply = Http::post_form(
			$spec['token'],
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => Providers::redirect_uri( $this->config, $family ),
				'client_id'     => $this->config->require( $spec['client_id'] ),
				'client_secret' => $this->config->require( $spec['secret'] ),
			]
		);

		if ( 200 !== $reply['status'] || empty( $reply['body']['access_token'] ) ) {
			$return( [
				'error'             => (string) ( $reply['body']['error'] ?? 'exchange_failed' ),
				'error_description' => (string) ( $reply['body']['error_description'] ?? 'The authorization code could not be exchanged.' ),
			] );
		}

		// A grant with no refresh token works for an hour and then stops. Better
		// to fail now, while an administrator is still looking at the screen
		// that caused it, than to look successful and die before lunch.
		if ( empty( $reply['body']['refresh_token'] ) ) {
			$return( [
				'error'             => 'no_refresh_token',
				'error_description' => 'The provider issued no lasting credential. Try again, and if it repeats, remove this application from the account\'s third-party access and reconnect.',
			] );
		}

		$access = (string) $reply['body']['access_token'];

		$handoff = $this->store->open_handoff(
			$family,
			(string) $flow['site_id'],
			[
				'access_token'  => $access,
				'refresh_token' => (string) $reply['body']['refresh_token'],
				'expires_in'    => (int) ( $reply['body']['expires_in'] ?? 3600 ),
				'email'         => $this->address( $family, $spec, $access ),
			]
		);

		// The handoff travels in the URL; the tokens do not. Anything in a
		// redirect lands in the site's access log, the administrator's browser
		// history, and whatever Referer the next page sends - so what goes here
		// is a single-use code worth nothing after the POST that redeems it.
		$return( [ 'handoff' => $handoff ] );
	}

	/**
	 * Trade a handoff for the tokens it stands for. One use.
	 *
	 * @param array<string,mixed> $body
	 */
	public function claim( string $family, array $body ): never {
		$site_id = trim( (string) ( $body['site_id'] ?? '' ) );
		$handoff = trim( (string) ( $body['handoff'] ?? '' ) );

		if ( '' === $site_id || '' === $handoff ) {
			Http::fail( 'bad_request', 'The claim was incomplete.' );
		}

		$tokens = $this->store->take_handoff( $family, $site_id, $handoff );

		if ( null === $tokens ) {
			Http::fail( 'used_or_expired', 'That sign-in has already been used or has expired. Start the connection again.', 410 );
		}

		Http::json( $tokens );
	}

	/**
	 * Mint a fresh access token from the site's refresh token.
	 *
	 * This is the hot route: it runs whenever a site's cached access token
	 * expires, so roughly hourly per connection.
	 *
	 * @param array<string,mixed> $body
	 */
	public function refresh( string $family, array $body ): never {
		$refresh = trim( (string) ( $body['refresh_token'] ?? '' ) );

		if ( '' === $refresh ) {
			Http::fail( 'bad_request', 'No credential was sent to refresh.' );
		}

		$spec  = Providers::spec( $family );
		$reply = Http::post_form(
			$spec['token'],
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $this->config->require( $spec['client_id'] ),
				'client_secret' => $this->config->require( $spec['secret'] ),
			] + ( Providers::MICROSOFT === $family ? [ 'scope' => $spec['scope'] ] : [] )
		);

		if ( 200 !== $reply['status'] || empty( $reply['body']['access_token'] ) ) {
			$error = (string) ( $reply['body']['error'] ?? 'refresh_failed' );

			// invalid_grant is not a transient fault: the account revoked
			// access, changed its password, or the grant expired. Saying so
			// sends an administrator to reconnect instead of to wait.
			$message = 'invalid_grant' === $error
				? 'The account has withdrawn access, or its password changed. Sign in again to reconnect.'
				: (string) ( $reply['body']['error_description'] ?? 'The credential could not be refreshed.' );

			Http::fail( $error, $message, 'invalid_grant' === $error ? 401 : 502 );
		}

		Http::json(
			[
				'access_token'  => (string) $reply['body']['access_token'],
				'expires_in'    => (int) ( $reply['body']['expires_in'] ?? 3600 ),

				// Microsoft retires the old refresh token on every use. Passing
				// the replacement back matters: a site that keeps the retired
				// one works until the current window closes and then fails with
				// nothing to explain it.
				'refresh_token' => (string) ( $reply['body']['refresh_token'] ?? '' ),
			]
		);
	}

	/**
	 * Revoke a grant at the provider.
	 *
	 * @param array<string,mixed> $body
	 */
	public function revoke( string $family, array $body ): never {
		$refresh = trim( (string) ( $body['refresh_token'] ?? '' ) );
		$spec    = Providers::spec( $family );

		if ( '' !== $refresh && null !== $spec['revoke'] ) {
			Http::post_form( $spec['revoke'], [ 'token' => $refresh ] );
		}

		// Reported as done either way. Microsoft has no equivalent endpoint for
		// a single refresh token - it is withdrawn from the account's own
		// security settings - and this service stores no grant to forget, so
		// there is nothing here that can be left behind.
		Http::json( [ 'revoked' => true ] );
	}

	/**
	 * Ask the provider which mailbox just connected.
	 *
	 * Best effort. It only fills in "Connected as ..." on the settings screen,
	 * so a failure here must not cost an otherwise working connection.
	 *
	 * @param array<string,mixed> $spec
	 */
	private function address( string $family, array $spec, string $access ): string {
		$reply = Http::get_authorized( (string) $spec['profile'], $access );

		return 200 === $reply['status'] ? Providers::address( $family, $reply['body'] ) : '';
	}

	/**
	 * Whether a return address really belongs to the site that asked.
	 *
	 * Same host, and the WordPress endpoint we expect - not merely "a URL on
	 * that domain", because an open redirect or an uploads directory on the
	 * same host would otherwise qualify.
	 */
	private function callback_belongs_to_site( string $callback, string $site_url ): bool {
		$c = parse_url( $callback );
		$s = parse_url( $site_url );

		if ( ! is_array( $c ) || ! is_array( $s ) || empty( $c['host'] ) || empty( $s['host'] ) ) {
			return false;
		}

		if ( strcasecmp( (string) $c['host'], (string) $s['host'] ) !== 0 ) {
			return false;
		}

		if ( 'https' !== ( $c['scheme'] ?? '' ) && ! $this->config->allows_insecure_callbacks() ) {
			return false;
		}

		if ( ! str_ends_with( (string) ( $c['path'] ?? '' ), '/wp-admin/admin-post.php' ) ) {
			return false;
		}

		parse_str( (string) ( $c['query'] ?? '' ), $params );

		return 'mmoa_one_click_callback' === ( $params['action'] ?? '' );
	}
}
