<?php
/**
 * Activation tasks: per-site placeholder image for image fields without a default.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Setup {

	public const PLACEHOLDER_OPTION = 'df_placeholder_image_id';

	public static function init(): void {
		// Covers sites where the plugin was activated before this code existed (or the attachment was deleted).
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_placeholder' ) );
	}

	public static function activate(): void {
		self::maybe_create_placeholder();
	}

	/**
	 * Creates a fresh placeholder attachment. The old one is deleted unless saved content uses it.
	 */
	public static function regenerate_placeholder(): int {
		$old = (int) get_option( self::PLACEHOLDER_OPTION, 0 );
		delete_option( self::PLACEHOLDER_OPTION );
		$id = self::maybe_create_placeholder();
		if ( ! $id ) {
			if ( $old > 0 ) {
				update_option( self::PLACEHOLDER_OPTION, $old, false );
			}
			return 0;
		}
		if ( $old > 0 && $old !== $id && 'attachment' === get_post_type( $old ) ) {
			$values = get_option( DF_Content::VALUES_OPTION, array() );
			$used   = is_array( $values ) && in_array( $old, array_map( 'intval', array_filter( $values, 'is_numeric' ) ), true );
			if ( ! $used ) {
				wp_delete_attachment( $old, true );
			}
		}
		return $id;
	}

	/**
	 * Creates the placeholder attachment once and stores its ID.
	 */
	public static function maybe_create_placeholder(): int {
		$id = (int) get_option( self::PLACEHOLDER_OPTION, 0 );
		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}

		$source = DF_EDITOR_DIR . 'assets/img/placeholder.png';
		if ( ! is_readable( $source ) ) {
			return 0;
		}

		$upload = wp_upload_bits( 'df-placeholder.png', null, (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_title'     => __( 'DF placeholder image', 'df-editor' ),
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', '' );

		update_option( self::PLACEHOLDER_OPTION, (int) $id, false );
		return (int) $id;
	}
}
