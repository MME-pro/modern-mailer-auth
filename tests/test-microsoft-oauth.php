<?php
/**
 * Microsoft delegated OAuth.
 *
 * The handshake itself is not exercised - it needs a browser and a person at
 * Microsoft's prompt, and a stubbed version of that would prove nothing about
 * the real one. What is checked is everything around it: registration, the
 * fields the form asks for, that the authorization URL is built correctly and
 * refuses to be built without credentials, that state is one-shot, and that
 * the credential lands in Secrets rather than Settings.
 *
 * The state check is the one that matters most. It is the entire CSRF defence
 * for the callback, because a redirect arriving from Microsoft carries no nonce
 * of ours and cannot be made to.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Auth\Microsoft_Consent;
use ModernMailer\Provider_Registry;
use ModernMailer\Providers\Graph;
use ModernMailer\Providers\Microsoft;
use ModernMailer\Providers\Microsoft_OAuth;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

echo "\n=== 1. Registered, and behind the Microsoft tile ===\n";
check( 'the registry knows the slug', Provider_Registry::exists( 'ms_oauth' ) );
check( 'it maps to the right class', Microsoft_OAuth::class === Provider_Registry::class_for( 'ms_oauth' ) );
check( 'it is not a tile of its own', ! Microsoft_OAuth::is_listed() );
check(
	'it does not appear in the chooser',
	! in_array( 'ms_oauth', array_column( Provider_Registry::to_array( $plugin->settings ), 'slug' ), true )
);
check( 'it is grouped as OAuth', 'oauth' === Microsoft_OAuth::describe()['category'] );
check( 'it sends the MIME this plugin built', true === Microsoft_OAuth::describe()['raw_mime'] );

echo "\n=== 2. Graph is untouched and still the default ===\n";
// The whole point of adding this was that it must not disturb the app-only
// path, which is the better one wherever it can be used.
check( 'Graph is still registered', Provider_Registry::exists( 'graph' ) );
check( 'and still asks for a tenant ID', in_array( 'ms_tenant_id', array_column( Graph::fields(), 'key' ), true ) );

$modes = [];
foreach ( Microsoft::fields() as $field ) {
	if ( 'ms_setup_mode' === $field->key ) {
		$modes = $field->options;
		check( 'the mode selector still defaults to the Azure app', 'own_client' === $field->default, (string) $field->default );
	}
}

check( 'the delegated mode is offered', isset( $modes['own_signin'] ), implode( ',', array_keys( $modes ) ) );
check( 'and the app-only mode is still offered', isset( $modes['own_client'] ) );

echo "\n=== 3. The form asks for two values, not three ===\n";
$fields = [];
foreach ( Microsoft_OAuth::fields() as $field ) {
	$fields[ $field->key ] = $field;
}

check( 'declares the application ID', isset( $fields['msoauth_client_id'] ) );
check( 'declares the client secret', isset( $fields['msoauth_client_sec'] ) );
check( 'the secret is marked secret, so it is encrypted and never stored in the clear', $fields['msoauth_client_sec']->secret );

// The reason this mode exists at all: /common resolves the tenant from whoever
// signs in, so asking for one would be asking for something unusable.
check( 'it does not ask for a tenant ID', ! isset( $fields['ms_tenant_id'] ) );
check( 'nor for a sending mailbox - the token names it', ! isset( $fields['ms_sender'] ) );

check(
	'it does not reuse the app-only credential keys',
	! isset( $fields['ms_client_id'], $fields['ms_client_secret'] )
);

// Not a field, deliberately. The connection screen already names the mailbox
// once the sign-in has happened, and publishing a read-only second copy on the
// form put the same address on screen twice - inviting the question of what it
// would mean for them to differ, which is nothing. Stored like google_account
// is: a base schema key the sign-in writes, not something anybody fills in.
check( 'the signed-in address is not published as a field', ! isset( $fields['msoauth_account'] ), implode( ',', array_keys( $fields ) ) );
check( 'the form asks for exactly two values', 2 === count( $fields ), implode( ',', array_keys( $fields ) ) );

// It still has to persist, which it only does while Settings knows the key -
// a provider field that Settings has never heard of is dropped on save without
// a word, and this one is written by the callback rather than by a form.
$plugin->settings->update( [ 'msoauth_account' => 'someone@example.com' ] );
Settings::flush_cache();
check( 'but it still round-trips through Settings', 'someone@example.com' === (string) $plugin->settings->get( 'msoauth_account' ) );
check( 'and the consent flow reads it back', 'someone@example.com' === $plugin->ms_consent->account( Settings::SLOT_PRIMARY ) );

echo "\n=== 4. The merged form gates each mode's fields ===\n";
$gated = [];
foreach ( Microsoft::fields() as $field ) {
	$gated[ $field->key ] = $field->depends;
}

check(
	'the delegated client ID belongs to the delegated mode',
	'own_signin' === ( $gated['msoauth_client_id']['value'] ?? '' ),
	wp_json_encode( $gated['msoauth_client_id'] ?? null )
);
check(
	'and the tenant ID still belongs to the app-only mode',
	'own_client' === ( $gated['ms_tenant_id']['value'] ?? '' ),
	wp_json_encode( $gated['ms_tenant_id'] ?? null )
);

echo "\n=== 5. The authorization URL ===\n";
$plugin->settings->update( [ 'provider' => 'microsoft', 'ms_setup_mode' => 'own_signin', 'msoauth_client_id' => '' ] );
$plugin->secrets->set( 'msoauth_client_sec', '' );
Settings::flush_cache();

$url = $plugin->ms_consent->authorization_url( Settings::SLOT_PRIMARY );
check( 'it refuses to be built with no credentials', is_wp_error( $url ) );

$plugin->settings->update( [ 'msoauth_client_id' => '11111111-2222-3333-4444-555555555555' ] );
$plugin->secrets->set( 'msoauth_client_sec', 'entra-secret-value' );
Settings::flush_cache();

$url = $plugin->ms_consent->authorization_url( Settings::SLOT_PRIMARY );
check( 'it is built once both are set', ! is_wp_error( $url ), is_wp_error( $url ) ? $url->get_error_message() : '' );

$url   = (string) $url;
$query = [];
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

check( 'it goes to the common authority, so no tenant is needed', false !== strpos( $url, 'login.microsoftonline.com/common' ), $url );
check( 'it asks for a code', 'code' === ( $query['response_type'] ?? '' ) );
check( 'it carries the client ID', '11111111-2222-3333-4444-555555555555' === ( $query['client_id'] ?? '' ) );

// Without offline_access Microsoft returns only an access token and sending
// dies within the hour. This is the single most consequential parameter here.
check( 'it requests offline_access, or the connection would last an hour', false !== strpos( (string) ( $query['scope'] ?? '' ), 'offline_access' ), (string) ( $query['scope'] ?? '' ) );
check( 'it requests Mail.Send', false !== strpos( (string) ( $query['scope'] ?? '' ), 'Mail.Send' ) );
check( 'it never asks to read the mailbox', false === strpos( (string) ( $query['scope'] ?? '' ), 'Mail.Read' ), (string) ( $query['scope'] ?? '' ) );

check(
	'the redirect URI survives encoding intact',
	Microsoft_Consent::redirect_uri() === ( $query['redirect_uri'] ?? '' ),
	(string) ( $query['redirect_uri'] ?? '' )
);
check( 'the redirect URI points at admin-post, not at a menu page', false !== strpos( Microsoft_Consent::redirect_uri(), 'admin-post.php' ) );
check( 'it forces the account chooser', 'select_account' === ( $query['prompt'] ?? '' ) );
check( 'it carries a state parameter', '' !== (string) ( $query['state'] ?? '' ) );

echo "\n=== 6. State is one-shot, which is the whole CSRF defence ===\n";
$state = (string) $query['state'];

$result = $plugin->ms_consent->handle_callback( [ 'state' => $state, 'code' => 'fake-code' ] );
// The exchange will fail - there is no real code and no network stub here -
// but it must fail *past* the state check rather than at it.
check(
	'a valid state gets past the state check',
	is_wp_error( $result ) && 'mmoa_oauth_bad_state' !== $result->get_error_code(),
	is_wp_error( $result ) ? $result->get_error_code() : 'not an error'
);

$replay = $plugin->ms_consent->handle_callback( [ 'state' => $state, 'code' => 'fake-code' ] );
check(
	'and the same state cannot be replayed',
	is_wp_error( $replay ) && 'mmoa_oauth_bad_state' === $replay->get_error_code(),
	is_wp_error( $replay ) ? $replay->get_error_code() : 'not an error'
);

$forged = $plugin->ms_consent->handle_callback( [ 'state' => 'never-issued', 'code' => 'fake-code' ] );
check(
	'a state we never issued is refused',
	is_wp_error( $forged ) && 'mmoa_oauth_bad_state' === $forged->get_error_code()
);

$none = $plugin->ms_consent->handle_callback( [ 'code' => 'fake-code' ] );
check( 'and so is a callback with no state at all', is_wp_error( $none ) );

echo "\n=== 7. Connection state and the credential split ===\n";
check( 'it reports itself disconnected with no refresh token', ! $plugin->ms_consent->is_connected( Settings::SLOT_PRIMARY ) );

$plugin->secrets->set( 'msoauth_refresh', 'refresh-token-value' );
Settings::flush_cache();

check( 'and connected once one is stored', $plugin->ms_consent->is_connected( Settings::SLOT_PRIMARY ) );
check( 'the refresh token reads back', 'refresh-token-value' === $plugin->secrets->get( 'msoauth_refresh' ) );
check(
	'and is not sitting in the settings option',
	'refresh-token-value' !== (string) $plugin->settings->get( 'msoauth_refresh' )
);

// Each connection holds its own. If the slots ever aliased, signing in on the
// backup would silently take over the primary's mailbox.
$plugin->secrets->for_slot( 'backup' )->set( 'msoauth_refresh', 'backup-refresh' );
Settings::flush_cache();

check( 'the backup keeps its own grant', 'backup-refresh' === $plugin->secrets->for_slot( 'backup' )->get( 'msoauth_refresh' ) );
check( 'and the primary keeps its own', 'refresh-token-value' === $plugin->secrets->get( 'msoauth_refresh' ) );
check( 'they report connected independently', $plugin->ms_consent->is_connected( 'backup' ) );

$plugin->ms_consent->disconnect( Settings::SLOT_PRIMARY );
Settings::flush_cache();

check( 'disconnecting forgets the primary grant', ! $plugin->ms_consent->is_connected( Settings::SLOT_PRIMARY ) );
check( 'and leaves the backup alone', $plugin->ms_consent->is_connected( 'backup' ) );

echo "\n=== 8. Sending without a grant fails in a way that says what to do ===\n";
$plugin->settings->update( [ 'provider' => 'ms_oauth' ] );
Settings::flush_cache();

$provider = new Microsoft_OAuth( $plugin->settings, $plugin->tokens, $plugin->http );
$verified = $provider->verify_connection();

check( 'verification fails with no account connected', is_wp_error( $verified ) );
check(
	'and names the sign-in as the remedy',
	is_wp_error( $verified ) && 'mmoa_ms_not_connected' === $verified->get_error_code(),
	is_wp_error( $verified ) ? $verified->get_error_code() : ''
);

echo "\n=== 9. Restoring a clean state ===\n";
$plugin->secrets->for_slot( 'backup' )->set( 'msoauth_refresh', '' );
$plugin->secrets->set( 'msoauth_client_sec', '' );
$plugin->secrets->set( 'msoauth_refresh', '' );
$plugin->settings->update(
	[
		'provider'          => '',
		'ms_setup_mode'     => '',
		'msoauth_client_id' => '',
		'msoauth_account'   => '',
		'from_email'        => '',
	]
);
$plugin->tokens->flush();
$plugin->health->reset();
Settings::flush_cache();

check( 'left inactive', ! $plugin->settings->is_active() );
check( 'no client secret remains', '' === $plugin->secrets->get( 'msoauth_client_sec' ) );
check( 'no refresh token remains', '' === $plugin->secrets->get( 'msoauth_refresh' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
