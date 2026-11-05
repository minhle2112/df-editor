<?php
/**
 * WP-CLI: wp df validate | import | export | fallback | orphans | sections | docs | preview-token | to-blocks | to-layout
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage DF Editor page layouts.
 */
class DF_CLI {

	/**
	 * Validates the layout of one page or of all DF pages.
	 *
	 * ## OPTIONS
	 *
	 * [<page>]
	 * : Page ID, slug or page key.
	 *
	 * [--all]
	 * : Validate every page that has a DF layout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df validate startseite
	 *     wp df validate --all
	 */
	public function validate( array $args, array $assoc ): void {
		if ( ! empty( $assoc['all'] ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => DF_Layout_Store::META_LAYOUT, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		} elseif ( isset( $args[0] ) ) {
			$ids = array( $this->resolve( $args[0] ) );
		} else {
			WP_CLI::error( 'Pass a page or --all.' );
		}

		if ( ! $ids ) {
			WP_CLI::warning( 'No pages with a DF layout found.' );
			return;
		}

		$failed = 0;
		foreach ( $ids as $id ) {
			$layout = DF_Layout_Store::get( (int) $id );
			$label  = sprintf( '#%d %s', $id, get_the_title( $id ) );
			if ( null === $layout ) {
				WP_CLI::warning( "$label: no DF layout." );
				continue;
			}
			$result = DF_Validator::validate( $layout );
			foreach ( $result['warnings'] as $w ) {
				WP_CLI::warning( "$label: $w" );
			}
			foreach ( $result['errors'] as $e ) {
				WP_CLI::log( WP_CLI::colorize( "%RError:%n $label: $e" ) );
			}
			if ( $result['errors'] ) {
				++$failed;
			} else {
				WP_CLI::log( WP_CLI::colorize( "%GOK:%n $label (page_key {$layout['page_key']}, " . count( $layout['sections'] ) . ' sections)' ) );
			}
		}

		if ( $failed ) {
			WP_CLI::error( "$failed page(s) with errors." );
		}
		WP_CLI::success( 'All layouts valid.' );
	}

	/**
	 * Imports a layout JSON file into a page.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the layout JSON file.
	 *
	 * [--page=<page>]
	 * : Target page (ID, slug or page key). Default: the page that owns the file's page_key.
	 *
	 * [--create]
	 * : Create a new published page when no target page exists.
	 *
	 * [--title=<title>]
	 * : Title for --create.
	 *
	 * [--front]
	 * : Set the page as static front page.
	 *
	 * [--force]
	 * : Import even when validation reports errors.
	 *
	 * [--overwrite-content]
	 * : Replace existing post_content with the plain-text fallback even when it was not generated
	 * by DF Editor. Never applies to layouts with a "seiteninhalt" section.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df import home.json --create --title=Startseite --front
	 */
	public function import( array $args, array $assoc ): void {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		$layout = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $layout ) ) {
			WP_CLI::error( 'Invalid JSON: ' . json_last_error_msg() );
		}

		$result = DF_Validator::validate( $layout );
		foreach ( $result['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $e ) {
				WP_CLI::log( WP_CLI::colorize( "%RError:%n $e" ) );
			}
			if ( empty( $assoc['force'] ) ) {
				WP_CLI::error( 'Layout has errors (use --force to import anyway).' );
			}
		}

		$post_id = 0;
		if ( ! empty( $assoc['page'] ) ) {
			$post_id = $this->resolve( (string) $assoc['page'], ! empty( $assoc['create'] ) );
		} elseif ( ! empty( $layout['page_key'] ) ) {
			$post_id = DF_Layout_Store::find_by_key( (string) $layout['page_key'] );
		}

		if ( ! $post_id ) {
			if ( empty( $assoc['create'] ) ) {
				WP_CLI::error( 'No target page found. Use --page=<id|slug> or --create.' );
			}
			$title   = (string) ( $assoc['title'] ?? ucfirst( (string) ( $layout['page_key'] ?? 'Neue Seite' ) ) );
			$postarr = array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			);
			if ( ! empty( $assoc['page'] ) && ! is_numeric( $assoc['page'] ) ) {
				$postarr['post_name'] = sanitize_title( (string) $assoc['page'] );
			}
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( $post_id );
			}
			WP_CLI::log( "Created page #$post_id \"$title\"." );
		}

		$existing_key = (string) get_post_meta( (int) $post_id, DF_Layout_Store::META_KEY, true );
		$file_key     = (string) ( $layout['page_key'] ?? '' );
		if ( '' !== $existing_key && '' !== $file_key && $existing_key !== $file_key ) {
			WP_CLI::warning( "Page #$post_id keeps page_key \"$existing_key\"; the layout says \"$file_key\". Field IDs will use \"$existing_key\"." );
		}

		// Publish path: previous live layout into the history, rev + 1, DF Panel draft discarded.
		$published = DF_Layout_Store::publish(
			(int) $post_id,
			$layout,
			array(
				'overwrite_content' => ! empty( $assoc['overwrite-content'] ),
				'user_id'           => get_current_user_id(),
			)
		);
		if ( ! $published['ok'] ) {
			WP_CLI::error( 'Could not save the layout.' );
		}
		if ( $published['had_draft'] ) {
			WP_CLI::warning( 'An unpublished DF Panel draft of this page was discarded.' );
		}

		switch ( $published['fallback'] ) {
			case 'written':
				WP_CLI::log( 'Plain-text fallback written to post_content.' );
				break;
			case 'skipped-content':
				WP_CLI::warning( 'post_content has manual content; fallback not written (use --overwrite-content).' );
				break;
			case 'error':
				WP_CLI::warning( 'Could not write the plain-text fallback.' );
				break;
		}

		if ( ! empty( $assoc['front'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $post_id );
			WP_CLI::log( "Page #$post_id is now the front page." );
		}

		WP_CLI::success( sprintf( 'Layout imported into page #%d (page_key %s, rev %d).', $post_id, DF_Layout_Store::page_key( (int) $post_id ), $published['rev'] ) );
	}

	/**
	 * Creates a token for single-section previews without a login (valid 1 hour).
	 *
	 * Use it for screenshots of the section library: the front end URL
	 * ?df_section_preview=<type>&variant=<v>&scheme=<s>&token=<token> renders only that section
	 * with its defaults. Save screenshots as sections/<type>/preview.png (default variant).
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Print the preview URL of this section type too.
	 *
	 * [--porcelain]
	 * : Print only the token.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df preview-token
	 *     wp df preview-token --type=hero
	 *
	 * @subcommand preview-token
	 */
	public function preview_token( array $args, array $assoc ): void {
		$token = DF_Renderer::create_preview_token();
		if ( ! empty( $assoc['porcelain'] ) ) {
			WP_CLI::line( $token );
			return;
		}
		$types = ! empty( $assoc['type'] ) ? array( (string) $assoc['type'] ) : array_keys( DF_Registry::all() );
		WP_CLI::log( 'Token (valid 1 hour): ' . $token );
		foreach ( $types as $type ) {
			if ( ! DF_Registry::exists( $type ) ) {
				WP_CLI::warning( "Unknown section type: $type" );
				continue;
			}
			WP_CLI::log(
				add_query_arg(
					array(
						'df_section_preview' => $type,
						'token'              => $token,
					),
					home_url( '/' )
				)
			);
		}
	}

	/**
	 * Regenerates the plain-text fallback (post_content) from the current layout and texts.
	 *
	 * The fallback is written on import and on layout metabox save; texts edited later through
	 * DF Inhalt are only picked up by this command. Run it before deploys.
	 *
	 * ## OPTIONS
	 *
	 * [<page>]
	 * : Page ID, slug or page key.
	 *
	 * [--all]
	 * : Every page that has a DF layout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df fallback --all
	 */
	public function fallback( array $args, array $assoc ): void {
		if ( ! empty( $assoc['all'] ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => DF_Layout_Store::META_LAYOUT, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		} elseif ( isset( $args[0] ) ) {
			$ids = array( $this->resolve( $args[0] ) );
		} else {
			WP_CLI::error( 'Pass a page or --all.' );
		}
		foreach ( $ids as $id ) {
			WP_CLI::log( sprintf( '#%d %s: %s', $id, get_the_title( $id ), DF_Fallback::write( (int) $id ) ) );
		}
		WP_CLI::success( sprintf( '%d page(s) processed.', count( $ids ) ) );
	}

	/**
	 * Prints the layout JSON of a page.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Page ID, slug or page key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df export startseite > home.json
	 */
	public function export( array $args ): void {
		$post_id = $this->resolve( $args[0] );
		$layout  = DF_Layout_Store::get( $post_id );
		if ( null === $layout ) {
			WP_CLI::error( "Page #$post_id has no DF layout." );
		}
		WP_CLI::line( (string) wp_json_encode( $layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Lists (and optionally deletes) content keys whose page or section no longer exists.
	 *
	 * Checks df_content_values and df_content_registry. A key {page_key}.{instance_id}.* is live
	 * when some layout (any post type and status, trash included) has that page key and instance.
	 * Keys under global.* are never touched.
	 *
	 * ## OPTIONS
	 *
	 * [--purge]
	 * : Delete the orphaned keys (asks for confirmation).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * [--format=<format>]
	 * : table, json, csv or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp df orphans
	 *     wp df orphans --purge --yes
	 */
	public function orphans( array $args, array $assoc ): void {
		$live      = $this->live_prefixes();
		$protected = (array) apply_filters( 'df_editor_orphan_protected_prefixes', array( 'global.' ) );

		$values   = get_option( DF_Content::VALUES_OPTION, array() );
		$registry = get_option( 'df_content_registry', array() );
		$values   = is_array( $values ) ? $values : array();
		$registry = is_array( $registry ) ? $registry : array();

		$rows = array();
		foreach ( array_unique( array_merge( array_keys( $values ), array_keys( $registry ) ) ) as $key ) {
			$key = (string) $key;
			foreach ( $protected as $prefix ) {
				if ( 0 === strpos( $key, (string) $prefix ) ) {
					continue 2;
				}
			}
			$parts = explode( '.', $key );
			$pair  = count( $parts ) >= 3 ? $parts[0] . '.' . $parts[1] : '';
			if ( '' !== $pair && isset( $live[ $pair ] ) ) {
				continue;
			}
			$rows[] = array(
				'key'      => $key,
				'reason'   => '' === $pair ? 'unknown format' : ( isset( $live[ '#' . $parts[0] ] ) ? 'section removed' : 'page key unknown' ),
				'value'    => array_key_exists( $key, $values ) ? 'yes' : 'no',
				'registry' => array_key_exists( $key, $registry ) ? 'yes' : 'no',
			);
		}

		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( ! $rows ) {
			if ( 'json' === $format ) {
				WP_CLI::line( '[]' );
				return;
			}
			WP_CLI::success( 'No orphaned content keys.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'reason', 'value', 'registry' ) );

		if ( empty( $assoc['purge'] ) ) {
			if ( 'table' === $format ) {
				WP_CLI::log( sprintf( '%d orphaned key(s). Run with --purge to delete them.', count( $rows ) ) );
			}
			return;
		}

		WP_CLI::confirm( sprintf( 'Delete %d orphaned key(s) from df_content_values / df_content_registry?', count( $rows ) ), $assoc );
		foreach ( $rows as $row ) {
			unset( $values[ $row['key'] ], $registry[ $row['key'] ] );
		}
		update_option( DF_Content::VALUES_OPTION, $values, false );
		update_option( 'df_content_registry', $registry, false );
		WP_CLI::success( sprintf( 'Deleted %d orphaned key(s).', count( $rows ) ) );
	}

	/**
	 * Lists the registered sections.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json (json prints the full definitions).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp df sections
	 *     wp df sections --format=json
	 */
	public function sections( array $args, array $assoc ): void {
		$all    = DF_Registry::all();
		$format = (string) ( $assoc['format'] ?? 'table' );
		ksort( $all );

		if ( 'json' === $format ) {
			$out = array();
			foreach ( $all as $type => $def ) {
				unset( $def['dir'], $def['url'] );
				$out[ $type ] = $def;
			}
			WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = array();
		foreach ( $all as $type => $def ) {
			$list   = $def['list'] ?? null;
			$rows[] = array(
				'type'     => $type,
				'label'    => (string) $def['label'],
				'v'        => (int) $def['version'],
				'source'   => (string) $def['source'],
				'variants' => implode( ', ', array_keys( (array) $def['variants'] ) ),
				'options'  => implode( ', ', array_keys( (array) $def['options'] ) ),
				'fields'   => implode( ', ', array_keys( (array) $def['fields'] ) ),
				'list'     => is_array( $list ) ? sprintf( '%s[%d-%d]: %s', $list['key'], $list['min'], $list['max'], implode( ', ', array_keys( (array) $list['fields'] ) ) ) : '',
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'type', 'label', 'v', 'source', 'variants', 'options', 'fields', 'list' ) );
	}

	/**
	 * Generates docs/SECTIONS.md from all section.json files of the plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<file>]
	 * : Target file. Default: df-editor/docs/SECTIONS.md
	 *
	 * [--include-theme]
	 * : Also document sections registered by the active (child) theme.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df docs
	 */
	public function docs( array $args, array $assoc ): void {
		$file = (string) ( $assoc['output'] ?? DF_EDITOR_DIR . 'docs/SECTIONS.md' );
		$all  = DF_Registry::all();
		ksort( $all );
		if ( empty( $assoc['include-theme'] ) ) {
			$all = array_filter( $all, static fn( $def ) => 'plugin' === ( $def['source'] ?? '' ) );
		}

		$esc = static function ( $text ): string {
			$text = is_array( $text ) ? (string) wp_json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : (string) $text;
			$text = str_replace( array( "\r", "\n" ), ' ', $text );
			return str_replace( '|', '\|', $text );
		};
		$field_rows = static function ( array $fields, string $prefix ) use ( $esc ): array {
			$lines = array( '| Field ID | Type | Label | Default | Extra |', '|---|---|---|---|---|' );
			foreach ( $fields as $name => $f ) {
				$extra = array();
				if ( isset( $f['max_length'] ) ) {
					$extra[] = 'max ' . (int) $f['max_length'];
				}
				if ( ! empty( $f['aspect_ratio'] ) ) {
					$extra[] = 'ratio ' . $f['aspect_ratio'];
				}
				$default = $f['default'] ?? ( 'image' === ( $f['type'] ?? '' ) ? '(placeholder)' : '' );
				$lines[] = sprintf( '| `%s%s` | %s | %s | %s | %s |', $prefix, $name, $esc( $f['type'] ?? 'text' ), $esc( $f['label'] ?? '' ), '' === $default ? '' : $esc( $default ), implode( ', ', $extra ) );
			}
			return $lines;
		};

		$md   = array();
		$md[] = '# DF Editor sections';
		$md[] = '';
		$md[] = '<!-- Generated by `wp df docs`. Do not edit by hand: change section.json and re-run. -->';
		$md[] = '';
		$md[] = 'Field IDs on a page: `{page_key}.{instance_id}.{field}`, list items `{page_key}.{instance_id}.{list}.{n}.{field}` (n from 1).';
		$md[] = 'Common layout options for every section: `scheme`, `spacing`, `width`, `hidden`, `anchor` (see docs/CONVENTIONS.md in df-blocks).';
		$md[] = '';
		$md[] = '| Type | Label | Version | Variants | List |';
		$md[] = '|---|---|---|---|---|';
		foreach ( $all as $type => $def ) {
			$md[] = sprintf( '| [`%1$s`](#%1$s) | %2$s | %3$d | %4$s | %5$s |', $type, $esc( $def['label'] ), (int) $def['version'], $esc( implode( ', ', array_keys( (array) $def['variants'] ) ) ), empty( $def['list'] ) ? '' : 'yes' );
		}

		foreach ( $all as $type => $def ) {
			$md[] = '';
			$md[] = '## ' . $type;
			$md[] = '';
			$md[] = sprintf( '**%s** · version %d · source `%s`', $esc( $def['label'] ), (int) $def['version'], $esc( $def['source'] ) );
			$md[] = '';

			$md[] = '### Variants';
			$md[] = '';
			if ( $def['variants'] ) {
				$md[] = '| Value | Label |';
				$md[] = '|---|---|';
				$first = true;
				foreach ( (array) $def['variants'] as $value => $label ) {
					$md[]  = sprintf( '| `%s`%s | %s |', $value, $first ? ' (default)' : '', $esc( $label ) );
					$first = false;
				}
			} else {
				$md[] = '_None._';
			}
			$md[] = '';

			$md[] = '### Options';
			$md[] = '';
			if ( $def['options'] || ! empty( $def['list'] ) ) {
				$md[] = '| Option | Label | Values | Default | Variants |';
				$md[] = '|---|---|---|---|---|';
				foreach ( (array) $def['options'] as $key => $opt ) {
					$values = array_map( static fn( $v ) => '`' . $v . '`', array_map( 'strval', (array) ( $opt['values'] ?? array() ) ) );
					$md[]   = sprintf( '| `%s` | %s | %s | `%s` | %s |', $key, $esc( $opt['label'] ?? '' ), implode( ', ', $values ), $esc( $opt['default'] ?? '' ), $esc( implode( ', ', (array) ( $opt['variants'] ?? array() ) ) ) );
				}
				if ( ! empty( $def['list'] ) ) {
					$l    = $def['list'];
					$md[] = sprintf( '| `count` | Number of items | %d–%d | `%d` | |', $l['min'], $l['max'], $l['default_count'] );
				}
			} else {
				$md[] = '_None._';
			}
			$md[] = '';

			$md[] = '### Fields';
			$md[] = '';
			$md   = array_merge( $md, $def['fields'] ? $field_rows( (array) $def['fields'], '' ) : array( '_None._' ) );

			if ( ! empty( $def['list'] ) ) {
				$l    = $def['list'];
				$md[] = '';
				$md[] = sprintf( '### List `%s` (%d–%d items, default %d)', $l['key'], $l['min'], $l['max'], $l['default_count'] );
				$md[] = '';
				$md   = array_merge( $md, $field_rows( (array) $l['fields'], $l['key'] . '.{n}.' ) );
			}
		}
		$md[] = '';

		if ( ! is_dir( dirname( $file ) ) && ! wp_mkdir_p( dirname( $file ) ) ) {
			WP_CLI::error( 'Cannot create ' . dirname( $file ) );
		}
		if ( false === file_put_contents( $file, implode( "\n", $md ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error( "Cannot write $file" );
		}
		WP_CLI::success( sprintf( 'Documented %d section(s) in %s', count( $all ), $file ) );
	}

	/**
	 * Converts the JSON layout of a page into DF blocks (prototype, see docs/BLOCKS.md).
	 *
	 * Every section becomes a df/<type> block in post_content with the same instance ID, so all
	 * field IDs ({page_key}.{id}.*) and saved DF Inhalt texts stay the same. _df_layout is moved to
	 * _df_layout_converted (backup), _df_page_key is kept.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Page ID, slug or page key.
	 *
	 * [--dry-run]
	 * : Print the block markup, change nothing.
	 *
	 * [--allow-partial]
	 * : Skip sections that are not available as blocks (with a warning) instead of failing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df to-blocks home --dry-run
	 *     wp df to-blocks 42
	 *
	 * @subcommand to-blocks
	 */
	public function to_blocks( array $args, array $assoc ): void {
		$post_id = $this->resolve_block_post( $args[0] );
		$layout  = DF_Layout_Store::get( $post_id );
		if ( null === $layout ) {
			WP_CLI::error( "Page #$post_id has no DF layout." );
		}
		$dry     = ! empty( $assoc['dry-run'] );
		$partial = ! empty( $assoc['allow-partial'] );
		$current = (string) get_post_field( 'post_content', $post_id );
		if ( ! DF_Fallback::can_replace( $current ) ) {
			WP_CLI::error( "Page #$post_id has manual post_content (e.g. for a seiteninhalt section); it would be overwritten. Not converted." );
		}

		$blocks  = array();
		$skipped = array();
		foreach ( array_values( $layout['sections'] ) as $i => $inst ) {
			$type = is_array( $inst ) ? (string) ( $inst['section'] ?? '' ) : '';
			$id   = is_array( $inst ) ? (string) ( $inst['id'] ?? '' ) : '';
			$def  = DF_Registry::get( $type );
			$why  = '';
			if ( null === $def ) {
				$why = 'unknown section';
			} elseif ( ! DF_Blocks::is_enabled( $type ) ) {
				$why = 'not enabled as a block (filter df_editor_block_sections)';
			} elseif ( ! preg_match( DF_Layout_Store::INSTANCE_REGEX, $id ) ) {
				$why = 'invalid instance id';
			}
			if ( '' !== $why ) {
				$skipped[] = sprintf( '#%d %s (%s): %s', $i + 1, $type, $id, $why );
				continue;
			}
			if ( ! empty( $inst['locked'] ) ) {
				WP_CLI::warning( "Section $id: the DF Panel lock is not kept (blocks have no lock)." );
			}
			$blocks[] = array(
				'blockName'    => DF_Blocks::block_name( $type ),
				'attrs'        => DF_Blocks::attributes_from_entry( $inst, $def ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}

		if ( $skipped ) {
			if ( ! $partial ) {
				foreach ( $skipped as $line ) {
					WP_CLI::log( WP_CLI::colorize( "%RError:%n $line" ) );
				}
				WP_CLI::error( 'Some sections cannot be converted (use --allow-partial to skip them).' );
			}
			foreach ( $skipped as $line ) {
				WP_CLI::warning( "Skipped $line" );
			}
		}
		if ( ! $blocks ) {
			WP_CLI::error( 'Nothing to convert.' );
		}

		$markup = implode( "\n\n", array_map( 'serialize_block', $blocks ) );
		if ( $dry ) {
			WP_CLI::line( $markup );
			WP_CLI::success( sprintf( 'Dry run: %d block(s), %d section(s) skipped. Nothing changed.', count( $blocks ), count( $skipped ) ) );
			return;
		}

		$raw    = (string) get_post_meta( $post_id, DF_Layout_Store::META_LAYOUT, true );
		$result = $this->update_content( $post_id, $markup );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		update_post_meta( $post_id, DF_Blocks::META_LAYOUT_CONVERTED, wp_slash( $raw ) );
		delete_post_meta( $post_id, DF_Blocks::META_BLOCKS_CONVERTED );
		delete_post_meta( $post_id, DF_Layout_Store::META_LAYOUT );
		delete_post_meta( $post_id, DF_Layout_Store::META_SAVED );
		if ( DF_Layout_Store::has_draft( $post_id ) ) {
			WP_CLI::warning( 'The DF Panel draft of this page was not converted (kept in _df_layout_draft).' );
		}
		DF_Layout_Store::purge_caches( $post_id );
		WP_CLI::success( sprintf( 'Page #%d converted to %d DF block(s) (page_key %s, backup in %s).', $post_id, count( $blocks ), DF_Layout_Store::page_key( $post_id ), DF_Blocks::META_LAYOUT_CONVERTED ) );
	}

	/**
	 * Converts the DF blocks of a page back into a JSON layout (prototype, see docs/BLOCKS.md).
	 *
	 * Every df/<type> block becomes a layout section with the same instance ID; the layout is
	 * validated and stored through the publish path (history, rev, plain-text fallback in
	 * post_content). The block markup is kept in _df_blocks_converted.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Page ID, slug or page key.
	 *
	 * [--dry-run]
	 * : Print the layout JSON, change nothing.
	 *
	 * [--allow-partial]
	 * : Drop other (non-DF) blocks with a warning instead of failing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp df to-layout 42 --dry-run
	 *
	 * @subcommand to-layout
	 */
	public function to_layout( array $args, array $assoc ): void {
		$post_id = $this->resolve_block_post( $args[0] );
		if ( DF_Layout_Store::has_layout( $post_id ) ) {
			WP_CLI::error( "Page #$post_id already has a DF layout." );
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$dry     = ! empty( $assoc['dry-run'] );

		$sections = array();
		$others   = array();
		$this->collect_sections( parse_blocks( $content ), $sections, $others );
		if ( $others ) {
			if ( empty( $assoc['allow-partial'] ) ) {
				WP_CLI::error( 'The page contains other blocks (' . implode( ', ', array_unique( $others ) ) . '); they cannot be part of a layout. Use --allow-partial to drop them.' );
			}
			WP_CLI::warning( 'Dropped other blocks: ' . implode( ', ', array_unique( $others ) ) );
		}
		if ( ! $sections ) {
			WP_CLI::error( "Page #$post_id has no DF blocks." );
		}

		// Read-only until the dry-run branch: a stored key, else the key that would be assigned
		// (DF_Layout_Store::page_key() would store one, fixing the field-ID namespace).
		$key    = (string) get_post_meta( $post_id, DF_Layout_Store::META_KEY, true );
		$is_new = '' === $key;
		if ( $is_new ) {
			$key = DF_Blocks::wanted_key( $post_id );
		}
		$layout = array(
			'format'   => DF_LAYOUT_FORMAT,
			'page_key' => $key,
			'sections' => $sections,
		);
		$result = DF_Validator::validate( $layout );
		foreach ( $result['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $e ) {
				WP_CLI::log( WP_CLI::colorize( "%RError:%n $e" ) );
			}
			WP_CLI::error( 'The converted layout has errors. Nothing changed.' );
		}

		if ( $dry ) {
			WP_CLI::line( (string) wp_json_encode( $layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			if ( $is_new ) {
				WP_CLI::log( "The page has no page key yet; \"$key\" (or a unique variant) would be assigned." );
			}
			WP_CLI::success( sprintf( 'Dry run: %d section(s). Nothing changed.', count( $sections ) ) );
			return;
		}

		if ( $is_new ) {
			$key                = DF_Layout_Store::assign_key( $post_id, $key );
			$layout['page_key'] = $key;
		}

		update_post_meta( $post_id, DF_Blocks::META_BLOCKS_CONVERTED, wp_slash( $content ) );
		// Publish path: rev, history, plain-text fallback (replaces the block markup in post_content).
		$published = DF_Layout_Store::publish(
			$post_id,
			$layout,
			array(
				'overwrite_content' => true,
				'user_id'           => get_current_user_id(),
			)
		);
		if ( ! $published['ok'] ) {
			WP_CLI::error( 'Could not save the layout.' );
		}
		delete_post_meta( $post_id, DF_Blocks::META_LAYOUT_CONVERTED );
		WP_CLI::success( sprintf( 'Page #%d converted to a DF layout with %d section(s) (page_key %s, rev %d, fallback %s).', $post_id, count( $sections ), $key, $published['rev'], $published['fallback'] ) );
	}

	/**
	 * Layout entries of the df/* blocks in a block tree; names of other non-empty blocks in $others.
	 */
	private function collect_sections( array $blocks, array &$sections, array &$others ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			$type = DF_Blocks::type_of( $name );
			if ( '' !== $type ) {
				if ( ! DF_Registry::exists( $type ) ) {
					$others[] = (string) $name;
					continue;
				}
				$entry = DF_Blocks::entry_from_attributes( $type, (array) ( $block['attrs'] ?? array() ) );
				if ( array() === $entry['content'] ) {
					unset( $entry['content'] );
				}
				$sections[] = $entry;
				continue;
			}
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$others[] = 'freeform';
				}
				continue;
			}
			$others[] = (string) $name;
		}
	}

	/**
	 * Writes post_content without kses (CLI runs without a user; block attributes must stay intact).
	 *
	 * @return int|WP_Error
	 */
	private function update_content( int $post_id, string $content ) {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		return $result;
	}

	/**
	 * Live "{page_key}.{instance_id}" pairs (plus "#{page_key}" markers) from every stored layout.
	 */
	private function live_prefixes(): array {
		global $wpdb;
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type <> 'revision'",
				DF_Layout_Store::META_LAYOUT
			)
		);
		$live = array();
		foreach ( $post_ids as $post_id ) {
			$layout = DF_Layout_Store::get( (int) $post_id );
			if ( null === $layout ) {
				continue;
			}
			$key                = (string) $layout['page_key'];
			$live[ '#' . $key ] = true;
			foreach ( $layout['sections'] as $inst ) {
				if ( is_array( $inst ) && ! empty( $inst['id'] ) ) {
					$live[ $key . '.' . $inst['id'] ] = true;
				}
			}
		}
		// DF block pages (df/* blocks in post_content) are live too.
		if ( class_exists( 'DF_Blocks' ) ) {
			$block_posts = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s",
					'%' . $wpdb->esc_like( '<!-- wp:' . DF_Blocks::NS . '/' ) . '%'
				)
			);
			foreach ( $block_posts as $block_post_id ) {
				$key = (string) get_post_meta( (int) $block_post_id, DF_Layout_Store::META_KEY, true );
				if ( '' === $key ) {
					continue;
				}
				$live[ '#' . $key ] = true;
				$tree               = parse_blocks( (string) get_post_field( 'post_content', (int) $block_post_id ) );
				foreach ( DF_Blocks::find_blocks( $tree, true ) as $df_block ) {
					$id = (string) ( $df_block['attrs']['dfId'] ?? '' );
					if ( '' !== $id ) {
						$live[ $key . '.' . $id ] = true;
					}
				}
			}
		}
		// Page keys without a layout still count as known pages (reason text only).
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", DF_Layout_Store::META_KEY ) ) as $key ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$live[ '#' . $key ] = true;
		}
		/**
		 * Filters the live "{page_key}.{instance_id}" pairs used by `wp df orphans`
		 * (add instances rendered by theme templates via df_section() on a page).
		 *
		 * @param array<string,bool> $live Pairs as keys.
		 */
		return (array) apply_filters( 'df_editor_live_instances', $live );
	}

	/**
	 * Resolves the target of to-blocks / to-layout: only post types that render DF blocks
	 * (DF_Blocks::post_types(), default page).
	 */
	private function resolve_block_post( string $ref ): int {
		$post_id = $this->resolve( $ref );
		if ( ! DF_Blocks::supports_post( $post_id ) ) {
			WP_CLI::error( sprintf( 'Post #%d is a "%s", not a page (DF blocks are only rendered for: %s).', $post_id, (string) get_post_type( $post_id ), implode( ', ', DF_Blocks::post_types() ) ) );
		}
		return $post_id;
	}

	/**
	 * Resolves a page by ID, slug/path or page key.
	 */
	private function resolve( string $ref, bool $allow_missing = false ): int {
		if ( is_numeric( $ref ) ) {
			$post = get_post( (int) $ref );
			if ( $post ) {
				return $post->ID;
			}
		} else {
			$post = get_page_by_path( $ref, OBJECT, 'page' );
			if ( $post ) {
				return $post->ID;
			}
			$id = DF_Layout_Store::find_by_key( $ref );
			if ( $id ) {
				return $id;
			}
		}
		if ( $allow_missing ) {
			return 0;
		}
		WP_CLI::error( "Page not found: $ref" );
	}
}
