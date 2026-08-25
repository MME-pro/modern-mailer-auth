import { useEffect, useState, useCallback } from '@wordpress/element';

const KEY = 'mmoa-theme';

/**
 * Light or dark, remembered per browser.
 *
 * The class goes on #mmoa-app rather than <html>: this app is a guest inside
 * wp-admin, and darkening the whole admin because a plugin screen is dark would
 * be presumptuous - and would leave WordPress's own chrome in a state it never
 * designed for.
 *
 * It does NOT follow prefers-color-scheme by default. wp-admin is light, and an
 * app that silently goes dark inside a light admin reads as broken rather than
 * as considered. Dark is a choice someone makes here.
 */
export const useTheme = () => {
	const [ theme, setTheme ] = useState( () => {
		try {
			return window.localStorage.getItem( KEY ) === 'dark' ? 'dark' : 'light';
		} catch {
			// Private windows and locked-down browsers throw on access rather
			// than returning null. Light is the safe answer.
			return 'light';
		}
	} );

	useEffect( () => {
		const root = document.getElementById( 'mmoa-app' );

		if ( root ) {
			root.classList.toggle( 'dark', theme === 'dark' );
		}

		try {
			window.localStorage.setItem( KEY, theme );
		} catch {
			// Not being able to remember the choice is not a reason to refuse
			// to make it.
		}
	}, [ theme ] );

	const toggle = useCallback(
		() => setTheme( ( current ) => ( current === 'dark' ? 'light' : 'dark' ) ),
		[]
	);

	return { theme, toggle };
};
