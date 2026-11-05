<?php
/**
 * Section: Team
 *
 * source=cpt    members from the post type df_team (DF_CPT), each value marked as a managed region.
 * source=manual fixed list of DF Inhalt fields (items.{n}.name|role|image|email|phone).
 *
 * @df-section-version 1
 * @var DF_Section $s
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';

$h        = 'h3' === $s->opt( 'heading_level' ) ? 'h3' : 'h2';
$h_item   = 'h3' === $h ? 'h4' : 'h3';
$can_edit = $s->can_edit();
$is_staff = current_user_can( 'edit_pages' );
$round    = 'round' === $s->opt( 'photo' );
$ratio    = ( ! $round && '4-5' === $s->opt( 'ratio' ) ) ? '4/5' : '1/1';
$contact  = 'yes' === $s->opt( 'contact' );
$source   = 'manual' === $s->opt( 'source' ) ? 'manual' : 'cpt';

$list_classes = array(
	'df-team__list',
	'df-team--cols-' . $s->opt( 'columns' ),
	'df-team--photo-' . ( $round ? 'round' : 'rect' ),
);

$frame_classes = 'df-frame df-team__photo' . ( $round ? '' : ' df-frame--rounded' );

// CPT source: query first so visitors get no orphan heading when nothing is published.
$cpt_ready = false;
$members   = array();
if ( 'cpt' === $source ) {
	$cpt_ready = class_exists( 'DF_CPT' ) && DF_CPT::is_enabled( 'df_team' );
	$max       = 'all' === $s->opt( 'cpt_max' ) ? -1 : (int) $s->opt( 'cpt_max' );
	$members   = $cpt_ready ? DF_CPT::query( 'df_team', $max ) : array();
	if ( ! $members && ! $is_staff ) {
		return;
	}
}

df_section_head( $s, 'team', $h, array( 'title_class' => 'df-team__title df-title' ) );

if ( 'cpt' === $source ) :
	$show_bio = 'yes' === $s->opt( 'bio' );

	if ( ! $members ) {
		echo '<div class="df-missing" role="note">';
		if ( $cpt_ready ) {
			printf(
				'%1$s <a href="%2$s">%3$s</a>',
				esc_html__( 'No published team members yet.', 'df-editor' ),
				esc_url( admin_url( 'post-new.php?post_type=df_team' ) ),
				esc_html__( 'Add a team member', 'df-editor' )
			);
		} else {
			esc_html_e( 'The Team post type is disabled (filter df_editor_cpts). Switch the section to source "manual" or enable the post type.', 'df-editor' );
		}
		echo '</div>';
		return;
	}
	?>
	<ul class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>" role="list">
		<?php
		foreach ( $members as $member ) :
			$edit     = admin_url( 'post.php?post=' . (int) $member->ID . '&action=edit' );
			$name     = get_the_title( $member );
			$role     = (string) get_post_meta( $member->ID, '_df_role', true );
			$email    = sanitize_email( (string) get_post_meta( $member->ID, '_df_email', true ) );
			$phone    = (string) get_post_meta( $member->ID, '_df_phone', true );
			$linkedin = (string) get_post_meta( $member->ID, '_df_linkedin', true );
			$photo_id = (int) get_post_thumbnail_id( $member );
			$photo_id = $photo_id > 0 ? $photo_id : DF_Content::placeholder_id();
			$region   = static function ( string $label, string $class ) use ( $edit ): void {
				df_region_open(
					array(
						'provider' => 'wordpress',
						'label'    => $label,
						'edit_url' => $edit,
						'class'    => $class,
					)
				);
			};
			?>
			<li class="df-team__member">
				<?php $region( __( 'Team member photo – edit in WordPress', 'df-editor' ), 'df-team__region df-team__region--photo' ); ?>
					<div class="<?php echo esc_attr( $frame_classes ); ?>" style="--df-ratio: <?php echo esc_attr( $ratio ); ?>">
						<?php
						if ( $photo_id > 0 ) {
							echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								$photo_id,
								'medium_large',
								false,
								array( 'loading' => 'lazy' )
							);
						}
						?>
					</div>
				<?php df_region_close(); ?>

				<div class="df-team__body">
					<?php $region( __( 'Name – edit in WordPress (Team)', 'df-editor' ), 'df-team__region' ); ?>
						<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="df-team__name"><?php echo esc_html( $name ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php df_region_close(); ?>

					<?php if ( '' !== $role || $can_edit ) : ?>
						<?php $region( __( 'Role – edit in WordPress (Team)', 'df-editor' ), 'df-team__region' ); ?>
							<p class="df-team__role df-muted"><?php echo esc_html( $role ); ?></p>
						<?php df_region_close(); ?>
					<?php endif; ?>

					<?php if ( $show_bio && has_excerpt( $member ) ) : ?>
						<?php $region( __( 'Short bio – edit in WordPress (Team)', 'df-editor' ), 'df-team__region' ); ?>
							<p class="df-team__bio"><?php echo esc_html( get_the_excerpt( $member ) ); ?></p>
						<?php df_region_close(); ?>
					<?php endif; ?>

					<?php if ( $contact && ( $email || $phone || $linkedin || $can_edit ) ) : ?>
						<?php $region( __( 'Contact – edit in WordPress (Team)', 'df-editor' ), 'df-team__region' ); ?>
							<p class="df-team__contact">
								<?php if ( $email ) : ?>
									<a class="df-team__link" href="<?php echo esc_url( 'mailto:' . antispambot( $email ) ); ?>"><?php echo esc_html( antispambot( $email ) ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== $phone ) : ?>
									<a class="df-team__link" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== $linkedin ) : ?>
									<a class="df-team__link" href="<?php echo esc_url( $linkedin ); ?>" target="_blank" rel="noopener">LinkedIn<span class="df-sr-only"> <?php echo esc_html( sprintf( /* translators: %s: person name */ __( 'profile of %s (opens in a new tab)', 'df-editor' ), $name ) ); ?></span></a>
								<?php endif; ?>
							</p>
						<?php df_region_close(); ?>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $is_staff && $cpt_ready ) : ?>
		<p class="df-team__manage">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=df_team' ) ); ?>"><?php esc_html_e( 'Manage team members (add, edit, reorder)', 'df-editor' ); ?></a>
		</p>
	<?php endif; ?>
	<?php
	return;
endif;

// Manual list.
?>
<ul class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>" role="list">
	<?php
	foreach ( $s->items() as $item ) :
		$name = trim( (string) $item->value( 'name' ) );
		// Empty name: hidden for visitors, still shown to editors.
		if ( '' === $name && ! $can_edit ) {
			continue;
		}
		$email = (array) $item->value( 'email' );
		$phone = (array) $item->value( 'phone' );
		$has_e = '' !== trim( (string) ( $email['text'] ?? '' ) );
		$has_p = '' !== trim( (string) ( $phone['text'] ?? '' ) );
		?>
		<li class="df-team__member"<?php $item->attrs(); ?>>
			<?php if ( $item->has_image( 'image' ) ) : ?>
			<div class="<?php echo esc_attr( $frame_classes ); ?>" style="--df-ratio: <?php echo esc_attr( $ratio ); ?>">
				<?php
				$item->image(
					'image',
					array(
						'size'         => 'medium_large',
						'loading'      => 'lazy',
						'aspect_ratio' => $ratio,
					)
				);
				?>
			</div>
			<?php endif; ?>
			<div class="df-team__body">
				<<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="df-team__name"><?php $item->text( 'name' ); ?></<?php echo $h_item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<p class="df-team__role df-muted"><?php $item->text( 'role' ); ?></p>
				<?php if ( $contact && ( $has_e || $has_p || $can_edit ) ) : ?>
					<p class="df-team__contact">
						<?php if ( $has_e || $can_edit ) : ?>
							<?php $item->button( 'email', array( 'class' => 'df-team__link' ) ); ?>
						<?php endif; ?>
						<?php if ( $has_p || $can_edit ) : ?>
							<?php $item->button( 'phone', array( 'class' => 'df-team__link' ) ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
