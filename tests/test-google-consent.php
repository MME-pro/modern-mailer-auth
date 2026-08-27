<?php
/**
 * Gmail sign-in flow: authorization URL, callback, disconnect.
 *
 * The client ID and secret are the site's own throughout - there is no shared
 * application anywhere in this path, so every assertion here is about a grant
 * issued directly between the site and Google.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Auth\Google_Consent;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin  = ModernMailer\Plugin::instance();
$consent = $plugin->consent;

ModernMailer\Logger::install();
ModernMailer\Queue::install();

// A capable user, since the flow is admin-only and stores state per user.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

// google_setup_mode is stated rather than assumed: this suite is entirely
// about the own-client flow, and inheriting one-click from another test
// would silently reroute it through the setup service.
$plugin->settings->update( [
	'provider'          => 'gmail_oauth',
	'google_setup_mode' => 'own_client',
	'from_email'        => 'me@gmail.com',
	'google_client_id'  => '123-abc.apps.googleusercontent.com',
	'log_enabled'      => true,
	'queue_enabled'    => true,
	'alert_email'      => '',
] );
$plugin->secrets->set( 'google_client_sec', 'client-secret-value' );
$plugin->secrets->set( 'google_refresh', '' );
Settings::flush_cache();
$plugin->install_mailer();

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

/** Pull the query parameters out of an authorization URL. */
function params_of( string $url ): array {
	$out = [];
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $out );
	return $out;
}

echo "\n=== 1. The authorization URL asks Google for the right things ===\n";
$url = $consent->authorization_url( Settings::SLOT_PRIMARY );
check( 'a URL was produced', is_string( $url ), is_wp_error( $url ) ? $url->get_error_message() : '' );

$p = is_string( $url ) ? params_of( $url ) : [];

check( 'it points at the Google consent endpoint', is_string( $url ) && 0 === strpos( $url, 'https://accounts.google.com/o/oauth2/v2/auth' ), (string) $url );
check( 'it carries our own client ID', '123-abc.apps.googleusercontent.com' === ( $p['client_id'] ?? '' ), $p['client_id'] ?? 'missing' );
check( 'it requests only the send scope', 'https://www.googleapis.com/auth/gmail.send' === ( $p['scope'] ?? '' ), $p['scope'] ?? 'missing' );

// The two parameters that decide whether this connection survives an hour.
check( 'access_type=offline, so a refresh token is issued', 'offline' === ( $p['access_type'] ?? '' ), $p['access_type'] ?? 'missing' );
check( 'prompt=consent, so a reconnect also returns one', 'consent' === ( $p['prompt'] ?? '' ), $p['prompt'] ?? 'missing' );

check( 'the redirect URI is not double-encoded', Google_Consent::redirect_uri() === ( $p['redirect_uri'] ?? '' ), $p['redirect_uri'] ?? 'missing' );
check( 'a state parameter is present', ! empty( $p['state'] ) );

echo "\n=== 2. Setup is refused before the client is entered ===\n";
$plugin->secrets->set( 'google_client_sec', '' );
Settings::flush_cache();
$blocked = $consent->authorization_url( Settings::SLOT_PRIMARY );
check( 'no sign-in without a client secret', is_wp_error( $blocked ) );
check( 'and the reason names what is missing', is_wp_error( $blocked ) && false !== strpos( $blocked->get_error_message(), 'client secret' ), is_wp_error( $blocked ) ? $blocked->get_error_message() : '' );
$plugin->secrets->set( 'google_client_sec', 'client-secret-value' );
Settings::flush_cache();

echo "\n=== 3. A forged or stale callback is rejected ===\n";
$bad = $consent->handle_callback( [ 'code' => 'x', 'state' => 'never-issued' ] );
check( 'an unknown state is refused', is_wp_error( $bad ) && 'mmoa_oauth_bad_state' === $bad->get_error_code(), is_wp_error( $bad ) ? $bad->get_error_code() : 'accepted' );

$missing = $consent->handle_callback( [ 'code' => 'x' ] );
check( 'a missing state is refused', is_wp_error( $missing ) && 'mmoa_oauth_bad_state' === $missing->get_error_code() );

echo "\n=== 4. The code is exchanged and the refresh token stored ===\n";
$url   = $consent->authorization_url( Settings::SLOT_PRIMARY );
$state = params_of( $url )['state'];

$sent_body = null;
$calls     = 0;
$script    = function ( $url, $args, $n ) use ( &$sent_body ) {
	$sent_body = $args['body'] ?? [];
	return json_response( 200, [
		'access_token'  => 'AT-1',
		'refresh_token' => 'RT-1',
		'expires_in'    => 3599,
		'scope'         => 'https://www.googleapis.com/auth/gmail.send',
	] );
};

$slot = $consent->handle_callback( [ 'code' => 'AUTH-CODE', 'state' => $state ] );

check( 'the callback succeeded', Settings::SLOT_PRIMARY === $slot, is_wp_error( $slot ) ? $slot->get_error_message() : var_export( $slot, true ) );
check( 'the authorization_code grant was used', 'authorization_code' === ( $sent_body['grant_type'] ?? '' ) );
check( 'the code was sent', 'AUTH-CODE' === ( $sent_body['code'] ?? '' ) );
check( 'our client secret was sent', 'client-secret-value' === ( $sent_body['client_secret'] ?? '' ) );
check( 'the redirect URI matched the authorization request', Google_Consent::redirect_uri() === ( $sent_body['redirect_uri'] ?? '' ) );
check( 'the refresh token was stored', 'RT-1' === $plugin->secrets->get( 'google_refresh' ) );
check( 'the connection reports itself connected', $consent->is_connected( Settings::SLOT_PRIMARY ) );

echo "\n=== 5. The same state cannot be replayed ===\n";
$replay = $consent->handle_callback( [ 'code' => 'AUTH-CODE', 'state' => $state ] );
check( 'a reused state is refused', is_wp_error( $replay ) && 'mmoa_oauth_bad_state' === $replay->get_error_code(), is_wp_error( $replay ) ? $replay->get_error_code() : 'accepted' );

echo "\n=== 6. Sending works off the stored grant ===\n";
$plugin->tokens->flush();
$plugin->health->reset();
$calls  = 0;
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		// A refresh_token grant this time, not an authorization_code one.
		return 'refresh_token' === ( $args['body']['grant_type'] ?? '' )
			? json_response( 200, [ 'access_token' => 'AT-2', 'expires_in' => 3600 ] )
			: json_response( 400, [ 'error' => 'unexpected_grant' ] );
	}
	return json_response( 200, [ 'id' => 'msg-1' ] );
};

$sent = wp_mail( 'someone@example.com', 'via gmail oauth', 'body' );
check( 'the message was delivered', true === $sent, var_export( $sent, true ) );

echo "\n=== 7. A grant with no refresh token is reported, not silently kept ===\n";
// Google withholds refresh_token when the account has already authorized this
// client and prompt=consent was not honoured. Storing nothing and saying so is
// the only safe response: it would otherwise work for an hour and then stop.
$url    = $consent->authorization_url( Settings::SLOT_PRIMARY );
$state  = params_of( $url )['state'];
$before = $plugin->secrets->get( 'google_refresh' );
$calls  = 0;
$script = fn( $url, $args, $n ) => json_response( 200, [ 'access_token' => 'AT-3', 'expires_in' => 3599 ] );

$none = $consent->handle_callback( [ 'code' => 'C2', 'state' => $state ] );
check( 'the missing refresh token is an error', is_wp_error( $none ) && 'mmoa_oauth_no_refresh_token' === $none->get_error_code(), is_wp_error( $none ) ? $none->get_error_code() : 'accepted' );
check( 'the existing grant was left untouched', $before === $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 8. Google's rejections are explained in actionable terms ===\n";
foreach ( [
	'redirect_uri_mismatch' => 'redirect URI',
	'invalid_client'        => 'client ID or client secret',
	'invalid_grant'         => 'already used',
] as $error => $expected ) {
	$url   = $consent->authorization_url( Settings::SLOT_PRIMARY );
	$state = params_of( $url )['state'];
	$calls = 0;
	$script = fn( $u, $a, $n ) => json_response( 400, [ 'error' => $error ] );

	$res = $consent->handle_callback( [ 'code' => 'C', 'state' => $state ] );
	check(
		"{$error} names the real fix",
		is_wp_error( $res ) && false !== strpos( $res->get_error_message(), $expected ),
		is_wp_error( $res ) ? $res->get_error_message() : 'accepted'
	);
}

echo "\n=== 9. Declining at the consent screen is handled ===\n";
$url    = $consent->authorization_url( Settings::SLOT_PRIMARY );
$state  = params_of( $url )['state'];
$denied = $consent->handle_callback( [ 'error' => 'access_denied', 'state' => $state ] );
check( 'a denial is reported', is_wp_error( $denied ) && 'mmoa_oauth_denied' === $denied->get_error_code(), is_wp_error( $denied ) ? $denied->get_error_code() : 'accepted' );

echo "\n=== 10. Disconnect revokes at Google and forgets locally ===\n";
$revoked = null;
$calls   = 0;
$script  = function ( $url, $args, $n ) use ( &$revoked ) {
	$revoked = $args['body']['token'] ?? '';
	return json_response( 200, [] );
};

$result = $consent->disconnect( Settings::SLOT_PRIMARY );
check( 'disconnect succeeded', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'the stored token was the one revoked', 'RT-1' === $revoked, (string) $revoked );
check( 'nothing is left locally', '' === $plugin->secrets->get( 'google_refresh' ) );
check( 'and it reports itself disconnected', ! $consent->is_connected( Settings::SLOT_PRIMARY ) );

echo "\n=== 11. An unreachable revoke endpoint still disconnects locally ===\n";
$plugin->secrets->set( 'google_refresh', 'RT-2' );
$calls  = 0;
$script = fn( $u, $a, $n ) => new WP_Error( 'http_request_failed', 'cURL error 7' );

$result = $consent->disconnect( Settings::SLOT_PRIMARY );
check( 'the failure is surfaced', is_wp_error( $result ) && 'mmoa_oauth_revoke_failed' === $result->get_error_code(), is_wp_error( $result ) ? $result->get_error_code() : 'silent' );
check( 'but the token is gone from this site regardless', '' === $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 12. Primary and backup grants are independent ===\n";
// Both slots share one redirect URI, so the slot has to survive the round trip
// inside `state` - if it did not, connecting the backup would overwrite the
// primary's grant.
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [
	'provider'         => 'gmail_oauth',
	'google_client_id' => '999-zzz.apps.googleusercontent.com',
] );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'google_client_sec', 'backup-secret' );
$plugin->secrets->set( 'google_refresh', 'PRIMARY-RT' );
Settings::flush_cache();

$url   = $consent->authorization_url( Settings::SLOT_BACKUP );
$p     = params_of( $url );
$state = $p['state'];

check( 'the backup uses its own client ID', '999-zzz.apps.googleusercontent.com' === ( $p['client_id'] ?? '' ), $p['client_id'] ?? 'missing' );
check( 'both slots share one redirect URI', Google_Consent::redirect_uri() === ( $p['redirect_uri'] ?? '' ) );

$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 200, [ 'access_token' => 'AT', 'refresh_token' => 'BACKUP-RT', 'expires_in' => 3599 ] );

$slot = $consent->handle_callback( [ 'code' => 'C3', 'state' => $state ] );

check( 'the callback landed on the backup slot', Settings::SLOT_BACKUP === $slot, is_wp_error( $slot ) ? $slot->get_error_message() : var_export( $slot, true ) );
check( 'the backup grant was stored', 'BACKUP-RT' === $plugin->secrets->for_slot( Settings::SLOT_BACKUP )->get( 'google_refresh' ) );
check( 'the primary grant was untouched', 'PRIMARY-RT' === $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 13. An additional connection keeps its own grant ===\n";
// The regression this guards: the callback resolved the slot by asking "is it
// backup? no - then it is primary", so signing in from any of the additional
// connections banked the refresh token over the PRIMARY one. The connection
// being configured stayed disconnected however many times an admin tried, and
// a working primary was overwritten with a grant minted from a different
// OAuth client - which then failed at its next token refresh.
$extra = $plugin->connections->add( 'Newsletter' );
$extra = is_wp_error( $extra ) ? '' : $extra;

check( 'an additional connection was created', '' !== $extra && Settings::SLOT_PRIMARY !== $extra, (string) $extra );

$plugin->settings->for_slot( $extra )->update( [
	'provider'         => 'gmail_oauth',
	'google_client_id' => '777-extra.apps.googleusercontent.com',
] );
$plugin->secrets->for_slot( $extra )->set( 'google_client_sec', 'extra-secret' );
$plugin->secrets->set( 'google_refresh', 'PRIMARY-RT' );
Settings::flush_cache();

$url   = $consent->authorization_url( $extra );
$p     = params_of( $url );
$state = $p['state'];

check( 'it uses its own client ID', '777-extra.apps.googleusercontent.com' === ( $p['client_id'] ?? '' ), $p['client_id'] ?? 'missing' );

$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 200, [ 'access_token' => 'AT', 'refresh_token' => 'EXTRA-RT', 'expires_in' => 3599 ] );

$slot = $consent->handle_callback( [ 'code' => 'C4', 'state' => $state ] );

check( 'the callback landed on that connection, not the primary', $extra === $slot, is_wp_error( $slot ) ? $slot->get_error_message() : var_export( $slot, true ) );
check( 'its grant was stored against itself', 'EXTRA-RT' === $plugin->secrets->for_slot( $extra )->get( 'google_refresh' ) );
check( 'it now reports itself connected', $consent->is_connected( $extra ) );
check( 'the primary grant was not overwritten', 'PRIMARY-RT' === $plugin->secrets->get( 'google_refresh' ), (string) $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 14. A connection deleted mid-flow is refused, not redirected elsewhere ===\n";
// Deleting the connection while the admin is away at Google leaves a state
// transient naming a slot that no longer exists. Writing that grant to the
// primary would be worse than failing.
$url   = $consent->authorization_url( $extra );
$state = params_of( $url )['state'];
$plugin->connections->delete( $extra );
Settings::flush_cache();

$gone = $consent->handle_callback( [ 'code' => 'C5', 'state' => $state ] );

check( 'the callback failed rather than guessing a slot', is_wp_error( $gone ) && 'mmoa_oauth_gone' === $gone->get_error_code(), is_wp_error( $gone ) ? $gone->get_error_code() : var_export( $gone, true ) );
check( 'and the primary grant is still its own', 'PRIMARY-RT' === $plugin->secrets->get( 'google_refresh' ), (string) $plugin->secrets->get( 'google_refresh' ) );

// Leave the site unconfigured, as the other suites do.
$plugin->secrets->set( 'google_refresh', '' );
$plugin->secrets->set( 'google_client_sec', '' );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'google_refresh', '' );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'google_client_sec', '' );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => '' ] );
$plugin->settings->update( [ 'provider' => '' ] );
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->queue->purge();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
