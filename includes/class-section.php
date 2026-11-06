<?php
/**
 * DF_Section: the $s object passed to section.php, plus the list item helper.
 *
 * Field ID = {page_key}.{instance_id}.{field}
 * List items = {page_key}.{instance_id}.{list_key}.{n}.{subfield}  (n starts at 1)
 *
 * Values come from DF Inhalt (df_content_values); the layout "content" only provides defaults.
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

	public function field_id( string $field ): string {
		return $this->prefix . $field;
	}

	public function text( string $field, array $args = array() ): void {
		DF_Content::text( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function richtext( string $field, array $args = array() ): void {
		DF_Content::richtext( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function button( string $field, array $args = array() ): void {
		DF_Content::button( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	public function image( string $field, array $args = array() ): void {
		DF_Content::image( $this->field_id( $field ), $this->args( $field, $args ) );
	}

	/**
	 * Current value (saved or default) without output, e.g. for JSON-LD or "hide when empty".
	 */
	public function value( string $field ): mixed {
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
	 * Aspect ratio declared for an image field ("4/3"), or empty string.
	 */
	public function aspect_ratio( string $field ): string {
		return (string) ( $this->fields[ $field ]['aspect_ratio'] ?? '' );
	}

	/**
	 * Whether the current user edits this content on the front end (DF Inhalt).
	 */
	public function can_edit(): bool {
		return DF_Content::can_edit();
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
				// "Open in new tab" from the layout content.
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

	/** Entrance animation of a layout entry (DF Motion); "auto" = section.json motion.default. */
	public const ANIMATIONS = array( 'auto', 'none', 'fade', 'fade-up' );

	protected array $def;
	protected array $instance;
	protected string $page_key;
	protected array $options = array();

	/**
	 * @param array  $instance Layout entry (id, section, variant, scheme, spacing, width, animation, hidden, anchor, options, content).
	 * @param array  $def      Section definition from DF_Registry.
	 * @param string $page_key Page key (or "global").
	 */
	public function __construct( array $instance, array $def, string $page_key ) {
		$this->def      = $def;
		$this->page_key = $page_key;
		$this->instance = self::normalize( $instance, $def );
		$this->prefix   = $page_key . '.' . $this->instance['id'] . '.';
		$this->fields   = (array) ( $def['fields'] ?? array() );
		$this->content  = (array) ( $this->instance['content'] ?? array() );
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

		$scheme = is_string( $inst['scheme'] ?? null ) ? $inst['scheme'] : '';
		$scheme = in_array( $scheme, self::SCHEMES, true ) ? $scheme : (string) ( $def['default_scheme'] ?? 'hell' );

		$spacing = is_array( $inst['spacing'] ?? null ) ? $inst['spacing'] : array();
		$top     = is_string( $spacing['top'] ?? null ) ? $spacing['top'] : 'l';
		$bottom  = is_string( $spacing['bottom'] ?? null ) ? $spacing['bottom'] : 'l';
		$top     = in_array( $top, self::SPACING, true ) ? $top : 'l';
		$bottom  = in_array( $bottom, self::SPACING, true ) ? $bottom : 'l';

		$width = is_string( $inst['width'] ?? null ) ? $inst['width'] : 'container';
		$width = in_array( $width, self::WIDTHS, true ) ? $width : 'container';

		$animation = is_string( $inst['animation'] ?? null ) ? $inst['animation'] : 'auto';
		$animation = in_array( $animation, self::ANIMATIONS, true ) ? $animation : 'auto';

		$options = array();
		$given   = (array) ( $inst['options'] ?? array() );
		foreach ( (array) ( $def['options'] ?? array() ) as $key => $opt ) {
			$values = array_map( 'strval', (array) ( $opt['values'] ?? array() ) );
			$value  = isset( $given[ $key ] ) && is_scalar( $given[ $key ] ) ? (string) ( is_bool( $given[ $key ] ) ? ( $given[ $key ] ? 'yes' : 'no' ) : $given[ $key ] ) : null;
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
			'id'        => (string) ( $inst['id'] ?? '' ),
			'section'   => (string) $def['type'],
			'v'         => (int) ( $inst['v'] ?? $def['version'] ?? 1 ),
			'variant'   => $variant,
			'scheme'    => $scheme,
			'spacing'   => array(
				'top'    => $top,
				'bottom' => $bottom,
			),
			'width'     => $width,
			'animation' => $animation,
			'hidden'    => ! empty( $inst['hidden'] ),
			'anchor'    => sanitize_title( (string) ( $inst['anchor'] ?? '' ) ),
			'options'   => $options,
			'content'   => (array) ( $inst['content'] ?? array() ),
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

	/**
	 * Entrance animation after resolving "auto": none|fade|fade-up.
	 */
	public function effective_animation(): string {
		$animation = $this->instance['animation'];
		return 'auto' === $animation ? self::default_animation( $this->def ) : $animation;
	}

	/**
	 * Default animation of a section type: section.json motion.default, else "fade-up".
	 */
	public static function default_animation( array $def ): string {
		$default = (string) ( $def['motion']['default'] ?? 'fade-up' );
		return in_array( $default, array( 'none', 'fade', 'fade-up' ), true ) ? $default : 'fade-up';
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
			$items[] = new DF_Section_Item( $this->prefix . $key . '.' . $n . '.', (array) $list['fields'], (array) ( $content[ $n - 1 ] ?? array() ), $n );
		}
		return $items;
	}
}

/**
 * One item of a list section (FAQ question, team member, ...).
 */
class DF_Section_Item extends DF_Field_Scope {

	public function __construct( string $prefix, array $fields, array $content, int $n ) {
		$this->prefix  = $prefix;
		$this->fields  = $fields;
		$this->content = $content;
		$this->n       = $n;
	}

	/** 1-based item number. */
	public function index(): int {
		return $this->n;
	}

	/**
	 * Formerly printed a block editor item marker. Prints nothing; kept so templates that still
	 * call <?php $item->attrs(); ?> keep working.
	 */
	public function attrs(): void {}
}
