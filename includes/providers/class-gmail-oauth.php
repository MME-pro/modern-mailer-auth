<?php
	/**
 * Gmail user-consent OAuth provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Broker;
use ModernMailer\Auth\One_Click;
use ModernMailer\Field;
use ModernMailer\Site_Identity;
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

	public static function slug(): string {
		return 'gmail_oauth';
	}

	/**
	 * Not listed in the chooser: reached through its merged tile instead.
	 *
	 * Still registered, so a connection storing this slug stays constructible -
	 * which matters both for sites that have not run the migration yet and for
	 * anything setting the slug directly through the mmoa_providers filter.
	 */
	public static function is_listed(): bool {
		return false;
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Gmail', 'modern-mailer-oauth' ),
			'summary'  => __( 'Consumer @gmail.com accounts, using your own OAuth client and a one-time sign-in. The only path here that holds a refresh token, and it can be revoked.', 'modern-mailer-oauth' ),
			'docs'     => 'https://developers.google.com/gmail/api/guides/sending',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		$fields = [];

		// Offered only where a broker exists to answer. A site that has
		// filtered it away gets the own-client form with no choice attached,
		// rather than a radio whose other option cannot work.
		if ( Broker::is_available() ) {
			$fields[] = new Field(
				key: 'google_setup_mode',
				label: __( 'Setup', 'modern-mailer-oauth' ),
				type: Field::RADIO,
				options: [
					One_Click::MODE_ONE_CLICK  => __( 'One-click', 'modern-mailer-oauth' ),
					One_Click::MODE_OWN_CLIENT => __( 'My own OAuth client', 'modern-mailer-oauth' ),
				],
				default: One_Click::MODE_OWN_CLIENT,
				help: __( 'One-click uses our setup service to obtain the credential, so there is nothing to configure in Google Cloud. Your mail is still sent directly from this site to Gmail - the service never sees a message.', 'modern-mailer-oauth' )
			);
		}

		// Required only in own-client mode. `depends` is what makes that true
		// in both directions: the form greys them out, and the required check
		// that guards verification stops demanding them - a field that cannot
		// apply must not be able to block a connection.
		$depends = Broker::is_available()
			? [ 'field' => 'google_setup_mode', 'value' => One_Click::MODE_OWN_CLIENT ]
			: [];

		$fields[] = new Field(
			key: 'google_client_id',
			label: __( 'OAuth client ID', 'modern-mailer-oauth' ),
			required: true,
			help: __( 'From Credentials in your own Google Cloud project. It must be a Web application client.', 'modern-mailer-oauth' ),
			depends: $depends
		);

		$fields[] = new Field(
			key: 'google_client_sec',
			label: __( 'OAuth client secret', 'modern-mailer-oauth' ),
			type: Field::PASSWORD,
			secret: true,
			required: true,
			depends: $depends
		);

		return $fields;
	}

	/**
	 * `me` resolves to whichever account granted the refresh token.
	 */
	protected function mailbox(): string {
		return 'me';
	}

	/**
	 * Whether this connection's credential comes from the setup service.
	 */
	private function is_brokered(): bool {
		return One_Click::MODE_ONE_CLICK === (string) $this->settings->get( 'google_setup_mode' )
			&& Broker::is_available();
	}

	protected function token_cache_key(): string {
		// The client ID is part of the key so that rotating a client retires
		// the cached token with it. A brokered connection has no client ID of
		// its own, so the mode stands in for one - which also means switching
		// a connection between modes cannot leave the previous mode's access
		// token in play.
		return 'gmail_oauth:' . md5(
			( $this->is_brokered() ? 'broker' : (string) $this->settings->get( 'google_client_id' ) ) . '|' .
			$this->settings->secrets()->get( 'google_refresh' )
		);
	}

	protected function request_token() {
		if ( $this->is_brokered() ) {
			// Same refresh token, same Gmail API, different party holding the
			// client secret. Everything downstream of here is unchanged.
			$broker = new Broker( $this->http, new Site_Identity() );

			return $broker->token_for( Broker::GOOGLE, $this->settings );
		}

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
