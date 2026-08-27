<?php
/**
 * One entry in the chooser, several ways to authenticate behind it.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Presents a mail service as one choice, then asks how to connect to it.
 *
 * The chooser used to list authentication methods rather than services:
 * "Microsoft 365" and "Outlook" were two tiles, as were "Google Workspace" and
 * "Gmail". That asks the wrong question first. Somebody arriving at this screen
 * knows they want to send through Google; what they do not yet know is whether
 * their situation calls for a service account, their own OAuth client, or the
 * one-click path - and four tiles offering no way to tell them apart is a worse
 * starting point than one tile that asks afterwards.
 *
 * So a merged provider is a façade. It holds no sending logic of its own: it
 * reads the setup mode, builds the underlying transport, and forwards. Those
 * transports are unchanged and still registered under their original slugs, so
 * every path through them keeps its existing tests, and a site storing a legacy
 * slug keeps working whether or not the migration has run yet.
 *
 * What this class does own is the form. `fields()` publishes the mode selector
 * plus each transport's own fields, gated on the mode that uses them - which
 * the existing `depends` mechanism then honours in both directions: the form
 * greys them out, and the required check stops demanding them, so a credential
 * belonging to a mode you are not using cannot block a connection.
 */
abstract class Abstract_Merged_Provider implements Provider_Interface {

	public function __construct(
		protected \ModernMailer\Settings $settings,
		protected \ModernMailer\Token_Store $tokens,
		protected \ModernMailer\Http $http
	) {}

	/**
	 * The setting holding the chosen mode, e.g. `ms_setup_mode`.
	 */
	abstract protected static function mode_key(): string;

	/**
	 * Mode value => transport class.
	 *
	 * @return array<string,class-string<Provider_Interface>>
	 */
	abstract protected static function transports(): array;

	/**
	 * The mode used when nothing has been chosen.
	 */
	abstract protected static function default_mode(): string;

	/**
	 * The mode selector itself, which every merged provider renders first.
	 */
	abstract protected static function mode_field(): Field;

	/**
	 * Which mode this connection is set to.
	 */
	protected function mode(): string {
		$mode  = (string) $this->settings->get( static::mode_key() );
		$modes = static::transports();

		return isset( $modes[ $mode ] ) ? $mode : static::default_mode();
	}

	/**
	 * The transport this connection actually sends through.
	 */
	protected function delegate(): Provider_Interface {
		$class = static::transports()[ $this->mode() ];

		return new $class( $this->settings, $this->tokens, $this->http );
	}

	public function send( string $raw_mime, PHPMailer $mailer ) {
		return $this->delegate()->send( $raw_mime, $mailer );
	}

	public function verify_connection() {
		return $this->delegate()->verify_connection();
	}

	public function get_max_message_bytes(): int {
		return $this->delegate()->get_max_message_bytes();
	}

	public function get_label(): string {
		// The transport's label, not the tile's: a log line saying "Microsoft
		// 365 (Graph)" tells you which credential was used, where "Microsoft"
		// would leave you unable to tell two connections apart.
		return $this->delegate()->get_label();
	}

	/**
	 * The mode selector, followed by each transport's fields gated on its mode.
	 *
	 * A transport that declares a `*_setup_mode` field of its own is filtered
	 * out of the borrowed set - Gmail_OAuth declares one for the case where it
	 * is used directly, and two radios controlling the same setting on one form
	 * would fight each other.
	 *
	 * Where two modes declare the same field key, the first wins and keeps its
	 * own gate. That is deliberate: a shared key means a shared credential, and
	 * showing it twice would ask for the same value in two places.
	 *
	 * @return array<int,Field>
	 */
	public static function fields(): array {
		$mode_field = static::mode_field();

		// A choice of one is not a choice. With the setup service switched off
		// Microsoft has a single way in, and offering it as a radio button
		// invites someone to look for the alternative that is not there.
		//
		// Dropping the selector means dropping the gates with it. A field that
		// depends on a field the form is not rendering resolves against nothing
		// and hides itself - which is how removing one radio button silently
		// emptied the entire Microsoft form.
		$gated  = count( $mode_field->options ) > 1;
		$fields = $gated ? [ $mode_field ] : [];
		$seen   = [ static::mode_key() => true ];

		foreach ( static::transports() as $mode => $class ) {
			foreach ( $class::fields() as $field ) {
				if ( isset( $seen[ $field->key ] ) ) {
					continue;
				}

				$seen[ $field->key ] = true;

				$fields[] = $gated
					? $field->with_depends(
						[
							'field' => static::mode_key(),
							'value' => $mode,
						]
					)
					: $field->with_depends( [] );
			}
		}

		return $fields;
	}

	/**
	 * Whether the chooser lists this provider.
	 *
	 * The merged tiles are listed; the transports behind them are not, because
	 * they remain registered so that stored slugs stay constructible.
	 */
	public static function is_listed(): bool {
		return true;
	}
}
