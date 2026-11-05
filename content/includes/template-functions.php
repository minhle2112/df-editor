<?php
/**
 * Internal DF Inhalt render helpers. Themes and the DF core call the DF_Inhalt facade instead.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return edit-mode attributes for a registered field, or an empty string for visitors.
 *
 * @internal
 *
 * @param array $field Registered field from DF_Inhalt_Fields::register().
 * @return string Escaped attribute string with a leading space.
 */
function df_inhalt_internal_edit_attributes( $field ) {
	if ( '' === $field['id'] || ! DF_Inhalt_Plugin::can_edit() ) {
		return '';
	}

	$attrs = array(
		'data-df-field' => $field['id'],
		'data-df-label' => $field['schema']['label'],
		'data-df-type'  => $field['schema']['type'],
		'tabindex'       => '-1',
	);

	if ( ! empty( $field['schema']['max_length'] ) ) {
		$attrs['data-df-max-length'] = (string) absint( $field['schema']['max_length'] );
	}

	$html = '';

	foreach ( $attrs as $name => $value ) {
		$html .= sprintf( ' %s="%s"', $name, esc_attr( $value ) );
	}

	return $html;
}

/**
 * Return sanitized theme classes from a helper's `class` argument.
 *
 * @internal
 *
 * @param array    $args          Helper arguments.
 * @param string[] $extra_classes Classes to add.
 * @return string
 */
function df_inhalt_internal_class_list( $args, $extra_classes = array() ) {
	$classes = isset( $args['class'] ) ? preg_split( '/\s+/', (string) $args['class'] ) : array();
	$classes = array_merge( $extra_classes, array_map( 'sanitize_html_class', $classes ) );

	return implode( ' ', array_unique( array_filter( $classes ) ) );
}

/**
 * Return the inline image-slot style declared by a theme.
 *
 * @internal
 *
 * @param array $args Image settings.
 * @return string
 */
function df_inhalt_internal_image_style( $args ) {
	$rules = array();

	if (
		! empty( $args['aspect_ratio'] )
		&& preg_match( '#^\s*(\d+(?:\.\d+)?)\s*(?:[/:]\s*(\d+(?:\.\d+)?))?\s*$#', (string) $args['aspect_ratio'], $matches )
	) {
		$rules[] = 'aspect-ratio:' . $matches[1] . ( ! empty( $matches[2] ) ? ' / ' . $matches[2] : '' );
	}

	$fits = array( 'cover', 'contain', 'fill', 'none', 'scale-down' );

	if ( isset( $args['object_fit'] ) && in_array( $args['object_fit'], $fits, true ) ) {
		$rules[] = 'object-fit:' . $args['object_fit'];
	} elseif ( $rules ) {
		$rules[] = 'object-fit:cover';
	}

	if ( ! empty( $args['object_position'] ) && preg_match( '/^[a-z0-9.%\s-]+$/i', (string) $args['object_position'] ) ) {
		$rules[] = 'object-position:' . trim( $args['object_position'] );
	}

	return implode( ';', $rules );
}

if ( ! function_exists( 'df_inhalt_text' ) ) {
	/**
	 * Render a DF Inhalt-owned single-line text field.
	 *
	 * @param string $id   Stable field ID.
	 * @param array  $args Field settings: label, default, max_length, scope.
	 * @return void
	 */
	function df_inhalt_text( $id, $args = array() ) {
		$field = DF_Inhalt_Fields::register( 'text', $id, $args );
		$attrs = df_inhalt_internal_edit_attributes( $field );

		if ( '' === $attrs ) {
			echo esc_html( $field['value'] );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are escaped by df_inhalt_internal_edit_attributes().
		printf( '<span class="df-inhalt-field"%s>%s</span>', $attrs, esc_html( $field['value'] ) );
	}
}

if ( ! function_exists( 'df_inhalt_richtext' ) ) {
	/**
	 * Render a DF Inhalt-owned rich text field with bold, italic, links, and lists.
	 *
	 * Always rendered inside `<div class="df-inhalt-richtext">` so layout is the same
	 * for visitors and editors.
	 *
	 * @param string $id   Stable field ID.
	 * @param array  $args Field settings: label, default, max_length, class, scope.
	 * @return void
	 */
	function df_inhalt_richtext( $id, $args = array() ) {
		$field   = DF_Inhalt_Fields::register( 'richtext', $id, $args );
		$classes = df_inhalt_internal_class_list( $args, array( 'df-inhalt-richtext' ) );
		$attrs   = df_inhalt_internal_edit_attributes( $field );

		if ( '' !== $attrs ) {
			$classes .= ' df-inhalt-field';
		}

		printf(
			'<div class="%s"%s>%s</div>',
			esc_attr( $classes ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by df_inhalt_internal_edit_attributes().
			wp_kses( $field['value'], DF_Inhalt_Fields::richtext_tags() )
		);
	}
}

if ( ! function_exists( 'df_inhalt_button' ) ) {
	/**
	 * Render a DF Inhalt-owned link button with editable text and URL.
	 *
	 * `before` and `after` hold theme-controlled markup inside the link, such as
	 * a decorative icon. The editable text is then wrapped in
	 * `<span class="df-inhalt-button__text">`.
	 *
	 * @param string $id   Stable field ID.
	 * @param array  $args Field settings: label, default (array with text and url),
	 *                     max_length, class, target, before, after, scope.
	 * @return void
	 */
	function df_inhalt_button( $id, $args = array() ) {
		$field = DF_Inhalt_Fields::register( 'button', $id, $args );
		$value = $field['value'];
		$attrs = df_inhalt_internal_edit_attributes( $field );

		if ( '' === $value['text'] && '' === $attrs ) {
			return;
		}

		$classes = df_inhalt_internal_class_list( $args, '' !== $attrs ? array( 'df-inhalt-field' ) : array() );
		$target  = isset( $args['target'] ) && '_blank' === $args['target'] ? ' target="_blank" rel="noopener"' : '';
		$before  = isset( $args['before'] ) ? wp_kses_post( $args['before'] ) : '';
		$after   = isset( $args['after'] ) ? wp_kses_post( $args['after'] ) : '';
		$text    = esc_html( $value['text'] );

		if ( '' !== $before || '' !== $after ) {
			$text = '<span class="df-inhalt-button__text">' . $text . '</span>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above or by df_inhalt_internal_edit_attributes().
		printf(
			'<a%s href="%s"%s%s>%s%s%s</a>',
			$classes ? ' class="' . esc_attr( $classes ) . '"' : '',
			esc_url( '' !== $value['url'] ? $value['url'] : '#' ),
			$target,
			$attrs,
			$before,
			$text,
			$after
		);
		// phpcs:enable
	}
}

if ( ! function_exists( 'df_inhalt_image' ) ) {
	/**
	 * Render a Media Library-backed image field.
	 *
	 * @param string $id   Stable field ID.
	 * @param array  $args Image settings: label, default (attachment ID), size, class,
	 *                     loading, fetchpriority, aspect_ratio, object_fit,
	 *                     object_position, scope.
	 * @return void
	 */
	function df_inhalt_image( $id, $args = array() ) {
		$field         = DF_Inhalt_Fields::register( 'image', $id, $args );
		$attachment_id = $field['value'];

		// A saved image that was deleted from the Media Library falls back to the
		// theme default, so the slot stays visible and can be replaced again.
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = isset( $field['schema']['default'] ) ? absint( $field['schema']['default'] ) : 0;
		}

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$attrs = array(
			'class' => df_inhalt_internal_class_list( $args ),
		);
		$style = df_inhalt_internal_image_style( $args );

		if ( $style ) {
			$attrs['style'] = $style;
		}

		if ( isset( $args['loading'] ) ) {
			$attrs['loading'] = sanitize_key( $args['loading'] );
		}

		if ( isset( $args['fetchpriority'] ) ) {
			$attrs['fetchpriority'] = sanitize_key( $args['fetchpriority'] );
		}

		if ( '' !== $field['id'] && DF_Inhalt_Plugin::can_edit() ) {
			$attrs['class']                  = trim( $attrs['class'] . ' df-inhalt-field df-inhalt-field--image' );
			$attrs['data-df-field']         = $field['id'];
			$attrs['data-df-label']         = $field['schema']['label'];
			$attrs['data-df-type']          = 'image';
			$attrs['data-df-attachment-id'] = (string) $attachment_id;
			$attrs['tabindex']               = '-1';
		}

		echo wp_get_attachment_image( $attachment_id, $field['schema']['size'], false, $attrs );
	}
}

if ( ! function_exists( 'df_inhalt_region_open' ) ) {
	/**
	 * Open a read-only region owned by ACF or another content provider.
	 *
	 * @param array $args Managed-region settings.
	 * @return void
	 */
	function df_inhalt_region_open( $args = array() ) {
		$provider = isset( $args['provider'] ) ? sanitize_key( $args['provider'] ) : 'provider';
		$names    = array(
			'acf'         => 'ACF',
			'jetengine'   => 'JetEngine',
			'woocommerce' => 'WooCommerce',
			'wordpress'   => 'WordPress',
		);
		$name     = isset( $names[ $provider ] ) ? $names[ $provider ] : ucfirst( $provider );
		$label    = isset( $args['label'] ) && $args['label']
			? sanitize_text_field( $args['label'] )
			: sprintf(
				/* translators: %s: content provider name. */
				__( 'Editable in %s', 'df-editor' ),
				$name
			);
		$action_label = isset( $args['action_label'] ) && $args['action_label']
			? sanitize_text_field( $args['action_label'] )
			: __( 'Edit', 'df-editor' );
		$classes  = array( 'df-inhalt-managed-region' );

		if ( ! empty( $args['class'] ) ) {
			$extra_classes = preg_split( '/\s+/', (string) $args['class'] );
			$classes       = array_merge( $classes, array_map( 'sanitize_html_class', $extra_classes ) );
		}

		$classes = array_filter( array_unique( $classes ) );
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			<?php if ( DF_Inhalt_Plugin::can_edit() ) : ?>
				data-df-managed="<?php echo esc_attr( $provider ); ?>"
				data-df-managed-label="<?php echo esc_attr( $label ); ?>"
				data-df-managed-action-label="<?php echo esc_attr( $action_label ); ?>"
				<?php if ( ! empty( $args['edit_url'] ) ) : ?>
					data-df-managed-edit-url="<?php echo esc_url( $args['edit_url'] ); ?>"
				<?php endif; ?>
			<?php endif; ?>
		>
		<?php
	}
}

if ( ! function_exists( 'df_inhalt_region_close' ) ) {
	/**
	 * Close a read-only provider region.
	 *
	 * @return void
	 */
	function df_inhalt_region_close() {
		echo '</div>';
	}
}
