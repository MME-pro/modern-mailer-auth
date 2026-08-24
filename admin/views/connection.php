<?php
/**
 * One connection's provider choice and credential panels.
 *
 * Included once per slot. Every field name is slot-prefixed by Admin_Page, so
 * the same markup drives the primary and the backup connection without either
 * knowing about the other.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $slot_settings Settings scoped to this slot.
 * @var string                        $slot          '' for primary, 'backup' otherwise.
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

$field_name  = static fn( string $key ): string => ( '' === $slot ? $key : $slot . '_' . $key );
$select_id   = $field_name( 'provider' );
$current     = (string) $slot_settings->get( 'provider' );
$panel_class = 'mmoa-panel-' . ( '' === $slot ? 'primary' : $slot );
?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $select_id ); ?>"><?php esc_html_e( 'Provider', 'modern-mailer-oauth' ); ?></label></th>
		<td>
			<select name="<?php echo esc_attr( $select_id ); ?>" id="<?php echo esc_attr( $select_id ); ?>"
				class="mmoa-provider-select" data-panels="<?php echo esc_attr( $panel_class ); ?>"
				<?php disabled( $slot_settings->is_constant( 'provider' ) ); ?>>
				<?php foreach ( Settings::provider_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
</table>

<div class="<?php echo esc_attr( $panel_class ); ?>" data-provider="graph">
	<h3><?php esc_html_e( 'Microsoft 365', 'modern-mailer-oauth' ); ?></h3>
	<p><?php esc_html_e( 'Uses app-only authentication, so there is no sign-in prompt and no token that expires and needs reauthorizing. Requires a Microsoft 365 work or school account; personal outlook.com addresses cannot use this method.', 'modern-mailer-oauth' ); ?></p>
	<table class="form-table" role="presentation">
		<?php
		$this->field( 'ms_tenant_id', __( 'Directory (tenant) ID', 'modern-mailer-oauth' ), __( 'From the Overview page of your Entra app registration.', 'modern-mailer-oauth' ), 'text', $slot );
		$this->field( 'ms_client_id', __( 'Application (client) ID', 'modern-mailer-oauth' ), '', 'text', $slot );
		$this->secret_field( 'ms_client_secret', __( 'Client secret', 'modern-mailer-oauth' ), __( 'Copy the secret Value, not the Secret ID. Entra shows the Value only once.', 'modern-mailer-oauth' ), false, $slot );
		$this->field( 'ms_sender', __( 'Send as mailbox', 'modern-mailer-oauth' ), __( 'A licensed or shared mailbox. Not a distribution list.', 'modern-mailer-oauth' ), 'email', $slot );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_name( 'ms_secret_expires' ) ); ?>"><?php esc_html_e( 'Secret expires', 'modern-mailer-oauth' ); ?></label></th>
			<td>
				<input type="date" id="<?php echo esc_attr( $field_name( 'ms_secret_expires' ) ); ?>"
					name="<?php echo esc_attr( $field_name( 'ms_secret_expires' ) ); ?>"
					value="<?php echo esc_attr( $slot_settings->get( 'ms_secret_expires' ) ? gmdate( 'Y-m-d', (int) $slot_settings->get( 'ms_secret_expires' ) ) : '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Entra secrets last at most 24 months. Record the expiry date and you will be warned before it lapses instead of finding out when mail stops.', 'modern-mailer-oauth' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Access scoping', 'modern-mailer-oauth' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $field_name( 'ms_policy_ack' ) ); ?>" value="1" <?php checked( (bool) $slot_settings->get( 'ms_policy_ack' ) ); ?> />
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

<div class="<?php echo esc_attr( $panel_class ); ?>" data-provider="gmail_sa">
	<h3><?php esc_html_e( 'Google Workspace service account', 'modern-mailer-oauth' ); ?></h3>
	<p><?php esc_html_e( 'The Google equivalent of app-only auth: no consent screen and no refresh token to expire. Workspace domains only.', 'modern-mailer-oauth' ); ?></p>
	<table class="form-table" role="presentation">
		<?php
		$this->field( 'google_sa_email', __( 'Service account email', 'modern-mailer-oauth' ), __( 'The client_email value from the downloaded JSON key.', 'modern-mailer-oauth' ), 'text', $slot );
		$this->secret_field( 'google_sa_key', __( 'Private key', 'modern-mailer-oauth' ), __( 'The private_key value from the same JSON, including the BEGIN and END lines.', 'modern-mailer-oauth' ), true, $slot );
		$this->field( 'google_sender', __( 'Send as mailbox', 'modern-mailer-oauth' ), __( 'The Workspace user this service account impersonates.', 'modern-mailer-oauth' ), 'email', $slot );
		?>
	</table>
	<p class="description">
		<?php esc_html_e( 'Authorize the service account client ID for the https://www.googleapis.com/auth/gmail.send scope in Admin console, Security, Access and data control, API controls, Domain-wide delegation.', 'modern-mailer-oauth' ); ?>
	</p>
</div>

<div class="<?php echo esc_attr( $panel_class ); ?>" data-provider="gmail_oauth">
	<h3><?php esc_html_e( 'Gmail', 'modern-mailer-oauth' ); ?></h3>
	<p><strong><?php esc_html_e( 'Set your Google Cloud consent screen to In production before connecting.', 'modern-mailer-oauth' ); ?></strong>
		<?php esc_html_e( 'While it is left in Testing, Google expires the refresh token every seven days and sending stops without warning. This is the most common cause of a Gmail connection that works for a week and then quietly dies.', 'modern-mailer-oauth' ); ?></p>
	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'This uses your own OAuth client, created in your own Google Cloud project. Nothing is routed through a shared or third-party application, so the tokens Google issues are only ever seen by this site.', 'modern-mailer-oauth' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<?php
		$this->field( 'google_client_id', __( 'OAuth client ID', 'modern-mailer-oauth' ), __( 'From Credentials in your Google Cloud project. It must be a Web application client.', 'modern-mailer-oauth' ), 'text', $slot );
		$this->secret_field( 'google_client_sec', __( 'OAuth client secret', 'modern-mailer-oauth' ), '', false, $slot );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Redirect URI', 'modern-mailer-oauth' ); ?></th>
			<td>
				<code><?php echo esc_html( ModernMailer\Auth\Google_Consent::redirect_uri() ); ?></code>
				<p class="description"><?php esc_html_e( 'Add this exact value to the authorized redirect URIs of your OAuth client. Google matches it character for character, and requires HTTPS for anything other than localhost.', 'modern-mailer-oauth' ); ?></p>
				<p class="description"><?php esc_html_e( 'Both connections share this one URI, so it only needs registering once.', 'modern-mailer-oauth' ); ?></p>
			</td>
		</tr>
		<?php $this->google_connect_control( $slot ); ?>
	</table>
	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'The prompt asks only for permission to send mail. This plugin never requests read access to the mailbox.', 'modern-mailer-oauth' ); ?>
	</p>
</div>
