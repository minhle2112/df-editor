<?php
/**
 * DF blocks: DF sections as native dynamic Gutenberg blocks ("df/<type>").
 *
 * - Registered from DF_Registry for the enabled section types (filter df_editor_block_sections,
 *   default: every registered section except "seiteninhalt").
 * - Dynamic: the markup always comes from the section template through
 *   DF_Renderer::render_instance(), so wrapper and classes are identical to JSON layout pages.
 * - Two content modes (see docs/BLOCKS.md):
 *   - Block content (v2): the block has a "fields" attribute (texts, buttons, images of the
 *     section) and one child block "df/<type>-item" per list item (FAQ question, card, ...).
 *     Everything is edited in the block editor (sidebar + live preview); DF Inhalt is not used.
 *   - Legacy (v1 prototype, no "fields" attribute): the block only stores the settings; texts and
 *     images are DF Inhalt fields ({page_key}.{dfId}.{field}) edited on the front end.
 * - Editor UI: assets/blocks/editor.js (handle df-editor-blocks), generic from section.json via
 *   window.dfBlocksData. Live preview: POST df/v1/blocks/preview.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Blocks {

	/** Block namespace ("df/hero"). */
	public const NS = 'df';

	/** Block category slug. */
	public const CATEGORY = 'df-sections';

	/** Editor script/style handle. */
	public const HANDLE = 'df-editor-blocks';

	/** Suffix of the child block of list sections ("df/faq-item"). */
	public const ITEM_SUFFIX = '-item';

	/** REST namespace. */
	public const REST_NS = 'df/v1';

	/** Backup of the layout after `wp df to-blocks`. */
	public const META_LAYOUT_CONVERTED = '_df_layout_converted';

	/** Backup of the block markup after `wp df to-layout`. */
	public const META_BLOCKS_CONVERTED = '_df_blocks_converted';

	/** Backup of the v1 block markup after `wp df to-blocks --upgrade`. */
	public const META_BLOCKS_V1 = '_df_blocks_v1';

	/** Backup of DF Inhalt values removed by `wp df to-layout` (block content moved into the layout). */
	public const META_INHALT_BACKUP = '_df_inhalt_values_backup';

	/** Maximum number of list items accepted from a block / preview request. */
	private const MAX_ITEMS = 100;

	/** Default attribute values that differ from the JSON layout defaults (spacing "l"). */
	private const DEFAULT_SPACING = 'm';

	/** Width used when the attribute is missing or invalid. */
	private const DEFAULT_WIDTH = 'container';

	/**
	 * Page key prefix for posts without a stored _df_page_key. Contains "_", which page keys
	 * never do (DF_Layout_Store::PAGE_KEY_REGEX), so no real page can own these field IDs.
	 */
	private const UNSAVED_KEY_PREFIX = 'df_unsaved_';

	/**
	 * Core blocks allowed on pages next to the DF blocks. Legal and contact pages also need tables
	 * (cookie lists), shortcodes / custom HTML (forms, consent embeds), quotes and classic content.
	 * core/group is left out on purpose: DF sections nested in a group would lose their full-bleed
	 * layout (add it with df_editor_allowed_blocks if a site needs it).
	 */
	private const CORE_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/image',
		'core/buttons',
		'core/button',
		'core/separator',
		'core/spacer',
		'core/table',
		'core/quote',
		'core/shortcode',
		'core/html',
		'core/freeform',
	);

	/** Whether the current request is a REST block-renderer request for a df/* block. */
	private static bool $editor_render = false;

	/** Preview endpoint: image IDs must also be readable by the current user. */
	private static bool $check_read = false;

	/** @var array<string,string> Rendered sections waiting for the end of the_content (token => HTML). */
	private static array $stash = array();

	/** Counter for stash tokens. */
	private static int $stash_n = 0;

	/** @var array<string,bool> has_df_blocks() cache (post ID + content hash). */
	private static array $has_cache = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'hook_rest_content' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'categories' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'detect_editor_render' ), 10, 3 );
		add_filter( 'the_content', array( __CLASS__, 'restore_stash' ), PHP_INT_MAX );
		add_action( 'save_post', array( __CLASS__, 'assign_page_key' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_iframe' ) );
		add_filter( 'allowed_block_types_all', array( __CLASS__, 'allowed_blocks' ), 10, 2 );
	}

	/* ------------------------------------------------------------- Registry */

	/**
	 * Section types offered as blocks (registered sections only, in filter order).
	 *
	 * @return string[]
	 */
	public static function enabled_types(): array {
		$default = array_values( array_diff( array_map( 'strval', array_keys( DF_Registry::all() ) ), array( 'seiteninhalt' ) ) );
		/**
		 * Filters the DF section types that are registered as Gutenberg blocks.
		 *
		 * @param string[] $types Section types, default: every registered section except
		 *                        "seiteninhalt" (prints WordPress content, layout-only).
		 */
		$types = (array) apply_filters( 'df_editor_block_sections', $default );
		$out   = array();
		foreach ( $types as $type ) {
			$type = is_string( $type ) ? $type : '';
			if ( '' !== $type && preg_match( '/^[a-z0-9][a-z0-9-]*$/', $type ) && DF_Registry::exists( $type ) ) {
				$out[ $type ] = true;
			}
		}
		return array_keys( $out );
	}

	public static function is_enabled( string $type ): bool {
		return in_array( $type, self::enabled_types(), true );
	}

	/**
	 * Post types whose df/* blocks are rendered (and get a page key on save). Other post types
	 * render nothing: they never get a stored page key, so their field IDs would not be safe.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		/**
		 * Filters the post types that may contain DF section blocks (default: page).
		 * A post type added here also gets its page key assigned on save.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'df_editor_block_post_types', array( 'page' ) ) ) ) );
	}

	/**
	 * Whether df/* blocks of this post are rendered.
	 *
	 * @param int|WP_Post $post Post.
	 */
	public static function supports_post( $post ): bool {
		$post_type = get_post_type( $post );
		return is_string( $post_type ) && in_array( $post_type, self::post_types(), true );
	}

	public static function block_name( string $type ): string {
		return self::NS . '/' . $type;
	}

	/** Child block name of a list section ("faq" → "df/faq-item"). */
	public static function item_name( string $type ): string {
		return self::NS . '/' . $type . self::ITEM_SUFFIX;
	}

	/**
	 * Section type of a block name ("df/hero" → "hero"), or '' for other blocks.
	 * Child blocks return "<type>-item" (not a section type).
	 */
	public static function type_of( ?string $block_name ): string {
		$prefix = self::NS . '/';
		return ( is_string( $block_name ) && str_starts_with( $block_name, $prefix ) ) ? substr( $block_name, strlen( $prefix ) ) : '';
	}

	/**
	 * Parent section type of a child block name ("df/faq-item" → "faq"), or ''.
	 */
	public static function item_parent_type( ?string $block_name ): string {
		$type = self::type_of( $block_name );
		if ( '' === $type || ! str_ends_with( $type, self::ITEM_SUFFIX ) || DF_Registry::exists( $type ) ) {
			return '';
		}
		$parent = substr( $type, 0, -strlen( self::ITEM_SUFFIX ) );
		$def    = DF_Registry::get( $parent );
		return ( null !== $def && self::has_list( $def ) ) ? $parent : '';
	}

	/** Whether a section definition has a list (child blocks). */
	public static function has_list( array $def ): bool {
		return ! empty( $def['list'] ) && is_array( $def['list'] );
	}

	/**
	 * Block attribute schema of a section (PHP is the source of truth, the editor script reads it).
	 *
	 * The look-defining settings (v, variant, scheme, spacing, width, options) have NO registered
	 * default: Gutenberg leaves default-equal attributes out of the block comment, so a later
	 * change of a section.json default would silently restyle saved blocks. The editor writes
	 * explicit values on insert; missing values (older blocks) fall back to defaults() when
	 * rendering. "fields" has no default either: its presence switches the block to block-content
	 * mode (v2). Never rename attributes (see docs/BLOCKS.md, "Versioning").
	 */
	public static function attributes( array $def ): array {
		return array(
			'dfId'          => array(
				'type'    => 'string',
				'default' => '',
			),
			// Section version the block was created with (layout "v").
			'v'             => array(
				'type' => 'integer',
			),
			'variant'       => array(
				'type' => 'string',
			),
			'scheme'        => array(
				'type' => 'string',
			),
			'spacingTop'    => array(
				'type' => 'string',
			),
			'spacingBottom' => array(
				'type' => 'string',
			),
			'width'         => array(
				'type' => 'string',
			),
			'anchor'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'hidden'        => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'options'       => array(
				'type' => 'object',
			),
			// v2 block content: {field: value} for the non-list fields. Present = block-content mode.
			'fields'        => array(
				'type' => 'object',
			),
			// v1 legacy: initial content defaults (layout "content"), kept so `wp df to-blocks
			// --no-content` is lossless. Not edited in the block editor; DF Inhalt values win.
			'content'       => array(
				'type'    => 'object',
				'default' => (object) array(),
			),
			'preview'       => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Attribute schema of the child block of a list section.
	 */
	public static function item_attributes(): array {
		return array(
			// Stable key of the item (not part of the output).
			'itemId' => array(
				'type'    => 'string',
				'default' => '',
			),
			// {subfield: value} for the list sub-fields of section.json.
			'fields' => array(
				'type' => 'object',
			),
		);
	}

	/**
	 * Values used for missing/invalid settings (also sent to the editor, which writes them
	 * explicitly into new blocks).
	 */
	public static function defaults( array $def ): array {
		return array(
			'v'             => max( 1, (int) ( $def['version'] ?? 1 ) ),
			'variant'       => self::default_variant( $def ),
			'scheme'        => self::default_scheme( $def ),
			'spacingTop'    => self::DEFAULT_SPACING,
			'spacingBottom' => self::DEFAULT_SPACING,
			'width'         => self::DEFAULT_WIDTH,
			'options'       => (object) self::default_options( $def ),
		);
	}

	private static function default_variant( array $def ): string {
		$variants = array_keys( (array) ( $def['variants'] ?? array() ) );
		$default  = (string) ( $def['default_variant'] ?? '' );
		return in_array( $default, array_map( 'strval', $variants ), true ) ? $default : (string) ( $variants[0] ?? 'default' );
	}

	private static function default_scheme( array $def ): string {
		$scheme = (string) ( $def['default_scheme'] ?? 'hell' );
		return in_array( $scheme, DF_Section::SCHEMES, true ) ? $scheme : 'hell';
	}

	/**
	 * Option defaults from section.json (strings) plus "count" for list sections.
	 */
	public static function default_options( array $def ): array {
		$out = array();
		foreach ( (array) ( $def['options'] ?? array() ) as $key => $opt ) {
			$values      = array_map( 'strval', (array) ( $opt['values'] ?? array() ) );
			$out[ $key ] = (string) ( $opt['default'] ?? ( $values[0] ?? '' ) );
		}
		if ( self::has_list( $def ) ) {
			$out['count'] = (string) (int) $def['list']['default_count'];
		}
		return $out;
	}

	/**
	 * Default value of one field for a new block ({n} = item number). Images: 0 (= placeholder).
	 */
	public static function default_value( array $field, int $n = 0 ): mixed {
		$type    = (string) ( $field['type'] ?? 'text' );
		$default = $field['default'] ?? null;
		$fill    = static fn( $v ) => is_string( $v ) && $n > 0 ? str_replace( '{n}', (string) $n, $v ) : $v;
		switch ( $type ) {
			case 'image':
				return is_numeric( $default ) ? (int) $default : 0;
			case 'button':
				$default = is_array( $default ) ? $default : array();
				return array(
					'text' => (string) $fill( is_scalar( $default['text'] ?? '' ) ? (string) ( $default['text'] ?? '' ) : '' ),
					'url'  => (string) $fill( is_scalar( $default['url'] ?? '' ) ? (string) ( $default['url'] ?? '' ) : '' ),
				);
			default:
				return is_scalar( $default ) ? (string) $fill( (string) $default ) : '';
		}
	}

	/**
	 * Default "fields" of a new block.
	 */
	public static function default_fields( array $def ): array {
		$out = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $key => $field ) {
			$out[ (string) $key ] = self::default_value( (array) $field );
		}
		return $out;
	}

	/**
	 * Default fields of list item $n (1-based).
	 */
	public static function default_item( array $def, int $n ): array {
		$out = array();
		foreach ( (array) ( $def['list']['fields'] ?? array() ) as $key => $field ) {
			$out[ (string) $key ] = self::default_value( (array) $field, $n );
		}
		return $out;
	}

	/**
	 * Registers one dynamic block per enabled section type, plus the child block of list sections.
	 */
	public static function register(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$supports = array(
			'html'            => false,
			'className'       => false,
			'customClassName' => false,
			'reusable'        => false,
			// Core supports that default to on in WP 6.9+/7: our render_callback ignores
			// per-block custom CSS, and "hidden" is the section's own setting.
			'customCSS'       => false,
			'visibility'      => false,
		);
		foreach ( self::enabled_types() as $type ) {
			$name = self::block_name( $type );
			$def  = (array) DF_Registry::get( $type );
			$list = self::has_list( $def );
			if ( ! $registry->is_registered( $name ) ) {
				$args = array(
					'api_version'     => 3,
					'title'           => (string) $def['label'],
					'description'     => (string) ( $def['description'] ?? '' ),
					'category'        => self::CATEGORY,
					'icon'            => 'layout',
					'keywords'        => array( 'df', 'section', $type ),
					'textdomain'      => 'df-editor',
					'attributes'      => self::attributes( $def ),
					'supports'        => array_merge( $supports, array( 'multiple' => true ) ),
					'uses_context'    => array( 'postId' ),
					'example'         => array( 'attributes' => array( 'preview' => true ) ),
					'render_callback' => static function ( $attributes, $content = '', $block = null ) use ( $type ): string {
						return self::render_callback( $type, (array) $attributes, $block );
					},
				);
				if ( $list ) {
					$args['allowed_blocks'] = array( self::item_name( $type ) );
				}
				register_block_type( $name, $args );
			}

			$item = self::item_name( $type );
			if ( $list && ! $registry->is_registered( $item ) && ! DF_Registry::exists( $type . self::ITEM_SUFFIX ) ) {
				register_block_type(
					$item,
					array(
						'api_version'     => 3,
						/* translators: 1: item label, 2: section name */
						'title'           => sprintf( __( '%1$s (%2$s)', 'df-editor' ), self::item_label( $def ), (string) $def['label'] ),
						'category'        => self::CATEGORY,
						'icon'            => 'list-view',
						'parent'          => array( $name ),
						'textdomain'      => 'df-editor',
						'attributes'      => self::item_attributes(),
						'supports'        => $supports,
						// The parent renders the items (render_section()); the child prints nothing.
						'render_callback' => static fn(): string => '',
					)
				);
			}
		}
	}

	/**
	 * Label of one list item ("Frage"): list.item_label of section.json, else "Item".
	 */
	public static function item_label( array $def ): string {
		$label = $def['list']['item_label'] ?? '';
		return is_string( $label ) && '' !== trim( $label ) ? trim( $label ) : __( 'Item', 'df-editor' );
	}

	/**
	 * Adds the "DF Sections" category at the top of the inserter.
	 */
	public static function categories( array $categories, $context = null ): array {
		foreach ( $categories as $category ) {
			if ( self::CATEGORY === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}
		array_unshift(
			$categories,
			array(
				'slug'  => self::CATEGORY,
				'title' => __( 'DF Sections', 'df-editor' ),
				'icon'  => null,
			)
		);
		return $categories;
	}

	/* ------------------------------------------------------------ Rendering */

	/**
	 * Flags REST block-renderer requests for df/* blocks (legacy editor preview: clean markup).
	 *
	 * @param mixed           $response Response (passed through).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function detect_editor_render( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && str_starts_with( (string) $request->get_route(), '/wp/v2/block-renderer/' . self::NS . '/' ) ) {
			self::$editor_render = true;
		}
		return $response;
	}

	/**
	 * Whether the current render is the editor preview (REST block-renderer or df/v1/blocks/preview).
	 */
	public static function is_editor_render(): bool {
		return self::$editor_render;
	}

	/**
	 * Whether block attributes are in block-content mode (v2): a "fields" attribute is present.
	 */
	public static function is_content_mode( array $attributes ): bool {
		return array_key_exists( 'fields', $attributes ) && ( is_array( $attributes['fields'] ) || is_object( $attributes['fields'] ) );
	}

	/**
	 * render_callback of every df/* section block.
	 *
	 * @param string        $type       Section type.
	 * @param array         $attributes Block attributes (defaults applied by WordPress).
	 * @param WP_Block|null $block      Block instance (context postId, inner blocks).
	 */
	public static function render_callback( string $type, array $attributes, $block = null ): string {
		$items = null;
		if ( self::is_content_mode( $attributes ) ) {
			$items = self::items_from_parsed( $block instanceof WP_Block ? (array) $block->parsed_block : array(), $type );
		}

		$post_id = 0;
		if ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		return self::render_section( $type, $attributes, $items, $post_id, self::is_editor_render(), false );
	}

	/**
	 * Renders one section block.
	 *
	 * @param string     $type       Section type.
	 * @param array      $attributes Block attributes.
	 * @param array|null $items      Block content: raw item fields in order; null = legacy (DF Inhalt).
	 * @param int        $post_id    Post the block belongs to.
	 * @param bool       $editor     Editor preview (placeholders, no DF Inhalt editing markup).
	 * @param bool       $markers    data-df-item markers on the item roots (block-content preview).
	 */
	public static function render_section( string $type, array $attributes, ?array $items, int $post_id, bool $editor, bool $markers ): string {
		$def = DF_Registry::get( $type );
		if ( null === $def ) {
			return '';
		}
		$entry = self::entry_from_attributes( $type, $attributes, $items );

		if ( $entry['hidden'] ) {
			return $editor ? self::placeholder( __( 'Hidden section', 'df-editor' ), (string) $def['label'] ) : '';
		}

		if ( ! preg_match( DF_Layout_Store::INSTANCE_REGEX, $entry['id'] ) ) {
			if ( ! $editor ) {
				// No stable instance ID yet: nothing for visitors, a notice for editors.
				return current_user_can( 'edit_pages' ) ? self::placeholder( __( 'Section without ID', 'df-editor' ), (string) $def['label'] ) : '';
			}
			$entry['id'] = $type . '-preview';
		}

		// Only post types that get a page key on save render DF blocks (no slug-derived keys).
		if ( $post_id > 0 && ! self::supports_post( $post_id ) ) {
			return $editor ? self::placeholder( __( 'Not available here', 'df-editor' ), (string) $def['label'] ) : '';
		}
		if ( $post_id <= 0 && ! $editor ) {
			return '';
		}
		$page_key = self::render_key( $post_id, $editor );
		$source   = null !== $items ? array(
			'blocks'  => true,
			'preview' => $editor && $markers,
		) : array();

		// Editor preview: visitor markup, no DF Inhalt editing attributes (also for global fields).
		if ( $editor ) {
			add_filter( 'df_inhalt_can_edit', '__return_false', 1000 );
		}

		// Inside the_content the section is rendered as if outside of it (like a JSON layout page):
		// core image helpers otherwise leave decoding/loading to wp_filter_content_tags().
		global $wp_current_filter;
		$in_content = doing_filter( 'the_content' );
		$filters    = $wp_current_filter;
		if ( $in_content ) {
			$wp_current_filter = array_values( array_diff( (array) $wp_current_filter, array( 'the_content' ) ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		ob_start();
		DF_Renderer::render_instance( $entry, $page_key, $source );
		$html = (string) ob_get_clean();

		if ( $in_content ) {
			$wp_current_filter = $filters; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( $editor ) {
			remove_filter( 'df_inhalt_can_edit', '__return_false', 1000 );
			return $html;
		}

		// Inside the_content: keep the section markup away from wptexturize, shortcodes,
		// wp_filter_content_tags & co., so it is byte-identical to a JSON layout page.
		if ( '' !== $html && $in_content ) {
			$token                 = '<!--df-block-' . ( ++self::$stash_n ) . '-' . substr( md5( $entry['id'] . self::$stash_n ), 0, 8 ) . '-->';
			self::$stash[ $token ] = $html;
			return $token;
		}
		return $html;
	}

	/**
	 * Raw item fields of the child blocks of a parsed section block, in order.
	 *
	 * @param array  $parsed Parsed block (blockName, attrs, innerBlocks).
	 * @param string $type   Section type.
	 * @return array[] One {subfield: value} array per child block.
	 */
	public static function items_from_parsed( array $parsed, string $type ): array {
		$name  = self::item_name( $type );
		$items = array();
		foreach ( (array) ( $parsed['innerBlocks'] ?? array() ) as $inner ) {
			if ( ! is_array( $inner ) || ( $inner['blockName'] ?? '' ) !== $name ) {
				continue;
			}
			$fields  = $inner['attrs']['fields'] ?? array();
			$items[] = self::to_array( $fields );
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Page key for rendering the blocks of a post. Never derived from an unstored slug (slugs are
	 * only unique per post type and can be chosen freely, so a slug-based key could read the DF
	 * Inhalt values of another page):
	 * - stored _df_page_key → that key;
	 * - none stored, front end, user who can edit content → a unique key is assigned and stored
	 *   (their DF Inhalt edits create field IDs under it), as for layout pages;
	 * - otherwise (visitors, editor preview, no post) → "df_unsaved_<ID>", a key no page can own.
	 */
	private static function render_key( int $post_id, bool $editor ): string {
		if ( $post_id > 0 ) {
			$key = (string) get_post_meta( $post_id, DF_Layout_Store::META_KEY, true );
			if ( '' !== $key ) {
				return $key;
			}
			if ( ! $editor && DF_Content::can_edit() && current_user_can( 'edit_post', $post_id ) ) {
				return DF_Layout_Store::assign_key( $post_id, self::wanted_key( $post_id ) );
			}
		}
		return self::UNSAVED_KEY_PREFIX . max( 0, $post_id );
	}

	/**
	 * Preferred page key of a post: its slug, else its title (drafts have no slug yet), else
	 * "page-<ID>". Not checked for uniqueness (DF_Layout_Store::assign_key() does that).
	 *
	 * @param int|WP_Post $post Post.
	 */
	public static function wanted_key( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$source = '' !== (string) $post->post_name ? (string) $post->post_name : (string) $post->post_title;
		$wanted = DF_Layout_Store::normalize_key( sanitize_title( $source ) );
		return '' !== $wanted ? $wanted : 'page-' . $post->ID;
	}

	/**
	 * Puts the stashed section markup back at the very end of the_content.
	 */
	public static function restore_stash( $content ) {
		if ( ! self::$stash || ! is_string( $content ) || ! str_contains( $content, '<!--df-block-' ) ) {
			return $content;
		}
		$used = array();
		foreach ( self::$stash as $token => $html ) {
			if ( str_contains( $content, $token ) ) {
				$used[ $token ] = $html;
				unset( self::$stash[ $token ] );
			}
		}
		return $used ? strtr( $content, $used ) : $content;
	}

	/**
	 * Small editor-only placeholder box.
	 */
	private static function placeholder( string $title, string $label ): string {
		return sprintf(
			'<div class="df-block-ssr-placeholder" role="note"><strong>%s</strong> %s</div>',
			esc_html( $title ),
			esc_html( $label )
		);
	}

	/**
	 * Object/array → plain array (JSON round trip for stdClass trees).
	 */
	private static function to_array( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Builds a sanitized layout entry from block attributes. Invalid values fall back to the
	 * block defaults; options are normalised against section.json (unknown keys dropped).
	 *
	 * @param string     $type       Section type.
	 * @param array      $attributes Block attributes.
	 * @param array|null $items      Raw item fields (block content); null = read no items.
	 */
	public static function entry_from_attributes( string $type, array $attributes, ?array $items = null ): array {
		$def      = (array) DF_Registry::get( $type );
		$defaults = self::attributes( $def );
		$get      = static function ( string $key ) use ( $attributes, $defaults ) {
			return array_key_exists( $key, $attributes ) ? $attributes[ $key ] : ( $defaults[ $key ]['default'] ?? null );
		};
		$pick     = static function ( $value, array $allowed, string $fallback ): string {
			$value = is_scalar( $value ) ? (string) $value : '';
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};

		$variants = array_map( 'strval', array_keys( (array) ( $def['variants'] ?? array() ) ) );
		$id       = $get( 'dfId' );
		$id       = is_string( $id ) ? strtolower( trim( $id ) ) : '';

		$options = $get( 'options' );
		$options = is_object( $options ) ? (array) $options : ( is_array( $options ) ? $options : array() );
		$given   = array();
		foreach ( self::default_options( $def ) as $key => $default ) {
			if ( array_key_exists( $key, $options ) && ( is_scalar( $options[ $key ] ) ) ) {
				$given[ $key ] = $options[ $key ];
			}
		}
		// Stored section version (like layout "v"); blocks saved before the attribute existed
		// were created with the current version.
		$v = $get( 'v' );
		$v = ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) && (int) $v >= 1 ? (int) $v : null;

		$content_mode = self::is_content_mode( $attributes );
		if ( $content_mode && self::has_list( $def ) ) {
			// Block content: the number of child blocks is the item count.
			$given['count'] = count( (array) $items );
		}

		$normalized = DF_Section::normalize(
			array(
				'id'      => $id,
				'v'       => $v,
				'options' => $given,
			),
			$def
		);

		if ( $content_mode ) {
			$content = self::content_from_block( $def, self::to_array( $attributes['fields'] ), (array) $items );
			if ( self::has_list( $def ) ) {
				$normalized['options']['count'] = (string) min( (int) $def['list']['max'], count( (array) $items ) );
			}
		} else {
			$content = $get( 'content' );
			$content = is_object( $content ) ? json_decode( (string) wp_json_encode( $content ), true ) : $content;
			$content = self::sanitize_content( is_array( $content ) ? $content : array(), $def );
		}

		return array(
			'id'      => $id,
			'section' => $type,
			'v'       => (int) $normalized['v'],
			'variant' => $pick( $get( 'variant' ), $variants, self::default_variant( $def ) ),
			'scheme'  => $pick( $get( 'scheme' ), DF_Section::SCHEMES, self::default_scheme( $def ) ),
			'spacing' => array(
				'top'    => $pick( $get( 'spacingTop' ), DF_Section::SPACING, self::DEFAULT_SPACING ),
				'bottom' => $pick( $get( 'spacingBottom' ), DF_Section::SPACING, self::DEFAULT_SPACING ),
			),
			'width'   => $pick( $get( 'width' ), DF_Section::WIDTHS, self::DEFAULT_WIDTH ),
			'hidden'  => true === $get( 'hidden' ),
			'anchor'  => sanitize_title( is_string( $get( 'anchor' ) ) ? $get( 'anchor' ) : '' ),
			'options' => $normalized['options'],
			'content' => $content,
		);
	}

	/**
	 * Layout "content" of a block in block-content mode: the sanitised fields plus the list items.
	 *
	 * @param array   $def    Section definition.
	 * @param array   $fields Raw "fields" attribute.
	 * @param array[] $items  Raw item fields.
	 */
	public static function content_from_block( array $def, array $fields, array $items ): array {
		$content = self::sanitize_fields( $fields, (array) ( $def['fields'] ?? array() ) );
		if ( self::has_list( $def ) ) {
			$list = array();
			foreach ( array_slice( array_values( $items ), 0, (int) $def['list']['max'] ) as $item ) {
				$list[] = self::sanitize_fields( self::to_array( $item ), (array) ( $def['list']['fields'] ?? array() ) );
			}
			$content[ (string) $def['list']['key'] ] = $list;
		}
		return $content;
	}

	/**
	 * Keeps only values of known fields, typed like the block editor stores them:
	 * text/richtext string, button {text,url,target?}, image attachment ID or {id, alt},
	 * setting one of its values (string). Order follows section.json.
	 *
	 * @param array $values Raw values.
	 * @param array $defs   Field definitions of section.json (fields or list.fields).
	 */
	public static function sanitize_fields( array $values, array $defs ): array {
		$out = array();
		foreach ( $defs as $key => $field ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			switch ( (string) ( $field['type'] ?? 'text' ) ) {
				case 'button':
					if ( ! is_array( $value ) ) {
						break;
					}
					$button = array(
						'text' => is_scalar( $value['text'] ?? '' ) ? (string) ( $value['text'] ?? '' ) : '',
						'url'  => is_scalar( $value['url'] ?? '' ) ? (string) ( $value['url'] ?? '' ) : '',
					);
					if ( '_blank' === ( $value['target'] ?? '' ) ) {
						$button['target'] = '_blank';
					}
					$out[ $key ] = $button;
					break;
				case 'image':
					$alt = '';
					if ( is_array( $value ) ) {
						$alt   = is_string( $value['alt'] ?? null ) ? trim( $value['alt'] ) : '';
						$value = $value['id'] ?? 0;
					}
					if ( DF_Section::NO_IMAGE === $value || '-1' === $value ) {
						$out[ $key ] = DF_Section::NO_IMAGE; // Optional image switched off.
						break;
					}
					if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
						break;
					}
					$out[ $key ] = '' !== $alt ? array(
						'id'  => self::image_id( (int) $value ),
						'alt' => sanitize_text_field( $alt ),
					) : self::image_id( (int) $value );
					break;
				case 'setting':
					$allowed = array_map( 'strval', (array) ( $field['values'] ?? array() ) );
					if ( is_scalar( $value ) && ( ! $allowed || in_array( (string) $value, $allowed, true ) ) ) {
						$out[ $key ] = (string) $value;
					}
					break;
				default:
					if ( is_scalar( $value ) ) {
						$out[ $key ] = (string) $value;
					}
			}
		}
		return $out;
	}

	/**
	 * An attachment ID that may be printed: an image attachment (no PDFs or other files), and in
	 * the preview endpoint one the current user can read. Anything else becomes 0 (placeholder).
	 */
	private static function image_id( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return 0;
		}
		if ( self::$check_read && ! current_user_can( 'read_post', $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Keeps only content keys/values that fit the section fields (same types as DF_Validator).
	 */
	public static function sanitize_content( array $content, array $def ): array {
		$out      = array();
		$fields   = (array) ( $def['fields'] ?? array() );
		$list_key = self::has_list( $def ) ? (string) $def['list']['key'] : '';
		foreach ( $content as $key => $value ) {
			$key = (string) $key;
			if ( '' !== $list_key && $key === $list_key ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					continue;
				}
				$items = array();
				foreach ( $value as $item ) {
					$clean = array();
					foreach ( (array) ( is_array( $item ) ? $item : array() ) as $sub => $sub_value ) {
						$field = $def['list']['fields'][ $sub ] ?? null;
						if ( is_array( $field ) && self::value_fits( (string) ( $field['type'] ?? 'text' ), $sub_value ) ) {
							$clean[ (string) $sub ] = $sub_value;
						}
					}
					$items[] = $clean;
				}
				$out[ $key ] = $items;
				continue;
			}
			if ( isset( $fields[ $key ] ) && self::value_fits( (string) ( $fields[ $key ]['type'] ?? 'text' ), $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function value_fits( string $type, $value ): bool {
		switch ( $type ) {
			case 'button':
				return is_array( $value ) && ( ! isset( $value['text'] ) || is_string( $value['text'] ) ) && ( ! isset( $value['url'] ) || is_string( $value['url'] ) );
			case 'image':
				return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			case 'setting':
				return is_scalar( $value );
			default:
				return is_string( $value );
		}
	}

	/**
	 * Block attributes for a layout entry (used by `wp df to-blocks`). The look-defining settings
	 * (v, variant, scheme, spacing, width, options) are always written explicitly, so a later
	 * change of a section.json default cannot change converted pages. Only attributes that have a
	 * registered default (anchor, hidden, content) are left out when equal to it, like the block
	 * editor serialises them.
	 */
	public static function attributes_from_entry( array $inst, array $def ): array {
		$norm  = DF_Section::normalize( $inst, $def );
		$attrs = array(
			'dfId'          => (string) ( $inst['id'] ?? '' ),
			'v'             => max( 1, (int) $norm['v'] ),
			'variant'       => $norm['variant'],
			'scheme'        => $norm['scheme'],
			'spacingTop'    => $norm['spacing']['top'],
			'spacingBottom' => $norm['spacing']['bottom'],
			'width'         => $norm['width'],
			'anchor'        => $norm['anchor'],
			'hidden'        => $norm['hidden'],
			'options'       => array_map( 'strval', $norm['options'] ),
		);
		if ( array() === $attrs['options'] ) {
			unset( $attrs['options'] );
		}
		$content = self::sanitize_content( (array) ( $inst['content'] ?? array() ), $def );
		if ( $content ) {
			$attrs['content'] = $content;
		}

		foreach ( self::attributes( $def ) as $key => $schema ) {
			if ( array_key_exists( $key, $attrs ) && array_key_exists( 'default', $schema ) && ! in_array( $key, array( 'dfId', 'content' ), true ) && $attrs[ $key ] === $schema['default'] ) {
				unset( $attrs[ $key ] );
			}
		}
		return $attrs;
	}

	/* --------------------------------------------------- Block content (v2) */

	/**
	 * Visible content of a section rendered from DF Inhalt (saved values, else layout content
	 * defaults, else section.json defaults), as block-content values: used by `wp df to-blocks
	 * --content`, `--upgrade` and the editor's "Move texts into the block".
	 *
	 * @param array  $entry    Layout entry (id, options, content, ...).
	 * @param array  $def      Section definition.
	 * @param string $page_key Page key the DF Inhalt values belong to.
	 * @return array{fields: array, items: array[]}
	 */
	public static function content_from_entry( array $entry, array $def, string $page_key ): array {
		$s      = new DF_Section( $entry, $def, $page_key );
		$fields = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $key => $field ) {
			$fields[ (string) $key ] = self::export_value( (array) $field, $s->value( (string) $key ) );
		}
		$items = array();
		if ( self::has_list( $def ) ) {
			foreach ( $s->items() as $item ) {
				$row = array();
				foreach ( (array) ( $def['list']['fields'] ?? array() ) as $key => $field ) {
					$row[ (string) $key ] = self::export_value( (array) $field, $item->value( (string) $key ) );
				}
				$items[] = $row;
			}
		}
		return array(
			'fields' => $fields,
			'items'  => $items,
		);
	}

	/**
	 * A resolved field value in block format (placeholder image → 0).
	 */
	private static function export_value( array $field, mixed $value ): mixed {
		switch ( (string) ( $field['type'] ?? 'text' ) ) {
			case 'image':
				$id = (int) ( is_numeric( $value ) ? $value : 0 );
				return ( $id > 0 && $id !== DF_Content::placeholder_id() ) ? $id : 0;
			case 'button':
				$value = is_array( $value ) ? $value : array();
				return array(
					'text' => (string) ( $value['text'] ?? '' ),
					'url'  => (string) ( $value['url'] ?? '' ),
				);
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * A new random item ID ("i" + 6 characters).
	 */
	public static function new_item_id(): string {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = 'i';
		for ( $i = 0; $i < 6; $i++ ) {
			$id .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}
		return $id;
	}

	/**
	 * Parsed df/<type> block in block-content mode (parent attributes + one child per item).
	 *
	 * @param string  $type   Section type.
	 * @param array   $attrs  Parent attributes (settings); "content" and options.count are dropped.
	 * @param array   $fields Field values.
	 * @param array[] $items  Item field values.
	 */
	public static function content_block( string $type, array $attrs, array $fields, array $items ): array {
		$def = (array) DF_Registry::get( $type );
		unset( $attrs['content'] );
		if ( isset( $attrs['options'] ) && is_array( $attrs['options'] ) ) {
			unset( $attrs['options']['count'] );
			if ( array() === $attrs['options'] ) {
				unset( $attrs['options'] );
			}
		}
		$attrs['fields'] = (object) self::sanitize_fields( $fields, (array) ( $def['fields'] ?? array() ) );

		$inner   = array();
		$content = array( "\n" );
		if ( self::has_list( $def ) ) {
			foreach ( $items as $i => $item ) {
				$inner[] = array(
					'blockName'    => self::item_name( $type ),
					'attrs'        => array(
						'itemId' => self::new_item_id(),
						'fields' => (object) self::sanitize_fields( self::to_array( $item ), (array) ( $def['list']['fields'] ?? array() ) ),
					),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
				if ( $i > 0 ) {
					$content[] = "\n\n";
				}
				$content[] = null;
			}
		}
		$content[] = "\n";
		if ( ! $inner ) {
			$content = array();
		}
		return array(
			'blockName'    => self::block_name( $type ),
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		);
	}

	/**
	 * Converts a legacy (v1) df/* block into block-content mode with the visible DF Inhalt texts.
	 * Other blocks (and blocks already in block-content mode) are returned unchanged.
	 *
	 * @param array  $block    Parsed block.
	 * @param string $page_key Page key of the post.
	 * @param bool   $changed  Set to true when the block was converted.
	 */
	public static function upgrade_block( array $block, string $page_key, bool &$changed = false ): array {
		$type  = self::type_of( $block['blockName'] ?? null );
		$attrs = (array) ( $block['attrs'] ?? array() );
		if ( '' === $type || ! self::is_enabled( $type ) || self::is_content_mode( $attrs ) ) {
			return $block;
		}
		$def     = (array) DF_Registry::get( $type );
		$entry   = self::entry_from_attributes( $type, $attrs );
		$content = self::content_from_entry( $entry, $def, $page_key );
		$changed = true;
		return self::content_block( $type, $attrs, $content['fields'], $content['items'] );
	}

	/* ------------------------------------------------------ Block detection */

	/**
	 * All df/* section blocks of enabled types in a block tree (recursive), document order.
	 *
	 * @param array $blocks   Parsed blocks.
	 * @param bool  $any_type Also df/* blocks whose type is not (or no longer) enabled.
	 * @return array[] Parsed blocks.
	 */
	public static function find_blocks( array $blocks, bool $any_type = false ): array {
		$out     = array();
		$enabled = self::enabled_types();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = self::type_of( $block['blockName'] ?? null );
			if ( '' !== $type && '' === self::item_parent_type( $block['blockName'] ?? null ) && ( $any_type || in_array( $type, $enabled, true ) ) ) {
				$out[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::find_blocks( (array) $block['innerBlocks'], $any_type ) );
			}
		}
		return $out;
	}

	/**
	 * Whether a content string contains df/* blocks of enabled types.
	 */
	public static function content_has_df_blocks( string $content ): bool {
		if ( ! str_contains( $content, '<!-- wp:' . self::NS . '/' ) ) {
			return false;
		}
		return (bool) self::find_blocks( parse_blocks( $content ) );
	}

	/**
	 * Whether a post contains df/* blocks (current post when null).
	 *
	 * @param int|WP_Post|null $post Post.
	 */
	public static function has_df_blocks( $post = null ): bool {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || ! self::supports_post( $post ) ) {
			return false;
		}
		$content = (string) $post->post_content;
		$key     = $post->ID . ':' . md5( $content );
		if ( ! isset( self::$has_cache[ $key ] ) ) {
			self::$has_cache[ $key ] = self::content_has_df_blocks( $content );
		}
		return self::$has_cache[ $key ];
	}

	/* ------------------------------------------------------------- Page key */

	/**
	 * save_post: a post of a supported type (default: page) with df/* blocks and no page key gets
	 * one (never changed afterwards).
	 */
	public static function assign_page_key( int $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, DF_Layout_Store::META_KEY, true ) ) {
			return;
		}
		if ( ! self::supports_post( $post ) || ! self::content_has_df_blocks( (string) $post->post_content ) ) {
			return;
		}
		// Drafts have no slug yet: the title gives a readable key ("leistungen", not "page-101").
		DF_Layout_Store::assign_key( $post_id, self::wanted_key( $post ) );
	}

	/* ------------------------------------------------------------------ REST */

	/**
	 * POST df/v1/blocks/preview and POST df/v1/blocks/legacy-content.
	 */
	public static function register_routes(): void {
		$args = array(
			'name'       => array(
				'type'     => 'string',
				'required' => true,
			),
			'attributes' => array(
				'type'    => 'object',
				'default' => array(),
			),
			'post_id'    => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			self::REST_NS,
			'/blocks/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_preview' ),
				'permission_callback' => array( __CLASS__, 'rest_can_preview' ),
				'args'                => array_merge(
					$args,
					array(
						'items' => array(
							'type'    => 'array',
							'default' => array(),
						),
					)
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/blocks/legacy-content',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_legacy_content' ),
				'permission_callback' => array( __CLASS__, 'rest_can_preview' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Registers the empty-object repair for the editor's raw content (pages, revisions, autosaves).
	 */
	public static function hook_rest_content(): void {
		foreach ( array_unique( array_merge( self::post_types(), array( 'revision', 'autosave' ) ) ) as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, array( __CLASS__, 'rest_fix_content' ), 20 );
		}
	}

	/**
	 * WordPress re-serialises block markup on its way to the editor (block hooks), which turns an
	 * empty attribute object into "[]"; Gutenberg then drops the attribute as invalid. For df/*
	 * blocks "fields": {} (a section without non-list fields) must stay an object, otherwise the
	 * block would open in legacy mode. Repairs "fields"/"options" in content.raw.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	public static function rest_fix_content( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content']['raw'] ) && is_string( $data['content']['raw'] ) ) {
			$fixed = self::fix_empty_objects( $data['content']['raw'] );
			if ( $fixed !== $data['content']['raw'] ) {
				$data['content']['raw'] = $fixed;
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * "fields":[] / "options":[] → {} inside df/* block comments.
	 */
	public static function fix_empty_objects( string $content ): string {
		if ( ! str_contains( $content, '<!-- wp:' . self::NS . '/' ) || ! str_contains( $content, '":[]' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'#<!-- wp:' . self::NS . '/[a-z0-9-]+ \{.*?\} /?-->#s',
			static fn( $m ) => str_replace( array( '"fields":[]', '"options":[]' ), array( '"fields":{}', '"options":{}' ), $m[0] ),
			$content
		);
	}

	/**
	 * Logged-in users who can edit posts, and this post (contributors: only their own drafts).
	 * Returning false gives 401 for anonymous requests and 403 for logged-in users.
	 */
	public static function rest_can_preview( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return is_user_logged_in() && current_user_can( 'edit_posts' ) && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Resolves and checks the section type of a REST request.
	 *
	 * @return string|WP_Error
	 */
	private static function rest_type( WP_REST_Request $request ) {
		$type = self::type_of( (string) $request->get_param( 'name' ) );
		if ( '' === $type || ! self::is_enabled( $type ) ) {
			return new WP_Error( 'df_unknown_block', __( 'Unknown DF section block.', 'df-editor' ), array( 'status' => 400 ) );
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error( 'df_unknown_post', __( 'Unknown post.', 'df-editor' ), array( 'status' => 404 ) );
		}
		return $type;
	}

	/**
	 * Live preview of one section block (block-content or legacy): {html}.
	 *
	 * Body: {name: "df/faq", post_id, attributes: {...}, items: [{question, answer}, ...]}.
	 * Block content renders with data-df-item markers on the item roots (click-to-select).
	 */
	public static function rest_preview( WP_REST_Request $request ) {
		$type = self::rest_type( $request );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$post_id    = (int) $request->get_param( 'post_id' );
		$attributes = self::to_array( $request->get_param( 'attributes' ) );
		unset( $attributes['preview'] );
		$items = null;
		if ( self::is_content_mode( $attributes ) ) {
			$items = array();
			foreach ( array_slice( (array) $request->get_param( 'items' ), 0, self::MAX_ITEMS ) as $item ) {
				$items[] = self::to_array( $item );
			}
		}

		global $post;
		$previous = $post;
		$post     = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$was                 = self::$editor_render;
		self::$editor_render = true;
		self::$check_read    = true;
		$html                = self::render_section( $type, $attributes, $items, $post_id, true, true );
		self::$check_read    = false;
		self::$editor_render = $was;
		$post                = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		return rest_ensure_response( array( 'html' => $html ) );
	}

	/**
	 * Visible DF Inhalt content of a legacy block as block content: {fields, items}
	 * (editor action "Move texts into the block").
	 */
	public static function rest_legacy_content( WP_REST_Request $request ) {
		$type = self::rest_type( $request );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$post_id    = (int) $request->get_param( 'post_id' );
		$attributes = self::to_array( $request->get_param( 'attributes' ) );
		unset( $attributes['fields'] );
		$entry = self::entry_from_attributes( $type, $attributes );
		$key   = (string) get_post_meta( $post_id, DF_Layout_Store::META_KEY, true );
		$key   = '' !== $key ? $key : self::UNSAVED_KEY_PREFIX . $post_id;
		if ( ! preg_match( DF_Layout_Store::INSTANCE_REGEX, $entry['id'] ) ) {
			$entry['id'] = $type . '-preview';
		}
		$content = self::content_from_entry( $entry, (array) DF_Registry::get( $type ), $key );
		return rest_ensure_response(
			array(
				'fields' => (object) $content['fields'],
				'items'  => array_map( static fn( $item ) => (object) $item, $content['items'] ),
			)
		);
	}

	/* --------------------------------------------------------------- Assets */

	/**
	 * Front end: base.css + the CSS/JS of every visible df/* block of the queried post, in <head>.
	 */
	public static function enqueue_front(): void {
		if ( ! is_singular() || DF_Renderer::is_section_preview() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::has_df_blocks( $post ) ) {
			return;
		}
		$types = array();
		foreach ( self::find_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( empty( $block['attrs']['hidden'] ) ) {
				$types[ self::type_of( $block['blockName'] ) ] = true;
			}
		}
		DF_Assets::enqueue_base();
		foreach ( array_keys( $types ) as $type ) {
			DF_Assets::enqueue_section( (string) $type );
		}
	}

	/**
	 * Block editor: editor script + styles + window.dfBlocksData.
	 */
	public static function enqueue_editor(): void {
		$js  = DF_EDITOR_DIR . 'assets/blocks/editor.js';
		$css = DF_EDITOR_DIR . 'assets/blocks/editor.css';

		wp_enqueue_script(
			self::HANDLE,
			DF_EDITOR_URL . 'assets/blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-data', 'wp-i18n', 'wp-hooks', 'wp-api-fetch', 'wp-core-data', 'wp-dom-ready' ),
			self::ver( $js ),
			false
		);
		wp_add_inline_script( self::HANDLE, 'window.dfBlocksData = ' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'df-editor', DF_EDITOR_DIR . 'languages' );

		if ( is_readable( $css ) ) {
			wp_enqueue_style( self::HANDLE, DF_EDITOR_URL . 'assets/blocks/editor.css', array(), self::ver( $css ) );
		}
	}

	/**
	 * Editor iframe (admin only): base.css + the enabled sections' style.css, so the previews
	 * look like the front end.
	 */
	public static function enqueue_iframe(): void {
		if ( ! is_admin() ) {
			return;
		}
		DF_Assets::enqueue_base();
		foreach ( self::enabled_types() as $type ) {
			$def    = DF_Registry::get( $type );
			$handle = 'df-section-' . $type;
			if ( null === $def || wp_style_is( $handle, 'enqueued' ) ) {
				continue;
			}
			$file = $def['dir'] . 'style.css';
			if ( is_readable( $file ) ) {
				wp_enqueue_style( $handle, $def['url'] . 'style.css', array( 'df-editor-base' ), self::ver( $file ) );
			}
		}
		wp_add_inline_style(
			'df-editor-base',
			'.df-block-ssr-placeholder{padding:12px 16px;border:1px dashed #8c8f94;background:#f6f7f7;color:#3c434a;font:13px/1.4 system-ui,sans-serif}'
		);
		// Canvas styles of the block UI (label, placeholders, preview states) inside the iframe.
		$css = DF_EDITOR_DIR . 'assets/blocks/editor.css';
		if ( is_readable( $css ) && ! wp_style_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::HANDLE, DF_EDITOR_URL . 'assets/blocks/editor.css', array(), self::ver( $css ) );
		}
	}

	/**
	 * Editor description of section fields: [{key,type,label,max_length,optional,values,aspect_ratio,default}].
	 *
	 * @param array $fields Field definitions (fields or list.fields); defaults keep "{n}".
	 */
	public static function field_schema( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $field ) {
			$field = is_array( $field ) ? $field : array();
			$type  = (string) ( $field['type'] ?? 'text' );
			$row   = array(
				'key'      => (string) $key,
				'type'     => in_array( $type, array( 'text', 'richtext', 'button', 'image', 'setting' ), true ) ? $type : 'text',
				'label'    => (string) ( $field['label'] ?? $key ),
				'optional' => ! empty( $field['optional'] ),
			);
			if ( isset( $field['max_length'] ) ) {
				$row['max_length'] = (int) $field['max_length'];
			}
			if ( ! empty( $field['aspect_ratio'] ) ) {
				$row['aspect_ratio'] = (string) $field['aspect_ratio'];
			}
			if ( ! empty( $field['help'] ) && is_string( $field['help'] ) ) {
				$row['help'] = $field['help'];
			}
			if ( 'setting' === $type ) {
				$labels        = (array) ( $field['labels'] ?? array() );
				$row['values'] = array_map(
					static fn( $v ) => array(
						'value' => (string) $v,
						'label' => isset( $labels[ $v ] ) && is_string( $labels[ $v ] ) ? $labels[ $v ] : (string) $v,
					),
					array_values( (array) ( $field['values'] ?? array() ) )
				);
			}
			$row['default'] = self::default_value( $field );
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * window.dfBlocksData for the editor script.
	 */
	public static function editor_data(): array {
		$sections = array();
		foreach ( self::enabled_types() as $type ) {
			$def    = (array) DF_Registry::get( $type );
			$schema = DF_Section_Schema::section_schema( $type, $def );
			$list   = null;
			$items  = array();
			if ( self::has_list( $def ) ) {
				$list = array(
					'key'           => (string) $def['list']['key'],
					'min'           => (int) $def['list']['min'],
					'max'           => (int) $def['list']['max'],
					'default_count' => (int) $def['list']['default_count'],
					'label'         => self::item_label( $def ),
					'name'          => self::item_name( $type ),
					'fields'        => self::field_schema( (array) ( $def['list']['fields'] ?? array() ) ),
				);
				for ( $n = 1; $n <= (int) $def['list']['default_count']; $n++ ) {
					$items[] = (object) self::default_item( $def, $n );
				}
			}
			$sections[ $type ] = array(
				'label'          => $schema['label'],
				'description'    => $schema['description'],
				'variants'       => $schema['variants'],
				'options'        => $schema['options'],
				'list'           => $list,
				'fields'         => self::field_schema( (array) ( $def['fields'] ?? array() ) ),
				'preview_url'    => $schema['preview_url'],
				'version'        => $schema['version'],
				// Written explicitly into new blocks (attributes have no registered defaults).
				'defaults'       => self::defaults( $def ),
				'default_fields' => (object) self::default_fields( $def ),
				'default_items'  => $items,
			);
		}

		$common  = DF_Section_Schema::common_choices();
		$swatch  = self::scheme_swatches();
		$schemes = array();
		foreach ( $common['schemes'] as $scheme ) {
			$scheme['swatch'] = $swatch[ $scheme['value'] ] ?? '';
			$schemes[]        = $scheme;
		}

		$post = get_post();
		return array(
			'sections'      => (object) $sections,
			'schemes'       => $schemes,
			'spacing'       => $common['spacing'],
			'widths'        => $common['widths'],
			'postId'        => $post instanceof WP_Post ? (int) $post->ID : 0,
			'homeUrl'       => home_url( '/' ),
			'previewPath'   => '/' . self::REST_NS . '/blocks/preview',
			'legacyPath'    => '/' . self::REST_NS . '/blocks/legacy-content',
			'placeholderId' => DF_Content::placeholder_id(),
			// Raw HTML source toggle of the rich text fields (agency only).
			'unfilteredHtml' => current_user_can( 'unfiltered_html' ),
		);
	}

	/**
	 * Background colour of each scheme from the theme.json palette (with the base.css fallbacks).
	 *
	 * @return array<string,string>
	 */
	private static function scheme_swatches(): array {
		$slugs  = array(
			'hell'     => array( 'base', '#ffffff' ),
			'hell-alt' => array( 'base-alt', '#f3f4f6' ),
			'dunkel'   => array( 'contrast', '#111111' ),
			'primaer'  => array( 'primary', '#0b5cad' ),
			'akzent'   => array( 'accent', '#f2a900' ),
		);
		$colors = array();
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				foreach ( (array) ( $palette[ $origin ] ?? array() ) as $entry ) {
					if ( isset( $entry['slug'], $entry['color'] ) && ! isset( $colors[ $entry['slug'] ] ) ) {
						$colors[ (string) $entry['slug'] ] = (string) $entry['color'];
					}
				}
			}
		}
		$out = array();
		foreach ( $slugs as $scheme => [ $slug, $fallback ] ) {
			$out[ $scheme ] = $colors[ $slug ] ?? $fallback;
		}
		return $out;
	}

	private static function ver( string $file ): string {
		return DF_EDITOR_VERSION . '.' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/* ------------------------------------------------------- Allowed blocks */

	/**
	 * Pages: the enabled DF blocks (+ their item blocks, which can only be inserted inside their
	 * section) plus a few core text blocks (filter df_editor_allowed_blocks). Pages that still
	 * have a JSON layout (_df_layout, e.g. with a seiteninhalt section) do not offer DF blocks:
	 * their sections come from the layout. Other editors lose the DF blocks.
	 *
	 * @param bool|string[]           $allowed Allowed block names, or true for all.
	 * @param WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public static function allowed_blocks( $allowed, $context ) {
		$post = ( is_object( $context ) && isset( $context->post ) ) ? $context->post : null;
		$df   = array();
		// Setting "Section blocks in Gutenberg" off: nothing new can be inserted (existing blocks still render).
		$offered = (bool) DF_Settings::get( 'section_blocks' );
		foreach ( self::enabled_types() as $type ) {
			$df[] = self::block_name( $type );
			$def  = DF_Registry::get( $type );
			if ( null !== $def && self::has_list( $def ) ) {
				$df[] = self::item_name( $type );
			}
		}
		if ( $post instanceof WP_Post && self::supports_post( $post ) ) {
			$list = ( ! $offered || DF_Layout_Store::has_layout( (int) $post->ID ) ) ? self::CORE_BLOCKS : array_merge( $df, self::CORE_BLOCKS );
			/**
			 * Filters the blocks allowed in the page editor.
			 *
			 * @param string[] $list Block names.
			 * @param WP_Post  $post Page being edited.
			 */
			return array_values( array_unique( (array) apply_filters( 'df_editor_allowed_blocks', $list, $post ) ) );
		}
		if ( is_array( $allowed ) ) {
			return array_values( array_diff( $allowed, $df ) );
		}
		return $allowed;
	}
}
