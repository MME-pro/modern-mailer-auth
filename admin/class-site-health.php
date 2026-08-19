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

	private function settings_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=modern-mailer-oauth' ) ),
			esc_html__( 'Open mail settings', 'modern-mailer-oauth' )
		);
	}
}
