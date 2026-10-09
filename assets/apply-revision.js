/**
 * "Take over into the original": fills the revision's title and text into the original's editor as
 * unsaved changes. Nothing changes until the author saves; then the server records it
 * (Admin\ApplyRevision). Block editor and classic editor.
 */
( function () {
	'use strict';

	var data = window.rankSphereApply;

	if ( ! data ) {
		return;
	}

	// A reload must not fill the text in a second time.
	function forgetLink() {
		try {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'ranksphere_apply' );
			url.searchParams.delete( '_wpnonce' );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			// Old browsers keep the address; the nonce still limits the link.
		}
	}

	function blockEditor() {
		var wp = window.wp;
		var tries = 0;
		var timer = window.setInterval( function () {
			var editor = wp.data.select( 'core/editor' );

			if ( ++tries > 100 ) {
				window.clearInterval( timer );
				return;
			}

			// Numbers may arrive as strings (older versions handed them over with wp_localize_script()).
			if ( ! editor || Number( editor.getCurrentPostId() ) !== Number( data.original ) ) {
				return;
			}

			window.clearInterval( timer );
			wp.data.dispatch( 'core/editor' ).editPost( { title: data.title } );
			wp.data.dispatch( 'core/editor' ).resetEditorBlocks( wp.blocks.parse( data.content ) );
			wp.data.dispatch( 'core/notices' ).createWarningNotice( data.inserted, { id: 'ranksphere-apply', isDismissible: true } );
			forgetLink();
			watchSave( wp );
		}, 100 );
	}

	// After the author's own save: tell the server, once – after the SEO plugin's meta boxes are
	// saved too, so their old values do not overwrite the fields taken over.
	function watchSave( wp ) {
		var editor = wp.data.select( 'core/editor' );
		var saving = false;
		var timer = null;
		var unsubscribe = wp.data.subscribe( function () {
			var postScreen = wp.data.select( 'core/edit-post' );
			var now = ( editor.isSavingPost() && ! editor.isAutosavingPost() ) || Boolean( postScreen && postScreen.isSavingMetaBoxes && postScreen.isSavingMetaBoxes() );

			if ( now ) {
				saving = true;
				window.clearTimeout( timer );
			} else if ( saving ) {
				saving = false;

				// The meta boxes start saving right after the post: wait a moment for them.
				timer = window.setTimeout( function () {
					if ( editor.didPostSaveRequestSucceed() ) {
						unsubscribe();
						record( wp );
					}
				}, 500 );
			}
		} );
	}

	function record( wp ) {
		wp.apiFetch( {
			path: '/ranksphere/v1/revisions/applied',
			method: 'POST',
			data: { original: Number( data.original ), revision: Number( data.revision ), before: Number( data.before ) },
		} ).then( function () {
			wp.data.dispatch( 'core/notices' ).removeNotice( 'ranksphere-apply' );
			wp.data.dispatch( 'core/notices' ).createSuccessNotice( data.applied, { id: 'ranksphere-applied', isDismissible: true } );
		} ).catch( function () {
			wp.data.dispatch( 'core/notices' ).removeNotice( 'ranksphere-apply' );
			wp.data.dispatch( 'core/notices' ).createErrorNotice( data.failed, { id: 'ranksphere-applied', isDismissible: true } );
		} );
	}

	function classicEditor() {
		var form = document.getElementById( 'post' );
		var title = document.getElementById( 'title' );
		var editor = window.tinymce && window.tinymce.get( 'content' );
		var textarea = document.getElementById( 'content' );

		if ( ! form ) {
			return;
		}

		if ( title ) {
			title.value = data.title;
			title.dispatchEvent( new Event( 'input' ) );
		}

		if ( editor && ! editor.isHidden() ) {
			editor.setContent( data.content );
		} else if ( textarea ) {
			textarea.value = data.content;
		}

		[ [ data.fields.revision, data.revision ], [ data.fields.before, data.before ], [ data.fields.nonce, data.nonce ] ].forEach( function ( field ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = field[ 0 ];
			input.value = String( field[ 1 ] );
			form.appendChild( input );
		} );

		var notice = document.createElement( 'div' );
		var text = document.createElement( 'p' );
		notice.className = 'notice notice-warning';
		text.textContent = data.inserted;
		notice.appendChild( text );
		form.parentNode.insertBefore( notice, form );
		forgetLink();
	}

	function start() {
		if ( data.block && window.wp && window.wp.data && window.wp.blocks ) {
			blockEditor();
		} else {
			classicEditor();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
