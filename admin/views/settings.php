<?php
/**
 * Settings screen markup.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $settings
 * @var string                        $provider
 * @var array                         $entries
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

$notice = $this->take_notice();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Modern Mailer', 'modern-mailer-oauth' ); ?></h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo 'error' === $notice[0] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $notice[1] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mmoa_save" />
		<?php wp_nonce_field( 'mmoa_save' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="provider"><?php esc_html_e( 'Provider', 'modern-mailer-oauth' ); ?></label></th>
				<td>
					<select name="provider" id="provider" <?php disabled( $settings->is_constant( 'provider' ) ); ?>>
						<?php foreach ( Settings::provider_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $provider, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
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

		<div class="mmoa-panel" data-provider="graph">
			<h2><?php esc_html_e( 'Microsoft 365', 'modern-mailer-oauth' ); ?></h2>
			<p><?php esc_html_e( 'Uses app-only authentication, so there is no sign-in prompt and no token that expires and needs reauthorizing. Requires a Microsoft 365 work or school account; personal outlook.com addresses cannot use this method.', 'modern-mailer-oauth' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'ms_tenant_id', __( 'Directory (tenant) ID', 'modern-mailer-oauth' ), __( 'From the Overview page of your Entra app registration.', 'modern-mailer-oauth' ) );
				$this->field( 'ms_client_id', __( 'Application (client) ID', 'modern-mailer-oauth' ) );
				$this->secret_field( 'ms_client_secret', __( 'Client secret', 'modern-mailer-oauth' ), __( 'Copy the secret Value, not the Secret ID. Entra shows the Value only once.', 'modern-mailer-oauth' ) );
				$this->field( 'ms_sender', __( 'Send as mailbox', 'modern-mailer-oauth' ), __( 'A licensed or shared mailbox. Not a distribution list.', 'modern-mailer-oauth' ), 'email' );
				?>
				<tr>
					<th scope="row"><label for="ms_secret_expires"><?php esc_html_e( 'Secret expires', 'modern-mailer-oauth' ); ?></label></th>
					<td>
						<input type="date" id="ms_secret_expires" name="ms_secret_expires"
							value="<?php echo esc_attr( $settings->get( 'ms_secret_expires' ) ? gmdate( 'Y-m-d', (int) $settings->get( 'ms_secret_expires' ) ) : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Entra secrets last at most 24 months. Record the expiry date and you will be warned before it lapses instead of finding out when mail stops.', 'modern-mailer-oauth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access scoping', 'modern-mailer-oauth' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ms_policy_ack" value="1" <?php checked( (bool) $settings->get( 'ms_policy_ack' ) ); ?> />
							<?php esc_html_e( 'I have restricted this application to specific mailboxes', 'modern-mailer-oauth' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Important: the Mail.Send application permission lets this app send as any mailbox in the tenant until you scope it. Restrict it in Exchange Online PowerShell:', 'modern-mailer-oauth' ); ?>
						</p>
						<pre class="code" style="white-space:pre-wrap"><code>New-ApplicationAccessPolicy -AppId &lt;client-id&gt; `
  -PolicyScopeGroupId wp-senders@yourdomain.com `
  -AccessRight RestrictAccess</code></pre>
					</td>
				</tr>
			</table>
		</div>

		<div class="mmoa-panel" data-provider="gmail_sa">
			<h2><?php esc_html_e( 'Google Workspace service account', 'modern-mailer-oauth' ); ?></h2>
			<p><?php esc_html_e( 'The Google equivalent of app-only auth: no consent screen and no refresh token to expire. Workspace domains only.', 'modern-mailer-oauth' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'google_sa_email', __( 'Service account email', 'modern-mailer-oauth' ), __( 'The client_email value from the downloaded JSON key.', 'modern-mailer-oauth' ) );
				$this->secret_field( 'google_sa_key', __( 'Private key', 'modern-mailer-oauth' ), __( 'The private_key value from the same JSON, including the BEGIN and END lines.', 'modern-mailer-oauth' ), true );
				$this->field( 'google_sender', __( 'Send as mailbox', 'modern-mailer-oauth' ), __( 'The Workspace user this service account impersonates.', 'modern-mailer-oauth' ), 'email' );
				?>
			</table>
			<p class="description">
				<?php esc_html_e( 'Authorize the service account client ID for the https://www.googleapis.com/auth/gmail.send scope in Admin console, Security, Access and data control, API controls, Domain-wide delegation.', 'modern-mailer-oauth' ); ?>
			</p>
		</div>

		<div class="mmoa-panel" data-provider="gmail_oauth">
			<h2><?php esc_html_e( 'Gmail', 'modern-mailer-oauth' ); ?></h2>
			<p><strong><?php esc_html_e( 'Set your Google Cloud consent screen to In production before connecting.', 'modern-mailer-oauth' ); ?></strong>
				<?php esc_html_e( 'While it is left in Testing, Google expires the refresh token every seven days and sending stops without warning. This is the most common cause of a Gmail connection that works for a week and then quietly dies.', 'modern-mailer-oauth' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'google_client_id', __( 'OAuth client ID', 'modern-mailer-oauth' ) );
				$this->secret_field( 'google_client_sec', __( 'OAuth client secret', 'modern-mailer-oauth' ) );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'modern-mailer-oauth' ); ?></th>
					<td>
						<code><?php echo esc_html( admin_url( 'options-general.php?page=modern-mailer-oauth&mmoa_oauth=google' ) ); ?></code>
						<p class="description"><?php esc_html_e( 'Add this exact value to the authorized redirect URIs of your OAuth client. Google requires HTTPS for anything other than localhost.', 'modern-mailer-oauth' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

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
			<?php wp_nonce_field( 'mmoa_verify' ); ?>
			<?php submit_button( __( 'Verify credentials', 'modern-mailer-oauth' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_test_email" />
			<?php wp_nonce_field( 'mmoa_test_email' ); ?>
			<input type="email" name="test_to" required
				value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
			<?php submit_button( __( 'Send test email', 'modern-mailer-oauth' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>

	<?php
	if ( $entries ) :
		?>
		<hr />
		<h2><?php esc_html_e( 'Recent activity', 'modern-mailer-oauth' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'To', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Result', 'modern-mailer-oauth' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $entry->created_at, 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( $entry->recipients ); ?></td>
						<td><?php echo esc_html( $entry->subject ); ?></td>
						<td>
							<?php if ( 'sent' === $entry->status ) : ?>
								<span style="color:#008a20">&#10003; <?php esc_html_e( 'Sent', 'modern-mailer-oauth' ); ?></span>
							<?php else : ?>
								<span style="color:#d63638">&#10007; <?php echo esc_html( $entry->error_message ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<script>
( function () {
	var select = document.getElementById( 'provider' );
	var panels = document.querySelectorAll( '.mmoa-panel' );

	function sync() {
		panels.forEach( function ( panel ) {
			panel.hidden = panel.dataset.provider !== select.value;
		} );
	}

	select.addEventListener( 'change', sync );
	sync();
}() );
</script>
