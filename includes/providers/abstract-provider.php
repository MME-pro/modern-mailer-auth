<?php
/**
 * Shared provider behaviour.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Http;
use ModernMailer\Settings;
use ModernMailer\Token_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Token acquisition, caching and stampede control, shared by all providers.
 */
abstract class Abstract_Provider implements Provider_Interface {

	public function __construct(
		protected Settings $settings,
		protected Token_Store $tokens,
		protected Http $http
	) {}

	/**
	 * Cache key identifying this credential set.
	 *
	 * Must change whenever the credentials change, so that rotating a secret
	 * cannot leave a stale token in play.
	 */
	abstract protected function token_cache_key(): string;

	/**
	 * Request a fresh access token from the identity provider.
	 *
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	abstract protected function request_token();

	/**
	 * Return a usable access token, minting one only when necessary.
	 *
	 * @return string|WP_Error
	 */
	protected function access_token() {
		$key    = $this->token_cache_key();
		$cached = $this->tokens->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		// Only one request per site should be talking to the token endpoint at
		// a time. Losers of the race wait for the winner's result rather than
		// duplicating the call.
		$acquired = $this->tokens->acquire_lock( $key );

		if ( ! $acquired ) {
			for ( $i = 0; $i < 10; $i++ ) {
				usleep( 200000 ); // 0.2s

				$cached = $this->tokens->get( $key );

				if ( null !== $cached ) {
					return $cached;
				}
			}

			// The holder never published a token. Fall through and mint one
			// ourselves rather than failing the send outright - but do not touch
			// the lock on the way out, because it is not ours to release.
		}

		try {
			$result = $this->request_token();

			if ( is_wp_error( $result ) ) {
				// The refresh failed, but a token we already hold may still be
				// inside its real lifetime - get() withholds it early on
				// purpose, to leave room for exactly this refresh. Spending
				// that remaining slack is strictly better than dropping an
				// email we are still authorized to send.
				//
				// This is what turns an intermittent outage at the token
				// endpoint from lost mail into a delivered message: the send
				// path never touches the network for auth at all.
				$stale = $this->tokens->get_stale( $key );

				if ( null !== $stale ) {
					/**
					 * Fires when a token refresh failed and the previous token
					 * was used instead.
					 *
					 * Worth watching: sending still works, but the site is one
					 * token lifetime away from failing outright.
					 *
					 * @param WP_Error $error Why the refresh failed.
					 * @param string   $key   Token cache key.
					 */
					do_action( 'mmoa_token_refresh_degraded', $result, $key );

					return $stale;
				}

				return $result;
			}

			$this->tokens->put( $key, $result['token'], $result['expires_in'] );

			return $result['token'];
		} finally {
			if ( $acquired ) {
				$this->tokens->release_lock( $key );
			}
		}
	}

	/**
	 * Drop any cached token. Called when the API rejects one, so the next
	 * attempt is guaranteed not to reuse it.
	 */
	protected function invalidate_token(): void {
		$this->tokens->forget( $this->token_cache_key() );
	}

	/**
	 * Decode a JSON API response body.
	 *
	 * @return array<string,mixed>
	 */
	protected function decode( string $body ): array {
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : [];
	}
}
