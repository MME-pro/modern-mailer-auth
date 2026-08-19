<?php
/**
 * Exercises the full wp_mail -> MIME -> Graph pipeline with stubbed HTTP.
 */
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

// --- configure the Graph provider with throwaway credentials -----------------
$plugin = ModernMailer\Plugin::instance();
$plugin->settings->update( [
	'provider'     => 'graph',
	'from_email'   => 'noreply@contoso.com',
	'from_name'    => 'Contoso Site',
	'force_from'   => true,
	'ms_tenant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	'ms_client_id' => '11111111-2222-3333-4444-555555555555',
	'ms_sender'    => 'noreply@contoso.com',
	'log_enabled'  => true,
] );
$plugin->secrets->set( 'ms_client_secret', 'test-secret-value' );
$plugin->tokens->flush();
$plugin->health->reset();

// The plugin normally installs the mailer on plugins_loaded; that already ran
// before these settings existed, so install it now.
$plugin->install_mailer();

// --- stub every outbound request --------------------------------------------
$requests = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = [ 'url' => $url, 'args' => $args ];

	if ( false !== strpos( $url, 'login.microsoftonline.com' ) ) {
		return [
			'headers'  => [],  // plain array on purpose - exercises Http::headers()
			'body'     => wp_json_encode( [ 'access_token' => 'FAKE_TOKEN', 'expires_in' => 3600 ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [], 'filename' => null,
		];
	}

	return [
		'headers'  => [],
		'body'     => '',
		'response' => [ 'code' => 202, 'message' => 'Accepted' ],
		'cookies'  => [], 'filename' => null,
	];
}, 10, 3 );

// --- build a message that exercises everything hard --------------------------
$attach = $SCRATCH_ATTACH = sys_get_temp_dir() . '/mmoa-test.txt';
file_put_contents( $attach, "attachment payload\n" );

// 1x1 transparent PNG
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );

add_action( 'phpmailer_init', function ( $m ) use ( $png ) {
	$m->addStringEmbeddedImage( $png, 'logo123', 'logo.png', 'base64', 'image/png' );
} );

$sent = wp_mail(
	'recipient@example.com',
	'Test subject with UTF-8: café — ünïcode',
	'<p>Hello <strong>world</strong></p><img src="cid:logo123" alt="logo">',
	[
		'Content-Type: text/html; charset=UTF-8',
		'Cc: cc-person@example.com',
		'Bcc: bcc-person@example.com',
		'Reply-To: replies@contoso.com',
		'X-Custom-Header: custom-value',
	],
	[ $attach ]
);

echo "\n=== Results ===\n";
check( 'wp_mail() returned true', true === $sent, var_export( $sent, true ) );
check( 'two HTTP calls made (token + send)', 2 === count( $requests ), count( $requests ) . ' calls' );

// --- token request -----------------------------------------------------------
$token_req = $requests[0] ?? null;
if ( $token_req ) {
	check( 'token URL is the tenant token endpoint',
		$token_req['url'] === 'https://login.microsoftonline.com/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/oauth2/v2.0/token',
		$token_req['url'] );
	$b = $token_req['args']['body'];
	check( 'grant_type is client_credentials', 'client_credentials' === ( $b['grant_type'] ?? '' ) );
	check( 'scope is the .default form', 'https://graph.microsoft.com/.default' === ( $b['scope'] ?? '' ), $b['scope'] ?? '' );
	check( 'no refresh_token anywhere in the flow', ! isset( $b['refresh_token'] ) );
}

// --- send request ------------------------------------------------------------
$send_req = $requests[1] ?? null;
if ( $send_req ) {
	check( 'send URL targets /users/{upn}/sendMail, not /me',
		$send_req['url'] === 'https://graph.microsoft.com/v1.0/users/noreply%40contoso.com/sendMail',
		$send_req['url'] );
	check( 'Content-Type is text/plain (MIME mode)',
		'text/plain' === ( $send_req['args']['headers']['Content-Type'] ?? '' ) );
	check( 'Authorization carries the minted token',
		'Bearer FAKE_TOKEN' === ( $send_req['args']['headers']['Authorization'] ?? '' ) );

	$raw = base64_decode( $send_req['args']['body'], true );
	check( 'body is valid base64', false !== $raw && '' !== $raw );

	if ( $raw ) {
		$headers = substr( $raw, 0, strpos( $raw, "\r\n\r\n" ) ?: 2000 );

		check( 'To header present',       (bool) preg_match( '/^To:.*recipient@example\.com/mi', $headers ) );
		check( 'Cc header present',       (bool) preg_match( '/^Cc:.*cc-person@example\.com/mi', $headers ) );
		check( 'Bcc header present (API reads recipients from headers)',
		                                  (bool) preg_match( '/^Bcc:.*bcc-person@example\.com/mi', $headers ) );
		check( 'Reply-To header present', (bool) preg_match( '/^Reply-To:.*replies@contoso\.com/mi', $headers ) );
		check( 'custom header survived',  (bool) preg_match( '/^X-Custom-Header: custom-value/mi', $headers ) );
		check( 'From was forced to configured sender',
		                                  (bool) preg_match( '/^From:.*noreply@contoso\.com/mi', $headers ) );
		check( 'Subject encoded (non-ASCII)', (bool) preg_match( '/^Subject: =\?/mi', $headers ) );
		check( 'multipart container',     (bool) preg_match( '/Content-Type: multipart\//i', $headers ) );

		check( 'attachment filename in body',  false !== strpos( $raw, 'mmoa-test.txt' ) );
		check( 'inline image cid in body',     false !== strpos( $raw, 'logo123' ) );
		check( 'inline image is disposition:inline', false !== stripos( $raw, 'Content-Disposition: inline' ) );
		check( 'html part present',            false !== stripos( $raw, 'text/html' ) );
		check( 'plain-text alternative generated', false !== stripos( $raw, 'text/plain' ) );
	}
}

// --- token caching -----------------------------------------------------------
$before = count( $requests );
wp_mail( 'second@example.com', 'Second message', 'body' );
$added = count( $requests ) - $before;
check( 'second send reuses cached token (1 new call, not 2)', 1 === $added, "{$added} new calls" );

// --- log --------------------------------------------------------------------
$recent = $plugin->logger->recent( 5 );
check( 'both sends logged', count( $recent ) >= 2, count( $recent ) . ' rows' );
check( 'logged status is sent', ( $recent[0]->status ?? '' ) === 'sent', $recent[0]->status ?? 'none' );
check( 'log stores no message body',
	! property_exists( $recent[0], 'body' ) && false === strpos( wp_json_encode( $recent[0] ), 'Hello' ) );

@unlink( $attach );
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
