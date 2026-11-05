<?php
/**
 * Section: Vorteile (USP bar)
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$can_edit = $s->can_edit();
$fallback = array( 'check', 'clock', 'shield', 'star', 'users', 'map-pin' );
$classes  = array( 'df-vorteile__list' );
if ( 'yes' === $s->opt( 'divider' ) ) {
	$classes[] = 'df-vorteile__list--divider';
}
// Item without title and text: hidden for visitors, still shown to editors.
// --df-count must match the printed items so the row variant has no empty columns.
$visible = array_filter(
	$s->items(),
	static fn( $item ) => $can_edit || df_field_filled( $item->value( 'title' ) ) || df_field_filled( $item->value( 'text' ) )
);
?>
<ul class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" role="list" style="--df-count: <?php echo (int) count( $visible ); ?>">
	<?php
	foreach ( $visible as $item ) :
		$n    = $item->index();
		$icon = (string) df_item_setting( $s, $n, 'icon', $fallback[ ( $n - 1 ) % count( $fallback ) ] );
		?>
		<li class="df-vorteile__item"<?php $item->attrs(); ?>>
			<?php if ( 'none' !== $icon ) : ?>
				<span class="df-vorteile__icon"><?php df_icon( $icon ); ?></span>
			<?php endif; ?>
			<span class="df-vorteile__body">
				<strong class="df-vorteile__title"><?php $item->text( 'title' ); ?></strong>
				<span class="df-vorteile__text"><?php $item->text( 'text' ); ?></span>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
