<?php
/**
 * Additional connections and smart routing.
 *
 * Two features that only earn their keep together: extra connections give a
 * routing rule somewhere to point, and routing is what makes a fourth
 * connection worth configuring.
 *
 * The assertions that matter most are the negative ones. A routing layer that
 * quietly swallows mail - because a rule pointed at a deleted connection, or an
 * unfinished rule matched everything - is worse than no routing at all, so most
 * of what follows is about what must NOT happen.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Connections;
use ModernMailer\Message;
use ModernMailer\Router;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
ModernMailer\Logger::install();
ModernMailer\Queue::install();

/** Build a Message without going near the network. */
function build_message( array $args ): Message {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

	$mailer = new PHPMailer\PHPMailer\PHPMailer( false );
	$mailer->isMail();
	$mailer->setFrom( $args['from'] ?? 'site@example.com', 'Site' );
	$mailer->Subject = $args['subject'] ?? 'Hello';
	$mailer->Body    = 'body';

	foreach ( (array) ( $args['to'] ?? [ 'someone@example.com' ] ) as $to ) {
		$mailer->addAddress( $to );
	}
	foreach ( (array) ( $args['cc'] ?? [] ) as $cc ) {
		$mailer->addCC( $cc );
	}
	foreach ( (array) ( $args['bcc'] ?? [] ) as $bcc ) {
		$mailer->addBCC( $bcc );
	}

	$mailer->preSend();

	return Message::from_mailer( $mailer->getSentMIMEMessage(), $mailer );
}

// Start from a clean slate so a previous run cannot leak connections in.
$plugin->settings->update( [ 'connections' => [], 'routing_rules' => [], 'routing_enabled' => false ] );
Settings::flush_cache();

$connections = $plugin->connections;
$router      = $plugin->router;

echo "\n=== 1. Built-in connections are always present ===\n";
$all = $connections->all();
check( 'primary and backup exist out of the box', 2 === count( $all ), count( $all ) . ' connections' );
check( 'primary is first', 'primary' === $all[0]['id'] );
check( 'primary maps to the unprefixed slot', '' === $all[0]['slot'] );
check( 'both are marked built-in', $all[0]['builtin'] && $all[1]['builtin'] );
check( 'primary cannot be deleted', ! $connections->delete( 'primary' ) );
check( 'backup cannot be deleted', ! $connections->delete( 'backup' ) );

echo "\n=== 2. Additional connections ===\n";
$marketing = $connections->add( 'Marketing' );
$receipts  = $connections->add( 'Receipts' );
Settings::flush_cache();

check( 'adding returns an id', is_string( $marketing ) && '' !== $marketing, var_export( $marketing, true ) );
check( 'the id is storage-safe', Connections::is_valid_id( $marketing ), $marketing );
check( 'ids are unique', $marketing !== $receipts );
check( 'both appear in the list', 4 === count( $connections->all() ) );
check( 'the name is kept', 'Marketing' === $connections->name_for( $marketing ) );
check( 'and it resolves to its own slot', $marketing === $connections->slot_for( $marketing ) );

// A name is free text; an id is not. A connection called "Marketing / EU" must
// not produce option keys nothing can read back.
$awkward = $connections->add( 'Marketing / EU — 2024' );
Settings::flush_cache();
check( 'an awkward name still yields a safe id', Connections::is_valid_id( $awkward ), $awkward );
check( 'while keeping the name it was given', 'Marketing / EU — 2024' === $connections->name_for( $awkward ) );

echo "\n=== 3. Each connection keeps its own credentials ===\n";
$plugin->settings->for_slot( $marketing )->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.marketing.test' ] );
$plugin->settings->for_slot( $marketing )->secrets()->set( 'smtp_password', 'marketing-secret' );
$plugin->settings->for_slot( $receipts )->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.receipts.test' ] );
$plugin->settings->for_slot( $receipts )->secrets()->set( 'smtp_password', 'receipts-secret' );
$plugin->settings->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.primary.test' ] );
Settings::flush_cache();

check( 'primary host is its own', 'smtp.primary.test' === $plugin->settings->get( 'smtp_host' ) );
check( 'marketing host is its own', 'smtp.marketing.test' === $plugin->settings->for_slot( $marketing )->get( 'smtp_host' ) );
check( 'receipts host is its own', 'smtp.receipts.test' === $plugin->settings->for_slot( $receipts )->get( 'smtp_host' ) );
check( 'and the passwords do not bleed', 'marketing-secret' === $plugin->settings->for_slot( $marketing )->secrets()->get( 'smtp_password' )
	&& 'receipts-secret' === $plugin->settings->for_slot( $receipts )->secrets()->get( 'smtp_password' ) );

echo "\n=== 4. Deleting a connection takes its credentials with it ===\n";
$doomed = $connections->add( 'Temporary' );
Settings::flush_cache();
$plugin->settings->for_slot( $doomed )->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.doomed.test' ] );
$plugin->settings->for_slot( $doomed )->secrets()->set( 'smtp_password', 'doomed-secret' );
Settings::flush_cache();

check( 'it stored something first', 'doomed-secret' === $plugin->settings->for_slot( $doomed )->secrets()->get( 'smtp_password' ) );

$connections->delete( $doomed );
Settings::flush_cache();

check( 'the connection is gone', ! $connections->exists( $doomed ) );
// Leaving credentials behind would put them beyond the UI's reach while still
// sitting in every database dump.
check( 'the password went with it', '' === $plugin->settings->for_slot( $doomed )->secrets()->get( 'smtp_password' ) );
check( 'and so did the provider', '' === $plugin->settings->for_slot( $doomed )->get( 'provider' ) );

echo "\n=== 5. Routing is off until asked for ===\n";
$plugin->settings->update( [
	'routing_enabled' => false,
	'routing_rules'   => [
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => 'Newsletter' ] ] ] ],
	],
] );
Settings::flush_cache();

check( 'a matching rule is ignored while disabled', null === $router->route( build_message( [ 'subject' => 'Newsletter time' ] ) ) );

$plugin->settings->update( [ 'routing_enabled' => true ] );
Settings::flush_cache();
check( 'and honoured once enabled', $marketing === $router->route( build_message( [ 'subject' => 'Newsletter time' ] ) ) );
check( 'a non-matching message still goes primary', null === $router->route( build_message( [ 'subject' => 'Your receipt' ] ) ) );

echo "\n=== 6. Operators ===\n";
$cases = [
	[ 'subject', 'contains', 'invoice', [ 'subject' => 'Your Invoice #12' ], true, 'contains is case-insensitive' ],
	[ 'subject', 'contains', 'invoice', [ 'subject' => 'Receipt' ], false, 'contains rejects a miss' ],
	[ 'subject', 'is', 'Welcome', [ 'subject' => 'Welcome' ], true, 'is matches exactly' ],
	[ 'subject', 'is', 'Welcome', [ 'subject' => 'Welcome aboard' ], false, 'is rejects a prefix' ],
	[ 'subject', 'starts_with', 'Re:', [ 'subject' => 'Re: your order' ], true, 'starts_with' ],
	[ 'subject', 'ends_with', 'receipt', [ 'subject' => 'Your receipt' ], true, 'ends_with' ],
	[ 'to', 'contains', 'shop.test', [ 'to' => [ 'buyer@shop.test' ] ], true, 'to matches a recipient' ],
	[ 'to_domain', 'is', 'shop.test', [ 'to' => [ 'buyer@shop.test' ] ], true, 'to_domain extracts the domain' ],
	[ 'from_email', 'is', 'billing@example.com', [ 'from' => 'billing@example.com' ], true, 'from_email' ],
	[ 'cc', 'contains', 'legal', [ 'cc' => [ 'legal@example.com' ] ], true, 'cc' ],
	[ 'bcc', 'contains', 'audit', [ 'bcc' => [ 'audit@example.com' ] ], true, 'bcc' ],
];

foreach ( $cases as [ $field, $operator, $value, $args, $expected, $label ] ) {
	$plugin->settings->update( [
		'routing_rules' => [
			[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => $field, 'operator' => $operator, 'value' => $value ] ] ] ],
		],
	] );
	Settings::flush_cache();

	$routed = $marketing === $router->route( build_message( $args ) );
	check( $label, $routed === $expected, $routed ? 'routed' : 'not routed' );
}

echo "\n=== 6b. Negative operators hold across every recipient ===\n";
// "Does not contain" over three recipients has to mean none of them contains
// it. Reading it as "any one differs" would fire the rule almost always.
$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'to', 'operator' => 'not_contains', 'value' => 'example.com' ] ] ] ],
	],
] );
Settings::flush_cache();

check(
	'not_contains is false when one recipient does contain it',
	null === $router->route( build_message( [ 'to' => [ 'a@other.test', 'b@example.com' ] ] ) )
);
check(
	'and true only when none do',
	$marketing === $router->route( build_message( [ 'to' => [ 'a@other.test', 'b@another.test' ] ] ) )
);

echo "\n=== 7. AND within a group, OR between groups ===\n";
$plugin->settings->update( [
	'routing_rules' => [
		[
			'connection' => $receipts,
			'groups'     => [
				[
					[ 'field' => 'subject', 'operator' => 'contains', 'value' => 'receipt' ],
					[ 'field' => 'to_domain', 'operator' => 'is', 'value' => 'shop.test' ],
				],
				[
					[ 'field' => 'subject', 'operator' => 'contains', 'value' => 'invoice' ],
				],
			],
		],
	],
] );
Settings::flush_cache();

check( 'both conditions in a group must hold', $receipts === $router->route( build_message( [ 'subject' => 'Your receipt', 'to' => [ 'b@shop.test' ] ] ) ) );
check( 'one condition failing fails the group', null === $router->route( build_message( [ 'subject' => 'Your receipt', 'to' => [ 'b@other.test' ] ] ) ) );
check( 'the second group can still match on its own', $receipts === $router->route( build_message( [ 'subject' => 'Invoice 5', 'to' => [ 'b@other.test' ] ] ) ) );

echo "\n=== 8. First matching rule wins ===\n";
$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $receipts, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => 'order' ] ] ] ],
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => 'order' ] ] ] ],
	],
] );
Settings::flush_cache();
check( 'the earlier rule takes it', $receipts === $router->route( build_message( [ 'subject' => 'Your order' ] ) ) );

echo "\n=== 9. Unusable rules are ignored, never allowed to capture mail ===\n";
// Each of these, taken literally, would match every message. An author who left
// a field blank did not mean "route everything here".
$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => '' ] ] ] ],
	],
] );
Settings::flush_cache();
check( 'a condition with no value is dropped', [] === $router->rules() );
check( 'so nothing is routed', null === $router->route( build_message( [ 'subject' => 'Anything at all' ] ) ) );

$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $marketing, 'groups' => [] ],
	],
] );
Settings::flush_cache();
check( 'a rule with no conditions is dropped', [] === $router->rules() );

$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'nonsense', 'operator' => 'contains', 'value' => 'x' ] ] ] ],
	],
] );
Settings::flush_cache();
check( 'an unknown field is dropped', [] === $router->rules() );

echo "\n=== 10. A rule pointing at a deleted connection is inert ===\n";
$gone = $connections->add( 'Doomed' );
Settings::flush_cache();
$plugin->settings->update( [
	'routing_rules' => [
		[ 'connection' => $gone, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => 'test' ] ] ] ],
	],
] );
Settings::flush_cache();
check( 'the rule works while the connection exists', 1 === count( $router->rules() ) );

$connections->delete( $gone );
Settings::flush_cache();

// Routing into a slot with no provider would fail every message it matched.
check( 'once deleted the rule is discarded', [] === $router->rules() );
check( 'and the message falls back to primary', null === $router->route( build_message( [ 'subject' => 'test' ] ) ) );

echo "\n=== 11. Routing picks the path; it does not bypass the safety net ===\n";
// The whole retry and fallback apparatus has to keep working for a routed
// message, or routing becomes a way to lose mail.
$plugin->settings->update( [
	'routing_enabled' => true,
	'queue_enabled'   => true,
	'routing_rules'   => [
		[ 'connection' => $marketing, 'groups' => [ [ [ 'field' => 'subject', 'operator' => 'contains', 'value' => 'campaign' ] ] ] ],
	],
] );
$plugin->settings->for_slot( $marketing )->update( [ 'provider' => 'graph', 'ms_tenant_id' => 't', 'ms_client_id' => 'c', 'ms_sender' => 'm@contoso.com' ] );
$plugin->settings->for_slot( $marketing )->secrets()->set( 'ms_client_secret', 's' );
$plugin->settings->update( [ 'provider' => 'graph', 'from_email' => 'site@contoso.com', 'ms_tenant_id' => 'p', 'ms_client_id' => 'p', 'ms_sender' => 'site@contoso.com' ] );
$plugin->secrets->set( 'ms_client_secret', 'p' );
$plugin->settings->for_slot( Settings::SLOT_BACKUP )->update( [ 'provider' => '' ] );
Settings::flush_cache();
$plugin->dispatcher->reset_providers();
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->queue->purge();
$plugin->install_mailer();

$seen = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$seen ) {
	$seen[] = $url;
	return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
}, 10, 3 );

$sent = wp_mail( 'reader@example.com', 'Autumn campaign', 'body' );

check( 'the routed send was accepted for later delivery', true === $sent, var_export( $sent, true ) );
check( 'and it is on the queue rather than lost', 1 === $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );

// The queue must remember which connection was chosen. Re-routing on retry
// would let a rule change move a half-delivered message to another sender.
global $wpdb;
$stored_slot = $wpdb->get_var( "SELECT slot FROM {$wpdb->prefix}mmoa_queue ORDER BY id DESC LIMIT 1" );
check( 'the chosen connection was recorded with it', $marketing === $stored_slot, var_export( $stored_slot, true ) );

echo "\n=== 12. Restoring a clean state ===\n";
foreach ( array_keys( $connections->additional() ) as $id ) {
	$connections->delete( $id );
}
$plugin->settings->update( [
	'provider' => '', 'from_email' => '', 'smtp_host' => '',
	'routing_enabled' => false, 'routing_rules' => [], 'connections' => [],
] );
$plugin->secrets->flush();
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->queue->purge();
Settings::flush_cache();

check( 'no additional connections remain', [] === $connections->additional() );
check( 'routing is off', ! $router->is_enabled() );
check( 'plugin left inactive', ! $plugin->settings->is_active() );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
