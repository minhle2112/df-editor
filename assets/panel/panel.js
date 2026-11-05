/**
 * DF Panel: full-screen layout editor (vanilla JS, ES2020, no dependencies).
 *
 * Talks to the REST API df/v1 (see includes/panel/class-panel.php for the localized config).
 * Every change updates the local layout, goes on the undo stack and is saved as a draft
 * (debounced PUT). The preview iframe reloads after each successful save.
 */
( function () {
	'use strict';

	const cfg = window.dfPanelConfig || {};
	const L = window.dfPanelI18n || {};
	const root = document.getElementById( 'df-panel' );
	if ( ! root ) {
		return;
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	const t = ( key, ...args ) => {
		let s = Object.prototype.hasOwnProperty.call( L, key ) ? String( L[ key ] ) : key;
		let i = 0;
		s = s.replace( /%(?:(\d+)\$)?([sd])/g, ( m, pos ) => {
			const idx = pos ? Number( pos ) - 1 : i++;
			return args[ idx ] !== undefined ? String( args[ idx ] ) : '';
		} );
		return s;
	};

	/** Creates an element: h( 'button.df-panel__btn', { attrs }, ...children ). */
	function h( spec, props, ...children ) {
		const [ tag, ...classes ] = spec.split( '.' );
		const el = document.createElement( tag || 'div' );
		if ( classes.length ) {
			el.className = classes.join( ' ' );
		}
		if ( props ) {
			for ( const [ k, v ] of Object.entries( props ) ) {
				if ( v === null || v === undefined || v === false ) {
					continue;
				}
				if ( k === 'text' ) {
					el.textContent = v;
				} else if ( k === 'html' ) {
					el.innerHTML = v; // only used with static SVG strings below
				} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
					el.addEventListener( k.slice( 2 ).toLowerCase(), v );
				} else if ( k === 'class' ) {
					el.className = ( el.className ? el.className + ' ' : '' ) + v;
				} else if ( k === 'dataset' ) {
					Object.assign( el.dataset, v );
				} else if ( v === true ) {
					el.setAttribute( k, '' );
				} else {
					el.setAttribute( k, String( v ) );
				}
			}
		}
		for ( const c of children.flat() ) {
			if ( c === null || c === undefined || c === false ) {
				continue;
			}
			el.append( c instanceof Node ? c : document.createTextNode( String( c ) ) );
		}
		return el;
	}

	const ICONS = {
		drag: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="7" cy="5" r="1.5"/><circle cx="13" cy="5" r="1.5"/><circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/><circle cx="7" cy="15" r="1.5"/><circle cx="13" cy="15" r="1.5"/></svg>',
		up: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 6l-5 5 1.4 1.4L10 8.8l3.6 3.6L15 11z"/></svg>',
		down: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 14l5-5-1.4-1.4L10 11.2 6.4 7.6 5 9z"/></svg>',
		eye: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 4C5.5 4 2.2 7.1 1 10c1.2 2.9 4.5 6 9 6s7.8-3.1 9-6c-1.2-2.9-4.5-6-9-6zm0 10a4 4 0 110-8 4 4 0 010 8zm0-6a2 2 0 100 4 2 2 0 000-4z"/></svg>',
		eyeOff: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3.3 2.3L2 3.6l2.6 2.6C3 7.3 1.7 8.6 1 10c1.2 2.9 4.5 6 9 6 1.6 0 3-.4 4.3-1l2.4 2.4 1.3-1.3L3.3 2.3zM10 14a4 4 0 01-3.8-5.2l1.6 1.6a2 2 0 002 2l1.6 1.6c-.4 0-.9.1-1.4 0zM10 4c4.5 0 7.8 3.1 9 6-.5 1.2-1.4 2.5-2.6 3.6l-2.5-2.5A4 4 0 008.9 6.1L7 4.4C8 4.1 9 4 10 4z"/></svg>',
		more: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="4" cy="10" r="1.8"/><circle cx="10" cy="10" r="1.8"/><circle cx="16" cy="10" r="1.8"/></svg>',
		lock: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M14 8V6a4 4 0 10-8 0v2H5v10h10V8h-1zM8 6a2 2 0 114 0v2H8V6z"/></svg>',
		plus: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M9 4h2v5h5v2h-5v5H9v-5H4V9h5z"/></svg>',
		back: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M12.6 4.6L7.2 10l5.4 5.4 1.4-1.4-4-4 4-4z"/></svg>',
		close: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5.4 4L4 5.4 8.6 10 4 14.6 5.4 16l4.6-4.6 4.6 4.6 1.4-1.4-4.6-4.6L16 5.4 14.6 4 10 8.6z"/></svg>',
		undo: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8 5V2L3 7l5 5V9c3.3 0 6 1.4 7 5 0-4.4-2.7-8.3-7-9z"/></svg>',
		redo: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M12 5V2l5 5-5 5V9c-3.3 0-6 1.4-7 5 0-4.4 2.7-8.3 7-9z"/></svg>',
		history: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 3a7 7 0 00-7 7H1l3 3 3-3H5a5 5 0 115 5v2a7 7 0 000-14zm-1 3v5l4 2.4.8-1.3L10.5 10V6H9z"/></svg>',
		desktop: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M2 3h16v11H11v2h3v1H6v-1h3v-2H2V3zm2 2v7h12V5H4z"/></svg>',
		tablet: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 2h10a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V3a1 1 0 011-1zm1 2v11h8V4H6zm4 12.2a.8.8 0 100-1.6.8.8 0 000 1.6z"/></svg>',
		mobile: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M7 2h6a1 1 0 011 1v14a1 1 0 01-1 1H7a1 1 0 01-1-1V3a1 1 0 011-1zm1 2v10h4V4H8zm2 12.2a.8.8 0 100-1.6.8.8 0 000 1.6z"/></svg>',
		menu: '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3 5h14v2H3zm0 4h14v2H3zm0 4h14v2H3z"/></svg>',
	};
	const icon = ( name ) => h( 'span.df-panel__icon', { html: ICONS[ name ] || '' } );

	const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
	const humanize = ( v ) => {
		const s = String( v ?? '' ).replace( /[-_]+/g, ' ' ).trim();
		return s ? s.charAt( 0 ).toUpperCase() + s.slice( 1 ) : '';
	};
	const slugify = ( v ) =>
		String( v || '' )
			.toLowerCase()
			.normalize( 'NFKD' )
			.replace( /ä/g, 'ae' ).replace( /ö/g, 'oe' ).replace( /ü/g, 'ue' ).replace( /ß/g, 'ss' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 60 );

	const withArgs = ( url, args ) => {
		const u = new URL( url, window.location.href );
		for ( const [ k, v ] of Object.entries( args ) ) {
			if ( v === null ) {
				u.searchParams.delete( k );
			} else {
				u.searchParams.set( k, v );
			}
		}
		return u.toString();
	};

	const randomSuffix = () => {
		const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		let s = '';
		const buf = new Uint8Array( 4 );
		( window.crypto || {} ).getRandomValues ? window.crypto.getRandomValues( buf ) : buf.forEach( ( _, i ) => ( buf[ i ] = Math.random() * 256 ) );
		buf.forEach( ( b ) => ( s += chars[ b % chars.length ] ) );
		return s;
	};

	/* ------------------------------------------------------------------ *
	 * REST
	 * ------------------------------------------------------------------ */

	let nonce = cfg.nonce || '';

	/** Identifies this panel window for the edit lock (same user in two tabs = conflict). */
	const panelToken = ( () => {
		const buf = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( buf );
		} else {
			buf.forEach( ( _, i ) => ( buf[ i ] = Math.random() * 256 ) );
		}
		return Array.from( buf, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	} )();

	function apiUrl( path ) {
		const base = String( cfg.restBase || '/wp-json/df/v1/' );
		return base + String( path ).replace( /^\/+/, '' );
	}

	async function refreshNonce() {
		try {
			const r = await fetch( cfg.nonceUrl, { credentials: 'same-origin' } );
			if ( r.ok ) {
				const n = ( await r.text() ).trim();
				if ( /^[a-f0-9]{6,}$/i.test( n ) ) {
					nonce = n;
					return true;
				}
			}
		} catch ( e ) {}
		return false;
	}

	/**
	 * Returns { ok, status, data }. Never throws for HTTP errors.
	 */
	async function api( method, path, body, retried ) {
		const opts = {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce, 'X-DF-Panel-Token': panelToken, Accept: 'application/json' },
		};
		if ( body !== undefined ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		let res;
		try {
			res = await fetch( apiUrl( path ), opts );
		} catch ( e ) {
			return { ok: false, status: 0, data: null };
		}
		let data = null;
		try {
			data = await res.json();
		} catch ( e ) {}
		if ( res.status === 403 && ! retried && data && data.code === 'rest_cookie_invalid_nonce' && ( await refreshNonce() ) ) {
			return api( method, path, body, true );
		}
		return { ok: res.ok, status: res.status, data };
	}

	/** WP_Error responses nest custom fields in data.data; flatten both. */
	const errData = ( d ) => Object.assign( {}, d || {}, ( d && typeof d.data === 'object' && d.data ) || {} );

	/* ------------------------------------------------------------------ *
	 * State
	 * ------------------------------------------------------------------ */

	const S = {
		postId: Number( cfg.postId ) || 0,
		pages: [],
		schema: null, // { sections, schemes, spacing, widths }
		types: {}, // type => section def
		resp: null, // last GET layout response
		rev: 0,
		layout: null, // working copy (draft or live)
		hasDraft: false,
		undo: [],
		redo: [],
		status: 'published',
		dirty: false,
		saving: false,
		saveAgain: false,
		saveTimer: 0,
		publishing: false,
		foreignDraft: false,
		errors: { global: [], rows: {} },
		warnings: { global: [], rows: {} },
		selected: null, // instance id shown in the options view
		tab: 'layout',
		device: 'desktop',
		readonly: false,
		reorderOnly: false,
		canEdit: false,
		lockHolder: null,
		hadLock: false,
		conflict: false,
		pendingScroll: null,
		frameScroll: 0,
		sidebarOpen: window.innerWidth > 900,
	};

	const UI = {}; // element references

	/* ------------------------------------------------------------------ *
	 * Layout helpers
	 * ------------------------------------------------------------------ */

	const sections = () => ( S.layout && Array.isArray( S.layout.sections ) ? S.layout.sections : [] );
	const byId = ( id ) => sections().find( ( s ) => s.id === id ) || null;
	const indexOf = ( id ) => sections().findIndex( ( s ) => s.id === id );
	const def = ( entry ) => S.types[ entry && entry.section ] || null;
	const label = ( entry ) => {
		const d = def( entry );
		return ( d && d.label ) || humanize( entry && entry.section ) || ( entry && entry.id ) || '';
	};
	const variantLabel = ( entry ) => {
		const d = def( entry );
		const v = ( d && ( d.variants || [] ).find( ( x ) => x.value === entry.variant ) ) || null;
		return v ? v.label : humanize( entry.variant );
	};
	const listLabel = ( list, value ) => {
		const v = ( list || [] ).find( ( x ) => x.value === value );
		return v ? v.label : humanize( value );
	};
	const schemeOf = ( entry ) => entry.scheme || 'hell';

	function uniqueId( type ) {
		const taken = new Set( sections().map( ( s ) => s.id ) );
		const base = String( type ).replace( /[^a-z0-9_-]/g, '' ) || 'section';
		let id;
		do {
			id = base + '-' + randomSuffix();
		} while ( taken.has( id ) );
		return id;
	}

	function newEntry( d ) {
		const options = {};
		for ( const o of d.options || [] ) {
			if ( o.key !== 'count' && o.default !== undefined && o.default !== null ) {
				options[ o.key ] = String( o.default );
			}
		}
		return {
			id: uniqueId( d.type ),
			section: d.type,
			v: Number( d.version ) || 1,
			variant: ( d.variants && d.variants[ 0 ] && d.variants[ 0 ].value ) || '',
			scheme: 'hell',
			spacing: { top: 'm', bottom: 'm' },
			width: 'container',
			hidden: false,
			anchor: '',
			locked: false,
			options,
			content: {},
		};
	}

	/**
	 * Moves the unlocked section `id` so that it lands at overall index `target`;
	 * locked sections keep their indices. Returns the new index or -1.
	 */
	function moveTo( id, target ) {
		const list = sections();
		const from = indexOf( id );
		if ( from < 0 || list[ from ].locked ) {
			return -1;
		}
		const slots = []; // indices of unlocked positions
		list.forEach( ( s, i ) => ! s.locked && slots.push( i ) );
		const movable = slots.map( ( i ) => list[ i ] );
		const fromSlot = slots.indexOf( from );
		// Target slot = the unlocked slot nearest to the target index.
		let toSlot = 0;
		let best = Infinity;
		slots.forEach( ( idx, n ) => {
			const d = Math.abs( idx - target );
			if ( d < best ) {
				best = d;
				toSlot = n;
			}
		} );
		if ( toSlot === fromSlot ) {
			return from;
		}
		const [ item ] = movable.splice( fromSlot, 1 );
		movable.splice( toSlot, 0, item );
		slots.forEach( ( idx, n ) => ( list[ idx ] = movable[ n ] ) );
		return slots[ toSlot ];
	}

	/** Next unlocked index above/below, or -1. */
	function neighbourSlot( id, dir ) {
		const list = sections();
		let i = indexOf( id ) + dir;
		while ( i >= 0 && i < list.length ) {
			if ( ! list[ i ].locked ) {
				return i;
			}
			i += dir;
		}
		return -1;
	}

	/* ------------------------------------------------------------------ *
	 * Announcements / status
	 * ------------------------------------------------------------------ */

	let announceTimer = 0;
	function announce( msg ) {
		if ( ! UI.live ) {
			return;
		}
		UI.live.textContent = '';
		window.clearTimeout( announceTimer );
		announceTimer = window.setTimeout( () => ( UI.live.textContent = msg ), 60 );
	}

	function setStatus( status ) {
		const prev = S.status;
		S.status = status;
		renderStatus();
		// UI.status is not a live region (it changes after every action); announce only failures.
		if ( prev !== status && ( status === 'error' || status === 'invalid' ) ) {
			announce( UI.status ? UI.status.textContent : '' );
		}
	}

	function renderStatus() {
		if ( ! UI.status ) {
			return;
		}
		const map = {
			published: 'statusPublished',
			draft: 'statusDraft',
			unsaved: 'statusUnsaved',
			saving: 'statusSaving',
			invalid: 'statusInvalid',
			error: 'statusError',
		};
		const key = S.readonly ? 'statusReadonly' : map[ S.status ] || 'statusPublished';
		UI.status.textContent = t( key );
		UI.status.title = t( key );
		UI.status.dataset.status = S.readonly ? 'readonly' : S.status;
		UI.undo.disabled = S.readonly || ! S.undo.length;
		UI.redo.disabled = S.readonly || ! S.redo.length;
		const busy = S.saving || S.dirty;
		UI.publish.disabled = S.publishing || S.readonly || S.conflict || ( ! S.hasDraft && ! busy ) || S.status === 'invalid';
		UI.discard.disabled = S.publishing || S.readonly || ( ! S.hasDraft && ! busy );
		UI.historyBtn.disabled = ! S.resp;
	}

	function notice( msg, type = 'info', actions = [] ) {
		const box = h(
			'div.df-panel__notice',
			{ class: 'is-' + type, role: type === 'error' ? 'alert' : 'status' },
			h( 'p', { text: msg } ),
			h(
				'div.df-panel__notice-actions',
				null,
				actions.map( ( a ) => h( 'button.df-panel__btn.is-small', { type: 'button', onClick: a.onClick, text: a.label } ) ),
				h( 'button.df-panel__icon-btn.is-small', { type: 'button', 'aria-label': t( 'dismiss' ), onClick: () => box.remove() }, icon( 'close' ) )
			)
		);
		UI.notices.append( box );
		if ( type !== 'error' ) {
			// Live regions inserted together with their text are often not read; announce explicitly.
			announce( msg );
		}
		return box;
	}
	const clearNotices = ( kind ) =>
		UI.notices.querySelectorAll( kind ? '[data-kind="' + kind + '"]' : '.df-panel__notice' ).forEach( ( n ) => n.remove() );

	/* ------------------------------------------------------------------ *
	 * Validation messages → rows
	 * ------------------------------------------------------------------ */

	function bucket( items ) {
		const out = { global: [], rows: {} };
		const ids = sections().map( ( s ) => s.id );
		for ( const item of items || [] ) {
			let msg = item;
			let id = null;
			if ( item && typeof item === 'object' ) {
				msg = item.message || item.msg || JSON.stringify( item );
				id = item.id || item.section_id || item.instance || null;
				if ( ! id && Number.isInteger( item.index ) && ids[ item.index ] ) {
					id = ids[ item.index ];
				}
			}
			msg = String( msg );
			if ( ! id ) {
				// Validator messages start with "Section #<n> (<id>):" (translatable label); use that id.
				const lead = /^[^(:]*\(([^)\s]+)\)/.exec( msg );
				if ( lead && ids.includes( lead[ 1 ] ) ) {
					id = lead[ 1 ];
				}
			}
			if ( ! id ) {
				// Validator messages start with "Section #<n>" (1-based position in the sent layout).
				const m = /^Section #(\d+)/.exec( msg );
				if ( m && ids[ Number( m[ 1 ] ) - 1 ] && ! ids.some( ( x ) => msg.includes( x ) ) ) {
					id = ids[ Number( m[ 1 ] ) - 1 ];
				}
			}
			if ( ! id ) {
				// Longest id first so "faq-ab12" does not match inside "faq-ab123".
				id = [ ...ids ].sort( ( a, b ) => b.length - a.length ).find( ( x ) => msg.includes( x ) ) || null;
			}
			if ( id && ids.includes( id ) ) {
				( out.rows[ id ] = out.rows[ id ] || [] ).push( msg );
			} else {
				out.global.push( msg );
			}
		}
		return out;
	}

	function setValidation( v ) {
		S.errors = bucket( ( v && v.errors ) || [] );
		S.warnings = bucket( ( v && v.warnings ) || [] );
	}

	/* ------------------------------------------------------------------ *
	 * Changes, undo, save
	 * ------------------------------------------------------------------ */

	/**
	 * Applies a mutation to the working layout. `fn` returns false to cancel.
	 */
	function change( fn, msg ) {
		if ( S.readonly || ! S.layout ) {
			return false;
		}
		if ( S.publishing ) {
			announce( t( 'publishWait' ) );
			return false;
		}
		const before = JSON.stringify( S.layout );
		if ( fn( S.layout ) === false ) {
			return false;
		}
		if ( JSON.stringify( S.layout ) === before ) {
			return false;
		}
		S.undo.push( before );
		if ( S.undo.length > 100 ) {
			S.undo.shift();
		}
		S.redo = [];
		afterChange( msg );
		return true;
	}

	function afterChange( msg ) {
		S.dirty = true;
		setStatus( 'unsaved' );
		scheduleSave();
		renderSidebar();
		if ( msg ) {
			announce( msg );
		}
	}

	function undo() {
		if ( S.publishing ) {
			announce( t( 'publishWait' ) );
			return;
		}
		if ( S.readonly || ! S.undo.length ) {
			announce( t( 'nothingToUndo' ) );
			return;
		}
		S.redo.push( JSON.stringify( S.layout ) );
		S.layout = JSON.parse( S.undo.pop() );
		if ( S.selected && ! byId( S.selected ) ) {
			S.selected = null;
		}
		afterChange( t( 'undone' ) );
	}

	function redo() {
		if ( S.publishing ) {
			announce( t( 'publishWait' ) );
			return;
		}
		if ( S.readonly || ! S.redo.length ) {
			announce( t( 'nothingToRedo' ) );
			return;
		}
		S.undo.push( JSON.stringify( S.layout ) );
		S.layout = JSON.parse( S.redo.pop() );
		if ( S.selected && ! byId( S.selected ) ) {
			S.selected = null;
		}
		afterChange( t( 'redone' ) );
	}

	function scheduleSave() {
		window.clearTimeout( S.saveTimer );
		S.saveTimer = window.setTimeout( saveDraft, Number( cfg.saveDelay ) || 400 );
	}

	async function saveDraft() {
		window.clearTimeout( S.saveTimer );
		if ( S.readonly || S.conflict || ! S.dirty ) {
			return true;
		}
		if ( S.saving ) {
			S.saveAgain = true;
			return false;
		}
		S.saving = true;
		S.dirty = false;
		setStatus( 'saving' );
		const sent = JSON.stringify( S.layout );
		const r = await api( 'PUT', 'panel/layout/' + S.postId + '/draft', { layout: JSON.parse( sent ), base_rev: S.rev } );
		S.saving = false;
		let ok = false;

		const current = () => JSON.stringify( S.layout ) === sent;
		if ( r.ok ) {
			ok = true;
			S.hasDraft = true;
			if ( r.data && r.data.preview_url && S.resp ) {
				S.resp.preview_url = r.data.preview_url; // fresh preview nonce
			}
			if ( current() ) {
				// Messages refer to positions in the sent layout; skip them when it changed meanwhile.
				setValidation( ( r.data && r.data.validation ) || null );
			}
			clearNotices( 'save' );
			setStatus( S.dirty ? 'unsaved' : 'draft' );
			if ( S.tab === 'layout' ) {
				reloadFrame();
			}
		} else if ( r.status === 409 ) {
			S.conflict = true;
			S.dirty = true;
			setStatus( 'error' );
			showConflict();
		} else if ( r.status === 422 ) {
			const d = errData( r.data );
			S.dirty = true; // keep the change local until it is fixed
			if ( current() ) {
				setValidation( { errors: d.errors || [ d.message || t( 'requestFailed' ) ], warnings: d.warnings || [] } );
				window.clearTimeout( S.saveTimer );
			}
			setStatus( 'invalid' );
		} else if ( r.status === 423 ) {
			S.dirty = true;
			handleLockConflict( r.data );
		} else if ( r.status === 403 ) {
			S.dirty = true;
			setStatus( 'error' );
			const d = errData( r.data );
			flash( d.message || t( 'forbidden' ), 'error', 'save' );
		} else {
			S.dirty = true;
			setStatus( 'error' );
			flash( ( r.data && r.data.message ) || t( 'requestFailed' ), 'error', 'save' );
		}

		renderSidebar();
		// Edits made while the request was in flight are sent now, also after a failed save
		// (they may fix the error).
		const changed = ! current();
		if ( S.saveAgain || changed ) {
			S.saveAgain = false;
			if ( changed && S.dirty && ! S.conflict && ! S.readonly ) {
				return saveDraft();
			}
		}
		if ( ok && S.dirty ) {
			scheduleSave();
		}
		return ok && ! S.dirty;
	}

	function flash( msg, type, kind ) {
		if ( kind ) {
			clearNotices( kind );
		}
		const n = notice( msg, type );
		if ( kind ) {
			n.dataset.kind = kind;
		}
		return n;
	}

	function showConflict() {
		clearNotices( 'conflict' );
		const n = notice( t( 'conflict' ), 'error', [
			{
				label: t( 'reload' ),
				onClick: () => {
					// The local changes cannot be saved anyway; skip the "unsaved changes" prompt.
					S.dirty = false;
					S.saving = false;
					window.location.reload();
				},
			},
		] );
		n.dataset.kind = 'conflict';
		renderStatus();
	}

	/** Waits for pending saves; resolves true when the draft on the server matches local state. */
	async function flushSave() {
		window.clearTimeout( S.saveTimer );
		for ( let i = 0; i < 50 && S.saving; i++ ) {
			await new Promise( ( r ) => window.setTimeout( r, 100 ) );
		}
		if ( S.dirty ) {
			return saveDraft();
		}
		return S.status !== 'invalid' && S.status !== 'error';
	}

	async function publish() {
		if ( S.readonly || S.conflict || S.publishing ) {
			return;
		}
		S.publishing = true;
		UI.publish.textContent = t( 'publishing' );
		renderStatus();
		try {
			await doPublish();
		} finally {
			S.publishing = false;
			UI.publish.textContent = t( 'publish' );
			renderStatus();
		}
	}

	async function doPublish() {
		const saved = await flushSave();
		if ( ! saved ) {
			return;
		}
		const r = await api( 'POST', 'panel/layout/' + S.postId + '/publish', { base_rev: S.rev } );
		if ( r.ok && r.data ) {
			applyResponse( r.data, true );
			announce( t( 'published' ) );
			flash( t( 'published' ), 'success', 'save' );
			reloadFrame();
		} else if ( r.status === 409 ) {
			S.conflict = true;
			showConflict();
		} else if ( r.status === 422 ) {
			const d = errData( r.data );
			setValidation( { errors: d.errors || [ d.message ], warnings: d.warnings || [] } );
			setStatus( 'invalid' );
			renderSidebar();
		} else if ( r.status === 423 ) {
			handleLockConflict( r.data );
		} else {
			flash( errData( r.data ).message || t( 'requestFailed' ), 'error', 'save' );
		}
	}

	async function discard() {
		if ( S.readonly || S.publishing || ! window.confirm( t( 'confirmDiscard' ) ) ) {
			return;
		}
		window.clearTimeout( S.saveTimer );
		S.dirty = false;
		for ( let i = 0; i < 50 && S.saving; i++ ) {
			await new Promise( ( r ) => window.setTimeout( r, 100 ) );
		}
		const r = await api( 'DELETE', 'panel/layout/' + S.postId + '/draft' );
		if ( r.ok || r.status === 404 ) {
			await loadLayout( true );
			announce( t( 'discarded' ) );
			flash( t( 'discarded' ), 'success', 'save' );
			reloadFrame();
		} else {
			flash( errData( r.data ).message || t( 'requestFailed' ), 'error', 'save' );
		}
	}

	async function restore( rev ) {
		if ( S.readonly || S.publishing ) {
			return;
		}
		await flushSave();
		const r = await api( 'POST', 'panel/layout/' + S.postId + '/restore', { rev } );
		if ( r.ok ) {
			closeModal();
			await loadLayout( true );
			const msg = t( 'restored', rev );
			announce( msg );
			flash( msg, 'success', 'save' );
			reloadFrame();
		} else {
			flash( errData( r.data ).message || t( 'requestFailed' ), 'error', 'save' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Lock
	 * ------------------------------------------------------------------ */

	let lockTimer = 0;

	async function takeLock( force ) {
		if ( ! S.postId || ( ! S.canEdit && ! S.reorderOnly ) ) {
			return;
		}
		if ( S.foreignDraft ) {
			// Read only while the agency's draft exists: do not hold the lock, just check again.
			const g = await api( 'GET', 'panel/layout/' + S.postId );
			if ( ! g.ok || ! g.data || g.data.draft_has_foreign_changes ) {
				return;
			}
			applyResponse( g.data, true );
			reloadFrame();
		}
		const r = await api( 'POST', 'panel/layout/' + S.postId + '/lock', force ? { force: true } : {} );
		if ( r.ok ) {
			const wasLocked = S.lockHolder !== null;
			S.lockHolder = null;
			S.hadLock = true;
			clearNotices( 'lock' );
			if ( S.readonly && wasLocked ) {
				// Took over (or the other user left): reload state so edits start from the server version.
				S.readonly = false;
				await loadLayout( true );
				if ( force ) {
					announce( t( 'tookOver' ) );
				}
				reloadFrame();
			}
		} else if ( r.status === 423 ) {
			handleLockConflict( r.data );
		}
	}

	function holderName( data ) {
		const d = errData( data );
		const lock = d.lock && typeof d.lock === 'object' ? d.lock : {};
		const name = d.holder_name || d.user_name || ( typeof d.holder === 'string' ? d.holder : '' ) || ( d.holder && d.holder.name ) || lock.user_name || lock.name || '';
		return name || ( S.lockHolder && S.lockHolder !== t( 'someone' ) ? S.lockHolder : t( 'someone' ) );
	}

	function handleLockConflict( data ) {
		const lost = S.hadLock && ! S.readonly;
		S.readonly = true;
		S.hadLock = false;
		S.lockHolder = holderName( data );
		window.clearTimeout( S.saveTimer );
		clearNotices( 'lock' );
		const d = errData( data );
		const sameUser = !! ( d.lock && d.lock.same_user );
		const actions = S.canEdit || sameUser ? [ { label: t( 'takeOver' ), onClick: () => takeLock( true ) } ] : [];
		const msg = sameUser ? t( 'otherWindow' ) : ( lost ? t( 'lockLost' ) + ' ' : '' ) + t( 'lockedBy', S.lockHolder );
		const n = notice( msg, 'warning', actions );
		n.dataset.kind = 'lock';
		renderAll();
	}

	function startLockLoop() {
		window.clearInterval( lockTimer );
		lockTimer = window.setInterval( () => takeLock( false ), ( Number( cfg.lockInterval ) || 60 ) * 1000 );
	}

	/* ------------------------------------------------------------------ *
	 * Loading
	 * ------------------------------------------------------------------ */

	function applyResponse( data, resetHistory ) {
		S.resp = data;
		S.rev = Number( data.rev ) || 0;
		S.hasDraft = !! data.draft;
		const base = data.draft || data.live || { format: 1, sections: [] };
		S.layout = clone( base );
		if ( ! Array.isArray( S.layout.sections ) ) {
			S.layout.sections = [];
		}
		if ( resetHistory ) {
			S.undo = [];
			S.redo = [];
		}
		S.dirty = false;
		S.conflict = false;
		clearNotices( 'conflict' );
		const perms = data.permissions || {};
		S.canEdit = !! perms.edit_layout;
		S.reorderOnly = ! perms.edit_layout && !! perms.reorder_only;
		if ( ! S.canEdit && ! S.reorderOnly ) {
			S.readonly = true;
		}
		const wasForeign = S.foreignDraft;
		S.foreignDraft = S.reorderOnly && !! data.draft_has_foreign_changes;
		if ( S.foreignDraft ) {
			S.readonly = true;
			if ( ! wasForeign ) {
				notice( t( 'foreignDraft' ), 'warning' ).dataset.kind = 'foreign';
			}
		} else if ( wasForeign ) {
			clearNotices( 'foreign' );
			S.readonly = S.lockHolder !== null;
		}
		if ( data.lock && Number( data.lock.user_id ) && Number( data.lock.user_id ) !== Number( cfg.userId ) ) {
			// Server says someone else holds the lock; takeLock() decides the final state.
			S.lockHolder = data.lock.user_name || data.lock.name || t( 'someone' );
		}
		setValidation( data.validation || null );
		if ( S.selected && ( ! byId( S.selected ) || S.reorderOnly ) ) {
			S.selected = null;
		}
		setStatus( S.hasDraft ? 'draft' : 'published' );
		renderAll();
	}

	async function loadLayout( resetHistory ) {
		const r = await api( 'GET', 'panel/layout/' + S.postId );
		if ( ! r.ok || ! r.data ) {
			fatal( errData( r.data ).message || t( 'loadFailed' ) );
			return false;
		}
		applyResponse( r.data, resetHistory );
		return true;
	}

	function fatal( msg ) {
		root.dataset.state = 'error';
		if ( UI.notices ) {
			flash( msg, 'error', 'fatal' );
		} else {
			root.textContent = '';
			root.append( h( 'p.df-panel__boot', { role: 'alert', text: msg } ) );
		}
	}

	async function boot() {
		buildShell();
		const [ pages, schema ] = await Promise.all( [ api( 'GET', 'panel/pages' ), api( 'GET', 'panel/sections' ) ] );
		S.pages = pages.ok && Array.isArray( pages.data ) ? pages.data : [];
		if ( schema.ok && schema.data ) {
			S.schema = Object.assign( { sections: [], schemes: [], spacing: [], widths: [] }, schema.data );
			for ( const d of S.schema.sections || [] ) {
				S.types[ d.type ] = d;
			}
		} else {
			S.schema = { sections: [], schemes: [], spacing: [], widths: [] };
		}
		renderPages();

		if ( ! S.postId ) {
			if ( S.pages.length === 1 ) {
				window.location.href = withArgs( cfg.panelUrl, { post: S.pages[ 0 ].id } );
				return;
			}
			root.dataset.state = 'nopage';
			UI.sidebarBody.textContent = '';
			UI.sidebarBody.append( h( 'p.df-panel__empty', { text: S.pages.length ? t( 'noPage' ) : t( 'noPages' ) } ) );
			UI.pageSelect.focus();
			return;
		}

		if ( ! ( await loadLayout( true ) ) ) {
			return;
		}
		root.dataset.state = 'ready';
		await takeLock( false );
		startLockLoop();
		if ( S.reorderOnly && ! S.readonly ) {
			flash( t( 'reorderOnly' ), 'info', 'role' );
		}
		loadFrame();
	}

	/* ------------------------------------------------------------------ *
	 * Shell
	 * ------------------------------------------------------------------ */

	function buildShell() {
		root.textContent = '';
		root.dataset.state = 'loading';

		UI.live = h( 'div.df-panel__sr', { 'aria-live': 'polite', 'aria-atomic': 'true' } );

		// Top bar.
		UI.pageSelect = h( 'select.df-panel__select', { id: 'df-panel-page', onChange: onPageChange } );
		UI.sidebarToggle = h(
			'button.df-panel__icon-btn.df-panel__sidebar-toggle',
			{ type: 'button', 'aria-label': t( 'toggleSidebar' ), 'aria-controls': 'df-panel-sidebar', 'aria-expanded': String( S.sidebarOpen ), onClick: toggleSidebar },
			icon( 'menu' )
		);

		UI.tabs = {};
		const tabList = h( 'div.df-panel__tabs', { role: 'tablist', 'aria-label': t( 'tabsLabel' ) } );
		for ( const [ key, lbl ] of [ [ 'inhalt', 'tabInhalt' ], [ 'layout', 'tabLayout' ], [ 'design', 'tabDesign' ] ] ) {
			const b = h( 'button.df-panel__tab', {
				type: 'button',
				role: 'tab',
				id: 'df-panel-tab-' + key,
				'aria-controls': 'df-panel-sidebar-body',
				'aria-selected': String( key === S.tab ),
				tabindex: key === S.tab ? '0' : '-1',
				text: t( lbl ),
				onClick: () => setTab( key ),
				onKeydown: onTabKey,
			} );
			b.dataset.tab = key;
			UI.tabs[ key ] = b;
			tabList.append( b );
		}

		UI.devices = {};
		const devGroup = h( 'div.df-panel__devices', { role: 'group', 'aria-label': t( 'device' ) } );
		for ( const d of [ 'desktop', 'tablet', 'mobile' ] ) {
			const b = h( 'button.df-panel__icon-btn', { type: 'button', 'aria-pressed': String( d === S.device ), 'aria-label': t( d ), title: t( d ), onClick: () => setDevice( d ) }, icon( d ) );
			UI.devices[ d ] = b;
			devGroup.append( b );
		}

		UI.status = h( 'span.df-panel__status' );
		UI.undo = h( 'button.df-panel__icon-btn', { type: 'button', 'aria-label': t( 'undo' ), title: t( 'undo' ) + ' (Ctrl+Z)', onClick: undo }, icon( 'undo' ) );
		UI.redo = h( 'button.df-panel__icon-btn', { type: 'button', 'aria-label': t( 'redo' ), title: t( 'redo' ) + ' (Ctrl+Shift+Z)', onClick: redo }, icon( 'redo' ) );
		UI.historyBtn = h( 'button.df-panel__btn', { type: 'button', onClick: openHistory, 'aria-haspopup': 'dialog', 'aria-label': t( 'history' ), title: t( 'history' ) }, icon( 'history' ), h( 'span.df-panel__btn-text', { text: t( 'history' ) } ) );
		UI.discard = h( 'button.df-panel__btn', { type: 'button', text: t( 'discard' ), onClick: discard } );
		UI.publish = h( 'button.df-panel__btn.is-primary', { type: 'button', text: t( 'publish' ), onClick: publish } );
		UI.exit = h( 'a.df-panel__btn.is-ghost', { href: cfg.homeUrl || '/', 'aria-label': t( 'exitLabel' ) }, icon( 'back' ), h( 'span.df-panel__btn-text', { text: t( 'exit' ) } ) );

		const top = h(
			'header.df-panel__top',
			null,
			h(
				'div.df-panel__top-left',
				null,
				UI.sidebarToggle,
				UI.exit,
				h( 'label.df-panel__sr', { for: 'df-panel-page', text: t( 'page' ) } ),
				UI.pageSelect
			),
			h( 'div.df-panel__top-center', null, tabList, devGroup ),
			h( 'div.df-panel__top-right', null, UI.status, UI.undo, UI.redo, UI.historyBtn, UI.discard, UI.publish )
		);

		// Sidebar.
		UI.sidebarBody = h( 'div.df-panel__sidebar-body', { id: 'df-panel-sidebar-body', role: 'tabpanel', 'aria-labelledby': 'df-panel-tab-' + S.tab, tabindex: '-1' } );
		UI.sidebar = h( 'aside.df-panel__sidebar', { id: 'df-panel-sidebar', 'aria-label': t( 'sections' ) }, UI.sidebarBody );

		// Preview.
		UI.frame = h( 'iframe.df-panel__frame', { title: t( 'previewTitle' ), name: 'df-panel-preview' } );
		UI.frame.addEventListener( 'load', onFrameLoad );
		UI.frameLoading = h( 'div.df-panel__frame-loading', { text: t( 'previewLoading' ), hidden: true } );
		UI.frameWrap = h( 'div.df-panel__frame-wrap', { dataset: { device: S.device } }, UI.frame, UI.frameLoading );
		UI.preview = h( 'main.df-panel__preview', null, UI.frameWrap );

		UI.notices = h( 'div.df-panel__notices' );

		UI.body = h( 'div.df-panel__body', null, UI.sidebar, UI.preview );
		root.append( top, UI.notices, UI.body, UI.live );
		root.classList.toggle( 'is-sidebar-closed', ! S.sidebarOpen );

		renderStatus();
		renderSidebar();
	}

	function renderPages() {
		UI.pageSelect.textContent = '';
		if ( ! S.postId ) {
			UI.pageSelect.append( h( 'option', { value: '', text: t( 'choosePage' ) } ) );
		}
		let found = false;
		for ( const p of S.pages ) {
			const id = Number( p.id );
			found = found || id === S.postId;
			UI.pageSelect.append( h( 'option', { value: String( id ), selected: id === S.postId, text: ( p.title || '#' + id ) + ( p.has_draft ? ' ' + t( 'draftMark' ) : '' ) } ) );
		}
		if ( S.postId && ! found ) {
			UI.pageSelect.prepend( h( 'option', { value: String( S.postId ), selected: true, text: '#' + S.postId } ) );
		}
	}

	async function onPageChange() {
		const id = Number( UI.pageSelect.value );
		if ( ! id || id === S.postId ) {
			return;
		}
		await flushSave();
		window.location.href = withArgs( cfg.panelUrl, { post: id } );
	}

	function onTabKey( e ) {
		const keys = Object.keys( UI.tabs );
		const i = keys.indexOf( e.currentTarget.dataset.tab );
		let n = -1;
		if ( e.key === 'ArrowRight' ) {
			n = ( i + 1 ) % keys.length;
		} else if ( e.key === 'ArrowLeft' ) {
			n = ( i - 1 + keys.length ) % keys.length;
		} else if ( e.key === 'Home' ) {
			n = 0;
		} else if ( e.key === 'End' ) {
			n = keys.length - 1;
		}
		if ( n >= 0 ) {
			e.preventDefault();
			setTab( keys[ n ] );
			UI.tabs[ keys[ n ] ].focus();
		}
	}

	async function setTab( key ) {
		if ( key === S.tab ) {
			return;
		}
		const prev = S.tab;
		S.tab = key;
		for ( const [ k, b ] of Object.entries( UI.tabs ) ) {
			b.setAttribute( 'aria-selected', String( k === key ) );
			b.tabIndex = k === key ? 0 : -1;
		}
		UI.sidebarBody.setAttribute( 'aria-labelledby', 'df-panel-tab-' + key );
		root.dataset.tab = key;
		if ( prev === 'layout' ) {
			await flushSave();
		}
		renderSidebar();
		if ( key === 'inhalt' || prev === 'inhalt' ) {
			S.frameScroll = 0;
			loadFrame();
		}
	}

	function setDevice( d ) {
		S.device = d;
		UI.frameWrap.dataset.device = d;
		for ( const [ k, b ] of Object.entries( UI.devices ) ) {
			b.setAttribute( 'aria-pressed', String( k === d ) );
		}
	}

	function toggleSidebar() {
		S.sidebarOpen = ! S.sidebarOpen;
		root.classList.toggle( 'is-sidebar-closed', ! S.sidebarOpen );
		UI.sidebarToggle.setAttribute( 'aria-expanded', String( S.sidebarOpen ) );
		if ( S.sidebarOpen ) {
			UI.sidebarBody.focus();
		}
	}

	function renderAll() {
		root.classList.toggle( 'is-readonly', S.readonly );
		root.classList.toggle( 'is-reorder-only', S.reorderOnly );
		const liveUrl = ( S.resp && S.resp.live_url ) || cfg.homeUrl;
		if ( liveUrl ) {
			UI.exit.href = liveUrl;
		}
		renderStatus();
		renderSidebar();
	}

	/* ------------------------------------------------------------------ *
	 * Preview iframe
	 * ------------------------------------------------------------------ */

	function frameUrl() {
		if ( ! S.resp ) {
			return 'about:blank';
		}
		if ( S.tab === 'inhalt' ) {
			// With a draft, edit texts on the draft preview so newly added sections can be filled in too.
			const inhaltBase = S.hasDraft ? S.resp.preview_url || S.resp.live_url : S.resp.live_url;
			return withArgs( inhaltBase || cfg.homeUrl, { df_panel_frame: '1', df_inhalt: 'open', 'df-inhalt-edit': '1' } );
		}
		const base = S.hasDraft ? S.resp.preview_url || S.resp.live_url : S.resp.live_url || S.resp.preview_url;
		return withArgs( base || cfg.homeUrl, { df_panel_frame: '1', _dfr: String( Date.now() ) } );
	}

	function loadFrame() {
		UI.frameLoading.hidden = false;
		UI.frame.src = frameUrl();
	}

	function reloadFrame() {
		try {
			S.frameScroll = UI.frame.contentWindow ? UI.frame.contentWindow.scrollY : S.frameScroll;
		} catch ( e ) {}
		loadFrame();
	}

	function onFrameLoad() {
		UI.frameLoading.hidden = true;
		let win;
		try {
			win = UI.frame.contentWindow;
			void win.document; // throws if cross-origin
		} catch ( e ) {
			return;
		}
		if ( S.pendingScroll ) {
			scrollFrameTo( S.pendingScroll );
			S.pendingScroll = null;
		} else if ( S.frameScroll ) {
			win.scrollTo( 0, S.frameScroll );
		}
		if ( S.selected && S.tab === 'layout' ) {
			markFrame( S.selected, false );
		}
		// Keyboard shortcuts also work while the preview has focus.
		win.document.addEventListener( 'keydown', onKey );
	}

	function scrollFrameTo( id ) {
		try {
			const doc = UI.frame.contentWindow.document;
			const el = doc.getElementById( id );
			if ( el ) {
				el.scrollIntoView( { behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'start' } );
				return true;
			}
		} catch ( e ) {}
		return false;
	}

	function markFrame( id, scroll ) {
		try {
			const doc = UI.frame.contentWindow.document;
			doc.querySelectorAll( '[data-df-panel-selected]' ).forEach( ( el ) => {
				el.removeAttribute( 'data-df-panel-selected' );
				el.style.outline = '';
				el.style.outlineOffset = '';
			} );
			const el = id ? doc.getElementById( id ) : null;
			if ( el ) {
				el.setAttribute( 'data-df-panel-selected', '' );
				el.style.outline = '3px solid #2271b1';
				el.style.outlineOffset = '-3px';
				if ( scroll ) {
					scrollFrameTo( id );
				}
			}
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ *
	 * Sidebar rendering
	 * ------------------------------------------------------------------ */

	function renderSidebar() {
		if ( ! UI.sidebarBody || dragState ) {
			return;
		}
		const focusKey = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.focusKey : null;
		UI.sidebarBody.textContent = '';
		if ( S.tab === 'inhalt' ) {
			renderInhalt();
		} else if ( S.tab === 'design' ) {
			renderDesign();
		} else if ( ! S.layout ) {
			UI.sidebarBody.append( h( 'p.df-panel__empty', { text: S.postId ? t( 'previewLoading' ) : t( 'noPage' ) } ) );
		} else if ( S.selected && byId( S.selected ) && S.canEdit ) {
			renderOptions( byId( S.selected ) );
		} else {
			renderList();
		}
		if ( focusKey ) {
			const el = UI.sidebarBody.querySelector( '[data-focus-key="' + CSS.escape( focusKey ) + '"]' );
			if ( el && ! el.disabled ) {
				el.focus();
			} else {
				const fallback = UI.sidebarBody.querySelector( '[data-focus-key^="' + CSS.escape( focusKey.split( ':' )[ 0 ] ) + ':"]:not([disabled])' );
				if ( fallback ) {
					fallback.focus();
				}
			}
		}
	}

	function renderInhalt() {
		const url = ( S.resp && S.resp.live_url ) || cfg.homeUrl;
		UI.sidebarBody.append(
			h( 'h2.df-panel__heading', { text: t( 'tabInhalt' ) } ),
			h( 'p.df-panel__hint', { text: t( 'inhaltHint' ) } ),
			url ? h( 'p', null, h( 'a.df-panel__link', { href: withArgs( url, { 'df-inhalt-edit': '1' } ), target: '_blank', rel: 'noopener', text: t( 'inhaltOpenPage' ) } ) ) : null
		);
	}

	function renderDesign() {
		const url = ( S.resp && S.resp.live_url ) || cfg.homeUrl;
		UI.sidebarBody.append( h( 'h2.df-panel__heading', { text: t( 'tabDesign' ) } ) );
		if ( cfg.caps && cfg.caps.customize ) {
			UI.sidebarBody.append(
				h( 'p.df-panel__hint', { text: t( 'designHint' ) } ),
				h( 'p', null, h( 'a.df-panel__btn', { href: withArgs( cfg.customizeUrl, { url, 'autofocus[panel]': 'df_design' } ), text: t( 'designOpen' ) } ) )
			);
		} else {
			UI.sidebarBody.append( h( 'p.df-panel__hint', { text: t( 'designNoAccess' ) } ) );
		}
	}

	function messages( list, cls, title ) {
		if ( ! list || ! list.length ) {
			return null;
		}
		return h( 'div.df-panel__msgs', { class: cls }, title ? h( 'strong', { text: title } ) : null, h( 'ul', null, list.map( ( m ) => h( 'li', { text: m } ) ) ) );
	}

	function renderList() {
		const list = sections();
		const canAdd = S.canEdit && ! S.readonly;
		const head = h( 'div.df-panel__list-head', null, h( 'h2.df-panel__heading', { id: 'df-panel-list-title', text: t( 'sections' ) } ) );
		UI.sidebarBody.append( head );
		const glob = [ messages( S.errors.global, 'is-error', t( 'errorsTitle' ) ), messages( S.warnings.global, 'is-warning', t( 'warningsTitle' ) ) ];
		UI.sidebarBody.append( ...glob.filter( Boolean ) );

		if ( ! list.length ) {
			UI.sidebarBody.append( h( 'p.df-panel__empty', { text: t( 'empty' ) } ) );
		}

		const ol = h( 'ol.df-panel__list', { 'aria-labelledby': 'df-panel-list-title' } );
		UI.list = ol;
		list.forEach( ( entry, i ) => {
			if ( canAdd ) {
				ol.append( h( 'li.df-panel__insert', { role: 'presentation' }, insertButton( i, true ) ) );
			}
			ol.append( renderRow( entry, i, list.length ) );
		} );
		UI.sidebarBody.append( ol );
		if ( canAdd ) {
			UI.sidebarBody.append( h( 'div.df-panel__add-end', null, insertButton( list.length, false ) ) );
		}
		UI.sidebarBody.append( h( 'p.df-panel__hint.is-small', { text: t( 'shortcutsHint' ) } ) );
	}

	function insertButton( index, between ) {
		const b = h(
			'button.df-panel__add',
			{
				type: 'button',
				class: between ? 'is-between' : 'is-end',
				'aria-label': between ? t( 'addSectionHere', index + 1 ) : t( 'addSection' ),
				'aria-haspopup': 'dialog',
				onClick: ( e ) => openLibrary( index, e.currentTarget ),
				dataset: { focusKey: 'add:' + index },
			},
			icon( 'plus' ),
			between ? null : h( 'span', { text: t( 'addSection' ) } )
		);
		return b;
	}

	function renderRow( entry, i, total ) {
		const name = label( entry );
		const id = entry.id;
		const locked = !! entry.locked;
		const ro = S.readonly;
		const canMove = ! ro && ! locked;
		const canHide = ! ro && ! ( S.reorderOnly && locked );
		const errs = S.errors.rows[ id ] || [];
		const warns = S.warnings.rows[ id ] || [];
		const scheme = schemeOf( entry );
		const colors = ( cfg.schemeColors || {} )[ scheme ] || { bg: '#fff', fg: '#111' };

		const li = h( 'li.df-panel__row', {
			class: [ entry.hidden ? 'is-hidden' : '', locked ? 'is-locked' : '', errs.length ? 'has-error' : '', S.selected === id ? 'is-selected' : '' ].filter( Boolean ).join( ' ' ),
			dataset: { id },
		} );

		const handle = h(
			'button.df-panel__handle',
			{ type: 'button', 'aria-label': t( 'dragHandle', name ), title: t( 'dragHandle', name ), disabled: ! canMove, tabindex: '-1' },
			icon( locked ? 'lock' : 'drag' )
		);
		if ( canMove ) {
			handle.addEventListener( 'pointerdown', ( e ) => startDrag( e, id, li ) );
		}

		const swatch = h( 'span.df-panel__swatch', { style: '--df-sw-bg:' + colors.bg + ';--df-sw-fg:' + colors.fg, title: listLabel( S.schema.schemes, scheme ), 'aria-hidden': 'true' } );
		const meta = [ variantLabel( entry ), listLabel( S.schema.schemes, scheme ) ].filter( Boolean ).join( ' · ' );
		const badges = h(
			'span.df-panel__badges',
			null,
			entry.hidden ? h( 'span.df-panel__badge', { text: t( 'hiddenBadge' ) } ) : null,
			locked ? h( 'span.df-panel__badge', { text: t( 'locked' ) } ) : null
		);
		const main = h(
			'button.df-panel__row-main',
			{
				type: 'button',
				onClick: () => selectRow( id ),
				'aria-label': S.canEdit ? t( 'editOptions', name ) + ( meta ? ', ' + meta : '' ) : name + ( meta ? ', ' + meta : '' ),
				'aria-describedby': errs.length || warns.length ? 'df-panel-msg-' + id : null,
				dataset: { focusKey: 'row:' + id },
			},
			swatch,
			h( 'span.df-panel__row-text', null, h( 'span.df-panel__row-label', { text: name } ), h( 'span.df-panel__row-meta', { text: meta } ) ),
			badges
		);

		const upDisabled = ! canMove || neighbourSlot( id, -1 ) < 0;
		const downDisabled = ! canMove || neighbourSlot( id, 1 ) < 0;
		const actions = h(
			'span.df-panel__row-actions',
			null,
			h( 'button.df-panel__icon-btn.is-small', { type: 'button', 'aria-label': t( 'moveUp', name ), title: t( 'moveUp', name ), disabled: upDisabled, onClick: () => step( id, -1 ), dataset: { focusKey: 'up:' + id } }, icon( 'up' ) ),
			h( 'button.df-panel__icon-btn.is-small', { type: 'button', 'aria-label': t( 'moveDown', name ), title: t( 'moveDown', name ), disabled: downDisabled, onClick: () => step( id, 1 ), dataset: { focusKey: 'down:' + id } }, icon( 'down' ) ),
			h(
				'button.df-panel__icon-btn.is-small',
				{
					type: 'button',
					'aria-label': entry.hidden ? t( 'show', name ) : t( 'hide', name ),
					title: entry.hidden ? t( 'show', name ) : t( 'hide', name ),
					'aria-pressed': String( !! entry.hidden ),
					disabled: ! canHide,
					onClick: () => toggleHidden( id ),
					dataset: { focusKey: 'eye:' + id },
				},
				icon( entry.hidden ? 'eyeOff' : 'eye' )
			),
			S.canEdit ? rowMenu( entry, name ) : null
		);

		li.append( h( 'div.df-panel__row-inner', null, handle, main, actions ) );
		if ( errs.length || warns.length ) {
			li.append( h( 'div.df-panel__row-msgs', { id: 'df-panel-msg-' + id }, messages( errs, 'is-error' ), messages( warns, 'is-warning' ) ) );
		}
		return li;
	}

	/* --- Row menu (Duplicate / Delete / Lock) --- */

	let openMenu = null;

	function rowMenu( entry, name ) {
		const id = entry.id;
		const wrap = h( 'span.df-panel__menu-wrap' );
		const btn = h(
			'button.df-panel__icon-btn.is-small',
			{ type: 'button', 'aria-label': t( 'more', name ), title: t( 'more', name ), 'aria-haspopup': 'menu', 'aria-expanded': 'false', disabled: S.readonly, dataset: { focusKey: 'menu:' + id } },
			icon( 'more' )
		);
		const items = [
			{ text: t( 'duplicate' ), run: () => duplicate( id ), disabled: false },
			{ text: entry.locked ? t( 'unlock' ) : t( 'lock' ), run: () => toggleLock( id ), disabled: false },
			{ text: t( 'delete' ), run: () => remove( id ), disabled: !! entry.locked, danger: true },
		];
		const menu = h( 'div.df-panel__menu', { role: 'menu', hidden: true } );
		items.forEach( ( it ) => {
			menu.append(
				h( 'button.df-panel__menu-item', {
					type: 'button',
					role: 'menuitem',
					tabindex: '-1',
					class: it.danger ? 'is-danger' : '',
					disabled: it.disabled,
					text: it.text,
					onClick: () => {
						closeMenu( false );
						it.run();
					},
				} )
			);
		} );
		btn.addEventListener( 'click', () => ( menu.hidden ? showMenu( btn, menu ) : closeMenu( true ) ) );
		menu.addEventListener( 'keydown', ( e ) => {
			const all = [ ...menu.querySelectorAll( '[role="menuitem"]:not([disabled])' ) ];
			const i = all.indexOf( document.activeElement );
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				all[ ( i + 1 ) % all.length ].focus();
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				all[ ( i - 1 + all.length ) % all.length ].focus();
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				e.stopPropagation();
				closeMenu( true );
			} else if ( e.key === 'Tab' ) {
				closeMenu( false );
			}
		} );
		wrap.append( btn, menu );
		return wrap;
	}

	function showMenu( btn, menu ) {
		closeMenu( false );
		menu.hidden = false;
		btn.setAttribute( 'aria-expanded', 'true' );
		openMenu = { btn, menu };
		const first = menu.querySelector( '[role="menuitem"]:not([disabled])' );
		if ( first ) {
			first.focus();
		}
	}

	function closeMenu( returnFocus ) {
		if ( ! openMenu ) {
			return;
		}
		openMenu.menu.hidden = true;
		openMenu.btn.setAttribute( 'aria-expanded', 'false' );
		if ( returnFocus ) {
			openMenu.btn.focus();
		}
		openMenu = null;
	}

	document.addEventListener( 'pointerdown', ( e ) => {
		if ( openMenu && ! openMenu.menu.contains( e.target ) && ! openMenu.btn.contains( e.target ) ) {
			closeMenu( false );
		}
	} );

	/* ------------------------------------------------------------------ *
	 * Row actions
	 * ------------------------------------------------------------------ */

	function selectRow( id ) {
		S.pendingScroll = null;
		if ( S.canEdit && ! S.readonly ) {
			S.selected = id;
			renderSidebar();
			const back = UI.sidebarBody.querySelector( '.df-panel__back' );
			if ( back ) {
				back.focus();
			}
		}
		markFrame( id, true );
	}

	function step( id, dir ) {
		const entry = byId( id );
		if ( ! entry ) {
			return;
		}
		const target = neighbourSlot( id, dir );
		if ( target < 0 ) {
			announce( entry.locked ? t( 'lockedNoMove', label( entry ) ) : t( 'cannotMove', label( entry ) ) );
			return;
		}
		let newIndex = -1;
		change( () => {
			newIndex = moveTo( id, target );
			return newIndex >= 0;
		}, null );
		if ( newIndex >= 0 ) {
			announce( t( 'moved', label( entry ), newIndex + 1, sections().length ) );
			// Keep focus on the same button; if it became disabled, renderSidebar falls back.
			const btn = UI.sidebarBody.querySelector( '[data-focus-key="' + ( dir < 0 ? 'up:' : 'down:' ) + CSS.escape( id ) + '"]' );
			if ( btn && ! btn.disabled ) {
				btn.focus();
			} else {
				const other = UI.sidebarBody.querySelector( '[data-focus-key="' + ( dir < 0 ? 'down:' : 'up:' ) + CSS.escape( id ) + '"]' );
				( other && ! other.disabled ? other : UI.sidebarBody.querySelector( '[data-focus-key="row:' + CSS.escape( id ) + '"]' ) )?.focus();
			}
			S.pendingScroll = id;
		}
	}

	function toggleHidden( id ) {
		const entry = byId( id );
		if ( ! entry ) {
			return;
		}
		const name = label( entry );
		change( ( l ) => {
			const e = l.sections.find( ( s ) => s.id === id );
			e.hidden = ! e.hidden;
		}, entry.hidden ? t( 'shownNow', name ) : t( 'hiddenNow', name ) );
	}

	function toggleLock( id ) {
		const entry = byId( id );
		if ( ! entry || ! S.canEdit ) {
			return;
		}
		const name = label( entry );
		change( ( l ) => {
			const e = l.sections.find( ( s ) => s.id === id );
			e.locked = ! e.locked;
		}, entry.locked ? t( 'unlockedNow', name ) : t( 'lockedNow', name ) );
		UI.sidebarBody.querySelector( '[data-focus-key="menu:' + CSS.escape( id ) + '"]' )?.focus();
	}

	function remove( id ) {
		const entry = byId( id );
		if ( ! entry || ! S.canEdit || entry.locked ) {
			return;
		}
		const name = label( entry );
		if ( ! window.confirm( t( 'confirmDelete', name ) ) ) {
			return;
		}
		const i = indexOf( id );
		change( ( l ) => {
			l.sections.splice( i, 1 );
		}, t( 'deleted', name ) );
		if ( S.selected === id ) {
			S.selected = null;
			renderSidebar();
		}
		const rows = sections();
		const next = rows[ Math.min( i, rows.length - 1 ) ];
		( next ? UI.sidebarBody.querySelector( '[data-focus-key="row:' + CSS.escape( next.id ) + '"]' ) : UI.sidebarBody.querySelector( '.df-panel__add' ) )?.focus();
	}

	async function duplicate( id ) {
		const entry = byId( id );
		if ( ! entry || ! S.canEdit ) {
			return;
		}
		const copy = clone( entry );
		copy.id = uniqueId( entry.section );
		copy.locked = false;
		if ( copy.anchor ) {
			copy.anchor = ''; // anchors must be unique on the page
		}
		const i = indexOf( id );
		const name = label( entry );
		change( ( l ) => {
			l.sections.splice( i + 1, 0, copy );
		}, t( 'duplicated', name ) );
		UI.sidebarBody.querySelector( '[data-focus-key="row:' + CSS.escape( copy.id ) + '"]' )?.focus();
		S.pendingScroll = copy.id;
		const r = await api( 'POST', 'panel/duplicate-content', { post_id: S.postId, from_id: id, to_id: copy.id } );
		if ( ! r.ok ) {
			flash( t( 'copyContentFail' ), 'warning' );
		}
	}

	function insertSection( d, index ) {
		const entry = newEntry( d );
		change( ( l ) => {
			l.sections.splice( Math.max( 0, Math.min( index, l.sections.length ) ), 0, entry );
		}, t( 'added', d.label || humanize( d.type ) ) );
		S.selected = entry.id;
		S.pendingScroll = entry.id;
		renderSidebar();
		UI.sidebarBody.querySelector( '.df-panel__back' )?.focus();
	}

	/* ------------------------------------------------------------------ *
	 * Drag & drop (pointer events; mouse, pen and touch)
	 * ------------------------------------------------------------------ */

	let dragState = null;

	function startDrag( e, id, li ) {
		if ( S.readonly || ( e.pointerType === 'mouse' && e.button !== 0 ) ) {
			return;
		}
		e.preventDefault();
		const rows = [ ...UI.list.querySelectorAll( '.df-panel__row' ) ];
		const rect = li.getBoundingClientRect();
		dragState = {
			id,
			li,
			pointerId: e.pointerId,
			startY: e.clientY,
			offset: e.clientY - rect.top,
			rows,
			rects: rows.map( ( r ) => r.getBoundingClientRect() ),
			from: rows.indexOf( li ),
			target: rows.indexOf( li ),
			moved: false,
		};
		e.currentTarget.setPointerCapture( e.pointerId );
		const handle = e.currentTarget;
		const onMove = ( ev ) => moveDrag( ev );
		const onUp = ( ev ) => {
			handle.removeEventListener( 'pointermove', onMove );
			handle.removeEventListener( 'pointerup', onUp );
			handle.removeEventListener( 'pointercancel', onUp );
			endDrag( ev.type === 'pointercancel' );
		};
		handle.addEventListener( 'pointermove', onMove );
		handle.addEventListener( 'pointerup', onUp );
		handle.addEventListener( 'pointercancel', onUp );
		li.classList.add( 'is-dragging' );
		root.classList.add( 'is-dragging' );
	}

	function moveDrag( e ) {
		const d = dragState;
		if ( ! d || e.pointerId !== d.pointerId ) {
			return;
		}
		const dy = e.clientY - d.startY;
		if ( Math.abs( dy ) > 3 ) {
			d.moved = true;
		}
		d.li.style.transform = 'translateY(' + dy + 'px)';

		// Target = row whose midpoint the dragged row centre passed.
		const centre = d.rects[ d.from ].top + dy + d.rects[ d.from ].height / 2;
		let target = d.from;
		d.rects.forEach( ( r, i ) => {
			const mid = r.top + r.height / 2;
			if ( i < d.from && centre < mid ) {
				target = Math.min( target, i );
			}
			if ( i > d.from && centre > mid ) {
				target = Math.max( target, i );
			}
		} );
		d.target = target;
		const h0 = d.rects[ d.from ].height + 8;
		d.rows.forEach( ( row, i ) => {
			if ( i === d.from ) {
				return;
			}
			let shift = 0;
			if ( target > d.from && i > d.from && i <= target ) {
				shift = -h0;
			} else if ( target < d.from && i < d.from && i >= target ) {
				shift = h0;
			}
			const lockedRow = row.classList.contains( 'is-locked' );
			row.style.transform = shift && ! lockedRow ? 'translateY(' + shift + 'px)' : '';
		} );

		// Auto-scroll the sidebar near its edges.
		const box = UI.sidebar.getBoundingClientRect();
		if ( e.clientY < box.top + 40 ) {
			UI.sidebar.scrollTop -= 12;
		} else if ( e.clientY > box.bottom - 40 ) {
			UI.sidebar.scrollTop += 12;
		}
	}

	function endDrag( cancelled ) {
		const d = dragState;
		if ( ! d ) {
			return;
		}
		d.rows.forEach( ( r ) => ( r.style.transform = '' ) );
		d.li.classList.remove( 'is-dragging' );
		root.classList.remove( 'is-dragging' );
		dragState = null;
		if ( cancelled || ! d.moved || d.target === d.from ) {
			renderSidebar();
			return;
		}
		const entry = byId( d.id );
		let newIndex = -1;
		const changed = change( () => {
			newIndex = moveTo( d.id, d.target );
			return newIndex >= 0;
		}, null );
		if ( ! changed ) {
			renderSidebar();
		}
		if ( newIndex >= 0 && entry ) {
			announce( t( 'moved', label( entry ), newIndex + 1, sections().length ) );
			S.pendingScroll = d.id;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Options panel
	 * ------------------------------------------------------------------ */

	function setField( id, fn, msg ) {
		change( ( l ) => {
			const e = l.sections.find( ( s ) => s.id === id );
			if ( ! e ) {
				return false;
			}
			return fn( e );
		}, msg );
	}

	let fieldSeq = 0;
	const fid = ( name ) => 'df-panel-f-' + name + '-' + ++fieldSeq;

	/** Segmented radio group (≤ 4 choices) or select. */
	function choiceField( name, legend, choices, value, onChange, opts = {} ) {
		const key = opts.focusKey || name;
		if ( choices.length && choices.length <= ( opts.maxSegments ?? 4 ) ) {
			const fs = h( 'fieldset.df-panel__field.df-panel__segmented', null, h( 'legend', { text: legend } ) );
			const group = h( 'div.df-panel__seg-group' );
			const gname = fid( name );
			for ( const c of choices ) {
				const id = fid( name + '-' + c.value );
				const input = h( 'input', { type: 'radio', name: gname, id, value: c.value, checked: c.value === value, dataset: { focusKey: key + ':' + c.value } } );
				input.addEventListener( 'change', () => input.checked && onChange( c.value ) );
				group.append( h( 'span.df-panel__seg', null, input, h( 'label', { for: id, text: c.label } ) ) );
			}
			fs.append( group );
			if ( opts.hint ) {
				fs.append( h( 'p.df-panel__hint.is-small', { text: opts.hint } ) );
			}
			return fs;
		}
		const id = fid( name );
		const sel = h( 'select.df-panel__select', { id, dataset: { focusKey: key + ':select' } } );
		let found = false;
		for ( const c of choices ) {
			found = found || c.value === value;
			sel.append( h( 'option', { value: c.value, selected: c.value === value, text: c.label } ) );
		}
		if ( ! found && value !== undefined && value !== '' ) {
			sel.prepend( h( 'option', { value, selected: true, text: humanize( value ) } ) );
		}
		sel.addEventListener( 'change', () => onChange( sel.value ) );
		return h( 'div.df-panel__field', null, h( 'label', { for: id, text: legend } ), sel, opts.hint ? h( 'p.df-panel__hint.is-small', { text: opts.hint } ) : null );
	}

	function schemeField( entry ) {
		const fs = h( 'fieldset.df-panel__field.df-panel__schemes', null, h( 'legend', { text: t( 'scheme' ) } ) );
		const gname = fid( 'scheme' );
		const current = schemeOf( entry );
		const choices = S.schema.schemes && S.schema.schemes.length ? S.schema.schemes : [ 'hell', 'hell-alt', 'dunkel', 'primaer', 'akzent' ].map( ( v ) => ( { value: v, label: humanize( v ) } ) );
		const wrap = h( 'div.df-panel__scheme-group' );
		for ( const c of choices ) {
			const colors = ( cfg.schemeColors || {} )[ c.value ] || { bg: '#fff', fg: '#111' };
			const id = fid( 'scheme-' + c.value );
			const input = h( 'input', { type: 'radio', name: gname, id, value: c.value, checked: c.value === current, dataset: { focusKey: 'scheme:' + c.value } } );
			input.addEventListener( 'change', () => input.checked && setField( entry.id, ( e ) => void ( e.scheme = c.value ) ) );
			wrap.append(
				h(
					'span.df-panel__scheme',
					null,
					input,
					h( 'label', { for: id }, h( 'span.df-panel__scheme-sw', { style: '--df-sw-bg:' + colors.bg + ';--df-sw-fg:' + colors.fg, 'aria-hidden': 'true', text: 'Aa' } ), h( 'span', { text: c.label } ) )
				)
			);
		}
		fs.append( wrap );
		return fs;
	}

	function renderOptions( entry ) {
		const d = def( entry ) || { type: entry.section, label: humanize( entry.section ), variants: [], options: [], list: null };
		const id = entry.id;
		const name = label( entry );
		const errs = S.errors.rows[ id ] || [];
		const warns = S.warnings.rows[ id ] || [];

		const back = h(
			'button.df-panel__back',
			{
				type: 'button',
				dataset: { focusKey: 'back:' + id },
				onClick: () => {
					S.selected = null;
					markFrame( null, false );
					renderSidebar();
					UI.sidebarBody.querySelector( '[data-focus-key="row:' + CSS.escape( id ) + '"]' )?.focus();
				},
			},
			icon( 'back' ),
			h( 'span', { text: t( 'back' ) } )
		);

		const form = h( 'form.df-panel__options', { 'aria-labelledby': 'df-panel-opt-title', onSubmit: ( e ) => e.preventDefault() } );
		// Native append() would print null as the text "null"; drop empty parts.
		form.append(
			...[
				h( 'h2.df-panel__heading', { id: 'df-panel-opt-title', text: name } ),
				d.description ? h( 'p.df-panel__hint', { text: d.description } ) : null,
				messages( errs, 'is-error', t( 'errorsTitle' ) ),
				messages( warns, 'is-warning', t( 'warningsTitle' ) ),
			].filter( Boolean )
		);

		// Variant.
		if ( ( d.variants || [] ).length > 1 ) {
			form.append( choiceField( 'variant', t( 'variant' ), d.variants, entry.variant, ( v ) => setField( id, ( e ) => void ( e.variant = v ) ), { maxSegments: 3 } ) );
		}

		form.append( schemeField( entry ) );

		// Spacing.
		const sp = S.schema.spacing && S.schema.spacing.length ? S.schema.spacing : [ '0', 's', 'm', 'l', 'xl' ].map( ( v ) => ( { value: v, label: v.toUpperCase() } ) );
		const spacing = entry.spacing || {};
		const spFs = h( 'fieldset.df-panel__field.df-panel__pair', null, h( 'legend', { text: t( 'spacing' ) } ) );
		for ( const side of [ 'top', 'bottom' ] ) {
			spFs.append(
				choiceField( 'spacing-' + side, t( side === 'top' ? 'spacingTop' : 'spacingBottom' ), sp, spacing[ side ] || 'l', ( v ) =>
					setField( id, ( e ) => {
						e.spacing = Object.assign( {}, e.spacing || {}, { [ side ]: v } );
					} ),
				{ maxSegments: 0 } )
			);
		}
		form.append( spFs );

		// Width.
		const widths = S.schema.widths && S.schema.widths.length ? S.schema.widths : [ 'container', 'breit', 'voll' ].map( ( v ) => ( { value: v, label: humanize( v ) } ) );
		form.append( choiceField( 'width', t( 'width' ), widths, entry.width || 'container', ( v ) => setField( id, ( e ) => void ( e.width = v ) ) ) );

		// Number of items.
		if ( d.list ) {
			const min = Number( d.list.min ) || 1;
			const max = Number( d.list.max ) || Math.max( min, 12 );
			const cur = Number( ( entry.options || {} ).count ) || Number( d.list.default_count ) || min;
			const nid = fid( 'count' );
			const input = h( 'input.df-panel__input.is-number', { type: 'number', id: nid, min, max, step: 1, value: cur, inputmode: 'numeric', 'aria-describedby': nid + '-hint', dataset: { focusKey: 'count:input' } } );
			input.addEventListener( 'change', () => {
				let n = Math.round( Number( input.value ) );
				n = Number.isFinite( n ) ? Math.min( max, Math.max( min, n ) ) : cur;
				input.value = n;
				setField( id, ( e ) => {
					e.options = Object.assign( {}, e.options || {}, { count: n } );
				} );
			} );
			form.append( h( 'div.df-panel__field', null, h( 'label', { for: nid, text: t( 'count' ) } ), input, h( 'p.df-panel__hint.is-small', { id: nid + '-hint', text: t( 'countHint', min, max ) } ) ) );
		}

		// Section options.
		const opts = ( d.options || [] ).filter( ( o ) => o.key !== 'count' );
		const applicable = opts.filter( ( o ) => ! Array.isArray( o.variants ) || ! o.variants.length || o.variants.includes( entry.variant ) );
		if ( applicable.length ) {
			const ofs = h( 'fieldset.df-panel__field.df-panel__group', null, h( 'legend', { text: t( 'options' ) } ) );
			for ( const o of applicable ) {
				const values = ( o.values || [] ).map( ( v ) => ( typeof v === 'object' ? v : { value: String( v ), label: humanize( v ) } ) );
				const cur = ( entry.options || {} )[ o.key ] ?? o.default;
				ofs.append(
					choiceField( 'opt-' + o.key, o.label || humanize( o.key ), values, cur === undefined || cur === null ? '' : String( cur ), ( v ) =>
						setField( id, ( e ) => {
							e.options = Object.assign( {}, e.options || {}, { [ o.key ]: v } );
						} ),
					{ focusKey: 'opt-' + o.key } )
				);
			}
			form.append( ofs );
		}

		// Anchor.
		const aid = fid( 'anchor' );
		const anchor = h( 'input.df-panel__input', { type: 'text', id: aid, value: entry.anchor || '', autocomplete: 'off', spellcheck: 'false', 'aria-describedby': aid + '-hint', placeholder: 'kontakt', dataset: { focusKey: 'anchor:input' } } );
		anchor.addEventListener( 'change', () => {
			const v = slugify( anchor.value );
			anchor.value = v;
			setField( id, ( e ) => void ( e.anchor = v ) );
		} );
		form.append( h( 'div.df-panel__field', null, h( 'label', { for: aid, text: t( 'anchor' ) } ), h( 'div.df-panel__prefixed', null, h( 'span', { 'aria-hidden': 'true', text: '#' } ), anchor ), h( 'p.df-panel__hint.is-small', { id: aid + '-hint', text: t( 'anchorHint' ) } ) ) );

		// Hidden.
		const hid = fid( 'hidden' );
		const cb = h( 'input', { type: 'checkbox', id: hid, checked: !! entry.hidden, dataset: { focusKey: 'hidden:input' } } );
		cb.addEventListener( 'change', () => setField( id, ( e ) => void ( e.hidden = cb.checked ) ) );
		form.append( h( 'div.df-panel__field.df-panel__check', null, cb, h( 'label', { for: hid, text: t( 'hiddenField' ) } ) ) );

		form.append(
			h( 'p.df-panel__hint.is-small', { text: t( 'textsHint' ) } ),
			h( 'p.df-panel__hint.is-small.df-panel__id', null, t( 'sectionId' ) + ': ', h( 'code', { text: id } ) )
		);

		if ( S.readonly ) {
			form.querySelectorAll( 'input,select,button' ).forEach( ( el ) => ( el.disabled = true ) );
		}

		UI.sidebarBody.append( back, form );
	}

	/* ------------------------------------------------------------------ *
	 * Modals (library, history)
	 * ------------------------------------------------------------------ */

	let modal = null;

	function openModal( title, body, opener, size ) {
		closeModal();
		const tid = fid( 'modal' );
		const dialog = h(
			'div.df-panel__dialog',
			{ role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': tid, class: size ? 'is-' + size : '' },
			h( 'div.df-panel__dialog-head', null, h( 'h2', { id: tid, text: title } ), h( 'button.df-panel__icon-btn', { type: 'button', 'aria-label': t( 'close' ), onClick: () => closeModal() }, icon( 'close' ) ) ),
			h( 'div.df-panel__dialog-body', null, body )
		);
		const overlay = h( 'div.df-panel__overlay', { onClick: ( e ) => e.target === overlay && closeModal() }, dialog );
		[ ...root.children ].forEach( ( el ) => el !== UI.live && el.setAttribute( 'inert', '' ) );
		root.append( overlay );
		modal = { overlay, dialog, opener: opener || document.activeElement };
		dialog.addEventListener( 'keydown', trapFocus );
		const first =
			dialog.querySelector( 'input:not([disabled]),button:not([disabled]):not(.df-panel__dialog-head button),[tabindex="0"]' ) ||
			dialog.querySelector( '.df-panel__dialog-head button' );
		if ( first ) {
			first.focus();
		}
		return dialog;
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		const { overlay, opener } = modal;
		modal = null;
		overlay.remove();
		[ ...root.children ].forEach( ( el ) => el.removeAttribute( 'inert' ) );
		if ( opener && document.contains( opener ) ) {
			opener.focus();
		}
	}

	function trapFocus( e ) {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			closeModal();
			return;
		}
		if ( e.key !== 'Tab' || ! modal ) {
			return;
		}
		const items = [ ...modal.dialog.querySelectorAll( 'a[href],button:not([disabled]),input:not([disabled]),select,[tabindex]:not([tabindex="-1"])' ) ].filter( ( el ) => el.offsetParent !== null );
		if ( ! items.length ) {
			return;
		}
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function openLibrary( index, opener ) {
		if ( ! S.canEdit || S.readonly ) {
			return;
		}
		const sid = fid( 'search' );
		const search = h( 'input.df-panel__input.df-panel__search', { type: 'search', id: sid, autocomplete: 'off', placeholder: t( 'search' ) } );
		const count = h( 'p.df-panel__sr', { 'aria-live': 'polite' } );
		const grid = h( 'ul.df-panel__library', { role: 'list' } );
		const none = h( 'p.df-panel__empty', { text: t( 'noResults' ), hidden: true } );
		const all = ( S.schema.sections || [] ).slice();

		const cards = all.map( ( d ) => {
			const name = d.label || humanize( d.type );
			const preview = d.preview_url
				? h( 'img.df-panel__card-img', { src: d.preview_url, alt: '', loading: 'lazy' } )
				: h( 'span.df-panel__card-ph', { 'aria-hidden': 'true' }, h( 'span.df-panel__card-ph-bar' ), h( 'span.df-panel__card-ph-bar.is-short' ), h( 'span.df-panel__card-ph-label', { text: name } ) );
			const variants = ( d.variants || [] ).length;
			const li = h(
				'li.df-panel__card',
				null,
				h(
					'button.df-panel__card-btn',
					{ type: 'button', 'aria-label': t( 'insert', name ), onClick: () => {
						closeModal();
						insertSection( d, index );
					} },
					h( 'span.df-panel__card-media', null, preview ),
					h( 'span.df-panel__card-body', null, h( 'span.df-panel__card-title', { text: name } ), d.description ? h( 'span.df-panel__card-desc', { text: d.description } ) : null, variants > 1 ? h( 'span.df-panel__card-meta', { text: t( 'variantsCount', variants ) } ) : null )
				)
			);
			li.dataset.search = [ name, d.type, d.description || '', ( d.variants || [] ).map( ( v ) => v.label ).join( ' ' ) ].join( ' ' ).toLowerCase();
			return li;
		} );
		grid.append( ...cards );

		search.addEventListener( 'input', () => {
			const q = search.value.trim().toLowerCase();
			let shown = 0;
			cards.forEach( ( c ) => {
				const ok = ! q || q.split( /\s+/ ).every( ( w ) => c.dataset.search.includes( w ) );
				c.hidden = ! ok;
				shown += ok ? 1 : 0;
			} );
			none.hidden = shown > 0;
			count.textContent = String( shown );
		} );

		openModal( t( 'libraryTitle' ), [ h( 'label.df-panel__sr', { for: sid, text: t( 'search' ) } ), search, count, grid, none ], opener, 'wide' );
	}

	function openHistory( e ) {
		const items = ( S.resp && S.resp.history ) || [];
		const body = [ h( 'p.df-panel__hint', { text: t( 'currentLive', S.rev ) } ) ];
		if ( ! items.length ) {
			body.push( h( 'p.df-panel__empty', { text: t( 'historyEmpty' ) } ) );
		} else {
			const ul = h( 'ul.df-panel__history' );
			for ( const it of items ) {
				let when = it.saved_at || '';
				try {
					const dt = new Date( it.saved_at );
					if ( ! isNaN( dt ) ) {
						when = dt.toLocaleString( document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' } );
					}
				} catch ( err ) {}
				ul.append(
					h(
						'li.df-panel__history-item',
						null,
						h( 'span.df-panel__history-text', null, h( 'strong', { text: t( 'historyRev', it.rev ) } ), h( 'span', { text: it.user_name ? t( 'historyBy', when, it.user_name ) : when } ) ),
						h( 'button.df-panel__btn.is-small', { type: 'button', disabled: S.readonly, text: t( 'restore' ), 'aria-label': t( 'restore' ) + ': ' + t( 'historyRev', it.rev ), onClick: () => restore( Number( it.rev ) ) } )
					)
				);
			}
			body.push( ul );
		}
		openModal( t( 'historyTitle' ), body, e && e.currentTarget );
	}

	/* ------------------------------------------------------------------ *
	 * Keyboard
	 * ------------------------------------------------------------------ */

	function onKey( e ) {
		const mod = e.ctrlKey || e.metaKey;
		const target = e.target;
		const typing = target && ( target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) ) && target.type !== 'checkbox' && target.type !== 'radio';
		if ( mod && ! e.altKey && ( e.key === 'z' || e.key === 'Z' || e.key === 'y' || e.key === 'Y' ) ) {
			if ( typing || S.tab !== 'layout' ) {
				return; // native undo in text fields / DF Inhalt in the preview
			}
			e.preventDefault();
			if ( e.key.toLowerCase() === 'y' || e.shiftKey ) {
				redo();
			} else {
				undo();
			}
			return;
		}
		if ( e.key === 'Escape' && ! modal && ! openMenu && S.selected && S.tab === 'layout' && ! typing && root.contains( target ) ) {
			const id = S.selected;
			S.selected = null;
			renderSidebar();
			UI.sidebarBody.querySelector( '[data-focus-key="row:' + CSS.escape( id ) + '"]' )?.focus();
		}
	}
	document.addEventListener( 'keydown', onKey );

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( S.dirty || S.saving ) {
			e.preventDefault();
			e.returnValue = t( 'unsavedLeave' );
			return e.returnValue;
		}
		return undefined;
	} );

	// Release the own edit lock when the panel closes (DELETE /lock; best effort).
	window.addEventListener( 'pagehide', () => {
		if ( ! S.hadLock || ! S.postId ) {
			return;
		}
		try {
			fetch( apiUrl( 'panel/layout/' + S.postId + '/lock' ), { method: 'DELETE', credentials: 'same-origin', keepalive: true, headers: { 'X-WP-Nonce': nonce, 'X-DF-Panel-Token': panelToken } } );
		} catch ( e ) {}
	} );

	const mq = window.matchMedia( '(max-width: 900px)' );
	mq.addEventListener( 'change', () => {
		S.sidebarOpen = ! mq.matches;
		root.classList.toggle( 'is-sidebar-closed', ! S.sidebarOpen );
		UI.sidebarToggle?.setAttribute( 'aria-expanded', String( S.sidebarOpen ) );
	} );

	boot().catch( ( err ) => {
		// eslint-disable-next-line no-console
		console.error( err );
		fatal( t( 'loadFailed' ) );
	} );
} )();
