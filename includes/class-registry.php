<?php
/**
 * Section registry: scans the df-sections/<type>/section.json files of the parent theme and the child theme.
 *
 * The plugin ships no sections (CSS framework + per-client sections in the theme). With no theme
 * sections the registry is empty and every layout entry renders the "missing section" notice.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Registry {

	/** @var array<string,array>|null */
	private static ?array $sections = null;

	/**
	 * All registered section definitions keyed by type.
	 * Later sources win: parent theme < child theme.
	 *
	 * Each definition is the decoded section.json plus:
	 * - dir    absolute folder path (trailing slash)
	 * - url    folder URL (trailing slash)
	 * - source theme|child-theme
	 */
	public static function all(): array {
		if ( null !== self::$sections ) {
			return self::$sections;
		}

		$sections = array();
		$sources  = array();

		$sources['theme'] = array( get_template_directory() . '/df-sections', get_template_directory_uri() . '/df-sections' );
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$sources['child-theme'] = array( get_stylesheet_directory() . '/df-sections', get_stylesheet_directory_uri() . '/df-sections' );
		}

		foreach ( $sources as $source => [ $base_dir, $base_url ] ) {
			if ( ! is_dir( $base_dir ) ) {
				continue;
			}
			foreach ( (array) glob( $base_dir . '/*/section.json' ) as $file ) {
				$def = self::load( $file );
				if ( null === $def ) {
					continue;
				}
				$folder         = basename( dirname( $file ) );
				$def['dir']     = trailingslashit( dirname( $file ) );
				$def['url']     = trailingslashit( $base_url . '/' . $folder );
				$def['source']  = $source;
				$sections[ $def['type'] ] = $def;
			}
		}

		/**
		 * Filters the registered DF sections.
		 *
		 * @param array<string,array> $sections Definitions keyed by type.
		 */
		self::$sections = (array) apply_filters( 'df_editor_sections', $sections );
		return self::$sections;
	}

	public static function get( string $type ): ?array {
		$all = self::all();
		return $all[ $type ] ?? null;
	}

	public static function exists( string $type ): bool {
		return null !== self::get( $type );
	}

	/** Clears the cache (theme switch, tests). */
	public static function flush(): void {
		self::$sections = null;
	}

	/**
	 * Reads and normalises one section.json.
	 */
	private static function load( string $file ): ?array {
		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$def  = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $def ) || empty( $def['type'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', (string) $def['type'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DF Editor: invalid section.json ' . $file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return null;
		}

		$def += array(
			'version'  => 1,
			'label'    => $def['type'],
			'variants' => array(),
			'options'  => array(),
			'fields'   => array(),
		);

		if ( isset( $def['list'] ) && is_array( $def['list'] ) ) {
			$def['list'] += array(
				'key'           => 'items',
				'min'           => 1,
				'max'           => 12,
				'default_count' => 3,
				'fields'        => array(),
			);
		}

		return $def;
	}
}
