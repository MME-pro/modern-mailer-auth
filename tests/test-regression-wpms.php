<?php
/**
 * Regression tests for the failure reported against WP Mail SMTP 4.9.0's
 * Outlook mailer:
 *
 *   http_request_failed: ["No valid URL was specified."]
 *
 * That string is not WordPress core's ("A valid URL was not provided."), not
 * the Requests library's, and not libcurl's ("URL using bad/illegal format or
 * missing URL"). It is the other plugin's own guard, firing because it was
 * about to request a URL that had gone empty.
 *
 * A URL cannot go empty from configuration - configuration does not change
 * between email 9 and email 16. It goes empty because it was read out of a
 * previous response that did not contain what was expected. The observed
 * trigger fits: the failing message was multipart/related (an inline image),
 * and it appeared only after a burst of sends - i.e. once throttling started.
 *
 * These tests pin the two properties that make that impossible here.
 */
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
$plugin->settings->update( [
	'provider' => 'graph', 'from_email' => 'kontakt@example.de', 'from_name' => 'Example Studio',
	'ms_tenant_id' => 'tid', 'ms_client_id' => 'cid', 'ms_sender' => 'kontakt@example.de',
	'log_enabled' => true, 'alert_threshold' => 3,
] );
$plugin->secrets->set( 'ms_client_secret', 'secret' );
$plugin->install_mailer();

$requests = [];
$script   = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests, &$script ) {
	$requests[] = [ 'url' => $url, 'args' => $args ];
	return $script ? ( $script )( $url, $args, count( $requests ) ) : $pre;
}, 10, 3 );

function resp( int $code, $body = '' ): array {
	return [ 'headers' => [], 'body' => is_array( $body ) ? wp_json_encode( $body ) : $body,
		'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
}
$ok_all = function ( $url, $args, $n ) {
	return false !== strpos( $url, 'login.microsoftonline' )
		? resp( 200, [ 'access_token' => 'T', 'expires_in' => 3600 ] )
		: resp( 202 );
};
function last_error( callable $fn ): ?WP_Error {
	$err = null; $cap = function ( $e ) use ( &$err ) { $err = $e; };
	add_action( 'wp_mail_failed', $cap ); $fn(); remove_action( 'wp_mail_failed', $cap );
	return $err;
}

// A message shaped exactly like the one that failed: HTML with an embedded
// logo and no file attachment, which PHPMailer renders as multipart/related.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
add_action( 'phpmailer_init', function ( $m ) use ( $png ) {
	$m->addStringEmbeddedImage( $png, 'llar-logo', 'logo.png', 'base64', 'image/png' );
} );
function llar_mail(): bool {
	return wp_mail(
		'wp-admin@example.de',
		'Failed login by IP 203.0.113.42 example.de',
		'<html><body><img src="cid:llar-logo"><p>Failed login attempt.</p></body></html>',
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}

echo "\n=== 1. The failing message shape sends in a single request ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$requests = []; $script = $ok_all;
$sent = llar_mail();
check( 'multipart/related message sent', true === $sent, var_export( $sent, true ) );

$sends = array_values( array_filter( $requests, fn( $r ) => false !== strpos( $r['url'], 'graph.microsoft.com' ) ) );
check( 'exactly ONE Graph request - no draft, no upload session, no send step',
	1 === count( $sends ), count( $sends ) . ' Graph requests' );

$mime = base64_decode( $sends[0]['args']['body'], true );
check( 'the message really is multipart/related',
	false !== stripos( (string) $mime, 'multipart/related' ) );
check( 'the inline image is in that single request',
	false !== strpos( (string) $mime, 'llar-logo' ) );

check( 'every URL was built from configuration, never from a response body',
	(bool) array_reduce( $requests, fn( $c, $r ) => $c && (
		0 === strpos( $r['url'], 'https://login.microsoftonline.com/' ) ||
		0 === strpos( $r['url'], 'https://graph.microsoft.com/v1.0/users/' ) ), true ) );

echo "\n=== 2. A burst does not degrade (the '10-15 mails' pattern) ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$requests = []; $script = $ok_all;
$results = [];
for ( $i = 0; $i < 20; $i++ ) { $results[] = llar_mail(); }
check( 'all 20 sends succeeded', 20 === count( array_filter( $results ) ),
	count( array_filter( $results ) ) . '/20' );
$tokens = array_filter( $requests, fn( $r ) => false !== strpos( $r['url'], 'login.microsoftonline' ) );
check( 'one token minted for the whole burst', 1 === count( $tokens ), count( $tokens ) . ' token calls' );
check( 'no request count growth per message', 21 === count( $requests ), count( $requests ) . ' total' );

echo "\n=== 3. Throttling reports throttling, not a URL problem ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$requests = [];
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, 'login.microsoftonline' ) ) {
		return resp( 200, [ 'access_token' => 'T', 'expires_in' => 3600 ] );
	}
	return resp( 429, [ 'error' => [ 'code' => 'ApplicationThrottled', 'message' => 'Too many requests' ] ] );
};

// Queue off for this section. A throttle is retryable, so with the queue on the
// message is banked for a later attempt and wp_mail() reports success - which is
// the behaviour we want, and is asserted immediately below. What is being
// checked here is the error text itself, and that is only observable when the
// failure is allowed to reach the caller.
$plugin->settings->update( [ 'queue_enabled' => false ] );
ModernMailer\Settings::flush_cache();

$err = last_error( 'llar_mail' );
check( 'error names throttling', $err && false !== stripos( $err->get_error_message(), 'throttl' ),
	$err ? $err->get_error_message() : 'no error' );
check( 'error does NOT mention a URL', $err && false === stripos( $err->get_error_message(), 'url' ),
	$err ? $err->get_error_message() : '' );

echo "\n=== 3b. With the queue on, a throttled message is kept, not lost ===\n";
// The other half of the same story. The original bug did not merely misreport
// throttling - it lost the email. Naming the cause correctly is worth little if
// the message still disappears.
$plugin->settings->update( [ 'queue_enabled' => true ] );
ModernMailer\Settings::flush_cache();
$plugin->queue->purge();
$plugin->tokens->flush(); $plugin->health->reset();

$sent = llar_mail();
check( 'the caller is told the message is in hand', true === $sent, var_export( $sent, true ) );
check( 'and it really is queued', 1 === $plugin->queue->stats()['pending'],
	wp_json_encode( $plugin->queue->stats() ) );

// It must also still be reported as unhealthy - a queue silently absorbing
// every send is the same invisible failure in a new costume.
check( 'the throttling still counted against health', $plugin->health->state()['streak'] >= 1 );

$plugin->queue->purge();

echo "\n=== 4. A failed upstream call can never become a request URL ===\n";
// Directly exercise the guard with the shapes that slip past WordPress's own
// check, which only requires a scheme.
$http = new ModernMailer\Http();
foreach ( [ '' => 'empty string', 'https://' => 'scheme but no host',
            '/v1.0/me/sendMail' => 'path only', 'ftp://x/y' => 'wrong scheme',
            'graph.microsoft.com/v1.0' => 'no scheme' ] as $bad => $desc ) {
	$r = $http->request( (string) $bad, [] );
	check( "rejected before the wire: {$desc}",
		is_wp_error( $r ) && 'mmoa_invalid_url' === $r->get_error_code(),
		is_wp_error( $r ) ? $r->get_error_code() : 'not an error' );
}
$r = $http->request( 'https://', [] );
check( 'guard explains it is an internal fault with a prior cause',
	is_wp_error( $r ) && false !== stripos( $r->get_error_message(), 'earlier API call failed' ),
	is_wp_error( $r ) ? $r->get_error_message() : '' );

echo "\n=== 5. Failures surface instead of accumulating ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
for ( $i = 0; $i < 3; $i++ ) { llar_mail(); }
check( 'repeated failures raise the alarm', $plugin->health->is_failing(),
	'streak=' . $plugin->health->state()['streak'] );
$logged = $plugin->logger->recent( 1 );
check( 'the real error is what got logged',
	isset( $logged[0] ) && false !== stripos( $logged[0]->error_message, 'throttl' ),
	$logged[0]->error_message ?? 'nothing logged' );

// Leave the site unconfigured.
$plugin->settings->update( [ 'provider' => '', 'ms_tenant_id' => '', 'ms_client_id' => '',
	'ms_sender' => '', 'from_email' => '', 'from_name' => '' ] );
$plugin->secrets->flush(); $plugin->tokens->flush(); $plugin->health->reset();
global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mmoa_log" );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
