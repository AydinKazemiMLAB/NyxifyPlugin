/*
 * Framing of the directory's screenshots, appended to the admin page's mockup by bin/screenshots.sh. The shot comes
 * from the address (?shot=N#state-lang): the mockup's switcher is hidden, and so are the cards the shot doesn't need;
 * the frame is moved to the top of the window and its height is written to the title for the second pass.
 */
setTimeout( function () {
	var shot = new URLSearchParams( location.search ).get( 'shot' );
	var hide = function ( sel ) {
		document.querySelectorAll( sel ).forEach( function ( el ) { el.style.display = 'none'; } );
	};
	var rect = function ( sel ) {
		var r = document.querySelector( sel ).getBoundingClientRect();
		return { top: r.top + window.scrollY, bottom: r.bottom + window.scrollY };
	};
	hide( '.mock-switcher' );
	var top = 0, bottom = 0, pad = 12;
	if ( '1' === shot ) {
		// Activating the shop with a license key.
		bottom = rect( '.nyxify-setup' ).bottom + pad;
	} else if ( '2' === shot ) {
		// The shop's status: the header, the notice and the four cards.
		var cards = document.querySelectorAll( '.nyxify-stat' );
		top = rect( '.wp-content' ).top;
		bottom = cards[ cards.length - 1 ].getBoundingClientRect().bottom + window.scrollY + pad;
	} else if ( '3' === shot ) {
		// Adding a bot with the messenger's token.
		hide( '.nyxify-admins-card' );
		hide( '.nyxify-connection' );
		top = rect( '.nyxify-bots-card' ).top - pad;
		bottom = rect( '.nyxify-bots-card' ).bottom + pad;
	} else if ( '4' === shot ) {
		// The admin PIN and the list of bot admins.
		hide( '.nyxify-bots-card' );
		hide( '.nyxify-connection' );
		top = Math.min( rect( '.nyxify-pin-card' ).top, rect( '.nyxify-admins-card' ).top ) - pad;
		bottom = Math.max( rect( '.nyxify-pin-card' ).bottom, rect( '.nyxify-admins-card' ).bottom ) + pad;
	}
	document.body.style.transform = 'translateY(-' + Math.round( top ) + 'px)';
	document.title = 'FRAME:' + Math.ceil( bottom - top );
}, 800 );
