/**
 * Survive a browser translator rewriting the DOM underneath React.
 *
 * ## The failure
 *
 * Chrome's built-in translation - and every extension that works the same way -
 * does not translate text in place. It replaces each text node with a <font>
 * element wrapping the translated text. The DOM still looks right; the tree
 * shape has changed.
 *
 * React never finds out. It is still holding a reference to the original text
 * node and still believes that node's parent is the element it rendered it
 * into. The moment that text changes - a label flipping to empty, a toast
 * appearing, a status line updating after Save - React calls
 *
 *     parent.removeChild( theTextNode )
 *
 * and the browser throws NotFoundError, because the node's parent is now the
 * <font> the translator inserted. The throw happens inside React's commit
 * phase, which is unrecoverable by design: React tears the whole root down
 * rather than leave a half-applied tree on screen.
 *
 * That teardown is what an administrator sees as the page going blank the
 * instant they press Save.
 *
 * ## The fix
 *
 * Make the two mutations survivable. Both of these calls are already errors -
 * without this patch they throw and take the app with them - so nothing that
 * was previously working changes behaviour. The only difference is that the
 * doomed operation returns quietly instead of destroying the screen, and
 * React's next render puts the subtree right.
 *
 * This is the workaround carried in facebook/react#11538, which has been open
 * since 2017 and is not going to be fixed inside React: React cannot tell a
 * translator's rewrite apart from any other third-party DOM mutation.
 *
 * ## What it costs
 *
 * Node.prototype is global, so this applies to the whole wp-admin page rather
 * than just this app. That is worth being deliberate about, and the reason it
 * is acceptable here is the narrowness: the guard only changes what happens in
 * the case that would otherwise have thrown an exception. Correct code takes
 * the original path, byte for byte.
 *
 * Installed once, and it says so, because a second patch layered over the first
 * would wrap the already-wrapped originals.
 */

const FLAG = '__mmoaTranslationGuard';

export const installTranslationGuard = () => {
	if ( typeof Node !== 'function' || ! Node.prototype || Node.prototype[ FLAG ] ) {
		return;
	}

	const originalRemoveChild = Node.prototype.removeChild;
	const originalInsertBefore = Node.prototype.insertBefore;

	Node.prototype.removeChild = function ( child ) {
		if ( child && child.parentNode !== this ) {
			// Already detached, or adopted by a translator's wrapper. Either way
			// it is not ours to remove, and saying so is enough.
			return child;
		}

		return originalRemoveChild.apply( this, arguments );
	};

	Node.prototype.insertBefore = function ( newNode, referenceNode ) {
		if ( referenceNode && referenceNode.parentNode !== this ) {
			// The anchor moved. Appending keeps the node on screen - possibly in
			// the wrong order for one frame - which is a far better outcome than
			// the alternative of not rendering it at all.
			return this.appendChild( newNode );
		}

		return originalInsertBefore.apply( this, arguments );
	};

	Node.prototype[ FLAG ] = true;
};

export default installTranslationGuard;
