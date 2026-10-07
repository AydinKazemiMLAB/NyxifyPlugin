/**
 * WooNyx on the plugins list: asks for confirmation before WooNyx is deactivated, through its Deactivate link or the
 * bulk "Deactivate" action with its row checked. The plugin's basename and the translated warning come from
 * `window.woonyxPlugins`.
 *
 * @package WooNyx
 */

( function () {
	'use strict';

	var settings = window.woonyxPlugins;
	if ( ! settings || ! settings.basename || ! settings.message ) {
		return;
	}

	function isWooNyxRow( row ) {
		return !! row && row.getAttribute( 'data-plugin' ) === settings.basename;
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		var link = target.closest( '.deactivate a' );
		if ( link && isWooNyxRow( link.closest( 'tr[data-plugin]' ) ) && ! window.confirm( settings.message ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) || 'bulk-action-form' !== form.id ) {
			return;
		}

		var deactivating = Array.prototype.some.call(
			form.querySelectorAll( 'select[name="action"], select[name="action2"]' ),
			function ( select ) {
				return 'deactivate-selected' === select.value;
			}
		);
		if ( ! deactivating ) {
			return;
		}

		var checked = Array.prototype.some.call(
			form.querySelectorAll( 'input[name="checked[]"]:checked' ),
			function ( box ) {
				return box.value === settings.basename;
			}
		);
		if ( checked && ! window.confirm( settings.message ) ) {
			event.preventDefault();
		}
	} );
}() );
