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
			// The two merged tiles, and behind them the transports they
			// delegate to. Those stay registered but unlisted, so a stored slug
			// remains constructible even before the migration has run.
			Providers\Microsoft::class,
			Providers\Google::class,

			// The order here is the order of the chooser, so it is a display
			// decision rather than an implementation one: the two finished
			// providers first, then the rest in the order they are being
			// worked through.
			Providers\Sendgrid::class,
			Providers\Resend::class,
			Providers\Brevo::class,
			Providers\Postmark::class,
			Providers\Mailgun::class,
			Providers\Smtp2go::class,
			Providers\Smtp::class,

			// Unlisted, so their position never shows. They sit last so that
			// reordering the tiles above cannot accidentally disturb them.
			Providers\Graph::class,
			Providers\Outlook::class,
			Providers\Gmail_Service_Account::class,
			Providers\Gmail_OAuth::class,
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

			// A provider may declare itself unavailable on this site. Outlook
			// does, when the setup service it depends on has been filtered
			// away: listing a transport that cannot obtain a credential would
			// let someone select it and then discover it never works.
			//
			// Not part of Provider_Interface, because the answer is yes for
			// every provider that does not say otherwise, and adding a method
			// to the contract that nine of ten implementations would define
			// identically is a worse trade than this check.
			if ( method_exists( $class, 'is_available' ) && ! $class::is_available() ) {
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
		$out     = [];
		$current = (string) $settings->get( 'provider' );

		foreach ( self::all() as $slug => $class ) {
			// Transports behind a merged tile are not offered as choices of
			// their own. The exception is a connection already storing one:
			// dropping it from the catalogue would leave the chooser with
			// nothing selected and the form empty, which reads as a connection
			// that lost its settings.
			if ( ! self::is_listed( $class ) && $slug !== $current ) {
				continue;
			}

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
	 * Whether a provider is offered in the chooser.
	 *
	 * Defaults to yes for anything that does not say otherwise, so a provider
	 * registered through the mmoa_providers filter needs no knowledge of this.
	 *
	 * @param class-string<Provider_Interface> $class Provider class.
	 */
	private static function is_listed( string $class ): bool {
		return ! method_exists( $class, 'is_listed' ) || $class::is_listed();
	}

	/**
	 * Drop the memoized list. For tests, and for anything hooking late.
	 */
	public static function flush(): void {
		self::$map = null;
	}
}
