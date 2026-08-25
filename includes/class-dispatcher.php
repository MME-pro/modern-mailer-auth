<?php
/**
 * Routes a built message to the configured provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a connection, enforces limits, sends, and records the outcome.
 *
 * Three things stand between a transient fault and a lost email, in the order
 * they are tried:
 *
 * 1. Http retries the request a few times inside this page load. Good for a
 *    dropped packet or a brief throttle.
 * 2. If the primary connection still fails, the backup connection is tried.
 *    Separate credentials and a separate endpoint, so it survives faults that
 *    are permanent for the primary.
 * 3. If that fails too and the failure looks transient, the message goes on the
 *    queue and is retried across later requests over the following hours.
 *
 * The escalation only happens when it can help, which is what Failure decides.
 * An oversized attachment is not retried anywhere, because no amount of
 * patience or alternative credentials will make it fit.
 */
class Dispatcher {

	/** @var array<string,?Provider_Interface> Slot => provider, once built. */
	private array $providers = [];

	/**
	 * Set while a queue drain is running, so a retry cannot re-enqueue itself.
	 */
	private bool $draining = false;

	public function __construct(
		private Settings $settings,
		private Token_Store $tokens,
		private Http $http,
		private Logger $logger,
		private Health_Monitor $health,
		private Queue $queue,
		private Router $router
	) {}

	/**
	 * The provider for a connection slot, or null if that slot is unconfigured.
	 */
	public function provider( string $slot = Settings::SLOT_PRIMARY ): ?Provider_Interface {
		// Deliberately isset() rather than array_key_exists(): a null result
		// must not be memoized. An unconfigured slot asked about early in the
		// request - by Site Health, by an admin screen - would otherwise cache
		// "no provider" and keep answering that after the settings are saved,
		// which reads as the plugin silently refusing to send.
		if ( isset( $this->providers[ $slot ] ) ) {
			return $this->providers[ $slot ];
		}

		$scoped = $this->settings->for_slot( $slot );
		$class  = Provider_Registry::class_for( (string) $scoped->get( 'provider' ) );

		// Providers receive the slot-scoped settings and are otherwise
		// identical, which is why adding a backup connection needed no changes
		// inside any provider.
		$this->providers[ $slot ] = null === $class
			? null
			: new $class( $scoped, $this->tokens, $this->http );

		return $this->providers[ $slot ];
	}

	/**
	 * Discard the memoized providers.
	 *
	 * Providers capture their credentials when constructed, so anything that
	 * changes settings mid-request - saving the form, a test suite reconfiguring
	 * the site - has to invalidate them or the next send uses the old ones.
	 */
	public function reset_providers(): void {
		$this->providers = [];
	}

	/**
	 * Send a built MIME message, escalating through backup and queue.
	 *
	 * @return true|WP_Error
	 */
	public function dispatch( string $raw_mime, PHPMailer $mailer, ?string $forced_slot = null ) {
		// Routing chooses the preferred path; it does not opt the message out of
		// anything below. A routed send that fails still falls through to the
		// backup and then to the queue, exactly as an unrouted one does.
		//
		// $forced_slot is set when a message comes off the queue, where the
		// choice was already made and recorded. Re-routing on retry would be
		// wrong: the rules may have changed since, and a message half-delivered
		// through one connection should not silently move to another.
		$slot = $forced_slot ?? $this->route( $raw_mime, $mailer );

		$result = $this->attempt( $slot, $raw_mime, $mailer );

		if ( true === $result ) {
			$this->health->record_success();

			return true;
		}

		// A routed message falls back to the backup like any other, unless the
		// backup is the connection that just failed.
		if (
			Settings::SLOT_BACKUP !== $slot
			&& Failure::should_try_backup( $result )
			&& null !== $this->provider( Settings::SLOT_BACKUP )
		) {
			$primary_error = $result;
			$result        = $this->attempt( Settings::SLOT_BACKUP, $raw_mime, $mailer );

			if ( true === $result ) {
				/**
				 * Fires when the backup connection delivered what the primary
				 * could not.
				 *
				 * Sending is working, but on the fallback path - worth knowing
				 * before the backup is the only thing left.
				 *
				 * @param WP_Error $primary_error Why the primary failed.
				 */
				do_action( 'mmoa_backup_used', $primary_error );

				$this->health->record_success();

				return true;
			}
		}

		// Sending genuinely failed, whatever happens to the message next. The
		// admin needs to know that even if the queue later delivers it, because
		// a queue quietly absorbing every send is exactly the silent breakage
		// this plugin exists to prevent.
		$this->health->record_failure( $result );

		// Last resort: keep the message so a later request can try again. Only
		// worth doing for a failure that could plausibly resolve on its own.
		if ( ! $this->draining && Failure::is_retryable( $result ) ) {
			$queued = $this->queue->enqueue(
				$raw_mime,
				$this->recipients( $mailer ),
				(string) $mailer->Subject,
				$result,
				$slot
			);

			if ( $queued ) {
				// Reported as success to the caller, because from wp_mail()'s
				// point of view the message has been accepted for delivery and
				// no longer needs the caller to do anything. Returning false
				// here would make correctly-written plugins show the user an
				// error for mail that is about to arrive.
				return true;
			}
		}

		return $result;
	}

	/**
	 * Send a message that came off the queue.
	 *
	 * Same path, minus the re-enqueue: the row already exists and the queue is
	 * managing its own attempt count and backoff.
	 *
	 * @return true|WP_Error
	 */
	public function dispatch_raw( string $raw_mime, PHPMailer $mailer, ?string $slot = null ) {
		$this->draining = true;

		try {
			return $this->dispatch( $raw_mime, $mailer, $slot ?? Settings::SLOT_PRIMARY );
		} finally {
			$this->draining = false;
		}
	}

	/**
	 * Ask the router which connection this message prefers.
	 *
	 * Building a Message is not free, so it happens only when routing is
	 * actually switched on - on a site that never enables it this costs one
	 * boolean read per send.
	 */
	private function route( string $raw_mime, PHPMailer $mailer ): string {
		if ( ! $this->router->is_enabled() ) {
			return Settings::SLOT_PRIMARY;
		}

		$slot = $this->router->route( Message::from_mailer( $raw_mime, $mailer ) );

		// A rule pointing at a connection with no provider would fail every
		// message it matched. Falling back to the primary keeps a half-finished
		// rule from taking the site's mail down with it.
		if ( null !== $slot && null !== $this->provider( $slot ) ) {
			return $slot;
		}

		return Settings::SLOT_PRIMARY;
	}

	/**
	 * One attempt against one connection, logged and recorded.
	 *
	 * @return true|WP_Error
	 */
	private function attempt( string $slot, string $raw_mime, PHPMailer $mailer ) {
		$provider = $this->provider( $slot );

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

		// Every attempt is logged, including one the backup went on to rescue -
		// the log is the record of what actually happened on the wire. Health
		// is decided once, by dispatch(), from the final outcome.
		$this->logger->record( $provider, $mailer, $bytes, $result );

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function recipients( PHPMailer $mailer ): array {
		return array_map(
			static fn( array $addr ): string => $addr[0],
			array_merge( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() )
		);
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
