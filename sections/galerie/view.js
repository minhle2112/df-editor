/**
 * Galerie section: lightbox with a native <dialog>.
 * Keyboard: Esc closes (native), ArrowLeft / ArrowRight switch images, focus returns to the trigger.
 * Never opens while DF Inhalt is active (html.df-edit-active) so images stay editable.
 * Vanilla JS, no dependencies. Labels come from data-df-lightbox (translated in PHP).
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var EDIT = 'df-edit-active';
	var state = null; // { dialog, img, caption, counter, buttons, index, trigger, i18n }

	function editing() {
		return root.classList.contains( EDIT );
	}

	function format( str, a, b ) {
		return String( str || '' ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	// Largest srcset candidate of the image currently in the tile (stays correct after an edit).
	function bestSource( button ) {
		var media = button.closest( '.df-galerie__media' );
		var img = media ? media.querySelector( 'img' ) : null;
		var best = '';
		var bestW = 0;
		if ( img && img.getAttribute( 'srcset' ) ) {
			img.getAttribute( 'srcset' ).split( ',' ).forEach( function ( part ) {
				var bits = part.trim().split( /\s+/ );
				var w = parseInt( bits[ 1 ], 10 ) || 0;
				if ( bits[ 0 ] && w >= bestW ) {
					bestW = w;
					best = bits[ 0 ];
				}
			} );
		}
		return {
			src: best || button.getAttribute( 'data-df-full' ) || ( img ? img.currentSrc || img.src : '' ),
			alt: img ? img.getAttribute( 'alt' ) || '' : ''
		};
	}

	function caption( button ) {
		var figure = button.closest( '.df-galerie__figure' );
		var cap = figure ? figure.querySelector( '.df-galerie__caption' ) : null;
		var text = cap ? cap.textContent.trim() : '';
		return text || button.getAttribute( 'data-df-caption' ) || '';
	}

	function el( tag, cls, attrs ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		return node;
	}

	function build( list, i18n ) {
		var dialog = el( 'dialog', 'df-galerie__dialog', { 'aria-label': i18n.dialog || 'Image viewer' } );
		var bar = el( 'div', 'df-galerie__bar' );
		var counter = el( 'p', 'df-galerie__counter', { 'aria-live': 'polite' } );
		counter.style.margin = '0';
		var close = el( 'button', 'df-galerie__btn df-galerie__btn--close', { type: 'button', 'aria-label': i18n.close || 'Close' } );
		close.textContent = '×';
		bar.appendChild( counter );
		bar.appendChild( close );

		var stage = el( 'figure', 'df-galerie__stage' );
		var img = el( 'img', '', { alt: '', decoding: 'async' } );
		var prev = el( 'button', 'df-galerie__btn df-galerie__btn--prev', { type: 'button', 'aria-label': i18n.prev || 'Previous' } );
		prev.textContent = '‹';
		var next = el( 'button', 'df-galerie__btn df-galerie__btn--next', { type: 'button', 'aria-label': i18n.next || 'Next' } );
		next.textContent = '›';
		stage.appendChild( prev );
		stage.appendChild( img );
		stage.appendChild( next );

		var cap = el( 'figcaption', '' );

		dialog.appendChild( bar );
		dialog.appendChild( stage );
		dialog.appendChild( cap );

		// Inside the section, so the --df-* colour variables of its scheme apply.
		( list.closest( '.df-section' ) || document.body ).appendChild( dialog );

		close.addEventListener( 'click', function () {
			dialog.close();
		} );
		prev.addEventListener( 'click', function () {
			step( -1 );
		} );
		next.addEventListener( 'click', function () {
			step( 1 );
		} );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog || e.target === stage ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) {
				e.preventDefault();
				step( -1 );
			} else if ( e.key === 'ArrowRight' ) {
				e.preventDefault();
				step( 1 );
			}
		} );
		dialog.addEventListener( 'close', function () {
			var trigger = state && state.trigger;
			img.removeAttribute( 'src' );
			if ( trigger && document.contains( trigger ) ) {
				trigger.focus();
			}
			state = null;
		} );

		return { dialog: dialog, img: img, caption: cap, counter: counter, prev: prev, next: next, close: close };
	}

	function buttons( list ) {
		return Array.prototype.slice.call( list.querySelectorAll( '.df-galerie__zoom' ) );
	}

	function show( index ) {
		var all = state.buttons;
		state.index = ( index + all.length ) % all.length;
		var btn = all[ state.index ];
		var src = bestSource( btn );
		var text = caption( btn );
		var count = format( state.i18n.count, state.index + 1, all.length );
		state.ui.img.src = src.src;
		// Never an empty alt in the viewer: caption, then image alt, then "Image n of m".
		state.ui.img.alt = text || src.alt || count;
		state.ui.caption.textContent = text;
		state.ui.counter.textContent = count;
		var multi = all.length > 1;
		state.ui.prev.hidden = ! multi;
		state.ui.next.hidden = ! multi;
	}

	function step( dir ) {
		if ( state ) {
			show( state.index + dir );
		}
	}

	function open( list, button ) {
		var i18n = {};
		try {
			i18n = JSON.parse( list.getAttribute( 'data-df-lightbox' ) || '{}' ) || {};
		} catch ( err ) {
			i18n = {};
		}
		if ( ! list.dfLightbox ) {
			list.dfLightbox = build( list, i18n );
		}
		var all = buttons( list );
		state = { ui: list.dfLightbox, buttons: all, index: 0, trigger: button, i18n: i18n };
		show( all.indexOf( button ) );
		if ( typeof state.ui.dialog.showModal === 'function' ) {
			state.ui.dialog.showModal();
			state.ui.close.focus();
		} else {
			// Very old browsers: open the image directly.
			window.location.href = state.ui.img.src;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest ? e.target.closest( '.df-galerie__zoom' ) : null;
		if ( ! button ) {
			return;
		}
		var list = button.closest( '[data-df-lightbox]' );
		if ( ! list || editing() ) {
			return; // Edit mode: let DF Inhalt handle the click on the image.
		}
		e.preventDefault();
		open( list, button );
	} );

	// Editing switched on while the lightbox is open: close it.
	new MutationObserver( function () {
		if ( editing() && state && state.ui.dialog.open ) {
			state.ui.dialog.close();
		}
	} ).observe( root, { attributes: true, attributeFilter: [ 'class' ] } );
}() );
