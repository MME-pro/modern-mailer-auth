<?php
/**
 * Generic SMTP provider.
 *
 * The socket conversation itself is not exercised here - stubbing a TCP session
 * convincingly costs more than it proves, and a wrong stub would give false
 * confidence about the one thing that has to work against a real server. What
 * is checked is everything around it: registration, the declared fields, the
 * credential split between Settings and Secrets, and the retry classification -
 * which is the part most likely to be wrong, because SMTP inverts the
 * convention every other provider here uses.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Failure;
use ModernMailer\Provider_Registry;
use ModernMailer\Providers\Smtp;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

echo "\n=== 1. Registered and selectable ===\n";
check( 'the registry knows the slug', Provider_Registry::exists( 'smtp' ) );
check( 'it maps to the right class', Smtp::class === Provider_Registry::class_for( 'smtp' ) );
check( 'it appears in the provider chooser', array_key_exists( 'smtp', Settings::provider_labels() ) );
check(
	'it is grouped under SMTP, not the API services',
	'smtp' === Smtp::describe()['category'],
	Smtp::describe()['category']
);

echo "\n=== 2. The declared fields are what an admin actually needs ===\n";
$fields = [];
foreach ( Smtp::fields() as $field ) {
	$fields[ $field->key ] = $field;
}

foreach ( [ 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password' ] as $key ) {
	check( "declares {$key}", isset( $fields[ $key ] ) );
}

check( 'the password is marked secret, so it is encrypted and never echoed back', $fields['smtp_password']->secret );
check( 'the username is NOT secret - it is not a credential on its own', ! $fields['smtp_username']->secret );
check(
	'encryption is a fixed choice rather than free text',
	in_array( $fields['smtp_encryption']->type, [ 'select', 'radio' ], true ),
	$fields['smtp_encryption']->type
);
check(
	'it offers STARTTLS, implicit TLS and none',
	[ 'tls', 'ssl', 'none' ] === array_keys( $fields['smtp_encryption']->options ),
	implode( ',', array_keys( $fields['smtp_encryption']->options ) )
);
check( 'the default is TLS', 'tls' === $fields['smtp_encryption']->default );
check( 'the default port matches that default', 587 === $fields['smtp_port']->default );

echo "\n=== 2b. Form layout and linked behaviour are declared, not hard-coded ===\n";
// The point of declaring these is that the form stays generated. If a provider
// ever needs a hand-written panel, the schema has failed at its one job.
check( 'encryption is radios, not a dropdown', 'radio' === $fields['smtp_encryption']->type );
check( 'authentication is radios too', 'radio' === $fields['smtp_auth']->type );
check( 'authentication defaults to on', 'yes' === $fields['smtp_auth']->default );
check(
	'authentication offers exactly on and off',
	[ 'yes', 'no' ] === array_keys( $fields['smtp_auth']->options ),
	implode( ',', array_keys( $fields['smtp_auth']->options ) )
);

check(
	'server, username and password share a row',
	'third' === $fields['smtp_host']->width
		&& 'third' === $fields['smtp_username']->width
		&& 'third' === $fields['smtp_password']->width,
	sprintf(
		'%s/%s/%s',
		$fields['smtp_host']->width,
		$fields['smtp_username']->width,
		$fields['smtp_password']->width
	)
);

// Picking an encryption picks the port. A mismatched pair is the commonest way
// an SMTP connection is misconfigured, so the pairing is declared rather than
// left to the admin to remember.
$sets = $fields['smtp_encryption']->sets;
check( 'choosing TLS sets port 587', 587 === ( $sets['tls']['smtp_port'] ?? null ) );
check( 'choosing SSL sets port 465', 465 === ( $sets['ssl']['smtp_port'] ?? null ) );
check( 'choosing None sets port 25', 25 === ( $sets['none']['smtp_port'] ?? null ) );

check(
	'the username is tied to the authentication toggle',
	'smtp_auth' === ( $fields['smtp_username']->depends['field'] ?? '' )
		&& 'yes' === ( $fields['smtp_username']->depends['value'] ?? '' )
);
check(
	'and so is the password',
	'smtp_auth' === ( $fields['smtp_password']->depends['field'] ?? '' )
);

// The REST payload is what the admin app actually renders from, so the metadata
// has to survive serialisation - not just exist on the object.
$published = $fields['smtp_encryption']->to_array( 'tls' );
check( 'width reaches the front end', 'half' === ( $published['width'] ?? null ) );
check( 'sets reaches the front end', 587 === ( $published['sets']['tls']['smtp_port'] ?? null ) );
check(
	'depends reaches the front end',
	'smtp_auth' === ( $fields['smtp_password']->to_array( null, true )['depends']['field'] ?? '' )
);

echo "\n=== 3. Credentials split correctly between the two stores ===\n";
// The password must never land in the options table in the clear. This is the
// assertion that would catch a field losing its `secret` flag.
$plugin->settings->update( [
	'provider'        => 'smtp',
	'from_email'      => 'noreply@example.com',
	'smtp_host'       => 'smtp.example.com',
	'smtp_port'       => 2525,
	'smtp_encryption' => 'tls',
	'smtp_username'   => 'postmaster@example.com',
] );
$plugin->secrets->set( 'smtp_password', 'hunter2-the-password' );
Settings::flush_cache();

check( 'the host is stored as a setting', 'smtp.example.com' === $plugin->settings->get( 'smtp_host' ) );
check( 'the port survives as an integer', 2525 === $plugin->settings->get( 'smtp_port' ) );
check( 'the password round-trips through Secrets', 'hunter2-the-password' === $plugin->secrets->get( 'smtp_password' ) );

global $wpdb;
$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_settings' ) );
check( 'the password is NOT in the settings option', false === strpos( $raw, 'hunter2-the-password' ), substr( $raw, 0, 80 ) );

$rawSecrets = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
check( 'and it is ciphertext where it is stored', false === strpos( $rawSecrets, 'hunter2-the-password' ) );

echo "\n=== 4. Primary and backup keep separate SMTP credentials ===\n";
$backup = $plugin->settings->for_slot( Settings::SLOT_BACKUP );
$backup->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.backup.example.com' ] );
$backup->secrets()->set( 'smtp_password', 'different-password' );
Settings::flush_cache();

check( 'the primary host is untouched', 'smtp.example.com' === $plugin->settings->get( 'smtp_host' ) );
check( 'the backup has its own host', 'smtp.backup.example.com' === $backup->get( 'smtp_host' ) );
check( 'and its own password', 'different-password' === $backup->secrets()->get( 'smtp_password' ) );
check( 'without disturbing the primary password', 'hunter2-the-password' === $plugin->secrets->get( 'smtp_password' ) );

echo "\n=== 5. An unconfigured connection fails without opening a socket ===\n";
$plugin->settings->update( [ 'smtp_host' => '' ] );
Settings::flush_cache();
$plugin->dispatcher->reset_providers();

$provider = $plugin->dispatcher->provider();
$result   = $provider->verify_connection();

check( 'verify refuses rather than dialling nowhere', is_wp_error( $result ) );
check(
	'and says what is missing',
	is_wp_error( $result ) && 'mmoa_provider_incomplete' === $result->get_error_code(),
	is_wp_error( $result ) ? $result->get_error_code() : 'no error'
);

echo "\n=== 5b. Authentication switched on with no username fails loudly ===\n";
// Inferring "no auth wanted" from an empty username would send unauthenticated
// and get quietly rejected downstream. The choice is explicit, so a half-filled
// credential is an error rather than a silent downgrade.
$plugin->settings->update( [
	'smtp_host'     => 'smtp.example.com',
	'smtp_auth'     => 'yes',
	'smtp_username' => '',
] );
Settings::flush_cache();
$plugin->dispatcher->reset_providers();

$result = $plugin->dispatcher->provider()->verify_connection();
check( 'it refuses before dialling', is_wp_error( $result ) );
check(
	'and names the contradiction',
	is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'username' ),
	is_wp_error( $result ) ? $result->get_error_message() : 'no error'
);

echo "\n=== 6. SMTP reply codes are classified the right way round ===\n";
// This is the one that matters. SMTP inverts HTTP's convention: 4xx is the
// temporary failure and 5xx the permanent refusal. Reading these through the
// HTTP rule would retry a rejected recipient for two days and discard a
// greylisted message on the first attempt.
$temporary = new WP_Error( 'mmoa_smtp_temporary', '451 4.7.1 Greylisted, try again later', [ 'smtp_code' => 451 ] );
$permanent = new WP_Error( 'mmoa_smtp_rejected', '550 5.1.1 No such user here', [ 'smtp_code' => 550 ] );
$unreach   = new WP_Error( 'mmoa_smtp_connect_failed', 'Connection refused' );
$auth      = new WP_Error( 'mmoa_smtp_auth_failed', 'Username and password not accepted' );
$tls       = new WP_Error( 'mmoa_smtp_tls_failed', 'STARTTLS refused' );

check( 'a 4xx greylist is retried', Failure::is_retryable( $temporary ) );
check( 'a 5xx rejection is NOT retried', ! Failure::is_retryable( $permanent ) );
check( 'an unreachable server is retried', Failure::is_retryable( $unreach ) );
check( 'bad credentials are NOT retried', ! Failure::is_retryable( $auth ) );
check( 'a TLS refusal is NOT retried', ! Failure::is_retryable( $tls ) );

// A 5xx must not be mistaken for an HTTP 5xx by the generic status rule.
check(
	'the smtp_code is not read as an HTTP status',
	! Failure::is_retryable( $permanent ),
	'550 would be retryable if it were treated as HTTP'
);

check( 'a bad password is still worth trying on the backup', Failure::should_try_backup( $auth ) );

echo "\n=== 7. Restoring a clean state ===\n";
$backup->update( [ 'provider' => '', 'smtp_host' => '' ] );
$backup->secrets()->set( 'smtp_password', '' );
$plugin->settings->update( [
	'provider' => '', 'smtp_host' => '', 'smtp_username' => '', 'from_email' => '',
] );
$plugin->secrets->set( 'smtp_password', '' );
$plugin->tokens->flush();
$plugin->health->reset();
Settings::flush_cache();

check( 'left inactive', ! $plugin->settings->is_active() );
check( 'no SMTP password remains', '' === $plugin->secrets->get( 'smtp_password' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
