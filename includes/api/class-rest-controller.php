<?php
/**
 * REST API backing the admin app.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Api;

use ModernMailer\Auth\Broker;
use ModernMailer\Failure;
use ModernMailer\Plugin;
use ModernMailer\Provider_Registry;
use ModernMailer\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the admin screens read and write.
 *
 * The admin app is a single page that talks to these routes, which means this
 * class is the whole contract between the two halves. Two consequences worth
 * stating, because they are why several things below look the way they do.
 *
 * Nothing here trusts the client about which fields exist. Every write is
 * filtered through the provider's declared schema, so a request naming a key no
 * provider asked for is dropped rather than stored - the front end cannot widen
 * what is persisted by posting extra keys.
 *
 * Credentials are sent to the browser, which they were not at first, and the
 * change is worth explaining. Withholding them left an administrator unable to
 * check what had been saved: a key pasted with a truncated tail looks exactly
 * like a correct one, and the only way to find out was to send a message and
 * read the error. The field now shows the stored value masked, with an eye to
 * reveal it - which requires the value to be here.
 *
 * The cost is that anything able to read this screen can read the credential.
 * These routes already require manage_options, and that capability can install
 * a plugin and take the value regardless, so the exposure is narrower than it
 * first appears - but it is real, and it is the reason the screen is the only
 * place this happens.
 */
class Rest_Controller {

	public const NAMESPACE = 'modern-mailer/v1';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$auth = [ $this, 'can_manage' ];

		register_rest_route(
			self::NAMESPACE,
			'/bootstrap',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_bootstrap' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<slot>[A-Za-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_connection' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_connection' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<slot>[A-Za-z0-9_-]+)/verify',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'verify_connection' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/test-email',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'send_test' ],
				'permission_callback' => $auth,
				'args'                => [
					'to' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_logs' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/(?P<id>[0-9]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_log_entry' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/(?P<action>drain|requeue|purge)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'queue_action' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_connections' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_connection' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<id>[A-Za-z0-9_-]+)/manage',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'rename_connection' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_connection' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/routing',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_routing' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_routing' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dashboard' ],
				'permission_callback' => $auth,
			]
		);
	}

	/**
	 * These routes expose credentials-adjacent configuration and can send mail,
	 * so they are held to the same bar as the settings screen itself.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * One request the app can start from, so the first paint needs no waterfall.
	 */
	public function get_bootstrap(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'settings'    => $this->settings_payload(),
				'connections' => [
					'primary' => $this->connection_payload( Settings::SLOT_PRIMARY ),
					'backup'  => $this->connection_payload( Settings::SLOT_BACKUP ),
				],
				'health'      => $this->health_payload(),
				'queue'       => $this->plugin->queue->stats(),
				'categories'  => Provider_Registry::CATEGORIES,
				'routing'     => $this->routing_payload(),
				'catalogue'   => $this->connections_payload(),
			]
		);
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings_payload() );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$body   = (array) $request->get_json_params();
		$allow  = [ 'from_email', 'from_name', 'force_from', 'log_enabled', 'log_retention', 'alert_threshold', 'alert_email', 'queue_enabled' ];
		$values = array_intersect_key( $body, array_flip( $allow ) );

		$this->plugin->settings->update( $values );
		$this->plugin->dispatcher->reset_providers();

		return new WP_REST_Response( $this->settings_payload() );
	}

	public function get_connection( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->connection_payload( $this->slot( $request ) ) );
	}

	public function update_connection( WP_REST_Request $request ): WP_REST_Response {
		$slot     = $this->slot( $request );
		$body     = (array) $request->get_json_params();
		$settings = $this->plugin->settings->for_slot( $slot );
		$secrets  = $settings->secrets();

		// The provider being saved decides which fields are legitimate. Anything
		// else in the request is ignored rather than stored.
		$provider = isset( $body['provider'] ) ? (string) $body['provider'] : (string) $settings->get( 'provider' );
		$provider = Provider_Registry::exists( $provider ) ? $provider : '';

		$values = [ 'provider' => $provider ];
		$class  = Provider_Registry::class_for( $provider );

		if ( null !== $class ) {
			foreach ( $class::fields() as $field ) {
				if ( ! array_key_exists( $field->key, $body ) ) {
					continue;
				}

				$value = $body[ $field->key ];

				if ( ! $field->secret ) {
					$values[ $field->key ] = $value;
					continue;
				}

				// An empty secret means "leave it alone". The form never
				// receives the stored value, so a blank field is the absence of
				// an edit, never an instruction to clear it - there is an
				// explicit clear action for that.
				if ( '' !== trim( (string) $value ) ) {
					$secrets->set( $field->key, trim( (string) $value ) );
				}
			}
		}

		$settings->update( $values );

		// Credentials may have moved underneath a cached token, and any provider
		// built earlier in this request captured the old ones.
		$this->plugin->tokens->flush();
		$this->plugin->dispatcher->reset_providers();

		return new WP_REST_Response( $this->connection_payload( $slot ) );
	}

	public function verify_connection( WP_REST_Request $request ): WP_REST_Response {
		$slot     = $this->slot( $request );
		$provider = $this->plugin->dispatcher->provider( $slot );

		if ( null === $provider ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => __( 'Choose a provider for this connection first.', 'modern-mailer-oauth' ),
				]
			);
		}

		$result = $provider->verify_connection();

		// A string is a pass with a caveat - verified, but some part of the
		// check needed a permission the transport does not need to send, so it
		// was skipped rather than failed. The caveat is the message.
		return new WP_REST_Response(
			[
				'ok'      => true === $result || is_string( $result ),
				'message' => is_wp_error( $result )
					? $result->get_error_message()
					: ( is_string( $result )
						? $result
						: __( 'Verified. The credentials are valid and the mailbox is reachable.', 'modern-mailer-oauth' ) ),
				'code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
			]
		);
	}

	public function send_test( WP_REST_Request $request ): WP_REST_Response {
		$to = (string) $request->get_param( 'to' );

		if ( ! is_email( $to ) ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => __( 'Enter a valid recipient address.', 'modern-mailer-oauth' ),
				]
			);
		}

		$captured = null;
		$capture  = static function ( $error ) use ( &$captured ): void {
			$captured = $error;
		};

		add_action( 'wp_mail_failed', $capture );

		// Sent with the safety nets off: no routing, no backup, no queue. A
		// test exists to say whether the primary connection works, and every
		// one of those would let it answer yes when the primary had failed -
		// which is the very situation somebody presses this button to find out
		// about.
		$sent = $this->plugin->dispatcher->without_fallbacks(
			fn() => wp_mail(
				$to,
				sprintf(
					/* translators: %s: site name. */
					__( 'Modern Mailer test from %s', 'modern-mailer-oauth' ),
					get_bloginfo( 'name' )
				),
				__( "This is a test message.\n\nIf you are reading it, the connection is working.", 'modern-mailer-oauth' )
			)
		);

		remove_action( 'wp_mail_failed', $capture );

		return new WP_REST_Response(
			[
				'ok'      => (bool) $sent,
				'message' => $sent
					? __( 'Accepted for delivery. If it does not arrive, check the log for what the provider said.', 'modern-mailer-oauth' )
					: ( $captured instanceof WP_Error ? $captured->get_error_message() : __( 'The test message could not be sent.', 'modern-mailer-oauth' ) ),
			]
		);
	}

	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		return new WP_REST_Response(
			[
				'entries' => array_map( [ $this, 'log_row' ], $this->plugin->logger->recent( $limit ) ),
				'enabled' => (bool) $this->plugin->settings->get( 'log_enabled' ),
			]
		);
	}

	/**
	 * One log entry with its diagnostic report.
	 *
	 * Its own route rather than a field on the list, because the report runs
	 * to kilobytes - a page of fifty failures would carry a megabyte of
	 * transcript nobody has asked to read yet.
	 */
	public function get_log_entry( WP_REST_Request $request ): WP_REST_Response {
		$row = $this->plugin->logger->entry( (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return new WP_REST_Response( [ 'message' => __( 'No such log entry.', 'modern-mailer-oauth' ) ], 404 );
		}

		$report = json_decode( (string) ( $row->diagnostics ?? '' ), true );

		return new WP_REST_Response(
			array_merge(
				$this->log_row( $row ),
				[
					'code'        => (string) $row->error_code,

					// Null rather than an empty shape when there is none: a
					// successful send has nothing to report, and the modal says
					// so instead of drawing empty sections.
					'diagnostics' => is_array( $report ) ? $report : null,
				]
			)
		);
	}

	public function get_queue(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'stats'   => $this->plugin->queue->stats(),
				'enabled' => (bool) $this->plugin->settings->get( 'queue_enabled' ),
				'entries' => array_map(
					static fn( object $row ): array => [
						'id'         => (int) $row->id,
						'created_at' => (string) $row->created_at,
						'next'       => (string) $row->next_attempt_at,
						'attempts'   => (int) $row->attempts,
						'status'     => (string) $row->status,
						'recipients' => (string) $row->recipients,
						'subject'    => (string) $row->subject,
						'bytes'      => (int) $row->bytes,
						'error'      => (string) $row->error_message,
					],
					$this->plugin->queue->recent( 50 )
				),
			]
		);
	}

	public function queue_action( WP_REST_Request $request ): WP_REST_Response {
		$action = (string) $request->get_param( 'action' );
		$queue  = $this->plugin->queue;

		switch ( $action ) {
			case 'drain':
				$queue->reschedule_all();
				$stats = $queue->drain( $this->plugin->dispatcher );

				return new WP_REST_Response(
					[
						'ok'      => true,
						'stats'   => $stats,
						'message' => sprintf(
							/* translators: 1: attempted, 2: delivered, 3: still queued, 4: abandoned. */
							__( 'Attempted %1$d: %2$d delivered, %3$d still queued, %4$d abandoned.', 'modern-mailer-oauth' ),
							$stats['attempted'],
							$stats['sent'],
							$stats['failed'],
							$stats['exhausted']
						),
					]
				);

			case 'requeue':
				$count = $queue->requeue_failed();

				return new WP_REST_Response(
					[
						'ok'      => true,
						'message' => sprintf(
							/* translators: %d: number of messages returned to the queue. */
							_n( '%d abandoned message returned to the queue.', '%d abandoned messages returned to the queue.', $count, 'modern-mailer-oauth' ),
							$count
						),
					]
				);

			default:
				$queue->purge();

				return new WP_REST_Response(
					[
						'ok'      => true,
						'message' => __( 'Queue emptied. Anything it held is gone.', 'modern-mailer-oauth' ),
					]
				);
		}
	}

	public function list_connections(): WP_REST_Response {
		return new WP_REST_Response( $this->connections_payload() );
	}

	public function add_connection( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();
		$id   = $this->plugin->connections->add( (string) ( $body['name'] ?? '' ) );

		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => $id->get_error_message(),
				],
				400
			);
		}

		return new WP_REST_Response(
			array_merge( [ 'ok' => true, 'id' => $id ], $this->connections_payload() )
		);
	}

	public function rename_connection( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();
		$done = $this->plugin->connections->rename(
			(string) $request->get_param( 'id' ),
			(string) ( $body['name'] ?? '' )
		);

		return new WP_REST_Response(
			array_merge(
				[
					'ok'      => $done,
					'message' => $done
						? __( 'Connection renamed.', 'modern-mailer-oauth' )
						: __( 'That connection cannot be renamed.', 'modern-mailer-oauth' ),
				],
				$this->connections_payload()
			)
		);
	}

	public function delete_connection( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );

		// Rules pointing at a connection that no longer exists are dropped when
		// the router reads them, but leaving them stored would mean a rule
		// silently reappearing if an id were ever reused. They go now.
		$done = $this->plugin->connections->delete( $id );

		if ( $done ) {
			$this->prune_rules_for( $id );
			$this->plugin->tokens->flush();
			$this->plugin->dispatcher->reset_providers();
		}

		return new WP_REST_Response(
			array_merge(
				[
					'ok'      => $done,
					'message' => $done
						? __( 'Connection removed, along with its stored credentials.', 'modern-mailer-oauth' )
						: __( 'That connection cannot be removed.', 'modern-mailer-oauth' ),
				],
				$this->connections_payload()
			)
		);
	}

	public function get_routing(): WP_REST_Response {
		return new WP_REST_Response( $this->routing_payload() );
	}

	public function update_routing( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();

		$this->plugin->settings->update(
			[
				'routing_enabled' => ! empty( $body['enabled'] ),
				'routing_rules'   => is_array( $body['rules'] ?? null ) ? $body['rules'] : [],
			]
		);

		return new WP_REST_Response( $this->routing_payload() );
	}

	/**
	 * Forget any rule that pointed at a connection which has just gone.
	 */
	private function prune_rules_for( string $id ): void {
		$rules = $this->plugin->settings->get( 'routing_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		$kept = array_values(
			array_filter(
				$rules,
				static fn( $rule ): bool => ! is_array( $rule ) || ( $rule['connection'] ?? '' ) !== $id
			)
		);

		if ( count( $kept ) !== count( $rules ) ) {
			$this->plugin->settings->update( [ 'routing_rules' => $kept ] );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function connections_payload(): array {
		return [
			'connections' => $this->plugin->connections->all(),
			'max'         => \ModernMailer\Connections::MAX,
			'labels'      => Provider_Registry::labels(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function routing_payload(): array {
		return [
			'enabled'     => $this->plugin->router->is_enabled(),
			// Returned raw rather than through Router::rules(), so a rule the
			// admin is midway through writing is not silently deleted from under
			// them the next time the screen loads.
			'rules'       => (array) $this->plugin->settings->get( 'routing_rules' ),
			'vocabulary'  => \ModernMailer\Router::vocabulary(),
			'connections' => $this->plugin->connections->all(),
		];
	}

	/**
	 * Counts for the dashboard, including a per-day series for the chart.
	 */
	public function get_dashboard(): WP_REST_Response {
		global $wpdb;

		$table = \ModernMailer\Logger::table();
		$days  = 14;

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
				        SUM(status = 'sent') AS sent,
				        SUM(status = 'failed') AS failed
				 FROM {$table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at) ORDER BY day ASC",
				$days
			)
		);

		$by_day = [];

		foreach ( $rows as $row ) {
			$by_day[ (string) $row->day ] = [
				'sent'   => (int) $row->sent,
				'failed' => (int) $row->failed,
			];
		}

		// Emitted as a dense series rather than only the days that have rows.
		// A chart drawn from sparse data silently closes the gaps and makes an
		// outage look like a quiet period.
		$series = [];

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );

			$series[] = [
				'day'    => $day,
				'sent'   => $by_day[ $day ]['sent'] ?? 0,
				'failed' => $by_day[ $day ]['failed'] ?? 0,
			];
		}

		return new WP_REST_Response(
			[
				'series' => $series,
				'totals' => [
					'sent'   => array_sum( array_column( $series, 'sent' ) ),
					'failed' => array_sum( array_column( $series, 'failed' ) ),
				],
				'health' => $this->health_payload(),
				'queue'  => $this->plugin->queue->stats(),
				'recent' => array_map( [ $this, 'log_row' ], $this->plugin->logger->recent( 8 ) ),
			]
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function log_row( object $row ): array {
		return [
			'id'         => (int) $row->id,
			'created_at' => (string) $row->created_at,
			'provider'   => (string) $row->provider,
			'recipients' => (string) $row->recipients,
			'subject'    => (string) $row->subject,
			'status'     => (string) $row->status,
			'error'      => (string) $row->error_message,
			'bytes'      => (int) $row->bytes,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings_payload(): array {
		$settings = $this->plugin->settings;
		$keys     = [ 'from_email', 'from_name', 'force_from', 'log_enabled', 'log_retention', 'alert_threshold', 'alert_email', 'queue_enabled' ];

		$out    = [];
		$locked = [];

		foreach ( $keys as $key ) {
			$out[ $key ]    = $settings->get( $key );
			$locked[ $key ] = $settings->is_constant( $key );
		}

		return [
			'values' => $out,
			'locked' => $locked,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function connection_payload( string $slot ): array {
		$scoped = $this->plugin->settings->for_slot( $slot );

		return [
			'slot'      => '' === $slot ? 'primary' : $slot,
			'provider'  => (string) $scoped->get( 'provider' ),
			'providers' => Provider_Registry::to_array( $scoped ),
			'oauth'     => $this->oauth_payload( $slot ),
			'one_click' => $this->one_click_payload( $slot ),
		];
	}

	/**
	 * The Google sign-in state and the links that drive it.
	 *
	 * These are nonce-signed admin-post URLs rather than REST routes because
	 * starting a consent flow means navigating the browser to Google - a fetch()
	 * cannot do that, and the redirect has to be a real top-level navigation for
	 * Google to redirect back into the site afterwards.
	 *
	 * `has_credentials` exists so the app can explain why the button is
	 * unavailable rather than hiding it: the client ID and secret must be saved
	 * before there is anything to sign in with.
	 *
	 * Returned for every connection, not only one already set to Gmail. This
	 * block is what tells an admin the redirect URI to register and why they
	 * cannot sign in yet - which they need while setting Gmail up, i.e. before
	 * the provider has ever been saved. Gating it on the stored provider made
	 * the whole section invisible at exactly the moment it was needed.
	 *
	 * @return array<string,mixed>
	 */
	private function oauth_payload( string $slot ): array {
		$scoped = $this->plugin->settings->for_slot( $slot );
		$urls   = \ModernMailer\Admin\Admin_Page::google_urls( $slot );

		return [
			'connected'       => $this->plugin->consent->is_connected( $slot ),
			'has_credentials' => '' !== trim( (string) $scoped->get( 'google_client_id' ) )
				&& '' !== $scoped->secrets()->get( 'google_client_sec' ),
			'connect_url'     => $urls['connect'],
			'disconnect_url'  => $urls['disconnect'],
			'redirect_uri'    => \ModernMailer\Auth\Google_Consent::redirect_uri(),
		];
	}

	/**
	 * One-click state for each provider family this connection could use.
	 *
	 * Returned for every connection rather than only for one already set to a
	 * brokered provider, for the same reason the own-client block is: the admin
	 * needs to see the choice while setting the connection up, which is before
	 * anything has been saved.
	 *
	 * @return array<string,mixed>
	 */
	private function one_click_payload( string $slot ): array {
		$families = [];

		foreach ( [ Broker::GOOGLE, Broker::MICROSOFT ] as $family ) {
			$urls = \ModernMailer\Admin\Admin_Page::one_click_urls( $family, $slot );

			$families[ $family ] = [
				'connected'      => $this->plugin->one_click->is_connected( $family, $slot ),
				'account'        => $this->plugin->one_click->account( $family, $slot ),
				'connect_url'    => $urls['connect'],
				'disconnect_url' => $urls['disconnect'],
			];
		}

		return [
			// The UI hides the whole one-click affordance when this is false,
			// rather than offering a button that returns an error.
			'available' => Broker::is_available(),
			'families'  => $families,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function health_payload(): array {
		$state = $this->plugin->health->state();

		return [
			'failing'      => $this->plugin->health->is_failing(),
			'streak'       => (int) $state['streak'],
			'last_error'   => (string) ( $state['last_error']['message'] ?? '' ),
			'last_success' => (int) $state['last_success'],
			'active'       => $this->plugin->settings->is_active(),
			'has_backup'   => $this->plugin->settings->has_backup(),
		];
	}

	/**
	 * Resolve the connection named in the route.
	 *
	 * Resolved through Connections rather than matched against a pattern, so an
	 * id for a connection that has been deleted falls back to the primary
	 * instead of addressing a slot that no longer exists - which would silently
	 * create one on save.
	 */
	private function slot( WP_REST_Request $request ): string {
		$slot = $this->plugin->connections->slot_for( (string) $request->get_param( 'slot' ) );

		return $slot ?? Settings::SLOT_PRIMARY;
	}
}
