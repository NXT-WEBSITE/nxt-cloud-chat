/**
 * Tags management screen.
 *
 * @package NXTCC
 */

/* global jQuery, NXTCC_TagsData, document, window, alert, confirm */
/* eslint-disable no-alert */

jQuery( function ( $ ) {
	'use strict';

	const $widget = $( '.nxtcc-tags-widget' );
	if ( ! $widget.length ) {
		return;
	}

	const config        = window.NXTCC_TagsData || {};
	const ajaxurl       = String( config.ajaxurl || window.ajaxurl || '' );
	const nonce         = String( config.nonce || $widget.data( 'nonce' ) || '' );
	const canManage     = String( $widget.data( 'can-manage' ) || '0' ) === '1';
	const hasConnection = String( $widget.data( 'has-connection' ) || '0' ) === '1';
	const state         = {
		rows: [],
		page: 1,
		perPage: 20,
		total: 0,
		search: '',
		mergeIds: [],
	};

	/**
	 * Create a safe DOM element.
	 *
	 * @param {string} tag Tag name.
	 * @param {Object=} attrs Attributes.
	 * @param {string=} text Text.
	 * @return {HTMLElement} Element.
	 */
	function el( tag, attrs, text ) {
		const node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			const value = attrs[ key ];
			if ( null === value || typeof value === 'undefined' ) {
				return;
			}

			if ( 'className' === key ) {
				node.className = String( value );
				return;
			}

			if ( key in node ) {
				try {
					node[ key ] = value;
					return;
				} catch ( error ) {
					// Fall through to setAttribute.
				}
			}

			node.setAttribute( key, String( value ) );
		} );

		if ( typeof text !== 'undefined' ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Empty a DOM node.
	 *
	 * @param {HTMLElement} node Node.
	 * @return {void}
	 */
	function empty( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Send an AJAX request.
	 *
	 * @param {string} action Action.
	 * @param {Object=} data Request data.
	 * @return {jqXHR} Request.
	 */
	function request( action, data ) {
		return $.post(
			ajaxurl,
			$.extend(
				{
					action,
					nonce,
				},
				data || {}
			)
		);
	}

	/**
	 * Read selected tag IDs.
	 *
	 * @return {Array<string>} IDs.
	 */
	function selectedIds() {
		return $widget
			.find( '.nxtcc-tag-select:checked' )
			.map( function () {
				return String( $( this ).data( 'id' ) || '' );
			} )
			.get()
			.filter( Boolean );
	}

	/**
	 * Update bulk controls.
	 *
	 * @return {void}
	 */
	function updateBulkControls() {
		const ids = selectedIds();
		$( '#nxtcc-tags-selected-count' ).text( ids.length + ' selected' );
		$( '#nxtcc-tags-bulk-actions' ).toggle( ids.length > 0 );
		$( '#nxtcc-tags-merge-selected' ).prop( 'disabled', ids.length < 2 );
	}

	/**
	 * Render summary cards.
	 *
	 * @param {Object} stats Stats.
	 * @return {void}
	 */
	function renderStats( stats ) {
		stats = stats || {};
		$( '#nxtcc-tags-summary-total' ).text( String( Number( stats.total_tags || 0 ) ) );
		$( '#nxtcc-tags-summary-contacts' ).text( String( Number( stats.tagged_contacts || 0 ) ) );
		$( '#nxtcc-tags-summary-assignments' ).text( String( Number( stats.assignments || 0 ) ) );
	}

	/**
	 * Build a colored tag chip.
	 *
	 * @param {Object} row Tag row.
	 * @return {HTMLElement} Chip.
	 */
	function tagChip( row ) {
		const chip = el( 'span', { className: 'nxtcc-tag-chip' }, row.tag_name || '' );
		chip.style.setProperty( '--nxtcc-tag-color', String( row.color || '#2271b1' ) );
		chip.title = String( row.tag_name || '' );
		return chip;
	}

	/**
	 * Render the Tags table.
	 *
	 * @return {void}
	 */
	function renderTable() {
		const tbody = document.getElementById( 'nxtcc-tags-tbody' );
		if ( ! tbody ) {
			return;
		}

		empty( tbody );

		if ( ! state.rows.length ) {
			const tr = el( 'tr', { className: 'nxtcc-groups-state-row' } );
			const td = el( 'td', { className: 'nxtcc-groups-state-cell', colSpan: 99 }, 'No tags found.' );
			tr.appendChild( td );
			tbody.appendChild( tr );
			return;
		}

		state.rows.forEach( function ( row ) {
			const tr = el( 'tr' );

			if ( canManage ) {
				const selectCell = el( 'td', { className: 'checkbox-col' } );
				const checkbox   = el( 'input', {
					type: 'checkbox',
					className: 'nxtcc-tag-select',
				} );
				checkbox.setAttribute( 'data-id', String( row.id || '' ) );
				selectCell.appendChild( checkbox );
				tr.appendChild( selectCell );
			}

			const tagCell = el( 'td' );
			tagCell.appendChild( tagChip( row ) );
			tr.appendChild( tagCell );
			tr.appendChild( el( 'td', { className: 'nxtcc-tags-description' }, row.description || '' ) );
			tr.appendChild( el( 'td', {}, String( Number( row.contact_count || 0 ) ) ) );
			tr.appendChild( el( 'td', {}, row.updated_at_local || '' ) );

			if ( canManage ) {
				const actions = el( 'td', { className: 'actions-col' } );
				const wrap    = el( 'div', { className: 'nxtcc-contact-row-actions' } );
				const edit    = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-green nxtcc-tag-edit' }, 'Edit' );
				const remove  = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-outline nxtcc-tag-delete' }, 'Delete' );

				edit.setAttribute( 'data-id', String( row.id || '' ) );
				remove.setAttribute( 'data-id', String( row.id || '' ) );
				wrap.appendChild( edit );
				wrap.appendChild( remove );
				actions.appendChild( wrap );
				tr.appendChild( actions );
			}

			tbody.appendChild( tr );
		} );
	}

	/**
	 * Update pagination controls.
	 *
	 * @return {void}
	 */
	function renderPagination() {
		const pages = Math.max( 1, Math.ceil( state.total / state.perPage ) );
		$( '#nxtcc-tags-page-label' ).text( 'Page ' + state.page + ' of ' + pages );
		$( '#nxtcc-tags-prev' ).prop( 'disabled', state.page <= 1 );
		$( '#nxtcc-tags-next' ).prop( 'disabled', state.page >= pages );
	}

	/**
	 * Load tags.
	 *
	 * @return {jqXHR|undefined} Request.
	 */
	function loadTags() {
		if ( ! hasConnection ) {
			renderStats( {} );
			renderTable();
			renderPagination();
			return undefined;
		}

		return request( 'nxtcc_tags_list', {
			page: state.page,
			per_page: state.perPage,
			search: state.search,
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					alert( response && response.data && response.data.message ? response.data.message : 'Unable to load tags.' );
					return;
				}

				state.rows  = Array.isArray( response.data.rows ) ? response.data.rows : [];
				state.total = Number( response.data.total || 0 );

				renderStats( response.data.stats || {} );
				renderTable();
				renderPagination();
				updateBulkControls();
			} )
			.fail( function () {
				alert( 'Network error while loading tags.' );
			} );
	}

	/**
	 * Open the create/edit modal.
	 *
	 * @param {Object=} row Tag row.
	 * @return {void}
	 */
	function openTagModal( row ) {
		row = row || {};
		$( '#nxtcc-tag-modal-title' ).text( row.id ? 'Edit Tag' : 'Add Tag' );
		$( '#nxtcc-tag-id' ).val( row.id || '' );
		$( '#nxtcc-tag-name' ).val( row.tag_name || '' );
		$( '#nxtcc-tag-color' ).val( row.color || '#2271b1' );
		$( '#nxtcc-tag-description' ).val( row.description || '' );
		$( '#nxtcc-tag-modal' ).fadeIn( 120 );
		$( '#nxtcc-tag-name' ).trigger( 'focus' );
	}

	$( '#nxtcc-add-tag-btn' ).on( 'click', function () {
		openTagModal();
	} );

	$( document ).on( 'click', '.nxtcc-tag-modal-dismiss', function () {
		$( '#nxtcc-tag-modal' ).fadeOut( 120 );
	} );

	$( '#nxtcc-tag-form' ).on( 'submit', function ( event ) {
		event.preventDefault();

		request( 'nxtcc_tags_save', {
			tag_id: $( '#nxtcc-tag-id' ).val() || '',
			tag_name: $( '#nxtcc-tag-name' ).val() || '',
			color: $( '#nxtcc-tag-color' ).val() || '#2271b1',
			description: $( '#nxtcc-tag-description' ).val() || '',
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				alert( response && response.data && response.data.message ? response.data.message : 'Unable to save tag.' );
				return;
			}

			$( '#nxtcc-tag-modal' ).fadeOut( 120 );
			loadTags();
		} ).fail( function () {
			alert( 'Network error while saving tag.' );
		} );
	} );

	$( document ).on( 'click', '.nxtcc-tag-edit', function () {
		const id  = String( $( this ).data( 'id' ) || '' );
		const row = state.rows.find( function ( item ) {
			return String( item.id || '' ) === id;
		} );

		if ( row ) {
			openTagModal( row );
		}
	} );

	$( document ).on( 'click', '.nxtcc-tag-delete', function () {
		const id = String( $( this ).data( 'id' ) || '' );
		if ( ! id || ! confirm( 'Delete this tag? Contacts will be kept.' ) ) {
			return;
		}

		request( 'nxtcc_tags_delete', { tag_id: id } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				alert( response && response.data && response.data.message ? response.data.message : 'Unable to delete tag.' );
				return;
			}

			loadTags();
		} );
	} );

	$( document ).on( 'change', '#nxtcc-tags-select-all', function () {
		$widget.find( '.nxtcc-tag-select' ).prop( 'checked', this.checked );
		updateBulkControls();
	} );

	$( document ).on( 'change', '.nxtcc-tag-select', updateBulkControls );

	$( '#nxtcc-tags-delete-selected' ).on( 'click', function () {
		const ids = selectedIds();
		if ( ! ids.length || ! confirm( 'Delete ' + ids.length + ' selected tags? Contacts will be kept.' ) ) {
			return;
		}

		request( 'nxtcc_tags_bulk_delete', { tag_ids: ids } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				alert( response && response.data && response.data.message ? response.data.message : 'Unable to delete selected tags.' );
				return;
			}

			loadTags();
		} );
	} );

	$( '#nxtcc-tags-merge-selected' ).on( 'click', function () {
		const ids = selectedIds();
		if ( ids.length < 2 ) {
			return;
		}

		state.mergeIds = ids;
		const select   = document.getElementById( 'nxtcc-tag-merge-target' );
		empty( select );

		state.rows
			.filter( function ( row ) {
				return ids.indexOf( String( row.id || '' ) ) !== -1;
			} )
			.forEach( function ( row ) {
				select.appendChild( el( 'option', { value: String( row.id || '' ) }, row.tag_name || '' ) );
			} );

		$( '#nxtcc-tag-merge-modal' ).fadeIn( 120 );
	} );

	$( document ).on( 'click', '.nxtcc-tag-merge-dismiss', function () {
		$( '#nxtcc-tag-merge-modal' ).fadeOut( 120 );
	} );

	$( '#nxtcc-tag-merge-apply' ).on( 'click', function () {
		const targetId = String( $( '#nxtcc-tag-merge-target' ).val() || '' );
		const sources  = state.mergeIds.filter( function ( id ) {
			return id !== targetId;
		} );

		if ( ! targetId || ! sources.length || ! confirm( 'Merge source tags into the selected target tag?' ) ) {
			return;
		}

		request( 'nxtcc_tags_merge', {
			target_tag_id: targetId,
			source_tag_ids: sources,
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				alert( response && response.data && response.data.message ? response.data.message : 'Unable to merge tags.' );
				return;
			}

			$( '#nxtcc-tag-merge-modal' ).fadeOut( 120 );
			loadTags();
		} );
	} );

	let searchTimer;
	$( '#nxtcc-tags-search' ).on( 'input', function () {
		state.search = String( $( this ).val() || '' ).trim();
		window.clearTimeout( searchTimer );
		searchTimer = window.setTimeout( function () {
			state.page = 1;
			loadTags();
		}, 300 );
	} );

	$( '#nxtcc-tags-prev' ).on( 'click', function () {
		if ( state.page > 1 ) {
			state.page -= 1;
			loadTags();
		}
	} );

	$( '#nxtcc-tags-next' ).on( 'click', function () {
		if ( state.page * state.perPage < state.total ) {
			state.page += 1;
			loadTags();
		}
	} );

	loadTags();
} );
