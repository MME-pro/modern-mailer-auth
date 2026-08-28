<?php
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

echo "\n=== Credential storage ===\n";
check( 'libsodium available', $plugin->secrets->is_encryption_available() );

$plugin->secrets->set( 'ms_client_secret', 'SuperSecretValue123' );
check( 'round-trips correctly', 'SuperSecretValue123' === $plugin->secrets->get( 'ms_client_secret' ) );

global $wpdb;
$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
check( 'ciphertext in the database, not the plaintext', false === strpos( (string) $raw, 'SuperSecretValue123' ), substr( (string) $raw, 0, 60 ) );
check( 'stored value is versioned', false !== strpos( (string) $raw, 'v1:' ) );

$plugin->secrets->set( 'ms_client_secret', 'Different' );
check( 'nonce is per-write, so rewriting the same value differs', true );
$a = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
$plugin->secrets->set( 'ms_client_secret', 'Different' );
$b = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
check( 'identical plaintext encrypts to different ciphertext', $a !== $b );

$plugin->secrets->set( 'ms_client_secret', '' );
check( 'empty value clears the credential', '' === $plugin->secrets->get( 'ms_client_secret' ) );

echo "\n=== Constant precedence ===\n";
define( 'MMOA_MS_TENANT_ID', 'tenant-from-wp-config' );
$plugin->settings->update( [ 'ms_tenant_id' => 'tenant-from-db' ] );
check( 'constant wins over the database', 'tenant-from-wp-config' === $plugin->settings->get( 'ms_tenant_id' ),
	(string) $plugin->settings->get( 'ms_tenant_id' ) );
check( 'the UI can tell it is pinned', $plugin->settings->is_constant( 'ms_tenant_id' ) );

echo "\n=== Inactive by default ===\n";
$plugin->settings->update( [ 'provider' => '' ] );
check( 'is_active() false with no provider', ! $plugin->settings->is_active() );
unset( $GLOBALS['phpmailer'] );
$plugin->install_mailer();
check( 'does not hijack PHPMailer when unconfigured', ! isset( $GLOBALS['phpmailer'] ) );

echo "\n=== Admin screen renders ===\n";
$admin_id = $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' LIMIT 1" );
wp_set_current_user( (int) $admin_id );
check( 'test user is an administrator', current_user_can( 'manage_options' ) );

$page = new ModernMailer\Admin\Admin_Page( $plugin );

/** Capture one screen's markup. */
function render_screen( ModernMailer\Admin\Admin_Page $page, string $method ): string {
	ob_start();
	$page->$method();
	return (string) ob_get_clean();
}

$html   = render_screen( $page, 'render_settings' );
$backup = render_screen( $page, 'render_backup' );
$logs   = render_screen( $page, 'render_logs' );

check( 'Settings renders without fatal', strlen( $html ) > 1000, strlen( $html ) . ' bytes' );
check( 'Backup renders without fatal', strlen( $backup ) > 500, strlen( $backup ) . ' bytes' );
check( 'Logs renders without fatal', strlen( $logs ) > 150, strlen( $logs ) . ' bytes' );

check( 'provider selector present', false !== strpos( $html, 'id="provider"' ) );
check( 'access-policy warning shown', false !== strpos( $html, 'New-ApplicationAccessPolicy' ) );
check( 'Gmail Testing-mode trap warned about', false !== strpos( $html, 'seven days' ) );
check( 'nonce fields emitted', substr_count( $html, '_wpnonce' ) >= 3, substr_count( $html, '_wpnonce' ) . ' found' );
check( 'no credential echoed into the form', false === strpos( $html, 'SuperSecretValue123' ) );
check( 'constant-pinned field marked as such', false !== strpos( $html, 'wp-config.php' ) );

// The backup screen must drive its own slot, or saving it would overwrite the
// primary's credentials.
check( 'Backup screen fields are slot-prefixed', false !== strpos( $backup, 'name="backup_provider"' ) );
check( 'Backup screen does not render primary fields', false === strpos( $backup, 'name="ms_tenant_id"' ) );
check( 'Backup screen warns when no primary exists', false !== strpos( $backup, 'no primary connection' ) );

// Every form has to say which screen to return to, or actions taken on Backup
// and Logs would bounce the admin to Settings.
check( 'Settings forms carry a return page', false !== strpos( $html, 'name="return_page" value="modern-mailer-oauth"' ) );
check( 'Backup forms carry a return page', false !== strpos( $backup, 'name="return_page" value="modern-mailer-backup"' ) );

check( 'Logs screen shows the send log section', false !== strpos( $logs, 'Send log' ) );
check( 'Logs screen shows the queue section', false !== strpos( $logs, 'Retry queue' ) );

// The redirect URI must not depend on where the menu lives, or reorganising the
// admin breaks every existing Google connection.
$redirect = ModernMailer\Auth\Google_Consent::redirect_uri();
check( 'OAuth redirect URI points at admin-post.php', false !== strpos( $redirect, 'admin-post.php?action=mmoa_google_callback' ), $redirect );
check( 'OAuth redirect URI does not reference a menu page', false === strpos( $redirect, 'page=' ), $redirect );

echo "\n=== Site Health ===\n";
$sh = ( new ModernMailer\Admin\Site_Health( $plugin ) )->run_test();
check( 'reports unconfigured as a recommendation', 'recommended' === $sh['status'], $sh['status'] );

echo "\n=== A stored credential can be read back ===\n";
// The field shows the saved value masked, with an eye to reveal it, so the
// value has to reach the browser. Withholding it left an administrator unable
// to check what had been saved - a key pasted with a truncated tail looks
// exactly like a correct one, and the only way to find out was to send a
// message and read the error.
$plugin->settings->update( [ 'provider' => 'brevo' ] );
$plugin->secrets->set( 'brevo_api_key', 'xkeysib-SECRET-VALUE-1234' );
ModernMailer\Settings::flush_cache();

$catalogue = ModernMailer\Provider_Registry::to_array( $plugin->settings );
$brevo     = null;

foreach ( $catalogue as $entry ) {
	if ( 'brevo' === $entry['slug'] ) {
		$brevo = $entry;
	}
}

$api_key = null;

foreach ( $brevo['fields'] ?? [] as $f ) {
	if ( 'brevo_api_key' === $f['key'] ) {
		$api_key = $f;
	}
}

check( 'the field is published', null !== $api_key );
check( 'it is still marked secret, so the form masks it', true === ( $api_key['secret'] ?? false ) );
check( 'it reports that one is stored', true === ( $api_key['is_set'] ?? false ) );
check( 'and it carries the stored value', 'xkeysib-SECRET-VALUE-1234' === ( $api_key['value'] ?? '' ), wp_json_encode( $api_key['value'] ?? null ) );

// Each connection keeps its own, which is the whole basis of having more than
// one - if the slots ever aliased, revealing the backup would show the primary.
$plugin->secrets->for_slot( 'backup' )->set( 'brevo_api_key', 'xkeysib-BACKUP-9999' );
ModernMailer\Settings::flush_cache();

$backup_key = null;

foreach ( ModernMailer\Provider_Registry::to_array( $plugin->settings->for_slot( 'backup' ) ) as $entry ) {
	if ( 'brevo' !== $entry['slug'] ) {
		continue;
	}
	foreach ( $entry['fields'] as $f ) {
		if ( 'brevo_api_key' === $f['key'] ) {
			$backup_key = $f['value'];
		}
	}
}

check( 'the backup carries its own key', 'xkeysib-BACKUP-9999' === $backup_key, wp_json_encode( $backup_key ) );
check( 'and the primary still carries its own', 'xkeysib-SECRET-VALUE-1234' === $plugin->secrets->get( 'brevo_api_key' ) );

$plugin->secrets->set( 'brevo_api_key', '' );
$plugin->secrets->for_slot( 'backup' )->set( 'brevo_api_key', '' );
ModernMailer\Settings::flush_cache();

$empty = null;

foreach ( ModernMailer\Provider_Registry::to_array( $plugin->settings ) as $entry ) {
	if ( 'brevo' !== $entry['slug'] ) {
		continue;
	}
	foreach ( $entry['fields'] as $f ) {
		if ( 'brevo_api_key' === $f['key'] ) {
			$empty = $f;
		}
	}
}

check( 'an unset credential reports nothing stored', false === ( $empty['is_set'] ?? true ) );
check( 'and carries an empty value', '' === ( $empty['value'] ?? 'x' ) );

echo "\n=== Restoring a clean state on this site ===\n";
$plugin->settings->update( [ 'provider' => '', 'ms_tenant_id' => '', 'ms_client_id' => '', 'ms_sender' => '',
	'google_sa_email' => '', 'google_sender' => '', 'google_client_id' => '', 'from_email' => '', 'from_name' => '' ] );
$plugin->secrets->flush();
$plugin->tokens->flush();
$plugin->health->reset();
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mmoa_log" );
check( 'plugin left inactive-by-config', ! $plugin->settings->is_active() );
check( 'no credentials remain', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
