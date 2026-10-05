/**
 * RankSphere → Texts: shows the facts and the hint of the chosen kind of text, and reloads the page
 * while RankSphere is writing (the server renders everything, Admin\TextsPage).
 */
( function () {
	'use strict';

	function switchType( select ) {
		var option = select.options[ select.selectedIndex ];
		var hint = document.querySelector( '[data-ranksphere-type-hint]' );

		if ( hint && option ) {
			hint.textContent = option.getAttribute( 'data-hint' ) || '';
		}

		document.querySelectorAll( '[data-ranksphere-type-fields]' ).forEach( function ( fields ) {
			fields.hidden = fields.getAttribute( 'data-ranksphere-type-fields' ) !== select.value;
		} );
	}

	function start() {
		var select = document.querySelector( '[data-ranksphere-type]' );

		if ( select ) {
			select.addEventListener( 'change', function () {
				switchType( select );
			} );
		}

		// While RankSphere writes: look again every 8 seconds, for up to five minutes.
		var waiting = Boolean( document.querySelector( '[data-ranksphere-refresh]' ) );
		var rounds = 0;

		try {
			rounds = waiting ? Number( window.sessionStorage.getItem( 'ranksphere-refresh' ) || '0' ) : 0;
			window.sessionStorage.setItem( 'ranksphere-refresh', String( waiting ? rounds + 1 : 0 ) );
		} catch ( e ) {
			// Without storage it simply keeps looking.
		}

		if ( waiting && rounds < 40 ) {
			window.setTimeout( function () {
				window.location.reload();
			}, 8000 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
