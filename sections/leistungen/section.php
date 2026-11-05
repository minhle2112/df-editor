<?php
/**
 * Section: Leistungen (service cards)
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$variant  = $s->variant();
$h        = df_heading_tag( $s->opt( 'heading_level' ) );
$h_item   = 'h2' === $h ? 'h3' : 'h4';
$linked   = 'yes' === $s->opt( 'card_link' );
$can_edit = $s->can_edit();
$fallback = array( 'wrench', 'flame', 'droplet', 'bolt', 'home', 'shield', 'leaf', 'clock', 'users', 'calendar', 'star', 'check' );

$grid_classes = array( 'df-leistungen__grid', 'df-leistungen--cols-' . $s->opt( 'columns' ) );

df_section_head( $s, 'leistungen', $h );
?>
<ul class="<?php echo esc_attr( implode( ' ', $grid_classes ) ); ?>" role="list">
	<?php
	foreach ( $s->items() as $item ) :
		$n     = $item->index();
		$title = trim( (string) $item->value( 'title' ) );
		// Empty title: hidden for visitors, still shown to editors so it can be filled in again.
		if ( '' === $title && ! $can_edit ) {
			continue;
		}
		// The link is shown whenever filled; card_link only controls the stretched whole-card link.
		$link_on = $can_edit || df_field_filled( $item->value( 'link' ) );
		$icon    = (string) df_item_setting( $s, $n, 'icon', $fallback[ ( $n - 1 ) % count( $fallback ) ] );
		$classes = array( 'df-leistungen__card' );
		if ( $link_on && $linked ) {
			$classes[] = 'df-leistungen__card--linked';
		}
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php if ( 'cards-image' === $variant ) : ?>
				<div class="df-frame df-leistungen__media" style="--df-ratio: <?php echo esc_attr( $item->aspect_ratio( 'image' ) ?: '4/3' ); ?>">
					<?php $item->image( 'image', array( 'size' => 'medium_large', 'loading' => 'lazy' ) ); ?>
				</div>
			<?php elseif ( 'none' !== $icon ) : ?>
				<span class="df-leistungen__icon"><?php df_icon( $icon ); ?></span>
			<?php endif; ?>

			<div class="df-leistungen__body">
				<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted ?> class="df-leistungen__title"><?php $item->text( 'title' ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php $item->richtext( 'text', array( 'class' => 'df-leistungen__text' ) ); ?>
				<?php if ( $link_on ) : ?>
					<p class="df-leistungen__more">
						<?php
						// One link per card; with card_link=yes its ::after stretches over the whole card (no nested links).
						// The visually hidden title gives repeated "Mehr erfahren" links a unique name.
						$item->button(
							'link',
							array(
								'class' => 'df-leistungen__link',
								'after' => '' !== $title ? '<span class="df-sr-only">: ' . esc_html( $title ) . '</span>' : '',
							)
						);
						df_icon( 'arrow-right', array( 'class' => 'df-leistungen__arrow' ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
