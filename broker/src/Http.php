<?php
/**
 * Outbound requests, and replies.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

final class Http {

	/**
	 * POST a form-encoded body and decode a JSON reply.
	 *
	 * Both token endpoints want application/x-www-form-urlencoded, not JSON,
	 * which is the single most common thing to get wrong here.
	 *
	 * @param array<string,string> $fields
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function post_form( string $url, array $fields ): array {
		return self::send( $url, [
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query( $fields ),
			CURLOPT_HTTPHEADER => [ 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json' ],
		] );
	}

	/**
	 * GET with a bearer token.
	 *
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function get_authorized( string $url, string $token ): array {
		return self::send( $url, [
			CURLOPT_HTTPHEADER => [ 'Authorization: Bearer ' . $token, 'Accept: application/json' ],
		] );
	}

	/**
	 * @param array<int,mixed> $options
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function send( string $url, array $options ): array {
		$handle = curl_init( $url );

		curl_setopt_array(
			$handle,
			$options + [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_CONNECTTIMEOUT => 10,

				// Explicit rather than relied upon. A build with these off
				// would make every exchange interceptable, and the failure
				// would be silent.
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
			]
		);

		$raw    = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $handle );

		curl_close( $handle );

		if ( false === $raw ) {
			return [ 'status' => 0, 'body' => [ 'error' => 'transport', 'message' => $error ] ];
		}

		$decoded = json_decode( (string) $raw, true );

		return [ 'status' => $status, 'body' => is_array( $decoded ) ? $decoded : [] ];
	}

	/**
	 * Reply with JSON and stop.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function json( array $payload, int $status = 200 ): never {
		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Reply with an error in the shape the plugin reads.
	 *
	 * The plugin shows `message` to an administrator verbatim when it is
	 * present, so these are written for a person rather than for a log: say
	 * what they must do, or say plainly that the fault is ours.
	 */
	public static function fail( string $code, string $message, int $status = 400 ): never {
		self::json( [ 'error' => $code, 'message' => $message ], $status );
	}

	/**
	 * Send the browser somewhere and stop.
	 *
	 * @param array<string,string> $params
	 */
	public static function redirect( string $url, array $params = [] ): never {
		if ( $params ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
		}

		header( 'Location: ' . $url, true, 302 );
		exit;
	}
}
