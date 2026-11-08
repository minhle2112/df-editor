<?php
/**
 * View switcher: check the current page as Desktop, Tablet or Mobile.
 *
 * Buttons only in the DF Inhalt toolbar at the bottom of the page (also for clients in client
 * mode, who have no admin bar), so only for users who see that toolbar. Tablet / Mobile shrink
 * the page in place, like Elementor's responsive mode: viewport.js lays a stage over the page (below the admin bar and the toolbar)
 * holding an iframe of the same URL plus ?df_view=tablet|mobile, loaded invisibly at full width
 * and scrolled to the same position, fades it in and then animates its width (drag handles on
 * both sides, rotate button in the toolbar). That flag (only for users who may use the switcher)
 * hides the admin bar and the frame's own toolbar inside the frame and keeps DF Inhalt working
 * there; the top toolbar's edit toggle drives edit mode in the frame. Back to Desktop animates to
 * full width and shows the page again at the frame's scroll position (reloaded after a save in
 * the frame). Never for visitors, never in wp-admin, the Customizer preview or a section preview.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Viewport {

	/** Query flag of the in-frame request. */
	public const FLAG = 'df_view';

	/** Views that open a frame (desktop = the normal page). */
	public const FRAME_VIEWS = array( 'tablet', 'mobile' );

	public static function init(): void {
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ), 1001 );
		add_action( 'template_redirect', array( __CLASS__, 'frame_headers' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'df_inhalt_toolbar', array( __CLASS__, 'toolbar_buttons' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the current user may use the switcher on this front-end request: only where the
	 * DF Inhalt toolbar (which holds the buttons) is shown, so off when DF Inhalt is disabled.
	 */
	public static function allowed(): bool {
		if ( is_admin() || ! is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_customize_preview() || is_feed() || is_embed() || self::section_preview() ) {
			return false;
		}
		$allowed = class_exists( 'DF_Inhalt_Plugin' ) && DF_Inhalt_Plugin::can_edit();

		/**
		 * Filters whether the current user gets the Desktop / Tablet / Mobile view switcher.
		 *
		 * @param bool $allowed Whether the switcher is available.
		 */
		return (bool) apply_filters( 'df_editor_viewport_enabled', $allowed );
	}

	/**
	 * The view requested for the frame ("tablet" / "mobile"), or '' when this is no in-frame
	 * request or the user may not use the switcher (the flag does nothing for visitors).
	 */
	public static function frame_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation flag.
		$view = isset( $_GET[ self::FLAG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FLAG ] ) ) : '';
		if ( ! in_array( $view, self::FRAME_VIEWS, true ) || ! self::allowed() ) {
			return '';
		}
		return $view;
	}

	private static function section_preview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		return isset( $_GET['df_section_preview'] ) || DF_Renderer::is_section_preview();
	}

	/** No admin bar inside the frame. */
	public static function show_admin_bar( $show ) {
		return $show && '' !== self::frame_view() ? false : $show;
	}

	/** The in-frame page is personal: never cached. */
	public static function frame_headers(): void {
		if ( '' !== self::frame_view() ) {
			nocache_headers();
		}
	}

	/**
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( '' !== self::frame_view() ) {
			$classes[] = 'df-view-frame';
		}
		return $classes;
	}

	public static function enqueue(): void {
		if ( ! self::allowed() ) {
			return;
		}
		$frame = self::frame_view();
		$css   = DF_EDITOR_DIR . 'assets/css/viewport.css';
		$js    = DF_EDITOR_DIR . 'assets/js/viewport.js';
		wp_enqueue_style( 'df-viewport', DF_EDITOR_URL . 'assets/css/viewport.css', array(), self::ver( $css ) );
		wp_enqueue_script(
			'df-viewport',
			DF_EDITOR_URL . 'assets/js/viewport.js',
			array(),
			self::ver( $js ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$config = array(
			'frame'  => $frame,
			'labels' => array(
				'frame'  => __( 'Device preview', 'df-editor' ),
				/* translators: %s: preview width in pixels. */
				'width'  => __( '%s px', 'df-editor' ),
				'resize' => __( 'Resize preview', 'df-editor' ),
				'hint'   => __( 'Drag to resize, double-click to reset', 'df-editor' ),
			),
		);
		wp_add_inline_script( 'df-viewport', 'window.dfView = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** View buttons in the DF Inhalt toolbar (not inside the frame). */
	public static function toolbar_buttons(): void {
		if ( ! self::allowed() || '' !== self::frame_view() ) {
			return;
		}
		echo self::buttons( 'df-view-buttons--toolbar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in buttons().
	}

	/** The view buttons (Desktop / Tablet / Mobile) and the rotate button; viewport.js wires them up. */
	private static function buttons( string $modifier ): string {
		$views = array(
			'desktop' => __( 'Desktop', 'df-editor' ),
			'tablet'  => __( 'Tablet', 'df-editor' ),
			'mobile'  => __( 'Mobile', 'df-editor' ),
		);
		$html  = '<span class="df-view-buttons ' . esc_attr( $modifier ) . '" role="group" aria-label="' . esc_attr__( 'View', 'df-editor' ) . '">';
		foreach ( $views as $view => $label ) {
			$html .= sprintf(
				'<button type="button" class="df-view-btn" data-df-view="%1$s" aria-pressed="%2$s" aria-label="%3$s" title="%3$s">%4$s</button>',
				esc_attr( $view ),
				'desktop' === $view ? 'true' : 'false',
				esc_attr( $label ),
				self::icon( $view )
			);
		}
		// Portrait / landscape, only shown in Tablet and Mobile (viewport.js).
		$html .= sprintf(
			'<button type="button" class="df-view-btn df-view-rotate" data-df-rotate aria-pressed="false" aria-label="%1$s" title="%1$s" hidden>%2$s</button>',
			esc_attr__( 'Rotate', 'df-editor' ),
			self::icon( 'rotate' )
		);
		return $html . '</span>';
	}

	/** Small inline SVG icon (stroke = currentColor). */
	private static function icon( string $name ): string {
		$paths = array(
			'desktop' => '<path d="M4 4h16a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 20 16H4a1.5 1.5 0 0 1-1.5-1.5v-9A1.5 1.5 0 0 1 4 4zM8 20h8M12 16v4"/>',
			'tablet'  => '<path d="M7 2.5h10a2 2 0 0 1 2 2v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-15a2 2 0 0 1 2-2zM11 18.5h2"/>',
			'mobile'  => '<path d="M9 2.5h6a2 2 0 0 1 2 2v15a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-15a2 2 0 0 1 2-2zM11 18.5h2"/>',
			'rotate'  => '<path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5M20 4v4.5h-4.5M20 12a8 8 0 0 1-13.7 5.6L4 15.5M4 20v-4.5h4.5"/>',
		);
		return '<svg class="df-view-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	private static function ver( string $file ): string {
		return DF_EDITOR_VERSION . '.' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
