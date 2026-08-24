<?php
/**
 * Shows only the credential panels belonging to the chosen provider.
 *
 * Shared by the Settings and Backup screens, which each render one connection.
 *
 * @package ModernMailer
 */

defined( 'ABSPATH' ) || exit;
?>
<script>
( function () {
	// Each connection's provider select owns a set of panels, found by the
	// class name the select carries in data-panels. Written this way rather
	// than against a single global select so the same code drives both screens.
	document.querySelectorAll( '.mmoa-provider-select' ).forEach( function ( select ) {
		var panels = document.querySelectorAll( '.' + select.dataset.panels );

		function sync() {
			panels.forEach( function ( panel ) {
				panel.hidden = panel.dataset.provider !== select.value;
			} );
		}

		select.addEventListener( 'change', sync );
		sync();
	} );
}() );
</script>
