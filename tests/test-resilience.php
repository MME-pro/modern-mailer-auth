<?php
/**
 * Stale-token grace, backup connection, and retry queue.
 *
 * The scenario driving all of this is a real one, taken from a site whose host
 * had intermittently broken outbound DNS:
 *
 *   cURL error 7: Failed to connect to login.microsoftonline.com port 443
 *   after 1 ms: Couldn't connect to server
 *
 * Note the timing. A firewall dropping packets produces a multi-second timeout;
 * failing in one millisecond means the connection was refused outright, and it
 * recurred for minutes at a time while credentials and configuration were
 * completely correct. Ninety messages were lost that way, including customer
 * enquiries from a contact form.
 *
 * Each test below is one of the three things that would have saved them.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

ModernMailer\Logger::install();
ModernMailer\Queue::install();

/** Reset to a known primary-only Graph configuration. */
function configure_primary_only( ModernMailer\Plugin $plugin ): void {
	$plugin->settings->update( [
		'provider'      => 'graph',
		'from_email'    => 'noreply@contoso.com',
		'ms_tenant_id'  => 'tid',
		'ms_client_id'  => 'cid',
		'ms_sender'     => 'noreply@contoso.com',
		'log_enabled'   => true,
		'queue_enabled' => true,
		'alert_email'   => '',
	] );
	$plugin->secrets->set( 'ms_client_secret', 'secret' );

	// Clear any backup left behind by an earlier test.
	$plugin->settings->for_slot( ModernMailer\Settings::SLOT_BACKUP )->update( [ 'provider' => '' ] );
}

function reset_state( ModernMailer\Plugin $plugin ): void {
	$plugin->tokens->flush();
	$plugin->health->reset();
	$plugin->queue->purge();
	ModernMailer\Settings::flush_cache();
}

configure_primary_only( $plugin );
$plugin->install_mailer();

/** Scripted HTTP stub. $script is callable(url,args,callno) => response|WP_Error. */
$calls  = 0;
$script = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$script, &$calls ) {
	$calls++;
	return $script ? ( $script )( $url, $args, $calls ) : $pre;
}, 10, 3 );

function json_response( int $code, array $body ): array {
	return [ 'headers' => [], 'body' => wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
}
function ok_token(): array { return json_response( 200, [ 'access_token' => 'T' . wp_rand(), 'expires_in' => 3600 ] ); }

/** The exact transport error WordPress produces for the real-world fault. */
function curl7(): WP_Error {
	return new WP_Error(
		'http_request_failed',
		"cURL error 7: Failed to connect to login.microsoftonline.com port 443 after 1 ms: Couldn't connect to server"
	);
}

function is_token_url( string $url ): bool {
	return false !== strpos( $url, 'login.microsoftonline' ) || false !== strpos( $url, 'oauth2.googleapis' );
}

echo "\n=== 1. A token refresh failure spends the token we already hold ===\n";
// The failure this exists for. get() withholds a token 300s before expiry so a
// refresh has room; if that refresh cannot reach the network, the old token is
// still valid and must be used rather than losing the message.
reset_state( $plugin );

// Seed a token that is inside the refresh window but genuinely still valid:
// expires in 200s, and SKEW is 300s.
$key = ( function () use ( $plugin ) {
	$graph = $plugin->dispatcher->provider();
	$m     = new ReflectionMethod( $graph, 'token_cache_key' );
	$m->setAccessible( true );
	return $m->invoke( $graph );
} )();

$plugin->tokens->put( $key, 'STALE-BUT-VALID', 200 );
check( 'get() withholds a token inside the refresh window', null === $plugin->tokens->get( $key ) );
check( 'get_stale() still offers it', 'STALE-BUT-VALID' === $plugin->tokens->get_stale( $key ) );

$degraded   = 0;
$sent_token = null;
add_action( 'mmoa_token_refresh_degraded', function () use ( &$degraded ) { $degraded++; } );

$calls  = 0;
$script = function ( $url, $args, $n ) use ( &$sent_token ) {
	if ( is_token_url( $url ) ) {
		return curl7();                       // token endpoint unreachable
	}
	$sent_token = $args['headers']['Authorization'] ?? '';
	return json_response( 202, [] );          // Graph itself is fine
};

$sent = wp_mail( 'a@example.com', 'stale token', 'body' );
check( 'the message was delivered anyway', true === $sent, var_export( $sent, true ) );
check( 'it used the token we already had', 'Bearer STALE-BUT-VALID' === $sent_token, (string) $sent_token );
check( 'the degradation was announced for monitoring', 1 === $degraded, "fired {$degraded}x" );

echo "\n=== 2. A genuinely expired token is not reused ===\n";
// The grace must not become "ignore expiry". Past real expiry there is nothing
// to fall back to and the send has to fail.
reset_state( $plugin );

// Written straight to the option: put() floors every lifetime at 60 seconds, so
// it cannot express "already expired" - which is correct of it, and means the
// only way to set this state up is by hand.
update_option( 'mmoa_tokens', [ $key => [ 'token' => 'LONG-EXPIRED', 'expires_at' => time() - 10 ] ], false );
check( 'get_stale() refuses an expired token', null === $plugin->tokens->get_stale( $key ) );

$calls  = 0;
$script = fn( $url, $args, $n ) => is_token_url( $url ) ? curl7() : json_response( 202, [] );
$err    = null;
$cap    = function ( $e ) use ( &$err ) { $err = $e; };
add_action( 'wp_mail_failed', $cap );
wp_mail( 'a@example.com', 'expired token', 'body' );
remove_action( 'wp_mail_failed', $cap );

// The queue accepts it, so wp_mail() reports success - but it must not have
// been sent, and it must be sitting in the queue.
check( 'nothing was delivered', 1 === $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );

echo "\n=== 3. A transport failure queues the message instead of losing it ===\n";
reset_state( $plugin );
$calls  = 0;
$script = fn( $url, $args, $n ) => curl7();

$sent = wp_mail( 'customer@example.com', 'Neue Nachricht uber Ihre Webseite', 'enquiry body' );
$stats = $plugin->queue->stats();

check( 'wp_mail() reports success, because delivery is still pending', true === $sent, var_export( $sent, true ) );
check( 'the message is on the queue', 1 === $stats['pending'], wp_json_encode( $stats ) );
check( 'the first retry is scheduled, not immediate', null !== $stats['next'] && strtotime( $stats['next'] . ' UTC' ) > time() + 60 );
check( 'the outage still counted against health', $plugin->health->state()['streak'] >= 1 );

echo "\n=== 4. The queue delivers once the network recovers ===\n";
// Same message, same credentials - only the network changed. This is the whole
// point: the 90 lost emails were all deliverable minutes later.
$plugin->queue->reschedule_all();
$calls  = 0;
$script = fn( $url, $args, $n ) => is_token_url( $url ) ? ok_token() : json_response( 202, [] );

$drained = $plugin->queue->drain( $plugin->dispatcher );

check( 'the queued message was attempted', 1 === $drained['attempted'], wp_json_encode( $drained ) );
check( 'and delivered', 1 === $drained['sent'], wp_json_encode( $drained ) );
check( 'and removed from the queue', 0 === $plugin->queue->stats()['pending'] );

echo "\n=== 5. A permanent failure is never queued ===\n";
// Retrying a wrong client secret forever would bury real mail behind it.
reset_state( $plugin );
$calls  = 0;
$script = fn( $url, $args, $n ) => json_response( 400, [
	'error'             => 'invalid_client',
	'error_description' => 'AADSTS7000215: Invalid client secret provided.',
] );

$err = null;
$cap = function ( $e ) use ( &$err ) { $err = $e; };
add_action( 'wp_mail_failed', $cap );
$sent = wp_mail( 'a@example.com', 'bad secret', 'body' );
remove_action( 'wp_mail_failed', $cap );

check( 'the send failed loudly', false === $sent );
check( 'nothing was queued', 0 === $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );
check( 'and the error still names the real cause', $err instanceof WP_Error && false !== strpos( $err->get_error_message(), 'client secret' ), $err ? $err->get_error_message() : 'none' );

echo "\n=== 6. An oversized message is not queued and not sent to the backup ===\n";
// A property of the message, not the connection: no amount of patience or
// alternative credentials will make it fit.
reset_state( $plugin );
$plugin->settings->for_slot( ModernMailer\Settings::SLOT_BACKUP )->update( [
	'provider'        => 'gmail_sa',
	'google_sa_email' => 'sa@project.iam.gserviceaccount.com',
	'google_sender'   => 'noreply@contoso.com',
] );
$plugin->secrets->for_slot( ModernMailer\Settings::SLOT_BACKUP )
	->set( 'google_sa_key', file_get_contents( __DIR__ . '/test-sa-key.pem' ) );
ModernMailer\Settings::flush_cache();
$plugin->dispatcher->reset_providers();
$plugin->install_mailer();

$calls  = 0;
$script = fn( $url, $args, $n ) => is_token_url( $url ) ? ok_token() : json_response( 202, [] );
$big    = str_repeat( 'x', 4 * 1024 * 1024 );

$sent = wp_mail( 'a@example.com', 'huge', $big );

check( 'the oversized send failed', false === $sent );
check( 'no HTTP call was made at all', 0 === $calls, "{$calls} calls" );
check( 'nothing was queued', 0 === $plugin->queue->stats()['pending'] );

echo "\n=== 7. The backup connection delivers what the primary cannot ===\n";
reset_state( $plugin );
$used_backup = 0;
add_action( 'mmoa_backup_used', function () use ( &$used_backup ) { $used_backup++; } );

$hit = [ 'graph' => 0, 'gmail' => 0 ];
$calls  = 0;
$script = function ( $url, $args, $n ) use ( &$hit ) {
	if ( false !== strpos( $url, 'microsoft' ) ) {
		$hit['graph']++;
		return curl7();                       // primary is unreachable, entirely
	}
	if ( false !== strpos( $url, 'oauth2.googleapis' ) ) {
		return ok_token();
	}
	$hit['gmail']++;
	return json_response( 200, [ 'id' => 'abc' ] );
};

$sent = wp_mail( 'a@example.com', 'via backup', 'body' );

check( 'the message was delivered', true === $sent, var_export( $sent, true ) );
check( 'the primary was tried first', $hit['graph'] > 0 );
check( 'the backup actually sent it', $hit['gmail'] > 0 );
check( 'the fallback was announced', 1 === $used_backup, "fired {$used_backup}x" );
check( 'nothing was queued, because nothing was lost', 0 === $plugin->queue->stats()['pending'] );
check( 'health is clean, because the site did deliver', ! $plugin->health->is_failing() );

echo "\n=== 7b. A test message is never rescued by anything ===\n";
// Send test exists to answer one question: does the primary work? Every
// mechanism that makes ordinary sending resilient makes that unanswerable - a
// test that fell through to the backup reported success while the primary was
// broken, which is the exact situation somebody presses the button to find out
// about, and one that got queued reported success for a message never sent.
//
// The arrangement is identical to section 7, where the backup did rescue it.
reset_state( $plugin );
$hit    = [ 'graph' => 0, 'gmail' => 0 ];
$calls  = 0;
$before = $used_backup;

$sent = $plugin->dispatcher->without_fallbacks(
	fn() => wp_mail( 'a@example.com', 'test message', 'body' )
);

check( 'the test reports failure, not the backup\'s success', true !== $sent, var_export( $sent, true ) );
check( 'the primary was tried', $hit['graph'] > 0 );
check( 'the backup was never asked', 0 === $hit['gmail'], "backup called {$hit['gmail']}x" );
check( 'no fallback was announced', $before === $used_backup );
check( 'and nothing was queued behind it', 0 === $plugin->queue->stats()['pending'], (string) $plugin->queue->stats()['pending'] );

echo "\n=== 7c. Ordinary sending still has its safety nets ===\n";
// The flag must not leak past the callback: the same arrangement that was
// deliberately not rescued above must be rescued now.
reset_state( $plugin );
$hit    = [ 'graph' => 0, 'gmail' => 0 ];
$calls  = 0;
$normal = wp_mail( 'a@example.com', 'ordinary message', 'body' );

check( 'an ordinary send still reaches the backup', true === $normal, var_export( $normal, true ) );
check( 'and the backup delivered it', $hit['gmail'] > 0 );

echo "\n=== 8. Both connections down: queued, not lost ===\n";
reset_state( $plugin );
$calls  = 0;
$script = fn( $url, $args, $n ) => curl7();

$sent = wp_mail( 'a@example.com', 'both down', 'body' );

check( 'accepted for later delivery', true === $sent, var_export( $sent, true ) );
check( 'and it is on the queue', 1 === $plugin->queue->stats()['pending'] );

echo "\n=== 9. A queue drain cannot re-enqueue its own retries ===\n";
// Without the draining guard, one failing message would clone itself on every
// tick and the table would grow without bound.
$plugin->queue->reschedule_all();
$calls  = 0;
$script = fn( $url, $args, $n ) => curl7();

$before  = $plugin->queue->stats()['pending'];
$drained = $plugin->queue->drain( $plugin->dispatcher );
$after   = $plugin->queue->stats()['pending'];

check( 'the row was retried', 1 === $drained['attempted'], wp_json_encode( $drained ) );
check( 'it failed again', 1 === $drained['failed'], wp_json_encode( $drained ) );
check( 'the queue did not grow', $after === $before, "{$before} -> {$after}" );

echo "\n=== 10. Backup credentials are stored separately from the primary ===\n";
// The slot scoping is the whole basis of the backup connection; if the two
// slots ever aliased, saving a backup would silently overwrite the primary.
check(
	'primary and backup client secrets are independent',
	'secret' === $plugin->secrets->get( 'ms_client_secret' )
		&& '' === $plugin->secrets->for_slot( ModernMailer\Settings::SLOT_BACKUP )->get( 'ms_client_secret' )
);
check(
	'primary and backup providers are independent',
	'graph' === $plugin->settings->get( 'provider' )
		&& 'gmail_sa' === $plugin->settings->for_slot( ModernMailer\Settings::SLOT_BACKUP )->get( 'provider' )
);
check(
	'site-wide settings read the same through either slot',
	$plugin->settings->get( 'from_email' )
		=== $plugin->settings->for_slot( ModernMailer\Settings::SLOT_BACKUP )->get( 'from_email' )
);

// Leave the site unconfigured, as the other suites do.
$plugin->queue->purge();
$plugin->settings->for_slot( ModernMailer\Settings::SLOT_BACKUP )->update( [ 'provider' => '' ] );
$plugin->settings->update( [ 'provider' => '' ] );
$plugin->tokens->flush();
$plugin->health->reset();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
