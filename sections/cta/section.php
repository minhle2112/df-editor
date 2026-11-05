<?php
/**
 * Section: Call to Action
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$variant  = $s->variant();
$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$buttons  = (int) $s->opt( 'buttons' );
$can_edit = $s->can_edit();
// phone_source "global" = site-wide global.contact.phone (stays in sync with footer/contact box).
$phone_global = 'own' !== $s->opt( 'phone_source' );
$phone_value  = $phone_global ? df_get( 'global.contact.phone' ) : $s->value( 'phone' );
$phone_on     = 'contact' === $variant && ( $can_edit || $phone_global || df_field_filled( $phone_value ) );
?>
<div class="df-cta__grid">
	<?php if ( 'contact' === $variant ) : ?>
		<div class="df-frame df-cta__photo" style="--df-ratio: <?php echo esc_attr( $s->aspect_ratio( 'image' ) ?: '1/1' ); ?>">
			<?php $s->image( 'image', array( 'size' => 'medium', 'loading' => 'lazy' ) ); ?>
		</div>
	<?php endif; ?>

	<div class="df-cta__text">
		<?php if ( $can_edit || df_field_filled( $s->value( 'eyebrow' ) ) ) : ?>
			<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
		<?php endif; ?>
		<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-cta__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php $s->richtext( 'text', array( 'class' => 'df-cta__body df-lead' ) ); ?>
	</div>

	<?php if ( $buttons > 0 || $phone_on ) : ?>
		<div class="df-cta__actions df-actions">
			<?php if ( $phone_on ) : ?>
				<p class="df-cta__phone">
					<?php df_icon( 'phone', array( 'class' => 'df-cta__phone-icon' ) ); ?>
					<?php
					if ( $phone_global ) {
						$phone_args          = (array) apply_filters(
							'df_contact_field_args',
							array(
								'label'      => __( 'Phone', 'df-editor' ),
								'default'    => array(
									'text' => '0221 987 654 0',
									'url'  => 'tel:+492219876540',
								),
								'max_length' => 40,
							),
							'phone'
						);
						$phone_args['class'] = 'df-cta__phone-link';
						df_button( 'global.contact.phone', $phone_args );
					} else {
						$s->button( 'phone', array( 'class' => 'df-cta__phone-link' ) );
					}
					?>
				</p>
			<?php endif; ?>
			<?php if ( $buttons > 0 ) : ?>
				<?php $s->button( 'cta1', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
			<?php endif; ?>
			<?php if ( $buttons > 1 ) : ?>
				<?php $s->button( 'cta2', array( 'class' => 'df-btn df-btn--secondary' ) ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
