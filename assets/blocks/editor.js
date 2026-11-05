/**
 * DF Editor – block editor UI for the DF section blocks (df/<type>, df/<type>-item).
 *
 * The blocks are registered server-side (PHP is the source of truth for name, title, category,
 * attributes, supports, parent). This script adds the editor UI, generic from section.json
 * (window.dfBlocksData) – no per-section code. Plain ES2020 on window.wp.* globals, no build step.
 *
 * Two modes per section block (see docs/BLOCKS.md):
 * - Block content (v2, attribute "fields" present): Shopify-like. Canvas = live preview of the
 *   whole section (POST df/v1/blocks/preview, debounced) + a compact "Blocks" strip with the
 *   child blocks (df/<type>-item: add, remove, duplicate, reorder with native Gutenberg actions).
 *   Sidebar = Content (generated controls per field) / Layout / Options / Advanced; a child block
 *   shows its own fields plus Duplicate / Remove. Clicking an item in the preview selects it.
 * - Legacy (v1, no "fields"): server-side preview, texts edited on the page with DF Inhalt;
 *   "Move texts into the block" converts it to block content.
 *
 * Expects window.dfBlocksData (localized by PHP):
 *   { sections: { <type>: { label, description, variants:[{value,label}],
 *       options:[{key,label,values:[{value,label}],default,variants|null}],
 *       fields:[{key,type,label,optional,max_length?,values?,aspect_ratio?,default}],
 *       list:{key,min,max,default_count,label,name,fields:[...]}|null,
 *       preview_url|null, version, defaults:{v,variant,scheme,spacingTop,spacingBottom,width,options},
 *       default_fields:{}, default_items:[{}] } },
 *     schemes:[{value,label,swatch}], spacing:[{value,label}], widths:[{value,label}],
 *     postId, homeUrl, previewPath, legacyPath, placeholderId }
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.data ) {
		return;
	}
	data = data || {};

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const RawHTML = wp.element.RawHTML;
	const useEffect = wp.element.useEffect;
	const useRef = wp.element.useRef;
	const useState = wp.element.useState;
	const useCallback = wp.element.useCallback;
	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;

	const be = wp.blockEditor;
	const c = wp.components;
	const useSelect = wp.data.useSelect;
	const apiFetch = wp.apiFetch;

	const SSR = wp.serverSideRender ? ( wp.serverSideRender.default || wp.serverSideRender ) : null;

	const SECTIONS = data.sections || {};
	const SCHEMES = Array.isArray( data.schemes ) ? data.schemes : [];
	const SPACING = Array.isArray( data.spacing ) ? data.spacing : [];
	const WIDTHS = Array.isArray( data.widths ) ? data.widths : [];
	const PREVIEW_DELAY = 300;
	/** Parallel preview requests (shared hosting has few PHP workers). */
	const PREVIEW_CONCURRENCY = 4;
	/** Previews rendered in this session, keyed by payload (undo/redo, toggling back). */
	const PREVIEW_CACHE_SIZE = 60;
	/** A request still running after this delay dims the preview (no flicker while typing). */
	const PREVIEW_SLOW = 400;
	/** Image value "no image" (optional image fields switched off); 0 = placeholder image. */
	const NO_IMAGE = -1;
	const CAN_HTML = !! data.unfilteredHtml;

	const ID_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/** Ids handed out in this editor session but maybe not yet in the store (parallel mounts). */
	const reserved = new Set();

	/**
	 * dfId → clientId of the block that owns it (first mounted). On load blocks mount in document
	 * order, so saved pages keep their ids; a paste, pattern insert or "Duplicate" mounts later
	 * and is always the newcomer, wherever it lands.
	 */
	const owners = new Map();

	/** Same for item ids (itemId → clientId). */
	const itemOwners = new Map();

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

	function isObject( v ) {
		return !! v && typeof v === 'object' && ! Array.isArray( v );
	}

	function clone( v ) {
		return v === undefined ? undefined : JSON.parse( JSON.stringify( v ) );
	}

	function stripTags( html ) {
		return String( html || '' ).replace( /<[^>]*>/g, ' ' ).replace( /&nbsp;/g, ' ' ).replace( /&amp;/g, '&' ).replace( /\s+/g, ' ' ).trim();
	}

	/** Replaces {n} (item number) in a default value. */
	function fillN( value, n ) {
		if ( typeof value === 'string' ) {
			return value.split( '{n}' ).join( String( n ) );
		}
		if ( isObject( value ) ) {
			const out = {};
			Object.keys( value ).forEach( ( k ) => {
				out[ k ] = fillN( value[ k ], n );
			} );
			return out;
		}
		return value;
	}

	function defaultItem( def, n ) {
		const out = {};
		( ( def.list && def.list.fields ) || [] ).forEach( ( f ) => {
			out[ f.key ] = fillN( clone( f.default ), n );
		} );
		return out;
	}

	function emptyValue( type ) {
		if ( type === 'button' ) {
			return { text: '', url: '' };
		}
		if ( type === 'image' ) {
			return NO_IMAGE;
		}
		return '';
	}

	function imageId( value ) {
		return Number( isObject( value ) ? value.id : value ) || 0;
	}

	function isFilled( type, value ) {
		if ( type === 'button' ) {
			return isObject( value ) && String( value.text || '' ).trim() !== '';
		}
		if ( type === 'image' ) {
			return imageId( value ) !== NO_IMAGE;
		}
		return stripTags( value ) !== '';
	}

	/**
	 * Value used when an optional field is switched on again: the section.json default when it
	 * has content, else null (the field is shown empty; nothing is written until the user types).
	 */
	function restoreValue( field, n ) {
		const d = fillN( clone( field.default ), n || 1 );
		if ( field.type === 'image' ) {
			return imageId( d ) > 0 ? d : 0;
		}
		return isFilled( field.type, d ) ? d : null;
	}

	/** Attachment record for thumbnails (core data; getMedia is deprecated since WP 6.9). */
	function useAttachment( id ) {
		return useSelect( ( select ) => ( id > 0 && select( 'core' ) ? select( 'core' ).getEntityRecord( 'postType', 'attachment', id, { context: 'view' } ) : null ), [ id ] );
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

	function randomChars( n ) {
		let out = '';
		for ( let i = 0; i < n; i++ ) {
			out += ID_CHARS.charAt( Math.floor( Math.random() * ID_CHARS.length ) );
		}
		return out;
	}

	function newId( type, taken ) {
		const base = ( String( type ).toLowerCase().replace( /[^a-z0-9-]/g, '' ) || 'section' ).replace( /^[^a-z]+/, '' ) || 'section';
		for ( let tries = 0; tries < 200; tries++ ) {
			const id = base.slice( 0, 35 ) + '-' + randomChars( 4 );
			if ( ! taken.has( id ) ) {
				return id;
			}
		}
		return base.slice( 0, 30 ) + '-' + Date.now().toString( 36 ).slice( -6 );
	}

	function newItemId() {
		return 'i' + randomChars( 6 );
	}

	/* ---------- preview queue + cache ---------- */

	const previewCache = new Map();
	const previewWaiting = [];
	let previewActive = 0;

	function cacheGet( key ) {
		if ( ! previewCache.has( key ) ) {
			return undefined;
		}
		const html = previewCache.get( key );
		previewCache.delete( key );
		previewCache.set( key, html );
		return html;
	}

	function cachePut( key, html ) {
		previewCache.delete( key );
		previewCache.set( key, html );
		while ( previewCache.size > PREVIEW_CACHE_SIZE ) {
			previewCache.delete( previewCache.keys().next().value );
		}
	}

	function pumpPreviews() {
		while ( previewActive < PREVIEW_CONCURRENCY && previewWaiting.length ) {
			const job = previewWaiting.shift();
			previewActive++;
			apiFetch( { path: data.previewPath || '/df/v1/blocks/preview', method: 'POST', data: job.body } )
				.then( job.resolve, job.reject )
				.finally( () => {
					previewActive--;
					pumpPreviews();
				} );
		}
	}

	/** Queues a preview request; cancel() drops it while it is still waiting. */
	function queuePreview( body ) {
		const job = { body };
		job.promise = new Promise( ( resolve, reject ) => {
			job.resolve = resolve;
			job.reject = reject;
		} );
		previewWaiting.push( job );
		pumpPreviews();
		return {
			promise: job.promise,
			cancel: () => {
				const at = previewWaiting.indexOf( job );
				if ( at >= 0 ) {
					previewWaiting.splice( at, 1 );
					job.reject( { cancelled: true } );
				}
			},
		};
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

	function currentPostId( registry ) {
		const ed = registry.select( 'core/editor' );
		const id = ed && ed.getCurrentPostId ? Number( ed.getCurrentPostId() ) : 0;
		return id > 0 ? id : Number( data.postId ) || 0;
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
	 * Front-end URL that opens DF Inhalt for the post being edited (legacy blocks). Drafts, pending
	 * and scheduled pages use the preview link (?page_id=X&preview=true): their pretty URL is a 404.
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
	 * Legacy blocks: opens DF Inhalt on the page in a new tab. Unsaved changes are saved first (for a
	 * published page only after confirmation, because saving publishes them).
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
	/* Icons                                                               */
	/* ------------------------------------------------------------------ */

	const icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M4 4h16a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v3h14V6H5zM4 13h16a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1zm1 2v3h14v-3H5z' } )
	);

	const itemIcon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M4 6h16v2H4zm0 5h16v2H4zm0 5h10v2H4z' } )
	);

	/** Small SVG icons for the canvas (dashicons are not loaded inside the editor iframe). */
	function svgIcon( d ) {
		return el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 20, height: 20, 'aria-hidden': 'true', focusable: 'false' }, el( 'path', { d } ) );
	}
	const ROW_ICONS = {
		up: svgIcon( 'M6.5 12.4 12 7l5.5 5.4-1 1.1L12 9.1l-4.5 4.4z' ),
		down: svgIcon( 'M17.5 11.6 12 17l-5.5-5.4 1-1.1 4.5 4.4 4.5-4.4z' ),
		copy: svgIcon( 'M7 7V4h13v13h-3v3H4V7h3zm1.5 0H17v8.5h1.5v-10h-10V7zM5.5 8.5v10h10v-10h-10z' ),
		plus: svgIcon( 'M11.25 5h1.5v6.25H19v1.5h-6.25V19h-1.5v-6.25H5v-1.5h6.25z' ),
		trash: svgIcon( 'M9 4h6v1.5h4V7H5V5.5h4V4zm-2 4.5h1.5V19h7V8.5H17V20.5H7V8.5zm3 1.5h1.5v7H10v-7zm2.5 0H14v7h-1.5v-7z' ),
	};

	/* ------------------------------------------------------------------ */
	/* Canvas parts                                                        */
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
						el( 'span', { className: 'df-block-scheme__swatch', style: { background: col.bg, color: col.fg || 'inherit' }, 'aria-hidden': 'true' }, col.fg ? 'Aa' : '' ),
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

	function Text( props ) {
		return el( c.TextControl, Object.assign( { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true }, props ) );
	}

	function counter( value, max ) {
		if ( ! max ) {
			return null;
		}
		const len = stripTags( value ).length;
		return sprintf( /* translators: 1: characters used, 2: maximum */ __( '%1$d / %2$d characters', 'df-editor' ), len, max );
	}

	/* ---------- rich text (small, dependency-free) ---------- */

	/** Normalises editor HTML to the rich text tag set (p, br, strong, em, ul, ol, li, a[href], h2, h3). */
	function cleanHtml( html ) {
		const tpl = document.createElement( 'template' );
		tpl.innerHTML = String( html || '' );
		const rename = { B: 'strong', I: 'em', DIV: 'p' };
		const allowed = [ 'P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI', 'A', 'H2', 'H3' ];
		const walk = ( node ) => {
			Array.from( node.childNodes ).forEach( ( child ) => {
				if ( child.nodeType === 1 ) {
					walk( child );
					let elNode = child;
					if ( rename[ elNode.tagName ] ) {
						const repl = document.createElement( rename[ elNode.tagName ] );
						while ( elNode.firstChild ) {
							repl.appendChild( elNode.firstChild );
						}
						elNode.parentNode.replaceChild( repl, elNode );
						elNode = repl;
					}
					if ( allowed.indexOf( elNode.tagName ) < 0 ) {
						while ( elNode.firstChild ) {
							elNode.parentNode.insertBefore( elNode.firstChild, elNode );
						}
						elNode.parentNode.removeChild( elNode );
						return;
					}
					Array.from( elNode.attributes ).forEach( ( a ) => {
						if ( ! ( elNode.tagName === 'A' && a.name === 'href' ) ) {
							elNode.removeAttribute( a.name );
						}
					} );
				} else if ( child.nodeType !== 3 ) {
					child.parentNode.removeChild( child );
				}
			} );
		};
		walk( tpl.content );
		const out = tpl.innerHTML.trim();
		return stripTags( out ) === '' ? '' : out;
	}

	function escapeHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/** URL typed into a link field; "https://" alone counts as no link. */
	function cleanLinkUrl( url ) {
		const u = String( url || '' ).trim();
		return /^(https?:\/\/)?$/i.test( u ) ? '' : u;
	}

	/** Link popover of the rich text field: page search (URLInput), apply, remove. */
	function LinkPopover( props ) {
		const [ url, setUrl ] = useState( props.url || '' );
		const apply = ( e ) => {
			if ( e ) {
				e.preventDefault();
			}
			props.onApply( cleanLinkUrl( url ) );
		};
		return el(
			c.Popover,
			{ placement: 'bottom-start', onClose: props.onClose, anchor: props.anchor || undefined, focusOnMount: 'firstElement', className: 'df-rt__link-popover' },
			el(
				'form',
				{ className: 'df-rt__link-form', onSubmit: apply },
				be.URLInput
					? el( be.URLInput, { label: __( 'Link', 'df-editor' ), value: url, onChange: ( u ) => setUrl( u ), __nextHasNoMarginBottom: true, placeholder: __( 'Search or paste a URL', 'df-editor' ) } )
					: el( Text, { label: __( 'Link', 'df-editor' ), value: url, onChange: ( u ) => setUrl( u ) } ),
				el(
					'div',
					{ className: 'df-rt__link-actions' },
					el( c.Button, { variant: 'primary', size: 'compact', type: 'submit' }, __( 'Apply', 'df-editor' ) ),
					props.hasLink ? el( c.Button, { variant: 'secondary', size: 'compact', onClick: () => props.onApply( '' ) }, __( 'Remove link', 'df-editor' ) ) : null,
					el( c.Button, { variant: 'tertiary', size: 'compact', onClick: props.onClose }, __( 'Cancel', 'df-editor' ) )
				)
			)
		);
	}

	function RichTextField( props ) {
		const ref = useRef( null );
		const toolbarRef = useRef( null );
		const focused = useRef( false );
		const [ source, setSource ] = useState( false );
		const [ linkUi, setLinkUi ] = useState( null );
		const value = String( props.value || '' );
		// Last value this field emitted: a different incoming value comes from outside (undo/redo).
		const lastEmitted = useRef( value );

		useEffect( () => {
			if ( source || ! ref.current ) {
				return;
			}
			const external = value !== lastEmitted.current;
			if ( ( external || ! focused.current ) && ref.current.innerHTML !== value ) {
				ref.current.innerHTML = value;
				if ( focused.current ) {
					// Caret to the end after an undo/redo while typing.
					const doc = ref.current.ownerDocument;
					const sel = doc.getSelection();
					if ( sel ) {
						const range = doc.createRange();
						range.selectNodeContents( ref.current );
						range.collapse( false );
						sel.removeAllRanges();
						sel.addRange( range );
					}
				}
			}
			lastEmitted.current = value;
		}, [ value, source ] );

		const emit = () => {
			if ( ref.current ) {
				const next = cleanHtml( ref.current.innerHTML );
				lastEmitted.current = next;
				props.onChange( next );
			}
		};
		const cmd = ( name, arg ) => {
			if ( ! ref.current ) {
				return;
			}
			ref.current.focus();
			try {
				document.execCommand( 'defaultParagraphSeparator', false, 'p' );
				document.execCommand( name, false, arg );
			} catch ( e ) {}
			emit();
		};
		const openLink = () => {
			if ( ! ref.current ) {
				return;
			}
			const doc = ref.current.ownerDocument;
			const sel = doc.getSelection();
			let range = null;
			let anchorEl = null;
			if ( sel && sel.rangeCount && ref.current.contains( sel.anchorNode ) ) {
				range = sel.getRangeAt( 0 ).cloneRange();
				const node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
				anchorEl = node && node.closest ? node.closest( 'a' ) : null;
				if ( anchorEl && ! ref.current.contains( anchorEl ) ) {
					anchorEl = null;
				}
			}
			setLinkUi( { url: anchorEl ? anchorEl.getAttribute( 'href' ) || '' : '', range, anchorEl } );
		};
		const applyLink = ( url ) => {
			const ui = linkUi || {};
			setLinkUi( null );
			if ( ! ref.current ) {
				return;
			}
			ref.current.focus();
			const doc = ref.current.ownerDocument;
			const sel = doc.getSelection();
			if ( ui.anchorEl && ref.current.contains( ui.anchorEl ) && sel ) {
				// Edit/remove the whole existing link.
				const r = doc.createRange();
				r.selectNodeContents( ui.anchorEl );
				sel.removeAllRanges();
				sel.addRange( r );
			} else if ( ui.range && sel ) {
				sel.removeAllRanges();
				sel.addRange( ui.range );
			}
			if ( ! url ) {
				if ( ui.anchorEl ) {
					cmd( 'unlink' );
				}
			} else if ( ! ui.anchorEl && ( ! ui.range || ui.range.collapsed ) ) {
				cmd( 'insertHTML', '<a href="' + escapeHtml( url ) + '">' + escapeHtml( url ) + '</a>' );
			} else {
				cmd( 'createLink', url );
			}
		};
		const tool = ( label, text, onClick ) =>
			el( c.Button, { size: 'small', className: 'df-rt__btn', label, showTooltip: true, onMouseDown: ( e ) => e.preventDefault(), onClick, disabled: source }, text );

		return el(
			c.BaseControl,
			// With a visible toggle label (optional fields) the area keeps only its aria-label.
			{ __nextHasNoMarginBottom: true, label: props.hideLabel ? undefined : props.label, help: props.help, className: 'df-rt' },
			el(
				'div',
				{ className: 'df-rt__toolbar', role: 'toolbar', 'aria-label': __( 'Formatting', 'df-editor' ), ref: toolbarRef },
				tool( __( 'Bold', 'df-editor' ), el( 'strong', null, 'B' ), () => cmd( 'bold' ) ),
				tool( __( 'Italic', 'df-editor' ), el( 'em', null, 'I' ), () => cmd( 'italic' ) ),
				tool( __( 'Link', 'df-editor' ), '🔗', openLink ),
				tool( __( 'Bulleted list', 'df-editor' ), '•≡', () => cmd( 'insertUnorderedList' ) ),
				tool( __( 'Numbered list', 'df-editor' ), '1≡', () => cmd( 'insertOrderedList' ) ),
				// Raw HTML only for users who may post unfiltered HTML (agency), not for clients.
				CAN_HTML ? el( c.Button, { size: 'small', className: 'df-rt__btn df-rt__src', isPressed: source, onClick: () => setSource( ! source ) }, 'HTML' ) : null
			),
			linkUi
				? el( LinkPopover, { url: linkUi.url, hasLink: !! linkUi.anchorEl, anchor: toolbarRef.current, onApply: applyLink, onClose: () => setLinkUi( null ) } )
				: null,
			source
				? el( c.TextareaControl, { __nextHasNoMarginBottom: true, value, rows: 6, onChange: ( v ) => props.onChange( v ), className: 'df-rt__source', 'aria-label': props.label } )
				: el( 'div', {
					ref,
					className: 'df-rt__area',
					contentEditable: true,
					suppressContentEditableWarning: true,
					role: 'textbox',
					'aria-multiline': 'true',
					'aria-label': props.label,
					'data-placeholder': props.placeholder || undefined,
					onFocus: () => {
						focused.current = true;
					},
					onBlur: () => {
						focused.current = false;
						emit();
					},
					onInput: emit,
				} )
		);
	}

	/* ---------- image ---------- */

	function ImageField( props ) {
		const field = props.field;
		const raw = props.value;
		const id = imageId( raw );
		const alt = isObject( raw ) ? String( raw.alt || '' ) : '';
		const media = useAttachment( id );
		const thumb = media
			? ( media.media_details && media.media_details.sizes && ( media.media_details.sizes.medium || media.media_details.sizes.thumbnail ) ? ( media.media_details.sizes.medium || media.media_details.sizes.thumbnail ).source_url : media.source_url )
			: '';
		const set = ( nextId, nextAlt ) => {
			const a = nextAlt === undefined ? alt : nextAlt;
			props.onChange( a ? { id: nextId, alt: a } : nextId );
		};
		const MediaUpload = be.MediaUpload;
		const Check = be.MediaUploadCheck || Fragment;
		return el(
			c.BaseControl,
			{ __nextHasNoMarginBottom: true, label: props.hideLabel ? undefined : field.label, className: 'df-image-field', help: field.aspect_ratio ? sprintf( /* translators: %s: aspect ratio */ __( 'Shown in %s format.', 'df-editor' ), field.aspect_ratio ) : null },
			el(
				'div',
				{ className: 'df-image-field__preview' },
				thumb ? el( 'img', { src: thumb, alt: '' } ) : el( 'span', { className: 'df-image-field__empty' }, id > 0 ? __( 'Loading…', 'df-editor' ) : __( 'Placeholder image', 'df-editor' ) )
			),
			el(
				'div',
				{ className: 'df-image-field__actions' },
				MediaUpload
					? el(
						Check,
						null,
						el( MediaUpload, {
							allowedTypes: [ 'image' ],
							value: id > 0 ? id : undefined,
							onSelect: ( m ) => set( Number( m && m.id ) || 0 ),
							render: ( r ) => el( c.Button, { variant: 'secondary', size: 'compact', onClick: r.open }, id > 0 ? __( 'Replace', 'df-editor' ) : __( 'Select image', 'df-editor' ) ),
						} )
					)
					: null,
				// Back to the section's default image (placeholder); removing the image is the toggle of optional images.
				id > 0 ? el( c.Button, { variant: 'tertiary', size: 'compact', onClick: () => set( 0, '' ) }, __( 'Reset image', 'df-editor' ) ) : null
			),
			id > 0
				? el( Text, {
					label: __( 'Alt text (optional, overrides the media library)', 'df-editor' ),
					value: alt,
					onChange: ( v ) => set( id, v ),
				} )
				: null
		);
	}

	/* ---------- one field ---------- */

	function FieldInput( props ) {
		const field = props.field;
		const value = props.value;
		const onChange = props.onChange;
		const max = field.max_length || 0;
		switch ( field.type ) {
			case 'richtext':
				return el( RichTextField, { label: field.label, hideLabel: props.hideLabel, value, onChange, help: counter( value, max ) } );
			case 'button': {
				const v = isObject( value ) ? value : { text: '', url: '' };
				const set = ( patch ) => {
					const next = Object.assign( {}, v, patch );
					if ( next.target !== '_blank' ) {
						delete next.target;
					}
					onChange( next );
				};
				const url = String( v.url || '' ).trim();
				const missing = String( v.text || '' ).trim() !== '' && ( url === '' || url === '#' );
				return el(
					'fieldset',
					// Optional buttons: the toggle above already shows the label.
					{ className: 'df-button-field', 'aria-label': props.hideLabel ? field.label : undefined },
					props.hideLabel ? null : el( 'legend', { className: 'df-block-control-label' }, field.label ),
					el( Text, { label: __( 'Button text', 'df-editor' ), value: String( v.text || '' ), placeholder: field.label, maxLength: max || undefined, onChange: ( t ) => set( { text: t } ) } ),
					be.URLInput
						? el( be.URLInput, { label: __( 'Link', 'df-editor' ), value: String( v.url || '' ), onChange: ( u ) => set( { url: u } ), className: 'df-button-field__url', __nextHasNoMarginBottom: true } )
						: el( Text, { label: __( 'Link', 'df-editor' ), value: String( v.url || '' ), onChange: ( u ) => set( { url: u } ) } ),
					missing ? el( 'p', { className: 'df-field-warning', role: 'status' }, __( 'Link missing', 'df-editor' ) ) : null,
					el( c.ToggleControl, { __nextHasNoMarginBottom: true, label: __( 'Open in new tab', 'df-editor' ), checked: v.target === '_blank', onChange: ( on ) => set( { target: on ? '_blank' : '' } ) } )
				);
			}
			case 'image':
				return el( ImageField, { field, value, onChange, hideLabel: props.hideLabel } );
			case 'setting':
				return el( Select, {
					label: field.label,
					value: String( value === undefined || value === null ? field.default : value ),
					options: toSelectOptions( field.values ),
					onChange,
				} );
			default:
				return el( Text, {
					label: field.label,
					hideLabelFromVision: !! props.hideLabel,
					placeholder: props.hideLabel ? field.label : undefined,
					value: String( value === undefined || value === null ? '' : value ),
					maxLength: max || undefined,
					help: counter( value, max ),
					onChange,
				} );
		}
	}

	/**
	 * One generated control; optional fields get a toggle (off = empty value = not rendered).
	 * Switching on restores the remembered or default value, else shows the field empty (with the
	 * label as placeholder) without writing anything until the user types.
	 */
	function FieldControl( props ) {
		const field = props.field;
		const value = props.value;
		const remembered = useRef( null );
		const [ shown, setShown ] = useState( false );
		if ( ! field.optional || field.type === 'setting' ) {
			return el( 'div', { className: 'df-field df-field--' + field.type }, el( FieldInput, props ) );
		}
		const filled = isFilled( field.type, value );
		const on = filled || shown;
		return el(
			'div',
			{ className: 'df-field df-field--' + field.type + ' is-optional' + ( on ? '' : ' is-off' ) },
			el( c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				className: 'df-field__toggle',
				label: on ? field.label : sprintf( /* translators: %s: field label */ __( '%s (hidden)', 'df-editor' ), field.label ),
				checked: on,
				onChange: ( next ) => {
					if ( next ) {
						const restored = remembered.current !== null && isFilled( field.type, remembered.current ) ? remembered.current : restoreValue( field, props.n );
						if ( restored !== null && isFilled( field.type, restored ) ) {
							props.onChange( restored );
						} else {
							setShown( true );
						}
					} else {
						setShown( false );
						if ( filled ) {
							remembered.current = value;
						}
						props.onChange( emptyValue( field.type ) );
					}
				},
			} ),
			on
				? el( FieldInput, Object.assign( {}, props, {
					hideLabel: true,
					onChange: ( v ) => {
						setShown( true );
						props.onChange( v );
					},
				} ) )
				: null
		);
	}

	function FieldList( props ) {
		const values = isObject( props.values ) ? props.values : {};
		return el(
			Fragment,
			null,
			( props.fields || [] ).map( ( f ) =>
				el( FieldControl, {
					key: f.key,
					field: f,
					n: props.n,
					value: values[ f.key ] !== undefined ? values[ f.key ] : fillN( clone( f.default ), props.n || 1 ),
					onChange: ( v ) => {
						const next = Object.assign( {}, values );
						next[ f.key ] = v;
						props.onChange( next );
					},
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Live preview (block content)                                        */
	/* ------------------------------------------------------------------ */

	function LivePreview( props ) {
		const registry = props.registry;
		const key = props.payloadKey;
		const cached = cacheGet( key );
		const [ html, setHtml ] = useState( cached === undefined ? null : cached );
		const [ loading, setLoading ] = useState( false );
		const [ error, setError ] = useState( '' );
		// First render only near the viewport (long pages: no request storm on load).
		const [ visible, setVisible ] = useState( cached !== undefined );
		const seq = useRef( 0 );
		const box = useRef( null );
		const root = useRef( null );

		useEffect( () => {
			if ( visible || ! root.current ) {
				return undefined;
			}
			const node = root.current;
			const win = node.ownerDocument && node.ownerDocument.defaultView;
			if ( ! win || ! win.IntersectionObserver ) {
				setVisible( true );
				return undefined;
			}
			const onSeen = ( entries ) => {
				if ( entries.some( ( e ) => e.isIntersecting ) ) {
					setVisible( true );
				}
			};
			let io;
			try {
				// Root = the canvas document (iframe), so the margin applies to its viewport.
				io = new win.IntersectionObserver( onSeen, { root: node.ownerDocument, rootMargin: '800px 0px' } );
			} catch ( e ) {
				io = new win.IntersectionObserver( onSeen, { rootMargin: '800px 0px' } );
			}
			io.observe( node );
			return () => io.disconnect();
		}, [ visible ] );

		useEffect( () => {
			if ( ! apiFetch || ! props.postId || ! visible ) {
				return undefined;
			}
			const mine = ++seq.current;
			const hit = cacheGet( key );
			if ( hit !== undefined ) {
				setHtml( hit );
				setError( '' );
				setLoading( false );
				return undefined;
			}
			let job = null;
			let slow = null;
			const timer = setTimeout( () => {
				// Dim only when the request is slow; the old preview stays crisp while typing.
				slow = setTimeout( () => {
					if ( mine === seq.current ) {
						setLoading( true );
					}
				}, PREVIEW_SLOW );
				job = queuePreview( props.payload() );
				job.promise
					.then( ( res ) => {
						const next = String( ( res && res.html ) || '' );
						cachePut( key, next );
						if ( mine === seq.current ) {
							setHtml( next );
							setError( '' );
							setLoading( false );
						}
					} )
					.catch( ( err ) => {
						if ( err && err.cancelled ) {
							return;
						}
						if ( mine === seq.current ) {
							setError( String( ( err && err.message ) || 'error' ) );
							setLoading( false );
						}
					} )
					.finally( () => clearTimeout( slow ) );
			}, html === null ? 0 : PREVIEW_DELAY );
			return () => {
				clearTimeout( timer );
				clearTimeout( slow );
				if ( job ) {
					job.cancel();
				}
			};
		}, [ key, props.postId, visible ] );

		// The preview is a picture of the section: no tab stops, no link navigation. Collapsed
		// content (<details>, e.g. FAQ answers) is opened so edits in the sidebar are visible.
		useEffect( () => {
			if ( box.current ) {
				box.current.querySelectorAll( 'a, button, input, select, textarea, summary, iframe, [tabindex]' ).forEach( ( n ) => n.setAttribute( 'tabindex', '-1' ) );
				box.current.querySelectorAll( 'details' ).forEach( ( d ) => {
					d.open = true;
				} );
			}
		}, [ html ] );

		const onClick = ( e ) => {
			e.preventDefault();
			const target = e.target && e.target.closest ? e.target.closest( '[data-df-item]' ) : null;
			const d = registry.dispatch( 'core/block-editor' );
			if ( target && box.current && box.current.contains( target ) ) {
				const n = parseInt( target.getAttribute( 'data-df-item' ), 10 );
				const child = props.childIds[ n - 1 ];
				if ( child ) {
					e.stopPropagation();
					d.selectBlock( child );
					return;
				}
			}
			d.selectBlock( props.clientId );
		};

		if ( html === null ) {
			return el( 'div', { ref: root, className: 'df-block-live is-pending' }, error ? el( ErrorResponse, { response: { errorMsg: error } } ) : el( Loading, null ) );
		}
		const content = html.trim() === '' ? el( EmptyResponse ) : el( RawHTML, null, html );
		return el(
			'div',
			{ ref: root, className: 'df-block-live' + ( loading ? ' is-loading' : '' ), onClickCapture: onClick },
			el( 'div', { className: 'df-block-live__content', ref: box, 'aria-hidden': 'true' }, content ),
			loading ? el( 'div', { className: 'df-block-live__spinner' }, el( c.Spinner ) ) : null,
			error ? el( 'div', { className: 'df-block-notice is-error', role: 'alert' }, __( 'Preview could not be updated.', 'df-editor' ) + ' ' + error ) : null
		);
	}

	/* ------------------------------------------------------------------ */
	/* Section block                                                       */
	/* ------------------------------------------------------------------ */

	function makeEdit( name ) {
		const type = typeOf( name );

		return function DfSectionEdit( props ) {
			const attributes = props.attributes || {};
			const setAttributes = props.setAttributes;
			const clientId = props.clientId;
			const isSelected = !! props.isSelected;
			const def = SECTIONS[ type ] || { label: type, description: '', variants: [], options: [], fields: [], list: null, preview_url: null };
			const defaults = isObject( def.defaults ) ? def.defaults : {};
			const list = def.list || null;
			const registry = wp.data.useRegistry ? wp.data.useRegistry() : wp.data;
			const variants = Array.isArray( def.variants ) ? def.variants : [];
			const sectionOptions = ( Array.isArray( def.options ) ? def.options : [] ).filter( ( o ) => o && o.key !== 'count' );

			// "fields" present (an empty one may arrive as [] from PHP) = block content; new blocks too.
			const contentMode = isObject( attributes.fields ) || Array.isArray( attributes.fields ) || ! attributes.dfId;
			const legacy = ! contentMode;

			const isPreviewMode = useSelect( ( select ) => {
				const s = select( 'core/block-editor' ).getSettings();
				return !! ( s && s.isPreviewMode );
			}, [] );
			const hasEditor = useSelect( ( select ) => !! select( 'core/editor' ), [] );
			const childIds = useSelect( ( select ) => select( 'core/block-editor' ).getBlockOrder( clientId ), [ clientId ] );
			const items = useSelect(
				( select ) => ( select( 'core/block-editor' ).getBlocks( clientId ) || [] ).map( ( b ) => ( b.attributes && b.attributes.fields ) || null ),
				[ clientId ]
			);
			const childSelected = useSelect( ( select ) => select( 'core/block-editor' ).hasSelectedInnerBlock( clientId, true ), [ clientId ] );
			const postId = useSelect( () => currentPostId( registry ), [] );

			const dfId = String( attributes.dfId || '' );
			// Missing settings (blocks saved before explicit values) fall back like the renderer does.
			const variant = attributes.variant || defaults.variant || ( variants[ 0 ] ? variants[ 0 ].value : '' );
			const scheme = attributes.scheme || defaults.scheme || 'hell';
			const spacingTop = attributes.spacingTop || defaults.spacingTop || 'm';
			const spacingBottom = attributes.spacingBottom || defaults.spacingBottom || 'm';
			const width = attributes.width || defaults.width || 'container';
			const ownOpts = isObject( attributes.options ) ? attributes.options : {};
			const opts = Object.assign( {}, defaults.options || {}, ownOpts );
			const count = childIds.length;

			/*
			 * dfId: a new block (empty id) gets an id, explicit settings, default fields and default
			 * items; a block whose id is owned by another, earlier mounted block (paste, pattern,
			 * "Duplicate") gets a new id. Automatic changes join the insert's undo level.
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
					EXPLICIT.forEach( ( k ) => {
						if ( attributes[ k ] === undefined && defaults[ k ] !== undefined ) {
							patch[ k ] = k === 'options' ? Object.assign( {}, defaults.options ) : defaults[ k ];
						}
					} );
					if ( ! isObject( attributes.fields ) && ! Array.isArray( attributes.fields ) ) {
						patch.fields = clone( def.default_fields || {} ) || {};
					}
					if ( isObject( patch.options ) ) {
						delete patch.options.count; // Block content: the child blocks are the items.
					}
				}
				markNotPersistent( registry );
				setAttributes( patch );
				if ( reason === 'new' && list && registry.select( 'core/block-editor' ).getBlockOrder( clientId ).length === 0 ) {
					const blocks = ( def.default_items || [] ).map( ( f ) => wp.blocks.createBlock( list.name, { itemId: newItemId(), fields: clone( f ) } ) );
					markNotPersistent( registry );
					registry.dispatch( 'core/block-editor' ).replaceInnerBlocks( clientId, blocks, false );
				}
				if ( reason === 'copy' ) {
					registry.dispatch( 'core/notices' ).createInfoNotice(
						legacy ? __( 'Section copied – it has its own texts now.', 'df-editor' ) : __( 'Section copied.', 'df-editor' ),
						{ type: 'snackbar', id: 'df-section-copied' }
					);
				}
			}, [ dfId, clientId, isPreviewMode, attributes.preview ] );

			/*
			 * Minimum number of items (e.g. select all + Delete, which the remove lock below cannot
			 * stop when more than min items are selected): the removed items are put back with their
			 * content (same undo step); default items only when there is nothing to restore.
			 */
			const innerSnap = useRef( [] );
			useEffect( () => {
				if ( ! list || legacy || attributes.preview || isPreviewMode || ! ( isObject( attributes.fields ) || Array.isArray( attributes.fields ) ) || ! dfId ) {
					return;
				}
				const min = Number( list.min ) || 0;
				if ( count >= min ) {
					return;
				}
				const d = registry.dispatch( 'core/block-editor' );
				const order = registry.select( 'core/block-editor' ).getBlockOrder( clientId );
				const removed = ( innerSnap.current || [] ).filter( ( b ) => order.indexOf( b.clientId ) < 0 ).slice( 0, min - count );
				if ( removed.length ) {
					markNotPersistent( registry );
					d.insertBlocks( removed.map( ( b ) => wp.blocks.cloneBlock( b ) ), 0, clientId, false );
				}
				const blocks = [];
				for ( let n = count + removed.length + 1; n <= min; n++ ) {
					blocks.push( wp.blocks.createBlock( list.name, { itemId: newItemId(), fields: defaultItem( def, n ) } ) );
				}
				if ( blocks.length ) {
					markNotPersistent( registry );
					d.insertBlocks( blocks, count + removed.length, clientId, false );
				}
				registry.dispatch( 'core/notices' ).createInfoNotice(
					sprintf( /* translators: 1: minimum number, 2: item label */ __( 'This section needs at least %1$d × %2$s.', 'df-editor' ), min, list.label ),
					{ type: 'snackbar', id: 'df-min-items' }
				);
			}, [ count, legacy, dfId ] );

			const listActive = !! list && ! legacy && ! attributes.preview && ! isPreviewMode && !! dfId;

			/*
			 * Minimum: at min the items are locked against removal (lock.remove: Gutenberg hides
			 * Delete in the toolbar / List View and ignores Del); unlocked again above min.
			 */
			useEffect( () => {
				if ( ! listActive ) {
					return;
				}
				const min = Number( list.min ) || 0;
				const lockIt = min > 0 && count <= min;
				const ids = ( registry.select( 'core/block-editor' ).getBlocks( clientId ) || [] )
					.filter( ( b ) => lockIt !== !! ( b.attributes && b.attributes.lock && b.attributes.lock.remove ) )
					.map( ( b ) => b.clientId );
				if ( ! ids.length ) {
					return;
				}
				markNotPersistent( registry );
				registry.dispatch( 'core/block-editor' ).updateBlockAttributes( ids, { lock: lockIt ? { remove: true } : undefined } );
			}, [ count, listActive ] );

			/*
			 * Maximum: native Duplicate (Ctrl+D), paste or List View can still insert items (the
			 * item's "parent" allows it). Extra items are removed again – the newly added ones –
			 * in the same undo step.
			 */
			const prevChildren = useRef( childIds );
			const childKey = childIds.join( ',' );
			useEffect( () => {
				const prev = prevChildren.current;
				prevChildren.current = childIds;
				if ( ! listActive ) {
					return;
				}
				const max = Number( list.max ) || 0;
				if ( ! max || childIds.length <= max ) {
					return;
				}
				const extra = childIds.length - max;
				let remove = childIds.filter( ( id ) => prev.indexOf( id ) < 0 ).slice( -extra );
				if ( remove.length < extra ) {
					remove = remove.concat( childIds.filter( ( id ) => remove.indexOf( id ) < 0 ).slice( -( extra - remove.length ) ) );
				}
				markNotPersistent( registry );
				registry.dispatch( 'core/block-editor' ).removeBlocks( remove, false );
				registry.dispatch( 'core/notices' ).createWarningNotice(
					sprintf( /* translators: 1: maximum number, 2: item label */ __( 'This section allows at most %1$d × %2$s.', 'df-editor' ), max, list.label ),
					{ type: 'snackbar', id: 'df-max-items' }
				);
			}, [ childKey, listActive ] );

			// Snapshot of the items after every render (read by the minimum effect above).
			useEffect( () => {
				if ( list ) {
					innerSnap.current = registry.select( 'core/block-editor' ).getBlocks( clientId ) || [];
				}
			} );

			const onEditTexts = useCallback( () => openTexts( registry ), [ registry ] );

			const setOption = useCallback(
				( k, value ) => {
					const next = Object.assign( {}, ownOpts );
					next[ k ] = value;
					setAttributes( { options: next } );
				},
				[ ownOpts, setAttributes ]
			);

			const addItem = () => {
				if ( ! list || count >= list.max ) {
					return;
				}
				const block = wp.blocks.createBlock( list.name, { itemId: newItemId(), fields: defaultItem( def, count + 1 ) } );
				registry.dispatch( 'core/block-editor' ).insertBlock( block, count, clientId, true );
			};

			/* Legacy preview: inert server-side render. */
			const contentRef = useRef( null );
			useEffect( () => {
				if ( contentRef.current ) {
					contentRef.current.setAttribute( 'inert', '' );
				}
			} );

			const atMax = !! list && count >= Number( list.max );
			const innerProps = be.useInnerBlocksProps(
				{ className: 'df-block-items__list' },
				{
					allowedBlocks: list && ! atMax ? [ list.name ] : [],
					renderAppender: false,
					templateLock: false,
					orientation: 'vertical',
				}
			);

			const variantLabel = labelOf( variants, variant );
			const sectionLabel = String( def.label || type );

			const blockProps = be.useBlockProps( {
				className: [
					'df-block',
					'df-block--' + type,
					contentMode ? 'is-df-content' : 'is-df-legacy',
					attributes.hidden ? 'is-df-hidden' : '',
					isSelected ? 'is-df-selected' : '',
					childSelected ? 'has-df-selected-item' : '',
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
			const previewAttributes = () => {
				const a = Object.assign( {}, attributes );
				delete a.preview;
				return a;
			};
			let canvas;
			if ( attributes.hidden ) {
				canvas = el( Placeholder, {
					muted: true,
					title: sprintf( /* translators: %s: section name */ __( '%s (hidden)', 'df-editor' ), sectionLabel ),
					text: __( 'Not shown on the site. Turn off “Hide section” in the sidebar to show it again.', 'df-editor' ),
				} );
			} else if ( ! dfId ) {
				canvas = el( Loading, null );
			} else if ( contentMode ) {
				const payload = {
					name,
					post_id: postId,
					attributes: previewAttributes(),
					items: items.map( ( f ) => f || {} ),
				};
				canvas = el( LivePreview, {
					registry,
					clientId,
					childIds,
					postId,
					payloadKey: JSON.stringify( payload ),
					payload: () => payload,
				} );
			} else if ( ! SSR ) {
				canvas = el( Placeholder, { title: sectionLabel, text: __( 'Preview is not available.', 'df-editor' ) } );
			} else {
				canvas = el(
					'div',
					{ className: 'df-block-preview__content', ref: contentRef },
					el( SSR, {
						block: name,
						attributes,
						// POST: the "content" attribute (initial texts, list items) can be long for a GET URL.
						httpMethod: 'POST',
						urlQueryArgs: { post_id: postId || undefined },
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
			const hint = legacy && isSelected && hasEditor
				? el( 'div', { className: 'df-block-hint', 'aria-hidden': 'true' }, __( 'Click “Edit texts” to change texts', 'df-editor' ) )
				: null;

			/* Blocks strip: the real child blocks (compact rows) + appender. */
			const expanded = isSelected || childSelected;
			const strip = list && contentMode
				? el(
					'div',
					{ className: 'df-block-items' + ( expanded ? '' : ' is-collapsed' ) },
					// Unselected section: a slim hint (on hover) that answers "how do I add one?".
					expanded
						? null
						: el(
							'button',
							{ type: 'button', className: 'df-block-items__hint', onClick: () => registry.dispatch( 'core/block-editor' ).selectBlock( clientId ) },
							sprintf( /* translators: 1: count, 2: item label */ __( '%1$d × %2$s – click to edit', 'df-editor' ), count, list.label )
						),
					el(
						'div',
						{ className: 'df-block-items__head' },
						el( 'span', { className: 'df-block-items__title' }, sprintf( /* translators: 1: item label, 2: count, 3: maximum */ __( '%1$s · %2$d of max. %3$d', 'df-editor' ), list.label, count, Number( list.max ) ) ),
						el( 'span', { className: 'df-block-items__help' }, __( 'Move, duplicate or remove with the buttons in each row.', 'df-editor' ) )
					),
					el( 'div', innerProps ),
					atMax
						? null
						: el( c.Button, { variant: 'secondary', className: 'df-block-items__add', icon: ROW_ICONS.plus, onClick: addItem }, sprintf( /* translators: %s: item label */ __( 'Add %s', 'df-editor' ), list.label ) )
				)
				: list
					? el( 'div', Object.assign( {}, innerProps, { className: 'df-block-items__list is-hidden' } ) )
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
				legacy && hasEditor && c.ToolbarButton
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

			/* ---------- sidebar ---------- */
			const upgrade = async () => {
				try {
					const res = await apiFetch( {
						path: data.legacyPath || '/df/v1/blocks/legacy-content',
						method: 'POST',
						data: { name, post_id: postId, attributes: previewAttributes() },
					} );
					const nextOpts = Object.assign( {}, ownOpts );
					delete nextOpts.count;
					setAttributes( { fields: isObject( res.fields ) ? res.fields : {}, content: {}, options: nextOpts } );
					if ( list ) {
						const blocks = ( Array.isArray( res.items ) ? res.items : [] ).map( ( f ) => wp.blocks.createBlock( list.name, { itemId: newItemId(), fields: isObject( f ) ? f : {} } ) );
						registry.dispatch( 'core/block-editor' ).replaceInnerBlocks( clientId, blocks, false );
					}
					registry.dispatch( 'core/notices' ).createSuccessNotice( __( 'Texts moved into the block. Save the page to keep them.', 'df-editor' ), { type: 'snackbar', id: 'df-upgrade' } );
				} catch ( e ) {
					registry.dispatch( 'core/notices' ).createErrorNotice( __( 'The texts could not be loaded.', 'df-editor' ), { type: 'snackbar', id: 'df-upgrade' } );
				}
			};

			const contentPanel = contentMode
				? el(
					c.PanelBody,
					{ title: __( 'Content', 'df-editor' ), initialOpen: true, className: 'df-panel-content' },
					( def.fields || [] ).length
						? el( FieldList, { fields: def.fields, values: attributes.fields, onChange: ( next ) => setAttributes( { fields: next } ) } )
						: null,
					list
						? el(
							'div',
							{ className: 'df-items-summary' },
							el( 'p', { className: 'df-block-help' }, sprintf( /* translators: 1: count, 2: item label, 3: minimum, 4: maximum */ __( '%1$d × %2$s (%3$d–%4$d). Select one in the canvas or in List View to edit it.', 'df-editor' ), count, list.label, Number( list.min ), Number( list.max ) ) ),
							atMax ? null : el( c.Button, { variant: 'secondary', icon: 'plus', onClick: addItem }, sprintf( /* translators: %s: item label */ __( 'Add %s', 'df-editor' ), list.label ) )
						)
						: null
				)
				: el(
					c.PanelBody,
					{ title: __( 'Texts', 'df-editor' ), initialOpen: true },
					el( 'p', { className: 'df-block-help df-block-help--dark' }, __( 'This section still uses texts edited on the page (DF Inhalt).', 'df-editor' ) ),
					hasEditor
						? el(
							c.Button,
							{ variant: 'secondary', icon: 'edit', className: 'df-block-inhalt-link', onClick: onEditTexts },
							__( 'Edit texts', 'df-editor' ),
							el( 'span', { className: 'df-block-sr' }, ' ' + __( '(opens in a new tab)', 'df-editor' ) )
						)
						: null,
					el( 'p', { className: 'df-block-help' }, __( 'Or move the current texts into the block and edit everything here in the sidebar:', 'df-editor' ) ),
					el( c.Button, { variant: 'primary', onClick: upgrade }, __( 'Move texts into the block', 'df-editor' ) )
				);

			const layoutPanel = el(
				c.PanelBody,
				{ title: __( 'Layout', 'df-editor' ), initialOpen: ! contentMode },
				variants.length
					? el( Select, { label: __( 'Variant', 'df-editor' ), value: String( variant ), options: toSelectOptions( variants ), onChange: ( v ) => setAttributes( { variant: v } ) } )
					: null,
				SCHEMES.length ? el( SchemePicker, { clientId, value: scheme, onChange: ( v ) => setAttributes( { scheme: v } ) } ) : null,
				SPACING.length
					? el(
						'div',
						{ className: 'df-block-row' },
						el( Select, { label: __( 'Spacing top', 'df-editor' ), value: String( spacingTop ), options: toSelectOptions( SPACING ), onChange: ( v ) => setAttributes( { spacingTop: v } ) } ),
						el( Select, { label: __( 'Spacing bottom', 'df-editor' ), value: String( spacingBottom ), options: toSelectOptions( SPACING ), onChange: ( v ) => setAttributes( { spacingBottom: v } ) } )
					)
					: null,
				WIDTHS.length ? el( Select, { label: __( 'Width', 'df-editor' ), value: String( width ), options: toSelectOptions( WIDTHS ), onChange: ( v ) => setAttributes( { width: v } ) } ) : null,
				legacy && list
					? el( c.RangeControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Number of items', 'df-editor' ),
						help: sprintf( /* translators: 1: minimum, 2: maximum */ __( 'Between %1$d and %2$d.', 'df-editor' ), Number( list.min ), Number( list.max ) ),
						min: Number( list.min ) || 1,
						max: Number( list.max ) || 12,
						step: 1,
						value: Number( opts.count ) || Number( list.default_count ) || Number( list.min ) || 1,
						onChange: ( n ) => {
							const min = Number( list.min ) || 1;
							const max = Number( list.max ) || 12;
							setOption( 'count', String( Math.max( min, Math.min( max, parseInt( n, 10 ) || Number( list.default_count ) || min ) ) ) );
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
					{ title: __( 'Options', 'df-editor' ), initialOpen: false },
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

			// Rendered into the core "Advanced" panel (InspectorControls group "advanced").
			const advancedPanel = el(
				Fragment,
				null,
				el( Text, {
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
				el( Text, {
					label: __( 'Section ID', 'df-editor' ),
					value: dfId,
					readOnly: true,
					className: 'df-block-readonly',
					help: contentMode
						? __( 'Fixed ID of this section (HTML id).', 'df-editor' )
						: __( 'Fixed ID that links this section to its texts and images. Texts and images are edited on the page with DF Inhalt.', 'df-editor' ),
					onChange: () => {},
				} )
			);

			return el(
				Fragment,
				null,
				variantMenu,
				textsButton,
				el( be.InspectorControls, null, contentPanel, layoutPanel, optionsPanel ),
				el( be.InspectorControls, { group: 'advanced' }, advancedPanel ),
				el( 'div', blockProps, header, hint, canvas, strip )
			);
		};
	}

	/* ------------------------------------------------------------------ */
	/* Item block                                                          */
	/* ------------------------------------------------------------------ */

	function makeItemEdit( parentType ) {
		return function DfItemEdit( props ) {
			const attributes = props.attributes || {};
			const setAttributes = props.setAttributes;
			const clientId = props.clientId;
			const def = SECTIONS[ parentType ] || {};
			const list = def.list || { label: 'Item', fields: [], min: 0, max: 99 };
			const registry = wp.data.useRegistry ? wp.data.useRegistry() : wp.data;
			const info = useSelect(
				( select ) => {
					const s = select( 'core/block-editor' );
					const parent = s.getBlockRootClientId( clientId );
					return { parent, index: s.getBlockIndex( clientId ), count: parent ? s.getBlockOrder( parent ).length : 0 };
				},
				[ clientId ]
			);
			const isPreviewMode = useSelect( ( select ) => !! ( select( 'core/block-editor' ).getSettings() || {} ).isPreviewMode, [] );
			const n = info.index + 1;
			const fields = isObject( attributes.fields ) ? attributes.fields : null;
			const itemId = String( attributes.itemId || '' );

			/* Defaults for items added through the inserter / List View; unique itemId (copies get a new one). */
			useEffect( () => {
				if ( isPreviewMode ) {
					return;
				}
				const patch = {};
				if ( ! fields ) {
					patch.fields = defaultItem( def, n );
				}
				const owner = itemId ? itemOwners.get( itemId ) : null;
				const ownerBlock = owner && owner !== clientId ? registry.select( 'core/block-editor' ).getBlock( owner ) : null;
				if ( ! itemId || ( ownerBlock && String( ( ownerBlock.attributes || {} ).itemId || '' ) === itemId ) ) {
					patch.itemId = newItemId();
				}
				itemOwners.set( patch.itemId || itemId, clientId );
				if ( Object.keys( patch ).length ) {
					markNotPersistent( registry );
					setAttributes( patch );
				}
			}, [ itemId, clientId, isPreviewMode ] );

			const values = fields || {};
			const textField = ( list.fields || [] ).find( ( f ) => f.type === 'text' && stripTags( values[ f.key ] ) !== '' );
			const imageField = ( list.fields || [] ).find( ( f ) => f.type === 'image' );
			const title = textField ? stripTags( values[ textField.key ] ) : sprintf( '%s %d', list.label, n );
			const other = ( list.fields || [] ).find( ( f ) => ( f.type === 'text' || f.type === 'richtext' ) && ( ! textField || f.key !== textField.key ) && stripTags( values[ f.key ] ) !== '' );
			const summary = other ? stripTags( values[ other.key ] ).slice( 0, 90 ) : '';
			const imgId = imageField ? Math.max( 0, imageId( values[ imageField.key ] ) ) : 0;
			const media = useAttachment( imgId );
			const thumb = media && media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail ? media.media_details.sizes.thumbnail.source_url : ( media ? media.source_url : '' );

			const blockProps = be.useBlockProps( { className: 'df-item-row' } );
			const d = registry.dispatch( 'core/block-editor' );
			const canRemove = info.count > ( Number( list.min ) || 0 );
			const canAdd = info.count < ( Number( list.max ) || 99 );
			const itemName = sprintf( '%s %d', list.label, n );
			const sectionLabel = String( def.label || parentType );

			const panel = el(
				c.PanelBody,
				{ title: itemName, initialOpen: true, className: 'df-panel-item' },
				// Breadcrumb back to the section ("FAQ › Frage 3").
				info.parent
					? el(
						'p',
						{ className: 'df-item-crumb' },
						el( c.Button, { variant: 'link', className: 'df-item-back', onClick: () => d.selectBlock( info.parent ) }, sectionLabel ),
						el( 'span', { 'aria-hidden': 'true' }, ' › ' ),
						el( 'span', null, itemName )
					)
					: null,
				el( FieldList, { fields: list.fields, values, n, onChange: ( next ) => setAttributes( { fields: next } ) } ),
				el(
					'div',
					{ className: 'df-item-actions' },
					el( c.Button, { variant: 'secondary', icon: 'admin-page', disabled: ! canAdd, onClick: () => d.duplicateBlocks( [ clientId ] ) }, __( 'Duplicate', 'df-editor' ) ),
					el( c.Button, { variant: 'secondary', isDestructive: true, icon: 'trash', disabled: ! canRemove, onClick: () => d.removeBlock( clientId ) }, __( 'Remove', 'df-editor' ) )
				),
				canRemove ? null : el( 'p', { className: 'df-block-help' }, sprintf( /* translators: 1: minimum, 2: item label */ __( 'At least %1$d × %2$s.', 'df-editor' ), Number( list.min ), list.label ) )
			);

			/* Inline row actions (visible on hover / selection): move, duplicate, remove. */
			const act = ( iconName, label, disabled, onClick ) =>
				el( c.Button, {
					size: 'small',
					icon: iconName,
					label,
					showTooltip: true,
					disabled,
					className: 'df-item-row__btn',
					onClick: ( e ) => {
						e.stopPropagation();
						onClick();
					},
				} );
			const actions = info.parent
				? el(
					'span',
					{ className: 'df-item-row__actions' },
					act( ROW_ICONS.up, sprintf( /* translators: %s: item name, e.g. "Frage 3" */ __( 'Move %s up', 'df-editor' ), itemName ), info.index <= 0, () => d.moveBlocksUp( [ clientId ], info.parent ) ),
					act( ROW_ICONS.down, sprintf( /* translators: %s: item name */ __( 'Move %s down', 'df-editor' ), itemName ), info.index >= info.count - 1, () => d.moveBlocksDown( [ clientId ], info.parent ) ),
					act( ROW_ICONS.copy, sprintf( /* translators: %s: item name */ __( 'Duplicate %s', 'df-editor' ), itemName ), ! canAdd, () => d.duplicateBlocks( [ clientId ] ) ),
					act( ROW_ICONS.trash, sprintf( /* translators: %s: item name */ __( 'Remove %s', 'df-editor' ), itemName ), ! canRemove, () => d.removeBlock( clientId ) )
				)
				: null;

			return el(
				Fragment,
				null,
				el( be.InspectorControls, null, panel ),
				el(
					'div',
					blockProps,
					el( 'span', { className: 'df-item-row__n', 'aria-hidden': 'true' }, String( n ) ),
					thumb ? el( 'img', { className: 'df-item-row__thumb', src: thumb, alt: '' } ) : null,
					el( 'span', { className: 'df-item-row__text' }, el( 'strong', { className: 'df-item-row__title' }, title ), summary ? el( 'span', { className: 'df-item-row__summary' }, summary ) : null ),
					actions
				)
			);
		};
	}

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	const SUPPORTS = { html: false, className: false, customClassName: false, reusable: false, customCSS: false, visibility: false };

	function clientSettings( name ) {
		const type = typeOf( name );
		const def = SECTIONS[ type ];
		if ( def ) {
			return {
				edit: makeEdit( name ),
				// Inner blocks (list items) are part of the block comment; the section HTML is rendered by PHP.
				save: () => el( be.InnerBlocks.Content ),
				icon,
				example: { attributes: { preview: true } },
			};
		}
		const parentType = type.replace( /-item$/, '' );
		return {
			edit: makeItemEdit( parentType ),
			save: () => null,
			icon: itemIcon,
			__experimentalLabel: ( attrs, ctx ) => {
				const f = attrs && isObject( attrs.fields ) ? attrs.fields : {};
				const pdef = SECTIONS[ parentType ] || {};
				const first = ( ( pdef.list && pdef.list.fields ) || [] ).find( ( x ) => x.type === 'text' && stripTags( f[ x.key ] ) !== '' );
				return first && ctx && ctx.context === 'list-view' ? stripTags( f[ first.key ] ).slice( 0, 40 ) : undefined;
			},
		};
	}

	function register( name ) {
		const client = clientSettings( name );
		const existing = wp.blocks.getBlockType( name );
		if ( existing ) {
			// Already registered (e.g. auto-registered server block): re-register with our UI.
			const prev = wp.blocks.unregisterBlockType( name ) || existing;
			const merged = Object.assign( {}, prev, client );
			delete merged.name;
			wp.blocks.registerBlockType( name, merged );
			return;
		}
		// Server definitions (title, category, attributes, supports, parent …) are merged in by wp.blocks.
		wp.blocks.registerBlockType( name, client );
	}

	/** Last resort when the server definition never reached wp.blocks: minimal client metadata. */
	function registerFallback( name ) {
		const type = typeOf( name );
		const def = SECTIONS[ type ];
		if ( def ) {
			wp.blocks.registerBlockType( name, Object.assign( clientSettings( name ), {
				apiVersion: 3,
				title: String( def.label || type ),
				description: String( def.description || '' ),
				category: 'df-sections',
				supports: Object.assign( { multiple: true }, SUPPORTS ),
				usesContext: [ 'postId' ],
				allowedBlocks: def.list ? [ def.list.name ] : undefined,
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
					fields: { type: 'object' },
					content: { type: 'object', default: {} },
					preview: { type: 'boolean', default: false },
				},
			} ) );
			return;
		}
		const parentType = type.replace( /-item$/, '' );
		const pdef = SECTIONS[ parentType ] || {};
		wp.blocks.registerBlockType( name, Object.assign( clientSettings( name ), {
			apiVersion: 3,
			title: String( ( pdef.list && pdef.list.label ) || 'Item' ),
			category: 'df-sections',
			parent: [ 'df/' + parentType ],
			supports: SUPPORTS,
			attributes: { itemId: { type: 'string', default: '' }, fields: { type: 'object' } },
		} ) );
	}

	function blockNames() {
		const names = [];
		Object.keys( SECTIONS ).forEach( ( type ) => {
			names.push( 'df/' + type );
			if ( SECTIONS[ type ] && SECTIONS[ type ].list && SECTIONS[ type ].list.name ) {
				names.push( SECTIONS[ type ].list.name );
			}
		} );
		return names;
	}

	function registerAll( final ) {
		blockNames().forEach( ( name ) => {
			const bt = wp.blocks.getBlockType( name );
			if ( bt && bt.edit && ( bt.edit.name === 'DfSectionEdit' || bt.edit.name === 'DfItemEdit' ) ) {
				return; // done already.
			}
			try {
				// Merges the bootstrapped server definition (or re-registers an existing one).
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
