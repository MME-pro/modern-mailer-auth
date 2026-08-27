<?php
/**
 * One-click connection flow, for Google and Microsoft.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Auth;

use ModernMailer\Connections;
use ModernMailer\Settings;
use ModernMailer\Token_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Connects a mailbox without anyone opening a cloud console.
 *
 * The manual path asks an admin to create a project, enable an API, configure a
 * consent screen, create a Web application client, register a redirect URI, and
 * copy two long strings back - and to get every one of them right, because the
 * errors are opaque when they do not. Most of the support load on any OAuth
 * mailer comes from that sequence.
 *
 * This replaces it with: choose the account, approve, done. The site never sees
 * an OAuth client secret because it never has one - the broker holds ours - and
 * what comes back is a real Google or Microsoft refresh token that the ordinary
 * provider code then uses exactly as though it had been obtained by hand.
 *
 * That last property is the design constraint worth keeping. One-click changes
 * where a token comes from and nothing else: sending, verification, routing,
 * queueing and health monitoring never learn that this mode exists, because the
 * credential they are handed is the same kind of credential either way.
 *
 * The own-client path stays, and stays the default for anyone who wants it.
 * A site that would rather not depend on a service we run should not have to.
 */
class One_Click {

	/** The admin-post action the broker returns the browser to. */
	public const CALLBACK_ACTION = 'mmoa_one_click_callback';

	/** Long enough for a person to read a consent screen and decide. */
	private const STATE_TTL = 900;

	/** The value of a `*_setup_mode` setting that means "use the broker". */
	public const MODE_ONE_CLICK = 'one_click';

	/** The value that means "this site has its own OAuth client". */
	public const MODE_OWN_CLIENT = 'own_client';

	public function __construct(
		private Settings $settings,
		private Broker $broker,
		private Connections $connections,
		private Token_Store $tokens
	) {}

	/**
	 * Where the browser is sent to begin.
	 *
	 * @return string|WP_Error
	 */
	public function authorization_url( string $family, string $slot ) {
		if ( ! Broker::is_family( $family ) ) {
			return new WP_Error( 'mmoa_one_click_unknown_family', __( 'Unknown provider.', 'modern-mailer-oauth' ) );
		}

		if ( ! Broker::is_available() ) {
			return new WP_Error(
				'mmoa_broker_disabled',
				__( 'One-click setup is switched off on this site. Connect using your own OAuth client instead.', 'modern-mailer-oauth' )
			);
		}

		$state = wp_generate_password( 40, false, false );

		set_transient(
			$this->state_key( $state ),
			[
				'family' => $family,
				'slot'   => $slot,
				'user'   => get_current_user_id(),
			],
			self::STATE_TTL
		);

		return $this->broker->authorize_url( $family, $state, self::callback_url() );
	}

	/**
	 * Where the broker returns the browser once the provider has answered.
	 *
	 * Points at admin-post.php rather than at an admin screen, for the same
	 * reason the own-client flow does: it does not move when the menu is
	 * reorganised, and it is one value for every connection on the site.
	 */
	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Finish the flow: verify state, claim the tokens, store them.
	 *
	 * @param array<string,mixed> $request Raw query parameters.
	 * @return array{family:string,slot:string,account:string}|WP_Error
	 */
	public function handle_callback( array $request ) {
		$state = isset( $request['state'] ) ? sanitize_text_field( (string) $request['state'] ) : '';
		$saved = '' === $state ? false : get_transient( $this->state_key( $state ) );

		// One-shot, deleted before any work happens, so a replayed callback
		// cannot be reused even if what follows fails.
		if ( '' !== $state ) {
			delete_transient( $this->state_key( $state ) );
		}

		if ( ! is_array( $saved ) ) {
			return new WP_Error(
				'mmoa_one_click_bad_state',
				__( 'This setup link has expired or did not originate here. Start the connection again.', 'modern-mailer-oauth' )
			);
		}

		if ( (int) $saved['user'] !== get_current_user_id() ) {
			return new WP_Error(
				'mmoa_one_click_wrong_user',
				__( 'This setup was started by a different user account.', 'modern-mailer-oauth' )
			);
		}

		if ( ! empty( $request['error'] ) ) {
			// The broker's own wording carries what the provider said, which is
			// more use than anything invented here.
			$detail = trim( (string) ( $request['error_description'] ?? $request['error'] ) );

			return new WP_Error(
				'mmoa_one_click_denied',
				sprintf(
					/* translators: %s: reason reported by the setup service. */
					__( 'The account was not connected: %s', 'modern-mailer-oauth' ),
					sanitize_text_field( $detail )
				)
			);
		}

		$family  = (string) $saved['family'];
		$handoff = isset( $request['handoff'] ) ? sanitize_text_field( (string) $request['handoff'] ) : '';

		if ( ! Broker::is_family( $family ) || '' === $handoff ) {
			return new WP_Error(
				'mmoa_one_click_no_handoff',
				__( 'The setup service did not return a usable result. Start the connection again.', 'modern-mailer-oauth' )
			);
		}

		// Resolved, not assumed: the connection may have been deleted while the
		// admin was away approving, and writing the grant to whatever slot
		// happens to be left would silently overwrite an unrelated connection.
		$slot = $this->connections->slot_for( (string) $saved['slot'] );

		if ( null === $slot ) {
			return new WP_Error(
				'mmoa_one_click_gone',
				__( 'That connection no longer exists, so the sign-in could not be saved. Start again from the connection you want to use.', 'modern-mailer-oauth' )
			);
		}

		$tokens = $this->broker->claim( $family, $handoff );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$keys   = Broker::keys( $family );
		$scoped = $this->settings->for_slot( $slot );

		$scoped->secrets()->set( $keys['refresh'], $tokens['refresh_token'] );
		$scoped->update(
			[
				$keys['mode']    => self::MODE_ONE_CLICK,
				$keys['account'] => $tokens['email'],
			]
		);

		Settings::flush_cache();

		// The access token that came with the grant is good for the best part
		// of an hour. Banking it means the first message after setup sends
		// without a refresh round trip - and, more usefully, means a broker
		// that goes down a minute later does not make the connection look
		// broken the moment someone tests it.
		if ( '' !== $tokens['access_token'] ) {
			$this->tokens->put(
				$this->token_cache_key( $family, $slot ),
				$tokens['access_token'],
				$tokens['expires_in']
			);
		}

		return [
			'family'  => $family,
			'slot'    => $slot,
			'account' => $tokens['email'],
		];
	}

	/**
	 * Mint a fresh access token for a brokered connection.
	 *
	 * Called by the providers in place of their own refresh request when the
	 * connection is in one-click mode.
	 *
	 * @return array{token:string,expires_in:int}|WP_Error
	 */
	public function access_token( string $family, string $slot ) {
		if ( ! Broker::is_family( $family ) ) {
			return new WP_Error( 'mmoa_one_click_unknown_family', __( 'Unknown provider.', 'modern-mailer-oauth' ) );
		}

		return $this->broker->token_for( $family, $this->settings->for_slot( $slot ) );
	}

	/**
	 * Whether this connection holds a brokered grant.
	 */
	public function is_connected( string $family, string $slot ): bool {
		return Broker::is_family( $family )
			&& '' !== $this->settings->for_slot( $slot )->secrets()->get( Broker::keys( $family )['refresh'] );
	}

	/**
	 * Whether this connection is set to use the broker.
	 */
	public function is_one_click( string $family, string $slot ): bool {
		return Broker::is_family( $family )
			&& self::MODE_ONE_CLICK === (string) $this->settings->for_slot( $slot )->get( Broker::keys( $family )['mode'] );
	}

	/**
	 * The account this connection is signed in as, if it knows.
	 */
	public function account( string $family, string $slot ): string {
		return Broker::is_family( $family )
			? (string) $this->settings->for_slot( $slot )->get( Broker::keys( $family )['account'] )
			: '';
	}

	/**
	 * Revoke the grant at the broker and forget it here.
	 *
	 * @return true|WP_Error
	 */
	public function disconnect( string $family, string $slot ) {
		if ( ! Broker::is_family( $family ) ) {
			return new WP_Error( 'mmoa_one_click_unknown_family', __( 'Unknown provider.', 'modern-mailer-oauth' ) );
		}

		$keys    = Broker::keys( $family );
		$scoped  = $this->settings->for_slot( $slot );
		$refresh = $scoped->secrets()->get( $keys['refresh'] );

		// Forget locally first, and unconditionally. If the broker is
		// unreachable, an admin who asked to disconnect must still end up
		// disconnected - leaving a credential in place because a remote call
		// failed is the one outcome nobody asks for.
		$scoped->secrets()->set( $keys['refresh'], '' );
		$scoped->update( [ $keys['account'] => '' ] );
		Settings::flush_cache();

		$this->tokens->forget( $this->token_cache_key( $family, $slot ) );

		if ( '' === $refresh ) {
			return true;
		}

		return $this->broker->revoke( $family, $refresh );
	}

	/**
	 * Cache key for a brokered access token.
	 *
	 * Keyed by the refresh token, so replacing the grant retires the cached
	 * access token with it rather than leaving a token from the old account in
	 * place until it expires.
	 */
	public function token_cache_key( string $family, string $slot ): string {
		$refresh = Broker::is_family( $family )
			? $this->settings->for_slot( $slot )->secrets()->get( Broker::keys( $family )['refresh'] )
			: '';

		return 'one_click:' . $family . ':' . md5( $slot . '|' . $refresh );
	}

	private function state_key( string $state ): string {
		return 'mmoa_oc_' . md5( $state );
	}
}
