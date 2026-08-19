<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
$key    = file_get_contents( __DIR__ . '/test-sa-key.pem' );

$requests = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = [ 'url' => $url, 'args' => $args ];
	if ( false !== strpos( $url, 'oauth2.googleapis.com' ) ) {
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'access_token' => 'G_TOKEN', 'expires_in' => 3600 ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	}
	return [ 'headers' => [], 'body' => wp_json_encode( [ 'id' => 'abc' ] ),
		'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );

echo "\n=== Service account (domain-wide delegation) ===\n";
$plugin->settings->update( [
	'provider' => 'gmail_sa', 'from_email' => 'noreply@example.com',
	'google_sa_email' => 'wp-mailer@my-project.iam.gserviceaccount.com',
	'google_sender' => 'noreply@example.com', 'log_enabled' => true,
] );
$plugin->secrets->set( 'google_sa_key', $key );
$plugin->tokens->flush(); $plugin->health->reset();
$plugin->install_mailer();

// Content chosen so that standard base64 produces + and / characters.
$binary = '';
for ( $i = 0; $i < 256; $i++ ) { $binary .= chr( $i ); }
$blob = sys_get_temp_dir() . '/mmoa-bin.dat';
file_put_contents( $blob, $binary );

$sent = wp_mail( 'someone@example.com', 'Gmail test', 'Body text', [], [ $blob ] );
check( 'wp_mail() returned true', true === $sent, var_export( $sent, true ) );

$token_req = $requests[0] ?? null;
$send_req  = $requests[1] ?? null;

if ( $token_req ) {
	check( 'token exchange hits Google token endpoint',
		'https://oauth2.googleapis.com/token' === $token_req['url'], $token_req['url'] );
	check( 'uses the jwt-bearer grant',
		'urn:ietf:params:oauth:grant-type:jwt-bearer' === ( $token_req['args']['body']['grant_type'] ?? '' ) );

	$jwt   = (string) ( $token_req['args']['body']['assertion'] ?? '' );
	$parts = explode( '.', $jwt );
	check( 'assertion is a three-part JWT', 3 === count( $parts ), (string) count( $parts ) );

	if ( 3 === count( $parts ) ) {
		$b64url = fn( $s ) => base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		$header = json_decode( $b64url( $parts[0] ), true );
		$claims = json_decode( $b64url( $parts[1] ), true );

		check( 'algorithm is RS256', 'RS256' === ( $header['alg'] ?? '' ), $header['alg'] ?? '?' );
		check( 'iss is the service account', 'wp-mailer@my-project.iam.gserviceaccount.com' === ( $claims['iss'] ?? '' ) );
		check( 'sub is the impersonated mailbox (this is what DWD needs)',
			'noreply@example.com' === ( $claims['sub'] ?? '' ), $claims['sub'] ?? '?' );
		check( 'scope is gmail.send only',
			'https://www.googleapis.com/auth/gmail.send' === ( $claims['scope'] ?? '' ), $claims['scope'] ?? '?' );
		check( 'aud is the token endpoint', 'https://oauth2.googleapis.com/token' === ( $claims['aud'] ?? '' ) );
		check( 'exp is within Google\'s 1 hour ceiling',
			( ( $claims['exp'] ?? 0 ) - ( $claims['iat'] ?? 0 ) ) <= 3600 );

		// Verify the signature really validates against the public key.
		$pub = openssl_pkey_get_details( openssl_pkey_get_private( file_get_contents( __DIR__ . '/test-sa-key.pem' ) ) )['key'];
		$sig = $b64url( $parts[2] );
		check( 'signature verifies against the public key',
			1 === openssl_verify( $parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256 ) );
	}
}

if ( $send_req ) {
	check( 'send URL impersonates the mailbox',
		'https://gmail.googleapis.com/gmail/v1/users/noreply%40example.com/messages/send' === $send_req['url'],
		$send_req['url'] );

	$body = json_decode( $send_req['args']['body'], true );
	$raw  = (string) ( $body['raw'] ?? '' );

	check( 'payload uses the raw field', '' !== $raw );
	check( 'raw is base64URL: no + character', false === strpos( $raw, '+' ) );
	check( 'raw is base64URL: no / character', false === strpos( $raw, '/' ) );
	check( 'raw is base64URL: no = padding',   false === strpos( $raw, '=' ) );

	$decoded = base64_decode( strtr( $raw, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $raw ) % 4 ) % 4 ), true );
	check( 'raw round-trips to a valid MIME message',
		false !== $decoded && false !== strpos( $decoded, 'Subject: Gmail test' ) );
	check( 'attachment survived the round trip',
		false !== $decoded && false !== strpos( $decoded, 'mmoa-bin.dat' ) );

	// Test the encoder directly on bytes that provably differ between the two
	// alphabets, rather than hoping this particular MIME happens to.
	$probe  = "\xfb\xff\xbe";                       // base64 -> "+/++" style
	$std    = base64_encode( $probe );
	$url    = ModernMailer\Auth\Jwt_Signer::base64url( $probe );
	check( 'probe really does differ between the alphabets',
		$std !== $url, "std={$std} url={$url}" );
	check( 'base64url swaps + and / for - and _',
		false === strpos( $url, '+' ) && false === strpos( $url, '/' ), $url );
	check( 'base64url strips padding', false === strpos( $url, '=' ), $url );
	check( 'base64url decodes back to the original bytes',
		$probe === base64_decode( strtr( $url, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $url ) % 4 ) % 4 ), true ) );
}

echo "\n=== Consumer OAuth ===\n";
$requests = [];
$plugin->settings->update( [ 'provider' => 'gmail_oauth', 'google_client_id' => 'client-id.apps.googleusercontent.com' ] );
$plugin->secrets->set( 'google_client_sec', 'client-secret' );
$plugin->secrets->set( 'google_refresh', 'refresh-token-value' );
$plugin->tokens->flush(); $plugin->health->reset();

// Rebuild the dispatcher so it picks up the new provider.
$r = new ReflectionClass( ModernMailer\Dispatcher::class );
$p = $r->getProperty( 'provider' ); $p->setAccessible( true ); $p->setValue( $plugin->dispatcher, null );

$sent = wp_mail( 'someone@example.com', 'OAuth test', 'Body' );
check( 'wp_mail() returned true', true === $sent, var_export( $sent, true ) );

if ( isset( $requests[0] ) ) {
	check( 'refresh_token grant used',
		'refresh_token' === ( $requests[0]['args']['body']['grant_type'] ?? '' ) );
	check( 'refresh token is sent', 'refresh-token-value' === ( $requests[0]['args']['body']['refresh_token'] ?? '' ) );
}
if ( isset( $requests[1] ) ) {
	check( 'consumer flow sends as users/me',
		'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' === $requests[1]['url'], $requests[1]['url'] );
}

echo "\n=== Google error mapping ===\n";
$plugin->tokens->flush(); $plugin->health->reset();
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', fn( $pre, $args, $url ) => [
	'headers' => [], 'body' => wp_json_encode( [ 'error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized' ] ),
	'response' => [ 'code' => 401, 'message' => '' ], 'cookies' => [], 'filename' => null ], 10, 3 );

$err = null;
$cap = function ( $e ) use ( &$err ) { $err = $e; };
add_action( 'wp_mail_failed', $cap );
wp_mail( 'a@example.com', 's', 'b' );
remove_action( 'wp_mail_failed', $cap );
check( 'unauthorized_client points at domain-wide delegation',
	$err && false !== stripos( $err->get_error_message(), 'Domain-wide Delegation' ),
	$err ? $err->get_error_message() : 'no error' );

@unlink( $blob );
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
