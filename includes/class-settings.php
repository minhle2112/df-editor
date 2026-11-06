<?php
/**
 * Settings page "DF Editor" (Settings menu): option df_editor_settings.
 *
 * - honeypot        on/off (default on), read by the form integration
 * - consent_tool    none|borlabs|real-cookie-banner|complianz (stored only; used by video/map sections)
 * - inline editing  mirrors option df_inhalt_disabled (the DF Inhalt master switch)
 * - placeholder     shows df_placeholder_image_id, "Regenerate" button
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Settings {

	public const OPTION = 'df_editor_settings';
	public const PAGE   = 'df-editor';
	public const CAP    = 'manage_options';

	public const CONSENT_TOOLS = array( 'none', 'borlabs', 'real-cookie-banner', 'complianz' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_df_editor_regenerate_placeholder', array( __CLASS__, 'regenerate_placeholder' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DF_EDITOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function defaults(): array {
		return array(
			'honeypot'     => 1,
			'consent_tool' => 'none',
		);
	}

	/**
	 * One setting (defaults applied). "disable_inline_editing" reads df_inhalt_disabled.
	 */
	public static function get( string $key ) {
		if ( 'disable_inline_editing' === $key ) {
			return (bool) get_option( 'df_inhalt_disabled', false );
		}
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return $all[ $key ] ?? null;
	}

	public static function menu(): void {
		add_options_page( __( 'DF Editor', 'df-editor' ), __( 'DF Editor', 'df-editor' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'df-editor' ) ) );
		return $links;
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'df_editor_main', __( 'General', 'df-editor' ), '__return_false', self::PAGE );
		add_settings_field( 'df_editor_inline', __( 'Inline editing', 'df-editor' ), array( __CLASS__, 'field_inline' ), self::PAGE, 'df_editor_main', array( 'label_for' => 'df-editor-disable-inline' ) );
		add_settings_field( 'df_editor_honeypot', __( 'Form spam protection', 'df-editor' ), array( __CLASS__, 'field_honeypot' ), self::PAGE, 'df_editor_main', array( 'label_for' => 'df-editor-honeypot' ) );
		add_settings_field( 'df_editor_consent', __( 'Consent tool', 'df-editor' ), array( __CLASS__, 'field_consent' ), self::PAGE, 'df_editor_main', array( 'label_for' => 'df-editor-consent' ) );
	}

	/**
	 * Sanitises the option; the inline editing switch is written to df_inhalt_disabled.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array(
			'honeypot'     => empty( $input['honeypot'] ) ? 0 : 1,
			'consent_tool' => in_array( $input['consent_tool'] ?? '', self::CONSENT_TOOLS, true ) ? $input['consent_tool'] : 'none',
		);
		// Only from our form (the hidden marker), never from a programmatic update_option().
		if ( isset( $input['_form'] ) && current_user_can( self::CAP ) ) {
			update_option( 'df_inhalt_disabled', empty( $input['disable_inline_editing'] ) ? 0 : 1 );
		}
		return $clean;
	}

	public static function field_inline(): void {
		printf(
			'<input type="hidden" name="%1$s[_form]" value="1"><label><input type="checkbox" id="df-editor-disable-inline" name="%1$s[disable_inline_editing]" value="1"%2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( self::get( 'disable_inline_editing' ), true, false ),
			esc_html__( 'Disable front-end editing (DF Inhalt) for everyone', 'df-editor' ),
			esc_html__( 'Saved content stays visible. Use this e.g. during a relaunch or to compare with the plain output.', 'df-editor' )
		);
	}

	public static function field_honeypot(): void {
		printf(
			'<label><input type="checkbox" id="df-editor-honeypot" name="%1$s[honeypot]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (int) self::get( 'honeypot' ), 1, false ),
			esc_html__( 'Add a honeypot field to forms', 'df-editor' )
		);
	}

	public static function field_consent(): void {
		$labels = array(
			'none'               => __( 'None', 'df-editor' ),
			'borlabs'            => 'Borlabs Cookie',
			'real-cookie-banner' => 'Real Cookie Banner',
			'complianz'          => 'Complianz',
		);
		printf( '<select id="df-editor-consent" name="%s[consent_tool]">', esc_attr( self::OPTION ) );
		foreach ( self::CONSENT_TOOLS as $tool ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $tool ), selected( self::get( 'consent_tool' ), $tool, false ), esc_html( $labels[ $tool ] ) );
		}
		echo '</select>';
		printf( '<p class="description">%s</p>', esc_html__( 'Used by sections that load third-party content (video, map) to show a two-click consent first.', 'df-editor' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = DF_Content::placeholder_id();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DF Editor', 'df-editor' ); ?></h1>
			<?php if ( isset( $_GET['df-placeholder'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-<?php echo 'ok' === $_GET['df-placeholder'] ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification ?> is-dismissible"><p>
					<?php echo 'ok' === $_GET['df-placeholder'] ? esc_html__( 'Placeholder image regenerated.', 'df-editor' ) : esc_html__( 'The placeholder image could not be created.', 'df-editor' ); // phpcs:ignore WordPress.Security.NonceVerification ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Placeholder image', 'df-editor' ); ?></h2>
			<p><?php esc_html_e( 'Shown in image fields that have no image yet. Clients replace it with DF Inhalt.', 'df-editor' ); ?></p>
			<?php if ( $id && wp_attachment_is_image( $id ) ) : ?>
				<p>
					<?php echo wp_get_attachment_image( $id, 'medium', false, array( 'style' => 'max-width:300px;height:auto;border:1px solid #c3c4c7' ) ); ?><br>
					<?php
					/* translators: %d: attachment ID */
					printf( esc_html__( 'Attachment ID: %d', 'df-editor' ), (int) $id );
					?>
				</p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'No placeholder image found.', 'df-editor' ); ?></strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="df_editor_regenerate_placeholder">
				<?php wp_nonce_field( 'df_editor_regenerate_placeholder' ); ?>
				<?php submit_button( __( 'Regenerate placeholder image', 'df-editor' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function regenerate_placeholder(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'df-editor' ), 403 );
		}
		check_admin_referer( 'df_editor_regenerate_placeholder' );
		$id = DF_Setup::regenerate_placeholder();
		wp_safe_redirect( add_query_arg( 'df-placeholder', $id ? 'ok' : 'error', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}
}
