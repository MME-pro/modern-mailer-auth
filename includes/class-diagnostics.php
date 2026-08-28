<?php
/**
 * The report attached to a failed send.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything somebody needs to diagnose one failure, gathered at the moment it
 * happened.
 *
 * The error message alone is rarely enough. "Recipient address rejected" says
 * what the far end thought; it does not say which host was dialled, on which
 * port, with which encryption, from which PHP - and those are exactly the facts
 * that turn a support thread into a fix. Gathering them later is no good
 * either, because by then the settings may have been changed in the course of
 * guessing.
 *
 * Two rules govern what goes in.
 *
 * No credentials, ever. Provider fields declared as secrets are listed by name
 * with their value replaced, so the report can say "a password is set" without
 * being a place to read it. The SMTP transcript relies on PHPMailer's own
 * masking, which is why the debug level is kept below DEBUG_LOWLEVEL - at that
 * level and above it prints the base64 of the AUTH exchange.
 *
 * And no message bodies. The log has never stored them and this does not start:
 * a mail log that keeps content is a liability the moment the site is
 * compromised, and it is not needed to diagnose delivery.
 */
class Diagnostics {

	/**
	 * Longest transcript kept, in bytes.
	 *
	 * A stuck conversation can run to megabytes, and a log table that grows
	 * without bound is its own outage. The tail is what matters - the failure
	 * is at the end - so the middle is what gets dropped.
	 */
	private const MAX_TRANSCRIPT = 16384;

	/**
	 * Build the report for one attempt.
	 *
	 * @param string        $provider   Provider slug.
	 * @param string        $slot       Connection slot the attempt used.
	 * @param WP_Error|true $result     What the provider answered.
	 * @param string        $transcript Protocol conversation, where there is one.
	 * @return array<string,mixed>
	 */
	public static function collect( Settings $settings, string $provider, string $slot, $result, string $transcript = '' ): array {
		return [
			'versions'   => self::versions(),
			'params'     => self::params( $settings, $provider, $slot ),
			'server'     => self::server(),
			'error'      => self::error( $result ),
			'transcript' => self::trim( $transcript ),
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function versions(): array {
		return [
			'WordPress'    => get_bloginfo( 'version' ),
			'Multisite'    => is_multisite() ? 'Yes' : 'No',
			'PHP'          => PHP_VERSION,
			'Modern Mailer' => VERSION,
		];
	}

	/**
	 * What the connection was configured with, minus the secrets.
	 *
	 * Read from the declared schema rather than hand-listed per provider, so a
	 * provider added later reports its settings without anybody editing this.
	 *
	 * @return array<string,string>
	 */
	private static function params( Settings $settings, string $provider, string $slot ): array {
		$scoped = $settings->for_slot( $slot );
		$class  = Provider_Registry::class_for( $provider );

		$out = [
			'Mailer'     => '' !== $provider ? $provider : '(none)',
			'Connection' => '' === $slot ? 'primary' : $slot,
		];

		if ( null === $class ) {
			return $out;
		}

		foreach ( $class::fields() as $field ) {
			if ( $field->secret ) {
				// Named, never shown. Whether a credential exists is a fact
				// worth having - half of these failures are an empty password -
				// and the value itself is never diagnostic.
				$out[ $field->label ] = '' !== $scoped->secrets()->get( $field->key ) ? '(set)' : '(empty)';
				continue;
			}

			$value = $scoped->get( $field->key );

			if ( Field::CHECKBOX === $field->type ) {
				$out[ $field->label ] = $value ? 'true' : 'false';
				continue;
			}

			// An unset value is not the same as an unused one: the provider
			// falls back to the declared default, so that is what is actually
			// in force and that is what the report has to say. Reporting
			// "(empty)" for a field the send is definitely using sends whoever
			// reads it looking for a setting nobody needed to fill in.
			$effective = '' === (string) $value ? $field->default : $value;

			// A choice is reported by its label. "Authentication: On" is what
			// the screen said; "smtp_auth: yes" is what the database said, and
			// only one of those is checkable against the form.
			if ( $field->options ) {
				$key                  = (string) $effective;
				$out[ $field->label ] = (string) ( $field->options[ $key ] ?? $key );
				continue;
			}

			$out[ $field->label ] = '' === (string) $effective ? '(empty)' : (string) $effective;
		}

		// Worth knowing before anyone edits a setting and wonders why nothing
		// changed: a value pinned in wp-config.php cannot be edited from here.
		$pinned = [];

		foreach ( $class::fields() as $field ) {
			if ( $field->secret ? $scoped->secrets()->is_constant( $field->key ) : $scoped->is_constant( $field->key ) ) {
				$pinned[] = $field->key;
			}
		}

		$out['Pinned in wp-config.php'] = $pinned ? implode( ', ', $pinned ) : 'No';

		return $out;
	}

	/**
	 * @return array<string,string>
	 */
	private static function server(): array {
		$out = [];

		if ( defined( 'OPENSSL_VERSION_TEXT' ) ) {
			$out['OpenSSL'] = OPENSSL_VERSION_TEXT;
		}

		if ( function_exists( 'curl_version' ) ) {
			$curl = curl_version();
			$out['cURL'] = is_array( $curl ) ? (string) $curl['version'] : 'unknown';
		}

		// Named because a low limit is a real cause of failures that otherwise
		// look like a network fault: the request is cut off mid-flight.
		$out['max_execution_time'] = (string) ini_get( 'max_execution_time' );
		$out['memory_limit']       = (string) ini_get( 'memory_limit' );

		return $out;
	}

	/**
	 * @param WP_Error|true $result
	 * @return array<string,string>
	 */
	private static function error( $result ): array {
		if ( ! is_wp_error( $result ) ) {
			return [];
		}

		return [
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		];
	}

	/**
	 * Keep the ends of an oversized transcript and say what was dropped.
	 *
	 * Both ends, not just the tail: the failure is at the end, but the
	 * connection and greeting at the start are what identify which server
	 * answered and what it offered.
	 */
	private static function trim( string $transcript ): string {
		if ( strlen( $transcript ) <= self::MAX_TRANSCRIPT ) {
			return $transcript;
		}

		$keep    = (int) ( self::MAX_TRANSCRIPT / 2 );
		$dropped = strlen( $transcript ) - ( $keep * 2 );

		return substr( $transcript, 0, $keep )
			. sprintf( "\n\n... %d bytes omitted ...\n\n", $dropped )
			. substr( $transcript, -$keep );
	}
}
