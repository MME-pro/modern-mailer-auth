<?php
/**
 * Front controller.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Crypto.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Providers.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Broker.php';

// Nothing here is cacheable, and several responses carry credentials.
header( 'Cache-Control: no-store, no-cache, must-revalidate' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );

/**
 * The path, with any prefix the site is mounted under removed.
 *
 * Shared hosting rarely gives you a bare domain, so this works whether the
 * service sits at the root or under /oauth/v1/. The tail is what matters.
 */
$path = trim( (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
$bits = array_values( array_filter( explode( '/', $path ), static fn( $bit ) => '' !== $bit ) );

// Everything before the last two segments is mount prefix, e.g. oauth/v1.
$tail = array_slice( $bits, -2 );

try {
	$config = Config::load( __DIR__ . '/../../.env.broker' );
	$store  = Store::connect( $config, new Crypto( $config->key() ) );
	$broker = new Broker( $config, $store );
} catch ( \Throwable $e ) {
	// The detail goes to the log, never to the caller: it names database hosts
	// and configuration keys.
	error_log( 'mmoa-broker: ' . $e->getMessage() );
	Http::fail( 'misconfigured', 'The setup service is not configured correctly. Its administrator has been given the details.', 500 );
}

// Cheap, and only occasionally. Shared hosting cron is unreliable, and these
// tables are small enough that this costs nothing worth measuring.
if ( 0 === random_int( 0, 49 ) ) {
	try {
		$store->prune();
	} catch ( \Throwable $e ) {
		error_log( 'mmoa-broker prune: ' . $e->getMessage() );
	}
}

$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

/**
 * Read a JSON request body.
 *
 * @return array<string,mixed>
 */
$body = static function (): array {
	$raw     = (string) file_get_contents( 'php://input' );
	$decoded = json_decode( $raw, true );

	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	// Form encoding is accepted as a fallback so the service can be exercised
	// with curl without composing JSON by hand.
	parse_str( $raw, $parsed );

	return is_array( $parsed ) ? $parsed : [];
};

try {
	// GET  {mount}/{family}/authorize
	// GET  {mount}/callback/{family}
	// POST {mount}/{family}/claim | refresh | revoke
	[ $first, $second ] = $tail + [ '', '' ];

	if ( 'callback' === $first && Providers::is_family( $second ) ) {
		$broker->callback( $second, $_GET );
	}

	if ( ! Providers::is_family( $first ) ) {
		Http::fail( 'not_found', 'No such route.', 404 );
	}

	if ( 'authorize' === $second && 'GET' === $method ) {
		$broker->authorize( $first, $_GET );
	}

	if ( 'POST' !== $method ) {
		Http::fail( 'method_not_allowed', 'That route expects a POST.', 405 );
	}

	match ( $second ) {
		'claim'   => $broker->claim( $first, $body() ),
		'refresh' => $broker->refresh( $first, $body() ),
		'revoke'  => $broker->revoke( $first, $body() ),
		default   => Http::fail( 'not_found', 'No such route.', 404 ),
	};
} catch ( \Throwable $e ) {
	error_log( 'mmoa-broker: ' . $e->getMessage() );
	Http::fail( 'server_error', 'The setup service failed unexpectedly. Existing connections keep sending; only connecting a new account is affected.', 500 );
}
