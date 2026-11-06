/**
 * DF Motion: entrance animations of DF sections (DF Design → Scroll animations).
 *
 * The inline head CSS hides the items of every section with data-df-anim until the section gets
 * "is-in"; this script tags the items (class df-anim-item + --df-i for the stagger), watches the
 * sections with one IntersectionObserver and adds "is-in" once. No scroll listeners.
 * window.dfMotion.sections = { type: [ itemSelectors, mediaSelectors ] } (printed by DF_Assets).
 *
 * @package DF_Editor
 */
( function () {
	'use strict';

	var root = document.documentElement;

	if ( ! root.classList.contains( 'df-motion' ) ) {
		return; // Reduced motion, or the safety net already showed everything.
	}
	if ( ! ( 'IntersectionObserver' in window ) || typeof WeakMap === 'undefined' ) {
		root.classList.remove( 'df-motion' );
		return;
	}

	var config = ( window.dfMotion && window.dfMotion.sections ) || {};
	var own = Object.prototype.hasOwnProperty;
	var MAX_STEPS = 6; // Long lists must not wait: the stagger stops after 6 steps.
	var styles = getComputedStyle( root );
	var tagged = new WeakMap();

	function time( name, fallback ) {
		var value = styles.getPropertyValue( name ).trim();
		var n = parseFloat( value );
		if ( isNaN( n ) ) {
			return fallback;
		}
		return /[^m]s$/.test( value ) ? n * 1000 : n;
	}

	var settle = time( '--df-anim-duration', 650 ) + time( '--df-anim-stagger', 100 ) * MAX_STEPS + 100;

	function prepare( section ) {
		var type = section.getAttribute( 'data-df-section' );
		var lists = own.call( config, type ) && Array.isArray( config[ type ] ) ? config[ type ] : [];
		var items = [];
		lists.forEach( function ( selectors ) {
			( Array.isArray( selectors ) ? selectors : [] ).forEach( function ( selector ) {
				var nodes;
				try {
					nodes = section.querySelectorAll( selector );
				} catch ( e ) {
					return;
				}
				Array.prototype.forEach.call( nodes, function ( el ) {
					if ( items.indexOf( el ) === -1 ) {
						el.style.setProperty( '--df-i', String( Math.min( items.length, MAX_STEPS ) ) );
						el.classList.add( 'df-anim-item' );
						items.push( el );
					}
				} );
			} );
		} );
		tagged.set( section, items );
	}

	function reveal( section ) {
		section.classList.add( 'is-in' );
		var items = tagged.get( section ) || [];
		// After the transition the items go back to their own styles (hover transitions etc.).
		window.setTimeout( function () {
			items.forEach( function ( el ) {
				el.classList.remove( 'df-anim-item' );
				el.style.removeProperty( '--df-i' );
			} );
		}, settle );
	}

	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				// In view, or already scrolled past (anchor jump): show it.
				if ( entry.isIntersecting || entry.boundingClientRect.bottom < 0 ) {
					observer.unobserve( entry.target );
					reveal( entry.target );
				}
			} );
		},
		{ threshold: 0, rootMargin: '0px 0px -10% 0px' }
	);

	try {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-df-anim]' ), function ( section ) {
			prepare( section );
			observer.observe( section );
		} );
		window.dfMotionReady = true; // Tells the head safety net that the script ran.
	} catch ( e ) {
		root.classList.remove( 'df-motion' ); // Never leave content hidden.
	}
}() );
