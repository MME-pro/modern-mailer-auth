<?php
/**
 * One-click setup: the brokered path for Google and Microsoft.
 *
 * The property under test throughout is that brokering changes where a
 * credential comes from and nothing else. No message ever goes to the broker,
 * a brokered connection stores the same kind of refresh token a hand-made one
 * does, and every other part of the plugin stays unaware the mode exists.
 */

// Point at a broker that does not exist. Every outbound call is stubbed, so
// what matters is only that the URL is well-formed and that `is_available()`
// answers yes - the address is never dialled.
define( 'MMOA_BROKER_URL', 'https://broker.test/oauth/v1/' );

require __DIR__ . '/bootstrap.php';

use ModernMailer\Auth\Broker;
use ModernMailer\Auth\One_Click;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin    = ModernMailer\Plugin::instance();
$one_click = $plugin->one_click;

ModernMailer\Logger::install();
ModernMailer\Queue::install();

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

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

function params_of( string $url ): array {
	$out = [];
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $out );
	return $out;
}

echo "\n=== 1. The site identifier leaks nothing ===\n";
// WP Mail SMTP builds this from AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY and
// sends the first thirty characters to its API on every request. Those
// constants sign authentication cookies. Ours is random.
$identity = $plugin->identity->get();

check( 'an identifier was minted', '' !== $identity, $identity );
check( 'it is stable across calls', $identity === $plugin->identity->get() );

$salts = preg_replace(
	'/[^a-zA-Z0-9]/',
	'',
	( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
);

check(
	'it is not derived from the site salts',
	'' === $salts || false === strpos( $salts, substr( $identity, 3, 12 ) ),
	'identifier overlaps AUTH_KEY'
);

echo "\n=== 2. The authorization URL carries no credential ===\n";
$url = $one_click->authorization_url( Broker::GOOGLE, Settings::SLOT_PRIMARY );

check( 'a URL was produced', is_string( $url ), is_wp_error( $url ) ? $url->get_error_message() : '' );

$p = is_string( $url ) ? params_of( $url ) : [];

check( 'it points at the broker', is_string( $url ) && 0 === strpos( $url, 'https://broker.test/oauth/v1/google/authorize' ), (string) $url );
check( 'it names this site', ( $p['site_id'] ?? '' ) === $identity, $p['site_id'] ?? 'missing' );
check( 'the callback is not double-encoded', One_Click::callback_url() === ( $p['callback'] ?? '' ), $p['callback'] ?? 'missing' );
check( 'a state parameter is present', ! empty( $p['state'] ) );
check( 'no client secret is anywhere in it', false === stripos( (string) $url, 'secret' ) );

echo "\n=== 3. A forged or stale callback is rejected ===\n";
$bad = $one_click->handle_callback( [ 'handoff' => 'x', 'state' => 'never-issued' ] );
check( 'an unknown state is refused', is_wp_error( $bad ) && 'mmoa_one_click_bad_state' === $bad->get_error_code(), is_wp_error( $bad ) ? $bad->get_error_code() : 'accepted' );

$none = $one_click->handle_callback( [ 'handoff' => 'x' ] );
check( 'a missing state is refused', is_wp_error( $none ) && 'mmoa_one_click_bad_state' === $none->get_error_code() );

echo "\n=== 4. A handoff is claimed and the grant stored ===\n";
$url   = $one_click->authorization_url( Broker::GOOGLE, Settings::SLOT_PRIMARY );
$state = params_of( $url )['state'];

$sent_url  = null;
$sent_body = null;
$calls     = 0;
$script    = function ( $url, $args, $n ) use ( &$sent_url, &$sent_body ) {
	$sent_url  = $url;
	$sent_body = json_decode( (string) ( $args['body'] ?? '' ), true );
	return json_response( 200, [
		'access_token'  => 'AT-1',
		'refresh_token' => 'RT-1',
		'expires_in'    => 3599,
		'email'         => 'someone@gmail.com',
	] );
};

$result = $one_click->handle_callback( [ 'handoff' => 'HANDOFF-1', 'state' => $state ] );

check( 'the callback succeeded', is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'not an array' );
check( 'it claimed against the broker', 'https://broker.test/oauth/v1/google/claim' === $sent_url, (string) $sent_url );
check( 'the handoff was sent', 'HANDOFF-1' === ( $sent_body['handoff'] ?? '' ) );
check( 'the site identified itself', $identity === ( $sent_body['site_id'] ?? '' ) );
check( 'the refresh token was stored', 'RT-1' === $plugin->secrets->get( 'google_refresh' ) );
check( 'the connection was switched to one-click', $one_click->is_one_click( Broker::GOOGLE, Settings::SLOT_PRIMARY ) );
check( 'the account is remembered', 'someone@gmail.com' === $one_click->account( Broker::GOOGLE, Settings::SLOT_PRIMARY ) );
check( 'it reports itself connected', $one_click->is_connected( Broker::GOOGLE, Settings::SLOT_PRIMARY ) );

echo "\n=== 5. The same handoff cannot be replayed ===\n";
$replay = $one_click->handle_callback( [ 'handoff' => 'HANDOFF-1', 'state' => $state ] );
check( 'a reused state is refused', is_wp_error( $replay ) && 'mmoa_one_click_bad_state' === $replay->get_error_code(), is_wp_error( $replay ) ? $replay->get_error_code() : 'accepted' );

echo "\n=== 6. A grant with no refresh token is refused, not stored ===\n";
// It would work for an hour and then stop. Failing while the admin is still
// looking at the screen is the only useful moment to fail.
$plugin->secrets->set( 'google_refresh', 'KEEP-ME' );
Settings::flush_cache();

$url    = $one_click->authorization_url( Broker::GOOGLE, Settings::SLOT_PRIMARY );
$state  = params_of( $url )['state'];
$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 200, [ 'access_token' => 'AT-2', 'expires_in' => 3599 ] );

$short = $one_click->handle_callback( [ 'handoff' => 'H2', 'state' => $state ] );

check( 'the missing refresh token is an error', is_wp_error( $short ) && 'mmoa_broker_no_refresh_token' === $short->get_error_code(), is_wp_error( $short ) ? $short->get_error_code() : 'accepted' );
check( 'the existing grant was left alone', 'KEEP-ME' === $plugin->secrets->get( 'google_refresh' ), (string) $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 7. Gmail sends on a brokered token, and never through the broker ===\n";
$plugin->settings->update( [
	'provider'          => 'gmail_oauth',
	'from_email'        => 'someone@gmail.com',
	'google_setup_mode' => One_Click::MODE_ONE_CLICK,
	'log_enabled'       => true,
	'queue_enabled'     => true,
	'alert_email'       => '',
] );
$plugin->secrets->set( 'google_refresh', 'RT-LIVE' );
Settings::flush_cache();
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->install_mailer();

$hits   = [];
$calls  = 0;
$script = function ( $url, $args, $n ) use ( &$hits ) {
	$hits[] = $url;

	if ( false !== strpos( $url, '/google/refresh' ) ) {
		return json_response( 200, [ 'access_token' => 'AT-LIVE', 'expires_in' => 3600 ] );
	}

	return json_response( 200, [ 'id' => 'msg-1' ] );
};

$sent = wp_mail( 'someone@example.com', 'via one-click gmail', 'body' );

check( 'the message was delivered', true === $sent, var_export( $sent, true ) );
check( 'the token came from the broker', (bool) array_filter( $hits, fn( $u ) => false !== strpos( $u, '/google/refresh' ) ) );
check( 'Google\'s own token endpoint was not used', ! array_filter( $hits, fn( $u ) => false !== strpos( $u, 'oauth2.googleapis.com' ) ), implode( ' ', $hits ) );
check( 'the message went straight to Gmail', (bool) array_filter( $hits, fn( $u ) => false !== strpos( $u, 'gmail.googleapis.com' ) ), implode( ' ', $hits ) );
check( 'no message body was ever sent to the broker', ! array_filter( $hits, fn( $u ) => false !== strpos( $u, 'broker.test' ) && false === strpos( $u, '/refresh' ) ), implode( ' ', $hits ) );

echo "\n=== 8. A rotated refresh token is stored ===\n";
// Microsoft retires the old refresh token on every use. Dropping the
// replacement means the connection dies when the current window closes, with
// nothing to explain it.
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => 'outlook' ] );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'ms_refresh', 'MS-OLD' );
Settings::flush_cache();

$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 200, [
	'access_token'  => 'MS-AT',
	'refresh_token' => 'MS-NEW',
	'expires_in'    => 3600,
] );

$token = $one_click->access_token( Broker::MICROSOFT, Settings::SLOT_BACKUP );

check( 'an access token came back', is_array( $token ) && 'MS-AT' === $token['token'], is_wp_error( $token ) ? $token->get_error_message() : '' );
check( 'the replacement refresh token was stored', 'MS-NEW' === $plugin->secrets->for_slot( Settings::SLOT_BACKUP )->get( 'ms_refresh' ), $plugin->secrets->for_slot( Settings::SLOT_BACKUP )->get( 'ms_refresh' ) );

echo "\n=== 9. Each connection keeps its own brokered grant ===\n";
$extra = $plugin->connections->add( 'Support' );
$extra = is_wp_error( $extra ) ? '' : $extra;

$plugin->secrets->set( 'google_refresh', 'PRIMARY-RT' );
Settings::flush_cache();

$url   = $one_click->authorization_url( Broker::GOOGLE, $extra );
$state = params_of( $url )['state'];

$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 200, [
	'access_token'  => 'AT',
	'refresh_token' => 'EXTRA-RT',
	'expires_in'    => 3599,
	'email'         => 'support@example.com',
] );

$landed = $one_click->handle_callback( [ 'handoff' => 'H3', 'state' => $state ] );

check( 'the callback landed on that connection', is_array( $landed ) && $extra === $landed['slot'], is_wp_error( $landed ) ? $landed->get_error_message() : var_export( $landed, true ) );
check( 'its grant was stored against itself', 'EXTRA-RT' === $plugin->secrets->for_slot( $extra )->get( 'google_refresh' ) );
check( 'the primary grant was not overwritten', 'PRIMARY-RT' === $plugin->secrets->get( 'google_refresh' ), (string) $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 10. A connection deleted mid-flow is refused ===\n";
$url   = $one_click->authorization_url( Broker::GOOGLE, $extra );
$state = params_of( $url )['state'];
$plugin->connections->delete( $extra );
Settings::flush_cache();

$gone = $one_click->handle_callback( [ 'handoff' => 'H4', 'state' => $state ] );

check( 'it failed rather than guessing a slot', is_wp_error( $gone ) && 'mmoa_one_click_gone' === $gone->get_error_code(), is_wp_error( $gone ) ? $gone->get_error_code() : 'accepted' );
check( 'the primary grant is still its own', 'PRIMARY-RT' === $plugin->secrets->get( 'google_refresh' ) );

echo "\n=== 11. Disconnecting works even when the broker does not ===\n";
// An admin who asked to disconnect must end up disconnected. Keeping the
// credential because a remote call failed is the one outcome nobody wants.
$calls  = 0;
$script = fn( $u, $a, $n ) => new WP_Error( 'http_request_failed', 'cURL error 7' );

$result = $one_click->disconnect( Broker::GOOGLE, Settings::SLOT_PRIMARY );

check( 'the failure to reach the broker is reported', is_wp_error( $result ), 'reported success' );
check( 'but the credential is gone locally', '' === $plugin->secrets->get( 'google_refresh' ), (string) $plugin->secrets->get( 'google_refresh' ) );
check( 'and it no longer reports itself connected', ! $one_click->is_connected( Broker::GOOGLE, Settings::SLOT_PRIMARY ) );

echo "\n=== 12. Errors say which of the two problems it is ===\n";
$url   = $one_click->authorization_url( Broker::GOOGLE, Settings::SLOT_PRIMARY );
$state = params_of( $url )['state'];

$calls  = 0;
$script = fn( $u, $a, $n ) => json_response( 503, [] );

$down = $one_click->handle_callback( [ 'handoff' => 'H5', 'state' => $state ] );

check( 'an outage is reported as an outage', is_wp_error( $down ), 'accepted' );
check(
	'and says existing connections keep sending',
	is_wp_error( $down ) && false !== stripos( $down->get_error_message(), 'keep sending' ),
	is_wp_error( $down ) ? $down->get_error_message() : ''
);

echo "\n=== 13. Declining at the provider is handled ===\n";
$url   = $one_click->authorization_url( Broker::GOOGLE, Settings::SLOT_PRIMARY );
$state = params_of( $url )['state'];

$denied = $one_click->handle_callback( [ 'error' => 'access_denied', 'state' => $state ] );

check( 'the refusal is reported', is_wp_error( $denied ) && 'mmoa_one_click_denied' === $denied->get_error_code(), is_wp_error( $denied ) ? $denied->get_error_code() : 'accepted' );
check( 'and repeats what the provider said', is_wp_error( $denied ) && false !== strpos( $denied->get_error_message(), 'access_denied' ) );

echo "\n=== 13b. The sign-in links survive being sent as data ===\n";
// The regression this guards, which broke every sign-in button on the site:
// wp_nonce_url() finishes with esc_html(), so it returns `&amp;` between
// parameters. Correct for a URL printed into markup, where the browser decodes
// the entities on the way out - wrong for these, which are serialised into JSON
// and set as an href by React, which decodes nothing.
//
// The browser then requested `...&amp;_wpnonce=...` verbatim, PHP parsed the
// parameter as `amp;_wpnonce`, the real nonce was never present, and
// check_admin_referer() answered "The link you followed has expired" - which
// blames a stale nonce and sends an admin looking in entirely the wrong place.
$links = [
	'one-click, google'  => [ ModernMailer\Admin\Admin_Page::one_click_urls( Broker::GOOGLE, Settings::SLOT_PRIMARY )['connect'], 'mmoa_one_click_connect' ],
	'one-click, ms'      => [ ModernMailer\Admin\Admin_Page::one_click_urls( Broker::MICROSOFT, Settings::SLOT_BACKUP )['connect'], 'mmoa_one_click_connect' ],
	'one-click, discon.' => [ ModernMailer\Admin\Admin_Page::one_click_urls( Broker::GOOGLE, Settings::SLOT_PRIMARY )['disconnect'], 'mmoa_one_click_disconnect' ],
	'own-client, google' => [ ModernMailer\Admin\Admin_Page::google_urls( Settings::SLOT_PRIMARY )['connect'], 'mmoa_connect_google' ],
	'own-client, discon.'=> [ ModernMailer\Admin\Admin_Page::google_urls( Settings::SLOT_BACKUP )['disconnect'], 'mmoa_disconnect_google' ],
];

foreach ( $links as $label => [ $url, $action ] ) {
	$entities = false !== strpos( $url, '&amp;' ) || false !== strpos( $url, '&#038;' );

	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

	check( "{$label}: no HTML entities in the URL", ! $entities, $url );
	check( "{$label}: the nonce arrives under its own name", isset( $params['_wpnonce'] ), implode( ',', array_keys( $params ) ) );
	check( "{$label}: and it verifies", isset( $params['_wpnonce'] ) && (bool) wp_verify_nonce( $params['_wpnonce'], $action ) );
	check( "{$label}: the action arrives intact", $action === ( $params['action'] ?? '' ), $params['action'] ?? 'missing' );
}

echo "\n=== 13c. A From address that is not the connected mailbox is named ===\n";
// The regression this guards: Outlook can only send as the mailbox that signed
// in, and Microsoft refuses anything else with a 403. The generic 403 branch
// sat ABOVE the ErrorSendAsDenied branch, so the specific case was unreachable
// and the commonest mistake in this provider was reported as "an administrator
// may have restricted it" - which sends somebody to argue with their IT
// department about a setting they control themselves.
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => 'outlook' ] );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'ms_refresh', 'MS-RT' );
// Set on the connection being tested, because the From address is per
// connection now - a value on the primary says nothing about what the backup
// will send as.
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [
	'from_email' => 'hello@example.com',
	'ms_account' => 'someone@outlook.com',
] );
Settings::flush_cache();
$plugin->tokens->flush();

$class   = ModernMailer\Provider_Registry::class_for( 'outlook' );
$outlook = new $class( $plugin->settings->for_slot( Settings::SLOT_BACKUP ), $plugin->tokens, $plugin->http );

$calls  = 0;
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, '/microsoft/refresh' ) ) {
		return json_response( 200, [ 'access_token' => 'MS-AT', 'expires_in' => 3600 ] );
	}

	// What Graph actually answers when the From address is not the mailbox.
	return json_response( 403, [ 'error' => [ 'code' => 'ErrorSendAsDenied', 'message' => 'Access is denied.' ] ] );
};

$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
$mailer->setFrom( 'hello@example.com' );
$mailer->addAddress( 'someone@example.com' );
$mailer->Subject = 'x';
$mailer->Body    = 'y';
$mailer->preSend();

$sent = $outlook->send( $mailer->getSentMIMEMessage(), $mailer );

check( 'sending is refused', is_wp_error( $sent ), 'accepted' );
check( 'and it is the send-as case, not a vague permissions one', is_wp_error( $sent ) && 'mmoa_outlook_send_as' === $sent->get_error_code(), is_wp_error( $sent ) ? $sent->get_error_code() : '' );
check( 'the message names the From address', is_wp_error( $sent ) && false !== strpos( $sent->get_error_message(), 'hello@example.com' ) );
check( 'and the connected mailbox', is_wp_error( $sent ) && false !== strpos( $sent->get_error_message(), 'someone@outlook.com' ) );
check( 'and does not blame an administrator', is_wp_error( $sent ) && false === stripos( $sent->get_error_message(), 'administrator may have restricted' ), is_wp_error( $sent ) ? $sent->get_error_message() : '' );

echo "\n=== 13d. Verify catches it before anyone sends ===\n";
$calls  = 0;
$script = function ( $url, $args, $n ) {
	if ( false !== strpos( $url, '/microsoft/refresh' ) ) {
		return json_response( 200, [ 'access_token' => 'MS-AT', 'expires_in' => 3600 ] );
	}

	return json_response( 200, [ 'mail' => 'someone@outlook.com' ] );
};
$plugin->tokens->flush();

$verified = $outlook->verify_connection();

check( 'verification fails rather than reporting success', is_wp_error( $verified ), is_string( $verified ) ? $verified : 'true' );
check( 'and names both addresses', is_wp_error( $verified ) && false !== strpos( $verified->get_error_message(), 'hello@example.com' ) && false !== strpos( $verified->get_error_message(), 'someone@outlook.com' ) );

// Matching addresses verify cleanly.
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'from_email' => 'someone@outlook.com' ] );
Settings::flush_cache();
$plugin->tokens->flush();

$ok = $outlook->verify_connection();
check( 'a matching From address verifies', ! is_wp_error( $ok ), is_wp_error( $ok ) ? $ok->get_error_message() : '' );

$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => '', 'ms_account' => '' ] );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'ms_refresh', '' );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'from_email' => '' ] );
Settings::flush_cache();

echo "\n=== 14. Outlook is a delegated provider, distinct from Microsoft 365 ===\n";
check( 'Outlook is registered', ModernMailer\Provider_Registry::exists( 'outlook' ) );
check( 'Microsoft 365 is still registered separately', ModernMailer\Provider_Registry::exists( 'graph' ) );
check( 'Outlook asks for nothing to be filled in', [] === ModernMailer\Providers\Outlook::fields() );
check( 'Microsoft 365 still asks for its own credentials', count( ModernMailer\Providers\Graph::fields() ) > 0 );

echo "\n=== 15. Gmail's own-client fields stand down in one-click mode ===\n";
// `depends` has to be right in both directions: the form greys them out, and
// the required check stops demanding them. A field that cannot apply must not
// be able to block a connection.
$gmail_fields = [];
foreach ( ModernMailer\Providers\Gmail_OAuth::fields() as $field ) {
	$gmail_fields[ $field->key ] = $field;
}

check( 'the setup mode is offered', isset( $gmail_fields['google_setup_mode'] ) );
check( 'it defaults to the site\'s own client', One_Click::MODE_OWN_CLIENT === ( $gmail_fields['google_setup_mode']->default ?? '' ) );
check(
	'the client ID depends on own-client mode',
	One_Click::MODE_OWN_CLIENT === ( $gmail_fields['google_client_id']->depends['value'] ?? '' ),
	var_export( $gmail_fields['google_client_id']->depends ?? [], true )
);
check(
	'and so does the client secret',
	One_Click::MODE_OWN_CLIENT === ( $gmail_fields['google_client_sec']->depends['value'] ?? '' )
);

// Leave the site unconfigured, as the other suites do.
$plugin->secrets->set( 'google_refresh', '' );
$plugin->secrets->set( 'google_client_sec', '' );
$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set( 'ms_refresh', '' );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => '' ] );
$plugin->settings->update( [ 'provider' => '', 'google_setup_mode' => One_Click::MODE_OWN_CLIENT, 'google_account' => '' ] );
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->queue->purge();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
