<?php
/**
 * DF Inhalt admin page: Changes, Comments, Settings, Help.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Inhalt_Admin {
	const MENU_SLUG = 'df-inhalt';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar_shortcut' ), 80 );
	}

	/**
	 * Add the DF Inhalt top-level menu.
	 *
	 * The icon is passed as a data URI so WordPress recolours it to match the
	 * user's admin colour scheme.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$svg  = file_get_contents( DF_INHALT_PLUGIN_PATH . 'admin/img/df-inhalt-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		/**
		 * Filter the admin menu label.
		 *
		 * @param string $title Menu label.
		 */
		$menu_title = (string) apply_filters( 'df_inhalt_admin_menu_title', __( 'DF Inhalt', 'df-editor' ) );

		/**
		 * Filter the parent menu slug. Empty keeps DF Inhalt as a top-level menu;
		 * a slug such as `edit.php?post_type=page` moves it under that menu.
		 *
		 * @param string $parent Parent menu slug.
		 */
		$parent = (string) apply_filters( 'df_inhalt_admin_menu_parent', '' );

		if ( '' !== $parent ) {
			self::$page_hook = (string) add_submenu_page(
				$parent,
				__( 'DF Inhalt', 'df-editor' ),
				$menu_title,
				'edit_pages',
				self::MENU_SLUG,
				array( __CLASS__, 'render_page' )
			);
			return;
		}

		self::$page_hook = add_menu_page(
__( 'DF Inhalt', 'df-editor' ),
			$menu_title,
			'edit_pages',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			$icon,
			58
		);
	}

	/**
	 * Add an "Open DF Inhalt" shortcut to the admin bar while viewing the site.
	 *
	 * @param WP_Admin_Bar $admin_bar WordPress admin bar instance.
	 * @return void
	 */
	public static function register_admin_bar_shortcut( $admin_bar ) {
		if ( is_admin() || ! DF_Inhalt_Plugin::can_edit() ) {
			return;
		}

		$current_url = home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$editor_url  = add_query_arg( 'df-inhalt-edit', '1', $current_url );

		$admin_bar->add_node(
			array(
				'id'    => 'df-inhalt-open-editor',
				'title' => __( 'Open DF Inhalt', 'df-editor' ),
				'href'  => esc_url( $editor_url ),
				'meta'  => array(
					'class' => 'df-inhalt-admin-bar-shortcut',
				),
			)
		);
	}

	/**
	 * Load styles and scripts only on the DF Inhalt admin page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$page_hook !== $hook_suffix ) {
			return;
		}

		$css_path = DF_INHALT_PLUGIN_PATH . 'admin/css/admin.css';
		$js_path  = DF_INHALT_PLUGIN_PATH . 'admin/js/admin.js';

		wp_enqueue_style(
			'df-inhalt-admin',
			DF_INHALT_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : DF_INHALT_VERSION
		);
		wp_enqueue_script(
			'df-inhalt-admin',
			DF_INHALT_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : DF_INHALT_VERSION,
			true
		);
	}

	/**
	 * Render the page. Every tab is in the DOM; JS switches between them.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'df-editor' ) );
		}

		$tabs = array(
			'changes'     => __( 'Changes', 'df-editor' ),
			'comments'    => __( 'Comments', 'df-editor' ),
			'help'        => __( 'Help', 'df-editor' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Settings', 'df-editor' );
		}

		/**
		 * Filter the admin tabs. Removing a tab also removes its panel.
		 *
		 * @param array<string, string> $tabs Tab slug => label.
		 */
		$tabs   = (array) apply_filters( 'df_inhalt_admin_tabs', $tabs );
		$panels = array(
			'changes'     => 'render_changes',
			'comments'    => 'render_comments',
			'settings'    => 'render_settings',
			'help'        => 'render_help',
		);
		?>
		<div class="df-inhalt-wrap">

			<header class="df-inhalt-header">
				<div class="df-inhalt-header__left">
					<h1 class="df-inhalt-header__title"><?php esc_html_e( 'DF Inhalt', 'df-editor' ); ?></h1>
					<span class="df-inhalt-header__version">v<?php echo esc_html( DF_INHALT_VERSION ); ?></span>
				</div>
				<nav class="df-inhalt-header__nav">
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<a href="#<?php echo esc_attr( $slug ); ?>" data-tab="<?php echo esc_attr( $slug ); ?>" class="df-inhalt-header__link">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
					<a class="df-inhalt-header__open" href="<?php echo esc_url( add_query_arg( 'df-inhalt-edit', '1', home_url( '/' ) ) ); ?>">
						<?php esc_html_e( 'Open DF Inhalt', 'df-editor' ); ?>
					</a>
				</nav>
			</header>
			<hr class="wp-header-end">

<?php
			$first = true;
			foreach ( $panels as $slug => $method ) :
				if ( ! isset( $tabs[ $slug ] ) ) {
					continue;
				}
				?>
				<div data-tab-panel="<?php echo esc_attr( $slug ); ?>"<?php echo $first ? '' : ' hidden'; ?>><?php self::$method(); ?></div>
				<?php
				$first = false;
			endforeach;
			?>

		</div>
		<?php
	}

	private static function render_settings() {
		?>
		<div class="df-inhalt-content"><div class="df-inhalt-card df-inhalt-card--full">
			<div class="df-inhalt-page-intro"><span class="df-inhalt-eyebrow"><?php esc_html_e( 'A simpler editing experience', 'df-editor' ); ?></span><h2><?php esc_html_e( 'Client mode', 'df-editor' ); ?></h2></div>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice.
			if ( isset( $_GET['df-inhalt-settings-saved'] ) ) : ?><p class="df-inhalt-settings-notice" role="status"><?php esc_html_e( 'Settings saved.', 'df-editor' ); ?></p><?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="df_inhalt_save_settings"><?php wp_nonce_field( 'df_inhalt_save_settings' ); ?>
				<label class="df-inhalt-setting-row"><span><strong><?php esc_html_e( 'Enable client mode', 'df-editor' ); ?></strong><small><?php esc_html_e( 'Create the Clients role and simplify their editing screens.', 'df-editor' ); ?></small></span><span class="df-inhalt-setting-switch"><input type="checkbox" name="client_mode" value="1" role="switch" aria-label="<?php esc_attr_e( 'Enable client mode', 'df-editor' ); ?>" <?php checked( DF_Inhalt_Client::enabled() ); ?>><span class="df-inhalt-setting-switch__track" aria-hidden="true"></span></span></label>
				<p><?php esc_html_e( 'Assign the Clients role to your client accounts in WordPress. Clients start on the website and use the existing managed-content links to open the correct editor.', 'df-editor' ); ?></p>
				<p class="df-inhalt-settings-notice"><?php esc_html_e( 'The WordPress top bar and left menu are hidden. The editor sidebar stays available for featured images, categories, and content fields.', 'df-editor' ); ?></p>
				<p><?php esc_html_e( 'Static page content stays editable directly on the website. Clients can create, edit, and trash blog posts, products, and frontend-facing custom post types. Pages remain unavailable in the backend.', 'df-editor' ); ?></p>
				<p><?php esc_html_e( 'Disabling client mode keeps assigned users in the Clients role but removes their content-editing access until you enable it again or assign another role.', 'df-editor' ); ?></p>
				<button class="df-inhalt-button" type="submit"><?php esc_html_e( 'Save settings', 'df-editor' ); ?></button>
			</form>
		</div></div>
		<?php
	}

	private static function render_comments() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inbox filtering.
		$resolved = isset( $_GET['df-inhalt-comment-status'] ) && 'resolved' === $_GET['df-inhalt-comment-status'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page = isset( $_GET['df-inhalt-comment-page'] ) ? max( 1, absint( $_GET['df-inhalt-comment-page'] ) ) : 1;
		$rows = DF_Inhalt_Comments::inbox( $resolved, $page );
		$more = count( $rows ) > 50;
		$rows = array_slice( $rows, 0, 50 );
		$base = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="df-inhalt-content df-inhalt-content--wide"><div class="df-inhalt-card df-inhalt-card--full">
			<div class="df-inhalt-page-intro"><span class="df-inhalt-eyebrow"><?php esc_html_e( 'Client feedback', 'df-editor' ); ?></span><h2><?php esc_html_e( 'Comments', 'df-editor' ); ?></h2><p><?php esc_html_e( 'Private notes attached to the website. Content changes stay in their own tab.', 'df-editor' ); ?></p></div>
			<nav class="df-inhalt-comment-filters" aria-label="<?php esc_attr_e( 'Comment status', 'df-editor' ); ?>">
				<a class="df-inhalt-button<?php echo $resolved ? ' df-inhalt-button--quiet' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'df-inhalt-comment-status', 'open', $base ) . '#comments' ); ?>"><?php esc_html_e( 'Open', 'df-editor' ); ?></a>
				<a class="df-inhalt-button<?php echo ! $resolved ? ' df-inhalt-button--quiet' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'df-inhalt-comment-status', 'resolved', $base ) . '#comments' ); ?>"><?php esc_html_e( 'Resolved', 'df-editor' ); ?></a>
			</nav>
			<?php if ( ! $rows ) : ?><p class="df-inhalt-comment-empty"><?php echo esc_html( $resolved ? __( 'No resolved comments yet.', 'df-editor' ) : __( 'No open comments.', 'df-editor' ) ); ?></p><?php endif; ?>
			<?php foreach ( $rows as $row ) : $target = json_decode( $row->target, true ); ?>
				<article class="df-inhalt-comment-record">
					<div class="df-inhalt-comment-heading"><h3><?php echo esc_html( $target['label'] ?? __( 'Page element', 'df-editor' ) ); ?></h3><span class="df-inhalt-comment-badge"><?php echo esc_html( $resolved ? __( 'Resolved', 'df-editor' ) : __( 'Comment', 'df-editor' ) ); ?></span></div>
					<p class="df-inhalt-comment-text"><?php echo esc_html( $row->comment_text ); ?></p>
					<p class="df-inhalt-comment-meta"><?php echo esc_html( $row->user_name . ' · ' . $row->page_title . ' · ' . wp_date( 'j M Y, H:i', strtotime( $row->created_at . ' UTC' ) ) ); ?></p>
					<div class="df-inhalt-comment-actions"><a class="df-inhalt-button df-inhalt-button--quiet" href="<?php echo esc_url( add_query_arg( array( 'df-inhalt-edit' => '1', 'df-inhalt-comment' => $row->comment_id ), $row->page_url ) ); ?>"><?php esc_html_e( 'View on website', 'df-editor' ); ?></a>
					<?php if ( current_user_can( 'manage_options' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="df_inhalt_resolve_comment"><input type="hidden" name="comment_id" value="<?php echo absint( $row->comment_id ); ?>"><input type="hidden" name="resolved" value="<?php echo $resolved ? '0' : '1'; ?>"><?php wp_nonce_field( 'df_inhalt_resolve_comment_' . $row->comment_id ); ?><button class="df-inhalt-button" type="submit"><?php echo esc_html( $resolved ? __( 'Reopen', 'df-editor' ) : __( 'Resolve', 'df-editor' ) ); ?></button></form><?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
			<nav class="df-inhalt-comment-actions" aria-label="<?php esc_attr_e( 'Comment pages', 'df-editor' ); ?>"><?php if ( $page > 1 ) : ?><a class="df-inhalt-button df-inhalt-button--quiet" href="<?php echo esc_url( add_query_arg( array( 'df-inhalt-comment-status' => $resolved ? 'resolved' : 'open', 'df-inhalt-comment-page' => $page - 1 ), $base ) . '#comments' ); ?>"><?php esc_html_e( 'Previous', 'df-editor' ); ?></a><?php endif; ?><?php if ( $more ) : ?><a class="df-inhalt-button df-inhalt-button--quiet" href="<?php echo esc_url( add_query_arg( array( 'df-inhalt-comment-status' => $resolved ? 'resolved' : 'open', 'df-inhalt-comment-page' => $page + 1 ), $base ) . '#comments' ); ?>"><?php esc_html_e( 'Next', 'df-editor' ); ?></a><?php endif; ?></nav>
		</div></div>
		<?php
	}

	/**
	 * Changes tab: open button and the activity log.
	 *
	 * @return void
	 */
	private static function render_changes() {
		$activities = DF_Inhalt_Activity::get_recent( 100 );
		?>
		<div class="df-inhalt-content df-inhalt-content--onboarding">
			<div class="df-inhalt-card df-inhalt-card--full df-inhalt-changes">
				<div class="df-inhalt-page-intro">
					<span class="df-inhalt-eyebrow"><?php esc_html_e( 'Content history', 'df-editor' ); ?></span>
					<h2><?php esc_html_e( 'Changes', 'df-editor' ); ?></h2>
				</div>

				<?php if ( empty( $activities ) ) : ?>
					<div class="df-inhalt-admin__empty">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<h3><?php esc_html_e( 'No changes yet', 'df-editor' ); ?></h3>
						<p><?php esc_html_e( 'Every change saved through DF Inhalt appears here, with the value before and after.', 'df-editor' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="df-inhalt-admin__list">
						<?php foreach ( $activities as $activity ) : ?>
							<li class="df-inhalt-admin__record">
								<?php self::render_activity_row( $activity ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Help tab: neutral usage notes and the developer reference.
	 *
	 * @return void
	 */
	private static function render_help() {
		$instructions_file = DF_INHALT_PLUGIN_PATH . 'docs/agent-instructions.md';
		$instructions      = file_exists( $instructions_file ) ? file_get_contents( $instructions_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		?>
		<div class="df-inhalt-content df-inhalt-content--onboarding">
			<div class="df-inhalt-card df-inhalt-card--full">
				<div class="df-inhalt-page-intro">
					<span class="df-inhalt-eyebrow"><?php esc_html_e( 'Help', 'df-editor' ); ?></span>
					<h2><?php esc_html_e( 'Editing content on the website', 'df-editor' ); ?></h2>
				</div>
				<ol class="df-inhalt-steps">
					<li>
						<strong><?php esc_html_e( 'Open the editor', 'df-editor' ); ?></strong>
						<?php esc_html_e( 'Visit the page while logged in and click the edit button in the bottom corner, or use "Open DF Inhalt" in the admin bar.', 'df-editor' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Click a highlighted element', 'df-editor' ); ?></strong>
						<?php esc_html_e( 'Texts, buttons and images that can be edited are highlighted. Change the content and save.', 'df-editor' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Leave a comment', 'df-editor' ); ?></strong>
						<?php esc_html_e( 'Switch to "Leave a comment" to send private feedback to the site team without changing the page.', 'df-editor' ); ?>
					</li>
				</ol>
				<div class="df-inhalt-get-started__details">
					<div>
						<h3><?php esc_html_e( 'Client access', 'df-editor' ); ?></h3>
						<p><?php esc_html_e( 'Administrators and Editors can edit content. For a simpler client experience, enable client mode in Settings and assign the Clients role to client accounts.', 'df-editor' ); ?></p>
					</div>
					<div>
						<h3><?php esc_html_e( 'Theme developers', 'df-editor' ); ?></h3>
						<p><?php esc_html_e( 'Saved content stays intact as long as field IDs and types stay the same. The reference below describes the field helpers.', 'df-editor' ); ?></p>
					</div>
				</div>
				<?php if ( '' !== $instructions && current_user_can( 'manage_options' ) ) : ?>
					<h3 class="df-inhalt-section-heading"><?php esc_html_e( 'Developer reference', 'df-editor' ); ?></h3>
					<div class="df-inhalt-instructions__actions">
						<button type="button" class="df-inhalt-button" data-copy="df-inhalt-agent-instructions" data-copied-label="<?php esc_attr_e( 'Copied', 'df-editor' ); ?>">
							<?php esc_html_e( 'Copy reference', 'df-editor' ); ?>
						</button>
						<span class="df-inhalt-instructions__path"><code>content/docs/agent-instructions.md</code></span>
					</div>
					<textarea id="df-inhalt-agent-instructions" class="df-inhalt-instructions__text" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Developer reference', 'df-editor' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one activity record.
	 *
	 * @param object $activity Activity database record.
	 * @return void
	 */
	private static function render_activity_row( $activity ) {
		$timestamp  = strtotime( $activity->created_at . ' UTC' );
		$when       = $timestamp ? wp_date( 'j M Y, H:i', $timestamp ) : $activity->created_at;
		$user_name  = $activity->user_name ? $activity->user_name : __( 'Unknown user', 'df-editor' );
		$page_id    = absint( $activity->page_id );
		$page_id    = $page_id && current_user_can( 'read_post', $page_id ) ? $page_id : 0;
		$page_title = $page_id ? get_the_title( $page_id ) : '';
		$page_url   = $page_id ? get_permalink( $page_id ) : '';
		$edit_url   = add_query_arg(
			array(
				'df-inhalt-edit'  => '1',
				'df-inhalt-field' => $activity->field_id,
			),
			$page_url ? $page_url : home_url( '/' )
		);

		$who_markup = sprintf(
			'<span class="df-inhalt-admin__user">%s<span>%s</span></span>',
			get_avatar( absint( $activity->user_id ), 20, '', '', array( 'class' => 'df-inhalt-admin__avatar' ) ),
			esc_html( $user_name )
		);

		if ( $page_title && $page_url ) {
			$where_markup = sprintf( '<a href="%s">%s</a>', esc_url( $page_url ), esc_html( $page_title ) );
		} else {
			$where_markup = sprintf( '<span class="df-inhalt-admin__muted">%s</span>', esc_html__( 'a site-wide field', 'df-editor' ) );
		}

		$when_markup = sprintf( '<span class="df-inhalt-admin__when">%s</span>', esc_html( $when ) );
		?>
		<div class="df-inhalt-admin__record-head">
			<div class="df-inhalt-admin__record-col df-inhalt-admin__record-col--before">
				<strong class="df-inhalt-admin__field" title="<?php echo esc_attr( $activity->field_id ); ?>"><?php echo esc_html( $activity->field_label ); ?></strong>
			</div>
			<span class="df-inhalt-admin__record-gutter" aria-hidden="true"></span>
			<div class="df-inhalt-admin__record-col df-inhalt-admin__record-col--after">
				<p class="df-inhalt-admin__meta-line">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: user name with avatar, 2: page or location, 3: date and time. */
							__( '%1$s updated %2$s on %3$s', 'df-editor' ),
							$who_markup,
							$where_markup,
							$when_markup
						)
					);
					?>
				</p>
				<a class="df-inhalt-admin__edit" href="<?php echo esc_url( $edit_url ); ?>">
					<?php esc_html_e( 'Edit', 'df-editor' ); ?>
				</a>
			</div>
		</div>
		<div class="df-inhalt-admin__change">
			<?php self::render_value( __( 'Before:', 'df-editor' ), $activity->old_value, $activity->field_type ); ?>
			<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			<?php self::render_value( __( 'After:', 'df-editor' ), $activity->new_value, $activity->field_type ); ?>
		</div>
		<?php
	}

	/**
	 * Render one before or after value box.
	 *
	 * Rich text keeps its formatting so a change such as adding bold is visible.
	 *
	 * @param string $label      Screen-reader label.
	 * @param string $value      Stored value.
	 * @param string $field_type Field type.
	 * @return void
	 */
	private static function render_value( $label, $value, $field_type ) {
		$is_rich = 'richtext' === $field_type && '' !== trim( wp_strip_all_tags( (string) $value ) );
		?>
		<div class="df-inhalt-admin__value-text<?php echo $is_rich ? ' df-inhalt-admin__value-text--rich' : ''; ?>">
			<span class="screen-reader-text"><?php echo esc_html( $label ); ?> </span>
			<?php
			if ( $is_rich ) {
				echo wp_kses( $value, DF_Inhalt_Fields::richtext_tags() );
			} else {
				echo esc_html( self::display_value( $value, $field_type ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Return a readable plain-text activity value.
	 *
	 * @param string $value      Stored value.
	 * @param string $field_type Field type.
	 * @return string
	 */
	private static function display_value( $value, $field_type ) {
		if ( 'image' === $field_type ) {
			$attachment_id = absint( $value );

			if ( ! $attachment_id ) {
				return __( 'No image', 'df-editor' );
			}

			$title = get_the_title( $attachment_id );

			return $title ? $title : sprintf(
				/* translators: %d: Media attachment ID. */
				__( 'Media #%d', 'df-editor' ),
				$attachment_id
			);
		}

		if ( 'button' === $field_type ) {
			$button = json_decode( (string) $value, true );

			if ( is_array( $button ) && ! empty( $button['text'] ) ) {
				return sprintf( '%s (%s)', $button['text'], isset( $button['url'] ) ? $button['url'] : '' );
			}

			return __( 'Empty', 'df-editor' );
		}

		$value = trim( wp_strip_all_tags( (string) $value ) );

		if ( '' === $value ) {
			return __( 'Empty', 'df-editor' );
		}

		return $value;
	}
}
