<?php
/**
 * Gmail user-consent OAuth provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends via Gmail using a refresh token obtained through user consent.
 *
 * This path exists for consumer @gmail.com accounts, which cannot use a
 * service account. It is the one place in the plugin where a long-lived
 * refresh token is unavoidable, so it inherits the failure mode the rest of
 * the design was built to eliminate: the token can be revoked, and when it is,
 * sending stops.
 *
 * We do not pretend otherwise. The setup screen warns about the Google Cloud
 * consent screen being left in Testing status - which silently expires refresh
 * tokens every seven days and is far and away the most common cause of "Gmail
 * worked for a week and then stopped" - and Health_Monitor makes sure a
 * revocation surfaces instead of accumulating quietly.
 */
class Gmail_OAuth extends Abstract_Gmail {

	public function get_label(): string {
		return __( 'Gmail (OAuth)', 'modern-mailer-oauth' );
	}

	/**
	 * `me` resolves to whichever account granted the refresh token.
	 */
	protected function mailbox(): string {
		return 'me';
	}

	protected function token_cache_key(): string {
		return 'gmail_oauth:' . md5(
			(string) $this->settings->get( 'google_client_id' ) . '|' .
			$this->settings->secrets()->get( 'google_refresh' )
		);
	}

	protected function request_token() {
		$client_id = trim( (string) $this->settings->get( 'google_client_id' ) );
		$secret    = $this->settings->secrets()->get( 'google_client_sec' );
		$refresh   = $this->settings->secrets()->get( 'google_refresh' );

		if ( '' === $client_id || '' === $secret ) {
			return new WP_Error(
				'mmoa_gmail_oauth_incomplete',
				__( 'The Google OAuth client ID or client secret is missing.', 'modern-mailer-oauth' )
			);
		}

		if ( '' === $refresh ) {
			return new WP_Error(
				'mmoa_gmail_not_connected',
				__( 'No Google account is connected. Use the Connect button on the settings screen.', 'modern-mailer-oauth' )
			);
		}

		$response = $this->http->request(
			self::TOKEN_URL,
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $client_id,
					'client_secret' => $secret,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response['body'] );

		if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
			return $this->map_error( $response['code'], $response['body'] );
		}

		return [
			'token'      => (string) $data['access_token'],
			'expires_in' => (int) ( $data['expires_in'] ?? 3600 ),
		];
	}
}
