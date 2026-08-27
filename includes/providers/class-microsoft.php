<?php
/**
 * Microsoft, however you connect to it.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Broker;
use ModernMailer\Auth\One_Click;
use ModernMailer\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft 365 and Outlook as one choice.
 *
 * The two ways in are genuinely different, and the difference matters enough to
 * explain on the form rather than bury in two tiles that looked alike:
 *
 * - **One-click** signs in as a person and sends only as that mailbox. It needs
 *   nothing registered in Azure and works for personal Outlook accounts with no
 *   tenant at all, but it holds a refresh token, which can be revoked.
 * - **Your own Azure app** is app-only. An administrator registers an
 *   application and grants Mail.Send, and the site mints tokens from a client
 *   credential. Nothing expires but the secret, and it can send as any mailbox
 *   in the tenant - which is what a business sending from a shared address
 *   wants, and which needs somebody with the authority to consent.
 *
 * Neither is a lesser version of the other, so the form presents them as a
 * choice about circumstances rather than a recommendation.
 */
class Microsoft extends Abstract_Merged_Provider {

	public static function slug(): string {
		return 'microsoft';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Microsoft', 'modern-mailer-oauth' ),
			'summary'  => __( 'Microsoft 365 and Outlook. Sign in to send as your own mailbox, or register an Azure application to send as any address in the tenant with nothing that expires but the secret.', 'modern-mailer-oauth' ),
			'docs'     => 'https://learn.microsoft.com/graph/auth-v2-service',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	protected static function mode_key(): string {
		return 'ms_setup_mode';
	}

	protected static function default_mode(): string {
		return One_Click::MODE_OWN_CLIENT;
	}

	/**
	 * Ordered so each transport's fields gate to the mode that uses them:
	 * the Azure credentials belong to the own-app mode, and the one-click
	 * transport declares none.
	 */
	protected static function transports(): array {
		$out = [ One_Click::MODE_OWN_CLIENT => Graph::class ];

		// Offered only where a broker exists to answer. Without one the
		// one-click transport could never obtain a credential, and a mode that
		// cannot work must not be selectable.
		if ( Broker::is_available() ) {
			$out[ One_Click::MODE_ONE_CLICK ] = Outlook::class;
		}

		return $out;
	}

	protected static function mode_field(): Field {
		$options = [];

		if ( Broker::is_available() ) {
			$options[ One_Click::MODE_ONE_CLICK ] = __( 'One-click', 'modern-mailer-oauth' );
		}

		$options[ One_Click::MODE_OWN_CLIENT ] = __( 'My own Azure app', 'modern-mailer-oauth' );

		return new Field(
			key: self::mode_key(),
			label: __( 'How to connect', 'modern-mailer-oauth' ),
			type: Field::RADIO,
			options: $options,
			default: self::default_mode(),
			help: __( 'One-click signs in as a person and sends as that mailbox, with nothing to register in Azure. Your own Azure app sends as any address in the tenant and has no refresh token to be revoked, but needs an administrator to register it. Either way, mail goes directly from this site to Microsoft.', 'modern-mailer-oauth' )
		);
	}
}
