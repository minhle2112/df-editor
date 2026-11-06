<?php
/**
 * Loads base.css on DF pages and only the CSS/JS of sections used on the current page,
 * plus DF Motion (entrance animations) when DF Design motion is on.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Assets {

	/** Default easing of the entrance animations. */
	public const MOTION_EASING = 'cubic-bezier(.2,.7,.2,1)';

	/** @var array<string,bool> Section types enqueued on this request (DF Motion builds its CSS from them). */
	private static array $types = array();

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// After wp_enqueue_scripts (wp_head priority 1), before the styles print (priority 8).
		add_action( 'wp_head', array( __CLASS__, 'motion_head' ), 2 );
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
		self::$types[ $type ] = true;
		$handle               = 'df-section-' . $type;
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

	/* ------------------------------------------------------------ DF Motion */

	/**
	 * DF Motion in <head> (front end, motion on, at least one DF section type on the page):
	 * a tiny script that puts "df-motion" on <html> (not with reduced motion; removed again after
	 * 3 s when motion.js never ran), the tokens and the hidden start state of the items of the
	 * section types on this page, plus motion.css (inlined) and motion.js (deferred, footer).
	 * Only wrappers with data-df-anim (DF_Renderer::animation()) are affected.
	 */
	public static function motion_head(): void {
		$level = DF_Design::motion();
		if ( 'off' === $level || ! self::$types || is_admin() || DF_Renderer::is_section_preview() ) {
			return;
		}
		[ $distance, $duration, $stagger ] = DF_Design::MOTION_LEVELS[ $level ];

		$config = array();
		$hide   = '';
		foreach ( array_keys( self::$types ) as $type ) {
			$def = DF_Registry::get( (string) $type );
			if ( null === $def ) {
				continue;
			}
			[ $items, $media ] = self::motion_selectors( $def );
			$config[ $type ]   = array( $items, $media );

			// :not(:focus-within): a keyboard user who tabs into a hidden section sees it at once.
			$scope = 'html.df-motion:not(.df-edit-active) .df-' . $type;
			$hide .= $scope . '[data-df-anim]:not(.is-in):not(:focus-within) :where(' . implode( ',', array_merge( $items, $media ) ) . '){opacity:0}';
			if ( $items ) {
				$hide .= $scope . '[data-df-anim="fade-up"]:not(.is-in):not(:focus-within) :where(' . implode( ',', $items ) . '){transform:translate3d(0,var(--df-anim-distance),0)}';
			}
		}
		if ( ! $config ) {
			return;
		}

		$tokens = sprintf( ':root{--df-anim-distance:%s;--df-anim-duration:%s;--df-anim-stagger:%s;--df-anim-easing:%s}', $distance, $duration, $stagger, self::MOTION_EASING );
		echo '<script id="df-motion-init">(function(d){var h=d.documentElement;if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;h.classList.add("df-motion");setTimeout(function(){if(!window.dfMotionReady)h.classList.remove("df-motion")},3000)})(document);</script>' . "\n";
		echo '<style id="df-motion-inline-css">' . $tokens . '@media screen and (prefers-reduced-motion:no-preference){' . $hide . '}' . self::motion_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selectors filtered in motion_selectors(), motion.css is a plugin file.

		$js = DF_EDITOR_DIR . 'assets/js/motion.js';
		wp_enqueue_script(
			'df-motion',
			DF_EDITOR_URL . 'assets/js/motion.js',
			array(),
			self::ver( $js ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( 'df-motion', 'window.dfMotion = ' . wp_json_encode( array( 'sections' => $config ) ) . ';', 'before' );
	}

	/**
	 * Contents of assets/css/motion.css (transitions only, < 1 KB), minified for the inline <style>:
	 * inlined instead of enqueued, so there is no extra render-blocking request in <head>.
	 */
	private static function motion_css(): string {
		$css = (string) @file_get_contents( DF_EDITOR_DIR . 'assets/css/motion.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) preg_replace( array( '#/\*.*?\*/#s', '#\s+#' ), array( '', ' ' ), $css );
		return trim( str_replace( '<', '', $css ) );
	}

	/**
	 * Item and media selectors of a section type (section.json "motion"), filtered for safe use
	 * inside <style>. Without any, the whole section content fades in.
	 *
	 * @return array{0:string[],1:string[]} [ items (fade / fade-up, staggered), media (fade only) ]
	 */
	public static function motion_selectors( array $def ): array {
		$out = array();
		foreach ( array( 'items', 'media' ) as $key ) {
			$list = array();
			foreach ( (array) ( $def['motion'][ $key ] ?? array() ) as $selector ) {
				$selector = is_string( $selector ) ? trim( $selector ) : '';
				// No characters that could end the rule or the <style> element ("<" incl. "</style").
				if ( '' !== $selector && ! preg_match( '#[<{};@\\\\]|/\*#', $selector ) ) {
					$list[] = $selector;
				}
			}
			$out[] = $list;
		}
		if ( ! $out[0] && ! $out[1] ) {
			$out[0] = array( '.df-section__inner' );
		}
		return $out;
	}

	private static function ver( string $file ): string {
		return DF_EDITOR_VERSION . '.' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
