<?php
/**
 * RS256 assertion signing.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Signs the JWT assertions Google's service-account flow requires.
 *
 * Implemented directly on ext-openssl rather than pulling in firebase/php-jwt.
 * Signing one RS256 token is about fifteen lines, and avoiding a bundled
 * vendor directory keeps the plugin's dependency surface at zero and its
 * wordpress.org review straightforward.
 */
class Jwt_Signer {

	public static function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public function is_available(): bool {
		return function_exists( 'openssl_sign' ) && function_exists( 'openssl_pkey_get_private' );
	}

	/**
	 * Produce a signed RS256 JWT.
	 *
	 * @param array<string,mixed> $claims      Payload.
	 * @param string              $private_key PEM-encoded RSA private key.
	 * @return string|WP_Error
	 */
	public function sign( array $claims, string $private_key ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'mmoa_no_openssl',
				__( 'The OpenSSL PHP extension is required to authenticate with a Google service account.', 'modern-mailer-oauth' )
			);
		}

		// Keys pasted out of the downloaded JSON arrive with literal \n.
		$private_key = str_replace( '\\n', "\n", trim( $private_key ) );
		$key         = openssl_pkey_get_private( $private_key );

		if ( false === $key ) {
			return new WP_Error(
				'mmoa_bad_key',
				__( 'The service account private key could not be read. Paste the whole private_key value from the downloaded JSON, including the BEGIN and END lines.', 'modern-mailer-oauth' )
			);
		}

		$header  = self::base64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$payload = self::base64url( (string) wp_json_encode( $claims ) );
		$signing = $header . '.' . $payload;
		$sig     = '';

		if ( ! openssl_sign( $signing, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error(
				'mmoa_sign_failed',
				__( 'The authentication assertion could not be signed.', 'modern-mailer-oauth' )
			);
		}

		return $signing . '.' . self::base64url( $sig );
	}
}
