<?php
/**
 * Google, however you connect to it.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Broker;
use ModernMailer\Auth\One_Click;
use ModernMailer\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Gmail and Google Workspace as one choice.
 *
 * Three ways in, and which one is right is decided by the account rather than
 * by preference:
 *
 * - **One-click** signs in and sends as that mailbox, with nothing to configure
 *   in Google Cloud. Works for any account, consumer or Workspace.
 * - **Your own OAuth client** is the same sign-in against a Google Cloud
 *   project you registered. More setup, and it depends on nothing of ours.
 * - **Service account** uses domain-wide delegation. No consent screen and no
 *   refresh token to be revoked, which makes it the sturdiest of the three -
 *   but it is Workspace-only and needs a domain administrator to authorise it.
 *
 * The first two share a transport: Gmail_OAuth already decides internally
 * whether its refresh token came from the broker or from a client the site
 * registered, so both modes resolve to it and the setup mode is the same
 * setting it was already reading.
 */
class Google extends Abstract_Merged_Provider {

	/** Domain-wide delegation, as distinct from either sign-in path. */
	public const MODE_SERVICE_ACCOUNT = 'service_account';

	public static function slug(): string {
		return 'google';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Google', 'modern-mailer-oauth' ),
			'summary'  => __( 'Gmail and Google Workspace. Sign in to send as your own mailbox, or use a service account with domain-wide delegation, which has no consent screen and nothing that expires.', 'modern-mailer-oauth' ),
			'docs'     => 'https://developers.google.com/gmail/api/guides/sending',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	protected static function mode_key(): string {
		return 'google_setup_mode';
	}

	protected static function default_mode(): string {
		return One_Click::MODE_OWN_CLIENT;
	}

	/**
	 * Ordered so each transport's fields gate to the mode that uses them.
	 *
	 * Own-client comes first so the OAuth client ID and secret attach to it -
	 * they are meaningless in the other two modes. One-click resolves to the
	 * same transport but contributes no fields, having none left to claim.
	 */
	protected static function transports(): array {
		$out = [
			One_Click::MODE_OWN_CLIENT => Gmail_OAuth::class,
			self::MODE_SERVICE_ACCOUNT => Gmail_Service_Account::class,
		];

		if ( Broker::is_available() ) {
			$out[ One_Click::MODE_ONE_CLICK ] = Gmail_OAuth::class;
		}

		return $out;
	}

	protected static function mode_field(): Field {
		$options = [];

		if ( Broker::is_available() ) {
			$options[ One_Click::MODE_ONE_CLICK ] = __( 'One-click', 'modern-mailer-oauth' );
		}

		$options[ One_Click::MODE_OWN_CLIENT ] = __( 'My own OAuth client', 'modern-mailer-oauth' );
		$options[ self::MODE_SERVICE_ACCOUNT ] = __( 'Service account', 'modern-mailer-oauth' );

		return new Field(
			key: self::mode_key(),
			label: __( 'How to connect', 'modern-mailer-oauth' ),
			type: Field::RADIO,
			options: $options,
			default: self::default_mode(),

			// The copy follows what is actually on offer. Describing one-click
			// where it is not shown sends someone looking for a control that
			// does not exist.
			help: Broker::is_available()
				? __( 'One-click and your own OAuth client both sign in as a person and send as that mailbox. A service account needs no sign-in and holds no refresh token, but is Workspace-only and must be authorised by a domain administrator. Either way, mail goes directly from this site to Gmail.', 'modern-mailer-oauth' )
				: __( 'Signing in with your own OAuth client sends as that mailbox. A service account needs no sign-in and holds no refresh token, but is Workspace-only and must be authorised by a domain administrator. Either way, mail goes directly from this site to Gmail.', 'modern-mailer-oauth' )
		);
	}
}
