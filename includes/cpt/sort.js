/**
 * DF post types: drag-and-drop + keyboard sort on the admin list table.
 * Saves menu_order via admin-ajax (nonce + edit_others_posts checked server side).
 * Vanilla JS, no dependencies. Config: window.dfCptSort (wp_localize_script).
 */
( function () {
	'use strict';

	var cfg = window.dfCptSort;
	var list = document.getElementById( 'the-list' );
	if ( ! cfg || ! list ) {
		return;
	}

	var status = document.createElement( 'p' );
	status.className = 'df-cpt-sort__status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	var table = list.closest( 'table' );
	if ( table && table.parentNode ) {
		table.parentNode.insertBefore( status, table );
	}

	var dragging = null;
	var timer = null;

	function rows() {
		return Array.prototype.filter.call( list.children, function ( tr ) {
			return tr.tagName === 'TR' && /^post-\d+$/.test( tr.id );
		} );
	}

	function say( text ) {
		status.textContent = text;
	}

	function save() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			var body = new FormData();
			body.append( 'action', cfg.action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'post_type', cfg.postType );
			body.append( 'offset', String( cfg.offset || 0 ) );
			rows().forEach( function ( tr ) {
				body.append( 'ids[]', tr.id.replace( 'post-', '' ) );
			} );
			say( cfg.i18n.saving );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					say( json && json.success ? cfg.i18n.saved : cfg.i18n.error );
				} )
				.catch( function () {
					say( cfg.i18n.error );
				} );
		}, 300 );
	}

	function setup( tr ) {
		var handle = tr.querySelector( '[data-df-sort-handle]' );
		if ( ! handle ) {
			return;
		}
		handle.title = cfg.i18n.handle;
		// Only the handle starts a drag, so text in the row stays selectable.
		handle.addEventListener( 'mousedown', function () {
			tr.draggable = true;
		} );
		handle.addEventListener( 'keydown', function ( e ) {
			var all = rows();
			var i = all.indexOf( tr );
			var target = null;
			if ( e.key === 'ArrowUp' && i > 0 ) {
				target = all[ i - 1 ];
				list.insertBefore( tr, target );
			} else if ( e.key === 'ArrowDown' && i < all.length - 1 ) {
				target = all[ i + 1 ];
				list.insertBefore( target, tr );
			} else {
				return;
			}
			e.preventDefault();
			handle.focus();
			say( cfg.i18n.moved.replace( '%d', String( rows().indexOf( tr ) + 1 ) ) );
			save();
		} );
	}

	list.addEventListener( 'dragstart', function ( e ) {
		var tr = e.target.closest && e.target.closest( 'tr' );
		if ( ! tr || ! tr.draggable ) {
			return;
		}
		dragging = tr;
		tr.classList.add( 'df-cpt-sort--dragging' );
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', tr.id );
	} );

	list.addEventListener( 'dragover', function ( e ) {
		if ( ! dragging ) {
			return;
		}
		e.preventDefault();
		var tr = e.target.closest( 'tr' );
		rows().forEach( function ( r ) {
			r.classList.toggle( 'df-cpt-sort--over', r === tr && r !== dragging );
		} );
	} );

	list.addEventListener( 'drop', function ( e ) {
		if ( ! dragging ) {
			return;
		}
		e.preventDefault();
		var tr = e.target.closest( 'tr' );
		if ( tr && tr !== dragging && tr.parentNode === list ) {
			var all = rows();
			if ( all.indexOf( dragging ) < all.indexOf( tr ) ) {
				list.insertBefore( dragging, tr.nextSibling );
			} else {
				list.insertBefore( dragging, tr );
			}
			save();
		}
	} );

	list.addEventListener( 'dragend', function () {
		if ( dragging ) {
			dragging.classList.remove( 'df-cpt-sort--dragging' );
			dragging.draggable = false;
		}
		rows().forEach( function ( r ) {
			r.classList.remove( 'df-cpt-sort--over' );
		} );
		dragging = null;
	} );

	document.addEventListener( 'mouseup', function () {
		if ( ! dragging ) {
			rows().forEach( function ( r ) {
				r.draggable = false;
			} );
		}
	} );

	rows().forEach( setup );
}() );
