import { createRoot } from '@wordpress/element';
import App from './app';
import ErrorBoundary from './components/error-boundary';
import { installTranslationGuard } from './lib/translation-guard';
import './styles.css';

/**
 * The admin app is mounted into one container printed by the settings page.
 *
 * HashRouter, not BrowserRouter: WordPress owns the real URL, and admin.php
 * cannot serve arbitrary sub-paths. Routing after the # keeps deep links
 * working - and keeps them shareable - without any rewrite rules.
 *
 * The guard goes in before the first render, because it has to be in place
 * before React holds any node references at all - patching it afterwards would
 * leave whatever was already mounted unprotected. The boundary is the second
 * net: the guard covers the one failure that is known and common, and the
 * boundary covers everything else, so no fault in here can produce a blank
 * screen with nothing on it to act on.
 */
installTranslationGuard();

const mount = document.getElementById( 'mmoa-app-root' );

if ( mount ) {
	createRoot( mount ).render(
		<ErrorBoundary>
			<App />
		</ErrorBoundary>
	);
}
