/**
 * Nyxify bot appearance page: a button with `data-nyxify-media` (the id of an address field) opens the media library,
 * where an image can be picked or uploaded; the image's address goes into the field, with its preview under it (an
 * element with the field's id and `-preview`). A typed address updates the preview too.
 *
 * @package Nyxify
 */

( function () {
	'use strict';

	var HTTPS = /^https:\/\/[^\s<>"']+$/;
	var frames = {};

	/**
	 * Shows the field's image under it, or hides the preview when the field holds no https address.
	 *
	 * @param {HTMLInputElement} field The address field.
	 */
	function preview( field ) {
		var image = document.getElementById( field.id + '-preview' );
		if ( ! image ) {
			return;
		}

		var url = field.value.trim();
		if ( HTTPS.test( url ) ) {
			image.src = url;
			image.hidden = false;
		} else {
			image.removeAttribute( 'src' );
			image.hidden = true;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target instanceof Element ? event.target.closest( '[data-nyxify-media]' ) : null;
		if ( ! button || ! window.wp || ! window.wp.media ) {
			return;
		}

		var id = button.getAttribute( 'data-nyxify-media' );
		var field = document.getElementById( id );
		if ( ! ( field instanceof HTMLInputElement ) ) {
			return;
		}

		event.preventDefault();
		if ( ! frames[ id ] ) {
			var frame = window.wp.media( {
				title: button.getAttribute( 'data-title' ),
				button: { text: button.getAttribute( 'data-button' ) },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var chosen = frame.state().get( 'selection' ).first();
				if ( ! chosen ) {
					return;
				}

				field.value = chosen.get( 'url' );
				field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
			frames[ id ] = frame;
		}
		frames[ id ].open();
	} );

	document.addEventListener( 'input', function ( event ) {
		var field = event.target;
		if ( field instanceof HTMLInputElement && document.querySelector( '[data-nyxify-media="' + field.id + '"]' ) ) {
			preview( field );
		}
	} );
} )();
