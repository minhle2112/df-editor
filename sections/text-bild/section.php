<?php
/**
 * Section: Text & Bild
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

$h     = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$frame = array( 'df-frame', 'df-text-bild__media' );
if ( 'plain' !== $s->opt( 'image_style' ) ) {
	$frame[] = 'df-frame--' . $s->opt( 'image_style' );
}
?>
<div class="df-text-bild__grid df-text-bild--r-<?php echo esc_attr( $s->opt( 'ratio' ) ); ?>">
	<div class="<?php echo esc_attr( implode( ' ', $frame ) ); ?>" style="--df-ratio: <?php echo esc_attr( $s->aspect_ratio( 'image' ) ?: '4/3' ); ?>">
		<?php $s->image( 'image', array( 'size' => 'large', 'loading' => 'lazy' ) ); ?>
	</div>
	<div class="df-text-bild__text">
		<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
		<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-text-bild__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php $s->richtext( 'text', array( 'class' => 'df-text-bild__body' ) ); ?>
		<?php if ( 'yes' === $s->opt( 'show_button' ) ) : ?>
			<div class="df-actions">
				<?php $s->button( 'cta', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
