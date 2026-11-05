<?php
/**
 * Reads/writes the page layout (_df_layout) and the stable page key (_df_page_key).
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Layout_Store {

	public const META_LAYOUT  = '_df_layout';
	public const META_KEY     = '_df_page_key';
	public const META_HISTORY = '_df_layout_history';
	/** {saved_at (UTC ISO), user_id} of the current live layout (copied into history on the next publish). */
	public const META_SAVED   = '_df_layout_saved';

	/** History entries kept per page (newest first). */
	public const HISTORY_MAX = 30;

	/** Field ID regex of DF Inhalt. */
	public const ID_REGEX = '/^[a-z0-9][a-z0-9._-]*$/';

	/** Instance IDs must not contain dots (dot separates field ID parts). */
	public const INSTANCE_REGEX = '/^[a-z0-9][a-z0-9_-]*$/';

	/** Page keys: [a-z0-9-] only. */
	public const PAGE_KEY_REGEX = '/^[a-z0-9][a-z0-9-]*$/';

	/** Reserved keys used by global fields. */
	public const RESERVED_KEYS = array( 'global' );

	public static function init(): void {
		// Duplicate Post & co. copy _df_page_key: give the copy a fresh key.
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
	}

	/**
	 * Layout array of a post, or null when the post has no DF layout.
	 */
	public static function get( int $post_id ): ?array {
		return self::read_meta_layout( $post_id, self::META_LAYOUT );
	}

	/**
	 * Decodes a stored layout and fills format, page_key and rev.
	 */
	private static function read_meta_layout( int $post_id, string $meta_key ): ?array {
		$raw = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || ! isset( $raw['sections'] ) || ! is_array( $raw['sections'] ) ) {
			return null;
		}
		$raw['format']   = (int) ( $raw['format'] ?? DF_LAYOUT_FORMAT );
		$raw['page_key'] = self::read_key( $post_id );
		$raw['rev']      = max( 0, (int) ( $raw['rev'] ?? 0 ) );
		return $raw;
	}

	/**
	 * Revision number of the live layout (0 = never published through the rev-aware path).
	 */
	public static function rev( int $post_id ): int {
		$layout = self::get( $post_id );
		return null === $layout ? 0 : (int) $layout['rev'];
	}

	/**
	 * Normalises a layout for storage and encodes it as JSON (false on failure).
	 *
	 * @return string|false
	 */
	private static function encode( int $post_id, array $layout, int $rev ) {
		$key = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' === $key ) {
			$key = self::assign_key( $post_id, (string) ( $layout['page_key'] ?? '' ) );
		}
		unset( $layout['base_rev'] );
		$layout = array_merge(
			array( 'format' => DF_LAYOUT_FORMAT ),
			$layout,
			array(
				'page_key' => $key,
				'rev'      => max( 0, $rev ),
			)
		);
		$sections = array();
		foreach ( array_values( (array) ( $layout['sections'] ?? array() ) ) as $inst ) {
			if ( is_array( $inst ) ) {
				// Keep JSON objects as objects ({} instead of []) for readable exports.
				foreach ( array( 'options', 'content', 'spacing' ) as $obj ) {
					if ( array_key_exists( $obj, $inst ) && array() === $inst[ $obj ] ) {
						$inst[ $obj ] = new stdClass();
					}
				}
			}
			$sections[] = $inst;
		}
		$layout['sections'] = $sections;
		return wp_json_encode( $layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function has_layout( int $post_id ): bool {
		return null !== self::get( $post_id );
	}

	/**
	 * Saves a layout. The page key is assigned once and never changes;
	 * the layout's "page_key" is only used when the post has no key yet.
	 *
	 * Low-level write of the live layout: no history, no rev change (an explicit integer
	 * "rev" in $layout is stored as given, otherwise the current live rev is kept).
	 * Use publish() for a normal save.
	 */
	public static function set( int $post_id, array $layout ): bool {
		$rev  = isset( $layout['rev'] ) && is_numeric( $layout['rev'] ) ? (int) $layout['rev'] : self::rev( $post_id );
		$json = self::encode( $post_id, $layout, $rev );
		if ( false === $json ) {
			return false;
		}
		update_post_meta( $post_id, self::META_LAYOUT, wp_slash( $json ) );
		return true;
	}

	/**
	 * Removes the live layout (history is kept for a later restore).
	 */
	public static function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_LAYOUT );
		delete_post_meta( $post_id, self::META_SAVED );
	}

	/**
	 * Current time as UTC ISO 8601 ("2026-11-05T10:00:00Z").
	 */
	public static function now_iso(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Publishes a layout: the previous live layout goes into the history, rev + 1, the
	 * plain-text fallback is regenerated and page caches are purged.
	 *
	 * Used by the layout metabox, `wp df import` and the block converter.
	 *
	 * @param array $args fallback (bool, default true), overwrite_content (bool), user_id (int).
	 * @return array{ok: bool, rev: int, fallback: string}
	 */
	public static function publish( int $post_id, array $layout, array $args = array() ): array {
		$args = array_merge(
			array(
				'fallback'          => true,
				'overwrite_content' => false,
				'user_id'           => get_current_user_id(),
			),
			$args
		);

		$previous = self::get( $post_id );
		$rev      = ( null === $previous ? 0 : (int) $previous['rev'] ) + 1;
		$json     = self::encode( $post_id, $layout, $rev );
		if ( false === $json ) {
			return array(
				'ok'       => false,
				'rev'      => $rev - 1,
				'fallback' => 'none',
			);
		}

		if ( null !== $previous ) {
			$saved = get_post_meta( $post_id, self::META_SAVED, true );
			$saved = is_array( $saved ) ? $saved : array();
			self::push_history(
				$post_id,
				array(
					'rev'      => (int) $previous['rev'],
					'saved_at' => (string) ( $saved['saved_at'] ?? self::now_iso() ),
					'user_id'  => (int) ( $saved['user_id'] ?? 0 ),
					'layout'   => $previous,
				)
			);
		}

		update_post_meta( $post_id, self::META_LAYOUT, wp_slash( $json ) );
		update_post_meta(
			$post_id,
			self::META_SAVED,
			array(
				'saved_at' => self::now_iso(),
				'user_id'  => (int) $args['user_id'],
			)
		);

		$fallback = 'none';
		if ( $args['fallback'] ) {
			$fallback = DF_Fallback::write( $post_id, (bool) $args['overwrite_content'] );
		}

		self::purge_caches( $post_id );

		/**
		 * Fires after a DF layout was published (metabox, CLI import, converter).
		 *
		 * @param int   $post_id Post ID.
		 * @param array $layout  The new live layout.
		 * @param int   $rev     New revision number.
		 */
		do_action( 'df_layout_published', $post_id, self::get( $post_id ), $rev );

		return array(
			'ok'       => true,
			'rev'      => $rev,
			'fallback' => $fallback,
		);
	}

	/**
	 * Purges the WordPress post cache and, when present, common page cache plugins for one post.
	 */
	public static function purge_caches( int $post_id ): void {
		clean_post_cache( $post_id );
		$url = (string) get_permalink( $post_id );

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id ); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id ); // W3 Total Cache.
		}
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			wpsc_delete_post_cache( $post_id ); // WP Super Cache.
		}
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $post_id ); // WP Fastest Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) && '' !== $url ) {
			sg_cachepress_purge_cache( $url ); // SiteGround Optimizer.
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_post', $post_id ); // LiteSpeed Cache.
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			do_action( 'cache_enabler_clear_page_cache_by_post', $post_id ); // Cache Enabler.
		}
	}

	/* -------------------------------------------------------------- History */

	/**
	 * History entries, newest first: {rev, saved_at, user_id, layout}.
	 */
	public static function get_history( int $post_id ): array {
		$history = get_post_meta( $post_id, self::META_HISTORY, true );
		if ( ! is_array( $history ) ) {
			return array();
		}
		return array_values( array_filter( $history, static fn( $e ) => is_array( $e ) && isset( $e['layout'] ) && is_array( $e['layout'] ) ) );
	}

	/**
	 * One history entry by rev, or null.
	 */
	public static function get_history_entry( int $post_id, int $rev ): ?array {
		foreach ( self::get_history( $post_id ) as $entry ) {
			if ( (int) ( $entry['rev'] ?? -1 ) === $rev ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Adds an entry at the front and keeps at most HISTORY_MAX entries.
	 */
	public static function push_history( int $post_id, array $entry ): void {
		$history = self::get_history( $post_id );
		array_unshift( $history, $entry );
		$max     = max( 1, (int) apply_filters( 'df_layout_history_max', self::HISTORY_MAX ) );
		$history = array_slice( $history, 0, $max );
		update_post_meta( $post_id, self::META_HISTORY, wp_slash( $history ) );
	}

	/**
	 * The page key of a post; generated (from the slug) on first use.
	 */
	public static function page_key( int $post_id ): string {
		$key = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' !== $key ) {
			return $key;
		}
		return self::assign_key( $post_id );
	}

	/**
	 * Page key for rendering. Visitors never trigger a database write: without a stored key
	 * they get the slug-based key (nothing can be saved under it yet). Only users who can edit
	 * content assign and store the key, because their edits create field IDs under it.
	 */
	public static function read_key( int $post_id ): string {
		$key = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' !== $key ) {
			return $key;
		}
		if ( DF_Content::can_edit() ) {
			return self::assign_key( $post_id );
		}
		$key = self::normalize_key( (string) get_post_field( 'post_name', $post_id ) );
		return '' !== $key ? $key : 'page-' . $post_id;
	}

	/**
	 * Assigns and stores a unique, valid page key for a post.
	 */
	public static function assign_key( int $post_id, string $wanted = '' ): string {
		$base = self::normalize_key( '' !== $wanted ? $wanted : (string) get_post_field( 'post_name', $post_id ) );
		if ( '' === $base ) {
			$base = self::normalize_key( (string) get_the_title( $post_id ) );
		}
		if ( '' === $base ) {
			$base = 'page-' . self::random_id();
		}

		$key = $base;
		$i   = 2;
		while ( self::key_taken( $key, $post_id ) ) {
			$key = $base . '-' . $i++;
			if ( $i > 50 ) {
				$key = 'page-' . self::random_id();
				break;
			}
		}

		update_post_meta( $post_id, self::META_KEY, $key );
		return $key;
	}

	/**
	 * Normalises any string to [a-z0-9-] (umlauts transliterated). Empty string if impossible.
	 */
	public static function normalize_key( string $value ): string {
		$value = strtolower( remove_accents( $value ) );
		$value = (string) preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value = trim( (string) preg_replace( '/-+/', '-', $value ), '-' );
		$value = substr( $value, 0, 40 );
		$value = rtrim( $value, '-' );
		if ( '' === $value || in_array( $value, self::RESERVED_KEYS, true ) || ! preg_match( self::PAGE_KEY_REGEX, $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Whether another post already uses this key.
	 */
	public static function key_taken( string $key, int $exclude_post_id = 0 ): bool {
		return 0 !== self::find_by_key( $key, $exclude_post_id );
	}

	/**
	 * Post ID that owns a page key (lowest ID wins), 0 if none.
	 */
	public static function find_by_key( string $key, int $exclude_post_id = 0 ): int {
		global $wpdb;
		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id <> %d AND p.post_type <> 'revision'
				 ORDER BY pm.post_id ASC LIMIT 1",
				self::META_KEY,
				$key,
				$exclude_post_id
			)
		);
		return (int) $id;
	}

	/**
	 * If a copied/edited _df_page_key collides with an older post, re-key the newer post.
	 */
	public static function on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( self::META_KEY !== $meta_key || ! is_string( $meta_value ) || '' === $meta_value ) {
			return;
		}
		$other = self::find_by_key( $meta_value, (int) $post_id );
		if ( $other && $other < (int) $post_id ) {
			remove_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10 );
			delete_post_meta( (int) $post_id, self::META_KEY );
			self::assign_key( (int) $post_id, $meta_value );
			add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		}
	}

	/**
	 * Random lowercase alphanumeric ID (default 4 chars), e.g. for instance IDs "hero-k3x9".
	 */
	public static function random_id( int $length = 4 ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, 35 ) ];
		}
		return $out;
	}

	/**
	 * Whether the layout contains a section of the given type (hidden ones included).
	 */
	public static function has_section_type( ?array $layout, string $type ): bool {
		foreach ( (array) ( $layout['sections'] ?? array() ) as $inst ) {
			if ( ( $inst['section'] ?? '' ) === $type ) {
				return true;
			}
		}
		return false;
	}
}
