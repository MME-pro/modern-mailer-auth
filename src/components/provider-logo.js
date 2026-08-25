import { Server } from 'lucide-react';
import { cn } from '../lib/utils';

/**
 * Brand marks for the connection picker.
 *
 * Drawn as inline SVG rather than loaded as images. An admin screen should not
 * make a request to a third party just to show a logo - that leaks which site
 * is looking at which provider - and an <img> that fails leaves a blank tile
 * with no clue what it was.
 *
 * These are simplified marks used to identify each service, which is what a
 * connection picker is for. Each keeps its own brand colours: recolouring them
 * into the interface palette would make them harder to recognise, which defeats
 * the point of showing a logo at all.
 */

const Microsoft = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path fill="#F25022" d="M2 2h9.5v9.5H2z" />
		<path fill="#7FBA00" d="M12.5 2H22v9.5h-9.5z" />
		<path fill="#00A4EF" d="M2 12.5h9.5V22H2z" />
		<path fill="#FFB900" d="M12.5 12.5H22V22h-9.5z" />
	</svg>
);

const Google = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path
			fill="#4285F4"
			d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.46a5.52 5.52 0 0 1-2.4 3.62v3.01h3.88c2.27-2.09 3.58-5.17 3.58-8.82z"
		/>
		<path
			fill="#34A853"
			d="M12 24c3.24 0 5.96-1.07 7.94-2.91l-3.88-3.01c-1.07.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.72-4.95H1.28v3.11A12 12 0 0 0 12 24z"
		/>
		<path
			fill="#FBBC05"
			d="M5.28 14.28a7.2 7.2 0 0 1 0-4.56V6.61H1.28a12 12 0 0 0 0 10.78l4-3.11z"
		/>
		<path
			fill="#EA4335"
			d="M12 4.77c1.76 0 3.34.61 4.59 1.8l3.44-3.44C17.95 1.19 15.23 0 12 0A12 12 0 0 0 1.28 6.61l4 3.11C6.22 6.88 8.87 4.77 12 4.77z"
		/>
	</svg>
);

const Gmail = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path fill="#4285F4" d="M22 5.5v13a1.5 1.5 0 0 1-1.5 1.5H18V8.9l-6 4.5-6-4.5V20H3.5A1.5 1.5 0 0 1 2 18.5v-13z" />
		<path fill="#34A853" d="M2 18.5v-13L12 13l10-7.5v13a1.5 1.5 0 0 1-1.5 1.5H18V8.9l-6 4.5-6-4.5V20H3.5A1.5 1.5 0 0 1 2 18.5z" opacity="0" />
		<path fill="#EA4335" d="M2 5.5A1.5 1.5 0 0 1 3.5 4h.7L12 9.8 19.8 4h.7A1.5 1.5 0 0 1 22 5.5L12 13 2 5.5z" />
		<path fill="#34A853" d="M2 5.5 12 13 2 20.5z" opacity="0" />
	</svg>
);

const SendGrid = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path fill="#1A82E2" d="M2 2h7.33v7.33H2z" />
		<path fill="#99E1F4" d="M9.33 2h7.34v7.33H9.33z" />
		<path fill="#99E1F4" d="M2 9.33h7.33v7.34H2z" />
		<path fill="#1A82E2" d="M9.33 9.33h7.34v7.34H9.33z" />
		<path fill="#00B3E3" d="M16.67 9.33H24v7.34h-7.33z" opacity="0" />
		<path fill="#1A82E2" d="M9.33 16.67h7.34V24H9.33z" />
	</svg>
);

const Postmark = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<rect width="24" height="24" rx="5" fill="#FFDE00" />
		<path
			fill="#1D1D1D"
			d="M17.1 10.3 12.7 5.9a1 1 0 0 0-1.4 0L6.9 10.3a1 1 0 0 0 1.4 1.4l2.7-2.7v8a1 1 0 0 0 2 0v-8l2.7 2.7a1 1 0 0 0 1.4-1.4z"
		/>
	</svg>
);

const Mailgun = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<circle cx="12" cy="12" r="11" fill="#F06B66" />
		<path
			fill="#fff"
			d="M12 6.2a5.8 5.8 0 1 0 0 11.6c1.3 0 2.5-.4 3.4-1.1l-1.1-1.4a4 4 0 1 1 1.5-3.1v.6a.8.8 0 0 1-1.6 0V12a2.2 2.2 0 1 0-.7 1.6l.1.2a2.4 2.4 0 0 0 4.2-1.5A5.8 5.8 0 0 0 12 6.2zm0 8a2.2 2.2 0 1 1 0-4.4 2.2 2.2 0 0 1 0 4.4z"
		/>
	</svg>
);

const Brevo = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<rect width="24" height="24" rx="5" fill="#0B996E" />
		<path
			fill="#fff"
			d="M8 6.5h4.6c2.4 0 3.9 1.2 3.9 3.1 0 1.3-.7 2.2-1.8 2.6 1.4.4 2.3 1.4 2.3 2.9 0 2.1-1.7 3.4-4.3 3.4H8zm2.2 4.9h2.1c1 0 1.7-.5 1.7-1.4s-.6-1.4-1.7-1.4h-2.1zm0 5h2.4c1.2 0 1.9-.6 1.9-1.5s-.7-1.5-1.9-1.5h-2.4z"
		/>
	</svg>
);

const Smtp2go = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<rect width="24" height="24" rx="5" fill="#00A2E1" />
		<path
			fill="#fff"
			d="M4.5 8.5A1.5 1.5 0 0 1 6 7h12a1.5 1.5 0 0 1 1.5 1.5v7A1.5 1.5 0 0 1 18 17H6a1.5 1.5 0 0 1-1.5-1.5zM6 8.7v.3l6 3.8 6-3.8v-.3z"
		/>
	</svg>
);

const Resend = () => (
	<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<rect width="24" height="24" rx="5" fill="#000" />
		<path
			fill="#fff"
			d="M7.4 5.8h5.3c2.7 0 4.4 1.5 4.4 3.9 0 1.8-1 3.1-2.7 3.6l3 4.9h-3.4l-2.6-4.4h-1v4.4H7.4zm3 5.6h1.9c1.1 0 1.8-.6 1.8-1.6s-.7-1.5-1.8-1.5h-1.9z"
		/>
	</svg>
);

const MARKS = {
	graph: Microsoft,
	gmail_sa: Google,
	gmail_oauth: Gmail,
	sendgrid: SendGrid,
	postmark: Postmark,
	mailgun: Mailgun,
	brevo: Brevo,
	smtp2go: Smtp2go,
	resend: Resend,
};

/**
 * A provider's mark, or a neutral server glyph for anything without one - a
 * generic SMTP host, or a provider added by another plugin.
 */
const ProviderLogo = ( { slug, className } ) => {
	const Mark = MARKS[ slug ];

	if ( ! Mark ) {
		return (
			<span
				className={ cn(
					'inline-flex items-center justify-center rounded-md bg-muted text-muted-foreground',
					className
				) }
			>
				<Server className="size-1/2" />
			</span>
		);
	}

	return (
		<span className={ cn( 'inline-flex items-center justify-center', className ) }>
			<Mark />
		</span>
	);
};

export default ProviderLogo;
