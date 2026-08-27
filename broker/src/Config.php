<?php
/**
 * Settings, read from the environment.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

/**
 * Everything the broker needs to know, and nothing it should hold in a file.
 *
 * The OAuth client secrets are the whole reason this service exists, so they
 * are read from the environment rather than written anywhere in the tree. On
 * shared hosting that usually means SetEnv in .htaccess or the host's control
 * panel; a .env file is supported for convenience but must live outside the
 * document root, because anything under it is one misconfigured rule away from
 * being served as plain text.
 */
final class Config {

	private array $values;

	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Where a configuration file is looked for, best first.
	 *
	 * More than one, because "outside the document root" means different paths
	 * on different hosts, and putting the file one directory off is a mistake
	 * that otherwise presents as a blank 500. The first location is the one to
	 * prefer and the one the documentation gives; the second is accepted
	 * because it is the obvious guess, and broker/.htaccess denies it to the
	 * web anyway.
	 *
	 * @return array<int,string>
	 */
	public static function candidates( string $public_dir ): array {
		return [
			$public_dir . '/../../.env.broker',
			$public_dir . '/../.env.broker',

			// Inside the document root. Last, and only reachable because some
			// shared hosting will not let a subdomain's root move above
			// public_html. It is protected by the deny rules in .htaccess
			// rather than by being unreachable, which is weaker - a host that
			// ignores .htaccess would serve it as text.
			$public_dir . '/.env.broker',
		];
	}

	/**
	 * Which candidate actually exists, for reporting.
	 */
	public static function found_in( string $public_dir ): string {
		foreach ( self::candidates( $public_dir ) as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Read configuration from the environment, and from an optional .env file.
	 */
	public static function load( ?string $env_file = null ): self {
		$values = [];

		if ( null !== $env_file && is_readable( $env_file ) ) {
			foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
				$line = trim( $line );

				if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
					continue;
				}

				[ $key, $value ] = explode( '=', $line, 2 );

				$values[ trim( $key ) ] = trim( trim( $value ), "\"'" );
			}
		}

		// A real environment variable always wins over the file, so a host's
		// control panel can override a value without editing anything.
		foreach ( array_keys( $values ) + [] as $key ) {
			$live = getenv( $key );

			if ( false !== $live && '' !== $live ) {
				$values[ $key ] = $live;
			}
		}

		foreach ( self::KEYS as $key ) {
			$live = getenv( $key );

			if ( false !== $live && '' !== $live ) {
				$values[ $key ] = $live;
			}
		}

		return new self( $values );
	}

	private const KEYS = [
		'BROKER_BASE_URL',
		'BROKER_KEY',
		'DB_DSN',
		'DB_USER',
		'DB_PASS',
		'GOOGLE_CLIENT_ID',
		'GOOGLE_CLIENT_SECRET',
		'MICROSOFT_CLIENT_ID',
		'MICROSOFT_CLIENT_SECRET',
		'ALLOW_INSECURE_CALLBACKS',
	];

	public function get( string $key, string $default = '' ): string {
		return (string) ( $this->values[ $key ] ?? $default );
	}

	public function require( string $key ): string {
		$value = $this->get( $key );

		if ( '' === $value ) {
			throw new \RuntimeException( "Missing required configuration: {$key}" );
		}

		return $value;
	}

	/**
	 * Where this service answers, with a trailing slash.
	 *
	 * Used to build the redirect URI registered with Google and Microsoft, so
	 * it has to match what they have on file character for character.
	 */
	public function base_url(): string {
		return rtrim( $this->require( 'BROKER_BASE_URL' ), '/' ) . '/';
	}

	/**
	 * The 32-byte key that encrypts tokens while a handoff is outstanding.
	 */
	public function key(): string {
		$key = base64_decode( $this->require( 'BROKER_KEY' ), true );

		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \RuntimeException( 'BROKER_KEY must be 32 random bytes, base64 encoded.' );
		}

		return $key;
	}

	/**
	 * Whether a plain-HTTP callback is accepted.
	 *
	 * Off by default, and should stay off in production: a handoff code
	 * travelling over plain HTTP can be read in transit, and it is worth real
	 * tokens for the few minutes it lives. On for local development only, where
	 * WordPress sites routinely have no certificate.
	 */
	public function allows_insecure_callbacks(): bool {
		return in_array( strtolower( $this->get( 'ALLOW_INSECURE_CALLBACKS' ) ), [ '1', 'true', 'yes' ], true );
	}
}
