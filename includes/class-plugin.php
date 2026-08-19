<?php
/**
 * Plugin container and bootstrap.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Admin\Admin_Page;
use ModernMailer\Admin\Site_Health;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and registers its hooks.
 */
class Plugin {

	private static ?Plugin $instance = null;

	public Secrets $secrets;
	public Settings $settings;
	public Token_Store $tokens;
	public Http $http;
	public Logger $logger;
	public Health_Monitor $health;
	public Dispatcher $dispatcher;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->secrets    = new Secrets();
		$this->settings   = new Settings( $this->secrets );
		$this->tokens     = new Token_Store();
		$this->http       = new Http();
		$this->logger     = new Logger( $this->settings );
		$this->health     = new Health_Monitor( $this->settings );
		$this->dispatcher = new Dispatcher(
			$this->settings,
			$this->tokens,
			$this->http,
			$this->logger,
			$this->health
		);
	}

	public function boot(): void {
		add_action( 'plugins_loaded', [ $this, 'install_mailer' ], 20 );
		add_action( Logger::CRON_HOOK, [ $this->logger, 'prune' ] );

		if ( is_admin() ) {
			( new Admin_Page( $this ) )->register();
		}

		( new Site_Health( $this ) )->register();
	}

	/**
	 * Take over wp_mail() by pre-seeding the PHPMailer global.
	 *
	 * wp_mail() only constructs a PHPMailer when the global is not already
	 * one, so placing our subclass there first is enough - no core patching,
	 * no reimplementation of header parsing.
	 *
	 * Nothing happens unless a provider is actually configured. Intercepting a
	 * send we cannot complete would be worse than not intercepting it, and it
	 * would break local development where a catcher like Mailpit is handling
	 * mail.
	 */
	public function install_mailer(): void {
		if ( ! $this->settings->is_active() ) {
			return;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';

		$catcher = new Mail_Catcher( true );
		$catcher->set_dispatcher( $this->dispatcher );

		// Match what core does when it builds its own instance.
		PHPMailer::$validator = static fn( $email ): bool => (bool) is_email( $email );

		$GLOBALS['phpmailer'] = $catcher;

		if ( $this->settings->get( 'force_from' ) ) {
			add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ], 100 );
			add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ], 100 );
		}
	}

	/**
	 * Force the envelope sender to the configured mailbox.
	 *
	 * Both APIs reject, or silently rewrite, a From address the authenticated
	 * identity is not allowed to use. Overriding it here means a plugin that
	 * hardcodes its own From does not quietly break delivery.
	 */
	public function filter_from_email( string $from ): string {
		$configured = (string) $this->settings->get( 'from_email' );

		return '' !== $configured ? $configured : $from;
	}

	public function filter_from_name( string $name ): string {
		$configured = (string) $this->settings->get( 'from_name' );

		return '' !== $configured ? $configured : $name;
	}

	public static function activate(): void {
		Logger::install();

		if ( ! wp_next_scheduled( Logger::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( Logger::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Logger::CRON_HOOK );
		}
	}
}
