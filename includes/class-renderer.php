<?php
/**
 * Renders page layouts and single sections.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Renderer {

	/** Transient prefix of single-section preview tokens (`wp df preview-token`). */
	public const PREVIEW_TOKEN_PREFIX = 'df_preview_token_';

	/** Lifetime of a preview token. */
	public const PREVIEW_TOKEN_TTL = HOUR_IN_SECONDS;

	/** @var bool Whether the current request renders a single-section preview. */
	private static bool $section_preview = false;

	/** @var int Sections rendered on this request (the first one does not animate with "auto": LCP). */
	private static int $rendered = 0;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_section_preview' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'preview_headers' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'preview_robots' ) );
		add_filter( 'render_block', array( __CLASS__, 'removed_block' ), 10, 2 );
	}

	/**
	 * DF blocks (df/*) of the removed Gutenberg integration render nothing for visitors and the
	 * "Section not available" notice for editors, so pages built from them get rebuilt.
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 */
	public static function removed_block( $content, $block ) {
		$name = is_array( $block ) ? (string) ( $block['blockName'] ?? '' ) : '';
		if ( 0 !== strpos( $name, 'df/' ) || \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return $content;
		}
		if ( ! current_user_can( 'edit_pages' ) ) {
			return '';
		}
		DF_Assets::enqueue_base();
		$id = (string) ( $block['attrs']['dfId'] ?? '' );
		ob_start();
		self::missing( 'df-block ' . substr( $name, 3 ), $id );
		return (string) ob_get_clean();
	}

	/**
	 * The layout to render for a post (the live layout), or null.
	 */
	public static function layout_for( int $post_id ): ?array {
		return $post_id > 0 ? DF_Layout_Store::get( $post_id ) : null;
	}

	/**
	 * Single-section previews are never cached.
	 */
	public static function preview_headers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['df_section_preview'] ) ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Page cache plugins.
			}
		}
	}

	/**
	 * noindex for single-section previews.
	 */
	public static function preview_robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['df_section_preview'] ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/* ------------------------------------------------ Single-section preview */

	/**
	 * Creates a preview token for single-section previews (screenshots without a login).
	 */
	public static function create_preview_token(): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::PREVIEW_TOKEN_PREFIX . $token, 1, self::PREVIEW_TOKEN_TTL );
		return $token;
	}

	private static function valid_preview_token( string $token ): bool {
		return '' !== $token && preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) && false !== get_transient( self::PREVIEW_TOKEN_PREFIX . $token );
	}

	/**
	 * Whether the current request renders a single-section preview (true while rendering it).
	 */
	public static function is_section_preview(): bool {
		return self::$section_preview;
	}

	/**
	 * Front end ?df_section_preview=<type>&variant=<v>&scheme=<s>[&token=<t>]: a minimal page
	 * with only wp_head/wp_footer and that section with its defaults (no theme header/footer).
	 */
	public static function maybe_section_preview(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview, token or capability checked.
		if ( ! isset( $_GET['df_section_preview'] ) ) {
			return;
		}
		$type  = sanitize_key( wp_unslash( (string) $_GET['df_section_preview'] ) );
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
		$inst  = array(
			'id'      => $type . '-preview',
			'section' => $type,
			'variant' => isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( (string) $_GET['variant'] ) ) : '',
			'scheme'  => isset( $_GET['scheme'] ) ? sanitize_key( wp_unslash( (string) $_GET['scheme'] ) ) : '',
		);
		// phpcs:enable

		if ( ! current_user_can( 'edit_pages' ) && ! self::valid_preview_token( $token ) ) {
			nocache_headers();
			status_header( 403 );
			wp_die( esc_html__( 'You are not allowed to preview sections.', 'df-editor' ), '', array( 'response' => 403 ) );
		}
		$def = DF_Registry::get( $type );
		if ( null === $def ) {
			nocache_headers();
			status_header( 404 );
			wp_die( esc_html__( 'Unknown section.', 'df-editor' ), '', array( 'response' => 404 ) );
		}
		if ( '' === $inst['variant'] ) {
			unset( $inst['variant'] );
		}
		if ( '' === $inst['scheme'] ) {
			unset( $inst['scheme'] );
		}

		self::$section_preview = true;
		nocache_headers();
		// Plain output: no admin bar, no DF Inhalt editing markup, defaults only.
		add_filter( 'show_admin_bar', '__return_false', 1000 );
		add_filter( 'pre_option_df_inhalt_disabled', static fn() => '1' );
		add_filter( 'df_inhalt_can_edit', '__return_false', 1000 );
		add_action(
			'wp_enqueue_scripts',
			static function () use ( $type ): void {
				DF_Assets::enqueue_section( $type );
			},
			20
		);

		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
</head>
<body <?php body_class( array( 'df-section-preview', 'df-section-preview--' . $type ) ); ?>>
		<?php
		self::render_instance( $inst, 'df-preview' );
		wp_footer();
		?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Renders the layout of a page (current post when null).
	 */
	public static function render_layout( ?int $post_id = null ): void {
		$post_id = $post_id ?? (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return;
		}
		$layout = self::layout_for( $post_id );
		if ( null === $layout ) {
			return;
		}
		foreach ( $layout['sections'] as $inst ) {
			if ( is_array( $inst ) ) {
				self::render_instance( $inst, $layout['page_key'] );
			}
		}
	}

	/**
	 * Renders one section outside a stored layout (theme templates, header/footer).
	 *
	 * @param string $type        Section type.
	 * @param string $instance_id Stable instance ID (part of the field IDs, never change it).
	 * @param array  $options     Same keys as a layout entry (variant, scheme, spacing, width, anchor, options, content)
	 *                            plus optional "page_key" (default: current page key, or "global" outside pages).
	 */
	public static function section( string $type, string $instance_id, array $options = array() ): void {
		$page_key = (string) ( $options['page_key'] ?? '' );
		if ( '' === $page_key ) {
			$post_id  = is_singular() ? (int) get_queried_object_id() : 0;
			$page_key = $post_id ? DF_Layout_Store::read_key( $post_id ) : 'global';
		}
		unset( $options['page_key'] );
		$inst = array_merge( $options, array( 'id' => $instance_id, 'section' => $type ) );
		self::render_instance( $inst, $page_key );
	}

	/**
	 * Renders one layout entry inside the standard wrapper.
	 *
	 * @param array  $inst     Layout entry.
	 * @param string $page_key Page key.
	 */
	public static function render_instance( array $inst, string $page_key ): void {
		if ( ! empty( $inst['hidden'] ) ) {
			return;
		}
		$type = (string) ( $inst['section'] ?? '' );
		$def  = DF_Registry::get( $type );
		$id   = (string) ( $inst['id'] ?? '' );

		if ( null === $def || ! preg_match( DF_Layout_Store::INSTANCE_REGEX, $id ) ) {
			self::missing( $type, $id );
			return;
		}

		$template = self::locate_template( $type, $def );
		if ( '' === $template ) {
			self::missing( $type, $id );
			return;
		}

		DF_Assets::enqueue_section( $type ); // No-op when already enqueued in <head>.

		$s    = new DF_Section( $inst, $def, $page_key );
		$norm = $s->instance();

		$classes = array(
			'df-section',
			'df-' . $type,
			'df-' . $type . '--' . $norm['variant'],
			'df-scheme-' . $norm['scheme'],
			'df-pt-' . $norm['spacing']['top'],
			'df-pb-' . $norm['spacing']['bottom'],
			'df-w-' . $norm['width'],
		);

		/**
		 * Filters the wrapper classes of a section.
		 *
		 * @param string[]   $classes Classes.
		 * @param DF_Section $s       Section object.
		 */
		$classes = (array) apply_filters( 'df_editor_section_classes', $classes, $s );

		$anim = self::animation( $s, 0 === self::$rendered++ );
		printf(
			'<section id="%s" class="%s" data-df-section="%s"%s>',
			esc_attr( $id ),
			esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ),
			esc_attr( $type ),
			'' !== $anim ? ' data-df-anim="' . esc_attr( $anim ) . '"' : ''
		);
		if ( '' !== $norm['anchor'] && $norm['anchor'] !== $id ) {
			printf( '<span id="%s" class="df-anchor" aria-hidden="true"></span>', esc_attr( $norm['anchor'] ) );
		}
		echo '<div class="df-section__inner">';
		self::include_template( $template, $s );
		echo '</div></section>' . "\n";
	}

	/**
	 * Entrance animation for the wrapper (data-df-anim): "fade" / "fade-up", or "" when DF Design
	 * motion is off, the section has none, or this is a preview (single section, admin, REST).
	 * The first section of the page usually holds the LCP element: with "auto" it does not animate;
	 * an explicit "fade" / "fade-up" in the layout entry (agency decision) animates it anyway.
	 *
	 * @param DF_Section $s     Section.
	 * @param bool       $first First section rendered on this request.
	 */
	public static function animation( DF_Section $s, bool $first = false ): string {
		if ( ( $first && 'auto' === ( $s->instance()['animation'] ?? 'auto' ) ) || 'off' === DF_Design::motion() || self::$section_preview || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$anim = $s->effective_animation();
		return 'none' === $anim ? '' : $anim;
	}

	/**
	 * Template lookup: child-theme/df-sections/<type>.php → theme/df-sections/<type>.php → section folder.
	 */
	public static function locate_template( string $type, array $def ): string {
		$candidates = array(
			get_stylesheet_directory() . '/df-sections/' . $type . '.php',
			get_template_directory() . '/df-sections/' . $type . '.php',
			$def['dir'] . 'section.php',
		);
		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) {
				return (string) apply_filters( 'df_editor_section_template', $file, $type );
			}
		}
		return '';
	}

	/**
	 * Includes a template with only $s in scope.
	 */
	private static function include_template( string $template, DF_Section $s ): void {
		( static function ( string $__template, DF_Section $s ): void {
			include $__template;
		} )( $template, $s );
	}

	/**
	 * Missing section: nothing for visitors, a yellow notice for editors. Layout data stays untouched.
	 */
	private static function missing( string $type, string $id ): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		printf(
			'<div class="df-missing" role="note"><strong>%s</strong> %s</div>',
			esc_html__( 'Section not available', 'df-editor' ),
			esc_html( trim( $type . ' ' . ( $id ? '(' . $id . ')' : '' ) ) )
		);
	}
}
