<?php
/**
 * Credential storage.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes credentials.
 *
 * Constants in wp-config.php win over anything in the database. That is the
 * recommended production setup: it keeps credentials out of DB dumps entirely.
 *
 * The database fallback is encrypted with sodium_crypto_secretbox, keyed from
 * the site's AUTH_KEY/SECURE_AUTH_SALT. Be clear-eyed about what this buys:
 * anyone who can read wp-config.php can derive the key, so this is not
 * protection against filesystem access. It protects against database-only
 * exposure - SQL injection, a leaked backup, a shared staging dump - which is
 * the realistic threat for a WordPress site.
 */
class Secrets {

	/**
	 * Settings key => wp-config.php constant.
	 */
	private const CONSTANTS = [
		'ms_client_secret'  => 'MMOA_MS_CLIENT_SECRET',
		'ms_certificate'    => 'MMOA_MS_CERTIFICATE',
		'google_sa_key'     => 'MMOA_GOOGLE_SA_PRIVATE_KEY',
		'google_client_sec' => 'MMOA_GOOGLE_CLIENT_SECRET',
		'google_refresh'    => 'MMOA_GOOGLE_REFRESH_TOKEN',
	];

	private const OPTION = 'mmoa_secrets';

	/**
	 * Which connection's credentials this instance reads and writes.
	 *
	 * Mirrors Settings::$slot exactly, and for the same reason: providers ask
	 * for `ms_client_secret` and the slot decides which one that is.
	 */
	public function __construct( private string $slot = '' ) {}

	/**
	 * A view of these credentials scoped to one connection slot.
	 */
	public function for_slot( string $slot ): Secrets {
		return $slot === $this->slot ? $this : new self( $slot );
	}

	/**
	 * Storage key for a credential in this slot.
	 */
	private function storage_key( string $key ): string {
		return '' === $this->slot ? $key : $this->slot . '_' . $key;
	}

	/**
	 * The wp-config.php constant for a credential in this slot, or null.
	 */
	private function constant_for( string $key ): ?string {
		$constant = self::CONSTANTS[ $key ] ?? null;

		// The map above covers the credentials whose constant name does not
		// follow from the key - MMOA_GOOGLE_SA_PRIVATE_KEY for google_sa_key
		// and so on. Everything a provider declares as secret gets the derived
		// name, so a new provider needs no entry here at all.
		if ( null === $constant ) {
			$field = Provider_Registry::all_fields()[ $key ] ?? null;

			if ( null !== $field && $field->secret ) {
				$constant = '' !== $field->constant
					? 'MMOA_' . $field->constant
					: 'MMOA_' . strtoupper( $key );
			}
		}

		if ( null === $constant || '' === $this->slot ) {
			return $constant;
		}

		return 'MMOA_' . strtoupper( $this->slot ) . '_' . substr( $constant, 5 );
	}

	/**
	 * Is this credential pinned by a constant?
	 */
	public function is_constant( string $key ): bool {
		$constant = $this->constant_for( $key );

		return null !== $constant && defined( $constant );
	}

	/**
	 * Read a credential. Returns '' when unset.
	 */
	public function get( string $key ): string {
		$constant = $this->constant_for( $key );

		if ( null !== $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}

		$stored  = get_option( self::OPTION, [] );
		$storage = $this->storage_key( $key );

		if ( ! is_array( $stored ) || ! isset( $stored[ $storage ] ) ) {
			return '';
		}

		return $this->decrypt( (string) $stored[ $storage ] );
	}

	/**
	 * Write a credential. Passing '' removes it.
	 *
	 * Silently ignores writes to a key that a constant already pins, so the
	 * settings screen can never mislead an admin into thinking a form value
	 * took effect when wp-config.php is overriding it.
	 */
	public function set( string $key, string $value ): void {
		if ( $this->is_constant( $key ) ) {
			return;
		}

		$stored  = get_option( self::OPTION, [] );
		$stored  = is_array( $stored ) ? $stored : [];
		$storage = $this->storage_key( $key );

		if ( '' === $value ) {
			unset( $stored[ $storage ] );
		} else {
			$stored[ $storage ] = $this->encrypt( $value );
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Remove every stored credential. Used on uninstall and on "disconnect".
	 */
	public function flush(): void {
		delete_option( self::OPTION );
	}

	/**
	 * True when libsodium is available, i.e. values are genuinely encrypted.
	 */
	public function is_encryption_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'random_bytes' )
			&& defined( 'AUTH_KEY' )
			&& defined( 'SECURE_AUTH_SALT' );
	}

	private function key(): string {
		return hash( 'sha256', AUTH_KEY . '|mmoa|' . SECURE_AUTH_SALT, true );
	}

	private function encrypt( string $plaintext ): string {
		if ( ! $this->is_encryption_available() ) {
			return 'plain:' . base64_encode( $plaintext );
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );

		return 'v1:' . base64_encode( $nonce . $cipher );
	}

	private function decrypt( string $stored ): string {
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ), true );
		}

		if ( 0 !== strpos( $stored, 'v1:' ) || ! $this->is_encryption_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 3 ), true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// Returns false if the salts changed since the value was written, which
		// is a real scenario on site migrations - treat it as "not configured"
		// rather than fataling.
		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );

		return false === $plain ? '' : $plain;
	}
}
