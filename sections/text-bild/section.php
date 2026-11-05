<?php
/**
 * Section: Text & Bild
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h        = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$can_edit = $s->can_edit();
$show_cta  = 'yes' === $s->opt( 'show_button' ) && ( $can_edit || df_field_filled( $s->value( 'cta' ) ) );
$show_cta2 = 'yes' === $s->opt( 'show_button' ) && ( $can_edit || df_field_filled( $s->value( 'cta2' ) ) );
$show_note = $can_edit || df_field_filled( $s->value( 'note' ) );
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
<?php if ( $can_edit || df_field_filled( $s->value( 'eyebrow' ) ) ) : ?>
		<p class="df-eyebrow<?php echo 'pill' === $s->opt( 'eyebrow_style' ) ? ' df-eyebrow--pill' : ''; ?>"><?php $s->text( 'eyebrow' ); ?></p>
<?php endif; ?>
		<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-text-bild__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php $s->richtext( 'text', array( 'class' => 'df-text-bild__body' ) ); ?>
		<?php if ( $show_cta || $show_cta2 ) : ?>
			<div class="df-actions">
				<?php if ( $show_cta ) : ?>
					<?php $s->button( 'cta', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
				<?php endif; ?>
				<?php if ( $show_cta2 ) : ?>
					<?php $s->button( 'cta2', array( 'class' => 'df-btn df-btn--secondary' ) ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $show_note ) : ?>
			<p class="df-text-bild__note df-muted"><?php $s->text( 'note' ); ?></p>
		<?php endif; ?>
	</div>
</div>
