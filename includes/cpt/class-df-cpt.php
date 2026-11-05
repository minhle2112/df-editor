<?php
/**
 * DF_CPT: optional post types "Team" (df_team) and "Referenzen" (df_referenz).
 *
 * - Opt-in via the filter df_editor_cpts (default: both enabled).
 * - No single pages, no archives, not in search (see register()).
 * - Meta registered with register_post_meta() (REST, auth_callback) + a simple meta box;
 *   REST responses drop the meta for users who cannot edit the post (staff data, DSGVO).
 * - Order by menu_order, with a small drag-and-drop / keyboard sort on the list table.
 *
 * Self-contained: loaded by df-editor.php (glob of includes/cpt/*.php) on plugins_loaded.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DF_CPT' ) ) :

	final class DF_CPT {

		/** AJAX action + nonce action for the list sort. */
		public const SORT_ACTION = 'df_cpt_sort';

		/** Capability needed to reorder entries. */
		public const SORT_CAP = 'edit_others_posts';

		/** Meta box nonce. */
		private const NONCE = 'df_cpt_meta_nonce';

		private static bool $booted = false;

		public static function init(): void {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			// plugins_loaded runs before init, so these hooks always fire.
			add_action( 'init', array( __CLASS__, 'register' ), 9 );
			add_action( 'template_redirect', array( __CLASS__, 'block_single_views' ) );
			add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 10, 2 );
			add_action( 'init', array( __CLASS__, 'rest_hooks' ), 20 );

			if ( is_admin() ) {
				add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
				add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
				add_action( 'pre_get_posts', array( __CLASS__, 'admin_order' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
				add_action( 'wp_ajax_' . self::SORT_ACTION, array( __CLASS__, 'ajax_sort' ) );
				add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
			}
		}

		/**
		 * Definitions of all known post types (labels, supports, meta).
		 */
		public static function definitions(): array {
			return array(
				'df_team'     => array(
					'labels'    => array(
						'name'               => __( 'Team', 'df-editor' ),
						'singular_name'      => __( 'Team member', 'df-editor' ),
						'menu_name'          => __( 'Team', 'df-editor' ),
						'add_new'            => __( 'Add member', 'df-editor' ),
						'add_new_item'       => __( 'Add team member', 'df-editor' ),
						'edit_item'          => __( 'Edit team member', 'df-editor' ),
						'new_item'           => __( 'New team member', 'df-editor' ),
						'view_item'          => __( 'View team member', 'df-editor' ),
						'search_items'       => __( 'Search team members', 'df-editor' ),
						'not_found'          => __( 'No team members found.', 'df-editor' ),
						'not_found_in_trash' => __( 'No team members found in Trash.', 'df-editor' ),
						'all_items'          => __( 'All team members', 'df-editor' ),
						'featured_image'     => __( 'Photo', 'df-editor' ),
						'set_featured_image' => __( 'Set photo', 'df-editor' ),
						'remove_featured_image' => __( 'Remove photo', 'df-editor' ),
						'use_featured_image' => __( 'Use as photo', 'df-editor' ),
					),
					'icon'      => 'dashicons-groups',
					'supports'  => array( 'title', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
					'title'     => __( 'Full name', 'df-editor' ),
					'meta'      => array(
						'_df_role'     => array(
							'type'  => 'string',
							'label' => __( 'Role / position', 'df-editor' ),
							'input' => 'text',
						),
						'_df_email'    => array(
							'type'  => 'string',
							'label' => __( 'Email', 'df-editor' ),
							'input' => 'email',
						),
						'_df_phone'    => array(
							'type'  => 'string',
							'label' => __( 'Phone', 'df-editor' ),
							'input' => 'tel',
						),
						'_df_linkedin' => array(
							'type'  => 'string',
							'label' => __( 'LinkedIn URL', 'df-editor' ),
							'input' => 'url',
						),
					),
				),
				'df_referenz' => array(
					'labels'    => array(
						'name'               => __( 'Testimonials', 'df-editor' ),
						'singular_name'      => __( 'Testimonial', 'df-editor' ),
						'menu_name'          => __( 'Testimonials', 'df-editor' ),
						'add_new'            => __( 'Add testimonial', 'df-editor' ),
						'add_new_item'       => __( 'Add testimonial', 'df-editor' ),
						'edit_item'          => __( 'Edit testimonial', 'df-editor' ),
						'new_item'           => __( 'New testimonial', 'df-editor' ),
						'view_item'          => __( 'View testimonial', 'df-editor' ),
						'search_items'       => __( 'Search testimonials', 'df-editor' ),
						'not_found'          => __( 'No testimonials found.', 'df-editor' ),
						'not_found_in_trash' => __( 'No testimonials found in Trash.', 'df-editor' ),
						'all_items'          => __( 'All testimonials', 'df-editor' ),
						'featured_image'     => __( 'Photo / logo', 'df-editor' ),
						'set_featured_image' => __( 'Set photo / logo', 'df-editor' ),
						'remove_featured_image' => __( 'Remove photo / logo', 'df-editor' ),
						'use_featured_image' => __( 'Use as photo / logo', 'df-editor' ),
					),
					'icon'      => 'dashicons-format-quote',
					'supports'  => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields' ),
					'title'     => __( 'Name of the person', 'df-editor' ),
					'meta'      => array(
						'_df_company' => array(
							'type'  => 'string',
							'label' => __( 'Company', 'df-editor' ),
							'input' => 'text',
						),
						'_df_rating'  => array(
							'type'  => 'integer',
							'label' => __( 'Rating (0 = no stars)', 'df-editor' ),
							'input' => 'rating',
						),
					),
				),
			);
		}

		/**
		 * Enabled post types (filter df_editor_cpts), limited to known ones.
		 *
		 * @return string[]
		 */
		public static function enabled(): array {
			/**
			 * Filters which DF post types are registered.
			 *
			 * @param string[] $types Post type keys. Default: df_team, df_referenz.
			 */
			$types = (array) apply_filters( 'df_editor_cpts', array( 'df_team', 'df_referenz' ) );
			return array_values( array_intersect( array_keys( self::definitions() ), array_map( 'strval', $types ) ) );
		}

		public static function is_enabled( string $type ): bool {
			return in_array( $type, self::enabled(), true ) && post_type_exists( $type );
		}

		/**
		 * Registers the enabled post types and their meta.
		 *
		 * Visibility: public=false and no rewrite / archive / search / query var, so there are no
		 * front-end URLs. publicly_queryable stays true only because DF Inhalt's client mode lets
		 * the "Clients" role edit a post type only when it is public or publicly_queryable;
		 * block_single_views() returns a 404 for any front-end request naming a DF type
		 * (?post_type=…&p=…, ?post_type=… listings, searches and feeds).
		 * Filter df_editor_cpt_args to change this per type.
		 */
		public static function register(): void {
			$defs = self::definitions();
			foreach ( self::enabled() as $type ) {
				$def  = $defs[ $type ];
				$args = array(
					'labels'              => $def['labels'],
					'public'              => false,
					'publicly_queryable'  => true,
					'exclude_from_search' => true,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_nav_menus'   => false,
					'show_in_admin_bar'   => true,
					'show_in_rest'        => true,
					'rest_base'           => str_replace( '_', '-', $type ),
					'menu_position'       => 21,
					'menu_icon'           => $def['icon'],
					'hierarchical'        => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
					'supports'            => $def['supports'],
				);

				/**
				 * Filters the register_post_type() args of a DF post type.
				 *
				 * @param array  $args Arguments.
				 * @param string $type Post type key.
				 */
				register_post_type( $type, (array) apply_filters( 'df_editor_cpt_args', $args, $type ) );

				foreach ( $def['meta'] as $key => $meta ) {
					register_post_meta(
						$type,
						$key,
						array(
							'type'              => $meta['type'],
							'single'            => true,
							'default'           => 'integer' === $meta['type'] ? 0 : '',
							'show_in_rest'      => true,
							'sanitize_callback' => static fn( $value ) => self::sanitize( $key, $value ),
							'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
						)
					);
				}
			}
		}

		/**
		 * REST: strip DF meta (staff contact data, DSGVO) for users who cannot edit the post.
		 */
		public static function rest_hooks(): void {
			foreach ( self::enabled() as $type ) {
				add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_strip_meta' ), 10, 2 );
			}
		}

		/**
		 * @param mixed $response Response (normally WP_REST_Response).
		 * @param mixed $post     Post.
		 * @return mixed
		 */
		public static function rest_strip_meta( $response, $post ) {
			if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
				return $response;
			}
			$data = $response->get_data();
			if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				$defs = self::definitions();
				foreach ( array_keys( $defs[ $post->post_type ]['meta'] ?? array() ) as $key ) {
					unset( $data['meta'][ $key ] );
				}
				$response->set_data( $data );
			}
			return $response;
		}

		/**
		 * Sanitizes one meta value by key.
		 */
		public static function sanitize( string $key, mixed $value ): mixed {
			$value = is_scalar( $value ) ? (string) $value : '';
			switch ( $key ) {
				case '_df_email':
					return sanitize_email( $value );
				case '_df_phone':
					// Digits, spaces and + ( ) / - . only.
					return trim( (string) preg_replace( '/[^0-9+()\/\-. ]/', '', $value ) );
				case '_df_linkedin':
					$url = esc_url_raw( trim( $value ), array( 'https', 'http' ) );
					return $url;
				case '_df_rating':
					return max( 0, min( 5, (int) $value ) );
				default:
					return sanitize_text_field( $value );
			}
		}

		/**
		 * No front-end views: 404 (with an empty result) for singles, listings, searches and
		 * feeds of DF post types.
		 */
		public static function block_single_views(): void {
			if ( is_admin() || is_preview() ) {
				return;
			}
			$enabled = self::enabled();
			// has_archive=false means ?post_type=df_team is not flagged as a post-type archive, so
			// also catch any main query (home, search, feed) whose post_type var names a DF type.
			$queried = array_map( 'strval', array_filter( (array) get_query_var( 'post_type' ), 'is_scalar' ) );
			if ( $enabled && ( is_singular( $enabled ) || is_post_type_archive( $enabled ) || array_intersect( $queried, $enabled ) ) ) {
				global $wp_query;
				$wp_query->set_404();
				// set_404() keeps is_feed and the fetched posts: empty them so feeds list nothing.
				$wp_query->posts       = array();
				$wp_query->post        = null;
				$wp_query->post_count  = 0;
				$wp_query->found_posts = 0;
				status_header( 404 );
				nocache_headers();
			}
		}

		/**
		 * Simple classic edit screen for DF post types (title, photo, excerpt, meta box).
		 */
		public static function classic_editor( bool $use, string $post_type ): bool {
			return in_array( $post_type, self::enabled(), true ) ? false : $use;
		}

		public static function title_placeholder( string $text, \WP_Post $post ): string {
			$defs = self::definitions();
			return isset( $defs[ $post->post_type ] ) && in_array( $post->post_type, self::enabled(), true ) ? $defs[ $post->post_type ]['title'] : $text;
		}

		/* ---------- Meta box ---------- */

		public static function add_meta_boxes(): void {
			foreach ( self::enabled() as $type ) {
				add_meta_box(
					'df-cpt-details',
					'df_team' === $type ? __( 'Role & contact', 'df-editor' ) : __( 'Testimonial details', 'df-editor' ),
					array( __CLASS__, 'render_meta_box' ),
					$type,
					'normal',
					'high'
				);
			}
		}

		public static function render_meta_box( \WP_Post $post ): void {
			$defs = self::definitions();
			if ( ! isset( $defs[ $post->post_type ] ) ) {
				return;
			}
			wp_nonce_field( self::NONCE, self::NONCE );
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $defs[ $post->post_type ]['meta'] as $key => $meta ) {
				$id    = 'df-cpt' . str_replace( '_', '-', $key );
				$value = get_post_meta( $post->ID, $key, true );
				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $meta['label'] ) . '</label></th><td>';
				if ( 'rating' === $meta['input'] ) {
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
					for ( $i = 0; $i <= 5; $i++ ) {
						printf(
							'<option value="%1$d"%2$s>%3$s</option>',
							(int) $i,
							selected( (int) $value, $i, false ),
							esc_html( 0 === $i ? __( 'No rating', 'df-editor' ) : sprintf( /* translators: %d: number of stars */ _n( '%d star', '%d stars', $i, 'df-editor' ), $i ) )
						);
					}
					echo '</select>';
				} else {
					printf(
						'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text"%5$s>',
						esc_attr( $meta['input'] ),
						esc_attr( $id ),
						esc_attr( $key ),
						esc_attr( (string) $value ),
						'url' === $meta['input'] ? ' placeholder="https://www.linkedin.com/in/…"' : ''
					);
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			if ( 'df_team' === $post->post_type ) {
				echo '<p class="description">' . esc_html__( 'Photo = featured image, short bio = excerpt. Empty contact fields are not shown on the website.', 'df-editor' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Quote = main text, name = title.', 'df-editor' ) . '</p>';
			}
		}

		public static function save_meta( int $post_id, \WP_Post $post ): void {
			$defs = self::definitions();
			if ( ! isset( $defs[ $post->post_type ] ) || ! in_array( $post->post_type, self::enabled(), true ) ) {
				return;
			}
			if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
				return;
			}
			if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			foreach ( array_keys( $defs[ $post->post_type ]['meta'] ) as $key ) {
				if ( ! isset( $_POST[ $key ] ) ) {
					continue;
				}
				$value = self::sanitize( $key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by key.
				if ( '' === $value || ( '_df_rating' === $key && 0 === $value ) ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		/* ---------- Ordering ---------- */

		/**
		 * Admin list: order by menu_order unless the user picked another column.
		 */
		public static function admin_order( \WP_Query $query ): void {
			if ( ! $query->is_main_query() || ! function_exists( 'get_current_screen' ) ) {
				return;
			}
			$type = (string) $query->get( 'post_type' );
			if ( ! in_array( $type, self::enabled(), true ) || ! empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$query->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
		}

		/**
		 * Whether the sort UI is usable on the current list screen.
		 */
		private static function sort_screen(): string {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, self::enabled(), true ) ) {
				return '';
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! current_user_can( self::SORT_CAP ) || ! empty( $_GET['orderby'] ) || ! empty( $_GET['s'] ) || ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) ) {
				return '';
			}
			// phpcs:enable
			return $screen->post_type;
		}

		public static function admin_assets(): void {
			$type = self::sort_screen();
			if ( '' === $type ) {
				return;
			}
			$file = __DIR__ . '/sort.js';
			$ver  = ( defined( 'DF_EDITOR_VERSION' ) ? DF_EDITOR_VERSION : '0' ) . '.' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_enqueue_script( 'df-cpt-sort', plugins_url( 'sort.js', __FILE__ ), array(), $ver, true );

			$per_page = (int) get_user_option( 'edit_' . $type . '_per_page' );
			$per_page = $per_page > 0 ? $per_page : 20;
			$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_localize_script(
				'df-cpt-sort',
				'dfCptSort',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'action'   => self::SORT_ACTION,
					'nonce'    => wp_create_nonce( self::SORT_ACTION ),
					'postType' => $type,
					'offset'   => ( $paged - 1 ) * $per_page,
					'i18n'     => array(
						'handle' => __( 'Drag to reorder, or focus and use the arrow keys', 'df-editor' ),
						'saving' => __( 'Saving order…', 'df-editor' ),
						'saved'  => __( 'Order saved.', 'df-editor' ),
						'error'  => __( 'The order could not be saved. Please reload the page.', 'df-editor' ),
						'moved'  => __( 'Moved to position %d.', 'df-editor' ),
					),
				)
			);

			wp_register_style( 'df-cpt-sort', false, array(), $ver );
			wp_enqueue_style( 'df-cpt-sort' );
			wp_add_inline_style(
				'df-cpt-sort',
				'.df-cpt-sort__handle{cursor:grab;border:0;background:none;padding:2px 4px;color:#50575e;font-size:18px;line-height:1;vertical-align:middle}'
				. '.df-cpt-sort__handle:focus{outline:2px solid #2271b1;outline-offset:1px}'
				. '#the-list tr.df-cpt-sort--dragging{opacity:.5}'
				. '#the-list tr.df-cpt-sort--over td,#the-list tr.df-cpt-sort--over th{box-shadow:inset 0 2px 0 #2271b1}'
				. '.df-cpt-sort__status{margin:8px 0}'
				. '.fixed .column-df_sort{width:2.5em}.fixed .column-df_photo{width:56px}'
			);
		}

		/**
		 * AJAX: saves the order of the rows on the current list page.
		 * POST: nonce, post_type, offset, ids[] (in display order).
		 */
		public static function ajax_sort(): void {
			check_ajax_referer( self::SORT_ACTION, 'nonce' );
			if ( ! current_user_can( self::SORT_CAP ) ) {
				wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			}
			$type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
			if ( ! in_array( $type, self::enabled(), true ) ) {
				wp_send_json_error( array( 'message' => 'invalid post type' ), 400 );
			}
			$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
			$ids    = array_values( array_unique( array_filter( $ids ) ) );
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			if ( ! $ids || count( $ids ) > 500 ) {
				wp_send_json_error( array( 'message' => 'invalid ids' ), 400 );
			}

			global $wpdb;
			$saved = 0;
			foreach ( $ids as $i => $id ) {
				if ( get_post_type( $id ) !== $type || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$order = $offset + $i;
				if ( (int) get_post_field( 'menu_order', $id ) !== $order ) {
					// Direct update: no revisions, no modified date change, no save_post side effects.
					$wpdb->update( $wpdb->posts, array( 'menu_order' => $order ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					clean_post_cache( $id );
				}
				++$saved;
			}
			wp_send_json_success( array( 'saved' => $saved ) );
		}

		/**
		 * Adds the sort handle column (only when sorting is possible on this screen).
		 */
		public static function columns( array $columns ): array {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$type   = (string) ( $screen?->post_type ?? '' );
			$sort   = '' !== self::sort_screen();
			$out    = array();
			foreach ( $columns as $key => $label ) {
				if ( 'title' === $key && $sort ) {
					$out['df_sort'] = '<span class="screen-reader-text">' . esc_html__( 'Order', 'df-editor' ) . '</span>';
				}
				if ( 'title' === $key && 'df_team' === $type ) {
					$out['df_photo'] = esc_html__( 'Photo', 'df-editor' );
				}
				$out[ $key ] = $label;
			}
			if ( 'df_team' === $type ) {
				$out['df_role'] = esc_html__( 'Role', 'df-editor' );
			}
			if ( 'df_referenz' === $type ) {
				$out['df_company'] = esc_html__( 'Company', 'df-editor' );
			}
			return $out;
		}

		public static function column( string $column, int $post_id ): void {
			switch ( $column ) {
				case 'df_sort':
					printf(
						'<button type="button" class="df-cpt-sort__handle" data-df-sort-handle aria-label="%s">&#8942;&#8942;</button>',
						esc_attr( sprintf( /* translators: %s: post title */ __( 'Reorder “%s”', 'df-editor' ), get_the_title( $post_id ) ) )
					);
					break;
				case 'df_photo':
					echo get_the_post_thumbnail( $post_id, array( 48, 48 ), array( 'style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'df_role':
					echo esc_html( (string) get_post_meta( $post_id, '_df_role', true ) );
					break;
				case 'df_company':
					echo esc_html( (string) get_post_meta( $post_id, '_df_company', true ) );
					$rating = (int) get_post_meta( $post_id, '_df_rating', true );
					if ( $rating > 0 ) {
						echo ' <span aria-label="' . esc_attr( sprintf( /* translators: %d: stars */ __( '%d of 5 stars', 'df-editor' ), $rating ) ) . '">' . esc_html( str_repeat( '★', $rating ) ) . '</span>';
					}
					break;
			}
		}

		/**
		 * Hooks the list table columns (needs the post types, so after init).
		 */
		public static function admin_columns(): void {
			foreach ( self::enabled() as $type ) {
				add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
				add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
			}
		}

		/**
		 * Front-end helper: published posts of a type in menu order.
		 *
		 * @return \WP_Post[]
		 */
		public static function query( string $type, int $limit = -1 ): array {
			if ( ! self::is_enabled( $type ) ) {
				return array();
			}
			return get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'posts_per_page'   => $limit > 0 ? $limit : 100,
					'has_password'     => false,
					'orderby'          => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);
		}
	}

	DF_CPT::init();
	if ( is_admin() ) {
		add_action( 'admin_init', array( 'DF_CPT', 'admin_columns' ) );
	}

endif;
