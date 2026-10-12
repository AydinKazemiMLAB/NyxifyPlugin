/**
 * Nyxify settings page: asks for confirmation before a form with a `data-nyxify-confirm` message is submitted, and
 * shows, hides and copies the admin PIN (in this browser only; nothing is sent).
 *
 * @package Nyxify
 */

( function () {
	'use strict';

	var MASK = '••••••';

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

	/**
	 * Copies text with the Clipboard API, or with a hidden field where it isn't available (no HTTPS).
	 *
	 * @param {string} text The text.
	 * @return {Promise} Settled once copied, rejected when the browser refused.
	 */
	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );
			field.value = text;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();
			var copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}
			document.body.removeChild( field );
			if ( copied ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	/**
	 * Wires the PIN's "Show" and "Copy" buttons.
	 *
	 * @param {HTMLElement} box The PIN's box (`data-nyxify-pin`).
	 */
	function setUpPin( box ) {
		var secret = document.querySelector( '[data-nyxify-pin-secret]' );
		var value = box.querySelector( '[data-nyxify-pin-value]' );
		var toggle = box.querySelector( '[data-nyxify-pin-toggle]' );
		var copyButton = box.querySelector( '[data-nyxify-pin-copy]' );
		var status = box.querySelector( '[role="status"]' );
		if ( ! secret || ! value || ! toggle || ! copyButton || ! status ) {
			return;
		}

		var hiddenLabel = value.getAttribute( 'aria-label' );

		toggle.addEventListener( 'click', function () {
			var shown = 'true' === toggle.getAttribute( 'aria-pressed' );
			value.textContent = shown ? MASK : secret.textContent;
			if ( shown ) {
				value.setAttribute( 'aria-label', hiddenLabel );
			} else {
				value.removeAttribute( 'aria-label' );
			}
			toggle.setAttribute( 'aria-pressed', shown ? 'false' : 'true' );
			toggle.textContent = box.getAttribute( shown ? 'data-nyxify-show' : 'data-nyxify-hide' );
		} );

		copyButton.addEventListener( 'click', function () {
			status.textContent = '';
			copy( secret.textContent ).then(
				function () {
					status.textContent = box.getAttribute( 'data-nyxify-copied' );
				},
				function () {
					if ( 'true' !== toggle.getAttribute( 'aria-pressed' ) ) {
						toggle.click();
					}
					status.textContent = box.getAttribute( 'data-nyxify-copy-failed' );
				}
			);
		} );
	}

	document.querySelectorAll( '[data-nyxify-pin]' ).forEach( setUpPin );
}() );
