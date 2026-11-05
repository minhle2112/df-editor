<?php
/**
 * Section: Kacheln (tile grid)
 *
 * Small cards (link tiles, process steps, facts, values) in 2–5 columns, optionally next to a
 * text column (variant "split"). Markers: icon, step number (list becomes <ol>) or arrow circle.
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$split    = 'split' === $s->variant();
$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$h_item   = 'h2' === $h ? 'h3' : 'h4';
$can_edit = $s->can_edit();
$marker   = (string) $s->opt( 'marker' );
$linked   = 'yes' === $s->opt( 'card_link' );
$center   = ! $split && 'center' === $s->opt( 'head_align' );
$fallback = array( 'star', 'heart', 'leaf', 'check', 'target', 'eye', 'gem', 'moon', 'users', 'calendar', 'shield', 'home' );

// Optional fields: empty = not printed for visitors (editors still see them to fill them in).
$show = array();
foreach ( array( 'eyebrow', 'title', 'title_accent', 'intro', 'text', 'cta' ) as $field ) {
	$show[ $field ] = $can_edit || df_field_filled( $s->value( $field ) );
}
$has_head = $show['eyebrow'] || $show['title'] || $show['intro'] || $show['text'] || ( $split && $show['cta'] );

$list_tag     = 'number' === $marker ? 'ol' : 'ul';
$grid_classes = array(
	'df-kacheln__grid',
	'df-kacheln--cols-' . $s->opt( 'columns' ),
	'df-kacheln--' . $s->opt( 'card_style' ),
	'df-kacheln--marker-' . $marker,
);
$head_classes = array( 'df-kacheln__head' );
if ( $center ) {
	$head_classes[] = 'df-kacheln__head--center';
}
?>
<div class="df-kacheln__wrap<?php echo $split ? ' df-kacheln__wrap--split' : ''; ?>">
	<?php if ( $has_head ) : ?>
		<div class="<?php echo esc_attr( implode( ' ', $head_classes ) ); ?>">
			<?php if ( $show['eyebrow'] ) : ?>
				<p class="df-eyebrow"><?php $s->text( 'eyebrow' ); ?></p>
			<?php endif; ?>
			<?php if ( $show['title'] ) : ?>
				<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-kacheln__heading df-title"><?php $s->text( 'title' ); ?><?php if ( $show['title_accent'] ) : ?> <span class="df-kacheln__accent"><?php $s->text( 'title_accent' ); ?></span><?php endif; ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php endif; ?>
			<?php if ( 'yes' === $s->opt( 'divider' ) ) : ?>
				<span class="df-kacheln__divider" aria-hidden="true"></span>
			<?php endif; ?>
			<?php if ( $show['intro'] ) : ?>
				<?php $s->richtext( 'intro', array( 'class' => 'df-kacheln__intro df-muted' ) ); ?>
			<?php endif; ?>
			<?php if ( $show['text'] ) : ?>
				<?php $s->richtext( 'text', array( 'class' => 'df-kacheln__body' ) ); ?>
			<?php endif; ?>
			<?php if ( $split && $show['cta'] ) : ?>
				<div class="df-actions">
					<?php $s->button( 'cta', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<<?php echo $list_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ul/ol ?> class="<?php echo esc_attr( implode( ' ', $grid_classes ) ); ?>" role="list">
		<?php
		$items  = $s->items();
		$number = 0;
		for ( $n = 1; $n <= $s->count(); $n++ ) :
			$item  = $items[ $n - 1 ];
			$title = trim( (string) $item->value( 'title' ) );
			// Empty title: hidden for visitors, still shown to editors so it can be filled in again.
			if ( '' === $title && ! $can_edit ) {
				continue;
			}
			++$number;
			$link_on = $can_edit || df_field_filled( $item->value( 'link' ) );
			$classes = array( 'df-kacheln__item' );
			if ( 1 === $number && 'yes' === $s->opt( 'highlight_first' ) ) {
				$classes[] = 'is-highlight';
			}
			if ( $linked && $link_on ) {
				$classes[] = 'df-kacheln__item--linked';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php $item->attrs(); ?>>
				<?php
				if ( 'number' === $marker ) {
					printf( '<span class="df-kacheln__marker df-kacheln__marker--number" aria-hidden="true">%d</span>', (int) $number );
				} elseif ( 'arrow' === $marker ) {
					echo '<span class="df-kacheln__marker df-kacheln__marker--arrow">';
					df_icon( 'arrow-right' );
					echo '</span>';
				} elseif ( 'icon' === $marker ) {
					$icon = (string) df_item_setting( $s, $n, 'icon', $fallback[ ( $n - 1 ) % count( $fallback ) ] );
					if ( 'none' !== $icon ) {
						echo '<span class="df-kacheln__marker df-kacheln__marker--icon">';
						df_icon( $icon );
						echo '</span>';
					}
				}
				?>
				<?php if ( $can_edit || df_field_filled( $item->value( 'kicker' ) ) ) : ?>
					<p class="df-kacheln__kicker"><?php $item->text( 'kicker' ); ?></p>
				<?php endif; ?>
				<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-kacheln__title"><?php $item->text( 'title' ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php if ( $can_edit || df_field_filled( $item->value( 'text' ) ) ) : ?>
					<?php $item->richtext( 'text', array( 'class' => 'df-kacheln__text' ) ); ?>
				<?php endif; ?>
				<?php if ( $link_on ) : ?>
					<p class="df-kacheln__more">
						<?php
						// One link per card; with card_link=yes its ::after stretches over the whole card.
						$item->button(
							'link',
							array(
								'class' => 'df-kacheln__link',
								'after' => '' !== $title ? '<span class="df-sr-only">: ' . esc_html( $title ) . '</span>' : '',
							)
						);
						if ( 'arrow' !== $marker ) {
							df_icon( 'arrow-right', array( 'class' => 'df-kacheln__arrow' ) );
						}
						?>
					</p>
				<?php endif; ?>
			</li>
		<?php endfor; ?>
	</<?php echo $list_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php if ( ! $split && $show['cta'] ) : ?>
		<div class="df-actions<?php echo $center ? ' df-kacheln__actions--center' : ''; ?>">
			<?php $s->button( 'cta', array( 'class' => 'df-btn df-btn--primary' ) ); ?>
		</div>
	<?php endif; ?>
</div>
