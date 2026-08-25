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
	 * The two built-in connection slots.
	 *
	 * Any other string is an additional connection, stored under its own
	 * prefix. Nothing here is limited to two - the prefix mechanism was always
	 * general, and additional connections use it unchanged.
	 */
	public const SLOT_PRIMARY = '';
	public const SLOT_BACKUP  = 'backup';

	/**
	 * Keys that describe one connection, and so exist once per slot.
	 *
	 * Setting key => [ default, constant, sanitizer ]. For the backup slot the
	 * storage key and the constant both gain a prefix, so
	 * `ms_tenant_id` / MMOA_MS_TENANT_ID becomes
	 * `backup_ms_tenant_id` / MMOA_BACKUP_MS_TENANT_ID.
	 */
	private const CONNECTION_SCHEMA = [
		'provider'          => [ self::PROVIDER_NONE, 'MMOA_PROVIDER', 'provider' ],

		'ms_tenant_id'      => [ '', 'MMOA_MS_TENANT_ID', 'text' ],
		'ms_client_id'      => [ '', 'MMOA_MS_CLIENT_ID', 'text' ],
		'ms_sender'         => [ '', 'MMOA_MS_SENDER', 'email' ],
		'ms_secret_expires' => [ 0, null, 'int' ],
		'ms_policy_ack'     => [ false, null, 'bool' ],

		'google_sa_email'   => [ '', 'MMOA_GOOGLE_SA_CLIENT_EMAIL', 'text' ],
		'google_sender'     => [ '', 'MMOA_GOOGLE_SENDER', 'email' ],
		'google_client_id'  => [ '', 'MMOA_GOOGLE_CLIENT_ID', 'text' ],
	];

	/**
	 * Keys that belong to the site, not to a connection.
	 *
	 * These stay readable through a slot-scoped instance - a provider asking
	 * for `from_email` gets the site's one answer no matter which slot it is
	 * sending from, which is what makes providers slot-agnostic.
	 */
	private const GLOBAL_SCHEMA = [
		'from_email'      => [ '', 'MMOA_FROM_EMAIL', 'email' ],
		'from_name'       => [ '', 'MMOA_FROM_NAME', 'text' ],
		'force_from'      => [ true, null, 'bool' ],

		'log_enabled'     => [ true, null, 'bool' ],
		'log_retention'   => [ 30, null, 'int' ],
		'alert_threshold' => [ 3, null, 'int' ],
		'alert_email'     => [ '', 'MMOA_ALERT_EMAIL', 'email' ],

		'queue_enabled'   => [ true, null, 'bool' ],

		// Additional connections beyond primary and backup: [ id => name ].
		// Only the names live here; every credential a connection holds is
		// stored under its own slot prefix by the same mechanism the backup
		// already uses.
		'connections'     => [ [], null, 'list' ],

		'routing_enabled' => [ false, null, 'bool' ],
		'routing_rules'   => [ [], null, 'list' ],
	];

	/**
	 * Shared across instances on purpose.
	 *
	 * Every slot reads and writes the one option row, so a per-instance cache
	 * would let a write through the primary view leave a backup view holding
	 * stale values for the rest of the request. There is exactly one copy of
	 * this data, so there is exactly one cache of it.
	 */
	private static ?array $cache = null;

	public function __construct( private Secrets $secrets, private string $slot = self::SLOT_PRIMARY ) {}

	public function secrets(): Secrets {
		return $this->secrets;
	}

	public function slot(): string {
		return $this->slot;
	}

	/**
	 * A view of these settings scoped to one connection slot.
	 *
	 * Providers are handed one of these and never learn which slot they are.
	 * That is the point: Graph reading `ms_tenant_id` resolves to the primary
	 * or the backup credential purely by which view it was constructed with,
	 * so the backup connection needed no provider changes at all.
	 */
	public function for_slot( string $slot ): Settings {
		if ( $slot === $this->slot ) {
			return $this;
		}

		return new self( $this->secrets->for_slot( $slot ), $slot );
	}

	/**
	 * Storage key for a setting in this slot.
	 */
	private function storage_key( string $key ): string {
		// Everything that is not explicitly site-wide belongs to a connection,
		// including the fields providers declare for themselves. Testing the
		// other way round - "is it a known connection key" - would silently
		// leave provider fields unprefixed, and the backup connection would
		// then write its credentials straight over the primary's.
		if ( self::SLOT_PRIMARY === $this->slot || isset( self::GLOBAL_SCHEMA[ $key ] ) ) {
			return $key;
		}

		return $this->slot . '_' . $key;
	}

	/**
	 * @return array{0:mixed,1:?string,2:string}|null
	 */
	private function schema( string $key ): ?array {
		$entry = self::CONNECTION_SCHEMA[ $key ] ?? self::GLOBAL_SCHEMA[ $key ] ?? null;

		// Anything a provider declares but this class has never heard of is a
		// connection setting too. Without this, adding a provider would mean
		// editing the schema here as well as writing the class, and the two
		// would drift - a field the provider reads but Settings refuses to
		// store fails silently and looks like a save bug.
		if ( null === $entry ) {
			$entry = $this->provider_field_schema( $key );
		}

		if ( null === $entry ) {
			return null;
		}

		// Only connection keys move between slots, and only they take a
		// prefixed constant.
		if ( self::SLOT_PRIMARY !== $this->slot && ! isset( self::GLOBAL_SCHEMA[ $key ] ) && null !== $entry[1] ) {
			$entry[1] = 'MMOA_' . strtoupper( $this->slot ) . '_' . substr( $entry[1], 5 );
		}

		return $entry;
	}

	/**
	 * Derive a schema entry from a provider's declared field.
	 *
	 * Credentials are excluded on purpose: a field marked secret belongs to
	 * Secrets, which encrypts it. Falling through to here would store an API
	 * key in the clear in the options table, so the two stores are kept
	 * strictly disjoint rather than merely conventionally so.
	 *
	 * @return array{0:mixed,1:?string,2:string}|null
	 */
	private function provider_field_schema( string $key ): ?array {
		$field = Provider_Registry::all_fields()[ $key ] ?? null;

		if ( null === $field || $field->secret ) {
			return null;
		}

		$types = [
			Field::EMAIL    => 'email',
			Field::NUMBER   => 'int',
			Field::CHECKBOX => 'bool',
		];

		return [
			$field->default,
			'' !== $field->constant ? 'MMOA_' . $field->constant : 'MMOA_' . strtoupper( $key ),
			$types[ $field->type ] ?? 'text',
		];
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$schema = $this->schema( $key );

		if ( null === $schema ) {
			return null;
		}

		[ $default, $constant, $type ] = $schema;

		if ( $constant && defined( $constant ) ) {
			return $this->sanitize_value( constant( $constant ), $type );
		}

		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = is_array( $stored ) ? $stored : [];
		}

		$storage = $this->storage_key( $key );

		return array_key_exists( $storage, self::$cache )
			? $this->sanitize_value( self::$cache[ $storage ], $type )
			: $default;
	}

	/**
	 * Is this setting pinned by a wp-config.php constant?
	 */
	public function is_constant( string $key ): bool {
		$constant = $this->schema( $key )[1] ?? null;

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
			$schema = $this->schema( $key );

			if ( null === $schema || $this->is_constant( $key ) ) {
				continue;
			}

			$stored[ $this->storage_key( $key ) ] = $this->sanitize_value( $value, $schema[2] );
		}

		update_option( self::OPTION, $stored, true );
		self::$cache = $stored;
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
	 * Is a backup connection configured?
	 */
	public function has_backup(): bool {
		return self::PROVIDER_NONE !== $this->for_slot( self::SLOT_BACKUP )->get( 'provider' );
	}

	/**
	 * Drop the shared read cache.
	 *
	 * Needed by the test suite, which rewrites settings out from under a live
	 * instance, and harmless in production.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * @return array<string,string> Provider slug => human label.
	 */
	public static function provider_labels(): array {
		return Provider_Registry::labels();
	}

	/**
	 * Sanitize a nested array of scalars.
	 *
	 * Keys are restricted to the characters a structured payload actually needs,
	 * so a crafted key cannot become anything surprising when this is read back
	 * and iterated. Depth is capped for the same reason a recursive walk over
	 * untrusted input always should be.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<mixed>
	 */
	private function sanitize_list( array $value, int $depth = 0 ): array {
		if ( $depth > 6 ) {
			return [];
		}

		$out = [];

		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? $key : preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );

			if ( '' === $key && ! is_int( $key ) ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = $this->sanitize_list( $item, $depth + 1 );
			} elseif ( is_bool( $item ) ) {
				$out[ $key ] = $item;
			} elseif ( is_int( $item ) || is_float( $item ) ) {
				$out[ $key ] = $item;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $item );
			}
		}

		return $out;
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
			case 'list':
				// Nested arrays - the connection list and the routing rules.
				// Sanitized recursively rather than trusted, because these
				// arrive from the admin app as JSON and end up in an option
				// that other code reads back as structured data.
				return is_array( $value ) ? $this->sanitize_list( $value ) : [];
			case 'provider':
				return array_key_exists( (string) $value, self::provider_labels() )
					? (string) $value
					: self::PROVIDER_NONE;
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
