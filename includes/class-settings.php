<?php
/**
 * Settings storage and schema.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Non-secret settings, with wp-config.php constants taking precedence.
 *
 * Credentials do not live here - see Secrets.
 */
class Settings {

	public const OPTION = 'mmoa_settings';

	public const PROVIDER_NONE         = '';
	public const PROVIDER_GRAPH        = 'graph';
	public const PROVIDER_GMAIL_SA     = 'gmail_sa';
	public const PROVIDER_GMAIL_OAUTH  = 'gmail_oauth';

	/**
	 * Setting key => [ default, constant, sanitizer ].
	 */
	private const SCHEMA = [
		'provider'          => [ self::PROVIDER_NONE, 'MMOA_PROVIDER', 'provider' ],
		'from_email'        => [ '', 'MMOA_FROM_EMAIL', 'email' ],
		'from_name'         => [ '', 'MMOA_FROM_NAME', 'text' ],
		'force_from'        => [ true, null, 'bool' ],

		'ms_tenant_id'      => [ '', 'MMOA_MS_TENANT_ID', 'text' ],
		'ms_client_id'      => [ '', 'MMOA_MS_CLIENT_ID', 'text' ],
		'ms_sender'         => [ '', 'MMOA_MS_SENDER', 'email' ],
		'ms_secret_expires' => [ 0, null, 'int' ],
		'ms_policy_ack'     => [ false, null, 'bool' ],

		'google_sa_email'   => [ '', 'MMOA_GOOGLE_SA_CLIENT_EMAIL', 'text' ],
		'google_sender'     => [ '', 'MMOA_GOOGLE_SENDER', 'email' ],
		'google_client_id'  => [ '', 'MMOA_GOOGLE_CLIENT_ID', 'text' ],

		'log_enabled'       => [ true, null, 'bool' ],
		'log_retention'     => [ 30, null, 'int' ],
		'alert_threshold'   => [ 3, null, 'int' ],
		'alert_email'       => [ '', 'MMOA_ALERT_EMAIL', 'email' ],
	];

	private ?array $cache = null;

	public function __construct( private Secrets $secrets ) {}

	public function secrets(): Secrets {
		return $this->secrets;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		if ( ! isset( self::SCHEMA[ $key ] ) ) {
			return null;
		}

		[ $default, $constant, $type ] = self::SCHEMA[ $key ];

		if ( $constant && defined( $constant ) ) {
			return $this->sanitize_value( constant( $constant ), $type );
		}

		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = is_array( $stored ) ? $stored : [];
		}

		return array_key_exists( $key, $this->cache )
			? $this->sanitize_value( $this->cache[ $key ], $type )
			: $default;
	}

	/**
	 * Is this setting pinned by a wp-config.php constant?
	 */
	public function is_constant( string $key ): bool {
		$constant = self::SCHEMA[ $key ][1] ?? null;

		return $constant && defined( $constant );
	}

	/**
	 * Merge and persist. Keys pinned by constants are skipped.
	 *
	 * @param array<string,mixed> $values Raw, unsanitized input.
	 */
	public function update( array $values ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $values as $key => $value ) {
			if ( ! isset( self::SCHEMA[ $key ] ) || $this->is_constant( $key ) ) {
				continue;
			}

			$stored[ $key ] = $this->sanitize_value( $value, self::SCHEMA[ $key ][2] );
		}

		update_option( self::OPTION, $stored, true );
		$this->cache = $stored;
	}

	/**
	 * Should the plugin take over wp_mail()?
	 *
	 * False means we stay out of the way and core sends normally - which is
	 * what we want on a fresh install, and locally where Mailpit is catching
	 * mail. Never intercept a send we cannot actually complete.
	 */
	public function is_active(): bool {
		return self::PROVIDER_NONE !== $this->get( 'provider' );
	}

	/**
	 * @return array<string,string> Provider slug => human label.
	 */
	public static function provider_labels(): array {
		return [
			self::PROVIDER_NONE        => __( 'Not configured (WordPress default)', 'modern-mailer-oauth' ),
			self::PROVIDER_GRAPH       => __( 'Microsoft 365 (Graph, app-only)', 'modern-mailer-oauth' ),
			self::PROVIDER_GMAIL_SA    => __( 'Google Workspace (service account)', 'modern-mailer-oauth' ),
			self::PROVIDER_GMAIL_OAUTH => __( 'Gmail (OAuth user consent)', 'modern-mailer-oauth' ),
		];
	}

	/**
	 * @param mixed $value Raw value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_value( $value, string $type ) {
		switch ( $type ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				return (int) $value;
			case 'email':
				return sanitize_email( (string) $value );
			case 'provider':
				return array_key_exists( (string) $value, self::provider_labels() )
					? (string) $value
					: self::PROVIDER_NONE;
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
