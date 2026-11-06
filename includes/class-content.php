<?php
/**
 * DF_Content: the ONLY core class that talks to the DF Inhalt module.
 *
 * When DF Inhalt is booted and enabled, every call is forwarded to it (editable markup).
 * Otherwise the saved value from option df_content_values (or the default) is printed,
 * escaped, so the site never falls back to dummy text.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Content {

	/** Option holding all saved content values (shared with DF Inhalt). */
	public const VALUES_OPTION = 'df_content_values';

	/** @var array|null Cached fallback values. */
	private static ?array $values = null;

	/** @var string[] Fallback region stack (tag names). */
	private static array $regions = array();

	/**
	 * Whether DF Inhalt is present and enabled.
	 */
	public static function editing(): bool {
		return class_exists( 'DF_Inhalt' ) && DF_Inhalt::editing_enabled();
	}

	/**
	 * Whether the current user may edit content on the front end.
	 */
	public static function can_edit(): bool {
		return self::editing() && DF_Inhalt::can_edit();
	}

	public static function text( string $id, array $args = array() ): void {
		if ( self::editing() ) {
			DF_Inhalt::text( $id, $args );
			return;
		}
		self::plain_text( self::value( $id, $args['default'] ?? '' ) );
	}

	public static function richtext( string $id, array $args = array() ): void {
		if ( self::editing() ) {
			DF_Inhalt::richtext( $id, $args );
			return;
		}
		self::plain_richtext( self::value( $id, $args['default'] ?? '' ), $args );
	}

	public static function button( string $id, array $args = array() ): void {
		if ( self::editing() ) {
			DF_Inhalt::button( $id, $args );
			return;
		}
		self::plain_button( self::value( $id, $args['default'] ?? array() ), $args );
	}

	public static function image( string $id, array $args = array() ): void {
		$attachment_id = (int) self::value( $id, $args['default'] ?? 0 );
		// A deleted attachment falls back to the default, like DF Inhalt.
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = absint( $args['default'] ?? 0 );
		}
		$args = self::image_classes( $attachment_id, $args );

		if ( self::editing() ) {
			DF_Inhalt::image( $id, $args );
			return;
		}
		self::plain_image( $attachment_id, $args );
	}

	/* ------------------------------------------------------ Fallback output */

	private static function plain_text( mixed $value ): void {
		echo esc_html( is_scalar( $value ) ? (string) $value : '' );
	}

	private static function plain_richtext( mixed $value, array $args ): void {
		$html = is_scalar( $value ) ? (string) $value : '';
		if ( '' === trim( $html ) ) {
			return;
		}
		printf(
			'<div class="%s">%s</div>',
			esc_attr( trim( 'df-inhalt-richtext ' . ( $args['class'] ?? '' ) ) ),
			wp_kses( $html, self::richtext_tags() )
		);
	}

	private static function plain_button( mixed $value, array $args ): void {
		$value = is_array( $value ) ? $value : array();
		$text  = (string) ( $value['text'] ?? '' );
		$url   = (string) ( $value['url'] ?? '' );
		if ( '' === trim( $text ) ) {
			return;
		}
		$target = (string) ( $value['target'] ?? $args['target'] ?? '' );
		$class  = trim( (string) ( $args['class'] ?? '' ) );
		$before = wp_kses_post( (string) ( $args['before'] ?? '' ) );
		$after  = wp_kses_post( (string) ( $args['after'] ?? '' ) );
		$label  = esc_html( $text );
		if ( '' !== $before || '' !== $after ) {
			$label = '<span class="df-inhalt-button__text">' . $label . '</span>';
		}

		// Same markup as the DF Inhalt button: before/after inside the link, "#" when no URL.
		printf(
			'<a%s href="%s"%s>%s%s%s</a>',
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
			esc_url( '' !== $url ? $url : '#' ),
			'_blank' === $target ? ' target="_blank" rel="noopener"' : '',
			$before, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above.
			$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html above.
			$after // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above.
		);
	}

	/**
	 * Image classes: always a class (never class=""), plus a marker for the per-site placeholder.
	 */
	private static function image_classes( int $attachment_id, array $args ): array {
		$classes   = array_filter( preg_split( '/\s+/', trim( (string) ( $args['class'] ?? '' ) ) ) );
		$classes[] = 'df-img';
		if ( $attachment_id > 0 && $attachment_id === self::placeholder_id() ) {
			$classes[] = 'df-img--placeholder';
		}
		$args['class'] = implode( ' ', array_unique( $classes ) );
		return $args;
	}

	private static function plain_image( int $attachment_id, array $args ): void {
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$style = array();
		if ( ! empty( $args['aspect_ratio'] ) ) {
			$style[] = 'aspect-ratio:' . $args['aspect_ratio'];
		}
		if ( ! empty( $args['object_fit'] ) ) {
			$style[] = 'object-fit:' . $args['object_fit'];
		}
		if ( ! empty( $args['object_position'] ) ) {
			$style[] = 'object-position:' . $args['object_position'];
		}

		$attr = array_filter(
			array(
				'class'         => $args['class'] ?? '',
				'loading'       => $args['loading'] ?? '',
				'fetchpriority' => $args['fetchpriority'] ?? '',
				'style'         => implode( ';', $style ),
				'alt'           => isset( $args['alt'] ) ? trim( (string) $args['alt'] ) : '',
			)
		);
		if ( 'high' === ( $attr['fetchpriority'] ?? '' ) && empty( $attr['loading'] ) ) {
			$attr['loading'] = 'eager';
		}

		echo wp_get_attachment_image( $attachment_id, $args['size'] ?? 'large', false, $attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function region_open( array $args = array() ): void {
		if ( self::editing() ) {
			DF_Inhalt::region_open( $args );
			return;
		}
		$tag             = tag_escape( $args['tag'] ?? 'div' );
		self::$regions[] = $tag;
		printf( '<%s class="%s">', $tag, esc_attr( $args['class'] ?? 'df-region' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function region_close(): void {
		if ( self::editing() ) {
			DF_Inhalt::region_close();
			return;
		}
		$tag = array_pop( self::$regions );
		if ( $tag ) {
			echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Saved value or null (no output).
	 */
	public static function get( string $id ): mixed {
		if ( self::editing() ) {
			return DF_Inhalt::get( $id );
		}
		if ( null === self::$values ) {
			$values       = get_option( self::VALUES_OPTION, array() );
			self::$values = is_array( $values ) ? $values : array();
		}
		return self::$values[ $id ] ?? null;
	}

	/**
	 * Saved value, or the given default when nothing (or an empty string) is saved.
	 */
	public static function value( string $id, mixed $default = null ): mixed {
		$saved = self::get( $id );
		return ( null === $saved ) ? $default : $saved;
	}

	/**
	 * Attachment ID of the per-site placeholder image (0 if missing).
	 */
	public static function placeholder_id(): int {
		return (int) get_option( 'df_placeholder_image_id', 0 );
	}

	/**
	 * Tags allowed in rich text (same set as DF Inhalt plus h2/h3).
	 */
	public static function richtext_tags(): array {
		$a = array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		);
		return array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h2'     => array(),
			'h3'     => array(),
			'a'      => $a,
		);
	}
}
