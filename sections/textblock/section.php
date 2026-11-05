<?php
/**
 * Section: Textblock
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$can_edit = $s->can_edit();
$align    = 'center' === $s->opt( 'align' ) ? 'center' : 'left';
?>
<div class="df-textblock__body df-textblock--align-<?php echo esc_attr( $align ); ?>">
	<?php if ( $can_edit || df_field_filled( $s->value( 'eyebrow' ) ) ) : ?>
		<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
	<?php endif; ?>
	<?php if ( $can_edit || df_field_filled( $s->value( 'title' ) ) ) : ?>
		<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-textblock__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php endif; ?>
	<?php $s->richtext( 'text', array( 'class' => 'df-textblock__text' ) ); ?>
</div>
