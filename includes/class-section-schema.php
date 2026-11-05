<?php
/**
 * Editor-facing description of the section library (labels of variants, options, schemes,
 * spacing and widths), used by the DF blocks sidebar.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Section_Schema {

	/**
	 * Editor-facing description of one section definition (DF blocks):
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
}
