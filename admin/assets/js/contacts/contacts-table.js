/**
 * Contacts table renderer (admin).
 *
 * Responsibilities:
 * - Render table header and body based on selected visible columns.
 * - Provide the "show/hide columns" popover with persisted preferences.
 * - Insert dynamic columns for custom fields detected in the contacts data.
 *
 * Security:
 * - Avoid unsafe DOM HTML sinks (no innerHTML/outerHTML/insertAdjacentHTML).
 * - Render user-controlled values via textContent / createTextNode only.
 *
 * @package NXTCC
 */

/* global jQuery, window, document */

jQuery( function ( $ ) {
	'use strict';

	const R = window.NXTCC_ContactsRuntime;

	if ( ! R ) {
		return;
	}

	const $widget                      = R.$widget;
	const storageScopeKey              = R.storageScopeKey;
	const formatCreatedAtUTCToSiteAMPM = R.time.formatCreatedAtUTCToSiteAMPM;
	const S                            = R.state;

	/**
	 * Current columns definition for the contacts table.
	 *
	 * Custom field columns are inserted dynamically between base and trailing columns.
	 *
	 * @type {Array<Object>}
	 */
	let columns = [
		{ key: 'checkbox', label: '', visible: true },
		{ key: 'name', label: 'Name', visible: true, sortable: false },
		{ key: 'country_code', label: 'Country Code', visible: true, sortable: false },
		{ key: 'phone_number', label: 'Phone Number', visible: true, sortable: false },
		{ key: 'groups', label: 'Groups', visible: true, sortable: false },
		{ key: 'subscribed', label: 'Subscription', visible: true, sortable: false },
		{ key: 'created_at', label: 'Created At', visible: true, sortable: false },
		{ key: 'updated_at', label: 'Updated At', visible: false, sortable: false },
		{ key: 'created_by', label: 'Created By', visible: false, sortable: false },
		{ key: 'updated_by', label: 'Updated By', visible: false, sortable: false },
		{ key: 'actions', label: 'Actions', visible: true, sortable: false },
	];

	/**
	 * Public table namespace used by other Contacts modules.
	 *
	 * @type {Object}
	 */
	R.table = R.table || {};

	/**
	 * Create a DOM element and assign safe attributes.
	 *
	 * Supported attrs:
	 * - className: string
	 * - text: string (sets textContent)
	 * - other attributes: setAttribute() fallback
	 *
	 * Note: This intentionally does not support "html" to avoid unsafe sinks.
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
	 * Remove all children from a DOM node.
	 *
	 * @param {HTMLElement} node Node.
	 * @return {void}
	 */
	function emptyNode( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Find a DOM node inside the widget.
	 *
	 * @param {string} selector Selector.
	 * @return {HTMLElement|null} Node.
	 */
	function withinWidget( selector ) {
		const found = $widget.find( selector );
		return found.length ? found.get( 0 ) : null;
	}

	// -------------------------------------------------------------------------
	// Column preferences (persist).
	// -------------------------------------------------------------------------

	/**
	 * Get the localStorage key for the current widget scope.
	 *
	 * @return {string} Storage key.
	 */
	function getColStorageKey() {
		return storageScopeKey;
	}

	/**
	 * Read saved column visibility map from localStorage.
	 *
	 * @return {Object} Map of columnKey => boolean visible.
	 */
	function getSavedColMap() {
		try {
			const raw = window.localStorage.getItem( getColStorageKey() );

			if ( ! raw ) {
				return {};
			}

			const arr = JSON.parse( raw );
			const map = {};

			( arr || [] ).forEach( ( c ) => {
				if ( c && c.key ) {
					map[ c.key ] = c.visible !== false;
				}
			} );

			return map;
		} catch ( e ) {
			return {};
		}
	}

	/**
	 * Apply saved visibility preferences to a columns array.
	 *
	 * @param {Array} columnsArr Columns.
	 * @return {Array} Columns with visibility applied.
	 */
	function applySavedPrefsTo( columnsArr ) {
		const saved = getSavedColMap();

		return ( columnsArr || [] ).map( ( c ) => {
			const has = Object.prototype.hasOwnProperty.call( saved, c.key );
			const vis = has ? saved[ c.key ] : c.visible !== false;

			return { ...c, visible: vis };
		} );
	}

	/**
	 * Persist current column visibility preferences.
	 *
	 * @return {void}
	 */
	function saveColPrefs() {
		try {
			const slim = ( columns || [] ).map( ( c ) => ( {
				key: c.key,
				visible: c.visible !== false,
			} ) );

			window.localStorage.setItem( getColStorageKey(), JSON.stringify( slim ) );
		} catch ( e ) {
			// Ignore storage failures (private mode, quota, etc).
		}
	}

	/**
	 * Load persisted preferences into the current columns array.
	 *
	 * @return {void}
	 */
	function loadColPrefs() {
		columns = applySavedPrefsTo( columns );
	}

	loadColPrefs();
	S.columns = columns;

	// -------------------------------------------------------------------------
	// Column popover.
	// -------------------------------------------------------------------------

	/**
	 * Render the show/hide columns popover content using DOM nodes.
	 *
	 * @return {void}
	 */
	function renderColumnsPopover() {
		const pop = document.getElementById( 'nxtcc-contacts-columns-popover' );

		if ( ! pop ) {
			return;
		}

		emptyNode( pop );

		columns.forEach( ( col ) => {
			if ( col.key === 'checkbox' || col.key === 'actions' ) {
				return;
			}

			const row   = el( 'div' );
			const label = el( 'label' );
			const input = el( 'input', { type: 'checkbox' } );

			input.setAttribute( 'data-col', col.key );
			input.checked = col.visible !== false;

			label.appendChild( input );
			label.appendChild( document.createTextNode( ' ' ) );
			label.appendChild( document.createTextNode( col.label ) );

			row.appendChild( label );
			pop.appendChild( row );
		} );
	}

	/**
	 * Toggle the columns popover and position it under the button.
	 */
	$widget.on( 'click', '#nxtcc-show-hide-columns', function () {
		const pop = document.getElementById( 'nxtcc-contacts-columns-popover' );

		if ( ! pop ) {
			return;
		}

		renderColumnsPopover();

		if ( pop.style.display !== 'none' && pop.style.display !== '' ) {
			pop.style.display = 'none';
			return;
		}

		if ( pop.parentNode !== document.body ) {
			document.body.appendChild( pop );
		}

		const $btn      = $( this );
		const btnOffset = $btn.offset();

		pop.style.position = 'absolute';
		pop.style.top      = String( btnOffset.top + $btn.outerHeight() + 4 ) + 'px';
		pop.style.left     = String( btnOffset.left ) + 'px';
		pop.style.minWidth = String( $btn.outerWidth() ) + 'px';
		pop.style.zIndex   = '99999';
		pop.style.display  = 'block';
	} );

	/**
	 * Hide the columns popover when clicking outside.
	 */
	$( document ).on( 'mousedown.nxtccCols', function ( e ) {
		const pop = document.getElementById( 'nxtcc-contacts-columns-popover' );
		const btn = document.getElementById( 'nxtcc-show-hide-columns' );

		if ( ! pop ) {
			return;
		}

		const target = e.target;

		if ( pop.contains( target ) ) {
			return;
		}

		if ( btn && btn.contains( target ) ) {
			return;
		}

		pop.style.display = 'none';
	} );

	/**
	 * Update column visibility when a checkbox changes.
	 */
	$( document ).on(
		'change',
		'#nxtcc-contacts-columns-popover input[type="checkbox"]',
		function () {
			const colKey = $( this ).data( 'col' );

			columns = columns.map( ( c ) => {
				if ( c.key !== colKey ) {
					return c;
				}

				return { ...c, visible: ! ! this.checked };
			} );

			S.columns = columns;

			saveColPrefs();
			renderColumnsPopover();
			renderTableHeader();
			renderTable( S.allContacts );
		}
	);

	// -------------------------------------------------------------------------
	// Custom fields -> dynamic columns.
	// -------------------------------------------------------------------------

	/**
	 * Collect all unique custom field labels from a contacts list.
	 *
	 * @param {Array} contacts Contacts list.
	 * @return {Array} Labels.
	 */
	function getAllCustomFieldLabels( contacts ) {
		const set = new Set();

		( contacts || [] ).forEach( ( c ) => {
			( c.custom_fields || [] ).forEach( ( f ) => {
				if ( f && f.label !== undefined && f.label !== null ) {
					set.add( String( f.label ) );
				}
			} );
		} );

		return Array.from( set );
	}

	/**
	 * Rebuild columns inserting dynamic custom field columns.
	 *
	 * @param {Array} contacts Contacts list.
	 * @return {void}
	 */
	function rebuildColumnsWithCustomFields( contacts ) {
		const before = [
			{ key: 'checkbox', label: '', visible: true },
			{ key: 'name', label: 'Name', visible: true, sortable: false },
			{ key: 'country_code', label: 'Country Code', visible: true, sortable: false },
			{ key: 'phone_number', label: 'Phone Number', visible: true, sortable: false },
			{ key: 'groups', label: 'Groups', visible: true, sortable: false },
			{ key: 'subscribed', label: 'Subscription', visible: true, sortable: false },
		];

		const audit = [
			{ key: 'created_at', label: 'Created At', visible: true, sortable: false },
			{ key: 'updated_at', label: 'Updated At', visible: false, sortable: false },
			{ key: 'created_by', label: 'Created By', visible: false, sortable: false },
			{ key: 'updated_by', label: 'Updated By', visible: false, sortable: false },
		];

		const after = [
			{ key: 'actions', label: 'Actions', visible: true, sortable: false },
		];

		const customCols = getAllCustomFieldLabels( contacts ).map( ( label ) => {
			let sample   = null;

			for ( const c of ( contacts || [] ) ) {
				sample = ( c.custom_fields || [] ).find( ( f ) => f && f.label === label );
				if ( sample ) {
					break;
				}
			}

			return {
				key: 'custom__' + label,
				label,
				visible: false,
				type: sample && sample.type ? sample.type : 'text',
				options: sample && sample.options ? sample.options : [],
			};
		} );

		let merged = [ ...before, ...customCols, ...audit, ...after ];
		merged     = applySavedPrefsTo( merged );

		columns   = merged;
		S.columns = columns;

		saveColPrefs();
	}

	// -------------------------------------------------------------------------
	// Table render helpers.
	// -------------------------------------------------------------------------

	/**
	 * Resolve a group ID into a readable name.
	 *
	 * @param {string|number} id Group ID.
	 * @return {string} Group name.
	 */
	function getGroupName( id ) {
		const found = ( S.allGroups || [] ).find( ( g ) => String( g.id ) === String( id ) );
		return found ? found.group_name : String( id );
	}

	/**
	 * Get the verified group IDs currently available in the tenant.
	 *
	 * @return {Set<string>} Verified group ids.
	 */
	function getVerifiedGroupIds() {
		return new Set(
			( S.allGroups || [] )
				.filter( ( group ) => Number( group.is_verified ) === 1 )
				.map( ( group ) => String( group.id ) )
		);
	}

	/**
	 * Check whether a contact belongs to any protected verified group.
	 *
	 * @param {Object} row Contact row.
	 * @return {boolean} True when the contact belongs to a verified group.
	 */
	function hasProtectedVerifiedGroup( row ) {
		const verifiedGroupIds = getVerifiedGroupIds();

		return Array.isArray( row.groups ) && row.groups.some( ( gid ) => verifiedGroupIds.has( String( gid ) ) );
	}

	/**
	 * Update top-of-screen summary cards.
	 *
	 * @param {Array} contacts Loaded contacts list.
	 * @return {void}
	 */
	function updateSummary( contacts ) {
		const items           = Array.isArray( contacts ) ? contacts : [];
		const filteredTotalEl = document.getElementById( 'nxtcc-contacts-summary-total' );
		const loadedEl        = document.getElementById( 'nxtcc-contacts-summary-loaded' );
		const subscribedEl    = document.getElementById( 'nxtcc-contacts-summary-subscribed' );
		const verifiedEl      = document.getElementById( 'nxtcc-contacts-summary-verified' );

		let subscribedCount = 0;
		let verifiedCount   = 0;

		items.forEach( ( row ) => {
			if ( Number( row.is_subscribed ) === 1 ) {
				subscribedCount += 1;
			}

			if ( hasProtectedVerifiedGroup( row ) ) {
				verifiedCount += 1;
			}
		} );

		if ( filteredTotalEl ) {
			filteredTotalEl.textContent = String( Number( S.filteredTotal || 0 ) );
		}

		if ( loadedEl ) {
			loadedEl.textContent = String( items.length );
		}

		if ( subscribedEl ) {
			subscribedEl.textContent = String( subscribedCount );
		}

		if ( verifiedEl ) {
			verifiedEl.textContent = String( verifiedCount );
		}
	}

	/**
	 * Render table header (<thead>) based on visible columns.
	 *
	 * @return {void}
	 */
	function renderTableHeader() {
		const tr = withinWidget( '.nxtcc-contacts-table thead tr' );

		if ( ! tr ) {
			return;
		}

		emptyNode( tr );

		columns.forEach( ( col ) => {
			if ( ! col.visible ) {
				return;
			}

			if ( col.key === 'checkbox' ) {
				const th    = el( 'th' );
				const input = el( 'input', { type: 'checkbox', id: 'nxtcc-contacts-select-all' } );

				th.setAttribute( 'data-col', 'checkbox' );
				th.appendChild( input );
				tr.appendChild( th );
				return;
			}

			const th = el( 'th', { text: col.label } );
			th.setAttribute( 'data-col', col.key );
			tr.appendChild( th );
		} );
	}

	/**
	 * Post-render UI updates (bulk toolbar, select-all reset).
	 *
	 * @return {void}
	 */
	function afterRenderTable() {
		const selectAll = document.getElementById( 'nxtcc-contacts-select-all' );

		if ( selectAll ) {
			selectAll.checked = false;
		}

		if ( R.actions && typeof R.actions.updateBulkToolbar === 'function' ) {
			R.actions.updateBulkToolbar();
		}
	}

	/**
	 * Build the groups chips node for a row.
	 *
	 * @param {Object}      row              Contact row.
	 * @param {Set<string>} verifiedGroupIds Verified group ids.
	 * @return {HTMLElement} Chips wrapper.
	 */
	function buildGroupsChips( row, verifiedGroupIds ) {
		const wrap = el( 'div', { className: 'nxtcc-contact-group-chips' } );

		( row.groups || [] ).forEach( ( gid ) => {
			const name           = getGroupName( gid );
			const isThisVerified = verifiedGroupIds.has( String( gid ) );

			const chip = el( 'span', { text: name } );

			if ( isThisVerified ) {
				chip.className = 'nxtcc-chip nxtcc-chip-verified';
				chip.setAttribute( 'title', name + ' (Protected verified group)' );
				chip.appendChild( document.createTextNode( ' 🔒' ) );
			} else {
				chip.className = 'nxtcc-chip';
				chip.setAttribute( 'title', name );
			}

			wrap.appendChild( chip );
		} );

		return wrap;
	}

	/**
	 * Render table body (<tbody>) based on contacts and visible columns.
	 *
	 * @param {Array} contacts Contacts list.
	 * @return {void}
	 */
	function renderTable( contacts ) {
		const tbody = withinWidget( '.nxtcc-contacts-table tbody' );

		if ( ! tbody ) {
			return;
		}

		emptyNode( tbody );

		if ( ! contacts || ! contacts.length ) {
			const tr = el( 'tr' );
			const td = el( 'td', {
				className: 'nxtcc-contacts-state-cell',
				text: 'No contacts found.',
			} );

			td.setAttribute( 'colspan', '99' );

			tr.appendChild( td );
			tbody.appendChild( tr );

			updateSummary( [] );
			afterRenderTable();
			return;
		}

		const verifiedGroupIds = getVerifiedGroupIds();

		contacts.forEach( ( row ) => {
			const hasLockedVerifiedGroup = hasProtectedVerifiedGroup( row );

			const tr = el( 'tr' );

			columns.forEach( ( col ) => {
				if ( ! col.visible ) {
					return;
				}

				if ( col.key === 'checkbox' ) {
					const td    = el( 'td' );
					const input = el( 'input', { type: 'checkbox' } );

					input.className = 'nxtcc-contact-select';
					input.setAttribute( 'data-id', String( row.id ) );

					td.appendChild( input );
					tr.appendChild( td );
					return;
				}

				if ( col.key === 'groups' ) {
					const td = el( 'td' );
					td.appendChild( buildGroupsChips( row, verifiedGroupIds ) );
					tr.appendChild( td );
					return;
				}

				if ( col.key === 'subscribed' ) {
					const flag  = String( row.is_subscribed ) === '1';
					const td    = el( 'td' );
					const badge = el( 'span', { text: flag ? 'Subscribed' : 'Unsubscribed' } );

					badge.className = flag ? 'nxtcc-chip nxtcc-chip-ok' : 'nxtcc-chip nxtcc-chip-muted';
					badge.setAttribute( 'title', flag ? 'Subscribed' : 'Unsubscribed' );

					td.appendChild( badge );
					tr.appendChild( td );
					return;
				}

				if ( col.key === 'created_at' ) {
					const localStr = formatCreatedAtUTCToSiteAMPM( row.created_at );
					const td       = el( 'td', { text: localStr || '' } );

					td.setAttribute( 'title', 'Stored in UTC; shown in site time' );
					tr.appendChild( td );
					return;
				}

				if ( col.key === 'updated_at' ) {
					const localStr = formatCreatedAtUTCToSiteAMPM( row.updated_at );
					const td       = el( 'td', { text: localStr || '' } );

					td.setAttribute( 'title', 'Stored in UTC; shown in site time' );
					tr.appendChild( td );
					return;
				}

				if ( col.key.startsWith( 'custom__' ) ) {
					const label = col.label;
					let value   = '';

					if ( Array.isArray( row.custom_fields ) ) {
						const f = row.custom_fields.find( ( cf ) => cf && cf.label === label );
						value   = f && f.value ? String( f.value ) : '';
					}

					tr.appendChild( el( 'td', { text: value } ) );
					return;
				}

				if ( col.key === 'created_by' ) {
					const v =
						row.created_by_label !== undefined &&
						row.created_by_label !== null &&
						String( row.created_by_label ).trim() !== ''
							? row.created_by_label
							: row.created_by !== undefined &&
								row.created_by !== null &&
								String( row.created_by ).trim() !== ''
							? row.created_by
							: '';

					tr.appendChild( el( 'td', { text: v } ) );
					return;
				}

				if ( col.key === 'updated_by' ) {
					const v =
						row.updated_by_label !== undefined &&
						row.updated_by_label !== null &&
						String( row.updated_by_label ).trim() !== ''
							? row.updated_by_label
							: row.updated_by !== undefined &&
								row.updated_by !== null &&
								String( row.updated_by ).trim() !== ''
									? row.updated_by
									: '';

					tr.appendChild( el( 'td', { text: v } ) );
					return;
				}

				if ( col.key === 'actions' ) {
					const td     = el( 'td' );
					td.className = 'actions-col';
					const wrap   = el( 'div', { className: 'nxtcc-contact-row-actions' } );

					const edit     = el( 'button', { type: 'button', text: 'Edit' } );
					edit.className = 'nxtcc-btn-sm nxtcc-btn-green nxtcc-edit-contact';
					edit.setAttribute( 'data-id', String( row.id ) );

					const del     = el( 'button', { type: 'button', text: 'Delete' } );
					del.className = 'nxtcc-btn-sm nxtcc-btn-outline nxtcc-delete-contact';
					del.setAttribute( 'data-id', String( row.id ) );

					if ( hasLockedVerifiedGroup ) {
						del.disabled = true;
						del.title    = 'Contacts assigned to verified groups cannot be deleted';
						del.setAttribute( 'title', 'Contacts assigned to verified groups cannot be deleted' );
					}

					if ( hasLockedVerifiedGroup ) {
						del.title = 'Contacts assigned to verified groups cannot be deleted';
					}

					wrap.appendChild( edit );
					wrap.appendChild( del );
					td.appendChild( wrap );

					tr.appendChild( td );
					return;
				}

				{
					const raw = row[ col.key ];
					const val = raw === undefined || raw === null ? '' : String( raw );
					tr.appendChild( el( 'td', { text: val } ) );
				}
			} );

			tbody.appendChild( tr );
		} );

		updateSummary( contacts );
		afterRenderTable();
	}

	// -------------------------------------------------------------------------
	// Bulk select (checkboxes).
	// -------------------------------------------------------------------------

	$widget.on( 'change', '#nxtcc-contacts-select-all', function () {
		const checked = $( this ).is( ':checked' );

		$widget.find( '.nxtcc-contact-select' ).prop( 'checked', checked );

		if ( R.actions && typeof R.actions.updateBulkToolbar === 'function' ) {
			R.actions.updateBulkToolbar();
		}
	} );

	$widget.on( 'change', '.nxtcc-contact-select', function () {
		const total   = $widget.find( '.nxtcc-contact-select' ).length;
		const checked = $widget.find( '.nxtcc-contact-select:checked' ).length;

		$( '#nxtcc-contacts-select-all' ).prop( 'checked', ! ! total && checked === total );

		if ( R.actions && typeof R.actions.updateBulkToolbar === 'function' ) {
			R.actions.updateBulkToolbar();
		}
	} );

	// -------------------------------------------------------------------------
	// Public API for other contacts modules.
	// -------------------------------------------------------------------------

	R.table.getGroupName                   = getGroupName;
	R.table.rebuildColumnsWithCustomFields = rebuildColumnsWithCustomFields;
	R.table.renderColumnsPopover           = renderColumnsPopover;
	R.table.renderTableHeader              = renderTableHeader;
	R.table.renderTable                    = renderTable;
	R.table.hasProtectedVerifiedGroup      = hasProtectedVerifiedGroup;
	R.table.updateSummary                  = updateSummary;
} );
