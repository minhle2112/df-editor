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

	/** @var array<int,bool> Draft preview decision per post. */
	private static array $draft_preview = array();

	/** @var bool Whether the current request renders a single-section preview. */
	private static bool $section_preview = false;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_section_preview' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'preview_headers' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'preview_robots' ) );
	}

	/* ------------------------------------------------------- Draft preview */

	/**
	 * Whether this request previews the draft of a post:
	 * ?df_preview=draft&_dfnonce=<wp_create_nonce('df_preview_'.$post_id)> and a user with
	 * df_edit_layout or df_reorder_sections.
	 */
	public static function is_draft_preview( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( isset( self::$draft_preview[ $post_id ] ) ) {
			return self::$draft_preview[ $post_id ];
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified here.
		$ok = isset( $_GET['df_preview'], $_GET['_dfnonce'] )
			&& 'draft' === $_GET['df_preview']
			&& is_user_logged_in()
			&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_dfnonce'] ) ), 'df_preview_' . $post_id )
			&& DF_Capabilities::can_reorder( $post_id );
		// phpcs:enable
		self::$draft_preview[ $post_id ] = $ok;
		return $ok;
	}

	/**
	 * Draft preview URL of a post (for the panel iframe: $in_panel adds df_panel_frame=1).
	 */
	public static function preview_url( int $post_id, bool $in_panel = true ): string {
		$args = array(
			'df_preview' => 'draft',
			'_dfnonce'   => wp_create_nonce( 'df_preview_' . $post_id ),
		);
		if ( $in_panel ) {
			$args['df_panel_frame'] = '1';
		}
		return add_query_arg( $args, (string) get_permalink( $post_id ) );
	}

	/**
	 * Whether the page is shown inside the DF Panel iframe (df_panel_frame=1).
	 */
	public static function in_panel_frame(): bool {
		return isset( $_GET['df_panel_frame'] ) && '1' === $_GET['df_panel_frame']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The layout to render for a post: the draft during a valid draft preview (when there is
	 * one), otherwise the live layout.
	 */
	public static function layout_for( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		if ( self::is_draft_preview( $post_id ) ) {
			$draft = DF_Layout_Store::get_draft( $post_id );
			if ( null !== $draft ) {
				return $draft;
			}
		}
		return DF_Layout_Store::get( $post_id );
	}

	/**
	 * Previews (draft, panel frame, single section) are never cached.
	 */
	public static function preview_headers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['df_preview'] ) || isset( $_GET['df_section_preview'] ) || self::in_panel_frame() ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Page cache plugins.
			}
		}
	}

	/**
	 * noindex for previews.
	 */
	public static function preview_robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['df_preview'] ) || isset( $_GET['df_section_preview'] ) || self::in_panel_frame() ) {
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

		if ( ! DF_Capabilities::can_edit_layout() && ! self::valid_preview_token( $token ) ) {
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
	 * Renders the layout of a page (current post when null). During a valid draft preview the
	 * draft is rendered instead of the live layout.
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

		printf(
			'<section id="%s" class="%s" data-df-section="%s">',
			esc_attr( $id ),
			esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ),
			esc_attr( $type )
		);
		if ( '' !== $norm['anchor'] && $norm['anchor'] !== $id ) {
			printf( '<span id="%s" class="df-anchor" aria-hidden="true"></span>', esc_attr( $norm['anchor'] ) );
		}
		echo '<div class="df-section__inner">';
		self::include_template( $template, $s );
		echo '</div></section>' . "\n";
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
