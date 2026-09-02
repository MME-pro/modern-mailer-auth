<?php
/**
 * Zoho Mail provider.
 *
 * Like the generic SMTP suite, the socket conversation is left alone - it is
 * inherited unchanged and is already covered there. What is worth checking is
 * the part this class actually adds: that the region maps to the right host,
 * that the port cannot be dragged off by a value left behind from a different
 * provider, and that the credential lands in Secrets rather than Settings.
 *
 * The region table is the reason this provider exists at all, so a wrong entry
 * here is the one bug that would make it worse than typing the host by hand.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Provider_Registry;
use ModernMailer\Providers\Smtp;
use ModernMailer\Providers\Zoho;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

/**
 * Reach one of the protected server accessors.
 *
 * Reflection rather than a test subclass: a subclass would be a different
 * class from the one the dispatcher builds, and the whole point of these
 * accessors is that the parent's connect() calls them.
 */
function server_value( Zoho $provider, string $method ) {
	$m = new ReflectionMethod( Zoho::class, $method );
	$m->setAccessible( true );

	return $m->invoke( $provider );
}

function zoho_with( array $settings ): Zoho {
	$plugin = ModernMailer\Plugin::instance();
	$plugin->settings->update( $settings );
	Settings::flush_cache();

	return new Zoho( $plugin->settings, $plugin->tokens, $plugin->http );
}

echo "\n=== 1. Registered and selectable ===\n";
check( 'the registry knows the slug', Provider_Registry::exists( 'zoho' ) );
check( 'it maps to the right class', Zoho::class === Provider_Registry::class_for( 'zoho' ) );
check( 'it appears in the provider chooser', array_key_exists( 'zoho', Settings::provider_labels() ) );
check(
	'it is grouped under SMTP',
	'smtp' === Zoho::describe()['category'],
	Zoho::describe()['category']
);
check( 'it is offered, not marked coming soon', empty( Zoho::describe()['coming_soon'] ) );
check( 'it sends the MIME this plugin built, rather than a rebuilt one', true === Zoho::describe()['raw_mime'] );
check( 'it is the SMTP transport underneath', is_subclass_of( Zoho::class, Smtp::class ) );

echo "\n=== 2. The form asks for the account and nothing else ===\n";
$fields = [];
foreach ( Zoho::fields() as $field ) {
	$fields[ $field->key ] = $field;
}

foreach ( [ 'zoho_region', 'smtp_username', 'smtp_password' ] as $key ) {
	check( "declares {$key}", isset( $fields[ $key ] ) );
}

check( 'it does not ask for a server', ! isset( $fields['smtp_host'] ) );
check( 'nor for a port', ! isset( $fields['smtp_port'] ) );
check( 'nor whether to authenticate', ! isset( $fields['smtp_auth'] ) );

check( 'the password is marked secret, so it is encrypted and never echoed back', $fields['smtp_password']->secret );
check( 'the address is required', $fields['smtp_username']->required );
check( 'the region defaults to the American data centre', 'com' === $fields['zoho_region']->default );
check( 'encryption defaults to SSL', 'ssl' === $fields['zoho_encryption']->default );

check(
	'only the two ports Zoho actually offers are choosable',
	[ 'ssl', 'tls' ] === array_keys( $fields['zoho_encryption']->options ),
	implode( ',', array_keys( $fields['zoho_encryption']->options ) )
);

check(
	'the credential keys are the generic ones, so switching provider keeps them',
	isset( $fields['smtp_username'], $fields['smtp_password'] )
);

echo "\n=== 3. The region picks the host ===\n";
$expected = [
	'com' => 'smtp.zoho.com',
	'eu'  => 'smtp.zoho.eu',
	'in'  => 'smtp.zoho.in',
	'au'  => 'smtp.zoho.com.au',
	'jp'  => 'smtp.zoho.jp',
	'ca'  => 'smtp.zohocloud.ca',
	'sa'  => 'smtp.zoho.sa',
];

foreach ( $expected as $region => $host ) {
	$provider = zoho_with( [ 'provider' => 'zoho', 'zoho_region' => $region ] );
	$actual   = server_value( $provider, 'host' );

	check( "{$region} connects to {$host}", $host === $actual, (string) $actual );
}

// An account is in exactly one data centre, so every region must be distinct -
// a copy-paste in the table would silently send an account at the wrong host.
check( 'no two regions share a host', count( array_unique( $expected ) ) === count( $expected ) );

$provider = zoho_with( [ 'provider' => 'zoho', 'zoho_region' => 'nowhere' ] );
check(
	'an unknown region falls back rather than refusing to dial',
	'smtp.zoho.com' === server_value( $provider, 'host' ),
	(string) server_value( $provider, 'host' )
);

echo "\n=== 4. The port follows the encryption, not a leftover setting ===\n";
$provider = zoho_with(
	[
		'provider'        => 'zoho',
		'zoho_region'     => 'eu',
		'zoho_encryption' => 'ssl',
		// What a connection that used to be Other SMTP would be carrying.
		'smtp_port'       => 2525,
		'smtp_auth'       => 'no',
	]
);

check( 'SSL means 465', 465 === server_value( $provider, 'port' ), (string) server_value( $provider, 'port' ) );
check( 'a stored port from another provider is ignored', 2525 !== server_value( $provider, 'port' ) );
check(
	'and a stored "do not authenticate" is ignored too',
	true === server_value( $provider, 'authenticates' )
);

$provider = zoho_with( [ 'provider' => 'zoho', 'zoho_encryption' => 'tls' ] );
check( 'TLS means 587', 587 === server_value( $provider, 'port' ), (string) server_value( $provider, 'port' ) );

$provider = zoho_with( [ 'provider' => 'zoho', 'zoho_encryption' => 'none' ] );
check(
	'the generic provider\'s unencrypted option cannot leak in',
	'ssl' === server_value( $provider, 'encryption' ),
	(string) server_value( $provider, 'encryption' )
);
check( 'so the port stays 465', 465 === server_value( $provider, 'port' ) );

echo "\n=== 5. The credential is stored the way a credential should be ===\n";
$plugin->settings->update( [ 'provider' => 'zoho', 'zoho_region' => 'eu', 'smtp_username' => 'billing@example.com' ] );
$plugin->secrets->set( 'smtp_password', 'zoho-app-password-1234' );
Settings::flush_cache();

check( 'the password reads back', 'zoho-app-password-1234' === $plugin->secrets->get( 'smtp_password' ) );
check( 'and is not sitting in the settings option', 'zoho-app-password-1234' !== (string) $plugin->settings->get( 'smtp_password' ) );

$catalogue = Provider_Registry::to_array( $plugin->settings );
$entry     = null;

foreach ( $catalogue as $candidate ) {
	if ( 'zoho' === $candidate['slug'] ) {
		$entry = $candidate;
	}
}

check( 'the catalogue publishes the tile', null !== $entry );

$published = [];
foreach ( $entry['fields'] ?? [] as $f ) {
	$published[ $f['key'] ] = $f;
}

check( 'the tile carries the common From address field', isset( $published['from_email'] ) );
check( 'the region is published as a dropdown', 'select' === ( $published['zoho_region']['type'] ?? '' ) );
check( 'and it reports the stored credential', true === ( $published['smtp_password']['is_set'] ?? false ) );

echo "\n=== 6. Restoring a clean state ===\n";
$plugin->settings->update(
	[
		'provider'        => '',
		'zoho_region'     => '',
		'smtp_username'   => '',
		'smtp_port'       => '',
		'zoho_encryption' => '',
		'smtp_auth'       => '',
		'from_email'      => '',
	]
);
$plugin->secrets->set( 'smtp_password', '' );
$plugin->tokens->flush();
$plugin->health->reset();
Settings::flush_cache();

check( 'left inactive', ! $plugin->settings->is_active() );
check( 'no app password remains', '' === $plugin->secrets->get( 'smtp_password' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
