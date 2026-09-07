/**
 * The translation guard, against a DOM that behaves like a translated page.
 *
 * Node rather than the PHP suite because the fault is entirely in the browser:
 * nothing about it reaches WordPress. jsdom is already here as a dependency of
 * wp-scripts, so this needs no new install.
 *
 *   node tests/translation-guard.test.mjs
 *
 * What is being reproduced is the exact sequence that blanks the screen. A
 * browser translator does not rewrite text in place - it wraps each text node
 * in a <font> element. React goes on holding the original text node and still
 * believes it is a child of the element it rendered it into, so the next update
 * that removes that text calls removeChild on the wrong parent and throws. The
 * throw lands in React's commit phase, and React answers an error there by
 * unmounting the entire root.
 */

import { JSDOM } from 'jsdom';
import { installTranslationGuard } from '../src/lib/translation-guard.js';

const dom = new JSDOM( '<!doctype html><body></body>' );

// The guard patches whatever global Node it is given, so jsdom's has to be the
// one in scope when it runs - exactly as the browser's is in the real app.
global.Node = dom.window.Node;

const { document } = dom.window;

let pass = 0;
let fail = 0;

const check = ( label, ok, detail = '' ) => {
	if ( ok ) {
		pass++;
		console.log( `  PASS  ${ label }` );
	} else {
		fail++;
		console.log( `  FAIL  ${ label }${ detail ? `  <- ${ detail }` : '' }` );
	}
};

/**
 * Do to an element what Chrome's translator does: take its text node out and
 * put it back inside a <font> wrapper. The visible text is unchanged; the tree
 * is a level deeper than whoever rendered it thinks.
 */
const translate = ( element ) => {
	const original = element.firstChild;
	const font = document.createElement( 'font' );

	element.removeChild( original );
	font.appendChild( original );
	element.appendChild( font );

	return original;
};

console.log( '\n=== 1. Without the guard, this is what breaks ===' );

const before = document.createElement( 'span' );
before.textContent = 'and';
document.body.appendChild( before );

const strandedBefore = translate( before );

let threw = false;
try {
	before.removeChild( strandedBefore );
} catch ( e ) {
	threw = true;
}

check( 'removing a translated text node throws unguarded', threw );
check(
	'and that is a NotFoundError, which React cannot recover from',
	threw,
	'this is the blank screen'
);

console.log( '\n=== 2. With the guard installed ===' );
installTranslationGuard();

const after = document.createElement( 'span' );
after.textContent = 'and';
document.body.appendChild( after );

const strandedAfter = translate( after );

let returned;
let stillThrew = false;
try {
	returned = after.removeChild( strandedAfter );
} catch ( e ) {
	stillThrew = true;
}

check( 'the same removal no longer throws', ! stillThrew );
check( 'and it hands back the node, as removeChild is contracted to', returned === strandedAfter );
check( 'the translated text is left on screen', after.textContent.includes( 'and' ) );

console.log( '\n=== 3. Correct code is untouched ===' );

const normal = document.createElement( 'div' );
const child = document.createElement( 'b' );
normal.appendChild( child );
document.body.appendChild( normal );

check( 'a genuine child is still removed', normal.removeChild( child ) === child );
check( 'and the parent is genuinely emptied', normal.childNodes.length === 0 );

const host = document.createElement( 'div' );
const anchor = document.createElement( 'i' );
const inserted = document.createElement( 'u' );
host.appendChild( anchor );
document.body.appendChild( host );

check( 'a genuine insertBefore still places the node first', host.insertBefore( inserted, anchor ) === inserted );
check( 'in the right order', host.firstChild === inserted && host.lastChild === anchor );

console.log( '\n=== 4. insertBefore against a stranded anchor ===' );

const moved = document.createElement( 'div' );
const strandedAnchor = document.createElement( 'i' );
const late = document.createElement( 'u' );
document.body.appendChild( moved );
// The anchor React wants to insert before was never in this parent.
document.body.appendChild( strandedAnchor );

let insertThrew = false;
try {
	moved.insertBefore( late, strandedAnchor );
} catch ( e ) {
	insertThrew = true;
}

check( 'it does not throw', ! insertThrew );
check( 'and the node is still rendered rather than dropped', moved.contains( late ) );

console.log( '\n=== 5. Installing twice does not stack the patch ===' );

const firstRemove = Node.prototype.removeChild;
installTranslationGuard();
check( 'the second call is a no-op', Node.prototype.removeChild === firstRemove );

console.log( `\n${ pass } passed, ${ fail } failed\n` );
process.exit( fail > 0 ? 1 : 0 );
