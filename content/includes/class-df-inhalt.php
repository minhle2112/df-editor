<?php
/**
 * Public API of the DF Inhalt module.
 *
 * This is the only class the rest of DF Editor (DF_Content) and themes should
 * call. Everything else in content/ is internal and may change.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Inhalt {

	/**
	 * Whether df_inhalt_boot() has run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Mark the module as booted. Called by df_inhalt_boot().
	 *
	 * @internal
	 * @return void
	 */
	public static function mark_booted(): void {
		self::$booted = true;
	}

	/**
	 * Render an editable single-line text field.
	 *
	 * @param string $id   Field ID, ^[a-z0-9][a-z0-9._-]*$.
	 * @param array  $args label, default, max_length (scope, post_id).
	 */
	public static function text( string $id, array $args = [] ): void {
		df_inhalt_text( $id, $args );
	}

	/**
	 * Render an editable rich text field (bold, italic, links, lists).
	 *
	 * @param string $id   Field ID.
	 * @param array  $args label, default, class, max_length (scope, post_id).
	 */
	public static function richtext( string $id, array $args = [] ): void {
		df_inhalt_richtext( $id, $args );
	}

	/**
	 * Render an editable link button.
	 *
	 * @param string $id   Field ID.
	 * @param array  $args label, default ([text, url]), class, max_length, before, after, target (scope, post_id).
	 */
	public static function button( string $id, array $args = [] ): void {
		df_inhalt_button( $id, $args );
	}

	/**
	 * Render an editable Media Library image.
	 *
	 * @param string $id   Field ID.
	 * @param array  $args label, default (attachment ID), size, class, loading, fetchpriority,
	 *                     aspect_ratio, object_fit, object_position (scope, post_id).
	 */
	public static function image( string $id, array $args = [] ): void {
		df_inhalt_image( $id, $args );
	}

	/**
	 * Open a read-only region whose content is owned by another provider (ACF, WooCommerce, ...).
	 *
	 * @param array $args provider, label, action_label, edit_url, class.
	 */
	public static function region_open( array $args ): void {
		df_inhalt_region_open( $args );
	}

	/**
	 * Close a region opened with region_open().
	 */
	public static function region_close(): void {
		df_inhalt_region_close();
	}

	/**
	 * Return the value saved for a field, or null when nothing was saved. Prints nothing.
	 *
	 * @param string $id Field ID.
	 * @return mixed|null
	 */
	public static function get( string $id ): mixed {
		return DF_Inhalt_Fields::get_saved_value( $id );
	}

	/**
	 * Whether the current user may edit content on the front end.
	 */
	public static function can_edit(): bool {
		return self::editing_enabled() && DF_Inhalt_Plugin::can_edit();
	}

	/**
	 * Whether the module is booted and not switched off with the option df_inhalt_disabled.
	 */
	public static function editing_enabled(): bool {
		return self::$booted && ! get_option( 'df_inhalt_disabled', false );
	}
}
