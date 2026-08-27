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
 * secretbox rather than plain encryption: it authenticates as well as encrypts,
 * so a modified row fails to open instead of decrypting to something attacker-
 * chosen.
 */
final class Crypto {

	public function __construct( private string $key ) {}

	public function seal( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key ) );
	}

	/**
	 * Decrypt, or null if the value was truncated, tampered with, or written
	 * under a different key.
	 */
	public function open( string $sealed ): ?string {
		$raw = base64_decode( $sealed, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plain = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		return false === $plain ? null : $plain;
	}
}
