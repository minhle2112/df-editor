<?php
/**
 * Helpers for DF section templates in the theme (df-sections/<type>/section.php).
 * Loaded by the plugin on after_setup_theme (after the theme's functions.php). Every function is
 * guarded with function_exists, so a theme can provide its own version first.
 *
 * - df_item_setting( $s, $n, $key, $fallback ) agency-only list item setting from the layout JSON
 *   (section.json list field with "type": "setting"; never passed to DF Inhalt)
 * - df_field_filled( $value )                  whether a text/richtext/button value has visible text
 * - df_heading_tag( $value, $default )         whitelists h1-h4
 * - df_section_head( $s, $type, $h, $args )    eyebrow/title/intro head, hide-when-empty for visitors
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'df_item_setting' ) ) {
	/**
	 * Agency setting of a list item, read from the layout JSON only (never client-editable).
	 *
	 * Settings are declared in section.json as list fields with "type": "setting" and are
	 * never passed to DF Inhalt, e.g. content.items[0].icon = "wrench" → df_item_setting( $s, 1, 'icon' ).
	 *
	 * @param DF_Section $s        Section.
	 * @param int        $n        1-based item number.
	 * @param string     $key      Setting key.
	 * @param mixed      $fallback Returned when the layout has no value (null = section.json default).
	 * @return mixed
	 */
	function df_item_setting( DF_Section $s, int $n, string $key, mixed $fallback = null ): mixed {
		$def      = $s->definition();
		$list_key = (string) ( $def['list']['key'] ?? 'items' );
		$items    = (array) ( $s->instance()['content'][ $list_key ] ?? array() );
		$item     = (array) ( $items[ $n - 1 ] ?? array() );
		if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
			return $item[ $key ];
		}
		if ( null !== $fallback ) {
			return $fallback;
		}
		return $def['list']['fields'][ $key ]['default'] ?? null;
	}
}

if ( ! function_exists( 'df_field_filled' ) ) {
	/**
	 * Whether a field value has visible content (text, rich text or button text).
	 *
	 * @param mixed $value Value from $s->value() / $item->value().
	 */
	function df_field_filled( mixed $value ): bool {
		if ( is_array( $value ) ) {
			$value = (string) ( $value['text'] ?? '' );
		}
		return '' !== trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'df_heading_tag' ) ) {
	/**
	 * Whitelisted heading tag from an option value.
	 *
	 * @param string|null $value   Option value.
	 * @param string      $default Default tag.
	 */
	function df_heading_tag( ?string $value, string $default = 'h2' ): string {
		$value = (string) $value;
		return in_array( $value, array( 'h1', 'h2', 'h3', 'h4' ), true ) ? $value : $default;
	}
}

if ( ! function_exists( 'df_section_head' ) ) {
	/**
	 * Prints the common section head (eyebrow, title as __heading, intro) with "hide when empty" for visitors.
	 * Only fields that exist in section.json are printed. Editors always see every field.
	 *
	 * @param DF_Section $s    Section.
	 * @param string     $type Block class base, e.g. "leistungen" → df-leistungen__head.
	 * @param string     $h    Heading tag (whitelisted h1–h4).
	 * @param array      $args class (extra head classes), title_class.
	 */
	function df_section_head( DF_Section $s, string $type, string $h = 'h2', array $args = array() ): void {
		$fields   = (array) ( $s->definition()['fields'] ?? array() );
		$can_edit = $s->can_edit();
		$h        = df_heading_tag( $h );
		$show     = array();
		foreach ( array( 'eyebrow', 'title', 'intro' ) as $field ) {
			$show[ $field ] = isset( $fields[ $field ] ) && ( $can_edit || df_field_filled( $s->value( $field ) ) );
		}
		if ( ! array_filter( $show ) ) {
			return;
		}
		$block = 'df-' . sanitize_html_class( $type );
		printf( '<div class="%s">', esc_attr( trim( $block . '__head ' . ( $args['class'] ?? '' ) ) ) );
		if ( $show['eyebrow'] ) {
			echo '<p class="df-eyebrow">';
			$s->text( 'eyebrow' );
			echo '</p>';
		}
		if ( $show['title'] ) {
			printf( '<%s class="%s">', $h, esc_attr( trim( $block . '__heading ' . ( $args['title_class'] ?? 'df-title' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
			$s->text( 'title' );
			echo '</' . $h . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
		}
		if ( $show['intro'] ) {
			$s->richtext( 'intro', array( 'class' => $block . '__intro df-muted' ) );
		}
		echo '</div>';
	}
}
