<?php
/**
 * Section: Formular
 *
 * Heading/text are DF Inhalt fields; the form itself belongs to Contact Form 7 or Fluent Forms
 * (via DF_Forms) and is marked as a managed region. Variant "with-contact" adds the site-wide
 * contact fields global.contact.* (same IDs, labels and defaults as the theme footer).
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

$h        = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$h_side   = 'h3' === $h ? 'h4' : 'h3';
$can_edit = $s->can_edit();
$is_staff = current_user_can( 'edit_pages' );
$with     = 'with-contact' === $s->variant();

$plugin  = class_exists( 'DF_Forms' ) ? DF_Forms::resolve_plugin( (string) $s->opt( 'form_plugin' ) ) : '';
$form_id = '' !== $plugin ? DF_Forms::resolve_id( $plugin, (string) $s->opt( 'form_id' ) ) : 0;
$form    = $form_id > 0 ? DF_Forms::render( $plugin, $form_id ) : '';
$text    = trim( wp_strip_all_tags( (string) $s->value( 'text' ) ) );

if ( '' === trim( $form ) && ! $is_staff && ! $with ) {
	return; // No form and no contact box: a heading alone would be empty for visitors.
}
?>
<div class="df-formular__grid">
	<div class="df-formular__main">
		<div class="df-formular__head">
			<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
			<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-formular__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( '' !== $text || $can_edit ) : ?>
				<?php $s->richtext( 'text', array( 'class' => 'df-formular__text' ) ); ?>
			<?php endif; ?>
		</div>

		<?php if ( '' !== trim( $form ) ) : ?>
			<?php
			df_region_open(
				array(
					'provider' => $plugin,
					'label'    => sprintf( /* translators: %s: form plugin name */ __( 'Form – edit in %s', 'df-editor' ), DF_Forms::plugin_name( $plugin ) ),
					'edit_url' => DF_Forms::edit_url( $plugin, $form_id ),
					'class'    => 'df-formular__form df-formular__form--' . $plugin,
				)
			);
			echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output of the form plugin.
			df_region_close();
			?>
		<?php elseif ( $is_staff ) : ?>
			<div class="df-missing" role="note">
				<?php
				if ( '' === $plugin ) {
					esc_html_e( 'No form plugin found. Install and activate Contact Form 7 or Fluent Forms, then create a form.', 'df-editor' );
				} else {
					printf(
						'%1$s <a href="%2$s">%3$s</a>',
						esc_html(
							sprintf(
								/* translators: 1: form plugin name, 2: form ID from the layout */
								__( '%1$s: form "%2$s" not found. Set options.form_id in the layout (empty = first form).', 'df-editor' ),
								DF_Forms::plugin_name( $plugin ),
								(string) $s->opt( 'form_id' )
							)
						),
						esc_url( DF_Forms::edit_url( $plugin ) ),
						esc_html__( 'Open forms', 'df-editor' )
					);
				}
				?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $with ) : ?>
		<aside class="df-formular__side" aria-labelledby="<?php echo esc_attr( $s->id() . '-contact' ); ?>">
			<<?php echo $h_side; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> id="<?php echo esc_attr( $s->id() . '-contact' ); ?>" class="df-formular__side-title"><?php $s->text( 'contact_title' ); ?></<?php echo $h_side; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<dl class="df-formular__contact">
				<?php
				// Fallback args only; the theme answers df_contact_field_args (CONVENTIONS §3) so the
				// footer and this box register one schema per global.contact.* ID.
				$contact_rows = array(
					'phone'   => array(
						'type' => 'button',
						'dt'   => __( 'Phone', 'df-editor' ),
						'args' => array(
							'label'      => __( 'Phone', 'df-editor' ),
							'default'    => array(
								'text' => '0221 987 654 0',
								'url'  => 'tel:+492219876540',
							),
							'max_length' => 40,
						),
					),
					'email'   => array(
						'type' => 'button',
						'dt'   => __( 'Email', 'df-editor' ),
						'args' => array(
							'label'      => __( 'Email', 'df-editor' ),
							'default'    => array(
								'text' => 'info@muster-haustechnik.de',
								'url'  => 'mailto:info@muster-haustechnik.de',
							),
							'max_length' => 80,
						),
					),
					'address' => array(
						'type' => 'text',
						'dt'   => __( 'Address', 'df-editor' ),
						'args' => array(
							'label'      => __( 'Address', 'df-editor' ),
							'default'    => 'Musterstraße 12, 50667 Köln',
							'max_length' => 120,
						),
					),
					'hours'   => array(
						'type' => 'text',
						'dt'   => __( 'Opening hours', 'df-editor' ),
						'args' => array(
							'label'      => __( 'Opening hours', 'df-editor' ),
							'default'    => 'Mo–Fr 7:30–17:00 Uhr',
							'max_length' => 80,
						),
					),
				);
				foreach ( $contact_rows as $contact_key => $row ) :
					$args = (array) apply_filters( 'df_contact_field_args', $row['args'], $contact_key );
					?>
					<div class="df-formular__row">
						<dt><?php echo esc_html( $row['dt'] ); ?></dt>
						<dd>
							<?php
							if ( 'button' === $row['type'] ) {
								$args['class'] = 'df-formular__link';
								df_button( 'global.contact.' . $contact_key, $args );
							} else {
								df_text( 'global.contact.' . $contact_key, $args );
							}
							?>
						</dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</aside>
	<?php endif; ?>
</div>
