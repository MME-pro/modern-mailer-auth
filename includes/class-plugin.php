<?php
/**
 * Plugin container and bootstrap.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Admin\Admin_Page;
use ModernMailer\Admin\App_Page;
use ModernMailer\Admin\Site_Health;
use ModernMailer\Api\Rest_Controller;
use ModernMailer\Auth\Broker;
use ModernMailer\Auth\Google_Consent;
use ModernMailer\Auth\One_Click;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and registers its hooks.
 */
class Plugin {

	/** Set once the merge migration has run, so it runs once. */
	private const MERGED_PROVIDERS_OPTION = 'mmoa_merged_providers';

	private static ?Plugin $instance = null;

	public Secrets $secrets;
	public Settings $settings;
	public Token_Store $tokens;
	public Http $http;
	public Logger $logger;
	public Health_Monitor $health;
	public Queue $queue;
	public Connections $connections;
	public Router $router;
	public Site_Identity $identity;
	public Broker $broker;
	public Google_Consent $consent;
	public One_Click $one_click;
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
		$this->queue      = new Queue( $this->settings );
		$this->connections = new Connections( $this->settings );
		$this->router      = new Router( $this->settings, $this->connections );
		$this->identity   = new Site_Identity();
		$this->broker     = new Broker( $this->http, $this->identity );
		$this->consent    = new Google_Consent( $this->settings, $this->http, $this->connections );
		$this->one_click  = new One_Click( $this->settings, $this->broker, $this->connections, $this->tokens );
		$this->dispatcher = new Dispatcher(
			$this->settings,
			$this->tokens,
			$this->http,
			$this->logger,
			$this->health,
			$this->queue,
			$this->router
		);
	}

	public function boot(): void {
		add_action( 'plugins_loaded', [ $this, 'install_mailer' ], 20 );
		add_action( Logger::CRON_HOOK, [ $this->logger, 'prune' ] );
		add_action( Queue::CRON_HOOK, [ $this, 'drain_queue' ] );
		add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

		if ( is_admin() ) {
			( new App_Page( $this ) )->register();
			( new Admin_Page( $this ) )->register();
		}

		// The update check only has to run where WordPress actually looks for
		// updates - the admin, and the cron job behind auto-updates. On a
		// front-end request it would be a network call nobody reads.
		if ( is_admin() || wp_doing_cron() ) {
			( new Updater( $this->http ) )->register();
		}

		( new Site_Health( $this ) )->register();

		// Registered unconditionally, not only in the admin: rest_api_init fires
		// on a front-end REST request too, and the admin app is served from a
		// page that is not always an admin screen by the time these run.
		( new Rest_Controller( $this ) )->register();
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

	/**
	 * Add the five-minute interval the queue drains on.
	 *
	 * Five minutes is the shortest interval worth having: WordPress cron only
	 * runs when the site gets traffic, so a tighter schedule buys nothing on a
	 * quiet site and adds needless work on a busy one.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function register_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : [];

		$schedules[ Queue::SCHEDULE_NAME ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Modern Mailer retry queue)', 'modern-mailer-oauth' ),
		];

		return $schedules;
	}

	/**
	 * Create anything a plugin update introduced.
	 *
	 * The activation hook does not fire on update, so a site that upgrades into
	 * this version would otherwise have the queue code but no queue table, and
	 * every enqueue would fail silently at the exact moment it was needed. Both
	 * installers no-op once their version option matches, so this costs one
	 * option read per admin request.
	 */
	public function maybe_upgrade(): void {
		Logger::install();
		Queue::install();

		if ( ! wp_next_scheduled( Queue::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), Queue::SCHEDULE_NAME, Queue::CRON_HOOK );
		}

		$this->migrate_merged_providers();
	}

	/**
	 * Move connections onto the merged Microsoft and Google providers.
	 *
	 * Those two tiles used to be four - Microsoft 365 and Outlook, Google
	 * Workspace and Gmail - which asked an admin to choose an authentication
	 * method before choosing a mail service. The methods survive unchanged as
	 * the setup modes behind each tile, so this only has to restate an existing
	 * choice in the new vocabulary: no credential is touched and no connection
	 * changes how it sends.
	 *
	 * Nothing here is strictly required for a site to keep working - the old
	 * slugs are still registered and still constructible, which is deliberate,
	 * because an upgrade must not be able to stop mail. Without the migration a
	 * connection would simply keep its old tile until someone edited it.
	 */
	private function migrate_merged_providers(): void {
		if ( get_option( self::MERGED_PROVIDERS_OPTION ) ) {
			return;
		}

		// Slug that was stored => [ merged slug, mode setting, mode value ].
		// A null mode means "leave whatever is there": gmail_oauth already used
		// google_setup_mode to record whether its token was brokered, and that
		// answer is still the right one.
		$map = [
			'graph'       => [ 'microsoft', 'ms_setup_mode', Auth\One_Click::MODE_OWN_CLIENT ],
			'outlook'     => [ 'microsoft', 'ms_setup_mode', Auth\One_Click::MODE_ONE_CLICK ],
			'gmail_sa'    => [ 'google', 'google_setup_mode', Providers\Google::MODE_SERVICE_ACCOUNT ],
			'gmail_oauth' => [ 'google', 'google_setup_mode', null ],
		];

		foreach ( $this->connections->all() as $connection ) {
			$scoped = $this->settings->for_slot( (string) $connection['slot'] );
			$stored = (string) $scoped->get( 'provider' );

			if ( ! isset( $map[ $stored ] ) ) {
				continue;
			}

			[ $merged, $mode_key, $mode ] = $map[ $stored ];

			$values = [ 'provider' => $merged ];

			if ( null !== $mode ) {
				$values[ $mode_key ] = $mode;
			}

			$scoped->update( $values );
		}

		Settings::flush_cache();
		update_option( self::MERGED_PROVIDERS_OPTION, time(), false );
	}

	/**
	 * Drain the retry queue. Runs on cron.
	 */
	public function drain_queue(): void {
		if ( ! $this->settings->is_active() ) {
			return;
		}

		$this->queue->drain( $this->dispatcher );
	}

	public static function activate(): void {
		Logger::install();
		Queue::install();

		if ( ! wp_next_scheduled( Logger::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( Queue::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), Queue::SCHEDULE_NAME, Queue::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		foreach ( [ Logger::CRON_HOOK, Queue::CRON_HOOK ] as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
