<?php
/**
 * Routes a built message to the configured provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Gmail_OAuth;
use ModernMailer\Providers\Gmail_Service_Account;
use ModernMailer\Providers\Graph;
use ModernMailer\Providers\Provider_Interface;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a provider, enforces limits, sends, and records the outcome.
 */
class Dispatcher {

	private ?Provider_Interface $provider = null;

	public function __construct(
		private Settings $settings,
		private Token_Store $tokens,
		private Http $http,
		private Logger $logger,
		private Health_Monitor $health
	) {}

	/**
	 * Build the provider for the current settings, or null if unconfigured.
	 */
	public function provider(): ?Provider_Interface {
		if ( null !== $this->provider ) {
			return $this->provider;
		}

		$class = [
			Settings::PROVIDER_GRAPH       => Graph::class,
			Settings::PROVIDER_GMAIL_SA    => Gmail_Service_Account::class,
			Settings::PROVIDER_GMAIL_OAUTH => Gmail_OAuth::class,
		][ $this->settings->get( 'provider' ) ] ?? null;

		if ( null === $class ) {
			return null;
		}

		$this->provider = new $class( $this->settings, $this->tokens, $this->http );

		return $this->provider;
	}

	/**
	 * Send a built MIME message.
	 *
	 * @return true|WP_Error
	 */
	public function dispatch( string $raw_mime, PHPMailer $mailer ) {
		$provider = $this->provider();

		if ( null === $provider ) {
			return new WP_Error(
				'mmoa_no_provider',
				__( 'No mail provider is configured.', 'modern-mailer-oauth' )
			);
		}

		$bytes  = strlen( $raw_mime );
		$result = $this->check_size( $provider, $bytes );

		if ( true === $result ) {
			/**
			 * Fires immediately before a message is handed to a provider.
			 *
			 * @param string             $raw_mime Complete RFC 822 message.
			 * @param Provider_Interface $provider Active provider.
			 */
			do_action( 'mmoa_before_send', $raw_mime, $provider );

			$result = $provider->send( $raw_mime, $mailer );
		}

		$this->logger->record( $provider, $mailer, $bytes, $result );

		if ( is_wp_error( $result ) ) {
			$this->health->record_failure( $result );
		} else {
			$this->health->record_success();
		}

		return $result;
	}

	/**
	 * Reject an oversized message before it hits the wire.
	 *
	 * Catching this locally means the admin gets a message naming the actual
	 * limit, rather than a generic API rejection that gives them nothing to
	 * act on.
	 *
	 * @return true|WP_Error
	 */
	private function check_size( Provider_Interface $provider, int $bytes ) {
		$max = $provider->get_max_message_bytes();

		if ( $bytes <= $max ) {
			return true;
		}

		return new WP_Error(
			'mmoa_message_too_large',
			sprintf(
				/* translators: 1: message size, 2: maximum size, 3: provider name. */
				__( 'The message is %1$s, which is over the %2$s that %3$s accepts in one request. Attachments are encoded twice on this path, so the usable attachment size is roughly half the limit.', 'modern-mailer-oauth' ),
				size_format( $bytes, 1 ),
				size_format( $max, 1 ),
				$provider->get_label()
			)
		);
	}
}
