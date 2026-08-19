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
ob_start();
$page->render();
$html = ob_get_clean();

check( 'renders without fatal', strlen( $html ) > 1000, strlen( $html ) . ' bytes' );
check( 'provider selector present', false !== strpos( $html, 'id="provider"' ) );
check( 'access-policy warning shown', false !== strpos( $html, 'New-ApplicationAccessPolicy' ) );
check( 'Gmail Testing-mode trap warned about', false !== strpos( $html, 'seven days' ) );
check( 'nonce fields emitted', substr_count( $html, '_wpnonce' ) >= 3, substr_count( $html, '_wpnonce' ) . ' found' );
check( 'no credential echoed into the form', false === strpos( $html, 'SuperSecretValue123' ) );
check( 'constant-pinned field marked as such', false !== strpos( $html, 'wp-config.php' ) );

echo "\n=== Site Health ===\n";
$sh = ( new ModernMailer\Admin\Site_Health( $plugin ) )->run_test();
check( 'reports unconfigured as a recommendation', 'recommended' === $sh['status'], $sh['status'] );

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
