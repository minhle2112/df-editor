<?php
/**
 * Section: Newsletter (sign-up band)
 *
 * Provider (agency setting):
 * - "none": the built-in form is shown but inert (no action, disabled submit); editors see a notice.
 * - "action": the built-in form posts to options.action_url (Brevo, CleverReach, Mailchimp …).
 *   Input names via the filter "df_newsletter_field_names" (name, email, consent).
 * - "shortcode": options.shortcode of a form plugin replaces the built-in form.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h         = df_heading_tag( $s->opt( 'heading_level' ) );
$can_edit  = $s->can_edit();
$staff     = $can_edit || $s->is_preview();
$provider  = (string) $s->opt( 'provider' );
$action    = trim( (string) $s->opt( 'action_url' ) );
$shortcode = trim( (string) $s->opt( 'shortcode' ) );
$show_name = 'yes' === $s->opt( 'show_name' );
$uid       = sanitize_html_class( '' !== $s->id() ? $s->id() : 'df-newsletter' ) . '-nl';

if ( 'shortcode' === $provider && ! preg_match( '/^\[[a-zA-Z0-9_-]+(\s[^\[\]]*)?\]$/', $shortcode ) ) {
	$shortcode = '';
}
$live = ( 'action' === $provider && '' !== $action ) || ( 'shortcode' === $provider && '' !== $shortcode );

/**
 * Filters the input names of the built-in newsletter form (provider "action").
 *
 * @param array      $names name, email, consent.
 * @param DF_Section $s     Section.
 */
$names = wp_parse_args(
	(array) apply_filters( 'df_newsletter_field_names', array(), $s ),
	array(
		'name'    => 'name',
		'email'   => 'email',
		'consent' => 'consent',
	)
);
?>
<div class="df-newsletter__wrap">
	<div class="df-newsletter__intro">
		<?php df_section_head( $s, 'newsletter', $h ); ?>
		<?php if ( $can_edit || df_field_filled( $s->value( 'text' ) ) ) : ?>
			<?php $s->richtext( 'text', array( 'class' => 'df-newsletter__text' ) ); ?>
		<?php endif; ?>
	</div>

	<div class="df-newsletter__formwrap">
		<?php if ( 'shortcode' === $provider && '' !== $shortcode ) : ?>
			<div class="df-newsletter__plugin">
				<?php echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output of the form plugin. ?>
			</div>
		<?php else : ?>
			<form class="df-newsletter__form<?php echo $show_name ? '' : ' df-newsletter__form--email-only'; ?>" method="post"<?php echo $live ? ' action="' . esc_url( $action ) . '"' : ''; ?>>
				<?php if ( $show_name ) : ?>
					<p class="df-newsletter__field">
						<label for="<?php echo esc_attr( $uid . '-name' ); ?>"><?php $s->text( 'name_label' ); ?></label>
						<input id="<?php echo esc_attr( $uid . '-name' ); ?>" name="<?php echo esc_attr( (string) $names['name'] ); ?>" type="text" autocomplete="given-name" placeholder="<?php echo esc_attr( (string) $s->value( 'name_placeholder' ) ); ?>">
					</p>
				<?php endif; ?>
				<p class="df-newsletter__field">
					<label for="<?php echo esc_attr( $uid . '-email' ); ?>"><?php $s->text( 'email_label' ); ?></label>
					<input id="<?php echo esc_attr( $uid . '-email' ); ?>" name="<?php echo esc_attr( (string) $names['email'] ); ?>" type="email" required autocomplete="email" placeholder="<?php echo esc_attr( (string) $s->value( 'email_placeholder' ) ); ?>">
				</p>
				<div class="df-newsletter__check">
					<input id="<?php echo esc_attr( $uid . '-consent' ); ?>" name="<?php echo esc_attr( (string) $names['consent'] ); ?>" type="checkbox" value="1" required aria-labelledby="<?php echo esc_attr( $uid . '-consent-text' ); ?>">
					<div id="<?php echo esc_attr( $uid . '-consent-text' ); ?>" class="df-newsletter__consent">
						<?php $s->richtext( 'consent' ); ?>
					</div>
				</div>
				<button class="df-btn df-btn--primary df-newsletter__submit" type="submit"<?php echo $live ? '' : ' disabled'; ?>><?php $s->text( 'button_text' ); ?></button>
			</form>
			<?php if ( $can_edit ) : ?>
				<?php // DF Inhalt editors: the placeholders are attributes, so they get visible fields here. ?>
				<div class="df-newsletter__edit" hidden data-df-hidden-for-visitors>
					<?php if ( $show_name ) : ?>
						<p><?php esc_html_e( 'Placeholder first name:', 'df-editor' ); ?> <?php $s->text( 'name_placeholder' ); ?></p>
					<?php endif; ?>
					<p><?php esc_html_e( 'Placeholder e-mail:', 'df-editor' ); ?> <?php $s->text( 'email_placeholder' ); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $can_edit || df_field_filled( $s->value( 'note' ) ) ) : ?>
			<p class="df-newsletter__note df-muted"><?php $s->text( 'note' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $live && $staff ) : ?>
			<p class="df-missing df-newsletter__notice" role="note">
				<?php
				if ( 'shortcode' === $provider ) {
					esc_html_e( 'Newsletter: the shortcode option is empty or invalid, so the built-in form is shown without function. This notice is only visible to editors.', 'df-editor' );
				} else {
					esc_html_e( 'Newsletter not connected yet: set the provider (action URL or shortcode) in the section options. This notice is only visible to editors.', 'df-editor' );
				}
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
