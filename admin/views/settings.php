<?php
/**
 * Settings screen: site-wide options and the primary connection.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $settings
 * @var string                        $page
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'MME-Mail to SMTP', 'modern-mailer-oauth' ); ?></h1>

	<?php $this->render_notice(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mmoa_save" />
		<?php
		$this->return_field( $page );
		wp_nonce_field( 'mmoa_save' );
		?>

		<table class="form-table" role="presentation">
			<?php
			$this->field( 'from_email', __( 'From address', 'modern-mailer-oauth' ), __( 'Must be a mailbox the connected identity is permitted to send as.', 'modern-mailer-oauth' ), 'email' );
			$this->field( 'from_name', __( 'From name', 'modern-mailer-oauth' ) );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Force sender', 'modern-mailer-oauth' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="force_from" value="1" <?php checked( (bool) $settings->get( 'force_from' ) ); ?> />
						<?php esc_html_e( 'Override the From address set by other plugins', 'modern-mailer-oauth' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended. Both APIs reject or rewrite a From address the authenticated identity may not use.', 'modern-mailer-oauth' ); ?></p>
				</td>
			</tr>
		</table>

		<hr />
		<h2><?php esc_html_e( 'Primary connection', 'modern-mailer-oauth' ); ?></h2>
		<?php
		$slot          = Settings::SLOT_PRIMARY;
		$slot_settings = $settings;
		require __DIR__ . '/connection.php';
		?>

		<hr />
		<h2><?php esc_html_e( 'Retry queue', 'modern-mailer-oauth' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Queue failed sends', 'modern-mailer-oauth' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="queue_enabled" value="1" <?php checked( (bool) $settings->get( 'queue_enabled' ) ); ?> />
						<?php esc_html_e( 'Hold on to messages that failed for a temporary reason and retry them', 'modern-mailer-oauth' ); ?>
					</label>
					<p class="description" style="max-width:46em">
						<?php esc_html_e( 'Strongly recommended. The failure that actually loses mail is a brief network or DNS fault at your host, which clears in minutes - far longer than the few seconds a single page request can wait. Retrying across later requests is the only thing that survives it. Attempts back off from five minutes and give up after about two days.', 'modern-mailer-oauth' ); ?>
					</p>
					<p class="description" style="max-width:46em">
						<?php esc_html_e( 'Note that a queued message is stored complete, body included, until it is delivered - unlike the send log, which never stores content. Delivered messages are deleted immediately; abandoned ones are kept for seven days so you can see what was lost, then removed.', 'modern-mailer-oauth' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Logging and alerts', 'modern-mailer-oauth' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send log', 'modern-mailer-oauth' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="log_enabled" value="1" <?php checked( (bool) $settings->get( 'log_enabled' ) ); ?> />
						<?php esc_html_e( 'Record the outcome of every send', 'modern-mailer-oauth' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Envelope details and errors only. Message bodies are never stored.', 'modern-mailer-oauth' ); ?></p>
				</td>
			</tr>
			<?php
			$this->field( 'log_retention', __( 'Keep entries for (days)', 'modern-mailer-oauth' ), '', 'number' );
			$this->field( 'alert_threshold', __( 'Alert after N failures', 'modern-mailer-oauth' ), __( 'Consecutive failures before raising an alert.', 'modern-mailer-oauth' ), 'number' );
			$this->field( 'alert_email', __( 'Alert address', 'modern-mailer-oauth' ), __( 'Sent using the server mail function rather than the API, since the API is what has failed.', 'modern-mailer-oauth' ), 'email' );
			?>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Check the connection', 'modern-mailer-oauth' ); ?></h2>
	<p style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_verify" />
			<?php
			$this->return_field( $page );
			wp_nonce_field( 'mmoa_verify' );
			submit_button( __( 'Verify credentials', 'modern-mailer-oauth' ), 'secondary', 'submit', false );
			?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_test_email" />
			<?php
			$this->return_field( $page );
			wp_nonce_field( 'mmoa_test_email' );
			?>
			<input type="email" name="test_to" required
				value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
			<?php submit_button( __( 'Send test email', 'modern-mailer-oauth' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>

	<p class="description">
		<?php
		printf(
			/* translators: 1: link to the Backup screen, 2: link to the Logs screen. */
			esc_html__( 'A test message goes out over the primary connection. Configure a fallback on the %1$s screen, and see what was actually sent on the %2$s screen.', 'modern-mailer-oauth' ),
			'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url( 'modern-mailer-backup' ) ) . '">' . esc_html__( 'Backup', 'modern-mailer-oauth' ) . '</a>',
			'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url( 'modern-mailer-logs' ) ) . '">' . esc_html__( 'Logs', 'modern-mailer-oauth' ) . '</a>'
		);
		?>
	</p>
</div>

<?php require __DIR__ . '/panel-script.php'; ?>
