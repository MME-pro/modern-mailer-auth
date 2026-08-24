<?php
/**
 * Logs screen: the retry queue and the send log.
 *
 * The queue comes first on purpose. The send log is history; the queue is mail
 * that has not arrived yet, which is the thing an admin opening this screen
 * needs to see without scrolling.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $settings
 * @var string                        $page
 * @var array                         $entries
 * @var array                         $queue_stats
 * @var array                         $queued
 */

defined( 'ABSPATH' ) || exit;

$has_queue = $queue_stats['pending'] > 0 || $queue_stats['failed'] > 0;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Mail Logs', 'modern-mailer-oauth' ); ?></h1>

	<?php $this->render_notice(); ?>

	<h2><?php esc_html_e( 'Retry queue', 'modern-mailer-oauth' ); ?></h2>

	<?php if ( ! $settings->get( 'queue_enabled' ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'The retry queue is switched off.', 'modern-mailer-oauth' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to the Settings screen. */
					esc_html__( 'A send that fails for a temporary reason is reported and discarded rather than retried. Turn it on under %s.', 'modern-mailer-oauth' ),
					'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url() ) . '">' . esc_html__( 'Settings', 'modern-mailer-oauth' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php elseif ( ! $has_queue ) : ?>
		<p><?php esc_html_e( 'Nothing waiting. Every message has been delivered or reported.', 'modern-mailer-oauth' ); ?></p>
	<?php endif; ?>

	<?php if ( $has_queue ) : ?>
		<?php if ( $queue_stats['failed'] > 0 ) : ?>
			<div class="notice notice-error inline">
				<p>
					<strong>
						<?php
						printf(
							/* translators: %d: number of abandoned messages. */
							esc_html( _n( '%d message was never delivered.', '%d messages were never delivered.', (int) $queue_stats['failed'], 'modern-mailer-oauth' ) ),
							(int) $queue_stats['failed']
						);
						?>
					</strong>
					<?php esc_html_e( 'These ran out of retries. Fix the cause, then return them to the queue.', 'modern-mailer-oauth' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: %d: number of messages waiting. */
				esc_html( _n( '%d message waiting.', '%d messages waiting.', (int) $queue_stats['pending'], 'modern-mailer-oauth' ) ),
				(int) $queue_stats['pending']
			);

			if ( null !== $queue_stats['next'] ) {
				echo ' ';
				printf(
					/* translators: %s: local time of the next retry. */
					esc_html__( 'Next attempt at %s.', 'modern-mailer-oauth' ),
					esc_html( get_date_from_gmt( (string) $queue_stats['next'], 'Y-m-d H:i' ) )
				);
			}
			?>
		</p>

		<p style="display:flex;gap:.5rem;flex-wrap:wrap">
			<?php foreach ( [ 'drain' => __( 'Retry now', 'modern-mailer-oauth' ), 'requeue' => __( 'Return abandoned to queue', 'modern-mailer-oauth' ), 'purge' => __( 'Discard everything queued', 'modern-mailer-oauth' ) ] as $queue_action => $queue_label ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					<?php echo 'purge' === $queue_action ? 'onsubmit="return confirm( ' . esc_attr( wp_json_encode( __( 'This permanently deletes every queued message, including any still waiting to be delivered. Continue?', 'modern-mailer-oauth' ) ) ) . ' )"' : ''; ?>>
					<input type="hidden" name="action" value="mmoa_queue" />
					<input type="hidden" name="queue_action" value="<?php echo esc_attr( $queue_action ); ?>" />
					<?php
					$this->return_field( $page );
					wp_nonce_field( 'mmoa_queue' );
					submit_button( $queue_label, 'purge' === $queue_action ? 'delete' : 'secondary', 'submit', false );
					?>
				</form>
			<?php endforeach; ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Queued', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'To', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Tries', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Next attempt', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Last error', 'modern-mailer-oauth' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $queued as $row ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( $row->recipients ); ?></td>
						<td><?php echo esc_html( $row->subject ); ?></td>
						<td><?php echo esc_html( (string) $row->attempts ); ?></td>
						<td>
							<?php
							echo 'failed' === $row->status
								? '<span style="color:#d63638">' . esc_html__( 'Abandoned', 'modern-mailer-oauth' ) . '</span>'
								: esc_html( get_date_from_gmt( $row->next_attempt_at, 'Y-m-d H:i' ) );
							?>
						</td>
						<td><?php echo esc_html( $row->error_message ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr />
	<h2><?php esc_html_e( 'Send log', 'modern-mailer-oauth' ); ?></h2>

	<?php if ( ! $settings->get( 'log_enabled' ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: link to the Settings screen. */
				esc_html__( 'Logging is switched off. Turn it on under %s.', 'modern-mailer-oauth' ),
				'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url() ) . '">' . esc_html__( 'Settings', 'modern-mailer-oauth' ) . '</a>'
			);
			?>
		</p>
	<?php elseif ( ! $entries ) : ?>
		<p><?php esc_html_e( 'Nothing recorded yet.', 'modern-mailer-oauth' ); ?></p>
	<?php else : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %d: retention period in days. */
				esc_html__( 'Envelope details and errors only - message bodies are never stored here. Entries are kept for %d days.', 'modern-mailer-oauth' ),
				(int) $settings->get( 'log_retention' )
			);
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Connection', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'To', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'modern-mailer-oauth' ); ?></th>
					<th><?php esc_html_e( 'Result', 'modern-mailer-oauth' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $entry->created_at, 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( $entry->provider ); ?></td>
						<td><?php echo esc_html( $entry->recipients ); ?></td>
						<td><?php echo esc_html( $entry->subject ); ?></td>
						<td>
							<?php if ( 'sent' === $entry->status ) : ?>
								<span style="color:#008a20">&#10003; <?php esc_html_e( 'Sent', 'modern-mailer-oauth' ); ?></span>
							<?php else : ?>
								<span style="color:#d63638">&#10007; <?php echo esc_html( $entry->error_message ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'A failed row followed by a successful one for the same message is the backup connection or the retry queue doing its job.', 'modern-mailer-oauth' ); ?>
		</p>
	<?php endif; ?>
</div>
