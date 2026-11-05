<?php
/**
 * Database upgrade routine of the plugin (runs once per DB_VERSION on every site).
 *
 * Version 2: the DF Panel was removed. Its capabilities (df_edit_layout, df_reorder_sections),
 * its post meta (_df_layout_draft, _df_layout_lock) and the setting allow_client_reorder are
 * cleaned up. Live layouts and their history are not touched.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Upgrade {

	/** Stored schema version; bump DB_VERSION to run upgrade() once on every site. */
	public const DB_OPTION  = 'df_editor_db_version';
	public const DB_VERSION = 2;

	/** Capabilities of the removed DF Panel. */
	private const OLD_CAPS = array( 'df_edit_layout', 'df_reorder_sections' );

	/** Post meta of the removed DF Panel. */
	private const OLD_META = array( '_df_layout_draft', '_df_layout_lock' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
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
	 * Idempotent.
	 */
	public static function upgrade(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::OLD_CAPS as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		foreach ( self::OLD_META as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		$settings = get_option( DF_Settings::OPTION );
		if ( is_array( $settings ) && array_key_exists( 'allow_client_reorder', $settings ) ) {
			unset( $settings['allow_client_reorder'] );
			update_option( DF_Settings::OPTION, $settings );
		}

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}
