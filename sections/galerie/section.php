<?php
/**
 * Section: Galerie
 *
 * Grid (fixed ratio or original), masonry (CSS columns, original ratio), mosaik (first image 2x2,
 * 16:9 tiles) or slider (CSS scroll-snap track, prev/next buttons from view.js).
 * Lightbox: view.js builds one <dialog>; it never opens while DF Inhalt is active.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;
require_once DF_EDITOR_DIR . 'sections/_shared/icons.php'; // df_icon() (slider buttons), also in the editor preview.

$h        = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$can_edit = $s->can_edit();
$variant  = $s->variant();
$masonry  = 'masonry' === $variant;
$mosaik   = 'mosaik' === $variant;
$slider   = 'slider' === $variant;
$ratios   = array(
	'1-1'  => '1/1',
	'4-3'  => '4/3',
	'16-9' => '16/9',
);
$ratio    = $masonry ? '' : ( $mosaik ? '16/9' : ( $ratios[ $s->opt( 'ratio' ) ] ?? '' ) );
if ( $slider && '' === $ratio ) {
	$ratio = '4/3'; // A slider needs equal tile heights.
}
$lightbox = 'yes' === $s->opt( 'lightbox' );
if ( ( $lightbox || $slider ) && class_exists( 'DF_Assets' ) ) {
	DF_Assets::enqueue_section_script( 'galerie' ); // view.js only when this instance needs it.
}
$captions = 'yes' === $s->opt( 'captions' );
$overlay  = $captions && 'overlay' === $s->opt( 'caption_style' );

$eyebrow = trim( (string) $s->value( 'eyebrow' ) );
$title   = trim( (string) $s->value( 'title' ) );
$intro   = trim( wp_strip_all_tags( (string) $s->value( 'intro' ) ) );

$list_classes = array( 'df-galerie__list', 'df-galerie--cols-' . $s->opt( 'columns' ) );
if ( '' === $ratio ) {
	$list_classes[] = 'df-galerie--natural';
}
if ( $overlay ) {
	$list_classes[] = 'df-galerie--caption-overlay';
}
$track_id = $s->id() . '-track';

$i18n = array(
	'dialog' => __( 'Image viewer', 'df-editor' ),
	'close'  => __( 'Close', 'df-editor' ),
	'prev'   => __( 'Previous image', 'df-editor' ),
	'next'   => __( 'Next image', 'df-editor' ),
	/* translators: 1: current image number, 2: total number of images */
	'count'  => __( 'Image %1$d of %2$d', 'df-editor' ),
);
?>
<?php if ( '' !== $eyebrow || '' !== $title || '' !== $intro || $can_edit ) : ?>
	<div class="df-galerie__head">
		<?php if ( '' !== $eyebrow || $can_edit ) : ?>
			<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $title || $can_edit ) : ?>
			<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-galerie__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php endif; ?>
		<?php if ( '' !== $intro || $can_edit ) : ?>
			<?php $s->richtext( 'intro', array( 'class' => 'df-galerie__intro df-muted' ) ); ?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $slider ) : ?>
<div class="df-galerie__wrap" data-df-slider role="group" aria-label="<?php esc_attr_e( 'Gallery', 'df-editor' ); ?>">
<?php endif; ?>
<ul<?php echo $slider ? ' id="' . esc_attr( $track_id ) . '" data-df-slides tabindex="0"' : ''; ?> class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>" role="list"<?php echo $lightbox ? ' data-df-lightbox="' . esc_attr( wp_json_encode( $i18n ) ) . '"' : ''; ?>>
	<?php
	foreach ( $s->items() as $item ) :
		$caption = trim( (string) $item->value( 'caption' ) );
		$img_id  = (int) $item->value( 'image' );
		$full    = $img_id > 0 && wp_attachment_is_image( $img_id ) ? (string) wp_get_attachment_image_url( $img_id, 'full' ) : '';
		?>
		<li class="df-galerie__item"<?php $item->attrs(); ?>>
			<figure class="df-galerie__figure">
				<div class="df-galerie__media<?php echo '' !== $ratio ? ' df-frame' : ''; ?>"<?php echo '' !== $ratio ? ' style="--df-ratio: ' . esc_attr( $ratio ) . '"' : ''; ?>>
					<?php
					$item->image(
						'image',
						array(
							'size'         => $masonry || '' === $ratio || ( $mosaik && 1 === $item->index() ) ? 'large' : 'medium_large',
							'loading'      => 'lazy',
							'aspect_ratio' => $ratio,
							'object_fit'   => '' !== $ratio ? 'cover' : '',
						)
					);
					?>
					<?php if ( $lightbox ) : ?>
						<button type="button" class="df-galerie__zoom" data-df-full="<?php echo esc_url( $full ); ?>" data-df-caption="<?php echo esc_attr( $caption ); ?>">
							<span class="df-sr-only">
								<?php
								echo esc_html(
									'' !== $caption
										/* translators: %s: image caption */
										? sprintf( __( 'Enlarge image: %s', 'df-editor' ), $caption )
										/* translators: %d: image number */
										: sprintf( __( 'Enlarge image %d', 'df-editor' ), $item->index() )
								);
								?>
							</span>
						</button>
					<?php endif; ?>
				</div>
				<?php if ( $captions && ( '' !== $caption || $can_edit ) ) : ?>
					<figcaption class="df-galerie__caption<?php echo $overlay ? ' df-galerie__caption--overlay' : ' df-muted'; ?>"><?php $item->text( 'caption' ); ?></figcaption>
				<?php endif; ?>
			</figure>
		</li>
	<?php endforeach; ?>
</ul>
<?php if ( $slider ) : ?>
	<?php if ( $s->count() > 1 ) : ?>
		<div class="df-galerie__controls" hidden>
			<button type="button" class="df-galerie__nav" aria-controls="<?php echo esc_attr( $track_id ); ?>" data-df-dir="-1">
				<?php df_icon( 'arrow-left' ); ?>
				<span class="df-sr-only"><?php esc_html_e( 'Previous images', 'df-editor' ); ?></span>
			</button>
			<button type="button" class="df-galerie__nav" aria-controls="<?php echo esc_attr( $track_id ); ?>" data-df-dir="1">
				<?php df_icon( 'arrow-right' ); ?>
				<span class="df-sr-only"><?php esc_html_e( 'Next images', 'df-editor' ); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>
