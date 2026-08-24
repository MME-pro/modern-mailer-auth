<?php
/**
 * Access token cache.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Caches short-lived access tokens.
 *
 * Deliberately option-backed rather than transient-backed. Under an external
 * object cache a transient is evictable at any moment, and an eviction here
 * means a token round-trip on the very next email - on a busy site that is a
 * measurable latency tax and a fast route into API throttling. Tokens are
 * small and rewritten roughly hourly, so an option is the right home.
 */
class Token_Store {

	private const OPTION = 'mmoa_tokens';

	/** Refresh this many seconds before actual expiry. */
	private const SKEW = 300;

	/** A lock older than this is assumed to belong to a dead request. */
	private const LOCK_TTL = 30;

	/**
	 * Return a cached token that is still comfortably valid, or null.
	 */
	public function get( string $key ): ?string {
		$all = $this->all();

		if ( ! isset( $all[ $key ]['token'], $all[ $key ]['expires_at'] ) ) {
			return null;
		}

		if ( time() >= ( (int) $all[ $key ]['expires_at'] - self::SKEW ) ) {
			return null;
		}

		return (string) $all[ $key ]['token'];
	}

	/**
	 * Return a cached token that has entered the refresh window but has not
	 * actually expired yet, or null.
	 *
	 * This is the last line of defence when the identity provider is
	 * unreachable. `get()` deliberately stops handing out a token SKEW seconds
	 * before real expiry so that a refresh happens while there is still slack;
	 * but if that refresh cannot complete - the token endpoint is down, DNS is
	 * failing, the network is filtered - the old token is still cryptographically
	 * valid and the API will still accept it.
	 *
	 * Failing the send in that window means losing an email we had everything
	 * needed to deliver. Callers must only reach for this after a refresh has
	 * actually failed, never in place of one.
	 */
	public function get_stale( string $key ): ?string {
		$all = $this->all();

		if ( ! isset( $all[ $key ]['token'], $all[ $key ]['expires_at'] ) ) {
			return null;
		}

		// No skew here - the only question is whether it is genuinely still
		// valid. A second of headroom is left so we never hand back a token
		// that expires between here and the API reading it.
		if ( time() >= ( (int) $all[ $key ]['expires_at'] - 1 ) ) {
			return null;
		}

		return (string) $all[ $key ]['token'];
	}

	/**
	 * @param int $expires_in Lifetime in seconds, as reported by the provider.
	 */
	public function put( string $key, string $token, int $expires_in ): void {
		$all = $this->all();

		$all[ $key ] = [
			'token'      => $token,
			'expires_at' => time() + max( 60, $expires_in ),
		];

		update_option( self::OPTION, $all, false );
	}

	public function forget( string $key ): void {
		$all = $this->all();
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
	}

	public function flush(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Try to claim the right to refresh this token.
	 *
	 * Uses add_option(), which is backed by an INSERT against a UNIQUE column -
	 * so concurrent requests cannot both succeed. Without this, a burst of
	 * traffic arriving just after expiry sends every worker to the token
	 * endpoint simultaneously, which is exactly the shape that trips rate
	 * limiting.
	 */
	public function acquire_lock( string $key ): bool {
		$name = $this->lock_name( $key );

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		$held = (int) get_option( $name, 0 );

		if ( $held && ( time() - $held ) > self::LOCK_TTL ) {
			// Previous holder died mid-refresh. Take over.
			delete_option( $name );

			return add_option( $name, time(), '', false );
		}

		return false;
	}

	public function release_lock( string $key ): void {
		delete_option( $this->lock_name( $key ) );
	}

	private function lock_name( string $key ): string {
		return 'mmoa_lock_' . md5( $key );
	}

	/**
	 * @return array<string,array{token:string,expires_at:int}>
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}
}
