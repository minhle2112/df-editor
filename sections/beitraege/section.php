<?php
/**
 * Section: Beiträge (post teasers)
 *
 * Source "manual": teaser cards from the list items (DF Inhalt or block content).
 * Source "posts": the latest published posts (optionally one category); the number of cards is the
 * list count (v2 blocks: the number of child blocks). Post cards are read-only for DF Inhalt
 * editors ("managed regions" linking to the WordPress editor).
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h         = df_heading_tag( $s->opt( 'heading_level' ) );
$h_item    = 'h2' === $h ? 'h3' : 'h4';
$can_edit  = $s->can_edit();
$show_meta = 'yes' === $s->opt( 'show_meta' );
$show_more = $can_edit || df_field_filled( $s->value( 'more' ) );
$show_cta  = $can_edit || df_field_filled( $s->value( 'cta' ) );
$cards     = array(); // Each entry: [ list item or null, callable that prints the card inside its <li> ].

if ( ! function_exists( 'df_beitraege_reading_time' ) ) {
	/**
	 * Reading time in minutes (200 words per minute, at least 1).
	 */
	function df_beitraege_reading_time( WP_Post $post ): int {
		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		return max( 1, (int) ceil( $words / 200 ) );
	}
}

if ( 'posts' === $s->opt( 'source' ) ) {
	$query_args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'has_password'        => false,
		'posts_per_page'      => max( 1, $s->count() ),
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	$category   = sanitize_title( (string) $s->opt( 'category' ) );
	if ( '' !== $category ) {
		$query_args['category_name'] = $category;
	}
	$query = new WP_Query( $query_args );
	foreach ( $query->posts as $post ) {
		$cards[] = array( null, static function () use ( $post, $h_item, $show_meta, $can_edit ): void {
			$title = get_the_title( $post );
			$url   = (string) get_permalink( $post );
			$thumb = (int) get_post_thumbnail_id( $post );
			$terms = get_the_category( $post->ID );
			$cat   = $terms ? (string) $terms[0]->name : '';
			echo '<article class="df-beitraege__card">';
			if ( $can_edit ) {
				df_region_open(
					array(
						'provider'     => 'wordpress',
						'label'        => __( 'Post (managed in WordPress)', 'df-editor' ),
						'action_label' => __( 'Edit post', 'df-editor' ),
						'edit_url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
						'class'        => 'df-beitraege__region',
					)
				);
			}
			?>
			<div class="df-frame df-beitraege__media" style="--df-ratio: 16/10">
				<?php
				if ( $thumb > 0 ) {
					echo wp_get_attachment_image( $thumb, 'medium_large', false, array( 'loading' => 'lazy', 'alt' => '' ) );
				} elseif ( class_exists( 'DF_Content' ) && DF_Content::placeholder_id() > 0 ) {
					echo wp_get_attachment_image( DF_Content::placeholder_id(), 'medium_large', false, array( 'loading' => 'lazy', 'alt' => '', 'class' => 'df-img--placeholder' ) );
				}
				if ( '' !== $cat ) {
					echo '<span class="df-beitraege__cat">' . esc_html( $cat ) . '</span>';
				}
				?>
			</div>
			<div class="df-beitraege__body">
				<?php if ( $show_meta ) : ?>
					<p class="df-beitraege__meta df-muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: reading time in minutes, 2: post date */
								__( '%1$d min read · %2$s', 'df-editor' ),
								df_beitraege_reading_time( $post ),
								get_the_date( 'd.m.Y', $post )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-beitraege__title"><?php echo esc_html( $title ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php if ( has_excerpt( $post ) || '' !== trim( (string) $post->post_content ) ) : ?>
					<div class="df-beitraege__text"><p><?php echo esc_html( wp_trim_words( get_the_excerpt( $post ), 24 ) ); ?></p></div>
				<?php endif; ?>
				<p class="df-beitraege__more">
					<a class="df-beitraege__link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Read more', 'df-editor' ); ?><span class="df-sr-only">: <?php echo esc_html( $title ); ?></span></a>
					<?php df_icon( 'arrow-right', array( 'class' => 'df-beitraege__arrow' ) ); ?>
				</p>
			</div>
			<?php
			if ( $can_edit ) {
				df_region_close();
			}
			echo '</article>';
		} );
	}
} else {
	foreach ( $s->items() as $item ) {
		$title = trim( (string) $item->value( 'title' ) );
		// Empty title: hidden for visitors, still shown to editors so it can be filled in again.
		if ( '' === $title && ! $can_edit ) {
			continue;
		}
		$cards[] = array( $item, static function () use ( $item, $title, $h_item, $show_meta, $can_edit ): void {
			?>
			<article class="df-beitraege__card">
				<div class="df-frame df-beitraege__media" style="--df-ratio: <?php echo esc_attr( $item->aspect_ratio( 'image' ) ?: '16/10' ); ?>">
					<?php $item->image( 'image', array( 'size' => 'medium_large', 'loading' => 'lazy' ) ); ?>
					<?php if ( $can_edit || df_field_filled( $item->value( 'category' ) ) ) : ?>
						<span class="df-beitraege__cat"><?php $item->text( 'category' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="df-beitraege__body">
					<?php if ( $show_meta && ( $can_edit || df_field_filled( $item->value( 'meta' ) ) ) ) : ?>
						<p class="df-beitraege__meta df-muted"><?php $item->text( 'meta' ); ?></p>
					<?php endif; ?>
					<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-beitraege__title"><?php $item->text( 'title' ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( $can_edit || df_field_filled( $item->value( 'text' ) ) ) : ?>
						<?php $item->richtext( 'text', array( 'class' => 'df-beitraege__text' ) ); ?>
					<?php endif; ?>
					<?php if ( $can_edit || df_field_filled( $item->value( 'link' ) ) ) : ?>
						<p class="df-beitraege__more">
							<?php
							$item->button(
								'link',
								array(
									'class' => 'df-beitraege__link',
									'after' => '' !== $title ? '<span class="df-sr-only">: ' . esc_html( $title ) . '</span>' : '',
								)
							);
							df_icon( 'arrow-right', array( 'class' => 'df-beitraege__arrow' ) );
							?>
						</p>
					<?php endif; ?>
				</div>
			</article>
			<?php
		} );
	}
}
?>
<div class="df-beitraege__headrow">
	<?php df_section_head( $s, 'beitraege', $h ); ?>
	<?php if ( $show_more ) : ?>
		<p class="df-beitraege__top-more">
			<?php $s->button( 'more', array( 'class' => 'df-beitraege__top-link' ) ); ?>
			<?php df_icon( 'arrow-right', array( 'class' => 'df-beitraege__arrow' ) ); ?>
		</p>
	<?php endif; ?>
</div>

<?php if ( $cards ) : ?>
	<ul class="df-beitraege__grid df-beitraege--cols-<?php echo esc_attr( (string) $s->opt( 'columns' ) ); ?>" role="list">
		<?php
		foreach ( $cards as list( $item, $print_card ) ) :
			// Manual cards carry their list item (editor item marker); post cards have none.
			?>
			<li class="df-beitraege__item"<?php if ( null !== $item ) { $item->attrs(); } ?>><?php $print_card(); ?></li>
		<?php endforeach; ?>
	</ul>
<?php elseif ( $can_edit || $s->is_preview() ) : ?>
	<p class="df-beitraege__empty df-muted"><?php esc_html_e( 'No published posts found. This notice is only visible to editors.', 'df-editor' ); ?></p>
<?php endif; ?>

<?php if ( $show_cta ) : ?>
	<div class="df-actions df-beitraege__actions">
		<?php $s->button( 'cta', array( 'class' => 'df-btn df-btn--secondary' ) ); ?>
	</div>
<?php endif; ?>
