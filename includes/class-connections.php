<?php
/**
 * The set of configured connections.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Names and identifies every connection the site can send through.
 *
 * Primary and Backup are built in and cannot be removed: primary is what sends
 * when nothing else applies, and backup is the fallback the dispatcher reaches
 * for when a send fails. Everything beyond those two exists so a routing rule
 * has somewhere to point - a transactional connection for receipts, a separate
 * one for newsletters, a different domain for a second brand.
 *
 * Only the names live in this list. Credentials are stored exactly as the
 * backup's always were, under a per-connection prefix, so adding a fifth
 * connection needs no storage work at all.
 *
 * ## On identifiers
 *
 * An id becomes part of every option key that connection owns, which puts two
 * hard constraints on it. It has to survive being concatenated into a key, so
 * it is generated rather than derived from the name - a connection called
 * "Marketing / EU" would otherwise produce keys nothing could read back. And it
 * must never be reused after deletion, or the next connection to take that id
 * would silently inherit the deleted one's credentials.
 */
class Connections {

	/** Ids are generated, never taken from user input. */
	private const PREFIX = 'c';

	/**
	 * Guard rail rather than a product limit. Each connection multiplies the
	 * settings payload, and a site needing more than this wants a different
	 * tool.
	 */
	public const MAX = 10;

	public function __construct( private Settings $settings ) {}

	/**
	 * Every connection, built-ins first.
	 *
	 * @return array<int,array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool}>
	 */
	public function all(): array {
		$out = [
			$this->describe( 'primary', Settings::SLOT_PRIMARY, __( 'Primary', 'modern-mailer-oauth' ), true ),
			$this->describe( 'backup', Settings::SLOT_BACKUP, __( 'Backup', 'modern-mailer-oauth' ), true ),
		];

		foreach ( $this->additional() as $id => $name ) {
			$out[] = $this->describe( $id, $id, $name, false );
		}

		return $out;
	}

	/**
	 * Additional connections only, id => name.
	 *
	 * @return array<string,string>
	 */
	public function additional(): array {
		$stored = $this->settings->get( 'connections' );
		$out    = [];

		if ( ! is_array( $stored ) ) {
			return $out;
		}

		foreach ( $stored as $id => $name ) {
			$id = (string) $id;

			// A stored id that no longer looks like one cannot be addressed
			// safely, so it is skipped rather than used to build option keys.
			if ( ! self::is_valid_id( $id ) ) {
				continue;
			}

			$out[ $id ] = (string) $name;
		}

		return $out;
	}

	/**
	 * The slot a public id addresses, or null when there is no such connection.
	 */
	public function slot_for( string $id ): ?string {
		if ( 'primary' === $id || '' === $id ) {
			return Settings::SLOT_PRIMARY;
		}

		if ( Settings::SLOT_BACKUP === $id ) {
			return Settings::SLOT_BACKUP;
		}

		return array_key_exists( $id, $this->additional() ) ? $id : null;
	}

	public function exists( string $id ): bool {
		return null !== $this->slot_for( $id );
	}

	/**
	 * The display name for a connection, for logs and the routing UI.
	 */
	public function name_for( string $id ): string {
		if ( 'primary' === $id || '' === $id ) {
			return __( 'Primary', 'modern-mailer-oauth' );
		}

		if ( Settings::SLOT_BACKUP === $id ) {
			return __( 'Backup', 'modern-mailer-oauth' );
		}

		return $this->additional()[ $id ] ?? $id;
	}

	/**
	 * Add a connection and return its generated id.
	 *
	 * @return string|\WP_Error
	 */
	public function add( string $name ) {
		$existing = $this->additional();

		if ( count( $existing ) >= self::MAX ) {
			return new \WP_Error(
				'mmoa_too_many_connections',
				sprintf(
					/* translators: %d: maximum number of additional connections. */
					__( 'A site can hold at most %d additional connections.', 'modern-mailer-oauth' ),
					self::MAX
				)
			);
		}

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name ) {
			$name = __( 'New connection', 'modern-mailer-oauth' );
		}

		$id              = $this->generate_id( $existing );
		$existing[ $id ] = $name;

		$this->settings->update( [ 'connections' => $existing ] );

		return $id;
	}

	/**
	 * Rename an additional connection. Built-ins keep their names.
	 */
	public function rename( string $id, string $name ): bool {
		$existing = $this->additional();

		if ( ! array_key_exists( $id, $existing ) ) {
			return false;
		}

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name ) {
			return false;
		}

		$existing[ $id ] = $name;
		$this->settings->update( [ 'connections' => $existing ] );

		return true;
	}

	/**
	 * Remove a connection, its settings, and its credentials.
	 *
	 * Deleting the name alone would leave the credentials orphaned in the
	 * options table - unreachable, unremovable through the UI, and still
	 * sitting there in any database dump. So the slot is emptied too.
	 */
	public function delete( string $id ): bool {
		$existing = $this->additional();

		if ( ! array_key_exists( $id, $existing ) ) {
			return false;
		}

		$this->forget_slot( $id );

		unset( $existing[ $id ] );
		$this->settings->update( [ 'connections' => $existing ] );

		return true;
	}

	/**
	 * Erase everything stored under one connection's prefix.
	 */
	private function forget_slot( string $slot ): void {
		$scoped  = $this->settings->for_slot( $slot );
		$secrets = $scoped->secrets();

		foreach ( Provider_Registry::all_fields() as $field ) {
			if ( $field->secret ) {
				$secrets->set( $field->key, '' );
			}
		}

		// Settings has no delete, and writing empties is enough: a connection
		// with no provider is inert, and every field falls back to its default.
		$blank = [ 'provider' => '' ];

		foreach ( Provider_Registry::all_fields() as $field ) {
			if ( ! $field->secret ) {
				$blank[ $field->key ] = '';
			}
		}

		$scoped->update( $blank );
	}

	/**
	 * Ids are opaque and never reused, so a deleted connection's credentials
	 * cannot be inherited by the next one created.
	 *
	 * @param array<string,string> $existing Current ids.
	 */
	private function generate_id( array $existing ): string {
		do {
			$id = self::PREFIX . strtolower( wp_generate_password( 8, false, false ) );
		} while ( isset( $existing[ $id ] ) );

		return $id;
	}

	/**
	 * @return array{id:string,slot:string,name:string,builtin:bool,provider:string,configured:bool}
	 */
	private function describe( string $id, string $slot, string $name, bool $builtin ): array {
		$scoped   = $this->settings->for_slot( $slot );
		$provider = (string) $scoped->get( 'provider' );

		return [
			'id'         => $id,
			'slot'       => $slot,
			'name'       => $name,
			'builtin'    => $builtin,
			'provider'   => $provider,
			'configured' => '' !== $provider,
		];
	}

	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^' . self::PREFIX . '[a-z0-9]{4,32}$/', $id );
	}
}
