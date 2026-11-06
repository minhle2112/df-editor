<?php
/**
 * theme.json -> tokens.css for plain-HTML mockups (no WordPress needed).
 *
 * Writes :root custom properties with exactly the names WordPress generates from theme.json,
 * so a mockup and the WordPress site share the same variables:
 *   --wp--preset--color--<slug>, --wp--preset--font-family--<slug>, --wp--preset--font-size--<slug>,
 *   --wp--preset--spacing--<slug>, --wp--custom--<path-kebab>, --wp--style--global--content-size / wide-size
 * plus @font-face rules from "fontFace" and body / heading / link defaults from "styles".
 *
 * A child theme (style.css "Template: <parent>") is merged over the parent theme.json, like WordPress.
 * Fluid font sizes become clamp() with WordPress' formula (same result within rounding).
 *
 * Usage:
 *   php bin/tokens-css.php <path/to/theme.json> [--font-base=<url prefix>] > tokens.css
 *       --font-base: replaces the theme folder in fontFace "file:./..." paths. Default: the theme
 *       folder as a file:/// URL (works when the mockup is opened from disk on this machine).
 *       Example: copy the theme's assets/fonts folder to <mockup>/assets/fonts (keep the path)
 *       and use --font-base=./ : "file:./assets/fonts/x.woff2" -> "./assets/fonts/x.woff2".
 *   php bin/tokens-css.php --framework [<out file>]
 *       Copies assets/css/base.css (the framework, single source) to framework/df-framework.css
 *       (or <out file>) with a "generated" header. Run after every base.css change.
 *
 * @package DF_Editor
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// PHP 8.0: array_is_list() exists from PHP 8.1.
if ( ! function_exists( 'array_is_list' ) ) {
	function array_is_list( array $a ): bool { // phpcs:ignore
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}

$args = array_slice( $argv, 1 );
$root = dirname( __DIR__ );

if ( in_array( '--framework', $args, true ) ) {
	$rest = array_values( array_diff( $args, array( '--framework' ) ) );
	$out  = $rest[0] ?? $root . '/framework/df-framework.css';
	$css  = file_get_contents( $root . '/assets/css/base.css' );
	if ( false === $css ) {
		fwrite( STDERR, "Cannot read assets/css/base.css\n" );
		exit( 1 );
	}
	$head = "/* GENERATED copy of df-editor/assets/css/base.css for plain-HTML mockups.\n"
		. " * Do not edit: change base.css, then run  php bin/tokens-css.php --framework */\n";
	if ( false === file_put_contents( $out, $head . $css ) ) {
		fwrite( STDERR, "Cannot write $out\n" );
		exit( 1 );
	}
	fwrite( STDERR, "Wrote $out\n" );
	exit( 0 );
}

$file      = null;
$font_base = null;
foreach ( $args as $arg ) {
	if ( str_starts_with( $arg, '--font-base=' ) ) {
		$font_base = substr( $arg, 12 );
	} elseif ( ! str_starts_with( $arg, '--' ) ) {
		$file = $arg;
	}
}
if ( null === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php bin/tokens-css.php <path/to/theme.json> [--font-base=<prefix>] > tokens.css\n" );
	exit( 1 );
}

/**
 * Reads a theme.json, merged over the parent theme's theme.json for a child theme.
 */
function dftc_load( string $file ): array {
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "Invalid JSON: $file\n" );
		exit( 1 );
	}
	$style = dirname( $file ) . '/style.css';
	if ( is_readable( $style ) && preg_match( '/^[ \t\/*#@]*Template:\s*(\S+)/mi', (string) file_get_contents( $style ), $m ) ) {
		$parent = dirname( $file, 2 ) . '/' . $m[1] . '/theme.json';
		if ( is_readable( $parent ) ) {
			$data = dftc_merge( dftc_load( $parent ), $data );
		}
	}
	return $data;
}

/**
 * Merges like WordPress: objects recursively, preset lists (arrays of {slug}) replaced as a whole.
 */
function dftc_merge( array $base, array $over ): array {
	foreach ( $over as $key => $value ) {
		if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) ) {
			$base[ $key ] = dftc_merge( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

/** camelCase / spaces -> kebab-case (like _wp_to_kebab_case for the usual keys). */
function dftc_kebab( string $s ): string {
	$s = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $s );
	$s = preg_replace( '/([a-zA-Z])([0-9])/', '$1-$2', (string) $s );
	return strtolower( trim( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $s ), '-' ) );
}

/** "var:preset|color|primary" -> var(--wp--preset--color--primary). */
function dftc_value( $v ): string {
	$v = is_scalar( $v ) ? (string) $v : '';
	if ( str_starts_with( $v, 'var:' ) ) {
		return 'var(--wp--' . str_replace( '|', '--', substr( $v, 4 ) ) . ')';
	}
	return $v;
}

/** Splits "1.25rem" into [1.25, 'rem']; null when not a px/rem/em length. */
function dftc_len( $v ): ?array {
	if ( preg_match( '/^\s*(-?[0-9.]+)\s*(px|rem|em)\s*$/', (string) $v, $m ) ) {
		return array( (float) $m[1], $m[2] );
	}
	return null;
}

/** Converts a length to $unit (16px = 1rem). */
function dftc_to( array $len, string $unit ): float {
	if ( $len[1] === $unit ) {
		return $len[0];
	}
	return 'px' === $unit ? $len[0] * 16 : $len[0] / 16;
}

/**
 * Font size value: fluid clamp() like wp_get_typography_font_size_value(), else the size.
 */
function dftc_font_size( array $preset, $fluid_settings ): string {
	$size  = (string) ( $preset['size'] ?? '' );
	$fluid = $preset['fluid'] ?? null;
	if ( empty( $fluid_settings ) || false === $fluid ) {
		return $size;
	}
	$fluid_settings = is_array( $fluid_settings ) ? $fluid_settings : array();
	$max_len        = dftc_len( is_array( $fluid ) && isset( $fluid['max'] ) ? $fluid['max'] : $size );
	if ( null === $max_len ) {
		return $size;
	}
	$unit    = $max_len[1];
	$min_vw  = dftc_len( $fluid_settings['minViewportWidth'] ?? '320px' ) ?? array( 320, 'px' );
	$max_vw  = dftc_len( $fluid_settings['maxViewportWidth'] ?? '1600px' ) ?? array( 1600, 'px' );
	$min_cap = dftc_len( $fluid_settings['minFontSize'] ?? '14px' ) ?? array( 14, 'px' );
	if ( is_array( $fluid ) && isset( $fluid['min'] ) && null !== dftc_len( $fluid['min'] ) ) {
		$min = dftc_to( dftc_len( $fluid['min'] ), $unit );
	} else {
		$max_v = dftc_to( $max_len, $unit );
		$cap   = dftc_to( $min_cap, $unit );
		if ( $max_v <= $cap ) {
			return $size; // Small sizes stay static.
		}
		// WordPress: logarithmic scale factor, clamped to 0.25..0.75, never below minFontSize.
		$px     = dftc_to( $max_len, 'px' );
		$factor = min( max( 1 - 0.075 * log( $px, 2 ), 0.25 ), 0.75 );
		$min    = max( round( $max_v * $factor, 3 ), $cap );
	}
	$max      = dftc_to( $max_len, $unit );
	$vw_min   = dftc_to( $min_vw, $unit );
	$vw_max   = dftc_to( $max_vw, $unit );
	$offset   = round( $vw_min / 100, 3 );
	$factor_l = round( 100 * ( ( $max - $min ) / ( $vw_max - $vw_min ) ), 3 );
	$fmt      = static fn( float $n ): string => rtrim( rtrim( number_format( $n, 4, '.', '' ), '0' ), '.' ) . $unit;
	return sprintf( 'clamp(%1$s, %1$s + ((1vw - %2$s) * %3$s), %4$s)', $fmt( $min ), $fmt( $offset ), rtrim( rtrim( number_format( $factor_l, 3, '.', '' ), '0' ), '.' ), $fmt( $max ) );
}

$json     = dftc_load( $file );
$settings = (array) ( $json['settings'] ?? array() );
$styles   = (array) ( $json['styles'] ?? array() );
$vars     = array();

foreach ( (array) ( $settings['color']['palette'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--color--' . dftc_kebab( (string) $p['slug'] ) ] = (string) $p['color'];
}
foreach ( (array) ( $settings['color']['gradients'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--gradient--' . dftc_kebab( (string) $p['slug'] ) ] = (string) $p['gradient'];
}
$faces = array();
foreach ( (array) ( $settings['typography']['fontFamilies'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--font-family--' . dftc_kebab( (string) $p['slug'] ) ] = (string) $p['fontFamily'];
	foreach ( (array) ( $p['fontFace'] ?? array() ) as $face ) {
		$faces[] = $face;
	}
}
$fluid = $settings['typography']['fluid'] ?? false;
foreach ( (array) ( $settings['typography']['fontSizes'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--font-size--' . dftc_kebab( (string) $p['slug'] ) ] = dftc_font_size( $p, $fluid );
}
foreach ( (array) ( $settings['spacing']['spacingSizes'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--spacing--' . dftc_kebab( (string) $p['slug'] ) ] = (string) $p['size'];
}
foreach ( (array) ( $settings['shadow']['presets'] ?? array() ) as $p ) {
	$vars[ '--wp--preset--shadow--' . dftc_kebab( (string) $p['slug'] ) ] = (string) $p['shadow'];
}

$walk = static function ( array $node, string $prefix ) use ( &$walk, &$vars ): void {
	foreach ( $node as $key => $value ) {
		$name = $prefix . '--' . dftc_kebab( (string) $key );
		if ( is_array( $value ) ) {
			$walk( $value, $name );
		} elseif ( is_scalar( $value ) ) {
			$vars[ $name ] = (string) $value;
		}
	}
};
$walk( (array) ( $settings['custom'] ?? array() ), '--wp--custom' );

if ( ! empty( $settings['layout']['contentSize'] ) ) {
	$vars['--wp--style--global--content-size'] = (string) $settings['layout']['contentSize'];
}
if ( ! empty( $settings['layout']['wideSize'] ) ) {
	$vars['--wp--style--global--wide-size'] = (string) $settings['layout']['wideSize'];
}

// ---------------------------------------------------------------- Output.
$theme_dir = str_replace( '\\', '/', (string) realpath( dirname( $file ) ) );
$font_base = $font_base ?? 'file:///' . ltrim( $theme_dir, '/' ) . '/';
$font_base = '' === $font_base || str_ends_with( $font_base, '/' ) ? $font_base : $font_base . '/';

$out   = array();
$out[] = '/* GENERATED from ' . basename( dirname( $file ) ) . '/theme.json by df-editor/bin/tokens-css.php.';
$out[] = ' * Same variable names as WordPress. Regenerate when theme.json changes; do not edit. */';

foreach ( $faces as $face ) {
	$src = array();
	foreach ( (array) ( $face['src'] ?? array() ) as $s ) {
		$s      = (string) $s;
		$url    = str_starts_with( $s, 'file:./' ) ? $font_base . substr( $s, 7 ) : $s;
		$format = preg_match( '/\.(woff2?|ttf|otf)$/i', $s, $m ) ? strtolower( $m[1] ) : '';
		$format = array( 'ttf' => 'truetype', 'otf' => 'opentype' )[ $format ] ?? $format;
		$src[]  = 'url("' . $url . '")' . ( $format ? ' format("' . $format . '")' : '' );
	}
	if ( ! $src ) {
		continue;
	}
	$family = trim( (string) ( $face['fontFamily'] ?? '' ), "\"' " );
	$out[]  = '@font-face {';
	$out[]  = "\tfont-family: \"" . $family . '";';
	foreach ( array( 'fontStyle' => 'font-style', 'fontWeight' => 'font-weight', 'fontDisplay' => 'font-display', 'fontStretch' => 'font-stretch', 'unicodeRange' => 'unicode-range' ) as $k => $prop ) {
		if ( isset( $face[ $k ] ) ) {
			$out[] = "\t" . $prop . ': ' . $face[ $k ] . ';';
		}
	}
	$out[] = "\tsrc: " . implode( ', ', $src ) . ';';
	$out[] = '}';
}

$out[] = ':root {';
foreach ( $vars as $name => $value ) {
	$out[] = "\t" . $name . ': ' . $value . ';';
}
$out[] = '}';

// Global styles (like WordPress' global stylesheet, reduced to what mockups need).
$pick = static function ( array $node ): array {
	$css = array();
	$map = array(
		'color'      => array( 'background' => 'background', 'text' => 'color' ),
		'typography' => array( 'fontFamily' => 'font-family', 'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'lineHeight' => 'line-height', 'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'textDecoration' => 'text-decoration' ),
	);
	foreach ( $map as $group => $props ) {
		foreach ( $props as $k => $prop ) {
			if ( isset( $node[ $group ][ $k ] ) && is_scalar( $node[ $group ][ $k ] ) ) {
				$css[ $prop ] = dftc_value( $node[ $group ][ $k ] );
			}
		}
	}
	return $css;
};
$rule = static function ( string $selector, array $css ) use ( &$out ): void {
	if ( ! $css ) {
		return;
	}
	$out[] = $selector . ' {';
	foreach ( $css as $prop => $value ) {
		$out[] = "\t" . $prop . ': ' . $value . ';';
	}
	$out[] = '}';
};

$out[] = '*, *::before, *::after { box-sizing: border-box; }';
$out[] = 'img { max-width: 100%; height: auto; }';
$body  = array_merge(
	array(
		'margin'      => '0',
		'background'  => 'var(--wp--preset--color--base)',
		'color'       => 'var(--wp--preset--color--contrast)',
		'font-family' => 'var(--wp--preset--font-family--body)',
	),
	$pick( $styles )
);
$rule( 'body', $body );
$elements = (array) ( $styles['elements'] ?? array() );
$rule( 'h1, h2, h3, h4, h5, h6', $pick( (array) ( $elements['heading'] ?? array() ) ) );
foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
	$rule( $h, $pick( (array) ( $elements[ $h ] ?? array() ) ) );
}
$rule( 'a', $pick( (array) ( $elements['link'] ?? array() ) ) );
$rule( 'a:hover', $pick( (array) ( $elements['link'][':hover'] ?? array() ) ) );

echo implode( "\n", $out ) . "\n";
