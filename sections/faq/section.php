<?php
/**
 * Section: FAQ / Akkordeon
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h         = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$can_edit  = $s->can_edit();
$open_1st  = 'yes' === $s->opt( 'open_first' );
$schema    = array();
$items_out = array();
$intro     = trim( wp_strip_all_tags( (string) $s->value( 'intro' ) ) );
$split     = 'split' === $s->variant();
$center    = ! $split && 'center' === $s->opt( 'head_align' );
$style     = 'cards' === $s->opt( 'style' ) ? 'cards' : 'lines';
$show_more = $can_edit || df_field_filled( $s->value( 'more' ) );
// Contact card: shown when it has a title or text (editors always see it to fill it in).
$show_card = $can_edit || df_field_filled( $s->value( 'contact_title' ) ) || df_field_filled( $s->value( 'contact_text' ) );
$show_ccta = $can_edit || df_field_filled( $s->value( 'contact_cta' ) );
$card      = static function () use ( $s, $can_edit, $show_ccta ) {
	?>
	<div class="df-faq__card">
		<?php if ( $s->has_image( 'contact_image' ) ) : ?>
			<div class="df-faq__card-media"><?php $s->image( 'contact_image', array( 'size' => 'thumbnail', 'aspect_ratio' => '1/1', 'object_fit' => 'cover' ) ); ?></div>
		<?php endif; ?>
		<div class="df-faq__card-body">
			<?php if ( $can_edit || df_field_filled( $s->value( 'contact_title' ) ) ) : ?>
				<p class="df-faq__card-title"><?php $s->text( 'contact_title' ); ?></p>
			<?php endif; ?>
			<?php if ( $can_edit || df_field_filled( $s->value( 'contact_text' ) ) ) : ?>
				<?php $s->richtext( 'contact_text', array( 'class' => 'df-faq__card-text df-muted' ) ); ?>
			<?php endif; ?>
		</div>
		<?php if ( $show_ccta ) : ?>
			<div class="df-actions df-faq__card-actions"><?php $s->button( 'contact_cta', array( 'class' => 'df-btn df-btn--primary' ) ); ?></div>
		<?php endif; ?>
	</div>
	<?php
};
$layout_classes = array( 'df-faq__layout', 'df-faq--style-' . $style );
if ( $center ) {
	$layout_classes[] = 'df-faq--center';
}

foreach ( $s->items() as $item ) {
	$question = trim( (string) $item->value( 'question' ) );
	// Empty question: hidden for visitors, still shown to editors so it can be filled in again.
	if ( '' === $question && ! $can_edit ) {
		continue;
	}
	$items_out[] = $item;
	if ( '' !== $question ) {
		$schema[] = array(
			'@type'          => 'Question',
			'name'           => $question,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => wp_kses( (string) $item->value( 'answer' ), DF_Content::richtext_tags() ),
			),
		);
	}
}
?>
<div class="<?php echo esc_attr( implode( ' ', $layout_classes ) ); ?>">
<div class="df-faq__head">
<?php if ( $can_edit || df_field_filled( $s->value( 'eyebrow' ) ) ) : ?>
	<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
<?php endif; ?>
	<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-faq__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $intro || $can_edit ) : ?>
		<?php $s->richtext( 'intro', array( 'class' => 'df-faq__intro df-muted' ) ); ?>
	<?php endif; ?>
	<?php if ( 'yes' === $s->opt( 'divider' ) ) : ?>
		<span class="df-faq__divider" aria-hidden="true"></span>
	<?php endif; ?>
	<?php
	if ( $split && $show_card ) {
		$card();
	}
	?>
</div>
<div class="df-faq__main">

<div class="df-faq__list"<?php echo 'yes' === $s->opt( 'single_open' ) ? ' data-df-single-open' : ''; ?>>
	<?php foreach ( $items_out as $i => $item ) : ?>
		<details class="df-faq__item"<?php echo ( 0 === $i && $open_1st ) ? ' open' : ''; ?><?php $item->attrs(); ?>>
			<summary class="df-faq__question">
				<span class="df-faq__question-text"><?php $item->text( 'question' ); ?></span>
				<span class="df-faq__icon" aria-hidden="true"></span>
			</summary>
			<div class="df-faq__answer">
				<?php $item->richtext( 'answer' ); ?>
			</div>
		</details>
	<?php endforeach; ?>
</div>
<?php if ( $show_more ) : ?>
	<?php $s->richtext( 'more', array( 'class' => 'df-faq__more df-muted' ) ); ?>
<?php endif; ?>
<?php
if ( ! $split && $show_card ) {
	$card();
}
?>
</div>
</div>
<?php
if ( 'yes' === $s->opt( 'schema' ) && $schema ) {
	printf(
		'<script type="application/ld+json">%s</script>',
		wp_json_encode(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $schema,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		)
	);
}
