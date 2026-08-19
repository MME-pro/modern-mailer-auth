<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
$plugin->settings->update( [
	'provider' => 'graph', 'from_email' => 'noreply@contoso.com',
	'ms_tenant_id' => 'tid', 'ms_client_id' => 'cid', 'ms_sender' => 'noreply@contoso.com',
	'log_enabled' => true, 'alert_threshold' => 2, 'alert_email' => '',
] );
$plugin->secrets->set( 'ms_client_secret', 'secret' );
$plugin->install_mailer();

/** Install a scripted HTTP stub. $script is a callable(url,args,callno) => response array. */
$calls = 0;
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

function capture_failure( callable $fn ): ?WP_Error {
	$err = null;
	$cap = function ( $e ) use ( &$err ) { $err = $e; };
	add_action( 'wp_mail_failed', $cap );
	$fn();
	remove_action( 'wp_mail_failed', $cap );
	return $err;
}

echo "\n=== 1. Mid-life 401 recovers on a fresh token ===\n";
// This is the credential-rotation case: a token that was valid is suddenly
// rejected. App-only should silently mint a new one and deliver.
$plugin->tokens->flush(); $plugin->health->reset(); $calls = 0;
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, 'login.microsoftonline' ) ) { return ok_token(); }
	static $sends = 0; $sends++;
	return 1 === $sends
		? json_response( 401, [ 'error' => [ 'code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.' ] ] )
		: json_response( 202, [] );
};
$sent = wp_mail( 'a@example.com', 'retry test', 'body' );
check( 'delivered despite an expired token, with no human involved', true === $sent, var_export( $sent, true ) );
check( 'stale token was discarded and a new one minted', $calls >= 4, "{$calls} HTTP calls" );

echo "\n=== 2. Error messages name the actual misconfiguration ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$script = fn( $url, $args, $n ) => json_response( 400, [
	'error' => 'invalid_client',
	'error_description' => 'AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value.',
] );
$err = capture_failure( fn() => wp_mail( 'a@example.com', 's', 'b' ) );
check( 'AADSTS7000215 explains the Value-vs-ID trap',
	$err && false !== stripos( $err->get_error_message(), 'secret Value' ), $err ? $err->get_error_message() : 'no error' );

$plugin->tokens->flush(); $plugin->health->reset();
$script = function ( $url, $args, $n ) {
	return false !== strpos( $url, 'login.microsoftonline' ) ? ok_token()
		: json_response( 403, [ 'error' => [ 'code' => 'ErrorAccessDenied', 'message' => 'Access denied' ] ] );
};
$err = capture_failure( fn() => wp_mail( 'a@example.com', 's', 'b' ) );
check( 'access denied points at the Exchange access policy',
	$err && false !== stripos( $err->get_error_message(), 'access policy' ), $err ? $err->get_error_message() : 'no error' );

$plugin->tokens->flush(); $plugin->health->reset();
$script = function ( $url, $args, $n ) {
	return false !== strpos( $url, 'login.microsoftonline' ) ? ok_token()
		: json_response( 404, [ 'error' => [ 'code' => 'ErrorInvalidUser', 'message' => 'not found' ] ] );
};
$err = capture_failure( fn() => wp_mail( 'a@example.com', 's', 'b' ) );
check( 'missing mailbox warns about aliases and distribution lists',
	$err && false !== stripos( $err->get_error_message(), 'distribution list' ), $err ? $err->get_error_message() : 'no error' );

echo "\n=== 3. Failure is never silent ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$script = fn( $url, $args, $n ) => json_response( 400, [ 'error' => 'invalid_client', 'error_description' => 'nope' ] );
$sent = wp_mail( 'a@example.com', 's', 'b' );
check( 'wp_mail() returns false on failure', false === $sent, var_export( $sent, true ) );
$err = capture_failure( fn() => wp_mail( 'a@example.com', 's', 'b' ) );
check( 'wp_mail_failed fires with a real WP_Error', $err instanceof WP_Error );
check( 'streak reached the alert threshold', $plugin->health->is_failing(), 'streak=' . $plugin->health->state()['streak'] );

$fired = false;
add_action( 'mmoa_send_failing', function () use ( &$fired ) { $fired = true; } );
$plugin->health->reset();
wp_mail( 'a@example.com', 's', 'b' );
wp_mail( 'a@example.com', 's', 'b' );
check( 'mmoa_send_failing action fired for external monitoring', $fired );

$health = new ReflectionClass( ModernMailer\Health_Monitor::class );
$plugin->health->reset();
$script = function ( $url, $args, $n ) {
	return false !== strpos( $url, 'login.microsoftonline' ) ? ok_token() : json_response( 202, [] );
};
$plugin->tokens->flush();
wp_mail( 'a@example.com', 's', 'b' );
check( 'a success clears the failing state', ! $plugin->health->is_failing() );

echo "\n=== 4. Oversized message is rejected before the wire ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$calls = 0;
$big = sys_get_temp_dir() . '/mmoa-big.bin';
file_put_contents( $big, str_repeat( 'A', 4 * 1024 * 1024 ) );
$before = $calls;
$err = capture_failure( fn() => wp_mail( 'a@example.com', 'big', 'b', [], [ $big ] ) );
check( 'rejected locally with a size message',
	$err && false !== stripos( $err->get_error_message(), 'over the' ), $err ? $err->get_error_message() : 'no error' );
check( 'message names the double-encoding caveat',
	$err && false !== stripos( $err->get_error_message(), 'encoded twice' ) );
check( 'no send request was made', $calls === $before, "{$calls} vs {$before}" );
@unlink( $big );

echo "\n=== 5. Throttling is honoured, then reported ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, 'login.microsoftonline' ) ) { return ok_token(); }
	static $s = 0; $s++;
	return 1 === $s
		? [ 'headers' => [ 'retry-after' => '1' ], 'body' => '',
			'response' => [ 'code' => 429, 'message' => '' ], 'cookies' => [], 'filename' => null ]
		: json_response( 202, [] );
};
$start = microtime( true );
$sent  = wp_mail( 'a@example.com', 'throttle', 'b' );
$took  = microtime( true ) - $start;
check( 'retried after 429 and delivered', true === $sent, var_export( $sent, true ) );
check( 'honoured the Retry-After delay', $took >= 1.0, sprintf( '%.2fs', $took ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
