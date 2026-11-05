<?php
/**
 * DF Panel REST API (namespace df/v1, routes under /panel/…).
 *
 * Cookie authentication: the panel sends the standard wp_rest nonce in X-WP-Nonce. Every route
 * requires a logged-in user with df_edit_layout or df_reorder_sections (see DF_Capabilities).
 *
 * Saving model: every change goes into the draft (_df_layout_draft); "publish" copies it live,
 * pushes the old live layout into the history (max 30) and increments rev. base_rev guards
 * against overwriting a newer live layout; the edit lock (_df_layout_lock, 120 s) against two
 * people editing the same page.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Panel_API {

	public const NS = 'df/v1';

	/** Route regex for the post ID. */
	private const ID = '(?P<id>\d+)';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat' ), 10, 2 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 999 );
	}

	/* --------------------------------------------------------------- Routes */

	public static function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		register_rest_route(
			self::NS,
			'/panel/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_pages' ),
				'permission_callback' => array( __CLASS__, 'can_use_panel' ),
			)
		);

		register_rest_route(
			self::NS,
			'/panel/sections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sections' ),
				'permission_callback' => array( __CLASS__, 'can_use_panel' ),
			)
		);

		register_rest_route(
			self::NS,
			'/panel/layout/' . self::ID,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_layout' ),
				'permission_callback' => array( __CLASS__, 'can_access_post' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NS,
			'/panel/layout/' . self::ID . '/draft',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'put_draft' ),
					'permission_callback' => array( __CLASS__, 'can_access_post' ),
					'args'                => $id_arg + array(
						'layout'   => array(
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'The complete layout (format, page_key, sections).', 'df-editor' ),
						),
						'base_rev' => array(
							'type'        => 'integer',
							'required'    => true,
							'minimum'     => 0,
							'description' => __( 'Live revision the layout was made from.', 'df-editor' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_draft' ),
					'permission_callback' => array( __CLASS__, 'can_access_post' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/panel/layout/' . self::ID . '/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'publish' ),
				'permission_callback' => array( __CLASS__, 'can_access_post' ),
				'args'                => $id_arg + array(
					'base_rev' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/panel/layout/' . self::ID . '/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => array( __CLASS__, 'can_access_post' ),
				'args'                => $id_arg + array(
					'rev' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/panel/layout/' . self::ID . '/lock',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'take_lock' ),
					'permission_callback' => array( __CLASS__, 'can_access_post' ),
					'args'                => $id_arg + array(
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'release_lock' ),
					'permission_callback' => array( __CLASS__, 'can_access_post' ),
					'args'                => $id_arg,
				),
			)
		);

		$instance_arg = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-z0-9][a-z0-9_-]*$',
		);
		register_rest_route(
			self::NS,
			'/panel/duplicate-content',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_content' ),
				'permission_callback' => array( __CLASS__, 'can_duplicate' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'from_id' => $instance_arg,
					'to_id'   => $instance_arg,
				),
			)
		);
	}

	/* ---------------------------------------------------------- Permissions */

	public static function can_use_panel(): bool {
		return DF_Capabilities::can_use_panel();
	}

	/**
	 * Post routes: the post must exist (not trashed) and the user needs panel rights for it.
	 * Posts without any DF layout are only open to full layout editors (pages only).
	 *
	 * @return true|WP_Error
	 */
	public static function can_access_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status || 'revision' === $post->post_type ) {
			return new WP_Error( 'df_not_found', __( 'Page not found.', 'df-editor' ), array( 'status' => 404 ) );
		}
		if ( ! DF_Capabilities::can_reorder( $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to change the layout of this page.', 'df-editor' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$has_layout = DF_Layout_Store::has_layout( $post_id ) || DF_Layout_Store::has_draft( $post_id );
		if ( ! $has_layout && ! ( 'page' === $post->post_type && DF_Capabilities::can_edit_layout( $post_id ) ) ) {
			return new WP_Error( 'df_no_layout', __( 'This page has no DF layout.', 'df-editor' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function can_duplicate( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'df_not_found', __( 'Page not found.', 'df-editor' ), array( 'status' => 404 ) );
		}
		if ( ! DF_Capabilities::can_edit_layout( $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to change the layout of this page.', 'df-editor' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/* ------------------------------------------------------------- Handlers */

	/**
	 * GET /panel/pages
	 */
	public static function get_pages(): WP_REST_Response {
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'meta_key'         => DF_Layout_Store::META_LAYOUT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'suppress_filters' => false,
			)
		);

		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$out   = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! DF_Capabilities::can_reorder( $id ) || ! DF_Layout_Store::has_layout( $id ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $id,
				'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'       => (string) get_permalink( $id ),
				'page_key'  => DF_Layout_Store::read_key( $id ),
				'has_draft' => DF_Layout_Store::has_draft( $id ),
				'status'    => (string) get_post_status( $id ),
				'is_front'  => $id === $front,
			);
		}
		// Front page first.
		usort( $out, static fn( $a, $b ) => (int) $b['is_front'] <=> (int) $a['is_front'] );
		return rest_ensure_response( $out );
	}

	/**
	 * GET /panel/sections
	 */
	public static function get_sections(): WP_REST_Response {
		$sections = array();
		$all      = DF_Registry::all();
		ksort( $all );
		foreach ( $all as $type => $def ) {
			$sections[] = self::section_schema( (string) $type, $def );
		}

		/**
		 * Filters the section library shown in the DF Panel (e.g. hide agency-only sections).
		 *
		 * @param array $sections Section descriptions.
		 */
		$sections = (array) apply_filters( 'df_panel_sections', $sections );

		return rest_ensure_response(
			array_merge(
				array( 'sections' => array_values( $sections ) ),
				self::common_choices()
			)
		);
	}

	/**
	 * Editor-facing description of one section definition (DF Panel library, DF blocks):
	 * type, label, description, version, variants [{value,label}], options
	 * [{key,label,values:[{value,label}],default,variants|null}], list {min,max,default_count}|null
	 * and preview_url|null.
	 *
	 * @param string $type Section type.
	 * @param array  $def  Definition from DF_Registry.
	 */
	public static function section_schema( string $type, array $def ): array {
		$variants = array();
		foreach ( (array) $def['variants'] as $value => $label ) {
			$variants[] = array(
				'value' => (string) $value,
				'label' => is_string( $label ) && '' !== $label ? $label : self::humanize( (string) $value ),
			);
		}

		$options = array();
		foreach ( (array) $def['options'] as $key => $opt ) {
			$opt    = is_array( $opt ) ? $opt : array();
			$labels = (array) ( $opt['labels'] ?? array() );
			$values = array();
			foreach ( array_map( 'strval', (array) ( $opt['values'] ?? array() ) ) as $value ) {
				$values[] = array(
					'value' => $value,
					'label' => isset( $labels[ $value ] ) && is_string( $labels[ $value ] ) ? $labels[ $value ] : self::humanize( $value ),
				);
			}
			$allowed   = array_column( $values, 'value' );
			$options[] = array(
				'key'      => (string) $key,
				'label'    => (string) ( $opt['label'] ?? self::humanize( (string) $key ) ),
				'values'   => $values,
				'default'  => (string) ( $opt['default'] ?? ( $allowed[0] ?? '' ) ),
				'variants' => isset( $opt['variants'] ) ? array_values( array_map( 'strval', (array) $opt['variants'] ) ) : null,
			);
		}

		$list = null;
		if ( ! empty( $def['list'] ) && is_array( $def['list'] ) ) {
			$list = array(
				'min'           => (int) $def['list']['min'],
				'max'           => (int) $def['list']['max'],
				'default_count' => (int) $def['list']['default_count'],
			);
		}

		$preview = is_readable( $def['dir'] . 'preview.png' ) ? $def['url'] . 'preview.png?ver=' . (string) filemtime( $def['dir'] . 'preview.png' ) : null;

		return array(
			'type'        => $type,
			'label'       => (string) $def['label'],
			'description' => (string) ( $def['description'] ?? '' ),
			'version'     => (int) $def['version'],
			'variants'    => $variants,
			'options'     => $options,
			'list'        => $list,
			'preview_url' => $preview,
		);
	}

	/**
	 * Common section settings as [{value,label}] lists: schemes, spacing, widths.
	 *
	 * @return array{schemes: array, spacing: array, widths: array}
	 */
	public static function common_choices(): array {
		return array(
			'schemes' => self::pairs( self::scheme_labels() ),
			'spacing' => self::pairs( self::spacing_labels() ),
			'widths'  => self::pairs( self::width_labels() ),
		);
	}

	/**
	 * GET /panel/layout/<id>
	 */
	public static function get_layout( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( self::layout_state( (int) $request['id'] ) );
	}

	/**
	 * PUT /panel/layout/<id>/draft
	 */
	public static function put_draft( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$blocked = self::write_guard( $request, (int) $request['base_rev'] );
		if ( $blocked ) {
			return $blocked;
		}

		$layout = $request->get_param( 'layout' );
		if ( ! is_array( $layout ) || ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) || ! array_is_list( $layout['sections'] ) ) {
			return self::error(
				'df_invalid_layout',
				__( 'The layout needs a "sections" array.', 'df-editor' ),
				422,
				array(
					'errors'   => array( __( 'The layout needs a "sections" array.', 'df-editor' ) ),
					'warnings' => array(),
				)
			);
		}
		unset( $layout['base_rev'] );
		$layout['page_key'] = DF_Layout_Store::page_key( $post_id );

		if ( DF_Capabilities::is_reorder_only( $post_id ) ) {
			if ( self::draft_has_foreign_changes( $post_id ) ) {
				return self::foreign_draft_error();
			}
			$base       = DF_Layout_Store::get_draft( $post_id ) ?? DF_Layout_Store::get( $post_id ) ?? self::empty_layout( $post_id );
			$violations = DF_Validator::reorder_only_violations( $base, $layout );
			if ( $violations ) {
				return self::error( 'df_reorder_only', __( 'You may only change the order and visibility of sections.', 'df-editor' ), 403, array( 'violations' => $violations ) );
			}
		}

		$validation = DF_Validator::validate( $layout );
		if ( $validation['errors'] ) {
			return self::error( 'df_invalid_layout', __( 'The layout has errors and was not saved.', 'df-editor' ), 422, $validation );
		}

		if ( ! DF_Layout_Store::set_draft( $post_id, $layout ) ) {
			return self::error( 'df_save_failed', __( 'The draft could not be saved.', 'df-editor' ), 500 );
		}
		DF_Layout_Store::set_lock( $post_id, get_current_user_id(), self::panel_token( $request ) );

		return rest_ensure_response(
			array(
				'draft'       => self::for_output( DF_Layout_Store::get_draft( $post_id ) ),
				'validation'  => $validation,
				// Fresh preview nonce, so a panel left open for a long time keeps previewing the draft.
				'preview_url' => DF_Renderer::preview_url( $post_id ),
			)
		);
	}

	/**
	 * DELETE /panel/layout/<id>/draft
	 */
	public static function delete_draft( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$blocked = self::write_guard( $request );
		if ( $blocked ) {
			return $blocked;
		}
		if ( DF_Capabilities::is_reorder_only( $post_id ) && self::draft_has_foreign_changes( $post_id ) ) {
			return self::foreign_draft_error();
		}
		DF_Layout_Store::delete_draft( $post_id );
		return rest_ensure_response( self::layout_state( $post_id ) );
	}

	/**
	 * POST /panel/layout/<id>/publish
	 */
	public static function publish( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$blocked = self::write_guard( $request, (int) $request['base_rev'] );
		if ( $blocked ) {
			return $blocked;
		}

		$draft = DF_Layout_Store::get_draft( $post_id );
		if ( null === $draft ) {
			return self::error( 'df_no_draft', __( 'There is no draft to publish.', 'df-editor' ), 400 );
		}
		$live_rev = DF_Layout_Store::rev( $post_id );
		if ( (int) $draft['rev'] !== $live_rev ) {
			return self::error(
				'df_stale_draft',
				__( 'The draft is based on an older version of the page. Discard it or restore it again.', 'df-editor' ),
				409,
				array( 'rev' => $live_rev )
			);
		}

		if ( DF_Capabilities::is_reorder_only( $post_id ) ) {
			$violations = DF_Validator::reorder_only_violations( DF_Layout_Store::get( $post_id ) ?? self::empty_layout( $post_id ), $draft );
			if ( $violations ) {
				return self::error( 'df_reorder_only', __( 'The draft contains changes you are not allowed to publish.', 'df-editor' ), 403, array( 'violations' => $violations ) );
			}
		}

		$validation = DF_Validator::validate( $draft );
		if ( $validation['errors'] ) {
			return self::error( 'df_invalid_layout', __( 'The draft has errors and was not published.', 'df-editor' ), 422, $validation );
		}

		$result = DF_Layout_Store::publish( $post_id, $draft );
		if ( ! $result['ok'] ) {
			return self::error( 'df_save_failed', __( 'The layout could not be published.', 'df-editor' ), 500 );
		}
		DF_Layout_Store::set_lock( $post_id, get_current_user_id(), self::panel_token( $request ) );

		$state             = self::layout_state( $post_id );
		$state['fallback'] = $result['fallback'];
		return rest_ensure_response( $state );
	}

	/**
	 * POST /panel/layout/<id>/restore — copies a history entry into the draft (never live).
	 */
	public static function restore( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$blocked = self::write_guard( $request );
		if ( $blocked ) {
			return $blocked;
		}
		$entry = DF_Layout_Store::get_history_entry( $post_id, (int) $request['rev'] );
		if ( null === $entry ) {
			return self::error( 'df_rev_not_found', __( 'This version is not in the history.', 'df-editor' ), 404 );
		}
		$layout = $entry['layout'];
		unset( $layout['rev'] );

		if ( DF_Capabilities::is_reorder_only( $post_id ) ) {
			if ( self::draft_has_foreign_changes( $post_id ) ) {
				return self::foreign_draft_error();
			}
			$base       = DF_Layout_Store::get_draft( $post_id ) ?? DF_Layout_Store::get( $post_id ) ?? self::empty_layout( $post_id );
			$violations = DF_Validator::reorder_only_violations( $base, $layout );
			if ( $violations ) {
				return self::error( 'df_reorder_only', __( 'This version differs in more than order and visibility.', 'df-editor' ), 403, array( 'violations' => $violations ) );
			}
		}

		if ( ! DF_Layout_Store::set_draft( $post_id, $layout ) ) {
			return self::error( 'df_save_failed', __( 'The draft could not be saved.', 'df-editor' ), 500 );
		}
		DF_Layout_Store::set_lock( $post_id, get_current_user_id(), self::panel_token( $request ) );
		return rest_ensure_response( self::layout_state( $post_id ) );
	}

	/**
	 * POST /panel/layout/<id>/lock {force?}
	 */
	public static function take_lock( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$user_id = get_current_user_id();
		$token   = self::panel_token( $request );
		$other   = DF_Layout_Store::locked_by_other( $post_id, $user_id, $token );
		// Taking over: full editors from anybody, every user from their own other window.
		$may_force = DF_Capabilities::can_edit_layout( $post_id ) || ( $other && $other['user_id'] === $user_id );
		if ( $other && ! ( $request['force'] && $may_force ) ) {
			return self::locked_error( $other );
		}
		DF_Layout_Store::set_lock( $post_id, $user_id, $token );
		return rest_ensure_response( array( 'lock' => self::lock_info( $post_id ) ) );
	}

	/**
	 * DELETE /panel/layout/<id>/lock — releases the own lock (e.g. when the panel closes).
	 */
	public static function release_lock( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		DF_Layout_Store::release_lock( $post_id, get_current_user_id(), self::panel_token( $request ) );
		return rest_ensure_response( array( 'lock' => self::lock_info( $post_id ) ) );
	}

	/**
	 * POST /panel/duplicate-content — copies saved DF Inhalt values of one instance to another.
	 */
	public static function duplicate_content( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$from    = (string) $request['from_id'];
		$to      = (string) $request['to_id'];
		if ( $from === $to ) {
			return self::error( 'df_same_instance', __( 'Source and target section are the same.', 'df-editor' ), 400 );
		}

		$key    = DF_Layout_Store::page_key( $post_id );
		$prefix = $key . '.' . $from . '.';
		$target = $key . '.' . $to . '.';
		$values = get_option( DF_Content::VALUES_OPTION, array() );
		$values = is_array( $values ) ? $values : array();

		$copied  = array();
		$skipped = array();
		foreach ( $values as $field_id => $value ) {
			$field_id = (string) $field_id;
			if ( 0 !== strpos( $field_id, $prefix ) ) {
				continue;
			}
			$new_id = $target . substr( $field_id, strlen( $prefix ) );
			if ( strlen( $new_id ) >= 191 || ! preg_match( DF_Layout_Store::ID_REGEX, $new_id ) ) {
				$skipped[] = $new_id;
				continue;
			}
			if ( array_key_exists( $new_id, $values ) ) {
				$skipped[] = $new_id;
				continue;
			}
			$values[ $new_id ] = $value;
			$copied[]          = $new_id;
		}
		if ( $copied ) {
			update_option( DF_Content::VALUES_OPTION, $values, false );
		}

		return rest_ensure_response(
			array(
				'copied'  => $copied,
				'skipped' => $skipped,
			)
		);
	}

	/* -------------------------------------------------------------- Helpers */

	/**
	 * Common checks before writing: lock held by somebody else (423) and base_rev (409).
	 * Takes/refreshes the lock for the current user when it is free.
	 */
	private static function write_guard( WP_REST_Request $request, ?int $base_rev = null ): ?WP_REST_Response {
		$post_id = (int) $request['id'];
		$user_id = get_current_user_id();
		$token   = self::panel_token( $request );
		$other   = DF_Layout_Store::locked_by_other( $post_id, $user_id, $token );
		if ( $other ) {
			return self::locked_error( $other );
		}
		if ( null !== $base_rev ) {
			$rev = DF_Layout_Store::rev( $post_id );
			if ( $base_rev !== $rev ) {
				return self::error(
					'df_conflict',
					__( 'The page was published by somebody else in the meantime. Reload to get the current version.', 'df-editor' ),
					409,
					array( 'rev' => $rev )
				);
			}
		}
		DF_Layout_Store::set_lock( $post_id, $user_id, $token );
		return null;
	}

	/**
	 * Token of the panel window (header X-DF-Panel-Token), '' when absent or malformed.
	 */
	private static function panel_token( WP_REST_Request $request ): string {
		$token = (string) $request->get_header( 'x_df_panel_token' );
		return preg_match( '/^[a-f0-9]{16,64}$/', $token ) ? $token : '';
	}

	/**
	 * Whether the draft differs from live in more than order and hidden flags, i.e. it holds
	 * changes of a full layout editor that a reorder-only user must not publish or discard.
	 */
	public static function draft_has_foreign_changes( int $post_id ): bool {
		$draft = DF_Layout_Store::get_draft( $post_id );
		if ( null === $draft ) {
			return false;
		}
		$live = DF_Layout_Store::get( $post_id ) ?? self::empty_layout( $post_id );
		return (bool) DF_Validator::reorder_only_violations( $live, $draft );
	}

	private static function foreign_draft_error(): WP_REST_Response {
		return self::error( 'df_foreign_draft', __( 'The agency is preparing changes to this page. Please try again later.', 'df-editor' ), 403 );
	}

	private static function locked_error( array $lock ): WP_REST_Response {
		$holder    = self::user_name( (int) $lock['user_id'] );
		$same_user = get_current_user_id() === (int) $lock['user_id'];
		return self::error(
			'df_locked',
			$same_user
				? __( 'You are editing this page in another window.', 'df-editor' )
				/* translators: %s: user name */
				: sprintf( __( '%s is editing this page right now.', 'df-editor' ), $holder ),
			423,
			array(
				'holder' => $holder,
				'lock'   => array(
					'user_id'   => (int) $lock['user_id'],
					'user_name' => $holder,
					'expires'   => (int) $lock['expires'],
					'mine'      => false,
					'same_user' => $same_user,
				),
			)
		);
	}

	/**
	 * Error response with the extra fields both at the top level (e.g. body.errors) and in
	 * "data" (WP_Error style: body.data.errors).
	 */
	private static function error( string $code, string $message, int $status, array $extra = array() ): WP_REST_Response {
		$body = array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$extra,
			array( 'data' => array_merge( array( 'status' => $status ), $extra ) )
		);
		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Shape returned by GET /panel/layout/<id>, publish, restore and DELETE draft.
	 */
	public static function layout_state( int $post_id ): array {
		$live  = DF_Layout_Store::get( $post_id ) ?? self::empty_layout( $post_id );
		$draft = DF_Layout_Store::get_draft( $post_id );

		$history = array();
		foreach ( DF_Layout_Store::get_history( $post_id ) as $entry ) {
			$history[] = array(
				'rev'       => (int) ( $entry['rev'] ?? 0 ),
				'saved_at'  => (string) ( $entry['saved_at'] ?? '' ),
				'user_name' => self::user_name( (int) ( $entry['user_id'] ?? 0 ) ),
			);
		}

		$saved = get_post_meta( $post_id, DF_Layout_Store::META_SAVED, true );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'post_id'     => $post_id,
			'title'       => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'page_key'    => (string) $live['page_key'],
			'rev'         => (int) $live['rev'],
			'live'        => self::for_output( $live ),
			'draft'       => self::for_output( $draft ),
			'history'     => $history,
			'saved_at'    => (string) ( $saved['saved_at'] ?? '' ),
			'saved_by'    => isset( $saved['user_id'] ) ? self::user_name( (int) $saved['user_id'] ) : '',
			'lock'        => self::lock_info( $post_id ),
			'permissions' => array(
				'edit_layout'  => DF_Capabilities::can_edit_layout( $post_id ),
				'reorder_only' => DF_Capabilities::is_reorder_only( $post_id ),
			),
			'draft_has_foreign_changes' => DF_Capabilities::is_reorder_only( $post_id ) && self::draft_has_foreign_changes( $post_id ),
			'preview_url' => DF_Renderer::preview_url( $post_id ),
			'live_url'    => (string) get_permalink( $post_id ),
			'validation'  => DF_Validator::validate( $draft ?? $live ),
		);
	}

	private static function empty_layout( int $post_id ): array {
		return array(
			'format'   => DF_LAYOUT_FORMAT,
			'page_key' => DF_Layout_Store::read_key( $post_id ),
			'rev'      => 0,
			'sections' => array(),
		);
	}

	/**
	 * Lock as {user_id, user_name, expires, mine} or null.
	 */
	public static function lock_info( int $post_id ): ?array {
		$lock = DF_Layout_Store::get_lock( $post_id );
		if ( null === $lock ) {
			return null;
		}
		return array(
			'user_id'   => $lock['user_id'],
			'user_name' => self::user_name( $lock['user_id'] ),
			'expires'   => $lock['expires'],
			'mine'      => get_current_user_id() === $lock['user_id'],
		);
	}

	/**
	 * Layout for JSON output: empty objects stay objects ({}), so the panel can add keys.
	 */
	private static function for_output( ?array $layout ): ?array {
		if ( null === $layout ) {
			return null;
		}
		$sections = array();
		foreach ( (array) ( $layout['sections'] ?? array() ) as $inst ) {
			if ( is_array( $inst ) ) {
				foreach ( array( 'options', 'content', 'spacing' ) as $obj ) {
					if ( ! isset( $inst[ $obj ] ) || array() === $inst[ $obj ] ) {
						$inst[ $obj ] = new stdClass();
					}
				}
				if ( is_array( $inst['content'] ) ) {
					foreach ( $inst['content'] as $field => $value ) {
						if ( is_array( $value ) && array_is_list( $value ) ) {
							foreach ( $value as $n => $item ) {
								if ( array() === $item ) {
									$inst['content'][ $field ][ $n ] = new stdClass();
								}
							}
						}
					}
				}
			}
			$sections[] = $inst;
		}
		$layout['sections'] = $sections;
		return $layout;
	}

	private static function user_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : '';
	}

	private static function humanize( string $value ): string {
		$map = array(
			'yes' => __( 'Yes', 'df-editor' ),
			'no'  => __( 'No', 'df-editor' ),
		);
		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}
		return ucfirst( trim( (string) preg_replace( '/[-_]+/', ' ', $value ) ) );
	}

	private static function pairs( array $map ): array {
		$out = array();
		foreach ( $map as $value => $label ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => $label,
			);
		}
		return $out;
	}

	public static function scheme_labels(): array {
		return array(
			'hell'     => __( 'Light', 'df-editor' ),
			'hell-alt' => __( 'Light (alternate)', 'df-editor' ),
			'dunkel'   => __( 'Dark', 'df-editor' ),
			'primaer'  => __( 'Primary', 'df-editor' ),
			'akzent'   => __( 'Accent', 'df-editor' ),
		);
	}

	public static function spacing_labels(): array {
		return array(
			'0'  => __( 'None', 'df-editor' ),
			's'  => __( 'Small', 'df-editor' ),
			'm'  => __( 'Medium', 'df-editor' ),
			'l'  => __( 'Large', 'df-editor' ),
			'xl' => __( 'Extra large', 'df-editor' ),
		);
	}

	public static function width_labels(): array {
		return array(
			'container' => __( 'Container', 'df-editor' ),
			'breit'     => __( 'Wide', 'df-editor' ),
			'voll'      => __( 'Full width', 'df-editor' ),
		);
	}

	/* ------------------------------------------------------------ Heartbeat */

	/**
	 * Heartbeat: data.df_panel_lock = <post_id> refreshes the own lock (or reports the holder).
	 * Response: df_panel_lock = {post_id, lock, locked_by_other}.
	 */
	public static function heartbeat( array $response, array $data ): array {
		if ( empty( $data['df_panel_lock'] ) ) {
			return $response;
		}
		$post_id = absint( $data['df_panel_lock'] );
		if ( ! $post_id || ! get_post( $post_id ) || ! DF_Capabilities::can_reorder( $post_id ) ) {
			return $response;
		}
		if ( ! DF_Layout_Store::has_layout( $post_id ) && ! DF_Layout_Store::has_draft( $post_id ) ) {
			return $response;
		}
		$token = (string) ( $data['df_panel_token'] ?? '' );
		$token = preg_match( '/^[a-f0-9]{16,64}$/', $token ) ? $token : '';
		$other = DF_Layout_Store::locked_by_other( $post_id, get_current_user_id(), $token );
		if ( ! $other ) {
			DF_Layout_Store::set_lock( $post_id, get_current_user_id(), $token );
		}
		$response['df_panel_lock'] = array(
			'post_id'         => $post_id,
			'lock'            => self::lock_info( $post_id ),
			'locked_by_other' => (bool) $other,
			'rev'             => DF_Layout_Store::rev( $post_id ),
		);
		return $response;
	}

	/* ------------------------------------------------------------ Admin bar */

	/**
	 * "DF Panel" node on front-end pages with a DF layout and on the page edit screen.
	 * Skipped when another component already added a node with the id "df-panel".
	 */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( $bar->get_node( 'df-panel' ) ) {
			return;
		}
		$post_id = 0;
		if ( is_admin() ) {
			global $pagenow;
			if ( 'post.php' === $pagenow && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} elseif ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		}
		if ( ! $post_id || DF_Renderer::in_panel_frame() ) {
			return;
		}
		if ( ! DF_Layout_Store::has_layout( $post_id ) && ! DF_Layout_Store::has_draft( $post_id ) ) {
			return;
		}
		if ( ! DF_Capabilities::can_use_panel( $post_id ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'df-panel',
				'title' => esc_html__( 'DF Panel', 'df-editor' ),
				'href'  => DF_Capabilities::panel_url( $post_id ),
				'meta'  => array( 'title' => __( 'Edit the page layout in the DF Panel', 'df-editor' ) ),
			)
		);
	}
}
