<?php
/**
 * The catalogue of available transports.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Knows every provider, without any of them being mentioned anywhere else.
 *
 * Before this existed the provider list was a literal array inside Dispatcher
 * and a second literal inside Settings, and adding a transport meant editing
 * both plus the settings view. With eighteen of them that arrangement stops
 * being tenable: the lists drift, and a provider that is selectable but not
 * constructible - or the reverse - is a silent failure.
 *
 * Now there is one list. Everything else - the chooser, the field forms, the
 * REST API, validation of a stored value - is derived from it.
 */
class Provider_Registry {

	/**
	 * Categories, in the order the chooser shows them.
	 *
	 * The ordering is a recommendation, not decoration. OAuth transports have
	 * no shared secret sitting in a database and nothing that silently expires,
	 * so they belong at the top; SMTP is last because it is the fallback that
	 * works everywhere and is the slowest and least diagnosable of the lot.
	 */
	public const CATEGORIES = [
		'oauth' => 'OAuth (recommended)',
		'api'   => 'API services',
		'smtp'  => 'SMTP',
	];

	/**
	 * @var array<string,class-string<Provider_Interface>>|null
	 */
	private static ?array $map = null;

	/**
	 * Every registered provider, slug => class.
	 *
	 * @return array<string,class-string<Provider_Interface>>
	 */
	public static function all(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$classes = [
			Providers\Graph::class,
			Providers\Gmail_Service_Account::class,
			Providers\Gmail_OAuth::class,
			Providers\Sendgrid::class,
			Providers\Postmark::class,
			Providers\Mailgun::class,
			Providers\Brevo::class,
			Providers\Smtp2go::class,
			Providers\Resend::class,
			Providers\Smtp::class,
		];

		/**
		 * Register an additional transport.
		 *
		 * The contract is Provider_Interface and a constructor taking
		 * (Settings, Token_Store, Http). Anything satisfying that works
		 * everywhere a built-in does, including in the admin app, because the
		 * UI is generated from the declared fields rather than hand-written.
		 *
		 * @param array<int,class-string<Provider_Interface>> $classes Provider classes.
		 */
		$classes = (array) apply_filters( 'mmoa_providers', $classes );

		$map = [];

		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}

			if ( ! in_array( Provider_Interface::class, class_implements( $class ) ?: [], true ) ) {
				continue;
			}

			$map[ $class::slug() ] = $class;
		}

		self::$map = $map;

		return self::$map;
	}

	/**
	 * @return class-string<Provider_Interface>|null
	 */
	public static function class_for( string $slug ): ?string {
		return self::all()[ $slug ] ?? null;
	}

	public static function exists( string $slug ): bool {
		return isset( self::all()[ $slug ] );
	}

	/**
	 * Slug => human label, for a chooser.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$out = [ '' => __( 'Not configured (WordPress default)', 'modern-mailer-oauth' ) ];

		foreach ( self::all() as $slug => $class ) {
			$out[ $slug ] = $class::describe()['label'];
		}

		return $out;
	}

	/**
	 * Every field any provider declares, keyed by field key.
	 *
	 * Settings and Secrets need this to know which storage keys exist and which
	 * of them are credentials. Keys are shared where the meaning is shared - two
	 * providers both wanting `api_key` get one field - so the same credential
	 * does not have to be entered twice when switching between them.
	 *
	 * @return array<string,Field>
	 */
	public static function all_fields(): array {
		$out = [];

		foreach ( self::all() as $class ) {
			foreach ( $class::fields() as $field ) {
				$out[ $field->key ] = $field;
			}
		}

		return $out;
	}

	/**
	 * The catalogue as the admin app consumes it.
	 *
	 * @param Settings $settings Slot-scoped settings, so values reflect the connection being edited.
	 * @return array<int,array<string,mixed>>
	 */
	public static function to_array( Settings $settings ): array {
		$out = [];

		foreach ( self::all() as $slug => $class ) {
			$meta   = $class::describe();
			$fields = [];

			foreach ( $class::fields() as $field ) {
				$fields[] = $field->secret
					? $field->to_array(
						null,
						'' !== $settings->secrets()->get( $field->key ),
						$settings->secrets()->is_constant( $field->key )
					)
					: $field->to_array(
						$settings->get( $field->key ),
						false,
						$settings->is_constant( $field->key )
					);
			}

			$out[] = array_merge(
				$meta,
				[
					'slug'   => $slug,
					'fields' => $fields,
				]
			);
		}

		return $out;
	}

	/**
	 * Drop the memoized list. For tests, and for anything hooking late.
	 */
	public static function flush(): void {
		self::$map = null;
	}
}
