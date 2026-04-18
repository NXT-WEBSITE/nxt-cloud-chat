/**
 * Contacts modal logic (admin).
 *
 * Handles:
 * - Add/Edit contact modal open/close.
 * - Groups dropdown rules for Verified group locking.
 * - Custom fields UI (static list + dynamic fields generated from columns).
 * - AJAX actions: get/save/delete contact and create group.
 *
 * Uses DOM node construction for UI building to satisfy WP/VIP JS sniffs.
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

	const $widget    = R.$widget;
	const ajaxurl    = R.ajaxurl;
	const nonce      = R.nonce;
	const instanceId = R.instanceId;
	const S          = R.state || {};
	const U          = R.utils || {};
	const T          = R.table || {};

	/**
	 * Whether the plugin has an active WhatsApp Cloud API connection.
	 *
	 * @type {boolean}
	 */
	const hasConnection = ! ! S.hasConnection;

	const escapeHtml              = U.escapeHtml;
	const enableMultiSelectToggle = U.enableMultiSelectToggle;

	// -------------------------------------------------------------------------
	// DOM helpers.
	// -------------------------------------------------------------------------

	/**
	 * Create a DOM element with safe attributes.
	 *
	 * Note: innerHTML is intentionally not supported.
	 *
	 * @param {string} tag Tag name.
	 * @param {Object} attrs Attributes.
	 * @return {HTMLElement} Element.
	 */
	function el( tag, attrs ) {
		const node = document.createElement( tag );

		if ( attrs && typeof attrs === 'object' ) {
			Object.keys( attrs ).forEach( ( key ) => {
				if ( attrs[ key ] === undefined || attrs[ key ] === null ) {
					return;
				}

				if ( key === 'className' ) {
					node.className = String( attrs[ key ] );
					return;
				}

				if ( key === 'text' ) {
					node.textContent = String( attrs[ key ] );
					return;
				}

				if ( key in node ) {
					try {
						node[ key ] = attrs[ key ];
						return;
					} catch ( err ) {
						// Fall through to setAttribute below.
					}
				}

				node.setAttribute( key, String( attrs[ key ] ) );
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
	 * Convert a jQuery collection (first match) into an HTMLElement.
	 *
	 * @param {jQuery} $node Node collection.
	 * @return {HTMLElement|null} Node.
	 */
	function firstDom( $node ) {
		return $node && $node.length ? $node.get( 0 ) : null;
	}

	// -------------------------------------------------------------------------
	// Small helpers.
	// -------------------------------------------------------------------------

	/**
	 * Parse JSON safely across mixed back-end response shapes.
	 *
	 * @param {*} maybeJson Value that might be JSON.
	 * @param {*} fallback Fallback value.
	 * @return {*} Parsed value.
	 */
	function safeParseJSON( maybeJson, fallback ) {
		if ( Array.isArray( maybeJson ) ) {
			return maybeJson;
		}

		if ( typeof maybeJson === 'string' ) {
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

		if ( typeof maybeJson === 'object' && maybeJson !== null ) {
			return maybeJson;
		}

		return fallback;
	}

	/**
	 * Normalize custom_fields value into an array.
	 *
	 * @param {*} val Raw value.
	 * @return {Array} Custom fields array.
	 */
	function normalizeCustomFields( val ) {
		const parsed = safeParseJSON( val, [] );
		return Array.isArray( parsed ) ? parsed : [];
	}

	/**
	 * Normalize groups into a string array.
	 *
	 * @param {*} val Raw value.
	 * @return {Array} Groups list.
	 */
	function normalizeGroups( val ) {
		if ( Array.isArray( val ) ) {
			return val.map( String );
		}

		if ( typeof val === 'string' && val.trim() !== '' ) {
			return val
				.split( ',' )
				.map( ( s ) => s.trim() )
				.filter( Boolean );
		}

		return [];
	}

	// -------------------------------------------------------------------------
	// Modal state.
	// -------------------------------------------------------------------------

	/**
	 * Verified group ID that must remain selected while editing a linked verified contact.
	 *
	 * @type {string|null}
	 */
	let modalLockedVerifiedGroupId = null;

	/**
	 * Read verified group ID from the cached group list.
	 *
	 * @return {string|null} Verified group ID.
	 */
	function getVerifiedGroupId() {
		const vg = ( S.allGroups || [] ).find( ( g ) => Number( g.is_verified ) === 1 );
		return vg ? String( vg.id ) : null;
	}

	/**
	 * Render the groups dropdown with verified group rules applied.
	 *
	 * @param {Array} selectedGroups Selected group IDs.
	 * @param {Object} opts Behaviour flags.
	 * @return {void}
	 */
	function loadGroupsDropdown( selectedGroups, opts ) {
		const options                  = opts || {};
		const hasProtectedVerifiedGroup = options.hasProtectedVerifiedGroup === true;
		const lockedVerifiedGroupId    = options.lockedVerifiedGroupId ? String( options.lockedVerifiedGroupId ) : null;

		const $sel   = $( '#nxtcc-contact-groups' );
		const selDom = firstDom( $sel );

		if ( ! selDom ) {
			return;
		}

		emptyNode( selDom );

		const selectedSet = new Set( ( selectedGroups || [] ).map( String ) );

		( S.allGroups || [] ).forEach( ( group ) => {
			const idStr           = String( group.id );
			const isVerifiedGroup = ! ! group.is_verified;
			const isSelected      = selectedSet.has( idStr );

			let disabled = false;
			let title    = '';
			let label    = group.group_name;

			if ( isVerifiedGroup ) {
				disabled = true;
				title    = 'Verified groups cannot be assigned from Contacts';

				if ( hasProtectedVerifiedGroup && lockedVerifiedGroupId && idStr === lockedVerifiedGroupId ) {
					title  = 'Verified group is protected and cannot be removed';
					label += ' (Protected)';
				}
			}

			const opt = el( 'option', { value: idStr, text: label } );

			if ( isSelected ) {
				opt.selected = true;
			}

			if ( disabled ) {
				opt.disabled = true;
			}

			if ( title ) {
				opt.setAttribute( 'title', title );
			}

			selDom.appendChild( opt );
		} );

		enableMultiSelectToggle( $sel );

	}

	/**
	 * Create a group via AJAX.
	 *
	 * @param {string} groupName Group name.
	 * @return {jqXHR} Request.
	 */
	function apiCreateGroup( groupName ) {
		const payload = {
			action: 'nxtcc_groups_create',
			security: nonce,
			instance_id: instanceId,
			group_name: ( groupName || '' ).trim(),
		};

		return $.post( ajaxurl, payload );
	}

	$widget.on( 'click', '.nxtcc-inline-add-link', function ( e ) {
		e.preventDefault();

		if ( ! hasConnection ) {
			alert( 'Please connect WhatsApp Cloud API first.' );
			return;
		}

		const name      = prompt( 'Enter new group name:' );
		const groupName = ( name || '' ).trim();

		if ( ! groupName ) {
			return;
		}

		apiCreateGroup( groupName )
			.done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Failed to create group';
					alert( msg );
					return;
				}

				/*
				 * Support multiple backend response shapes.
				 */
				const newId =
					( resp && resp.data && resp.data.id ) ||
					( resp && resp.data && resp.data.group_id ) ||
					( resp && resp.data && resp.data.group && resp.data.group.id ) ||
					null;

				/*
				 * Refresh group list then re-render dropdown.
				 */
				if ( R.actions && typeof R.actions.fetchGroups === 'function' ) {
					R.actions.fetchGroups().always( function () {
						const current = ( $( '#nxtcc-contact-groups' ).val() || [] ).map( String );

						if ( newId && ! current.includes( String( newId ) ) ) {
							current.push( String( newId ) );
						}

						const editing                   = S.editingContact || {};
						const hasProtectedVerifiedGroup =
							modalLockedVerifiedGroupId &&
							Array.isArray( editing.groups ) &&
							editing.groups.map( String ).includes( String( modalLockedVerifiedGroupId ) );

						loadGroupsDropdown( current, {
							hasProtectedVerifiedGroup: !! hasProtectedVerifiedGroup,
							lockedVerifiedGroupId: modalLockedVerifiedGroupId,
						} );
					} );
				} else {
					alert( 'Group created, but cannot refresh list. Please reload the page.' );
				}
			} )
			.fail( function () {
				alert( 'Network error while creating group.' );
			} );
	} );

	// -------------------------------------------------------------------------
	// Custom fields UI.
	// -------------------------------------------------------------------------

	/**
	 * Build a "static" custom field row (Add Field list).
	 *
	 * @return {HTMLElement} Row element.
	 */
	function buildCustomFieldRow() {
		const row = el( 'div', { className: 'nxtcc-form-row nxtcc-custom-field-row' } );

		const label = el( 'input', {
			type: 'text',
			className: 'nxtcc-custom-field-label',
			placeholder: 'Label',
		} );

		const type = el( 'select', { className: 'nxtcc-custom-field-type' } );

		[
			{ v: 'text', t: 'Text' },
			{ v: 'number', t: 'Number' },
			{ v: 'email', t: 'Email' },
			{ v: 'date', t: 'Date' },
			{ v: 'dropdown', t: 'Dropdown' },
		].forEach( ( o ) => {
			type.appendChild( el( 'option', { value: o.v, text: o.t } ) );
		} );

		const value = el( 'input', {
			type: 'text',
			className: 'nxtcc-custom-field-value',
			placeholder: 'Value',
		} );

		const removeBtn = el( 'button', {
			type: 'button',
			className: 'nxtcc-btn nxtcc-btn-outline nxtcc-remove-custom-field',
			text: '×',
		} );

		row.appendChild( label );
		row.appendChild( type );
		row.appendChild( value );
		row.appendChild( removeBtn );

		return row;
	}

	/**
	 * Ensure the dropdown options input exists/doesn't exist for a row.
	 *
	 * @param {HTMLElement} row Row element.
	 * @param {string} type Selected type.
	 * @return {void}
	 */
	function syncStaticRowOptionsInput( row, type ) {
		const existing = row.querySelector( '.nxtcc-custom-field-options' );

		if ( type === 'dropdown' ) {
			if ( ! existing ) {
				const opts = el( 'input', {
					type: 'text',
					className: 'nxtcc-custom-field-options',
					placeholder: 'Options (comma-separated)',
				} );
				row.appendChild( opts );
			}
			return;
		}

		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
	}

	/**
	 * Render saved custom fields into the "Add Field" list.
	 *
	 * @param {Array} fields Fields array.
	 * @return {void}
	 */
	function renderCustomFields( fields ) {
		const listDom = document.getElementById( 'nxtcc-custom-fields-list' );

		if ( ! listDom ) {
			return;
		}

		emptyNode( listDom );

		const arr = Array.isArray( fields ) ? fields : [];

		if ( ! arr.length ) {
			return;
		}

		arr.forEach( ( field ) => {
			const row = buildCustomFieldRow();

			const labelInput = row.querySelector( '.nxtcc-custom-field-label' );
			const typeSelect = row.querySelector( '.nxtcc-custom-field-type' );
			const valueInput = row.querySelector( '.nxtcc-custom-field-value' );

			if ( labelInput ) {
				labelInput.value = field.label || '';
			}

			if ( typeSelect ) {
				typeSelect.value = field.type || 'text';
			}

			if ( valueInput ) {
				valueInput.value = field.value || '';
			}

			syncStaticRowOptionsInput( row, field.type || 'text' );

			if ( field.type === 'dropdown' ) {
				const optsInput = row.querySelector( '.nxtcc-custom-field-options' );
				if ( optsInput ) {
					optsInput.value = Array.isArray( field.options ) ? field.options.join( ', ' ) : '';
				}
			}

			listDom.appendChild( row );
		} );
	}

	/**
	 * Build one dynamic field row for a column definition.
	 *
	 * @param {Object} col Column object.
	 * @param {Object|null} found Matching saved field.
	 * @return {HTMLElement} Row element.
	 */
	function buildDynamicFieldRow( col, found ) {
		const row     = el( 'div', { className: 'nxtcc-form-row' } );
		const labelEl = el( 'label', { text: col.label } );

		const value =
			found && typeof found.value !== 'undefined'
				? String( found.value )
				: '';

		const type = found && found.type ? found.type : ( col.type || 'text' );

		const options =
			found && Array.isArray( found.options )
				? found.options
				: ( col.options || [] );

		row.appendChild( labelEl );

		if ( type === 'dropdown' ) {
			const wrap = el( 'div', {
				className: 'nxtcc-dynamic-dropdown-wrap',
			} );
			wrap.setAttribute( 'data-label', col.label );

			const select = el( 'select', {
				className: 'nxtcc-custom-field-dynamic-input',
			} );
			select.setAttribute( 'data-label', col.label );
			select.setAttribute( 'data-type', 'dropdown' );

			select.appendChild( el( 'option', { value: '', text: '--Select--' } ) );

			( options || [] ).forEach( ( opt ) => {
				const optNode = el( 'option', { value: String( opt ), text: String( opt ) } );

				if ( String( opt ) === String( value ) ) {
					optNode.selected = true;
				}

				select.appendChild( optNode );
			} );

			const addLink            = el( 'a', { href: '#', className: 'nxtcc-add-dropdown-option', text: 'Add' } );
			addLink.style.marginLeft = '8px';
			addLink.style.fontSize   = '13px';

			wrap.appendChild( select );
			wrap.appendChild( addLink );

			row.appendChild( wrap );

			return row;
		}

		const inputType = type === 'date' ? 'date' : ( type === 'number' ? 'number' : ( type === 'email' ? 'email' : 'text' ) );

		const input = el( 'input', {
			type: inputType,
			className: 'nxtcc-custom-field-dynamic-input',
		} );

		input.setAttribute( 'data-label', col.label );
		input.setAttribute( 'data-type', type );

		if ( value ) {
			input.value = value;
		}

		row.appendChild( input );

		return row;
	}

	/**
	 * Render dynamic custom fields generated from runtime columns.
	 *
	 * @param {Array} fields Saved fields array.
	 * @return {void}
	 */
	function renderDynamicCustomFields( fields ) {
		const container = document.getElementById( 'nxtcc-dynamic-custom-fields-list' );

		if ( ! container ) {
			return;
		}

		emptyNode( container );

		const customCols = ( S.columns || [] ).filter(
			( col ) => String( col.key || '' ).indexOf( 'custom__' ) === 0
		);

		const arr = Array.isArray( fields ) ? fields : [];

		customCols.forEach( ( col ) => {
			const found = arr.find( ( f ) => f && f.label === col.label ) || null;
			container.appendChild( buildDynamicFieldRow( col, found ) );
		} );
	}

	/**
	 * Add option to a dynamic dropdown field.
	 */
	$( document ).on( 'click', '.nxtcc-add-dropdown-option', function ( e ) {
		e.preventDefault();

		const $wrap   = $( this ).closest( '.nxtcc-dynamic-dropdown-wrap' );
		const $select = $wrap.find( 'select' );
		const label   = $wrap.data( 'label' );

		const newOptRaw = prompt( 'Enter new option value:' );
		const newOpt    = newOptRaw ? String( newOptRaw ).trim() : '';

		if ( ! newOpt ) {
			return;
		}

		const exists = $select.find( 'option' ).filter( function () {
			return String( $( this ).val() ) === newOpt;
		} ).length;

		if ( exists ) {
			alert( 'Option already exists!' );
			return;
		}

		/*
		 * Add option using native DOM to avoid HTML sink APIs.
		 */
		const selectDom = $select.length ? $select.get( 0 ) : null;

		if ( selectDom ) {
			const opt = el( 'option', { value: newOpt, text: newOpt } );
			selectDom.appendChild( opt );
			$select.val( newOpt );
		}

		/*
		 * Persist the option into runtime columns so it can be sent in custom_fields payload.
		 */
		const col = ( S.columns || [] ).find( ( c ) => c.key === 'custom__' + label );

		if ( col ) {
			col.options = ( col.options || [] ).concat( [ newOpt ] );

			/*
			 * Table module may not expose saveColPrefs; this call is optional.
			 */
			if ( T && typeof T.saveColPrefs === 'function' ) {
				T.saveColPrefs();
			}
		}

	} );

	/**
	 * Add a blank custom field row in the "Add Field" list.
	 */
	$widget.on( 'click', '#nxtcc-add-custom-field-btn', function ( e ) {
		e.preventDefault();

		const listDom = document.getElementById( 'nxtcc-custom-fields-list' );

		if ( ! listDom ) {
			return;
		}

		listDom.appendChild( buildCustomFieldRow() );
	} );

	/**
	 * Remove a custom field row.
	 */
	$widget.on( 'click', '.nxtcc-remove-custom-field', function ( e ) {
		e.preventDefault();

		const row = $( this ).closest( '.nxtcc-custom-field-row' ).get( 0 );

		if ( row && row.parentNode ) {
			row.parentNode.removeChild( row );
		}
	} );

	/**
	 * Toggle options input for dropdown type rows.
	 */
	$widget.on( 'change', '.nxtcc-custom-field-type', function () {
		const row  = $( this ).closest( '.nxtcc-custom-field-row' ).get( 0 );
		const type = String( $( this ).val() || '' );

		if ( row ) {
			syncStaticRowOptionsInput( row, type );
		}
	} );

	// -------------------------------------------------------------------------
	// Modal open/close.
	// -------------------------------------------------------------------------

	/**
	 * Open the contact modal in add/edit mode and populate fields.
	 *
	 * @param {string} mode Mode (add|edit).
	 * @param {Object} data Contact data.
	 * @return {void}
	 */
	function openModal( mode, data ) {
		const payload = data || {};

		$( '#nxtcc-contact-modal-title' ).text( mode === 'edit' ? 'Edit Contact' : 'Add Contact' );
		$( '#nxtcc-contact-form' )[ 0 ].reset();

		/*
		 * Store current contact so submit can detect "clear" and send remove flags.
		 */
		S.editingContact = payload;

		const parsedCustomFields = normalizeCustomFields( payload.custom_fields );
		const groupsArr          = normalizeGroups( payload.groups );

		$( '#nxtcc-contact-id' ).val( payload.id || '' );
		$( '#nxtcc-contact-name' ).val( payload.name || '' );
		$( '#nxtcc-country-code' ).val( payload.country_code || '' );
		$( '#nxtcc-phone-number' ).val( payload.phone_number || '' );

		/*
		 * Subscribed checkbox defaults off unless explicitly set to 1.
		 */
		if ( typeof payload.is_subscribed !== 'undefined' ) {
			$( '#nxtcc-contact-subscribed' ).prop( 'checked', String( payload.is_subscribed ) === '1' );
		} else {
			$( '#nxtcc-contact-subscribed' ).prop( 'checked', false );
		}

		const isLinkedVerified =
			Number( payload.is_verified ) === 1 &&
			payload.wp_uid !== null &&
			payload.wp_uid !== undefined;
		const verifiedGroupId = getVerifiedGroupId();
		const hasProtectedVerifiedGroup =
			!! verifiedGroupId &&
			Array.isArray( groupsArr ) &&
			groupsArr.map( String ).includes( String( verifiedGroupId ) );

		modalLockedVerifiedGroupId = null;

		if ( hasProtectedVerifiedGroup ) {
			modalLockedVerifiedGroupId = String( verifiedGroupId );
		}

		loadGroupsDropdown( groupsArr || [], {
			hasProtectedVerifiedGroup: hasProtectedVerifiedGroup,
			lockedVerifiedGroupId: modalLockedVerifiedGroupId,
		} );

		/*
		 * Render dynamic custom fields from columns and saved values.
		 */
		renderDynamicCustomFields( parsedCustomFields );

		$( '#nxtcc-add-custom-field-btn' ).show();
		$( '#nxtcc-custom-fields-list' ).show();

		if ( mode !== 'edit' ) {
			renderCustomFields( parsedCustomFields );
		} else {
			const listDom = document.getElementById( 'nxtcc-custom-fields-list' );
			if ( listDom ) {
				emptyNode( listDom );
			}
		}

		/*
		 * Update country autocomplete list (prefer actions module when available).
		 */
		if ( R.actions && typeof R.actions.updateCountryAutocomplete === 'function' ) {
			R.actions.updateCountryAutocomplete();
		} else {
			const list = document.getElementById( 'nxtcc-country-codes-list' );

			if ( list ) {
				emptyNode( list );

				const codes = S.allCountryCodes && S.allCountryCodes.length
					? S.allCountryCodes
					: [ ...new Set( ( S.allContacts || [] ).map( ( c ) => c.country_code ) ) ];

				codes
					.filter( Boolean )
					.sort()
					.forEach( ( code ) => {
						list.appendChild( el( 'option', { value: String( code ) } ) );
					} );
			}
		}

		if ( hasProtectedVerifiedGroup || isLinkedVerified ) {
			$( '#nxtcc-verified-contact-hint' ).show();
		} else {
			$( '#nxtcc-verified-contact-hint' ).hide();
		}

		if ( isLinkedVerified ) {
			$( '#nxtcc-country-code, #nxtcc-phone-number' )
				.prop( 'disabled', true )
				.attr( 'title', 'Locked for verified contacts' );
		} else {
			$( '#nxtcc-country-code, #nxtcc-phone-number' )
				.prop( 'disabled', false )
				.removeAttr( 'title' );
		}

		$( '#nxtcc-contact-modal' ).fadeIn( 120 );
	}

	/**
	 * Close the contact modal and reset its UI state.
	 *
	 * @return {void}
	 */
	function closeModal() {
		$( '#nxtcc-contact-modal' ).fadeOut( 120 );
		$( '#nxtcc-contact-form' )[ 0 ].reset();

		{
			const listDom = document.getElementById( 'nxtcc-custom-fields-list' );
			if ( listDom ) {
				emptyNode( listDom );
			}
		}

		{
			const dynDom = document.getElementById( 'nxtcc-dynamic-custom-fields-list' );
			if ( dynDom ) {
				emptyNode( dynDom );
			}
		}

		modalLockedVerifiedGroupId = null;
		S.editingContact           = null;
	}

	$widget.on(
		'click',
		'#nxtcc-contact-modal-close, #nxtcc-cancel-contact-modal, #nxtcc-contact-modal .nxtcc-modal-overlay',
		function ( e ) {
			e.preventDefault();
			closeModal();
		}
	);

	$( document ).on( 'keydown.nxtccModal', function ( e ) {
		if ( $( '#nxtcc-contact-modal' ).is( ':visible' ) && e.key === 'Escape' ) {
			closeModal();
		}
	} );

	// -------------------------------------------------------------------------
	// Add / Edit / Save / Delete.
	// -------------------------------------------------------------------------

	$widget.on( 'click', '#nxtcc-add-contact-btn', function ( e ) {
		e.preventDefault();

		if ( ! hasConnection ) {
			alert( 'Please connect WhatsApp Cloud API first.' );
			return;
		}

		openModal( 'add', {} );
	} );

	$widget.on( 'click', '.nxtcc-edit-contact', function ( e ) {
		e.preventDefault();

		const contactId = $( this ).data( 'id' );
		const idNum     = parseInt( contactId, 10 );

		if ( ! idNum ) {
			alert( 'Invalid contact id.' );
			return;
		}

		const payload = {
			action: 'nxtcc_contacts_get',
			nonce,
			instance_id: instanceId,
			id: idNum,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success && resp.data && resp.data.contact ) {
				const c = resp.data.contact;

				/*
				 * Modal expects groups array in data.groups.
				 */
				c.groups = resp.data.group_ids || [];

				/*
				 * custom_fields may come as JSON string.
				 */
				c.custom_fields = normalizeCustomFields( c.custom_fields );

				openModal( 'edit', c );
			} else {
				const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Failed to load contact';
				alert( msg );
			}
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	/**
	 * Save contact and preserve locked verified group client-side.
	 */
	$widget.on( 'submit', '#nxtcc-contact-form', function ( e ) {
		e.preventDefault();

		/*
		 * PHP expects: id, is_subscribed, group_ids, custom_fields_json.
		 */
		const idVal      = String( $( '#nxtcc-contact-id' ).val() || '' ).trim();
		const isEditMode = ! ! idVal;

		const payload = {
			action: 'nxtcc_contacts_save',
			nonce,
			instance_id: instanceId,
			id: idVal ? idVal : '',
			name: String( $( '#nxtcc-contact-name' ).val() || '' ).trim(),
			country_code: String( $( '#nxtcc-country-code' ).val() || '' )
				.replace( /^\+/, '' )
				.replace( /\D/g, '' ),
			phone_number: String( $( '#nxtcc-phone-number' ).val() || '' ).replace( /\D/g, '' ),
			is_subscribed: $( '#nxtcc-contact-subscribed' ).is( ':checked' ) ? 1 : 0,
		};

		/*
		 * PHP expects group_ids.
		 */
		let groupIds = $( '#nxtcc-contact-groups' ).val() || [];
		groupIds     = groupIds.map( ( x ) => parseInt( x, 10 ) ).filter( Boolean );

		if ( modalLockedVerifiedGroupId ) {
			const vg = parseInt( modalLockedVerifiedGroupId, 10 );
			if ( vg && ! groupIds.includes( vg ) ) {
				groupIds.push( vg );
			}
		}

		payload.group_ids = groupIds;

		/*
		 * custom_fields_json:
		 * - Dynamic inputs reflect existing columns.
		 * - "Add Field" list allows adding new fields.
		 * - In edit mode, clearing a value sends remove:1.
		 */
		const customFields = [];

		$( '#nxtcc-dynamic-custom-fields-list .nxtcc-custom-field-dynamic-input' ).each( function () {
			const label = $( this ).data( 'label' );
			const type  = $( this ).data( 'type' ) || 'text';
			const value = $( this ).val();

			if ( ! label ) {
				return;
			}

			const col     = ( S.columns || [] ).find( ( c ) => c.key === 'custom__' + label );
			const options = col && Array.isArray( col.options ) ? col.options : [];

			if ( isEditMode && ( value === '' || value === null ) ) {
				customFields.push( {
					label: String( label ),
					type: String( type ),
					value: '',
					options,
					remove: 1,
				} );
				return;
			}

			if ( value === '' || value === null ) {
				return;
			}

			customFields.push( {
				label: String( label ),
				type: String( type ),
				value: String( value ),
				options,
			} );
		} );

		$( '#nxtcc-custom-fields-list .nxtcc-custom-field-row' ).each( function () {
			const label = String( $( this ).find( '.nxtcc-custom-field-label' ).val() || '' ).trim();
			const type  = String( $( this ).find( '.nxtcc-custom-field-type' ).val() || 'text' ).trim();
			const value = String( $( this ).find( '.nxtcc-custom-field-value' ).val() || '' ).trim();

			let options = [];

			if ( type === 'dropdown' ) {
				const opts = String( $( this ).find( '.nxtcc-custom-field-options' ).val() || '' ).trim();
				options    = opts
					? opts.split( ',' ).map( ( x ) => x.trim() ).filter( Boolean )
					: [];
			}

			if ( ! label ) {
				return;
			}

			if ( isEditMode && value === '' ) {
				customFields.push( { label, type, value: '', options, remove: 1 } );
				return;
			}

			if ( value === '' ) {
				return;
			}

			customFields.push( { label, type, value, options } );
		} );

		payload.custom_fields_json = JSON.stringify( customFields );

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success ) {
				closeModal();

				if ( R.actions && typeof R.actions.loadAll === 'function' ) {
					R.actions.loadAll( true );
				}
			} else {
				const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Failed to save contact';
				alert( msg );
			}
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	/**
	 * Delete a single contact.
	 */
	$widget.on( 'click', '.nxtcc-delete-contact', function ( e ) {
		e.preventDefault();

		if ( $( this ).is( ':disabled' ) ) {
			return;
		}

		if ( ! confirm( 'Delete this contact? This cannot be undone.' ) ) {
			return;
		}

		const contactId = $( this ).data( 'id' );
		const idNum     = parseInt( contactId, 10 );

		if ( ! idNum ) {
			alert( 'Invalid contact id.' );
			return;
		}

		const payload = {
			action: 'nxtcc_contacts_delete',
			nonce,
			instance_id: instanceId,
			id: idNum,
		};

		$.post( ajaxurl, payload, function ( resp ) {
			if ( resp && resp.success ) {
				if ( R.actions && typeof R.actions.loadAll === 'function' ) {
					R.actions.loadAll( true );
				}
			} else {
				const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Failed to delete contact';
				alert( msg );
			}
		} ).fail( function () {
			alert( 'Network error. Please try again.' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Select all + per-row checkbox.
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

	/*
	 * Keep escapeHtml referenced for compatibility with other modules.
	 */
	void escapeHtml;
} );
