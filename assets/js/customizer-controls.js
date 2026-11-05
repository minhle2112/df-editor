/**
 * DF Design: contrast warnings in the Customizer controls pane.
 * Below 4.5:1 a warning notification is shown (saving allowed); below 3:1 the server rejects the value.
 */
( function ( api ) {
	'use strict';
	var cfg = window.dfDesign;
	if ( ! api || ! cfg ) {
		return;
	}

	function lum( hex ) {
		hex = String( hex || '' ).replace( '#', '' );
		if ( hex.length === 3 ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {
			return null;
		}
		var c = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( hex.substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	}

	function ratio( a, b ) {
		var la = lum( a ), lb = lum( b );
		if ( la === null || lb === null ) {
			return null;
		}
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function check( key, setting ) {
		var hex = setting.get(), slug = cfg.colors[ key ], worst = null;
		setting.notifications.remove( 'df_contrast_warning' );
		if ( ! hex ) {
			return;
		}
		( cfg.pairs[ key ] || [] ).forEach( function ( p ) {
			var fg = p[ 0 ] === slug ? hex : cfg.palette[ p[ 0 ] ];
			var bg = p[ 1 ] === slug ? hex : cfg.palette[ p[ 1 ] ];
			var r = ratio( fg, bg );
			if ( r !== null && ( worst === null || r < worst.r ) ) {
				worst = { r: r, label: p[ 2 ] };
			}
		} );
		if ( worst && worst.r >= cfg.min && worst.r < cfg.aa ) {
			setting.notifications.add( new api.Notification( 'df_contrast_warning', {
				type: 'warning',
				message: cfg.warning.replace( '%1$s', worst.label ).replace( '%2$s', worst.r.toFixed( 2 ) )
			} ) );
		}
	}

	api.bind( 'ready', function () {
		Object.keys( cfg.colors ).forEach( function ( key ) {
			api( cfg.option + '[' + key + ']', function ( setting ) {
				check( key, setting );
				setting.bind( function () {
					check( key, setting );
				} );
			} );
		} );
	} );
}( window.wp && window.wp.customize ) );
