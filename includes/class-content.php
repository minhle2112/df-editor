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

	/* ------------------------------------------------- Block content values */

	/**
	 * Prints a value stored in a DF block (block-content mode: no field ID, no editing markup).
	 *
	 * Markup and escaping mirror what a visitor gets for the same value on a JSON layout page:
	 * the DF Inhalt visitor output while DF Inhalt is active, else the fallback output of this
	 * class. Text esc_html, rich text wp_kses, URLs esc_url.
	 *
	 * @param string $type  text|richtext|button|image.
	 * @param mixed  $value Text/HTML string, button array {text,url,target?} or attachment ID.
	 * @param array  $args  Same args as the field helpers (class, size, before, after, alt, ...).
	 */
	public static function render_value( string $type, mixed $value, array $args = array() ): void {
		$inhalt = self::editing() && class_exists( 'DF_Inhalt_Fields' ) && function_exists( 'df_inhalt_internal_class_list' );

		switch ( $type ) {
			case 'richtext':
				if ( ! $inhalt ) {
					self::plain_richtext( $value, $args );
					return;
				}
				// Same as df_inhalt_richtext() for visitors (DF Inhalt sanitises defaults like this).
				printf(
					'<div class="%s">%s</div>',
					esc_attr( df_inhalt_internal_class_list( $args, array( 'df-inhalt-richtext' ) ) ),
					wp_kses( DF_Inhalt_Fields::sanitize_richtext( is_scalar( $value ) ? (string) $value : '' ), DF_Inhalt_Fields::richtext_tags() )
				);
				return;

			case 'button':
				$value = is_array( $value ) ? $value : array();
				if ( ! $inhalt ) {
					self::plain_button( $value, $args );
					return;
				}
				$text = sanitize_text_field( (string) ( $value['text'] ?? '' ) );
				$url  = esc_url_raw( (string) ( $value['url'] ?? '' ) );
				if ( '' === $text ) {
					return;
				}
				$classes = df_inhalt_internal_class_list( $args );
				$blank   = '_blank' === ( $args['target'] ?? '' ) || '_blank' === ( $value['target'] ?? '' );
				$before  = isset( $args['before'] ) ? wp_kses_post( $args['before'] ) : '';
				$after   = isset( $args['after'] ) ? wp_kses_post( $args['after'] ) : '';
				$label   = esc_html( $text );
				if ( '' !== $before || '' !== $after ) {
					$label = '<span class="df-inhalt-button__text">' . $label . '</span>';
				}
				// Same markup as df_inhalt_button() for visitors.
				printf(
					'<a%s href="%s"%s>%s%s%s</a>',
					$classes ? ' class="' . esc_attr( $classes ) . '"' : '',
					esc_url( '' !== $url ? $url : '#' ),
					$blank ? ' target="_blank" rel="noopener"' : '',
					$before, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above.
					$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html above.
					$after // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above.
				);
				return;

			case 'image':
				$attachment_id = absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value );
				if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
					$attachment_id = absint( $args['default'] ?? 0 );
				}
				$args = self::image_classes( $attachment_id, $args );
				if ( ! $inhalt ) {
					self::plain_image( $attachment_id, $args );
					return;
				}
				if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
					return;
				}
				// Same attributes as df_inhalt_image() for visitors.
				$attr  = array( 'class' => df_inhalt_internal_class_list( $args ) );
				$style = df_inhalt_internal_image_style( $args );
				if ( $style ) {
					$attr['style'] = $style;
				}
				if ( isset( $args['loading'] ) ) {
					$attr['loading'] = sanitize_key( $args['loading'] );
				}
				if ( isset( $args['fetchpriority'] ) ) {
					$attr['fetchpriority'] = sanitize_key( $args['fetchpriority'] );
				}
				if ( isset( $args['alt'] ) && '' !== trim( (string) $args['alt'] ) ) {
					$attr['alt'] = trim( (string) $args['alt'] );
				}
				echo wp_get_attachment_image( $attachment_id, isset( $args['size'] ) ? sanitize_key( $args['size'] ) : 'full', false, $attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return;

			default:
				self::plain_text( $inhalt ? sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) : $value );
		}
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
	 * Removes the saved DF Inhalt values of whole section instances (after `wp df to-layout`
	 * moved block content into layout content, which stale saved values would override).
	 *
	 * @param string[] $prefixes Field ID prefixes incl. trailing dot ("home.hero-k3x9.").
	 * @return array<string,mixed> The removed values (field ID => value), for a backup.
	 */
	public static function forget_values( array $prefixes ): array {
		$prefixes = array_values( array_filter( array_map( 'strval', $prefixes ) ) );
		$values   = get_option( self::VALUES_OPTION, array() );
		if ( ! $prefixes || ! is_array( $values ) || ! $values ) {
			return array();
		}
		$removed = array();
		foreach ( array_keys( $values ) as $id ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( (string) $id, $prefix ) ) {
					$removed[ $id ] = $values[ $id ];
					unset( $values[ $id ] );
					break;
				}
			}
		}
		if ( $removed ) {
			update_option( self::VALUES_OPTION, $values );
			self::$values = null;
		}
		return $removed;
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
