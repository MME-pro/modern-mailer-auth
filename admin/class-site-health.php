<?php
/**
 * Site Health integration.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces mail failures in Tools > Site Health.
 *
 * Worth doing beyond the admin notice, because Site Health is what managed
 * hosts and monitoring plugins actually read. A dismissible banner in wp-admin
 * only helps somebody who is already logged in and looking.
 */
class Site_Health {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
	}

	/**
	 * @param array<string,mixed> $tests Registered tests.
	 * @return array<string,mixed>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['mmoa_updates'] = [
			'label' => __( 'MME-Mail to SMTP updates', 'modern-mailer-oauth' ),
			'test'  => [ $this, 'run_update_test' ],
		];

		$tests['direct']['mmoa_delivery'] = [
			'label' => __( 'Email delivery', 'modern-mailer-oauth' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_test(): array {
		$settings = $this->plugin->settings;

		$result = [
			'label'       => __( 'Email is sending normally', 'modern-mailer-oauth' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Email', 'modern-mailer-oauth' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Outgoing email is being delivered through an authenticated API connection.', 'modern-mailer-oauth' ) . '</p>',
			'actions'     => '',
			'test'        => 'mmoa_delivery',
		];

		if ( ! $settings->is_active() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'No mail provider is configured', 'modern-mailer-oauth' );
			$result['description'] = '<p>' . esc_html__( 'WordPress is falling back to the server mail function, which most hosts either block or deliver straight to spam.', 'modern-mailer-oauth' ) . '</p>';
			$result['actions']     = $this->settings_link();

			return $result;
		}

		$state = $this->plugin->health->state();

		if ( $this->plugin->health->is_failing() ) {
			$result['status'] = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']  = __( 'Email is failing to send', 'modern-mailer-oauth' );
			$result['description'] = '<p>' . sprintf(
				/* translators: 1: consecutive failure count, 2: most recent error message. */
				esc_html__( '%1$d messages in a row have failed. Most recent error: %2$s', 'modern-mailer-oauth' ),
				(int) $state['streak'],
				esc_html( (string) ( $state['last_error']['message'] ?? '' ) )
			) . '</p>';
			$result['actions'] = $this->settings_link();

			return $result;
		}

		// Mail that ran out of retries is the one state worth shouting about:
		// unlike a failing send, it is finished, and nothing else will surface
		// it. Checked before the secret-expiry warning because data already lost
		// outranks an outage that has not happened yet.
		$queue = $this->plugin->queue->stats();

		if ( $queue['failed'] > 0 ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Some email was never delivered', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %d: number of abandoned messages. */
				esc_html( _n( '%d message exhausted every retry and has been abandoned.', '%d messages exhausted every retry and have been abandoned.', (int) $queue['failed'], 'modern-mailer-oauth' ) ),
				(int) $queue['failed']
			) . '</p>';
			$result['actions']        = $this->logs_link();

			return $result;
		}

		if ( $queue['pending'] > 0 ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'Email is queued for retry', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %d: number of messages waiting. */
				esc_html( _n( '%d message could not be sent on the first attempt and is waiting to be retried. Nothing has been lost, but sending is not healthy.', '%d messages could not be sent on the first attempt and are waiting to be retried. Nothing has been lost, but sending is not healthy.', (int) $queue['pending'], 'modern-mailer-oauth' ) ),
				(int) $queue['pending']
			) . '</p>';
			$result['actions']        = $this->logs_link();

			return $result;
		}

		// An Entra client secret that quietly expires is a scheduled outage.
		// Warning ahead of time is the difference between a two-minute job and
		// an incident.
		$expires = (int) $settings->get( 'ms_secret_expires' );

		if ( $expires > 0 && $expires < ( time() + 30 * DAY_IN_SECONDS ) ) {
			$result['status'] = $expires < time() ? 'critical' : 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']  = $expires < time()
				? __( 'The Microsoft client secret has expired', 'modern-mailer-oauth' )
				: __( 'The Microsoft client secret expires soon', 'modern-mailer-oauth' );
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: human-readable date. */
				esc_html__( 'The client secret expires on %s. Generate a replacement in Entra before then, or consider switching to a certificate credential, which does not expire on a fixed schedule.', 'modern-mailer-oauth' ),
				esc_html( wp_date( get_option( 'date_format' ), $expires ) )
			) . '</p>';
			$result['actions'] = $this->settings_link();
		}

		return $result;
	}

	/**
	 * Say whether the update check is actually working.
	 *
	 * This exists because the failure was invisible. A check that cannot reach
	 * GitHub behaves exactly like a check that found nothing new - the site
	 * quietly never offers an update, and no screen anywhere says why. On a host
	 * that blocks outbound requests, or behind a firewall that filters them, a
	 * site could sit unpatched indefinitely while looking perfectly healthy.
	 *
	 * @return array<string,mixed>
	 */
	public function run_update_test(): array {
		$status = ( new \ModernMailer\Updater( $this->plugin->http ) )->status();

		$result = [
			'label'       => __( 'The plugin is up to date', 'modern-mailer-oauth' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Email', 'modern-mailer-oauth' ),
				'color' => 'blue',
			],
			'description' => '<p>' . sprintf(
				/* translators: %s: installed version number. */
				esc_html__( 'Version %s is installed, and it is the newest release.', 'modern-mailer-oauth' ),
				esc_html( $status['installed'] )
			) . '</p>',
			'actions'     => '',
			'test'        => 'mmoa_updates',
		];

		if ( ! $status['reachable'] ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'Update checks are not getting through', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %s: GitHub repository, e.g. owner/name. */
				esc_html__( 'This site could not reach the GitHub API to ask whether a newer version of MME-Mail to SMTP exists, so it will not offer updates for it. Usually that means outbound HTTPS requests are blocked, a security plugin is filtering them, or the API is rate limiting this server. The plugin keeps working; it simply cannot tell you when a fix is available. It checks %s.', 'modern-mailer-oauth' ),
				'<code>' . esc_html( $status['repo'] ) . '</code>'
			) . '</p>';

			return $result;
		}

		if ( version_compare( $status['latest'], $status['installed'], '>' ) ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'A newer version of MME-Mail to SMTP is available', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: 1: installed version, 2: available version. */
				esc_html__( 'Version %1$s is installed and %2$s has been released. Update from the Plugins screen.', 'modern-mailer-oauth' ),
				esc_html( $status['installed'] ),
				esc_html( $status['latest'] )
			) . '</p>';
			$result['actions']        = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Open the Plugins screen', 'modern-mailer-oauth' )
			);
		}

		return $result;
	}

	private function settings_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( Admin_Page::url() ),
			esc_html__( 'Open mail settings', 'modern-mailer-oauth' )
		);
	}

	/**
	 * Queue problems are acted on from the Logs screen, not from Settings -
	 * that is where the affected messages and the retry controls are.
	 */
	private function logs_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( Admin_Page::url( 'modern-mailer-logs' ) ),
			esc_html__( 'Review queued mail', 'modern-mailer-oauth' )
		);
	}
}
