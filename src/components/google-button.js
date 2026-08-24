import { __ } from '@wordpress/i18n';
import { cn } from '../lib/utils';

/**
 * The official Google "G".
 *
 * Inlined rather than loaded from Google's CDN: a strict admin screen should
 * not make a third-party request just to draw a logo, and an image that fails
 * to load would leave an unlabelled button. The four brand colours are fixed
 * values on purpose - they are Google's mark, not ours to re-theme, and they
 * must not shift with the palette or in dark mode.
 */
const GoogleMark = ( { className } ) => (
	<svg
		className={ cn( 'size-[18px]', className ) }
		viewBox="0 0 18 18"
		aria-hidden="true"
		focusable="false"
	>
		<path
			fill="#4285F4"
			d="M17.64 9.2045c0-.6381-.0573-1.2518-.1636-1.8409H9v3.4814h4.8436c-.2086 1.125-.8427 2.0782-1.7959 2.7164v2.2581h2.9087c1.7018-1.5668 2.6836-3.874 2.6836-6.615z"
		/>
		<path
			fill="#34A853"
			d="M9 18c2.43 0 4.4673-.806 5.9564-2.1805l-2.9087-2.2581c-.8059.54-1.8368.859-3.0477.859-2.344 0-4.3282-1.5831-5.036-3.7104H.9574v2.3318C2.4382 15.9832 5.4818 18 9 18z"
		/>
		<path
			fill="#FBBC05"
			d="M3.964 10.71c-.18-.54-.2822-1.1168-.2822-1.71s.1023-1.17.2823-1.71V4.9582H.9573A8.9965 8.9965 0 0 0 0 9c0 1.4523.3477 2.8268.9573 4.0418L3.964 10.71z"
		/>
		<path
			fill="#EA4335"
			d="M9 3.5795c1.3214 0 2.5077.4541 3.4405 1.346l2.5813-2.5814C13.4632.8918 11.426 0 9 0 5.4818 0 2.4382 2.0168.9573 4.9582L3.964 7.29C4.6718 5.1627 6.656 3.5795 9 3.5795z"
		/>
	</svg>
);

/**
 * "Sign in with Google", styled to Google's identity guidelines rather than to
 * this plugin's design system.
 *
 * That is a deliberate exception. Every other control here follows the shadcn
 * tokens, but this one is a federated sign-in button people recognise by sight,
 * and re-theming it into a black pill makes it look like an ordinary action
 * instead of "you are about to hand credentials to Google". The neutral surface,
 * the fixed mark and the wording are the parts users actually read.
 */
const GoogleButton = ( { href, disabled = false, className, children } ) => {
	const classes = cn(
		'inline-flex items-center gap-3 h-10 pl-3 pr-4 rounded-md border no-underline select-none',
		'bg-white border-[#dadce0] text-[#1f1f1f] shadow-xs',
		'font-medium text-sm tracking-[0.01em]',
		'transition-[background-color,box-shadow] hover:bg-[#f8f9fa] hover:shadow-sm',
		'focus-visible:ring-[3px] focus-visible:ring-[#4285F4]/40 outline-none',
		disabled && 'pointer-events-none opacity-50',
		className
	);

	const content = (
		<>
			<GoogleMark />
			<span>{ children || __( 'Sign in with Google', 'modern-mailer-oauth' ) }</span>
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

export { GoogleButton, GoogleMark };
