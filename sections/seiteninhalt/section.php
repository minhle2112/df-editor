<?php
/**
 * Section: Seiteninhalt
 *
 * Prints the WordPress post content of the page being rendered (Impressum, Datenschutz, long texts).
 * The content is edited in WordPress, so it is marked as a managed region. No DF Inhalt fields.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

// Recursion guard: a the_content filter that renders the DF layout again would include this section.
if ( ! empty( $GLOBALS['df_seiteninhalt_rendering'] ) ) {
	return;
}

$df_post = get_post( in_the_loop() ? get_the_ID() : ( is_singular() ? get_queried_object_id() : get_the_ID() ) );
if ( ! $df_post instanceof WP_Post ) {
	return;
}

$df_is_staff = current_user_can( 'edit_post', $df_post->ID );
$df_html     = '';

if ( post_password_required( $df_post ) ) {
	$df_html = get_the_password_form( $df_post );
} elseif ( '' !== trim( (string) $df_post->post_content ) ) {
	$GLOBALS['df_seiteninhalt_rendering'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	// the_content filters rely on the global post (blocks, shortcodes, embeds): set it temporarily.
	$df_prev_post    = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $df_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $df_post );
	$df_html = (string) apply_filters( 'the_content', $df_post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	$df_html = str_replace( ']]>', ']]&gt;', $df_html );
	$GLOBALS['post'] = $df_prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	if ( $df_prev_post instanceof WP_Post ) {
		setup_postdata( $df_prev_post );
	}
	$GLOBALS['df_seiteninhalt_rendering'] = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

if ( '' === trim( $df_html ) ) {
	if ( $df_is_staff ) {
		printf(
			'<div class="df-missing" role="note">%1$s <a href="%2$s">%3$s</a></div>',
			esc_html__( 'This page has no WordPress content yet.', 'df-editor' ),
			esc_url( admin_url( 'post.php?post=' . (int) $df_post->ID . '&action=edit' ) ),
			esc_html__( 'Edit page content', 'df-editor' )
		);
	}
	return;
}

df_region_open(
	array(
		'provider' => 'wordpress',
		'label'    => __( 'Page content – edit in WordPress', 'df-editor' ),
		'edit_url' => admin_url( 'post.php?post=' . (int) $df_post->ID . '&action=edit' ),
		'class'    => 'df-seiteninhalt__region',
	)
);
?>
<div class="df-seiteninhalt__prose df-prose">
	<?php echo $df_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output. ?>
</div>
<?php
df_region_close();
