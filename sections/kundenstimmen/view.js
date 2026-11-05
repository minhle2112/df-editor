/**
 * Kundenstimmen slider: prev/next buttons for a CSS scroll-snap track.
 * No cloning (editable nodes must stay unique), works without JS (plain scrollable track).
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' );

	function editing() {
		return root.classList.contains( 'df-edit-active' );
	}

	function setup( wrap ) {
		var track = wrap.querySelector( '[data-df-slides]' );
		var controls = wrap.querySelector( '.df-kundenstimmen__controls' );
		if ( ! track || ! controls ) {
			return;
		}
		var prev = controls.querySelector( '[data-df-dir="-1"]' );
		var next = controls.querySelector( '[data-df-dir="1"]' );

		// One step = width of one slide plus the gap.
		function step() {
			var first = track.firstElementChild;
			if ( ! first ) {
				return track.clientWidth;
			}
			var gap = parseFloat( getComputedStyle( track ).columnGap ) || 0;
			return first.getBoundingClientRect().width + gap;
		}

		function update() {
			var max = track.scrollWidth - track.clientWidth;
			var overflow = max > 2 && ! editing();
			controls.hidden = ! overflow;
			if ( ! overflow ) {
				return;
			}
			var pos = Math.abs( track.scrollLeft );
			prev.disabled = pos <= 2;
			next.disabled = pos >= max - 2;
		}

		function move( dir ) {
			var rtl = 'rtl' === getComputedStyle( track ).direction ? -1 : 1;
			track.scrollBy( {
				left: dir * rtl * step(),
				behavior: reduce && reduce.matches ? 'auto' : 'smooth'
			} );
		}

		controls.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-df-dir]' );
			if ( btn && ! btn.disabled ) {
				move( parseInt( btn.getAttribute( 'data-df-dir' ), 10 ) );
			}
		} );

		// Arrow keys while the track (or something inside it) has focus.
		track.addEventListener( 'keydown', function ( e ) {
			if ( editing() || e.altKey || e.ctrlKey || e.metaKey ) {
				return;
			}
			if ( 'ArrowRight' === e.key || 'ArrowLeft' === e.key ) {
				if ( e.target !== track ) {
					return; // Do not hijack arrows inside editable/text content.
				}
				e.preventDefault();
				move( 'ArrowRight' === e.key ? 1 : -1 );
			}
		} );

		var raf = 0;
		track.addEventListener( 'scroll', function () {
			if ( ! raf ) {
				raf = requestAnimationFrame( function () {
					raf = 0;
					update();
				} );
			}
		}, { passive: true } );

		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( update ).observe( track );
		} else {
			window.addEventListener( 'resize', update );
		}
		new MutationObserver( update ).observe( root, { attributes: true, attributeFilter: [ 'class' ] } );
		update();
	}

	function init() {
		document.querySelectorAll( '.df-kundenstimmen [data-df-slider]' ).forEach( setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
