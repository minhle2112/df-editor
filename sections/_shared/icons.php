<?php
/**
 * Shared inline SVG icon set for DF sections (simple 24px line icons, drawn for DF Editor).
 *
 * Usage in a section template:
 *   require_once DF_EDITOR_DIR . 'sections/_shared/icons.php';
 *   df_icon( 'phone', array( 'class' => 'df-cta__icon' ) );
 *
 * Icons are decorative by default (aria-hidden). Pass 'label' to make one meaningful
 * (role="img" + aria-label). Colour = currentColor, size = 1em unless the section CSS says otherwise.
 * Themes can add or replace icons with the filter "df_editor_icons" (name => inner SVG markup).
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'df_icon_set' ) ) {
	/**
	 * All icons: name => inner SVG markup (viewBox 0 0 24 24, stroke based).
	 *
	 * @return array<string,string>
	 */
	function df_icon_set(): array {
		static $icons = null;
		if ( null !== $icons ) {
			return $icons;
		}
		$icons = array(
			'check'       => '<path d="M20 6 9 17l-5-5"/>',
			'phone'       => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
			'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'map-pin'     => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
			'star'        => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
			'shield'      => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
			'wrench'      => '<path d="M14 3.5a5 5 0 0 0-3.6 7.1l-6.6 6.6a2 2 0 0 0 2.9 2.9l6.6-6.6A5 5 0 0 0 20.5 9L17 12.5 13.5 11 12 7.5 15.5 4a5 5 0 0 0-1.5-.5z"/>',
			'flame'       => '<path d="M12 21c-4 0-7-2.8-7-6.5 0-3 2-5 3.5-6.5.3 2 1.3 3 2.5 3.5C11 8 12 5 14 3c.5 3 5 5.5 5 11 0 4-3 7-7 7z"/>',
			'droplet'     => '<path d="M12 3s-6 6.5-6 11a6 6 0 0 0 12 0c0-4.5-6-11-6-11z"/>',
			'home'        => '<path d="m3 11 9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
			'leaf'        => '<path d="M5 20c0-9.5 5.5-15 15-15 0 9.5-5.5 15-15 15z"/><path d="M5 20c3-4.5 6-7 10-9"/>',
			'bolt'        => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
			'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
			'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'arrow-left'  => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
		);

		/**
		 * Filters the DF icon set.
		 *
		 * @param array<string,string> $icons name => inner SVG markup (24x24 viewBox, stroke based).
		 */
		$icons = (array) apply_filters( 'df_editor_icons', $icons );
		return $icons;
	}
}

if ( ! function_exists( 'df_icon_names' ) ) {
	/**
	 * Names of all available icons.
	 *
	 * @return string[]
	 */
	function df_icon_names(): array {
		return array_keys( df_icon_set() );
	}
}

if ( ! function_exists( 'df_get_icon' ) ) {
	/**
	 * Returns the SVG markup of an icon ('' for unknown names).
	 *
	 * @param string $name Icon name, e.g. "phone".
	 * @param array  $args class (extra classes), label (accessible name; omitted = decorative).
	 */
	function df_get_icon( string $name, array $args = array() ): string {
		$icons = df_icon_set();
		$name  = sanitize_key( $name );
		if ( '' === $name || ! isset( $icons[ $name ] ) ) {
			return '';
		}
		$class = trim( 'df-icon df-icon--' . $name . ' ' . (string) ( $args['class'] ?? '' ) );
		$label = trim( (string) ( $args['label'] ?? '' ) );
		$a11y  = '' !== $label
			? ' role="img" aria-label="' . esc_attr( $label ) . '"'
			: ' aria-hidden="true"';

		return sprintf(
			'<svg class="%1$s" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"%2$s>%3$s</svg>',
			esc_attr( $class ),
			$a11y,
			$icons[ $name ] // Trusted markup from the icon set (code or theme filter).
		);
	}
}

if ( ! function_exists( 'df_icon' ) ) {
	/**
	 * Prints an icon. See df_get_icon().
	 *
	 * @param string $name Icon name.
	 * @param array  $args class, label.
	 */
	function df_icon( string $name, array $args = array() ): void {
		echo df_get_icon( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from trusted icon markup, attributes escaped.
	}
}
