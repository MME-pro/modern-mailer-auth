import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { CheckCircle2, Copy, Check, TriangleAlert, ShieldCheck } from 'lucide-react';
import { Button, Separator, Alert, AlertDescription } from './ui';
import { GoogleButton } from './google-button';

/**
 * Google sign-in for the consumer Gmail connection.
 *
 * This is the only control in the app that is a plain link rather than a
 * fetch(). Starting an OAuth handshake means handing the browser to Google and
 * getting it back as a fresh page load, which XHR cannot do - so the link is a
 * nonce-signed admin-post URL the server turns into a redirect.
 *
 * The OAuth client is the site's own, so the consent prompt is strictly between
 * the admin and Google. Nothing is proxied through a shared application and no
 * third party ever sees the resulting tokens - which is worth stating on the
 * screen, because "sign in with Google" in a plugin usually means the opposite.
 */
const CopyField = ( { value } ) => {
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
			<Button
				variant="outline"
				size="icon"
				onClick={ copy }
				aria-label={ __( 'Copy redirect URI', 'modern-mailer-oauth' ) }
			>
				{ copied ? <Check className="text-success" /> : <Copy /> }
			</Button>
		</div>
	);
};

const GoogleConnect = ( { oauth, dirty } ) => {
	if ( ! oauth ) {
		return null;
	}

	const {
		connected,
		has_credentials: hasCredentials,
		connect_url: connectUrl,
		disconnect_url: disconnectUrl,
		redirect_uri: redirectUri,
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
							'Add this exact value to your OAuth client in the Google Cloud console. Google matches it character for character, and refuses anything but HTTPS except on localhost.',
							'modern-mailer-oauth'
						) }
					</p>
					<CopyField value={ redirectUri } />
				</div>

				<div className="grid gap-3">
					<h4 className="text-sm font-medium m-0">
						{ __( 'Account', 'modern-mailer-oauth' ) }
					</h4>

					{ connected ? (
						<div className="flex flex-wrap items-center gap-3 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
							<CheckCircle2 className="size-4 text-success shrink-0" />
							<span className="text-sm flex-1 min-w-[200px]">
								{ __(
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
													'This revokes the grant at Google and forgets it here. Sending through this connection will stop until you sign in again. Continue?',
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
											'Enter the client ID and secret above and save them before signing in.',
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
								<GoogleButton
									href={ connectUrl }
									disabled={ ! hasCredentials }
								/>
							</div>

							<p className="flex items-start gap-2 text-xs text-muted-foreground m-0 max-w-prose">
								<ShieldCheck className="size-3.5 shrink-0 mt-px text-success" />
								{ __(
									'The prompt asks only for permission to send mail, using your own OAuth client. This plugin never requests read access to the mailbox, and no third party sees the tokens.',
									'modern-mailer-oauth'
								) }
							</p>
						</>
					) }
				</div>
			</div>
		</div>
	);
};

export default GoogleConnect;
