<?php
/**
 * Loads base.css on DF pages and only the CSS/JS of sections used on the current page.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Assets {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueues everything the current page needs, in <head>.
	 */
	public static function enqueue(): void {
		if ( ! is_singular() || DF_Renderer::is_section_preview() ) {
			return;
		}
		$layout = DF_Renderer::layout_for( (int) get_queried_object_id() );
		$types  = array();
		foreach ( (array) ( $layout['sections'] ?? array() ) as $inst ) {
			if ( is_array( $inst ) && empty( $inst['hidden'] ) && ! empty( $inst['section'] ) ) {
				$types[ (string) $inst['section'] ] = true;
			}
		}

		/**
		 * Filters the section types whose assets are loaded on this page
		 * (add types rendered by the theme via df_section(), e.g. in the footer).
		 *
		 * @param string[]   $types  Section types.
		 * @param array|null $layout Layout of the current page.
		 */
		$types = (array) apply_filters( 'df_editor_page_section_types', array_keys( $types ), $layout );

		if ( null === $layout && ! $types ) {
			return;
		}

		self::enqueue_base();
		foreach ( $types as $type ) {
			self::enqueue_section( (string) $type );
		}
	}

	public static function enqueue_base(): void {
		if ( wp_style_is( 'df-editor-base', 'enqueued' ) ) {
			return;
		}
		$file = DF_EDITOR_DIR . 'assets/css/base.css';
		wp_enqueue_style( 'df-editor-base', DF_EDITOR_URL . 'assets/css/base.css', array(), self::ver( $file ) );
		wp_style_add_data( 'df-editor-base', 'path', $file );
	}

	/**
	 * Enqueues style.css / view.js of one section type. Called late (during rendering)
	 * it still works: styles then print in the footer.
	 */
	public static function enqueue_section( string $type ): void {
		$handle = 'df-section-' . $type;
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			return;
		}
		$def = DF_Registry::get( $type );
		if ( null === $def ) {
			return;
		}
		self::enqueue_base();

		$css = $def['dir'] . 'style.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style( $handle, $def['url'] . 'style.css', array( 'df-editor-base' ), self::ver( $css ) );
			wp_style_add_data( $handle, 'path', $css );
		}

		// "script": "on-demand" in section.json: section.php calls enqueue_section_script() itself
		// when the instance needs the JS (e.g. slider / lightbox).
		if ( 'on-demand' !== ( $def['script'] ?? '' ) ) {
			self::enqueue_section_script( $type );
		}
	}

	/**
	 * Enqueues view.js of one section type (deferred; late calls print it in the footer).
	 */
	public static function enqueue_section_script( string $type ): void {
		$handle = 'df-section-' . $type;
		$def    = DF_Registry::get( $type );
		if ( null === $def || wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}
		$js = $def['dir'] . 'view.js';
		if ( is_readable( $js ) ) {
			wp_enqueue_script(
				$handle,
				$def['url'] . 'view.js',
				array(),
				self::ver( $js ),
				array(
					'strategy'  => 'defer',
					'in_footer' => false,
				)
			);
		}
	}

	private static function ver( string $file ): string {
		return DF_EDITOR_VERSION . '.' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
