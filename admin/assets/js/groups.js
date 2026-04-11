/**
 * Groups screen interactions (admin).
 *
 * Features:
 * - Loads the groups list with search + sortable columns.
 * - Add/Edit modal with verified-group lock behavior.
 * - Single delete and bulk actions with selection UI.
 *
 * Notes:
 * - Server enforces tenant scoping, permissions, and nonce validation.
 * - This file avoids jQuery HTML-string insertion to satisfy VIP/PHPCS warnings.
 *
 * @package NXTCC
 */

/* global jQuery, wp, NXTCC_GroupsData, ajaxurl */

jQuery( function ( $ ) {
	'use strict';

	const $widget = $( '.nxtcc-groups-widget' );
	if ( ! $widget.length ) {
		return;
	}

	// Prefer localized nonce, fallback to markup data-nonce.
	const nonce = ( window.NXTCC_GroupsData && window.NXTCC_GroupsData.nonce )
		? String( window.NXTCC_GroupsData.nonce )
		: String( $widget.data( 'nonce' ) || '' );

	// Prefer localized ajaxurl, fallback to global ajaxurl.
	const ajaxEndpoint =
		( window.NXTCC_GroupsData &&
			window.NXTCC_GroupsData.ajaxurl &&
			String( window.NXTCC_GroupsData.ajaxurl ) ) ||
		( typeof window.ajaxurl === 'string' && window.ajaxurl ) ||
		'';

	if ( ! ajaxEndpoint || ! nonce ) {
		return;
	}

	const hasConnection = Number( $widget.data( 'has-connection' ) ) === 1;

	const selectedIds = new Set();

	const state = {
		search: '',
		sort_key: 'group_name',
		sort_dir: 'asc',
	};

	let inflightListXHR = null;
	let modalIsVerified = false;

	const __ =
		window.wp && wp.i18n && typeof wp.i18n.__ === 'function'
			? wp.i18n.__
			: ( s ) => s;

	/**
	 * Append a DOM node or jQuery node without inserting HTML strings.
	 *
	 * @param {jQuery}         $parent Parent container.
	 * @param {Element|jQuery} child   Child node.
	 * @return {void}
	 */
	function safeAppend( $parent, child ) {
		const parentEl = $parent && $parent.get ? $parent.get( 0 ) : null;
		if ( ! parentEl ) {
			return;
		}

		const el = child && child.get ? child.get( 0 ) : child;
		if ( el && el.nodeType ) {
			parentEl.appendChild( el );
		}
	}

	/**
	 * Remove all children from an element.
	 *
	 * @param {jQuery} $parent Parent container.
	 * @return {void}
	 */
	function safeEmpty( $parent ) {
		const parentEl = $parent && $parent.get ? $parent.get( 0 ) : null;
		if ( ! parentEl ) {
			return;
		}
		while ( parentEl.firstChild ) {
			parentEl.removeChild( parentEl.firstChild );
		}
	}

	const toInt = ( v ) => {
		const n = Number( v );
		return Number.isFinite( n ) ? n : 0;
	};

	const toBool = ( v ) => Number( v ) === 1;

	const toStr = ( v ) => ( v == null ? '' : String( v ) );

	/**
	 * Normalize a client-side search string.
	 *
	 * @param {*} val Value.
	 * @return {string} Normalized search string.
	 */
	function safeSearch( val ) {
		return toStr( val )
			.replace( /[\u0000-\u001F\u007F]/g, '' )
			.trim()
			.slice( 0, 200 );
	}

	/**
	 * Get selected group IDs for bulk actions.
	 *
	 * @return {Array<number>} Selected IDs.
	 */
	function getSelectedIds() {
		return Array.from( selectedIds );
	}

	/**
	 * Abort an inflight list request to avoid race conditions.
	 *
	 * @return {void}
	 */
	function abortInflightList() {
		if ( inflightListXHR && typeof inflightListXHR.abort === 'function' ) {
			try {
				inflightListXHR.abort();
			} catch ( e ) {
				// ignore abort failures.
			}
		}
		inflightListXHR = null;
	}

	/**
	 * Post to admin-ajax with the expected nonce field.
	 *
	 * @param {Object}   payload Payload.
	 * @param {Function} done    Success callback.
	 * @param {Function} fail    Error callback.
	 * @return {Object} jqXHR.
	 */
	function postAjax( payload, done, fail ) {
		const body = Object.assign( {}, payload, { nonce: nonce } );

		// traditional:true helps serialize arrays like group_ids[] in a predictable way.
		return $.ajax( {
			url: ajaxEndpoint,
			method: 'POST',
			dataType: 'json',
			data: body,
			traditional: true,
		} )
			.done( done )
			.fail( fail );
	}

	/**
	 * Apply sort direction classes to the table headers.
	 *
	 * @return {void}
	 */
	function applySortHeaderUI() {
		$( '.nxtcc-groups-table thead th.sortable' ).removeClass( 'is-asc is-desc' );

		const key = String( state.sort_key || '' ).trim();
		if ( ! key ) {
			return;
		}

		const selector =
			'.nxtcc-groups-table thead th.sortable[data-key="' +
			key.replace( /"/g, '\\"' ) +
			'"]';

		const $th = $( selector );
		$th.addClass( state.sort_dir === 'asc' ? 'is-asc' : 'is-desc' );
	}

	/**
	 * Keep the "select all" checkbox in sync with row selection.
	 *
	 * @return {void}
	 */
	function updateMasterCheckbox() {
		const $rows = $( '.nxtcc-group-select' ).not( ':disabled' );

		if ( ! $rows.length ) {
			$( '#nxtcc-groups-select-all' ).prop( {
				checked: false,
				indeterminate: false,
			} );
			return;
		}

		const total   = $rows.length;
		const checked = $rows.filter( ':checked' ).length;

		$( '#nxtcc-groups-select-all' )
			.prop( 'checked', checked === total && total > 0 )
			.prop( 'indeterminate', checked > 0 && checked < total );
	}

	/**
	 * Show or hide the bulk actions toolbar depending on selection.
	 *
	 * @return {void}
	 */
	function updateBulkVisibility() {
		const any = $( '.nxtcc-group-select:checked' ).length > 0;
		$( '#nxtcc-groups-bulk-actions' ).toggle( any );
		updateMasterCheckbox();
	}

	/**
	 * Replace tbody with a single informational message row.
	 *
	 * @param {string} text Message.
	 * @param {string} tone neutral|danger|warn.
	 * @return {void}
	 */
	function setTbodyMessage( text, tone ) {
		const tones = {
			neutral: '#667085',
			danger: '#b91c1c',
			warn: '#b45309',
		};

		const color = tones[ tone ] || tones.neutral;

		const $tbody = $( '.nxtcc-groups-table tbody' );
		safeEmpty( $tbody );

		const $tr = $( '<tr/>' );
		const $td = $( '<td/>' ).attr( 'colspan', 99 );

		$td.css( {
			textAlign: 'center',
			color: color,
		} );

		$td.text( toStr( text ) );

		safeAppend( $tr, $td );
		safeAppend( $tbody, $tr );

		$( '#nxtcc-groups-select-all' ).prop( {
			checked: false,
			indeterminate: false,
		} );

		$( '#nxtcc-groups-bulk-actions' ).hide();
	}

	/**
	 * Open the add/edit modal and populate fields.
	 *
	 * @param {string} mode add|edit.
	 * @param {Object} data Group payload.
	 * @return {void}
	 */
	function openGroupModal( mode, data ) {
		const payload = data && typeof data === 'object' ? data : {};

		if ( ! hasConnection ) {
			return;
		}

		$( '#nxtcc-group-modal-title' ).text(
			mode === 'edit' ? __( 'Edit Group', 'nxt-cloud-chat' ) : __( 'Add Group', 'nxt-cloud-chat' )
		);

		const $form = $( '#nxtcc-group-form' );
		if ( $form[ 0 ] ) {
			$form[ 0 ].reset();
		}

		$( '#nxtcc-group-id' ).val( toInt( payload.id ) || '' );
		$( '#nxtcc-group-name' ).val( toStr( payload.group_name ) );

		modalIsVerified = toBool( payload.is_verified );

		const $name = $( '#nxtcc-group-name' );
		const $save = $( '.nxtcc-groups-modal-content .nxtcc-groups-btn-green' );

		if ( mode === 'edit' && modalIsVerified ) {
			$name.prop( 'disabled', true );
			$( '.nxtcc-verified-hint' ).show();
			$save.prop( 'disabled', true ).addClass( 'is-disabled' );
		} else {
			$name.prop( 'disabled', false );
			$( '.nxtcc-verified-hint' ).hide();
			$save.prop( 'disabled', false ).removeClass( 'is-disabled' );
		}

		$( '#nxtcc-group-modal' ).fadeIn( 150 );
	}

	/**
	 * Close the add/edit modal.
	 *
	 * @return {void}
	 */
	function closeGroupModal() {
		$( '#nxtcc-group-modal' ).fadeOut( 150 );
	}

	$widget.on(
		'click.nxtcc',
		'.nxtcc-groups-modal-close, .nxtcc-groups-modal-overlay',
		closeGroupModal
	);

	$( document ).on( 'keydown.nxtcc', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeGroupModal();
		}
	} );

	/**
	 * Build a single table row for a group.
	 *
	 * @param {Object} row Group row.
	 * @return {jQuery} Row element.
	 */
	function buildRow( row ) {
		const id         = toInt( row.id );
		const isVerified = toBool( row.is_verified || 0 );

		if ( isVerified ) {
			selectedIds.delete( id );
		}

		const $tr = $( '<tr/>' ).attr( {
			'data-id': id,
			'data-is_verified': isVerified ? 1 : 0,
		} );
		$tr.addClass( 'nxtcc-group-row' );

		const $cb = $( '<input/>' ).attr( {
			type: 'checkbox',
			'data-id': id,
		} );
		$cb.addClass( 'nxtcc-group-select' );
		$cb.prop( {
			disabled: isVerified,
			checked: ! isVerified && selectedIds.has( id ),
		} );

		const $cbTd = $( '<td/>' ).addClass( 'checkbox-col' );
		safeAppend( $cbTd, $cb );
		safeAppend( $tr, $cbTd );

		const $nameCol = $( '<td/>' ).addClass( 'group-name' );

		safeAppend(
			$nameCol,
			$( '<span/>' )
				.addClass( 'nxtcc-group-name-text' )
				.text( toStr( row.group_name ) )
		);

		if ( isVerified ) {
			const $badge = $( '<span/>' )
				.addClass( 'nxtcc-lock-badge' )
				.attr( 'title', __( 'Verified group — locked', 'nxt-cloud-chat' ) )
				.text( '🔒' )
				.css( { marginLeft: '6px' } );

			safeAppend( $nameCol, $badge );
		}

		safeAppend( $tr, $nameCol );

		safeAppend(
			$tr,
			$( '<td/>' )
				.addClass( 'group-count' )
				.text( String( toInt( row.count ) || 0 ) )
		);

		safeAppend(
			$tr,
			$( '<td/>' )
				.addClass( 'group-created-by' )
				.text( toStr( row.created_by ) )
		);

		const disableActions = ! hasConnection || isVerified;

		const $renameBtn = $( '<button/>' )
			.attr( { type: 'button', 'data-id': id } )
			.addClass( 'nxtcc-groups-btn btn-rename' )
			.prop( 'disabled', disableActions )
			.text( __( 'Rename', 'nxt-cloud-chat' ) );

		const $deleteBtn = $( '<button/>' )
			.attr( { type: 'button', 'data-id': id } )
			.addClass( 'nxtcc-groups-btn btn-delete' )
			.prop( 'disabled', disableActions )
			.text( __( 'Delete', 'nxt-cloud-chat' ) );

		const $actionsCol = $( '<td/>' ).addClass( 'actions-col' );

		safeAppend( $actionsCol, $renameBtn );
		safeAppend( $actionsCol, $( '<span/>' ).text( ' ' ) );
		safeAppend( $actionsCol, $deleteBtn );

		safeAppend( $tr, $actionsCol );

		return $tr;
	}

	/**
	 * Render groups list into the table body.
	 *
	 * @param {Array<Object>} rows Groups.
	 * @return {void}
	 */
	function renderGroupsTable( rows ) {
		const $tbody = $( '.nxtcc-groups-table tbody' );
		safeEmpty( $tbody );

		if ( Array.isArray( rows ) ) {
			const idsInRows = new Set( rows.map( ( r ) => toInt( r.id ) ) );
			Array.from( selectedIds ).forEach( ( id ) => {
				if ( ! idsInRows.has( id ) ) {
					selectedIds.delete( id );
				}
			} );
		}

		if ( ! Array.isArray( rows ) || ! rows.length ) {
			setTbodyMessage( __( 'No groups found.', 'nxt-cloud-chat' ), 'neutral' );
			return;
		}

		rows.forEach( ( row ) => {
			safeAppend( $tbody, buildRow( row ) );
		} );

		updateBulkVisibility();
	}

	/**
	 * Load groups list from the server (abortable).
	 *
	 * @param {Object} extra Extra args.
	 * @return {void}
	 */
	function loadGroups( extra ) {
		const extraArgs = extra && typeof extra === 'object' ? extra : {};

		abortInflightList();

		const params = Object.assign(
			{
				action: 'nxtcc_fetch_groups_list',
				search: state.search,
				sort_key: state.sort_key,
				sort_dir: state.sort_dir,
			},
			extraArgs
		);

		setTbodyMessage( __( 'Loading groups…', 'nxt-cloud-chat' ), 'neutral' );

		inflightListXHR = postAjax(
			params,
			function ( resp ) {
				inflightListXHR = null;

				if ( ! resp || resp.success !== true ) {
					const msg =
						resp && resp.data && resp.data.message
							? toStr( resp.data.message )
							: __( 'Failed to load groups.', 'nxt-cloud-chat' );
					setTbodyMessage( msg, 'danger' );
					applySortHeaderUI();
					return;
				}

				if ( resp.data && resp.data.has_tenant === false ) {
					setTbodyMessage(
						__( 'Connect your WhatsApp Business account and phone number to manage groups.', 'nxt-cloud-chat' ),
						'warn'
					);
					applySortHeaderUI();
					return;
				}

				const groups =
					resp.data && Array.isArray( resp.data.groups )
						? resp.data.groups
						: [];

				renderGroupsTable( groups );
				applySortHeaderUI();
			},
			function () {
				inflightListXHR = null;
				setTbodyMessage( __( 'Failed to load groups.', 'nxt-cloud-chat' ), 'danger' );
			}
		);
	}

	$widget.on( 'click.nxtcc', '#nxtcc-add-group-btn', function () {
		if ( ! hasConnection || $( this ).is( ':disabled' ) ) {
			return;
		}
		openGroupModal( 'add', {} );
	} );

	$widget.on( 'click.nxtcc', '.btn-rename', function () {
		const $btn = $( this );

		if ( ! hasConnection || $btn.is( ':disabled' ) ) {
			return;
		}

		const groupId = toInt( $btn.data( 'id' ) );
		if ( ! groupId ) {
			return;
		}

		postAjax(
			{ action: 'nxtcc_fetch_single_group', group_id: groupId },
			function ( resp ) {
				if ( resp && resp.success && resp.data ) {
					resp.data.is_verified = toInt( resp.data.is_verified || 0 );
					openGroupModal( 'edit', resp.data );
					return;
				}

				window.alert(
					( resp && resp.data && resp.data.message ) ||
						__( 'Failed to load group.', 'nxt-cloud-chat' )
				);
			},
			function () {
				window.alert( __( 'Network error. Please try again.', 'nxt-cloud-chat' ) );
			}
		);
	} );

	$widget.on( 'submit.nxtcc', '#nxtcc-group-form', function ( e ) {
		e.preventDefault();

		if ( ! hasConnection ) {
			return;
		}

		if ( modalIsVerified ) {
			window.alert( __( 'Verified groups cannot be renamed.', 'nxt-cloud-chat' ) );
			return;
		}

		const $form     = $( this );
		const formArray = $form.serializeArray();
		const formData  = { action: 'nxtcc_save_group' };

		formArray.forEach( ( it ) => {
			formData[ it.name ] = it.value;
		} );

		const nameVal = toStr( formData.group_name || '' ).trim();
		if ( ! nameVal ) {
			window.alert( __( 'Group name is required.', 'nxt-cloud-chat' ) );
			return;
		}

		const $save = $( '.nxtcc-groups-modal-content .nxtcc-groups-btn-green' )
			.prop( 'disabled', true )
			.text( __( 'Saving…', 'nxt-cloud-chat' ) );

		postAjax(
			formData,
			function ( resp ) {
				$save.prop( 'disabled', false ).text( __( 'Save Group', 'nxt-cloud-chat' ) );

				if ( resp && resp.success ) {
					closeGroupModal();
					loadGroups();
					return;
				}

				window.alert(
					( resp && resp.data && resp.data.message ) ||
						__( 'Failed to save group.', 'nxt-cloud-chat' )
				);
			},
			function () {
				$save.prop( 'disabled', false ).text( __( 'Save Group', 'nxt-cloud-chat' ) );
				window.alert( __( 'Network error. Please try again.', 'nxt-cloud-chat' ) );
			}
		);
	} );

	$widget.on( 'click.nxtcc', '.btn-delete', function () {
		const $btn = $( this );

		if ( ! hasConnection || $btn.is( ':disabled' ) ) {
			return;
		}

		if ( ! window.confirm( __( 'Delete this group? This cannot be undone.', 'nxt-cloud-chat' ) ) ) {
			return;
		}

		const groupId = toInt( $btn.data( 'id' ) );
		if ( ! groupId ) {
			return;
		}

		selectedIds.delete( groupId );

		$btn.prop( 'disabled', true ).text( __( 'Deleting…', 'nxt-cloud-chat' ) );

		postAjax(
			{ action: 'nxtcc_delete_group', group_id: groupId },
			function ( resp ) {
				$btn.prop( 'disabled', false ).text( __( 'Delete', 'nxt-cloud-chat' ) );

				if ( resp && resp.success ) {
					loadGroups();
					return;
				}

				window.alert(
					( resp && resp.data && resp.data.message ) ||
						__( 'Failed to delete group.', 'nxt-cloud-chat' )
				);
			},
			function () {
				$btn.prop( 'disabled', false ).text( __( 'Delete', 'nxt-cloud-chat' ) );
				window.alert( __( 'Network error. Please try again.', 'nxt-cloud-chat' ) );
			}
		);
	} );

	$widget.on( 'change.nxtcc', '.nxtcc-group-select', function () {
		const id = toInt( $( this ).data( 'id' ) );

		if ( $( this ).is( ':checked' ) ) {
			selectedIds.add( id );
		} else {
			selectedIds.delete( id );
		}

		updateBulkVisibility();
	} );

	$widget.on( 'change.nxtcc', '#nxtcc-groups-select-all', function () {
		const checked = $( this ).prop( 'checked' );

		$( '.nxtcc-group-select' ).each( function () {
			const $cb = $( this );

			if ( $cb.is( ':disabled' ) ) {
				return;
			}

			const id = toInt( $cb.data( 'id' ) );
			$cb.prop( 'checked', checked );

			if ( checked ) {
				selectedIds.add( id );
			} else {
				selectedIds.delete( id );
			}
		} );

		updateBulkVisibility();
	} );

	$widget.on( 'click.nxtcc', '#nxtcc-groups-bulk-apply', function () {
		const $apply = $( this );

		if ( ! hasConnection || $apply.is( ':disabled' ) ) {
			return;
		}

		const actionVal = $( '#nxtcc-groups-bulk-select' ).val();
		let ids         = getSelectedIds();

		if ( ! actionVal ) {
			window.alert( __( 'Choose a bulk action.', 'nxt-cloud-chat' ) );
			return;
		}

		if ( ! ids.length ) {
			window.alert( __( 'Select at least one group.', 'nxt-cloud-chat' ) );
			return;
		}

		if ( actionVal === 'delete' ) {
			const verifiedIds = [];

			ids            = ids.filter( ( id ) => {
				const $row = $( 'tr[data-id="' + id + '"]' );
				const isV  = toInt( $row.attr( 'data-is_verified' ) ) === 1;

				if ( isV ) {
					verifiedIds.push( id );
				}

				return ! isV;
			} );

			if ( ! ids.length ) {
				window.alert( __( 'No deletable groups selected. Verified groups are locked.', 'nxt-cloud-chat' ) );
				return;
			}

			if ( verifiedIds.length ) {
				window.alert( __( 'Verified groups were excluded from bulk delete.', 'nxt-cloud-chat' ) );
			}

			if ( ! window.confirm( __( 'Delete the selected groups? This cannot be undone.', 'nxt-cloud-chat' ) ) ) {
				return;
			}
		}

		$apply.prop( 'disabled', true ).text( __( 'Working…', 'nxt-cloud-chat' ) );

		postAjax(
			{
				action: 'nxtcc_groups_bulk_action',
				bulk_action: actionVal,
				'group_ids[]': ids,
			},
			function ( resp ) {
				$apply.prop( 'disabled', false ).text( __( 'Apply', 'nxt-cloud-chat' ) );

				if ( resp && resp.success ) {
					if ( actionVal === 'delete' && resp.data && Array.isArray( resp.data.deleted_ids ) ) {
						resp.data.deleted_ids.forEach( ( id ) => {
							selectedIds.delete( toInt( id ) );
						} );
					}

					loadGroups();
					$( '#nxtcc-groups-bulk-actions' ).hide();
					return;
				}

				window.alert(
					( resp && resp.data && resp.data.message ) ||
						__( 'Bulk action failed.', 'nxt-cloud-chat' )
				);
			},
			function () {
				$apply.prop( 'disabled', false ).text( __( 'Apply', 'nxt-cloud-chat' ) );
				window.alert( __( 'Network error. Please try again.', 'nxt-cloud-chat' ) );
			}
		);
	} );

	$widget.on( 'click.nxtcc', '.nxtcc-groups-table thead th.sortable', function () {
		const key = $( this ).data( 'key' );
		if ( ! key ) {
			return;
		}

		if ( state.sort_key === key ) {
			state.sort_dir = state.sort_dir === 'asc' ? 'desc' : 'asc';
		} else {
			state.sort_key = key;
			state.sort_dir = 'asc';
		}

		loadGroups();
	} );

	let searchTimer = null;

	$widget.on( 'input.nxtcc', '#nxtcc-groups-search', function () {
		state.search = safeSearch( $( this ).val() );

		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			loadGroups();
		}, 250 );
	} );

	// Initial load.
	loadGroups();
} );
