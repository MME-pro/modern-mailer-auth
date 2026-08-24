<?php
/**
 * Mount point for the admin app.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Api\Rest_Controller;
use ModernMailer\Auth\Google_Consent;
use ModernMailer\Plugin;

use const ModernMailer\PLUGIN_DIR;
use const ModernMailer\PLUGIN_URL;
use const ModernMailer\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and hands the rest to the React app.
 *
 * The whole interface is one page. The WordPress submenu entries exist because
 * people look for them there, but each is a link into the same app at a
 * different hash route rather than a separate screen - so navigating between
 * them costs nothing and the app never remounts.
 */
class App_Page {

	public const SLUG = 'modern-mailer';

	private const CAPABILITY = 'manage_options';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'conflict_notice' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Modern Mailer', 'modern-mailer-oauth' ),
			__( 'Modern Mailer', 'modern-mailer-oauth' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-email-alt',
			80
		);

		$submenus = [
			''             => __( 'Dashboard', 'modern-mailer-oauth' ),
			'connections'  => __( 'Connections', 'modern-mailer-oauth' ),
			'logs'         => __( 'Email Logs', 'modern-mailer-oauth' ),
			'settings'     => __( 'Settings', 'modern-mailer-oauth' ),
		];

		foreach ( $submenus as $route => $label ) {
			add_submenu_page(
				self::SLUG,
				$label,
				$label,
				self::CAPABILITY,
				'' === $route ? self::SLUG : self::SLUG . '#/' . $route,
				'' === $route ? [ $this, 'render' ] : '__return_null'
			);
		}
	}

	/**
	 * Warn when another mailer has taken over wp_mail().
	 *
	 * wp_mail() is pluggable, so whichever plugin defines it first wins and the
	 * loser is left installed, configured, and doing nothing at all. That is a
	 * genuinely confusing state - the settings look right, the test button
	 * fails, and nothing says why - so it is worth detecting rather than
	 * letting an admin discover it from a log.
	 */
	public function conflict_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->plugin->settings->is_active() ) {
			return;
		}

		$mailer = new \ReflectionFunction( 'wp_mail' );
		$file   = (string) $mailer->getFileName();

		// Core's own wp_mail lives in pluggable.php. Anything else means a
		// plugin declared it first.
		if ( false !== strpos( $file, 'wp-includes' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
			esc_html__( 'Another plugin has taken over email sending.', 'modern-mailer-oauth' ),
			esc_html__(
				'It defined wp_mail() before WordPress could, so Modern Mailer is configured but not sending anything. Deactivate one of the two.',
				'modern-mailer-oauth'
			),
			esc_html( str_replace( WP_PLUGIN_DIR . '/', '', $file ) )
		);
	}

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$asset_file = PLUGIN_DIR . 'build/index.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mmoa-app',
			PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'mmoa-app',
			PLUGIN_URL . 'build/index.css',
			[],
			$asset['version']
		);

		wp_set_script_translations( 'mmoa-app', 'modern-mailer-oauth' );

		wp_localize_script(
			'mmoa-app',
			'mmoa',
			[
				'version'          => VERSION,
				'restNamespace'    => Rest_Controller::NAMESPACE,
				'currentUserEmail' => wp_get_current_user()->user_email,
				'redirectUri'      => Google_Consent::redirect_uri(),
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// The app replaces the page entirely. The noscript fallback is not
		// decoration: an admin whose bundle failed to load should be told that,
		// not left looking at a blank screen.
		?>
		<div class="wrap" style="margin:0;padding:0">
			<div id="mmoa-app-root">
				<noscript>
					<p style="padding:2rem">
						<?php esc_html_e( 'Modern Mailer needs JavaScript to show its settings.', 'modern-mailer-oauth' ); ?>
					</p>
				</noscript>
			</div>
		</div>
		<?php
	}
}
