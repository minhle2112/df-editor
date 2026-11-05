/**
 * FAQ section: "single open" accordion + edit state (DF Inhalt active -> all items open).
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var EDIT = 'df-edit-active';

	function editing() {
		return root.classList.contains( EDIT );
	}

	// Single open: close siblings when one opens (not while editing).
	document.addEventListener( 'toggle', function ( e ) {
		var item = e.target;
		if ( ! item || ! item.matches || ! item.matches( '.df-faq__item' ) || ! item.open || editing() ) {
			return;
		}
		var list = item.closest( '[data-df-single-open]' );
		if ( ! list ) {
			return;
		}
		list.querySelectorAll( '.df-faq__item[open]' ).forEach( function ( other ) {
			if ( other !== item ) {
				other.open = false;
			}
		} );
	}, true );

	// Edit state: open all items, restore the previous state when editing ends.
	function sync() {
		document.querySelectorAll( '.df-faq__item' ).forEach( function ( item ) {
			if ( editing() ) {
				if ( ! item.hasAttribute( 'data-df-was' ) ) {
					item.setAttribute( 'data-df-was', item.open ? '1' : '0' );
				}
				item.open = true;
			} else if ( item.hasAttribute( 'data-df-was' ) ) {
				item.open = '1' === item.getAttribute( 'data-df-was' );
				item.removeAttribute( 'data-df-was' );
			}
		} );
	}

	function init() {
		sync();
		new MutationObserver( sync ).observe( root, { attributes: true, attributeFilter: [ 'class' ] } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
