<?php
/**
 * Client role and the simplified WordPress editing experience.
 *
 * @package DF_Editor
 */
defined( 'ABSPATH' ) || exit;

final class DF_Inhalt_Client {

	const ROLE = 'df_client';
	const OPTION = 'df_inhalt_client_mode';
	const CAPS_OPTION = 'df_inhalt_client_capabilities';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'sync_role' ), 1000 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'login_message', array( __CLASS__, 'logged_out_message' ) );
		add_filter( 'wp_login_errors', array( __CLASS__, 'logged_out_errors' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect_pages' ), 20, 4 );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_menus' ), 999 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'render_header' ) );
		add_action( 'admin_post_df_inhalt_save_settings', array( __CLASS__, 'save_settings' ) );
	}

	public static function enabled() {
		return (bool) get_option( self::OPTION, false );
	}

	/** A role remains identifiable when client mode is disabled. */
	public static function is_client( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		return in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	public static function clean_admin() {
		return self::enabled() && self::is_client();
	}

	/** No choices screen: use registered, frontend-facing content types. */
	public static function editable_types() {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $type ) {
			if ( ! in_array( $name, array( 'page', 'attachment', 'revision' ), true ) && ( $type->public || $type->publicly_queryable ) ) {
				$types[ $name ] = $type;
			}
		}
		return $types;
	}

	/** Reconcile only capabilities this plugin owns, including late-registered CPTs. */
	public static function sync_role() {
		$role = get_role( self::ROLE );
		if ( ! $role && ! self::enabled() ) {
			return;
		}
		if ( ! $role ) {
			add_role( self::ROLE, __( 'Clients', 'df-editor' ), array( 'read' => true ) );
			$role = get_role( self::ROLE );
		}
		if ( ! $role ) {
			return;
		}
		$owned = array( 'read' );
		if ( self::enabled() ) {
			$owned = array( 'read', 'upload_files', 'df_inhalt_edit_content', 'df_inhalt_comment' );
			$keys = array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'edit_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'create_posts' );
			foreach ( self::editable_types() as $type ) {
				foreach ( $keys as $key ) {
					if ( isset( $type->cap->$key ) && self::safe_capability( $type->cap->$key ) ) {
						$owned[] = $type->cap->$key;
					}
				}
				foreach ( get_object_taxonomies( $type->name, 'objects' ) as $taxonomy ) {
					if ( self::safe_capability( $taxonomy->cap->assign_terms ) ) {
						$owned[] = $taxonomy->cap->assign_terms;
					}
				}
			}
		}
		$owned = array_values( array_unique( $owned ) );
		sort( $owned );
		$previous = (array) get_option( self::CAPS_OPTION, array() );
		foreach ( array_diff( $previous, $owned ) as $cap ) {
			$role->remove_cap( $cap );
		}
		foreach ( $owned as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
		if ( $owned !== $previous ) {
			update_option( self::CAPS_OPTION, $owned, false );
		}
	}

	/**
	 * Remove every capability this module added to the Clients role (deactivation).
	 *
	 * Keeps the role and `read`, and forgets the owned list so the next
	 * sync_role() after reactivation adds the capabilities again.
	 *
	 * @return void
	 */
	public static function revoke_capabilities() {
		$role = get_role( self::ROLE );
		if ( $role ) {
			$owned = array_merge( (array) get_option( self::CAPS_OPTION, array() ), array( 'upload_files', 'df_inhalt_edit_content', 'df_inhalt_comment' ) );
			foreach ( array_unique( $owned ) as $cap ) {
				if ( 'read' !== $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
		delete_option( self::CAPS_OPTION );
	}

	private static function safe_capability( $cap ) {
		return is_string( $cap ) && preg_match( '/^(?:edit|delete|publish|read|create|assign)_[a-z0-9_]+$/', $cap ) && ! in_array( $cap, array( 'manage_options', 'manage_woocommerce', 'edit_theme_options', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'edit_private_pages', 'delete_private_pages', 'read_private_pages', 'unfiltered_html', 'do_not_allow' ), true ) && ! preg_match( '/(?:plugins|themes|users|network|files)$/', $cap );
	}

	/** Enforce page restrictions beyond the hidden navigation, including REST. */
	public static function protect_pages( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'edit_page', 'delete_page', 'edit_post_meta', 'delete_post_meta', 'add_post_meta' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::is_client( $user ) ) {
			return $caps;
		}
		$post = get_post( $args[0] );
		if ( $post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}
		if ( $post && 'attachment' !== $post->post_type && ( ! self::enabled() || ! isset( self::editable_types()[ $post->post_type ] ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		return $user instanceof WP_User && self::enabled() && self::is_client( $user ) ? home_url( '/' ) : $redirect;
	}

	public static function show_admin_bar( $show ) {
		return self::clean_admin() ? false : $show;
	}

	public static function logged_out_url() {
		return add_query_arg( array( 'loggedout' => 'true', 'df-inhalt-logged-out' => '1' ), wp_login_url() );
	}

	private static function is_logged_out_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation flag.
		return self::enabled() && ! is_user_logged_in() && isset( $_GET['df-inhalt-logged-out'], $_GET['loggedout'] ) && '1' === $_GET['df-inhalt-logged-out'] && 'true' === $_GET['loggedout'];
	}

	public static function logged_out_message( $message ) {
		if ( ! self::is_logged_out_screen() ) {
			return $message;
		}
		return $message . '<div class="df-inhalt-logged-out"><h2>' . esc_html__( 'You are logged out.', 'df-editor' ) . '</h2><p>' . esc_html__( 'You can now view the website without DF Inhalt.', 'df-editor' ) . '</p><a class="df-inhalt-logged-out__button" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to website', 'df-editor' ) . '</a></div>';
	}

	public static function logged_out_errors( $errors ) {
		if ( self::is_logged_out_screen() ) {
			$errors->remove( 'loggedout' );
		}
		return $errors;
	}

	public static function login_assets() {
		if ( self::is_logged_out_screen() ) {
			wp_enqueue_style( 'df-inhalt-login', DF_INHALT_PLUGIN_URL . 'admin/css/login.css', array(), DF_INHALT_VERSION );
		}
	}

	/**
	 * Whether the request is the DF Panel screen (admin.php?page=df-panel)
	 * and the user may open it.
	 *
	 * The agency enables client reordering in the DF Editor settings, which
	 * grants `df_reorder_sections`; the panel and its REST routes enforce the
	 * reorder-only limits on the server.
	 *
	 * @return bool
	 */
	public static function is_panel_request() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 'admin.php' === $pagenow && 'df-panel' === $page
			&& ( current_user_can( 'df_reorder_sections' ) || current_user_can( 'df_edit_layout' ) );
	}

	/** Keep media uploads, autosaves and authenticated editor AJAX working. */
	public static function guard_admin() {
		if ( ! self::is_client() ) {
			return;
		}
		if ( self::is_panel_request() ) {
			return; // DF Panel: capability-gated, see is_panel_request().
		}
		if ( ! self::enabled() ) {
			if ( ! wp_doing_ajax() ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			return;
		}
		if ( wp_doing_ajax() ) {
			return; // Core handlers enforce their own capabilities and nonces.
		}
		global $pagenow;
		if ( 'async-upload.php' === $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; mutations remain protected by core.
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : ( isset( $_REQUEST['post_ID'] ) ? absint( $_REQUEST['post_ID'] ) : 0 );
		if ( $post_id ) {
			$type = get_post_type( $post_id );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			$type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : 'post';
		}
		$allowed = isset( self::editable_types()[ $type ] ) && in_array( $pagenow, array( 'post.php', 'post-new.php', 'edit.php' ), true );
		if ( ! $allowed ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	public static function remove_menus() {
		if ( self::clean_admin() ) {
			global $menu;
			foreach ( (array) $menu as $entry ) {
				remove_menu_page( $entry[2] );
			}
		}
	}

	public static function body_class( $classes ) {
		return self::clean_admin() && ! self::is_panel_request() ? $classes . ' df-inhalt-client-admin' : $classes;
	}

	public static function enqueue_assets() {
		// The DF Panel brings its own full-screen UI; no client chrome there.
		if ( ! self::clean_admin() || self::is_panel_request() ) {
			return;
		}
		wp_enqueue_style( 'df-inhalt-client', DF_INHALT_PLUGIN_URL . 'admin/css/client.css', array(), DF_INHALT_VERSION );
		$screen = get_current_screen();
		$deps = $screen && $screen->is_block_editor() ? array( 'wp-data', 'wp-dom-ready', 'wp-preferences' ) : array();
		wp_enqueue_script( 'df-inhalt-client', DF_INHALT_PLUGIN_URL . 'admin/js/client.js', $deps, DF_INHALT_VERSION, true );
		wp_localize_script( 'df-inhalt-client', 'dfInhaltClient', array( 'trash' => __( 'Move to trash', 'df-editor' ), 'keep' => __( 'Keep editing', 'df-editor' ), 'question' => __( 'Move this content to trash?', 'df-editor' ), 'description' => __( 'You can restore it from the trash later.', 'df-editor' ) ) );
	}

	public static function render_header() {
		if ( ! self::clean_admin() || self::is_panel_request() ) {
			return;
		}
		$screen = get_current_screen();
		$type = $screen ? get_post_type_object( $screen->post_type ) : null;
		if ( ! $type ) {
			return;
		}
		$back = home_url( '/' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a validated same-site return URL.
		if ( isset( $_GET['df_inhalt_return'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['df_inhalt_return'] ) ), $back );
			$origin = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( wp_parse_url( $back, PHP_URL_HOST ) !== $origin ) {
				$back = home_url( '/' );
			}
		}
		?>
		<header class="df-inhalt-client-header">
			<div class="df-inhalt-client-brand"><strong>DF Inhalt</strong><span><?php esc_html_e( 'Client', 'df-editor' ); ?></span></div>
			<nav aria-label="<?php esc_attr_e( 'Client navigation', 'df-editor' ); ?>">
				<a class="df-inhalt-client-button" href="<?php echo esc_url( add_query_arg( 'post_type', $type->name, admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: plural post type label. */ __( 'All %s', 'df-editor' ), $type->labels->name ) ); ?></a>
				<a class="df-inhalt-client-button" href="<?php echo esc_url( add_query_arg( 'df-inhalt-edit', '1', $back ) ); ?>"><?php esc_html_e( 'Back to website', 'df-editor' ); ?></a>
				<a class="df-inhalt-client-button" href="<?php echo esc_url( wp_logout_url( self::logged_out_url() ) ); ?>"><?php esc_html_e( 'Log out', 'df-editor' ); ?></a>
			</nav>
		</header>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'df-editor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'df_inhalt_save_settings' );
		update_option( self::OPTION, ! empty( $_POST['client_mode'] ), false );
		self::sync_role();
		wp_safe_redirect( add_query_arg( array( 'page' => DF_Inhalt_Admin::MENU_SLUG, 'df-inhalt-settings-saved' => '1' ), admin_url( 'admin.php' ) ) . '#settings' );
		exit;
	}
}
