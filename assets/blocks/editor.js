/**
 * DF Editor – block editor UI for the DF section blocks (df/<type>).
 *
 * The blocks are registered server-side (PHP is the source of truth for
 * name, title, category, attributes, supports). This script only adds the
 * editor UI: edit (server-side preview + sidebar), save (null, dynamic block)
 * and the inserter example. Plain ES2020 on window.wp.* globals, no build step.
 *
 * Expects window.dfBlocksData (localized by PHP):
 *   { sections: { <type>: { label, description, variants:[{value,label}],
 *       options:[{key,label,values:[{value,label}],default,variants|null}],
 *       list:{min,max,default_count}|null, preview_url|null, version,
 *       defaults:{v,variant,scheme,spacingTop,spacingBottom,width,options} } },
 *     schemes:[{value,label,swatch}], spacing:[{value,label}],
 *     widths:[{value,label}], postId }
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}
	data = data || {};

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const useEffect = wp.element.useEffect;
	const useRef = wp.element.useRef;
	const useCallback = wp.element.useCallback;
	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;

	const be = wp.blockEditor;
	const c = wp.components;
	const useSelect = wp.data.useSelect;

	const SSR = wp.serverSideRender ? ( wp.serverSideRender.default || wp.serverSideRender ) : null;

	const SECTIONS = data.sections || {};
	const SCHEMES = Array.isArray( data.schemes ) ? data.schemes : [];
	const SPACING = Array.isArray( data.spacing ) ? data.spacing : [];
	const WIDTHS = Array.isArray( data.widths ) ? data.widths : [];

	const ID_PATTERN = /^[a-z][a-z0-9-]{1,40}$/;
	const ID_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/** Ids handed out in this editor session but maybe not yet in the store (parallel mounts). */
	const reserved = new Set();

	/**
	 * dfId → clientId of the block that owns it (first mounted). On load blocks mount in document
	 * order, so saved pages keep their ids; a paste, pattern insert or "Duplicate" mounts later
	 * and is always the newcomer, wherever it lands.
	 */
	const owners = new Map();

	/** Attributes the editor writes explicitly into new blocks (they have no registered default). */
	const EXPLICIT = [ 'v', 'variant', 'scheme', 'spacingTop', 'spacingBottom', 'width', 'options' ];

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function typeOf( name ) {
		return String( name || '' ).replace( /^df\//, '' );
	}

	function labelOf( list, value ) {
		const hit = ( list || [] ).find( ( i ) => i && String( i.value ) === String( value ) );
		return hit ? String( hit.label ) : String( value || '' );
	}

	function toSelectOptions( list ) {
		return ( list || [] ).map( ( i ) => ( { value: String( i.value ), label: String( i.label ) } ) );
	}

	/** Flat list of all blocks in document order (inner blocks included). */
	function flatBlocks( blocks, out ) {
		out = out || [];
		( blocks || [] ).forEach( ( b ) => {
			out.push( b );
			if ( b.innerBlocks && b.innerBlocks.length ) {
				flatBlocks( b.innerBlocks, out );
			}
		} );
		return out;
	}

	function usedIds( exceptClientId ) {
		const ids = new Set( reserved );
		const all = flatBlocks( wp.data.select( 'core/block-editor' ).getBlocks() );
		all.forEach( ( b ) => {
			if ( b.clientId !== exceptClientId && /^df\//.test( b.name ) && b.attributes && b.attributes.dfId ) {
				ids.add( String( b.attributes.dfId ) );
			}
		} );
		return ids;
	}

	function newId( type, taken ) {
		const base = ( String( type ).toLowerCase().replace( /[^a-z0-9-]/g, '' ) || 'section' ).replace( /^[^a-z]+/, '' ) || 'section';
		for ( let tries = 0; tries < 200; tries++ ) {
			let rnd = '';
			for ( let i = 0; i < 4; i++ ) {
				rnd += ID_CHARS.charAt( Math.floor( Math.random() * ID_CHARS.length ) );
			}
			const id = ( base.slice( 0, 35 ) + '-' + rnd );
			if ( ! taken.has( id ) ) {
				return id;
			}
		}
		return base.slice( 0, 30 ) + '-' + Date.now().toString( 36 ).slice( -6 );
	}

	/** Change that joins the previous undo level (automatic id/defaults, not a user action). */
	function markNotPersistent( registry ) {
		const d = registry.dispatch( 'core/block-editor' );
		if ( d && typeof d.__unstableMarkNextChangeAsNotPersistent === 'function' ) {
			d.__unstableMarkNextChangeAsNotPersistent();
		}
	}

	/** Anchor → slug (lowercase, a-z0-9, "-" and "_"); trailing "-" kept while typing. */
	function cleanAnchor( value ) {
		return String( value || '' )
			.toLowerCase()
			.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
			.replace( /ß/g, 'ss' )
			.replace( /[^a-z0-9_-]+/g, '-' )
			.replace( /-{2,}/g, '-' )
			.replace( /^-+/, '' )
			.slice( 0, 80 );
	}

	function swatchColors( scheme ) {
		const s = scheme && scheme.swatch;
		if ( s && typeof s === 'object' ) {
			return { bg: String( s.bg || s.background || '#ffffff' ), fg: String( s.fg || s.color || s.text || '#111111' ) };
		}
		if ( typeof s === 'string' && s ) {
			return { bg: s, fg: '' };
		}
		return { bg: '#ffffff', fg: '#111111' };
	}

	function withInhalt( link ) {
		if ( ! link ) {
			return '';
		}
		const hashAt = link.indexOf( '#' );
		const base = hashAt >= 0 ? link.slice( 0, hashAt ) : link;
		return base + ( base.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'df_inhalt=open';
	}

	/**
	 * Front-end URL that opens DF Inhalt for the post being edited. Drafts, pending and scheduled
	 * pages are not served at their pretty permalink (404 even for editors), so they use the
	 * preview link (?page_id=X&preview=true), where DF Inhalt runs as well.
	 */
	function textsUrl( ed ) {
		if ( ! ed ) {
			return '';
		}
		const post = ed.getCurrentPost ? ed.getCurrentPost() || {} : {};
		const status = String( post.status || '' );
		if ( status === 'publish' || status === 'private' ) {
			return withInhalt( ed.getPermalink ? ed.getPermalink() : '' );
		}
		if ( ! post.id || status === 'auto-draft' || ( ed.isEditedPostNew && ed.isEditedPostNew() ) ) {
			return '';
		}
		let link = ed.getEditedPostPreviewLink ? ed.getEditedPostPreviewLink() : '';
		if ( ! link ) {
			link = ( data.homeUrl || '/' ).replace( /\/?$/, '/' ) + '?page_id=' + post.id + '&preview=true';
		}
		return withInhalt( link );
	}

	/**
	 * Opens DF Inhalt on the page in a new tab. Unsaved layout changes are saved first (for a
	 * published page only after confirmation, because saving publishes them), so the page shows
	 * the current sections.
	 */
	async function openTexts( registry ) {
		const ed = registry.select( 'core/editor' );
		if ( ! ed ) {
			return;
		}
		const notices = registry.dispatch( 'core/notices' );
		const status = String( ( ed.getCurrentPost() || {} ).status || '' );
		const live = status === 'publish' || status === 'private' || status === 'future';
		const dirty = ed.isEditedPostDirty() || ( ed.isEditedPostNew && ed.isEditedPostNew() );
		if ( dirty && live && ! window.confirm( __( 'The page has unsaved changes. They must be saved (and go live) before you edit the texts. Save now?', 'df-editor' ) ) ) {
			return;
		}
		// Open the tab inside the click (popup blockers), navigate it after saving.
		const win = window.open( '', '_blank' );
		if ( win ) {
			win.opener = null;
		}
		try {
			if ( dirty ) {
				await registry.dispatch( 'core/editor' ).savePost();
				if ( ed.didPostSaveRequestFail && registry.select( 'core/editor' ).didPostSaveRequestFail() ) {
					throw new Error( 'save failed' );
				}
			}
			const url = textsUrl( registry.select( 'core/editor' ) );
			if ( ! url ) {
				throw new Error( 'no url' );
			}
			if ( win ) {
				win.location.href = url;
			} else {
				window.open( url, '_blank', 'noopener' );
			}
		} catch ( e ) {
			if ( win ) {
				win.close();
			}
			notices.createErrorNotice( __( 'Save the page first, then edit its texts on the page.', 'df-editor' ), { type: 'snackbar', id: 'df-edit-texts' } );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Icon                                                                */
	/* ------------------------------------------------------------------ */

	const icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M4 4h16a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v3h14V6H5zM4 13h16a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1zm1 2v3h14v-3H5z' } )
	);

	/* ------------------------------------------------------------------ */
	/* Preview parts                                                       */
	/* ------------------------------------------------------------------ */

	function Loading( props ) {
		return el(
			'div',
			{ className: 'df-block-preview__loading' + ( props && props.children ? ' has-previous' : '' ) },
			props && props.children ? el( 'div', { className: 'df-block-preview__previous', 'aria-hidden': 'true' }, props.children ) : null,
			el( 'div', { className: 'df-block-preview__spinner' }, el( c.Spinner ), el( 'span', { className: 'df-block-sr' }, __( 'Loading preview…', 'df-editor' ) ) )
		);
	}

	function ErrorResponse( props ) {
		const msg = props && props.response && props.response.errorMsg ? String( props.response.errorMsg ) : '';
		return el(
			'div',
			{ className: 'df-block-notice is-error', role: 'alert' },
			el( 'strong', null, __( 'Preview could not be loaded.', 'df-editor' ) ),
			msg ? el( 'span', null, ' ' + msg ) : null
		);
	}

	function EmptyResponse() {
		return el( 'div', { className: 'df-block-notice' }, __( 'This section renders no output with the current settings.', 'df-editor' ) );
	}

	function Placeholder( props ) {
		return el(
			'div',
			{ className: 'df-block-placeholder' + ( props.muted ? ' is-muted' : '' ) },
			el( 'span', { className: 'df-block-placeholder__icon', 'aria-hidden': 'true' }, icon ),
			el(
				'div',
				{ className: 'df-block-placeholder__text' },
				el( 'strong', null, props.title ),
				props.text ? el( 'span', null, props.text ) : null
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sidebar parts                                                       */
	/* ------------------------------------------------------------------ */

	function SchemePicker( props ) {
		const labelId = 'df-block-scheme-' + props.clientId;
		return el(
			c.BaseControl,
			{ __nextHasNoMarginBottom: true, id: labelId + '-ctl', className: 'df-block-scheme', help: null },
			el( 'span', { id: labelId, className: 'df-block-control-label' }, __( 'Colour scheme', 'df-editor' ) ),
			el(
				'div',
				{ className: 'df-block-scheme__list', role: 'group', 'aria-labelledby': labelId },
				SCHEMES.map( ( s ) => {
					const col = swatchColors( s );
					const active = String( props.value ) === String( s.value );
					return el(
						c.Button,
						{
							key: s.value,
							className: 'df-block-scheme__btn' + ( active ? ' is-active' : '' ),
							isPressed: active,
							'aria-pressed': active,
							onClick: () => props.onChange( String( s.value ) ),
						},
						el(
							'span',
							{
								className: 'df-block-scheme__swatch',
								style: { background: col.bg, color: col.fg || 'inherit' },
								'aria-hidden': 'true',
							},
							col.fg ? 'Aa' : ''
						),
						el( 'span', { className: 'df-block-scheme__label' }, String( s.label ) ),
						active ? el( 'span', { className: 'df-block-scheme__check', 'aria-hidden': 'true' }, '✓' ) : null
					);
				} )
			)
		);
	}

	function Select( props ) {
		return el( c.SelectControl, Object.assign( { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true }, props ) );
	}

	/* ------------------------------------------------------------------ */
	/* Edit                                                                */
	/* ------------------------------------------------------------------ */

	function makeEdit( name ) {
		const type = typeOf( name );

		return function DfSectionEdit( props ) {
			const attributes = props.attributes || {};
			const setAttributes = props.setAttributes;
			const clientId = props.clientId;
			const isSelected = !! props.isSelected;
			const def = SECTIONS[ type ] || { label: type, description: '', variants: [], options: [], list: null, preview_url: null };
			const defaults = def.defaults && typeof def.defaults === 'object' ? def.defaults : {};
			const registry = wp.data.useRegistry ? wp.data.useRegistry() : wp.data;
			const variants = Array.isArray( def.variants ) ? def.variants : [];
			const sectionOptions = ( Array.isArray( def.options ) ? def.options : [] ).filter( ( o ) => o && o.key !== 'count' );

			const isPreviewMode = useSelect( ( select ) => {
				const s = select( 'core/block-editor' ).getSettings();
				return !! ( s && s.isPreviewMode );
			}, [] );

			const hasEditor = useSelect( ( select ) => !! select( 'core/editor' ), [] );

			const dfId = String( attributes.dfId || '' );
			// Missing settings (blocks saved before explicit values) fall back like the renderer does.
			const variant = attributes.variant || defaults.variant || ( variants[ 0 ] ? variants[ 0 ].value : '' );
			const scheme = attributes.scheme || defaults.scheme || 'hell';
			const spacingTop = attributes.spacingTop || defaults.spacingTop || 'm';
			const spacingBottom = attributes.spacingBottom || defaults.spacingBottom || 'm';
			const width = attributes.width || defaults.width || 'container';
			const ownOpts = attributes.options && typeof attributes.options === 'object' && ! Array.isArray( attributes.options ) ? attributes.options : {};
			const opts = Object.assign( {}, defaults.options || {}, ownOpts );

			/*
			 * dfId: a new block (empty id) gets an id plus explicit settings; a block whose id is
			 * owned by another, earlier mounted block (paste, pattern, "Duplicate") gets a new id.
			 * Automatic changes join the insert's undo level (no undo trap).
			 */
			useEffect( () => {
				if ( attributes.preview || isPreviewMode ) {
					return;
				}
				let reason = '';
				if ( ! dfId ) {
					reason = 'new';
				} else {
					const owner = owners.get( dfId );
					if ( owner && owner !== clientId ) {
						const ownerBlock = registry.select( 'core/block-editor' ).getBlock( owner );
						if ( ownerBlock && String( ( ownerBlock.attributes || {} ).dfId || '' ) === dfId ) {
							reason = 'copy';
						}
					}
				}
				if ( ! reason ) {
					owners.set( dfId, clientId );
					return;
				}
				const id = newId( type, usedIds( clientId ) );
				reserved.add( id );
				owners.set( id, clientId );
				const patch = { dfId: id };
				if ( reason === 'new' ) {
					EXPLICIT.forEach( ( key ) => {
						if ( attributes[ key ] === undefined && defaults[ key ] !== undefined ) {
							patch[ key ] = key === 'options' ? Object.assign( {}, defaults.options ) : defaults[ key ];
						}
					} );
				}
				markNotPersistent( registry );
				setAttributes( patch );
				if ( reason === 'copy' ) {
					registry.dispatch( 'core/notices' ).createInfoNotice( __( 'Section copied – it has its own texts now.', 'df-editor' ), { type: 'snackbar', id: 'df-section-copied' } );
				}
			}, [ dfId, clientId, isPreviewMode, attributes.preview ] );

			const onEditTexts = useCallback( () => {
				openTexts( registry );
			}, [ registry ] );

			const setOption = useCallback(
				( key, value ) => {
					const next = Object.assign( {}, opts );
					next[ key ] = value;
					setAttributes( { options: next } );
				},
				[ opts, setAttributes ]
			);

			/* Make the preview inert (no link clicks, no tab stops) while keeping the block selectable. */
			const contentRef = useRef( null );
			useEffect( () => {
				if ( contentRef.current ) {
					contentRef.current.setAttribute( 'inert', '' );
				}
			} );

			const variantLabel = labelOf( variants, variant );
			const sectionLabel = String( def.label || type );

			const blockProps = be.useBlockProps( {
				className: [
					'df-block',
					'df-block--' + type,
					attributes.hidden ? 'is-df-hidden' : '',
					isSelected ? 'is-df-selected' : '',
				].filter( Boolean ).join( ' ' ),
			} );

			/* ---------- inserter example ---------- */
			if ( attributes.preview ) {
				return el(
					'div',
					blockProps,
					def.preview_url
						? el( 'img', { className: 'df-block-example', src: def.preview_url, alt: sprintf( /* translators: %s: section name */ __( 'Preview of the %s section', 'df-editor' ), sectionLabel ) } )
						: el( Placeholder, { title: sectionLabel, text: def.description || '' } )
				);
			}

			/* ---------- canvas ---------- */
			let canvas;
			if ( attributes.hidden ) {
				canvas = el( Placeholder, {
					muted: true,
					title: sprintf( /* translators: %s: section name */ __( '%s (hidden)', 'df-editor' ), sectionLabel ),
					text: __( 'Not shown on the site. Turn off “Hide section” in the sidebar to show it again.', 'df-editor' ),
				} );
			} else if ( ! SSR ) {
				canvas = el( Placeholder, { title: sectionLabel, text: __( 'Preview is not available.', 'df-editor' ) } );
			} else if ( ! dfId ) {
				canvas = el( Loading, null );
			} else {
				canvas = el(
					'div',
					{ className: 'df-block-preview__content', ref: contentRef },
					el( SSR, {
						block: name,
						attributes: attributes,
						// POST: the "content" attribute (initial texts, list items) can be long for a GET URL.
						httpMethod: 'POST',
						urlQueryArgs: { post_id: data.postId || undefined },
						LoadingResponsePlaceholder: Loading,
						ErrorResponsePlaceholder: ErrorResponse,
						EmptyResponsePlaceholder: EmptyResponse,
					} )
				);
			}

			const header = el(
				'div',
				{ className: 'df-block-label', 'aria-hidden': isSelected ? undefined : 'true' },
				el( 'span', { className: 'df-block-label__brand' }, 'DF' ),
				el( 'span', null, ' · ' + sectionLabel + ( variantLabel ? ' · ' + variantLabel : '' ) ),
				attributes.hidden ? el( 'span', { className: 'df-block-label__flag' }, __( 'Hidden', 'df-editor' ) ) : null
			);
			const hint = isSelected && hasEditor
				? el( 'div', { className: 'df-block-hint', 'aria-hidden': 'true' }, __( 'Click “Edit texts” to change texts', 'df-editor' ) )
				: null;

			/* ---------- toolbar ---------- */
			const variantMenu =
				variants.length > 1 && c.ToolbarDropdownMenu
					? el(
						be.BlockControls,
						{ group: 'block' },
						el( c.ToolbarDropdownMenu, {
							icon: 'layout',
							label: sprintf( /* translators: %s: current variant */ __( 'Variant: %s', 'df-editor' ), variantLabel ),
							text: variantLabel,
							controls: variants.map( ( v ) => ( {
								title: String( v.label ),
								isActive: String( v.value ) === String( variant ),
								role: 'menuitemradio',
								onClick: () => setAttributes( { variant: String( v.value ) } ),
							} ) ),
						} )
					)
					: null;
			const textsButton =
				hasEditor && c.ToolbarButton
					? el(
						be.BlockControls,
						{ group: 'block' },
						el( c.ToolbarButton, {
							icon: 'edit',
							label: __( 'Edit texts on the page (opens in a new tab)', 'df-editor' ),
							text: __( 'Edit texts', 'df-editor' ),
							showTooltip: true,
							onClick: onEditTexts,
						} )
					)
					: null;
			const toolbar = el( Fragment, null, variantMenu, textsButton );

			/* ---------- sidebar ---------- */
			const layoutPanel = el(
				c.PanelBody,
				{ title: __( 'Layout', 'df-editor' ), initialOpen: true },
				hasEditor
					? el(
						'div',
						{ className: 'df-block-texts' },
						el( 'p', { className: 'df-block-help' }, __( 'Texts and images are edited directly on the page.', 'df-editor' ) ),
						el(
							c.Button,
							{ variant: 'secondary', icon: 'edit', className: 'df-block-inhalt-link', onClick: onEditTexts },
							__( 'Edit texts', 'df-editor' ),
							el( 'span', { className: 'df-block-sr' }, ' ' + __( '(opens in a new tab)', 'df-editor' ) )
						)
					)
					: null,
				variants.length
					? el( Select, {
						label: __( 'Variant', 'df-editor' ),
						value: String( variant ),
						options: toSelectOptions( variants ),
						onChange: ( v ) => setAttributes( { variant: v } ),
					} )
					: null,
				SCHEMES.length
					? el( SchemePicker, { clientId, value: scheme, onChange: ( v ) => setAttributes( { scheme: v } ) } )
					: null,
				SPACING.length
					? el(
						'div',
						{ className: 'df-block-row' },
						el( Select, {
							label: __( 'Spacing top', 'df-editor' ),
							value: String( spacingTop ),
							options: toSelectOptions( SPACING ),
							onChange: ( v ) => setAttributes( { spacingTop: v } ),
						} ),
						el( Select, {
							label: __( 'Spacing bottom', 'df-editor' ),
							value: String( spacingBottom ),
							options: toSelectOptions( SPACING ),
							onChange: ( v ) => setAttributes( { spacingBottom: v } ),
						} )
					)
					: null,
				WIDTHS.length
					? el( Select, {
						label: __( 'Width', 'df-editor' ),
						value: String( width ),
						options: toSelectOptions( WIDTHS ),
						onChange: ( v ) => setAttributes( { width: v } ),
					} )
					: null,
				def.list
					? el( c.RangeControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Number of items', 'df-editor' ),
						help: sprintf( /* translators: 1: minimum, 2: maximum */ __( 'Between %1$d and %2$d.', 'df-editor' ), Number( def.list.min ), Number( def.list.max ) ),
						min: Number( def.list.min ) || 1,
						max: Number( def.list.max ) || 12,
						step: 1,
						value: Number( opts.count ) || Number( def.list.default_count ) || Number( def.list.min ) || 1,
						onChange: ( n ) => {
							const min = Number( def.list.min ) || 1;
							const max = Number( def.list.max ) || 12;
							const v = Math.max( min, Math.min( max, parseInt( n, 10 ) || Number( def.list.default_count ) || min ) );
							setOption( 'count', v );
						},
					} )
					: null,
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Hide section', 'df-editor' ),
					help: attributes.hidden ? __( 'Not shown on the site.', 'df-editor' ) : __( 'Shown on the site.', 'df-editor' ),
					checked: !! attributes.hidden,
					onChange: ( v ) => setAttributes( { hidden: !! v } ),
				} )
			);

			const applicable = sectionOptions.filter( ( o ) => ! Array.isArray( o.variants ) || o.variants.indexOf( String( variant ) ) >= 0 );
			const optionsPanel = sectionOptions.length
				? el(
					c.PanelBody,
					{ title: __( 'Options', 'df-editor' ), initialOpen: true },
					applicable.length
						? applicable.map( ( o ) =>
							el( Select, {
								key: o.key,
								label: String( o.label ),
								value: String( opts[ o.key ] !== undefined && opts[ o.key ] !== null ? opts[ o.key ] : o.default ),
								options: toSelectOptions( o.values ),
								onChange: ( v ) => setOption( o.key, v ),
							} )
						)
						: el( 'p', { className: 'df-block-help' }, __( 'No options for this variant.', 'df-editor' ) )
				)
				: null;

			// Rendered into the core "Advanced" panel (InspectorControls group "advanced"), so the
			// sidebar does not show two "Advanced" panels.
			const advancedPanel = el(
				Fragment,
				null,
				el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Anchor', 'df-editor' ),
					help: __( 'Optional HTML anchor for links to this section (letters, numbers and hyphens).', 'df-editor' ),
					value: String( attributes.anchor || '' ),
					onChange: ( v ) => setAttributes( { anchor: cleanAnchor( v ) } ),
					onBlur: () => {
						const v = String( attributes.anchor || '' ).replace( /[-_]+$/, '' );
						if ( v !== attributes.anchor ) {
							setAttributes( { anchor: v } );
						}
					},
				} ),
				el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Section ID', 'df-editor' ),
					value: dfId,
					readOnly: true,
					className: 'df-block-readonly',
					help: __( 'Fixed ID that links this section to its texts and images. Texts and images are edited on the page with DF Inhalt.', 'df-editor' ),
					onChange: () => {},
				} )
			);

			return el(
				Fragment,
				null,
				toolbar,
				el( be.InspectorControls, null, layoutPanel, optionsPanel ),
				el( be.InspectorControls, { group: 'advanced' }, advancedPanel ),
				el( 'div', blockProps, header, hint, canvas )
			);
		};
	}

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	function register( name ) {
		const client = {
			edit: makeEdit( name ),
			save: () => null,
			icon: icon,
			example: { attributes: { preview: true } },
		};
		const existing = wp.blocks.getBlockType( name );
		if ( existing ) {
			// Already registered (e.g. auto-registered server block): re-register with our UI.
			const prev = wp.blocks.unregisterBlockType( name ) || existing;
			const merged = Object.assign( {}, prev, client );
			delete merged.name;
			wp.blocks.registerBlockType( name, merged );
			return;
		}
		// Server definitions (title, category, attributes, supports, usesContext …) are merged in by wp.blocks.
		wp.blocks.registerBlockType( name, client );
	}

	/** Last resort when the server definition never reached wp.blocks: minimal client metadata. */
	function registerFallback( name ) {
		const type = typeOf( name );
		const def = SECTIONS[ type ] || {};
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: String( def.label || type ),
			description: String( def.description || '' ),
			category: 'df-sections',
			icon: icon,
			supports: { html: false, className: false, customClassName: false, reusable: false, multiple: true, customCSS: false, visibility: false },
			usesContext: [ 'postId' ],
			attributes: {
				dfId: { type: 'string', default: '' },
				v: { type: 'integer' },
				variant: { type: 'string' },
				scheme: { type: 'string' },
				spacingTop: { type: 'string' },
				spacingBottom: { type: 'string' },
				width: { type: 'string' },
				anchor: { type: 'string', default: '' },
				hidden: { type: 'boolean', default: false },
				options: { type: 'object' },
				content: { type: 'object', default: {} },
				preview: { type: 'boolean', default: false },
			},
			example: { attributes: { preview: true } },
			edit: makeEdit( name ),
			save: () => null,
		} );
	}

	function registerAll( final ) {
		Object.keys( SECTIONS ).forEach( ( type ) => {
			const name = 'df/' + type;
			if ( wp.blocks.getBlockType( name ) && wp.blocks.getBlockType( name ).edit && wp.blocks.getBlockType( name ).edit.name === 'DfSectionEdit' ) {
				return; // done already.
			}
			try {
				register( name );
				if ( ! wp.blocks.getBlockType( name ) && final ) {
					// eslint-disable-next-line no-console
					console.warn( 'DF Editor: no server definition for ' + name + ', using client fallback.' );
					registerFallback( name );
				}
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.error( 'DF Editor: could not register block ' + name, e );
			}
		} );
	}

	// Server definitions are bootstrapped inline before editor scripts run; retry on domReady just in case.
	registerAll( false );
	if ( wp.domReady ) {
		wp.domReady( () => registerAll( true ) );
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => registerAll( true ) );
	} else {
		setTimeout( () => registerAll( true ), 0 );
	}
}( window.wp, window.dfBlocksData ) );
