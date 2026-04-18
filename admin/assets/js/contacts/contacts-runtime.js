/**
 * Contacts runtime bootstrap (admin).
 *
 * Initializes a shared runtime object for Contacts admin modules:
 * - Reads widget config (instance id, nonce, ajaxurl, tenant scope).
 * - Ensures required UI containers exist (popover and export modal).
 * - Registers shared utilities (escapeHtml, pad2, multiselect toggler).
 * - Provides time helpers for displaying UTC timestamps in site time.
 * - Creates a shared state bag used by other Contacts scripts.
 *
 * Security:
 * - Avoid HTML string concatenation and HTML sinks (no innerHTML/append(html)).
 * - Construct DOM nodes explicitly and set user-controlled values via textContent.
 *
 * @package NXTCC
 */

/* global jQuery, window, document */

jQuery( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Widget root and configuration.
	// -------------------------------------------------------------------------

	const $widget = $( '.nxtcc-contacts-widget' );

	if ( ! $widget.length ) {
		// Widget not present on this screen.
		return;
	}

	const instanceId = String( $widget.data( 'instance' ) || '' ).trim();

	/**
	 * Read a string safely from a possibly-missing object.
	 *
	 * @param {*} obj Object.
	 * @param {string} key Key.
	 * @return {string} Value or empty string.
	 */
	function readObjString( obj, key ) {
		if ( ! obj || typeof obj !== 'object' ) {
			return '';
		}
		if ( obj[ key ] === undefined || obj[ key ] === null ) {
			return '';
		}
		return String( obj[ key ] ).trim();
	}

	const data = window.NXTCC_ContactsData && typeof window.NXTCC_ContactsData === 'object'
		? window.NXTCC_ContactsData
		: null;

	// Prefer localized nonce; fallback to widget attribute if needed.
	const localizedNonce = readObjString( data, 'nonce' );
	const widgetNonce    = String(
		$widget.data( 'nonce' ) || $widget.attr( 'data-nonce' ) || ''
	).trim();
	const nonce          = localizedNonce || widgetNonce || '';

	const ajaxurl =
		readObjString( data, 'ajaxurl' ) ||
		( 'string' === typeof window.ajaxurl ? String( window.ajaxurl ).trim() : '' );

	const currentUserEmail =
		readObjString( data, 'current_user' ) ||
		( typeof $widget.data( 'currentUser' ) !== 'undefined'
			? String( $widget.data( 'currentUser' ) ).trim()
			: '' ) ||
		null;

	const tenantKey =
		readObjString( data, 'tenant_key' ) ||
		( typeof $widget.data( 'tenant' ) !== 'undefined'
			? String( $widget.data( 'tenant' ) ).trim()
			: '' ) ||
		'';

	const storageScopeKey = [
		'nxtcc_contacts_cols_v3',
		tenantKey || 'no-tenant',
		currentUserEmail || 'anon',
	].join( '::' );

	// -------------------------------------------------------------------------
	// Build / extend runtime object once.
	// -------------------------------------------------------------------------

	const R = window.NXTCC_ContactsRuntime = window.NXTCC_ContactsRuntime || {};

	R.$                = $;
	R.$widget          = $widget;
	R.ajaxurl          = ajaxurl;
	R.nonce            = nonce;
	R.instanceId       = instanceId;
	R.currentUserEmail = currentUserEmail;
	R.tenantKey        = tenantKey;
	R.storageScopeKey  = storageScopeKey;

	// -------------------------------------------------------------------------
	// DOM helpers (no HTML strings).
	// -------------------------------------------------------------------------

	/**
	 * Create a DOM element and assign safe properties/attributes.
	 *
	 * Supported attributes:
	 * - className: string
	 * - text: string (textContent)
	 * - id: string
	 * - style: object (style key/value)
	 * - other keys: setAttribute
	 *
	 * @param {string} tag Tag name.
	 * @param {Object} attrs Attributes.
	 * @return {HTMLElement} Element.
	 */
	function el( tag, attrs ) {
		const node = document.createElement( tag );

		if ( attrs && typeof attrs === 'object' ) {
			Object.keys( attrs ).forEach( ( key ) => {
				const value = attrs[ key ];

				if ( value === undefined || value === null ) {
					return;
				}

				if ( key === 'className' ) {
					node.className = String( value );
					return;
				}

				if ( key === 'text' ) {
					node.textContent = String( value );
					return;
				}

				if ( key === 'style' && value && typeof value === 'object' ) {
					Object.keys( value ).forEach( ( sk ) => {
						node.style[ sk ] = String( value[ sk ] );
					} );
					return;
				}

				if ( key in node ) {
					try {
						node[ key ] = value;
						return;
					} catch ( err ) {
						// No-op.
					}
				}

				node.setAttribute( key, String( value ) );
			} );
		}

		return node;
	}

	/**
	 * Ensure the columns popover container exists and is attached to body.
	 *
	 * @return {void}
	 */
	function ensureColumnsPopover() {
		const existing = document.getElementById( 'nxtcc-contacts-columns-popover' );

		if ( existing ) {
			return;
		}

		const pop = el( 'div', {
			id: 'nxtcc-contacts-columns-popover',
			className: 'nxtcc-popover',
			style: {
				display: 'none',
				position: 'absolute',
				zIndex: '999',
			},
		} );

		document.body.appendChild( pop );
	}

	/**
	 * Ensure the export modal exists and is attached to body.
	 *
	 * @return {void}
	 */
	function ensureExportModal() {
		const existing = document.getElementById( 'nxtcc-export-modal' );

		if ( existing ) {
			return;
		}

		const modal = el( 'div', {
			id: 'nxtcc-export-modal',
			className: 'nxtcc-modal',
			style: { display: 'none' },
		} );

		const overlay = el( 'div', { className: 'nxtcc-modal-overlay' } );

		const content = el( 'div', {
			className: 'nxtcc-modal-content',
			style: { maxWidth: '520px' },
		} );

		const header = el( 'div', { className: 'nxtcc-modal-header' } );
		const title  = el( 'span', { text: 'Export Contacts' } );

		const closeBtn = el( 'button', {
			type: 'button',
			className: 'nxtcc-modal-close',
			id: 'nxtcc-export-close',
			'aria-label': 'Close',
			text: '×',
		} );

		header.appendChild( title );
		header.appendChild( closeBtn );

		const row   = el( 'div', { className: 'nxtcc-form-row' } );
		const label = el( 'label', { text: 'What would you like to export?' } );

		const summary = el( 'div', {
			id: 'nxtcc-export-summary',
			style: { fontSize: '14px' },
		} );

		row.appendChild( label );
		row.appendChild( summary );

		const footer = el( 'div', { className: 'nxtcc-modal-footer' } );

		const cancelBtn = el( 'button', {
			type: 'button',
			className: 'nxtcc-btn nxtcc-btn-outline',
			id: 'nxtcc-export-cancel',
			text: 'Cancel',
		} );

		const selectedBtn = el( 'button', {
			type: 'button',
			className: 'nxtcc-btn nxtcc-btn-outline',
			id: 'nxtcc-export-selected',
			text: 'Selected',
		} );

		const allBtn = el( 'button', {
			type: 'button',
			className: 'nxtcc-btn nxtcc-btn-green',
			id: 'nxtcc-export-all',
			text: 'All (filtered)',
		} );

		footer.appendChild( cancelBtn );
		footer.appendChild( selectedBtn );
		footer.appendChild( allBtn );

		content.appendChild( header );
		content.appendChild( row );
		content.appendChild( footer );

		modal.appendChild( overlay );
		modal.appendChild( content );

		document.body.appendChild( modal );
	}

	ensureColumnsPopover();
	ensureExportModal();

	// -------------------------------------------------------------------------
	// Utils (shared).
	// -------------------------------------------------------------------------

	/**
	 * Escape text for safe HTML embedding.
	 *
	 * Note: Most modules should prefer textContent; this exists for places
	 * where escaped strings are needed for attributes or server-side templates.
	 *
	 * @param {string} str Input string.
	 * @return {string} Escaped string.
	 */
	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, ( m ) => {
			return (
				{
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;',
				}[ m ] || m
			);
		} );
	}

	/**
	 * Pad a number to two digits.
	 *
	 * @param {number} n Number.
	 * @return {string} Padded string.
	 */
	function pad2( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	/**
	 * Make a <select multiple> behave like a toggle list on mousedown.
	 *
	 * @param {jQuery} $select Select element wrapped in jQuery.
	 * @return {void}
	 */
	function enableMultiSelectToggle( $select ) {
		$select
			.off( 'mousedown.nxtccToggle' )
			.on( 'mousedown.nxtccToggle', 'option', function ( e ) {
				e.preventDefault();

				const opt = this;

				if ( opt.disabled ) {
					return;
				}

				const sel       = opt.parentElement;
				const scrollTop = sel.scrollTop;

				opt.selected  = ! opt.selected;
				sel.scrollTop = scrollTop;

				$( sel ).trigger( 'change' );
				return false;
			} );
	}

	R.utils                         = R.utils || {};
	R.utils.escapeHtml              = escapeHtml;
	R.utils.pad2                    = pad2;
	R.utils.enableMultiSelectToggle = enableMultiSelectToggle;

	// -------------------------------------------------------------------------
	// Site timezone formatting.
	// -------------------------------------------------------------------------

	const SITE_TZ =
		readObjString( data, 'site_tz' ) ||
		readObjString( data, 'timezone' ) ||
		null;

	const rawOffset          = data && data.site_tz_offset_min !== undefined ? data.site_tz_offset_min : null;
	const SITE_TZ_OFFSET_MIN = Number.isFinite( Number( rawOffset ) ) ? Number( rawOffset ) : null;

	/**
	 * Format a Date in an IANA timezone using AM/PM.
	 *
	 * @param {Date} dateObj Date object.
	 * @param {string} tz IANA timezone.
	 * @return {string|null} Formatted string or null.
	 */
	function formatDateAMPMInTZ( dateObj, tz ) {
		try {
			const fmt = new Intl.DateTimeFormat( 'en-US', {
				timeZone: tz,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: 'numeric',
				minute: '2-digit',
				hour12: true,
			} );

			const parts       = fmt.formatToParts( dateObj ).reduce( ( acc, p ) => {
				acc[ p.type ] = p.value;
				return acc;
			}, {} );

			const ap = parts.dayPeriod || '';
			return `${ parts.year } - ${ parts.month } - ${ parts.day } ${ parts.hour }:${ parts.minute } ${ ap }`;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Format a Date using a fixed offset in minutes (relative to UTC).
	 *
	 * @param {Date} dateObj Date object.
	 * @param {number} offsetMin Offset minutes.
	 * @return {string} Formatted string.
	 */
	function formatDateAMPMWithOffset( dateObj, offsetMin ) {
		const ms = dateObj.getTime() + ( offsetMin || 0 ) * 60 * 1000;
		const d  = new Date( ms );

		const y  = d.getUTCFullYear();
		const mo = pad2( d.getUTCMonth() + 1 );
		const da = pad2( d.getUTCDate() );

		const hr24 = d.getUTCHours();
		const mins = pad2( d.getUTCMinutes() );

		const ap = hr24 >= 12 ? 'PM' : 'AM';
		let hr12 = hr24 % 12;

		if ( hr12 === 0 ) {
			hr12 = 12;
		}

		return `${ y } - ${ mo } - ${ da } ${ hr12 }:${ mins } ${ ap }`;
	}

	/**
	 * Convert a MySQL UTC datetime string to a site-time AM/PM string.
	 *
	 * @param {string} mysqlUtcStr UTC MySQL datetime string.
	 * @return {string} Human readable date.
	 */
	function formatCreatedAtUTCToSiteAMPM( mysqlUtcStr ) {
		if ( ! mysqlUtcStr ) {
			return '';
		}

		const iso     = String( mysqlUtcStr ).replace( ' ', 'T' ) + 'Z';
		const utcDate = new Date( iso );

		if ( Number.isNaN( utcDate.getTime() ) ) {
			return String( mysqlUtcStr );
		}

		if ( SITE_TZ ) {
			const s = formatDateAMPMInTZ( utcDate, SITE_TZ );
			if ( s ) {
				return s;
			}
		}

		if ( SITE_TZ_OFFSET_MIN !== null ) {
			return formatDateAMPMWithOffset( utcDate, SITE_TZ_OFFSET_MIN );
		}

		try {
			return new Intl.DateTimeFormat( 'en-US', {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: 'numeric',
				minute: '2-digit',
				hour12: true,
			} ).format( utcDate );
		} catch ( e ) {
			return String( mysqlUtcStr );
		}
	}

	/**
	 * Build a compact timestamp (YYYYMMDDHHMM) in the site timezone/offset.
	 *
	 * @return {string} Timestamp.
	 */
	function nowInSiteTzStamp() {
		const nowUtc = new Date();

		let formatted = null;

		if ( SITE_TZ ) {
			formatted = formatDateAMPMInTZ( nowUtc, SITE_TZ );
		} else if ( SITE_TZ_OFFSET_MIN !== null ) {
			formatted = formatDateAMPMWithOffset( nowUtc, SITE_TZ_OFFSET_MIN );
		}

		if ( formatted ) {
			return formatted
				.replace( /[-: ]/g, '' )
				.replace( /[AP]M$/, '' )
				.slice( 0, 12 );
		}

		return new Date()
			.toISOString()
	}

	R.time                              = R.time || {};
	R.time.SITE_TZ                      = SITE_TZ;
	R.time.SITE_TZ_OFFSET_MIN           = SITE_TZ_OFFSET_MIN;
	R.time.formatCreatedAtUTCToSiteAMPM = formatCreatedAtUTCToSiteAMPM;
	R.time.nowInSiteTzStamp             = nowInSiteTzStamp;

	// -------------------------------------------------------------------------
	// Shared state.
	// -------------------------------------------------------------------------

	R.state = R.state || {
		allContacts: [],
		allGroups: [],
		allCountryCodes: [],
		creatorsServer: [],

		filterGroup: '',
		filterCountry: '',
		filterName: '',
		filterCreatedBy: '',
		filterCreatedFrom: '',
		filterCreatedTo: '',
		filterSubscription: '',

		currentPage: 1,
		perPage: 100,
		hasMore: false,
		isLoadingPage: false,

		columns: [],
	};

	// Widget banner warning implies a disconnected tenant/config state.
	R.state.hasConnection = ! $widget.find( '.nxtcc-banner-warning' ).length;
} );

