<?php
/**
 * Failure detection and alerting.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Watches for repeated send failures and makes sure somebody hears about it.
 *
 * This is the piece that addresses the actual reported symptom. The underlying
 * cause of "our site stopped sending email" is rarely that a send failed once;
 * it is that nothing surfaced the failure, because almost no WordPress code
 * checks what wp_mail() returned. Weeks pass before a customer mentions they
 * never got their receipt.
 */
class Health_Monitor {

	private const OPTION = 'mmoa_health';

	public function __construct( private Settings $settings ) {}

	/**
	 * @return array{streak:int,alerted:bool,last_error:array,last_success:int}
	 */
	public function state(): array {
		$state = get_option( self::OPTION, [] );

		return wp_parse_args(
			is_array( $state ) ? $state : [],
			[
				'streak'       => 0,
				'alerted'      => false,
				'last_error'   => [],
				'last_success' => 0,
			]
		);
	}

	public function record_success(): void {
		$state = $this->state();

		if ( 0 === $state['streak'] && ! $state['alerted'] ) {
			// Nothing to clear; avoid a write on every single email.
			if ( $state['last_success'] > ( time() - HOUR_IN_SECONDS ) ) {
				return;
			}
		}

		update_option(
			self::OPTION,
			[
				'streak'       => 0,
				'alerted'      => false,
				'last_error'   => [],
				'last_success' => time(),
			],
			false
		);
	}

	public function record_failure( WP_Error $error ): void {
		$state = $this->state();

		$state['streak']    = (int) $state['streak'] + 1;
		$state['last_error'] = [
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'time'    => time(),
		];

		$threshold = max( 1, (int) $this->settings->get( 'alert_threshold' ) );
		$fire      = ! $state['alerted'] && $state['streak'] >= $threshold;

		if ( $fire ) {
			$state['alerted'] = true;
		}

		update_option( self::OPTION, $state, false );

		if ( $fire ) {
			$this->alert( $error, (int) $state['streak'] );
		}
	}

	public function is_failing(): bool {
		return (bool) $this->state()['alerted'];
	}

	public function reset(): void {
		delete_option( self::OPTION );
	}

	private function alert( WP_Error $error, int $streak ): void {
		/**
		 * Fires once when sending has failed enough times in a row to count as
		 * an outage. Wire this to Slack, PagerDuty, or an uptime monitor.
		 *
		 * @param WP_Error $error  The most recent failure.
		 * @param int      $streak Consecutive failures.
		 */
		do_action( 'mmoa_send_failing', $error, $streak );

		$this->email_alert( $error, $streak );
	}

	/**
	 * Try to email an alert, deliberately bypassing our own provider.
	 *
	 * Routing the "email is broken" alert through the broken email path would
	 * be useless, so this builds a bare PHPMailer and goes out over the
	 * server's native mail() instead. That may also be unavailable - which is
	 * exactly why the action hook above exists and is the supported
	 * integration point. This is a best-effort second channel, not the plan.
	 */
	private function email_alert( WP_Error $error, int $streak ): void {
		$to = (string) $this->settings->get( 'alert_email' );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		try {
			$mailer = new PHPMailer( false );
			$mailer->isMail();
			$mailer->setFrom( 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ), 'WordPress' );
			$mailer->addAddress( $to );
			$mailer->Subject = sprintf(
				/* translators: %s: site name. */
				__( '[%s] Email sending is failing', 'modern-mailer-oauth' ),
				get_bloginfo( 'name' )
			);
			$mailer->Body = sprintf(
				/* translators: 1: consecutive failure count, 2: error message, 3: settings URL. */
				__( "%1\$d consecutive emails have failed to send.\n\nMost recent error:\n%2\$s\n\nSettings: %3\$s", 'modern-mailer-oauth' ),
				$streak,
				$error->get_error_message(),
				admin_url( 'options-general.php?page=modern-mailer-oauth' )
			);

			$mailer->send();
		} catch ( \Throwable $e ) {
			// Nothing useful to do here - the hook above already fired.
			unset( $e );
		}
	}
}
