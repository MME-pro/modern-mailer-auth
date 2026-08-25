<?php
/**
 * Update checker backed by GitHub Releases.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Teaches WordPress to find this plugin's updates on GitHub.
 *
 * The plugin is not on wordpress.org, so core's update check returns nothing
 * for it and a site would sit on whatever version was installed by hand. This
 * fills that gap: it reads the latest published release, and if its tag is
 * newer than the running version it hands core the release's zip. From there
 * the normal machinery takes over - the update appears on the Plugins screen,
 * "Update now" works, and auto-updates work if the site has them enabled.
 *
 * The remote answer is cached, and cached on failure too. A repository that is
 * unreachable must not cost every admin page load a network round trip.
 */
class Updater {

	/** Slug, and the directory name the release zip must unpack to. */
	public const SLUG = 'modern-mailer-oauth';

	private const REPO = 'MME-pro/modern-mailer-auth';

	private const CACHE_KEY = 'mmoa_update_check';

	/**
	 * How long a successful answer is trusted.
	 *
	 * Short, because six hours meant a published release could sit unnoticed for
	 * most of a working day.
	 *
	 * Not zero, though, and the reason is worth stating: this filter runs on
	 * nearly every admin page load - the update-count bubble in the menu reads
	 * the same transient - so an uncached check would put a blocking HTTPS
	 * request in front of every page. It would also burn through GitHub's
	 * unauthenticated limit of sixty requests an hour per IP, and on shared
	 * hosting that IP is shared, so the resulting failure would not even be
	 * confined to this site. The outcome of removing the cache is fewer update
	 * notices, not more.
	 *
	 * Fifteen minutes is at most four calls an hour, and anyone who wants an
	 * answer sooner has Check Again, which skips this entirely.
	 */
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * How long a failure is remembered.
	 *
	 * Deliberately shorter than the success window now. A failure is usually a
	 * blip - a timeout, a momentary rate limit - and retrying in five minutes
	 * costs little, where remembering it for half an hour delays the recovery.
	 */
	private const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	private Http $http;

	public function __construct( Http $http ) {
		$this->http = $http;
	}

	public function register(): void {
		add_filter( 'site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );

		// "Check Again" has to mean it.
		add_action( 'load-update-core.php', [ $this, 'honour_force_check' ] );
	}

	/**
	 * Discard our cached answer when an admin explicitly asks for a re-check.
	 *
	 * WordPress's "Check Again" button links to update-core.php?force-check=1,
	 * and core responds by throwing away *its* update transient and rebuilding
	 * it. That rebuild runs inject_update() again - which, without this, was
	 * answered straight from our own six-hour cache.
	 *
	 * The effect was that a site which last checked while an older release was
	 * current kept reporting itself up to date, and no amount of clicking would
	 * change it until the cache aged out. A button labelled "Check Again" that
	 * does not check again is worse than no button.
	 */
	public function honour_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a core flag, not acting on input.
		if ( ! empty( $_GET['force-check'] ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	private function basename(): string {
		return plugin_basename( PLUGIN_FILE );
	}

	/**
	 * Add our release to the set of available updates core already assembled.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		// Core passes an empty value the first time, before it has built the
		// object. Returning early leaves that untouched; it comes back here
		// populated a moment later.
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$basename = $this->basename();
		$release  = $this->latest_release();

		if ( null === $release ) {
			return $transient;
		}

		$item = (object) [
			'id'            => self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $release['package'],
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => $release['tested'],
			'requires_php'  => '8.0',
			'compatibility' => new \stdClass(),
		];

		if ( version_compare( $release['version'], VERSION, '>' ) && '' !== $release['package'] ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );

			return $transient;
		}

		// Listing the plugin as up to date is not cosmetic: core only offers
		// the auto-update toggle for plugins it knows something about, and a
		// plugin missing from both lists never gets one.
		$item->new_version = VERSION;
		$item->package     = '';
		unset( $transient->response[ $basename ] );
		$transient->no_update[ $basename ] = $item;

		return $transient;
	}

	/**
	 * Fill in the "View details" modal, which would otherwise 404 against
	 * wordpress.org.
	 *
	 * @param mixed  $result The value being filtered.
	 * @param string $action The plugins_api action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Modern Mailer - OAuth SMTP for Microsoft 365 and Gmail',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . self::REPO . '">builtwithmtw</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '6.5',
			'requires_php'  => '8.0',
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'sections'      => [
				'description' => wp_kses_post( $release['notes'] ),
			],
		];
	}

	/**
	 * Drop the cache after an update runs, so the Plugins screen does not keep
	 * offering a version that is now installed.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param mixed $extra    Context supplied by the upgrader.
	 */
	public function flush_cache( $upgrader, $extra ): void {
		unset( $upgrader );

		if ( is_array( $extra ) && 'plugin' === ( $extra['type'] ?? '' ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * What the update check currently knows.
	 *
	 * Exists so the failure can be *seen*. Until now an update check that could
	 * not reach GitHub - a firewall, a proxy, an outbound block, a rate limit -
	 * did exactly what a successful check with no new release does: nothing at
	 * all. The site simply never offered an update and there was no way to tell
	 * the two apart from the admin.
	 *
	 * @return array{installed:string,latest:string,package:string,reachable:bool,from_cache:bool,repo:string}
	 */
	public function status(): array {
		$had_cache = is_array( get_site_transient( self::CACHE_KEY ) );
		$release   = $this->latest_release();

		return [
			'installed'  => VERSION,
			'latest'     => (string) ( $release['version'] ?? '' ),
			'package'    => (string) ( $release['package'] ?? '' ),
			'reachable'  => null !== $release,
			'from_cache' => $had_cache,
			'repo'       => self::REPO,
		];
	}

	/**
	 * The latest published release, or null when there is nothing usable.
	 *
	 * @return array{version:string,package:string,notes:string,published:string,tested:string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return '' !== ( $cached['version'] ?? '' ) ? $cached : null;
		}

		$response = $this->http->request(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'method'  => 'GET',
				'headers' => array_filter(
					[
						'Accept'               => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => '2022-11-28',
						'Authorization'        => $this->auth_header(),
					]
				),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) $response['code'] ) {
			// Remember the failure briefly. Without this a repo that is down,
			// renamed, or rate-limiting costs every admin request a network
			// round trip.
			$this->remember_failure();

			return null;
		}

		$body = json_decode( (string) $response['body'], true );

		if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			$this->remember_failure();

			return null;
		}

		$release = [
			'version'   => ltrim( (string) ( $body['tag_name'] ?? '' ), 'vV' ),
			'package'   => $this->zip_url( $body ),
			'notes'     => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
			'tested'    => (string) get_bloginfo( 'version' ),
		];

		if ( '' === $release['version'] ) {
			$this->remember_failure();

			return null;
		}

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	private function remember_failure(): void {
		set_site_transient( self::CACHE_KEY, [ 'version' => '' ], self::FAILURE_TTL );
	}

	/**
	 * Pick the built zip the release workflow attached.
	 *
	 * GitHub's own source tarball is deliberately not a fallback: it unpacks to
	 * a directory named after the tag, and it does not contain the compiled
	 * admin app. Installing it would leave the site with a broken second copy
	 * of the plugin.
	 *
	 * @param array<string,mixed> $body Decoded release payload.
	 */
	private function zip_url( array $body ): string {
		$fallback = '';

		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );

			if ( self::SLUG . '.zip' === $name ) {
				return $url;
			}

			if ( '' === $fallback && str_ends_with( $name, '.zip' ) ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	/**
	 * Token for a private repository, if the site defines one.
	 *
	 * Public repositories need nothing here. A site pulling from a private repo
	 * can define MMOA_GITHUB_TOKEN in wp-config.php, or attach one with the
	 * `mmoa_github_token` filter.
	 */
	private function auth_header(): ?string {
		$token = defined( 'MMOA_GITHUB_TOKEN' ) ? (string) constant( 'MMOA_GITHUB_TOKEN' ) : '';

		/**
		 * Filters the GitHub token used for update checks.
		 *
		 * @param string $token Personal access token, or an empty string.
		 */
		$token = (string) apply_filters( 'mmoa_github_token', $token );

		return '' !== $token ? 'Bearer ' . $token : null;
	}
}
