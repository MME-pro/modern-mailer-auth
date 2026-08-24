<?php
/**
 * Backup connection screen.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $settings
 * @var string                        $page
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

$primary_provider = (string) $settings->get( 'provider' );
$primary_label    = Settings::provider_labels()[ $primary_provider ] ?? '';
$backup_settings  = $settings->for_slot( Settings::SLOT_BACKUP );
$backup_provider  = (string) $backup_settings->get( 'provider' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Backup Connection', 'modern-mailer-oauth' ); ?></h1>

	<?php $this->render_notice(); ?>

	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'Optional. When the primary connection fails, the message is immediately retried here before anything else is attempted. Because it has its own credentials and its own endpoint, it survives failures that are permanent for the primary - an expired client secret, a revoked consent, a tenant-wide outage.', 'modern-mailer-oauth' ); ?>
	</p>

	<?php if ( Settings::PROVIDER_NONE === $primary_provider ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'There is no primary connection yet.', 'modern-mailer-oauth' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to the Settings screen. */
					esc_html__( 'A backup is only reached when the primary fails, so configure the primary on the %s screen first.', 'modern-mailer-oauth' ),
					'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url() ) . '">' . esc_html__( 'Settings', 'modern-mailer-oauth' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: %s: name of the primary provider. */
				esc_html__( 'Primary connection: %s', 'modern-mailer-oauth' ),
				'<strong>' . esc_html( $primary_label ) . '</strong>'
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	// The one mistake that makes a backup worthless, so it is called out as a
	// warning rather than buried in help text.
	if ( Settings::PROVIDER_NONE !== $backup_provider && $backup_provider === $primary_provider ) :
		?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Both connections use the same provider.', 'modern-mailer-oauth' ); ?></strong>
				<?php esc_html_e( 'They share an identity endpoint, so the outage that stops the primary will almost always stop the backup at the same moment. Pair Microsoft 365 with Google, or the reverse, for a fallback that actually holds.', 'modern-mailer-oauth' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mmoa_save" />
		<?php
		$this->return_field( $page );
		wp_nonce_field( 'mmoa_save' );

		$slot          = Settings::SLOT_BACKUP;
		$slot_settings = $backup_settings;
		require __DIR__ . '/connection.php';

		submit_button();
		?>
	</form>

	<?php if ( Settings::PROVIDER_NONE !== $backup_provider ) : ?>
		<hr />
		<h2><?php esc_html_e( 'Check the backup', 'modern-mailer-oauth' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_verify" />
			<input type="hidden" name="slot" value="<?php echo esc_attr( Settings::SLOT_BACKUP ); ?>" />
			<?php
			$this->return_field( $page );
			wp_nonce_field( 'mmoa_verify' );
			submit_button( __( 'Verify backup credentials', 'modern-mailer-oauth' ), 'secondary', 'submit', false );
			?>
		</form>
		<p class="description">
			<?php esc_html_e( 'This checks the credentials and the mailbox without sending anything. There is deliberately no "test through the backup" button: the backup is only reached when the primary fails, and forcing that would mean breaking the primary.', 'modern-mailer-oauth' ); ?>
		</p>
	<?php endif; ?>
</div>

<?php require __DIR__ . '/panel-script.php'; ?>
