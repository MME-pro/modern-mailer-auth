<?php
/**
 * Encryption for tokens in transit through the database.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

/**
 * Authenticated encryption, keyed from BROKER_KEY.
 *
 * A handoff holds real Google or Microsoft tokens for up to five minutes. That
 * window is short, but a database backup taken during it, or a dump obtained
 * later, would otherwise contain working credentials for someone's mailbox.
 *
 * Two backends, and the choice is made by what the host actually has rather
 * than by preference. libsodium is first where it exists. Where it does not,
 * AES-256-GCM through OpenSSL does the same job - both authenticate as well as
 * encrypt, so a modified row fails to open rather than decrypting to something
 * an attacker chose.
 *
 * The fallback is not a compromise made for tidiness. Shared hosting ships
 * whatever set of extensions it ships: on the host this was first deployed to,
 * sodium.so existed for five PHP versions and was enabled for two, and none of
 * them was the one serving the site. Requiring it would have meant a service
 * that could not run at all, for a reason its operator could do nothing about
 * from the command line.
 *
 * Every value carries a prefix naming the backend that wrote it, so a host that
 * gains or loses sodium later can still read what is already stored.
 */
final class Crypto {

	private const SODIUM = 's1:';
	private const OPENSSL = 'o1:';

	/** AES-256-GCM's tag, in bytes. */
	private const TAG_BYTES = 16;

	public function __construct( private string $key ) {}

	/**
	 * Whether this host can encrypt at all.
	 *
	 * Reported by /health, so a missing extension is named before anyone tries
	 * to connect an account rather than after.
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			|| ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) );
	}

	/**
	 * The backend that would be used, for reporting.
	 */
	public static function backend(): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'sodium';
		}

		return function_exists( 'openssl_encrypt' ) ? 'openssl (aes-256-gcm)' : 'none';
	}

	public function seal( string $plaintext ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key ) );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'Neither sodium nor openssl is available; tokens cannot be encrypted.' );
		}

		$iv  = random_bytes( 12 );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES );

		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'Encryption failed.' );
		}

		return self::OPENSSL . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt, or null if the value was truncated, tampered with, or written
	 * under a different key.
	 */
	public function open( string $sealed ): ?string {
		// The prefix says which backend wrote it, so a host that gains or loses
		// sodium can still read rows written before the change.
		if ( str_starts_with( $sealed, self::SODIUM ) ) {
			return $this->open_sodium( substr( $sealed, strlen( self::SODIUM ) ) );
		}

		if ( str_starts_with( $sealed, self::OPENSSL ) ) {
			return $this->open_openssl( substr( $sealed, strlen( self::OPENSSL ) ) );
		}

		// Written before the prefix existed, which only ever meant sodium.
		return $this->open_sodium( $sealed );
	}

	private function open_sodium( string $encoded ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}

		$raw = base64_decode( $encoded, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key
		);

		return false === $plain ? null : $plain;
	}

	private function open_openssl( string $encoded ): ?string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$raw = base64_decode( $encoded, true );

		// Less than, not less-than-or-equal: an empty plaintext encrypts to
		// exactly the IV plus the tag, and rejecting that length would make an
		// empty value indistinguishable from a corrupt one.
		if ( false === $raw || strlen( $raw ) < 12 + self::TAG_BYTES ) {
			return null;
		}

		$plain = openssl_decrypt(
			substr( $raw, 12 + self::TAG_BYTES ),
			'aes-256-gcm',
			$this->key,
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ),
			substr( $raw, 12, self::TAG_BYTES )
		);

		return false === $plain ? null : $plain;
	}
}
