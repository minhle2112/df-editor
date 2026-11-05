<?php
/**
 * Validates a layout against the registered section.json files.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Validator {

	/** DF Inhalt log column limit. */
	private const MAX_FIELD_ID_LENGTH = 191;

	/**
	 * @return array{errors: string[], warnings: string[]}
	 */
	public static function validate( array $layout ): array {
		$errors   = array();
		$warnings = array();

		if ( (int) ( $layout['format'] ?? DF_LAYOUT_FORMAT ) !== DF_LAYOUT_FORMAT ) {
			/* translators: %d: layout format number */
			$warnings[] = sprintf( __( 'Unknown layout format %d.', 'df-editor' ), (int) $layout['format'] );
		}

		$page_key = (string) ( $layout['page_key'] ?? '' );
		if ( '' !== $page_key && ! preg_match( DF_Layout_Store::PAGE_KEY_REGEX, $page_key ) ) {
			/* translators: %s: page key */
			$errors[] = sprintf( __( 'Invalid page_key "%s" (allowed: a-z, 0-9, -).', 'df-editor' ), $page_key );
		}

		if ( ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			$errors[] = __( 'The layout needs a "sections" array.', 'df-editor' );
			return compact( 'errors', 'warnings' );
		}

		$ids      = array();
		$h1       = array();
		$html_ids = array(); // instance ids + normalised anchors => section label (both become HTML ids).
		$visible  = 0;
		$wp_body  = false; // a visible seiteninhalt section prints post_content, which may carry the H1.

		foreach ( array_values( $layout['sections'] ) as $i => $inst ) {
			/* translators: %d: section position */
			$where = sprintf( __( 'Section #%d', 'df-editor' ), $i + 1 );

			if ( ! is_array( $inst ) ) {
				$errors[] = $where . ': ' . __( 'must be an object.', 'df-editor' );
				continue;
			}

			$id   = (string) ( $inst['id'] ?? '' );
			$type = (string) ( $inst['section'] ?? '' );
			if ( '' !== $id ) {
				$where .= ' (' . $id . ')';
			}

			if ( '' === $id || ! preg_match( DF_Layout_Store::INSTANCE_REGEX, $id ) ) {
				$errors[] = $where . ': ' . __( 'missing or invalid "id" (allowed: a-z, 0-9, -, _; no dots).', 'df-editor' );
			} elseif ( isset( $ids[ $id ] ) ) {
				$errors[] = $where . ': ' . __( 'duplicate "id".', 'df-editor' );
			}
			$ids[ $id ] = true;

			// Instance id and anchor both end up as HTML ids; they must be unique on the page.
			if ( '' !== $id ) {
				if ( isset( $html_ids[ $id ] ) && $html_ids[ $id ] !== $where ) {
					/* translators: 1: HTML id, 2: other section */
					$errors[] = $where . ': ' . sprintf( __( 'HTML id "%1$s" is already used by %2$s.', 'df-editor' ), $id, $html_ids[ $id ] );
				}
				$html_ids[ $id ] = $where;
			}
			$anchor = sanitize_title( (string) ( $inst['anchor'] ?? '' ) );
			if ( '' !== $anchor && $anchor !== $id ) {
				if ( isset( $html_ids[ $anchor ] ) ) {
					/* translators: 1: anchor, 2: other section */
					$errors[] = $where . ': ' . sprintf( __( 'anchor "%1$s" is already used as an HTML id by %2$s.', 'df-editor' ), $anchor, $html_ids[ $anchor ] );
				}
				$html_ids[ $anchor ] = $where;
			}

			$def = DF_Registry::get( $type );
			if ( null === $def ) {
				/* translators: %s: section type */
				$errors[] = $where . ': ' . sprintf( __( 'unknown section "%s".', 'df-editor' ), $type );
				continue;
			}

			// Variant.
			$variants = array_keys( (array) $def['variants'] );
			if ( isset( $inst['variant'] ) && ! in_array( (string) $inst['variant'], $variants, true ) ) {
				/* translators: 1: given value, 2: allowed values */
				$errors[] = $where . ': ' . sprintf( __( 'variant "%1$s" not allowed (%2$s).', 'df-editor' ), $inst['variant'], implode( ', ', $variants ) );
			}

			// Common options.
			if ( isset( $inst['scheme'] ) && ! in_array( (string) $inst['scheme'], DF_Section::SCHEMES, true ) ) {
				/* translators: 1: option name, 2: value, 3: allowed values */
				$errors[] = $where . ': ' . sprintf( __( 'option "%1$s" has invalid value "%2$s" (%3$s).', 'df-editor' ), 'scheme', $inst['scheme'], implode( ', ', DF_Section::SCHEMES ) );
			}
			foreach ( array( 'top', 'bottom' ) as $side ) {
				if ( isset( $inst['spacing'][ $side ] ) && ! in_array( (string) $inst['spacing'][ $side ], DF_Section::SPACING, true ) ) {
					/* translators: 1: option name, 2: value, 3: allowed values */
					$errors[] = $where . ': ' . sprintf( __( 'option "%1$s" has invalid value "%2$s" (%3$s).', 'df-editor' ), 'spacing.' . $side, $inst['spacing'][ $side ], implode( ', ', DF_Section::SPACING ) );
				}
			}
			if ( isset( $inst['width'] ) && ! in_array( (string) $inst['width'], DF_Section::WIDTHS, true ) ) {
				$errors[] = $where . ': ' . sprintf( __( 'option "%1$s" has invalid value "%2$s" (%3$s).', 'df-editor' ), 'width', $inst['width'], implode( ', ', DF_Section::WIDTHS ) );
			}
			foreach ( array( 'hidden', 'locked' ) as $flag ) {
				if ( isset( $inst[ $flag ] ) && ! is_bool( $inst[ $flag ] ) ) {
					/* translators: %s: key name */
					$errors[] = $where . ': ' . sprintf( __( '"%s" must be true or false.', 'df-editor' ), $flag );
				}
			}
			if ( ! empty( $inst['anchor'] ) && sanitize_title( (string) $inst['anchor'] ) !== $inst['anchor'] ) {
				/* translators: %s: anchor */
				$warnings[] = $where . ': ' . sprintf( __( 'anchor "%s" will be normalised.', 'df-editor' ), $inst['anchor'] );
			}

			// Section options.
			$given = (array) ( $inst['options'] ?? array() );
			foreach ( $given as $key => $value ) {
				if ( 'count' === $key && ! empty( $def['list'] ) ) {
					$min = (int) $def['list']['min'];
					$max = (int) $def['list']['max'];
					if ( ! is_numeric( $value ) || (int) $value < $min || (int) $value > $max ) {
						/* translators: 1: value, 2: min, 3: max */
						$errors[] = $where . ': ' . sprintf( __( 'count "%1$s" must be between %2$d and %3$d.', 'df-editor' ), is_scalar( $value ) ? $value : '?', $min, $max );
					}
					continue;
				}
				if ( ! isset( $def['options'][ $key ] ) ) {
					/* translators: %s: option name */
					$warnings[] = $where . ': ' . sprintf( __( 'unknown option "%s" is ignored.', 'df-editor' ), $key );
					continue;
				}
				$allowed = array_map( 'strval', (array) ( $def['options'][ $key ]['values'] ?? array() ) );
				$str     = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( is_scalar( $value ) ? (string) $value : '' );
				if ( $allowed && ! in_array( $str, $allowed, true ) ) {
					$errors[] = $where . ': ' . sprintf( __( 'option "%1$s" has invalid value "%2$s" (%3$s).', 'df-editor' ), $key, $str, implode( ', ', $allowed ) );
				}
			}

			// Content keys.
			$known = array_keys( (array) $def['fields'] );
			if ( ! empty( $def['list'] ) ) {
				$known[] = (string) $def['list']['key'];
			}
			$list_key = ! empty( $def['list'] ) ? (string) $def['list']['key'] : '';
			foreach ( (array) ( $inst['content'] ?? array() ) as $field => $value ) {
				$field = (string) $field;
				if ( ! in_array( $field, $known, true ) ) {
					/* translators: %s: field name */
					$warnings[] = $where . ': ' . sprintf( __( 'content for unknown field "%s" is ignored.', 'df-editor' ), $field );
					continue;
				}
				if ( '' !== $list_key && $field === $list_key ) {
					$list_fields = (array) ( $def['list']['fields'] ?? array() );
					if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
						/* translators: %s: field name */
						$errors[] = $where . ': ' . sprintf( __( 'content "%s" must be an array of items.', 'df-editor' ), $field );
						continue;
					}
					foreach ( $value as $n => $item ) {
						$item_where = $field . '[' . $n . ']';
						if ( ! is_array( $item ) || ( array() !== $item && array_is_list( $item ) ) ) {
							/* translators: %s: list item */
							$errors[] = $where . ': ' . sprintf( __( 'content "%s" must be an object.', 'df-editor' ), $item_where );
							continue;
						}
						foreach ( $item as $sub => $sub_value ) {
							if ( ! isset( $list_fields[ $sub ] ) ) {
								/* translators: 1: subfield, 2: list item */
								$errors[] = $where . ': ' . sprintf( __( 'unknown subfield "%1$s" in %2$s.', 'df-editor' ), $sub, $item_where );
								continue;
							}
							$type_error = self::value_type_error( (string) ( $list_fields[ $sub ]['type'] ?? '' ), $sub_value );
							if ( $type_error ) {
								$errors[] = $where . ': ' . $item_where . '.' . $sub . ' ' . $type_error;
							}
						}
					}
					$count = (int) ( DF_Section::normalize( $inst, $def )['options']['count'] ?? 0 );
					if ( count( $value ) > $count ) {
						/* translators: 1: item count, 2: field name, 3: count option */
						$warnings[] = $where . ': ' . sprintf( __( '%1$d items in "%2$s" but count is %3$d; extra items are not shown.', 'df-editor' ), count( $value ), $field, $count );
					}
					continue;
				}
				$type_error = self::value_type_error( (string) ( $def['fields'][ $field ]['type'] ?? '' ), $value );
				if ( $type_error ) {
					$errors[] = $where . ': ' . $field . ' ' . $type_error;
				}
			}

			// Field ID length.
			$longest = max( array_map( 'strlen', array_merge( array( '' ), $known ) ) ) + 16;
			if ( strlen( $page_key . '.' . $id . '.' ) + $longest > self::MAX_FIELD_ID_LENGTH ) {
				$warnings[] = $where . ': ' . __( 'field IDs may exceed 191 characters; use a shorter page key or id.', 'df-editor' );
			}

			// H1 count (visible sections only).
			if ( empty( $inst['hidden'] ) ) {
				++$visible;
				if ( 'seiteninhalt' === ( $inst['section'] ?? '' ) ) {
					$wp_body = true;
				}
				$normalized = DF_Section::normalize( $inst, $def );
				if ( 'h1' === ( $normalized['options']['heading_level'] ?? '' ) ) {
					$h1[] = $id;
				}
			}
		}

		if ( count( $h1 ) > 1 ) {
			/* translators: %s: section IDs */
			$warnings[] = sprintf( __( 'More than one section uses an H1 heading: %s.', 'df-editor' ), implode( ', ', $h1 ) );
		} elseif ( $visible > 0 && ! $h1 && ! $wp_body ) {
			// DF pages do not print the page title, so the layout must provide the H1.
			$warnings[] = __( 'No section uses an H1 heading.', 'df-editor' );
		}

		return compact( 'errors', 'warnings' );
	}

	/**
	 * Changes a "reorder-only" user (df_reorder_sections without df_edit_layout) is not allowed
	 * to make between two layouts: only the ORDER of the existing sections and their "hidden"
	 * flag may change; locked sections keep their position and hidden flag.
	 *
	 * @param array $old Layout the user started from (current draft, else live).
	 * @param array $new Layout the user wants to save.
	 * @return string[] Violations (empty = allowed).
	 */
	public static function reorder_only_violations( array $old, array $new ): array {
		$violations = array();

		// Top-level keys other than the server-managed ones must not change.
		$ignore = array( 'format', 'page_key', 'rev', 'base_rev', 'sections' );
		$old_top = array_diff_key( $old, array_flip( $ignore ) );
		$new_top = array_diff_key( $new, array_flip( $ignore ) );
		if ( self::canonical( $old_top ) !== self::canonical( $new_top ) ) {
			$violations[] = __( 'Only the order and visibility of sections may be changed.', 'df-editor' );
		}

		$old_sections = self::sections_by_id( $old );
		$new_sections = self::sections_by_id( $new );
		if ( null === $old_sections || null === $new_sections ) {
			$violations[] = __( 'Sections need unique ids.', 'df-editor' );
			return $violations;
		}

		$added   = array_diff( array_keys( $new_sections ), array_keys( $old_sections ) );
		$removed = array_diff( array_keys( $old_sections ), array_keys( $new_sections ) );
		if ( $added ) {
			/* translators: %s: section ids */
			$violations[] = sprintf( __( 'Sections may not be added (%s).', 'df-editor' ), implode( ', ', $added ) );
		}
		if ( $removed ) {
			/* translators: %s: section ids */
			$violations[] = sprintf( __( 'Sections may not be removed (%s).', 'df-editor' ), implode( ', ', $removed ) );
		}

		$old_pos = array_flip( array_keys( $old_sections ) );
		$new_pos = array_flip( array_keys( $new_sections ) );
		foreach ( $old_sections as $id => $old_inst ) {
			if ( ! isset( $new_sections[ $id ] ) ) {
				continue;
			}
			$new_inst = $new_sections[ $id ];
			$locked   = ! empty( $old_inst['locked'] );

			$a = self::comparable_entry( $old_inst );
			$b = self::comparable_entry( $new_inst );
			unset( $a['hidden'], $b['hidden'] );
			if ( self::canonical( $a ) !== self::canonical( $b ) ) {
				/* translators: %s: section id */
				$violations[] = sprintf( __( 'Section %s: only its position and visibility may be changed.', 'df-editor' ), $id );
				continue;
			}
			if ( $locked && $old_pos[ $id ] !== $new_pos[ $id ] ) {
				/* translators: %s: section id */
				$violations[] = sprintf( __( 'Section %s is locked and keeps its position.', 'df-editor' ), $id );
			}
			if ( $locked && ! empty( $old_inst['hidden'] ) !== ! empty( $new_inst['hidden'] ) ) {
				/* translators: %s: section id */
				$violations[] = sprintf( __( 'Section %s is locked; its visibility cannot be changed.', 'df-editor' ), $id );
			}
		}

		return $violations;
	}

	/**
	 * A layout entry without keys whose value equals "absent" (empty options/content/spacing,
	 * false hidden/locked, empty anchor), so the panel's {} defaults do not count as changes.
	 */
	private static function comparable_entry( array $inst ): array {
		foreach ( array( 'options', 'content', 'spacing' ) as $key ) {
			if ( array_key_exists( $key, $inst ) && ( array() === $inst[ $key ] || ( is_object( $inst[ $key ] ) && array() === (array) $inst[ $key ] ) ) ) {
				unset( $inst[ $key ] );
			}
		}
		foreach ( array( 'hidden', 'locked' ) as $key ) {
			if ( array_key_exists( $key, $inst ) && false === $inst[ $key ] ) {
				unset( $inst[ $key ] );
			}
		}
		if ( array_key_exists( 'anchor', $inst ) && '' === $inst['anchor'] ) {
			unset( $inst['anchor'] );
		}
		return $inst;
	}

	/**
	 * Sections keyed by id in order, or null when an entry has no id or an id repeats.
	 */
	private static function sections_by_id( array $layout ): ?array {
		$out = array();
		foreach ( array_values( (array) ( $layout['sections'] ?? array() ) ) as $inst ) {
			if ( ! is_array( $inst ) || ! isset( $inst['id'] ) || ! is_scalar( $inst['id'] ) || '' === (string) $inst['id'] ) {
				return null;
			}
			$id = (string) $inst['id'];
			if ( isset( $out[ $id ] ) ) {
				return null;
			}
			$out[ $id ] = $inst;
		}
		return $out;
	}

	/**
	 * Order-independent JSON of a value (object keys sorted, empty objects = empty arrays,
	 * absent/false booleans treated alike at the entry level by the caller).
	 */
	private static function canonical( mixed $value ): string {
		$sort = static function ( $v ) use ( &$sort ) {
			if ( is_object( $v ) ) {
				$v = (array) $v;
			}
			if ( ! is_array( $v ) ) {
				return $v;
			}
			if ( ! array_is_list( $v ) ) {
				ksort( $v, SORT_STRING );
			}
			foreach ( $v as $k => $item ) {
				$v[ $k ] = $sort( $item );
			}
			return $v;
		};
		$value = $sort( $value );
		if ( is_array( $value ) ) {
			// "locked": false and a missing "locked" mean the same.
			if ( array_key_exists( 'locked', $value ) && false === $value['locked'] ) {
				unset( $value['locked'] );
			}
		}
		return (string) wp_json_encode( $value );
	}

	/**
	 * Checks a content value against its field type.
	 *
	 * @return string Error text, or '' when the value fits.
	 */
	private static function value_type_error( string $type, mixed $value ): string {
		switch ( $type ) {
			case 'text':
			case 'richtext':
				return is_string( $value ) ? '' : __( 'must be a string.', 'df-editor' );
			case 'button':
				if ( ! is_array( $value ) ) {
					return __( 'must be an object with "text" and "url".', 'df-editor' );
				}
				foreach ( array( 'text', 'url' ) as $key ) {
					if ( isset( $value[ $key ] ) && ! is_string( $value[ $key ] ) ) {
						/* translators: %s: button key */
						return sprintf( __( '"%s" must be a string.', 'df-editor' ), $key );
					}
				}
				return '';
			case 'image':
				return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ? '' : __( 'must be an attachment ID (number).', 'df-editor' );
		}
		return '';
	}
}
