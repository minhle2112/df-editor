<?php
/**
 * Section: Termin (event highlight)
 *
 * Variant "feature": text with date, buttons and note next to an image (with caption).
 * Variant "card": compact card with icon tile or small image, text, a text link and key facts as pills.
 * The key facts (list "facts", 0–4) are shown in both variants when present.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$card     = 'card' === $s->variant();
$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$buttons  = (int) $s->opt( 'buttons' );
$can_edit = $s->can_edit();

// Optional fields: empty = not printed for visitors (editors still see them to fill them in).
$show = array();
foreach ( array( 'eyebrow', 'date', 'text', 'cta1', 'cta2', 'note', 'caption' ) as $field ) {
	$show[ $field ] = $can_edit || df_field_filled( $s->value( $field ) );
}
$show['cta1'] = $show['cta1'] && $buttons > 0;
$show['cta2'] = $show['cta2'] && $buttons > 1;

// Key facts (pills): empty labels are hidden for visitors.
$facts = array();
foreach ( $s->items() as $item ) {
	if ( $can_edit || df_field_filled( $item->value( 'label' ) ) ) {
		$facts[] = $item;
	}
}

$print_facts = static function () use ( $facts ): void {
	if ( ! $facts ) {
		return;
	}
	echo '<ul class="df-termin__facts" role="list">';
	foreach ( $facts as $item ) {
		echo '<li class="df-termin__fact"';
		$item->attrs();
		echo '>';
		$item->text( 'label' );
		echo '</li>';
	}
	echo '</ul>';
};

$print_text = static function () use ( $s, $h, $show, $card, $print_facts ): void {
	if ( $show['eyebrow'] ) {
		echo '<p class="df-eyebrow df-termin__eyebrow">';
		$s->text( 'eyebrow' );
		echo '</p>';
	}
	printf( '<%s class="df-termin__title df-title">', $h ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
	$s->text( 'title' );
	echo '</' . $h . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
	if ( $show['date'] ) {
		echo '<p class="df-termin__date">';
		$s->text( 'date' );
		echo '</p>';
	}
	if ( $show['text'] ) {
		$s->richtext( 'text', array( 'class' => 'df-termin__lead' . ( $card ? '' : ' df-lead' ) ) );
	}
	if ( ! $card ) {
		$print_facts();
	}
	if ( $show['cta1'] || $show['cta2'] ) {
		if ( $card ) {
			// Card: the buttons are text links with an arrow.
			echo '<div class="df-termin__links">';
			foreach ( array( 'cta1', 'cta2' ) as $cta ) {
				if ( $show[ $cta ] ) {
					echo '<p class="df-termin__more">';
					$s->button( $cta, array( 'class' => 'df-termin__link' ) );
					df_icon( 'arrow-right', array( 'class' => 'df-termin__arrow' ) );
					echo '</p>';
				}
			}
			echo '</div>';
		} else {
			echo '<div class="df-actions">';
			if ( $show['cta1'] ) {
				$s->button( 'cta1', array( 'class' => 'df-btn df-btn--primary' ) );
			}
			if ( $show['cta2'] ) {
				$s->button( 'cta2', array( 'class' => 'df-btn df-btn--secondary' ) );
			}
			echo '</div>';
		}
	}
	if ( $show['note'] ) {
		echo '<p class="df-termin__note df-muted">';
		$s->text( 'note' );
		echo '</p>';
	}
};

if ( $card ) :
	$marker = (string) $s->opt( 'marker' );
	?>
	<article class="df-termin__card<?php echo 'none' === $marker ? '' : ' df-termin__card--marker'; ?>">
		<?php if ( 'icon' === $marker ) : ?>
			<div class="df-termin__marker"><span class="df-termin__icon"><?php df_icon( (string) $s->opt( 'icon' ) ); ?></span></div>
		<?php elseif ( 'image' === $marker ) : ?>
			<div class="df-termin__marker">
				<div class="df-frame df-termin__thumb" style="--df-ratio: 1/1">
					<?php $s->image( 'image', array( 'size' => 'medium', 'loading' => 'lazy' ) ); ?>
				</div>
			</div>
		<?php endif; ?>
		<div class="df-termin__body">
			<?php $print_text(); ?>
		</div>
		<?php $print_facts(); ?>
	</article>
	<?php
else :
	$frame = array( 'df-frame', 'df-termin__frame' );
	if ( 'plain' !== $s->opt( 'image_style' ) ) {
		$frame[] = 'df-frame--' . $s->opt( 'image_style' );
	}
	?>
	<div class="df-termin__grid df-termin--img-<?php echo esc_attr( (string) $s->opt( 'image_side' ) ); ?>">
		<div class="df-termin__text">
			<?php $print_text(); ?>
		</div>
		<figure class="df-termin__media">
			<div class="<?php echo esc_attr( implode( ' ', $frame ) ); ?>" style="--df-ratio: <?php echo esc_attr( $s->aspect_ratio( 'image' ) ?: '4/3' ); ?>">
				<?php $s->image( 'image', array( 'size' => 'large', 'loading' => 'lazy' ) ); ?>
			</div>
			<?php if ( $show['caption'] ) : ?>
				<figcaption class="df-termin__caption df-muted"><?php $s->text( 'caption' ); ?></figcaption>
			<?php endif; ?>
		</figure>
	</div>
	<?php
endif;
