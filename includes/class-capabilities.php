<?php
/**
 * DF Panel capabilities and the plugin's database upgrade routine.
 *
 * - df_edit_layout       full Layout tab (administrator + editor).
 * - df_reorder_sections  "reorder-only": change the order and the hidden flag of existing sections.
 *                        Given to the role df_client only while the setting allow_client_reorder is on.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Capabilities {

	public const CAP_EDIT    = 'df_edit_layout';
	public const CAP_REORDER = 'df_reorder_sections';

	/** Roles that get df_edit_layout. */
	public const EDIT_ROLES = array( 'administrator', 'editor' );

	/** Role of the client (created by DF Inhalt client mode). */
	public const CLIENT_ROLE = 'df_client';

	/** Stored schema version; bump DB_VERSION to run upgrade() once on every site. */
	public const DB_OPTION  = 'df_editor_db_version';
	public const DB_VERSION = 1;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		// After DF Inhalt (re)creates the client role at init:1000.
		add_action( 'init', array( __CLASS__, 'sync_client_reorder' ), 1001 );
		add_action( 'add_option_' . DF_Settings::OPTION, array( __CLASS__, 'sync_client_reorder' ) );
		add_action( 'update_option_' . DF_Settings::OPTION, array( __CLASS__, 'sync_client_reorder' ) );
		// Turning DF Inhalt client mode off also takes panel access away from clients.
		add_action( 'add_option_df_inhalt_client_mode', array( __CLASS__, 'sync_client_reorder' ) );
		add_action( 'update_option_df_inhalt_client_mode', array( __CLASS__, 'sync_client_reorder' ) );
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::upgrade();
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) < self::DB_VERSION ) {
			self::upgrade();
		}
	}

	/**
	 * Idempotent: grants df_edit_layout to the edit roles and syncs the client role.
	 */
	public static function upgrade(): void {
		foreach ( self::EDIT_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP_EDIT ) ) {
				$role->add_cap( self::CAP_EDIT );
			}
		}
		self::sync_client_reorder();
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Gives or takes df_reorder_sections on the client role according to allow_client_reorder.
	 * Writes to the database only when the role is out of sync.
	 */
	public static function sync_client_reorder(): void {
		$role = get_role( self::CLIENT_ROLE );
		if ( ! $role ) {
			return;
		}
		// Only while DF Inhalt client mode is on (clients are locked out of everything when it is off).
		$want = (bool) DF_Settings::get( 'allow_client_reorder' ) && self::client_mode_enabled();
		if ( $want && ! $role->has_cap( self::CAP_REORDER ) ) {
			$role->add_cap( self::CAP_REORDER );
		} elseif ( ! $want && $role->has_cap( self::CAP_REORDER ) ) {
			$role->remove_cap( self::CAP_REORDER );
		}
	}

	/**
	 * Full layout editing (optionally for one post: also needs edit_post).
	 */
	public static function can_edit_layout( int $post_id = 0 ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP_EDIT ) ) {
			return false;
		}
		return $post_id <= 0 || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether the user may at least reorder/hide sections (for one post: see can_reorder_post()).
	 */
	public static function can_reorder( int $post_id = 0 ): bool {
		if ( self::can_edit_layout( $post_id ) ) {
			return true;
		}
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP_REORDER ) || ! self::client_mode_enabled() ) {
			return false;
		}
		return $post_id <= 0 || self::can_reorder_post( $post_id );
	}

	/**
	 * Per-post gate for reorder-only users: published posts of an allowed type that the user
	 * can read. Filter "df_editor_reorder_post_types" (default: page).
	 */
	private static function can_reorder_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		$types = (array) apply_filters( 'df_editor_reorder_post_types', array( 'page' ) );
		return in_array( $post->post_type, $types, true ) && current_user_can( 'read_post', $post_id );
	}

	/**
	 * DF Inhalt client mode. True when DF Inhalt is not loaded, so the setting alone decides.
	 */
	private static function client_mode_enabled(): bool {
		return ! class_exists( 'DF_Inhalt_Client' ) || DF_Inhalt_Client::enabled();
	}

	/**
	 * Reorder-only user: may change order and hidden flags only.
	 */
	public static function is_reorder_only( int $post_id = 0 ): bool {
		return ! self::can_edit_layout( $post_id ) && self::can_reorder( $post_id );
	}

	/**
	 * Any DF Panel access (Layout tab).
	 */
	public static function can_use_panel( int $post_id = 0 ): bool {
		return self::can_reorder( $post_id );
	}

	/**
	 * URL of the DF Panel app for a post.
	 */
	public static function panel_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => 'df-panel',
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}
}
