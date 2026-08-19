<?php
/**
 * CLI bootstrap for the integration suite.
 *
 * Boots WordPress directly so the tests exercise real wp_mail(), real
 * PHPMailer, and the real plugin. Every outbound call is stubbed through the
 * pre_http_request filter, so no credentials are needed and nothing is sent.
 *
 * Override the host with MMOA_TEST_HOST if your dev site uses a different one.
 */

define( 'WP_USE_THEMES', false );

$host = getenv( 'MMOA_TEST_HOST' ) ?: 'localhost';

$_SERVER['HTTP_HOST']      = $host;
$_SERVER['SERVER_NAME']    = $host;
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// tests/ -> plugin -> plugins -> wp-content -> WordPress root
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! is_readable( $wp_load ) ) {
    fwrite( STDERR, "Could not locate wp-load.php at {$wp_load}\n" );
    fwrite( STDERR, "The plugin must live in wp-content/plugins/ of a WordPress install.\n" );
    exit( 1 );
}

require $wp_load;
