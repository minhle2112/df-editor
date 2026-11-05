<?php
/**
 * DF Panel: full-screen layout editor (admin.php?page=df-panel&post=<id>).
 *
 * This file only registers the screen, renders the app shell, enqueues assets/panel/*,
 * and adds the entry points (admin bar node, page row action, floating link for users
 * without an admin bar). All data goes through the REST API (namespace df/v1).
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Panel {

	public const SLUG = 'df-panel';

	/** Capabilities that open the panel (full editor / reorder-only). */
	public const CAP_EDIT    = 'df_edit_layout';
	public const CAP_REORDER = 'df_reorder_sections';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		// Before DF Inhalt's client guard (admin_init, 20) so reorder-only clients can open the panel.
		add_action( 'admin_init', array( __CLASS__, 'maybe_render' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_in_frame' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'floating_link' ), 50 );
	}

	/* ------------------------------------------------------------------ *
	 * Permissions / helpers
	 * ------------------------------------------------------------------ */

	public static function can_open(): bool {
		return is_user_logged_in() && ( current_user_can( self::CAP_EDIT ) || current_user_can( self::CAP_REORDER ) );
	}

	public static function url( int $post_id = 0 ): string {
		$args = array( 'page' => self::SLUG );
		if ( $post_id ) {
			$args['post'] = $post_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function has_layout( int $post_id ): bool {
		return $post_id > 0 && class_exists( 'DF_Layout_Store' ) && DF_Layout_Store::has_layout( $post_id );
	}

	/** True on a front-end request rendered inside the panel iframe. */
	public static function in_frame(): bool {
		return ! is_admin() && isset( $_GET['df_panel_frame'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ------------------------------------------------------------------ *
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Hidden submenu page (no menu entry). The real output happens in maybe_render().
	 */
	public static function register_page(): void {
		$cap = current_user_can( self::CAP_EDIT ) ? self::CAP_EDIT : self::CAP_REORDER;
		add_submenu_page( '', __( 'DF Panel', 'df-editor' ), __( 'DF Panel', 'df-editor' ), $cap, self::SLUG, array( __CLASS__, 'render_fallback' ) );
	}

	public static function render_fallback(): void {
		echo '<div class="wrap"><p>' . esc_html__( 'Loading DF Panel…', 'df-editor' ) . '</p></div>';
	}

	/**
	 * Renders the full-screen app without the admin chrome and exits.
	 */
	public static function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( wp_doing_ajax() || ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! self::can_open() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit page layouts.', 'df-editor' ), 403 );
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $post_id && ! get_post( $post_id ) ) {
			$post_id = 0;
		}

		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		self::enqueue( $post_id );
		self::render( $post_id );
		exit;
	}

	private static function asset_ver( string $rel ): string {
		$file = DF_EDITOR_DIR . $rel;
		return file_exists( $file ) ? (string) filemtime( $file ) : DF_EDITOR_VERSION;
	}

	/**
	 * Scheme swatch colours from the effective DF Design palette (fallbacks match base.css).
	 */
	private static function scheme_colors(): array {
		$p = array(
			'base'       => '#ffffff',
			'base-alt'   => '#f3f4f6',
			'contrast'   => '#111111',
			'primary'    => '#0b5cad',
			'accent'     => '#f2a900',
			'on-primary' => '#ffffff',
			'on-accent'  => '#111111',
		);
		if ( class_exists( 'DF_Design' ) && method_exists( 'DF_Design', 'palette' ) ) {
			$p = array_merge( $p, array_filter( (array) DF_Design::palette(), 'is_string' ) );
		}
		return array(
			'hell'     => array( 'bg' => $p['base'], 'fg' => $p['contrast'] ),
			'hell-alt' => array( 'bg' => $p['base-alt'], 'fg' => $p['contrast'] ),
			'dunkel'   => array( 'bg' => $p['contrast'], 'fg' => $p['base'] ),
			'primaer'  => array( 'bg' => $p['primary'], 'fg' => $p['on-primary'] ),
			'akzent'   => array( 'bg' => $p['accent'], 'fg' => $p['on-accent'] ),
		);
	}

	private static function enqueue( int $post_id ): void {
		wp_register_style( 'df-panel', DF_EDITOR_URL . 'assets/panel/panel.css', array(), self::asset_ver( 'assets/panel/panel.css' ) );
		wp_register_script( 'df-panel', DF_EDITOR_URL . 'assets/panel/panel.js', array(), self::asset_ver( 'assets/panel/panel.js' ), true );

		$user = wp_get_current_user();
		wp_localize_script(
			'df-panel',
			'dfPanelConfig',
			array(
				'restBase'     => esc_url_raw( rest_url( 'df/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'nonceUrl'     => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
				'postId'       => $post_id,
				'panelUrl'     => esc_url_raw( self::url() ),
				'pagesUrl'     => esc_url_raw( admin_url( 'edit.php?post_type=page' ) ),
				'adminUrl'     => esc_url_raw( admin_url() ),
				'homeUrl'      => esc_url_raw( home_url( '/' ) ),
				'customizeUrl' => esc_url_raw( admin_url( 'customize.php' ) ),
				'userId'       => (int) $user->ID,
				'userName'     => $user->display_name,
				'caps'         => array(
					'editLayout' => current_user_can( self::CAP_EDIT ),
					'reorder'    => current_user_can( self::CAP_REORDER ),
					'customize'  => current_user_can( 'customize' ),
					'wpAdmin'    => current_user_can( 'edit_pages' ),
				),
				'schemeColors' => self::scheme_colors(),
				'lockInterval' => 60,
				'saveDelay'    => 400,
			)
		);
		wp_enqueue_style( 'df-panel' );
		wp_enqueue_script( 'df-panel' );
	}

	private static function render( int $post_id ): void {
		$title = $post_id ? get_the_title( $post_id ) : '';
		?><!doctype html>
<html <?php language_attributes(); ?> class="df-panel-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( '' !== $title ? sprintf( /* translators: %s: page title */ __( 'DF Panel: %s', 'df-editor' ), $title ) : __( 'DF Panel', 'df-editor' ) ); ?></title>
		<?php wp_print_styles( array( 'df-panel' ) ); ?>
</head>
<body class="df-panel-body">
<div id="df-panel" class="df-panel" data-state="loading">
	<noscript><p class="df-panel__noscript"><?php esc_html_e( 'DF Panel needs JavaScript.', 'df-editor' ); ?></p></noscript>
	<p class="df-panel__boot" role="status"><?php esc_html_e( 'Loading DF Panel…', 'df-editor' ); ?></p>
</div>
		<?php
		self::print_i18n();
		wp_print_scripts( array( 'df-panel' ) );
		?>
</body>
</html>
		<?php
	}

	/**
	 * UI strings (English source, text domain df-editor) as window.dfPanelI18n.
	 */
	private static function print_i18n(): void {
		$s = array(
			'appName'          => __( 'DF Panel', 'df-editor' ),
			'exit'             => __( 'Exit', 'df-editor' ),
			'exitLabel'        => __( 'Exit DF Panel and view the page', 'df-editor' ),
			'page'             => __( 'Page', 'df-editor' ),
			'choosePage'       => __( 'Choose a page', 'df-editor' ),
			'noPages'          => __( 'No pages with a DF layout yet.', 'df-editor' ),
			'draftMark'        => __( '(draft)', 'df-editor' ),
			'tabInhalt'        => __( 'Content', 'df-editor' ),
			'tabLayout'        => __( 'Layout', 'df-editor' ),
			'tabDesign'        => __( 'Design', 'df-editor' ),
			'tabsLabel'        => __( 'Editing mode', 'df-editor' ),
			'device'           => __( 'Preview size', 'df-editor' ),
			'desktop'          => __( 'Desktop', 'df-editor' ),
			'tablet'           => __( 'Tablet', 'df-editor' ),
			'mobile'           => __( 'Mobile', 'df-editor' ),
			'undo'             => __( 'Undo', 'df-editor' ),
			'redo'             => __( 'Redo', 'df-editor' ),
			'history'          => __( 'History', 'df-editor' ),
			'discard'          => __( 'Discard', 'df-editor' ),
			'publish'          => __( 'Publish', 'df-editor' ),
			'publishing'       => __( 'Publishing…', 'df-editor' ),
			'statusPublished'  => __( 'Published', 'df-editor' ),
			'statusDraft'      => __( 'Draft saved', 'df-editor' ),
			'statusUnsaved'    => __( 'Unsaved changes', 'df-editor' ),
			'statusSaving'     => __( 'Saving…', 'df-editor' ),
			'statusInvalid'    => __( 'Not saved: please fix the errors', 'df-editor' ),
			'statusError'      => __( 'Saving failed', 'df-editor' ),
			'statusReadonly'   => __( 'Read only', 'df-editor' ),
			'sections'         => __( 'Sections', 'df-editor' ),
			'sectionsList'     => __( 'Sections on this page', 'df-editor' ),
			'toggleSidebar'    => __( 'Show or hide the sidebar', 'df-editor' ),
			'empty'            => __( 'This page has no sections yet.', 'df-editor' ),
			'addSection'       => __( 'Add section', 'df-editor' ),
			'addSectionHere'   => __( 'Add section at position %d', 'df-editor' ),
			'dragHandle'       => __( 'Drag to reorder %s', 'df-editor' ),
			'moveUp'           => __( 'Move %s up', 'df-editor' ),
			'moveDown'         => __( 'Move %s down', 'df-editor' ),
			'hide'             => __( 'Hide %s', 'df-editor' ),
			'show'             => __( 'Show %s', 'df-editor' ),
			'more'             => __( 'More actions for %s', 'df-editor' ),
			'duplicate'        => __( 'Duplicate', 'df-editor' ),
			'delete'           => __( 'Delete', 'df-editor' ),
			'lock'             => __( 'Lock position', 'df-editor' ),
			'unlock'           => __( 'Unlock', 'df-editor' ),
			'locked'           => __( 'Locked', 'df-editor' ),
			'hiddenBadge'      => __( 'Hidden', 'df-editor' ),
			'editOptions'      => __( 'Edit options of %s', 'df-editor' ),
			'confirmDelete'    => __( 'Delete the section "%s"? Its texts stay saved and come back if you undo.', 'df-editor' ),
			'confirmDiscard'   => __( 'Discard the draft and go back to the published version?', 'df-editor' ),
			'moved'            => __( '%1$s moved to position %2$d of %3$d.', 'df-editor' ),
			'cannotMove'       => __( '%s cannot move further.', 'df-editor' ),
			'lockedNoMove'     => __( '%s is locked and keeps its position.', 'df-editor' ),
			'added'            => __( '%s added.', 'df-editor' ),
			'deleted'          => __( '%s deleted.', 'df-editor' ),
			'duplicated'       => __( '%s duplicated.', 'df-editor' ),
			'hiddenNow'        => __( '%s is now hidden.', 'df-editor' ),
			'shownNow'         => __( '%s is now visible.', 'df-editor' ),
			'lockedNow'        => __( '%s is locked.', 'df-editor' ),
			'unlockedNow'      => __( '%s is unlocked.', 'df-editor' ),
			'undone'           => __( 'Undone.', 'df-editor' ),
			'redone'           => __( 'Redone.', 'df-editor' ),
			'nothingToUndo'    => __( 'Nothing to undo.', 'df-editor' ),
			'nothingToRedo'    => __( 'Nothing to redo.', 'df-editor' ),
			'copyContentFail'  => __( 'The section was duplicated, but its saved texts could not be copied.', 'df-editor' ),
			'back'             => __( 'Back to sections', 'df-editor' ),
			'variant'          => __( 'Variant', 'df-editor' ),
			'scheme'           => __( 'Colour scheme', 'df-editor' ),
			'spacing'          => __( 'Spacing', 'df-editor' ),
			'spacingTop'       => __( 'Top', 'df-editor' ),
			'spacingBottom'    => __( 'Bottom', 'df-editor' ),
			'width'            => __( 'Width', 'df-editor' ),
			'count'            => __( 'Number of items', 'df-editor' ),
			'countHint'        => __( 'Between %1$d and %2$d.', 'df-editor' ),
			'options'          => __( 'Options', 'df-editor' ),
			'anchor'           => __( 'Anchor', 'df-editor' ),
			'anchorHint'       => __( 'Optional jump target for menu links, e.g. "contact" for #contact. Lowercase letters, digits and hyphens.', 'df-editor' ),
			'hiddenField'      => __( 'Hide this section on the page', 'df-editor' ),
			'sectionId'        => __( 'Section ID', 'df-editor' ),
			'textsHint'        => __( 'Texts and images are edited in the Content tab.', 'df-editor' ),
			'libraryTitle'     => __( 'Add a section', 'df-editor' ),
			'search'           => __( 'Search sections', 'df-editor' ),
			'noResults'        => __( 'No sections match your search.', 'df-editor' ),
			'close'            => __( 'Close', 'df-editor' ),
			'insert'           => __( 'Add %s', 'df-editor' ),
			'variantsCount'    => __( '%d variants', 'df-editor' ),
			'historyTitle'     => __( 'Published versions', 'df-editor' ),
			'historyEmpty'     => __( 'No earlier versions yet.', 'df-editor' ),
			'historyRev'       => __( 'Version %d', 'df-editor' ),
			'historyBy'        => __( '%1$s by %2$s', 'df-editor' ),
			'restore'          => __( 'Restore to draft', 'df-editor' ),
			'restored'         => __( 'Version %d restored into the draft. Publish to make it live.', 'df-editor' ),
			'currentLive'      => __( 'Live version: %d', 'df-editor' ),
			'published'        => __( 'Published.', 'df-editor' ),
			'discarded'        => __( 'Draft discarded.', 'df-editor' ),
			'conflict'         => __( 'Someone published a newer version of this page. Reload to continue with the latest version (your unsaved changes will be lost).', 'df-editor' ),
			'reload'           => __( 'Reload', 'df-editor' ),
			'lockedBy'         => __( '%s is editing this page right now. You can look, but not change anything.', 'df-editor' ),
			'otherWindow'      => __( 'You are editing this page in another window or on another device. This view is read only.', 'df-editor' ),
			'foreignDraft'     => __( 'The agency is preparing changes to this page. You can look, but not change anything until they are published or discarded.', 'df-editor' ),
			'publishWait'      => __( 'Publishing… please wait a moment before making more changes.', 'df-editor' ),
			'someone'          => __( 'Someone else', 'df-editor' ),
			'takeOver'         => __( 'Take over', 'df-editor' ),
			'tookOver'         => __( 'You are now editing this page.', 'df-editor' ),
			'lockLost'         => __( 'Another user took over this page. Your view is read only now.', 'df-editor' ),
			'reorderOnly'      => __( 'You can change the order of sections and hide or show them.', 'df-editor' ),
			'errorsTitle'      => __( 'Please fix:', 'df-editor' ),
			'warningsTitle'    => __( 'Notes:', 'df-editor' ),
			'forbidden'        => __( 'You are not allowed to make this change.', 'df-editor' ),
			'requestFailed'    => __( 'The request failed. Please try again.', 'df-editor' ),
			'loadFailed'       => __( 'DF Panel could not load this page.', 'df-editor' ),
			'noPage'           => __( 'Choose a page to edit.', 'df-editor' ),
			'previewTitle'     => __( 'Page preview', 'df-editor' ),
			'previewLoading'   => __( 'Loading preview…', 'df-editor' ),
			'inhaltHint'       => __( 'Click any text or image in the preview to edit it. Text changes are saved immediately by DF Inhalt (they are not part of the layout draft). If a layout draft exists, it is shown here so new sections can be filled in too.', 'df-editor' ),
			'inhaltOpenPage'   => __( 'Open the page in a new tab', 'df-editor' ),
			'designHint'       => __( 'Design settings: open the Customizer (DF Design).', 'df-editor' ),
			'designOpen'       => __( 'Open the Customizer', 'df-editor' ),
			'designNoAccess'   => __( 'Design settings are managed by the agency.', 'df-editor' ),
			'notApplicable'    => __( 'Not used by this variant', 'df-editor' ),
			'dismiss'          => __( 'Dismiss', 'df-editor' ),
			'unsavedLeave'     => __( 'Changes are still being saved. Leave anyway?', 'df-editor' ),
			'shortcutsHint'    => __( 'Shortcuts: Ctrl+Z undo, Ctrl+Shift+Z redo.', 'df-editor' ),
		);
		printf( "<script>window.dfPanelI18n = %s;</script>\n", wp_json_encode( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ------------------------------------------------------------------ *
	 * Entry points
	 * ------------------------------------------------------------------ */

	/**
	 * Admin bar node "DF Panel" on front-end pages with a DF layout and on the page edit screen.
	 */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! self::can_open() ) {
			return;
		}
		$post_id = 0;
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		} elseif ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		}
		if ( ! self::has_layout( $post_id ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'df-panel',
				'title' => esc_html__( 'DF Panel', 'df-editor' ),
				'href'  => self::url( $post_id ),
				'meta'  => array( 'title' => __( 'Edit the layout of this page', 'df-editor' ) ),
			)
		);
	}

	/**
	 * "DF Panel" link in the pages list.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Page.
	 */
	public static function row_action( $actions, $post ) {
		if ( $post instanceof WP_Post && self::can_open() && self::has_layout( (int) $post->ID ) ) {
			$actions['df_panel'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( (int) $post->ID ) ), esc_html__( 'DF Panel', 'df-editor' ) );
		}
		return $actions;
	}

	/**
	 * No admin bar inside the panel preview iframe.
	 *
	 * @param bool $show Show the admin bar.
	 */
	public static function hide_admin_bar_in_frame( $show ) {
		return self::in_frame() ? false : $show;
	}

	/**
	 * Small floating "DF Panel" link for users who may open the panel but see no admin bar
	 * (e.g. the client role in DF Inhalt client mode).
	 */
	public static function floating_link(): void {
		if ( is_admin() || self::in_frame() || is_admin_bar_showing() || ! is_singular() || ! self::can_open() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! self::has_layout( $post_id ) ) {
			return;
		}
		/**
		 * Filters whether the floating DF Panel link is printed.
		 *
		 * @param bool $show    Show the link.
		 * @param int  $post_id Current page.
		 */
		if ( ! apply_filters( 'df_panel_floating_link', true, $post_id ) ) {
			return;
		}
		printf(
			'<a class="df-panel-fab" href="%1$s" style="position:fixed;left:16px;bottom:16px;z-index:99990;padding:10px 14px;border-radius:999px;background:#1d2327;color:#fff;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25)">%2$s</a>',
			esc_url( self::url( $post_id ) ),
			esc_html__( 'Edit layout', 'df-editor' )
		);
	}
}

DF_Panel::init();
