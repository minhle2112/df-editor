<?php
/**
 * Plugin bootstrap and editor interface.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Inhalt_Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_editor' ), 5 );
	}

	/**
	 * A client-supplied page ID, kept only for an existing, viewable post the user may read; otherwise 0.
	 *
	 * @param mixed $page_id Raw page ID from the request.
	 * @return int
	 */
	public static function sanitize_page_id( $page_id ) {
		$page_id = absint( $page_id );
		$post    = $page_id ? get_post( $page_id ) : null;
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) || ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $page_id ) ) ) {
			return 0;
		}
		return $page_id;
	}

	/**
	 * Whether the current visitor may use DF Inhalt.
	 *
	 * Administrators and Editors by default. Contributors and Authors have
	 * `edit_posts` but not `edit_pages`, so they are left out.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		$can_edit = is_user_logged_in() && ( current_user_can( 'edit_pages' ) || ( DF_Inhalt_Client::enabled() && current_user_can( 'df_inhalt_edit_content' ) ) );
		if ( DF_Inhalt_Client::is_client() && ! DF_Inhalt_Client::enabled() ) {
			$can_edit = false;
		}
		// The whole front-end editor can be switched off without deactivating DF Editor.
		if ( get_option( 'df_inhalt_disabled', false ) ) {
			$can_edit = false;
		}

		/**
		 * Filter who may open DF Inhalt and save content.
		 *
		 * @param bool $can_edit Whether the current user may edit.
		 */
		return (bool) apply_filters( 'df_inhalt_can_edit', $can_edit );
	}

	/**
	 * Whether a read-only query flag is present with the given value.
	 *
	 * @param string $key   Query argument.
	 * @param string $value Expected value.
	 * @return bool
	 */
	private static function query_flag( $key, $value ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation flag.
		return isset( $_GET[ $key ] ) && $value === sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Whether edit mode was requested on load (?df_inhalt=open).
	 *
	 * @return bool
	 */
	public static function auto_open() {
		return self::query_flag( 'df_inhalt', 'open' );
	}

	/**
	 * Register save endpoints.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'df/v1',
			'/fields/(?P<id>[a-z0-9._-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_field' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'id'      => array(
						'required' => true,
					),
					'value'   => array(
						'required' => true,
					),
					'page_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);
	}

	/**
	 * Save an editable field.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_field( WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$schema = DF_Inhalt_Fields::get_schema( $id );

		if ( ! $schema ) {
			return new WP_Error(
				'df_inhalt_unknown_field',
				__( 'This content field is no longer available.', 'df-editor' ),
				array( 'status' => 404 )
			);
		}

		$old_value = DF_Inhalt_Fields::get_current_value( $id );
		$value     = DF_Inhalt_Fields::save( $id, $request->get_param( 'value' ) );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		DF_Inhalt_Activity::record(
			$id,
			$schema,
			$old_value,
			$value,
			self::sanitize_page_id( $request->get_param( 'page_id' ) )
		);

		$response = array(
			'id'    => $id,
			'type'  => $schema['type'],
			'value' => $value,
		);

		if ( 'image' === $schema['type'] ) {
			$response['image'] = DF_Inhalt_Fields::get_image_data(
				$value,
				isset( $schema['size'] ) ? $schema['size'] : 'full'
			);
		}

		return rest_ensure_response(
			$response
		);
	}

	/**
	 * Load editor-only assets for authorised users.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		if ( ! self::can_edit() ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'df-inhalt-editor',
			DF_INHALT_PLUGIN_URL . 'assets/css/editor.css',
			array( 'dashicons' ),
			self::asset_version( 'assets/css/editor.css' )
		);

		wp_enqueue_script(
			'df-inhalt-editor',
			DF_INHALT_PLUGIN_URL . 'assets/js/editor.js',
			array(),
			self::asset_version( 'assets/js/editor.js' ),
			true
		);

		wp_localize_script(
			'df-inhalt-editor',
			'dfInhaltEditor',
			array(
				'restUrl' => esc_url_raw( rest_url( 'df/v1/fields/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'pageId'  => get_queried_object_id(),
				'commentsUrl' => esc_url_raw( rest_url( 'df/v1/comments' ) ),
				'clientMode' => DF_Inhalt_Client::clean_admin(),
				'autoOpen'   => self::auto_open(),
				'labels'  => array(
					'openEditor'  => __( 'Open DF Inhalt', 'df-editor' ),
					'closeEditor' => __( 'Close DF Inhalt', 'df-editor' ),
					'saved'       => __( 'Saved', 'df-editor' ),
					'error'       => __( 'Something went wrong. Please try again.', 'df-editor' ),
					'chooseImage' => __( 'Choose image', 'df-editor' ),
					'useImage'    => __( 'Use image', 'df-editor' ),
					'postComment' => __( 'Post comment', 'df-editor' ),
					'commentSaved' => __( 'Comment posted', 'df-editor' ),
					'writeComment' => __( 'Write a comment first.', 'df-editor' ),
					'saveChanges' => __( 'Save changes', 'df-editor' ),
					'section' => __( 'Page section', 'df-editor' ),
					'locationMissing' => __( 'The original element has changed. This comment is still saved with its page context.', 'df-editor' ),
					'selectText'  => __( 'Select the text you want to turn into a link first.', 'df-editor' ),
				),
			)
		);
	}

	/**
	 * Render the minimal editor interface.
	 *
	 * @return void
	 */
	public static function render_editor() {
		if ( ! self::can_edit() ) {
			return;
		}
		?>
		<div class="df-inhalt-ui df-inhalt-toolbar" data-df-toolbar hidden>
			<button class="df-inhalt-toolbar__select" type="button" data-df-toggle aria-pressed="false">
				<span data-df-toggle-label><?php esc_html_e( 'Open DF Inhalt', 'df-editor' ); ?></span>
				<span class="dashicons dashicons-edit" aria-hidden="true"></span>
			</button>
			<?php
			/**
			 * Fires inside the DF Inhalt toolbar, after the edit toggle (e.g. the view switcher).
			 */
			do_action( 'df_inhalt_toolbar' );
			?>
		</div>
		<?php if ( DF_Inhalt_Client::clean_admin() ) : ?>
			<a class="df-inhalt-ui df-inhalt-client-logout" href="<?php echo esc_url( wp_logout_url( DF_Inhalt_Client::logged_out_url() ) ); ?>"><?php esc_html_e( 'Log out', 'df-editor' ); ?></a>
		<?php endif; ?>

		<div class="df-inhalt-ui df-inhalt-status" data-df-status role="status" aria-live="polite" hidden></div>

		<div class="df-inhalt-ui df-inhalt-highlight" data-df-highlight hidden>
			<div class="df-inhalt-highlight__label">
				<span class="df-inhalt-highlight__text" data-df-highlight-label></span>
				<a
					class="df-inhalt-highlight__action"
					data-df-managed-edit
					href="#"

					hidden
				></a>
			</div>
		</div>

		<div
			class="df-inhalt-ui df-inhalt-popup"
			data-df-popup
			role="dialog"
			aria-labelledby="df-inhalt-popup-title"
			hidden
		>
			<form data-df-form>
				<div class="df-inhalt-popup__label" id="df-inhalt-popup-title" data-df-popup-label></div>
				<div class="df-inhalt-popup__switch" role="tablist" aria-label="<?php esc_attr_e( 'Selected element action', 'df-editor' ); ?>">
					<button type="button" role="tab" aria-selected="true" data-df-mode="edit"><?php esc_html_e( 'Edit content', 'df-editor' ); ?></button>
					<button type="button" role="tab" aria-selected="false" data-df-mode="comment"><?php esc_html_e( 'Leave a comment', 'df-editor' ); ?></button>
				</div>
				<div class="df-inhalt-popup__comment" data-df-comment-editor hidden>
					<label class="df-inhalt-popup__field"><span class="df-inhalt-popup__field-label"><?php esc_html_e( 'Comment', 'df-editor' ); ?></span><textarea class="df-inhalt-popup__input" rows="4" maxlength="5000" data-df-comment-input></textarea></label>
					<p class="df-inhalt-popup__hint"><?php esc_html_e( 'Private feedback for your site team. This does not change the page.', 'df-editor' ); ?></p>
				</div>
				<div class="df-inhalt-popup__managed" data-df-managed-editor hidden>
					<p class="df-inhalt-popup__hint" data-df-managed-description></p>
					<a class="df-inhalt-popup__button df-inhalt-popup__button--primary" data-df-popup-managed-link href="#"><?php esc_html_e( 'Open editor', 'df-editor' ); ?></a>
				</div>
				<div class="df-inhalt-popup__comment" data-df-comment-detail hidden><p data-df-comment-author class="df-inhalt-popup__hint"></p><p data-df-comment-body class="df-inhalt-popup__note"></p></div>
				<div data-df-text-editor>
					<textarea class="df-inhalt-popup__input" id="df-inhalt-popup-value" name="value" rows="1" wrap="soft" autocomplete="off" aria-labelledby="df-inhalt-popup-title" data-df-input></textarea>
				</div>
				<div class="df-inhalt-popup__richtext-editor" data-df-richtext-editor hidden>
					<div class="df-inhalt-popup__toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Formatting', 'df-editor' ); ?>">
						<?php
						// Icon: a dashicon name, or "text:<label>" for a short text label.
						$tools = array(
							'h2'                  => array( 'text:H2', __( 'Heading', 'df-editor' ) ),
							'h3'                  => array( 'text:H3', __( 'Subheading', 'df-editor' ) ),
							'bold'                => array( 'editor-bold', __( 'Bold', 'df-editor' ) ),
							'italic'              => array( 'editor-italic', __( 'Italic', 'df-editor' ) ),
							'link'                => array( 'admin-links', __( 'Link', 'df-editor' ) ),
							'insertUnorderedList' => array( 'editor-ul', __( 'Bulleted list', 'df-editor' ) ),
							'insertOrderedList'   => array( 'editor-ol', __( 'Numbered list', 'df-editor' ) ),
						);

						foreach ( $tools as $command => $tool ) :
							?>
							<button class="df-inhalt-popup__tool" type="button" data-df-command="<?php echo esc_attr( $command ); ?>" aria-label="<?php echo esc_attr( $tool[1] ); ?>" title="<?php echo esc_attr( $tool[1] ); ?>" aria-pressed="false">
								<?php if ( 0 === strpos( $tool[0], 'text:' ) ) : ?>
									<span class="df-inhalt-popup__tool-text" aria-hidden="true"><?php echo esc_html( substr( $tool[0], 5 ) ); ?></span>
								<?php else : ?>
									<span class="dashicons dashicons-<?php echo esc_attr( $tool[0] ); ?>" aria-hidden="true"></span>
								<?php endif; ?>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="df-inhalt-popup__richtext" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="df-inhalt-popup-title" data-df-richtext-input></div>
					<div class="df-inhalt-popup__link" data-df-link-row hidden>
						<input class="df-inhalt-popup__input" type="text" autocomplete="off" inputmode="url" placeholder="<?php esc_attr_e( 'https://example.com or /contact', 'df-editor' ); ?>" aria-label="<?php esc_attr_e( 'Link address', 'df-editor' ); ?>" data-df-link-input>
						<button class="df-inhalt-popup__button df-inhalt-popup__button--quiet" type="button" data-df-link-apply>
							<?php esc_html_e( 'Apply', 'df-editor' ); ?>
						</button>
					</div>
				</div>
				<div class="df-inhalt-popup__button-editor" data-df-button-editor hidden>
					<label class="df-inhalt-popup__field">
						<span class="df-inhalt-popup__field-label"><?php esc_html_e( 'Text', 'df-editor' ); ?></span>
						<input class="df-inhalt-popup__input" type="text" autocomplete="off" data-df-button-text>
					</label>
					<label class="df-inhalt-popup__field">
						<span class="df-inhalt-popup__field-label"><?php esc_html_e( 'Link', 'df-editor' ); ?></span>
						<input class="df-inhalt-popup__input" type="text" autocomplete="off" inputmode="url" placeholder="<?php esc_attr_e( 'https://example.com or /contact', 'df-editor' ); ?>" data-df-button-url>
					</label>
				</div>
				<div class="df-inhalt-popup__image-editor" data-df-image-editor hidden>
					<div class="df-inhalt-popup__image-preview">
						<img src="" alt="" data-df-image-preview>
					</div>
					<button class="df-inhalt-popup__replace" type="button" data-df-replace-image>
						<?php esc_html_e( 'Replace image', 'df-editor' ); ?>
					</button>
				</div>
				<p class="df-inhalt-popup__error" data-df-error hidden></p>
				<div class="df-inhalt-popup__actions">
					<button class="df-inhalt-popup__button df-inhalt-popup__button--quiet" type="button" data-df-cancel>
						<?php esc_html_e( 'Close', 'df-editor' ); ?>
					</button>
					<button class="df-inhalt-popup__button df-inhalt-popup__button--primary" type="submit" data-df-save>
						<?php esc_html_e( 'Save changes', 'df-editor' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Return a cache-busting asset version.
	 *
	 * @param string $relative_path Plugin-relative path.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$path = DF_INHALT_PLUGIN_PATH . $relative_path;

		return file_exists( $path ) ? (string) filemtime( $path ) : DF_INHALT_VERSION;
	}
}
