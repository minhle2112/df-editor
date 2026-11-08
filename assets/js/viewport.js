/*
 * DF Editor view switcher: Desktop / Tablet / Mobile (see includes/class-viewport.php).
 *
 * Top page: Tablet / Mobile lay a stage over the page (below the admin bar, under the DF Inhalt
 * toolbar) with an iframe of the same URL + ?df_view=…, loaded invisibly at full width and scrolled
 * to the same position. Once the frame is ready the stage fades in (it looks identical), the page
 * scroll is locked and the frame's width animates to the device width, so the page seems to shrink
 * in place. Drag handles / arrow keys resize it, the rotate button swaps portrait and landscape.
 * Desktop animates back to full width, puts the page at the frame's scroll position and removes
 * the stage (or reloads / follows the frame after a save or a link).
 *
 * Messages (postMessage, same origin, { source: 'df-view', type, … }):
 *   frame → top: hello { url, edit }, ready { edit }, state { edit, busy }, saved, close,
 *                leave { url }, where { y, moved, url, edit }
 *   top → frame: init { y, edit, moved }, edit { on }, esc, query
 */
( function () {
	'use strict';

	var cfg = window.dfView || {};
	var L = cfg.labels || {};
	var FLAG = 'df_view';
	var MSG = 'df-view';
	var KEY = 'dfView'; // { v, r, w } view, rotated, custom width of this browser tab.
	var BACK = 'dfViewBack'; // { url, y, edit } scroll / edit mode for the page loaded after the frame.
	var FRAME_Y = 'dfViewFrameY'; // { url, y } last scroll position inside the frame (for a reload).
	var origin = window.location.origin;
	var root = document.documentElement;

	// sessionStorage (may throw or be empty): read with one argument, write a value, null removes.
	var session = function ( key, value ) {
		try {
			if ( undefined === value ) {
				return JSON.parse( window.sessionStorage.getItem( key ) || 'null' );
			}
			if ( null === value ) {
				window.sessionStorage.removeItem( key );
			} else {
				window.sessionStorage.setItem( key, JSON.stringify( value ) );
			}
		} catch ( e ) {}
		return null;
	};

	// The page URL without the switcher / DF Inhalt flags and the hash.
	var cleanUrl = function ( href ) {
		var u = new URL( href, window.location.href );
		[ FLAG, 'df_inhalt', 'df-inhalt-edit', 'df-inhalt-field' ].forEach( function ( k ) {
			u.searchParams.delete( k );
		} );
		u.hash = '';
		return u.href;
	};

	var typing = function () {
		var a = document.activeElement;
		return !! ( a && ( a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( a.tagName ) ) );
	};

	/* ================================================================ Inside the frame. */
	if ( cfg.frame ) {
		if ( window.parent === window ) {
			return;
		}
		var parentWin = window.parent;
		var toggle = document.querySelector( '[data-df-toggle]' );
		var moved = false;
		var post = function ( type, data ) {
			var m = data || {};
			m.source = MSG;
			m.type = type;
			parentWin.postMessage( m, origin );
		};
		var editing = function () {
			return root.classList.contains( 'df-edit-active' );
		};
		var state = function () {
			post( 'state', { edit: editing(), busy: !! ( toggle && toggle.disabled ) } );
		};
		var frames = function ( n ) {
			return new Promise( function ( resolve ) {
				var step = function () {
					if ( n-- > 0 ) {
						window.requestAnimationFrame( step );
					} else {
						resolve();
					}
				};
				step();
			} );
		};
		// Ready to be shown: images in view loaded and fonts ready (at most 1.5 s).
		var settle = function () {
			return frames( 2 ).then( function () {
				var h = window.innerHeight;
				var waits = Array.prototype.filter.call( document.images, function ( img ) {
					var r = img.getBoundingClientRect();
					return ! img.complete && r.width > 0 && r.bottom > 0 && r.top < h;
				} ).map( function ( img ) {
					return new Promise( function ( resolve ) {
						img.addEventListener( 'load', resolve, { once: true } );
						img.addEventListener( 'error', resolve, { once: true } );
					} );
				} );
				if ( document.fonts && document.fonts.ready ) {
					waits.push( document.fonts.ready );
				}
				return Promise.race( [ Promise.all( waits ), new Promise( function ( resolve ) {
					window.setTimeout( resolve, 1500 );
				} ) ] );
			} ).then( function () {
				return frames( 2 );
			} );
		};
		// Keep the section at the top of the view in place while the width changes (the page reflows
		// during the width animation): anchor = first section on screen + how far it is scrolled.
		var anchor = null;
		var selfScroll = null; // Target of our own scrollTo (its scroll event is not the user's).
		var counting = false; // Scrolls count as "moved" only once the frame is ready.
		var resizedAt = 0;
		var takeAnchor = function () {
			var list = document.querySelectorAll( '[data-df-section]' );
			if ( ! list.length ) {
				list = document.querySelectorAll( 'main > *, article > *' );
			}
			anchor = null;
			for ( var i = 0; i < list.length; i++ ) {
				var r = list[ i ].getBoundingClientRect();
				if ( r.height > 0 && r.bottom > 24 ) {
					anchor = { el: list[ i ], ratio: -r.top / r.height };
					return;
				}
			}
		};
		var keepAnchor = function () {
			if ( ! anchor || ! document.contains( anchor.el ) ) {
				return;
			}
			var r = anchor.el.getBoundingClientRect();
			var delta = Math.round( r.top + anchor.ratio * r.height );
			if ( delta ) {
				window.scrollTo( { top: window.scrollY + delta, left: 0, behavior: 'instant' } );
				selfScroll = window.scrollY;
			}
		};
		window.addEventListener( 'resize', function () {
			resizedAt = Date.now();
			keepAnchor();
		} );
		window.addEventListener( 'scroll', function () {
			var own = null !== selfScroll && Math.abs( window.scrollY - selfScroll ) <= 1;
			selfScroll = null;
			if ( ! own ) {
				takeAnchor();
				// Any other scroll (wheel, keys, Tab focus, scrollIntoView, …) counts; reflows while the
				// width changes do not.
				if ( counting && Date.now() - resizedAt > 120 ) {
					moved = true;
				}
			}
		}, { passive: true } );

		var init = function ( d ) {
			// DF Motion items already in view appear at once (the frame must look like the page).
			root.classList.add( 'df-view-instant' );
			counting = false;
			if ( 'number' === typeof d.y ) {
				window.scrollTo( { top: d.y, left: 0, behavior: 'instant' } );
			}
			if ( 'boolean' === typeof d.edit && window.dfInhalt && d.edit !== editing() ) {
				window.dfInhalt.setEditMode( d.edit );
			}
			// Restored after a reload: the frame's position is not the page's, Desktop maps back from it.
			moved = !! d.moved;
			settle().then( function () {
				takeAnchor();
				counting = true;
				post( 'ready', { edit: editing() } );
				state();
				window.setTimeout( function () {
					root.classList.remove( 'df-view-instant' );
				}, 600 );
			} );
		};

		document.addEventListener( 'df-inhalt:saved', function () {
			post( 'saved' );
		} );
		document.addEventListener( 'df-inhalt:editmode', state );
		if ( toggle && window.MutationObserver ) {
			// The (hidden) toggle is disabled while a popup is open: the top toggle follows.
			new window.MutationObserver( state ).observe( toggle, { attributes: true, attributeFilter: [ 'disabled' ] } );
		}

		// Did the user scroll in the frame? (Width changes reflow the page; those do not count.)
		var mark = function () {
			moved = true;
		};
		window.addEventListener( 'wheel', mark, { passive: true } );
		window.addEventListener( 'touchmove', mark, { passive: true } );
		window.addEventListener( 'keydown', function ( e ) {
			if ( ! typing() && /^(ArrowUp|ArrowDown|PageUp|PageDown|Home|End| )$/.test( e.key ) ) {
				moved = true;
			}
		}, true );

		// Links followed inside the frame keep the flag; other sites and wp-admin open in the top window.
		document.addEventListener( 'click', function ( e ) {
			var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
			if ( ! a || e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.hasAttribute( 'download' ) || ( a.target && '_self' !== a.target ) ) {
				return;
			}
			var u = new URL( a.href, window.location.href );
			if ( ! /^https?:$/.test( u.protocol ) ) {
				return;
			}
			if ( u.origin !== origin || /\/wp-(admin|login\.php)/.test( u.pathname ) ) {
				e.preventDefault();
				post( 'leave', { url: u.href } );
				return;
			}
			if ( u.hash && cleanUrl( u.href ) === cleanUrl( window.location.href ) ) {
				moved = true; // Anchor on this page.
				return;
			}
			e.preventDefault();
			u.searchParams.set( FLAG, cfg.frame );
			window.location.assign( u.href );
		} );

		// Forms keep the flag too (otherwise the page loads without it, then again with it, and a POST
		// answer would be lost). Bubble phase: forms sent by script (default prevented) stay untouched.
		window.addEventListener( 'submit', function ( e ) {
			var f = e.target;
			if ( e.defaultPrevented || ! f || 'FORM' !== f.tagName ) {
				return;
			}
			var sub = e.submitter && e.submitter.form === f ? e.submitter : null;
			var target = sub && sub.hasAttribute( 'formtarget' ) ? sub.getAttribute( 'formtarget' ) : f.getAttribute( 'target' );
			var method = ( ( sub && sub.hasAttribute( 'formmethod' ) ? sub.getAttribute( 'formmethod' ) : f.getAttribute( 'method' ) ) || 'get' ).toLowerCase();
			if ( ( target && '_self' !== target ) || 'dialog' === method ) {
				return;
			}
			var u = new URL( ( sub && sub.hasAttribute( 'formaction' ) ? sub.formAction : f.action ) || window.location.href, window.location.href );
			if ( ! /^https?:$/.test( u.protocol ) ) {
				return;
			}
			if ( u.origin !== origin ) {
				f.setAttribute( 'target', '_top' ); // Other sites open in the top window.
				return;
			}
			if ( /\/wp-(admin|login\.php)/.test( u.pathname ) ) {
				return;
			}
			if ( 'post' === method ) {
				u.searchParams.set( FLAG, cfg.frame );
				if ( sub && sub.hasAttribute( 'formaction' ) ) {
					sub.setAttribute( 'formaction', u.href );
				} else {
					f.setAttribute( 'action', u.href );
				}
			} else if ( ! ( f.elements[ FLAG ] ) ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = FLAG;
				input.value = cfg.frame;
				f.appendChild( input );
			}
		} );

		// Esc goes back to Desktop only when nothing on the page uses it. State read in the capture
		// phase (before DF Inhalt or a burger menu reacts), decision in the bubble phase.
		var busyEsc = false;
		window.addEventListener( 'keydown', function ( e ) {
			busyEsc = 'Escape' === e.key && !! ( editing() || root.classList.contains( 'df-inhalt-media-open' ) ||
				document.querySelector( '[data-df-popup]:not([hidden]), [aria-expanded="true"], dialog[open], [aria-modal="true"]' ) || typing() );
		}, true );
		window.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! busyEsc && ! e.defaultPrevented ) {
				post( 'close' );
			}
		} );

		window.addEventListener( 'message', function ( e ) {
			var d = e.data;
			if ( e.source !== parentWin || e.origin !== origin || ! d || MSG !== d.source ) {
				return;
			}
			if ( 'init' === d.type ) {
				init( d );
			} else if ( 'edit' === d.type ) {
				if ( window.dfInhalt ) {
					window.dfInhalt.setEditMode( !! d.on );
				}
				state();
			} else if ( 'esc' === d.type ) {
				// Esc pressed in the top page while the frame is busy / in edit mode: same as an Esc here.
				var t = document.activeElement && document.activeElement !== document.body ? document.activeElement : document;
				t.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true } ) );
			} else if ( 'query' === d.type ) {
				post( 'where', { y: window.scrollY, moved: moved, url: window.location.href, edit: editing() } );
			}
		} );

		window.addEventListener( 'pagehide', function () {
			session( FRAME_Y, { url: cleanUrl( window.location.href ), y: window.scrollY } );
		} );

		var hello = function () {
			post( 'hello', { url: window.location.href, edit: editing() } );
		};
		if ( 'complete' === document.readyState ) {
			hello();
		} else {
			window.addEventListener( 'load', hello );
		}
		return;
	}

	/* ================================================================ Top page. */
	// Never nested: a framed page without the flag does nothing.
	if ( window.top !== window ) {
		return;
	}

	// Page loaded after the frame (saved there, or followed a link): scroll position and edit mode.
	var back = session( BACK );
	if ( back ) {
		session( BACK, null );
		if ( back.url === cleanUrl( window.location.href ) ) {
			var applyBack = function () {
				window.scrollTo( { top: Number( back.y ) || 0, left: 0, behavior: 'instant' } );
			};
			applyBack();
			window.addEventListener( 'load', function () {
				applyBack();
				try {
					window.history.scrollRestoration = 'auto';
				} catch ( e ) {}
			} );
			if ( back.edit && window.dfInhalt ) {
				window.dfInhalt.setEditMode( true );
			}
		}
	}

	var group = document.querySelector( '.df-view-buttons--toolbar' );
	if ( ! group ) {
		return;
	}
	var SIZES = { tablet: [ 768, 1024 ], mobile: [ 390, 844 ] }; // [ portrait, landscape ] width.
	var MIN = 320;
	var GUTTER = 28; // Room for the drag handles on each side.
	var reduce = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	var rotateBtn = group.querySelector( '[data-df-rotate]' );
	var desktopBtn = group.querySelector( '[data-df-view="desktop"]' );
	var toggle = document.querySelector( '[data-df-toggle]' );
	var view = 'desktop';
	var rotated = false;
	var custom = 0;
	var phase = 'off'; // off | loading | on | closing
	var stage = null;
	var canvas = null;
	var iframe = null;
	var sizeLabel = null;
	var handles = [];
	var inerted = [];
	var opener = null;
	var here = '';
	var pageY = 0;
	var pendingY = null;
	var frameEdit = false;
	var frameBusy = false; // A DF Inhalt popup is open in the frame (unsaved input possible).
	var initMoved = false;
	var saved = false;
	var readyTimer = 0;
	var labelTimer = 0;
	var closeToken = 0;
	var pendingQuery = null;
	var shownW = 0; // Width of the canvas (CSS px, before scaling) in a narrow view.

	var still = function () {
		return !! ( reduce && reduce.matches );
	};
	var wait = function ( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, still() ? 0 : ms );
		} );
	};
	var send = function ( type, data ) {
		if ( iframe && iframe.contentWindow ) {
			var m = data || {};
			m.source = MSG;
			m.type = type;
			iframe.contentWindow.postMessage( m, origin );
		}
	};
	var remember = function () {
		session( KEY, SIZES[ view ] ? { v: view, r: rotated, w: custom } : null );
	};
	var preset = function () {
		var s = SIZES[ view ];
		return s ? s[ rotated ? 1 : 0 ] : 0;
	};
	var avail = function () {
		return Math.max( MIN, ( stage ? stage.clientWidth : window.innerWidth ) - 2 * GUTTER );
	};
	var el = function ( cls ) {
		var d = document.createElement( 'div' );
		d.className = cls;
		return d;
	};

	var pressed = function () {
		group.querySelectorAll( '[data-df-view]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-df-view' ) === view ? 'true' : 'false' );
		} );
		if ( rotateBtn ) {
			rotateBtn.hidden = ! SIZES[ view ];
			rotateBtn.setAttribute( 'aria-pressed', rotated ? 'true' : 'false' );
		}
	};

	var setBusy = function ( b ) {
		group.querySelectorAll( '[aria-busy]' ).forEach( function ( x ) {
			x.removeAttribute( 'aria-busy' );
		} );
		if ( b ) {
			b.setAttribute( 'aria-busy', 'true' );
		}
	};

	// The top edit toggle shows (and drives) edit mode inside the frame.
	var syncToggle = function ( busy ) {
		if ( ! toggle ) {
			return;
		}
		var lab = ( window.dfInhaltEditor && window.dfInhaltEditor.labels ) || {};
		toggle.setAttribute( 'aria-pressed', frameEdit ? 'true' : 'false' );
		( toggle.querySelector( '[data-df-toggle-label]' ) || toggle ).textContent = frameEdit ? ( lab.closeEditor || 'Close DF Inhalt' ) : ( lab.openEditor || 'Open DF Inhalt' );
		toggle.disabled = !! busy;
		root.classList.toggle( 'df-view-edit', frameEdit );
	};
	// While a popup is open in the frame, Desktop waits (it would throw the input away), like the toggle.
	var syncBusy = function ( busy ) {
		frameBusy = !! busy;
		syncToggle( frameBusy );
		if ( desktopBtn ) {
			desktopBtn.disabled = frameBusy;
		}
	};

	var showLabel = function ( stay ) {
		if ( ! sizeLabel ) {
			return;
		}
		window.clearTimeout( labelTimer );
		sizeLabel.classList.add( 'is-visible' );
		if ( ! stay ) {
			labelTimer = window.setTimeout( function () {
				if ( sizeLabel ) {
					sizeLabel.classList.remove( 'is-visible' );
				}
			}, 1400 );
		}
	};

	// Full width (Desktop) or the device / custom width, scaled down when it does not fit.
	var applyWidth = function ( full ) {
		if ( ! stage ) {
			return;
		}
		if ( full ) {
			// As tall as the window, like the page (vh units match); the part below the window is hidden.
			canvas.style.width = '100%';
			canvas.style.height = 'calc(100% + var(--df-view-top, 0px))';
			canvas.style.setProperty( '--df-view-scale', '1' );
			stage.classList.remove( 'is-narrow' );
			return;
		}
		var max = avail();
		var w = custom ? Math.min( custom, max ) : preset();
		var scale = w > max ? max / w : 1;
		var text = ( L.width || '%s px' ).replace( '%s', String( Math.round( w ) ) ) + ( scale < 1 ? ' · ' + Math.round( scale * 100 ) + '%' : '' );
		shownW = w;
		canvas.style.width = w + 'px';
		canvas.style.height = ( 100 / scale ) + '%';
		canvas.style.setProperty( '--df-view-scale', String( scale ) );
		stage.classList.add( 'is-narrow' );
		sizeLabel.textContent = text;
		handles.forEach( function ( h ) {
			h.setAttribute( 'aria-valuenow', String( Math.round( w ) ) );
			h.setAttribute( 'aria-valuemax', String( Math.round( Math.max( max, w ) ) ) );
			h.setAttribute( 'aria-valuetext', text );
		} );
	};

	var setCustom = function ( w ) {
		custom = Math.round( Math.max( MIN, Math.min( avail(), w ) ) );
		applyWidth( false );
	};

	// Change without the width transition (keyboard steps, window resize, plain switch).
	var instant = function ( fn ) {
		stage.classList.add( 'no-anim' );
		fn();
		void canvas.offsetWidth; // Apply the new width before the transition comes back.
		stage.classList.remove( 'no-anim' );
	};

	var resetWidth = function () {
		custom = 0;
		applyWidth( false );
		showLabel();
		remember();
	};

	var wireHandle = function ( h ) {
		var side = h.getAttribute( 'data-side' );
		var id = null;
		var startX = 0;
		var startW = 0;
		var end = function ( e ) {
			if ( null === id || e.pointerId !== id ) {
				return;
			}
			id = null;
			if ( stage ) {
				stage.classList.remove( 'is-dragging' );
			}
			showLabel();
			remember();
		};
		h.addEventListener( 'pointerdown', function ( e ) {
			if ( 0 !== e.button || 'on' !== phase ) {
				return;
			}
			e.preventDefault();
			h.focus( { preventScroll: true } );
			id = e.pointerId;
			try {
				h.setPointerCapture( id );
			} catch ( err ) {}
			startX = e.clientX;
			startW = canvas.getBoundingClientRect().width;
			stage.classList.add( 'is-dragging' );
			showLabel( true );
		} );
		h.addEventListener( 'pointermove', function ( e ) {
			if ( null === id || e.pointerId !== id ) {
				return;
			}
			var dx = e.clientX - startX;
			setCustom( startW + 2 * ( 'start' === side ? -dx : dx ) );
		} );
		h.addEventListener( 'pointerup', end );
		h.addEventListener( 'pointercancel', end );
		h.addEventListener( 'lostpointercapture', end );
		h.addEventListener( 'dblclick', resetWidth );
		h.addEventListener( 'keydown', function ( e ) {
			if ( 'on' !== phase ) {
				return;
			}
			var step = e.shiftKey ? 50 : 10;
			var cur = Math.round( shownW );
			var grow = 'end' === side ? 'ArrowRight' : 'ArrowLeft';
			var shrink = 'end' === side ? 'ArrowLeft' : 'ArrowRight';
			var next = null;
			if ( grow === e.key || 'ArrowUp' === e.key ) {
				next = cur + step;
			} else if ( shrink === e.key || 'ArrowDown' === e.key ) {
				next = cur - step;
			} else if ( 'Home' === e.key ) {
				next = MIN;
			} else if ( 'End' === e.key ) {
				next = avail();
			} else if ( 'Enter' === e.key ) {
				e.preventDefault();
				resetWidth();
				return;
			}
			if ( null === next ) {
				return;
			}
			e.preventDefault();
			instant( function () {
				setCustom( next );
			} );
			showLabel();
			remember();
		} );
	};

	var build = function () {
		stage = el( 'df-view-stage' );
		canvas = el( 'df-view-canvas' );
		iframe = document.createElement( 'iframe' );
		iframe.className = 'df-view-frame-el';
		iframe.title = L.frame || 'Device preview';
		canvas.appendChild( iframe );
		handles = [ 'start', 'end' ].map( function ( side ) {
			var h = el( 'df-view-handle df-view-handle--' + side );
			h.tabIndex = 0;
			h.setAttribute( 'data-side', side );
			h.setAttribute( 'role', 'separator' );
			h.setAttribute( 'aria-orientation', 'vertical' );
			h.setAttribute( 'aria-label', L.resize || 'Resize preview' );
			h.setAttribute( 'aria-valuemin', String( MIN ) );
			h.title = L.hint || '';
			canvas.appendChild( h );
			wireHandle( h );
			return h;
		} );
		sizeLabel = el( 'df-view-size' );
		sizeLabel.setAttribute( 'aria-hidden', 'true' );
		stage.append( canvas, sizeLabel, el( 'df-view-shield' ) );

		// Behind the frame while it is still full width: the page's own background.
		var bg = window.getComputedStyle( document.body ).backgroundColor;
		if ( ! bg || /^(transparent|rgba\(0, 0, 0, 0\))$/.test( bg ) ) {
			bg = '#fff';
		}
		stage.style.setProperty( '--df-view-page-bg', bg );
		placeStage();
		applyWidth( true );
		document.body.appendChild( stage );

		// A page reached without the flag (script / form navigation): put it back (same origin only).
		iframe.addEventListener( 'load', function () {
			try {
				var loc = iframe.contentWindow.location;
				if ( loc.origin === origin && 'about:blank' !== loc.href && ! new URL( loc.href ).searchParams.has( FLAG ) ) {
					var u = new URL( loc.href );
					u.searchParams.set( FLAG, SIZES[ view ] ? view : 'mobile' );
					loc.replace( u.href );
				}
			} catch ( e ) {}
		} );
	};

	// The stage starts right below the admin bar (when it is on screen).
	var placeStage = function () {
		var bar = document.getElementById( 'wpadminbar' );
		var top = bar ? Math.max( 0, Math.round( bar.getBoundingClientRect().bottom ) ) : 0;
		stage.style.setProperty( '--df-view-top', top + 'px' );
	};

	// The page under the stage: no scrolling, not focusable (admin bar, toolbar and stage stay usable).
	var lockPage = function () {
		var bar = document.getElementById( 'wpadminbar' );
		var before = stage.getBoundingClientRect().width;
		// A classic scrollbar keeps its room (else the page comes back narrower than the frame).
		root.classList.toggle( 'df-view-gutter', window.innerWidth - root.clientWidth > 0 );
		root.classList.add( 'df-view-on' );
		var grown = Math.round( stage.getBoundingClientRect().width - before );
		stage.style.right = grown > 0 ? grown + 'px' : '';
		inerted = Array.prototype.filter.call( document.body.children, function ( node ) {
			return node !== stage && ! node.inert && ! node.contains( group ) && ! ( bar && node.contains( bar ) ) &&
				! node.classList.contains( 'df-inhalt-ui' ) && ! /^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT|LINK)$/.test( node.tagName );
		} );
		inerted.forEach( function ( node ) {
			node.inert = true;
		} );
		if ( Math.abs( window.scrollY - pageY ) > 1 ) {
			window.scrollTo( { top: pageY, left: 0, behavior: 'instant' } );
		}
	};

	var unlockPage = function ( y ) {
		inerted.forEach( function ( node ) {
			node.inert = false;
		} );
		inerted = [];
		root.classList.add( 'df-view-instant' );
		root.classList.remove( 'df-view-on', 'df-view-gutter' );
		window.scrollTo( { top: y, left: 0, behavior: 'instant' } );
		window.setTimeout( function () {
			root.classList.remove( 'df-view-instant' );
		}, 700 );
	};

	// Back to the normal page: toggle and edit mode of the page itself, stage removed.
	var release = function ( edit, fade ) {
		window.clearTimeout( readyTimer );
		setBusy( null );
		phase = 'off';
		frameEdit = false;
		syncBusy( false ); // Toggle enabled, label of the page's own (off) edit mode.
		if ( window.dfInhalt && edit ) {
			window.dfInhalt.setEditMode( true );
		}
		var s = stage;
		stage = canvas = iframe = sizeLabel = null;
		handles = [];
		if ( ! s ) {
			return;
		}
		s.classList.remove( 'is-shown' );
		wait( fade ? 140 : 0 ).then( function () {
			s.remove();
		} );
	};

	var reveal = function ( plain ) {
		window.clearTimeout( readyTimer );
		setBusy( null );
		if ( 'loading' !== phase || ! stage ) {
			return;
		}
		phase = 'on';
		if ( stage.classList.contains( 'is-shown' ) ) {
			// Restored after a reload: already in place.
			instant( function () {
				applyWidth( false );
			} );
			return;
		}
		if ( plain ) {
			// No answer from the frame in time: switch without the fade and the animation.
			lockPage();
			instant( function () {
				stage.classList.add( 'is-shown' );
				applyWidth( false );
			} );
			showLabel();
			return;
		}
		stage.classList.add( 'is-shown' );
		wait( 130 ).then( function () {
			if ( 'on' !== phase || ! stage ) {
				return;
			}
			lockPage();
			void stage.offsetWidth; // Start the width transition from the full width.
			applyWidth( false );
			showLabel();
		} );
	};

	var show = function ( v, opts ) {
		opts = opts || {};
		if ( v !== view && ! opts.keep ) {
			rotated = false;
			custom = 0;
		} else if ( v === view && ! opts.keep ) {
			custom = 0; // Same button again: back to its preset width.
		}
		view = v;
		pressed();
		remember();
		if ( 'on' === phase || 'closing' === phase ) {
			closeToken++;
			phase = 'on';
			applyWidth( false );
			showLabel();
			return;
		}
		if ( 'loading' === phase ) {
			return; // Applied once the frame is ready.
		}
		phase = 'loading';
		saved = false;
		here = cleanUrl( window.location.href );
		pageY = window.scrollY;
		var wanted = !! ( window.dfInhalt && window.dfInhalt.isEditMode() );
		if ( wanted ) {
			window.dfInhalt.setEditMode( false ); // Edit mode moves into the frame.
		}
		frameEdit = wanted;
		syncToggle( false );
		pendingY = pageY;
		build();
		if ( opts.restore ) {
			var fy = session( FRAME_Y );
			pendingY = fy && fy.url === here ? Number( fy.y ) || 0 : pageY;
			initMoved = !! ( fy && fy.url === here ); // Desktop then maps back from the frame position.
			stage.classList.add( 'no-anim', 'is-shown' );
			lockPage();
			applyWidth( false );
		}
		setBusy( opener );
		var u = new URL( here );
		u.searchParams.set( FLAG, v );
		iframe.src = u.href;
		// No answer from the frame: show it anyway, without the fade.
		readyTimer = window.setTimeout( function () {
			reveal( true );
		}, 8000 );
	};

	var query = function () {
		return new Promise( function ( resolve ) {
			var t = window.setTimeout( function () {
				pendingQuery = null;
				resolve( null );
			}, 600 );
			pendingQuery = function ( d ) {
				window.clearTimeout( t );
				pendingQuery = null;
				resolve( d );
			};
			send( 'query' );
		} );
	};

	var finish = function ( where, refocus ) {
		var edit = where ? !! where.edit : frameEdit;
		var y = where && where.moved ? Number( where.y ) || 0 : pageY;
		var there = where && 'string' === typeof where.url && 0 === where.url.indexOf( origin + '/' ) ? cleanUrl( where.url ) : here;
		// Saved in the frame, or followed a link there: show that page, up to date.
		if ( saved || there !== here ) {
			session( BACK, { url: there, y: there === here ? y : 0, edit: edit } );
			if ( there === here ) {
				try {
					window.history.scrollRestoration = 'manual';
				} catch ( e ) {}
				window.location.reload();
			} else {
				window.location.assign( there );
			}
			return;
		}
		unlockPage( y );
		release( edit, true );
		if ( refocus && opener && document.contains( opener ) ) {
			opener.focus();
		}
	};

	var toDesktop = function ( refocus ) {
		view = 'desktop';
		rotated = false;
		custom = 0;
		pressed();
		remember();
		if ( 'loading' === phase ) {
			if ( root.classList.contains( 'df-view-on' ) ) {
				unlockPage( pageY );
			}
			release( frameEdit, false );
			return;
		}
		if ( 'on' !== phase ) {
			return;
		}
		phase = 'closing';
		var token = ++closeToken;
		applyWidth( true );
		wait( 430 ).then( query ).then( function ( where ) {
			if ( token === closeToken && 'closing' === phase ) {
				finish( where, refocus );
			}
		} );
		if ( refocus && opener && document.contains( opener ) ) {
			opener.focus();
		}
	};

	/* ---------------------------------------------------------------- Events. */
	group.addEventListener( 'click', function ( e ) {
		var b = e.target && e.target.closest ? e.target.closest( '[data-df-view]' ) : null;
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var v = b.getAttribute( 'data-df-view' );
		if ( SIZES[ v ] ) {
			opener = b;
			show( v );
		} else if ( ! frameBusy ) {
			toDesktop( false );
		}
	} );

	if ( rotateBtn ) {
		rotateBtn.addEventListener( 'click', function () {
			if ( ! SIZES[ view ] ) {
				return;
			}
			rotated = ! rotated;
			custom = 0;
			pressed();
			remember();
			if ( 'on' === phase ) {
				applyWidth( false );
				showLabel();
			}
		} );
	}

	// The edit toggle drives the frame while a narrow view is on (capture: before DF Inhalt's handler).
	window.addEventListener( 'click', function ( e ) {
		if ( 'off' === phase || ! toggle || ! toggle.contains( e.target ) ) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		if ( 'on' === phase ) {
			send( 'edit', { on: ! frameEdit } );
		} else if ( 'loading' === phase ) {
			frameEdit = ! frameEdit;
			syncToggle( false );
		}
	}, true );

	// The page itself never enters edit mode under the stage (e.g. a comment link): the frame does.
	document.addEventListener( 'df-inhalt:editmode', function ( e ) {
		if ( 'off' === phase || ! e.detail || ! e.detail.active || ! window.dfInhalt ) {
			return;
		}
		window.dfInhalt.setEditMode( false );
		frameEdit = true;
		syncToggle( frameBusy );
		if ( 'on' === phase ) {
			send( 'edit', { on: true } );
		}
	} );

	// Esc: back to Desktop, unless the focus is in a field or an admin bar menu / dialog is open.
	window.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key || ( 'on' !== phase && 'loading' !== phase ) || e.defaultPrevented ) {
			return;
		}
		if ( typing() || root.classList.contains( 'df-inhalt-media-open' ) || document.querySelector( '#wpadminbar .hover, dialog[open]' ) ) {
			return;
		}
		e.preventDefault();
		// Page UI first: a popup / edit mode in the frame takes this Esc (as if pressed there).
		if ( 'on' === phase && ( frameBusy || frameEdit ) ) {
			send( 'esc' );
			return;
		}
		toDesktop( true );
	} );

	window.addEventListener( 'message', function ( e ) {
		var d = e.data;
		if ( ! iframe || e.source !== iframe.contentWindow || e.origin !== origin || ! d || MSG !== d.source ) {
			return;
		}
		switch ( d.type ) {
			case 'hello':
				send( 'init', { y: pendingY, edit: frameEdit, moved: initMoved } );
				initMoved = false;
				pendingY = null; // A page opened later in the frame starts at its top.
				break;
			case 'ready':
				frameEdit = !! d.edit;
				syncToggle( false );
				reveal( false );
				break;
			case 'state':
				frameEdit = !! d.edit;
				syncBusy( d.busy );
				break;
			case 'saved':
				saved = true;
				break;
			case 'close':
				toDesktop( true );
				break;
			case 'leave':
				if ( 'string' === typeof d.url && /^https?:\/\//.test( d.url ) ) {
					window.location.assign( d.url );
				}
				break;
			case 'where':
				if ( pendingQuery ) {
					pendingQuery( d );
				}
				break;
		}
	} );

	window.addEventListener( 'resize', function () {
		if ( ! stage ) {
			return;
		}
		placeStage();
		if ( 'on' === phase ) {
			instant( function () {
				applyWidth( false );
			} );
		}
	} );

	// Same view again after a reload in this browser tab (directly, without the animation).
	var last = session( KEY );
	if ( last && SIZES[ last.v ] ) {
		rotated = Boolean( last.r );
		custom = Math.max( 0, Number( last.w ) || 0 );
		opener = group.querySelector( '[data-df-view="' + last.v + '"]' );
		show( last.v, { keep: true, restore: true } );
	} else {
		pressed();
	}
}() );
