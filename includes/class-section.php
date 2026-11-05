<?php
/**
 * DF_Section: the $s object passed to section.php, plus the list item helper.
 *
 * Field ID = {page_key}.{instance_id}.{field}
 * List items = {page_key}.{instance_id}.{list_key}.{n}.{subfield}  (n starts at 1)
 *
 * Two content sources:
 * - DF Inhalt (default, JSON layouts and v1 blocks): values come from DF Inhalt / df_content_values,
 *   the layout "content" only provides defaults.
 * - Block content (DF blocks v2, see docs/BLOCKS.md): "content" holds the values themselves
 *   (fields + list items from the block), printed escaped without DF Inhalt and without
 *   editing markup; can_edit() is false, so empty optional fields are left out.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared field helpers for a section and its list items.
 */
abstract class DF_Field_Scope {

	/** Image value "no image" (optional image switched off); 0 / missing = placeholder image. */
	public const NO_IMAGE = -1;

	/** Field ID prefix incl. trailing dot, e.g. "home.hero-k3x9." */
	protected string $prefix;

	/** Field definitions from section.json. */
	protected array $fields;

	/** Initial content from the layout JSON ("content"). */
	protected array $content;

	/** Item number (1-based) for list items, 0 for the section itself. */
	protected int $n = 0;

	/** Block-content mode: "content" holds the values (no DF Inhalt). */
	protected bool $blocks = false;

	/** Block editor preview (block-content mode only): item markers for click-to-select. */
	protected bool $preview = false;

	public function field_id( string $field ): string {
		return $this->prefix . $field;
	}

	public function text( string $field, array $args = array() ): void {
		if ( $this->blocks ) {
			DF_Content::render_value( 'text', $this->default_for( $field ), $this->args( $field, $args ) );
			return;
		}
		DF_Content::text( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function richtext( string $field, array $args = array() ): void {
		if ( $this->blocks ) {
			DF_Content::render_value( 'richtext', $this->default_for( $field ), $this->args( $field, $args ) );
			return;
		}
		DF_Content::richtext( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function button( string $field, array $args = array() ): void {
		if ( $this->blocks ) {
			DF_Content::render_value( 'button', $this->default_for( $field ), $this->args( $field, $args ) );
			return;
		}
		DF_Content::button( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function image( string $field, array $args = array() ): void {
		if ( $this->blocks ) {
			$a = $this->args( $field, $args );
			if ( (int) $a['default'] <= 0 ) {
				return; // Optional image switched off ("no image").
			}
			// A deleted attachment falls back to the placeholder image.
			$a['default'] = DF_Content::placeholder_id();
			$raw          = $this->content[ $field ] ?? null;
			if ( is_array( $raw ) && isset( $raw['alt'] ) && is_string( $raw['alt'] ) && '' !== trim( $raw['alt'] ) && ! isset( $args['alt'] ) ) {
				$a['alt'] = $raw['alt'];
			}
			DF_Content::render_value( 'image', $this->default_for( $field ), $a );
			return;
		}
		DF_Content::image( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	/**
	 * Current value (saved or default) without output, e.g. for JSON-LD or "hide when empty".
	 * Block-content mode: the block value (section.json default when the block has none).
	 */
	public function value( string $field ): mixed {
		if ( $this->blocks ) {
			return $this->default_for( $field );
		}
		return DF_Content::value( $this->field_id( $field ), $this->default_for( $field ) );
	}

	/**
	 * Whether an image field has an image to show (false when an optional image is switched
	 * off). Editors on the front end (DF Inhalt) always get the slot to fill it in.
	 */
	public function has_image( string $field ): bool {
		return $this->can_edit() || (int) $this->value( $field ) > 0;
	}

	/**
	 * Whether the values come from the block (DF blocks v2) instead of DF Inhalt.
	 */
	public function is_block_content(): bool {
		return $this->blocks;
	}

	/**
	 * Whether this render is the block editor preview (block-content mode only).
	 */
	public function is_preview(): bool {
		return $this->preview;
	}

	/**
	 * Aspect ratio declared for an image field ("4/3"), or empty string.
	 */
	public function aspect_ratio( string $field ): string {
		return (string) ( $this->fields[ $field ]['aspect_ratio'] ?? '' );
	}

	/**
	 * Whether the current user edits this content on the front end (DF Inhalt). Always false in
	 * block-content mode: the texts are edited in the block editor, empty fields stay hidden.
	 */
	public function can_edit(): bool {
		return ! $this->blocks && DF_Content::can_edit();
	}

	/**
	 * Default value: layout "content" first, then section.json default, then placeholder image.
	 */
	protected function default_for( string $field ): mixed {
		$def  = $this->fields[ $field ] ?? array();
		$type = $def['type'] ?? 'text';

		$default = $this->content[ $field ] ?? ( $def['default'] ?? null );

		if ( is_string( $default ) && $this->n > 0 ) {
			$default = str_replace( '{n}', (string) $this->n, $default );
		}
		if ( is_array( $default ) && $this->n > 0 ) {
			$default = array_map( fn( $v ) => is_string( $v ) ? str_replace( '{n}', (string) $this->n, $v ) : $v, $default );
		}

		switch ( $type ) {
			case 'image':
				if ( $this->blocks && is_array( $default ) ) {
					$default = $default['id'] ?? 0; // Block value {id, alt}.
				}
				$id = is_numeric( $default ) ? (int) $default : 0;
				if ( self::NO_IMAGE === $id ) {
					return 0; // Optional image switched off: no image, not the placeholder.
				}
				return $id > 0 ? $id : DF_Content::placeholder_id();
			case 'button':
				$default = is_array( $default ) ? $default : array();
				$button  = array(
					'text' => is_scalar( $default['text'] ?? '' ) ? (string) ( $default['text'] ?? '' ) : '',
					'url'  => is_scalar( $default['url'] ?? '' ) ? (string) ( $default['url'] ?? '' ) : '',
				);
				// "Open in new tab" (block value, kept by wp df to-layout in the layout content).
				if ( '_blank' === ( $default['target'] ?? '' ) ) {
					$button['target'] = '_blank';
				}
				return $button;
			default:
				return is_scalar( $default ) ? (string) $default : '';
		}
	}

	/**
	 * Builds DF Inhalt args from section.json + layout content, caller args win (except default).
	 */
	protected function args( string $field, array $args ): array {
		$def  = $this->fields[ $field ] ?? array();
		$base = array(
			'label'   => (string) ( $def['label'] ?? $field ),
			'default' => $this->default_for( $field ),
		);
		if ( $this->n > 0 ) {
			/* translators: 1: field label, 2: item number */
			$base['label'] = sprintf( __( '%1$s (%2$d)', 'df-editor' ), $base['label'], $this->n );
		}
		if ( isset( $def['max_length'] ) ) {
			$base['max_length'] = (int) $def['max_length'];
		}
		if ( 'image' === ( $def['type'] ?? '' ) && ! empty( $def['aspect_ratio'] ) ) {
			$base['aspect_ratio'] = (string) $def['aspect_ratio'];
		}
		// Button "open in new tab" from the layout content (DF Inhalt reads the target from args).
		if ( 'button' === ( $def['type'] ?? '' ) && ! isset( $args['target'] ) && '_blank' === ( $base['default']['target'] ?? '' ) ) {
			$base['target'] = '_blank';
		}
		if ( 'image' === ( $def['type'] ?? '' ) && ! isset( $args['object_fit'] ) ) {
			$base['object_fit'] = 'cover';
		}
		unset( $args['default'] );
		return array_merge( $base, $args );
	}
}

/**
 * One section instance on a page.
 */
class DF_Section extends DF_Field_Scope {

	public const SCHEMES = array( 'hell', 'hell-alt', 'dunkel', 'primaer', 'akzent' );
	public const SPACING = array( '0', 's', 'm', 'l', 'xl' );
	public const WIDTHS  = array( 'container', 'breit', 'voll' );

	protected array $def;
	protected array $instance;
	protected string $page_key;
	protected array $options = array();

	/**
	 * @param array  $instance Layout entry (id, section, variant, scheme, spacing, width, hidden, anchor, options, content).
	 * @param array  $def      Section definition from DF_Registry.
	 * @param string $page_key Page key (or "global").
	 * @param array  $source   Content source: [] = DF Inhalt (default); ['blocks' => true, 'preview' => bool]
	 *                         = block content (DF blocks v2): "content" holds the field values and
	 *                         the list items, the number of list items is the item count.
	 */
	public function __construct( array $instance, array $def, string $page_key, array $source = array() ) {
		$this->def      = $def;
		$this->page_key = $page_key;
		$this->instance = self::normalize( $instance, $def );
		$this->prefix   = $page_key . '.' . $this->instance['id'] . '.';
		$this->fields   = (array) ( $def['fields'] ?? array() );
		$this->content  = (array) ( $this->instance['content'] ?? array() );
		$this->blocks   = ! empty( $source['blocks'] );
		$this->preview  = $this->blocks && ! empty( $source['preview'] );
		if ( $this->blocks && ! empty( $def['list'] ) ) {
			// Block content: exactly the items of the block (up to max), no min padding.
			$items = $this->content[ (string) $def['list']['key'] ] ?? array();
			$count = (string) min( (int) $def['list']['max'], is_array( $items ) ? count( $items ) : 0 );

			$this->instance['options']['count'] = $count;
		}
		$this->options = $this->instance['options'];
	}

	/**
	 * Normalises a layout entry: unknown values fall back to defaults.
	 */
	public static function normalize( array $inst, array $def ): array {
		$variants = array_keys( (array) ( $def['variants'] ?? array() ) );
		$variant  = (string) ( $inst['variant'] ?? '' );
		if ( ! in_array( $variant, $variants, true ) ) {
			$variant = (string) ( $def['default_variant'] ?? ( $variants[0] ?? 'default' ) );
		}

		$scheme = (string) ( $inst['scheme'] ?? '' );
		$scheme = in_array( $scheme, self::SCHEMES, true ) ? $scheme : (string) ( $def['default_scheme'] ?? 'hell' );

		$spacing = (array) ( $inst['spacing'] ?? array() );
		$top     = (string) ( $spacing['top'] ?? 'l' );
		$bottom  = (string) ( $spacing['bottom'] ?? 'l' );
		$top     = in_array( $top, self::SPACING, true ) ? $top : 'l';
		$bottom  = in_array( $bottom, self::SPACING, true ) ? $bottom : 'l';

		$width = (string) ( $inst['width'] ?? 'container' );
		$width = in_array( $width, self::WIDTHS, true ) ? $width : 'container';

		$options = array();
		$given   = (array) ( $inst['options'] ?? array() );
		foreach ( (array) ( $def['options'] ?? array() ) as $key => $opt ) {
			$values = array_map( 'strval', (array) ( $opt['values'] ?? array() ) );
			$value  = isset( $given[ $key ] ) ? (string) ( is_bool( $given[ $key ] ) ? ( $given[ $key ] ? 'yes' : 'no' ) : $given[ $key ] ) : null;
			if ( null === $value || ( $values && ! in_array( $value, $values, true ) ) ) {
				$value = (string) ( $opt['default'] ?? ( $values[0] ?? '' ) );
			}
			$options[ $key ] = $value;
		}

		if ( ! empty( $def['list'] ) ) {
			$list  = $def['list'];
			$count = isset( $given['count'] ) && is_numeric( $given['count'] ) ? (int) $given['count'] : (int) $list['default_count'];
			$options['count'] = (string) max( (int) $list['min'], min( (int) $list['max'], $count ) );
		}

		return array(
			'id'      => (string) ( $inst['id'] ?? '' ),
			'section' => (string) $def['type'],
			'v'       => (int) ( $inst['v'] ?? $def['version'] ?? 1 ),
			'variant' => $variant,
			'scheme'  => $scheme,
			'spacing' => array(
				'top'    => $top,
				'bottom' => $bottom,
			),
			'width'   => $width,
			'hidden'  => ! empty( $inst['hidden'] ),
			'anchor'  => sanitize_title( (string) ( $inst['anchor'] ?? '' ) ),
			'options' => $options,
			'content' => (array) ( $inst['content'] ?? array() ),
		);
	}

	public function id(): string {
		return $this->instance['id'];
	}

	public function type(): string {
		return $this->instance['section'];
	}

	public function variant(): string {
		return $this->instance['variant'];
	}

	public function page_key(): string {
		return $this->page_key;
	}

	/**
	 * Normalised option value (always a string; list "count" included).
	 */
	public function opt( string $key ): ?string {
		return $this->options[ $key ] ?? null;
	}

	/** Normalised layout entry (used by the renderer for the wrapper). */
	public function instance(): array {
		return $this->instance;
	}

	public function definition(): array {
		return $this->def;
	}

	/**
	 * Number of list items (0 if the section has no list).
	 */
	public function count(): int {
		return empty( $this->def['list'] ) ? 0 : (int) $this->options['count'];
	}

	/**
	 * List item helpers, 1..count().
	 *
	 * @return DF_Section_Item[]
	 */
	public function items(): array {
		if ( empty( $this->def['list'] ) ) {
			return array();
		}
		$list    = $this->def['list'];
		$key     = (string) $list['key'];
		$content = (array) ( $this->content[ $key ] ?? array() );
		$items   = array();
		for ( $n = 1; $n <= $this->count(); $n++ ) {
			// Layout content lists are 0-based JSON arrays; field numbers start at 1.
			$items[] = new DF_Section_Item( $this->prefix . $key . '.' . $n . '.', (array) $list['fields'], (array) ( $content[ $n - 1 ] ?? array() ), $n, $this->blocks, $this->preview );
		}
		return $items;
	}
}

/**
 * One item of a list section (FAQ question, team member, ...).
 */
class DF_Section_Item extends DF_Field_Scope {

	public function __construct( string $prefix, array $fields, array $content, int $n, bool $blocks = false, bool $preview = false ) {
		$this->prefix  = $prefix;
		$this->fields  = $fields;
		$this->content = $content;
		$this->n       = $n;
		$this->blocks  = $blocks;
		$this->preview = $blocks && $preview;
	}

	/** 1-based item number. */
	public function index(): int {
		return $this->n;
	}

	/**
	 * Prints the item marker attribute for the root element of an item (block editor preview
	 * only: a click on the item selects its child block). Prints nothing anywhere else.
	 *
	 * Usage in section.php: <li class="df-x__item"<?php $item->attrs(); ?>>
	 */
	public function attrs(): void {
		if ( $this->preview ) {
			printf( ' data-df-item="%d"', (int) $this->n );
		}
	}
}
