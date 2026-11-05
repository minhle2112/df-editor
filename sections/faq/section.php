<?php
/**
 * Section: FAQ / Akkordeon
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

$h         = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$can_edit  = $s->can_edit();
$open_1st  = 'yes' === $s->opt( 'open_first' );
$schema    = array();
$items_out = array();
$intro     = trim( wp_strip_all_tags( (string) $s->value( 'intro' ) ) );

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
<div class="df-faq__head">
	<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
	<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-faq__title df-title"><?php $s->text( 'title' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $intro || $can_edit ) : ?>
		<?php $s->richtext( 'intro', array( 'class' => 'df-faq__intro df-muted' ) ); ?>
	<?php endif; ?>
</div>

<div class="df-faq__list"<?php echo 'yes' === $s->opt( 'single_open' ) ? ' data-df-single-open' : ''; ?>>
	<?php foreach ( $items_out as $i => $item ) : ?>
		<details class="df-faq__item"<?php echo ( 0 === $i && $open_1st ) ? ' open' : ''; ?>>
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
