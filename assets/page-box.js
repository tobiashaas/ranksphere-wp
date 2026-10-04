/**
 * RankSphere box on the edit screen: loads the page's data after the editor, so opening a post
 * never waits for RankSphere, and drives "Create suggestion" / "Apply". The server renders and
 * escapes all HTML (Admin\PageBox); this script only fetches and inserts it.
 */
( function () {
	'use strict';

	var POLL_MS = 3000;
	var POLL_MAX = 60;

	function failed( target, box ) {
		var message = document.createElement( 'p' );
		message.textContent = box.getAttribute( 'data-error' ) || '';
		target.replaceChildren( message );
	}

	function request( box, path, method, data ) {
		var id = box.getAttribute( 'data-ranksphere-page' ) || '';
		var options = {
			path: '/ranksphere/v1/' + path,
			method: method,
		};

		if ( 'GET' === method ) {
			options.path += '?post_id=' + encodeURIComponent( id );
		} else {
			options.data = Object.assign( { post_id: Number( id ) }, data || {} );
		}

		return window.wp.apiFetch( options );
	}

	/** Shows a suggestion state and keeps asking while RankSphere is still writing. */
	function show( box, response, round ) {
		var target = box.querySelector( '[data-ranksphere-suggestion]' );

		if ( ! target ) {
			return;
		}

		target.innerHTML = response && typeof response.html === 'string' ? response.html : '';

		if ( response && 'pending' === response.status && round < POLL_MAX ) {
			window.setTimeout( function () {
				request( box, 'page-suggestion', 'GET' )
					.then( function ( next ) {
						show( box, next, round + 1 );
					} )
					.catch( function () {
						failed( target, box );
					} );
			}, POLL_MS );
		}
	}

	function onClick( box, event ) {
		var button = event.target instanceof Element ? event.target.closest( 'button' ) : null;
		var target = box.querySelector( '[data-ranksphere-suggestion]' );

		if ( ! button || ! target ) {
			return;
		}

		if ( button.hasAttribute( 'data-ranksphere-reload' ) ) {
			window.location.reload();

			return;
		}

		if ( button.hasAttribute( 'data-ranksphere-suggest' ) ) {
			button.disabled = true;
			request( box, 'page-suggestion', 'POST' )
				.then( function ( response ) {
					show( box, response, 0 );
				} )
				.catch( function () {
					failed( target, box );
				} );

			return;
		}

		if ( button.hasAttribute( 'data-ranksphere-apply' ) ) {
			button.disabled = true;
			request( box, 'page-suggestion/apply', 'POST', {
				field: button.getAttribute( 'data-ranksphere-apply' ),
				value: button.getAttribute( 'data-value' ) || '',
			} )
				.then( function ( response ) {
					var result = document.createElement( 'div' );
					var proposal = button.closest( '.ranksphere-proposal' );
					result.innerHTML = response && typeof response.html === 'string' ? response.html : '';

					if ( proposal ) {
						proposal.replaceWith( result );
					}
				} )
				.catch( function () {
					button.disabled = false;
				} );
		}
	}

	function load( box ) {
		box.addEventListener( 'click', function ( event ) {
			onClick( box, event );
		} );

		request( box, 'page-insights', 'GET' )
			.then( function ( response ) {
				box.innerHTML = response && typeof response.html === 'string' ? response.html : '';
			} )
			.catch( function () {
				failed( box, box );
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
