<?php
/**
 * Public template helpers. They only forward to DF_Content / DF_Renderer.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

function df_text( string $id, array $args = array() ): void {
	DF_Content::text( $id, $args );
}

function df_richtext( string $id, array $args = array() ): void {
	DF_Content::richtext( $id, $args );
}

function df_button( string $id, array $args = array() ): void {
	DF_Content::button( $id, $args );
}

function df_image( string $id, array $args = array() ): void {
	DF_Content::image( $id, $args );
}

function df_region_open( array $args = array() ): void {
	DF_Content::region_open( $args );
}

function df_region_close(): void {
	DF_Content::region_close();
}

/**
 * Saved content value of a field ID, or null.
 */
function df_get( string $id ): mixed {
	return DF_Content::get( $id );
}

/**
 * Renders the DF layout of a page (current post when null).
 */
function df_render_layout( ?int $post_id = null ): void {
	DF_Renderer::render_layout( $post_id );
}

/**
 * Renders one section outside a stored layout (e.g. in a theme template).
 *
 * @param string $type        Section type, e.g. "hero".
 * @param string $instance_id Stable instance ID, e.g. "hero-main". Never change it once published.
 * @param array  $options     variant, scheme, spacing, width, anchor, options, content, page_key.
 */
function df_section( string $type, string $instance_id, array $options = array() ): void {
	DF_Renderer::section( $type, $instance_id, $options );
}

/**
 * Whether a post has a DF layout.
 */
function df_has_layout( ?int $post_id = null ): bool {
	$post_id = $post_id ?? (int) get_the_ID();
	return $post_id > 0 && null !== DF_Renderer::layout_for( $post_id );
}

/**
 * A DF Editor setting (Settings > DF Editor): honeypot, consent_tool, disable_inline_editing.
 */
function df_editor_setting( string $key ) {
	return DF_Settings::get( $key );
}

/**
 * Current DF Design values (option df_design merged with defaults; empty = theme value).
 */
function df_design(): array {
	return DF_Design::get();
}
