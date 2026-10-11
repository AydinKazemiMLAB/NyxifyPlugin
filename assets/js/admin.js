/**
 * Nyxify settings page: asks for confirmation before a form with a `data-nyxify-confirm` message is submitted.
 *
 * @package Nyxify
 */

( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		var message = form.getAttribute( 'data-nyxify-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
