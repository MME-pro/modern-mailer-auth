import { createRoot } from '@wordpress/element';
import App from './app';
import './styles.css';

/**
 * The admin app is mounted into one container printed by the settings page.
 *
 * HashRouter, not BrowserRouter: WordPress owns the real URL, and admin.php
 * cannot serve arbitrary sub-paths. Routing after the # keeps deep links
 * working - and keeps them shareable - without any rewrite rules.
 */
const mount = document.getElementById( 'mmoa-app-root' );

if ( mount ) {
	createRoot( mount ).render( <App /> );
}
