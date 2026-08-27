<?php
/**
 * The identifier this site presents to the OAuth broker.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * A stable, opaque name for this installation.
 *
 * The broker needs to tell one site's grant from another's, and a site URL is
 * not good enough: it changes when a site moves domain or is cloned to staging,
 * and two installations can share one. So each site mints a random identifier
 * once and keeps it.
 *
 * It is random, and that is the whole point. WP Mail SMTP derives the same
 * identifier from the site's own secrets:
 *
 *     $site_id = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY;
 *     $site_id = preg_replace( '/[^a-zA-Z0-9]/', '', $site_id );
 *     return substr( $site_id, 0, 30 );
 *
 * which sends the first thirty alphanumeric characters of a customer's AUTH_KEY
 * to a third-party API on every request. Those constants sign authentication
 * cookies. Nothing about identifying a site requires deriving that identifier
 * from the one secret that must never leave the server, and a random value does
 * the job with nothing to leak.
 */
class Site_Identity {

	private const OPTION = 'mmoa_site_id';

	/**
	 * This site's identifier, minting one on first use.
	 */
	public function get(): string {
		$id = (string) get_option( self::OPTION, '' );

		if ( '' !== $id ) {
			return $id;
		}

		$id = 'mm_' . wp_generate_password( 32, false, false );

		// Autoloaded: it is read on any request that refreshes a token, and a
		// separate query for one short string on each of those is waste.
		add_option( self::OPTION, $id, '', 'yes' );

		// add_option() is a no-op if another request won the race, so read back
		// rather than trusting what we generated.
		return (string) get_option( self::OPTION, $id );
	}

	/**
	 * Forget the identifier.
	 *
	 * Only for uninstall. Rotating it while connections exist would orphan
	 * every grant the broker holds for this site - they would keep working
	 * until their refresh token expired and then fail with nothing to explain
	 * why, because the broker would no longer recognise who was asking.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}
}
