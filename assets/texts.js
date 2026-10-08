/**
 * RankSphere → Texts: post type and "new or existing" (the posts of the type, searchable by title),
 * the facts and the hint of the chosen kind of text, and a reload while RankSphere is writing. The
 * server renders everything (Admin\TextsPage); this only switches and fills.
 */
( function () {
	'use strict';

	var labels = window.rankSphereTexts || {};

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

	function existingChosen() {
		var checked = document.querySelector( '[data-ranksphere-mode]:checked' );

		return Boolean( checked && 'existing' === checked.value );
	}

	function switchMode() {
		var existing = existingChosen();
		var box = document.querySelector( '[data-ranksphere-existing]' );
		var posts = document.querySelector( '[data-ranksphere-posts]' );
		var topic = document.getElementById( 'rs-topic' );

		if ( box ) {
			box.hidden = ! existing;
		}

		if ( posts ) {
			posts.required = existing;
		}

		if ( topic ) {
			topic.required = ! existing;
			topic.placeholder = existing ? labels.topicSource || '' : labels.topicNew || '';
		}
	}

	// The posts of a type the user may edit, optionally by title.
	function loadPosts( postType, search ) {
		var select = document.querySelector( '[data-ranksphere-posts]' );

		if ( ! select || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		var path = '/ranksphere/v1/editable-posts?post_type=' + encodeURIComponent( postType ) + ( search ? '&search=' + encodeURIComponent( search ) : '' );

		window.wp.apiFetch( { path: path } ).then( function ( answer ) {
			var current = select.value;
			var first = select.options[ 0 ];

			select.innerHTML = '';
			select.appendChild( first );

			( answer && answer.posts ? answer.posts : [] ).forEach( function ( post ) {
				var option = document.createElement( 'option' );
				option.value = String( post.id );
				option.textContent = post.title;
				option.selected = String( post.id ) === current;
				select.appendChild( option );
			} );

			if ( select.options.length === 1 && labels.none ) {
				var none = document.createElement( 'option' );
				none.disabled = true;
				none.textContent = labels.none;
				select.appendChild( none );
			}
		} ).catch( function () {
			// The list from the server stays.
		} );
	}

	function start() {
		var kind = document.querySelector( '[data-ranksphere-type]' );
		var postType = document.querySelector( '[data-ranksphere-post-type]' );
		var search = document.querySelector( '[data-ranksphere-post-search]' );
		var kindTouched = false;
		var timer;

		if ( kind ) {
			kind.addEventListener( 'change', function () {
				kindTouched = true;
				switchType( kind );
			} );
		}

		if ( postType ) {
			postType.addEventListener( 'change', function () {
				var option = postType.options[ postType.selectedIndex ];
				var suggested = option ? option.getAttribute( 'data-kind' ) : '';

				// Pages usually present a service, posts answer a question – unless chosen already.
				if ( kind && ! kindTouched && suggested ) {
					kind.value = suggested;
					switchType( kind );
				}

				if ( search ) {
					search.value = '';
				}

				loadPosts( postType.value, '' );
			} );
		}

		if ( search && postType ) {
			search.hidden = false;
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					loadPosts( postType.value, search.value.trim() );
				}, 300 );
			} );
		}

		document.querySelectorAll( '[data-ranksphere-mode]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', switchMode );
		} );

		if ( document.querySelector( '[data-ranksphere-mode]' ) ) {
			switchMode();
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
