<?php
/**
 * Removes everything the plugin created.
 *
 * @package ModernMailer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-queue.php';

ModernMailer\Logger::uninstall();

// The queue can hold message bodies, so dropping it is the one step here that
// removes actual content rather than configuration.
ModernMailer\Queue::uninstall();

foreach ( [ 'mmoa_settings', 'mmoa_secrets', 'mmoa_tokens', 'mmoa_health', 'mmoa_db_version', 'mmoa_queue_db_version' ] as $option ) {
	delete_option( $option );
}

// Refresh locks are transient by nature but are stored as options, so a crash
// during uninstall of an earlier version could leave one behind.
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mmoa\_lock\_%'"
);

wp_clear_scheduled_hook( 'mmoa_prune_log' );
wp_clear_scheduled_hook( 'mmoa_drain_queue' );
