/**
 * Contacts import/export (admin).
 *
 * Notes:
 * - Avoids HTML string concatenation and jQuery .html()/.append() with HTML strings.
 * - Builds UI with DOM node construction + textContent to prevent XSS.
 *
 * @package NXTCC
 */

/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	const R = window.NXTCC_ContactsRuntime;
	if ( ! R ) {
		return;
	}

	const ajaxurl    = R.ajaxurl;
	const nonce      = R.nonce;
	const instanceId = R.instanceId;

	const S  = R.state || {};
	const U  = R.utils || {};
	const TM = R.time || {};

	const escapeHtml = U.escapeHtml || function ( s ) {
		const div       = document.createElement( 'div' );
		div.textContent = null == s ? '' : String( s );
		return div.innerHTML;
	};

	const enableMultiSelectToggle = U.enableMultiSelectToggle || function () {};

	function hasConnectionNow() {
		return Boolean( R.state && R.state.hasConnection );
	}

	/**
	 * Create an element with attributes and optional text.
	 *
	 * @param {string} tag   Tag name.
	 * @param {Object} attrs Attributes.
	 * @param {string} text  Text content.
	 * @return {Element} Element.
	 */
	function el( tag, attrs, text ) {
		const node = document.createElement( tag );

		if ( attrs && 'object' === typeof attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( null == attrs[ k ] ) {
					return;
				}
				node.setAttribute( k, String( attrs[ k ] ) );
			} );
		}

		if ( null != text ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Remove all children from a DOM node.
	 *
	 * @param {Element} node Node.
	 * @return {void}
	 */
	function emptyNode( node ) {
		if ( ! node ) {
			return;
		}
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Safe append child node to a DOM element.
	 *
	 * @param {Element} parent Parent.
	 * @param {Node}    child  Child.
	 * @return {void}
	 */
	function safeAppend( parent, child ) {
		if ( parent && child && parent.appendChild ) {
			parent.appendChild( child );
		}
	}

	/**
	 * Convert a value into a trimmed string.
	 *
	 * @param {*} v Value.
	 * @return {string} String.
	 */
	function toTrimmedString( v ) {
		return null == v ? '' : String( v ).trim();
	}

	/**
	 * Insert node after target node.
	 *
	 * @param {Element} target Target element.
	 * @param {Element} node   Node to insert.
	 * @return {void}
	 */
	function insertAfterNode( target, node ) {
		if ( ! target || ! node ) {
			return;
		}

		if ( 'function' === typeof target.insertAdjacentElement ) {
			target.insertAdjacentElement( 'afterend', node );
			return;
		}

		// Fallback (older engines).
		if ( target.parentNode ) {
			target.parentNode.appendChild( node );
		}
	}

	/**
	 * Navigate using an anchor element (avoid window.location usage).
	 *
	 * @param {string} url URL.
	 * @return {void}
	 */
	function navigateWithAnchor( url ) {
		const a  = document.createElement( 'a' );
		a.href   = String( url );
		a.rel    = 'noopener';
		a.target = '_self';
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			a.remove();
		}, 50 );
	}

	// Time helpers live under R.time in your runtime (fallbacks included).
	const nowInSiteTzStamp =
		'function' === typeof TM.nowInSiteTzStamp
			? TM.nowInSiteTzStamp
			: 'function' === typeof U.nowInSiteTzStamp
				? U.nowInSiteTzStamp
				: function () {
					const d   = new Date();
					const pad = ( n ) => ( n < 10 ? '0' + n : String( n ) );
					return (
						String( d.getFullYear() ) +
						pad( d.getMonth() + 1 ) +
						pad( d.getDate() ) +
						pad( d.getHours() ) +
						pad( d.getMinutes() )
					);
				};

	const formatCreatedAt =
		'function' === typeof TM.formatCreatedAtUTCToSiteAMPM
			? TM.formatCreatedAtUTCToSiteAMPM
			: 'function' === typeof U.formatCreatedAtUTCToSiteAMPM
				? U.formatCreatedAtUTCToSiteAMPM
				: function ( createdAt ) {
					if ( ! createdAt ) {
						return '';
					}

					const s = String( createdAt ).trim();
					const m = s.match(
						/  ^(\d{4}) -(\d{2}) -(\d{2})\s +(\d{2}):(\d{2})( ?:  :(\d{2})) ?$ /
					);

					let d = null;

					if ( m ) {
						d = new Date(
							Date.UTC(
								parseInt( m[ 1 ], 10 ),
								parseInt( m[ 2 ], 10 ) - 1,
								parseInt( m[ 3 ], 10 ),
								parseInt( m[ 4 ], 10 ),
								parseInt( m[ 5 ], 10 ),
								parseInt( m[ 6 ] || '0', 10 )
							)
						);
					} else {
						const maybe = new Date( s );
						d           = isNaN( maybe.getTime() ) ? null : maybe;
					}

					if ( ! d ) {
						return s;
					}

					return d.toLocaleString( undefined, {
						year: 'numeric',
						month: '2-digit',
						day: '2-digit',
						hour: '2-digit',
						minute: '2-digit',
						hour12: true,
					} );
				};

	// ==========================================================
	// Small helpers.
	// ==========================================================
	function safeParseJSON( maybeJson, fallback ) {
		if ( Array.isArray( maybeJson ) ) {
			return maybeJson;
		}
		if ( 'string' === typeof maybeJson ) {
			const s = maybeJson.trim();
			if ( ! s ) {
				return fallback;
			}
			try {
				return JSON.parse( s );
			} catch ( e ) {
				return fallback;
			}
		}
		if ( 'object' === typeof maybeJson && null !== maybeJson ) {
			return maybeJson;
		}
		return fallback;
	}

	function normalizeCustomFields( val ) {
		const parsed = safeParseJSON( val, [] );
		return Array.isArray( parsed ) ? parsed : [];
	}

	function csvEscape( v ) {
		const s = null == v ? '' : String( v );
		return /[",\r\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;
	}

	function getGroupName( id ) {
		const found = ( S.allGroups || [] ).find(
			( g ) => String( g.id ) === String( id )
		);
		return found ? found.group_name : String( id );
	}

	function readFiltersFromDOM() {
		const groupId      = toTrimmedString( $( '#nxtcc-filter-group' ).val() || '' );
		const country      = toTrimmedString( $( '#nxtcc-filter-country' ).val() || '' );
		const createdBy    = toTrimmedString( $( '#nxtcc-filter-created-by' ).val() || '' );
		const createdFrom  = toTrimmedString( $( '#nxtcc-filter-created-from' ).val() || '' );
		const createdTo    = toTrimmedString( $( '#nxtcc-filter-created-to' ).val() || '' );
		const subscription = toTrimmedString( $( '#nxtcc-filter-subscription' ).val() || '' ); // '' | '1' | '0'.
		const search       = toTrimmedString( $( '#nxtcc-filter-name' ).val() || '' );

		return {
			groupId: groupId,
			country: country,
			createdBy: createdBy,
			createdFrom: createdFrom,
			createdTo: createdTo,
			subscription: subscription,
			search: search,
		};
	}

	function currentFilterPayload() {
		const f   = readFiltersFromDOM();
		const sub =
			'0' === f.subscription || '1' === f.subscription
				? f.subscription
				: '';

		return {
			group_id: f.groupId,
			country: f.country,
			created_by: f.createdBy,
			created_from: f.createdFrom,
			created_to: f.createdTo,
			subscription: sub,
			search: f.search,

			// Legacy keys (harmless).
			filter_group: f.groupId,
			filter_country: f.country,
			filter_created_by: f.createdBy,
			filter_created_from: f.createdFrom,
			filter_created_to: f.createdTo,
			filter_subscription: sub,
		};
	}

	function getSelectedIds() {
		return $( '.nxtcc-contact-select:checked' )
			.map( function () {
				return $( this ).data( 'id' );
			} )
			.get()
			.map( ( x ) => parseInt( x, 10 ) )
			.filter( Boolean );
	}

	// ==========================================================
	// EXPORT.
	// ==========================================================
	function ensureExportModalExists() {
		if ( document.getElementById( 'nxtcc-export-modal' ) ) {
			return;
		}

		const modal = el( 'div', {
			class: 'nxtcc-modal',
			id: 'nxtcc-export-modal',
			style: 'display:none;',
		} );

		const overlay = el( 'div', { class: 'nxtcc-modal-overlay' } );
		const content = el( 'div', {
			class: 'nxtcc-modal-content',
			style: 'max-width:520px;',
		} );

		const header = el( 'div', { class: 'nxtcc-modal-header' } );
		safeAppend( header, el( 'span', null, 'Export Contacts' ) );
		safeAppend(
			header,
			el(
				'button',
				{
					type: 'button',
					class: 'nxtcc-modal-close',
					id: 'nxtcc-export-close',
					'aria-label': 'Close',
				},
				'×'
			)
		);

		const formRow = el( 'div', { class: 'nxtcc-form-row' } );
		safeAppend( formRow, el( 'label', null, 'What would you like to export?' ) );
		safeAppend(
			formRow,
			el( 'div', { id: 'nxtcc-export-summary', style: 'font-size:14px;' } )
		);

		const footer = el( 'div', { class: 'nxtcc-modal-footer' } );
		safeAppend(
			footer,
			el(
				'button',
				{
					type: 'button',
					class: 'nxtcc-btn nxtcc-btn-outline',
					id: 'nxtcc-export-cancel',
				},
				'Cancel'
			)
		);
		safeAppend(
			footer,
			el(
				'button',
				{
					type: 'button',
					class: 'nxtcc-btn nxtcc-btn-outline',
					id: 'nxtcc-export-selected',
				},
				'Selected'
			)
		);
		safeAppend(
			footer,
			el(
				'button',
				{
					type: 'button',
					class: 'nxtcc-btn nxtcc-btn-green',
					id: 'nxtcc-export-all',
				},
				'All (filtered)'
			)
		);

		safeAppend( content, header );
		safeAppend( content, formRow );
		safeAppend( content, footer );

		safeAppend( modal, overlay );
		safeAppend( modal, content );

		document.body.appendChild( modal );
	}

	ensureExportModalExists();

	function ensureExportButton() {
		if ( document.getElementById( 'nxtcc-bulk-export' ) ) {
			return;
		}

		const target = document.getElementById( 'nxtcc-bulk-edit-groups' );
		if ( ! target ) {
			return;
		}

		const btn = el(
			'button',
			{
				type: 'button',
				id: 'nxtcc-bulk-export',
				class: 'nxtcc-btn nxtcc-btn-outline',
			},
			'Export'
		);

		insertAfterNode( target, btn );
	}

	R.actions                    = R.actions || {};
	R.actions.ensureExportButton = ensureExportButton;

	function normalizeListRespForExport( resp ) {
		const rows =
			resp &&
			resp.success &&
			resp.data &&
			Array.isArray( resp.data.rows )
				? resp.data.rows
				: resp && resp.success && resp.data && Array.isArray( resp.data.contacts )
					? resp.data.contacts
					: [];

		const groupMap   = resp && resp.data && resp.data.group_map ? resp.data.group_map : {};
		const total      = Number( resp && resp.data ? resp.data.total || 0 : 0 );
		const pageNum    = Number( resp && resp.data ? resp.data.page || 1 : 1 );
		const perPageNum = Number( resp && resp.data ? resp.data.per_page || 100 : 100 );
		const hasMore    = pageNum * perPageNum < total;

		const normalized = rows.map( ( r ) => {
			const row    = { ...r };
			row.id       = Number( row.id || 0 );

			const idStr = String( row.id );
			if ( ! Array.isArray( row.groups ) ) {
				if ( Array.isArray( groupMap[ idStr ] ) ) {
					row.groups = groupMap[ idStr ].map( String );
				} else if ( 'string' === typeof row.group_ids && '' !== row.group_ids.trim() ) {
					row.groups = row.group_ids
						.split( ',' )
						.map( ( s ) => s.trim() )
						.filter( Boolean );
				} else {
					row.groups = [];
				}
			}

			row.custom_fields = normalizeCustomFields( row.custom_fields );
			return row;
		} );

		return { rows: normalized, hasMore: hasMore };
	}

	async function fetchAllPagesForExport( pageSize ) {
		const acc = [];
		let page  = 1;

		while ( true ) {
			const payload = {
				action: 'nxtcc_contacts_list',
				nonce: nonce,
				instance_id: instanceId,
				page: page,
				per_page: pageSize,
				...currentFilterPayload(),
			};

			let resp;
			try {
				resp = await $.post( ajaxurl, payload );
			} catch ( e ) {
				alert( 'Network error during export. Please try again.' );
				break;
			}

			if ( ! resp || ! resp.success ) {
				break;
			}

			const norm = normalizeListRespForExport( resp );
			acc.push( ...norm.rows );

			if ( ! norm.hasMore ) {
				break;
			}

			page += 1;
		}

		return acc;
	}

	function buildAndDownloadCSV( rows ) {
		const list = Array.isArray( rows ) ? rows.slice() : [];

		const visibleCols = ( S.columns || [] ).filter(
			( c ) => c && c.visible && 'checkbox' !== c.key && 'actions' !== c.key
		);

		const header = visibleCols.map( ( c ) => c.label );
		const lines  = [ header.map( csvEscape ).join( ',' ) ];

		list.forEach( function ( row ) {
			const vals = visibleCols.map( function ( col ) {
				const key = col.key;

				if ( 'groups' === key ) {
					return ( row.groups || [] )
						.map( ( gid ) => getGroupName( gid ) )
						.join( '|' );
				}

				if ( 'subscribed' === key ) {
					return '1' === String( row.is_subscribed ) ? 'Subscribed' : 'Unsubscribed';
				}

				if ( 'created_at' === key ) {
					return formatCreatedAt( row.created_at );
				}

				if ( 0 === String( key ).indexOf( 'custom__' ) ) {
					const f = ( row.custom_fields || [] ).find(
						( cf ) => cf && cf.label === col.label
					);
					return f && 'undefined' !== typeof f.value ? String( f.value ) : '';
				}

				return null != row[ key ] ? String( row[ key ] ) : '';
			} );

			lines.push( vals.map( csvEscape ).join( ',' ) );
		} );

		const csv  = '\uFEFF' + lines.join( '\r\n' );
		const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
		const url  = URL.createObjectURL( blob );

		const a    = document.createElement( 'a' );
		const ts   = nowInSiteTzStamp();
		a.href     = url;
		a.download = 'nxtcc - contacts - ' + ts + '.csv';

		document.body.appendChild( a );
		a.click();

		setTimeout( function () {
			URL.revokeObjectURL( url );
			a.remove();
		}, 800 );
	}

	function renderExportSummary( selectedCount, parts ) {
		const wrap = document.getElementById( 'nxtcc-export-summary' );
		if ( ! wrap ) {
			return;
		}

		emptyNode( wrap );

		const root = el( 'div', { style: 'line-height:1.45;' } );

		const row1 = el( 'div' );
		safeAppend( row1, el( 'b', null, 'Selected: ' ) );
		safeAppend( row1, document.createTextNode( String( selectedCount ) ) );

		const row2 = el( 'div' );
		safeAppend( row2, el( 'b', null, 'Filters: ' ) );
		safeAppend(
			row2,
			document.createTextNode( parts.length ? parts.join( ' • ' ) : 'None' )
		);

		const note1 = el( 'div', { style: 'margin-top:6px;color:#666;font-size:12.5px;' } );
		safeAppend( note1, document.createTextNode( 'Timestamps are exported in ' ) );
		safeAppend( note1, el( 'b', null, 'site time' ) );
		safeAppend( note1, document.createTextNode( ' with ' ) );
		safeAppend( note1, el( 'b', null, 'AM/PM' ) );
		safeAppend( note1, document.createTextNode( ' (stored in UTC).' ) );

		const note2 = el(
			'div',
			{ style: 'margin-top:2px;color:#888;font-size:12px;' },
			'"All (filtered)" fetches every server-filtered contact across pages.'
		);

		safeAppend( root, row1 );
		safeAppend( root, row2 );
		safeAppend( root, note1 );
		safeAppend( root, note2 );

		safeAppend( wrap, root );
	}

	$( document ).on( 'click', '#nxtcc-bulk-export', function ( e ) {
		e.preventDefault();

		if ( ! hasConnectionNow() ) {
			alert( 'Please connect WhatsApp Cloud API first.' );
			return;
		}

		const selectedIds = getSelectedIds();
		const f           = readFiltersFromDOM();
		const parts       = [];

		if ( f.search ) {
			parts.push( 'Search: "' + f.search + '"' );
		}
		if ( f.country ) {
			parts.push( 'Country: ' + f.country );
		}
		if ( f.createdBy ) {
			parts.push( 'Created By: ' + f.createdBy );
		}
		if ( f.createdFrom || f.createdTo ) {
			parts.push( 'Created: ' + ( f.createdFrom || '…' ) + ' → ' + ( f.createdTo || '…' ) );
		}
		if ( f.groupId ) {
			parts.push( 'Group: ' + getGroupName( f.groupId ) );
		}
		if ( '' !== f.subscription ) {
			parts.push(
				'Subscription: ' + ( '1' === f.subscription ? 'Subscribed' : 'Unsubscribed' )
			);
		}

		renderExportSummary( selectedIds.length, parts );

		$( '#nxtcc-export-selected' )
			.prop( 'disabled', 0 === selectedIds.length )
			.text( selectedIds.length ? 'Selected (' + selectedIds.length + ')' : 'Selected' );

		$( '#nxtcc-export-modal' ).fadeIn( 120 );
	} );

	$( document ).on(
		'click',
		'#nxtcc-export-close, #nxtcc-export-cancel, #nxtcc-export-modal .nxtcc-modal-overlay',
		function ( e ) {
			e.preventDefault();
			$( '#nxtcc-export-modal' ).fadeOut( 120 );
		}
	);

	$( document ).on( 'click', '#nxtcc-export-selected', function ( e ) {
		e.preventDefault();

		const selectedIds = getSelectedIds();
		if ( ! selectedIds.length ) {
			return;
		}

		const wanted = new Set( selectedIds.map( String ) );
		const rows   = ( S.allContacts || [] ).filter( ( r ) => wanted.has( String( r.id ) ) );

		if ( ! rows.length ) {
			alert(
				'Selected rows are not in the loaded list yet. Please load those pages first or use "All (filtered)".'
			);
			return;
		}

		$( '#nxtcc-export-modal' ).fadeOut( 120 );
		buildAndDownloadCSV( rows );
	} );

	$( document ).on( 'click', '#nxtcc-export-all', async function ( e ) {
		e.preventDefault();

		$( '#nxtcc-export-modal' ).fadeOut( 120 );

		const pageSize = 1000;
		const allRows  = await fetchAllPagesForExport( pageSize );

		buildAndDownloadCSV( allRows );
	} );

	// ==========================================================
	// IMPORT.
	// ==========================================================
	const importState = {
		token: '',
		hasHeader: true,
		delimiter: 'auto',
		defaultGroupIds: [],
		defaultSubscribed: true,
		mapping: [],
		stats: null,
		totalRows: 0,
		mode: 'skip',
		errorReportUrl: '',
		insertedTotal: 0,
		updatedTotal: 0,
		skippedTotal: 0,
	};

	$( document ).on( 'click', '#nxtcc-import-contacts-btn, #nxtcc-import-btn', function ( e ) {
		e.preventDefault();

		if ( ! hasConnectionNow() ) {
			alert( 'Please connect WhatsApp Cloud API first.' );
			return;
		}

		if ( ! $( '#nxtcc-import-modal' ).length ) {
			alert( 'Import modal markup (#nxtcc-import-modal) not found on page.' );
			return;
		}

		resetImportUI();
		$( '#nxtcc-import-modal' ).fadeIn( 120 );
	} );

	$( document ).on(
		'click',
		'#nxtcc-import-close, #nxtcc-import-modal .nxtcc-modal-overlay',
		function ( e ) {
			e.preventDefault();
			$( '#nxtcc-import-modal' ).fadeOut( 120 );
		}
	);

	function showImportStep( step ) {
		$( '.nxtcc-import-step' ).hide();
		$( '.nxtcc-import-step[data-step="' + String( step ) + '"]' ).show();
	}

	function resetImportUI() {
		showImportStep( 1 );

		$( '#nxtcc-import-file' ).val( '' );
		$( '#nxtcc-import-has-header' ).prop( 'checked', true );
		$( '#nxtcc-import-delimiter' ).val( 'auto' );
		$( '#nxtcc-import-default-subscribed' ).prop( 'checked', true );

		$( '#nxtcc-import-mapping-grid' ).empty();
		$( '#nxtcc-import-validation-cards' ).empty();
		$( '#nxtcc-import-progress-bar' ).css( 'width', '0%' );
		$( '#nxtcc-import-progress-text' ).text( 'Waiting…' );
		$( '#nxtcc-import-log' ).empty();
		$( '#nxtcc-import-download-errors' ).hide().attr( 'href', '#' );
		$( '#nxtcc-import-done' ).hide();

		importState.token          = '';
		importState.mapping        = [];
		importState.stats          = null;
		importState.totalRows      = 0;
		importState.mode           = 'skip';
		importState.errorReportUrl = '';
		importState.insertedTotal  = 0;
		importState.updatedTotal   = 0;
		importState.skippedTotal   = 0;

		const gsel = document.getElementById( 'nxtcc-import-default-groups' );
		if ( gsel && ( S.allGroups || [] ).length ) {
			emptyNode( gsel );

			( S.allGroups || [] ).forEach( function ( g ) {
				const opt       = document.createElement( 'option' );
				opt.value       = String( g.id );
				opt.textContent = String( g.group_name );
				gsel.appendChild( opt );
			} );

			enableMultiSelectToggle( $( gsel ) );
		}
	}

	// Sample template (CSV only; XLS not implemented server-side yet).
	$( document ).on(
		'click',
		'#nxtcc-import-download-sample-csv, #nxtcc-import-download-sample-xls',
		function ( e ) {
			e.preventDefault();

			const url =
				String( ajaxurl ) +
				'?action=nxtcc_contacts_import_sample' +
				'&instance_id=' +
				encodeURIComponent( String( instanceId ) ) +
				'&security=' +
				encodeURIComponent( String( nonce ) );

			navigateWithAnchor( url );
		}
	);

	// -------- Upload (Step 1 -> Step 2).
	$( document ).on( 'click', '#nxtcc-import-next-1', function ( e ) {
		e.preventDefault();

		const fileInput = document.getElementById( 'nxtcc-import-file' );
		const file      = fileInput && fileInput.files ? fileInput.files[ 0 ] : null;

		if ( ! file ) {
			alert( 'Please choose a file first.' );
			return;
		}

		importState.hasHeader         = $( '#nxtcc-import-has-header' ).is( ':checked' );
		importState.delimiter         = $( '#nxtcc-import-delimiter' ).val() || 'auto';
		importState.defaultGroupIds   = $( '#nxtcc-import-default-groups' ).val() || [];
		importState.defaultSubscribed = $( '#nxtcc-import-default-subscribed' ).is( ':checked' );

		const form = new FormData();

		// Use FormData.set() instead of .append() to satisfy VIP sniff.
		form.set( 'action', 'nxtcc_contacts_import_upload' );
		form.set( 'security', nonce );
		form.set( 'instance_id', instanceId );
		form.set( 'has_header', importState.hasHeader ? '1' : '0' );
		form.set( 'delimiter', importState.delimiter );
		form.set( 'file', file, file.name );

		$.ajax( {
			url: ajaxurl,
			method: 'POST',
			data: form,
			contentType: false,
			processData: false,
			success: function ( resp ) {
				if ( ! resp || ! resp.success || ! resp.data ) {
					const message =
					resp && resp.data && resp.data.message
						? resp.data.message
						: 'Upload failed.';
					alert( message );
					return;
				}

				importState.token     = resp.data.token || '';
				importState.totalRows = resp.data.total_rows || 0;

				const cols = Array.isArray( resp.data.columns ) ? resp.data.columns : [];

				if ( cols.length ) {
					renderMappingGrid( cols );
					showImportStep( 2 );
					return;
				}

				alert( 'No CSV columns detected. Please check the uploaded file format.' );
			},
			error: function () {
				alert( 'Network error during upload.' );
			},
		} );
	} );

	function renderMappingGrid( csvColumns ) {
		const gridNode = document.getElementById( 'nxtcc-import-mapping-grid' );
		if ( ! gridNode ) {
			return;
		}

		emptyNode( gridNode );

		if ( ! Array.isArray( csvColumns ) || ! csvColumns.length ) {
			safeAppend(
				gridNode,
				el(
					'div',
					{ style: 'color:#b00;' },
					'No CSV columns detected. Please check the uploaded file format.'
				)
			);
			return;
		}

		const existingCustomLabels = ( S.columns || [] )
			.filter( ( c ) => 0 === String( c.key || '' ).indexOf( 'custom__' ) )
			.map( ( c ) => c.label );

		const fieldOptions = [
			{ val: 'ignore', text: '— Ignore —' },
			{ val: 'name', text: 'Name (required)' },
			{ val: 'country_code', text: 'Country Code (required)' },
			{ val: 'phone_number', text: 'Phone Number (required)' },
		]
			.concat(
				existingCustomLabels.map( ( lbl ) => ( { val: 'custom:' + lbl, text: 'Custom: ' + lbl } ) )
			)
			.concat( [ { val: 'custom__new', text: '➕ Add New field' } ] );

		const frag = document.createDocumentFragment();

		csvColumns.forEach( function ( colName, idx ) {
			const row = el( 'div', { class: 'nxtcc-import-map-row' } );

			const left = el( 'div', { class: 'nxtcc-import-map-left' } );
			safeAppend(
				left,
				el(
					'div',
					{ class: 'nxtcc-import-csv-col' },
					colName || 'Column ' + String( idx + 1 )
				)
			);

			const right = el( 'div', { class: 'nxtcc-import-map-right' } );

			const select = el( 'select', {
				class: 'nxtcc-import-target',
				'data-idx': String( idx ),
			} );

			fieldOptions.forEach( function ( o ) {
				const opt       = document.createElement( 'option' );
				opt.value       = o.val;
				opt.textContent = o.text;
				select.appendChild( opt );
			} );

			const input = el( 'input', {
				type: 'text',
				class: 'nxtcc-import-custom-key',
				'data-idx': String( idx ),
				placeholder: 'Enter custom field key',
				style: 'display:none;',
			} );

			safeAppend( right, select );
			safeAppend( right, input );

			safeAppend( row, left );
			safeAppend( row, right );
			safeAppend( frag, row );
		} );

		safeAppend( gridNode, frag );

		// Auto mapping (best-effort).
		$( gridNode )
			.find( '.nxtcc-import-target' )
			.each( function () {
				const i    = parseInt( $( this ).attr( 'data-idx' ), 10 );
				const name = String( csvColumns[ i ] || '' ).toLowerCase().trim();

				if ( /(^|\s)name($|\s)/.test( name ) ) {
					$( this ).val( 'name' );
					return;
				}

				if ( /country/.test( name ) || /^cc$/.test( name ) || /country\s*code/.test( name ) ) {
					$( this ).val( 'country_code' );
					return;
				}

				if ( /phone|mobile|whatsapp/.test( name ) ) {
					$( this ).val( 'phone_number' );
				}
			} );

		$( gridNode )
			.off( 'change', '.nxtcc-import-target' )
			.on( 'change', '.nxtcc-import-target', function () {
				const idx = $( this ).attr( 'data-idx' );
				const val = $( this ).val();

				const $key = $( gridNode ).find(
					'.nxtcc-import-custom-key[data-idx="' + String( idx ) + '"]'
				);

				if ( 'custom__new' === val ) {
					$key.show().trigger( 'focus' );
					return;
				}

				$key.hide();
			} );
	}

	$( document ).on( 'click', '#nxtcc-import-back-2', function ( e ) {
		e.preventDefault();
		showImportStep( 1 );
	} );

	// Step 2 -> validate.
	$( document ).on( 'click', '#nxtcc-import-next-2', function ( e ) {
		e.preventDefault();

		const mapping = [];

		$( '#nxtcc-import-mapping-grid .nxtcc-import-target' ).each( function () {
			const idx  = parseInt( $( this ).attr( 'data-idx' ), 10 );
			let target = $( this ).val();

			if ( 'custom__new' === target ) {
				const key = toTrimmedString(
					$( '#nxtcc-import-mapping-grid .nxtcc-import-custom-key[data-idx="' + String( idx ) + '"]' ).val() || ''
				);

				if ( ! key ) {
					return;
				}

				target = 'custom:' + key;
			}

			mapping.push( { csvIndex: idx, target: target } );
		} );

		const hasName  = mapping.some( ( m ) => 'name' === m.target );
		const hasCC    = mapping.some( ( m ) => 'country_code' === m.target );
		const hasPhone = mapping.some( ( m ) => 'phone_number' === m.target );

		if ( ! hasName || ! hasCC || ! hasPhone ) {
			alert( 'Please map Name, Country Code, and Phone Number.' );
			return;
		}

		importState.mapping           = mapping;
		importState.defaultGroupIds   = $( '#nxtcc-import-default-groups' ).val() || [];
		importState.defaultSubscribed = $( '#nxtcc-import-default-subscribed' ).is( ':checked' );

		const payload = {
			action: 'nxtcc_contacts_import_validate',
			security: nonce,
			instance_id: instanceId,
			token: importState.token,
			mapping: JSON.stringify( mapping ),
			default_groups: JSON.stringify( importState.defaultGroupIds ),
			default_subscribed: importState.defaultSubscribed ? '1' : '0',
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( ! resp || ! resp.success || ! resp.data ) {
				alert( resp && resp.data && resp.data.message ? resp.data.message : 'Validation failed.' );
				return;
			}

			importState.stats = resp.data.stats || null;
			renderValidationCards( importState.stats );
			showImportStep( 3 );
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	function renderValidationCards( stats ) {
		const wrap = document.getElementById( 'nxtcc-import-validation-cards' );
		if ( ! wrap ) {
			return;
		}

		emptyNode( wrap );

		const items = [
			{ label: 'Total rows', v: stats && stats.total ? stats.total : 0 },
			{ label: 'Valid', v: stats && stats.valid ? stats.valid : 0 },
			{ label: 'Invalid (missing/invalid required)', v: stats && stats.invalid ? stats.invalid : 0 },
			{ label: 'Duplicates in file (collapsed)', v: stats && stats.dup_in_file ? stats.dup_in_file : 0 },
			{ label: 'Existing in DB (same tenant)', v: stats && stats.dup_in_db ? stats.dup_in_db : 0 },
		];

		const frag = document.createDocumentFragment();

		items.forEach( function ( it ) {
			const card = el( 'div', { class: 'nxtcc-import-card' } );
			safeAppend( card, el( 'div', { class: 'nxtcc-import-card-label' }, it.label ) );
			safeAppend( card, el( 'div', { class: 'nxtcc-import-card-val' }, String( it.v ) ) );
			safeAppend( frag, card );
		} );

		safeAppend( wrap, frag );
	}

	$( document ).on( 'click', '#nxtcc-import-back-3', function ( e ) {
		e.preventDefault();
		showImportStep( 2 );
	} );

	// Step 3 -> run.
	$( document ).on( 'click', '#nxtcc-import-start', function ( e ) {
		e.preventDefault();

		importState.mode          =
			$( 'input[name="nxtcc-import-conflict"]:checked' ).val() || 'skip';
		importState.insertedTotal = 0;
		importState.updatedTotal  = 0;
		importState.skippedTotal  = 0;

		showImportStep( 4 );
		runImportChunks( 0 );
	} );

	function logImport( level, msg ) {
		const logNode = document.getElementById( 'nxtcc-import-log' );
		if ( ! logNode ) {
			return;
		}

		const line       = el( 'div' );
		line.textContent = '[' + String( level ) + '] ' + String( msg );

		safeAppend( logNode, line );
		$( logNode ).scrollTop( logNode.scrollHeight );
	}

	function runImportChunks( offset ) {
		const payload = {
			action: 'nxtcc_contacts_import_run',
			security: nonce,
			instance_id: instanceId,
			token: importState.token,
			mode: importState.mode,
			offset: offset || 0,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( ! resp || ! resp.success || ! resp.data ) {
				logImport(
					'ERROR',
					resp && resp.data && resp.data.message ? resp.data.message : 'Import failed.'
				);

				if ( resp && resp.data && resp.data.error_csv_url ) {
					$( '#nxtcc-import-download-errors' )
						.show()
						.attr( 'href', String( resp.data.error_csv_url ) );
				}

				return;
			}

			const d                    = resp.data;
			importState.totalRows      = d.total || importState.totalRows;
			importState.insertedTotal += Number( d.inserted || 0 );
			importState.updatedTotal  += Number( d.updated || 0 );
			importState.skippedTotal  += Number( d.skipped || 0 );

			const done = Math.min( d.done || 0, importState.totalRows || 0 );
			const pct  = importState.totalRows
				? Math.floor( ( done / importState.totalRows ) * 100 )
				: 0;

			$( '#nxtcc-import-progress-bar' ).css( 'width', pct + '%' );
			$( '#nxtcc-import-progress-text' ).text(
				'Processed ' +
					done +
					' / ' +
					importState.totalRows +
					' (' +
					pct +
					'%) — Inserted: ' +
					importState.insertedTotal +
					', Updated: ' +
					importState.updatedTotal +
					', Skipped: ' +
					importState.skippedTotal
			);

			( d.logs || [] ).forEach( function ( l ) {
				logImport( l.level || 'INFO', l.message || '' );
			} );

			if ( d.finished ) {
				if ( d.error_csv_url ) {
					$( '#nxtcc-import-download-errors' )
						.show()
						.attr( 'href', String( d.error_csv_url ) );
				}

				$( '#nxtcc-import-done' )
					.show()
					.off( 'click' )
					.on( 'click', function () {
						$( '#nxtcc-import-modal' ).fadeOut( 120 );

						if ( R.actions && 'function' === typeof R.actions.loadAll ) {
							R.actions.loadAll( true );
						}
					} );

				return;
			}

			runImportChunks( d.next_offset || done );
		} ).fail( function () {
			logImport( 'ERROR', 'Network error. Please try again.' );
		} );
	}
} );
