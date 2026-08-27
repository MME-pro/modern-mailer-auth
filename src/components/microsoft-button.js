import { __ } from '@wordpress/i18n';
import { cn } from '../lib/utils';

/**
 * Microsoft's four-square mark.
 *
 * Inlined for the same reasons as the Google one: an admin screen should not
 * make a third-party request to draw a logo, and an <img> that fails leaves an
 * unlabelled button. The four colours are Microsoft's, fixed - they must not
 * shift with the palette or in dark mode.
 */
const MicrosoftMark = ( { className } ) => (
	<svg
		className={ cn( 'size-[18px]', className ) }
		viewBox="0 0 18 18"
		aria-hidden="true"
		focusable="false"
	>
		<path fill="#F25022" d="M0 0h8.5v8.5H0z" />
		<path fill="#7FBA00" d="M9.5 0H18v8.5H9.5z" />
		<path fill="#00A4EF" d="M0 9.5h8.5V18H0z" />
		<path fill="#FFB900" d="M9.5 9.5H18V18H9.5z" />
	</svg>
);

/**
 * "Sign in with Microsoft", to Microsoft's identity guidelines.
 *
 * The same deliberate exception the Google button makes: a federated sign-in
 * control people recognise by sight, left looking like one rather than
 * re-themed into this plugin's palette. Microsoft's guidance specifies a white
 * or black surface with a thin grey border and Segoe UI, and getting close to
 * it matters more than matching the surrounding buttons - what a person reads
 * here is "I am about to hand credentials to Microsoft".
 */
const MicrosoftButton = ( { href, disabled = false, className, children } ) => {
	const classes = cn(
		'inline-flex items-center gap-3 h-10 pl-3 pr-4 rounded-md border no-underline select-none',
		'bg-white border-[#8c8c8c] text-[#5e5e5e] shadow-xs',
		'font-semibold text-sm',
		'[font-family:"Segoe_UI",system-ui,sans-serif]',
		'transition-[background-color,box-shadow] hover:bg-[#f3f3f3] hover:shadow-sm',
		'focus-visible:ring-[3px] focus-visible:ring-[#00A4EF]/40 outline-none',
		disabled && 'pointer-events-none opacity-50',
		className
	);

	const content = (
		<>
			<MicrosoftMark />
			<span>{ children || __( 'Sign in with Microsoft', 'modern-mailer-oauth' ) }</span>
		</>
	);

	if ( disabled || ! href ) {
		return (
			<button type="button" disabled className={ classes }>
				{ content }
			</button>
		);
	}

	return (
		<a href={ href } className={ classes }>
			{ content }
		</a>
	);
};

export { MicrosoftButton, MicrosoftMark };
