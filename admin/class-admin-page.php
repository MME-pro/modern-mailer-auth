<?php
/**
 * Settings screen.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Plugin;
use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The single settings screen, its form handlers, and the failure notice.
 */
class Admin_Page {

	private const SLUG       = 'modern-mailer-oauth';
	private const CAPABILITY = 'manage_options';
	private const NOTICE     = 'mmoa_notice';

	/**
	 * Credential fields: form name => Secrets key.
	 */
	private const SECRET_FIELDS = [
		'ms_client_secret'  => 'ms_client_secret',
		'google_sa_key'     => 'google_sa_key',
		'google_client_sec' => 'google_client_sec',
	];

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_mmoa_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_mmoa_verify', [ $this, 'handle_verify' ] );
		add_action( 'admin_post_mmoa_test_email', [ $this, 'handle_test_email' ] );
		add_action( 'admin_notices', [ $this, 'failure_notice' ] );
	}

	public function add_page(): void {
		add_options_page(
			__( 'Modern Mailer', 'modern-mailer-oauth' ),
			__( 'Modern Mailer', 'modern-mailer-oauth' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Persistent banner while sending is broken.
	 */
	public function failure_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->plugin->health->is_failing() ) {
			return;
		}

		$state = $this->plugin->health->state();

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Email is not being delivered.', 'modern-mailer-oauth' ),
			esc_html( (string) ( $state['last_error']['message'] ?? '' ) ),
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Review mail settings', 'modern-mailer-oauth' )
		);
	}

	public function handle_save(): void {
		$this->guard( 'mmoa_save' );

		$settings = $this->plugin->settings;
		$posted   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

		$values = [];

		foreach ( [ 'provider', 'from_email', 'from_name', 'ms_tenant_id', 'ms_client_id', 'ms_sender', 'google_sa_email', 'google_sender', 'google_client_id', 'log_retention', 'alert_threshold', 'alert_email' ] as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$values[ $key ] = $posted[ $key ];
			}
		}

		$values['force_from']    = ! empty( $posted['force_from'] );
		$values['log_enabled']   = ! empty( $posted['log_enabled'] );
		$values['ms_policy_ack'] = ! empty( $posted['ms_policy_ack'] );

		if ( isset( $posted['ms_secret_expires'] ) && '' !== $posted['ms_secret_expires'] ) {
			$values['ms_secret_expires'] = (int) strtotime( sanitize_text_field( (string) $posted['ms_secret_expires'] ) );
		}

		$settings->update( $values );

		// Credentials are only written when the field was actually filled in.
		// The form renders a masked placeholder rather than the stored value,
		// so an empty field means "leave it alone", never "clear it".
		foreach ( self::SECRET_FIELDS as $field => $key ) {
			if ( isset( $posted[ $field ] ) && '' !== trim( (string) $posted[ $field ] ) ) {
				$this->plugin->secrets->set( $key, trim( (string) $posted[ $field ] ) );
			}
		}

		// Credentials may have changed underneath a cached token.
		$this->plugin->tokens->flush();
		$this->plugin->health->reset();

		$this->redirect( 'saved', __( 'Settings saved.', 'modern-mailer-oauth' ) );
	}

	public function handle_verify(): void {
		$this->guard( 'mmoa_verify' );

		$provider = $this->plugin->dispatcher->provider();

		if ( null === $provider ) {
			$this->redirect( 'error', __( 'Choose a provider first.', 'modern-mailer-oauth' ) );
		}

		$result = $provider->verify_connection();

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		$this->redirect( 'saved', __( 'Connection verified. Credentials are valid and the mailbox is reachable.', 'modern-mailer-oauth' ) );
	}

	public function handle_test_email(): void {
		$this->guard( 'mmoa_test_email' );

		$to = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_email( $to ) ) {
			$this->redirect( 'error', __( 'Enter a valid recipient address.', 'modern-mailer-oauth' ) );
		}

		$captured = null;

		$capture = static function ( $error ) use ( &$captured ): void {
			$captured = $error;
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( 'Modern Mailer test from %s', 'modern-mailer-oauth' ),
				get_bloginfo( 'name' )
			),
			__( "This is a test message.\n\nIf you are reading it, the API connection is working.", 'modern-mailer-oauth' )
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			$this->redirect( 'saved', __( 'Test message accepted by the provider.', 'modern-mailer-oauth' ) );
		}

		$this->redirect(
			'error',
			$captured instanceof \WP_Error
				? $captured->get_error_message()
				: __( 'The test message could not be sent.', 'modern-mailer-oauth' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = $this->plugin->settings;
		$provider = (string) $settings->get( 'provider' );
		$entries  = $settings->get( 'log_enabled' ) ? $this->plugin->logger->recent( 25 ) : [];

		require __DIR__ . '/views/settings.php';
	}

	/**
	 * Render one text field, disabled when a constant pins it.
	 */
	public function field( string $key, string $label, string $help = '', string $type = 'text' ): void {
		$settings = $this->plugin->settings;
		$locked   = $settings->is_constant( $key );

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>' .
			'<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text" %5$s />',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $settings->get( $key ) ),
			$locked ? 'disabled="disabled"' : ''
		);

		if ( $locked ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Set in wp-config.php, so it cannot be edited here.', 'modern-mailer-oauth' )
			);
		} elseif ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Render one credential field. Never echoes the stored value back.
	 */
	public function secret_field( string $key, string $label, string $help = '', bool $textarea = false ): void {
		$secrets = $this->plugin->secrets;
		$locked  = $secrets->is_constant( $key );
		$has     = '' !== $secrets->get( $key );

		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $key ), esc_html( $label ) );

		if ( $locked ) {
			printf(
				'<p><code>%s</code></p><p class="description">%s</p></td></tr>',
				esc_html__( 'defined in wp-config.php', 'modern-mailer-oauth' ),
				esc_html__( 'This is the recommended place to keep it.', 'modern-mailer-oauth' )
			);

			return;
		}

		$placeholder = $has
			? __( 'Stored. Leave blank to keep it.', 'modern-mailer-oauth' )
			: __( 'Not set', 'modern-mailer-oauth' );

		if ( $textarea ) {
			printf(
				'<textarea id="%1$s" name="%1$s" rows="5" class="large-text code" placeholder="%2$s" autocomplete="off"></textarea>',
				esc_attr( $key ),
				esc_attr( $placeholder )
			);
		} else {
			printf(
				'<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" placeholder="%2$s" autocomplete="new-password" />',
				esc_attr( $key ),
				esc_attr( $placeholder )
			);
		}

		if ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}

		if ( ! $secrets->is_encryption_available() ) {
			printf(
				'<p class="description"><strong>%s</strong></p>',
				esc_html__( 'Libsodium is unavailable, so this will be stored unencrypted. Put it in wp-config.php instead.', 'modern-mailer-oauth' )
			);
		}

		echo '</td></tr>';
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'modern-mailer-oauth' ) );
		}

		check_admin_referer( $action );
	}

	private function redirect( string $type, string $message ): void {
		set_transient( self::NOTICE . '_' . get_current_user_id(), [ $type, $message ], 60 );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Pull and clear the one-shot notice set by a form handler.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public function take_notice(): ?array {
		$key    = self::NOTICE . '_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}
}
