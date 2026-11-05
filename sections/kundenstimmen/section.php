<?php
/**
 * Section: Kundenstimmen (testimonials)
 *
 * Source "manual": DF Inhalt list items. Source "cpt": posts of type df_referenz
 * (title = name, content = quote, meta _df_company / _df_rating, featured image = photo),
 * printed as read-only "managed regions" that link to the WordPress editor.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

if ( ! function_exists( 'df_kundenstimmen_stars' ) ) {
	/**
	 * Star rating: decorative SVG stars + visually hidden text ("4 out of 5 stars"). 0 = nothing.
	 *
	 * @param int $rating 0–5.
	 */
	function df_kundenstimmen_stars( int $rating ): void {
		$rating = max( 0, min( 5, $rating ) );
		if ( 0 === $rating ) {
			return;
		}
		echo '<p class="df-kundenstimmen__stars">';
		for ( $i = 1; $i <= 5; $i++ ) {
			df_icon( 'star', array( 'class' => $i <= $rating ? 'df-kundenstimmen__star df-kundenstimmen__star--on' : 'df-kundenstimmen__star' ) );
		}
		/* translators: %d: number of stars (1-5) */
		echo '<span class="df-sr-only">' . esc_html( sprintf( _n( '%d out of 5 stars', '%d out of 5 stars', $rating, 'df-editor' ), $rating ) ) . '</span>';
		echo '</p>';
	}
}

$variant  = $s->variant();
$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$can_edit = $s->can_edit();
$photos   = 'yes' === $s->opt( 'photos' );
$slider   = 'slider' === $variant;
$max      = 'single' === $variant ? 1 : $s->count();
$cards    = array(); // Each entry: callable that prints one card (keeps the wrapper markup in one place).

if ( 'cpt' === $s->opt( 'source' ) ) {
	$query = new WP_Query(
		array(
			'post_type'              => 'df_referenz',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $max,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		)
	);
	$posts = ( class_exists( 'DF_CPT' ) && DF_CPT::is_enabled( 'df_referenz' ) ) ? $query->posts : array();
	foreach ( $posts as $post ) {
		$cards[] = static function () use ( $post, $photos ) {
			$company = (string) get_post_meta( $post->ID, '_df_company', true );
			$rating  = (int) get_post_meta( $post->ID, '_df_rating', true );
			$quote   = wp_kses( wpautop( do_blocks( (string) $post->post_content ) ), DF_Content::richtext_tags() );
			$thumb   = $photos ? (int) get_post_thumbnail_id( $post ) : 0;

			df_region_open(
				array(
					'provider'     => 'wordpress',
					'label'        => __( 'Testimonial (managed in WordPress)', 'df-editor' ),
					'action_label' => __( 'Edit testimonial', 'df-editor' ),
					'edit_url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'class'        => 'df-kundenstimmen__item',
				)
			);
			?>
			<figure class="df-kundenstimmen__card">
				<?php df_kundenstimmen_stars( $rating ); ?>
				<blockquote class="df-kundenstimmen__quote">
					<div class="df-kundenstimmen__quote-text"><?php echo $quote; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses above. ?></div>
				</blockquote>
				<figcaption class="df-kundenstimmen__author">
					<?php if ( $thumb > 0 ) : ?>
						<span class="df-frame df-kundenstimmen__photo" style="--df-ratio: 1/1">
							<?php echo wp_get_attachment_image( $thumb, 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '' ) ); ?>
						</span>
					<?php endif; ?>
					<span class="df-kundenstimmen__meta">
						<span class="df-kundenstimmen__name"><?php echo esc_html( get_the_title( $post ) ); ?></span>
						<?php if ( '' !== trim( $company ) ) : ?>
							<span class="df-kundenstimmen__company"><?php echo esc_html( $company ); ?></span>
						<?php endif; ?>
					</span>
				</figcaption>
			</figure>
			<?php
			df_region_close();
		};
	}
} else {
	foreach ( array_slice( $s->items(), 0, $max ) as $item ) {
		// Empty quote: hidden for visitors, still shown to editors so it can be filled in again.
		if ( ! $can_edit && ! df_field_filled( $item->value( 'quote' ) ) ) {
			continue;
		}
		$rating  = (int) df_item_setting( $s, $item->index(), 'rating', 0 );
		$cards[] = static function () use ( $item, $rating, $photos, $can_edit ) {
			?>
			<div class="df-kundenstimmen__item">
				<figure class="df-kundenstimmen__card">
					<?php df_kundenstimmen_stars( $rating ); ?>
					<blockquote class="df-kundenstimmen__quote">
						<?php $item->richtext( 'quote', array( 'class' => 'df-kundenstimmen__quote-text' ) ); ?>
					</blockquote>
					<figcaption class="df-kundenstimmen__author">
						<?php if ( $photos ) : ?>
							<span class="df-frame df-kundenstimmen__photo" style="--df-ratio: <?php echo esc_attr( $item->aspect_ratio( 'image' ) ?: '1/1' ); ?>">
								<?php $item->image( 'image', array( 'size' => 'thumbnail', 'loading' => 'lazy' ) ); ?>
							</span>
						<?php endif; ?>
						<span class="df-kundenstimmen__meta">
							<span class="df-kundenstimmen__name"><?php $item->text( 'name' ); ?></span>
							<?php if ( $can_edit || df_field_filled( $item->value( 'company' ) ) ) : ?>
								<span class="df-kundenstimmen__company"><?php $item->text( 'company' ); ?></span>
							<?php endif; ?>
						</span>
					</figcaption>
				</figure>
			</div>
			<?php
		};
	}
}

if ( ! $cards && ! $can_edit ) {
	return; // Nothing to show for visitors (heading alone would be empty).
}

df_section_head( $s, 'kundenstimmen', $h );

if ( ! $cards ) {
	echo '<p class="df-kundenstimmen__empty df-muted">' . esc_html__( 'No published testimonials found (post type df_referenz). This notice is only visible to editors.', 'df-editor' ) . '</p>';
	return;
}

$track_id = $s->id() . '-track';
if ( $slider && class_exists( 'DF_Assets' ) ) {
	DF_Assets::enqueue_section_script( 'kundenstimmen' ); // view.js only when this instance needs it.
}
?>
<div class="df-kundenstimmen__wrap"<?php echo $slider ? ' data-df-slider' : ''; ?>>
	<div
		id="<?php echo esc_attr( $track_id ); ?>"
		class="df-kundenstimmen__track"
		<?php if ( $slider ) : ?>
			data-df-slides
			tabindex="0"
			role="group"
			aria-label="<?php esc_attr_e( 'Testimonials', 'df-editor' ); ?>"
		<?php endif; ?>
	>
		<?php
		foreach ( $cards as $card ) {
			$card();
		}
		?>
	</div>
	<?php if ( $slider && count( $cards ) > 1 ) : ?>
		<div class="df-kundenstimmen__controls" hidden>
			<button type="button" class="df-kundenstimmen__nav df-kundenstimmen__nav--prev" aria-controls="<?php echo esc_attr( $track_id ); ?>" data-df-dir="-1">
				<?php df_icon( 'arrow-left' ); ?>
				<span class="df-sr-only"><?php esc_html_e( 'Previous testimonials', 'df-editor' ); ?></span>
			</button>
			<button type="button" class="df-kundenstimmen__nav df-kundenstimmen__nav--next" aria-controls="<?php echo esc_attr( $track_id ); ?>" data-df-dir="1">
				<?php df_icon( 'arrow-right' ); ?>
				<span class="df-sr-only"><?php esc_html_e( 'Next testimonials', 'df-editor' ); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>
