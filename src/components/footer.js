import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Credits, and the one place the plugin says who built it.
 *
 * Quiet on purpose. The header band already carries the name, the version and
 * the delivery status, so repeating any of that loudly down here would be a
 * second header rather than a footer. What this adds is the one thing the app
 * never says anywhere else: who is responsible for it, and where to go.
 *
 * Light rather than a second ink band. Bookending the screen in near-black
 * would look deliberate on a long log table and absurd on a short settings
 * form, because the band would float halfway up an empty page. A hairline and
 * muted text read the same at every content height.
 *
 * The version is repeated from the header for a duller reason than symmetry:
 * this is the part of the screen someone screenshots when reporting a problem.
 */

const CREDIT_URL = 'https://mme-pro.de/';

const Footer = () => (
	<footer className="mt-4 border-t border-border px-6 py-6 sm:px-8">
		<div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-muted-foreground">
			<p className="m-0">
				<span className="font-medium text-foreground">
					{ __( 'MME-Mail to SMTP', 'modern-mailer-oauth' ) }
				</span>
				<span className="mx-2 text-border">/</span>
				{ sprintf(
					/* translators: %s: plugin version number. */
					__( 'Version %s', 'modern-mailer-oauth' ),
					window.mmoa?.version || ''
				) }
				<span className="mx-2 text-border">/</span>
				{ __( 'GPL-2.0-or-later', 'modern-mailer-oauth' ) }
			</p>

			{ /* One interpolated string rather than a sentence glued to a link.
			     Split in two, a translator gets "Built and maintained by" with
			     nowhere to put it - German wants the agency name in a different
			     place in the clause. */ }
			<p className="m-0 sm:ml-auto">
				{ createInterpolateElement(
					__( 'Built and maintained by <a>MME-pro</a>', 'modern-mailer-oauth' ),
					{
						a: (
							<a
								href={ CREDIT_URL }
								target="_blank"
								rel="noreferrer"
								className="font-medium text-brand-deep no-underline hover:underline"
							/>
						),
					}
				) }
			</p>
		</div>
	</footer>
);

export default Footer;
