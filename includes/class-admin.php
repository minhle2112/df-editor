<?php
/**
 * Admin: "DF Layout (JSON)" metabox for developers and block editor switch-off on DF pages.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Admin {

	private const NONCE   = 'df_layout_nonce';
	private const FIELD   = 'df_layout_json';
	private const CAP     = 'manage_options';
	private const NOTICE  = 'df_layout_notice_';

	public static function init(): void {
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_ajax_df_validate_layout', array( __CLASS__, 'ajax_validate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'use_block_editor' ), 10, 2 );
		add_action( 'load-post.php', array( __CLASS__, 'maybe_remove_editor' ) );
	}

	/**
	 * DF layout pages (non-empty _df_layout) are edited through the layout; only those with a
	 * "seiteninhalt" section keep the editor. Pages without _df_layout use the block editor normally.
	 */
	private static function needs_editor( int $post_id ): bool {
		$layout = DF_Layout_Store::get( $post_id );
		return null === $layout || DF_Layout_Store::has_section_type( $layout, 'seiteninhalt' );
	}

	public static function use_block_editor( bool $use, $post ): bool {
		if ( $post instanceof WP_Post && 'page' === $post->post_type && ! self::needs_editor( $post->ID ) ) {
			return false;
		}
		return $use;
	}

	/**
	 * Classic screen: hide the content editor too.
	 */
	public static function maybe_remove_editor(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $post_id && 'page' === get_post_type( $post_id ) && ! self::needs_editor( $post_id ) ) {
			remove_post_type_support( 'page', 'editor' );
		}
	}

	public static function add_metabox(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		add_meta_box( 'df-layout', __( 'DF Layout (JSON)', 'df-editor' ), array( __CLASS__, 'render_metabox' ), 'page', 'normal', 'high' );
	}

	public static function render_metabox( WP_Post $post ): void {
		$layout = DF_Layout_Store::get( $post->ID );
		$json   = $layout ? wp_json_encode( $layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
		$key    = (string) get_post_meta( $post->ID, DF_Layout_Store::META_KEY, true );
		wp_nonce_field( 'df_layout_save_' . $post->ID, self::NONCE );
		?>
		<p>
			<?php esc_html_e( 'Page key:', 'df-editor' ); ?>
			<code><?php echo esc_html( '' !== $key ? $key : __( '(assigned on first save)', 'df-editor' ) ); ?></code>
			&middot;
			<?php
			/* translators: %d: layout revision */
			printf( esc_html__( 'Revision: %d', 'df-editor' ), (int) ( $layout['rev'] ?? 0 ) );
			?>
			&middot;
			<?php
			/* translators: %s: section types */
			printf( esc_html__( 'Available sections: %s', 'df-editor' ), '<code>' . esc_html( implode( ', ', array_keys( DF_Registry::all() ) ) ) . '</code>' );
			?>
		</p>
		<textarea name="<?php echo esc_attr( self::FIELD ); ?>" id="df-layout-json" rows="20" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px" spellcheck="false"><?php echo esc_textarea( (string) $json ); ?></textarea>
		<p>
			<button type="button" class="button" id="df-layout-validate"><?php esc_html_e( 'Validate', 'df-editor' ); ?></button>
			<span class="description"><?php esc_html_e( 'Empty the field and update the page to remove the layout.', 'df-editor' ); ?></span>
		</p>
		<div id="df-layout-result" aria-live="polite"></div>
		<script>
		( function () {
			var btn = document.getElementById( 'df-layout-validate' );
			var out = document.getElementById( 'df-layout-result' );
			btn.addEventListener( 'click', function () {
				var body = new FormData();
				body.append( 'action', 'df_validate_layout' );
				body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'df_validate_layout' ) ); ?> );
				body.append( 'json', document.getElementById( 'df-layout-json' ).value );
				out.textContent = '…';
				fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						var d = r.data || {}, html = '';
						var list = function ( items, cls ) {
							return items && items.length ? '<div class="notice inline ' + cls + '"><ul>' + items.map( function ( m ) {
								var li = document.createElement( 'li' ); li.textContent = m; return li.outerHTML;
							} ).join( '' ) + '</ul></div>' : '';
						};
						html += list( d.errors, 'notice-error' ) + list( d.warnings, 'notice-warning' );
						if ( ! html ) { html = '<div class="notice inline notice-success"><p>' + <?php echo wp_json_encode( __( 'Layout is valid.', 'df-editor' ) ); ?> + '</p></div>'; }
						out.innerHTML = html;
					} )
					.catch( function () { out.textContent = <?php echo wp_json_encode( __( 'Validation request failed.', 'df-editor' ) ); ?>; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Decodes JSON text; returns [layout|null, error messages].
	 */
	private static function decode( string $json ): array {
		$layout = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $layout ) ) {
			/* translators: %s: JSON error message */
			return array( null, array( sprintf( __( 'Invalid JSON: %s', 'df-editor' ), json_last_error_msg() ) ) );
		}
		return array( $layout, array() );
	}

	public static function ajax_validate(): void {
		check_ajax_referer( 'df_validate_layout', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'errors' => array( __( 'Not allowed.', 'df-editor' ) ) ), 403 );
		}
		$json = isset( $_POST['json'] ) ? wp_unslash( (string) $_POST['json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		[ $layout, $errors ] = self::decode( $json );
		if ( null === $layout ) {
			wp_send_json_success( array( 'errors' => $errors, 'warnings' => array() ) );
		}
		wp_send_json_success( DF_Validator::validate( $layout ) );
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ], $_POST[ self::FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'df_layout_save_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( self::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$json = trim( wp_unslash( (string) $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $json ) {
			if ( DF_Layout_Store::has_layout( $post_id ) ) {
				DF_Layout_Store::delete( $post_id );
				self::notice( 'success', array( __( 'DF layout removed.', 'df-editor' ) ) );
			}
			return;
		}

		[ $layout, $errors ] = self::decode( $json );
		if ( null === $layout ) {
			self::notice( 'error', array_merge( array( __( 'DF layout NOT saved.', 'df-editor' ) ), $errors ) );
			return;
		}
		if ( ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			self::notice( 'error', array( __( 'DF layout NOT saved.', 'df-editor' ), __( 'The layout needs a "sections" array.', 'df-editor' ) ) );
			return;
		}

		// Somebody published (CLI, another editor) after this screen was loaded: do not overwrite silently.
		$current_rev = DF_Layout_Store::rev( $post_id );
		if ( isset( $layout['rev'] ) && is_numeric( $layout['rev'] ) && (int) $layout['rev'] !== $current_rev && DF_Layout_Store::has_layout( $post_id ) ) {
			self::notice(
				'error',
				array(
					__( 'DF layout NOT saved.', 'df-editor' ),
					/* translators: 1: revision in the form, 2: current revision */
					sprintf( __( 'The layout was changed in the meantime (revision %1$d in this form, %2$d now). Reload the page and apply your changes again.', 'df-editor' ), (int) $layout['rev'], $current_rev ),
				)
			);
			return;
		}

		$result = DF_Validator::validate( $layout );
		// Saved even with validation errors: unknown sections may come from an inactive theme; the renderer skips invalid entries.
		// Publish path: history + rev + 1 + plain-text fallback (wp_update_post inside would re-enter this hook).
		remove_action( 'save_post_page', array( __CLASS__, 'save' ), 10 );
		$published = DF_Layout_Store::publish( $post_id, $layout );
		add_action( 'save_post_page', array( __CLASS__, 'save' ), 10, 2 );
		if ( ! $published['ok'] ) {
			self::notice( 'error', array( __( 'DF layout NOT saved.', 'df-editor' ) ) );
			return;
		}

		if ( $result['errors'] ) {
			self::notice( 'error', array_merge( array( __( 'DF layout saved with errors:', 'df-editor' ) ), $result['errors'], $result['warnings'] ) );
		} elseif ( $result['warnings'] ) {
			self::notice( 'warning', array_merge( array( __( 'DF layout saved with warnings:', 'df-editor' ) ), $result['warnings'] ) );
		}
	}

	private static function notice( string $type, array $messages ): void {
		set_transient( self::NOTICE . get_current_user_id(), array( $type, $messages ), 120 );
	}

	public static function notices(): void {
		$data = get_transient( self::NOTICE . get_current_user_id() );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( self::NOTICE . get_current_user_id() );
		[ $type, $messages ] = $data;
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><ul>';
		foreach ( (array) $messages as $m ) {
			echo '<li>' . esc_html( (string) $m ) . '</li>';
		}
		echo '</ul></div>';
	}
}
