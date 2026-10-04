/**
 * RankSphere box on the edit screen: loads the page's data after the editor, so opening a post
 * never waits for RankSphere. The server renders and escapes the HTML (Admin\PageBox).
 */
( function () {
	'use strict';

	function load( box ) {
		window.wp
			.apiFetch( {
				path: '/ranksphere/v1/page-insights?post_id=' + encodeURIComponent( box.getAttribute( 'data-ranksphere-page' ) || '' ),
			} )
			.then( function ( response ) {
				box.innerHTML = response && typeof response.html === 'string' ? response.html : '';
			} )
			.catch( function () {
				var message = document.createElement( 'p' );
				message.textContent = box.getAttribute( 'data-error' ) || '';
				box.replaceChildren( message );
			} );
	}

	function start() {
		document.querySelectorAll( '[data-ranksphere-page]' ).forEach( load );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
