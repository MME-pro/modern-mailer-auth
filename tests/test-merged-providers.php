<?php
/**
 * Merged providers: one tile per mail service, the method chosen inside it.
 *
 * The chooser used to list authentication methods - Microsoft 365 next to
 * Outlook, Google Workspace next to Gmail - which asked an admin to decide how
 * to authenticate before deciding where to send. These two tiles ask the second
 * question first and the first question afterwards.
 *
 * What matters most here is that nothing under them changed: each mode still
 * resolves to the transport that always implemented it, so the existing suites
 * keep covering the sending paths and this one covers the front door.
 */

define( 'MMOA_BROKER_URL', 'https://broker.test/oauth/v1/' );

require __DIR__ . '/bootstrap.php';

use ModernMailer\Auth\One_Click;
use ModernMailer\Provider_Registry;
use ModernMailer\Providers\Google;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

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

/** The provider slugs the chooser actually offers. */
function offered( ModernMailer\Settings $settings ): array {
	return array_column( Provider_Registry::to_array( $settings ), 'slug' );
}

echo "\n=== 1. The chooser offers services, not authentication methods ===\n";
$plugin->settings->update( [ 'provider' => '' ] );
Settings::flush_cache();

$slugs = offered( $plugin->settings );

check( 'Microsoft is one tile', in_array( 'microsoft', $slugs, true ), implode( ' ', $slugs ) );
check( 'Google is one tile', in_array( 'google', $slugs, true ) );

foreach ( [ 'graph', 'outlook', 'gmail_sa', 'gmail_oauth' ] as $hidden ) {
	check( "{$hidden} is no longer a tile of its own", ! in_array( $hidden, $slugs, true ), implode( ' ', $slugs ) );
}

check( 'the unmerged providers are untouched', in_array( 'sendgrid', $slugs, true ) && in_array( 'smtp', $slugs, true ) );

echo "\n=== 1b. The chooser is in the order it was asked to be in ===\n";
// Asserted rather than left to the registry's declaration order, because that
// list is edited whenever a provider is added and nothing else would notice it
// had moved. The order is a display decision someone made deliberately.
$order = array_column( Provider_Registry::to_array( $plugin->settings ), 'label' );

check(
	'the tiles are in the intended order',
	[ 'Microsoft', 'Google', 'SendGrid', 'Resend', 'Brevo', 'Postmark', 'Mailgun', 'SMTP2GO', 'Other SMTP' ] === $order,
	implode( ' - ', $order )
);

check( 'the two finished providers come first', [ 'Microsoft', 'Google' ] === array_slice( $order, 0, 2 ) );
check( 'and the generic SMTP fallback comes last', 'Other SMTP' === end( $order ) );

echo "\n=== 1c. Only the finished providers are selectable ===\n";
$catalogue = Provider_Registry::to_array( $plugin->settings );
$soon      = [];
$ready     = [];

foreach ( $catalogue as $entry ) {
	if ( ! empty( $entry['coming_soon'] ) ) {
		$soon[] = $entry['label'];
	} else {
		$ready[] = $entry['label'];
	}
}

check( 'Microsoft and Google are offered', [ 'Microsoft', 'Google' ] === $ready, implode( ',', $ready ) );
check( 'the other seven are marked coming soon', 7 === count( $soon ), implode( ',', $soon ) );

echo "\n=== 2. The transports stay constructible ===\n";
// An upgrade must not be able to stop mail. A site storing a legacy slug keeps
// sending whether or not the migration has run.
foreach ( [ 'graph', 'outlook', 'gmail_sa', 'gmail_oauth' ] as $legacy ) {
	check( "{$legacy} still resolves to a class", null !== Provider_Registry::class_for( $legacy ) );
}

echo "\n=== 3. A connection still on a legacy slug stays editable ===\n";
// Hiding it from the catalogue would leave the chooser with nothing selected
// and the form empty, which reads as a connection that lost its settings.
$plugin->settings->update( [ 'provider' => 'graph' ] );
Settings::flush_cache();

check( 'its own tile reappears while it is selected', in_array( 'graph', offered( $plugin->settings ), true ) );
check( 'and the other legacy tiles stay hidden', ! in_array( 'gmail_sa', offered( $plugin->settings ), true ) );

echo "\n=== 4. Microsoft forwards to the transport its mode names ===\n";
$plugin->settings->update( [
	'provider'      => 'microsoft',
	'ms_setup_mode' => One_Click::MODE_OWN_CLIENT,
] );
Settings::flush_cache();

$class = Provider_Registry::class_for( 'microsoft' );
$ms    = new $class( $plugin->settings, $plugin->tokens, $plugin->http );

check( 'own-app mode reports the Graph transport', 'Microsoft 365 (Graph)' === $ms->get_label(), $ms->get_label() );

$plugin->settings->update( [ 'ms_setup_mode' => One_Click::MODE_ONE_CLICK ] );
Settings::flush_cache();
$ms = new $class( $plugin->settings, $plugin->tokens, $plugin->http );

check( 'one-click mode reports the Outlook transport', 'Outlook' === $ms->get_label(), $ms->get_label() );

echo "\n=== 5. Google forwards to the transport its mode names ===\n";
$class = Provider_Registry::class_for( 'google' );

foreach ( [
	One_Click::MODE_OWN_CLIENT => 'Gmail (OAuth)',
	One_Click::MODE_ONE_CLICK  => 'Gmail (OAuth)',
	Google::MODE_SERVICE_ACCOUNT => 'Google Workspace (service account)',
] as $mode => $label ) {
	$plugin->settings->update( [ 'provider' => 'google', 'google_setup_mode' => $mode ] );
	Settings::flush_cache();

	$google = new $class( $plugin->settings, $plugin->tokens, $plugin->http );

	check( "{$mode} reports {$label}", $label === $google->get_label(), $google->get_label() );
}

echo "\n=== 6. An unknown mode falls back rather than fatalling ===\n";
$plugin->settings->update( [ 'provider' => 'google', 'google_setup_mode' => 'nonsense' ] );
Settings::flush_cache();

$google = new $class( $plugin->settings, $plugin->tokens, $plugin->http );

check( 'it uses the default mode', 'Gmail (OAuth)' === $google->get_label(), $google->get_label() );

echo "\n=== 7. Each mode's credentials are gated to that mode ===\n";
// `depends` has to be right in both directions: the form greys them out, and
// the required check stops demanding them, so a credential belonging to a mode
// you are not using cannot block a connection.
$fields = [];
foreach ( Provider_Registry::class_for( 'microsoft' )::fields() as $field ) {
	$fields[ $field->key ] = $field;
}

check( 'Microsoft asks how to connect first', isset( $fields['ms_setup_mode'] ) );
check( 'the tenant ID belongs to the own-app mode', One_Click::MODE_OWN_CLIENT === ( $fields['ms_tenant_id']->depends['value'] ?? '' ), var_export( $fields['ms_tenant_id']->depends ?? [], true ) );
check( 'so does the client secret', One_Click::MODE_OWN_CLIENT === ( $fields['ms_client_secret']->depends['value'] ?? '' ) );
check( 'only one mode selector is published', 1 === count( array_filter( array_keys( $fields ), fn( $k ) => str_ends_with( $k, '_setup_mode' ) ) ) );

$fields = [];
foreach ( Provider_Registry::class_for( 'google' )::fields() as $field ) {
	$fields[ $field->key ] = $field;
}

check( 'Google asks how to connect first', isset( $fields['google_setup_mode'] ) );
check( 'the OAuth client ID belongs to own-client mode', One_Click::MODE_OWN_CLIENT === ( $fields['google_client_id']->depends['value'] ?? '' ), var_export( $fields['google_client_id']->depends ?? [], true ) );
check( 'the service account key belongs to service-account mode', Google::MODE_SERVICE_ACCOUNT === ( $fields['google_sa_key']->depends['value'] ?? '' ) );
check( 'all three modes are offered', 3 === count( $fields['google_setup_mode']->options ) , implode( ',', array_keys( $fields['google_setup_mode']->options ) ) );
check( 'and only one mode selector is published', 1 === count( array_filter( array_keys( $fields ), fn( $k ) => str_ends_with( $k, '_setup_mode' ) ) ) );

echo "\n=== 7b. With one way in, there is no selector - and no gates either ===\n";
// The regression this guards: dropping a single-option radio left the Azure
// credentials depending on a field the form was no longer rendering. Each one
// resolved against nothing, decided it did not apply, and hid itself - so
// removing one radio button emptied the entire Microsoft form.
$off = fn() => '';
add_filter( 'mmoa_broker_url', $off );

$solo = [];
foreach ( Provider_Registry::class_for( 'microsoft' )::fields() as $field ) {
	$solo[ $field->key ] = $field;
}

check( 'the selector is gone', ! isset( $solo['ms_setup_mode'] ) );
check( 'but the credentials are still published', isset( $solo['ms_tenant_id'], $solo['ms_client_id'], $solo['ms_client_secret'] ), implode( ',', array_keys( $solo ) ) );
check( 'and none of them depends on the vanished field', [] === ( $solo['ms_tenant_id']->depends ?? null ), var_export( $solo['ms_tenant_id']->depends ?? null, true ) );
check( 'the one-click transport is not offered', ! in_array( 'outlook', array_column( Provider_Registry::to_array( $plugin->settings ), 'slug' ), true ) );

// Google keeps a real choice even without the broker, so it keeps its gates.
$duo = [];
foreach ( Provider_Registry::class_for( 'google' )::fields() as $field ) {
	$duo[ $field->key ] = $field;
}

check( 'Google still has two ways in, so it keeps its selector', isset( $duo['google_setup_mode'] ) && 2 === count( $duo['google_setup_mode']->options ) );
check( 'and its fields stay gated', One_Click::MODE_OWN_CLIENT === ( $duo['google_client_id']->depends['value'] ?? '' ) );

remove_filter( 'mmoa_broker_url', $off );
Provider_Registry::flush();

echo "\n=== 8. Sending through the merged tile reaches the right endpoints ===\n";
$plugin->settings->update( [
	'provider'      => 'microsoft',
	'from_email'    => 'sender@example.com',
	'ms_setup_mode' => One_Click::MODE_ONE_CLICK,
	'log_enabled'   => true,
	'queue_enabled' => true,
	'alert_email'   => '',
] );
$plugin->secrets->set( 'ms_refresh', 'MS-RT' );
Settings::flush_cache();
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->install_mailer();

$hits   = [];
$calls  = 0;
$script = function ( $url, $args, $n ) use ( &$hits ) {
	$hits[] = $url;

	if ( false !== strpos( $url, '/microsoft/refresh' ) ) {
		return json_response( 200, [ 'access_token' => 'MS-AT', 'expires_in' => 3600 ] );
	}

	return json_response( 202, [] );
};

$sent = wp_mail( 'someone@example.com', 'via merged microsoft', 'body' );

check( 'the message was delivered', true === $sent, var_export( $sent, true ) );
check( 'the token came from the broker', (bool) array_filter( $hits, fn( $u ) => false !== strpos( $u, '/microsoft/refresh' ) ), implode( ' ', $hits ) );
check( 'the message went straight to Graph', (bool) array_filter( $hits, fn( $u ) => false !== strpos( $u, 'graph.microsoft.com/v1.0/me/sendMail' ) ), implode( ' ', $hits ) );
check( 'no message body reached the broker', ! array_filter( $hits, fn( $u ) => false !== strpos( $u, 'broker.test' ) && false === strpos( $u, '/refresh' ) ), implode( ' ', $hits ) );

echo "\n=== 9. Existing connections are migrated, not stranded ===\n";
// Restating an existing choice in the new vocabulary. No credential is touched
// and no connection changes how it sends.
$extra = $plugin->connections->add( 'Newsletter' );
$extra = is_wp_error( $extra ) ? '' : $extra;

$plugin->settings->update( [ 'provider' => 'graph' ] );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => 'gmail_sa' ] );
$plugin->settings->for_slot( $extra )->update( [
	'provider'          => 'gmail_oauth',
	'google_setup_mode' => One_Click::MODE_ONE_CLICK,
] );
delete_option( 'mmoa_merged_providers' );
Settings::flush_cache();

$plugin->maybe_upgrade();
Settings::flush_cache();

check( 'Microsoft 365 became Microsoft', 'microsoft' === $plugin->settings->get( 'provider' ), (string) $plugin->settings->get( 'provider' ) );
check( 'and kept app-only authentication', One_Click::MODE_OWN_CLIENT === $plugin->settings->get( 'ms_setup_mode' ), (string) $plugin->settings->get( 'ms_setup_mode' ) );

$backup = $plugin->settings->for_slot( Settings::SLOT_BACKUP );
check( 'Google Workspace became Google', 'google' === $backup->get( 'provider' ), (string) $backup->get( 'provider' ) );
check( 'and kept its service account', Google::MODE_SERVICE_ACCOUNT === $backup->get( 'google_setup_mode' ), (string) $backup->get( 'google_setup_mode' ) );

$third = $plugin->settings->for_slot( $extra );
check( 'Gmail became Google', 'google' === $third->get( 'provider' ), (string) $third->get( 'provider' ) );
check( 'and a brokered connection stayed brokered', One_Click::MODE_ONE_CLICK === $third->get( 'google_setup_mode' ), (string) $third->get( 'google_setup_mode' ) );

echo "\n=== 10. The migration runs once ===\n";
// Re-running it must not overwrite a mode someone has since changed by hand.
$plugin->settings->update( [ 'ms_setup_mode' => One_Click::MODE_ONE_CLICK ] );
Settings::flush_cache();

$plugin->maybe_upgrade();
Settings::flush_cache();

check( 'a later change is not reverted', One_Click::MODE_ONE_CLICK === $plugin->settings->get( 'ms_setup_mode' ), (string) $plugin->settings->get( 'ms_setup_mode' ) );

// Leave the site unconfigured, as the other suites do.
$plugin->connections->delete( $extra );
$plugin->secrets->set( 'ms_refresh', '' );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => '', 'google_setup_mode' => One_Click::MODE_OWN_CLIENT ] );
$plugin->settings->update( [
	'provider'          => '',
	'ms_setup_mode'     => One_Click::MODE_OWN_CLIENT,
	'google_setup_mode' => One_Click::MODE_OWN_CLIENT,
] );
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->queue->purge();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
