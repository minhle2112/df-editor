<?php
/**
 * Database upgrade routine of the plugin (runs once per DB_VERSION on every site).
 *
 * Version 2: the DF Panel was removed. Its capabilities (df_edit_layout, df_reorder_sections),
 * its post meta (_df_layout_draft, _df_layout_lock) and the setting allow_client_reorder are
 * cleaned up. Live layouts and their history are not touched.
 *
 * Version 3: the plugin section library and the Gutenberg integration (DF blocks) were removed.
 * The setting section_blocks is cleaned up. Pages converted with `wp df to-blocks` get their
 * JSON layout back from the converter backup (_df_layout_converted) when they have none; the
 * backups themselves are kept. Sections now live in the client theme (df-sections/).
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Upgrade {

	/** Stored schema version; bump DB_VERSION to run upgrade() once on every site. */
	public const DB_OPTION  = 'df_editor_db_version';
	public const DB_VERSION = 3;

	/** Capabilities of the removed DF Panel. */
	private const OLD_CAPS = array( 'df_edit_layout', 'df_reorder_sections' );

	/** Post meta of the removed DF Panel (v2). */
	private const OLD_META = array( '_df_layout_draft', '_df_layout_lock' );

	/** Layout backup written by the removed `wp df to-blocks` (v3: restored, never deleted). */
	private const CONVERTED_META = '_df_layout_converted';

	/** Settings keys of removed features: DF Panel (v2), section blocks in Gutenberg (v3). */
	private const OLD_SETTINGS = array( 'allow_client_reorder', 'section_blocks' );

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

		self::restore_converted_layouts();

		$settings = get_option( DF_Settings::OPTION );
		if ( is_array( $settings ) ) {
			$changed = false;
			foreach ( self::OLD_SETTINGS as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					unset( $settings[ $key ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( DF_Settings::OPTION, $settings );
			}
		}

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Pages converted to DF blocks (removed in v3) get their JSON layout back, so they render
	 * through the theme sections again. Only when the page has no _df_layout of its own.
	 */
	private static function restore_converted_layouts(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::CONVERTED_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $post_id ) {
			$raw = get_post_meta( (int) $post_id, self::CONVERTED_META, true );
			if ( ! is_string( $raw ) || '' === trim( $raw ) || metadata_exists( 'post', (int) $post_id, DF_Layout_Store::META_LAYOUT ) ) {
				continue;
			}
			if ( ! is_array( json_decode( $raw, true ) ) ) {
				continue;
			}
			update_post_meta( (int) $post_id, DF_Layout_Store::META_LAYOUT, wp_slash( $raw ) );
		}
	}
}
