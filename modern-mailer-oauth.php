<?php
/**
 * Plugin Name:       Modern Mailer - OAuth SMTP for Microsoft 365 and Gmail
 * Description:       Sends WordPress email through the Microsoft Graph and Gmail APIs using OAuth 2.0. App-only and service-account authentication mean there is no refresh token to expire and no periodic reauthorization. A backup connection and a retry queue mean a transient fault delays an email rather than losing it.
 * Version:           0.5.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            builtwithmtw
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-mailer-oauth
 * Domain Path:       /languages
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.5.2';
const PLUGIN_FILE = __FILE__;

define( 'ModernMailer\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ModernMailer\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Map a namespaced class to its file and load it.
 *
 * ModernMailer\Token_Store            => includes/class-token-store.php
 * ModernMailer\Providers\Graph        => includes/providers/class-graph.php
 * ModernMailer\Providers\Provider_Interface => includes/providers/interface-provider.php
 * ModernMailer\Providers\Abstract_Provider  => includes/providers/abstract-provider.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		if ( 0 === strpos( $name, 'Abstract_' ) ) {
			$file = 'abstract-' . substr( $name, 9 );
		} elseif ( substr( $name, -10 ) === '_Interface' ) {
			$file = 'interface-' . substr( $name, 0, -10 );
		} else {
			$file = 'class-' . $name;
		}

		$dir  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path = PLUGIN_DIR . 'includes/' . $dir . strtolower( str_replace( '_', '-', $file ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Admin classes live outside includes/, so they get their own tiny loader.
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\Admin\\' ) ) {
			return;
		}

		$name = substr( $class, strlen( __NAMESPACE__ . '\\Admin\\' ) );
		$path = PLUGIN_DIR . 'admin/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
