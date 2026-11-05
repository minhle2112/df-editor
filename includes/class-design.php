<?php
/**
 * DF Design: site-wide design overrides (option df_design) on top of the theme.json tokens.
 *
 * Shared schema for the Customizer (class-customizer.php).
 * Values are injected through the theme.json user layer (filter wp_theme_json_data_user), so
 * WordPress regenerates --wp--preset--color--primary & co. and every section follows.
 * An empty value means "use the theme.json value".
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Design {

	public const OPTION = 'df_design';

	/** WCAG thresholds: below MIN the change is rejected, below AA a warning is shown. */
	public const CONTRAST_MIN = 3.0;
	public const CONTRAST_AA  = 4.5;

	/** Customisable colours => theme.json palette slug. */
	public const COLORS = array(
		'primary'   => 'primary',
		'secondary' => 'secondary',
		'accent'    => 'accent',
	);

	/** Radius presets: [ general radius, button radius ]. */
	public const RADII = array(
		'none'   => array( '0px', '0px' ),
		'small'  => array( '4px', '4px' ),
		'medium' => array( '8px', '8px' ),
		'large'  => array( '16px', '16px' ),
		'pill'   => array( '16px', '999px' ),
	);

	public const BUTTON_STYLES = array( 'filled', 'outline' );

	/** Fallback colours when the theme does not declare a slug. */
	private const FALLBACK_PALETTE = array(
		'base'     => '#ffffff',
		'base-alt' => '#f5f2ed',
		'contrast' => '#1b2433',
	);

	public static function init(): void {
		add_filter( 'wp_theme_json_data_user', array( __CLASS__, 'filter_theme_json' ) );
		add_filter( 'sanitize_option_' . self::OPTION, array( __CLASS__, 'sanitize_option' ), 10, 3 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Schema
	 * ------------------------------------------------------------------ */

	public static function defaults(): array {
		return array(
			'primary'      => '',
			'secondary'    => '',
			'accent'       => '',
			'heading_font' => '',
			'body_font'    => '',
			'radius'       => '',
			'button_style' => 'filled',
		);
	}

	/**
	 * Stored values merged with defaults.
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), array_intersect_key( is_array( $saved ) ? $saved : array(), self::defaults() ) );
	}

	/**
	 * Labels for the radius presets.
	 */
	public static function radius_choices(): array {
		return array(
			''       => __( 'Theme default', 'df-editor' ),
			'none'   => __( 'None (square)', 'df-editor' ),
			'small'  => __( 'Small', 'df-editor' ),
			'medium' => __( 'Medium', 'df-editor' ),
			'large'  => __( 'Large', 'df-editor' ),
			'pill'   => __( 'Pill buttons', 'df-editor' ),
		);
	}

	/**
	 * Curated font stacks: self-contained system stacks (no downloads, DSGVO-safe)
	 * plus every fontFamily the active theme.json defines (self-hosted by the theme).
	 *
	 * @return array<string,array{label:string,stack:string}>
	 */
	public static function fonts(): array {
		$fonts = array(
			'system-sans'  => array( __( 'System sans-serif', 'df-editor' ), 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' ),
			'system-serif' => array( __( 'System serif', 'df-editor' ), 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif' ),
			'humanist'     => array( __( 'Humanist sans', 'df-editor' ), 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", source-sans-pro, sans-serif' ),
			'geometric'    => array( __( 'Geometric sans', 'df-editor' ), 'Avenir, Montserrat, Corbel, "URW Gothic", source-sans-pro, sans-serif' ),
			'classical'    => array( __( 'Classical humanist', 'df-editor' ), 'Optima, Candara, "Noto Sans", source-sans-pro, sans-serif' ),
			'transitional' => array( __( 'Transitional serif', 'df-editor' ), 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif' ),
			'old-style'    => array( __( 'Old-style serif', 'df-editor' ), '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", P052, serif' ),
			'slab'         => array( __( 'Slab serif', 'df-editor' ), 'Rockwell, "Rockwell Nova", "Roboto Slab", "DejaVu Serif", "Sitka Small", serif' ),
			'mono'         => array( __( 'Monospace', 'df-editor' ), 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace' ),
		);
		$out = array();
		foreach ( self::theme_fonts() as $slug => $font ) {
			/* translators: %s: font name from the theme */
			$out[ 'theme-' . $slug ] = array( 'label' => sprintf( __( 'Theme: %s', 'df-editor' ), $font['name'] ), 'stack' => $font['stack'] );
		}
		foreach ( $fonts as $key => [ $label, $stack ] ) {
			$out[ $key ] = array( 'label' => $label, 'stack' => $stack );
		}
		return $out;
	}

	public static function font_choices(): array {
		$choices = array( '' => __( 'Theme default', 'df-editor' ) );
		foreach ( self::fonts() as $key => $font ) {
			$choices[ $key ] = $font['label'];
		}
		return $choices;
	}

	/**
	 * Font families declared by the active theme (theme origin only).
	 *
	 * @return array<string,array{name:string,stack:string}>
	 */
	public static function theme_fonts(): array {
		$out      = array();
		$settings = self::theme_settings();
		$families = $settings['typography']['fontFamilies']['theme'] ?? ( $settings['typography']['fontFamilies'] ?? array() );
		foreach ( (array) $families as $family ) {
			if ( is_array( $family ) && ! empty( $family['slug'] ) && ! empty( $family['fontFamily'] ) ) {
				$out[ (string) $family['slug'] ] = array(
					'name'  => (string) ( $family['name'] ?? $family['slug'] ),
					'stack' => (string) $family['fontFamily'],
				);
			}
		}
		return $out;
	}

	/**
	 * Theme palette (theme origin) as slug => hex.
	 */
	public static function theme_palette(): array {
		$out      = self::FALLBACK_PALETTE;
		$settings = self::theme_settings();
		$palette  = $settings['color']['palette']['theme'] ?? ( $settings['color']['palette'] ?? array() );
		foreach ( (array) $palette as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['slug'] ) && is_string( $entry['color'] ?? null ) ) {
				$hex = sanitize_hex_color( $entry['color'] );
				if ( $hex ) {
					$out[ (string) $entry['slug'] ] = strtolower( $hex );
				}
			}
		}
		return $out;
	}

	private static function theme_settings(): array {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! wp_theme_has_theme_json() ) {
			return array();
		}
		return (array) WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
	}

	/* ------------------------------------------------------------------ *
	 * Contrast
	 * ------------------------------------------------------------------ */

	/**
	 * Relative luminance (WCAG 2.x) of a #rgb / #rrggbb colour.
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$rgb = array_map( 'hexdec', str_split( substr( str_pad( $hex, 6, '0' ), 0, 6 ), 2 ) );
		$lin = array_map(
			static function ( $c ) {
				$c /= 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Text colour for a background: the theme's light/dark token with the better contrast,
	 * or pure white/black when neither reaches 4.5:1.
	 */
	public static function on_color( string $bg, ?array $palette = null ): string {
		$palette = $palette ?? self::theme_palette();
		$light   = $palette['base'] ?? '#ffffff';
		$dark    = $palette['contrast'] ?? '#000000';
		$best    = self::contrast( $light, $bg ) >= self::contrast( $dark, $bg ) ? $light : $dark;
		if ( self::contrast( $best, $bg ) >= self::CONTRAST_AA ) {
			return $best;
		}
		return self::contrast( '#ffffff', $bg ) >= self::contrast( '#000000', $bg ) ? '#ffffff' : '#000000';
	}

	/**
	 * Foreground/background pairs checked per customisable colour.
	 * Each pair: [ fg slug, bg slug, description ]. The customised colour is looked up by slug.
	 */
	public static function pairs(): array {
		return array(
			'primary'   => array(
				array( 'primary', 'base', __( 'Links/primary colour on the page background', 'df-editor' ) ),
				array( 'primary', 'base-alt', __( 'Links/primary colour on the alternate background', 'df-editor' ) ),
			),
			'secondary' => array(
				array( 'base', 'secondary', __( 'Light text on the secondary colour (hover states)', 'df-editor' ) ),
			),
			'accent'    => array(
				array( 'accent', 'contrast', __( 'Accent colour on the dark scheme', 'df-editor' ) ),
			),
		);
	}

	/**
	 * Checks one colour against its pairs.
	 *
	 * @return array{ratio:float,label:string}|null The worst pair, null when nothing to check.
	 */
	public static function worst_pair( string $key, string $hex, ?array $palette = null ): ?array {
		$palette = $palette ?? self::theme_palette();
		$slug    = self::COLORS[ $key ] ?? '';
		$worst   = null;
		foreach ( self::pairs()[ $key ] ?? array() as [ $fg, $bg, $label ] ) {
			$fg_hex = $fg === $slug ? $hex : ( $palette[ $fg ] ?? null );
			$bg_hex = $bg === $slug ? $hex : ( $palette[ $bg ] ?? null );
			if ( ! $fg_hex || ! $bg_hex ) {
				continue;
			}
			$ratio = self::contrast( $fg_hex, $bg_hex );
			if ( null === $worst || $ratio < $worst['ratio'] ) {
				$worst = array(
					'ratio' => $ratio,
					'label' => $label,
				);
			}
		}
		return $worst;
	}

	/**
	 * Error message when a colour must be rejected (below 3:1), else null.
	 */
	public static function contrast_error( string $key, string $hex ): ?string {
		$worst = self::worst_pair( $key, $hex );
		if ( null === $worst || $worst['ratio'] >= self::CONTRAST_MIN ) {
			return null;
		}
		/* translators: 1: colour pair description, 2: contrast ratio, 3: minimum ratio */
		return sprintf( __( 'Contrast too low: %1$s is %2$s:1 (minimum %3$s:1). The colour was not saved.', 'df-editor' ), $worst['label'], number_format_i18n( $worst['ratio'], 2 ), number_format_i18n( self::CONTRAST_MIN, 1 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Sanitising
	 * ------------------------------------------------------------------ */

	public static function sanitize_color( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( '#' !== $value[0] ) {
			$value = '#' . $value;
		}
		$hex = sanitize_hex_color( $value );
		return $hex ? strtolower( $hex ) : '';
	}

	public static function sanitize_font( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return ( '' === $value || isset( self::fonts()[ $value ] ) ) ? $value : '';
	}

	public static function sanitize_radius( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return isset( self::RADII[ $value ] ) ? $value : '';
	}

	public static function sanitize_button_style( $value ): string {
		return in_array( $value, self::BUTTON_STYLES, true ) ? $value : 'filled';
	}

	/**
	 * Sanitises a full df_design array. A colour failing the 3:1 rule keeps its old value.
	 *
	 * @return array{0:array,1:array<string,string>} [ clean values, errors keyed by setting ]
	 */
	public static function sanitize( $new, $old = array() ): array {
		$new    = is_array( $new ) ? $new : array();
		$old    = array_merge( self::defaults(), is_array( $old ) ? $old : array() );
		$clean  = self::defaults();
		$errors = array();

		foreach ( array_keys( self::COLORS ) as $key ) {
			$hex = self::sanitize_color( $new[ $key ] ?? '' );
			if ( '' !== $hex ) {
				$error = self::contrast_error( $key, $hex );
				if ( null !== $error ) {
					$errors[ $key ] = $error;
					$hex            = self::sanitize_color( $old[ $key ] ?? '' );
				}
			}
			$clean[ $key ] = $hex;
		}
		$clean['heading_font'] = self::sanitize_font( $new['heading_font'] ?? '' );
		$clean['body_font']    = self::sanitize_font( $new['body_font'] ?? '' );
		$clean['radius']       = self::sanitize_radius( $new['radius'] ?? '' );
		$clean['button_style'] = self::sanitize_button_style( $new['button_style'] ?? 'filled' );

		return array( $clean, $errors );
	}

	/**
	 * sanitize_option_df_design: runs for update_option() from anywhere (Customizer, CLI, code).
	 */
	public static function sanitize_option( $value, $option = '', $original = null ): array {
		[ $clean, $errors ] = self::sanitize( $value, get_option( self::OPTION, array() ) );
		if ( $errors && defined( 'WP_CLI' ) && WP_CLI ) {
			foreach ( $errors as $key => $message ) {
				WP_CLI::warning( "df_design[$key]: $message" );
			}
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ *
	 * Output
	 * ------------------------------------------------------------------ */

	/**
	 * Effective palette (theme values with overrides and computed on-colours).
	 */
	public static function palette( ?array $design = null ): array {
		$design  = $design ?? self::get();
		$palette = self::theme_palette();
		foreach ( self::COLORS as $key => $slug ) {
			if ( '' !== $design[ $key ] ) {
				$palette[ $slug ] = $design[ $key ];
			}
		}
		if ( '' !== $design['primary'] ) {
			$palette['on-primary'] = self::on_color( $design['primary'], $palette );
		}
		if ( '' !== $design['accent'] ) {
			$palette['on-accent'] = self::on_color( $design['accent'], $palette );
		}
		return $palette;
	}

	/**
	 * theme.json fragment (user origin) for the stored overrides; empty array when nothing is set.
	 */
	public static function theme_json_fragment( ?array $design = null ): array {
		$design   = $design ?? self::get();
		$settings = array();

		$changed = array();
		foreach ( self::COLORS as $key => $slug ) {
			if ( '' !== $design[ $key ] ) {
				$changed[] = $slug;
			}
		}
		if ( '' !== $design['primary'] ) {
			$changed[] = 'on-primary';
		}
		if ( '' !== $design['accent'] ) {
			$changed[] = 'on-accent';
		}
		if ( $changed ) {
			$palette = self::palette( $design );
			$names   = self::palette_names();
			foreach ( $changed as $slug ) {
				$settings['color']['palette'][] = array(
					'slug'  => $slug,
					'name'  => $names[ $slug ] ?? $slug,
					'color' => $palette[ $slug ],
				);
			}
		}

		$fonts = self::fonts();
		foreach ( array( 'heading_font' => 'heading', 'body_font' => 'body' ) as $key => $slug ) {
			if ( '' !== $design[ $key ] && isset( $fonts[ $design[ $key ] ] ) ) {
				$settings['typography']['fontFamilies'][] = array(
					'slug'       => $slug,
					'name'       => $fonts[ $design[ $key ] ]['label'],
					'fontFamily' => $fonts[ $design[ $key ] ]['stack'],
				);
			}
		}

		if ( '' !== $design['radius'] ) {
			[ $radius, $button ]                     = self::RADII[ $design['radius'] ];
			$settings['custom']['radius']            = $radius;
			$settings['custom']['button-radius']     = $button;
		}
		if ( 'outline' === $design['button_style'] ) {
			$settings['custom']['button-style'] = 'outline';
		}

		return $settings ? array(
			'version'  => 3,
			'settings' => $settings,
		) : array();
	}

	/**
	 * Palette names from the theme (so the editor keeps the theme's labels).
	 */
	private static function palette_names(): array {
		$names    = array();
		$settings = self::theme_settings();
		foreach ( (array) ( $settings['color']['palette']['theme'] ?? array() ) as $entry ) {
			if ( ! empty( $entry['slug'] ) ) {
				$names[ (string) $entry['slug'] ] = (string) ( $entry['name'] ?? $entry['slug'] );
			}
		}
		return $names;
	}

	/**
	 * wp_theme_json_data_user: merge the overrides into the user layer.
	 *
	 * The user layer is resolved for classic themes with theme.json too
	 * (WP_Theme_JSON_Resolver::get_user_data(), output by wp_get_global_stylesheet()).
	 *
	 * @param WP_Theme_JSON_Data $theme_json User data.
	 */
	public static function filter_theme_json( $theme_json ) {
		$fragment = self::theme_json_fragment();
		if ( $fragment && is_object( $theme_json ) && method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json->update_with( $fragment );
		}
		return $theme_json;
	}

	/**
	 * Adds df-btn-style-outline to <body> when outline buttons are chosen.
	 */
	public static function body_class( array $classes ): array {
		if ( 'outline' === self::get()['button_style'] ) {
			$classes[] = 'df-btn-style-outline';
		}
		return $classes;
	}

	/**
	 * Clears the theme.json caches so the next request regenerates the CSS variables.
	 */
	public static function flush(): void {
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
	}
}
