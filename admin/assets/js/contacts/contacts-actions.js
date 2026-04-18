/**
 * Contacts actions: list, filters, paging, and bulk actions.
 *
 * @package NXTCC
 */

/* global jQuery, alert, confirm */
/* eslint-disable no-alert */

jQuery( function ( $ ) {
	'use strict';

	const R = window.NXTCC_ContactsRuntime;

	if ( ! R ) {
		return;
	}

	/**
	 * Convert any value to a string.
	 *
	 * @param {*} v Value.
	 * @return {string} String.
	 */
	function toStr( v ) {
		return null == v ? '' : String( v );
	}

	/**
	 * DOM-safe append (Node only).
	 *
	 * @param {Node} parent Parent node.
	 * @param {Node} child Child node.
	 * @return {void}
	 */
	function safeAppend( parent, child ) {
		if ( parent && child && parent.appendChild ) {
			parent.appendChild( child );
		}
	}

	/**
	 * DOM-safe empty (Node only).
	 *
	 * @param {Node} node Node.
	 * @return {void}
	 */
	function safeEmpty( node ) {
		if ( ! node ) {
			return;
		}
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Create an element with attributes and optional textContent.
	 * Uses textContent (never innerHTML).
	 *
	 * @param {string} tag Tag name.
	 * @param {Object} attrs Attributes.
	 * @param {string} text Text content.
	 * @return {Element} Element.
	 */
	function el( tag, attrs, text ) {
		const node = document.createElement( tag );

		if ( attrs && 'object' === typeof attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				const val = attrs[ key ];
				if ( null === val || typeof val === 'undefined' ) {
					return;
				}
				node.setAttribute( key, String( val ) );
			} );
		}

		if ( null !== text && typeof text !== 'undefined' ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Insert a Node after a reference element without using insertBefore()/after().
	 *
	 * @param {Element} ref Reference element.
	 * @param {Element} node Node to insert.
	 * @return {void}
	 */
	function safeInsertAfterElement( ref, node ) {
		if ( ! ref || ! node ) {
			return;
		}

		if ( ! ( ref instanceof Element ) || ! ( node instanceof Element ) ) {
			return;
		}

		// Uses Element.insertAdjacentElement (no HTML strings).
		ref.insertAdjacentElement( 'afterend', node );
	}

	// IMPORTANT: hasConnection is stored in R.state in your runtime.
	const $widget    = R.$widget;
	const ajaxurl    = R.ajaxurl;
	const nonce      = R.nonce;
	const instanceId = R.instanceId;
	const S          = R.state;

	/**
	 * Enable the multi-select dropdown UI (provided by runtime utilities).
	 *
	 * @type {Function}
	 */
	const enableMultiSelectToggle =
		R.utils && R.utils.enableMultiSelectToggle
			? R.utils.enableMultiSelectToggle
			: function () {};

	// -----------------------------
	// Helpers.
	// -----------------------------
	function ensureExportButton() {
		if ( $( '#nxtcc-bulk-export' ).length ) {
			return;
		}

		if ( ! $( '#nxtcc-bulk-edit-groups' ).length ) {
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

		const ref = $( '#nxtcc-bulk-edit-groups' ).get( 0 );

		if ( ref && ref instanceof Element ) {
			safeInsertAfterElement( ref, btn );
		}
	}

	function updateBulkToolbar() {
		ensureExportButton();

		const checked = $widget.find( '.nxtcc-contact-select:checked' ).length;

		if ( checked ) {
			$( '#nxtcc-bulk-selected-count' ).text( checked + ' selected' );
			$( '#nxtcc-bulk-toolbar' ).show();
			return;
		}

		$( '#nxtcc-bulk-toolbar' ).hide();
	}

	R.actions                   = R.actions || {};
	R.actions.updateBulkToolbar = updateBulkToolbar;

	function splitProtectedIds( idList ) {
		const idsSet       = new Set( ( idList || [] ).map( String ) );
		const protectedIds = [];
		const deletableIds = [];

		( S.allContacts || [] ).forEach( function ( c ) {
			if ( ! idsSet.has( String( c.id ) ) ) {
				return;
			}

			const isLockedVerified =
				R.table &&
				'function' === typeof R.table.hasProtectedVerifiedGroup &&
				R.table.hasProtectedVerifiedGroup( c );

			( isLockedVerified ? protectedIds : deletableIds ).push( String( c.id ) );
		} );

		return { deletableIds: deletableIds, protectedIds: protectedIds };
	}

	// -----------------------------
	// Filters (server-side).
	// -----------------------------
	let filterGroup        = '';
	let filterCountry      = '';
	let filterName         = '';
	let filterCreatedBy    = '';
	let filterCreatedFrom  = '';
	let filterCreatedTo    = '';
	let filterSubscription = ''; // '' | '1' | '0'.

	function currentFilterPayload() {
		return {
			filter_group: filterGroup || '',
			filter_country: filterCountry || '',
			filter_created_by: filterCreatedBy || '',
			filter_created_from: filterCreatedFrom || '',
			filter_created_to: filterCreatedTo || '',
			filter_subscription: filterSubscription,
			search: filterName || '',
		};
	}

	// -----------------------------
	// Paging / loading.
	// -----------------------------
	let currentPage   = 1;
	const perPage     = 100;
	let hasMore       = false;
	let isLoadingPage = false;

	function updateLoadMoreUI() {
		const $wrap = $( '#nxtcc-load-more-wrap' );

		if ( hasMore ) {
			$wrap.show();

			$( '#nxtcc-load-more' )
				.prop( 'disabled', isLoadingPage )
				.text( isLoadingPage ? 'Loading...' : 'Load more' );

			return;
		}

		$wrap.hide();
	}

	$( document ).on( 'click', '#nxtcc-load-more', function () {
		if ( ! hasMore || isLoadingPage ) {
			return;
		}

		fetchContactsPage( currentPage + 1 );
	} );

	// -----------------------------
	// API calls.
	// -----------------------------
	function apiListContacts( page ) {
		const payload = $.extend(
			{
				action: 'nxtcc_contacts_list',
				nonce: nonce,
				instance_id: instanceId,
				page: page,
				per_page: perPage,
			},
			currentFilterPayload()
		);

		return $.post( ajaxurl, payload );
	}

	function apiGroupsList() {
		const payload = {
			action: 'nxtcc_groups_list',
			security: nonce,
			instance_id: instanceId,
		};

		return $.post( ajaxurl, payload );
	}

	function apiCountryCodes() {
		const payload = {
			action: 'nxtcc_contacts_country_codes',
			nonce: nonce,
			instance_id: instanceId,
		};

		return $.post( ajaxurl, payload );
	}

	function apiCreators() {
		const payload = {
			action: 'nxtcc_contacts_creators',
			nonce: nonce,
			instance_id: instanceId,
		};

		return $.post( ajaxurl, payload );
	}

	// -----------------------------
	// Rendering + filter dropdowns.
	// -----------------------------
	function updateCountryFilter() {
		const selectEl = $( '#nxtcc-filter-country' ).get( 0 );
		if ( ! selectEl ) {
			return;
		}

		safeEmpty( selectEl );
		safeAppend( selectEl, el( 'option', { value: '' }, 'All Country Codes' ) );

		let codes = [];

		if ( S.allCountryCodes && S.allCountryCodes.length ) {
			codes = S.allCountryCodes.slice( 0 );
		} else {
			const tmp = {};
			( S.allContacts || [] ).forEach( function ( c ) {
				const cc = c && c.country_code ? String( c.country_code ) : '';
				if ( cc ) {
					tmp[ cc ] = true;
				}
			} );
			codes = Object.keys( tmp );
		}

		codes
			.filter( Boolean )
			.sort()
			.forEach( function ( code ) {
				safeAppend( selectEl, el( 'option', { value: String( code ) }, String( code ) ) );
			} );
	}

	function updateCountryAutocomplete() {
		const listEl = $( '#nxtcc-country-codes-list' ).get( 0 );
		if ( ! listEl ) {
			return;
		}

		safeEmpty( listEl );

		let codes = [];

		if ( S.allCountryCodes && S.allCountryCodes.length ) {
			codes = S.allCountryCodes.slice( 0 );
		} else {
			const tmp = {};
			( S.allContacts || [] ).forEach( function ( c ) {
				const cc = c && c.country_code ? String( c.country_code ) : '';
				if ( cc ) {
					tmp[ cc ] = true;
				}
			} );
			codes = Object.keys( tmp );
		}

		codes
			.filter( Boolean )
			.sort()
			.forEach( function ( code ) {
				safeAppend( listEl, el( 'option', { value: String( code ) } ) );
			} );
	}

	function updateCreatedByFilter() {
		let creators = [];

		if ( S.creatorsServer && S.creatorsServer.length ) {
			creators = S.creatorsServer.slice( 0 );
		} else {
			const tmp = {};
			( S.allContacts || [] ).forEach( function ( c ) {
				const value =
					c && c.created_by_key
						? String( c.created_by_key )
						: c && c.created_by_email
							? String( c.created_by_email )
							: '';
				const label =
					c && c.created_by_label
						? String( c.created_by_label )
						: c && c.created_by
							? String( c.created_by )
							: value;

				if ( value ) {
					tmp[ value ] = label || value;
				}
			} );
			creators = Object.keys( tmp ).map( function ( value ) {
				return {
					value: value,
					label: tmp[ value ] || value,
				};
			} );
		}

		const selectEl = $( '#nxtcc-filter-created-by' ).get( 0 );
		if ( ! selectEl ) {
			return;
		}

		safeEmpty( selectEl );
		safeAppend( selectEl, el( 'option', { value: '' }, 'All Creators' ) );

		creators.forEach( function ( creator ) {
			let value = '';
			let label = '';

			if ( creator && 'object' === typeof creator ) {
				value = creator.value ? String( creator.value ) : '';
				label = creator.label ? String( creator.label ) : value;
			} else {
				value = String( creator || '' );
				label = value;
			}

			if ( ! value ) {
				return;
			}

			safeAppend( selectEl, el( 'option', { value: value }, label || value ) );
		} );

		const optionStrings = creators.map( function ( creator ) {
			if ( creator && 'object' === typeof creator ) {
				return String( creator.value || '' );
			}

			return String( creator || '' );
		} );

		if ( filterCreatedBy && optionStrings.indexOf( String( filterCreatedBy ) ) === -1 ) {
			filterCreatedBy = '';
			$( selectEl ).val( '' );
		} else if ( filterCreatedBy ) {
			$( selectEl ).val( filterCreatedBy );
		}
	}

	function updateFiltersUI() {
		updateCountryFilter();

		const groupSel = $( '#nxtcc-filter-group' ).get( 0 );
		if ( groupSel ) {
			safeEmpty( groupSel );
			safeAppend( groupSel, el( 'option', { value: '' }, 'All Groups' ) );

			( S.allGroups || [] ).forEach( function ( group ) {
				safeAppend(
					groupSel,
					el( 'option', { value: String( group.id ) }, toStr( group.group_name ) )
				);
			} );
		}

		updateCreatedByFilter();
	}

	R.actions.updateCountryAutocomplete = updateCountryAutocomplete;

	// -----------------------------
	// Normalizers.
	// -----------------------------
	function safeParseJSON( maybeJson, fallback ) {
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
		if ( Array.isArray( val ) ) {
			return val;
		}

		const parsed = safeParseJSON( val, [] );

		return Array.isArray( parsed ) ? parsed : [];
	}

	function normalizeGroups( c, groupMap ) {
		if ( Array.isArray( c.groups ) ) {
			return c.groups.map( String );
		}

		const idStr = String( c.id || '' );

		if ( groupMap && Array.isArray( groupMap[ idStr ] ) ) {
			return groupMap[ idStr ].map( String );
		}

		if ( 'string' === typeof c.group_ids && '' !== c.group_ids.trim() ) {
			return c.group_ids
				.split( ',' )
				.map( function ( s ) {
					return s.trim();
				} )
				.filter( Boolean );
		}

		return [];
	}

	function normalizeContactsResponse( resp ) {
		let rows = [];

		if ( resp && resp.success && resp.data ) {
			if ( Array.isArray( resp.data.rows ) ) {
				rows = resp.data.rows;
			} else if ( Array.isArray( resp.data.contacts ) ) {
				rows = resp.data.contacts;
			}
		}

		const total      = Number( resp && resp.data && resp.data.total ? resp.data.total : 0 );
		const pageNum    = Number( resp && resp.data && resp.data.page ? resp.data.page : 1 );
		const perPageNum = Number(
			resp && resp.data && resp.data.per_page ? resp.data.per_page : perPage
		);

		const groupMap   = resp && resp.data && resp.data.group_map ? resp.data.group_map : {};
		const groupNames =
			resp && resp.data && resp.data.group_names ? resp.data.group_names : [];

		const normalized = rows.map( function ( c ) {
			const cc = $.extend( {}, c );

			cc.id = Number( cc.id );

			cc.wp_uid =
				cc.wp_uid === null ||
				typeof cc.wp_uid === 'undefined' ||
				'' === cc.wp_uid
					? null
					: Number( cc.wp_uid );

			cc.is_verified   = Number( cc.is_verified || 0 );
			cc.is_subscribed = Number( cc.is_subscribed || 0 );

			cc.created_by_label =
				cc.created_by_label ||
				cc.created_by_login ||
				cc.created_by_display ||
				cc.created_by ||
				cc.user_email ||
				'';
			cc.created_by_key =
				cc.created_by_key ||
				cc.created_by_login ||
				cc.created_by_email ||
				cc.user_email ||
				'';
			cc.created_by = cc.created_by_label;
			cc.updated_by_label =
				cc.updated_by_label ||
				cc.updated_by_login ||
				cc.updated_by_display ||
				cc.updated_by ||
				cc.updated_by_email ||
				'';
			cc.updated_by_key =
				cc.updated_by_key ||
				cc.updated_by_login ||
				cc.updated_by_email ||
				'';
			cc.updated_by = cc.updated_by_label;

			cc.custom_fields = normalizeCustomFields( cc.custom_fields );
			cc.groups        = normalizeGroups( cc, groupMap );

			return cc;
		} );

		const computedHasMore = pageNum * perPageNum < total;

		return {
			normalized: normalized,
			hasMore: computedHasMore,
			groupNames: groupNames,
			total: total,
		};
	}

	// -----------------------------
	// Fetch contacts page + render.
	// -----------------------------
	function renderEmptyRow( message ) {
		const tbodyEl = $widget.find( '.nxtcc-contacts-table tbody' ).get( 0 );
		if ( ! tbodyEl ) {
			return;
		}

		safeEmpty( tbodyEl );

		const tr = el( 'tr' );
		const td = el( 'td', {
			class: 'nxtcc-contacts-state-cell',
			colspan: '99',
		} );

		td.textContent = String( message || '' );

		safeAppend( tr, td );
		safeAppend( tbodyEl, tr );
	}

	function fetchContactsPage( page ) {
		isLoadingPage = true;
		updateLoadMoreUI();

		return apiListContacts( page )
			.done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					if ( 1 === page ) {
						renderEmptyRow( 'No contacts found.' );

						if ( R.actions && 'function' === typeof R.actions.updateBulkToolbar ) {
							R.actions.updateBulkToolbar();
						}

						if ( R.table && 'function' === typeof R.table.updateSummary ) {
							R.table.updateSummary( [] );
						}
					}

					hasMore = false;
					updateLoadMoreUI();
					return;
				}

				const parsed = normalizeContactsResponse( resp );

				const normalized      = parsed.normalized;
				const computedHasMore = parsed.hasMore;
				const groupNames      = parsed.groupNames;
				const total           = Number( parsed.total || 0 );

				S.filteredTotal = total;

				if ( ! Array.isArray( normalized ) || ! normalized.length ) {
					if ( 1 === page ) {
						renderEmptyRow( 'No contacts found.' );

						if ( R.actions && 'function' === typeof R.actions.updateBulkToolbar ) {
							R.actions.updateBulkToolbar();
						}

						if ( R.table && 'function' === typeof R.table.updateSummary ) {
							R.table.updateSummary( [] );
						}
					}

					hasMore = false;
					updateLoadMoreUI();
					return;
				}

				S.group_names = groupNames;

				if ( 1 === page ) {
					S.allContacts = normalized;
				} else {
					S.allContacts = ( S.allContacts || [] ).concat( normalized );
				}

				if (
					R.table &&
					'function' === typeof R.table.rebuildColumnsWithCustomFields
				) {
					R.table.rebuildColumnsWithCustomFields( S.allContacts );
				}

				if ( R.table && 'function' === typeof R.table.renderColumnsPopover ) {
					R.table.renderColumnsPopover();
				}

				if ( R.table && 'function' === typeof R.table.renderTableHeader ) {
					R.table.renderTableHeader();
				}

				if ( R.table && 'function' === typeof R.table.renderTable ) {
					R.table.renderTable( S.allContacts );
				}

				currentPage = page;
				hasMore     = computedHasMore;

				if ( resp.data && Array.isArray( resp.data.creators ) ) {
					S.creatorsServer = resp.data.creators
						.map( function ( creator ) {
							if ( creator && 'object' === typeof creator ) {
								return {
									value: creator.value ? String( creator.value ) : '',
									label: creator.label ? String( creator.label ) : String( creator.value || '' ),
								};
							}

							const value = String( creator || '' );

							return value
								? {
									value: value,
									label: value,
								}
								: null;
						} )
						.filter( Boolean );
					updateCreatedByFilter();
				}

				updateLoadMoreUI();
			} )
			.fail( function () {
				if ( 1 === page ) {
					renderEmptyRow( 'Network error. Please retry.' );

					if ( R.actions && 'function' === typeof R.actions.updateBulkToolbar ) {
						R.actions.updateBulkToolbar();
					}

					if ( R.table && 'function' === typeof R.table.updateSummary ) {
						R.table.updateSummary( [] );
					}
				}
			} )
			.always( function () {
				isLoadingPage = false;
				updateLoadMoreUI();
			} );
	}

	function loadAll( reset ) {
		const doReset = ( typeof reset === 'undefined' ) ? true : reset;

		if ( doReset ) {
			currentPage   = 1;
			hasMore       = false;
			S.allContacts = [];

			if ( R.table && 'function' === typeof R.table.updateSummary ) {
				R.table.updateSummary( [] );
			}
			renderEmptyRow( 'Loading contacts...' );
		}

		return fetchContactsPage( currentPage );
	}

	R.actions.loadAll = loadAll;

	// -----------------------------
	// Initial loads.
	// -----------------------------
	function fetchGroups() {
		return apiGroupsList()
			.done( function ( resp ) {
				if ( resp && resp.success && resp.data && Array.isArray( resp.data.groups ) ) {
					S.allGroups = resp.data.groups.map( function ( g ) {
						return {
							id: g.id,
							group_name: g.group_name,
							is_verified: Number( g.is_verified ) === 1,
						};
					} );

					updateFiltersUI();

					if (
						S.allContacts &&
						S.allContacts.length &&
						R.table &&
						'function' === typeof R.table.renderTable
					) {
						R.table.renderTable( S.allContacts );
					}

					const gselEl = $( '#nxtcc-import-default-groups' ).get( 0 );

					if ( gselEl ) {
						safeEmpty( gselEl );

						S.allGroups.forEach( function ( g ) {
							const label = toStr( g.group_name ) + ( g.is_verified ? ' (Protected)' : '' );
							const opt   = el( 'option', { value: String( g.id ) }, label );

							if ( g.is_verified ) {
								opt.disabled = true;
								opt.title    = 'Verified groups are protected and cannot be selected as import defaults';
							}

							safeAppend( gselEl, opt );
						} );

						enableMultiSelectToggle( $( gselEl ) );
					}
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-console..
				console.warn( '[NXTCC] fetchGroups failed.' );
			} );
	}

	R.actions.fetchGroups = fetchGroups;

	function fetchCountryCodes() {
		return apiCountryCodes()
			.done( function ( resp ) {
				let list = [];

				if ( resp && resp.data && Array.isArray( resp.data.codes ) ) {
					list = resp.data.codes;
				} else if ( resp && resp.data && Array.isArray( resp.data.country_codes ) ) {
					list = resp.data.country_codes;
				}

				if ( resp && resp.success && Array.isArray( list ) ) {
					S.allCountryCodes = list
						.map( String )
						.filter( function ( c ) {
							return c && /^\d+$/.test( c );
						} );

					updateCountryFilter();
					updateCountryAutocomplete();
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-console..
				console.warn( '[NXTCC] fetchCountryCodes failed.' );
			} );
	}

	function fetchCreators() {
		return apiCreators()
			.done( function ( resp ) {
				if ( resp && resp.success && resp.data && Array.isArray( resp.data.creators ) ) {
					S.creatorsServer = resp.data.creators
						.map( function ( creator ) {
							if ( creator && 'object' === typeof creator ) {
								return {
									value: creator.value ? String( creator.value ) : '',
									label: creator.label ? String( creator.label ) : String( creator.value || '' ),
								};
							}

							const value = String( creator || '' );

							return value
								? {
									value: value,
									label: value,
								}
								: null;
						} )
						.filter( Boolean );
					updateCreatedByFilter();
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-console..
				console.warn( '[NXTCC] fetchCreators failed.' );
			} );
	}

	if ( ! S.hasConnection ) {
		// eslint-disable-next-line no-console.
		console.warn( '[NXTCC] No connection banner detected; skipping initial AJAX loads.' );
		return;
	}

	loadAll( true );
	fetchGroups();
	fetchCountryCodes();
	fetchCreators();

	// -----------------------------
	// Filter events.
	// -----------------------------
	$( document ).on( 'change', '#nxtcc-filter-group', function () {
		filterGroup = $( this ).val() || '';
		loadAll( true );
	} );

	let nameDebounce;

	$( document ).on( 'input', '#nxtcc-filter-name', function () {
		filterName = toStr( $( this ).val() ).trim();

		clearTimeout( nameDebounce );

		nameDebounce = setTimeout( function () {
			loadAll( true );
		}, 300 );
	} );

	$( document ).on( 'change', '#nxtcc-filter-country', function () {
		filterCountry = $( this ).val() || '';
		loadAll( true );
	} );

	$( document ).on( 'change', '#nxtcc-filter-created-by', function () {
		filterCreatedBy = $( this ).val() || '';
		loadAll( true );
	} );

	$( document ).on(
		'change',
		'#nxtcc-filter-created-from, #nxtcc-filter-created-to',
		function () {
			filterCreatedFrom = toStr( $( '#nxtcc-filter-created-from' ).val() ).trim();
			filterCreatedTo   = toStr( $( '#nxtcc-filter-created-to' ).val() ).trim();
			loadAll( true );
		}
	);

	$( document ).on( 'change', '#nxtcc-filter-subscription', function () {
		const v            = $( this ).val();
		filterSubscription = ( '1' === v || '0' === v ) ? v : '';
		loadAll( true );
	} );

	// -----------------------------
	// Bulk delete.
	// -----------------------------
	$widget.on( 'click', '#nxtcc-bulk-delete', function () {
		const ids = $widget
			.find( '.nxtcc-contact-select:checked' )
			.map( function () {
				return $( this ).data( 'id' );
			} )
			.get();

		if ( ! ids.length ) {
			return;
		}

		const split        = splitProtectedIds( ids );
		const deletableIds = split.deletableIds;
		const protectedIds = split.protectedIds;

		if ( ! deletableIds.length ) {
			alert( 'Selected contacts include only verified-group contacts. They cannot be deleted.' );
			return;
		}

		if ( ! confirm( 'Delete ' + deletableIds.length + ' contacts? This cannot be undone.' ) ) {
			return;
		}

		const payload = {
			action: 'nxtcc_contacts_bulk_delete',
			nonce: nonce,
			instance_id: instanceId,
			ids: deletableIds,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success ) {
				const skippedCount =
					resp &&
					resp.data &&
					Array.isArray( resp.data.skipped_locked )
						? resp.data.skipped_locked.length
						: protectedIds.length;

				if ( skippedCount ) {
					alert( skippedCount + ' verified-group contact(s) were skipped.' );
				}

				loadAll( true );
				return;
			}

			const msg =
				resp && resp.data && resp.data.message
					? String( resp.data.message )
					: 'Failed to delete contacts.';
			alert( msg );
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	// -----------------------------
	// Bulk groups modal.
	// -----------------------------
	$widget.on( 'click', '#nxtcc-bulk-edit-groups', function () {
		const ids = $widget
			.find( '.nxtcc-contact-select:checked' )
			.map( function () {
				return $( this ).data( 'id' );
			} )
			.get();

		if ( ! ids.length ) {
			return;
		}

		const selEl = $( '#nxtcc-bulk-group-select' ).get( 0 );
		if ( ! selEl ) {
			return;
		}

		safeEmpty( selEl );

		( S.allGroups || [] ).forEach( function ( group ) {
			const isV   = ! ! group.is_verified;
			const label = toStr( group.group_name ) + ( isV ? ' (Protected)' : '' );
			const title = isV ? 'Verified groups stay assigned automatically and cannot be bulk-assigned' : '';

			const opt = el( 'option', { value: String( group.id ) }, label );

			if ( isV ) {
				opt.disabled = true;
			}

			if ( title ) {
				opt.title = title;
			}

			safeAppend( selEl, opt );
		} );

		$( selEl ).val( [] );
		enableMultiSelectToggle( $( selEl ) );

		$( '#nxtcc-bulk-group-modal' ).fadeIn( 120 ).data( 'contactIds', ids );
	} );

	$( document ).on(
		'click',
		'#nxtcc-bulk-group-cancel, #nxtcc-bulk-group-close, #nxtcc-bulk-group-modal .nxtcc-modal-overlay',
		function () {
			$( '#nxtcc-bulk-group-modal' ).fadeOut( 120 );
		}
	);

	$( document ).on( 'click', '#nxtcc-bulk-group-apply', function () {
		const groupIds   = $( '#nxtcc-bulk-group-select' ).val() || [];
		const contactIds = $( '#nxtcc-bulk-group-modal' ).data( 'contactIds' ) || [];

		if ( ! contactIds.length ) {
			return;
		}

		const payload = {
			action: 'nxtcc_contacts_bulk_update_groups',
			nonce: nonce,
			instance_id: instanceId,
			ids: contactIds,
			group_ids: groupIds,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success ) {
				$( '#nxtcc-bulk-group-modal' ).fadeOut( 120 );
				loadAll( true );
				return;
			}

			const msg =
				resp && resp.data && resp.data.message
					? String( resp.data.message )
					: 'Failed to update groups for selected contacts.';
			alert( msg );
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	// -----------------------------
	// Bulk subscription modal.
	// -----------------------------
	$widget.on( 'click', '#nxtcc-bulk-edit-subscription', function () {
		const ids = $widget
			.find( '.nxtcc-contact-select:checked' )
			.map( function () {
				return $( this ).data( 'id' );
			} )
			.get();

		if ( ! ids.length ) {
			return;
		}

		$( 'input[name="nxtcc-bulk-subscription-choice"][value="1"]' ).prop( 'checked', true );

		$( '#nxtcc-bulk-subscription-modal' )
			.data( 'contactIds', ids )
			.fadeIn( 120 );
	} );

	$( document ).on(
		'click',
		'#nxtcc-bulk-subscription-cancel, #nxtcc-bulk-subscription-close, #nxtcc-bulk-subscription-modal .nxtcc-modal-overlay',
		function () {
			$( '#nxtcc-bulk-subscription-modal' ).fadeOut( 120 );
		}
	);

	$( document ).on( 'click', '#nxtcc-bulk-subscription-apply', function () {
		const ids = $( '#nxtcc-bulk-subscription-modal' ).data( 'contactIds' ) || [];

		if ( ! ids.length ) {
			return;
		}

		const val        = $( 'input[name="nxtcc-bulk-subscription-choice"]:checked' ).val();
		const subscribed = ( String( val ) === '1' ) ? 1 : 0;

		const payload = {
			action: 'nxtcc_contacts_bulk_update_subscription',
			nonce: nonce,
			instance_id: instanceId,
			ids: ids,
			is_subscribed: subscribed,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success ) {
				$( '#nxtcc-bulk-subscription-modal' ).fadeOut( 120 );
				loadAll( true );
				return;
			}

			const msg =
				resp && resp.data && resp.data.message
					? String( resp.data.message )
					: 'Failed to update subscription for selected contacts.';
			alert( msg );
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );
} );
