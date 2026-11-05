<?php
/**
 * Section: Hero
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

$variant = $s->variant();
$h       = 'h2' === $s->opt( 'heading_level' ) ? 'h2' : 'h1';
$buttons = (int) $s->opt( 'buttons' );
$classes = array( 'df-hero__grid', 'df-hero--h-' . $s->opt( 'height' ) );
if ( 'split' === $variant ) {
	$classes[] = 'df-hero--img-' . $s->opt( 'image_side' );
}
?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
	<?php if ( 'overlay' === $variant ) : ?>
		<div class="df-hero__bg">
			<?php $s->image( 'image', array( 'size' => 'full', 'fetchpriority' => 'high', 'class' => 'df-hero__bg-img', 'aspect_ratio' => '' ) ); ?>
		</div>
	<?php endif; ?>

	<div class="df-hero__text">
		<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
		<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted h1/h2 ?> class="df-hero__title df-display"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php $s->richtext( 'text', array( 'class' => 'df-hero__lead df-lead' ) ); ?>
		<?php if ( $buttons > 0 ) : ?>
			<div class="df-actions">
				<?php $s->button( 'cta1', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
				<?php if ( $buttons > 1 ) : ?>
					<?php $s->button( 'cta2', array( 'class' => 'df-btn df-btn--secondary' ) ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 'split' === $variant ) : ?>
		<div class="df-frame df-hero__media" style="--df-ratio: <?php echo esc_attr( $s->aspect_ratio( 'image' ) ?: '4/3' ); ?>">
			<?php $s->image( 'image', array( 'size' => 'large', 'fetchpriority' => 'high' ) ); ?>
		</div>
	<?php endif; ?>
</div>
