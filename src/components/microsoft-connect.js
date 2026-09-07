import { __, sprintf } from '@wordpress/i18n';
import { createInterpolateElement, useState } from '@wordpress/element';
import { CheckCircle2, Copy, Check, TriangleAlert, ShieldCheck } from 'lucide-react';
import { Button, Separator, Alert, AlertDescription } from './ui';
import { MicrosoftButton } from './microsoft-button';

/**
 * Microsoft sign-in for the delegated connection.
 *
 * The counterpart to GoogleConnect and deliberately the same shape, because it
 * is the same job: an OAuth handshake hands the browser to the identity
 * provider and gets it back as a fresh page load, which a fetch() cannot do -
 * so the control is a nonce-signed admin-post link the server turns into a
 * redirect, not a button that posts.
 *
 * Two things differ from the Google block, and both are Microsoft's doing.
 *
 * It shows which mailbox signed in. A delegated connection can send as that one
 * address and no other, so it is the most useful fact on the panel - where a
 * Gmail connection's account is implied by the client it belongs to.
 *
 * And disconnecting says less. Google revokes the grant when asked; Microsoft
 * publishes no endpoint that revokes a single one, so this can only forget the
 * token locally and point at the portal. Saying "revoked" here would be untrue,
 * and untrue in the direction that matters - somebody would believe access had
 * been withdrawn when it had not.
 */
const CopyField = ( { value, label } ) => {
	const [ copied, setCopied ] = useState( false );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( value );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard access can be refused - over plain HTTP, or by policy.
			// The value is on screen and selectable regardless, so there is
			// nothing useful to report.
		}
	};

	return (
		<div className="flex items-stretch gap-2">
			<code className="flex-1 min-w-0 rounded-md border bg-muted/60 px-3 py-2 font-mono text-xs leading-relaxed break-all">
				{ value }
			</code>
			<Button variant="outline" size="icon" onClick={ copy } aria-label={ label }>
				{ copied ? <Check className="text-success" /> : <Copy /> }
			</Button>
		</div>
	);
};

const MicrosoftConnect = ( { oauth, dirty } ) => {
	if ( ! oauth ) {
		return null;
	}

	const {
		connected,
		account,
		has_credentials: hasCredentials,
		connect_url: connectUrl,
		disconnect_url: disconnectUrl,
		redirect_uri: redirectUri,
		revoke_help_url: revokeHelpUrl,
	} = oauth;

	return (
		<div className="mt-6">
			<Separator />

			<div className="grid gap-5 pt-6">
				<div className="grid gap-2">
					<h4 className="text-sm font-medium m-0">
						{ __( 'Redirect URI', 'modern-mailer-oauth' ) }
					</h4>
					<p className="text-xs text-muted-foreground m-0 max-w-prose">
						{ __(
							'Add this exact value to your app registration in Entra, under Authentication, as a Web platform. Not a Single-page application - that registration refuses to issue a token here and reports it as a CORS error, which explains nothing.',
							'modern-mailer-oauth'
						) }
					</p>
					<CopyField
						value={ redirectUri }
						label={ __( 'Copy redirect URI', 'modern-mailer-oauth' ) }
					/>
				</div>

				<div className="grid gap-3">
					<h4 className="text-sm font-medium m-0">
						{ __( 'Mailbox', 'modern-mailer-oauth' ) }
					</h4>

					{ connected ? (
						<div className="flex flex-wrap items-center gap-3 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
							<CheckCircle2 className="size-4 text-success shrink-0" />
							{ /* One interpolated string rather than a sentence with the
							     address bolted on the end. German puts the mailbox
							     before the verb, and a translator handed two
							     fragments has nowhere to put it. */ }
							<span className="text-sm flex-1 min-w-[200px]">
								{ account
									? createInterpolateElement(
											sprintf(
												/* translators: %s: the signed-in mailbox address. */
												__(
													'Signed in as <b>%s</b>. This connection can send as that mailbox.',
													'modern-mailer-oauth'
												),
												account
											),
											{ b: <strong className="font-medium" /> }
									  )
									: __(
											'Connected. A refresh token is stored for this connection.',
											'modern-mailer-oauth'
									  ) }
							</span>
							<Button
								asChild
								variant="outline"
								size="sm"
								className="border-danger/30 text-danger hover:bg-danger/10 hover:text-danger"
							>
								<a
									href={ disconnectUrl }
									onClick={ ( e ) => {
										// eslint-disable-next-line no-alert
										if (
											! window.confirm(
												__(
													'This forgets the sign-in here and sending through this connection will stop. Microsoft cannot revoke a single sign-in remotely, so the grant itself stays until you remove it in your Microsoft account. Continue?',
													'modern-mailer-oauth'
												)
											)
										) {
											e.preventDefault();
										}
									} }
								>
									{ __( 'Disconnect', 'modern-mailer-oauth' ) }
								</a>
							</Button>
						</div>
					) : (
						<>
							{ ! hasCredentials && (
								<Alert variant="warning">
									<TriangleAlert />
									<AlertDescription>
										{ __(
											'Enter the application ID and client secret above and save them before signing in.',
											'modern-mailer-oauth'
										) }
									</AlertDescription>
								</Alert>
							) }

							{ dirty && hasCredentials && (
								<Alert variant="warning">
									<TriangleAlert />
									<AlertDescription>
										{ __(
											'You have unsaved changes. Signing in leaves this page and they will be lost.',
											'modern-mailer-oauth'
										) }
									</AlertDescription>
								</Alert>
							) }

							<div>
								<MicrosoftButton
									href={ connectUrl }
									disabled={ ! hasCredentials }
								/>
							</div>

							<p className="flex items-start gap-2 text-xs text-muted-foreground m-0 max-w-prose">
								<ShieldCheck className="size-3.5 shrink-0 mt-px text-success" />
								{ __(
									'The prompt asks only for permission to send mail and to read your own address, using your own app registration. This plugin never requests read access to the mailbox, and no third party sees the tokens.',
									'modern-mailer-oauth'
								) }
							</p>
						</>
					) }

					{ connected && revokeHelpUrl && (
						<p className="text-xs text-muted-foreground m-0 max-w-prose">
							{ __(
								'To withdraw the grant at Microsoft as well, remove this application under My Applications:',
								'modern-mailer-oauth'
							) }{ ' ' }
							<a
								href={ revokeHelpUrl }
								target="_blank"
								rel="noreferrer"
								className="text-brand-deep no-underline hover:underline"
							>
								{ revokeHelpUrl }
							</a>
						</p>
					) }
				</div>
			</div>
		</div>
	);
};

export default MicrosoftConnect;
