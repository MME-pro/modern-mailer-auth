<?php
/**
 * A body that is HTML must leave as HTML.
 *
 * wp_mail() sends text/plain unless the caller passes a Content-Type header,
 * and a great many callers do not, so the markup arrives as visible tags.
 * Mail_Catcher promotes such a message before PHPMailer builds it. These tests
 * pin both halves of that: the promotion, and the cases that must never be
 * promoted.
 */
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

// --- a raw-MIME provider, so the tests read the actual message --------------
$plugin->settings->update( [
	'provider'      => 'graph',
	'from_email'    => 'noreply@contoso.com',
	'from_name'     => 'Contoso',
	'force_from'    => true,
	'ms_tenant_id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	'ms_client_id'  => '11111111-2222-3333-4444-555555555555',
	'ms_sender'     => 'noreply@contoso.com',
	'log_enabled'   => false,
	'queue_enabled' => false,
] );
$plugin->secrets->set( 'ms_client_secret', 'test-secret-value' );
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->install_mailer();

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'login.microsoftonline.com' ) ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'access_token' => 'FAKE_TOKEN', 'expires_in' => 3600 ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [], 'filename' => null,
		];
	}

	return [
		'headers'  => [],
		'body'     => '',
		'response' => [ 'code' => 202, 'message' => 'Accepted' ],
		'cookies'  => [], 'filename' => null,
	];
}, 10, 3 );

$captured = '';
add_action( 'mmoa_before_send', function ( $raw ) use ( &$captured ) {
	$captured = $raw;
}, 10, 1 );

/** Send and hand back the MIME the provider was given. */
$send = function ( ...$args ) use ( &$captured ): string {
	$captured = '';
	wp_mail( ...$args );

	return $captured;
};

/** The decoded body of the first part with this content type. */
$part = function ( string $mime, string $type ): string {
	if ( ! preg_match( '~boundary="?([^"\s;]+)"?~i', $mime, $b ) ) {
		return $mime;
	}

	foreach ( explode( '--' . $b[1], $mime ) as $chunk ) {
		if ( false === stripos( $chunk, 'Content-Type: ' . $type ) ) {
			continue;
		}

		$split = strpos( $chunk, "\r\n\r\n" );

		if ( false === $split ) {
			continue;
		}

		$body = substr( $chunk, $split + 4 );

		return false !== stripos( $chunk, 'quoted-printable' )
			? quoted_printable_decode( $body )
			: $body;
	}

	return '';
};

$html = '<html><head><style>p{color:red}</style></head><body>'
	. '<h1>Rechnung</h1>'
	. '<p>Hallo <strong>Max</strong>,<br>danke f&uuml;r Ihre Bestellung.</p>'
	. '<p><a href="https://example.com/pay">Jetzt bezahlen</a></p>'
	. '<table><tr><td>Artikel</td><td>9,90 &euro;</td></tr></table>'
	. '</body></html>';

// --- 1. the reported bug ----------------------------------------------------
echo "\n=== 1. HTML body with no Content-Type header ===\n";

$mime = $send( 'someone@example.com', 'invoice', $html );

check( 'the message is multipart/alternative, not text/plain',
	(bool) preg_match( '~^Content-Type: multipart/alternative~mi', $mime ),
	trim( (string) ( preg_match( '~^Content-Type:.*$~mi', $mime, $m ) ? $m[0] : 'none' ) ) );

$html_part = $part( $mime, 'text/html' );

check( 'an HTML part carries the markup', false !== strpos( $html_part, '<h1>Rechnung</h1>' ) );
check( 'the markup was not escaped on the way', false === strpos( $html_part, '&lt;h1&gt;' ) );

// --- 2. the text alternative that comes with it -----------------------------
echo "\n=== 2. The generated plain-text alternative ===\n";

$text_part = trim( str_replace( "\r\n", "\n", $part( $mime, 'text/plain' ) ) );

check( 'no tags survive in the text part', false === strpos( $text_part, '<h1>' ) );
check( 'the stylesheet is not in the text part', false === strpos( $text_part, 'color:red' ) );
check( 'entities are decoded', false !== strpos( $text_part, 'für' ) && false === strpos( $text_part, '&uuml;' ) );
check( 'the link target is kept', false !== strpos( $text_part, 'Jetzt bezahlen (https://example.com/pay)' ), $text_part );
check( 'table cells are separated', false !== strpos( $text_part, 'Artikel 9,90' ), $text_part );
check( 'block structure became line breaks',
	false !== strpos( $text_part, "Rechnung\n" ) && false === strpos( $text_part, "\n\n\n" ), $text_part );

// --- 3. what must not change ------------------------------------------------
echo "\n=== 3. Messages that must be left alone ===\n";

$plain = "Neue Nachricht von Max <max@example.com>.\n"
	. "Bedingung: a < b und 3 > 2.\n"
	. 'Antworten unter https://example.com/x';

$mime = $send( 'someone@example.com', 'plain', $plain );

check( 'prose containing < and an address stays text/plain',
	(bool) preg_match( '~^Content-Type: text/plain~mi', $mime ) );
check( 'and its body is untouched', false !== strpos( $mime, 'Max <max@example.com>' ) );

// A lone opening tag is not enough - no closing tag, no <br>/<hr>/<img>.
$mime = $send( 'someone@example.com', 'fragment', "Use <p to start a paragraph.\nThat is all." );

check( 'a lone unclosed tag is not treated as HTML',
	(bool) preg_match( '~^Content-Type: text/plain~mi', $mime ) );

// --- 4. an explicit Content-Type still wins ---------------------------------
echo "\n=== 4. An explicit Content-Type is never second-guessed ===\n";

$mime = $send( 'someone@example.com', 'explicit', $html, [ 'Content-Type: text/html; charset=UTF-8' ] );

check( 'an explicitly HTML message is still HTML',
	(bool) preg_match( '~^Content-Type: text/html~mi', $mime ) );
check( 'and no alternative part was invented for it',
	! preg_match( '~^Content-Type: multipart/alternative~mi', $mime ) );

// --- 5. a caller's own AltBody is preserved ---------------------------------
echo "\n=== 5. A caller that set AltBody keeps it ===\n";

$alt = 'Die von Hand geschriebene Textfassung.';
$keep = function ( $m ) use ( $alt ) { $m->AltBody = $alt; };
add_action( 'phpmailer_init', $keep );
$mime = $send( 'someone@example.com', 'with altbody', $html );
remove_action( 'phpmailer_init', $keep );

check( 'the message was still promoted to HTML',
	(bool) preg_match( '~^Content-Type: multipart/alternative~mi', $mime ) );
check( 'and the supplied text part was not overwritten',
	false !== strpos( $part( $mime, 'text/plain' ), $alt ), trim( $part( $mime, 'text/plain' ) ) );

// --- 6. the escape hatch ----------------------------------------------------
echo "\n=== 6. mmoa_promote_html can switch it off ===\n";

add_filter( 'mmoa_promote_html', '__return_false' );
$mime = $send( 'someone@example.com', 'opted out', $html );
remove_filter( 'mmoa_promote_html', '__return_false' );

check( 'the filter leaves the message exactly as built',
	(bool) preg_match( '~^Content-Type: text/plain~mi', $mime ) );

// --- 7. and it reaches a JSON provider as HTML ------------------------------
echo "\n=== 7. A structured-API provider receives it as HTML ===\n";

$plugin->settings->update( [ 'provider' => 'brevo' ] );
$plugin->secrets->set( 'brevo_api_key', 'xkeysib-test' );
$plugin->dispatcher->reset_providers();

$json = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$json ) {
	if ( false !== strpos( $url, 'api.brevo.com' ) ) {
		$json = json_decode( (string) $args['body'], true ) ?: [];
	}

	return $pre;
}, 5, 3 );

wp_mail( 'someone@example.com', 'via brevo', $html );

check( 'htmlContent holds the markup',
	isset( $json['htmlContent'] ) && false !== strpos( $json['htmlContent'], '<h1>Rechnung</h1>' ),
	wp_json_encode( array_keys( $json ) ) );
check( 'textContent holds the readable text',
	isset( $json['textContent'] ) && false !== strpos( $json['textContent'], 'Jetzt bezahlen (https://example.com/pay)' ),
	(string) ( $json['textContent'] ?? '' ) );

// The message this plugin was likeliest to get wrong, and not through any
// auto-detection: an explicitly HTML message that also carries a text part -
// core, WooCommerce and every form plugin send exactly this. preSend() rewrites
// ContentType to multipart/alternative for it, which is why Message has to read
// the type after that rewrite rather than before.
$alt  = 'Rechnung. Jetzt bezahlen: https://example.com/pay';
$keep = function ( $m ) use ( $alt ) { $m->AltBody = $alt; };
add_action( 'phpmailer_init', $keep );
$json = [];
wp_mail( 'someone@example.com', 'html plus altbody', $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
remove_action( 'phpmailer_init', $keep );

check( 'an HTML message with a text alternative is still sent as HTML',
	isset( $json['htmlContent'] ) && false !== strpos( $json['htmlContent'], '<h1>Rechnung</h1>' ),
	wp_json_encode( array_keys( $json ) ) );
check( 'and its text field is the alternative, not the markup',
	( $json['textContent'] ?? '' ) === $alt, (string) ( $json['textContent'] ?? '' ) );

// --- restore ----------------------------------------------------------------
$plugin->settings->update( [ 'provider' => '', 'from_email' => '', 'from_name' => '' ] );
$plugin->secrets->set( 'ms_client_secret', '' );
$plugin->secrets->set( 'brevo_api_key', '' );
$plugin->tokens->flush();
$plugin->health->reset();

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
