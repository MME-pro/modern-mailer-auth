import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The last thing between a thrown error and a blank screen.
 *
 * React unmounts the entire root when a render or a commit throws. That is the
 * right default for a component tree nobody is watching, and the wrong one for
 * a settings screen: an administrator who has just pressed Save is left looking
 * at nothing at all, with no way to tell whether the save happened.
 *
 * So there is a boundary, and it says the one thing that actually matters -
 * that the change was probably saved and a reload will show the truth. The
 * request is sent and answered before React re-renders, so a crash on the
 * render that follows means the work is almost certainly already done. Telling
 * someone that is the difference between reloading and re-entering a form.
 *
 * The fallback is deliberately plain. It renders when something in the app has
 * already failed, so it leans on nothing from the app: no context, no data
 * fetching, no icon set. Tokens only, which are defined on :root and therefore
 * still there.
 *
 * Class component because React has no hook for this. getDerivedStateFromError
 * and componentDidCatch have no function equivalent.
 */
class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	componentDidCatch( error, info ) {
		// Console rather than a notice: this is for whoever is asked to explain
		// it later, and the screen already says what the reader needs to do.
		// eslint-disable-next-line no-console
		console.error( 'MME-Mail to SMTP crashed.', error, info );
	}

	render() {
		const { error } = this.state;

		if ( ! error ) {
			return this.props.children;
		}

		return (
			<div id="mmoa-app">
				<div className="mx-auto w-full max-w-2xl px-6 py-16">
					<h1 className="font-display m-0 text-2xl font-normal tracking-[-0.02em] text-foreground">
						{ __( 'This screen stopped responding.', 'modern-mailer-oauth' ) }
					</h1>

					<p className="mt-4 mb-0 text-sm text-muted-foreground">
						{ __(
							'Your change was most likely saved - the screen failed while redrawing itself, which happens after the save has already been sent and answered. Reload to see the current settings.',
							'modern-mailer-oauth'
						) }
					</p>

					<p className="mt-3 mb-0 text-sm text-muted-foreground">
						{ __(
							'If you are using a browser translation, this is the usual cause: it rewrites the page in a way the interface cannot always follow. Turning it off for this screen avoids it.',
							'modern-mailer-oauth'
						) }
					</p>

					<button
						type="button"
						onClick={ () => window.location.reload() }
						className="mt-6 inline-flex h-9 cursor-pointer items-center rounded-md border-0 bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
					>
						{ __( 'Reload this screen', 'modern-mailer-oauth' ) }
					</button>

					{ /* Folded away: useful when reporting the fault, noise otherwise. */ }
					<details className="mt-8">
						<summary className="cursor-pointer text-xs text-muted-foreground">
							{ __( 'Technical detail', 'modern-mailer-oauth' ) }
						</summary>
						<pre className="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs text-muted-foreground">
							{ String( error?.stack || error?.message || error ) }
						</pre>
					</details>
				</div>
			</div>
		);
	}
}

export default ErrorBoundary;
