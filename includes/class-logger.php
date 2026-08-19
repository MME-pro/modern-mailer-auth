<?php
/**
 * Send log.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Records the outcome of every send attempt.
 *
 * Message bodies are never stored - only envelope metadata and the error. A
 * mail log that keeps message content is a liability the moment the site is
 * compromised, and it is not needed to diagnose delivery problems.
 */
class Logger {

	public const CRON_HOOK = 'mmoa_prune_log';

	private const DB_VERSION_OPTION = 'mmoa_db_version';
	private const DB_VERSION        = '1';

	public function __construct( private Settings $settings ) {}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mmoa_log';
	}

	/**
	 * Create or migrate the log table.
	 */
	public static function install(): void {
		global $wpdb;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				provider varchar(32) NOT NULL DEFAULT '',
				recipients text NOT NULL,
				subject text NOT NULL,
				status varchar(16) NOT NULL DEFAULT '',
				error_code varchar(64) NOT NULL DEFAULT '',
				error_message text NOT NULL,
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY status (status)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function uninstall(): void {
		global $wpdb;

		$table = self::table();

		// Table name cannot be parameterized; it is built from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Record one send attempt.
	 *
	 * @param true|WP_Error $result Outcome.
	 */
	public function record( Provider_Interface $provider, PHPMailer $mailer, int $bytes, $result ): void {
		if ( ! $this->settings->get( 'log_enabled' ) ) {
			return;
		}

		global $wpdb;

		$recipients = array_map(
			static fn( array $addr ): string => $addr[0],
			array_merge( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() )
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at'    => current_time( 'mysql', true ),
				'provider'      => $provider->get_label(),
				'recipients'    => implode( ', ', $recipients ),
				'subject'       => $mailer->Subject,
				'status'        => is_wp_error( $result ) ? 'failed' : 'sent',
				'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
				'error_message' => is_wp_error( $result ) ? $result->get_error_message() : '',
				'bytes'         => $bytes,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);
	}

	/**
	 * @return array<int,object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);
	}

	/**
	 * Drop entries past the retention window. Runs on cron.
	 */
	public function prune(): void {
		global $wpdb;

		$days = max( 1, (int) $this->settings->get( 'log_retention' ) );
		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
