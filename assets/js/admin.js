/**
 * WooNyx settings page: asks for confirmation before a form with a `data-woonyx-confirm` message is submitted.
 *
 * @package WooNyx
 */

( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		var message = form.getAttribute( 'data-woonyx-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
