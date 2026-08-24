<?php
/**
 * Retry queue for transient send failures.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Holds messages that failed for a reason worth retrying, and drains them on
 * cron.
 *
 * Why this exists at all: Http already retries three times with backoff, but
 * those attempts all happen inside the one page request, spread over a handful
 * of seconds. That is the right shape for a throttle or a dropped packet, and
 * completely the wrong shape for the failure that actually loses mail - an
 * outbound DNS or egress fault at the host, which resolves in minutes, not
 * milliseconds. Three attempts three seconds apart all hit the same broken
 * resolver, and then the message is gone for good.
 *
 * Retrying across requests is the only thing that survives that, and it needs
 * the message to outlive the request that produced it.
 *
 * ## On storing message bodies
 *
 * Logger deliberately never stores message content, because a mail log holding
 * bodies is a standing liability. This table has no such choice available - you
 * cannot resend a message you did not keep. The mitigations are therefore
 * structural rather than optional:
 *
 * - a row is deleted the moment it is delivered, so the steady state is empty;
 * - a row that exhausts its attempts is kept only until the discard window
 *   passes, then deleted body and all;
 * - the table is dropped on uninstall.
 *
 * A queue that holds bodies for minutes is a very different exposure from a log
 * that holds them for thirty days, and it is the price of not losing mail.
 */
class Queue {

	public const CRON_HOOK     = 'mmoa_drain_queue';
	public const SCHEDULE_NAME = 'mmoa_five_minutes';

	private const DB_VERSION_OPTION = 'mmoa_queue_db_version';
	private const DB_VERSION        = '1';

	/**
	 * Rows to attempt per drain.
	 *
	 * Held low on purpose. A drain runs inside a cron request under the same
	 * PHP time limit as any other, and an overrun means the batch dies partway
	 * with rows still claimed. Small batches every five minutes drain a backlog
	 * just as fast in aggregate and cannot wedge.
	 */
	private const BATCH = 20;

	/**
	 * Give up after this many attempts.
	 *
	 * With the backoff below this spans roughly two days, which comfortably
	 * outlasts the class of outage this exists for. Past that point the failure
	 * is not transient and retrying is not the answer.
	 */
	private const MAX_ATTEMPTS = 8;

	/**
	 * Keep exhausted rows this long before deleting them.
	 *
	 * They are useless for delivery by then, but they are the evidence an admin
	 * needs to see that mail was lost and what it was - so they outlive the
	 * final attempt, and not much longer.
	 */
	private const DISCARD_AFTER_DAYS = 7;

	/** A claim older than this belonged to a request that died mid-drain. */
	private const CLAIM_TTL = 600;

	public function __construct( private Settings $settings ) {}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mmoa_queue';
	}

	/**
	 * Create or migrate the queue table.
	 */
	public static function install(): void {
		global $wpdb;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// next_attempt_at is indexed together with status because that pair is
		// the only query the drain ever runs, and it runs it on every cron tick
		// on sites where the table is large precisely because something is
		// wrong.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				next_attempt_at datetime NOT NULL,
				claimed_at datetime DEFAULT NULL,
				claim_token varchar(40) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'pending',
				recipients text NOT NULL,
				subject text NOT NULL,
				raw_mime longtext NOT NULL,
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				error_code varchar(64) NOT NULL DEFAULT '',
				error_message text NOT NULL,
				PRIMARY KEY  (id),
				KEY drain (status,next_attempt_at),
				KEY created_at (created_at)
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

	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'queue_enabled' );
	}

	/**
	 * Store a message for a later attempt.
	 *
	 * @param string   $raw_mime Complete RFC 822 message.
	 * @param string[] $recipients Envelope recipients, for the admin screen.
	 * @return bool True when the message is safely persisted.
	 */
	public function enqueue( string $raw_mime, array $recipients, string $subject, WP_Error $error ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at'      => $now,
				// First retry is not immediate: whatever just failed is still
				// failing, and hammering it changes nothing.
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $this->backoff( 1 ) ),
				'attempts'        => 0,
				'status'          => 'pending',
				'recipients'      => implode( ', ', $recipients ),
				'subject'         => $subject,
				'raw_mime'        => $raw_mime,
				'bytes'           => strlen( $raw_mime ),
				'error_code'      => (string) $error->get_error_code(),
				'error_message'   => $error->get_error_message(),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return (bool) $inserted;
	}

	/**
	 * Attempt everything that is due. Runs on cron.
	 *
	 * @return array{attempted:int,sent:int,failed:int,exhausted:int}
	 */
	public function drain( Dispatcher $dispatcher ): array {
		$stats = [
			'attempted' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'exhausted' => 0,
		];

		$this->release_stale_claims();
		$this->discard_expired();

		foreach ( $this->claim_due() as $row ) {
			++$stats['attempted'];

			$result = $dispatcher->dispatch_raw(
				(string) $row->raw_mime,
				$this->rebuild_mailer( $row )
			);

			if ( true === $result ) {
				$this->delete( (int) $row->id );
				++$stats['sent'];
				continue;
			}

			$attempts  = (int) $row->attempts + 1;
			$exhausted = $attempts >= self::MAX_ATTEMPTS || ! Failure::is_retryable( $result );

			$this->record_attempt( (int) $row->id, $attempts, $result, $exhausted );

			if ( $exhausted ) {
				++$stats['exhausted'];
			} else {
				++$stats['failed'];
			}
		}

		if ( $stats['exhausted'] > 0 ) {
			/**
			 * Fires when queued mail has been abandoned for good.
			 *
			 * Unlike a single send failure, this is unambiguous data loss and
			 * the right place to page someone.
			 *
			 * @param int $count Messages abandoned in this drain.
			 */
			do_action( 'mmoa_queue_exhausted', $stats['exhausted'] );
		}

		return $stats;
	}

	/**
	 * @return array{pending:int,failed:int,next:?string}
	 */
	public function stats(): array {
		global $wpdb;

		$table = self::table();

		$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"
		);
		$failed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
		);
		$next = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT MIN(next_attempt_at) FROM {$table} WHERE status = 'pending'"
		);

		return [
			'pending' => $pending,
			'failed'  => $failed,
			'next'    => null === $next ? null : (string) $next,
		];
	}

	/**
	 * @return array<int,object>
	 */
	public function recent( int $limit = 25 ): array {
		global $wpdb;

		$table = self::table();

		// raw_mime is deliberately excluded: nothing in the admin screen needs
		// the body, and selecting it would pull megabytes into memory to render
		// a table of subjects.
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, created_at, next_attempt_at, attempts, status, recipients, subject, bytes, error_code, error_message
				 FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Make every pending row due immediately. Backs the "Retry now" button.
	 */
	public function reschedule_all(): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET next_attempt_at = %s, claimed_at = NULL, claim_token = ''
				 WHERE status = 'pending'",
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Move exhausted rows back into the queue for one more run of attempts.
	 */
	public function requeue_failed(): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', attempts = 0, next_attempt_at = %s, claimed_at = NULL, claim_token = ''
				 WHERE status = 'failed'",
				current_time( 'mysql', true )
			)
		);
	}

	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function purge(): void {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Claim a batch of due rows.
	 *
	 * The claim is what stops two overlapping cron runs from sending the same
	 * message twice. WordPress cron offers no mutual exclusion of its own - on
	 * a busy site two requests can both decide a job is due - and a duplicate
	 * email is a worse failure than a late one, so the claim is taken with a
	 * conditional UPDATE and only rows we actually won are read back.
	 *
	 * @return array<int,object>
	 */
	private function claim_due(): array {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );
		$token = wp_generate_uuid4();

		// Mark our batch. LIMIT on UPDATE with an ORDER BY is deterministic
		// enough here; the only cost of an unlucky interleaving is that a row
		// waits for the next tick.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET claimed_at = %s, claim_token = %s
				 WHERE status = 'pending' AND claimed_at IS NULL AND next_attempt_at <= %s
				 ORDER BY next_attempt_at ASC LIMIT %d",
				$now,
				$token,
				$now,
				self::BATCH
			)
		);

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE claim_token = %s ORDER BY next_attempt_at ASC",
				$token
			)
		);
	}

	/**
	 * Return rows whose claiming request never came back.
	 */
	private function release_stale_claims(): void {
		global $wpdb;

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET claimed_at = NULL
				 WHERE status = 'pending' AND claimed_at IS NOT NULL AND claimed_at < DATE_SUB(%s, INTERVAL %d SECOND)",
				current_time( 'mysql', true ),
				self::CLAIM_TTL
			)
		);
	}

	private function discard_expired(): void {
		global $wpdb;

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE status = 'failed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				self::DISCARD_AFTER_DAYS
			)
		);
	}

	private function record_attempt( int $id, int $attempts, WP_Error $error, bool $exhausted ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'attempts'        => $attempts,
				'status'          => $exhausted ? 'failed' : 'pending',
				'claimed_at'      => null,
				'claim_token'     => '',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $this->backoff( $attempts + 1 ) ),
				'error_code'      => (string) $error->get_error_code(),
				'error_message'   => $error->get_error_message(),
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Seconds to wait before attempt N.
	 *
	 * Exponential from five minutes, capped at six hours: 5m, 10m, 20m, 40m,
	 * 80m, 160m, 320m, 6h. The floor matters as much as the growth - retrying a
	 * host-level network fault sooner than that has never once helped, and only
	 * spends the attempt budget.
	 */
	private function backoff( int $attempt ): int {
		$seconds = 300 * ( 2 ** max( 0, $attempt - 1 ) );

		return (int) min( 6 * HOUR_IN_SECONDS, $seconds );
	}

	/**
	 * Rebuild just enough of a PHPMailer for the send path to log correctly.
	 *
	 * Providers take a PHPMailer but read nothing from it - the recipients and
	 * headers they use are already inside the raw MIME, which is the whole
	 * reason this plugin hands over raw MIME in the first place. Logger does
	 * read it, so the addresses and subject are restored and nothing else is.
	 */
	private function rebuild_mailer( object $row ): PHPMailer {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$mailer          = new PHPMailer( false );
		$mailer->Subject = (string) $row->subject;

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $row->recipients ) ) ) as $address ) {
			try {
				$mailer->addAddress( $address );
			} catch ( \Throwable $e ) {
				// An address that no longer validates must not stop the retry;
				// the copy that matters is the one inside the MIME.
				unset( $e );
			}
		}

		return $mailer;
	}
}
