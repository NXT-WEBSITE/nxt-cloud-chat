/**
 * Contacts personal saved views.
 *
 * @package NXTCC
 */

/* global jQuery, window, document */

jQuery( function ( $ ) {
	'use strict';

	const R = window.NXTCC_ContactsRuntime;

	if ( ! R || ! R.actions || ! $( '#nxtcc-saved-view-select' ).length ) {
		return;
	}

	const viewsById = {};
	const strings   = R.strings || {};
	let selectedId  = '';

	/**
	 * Read a localized UI string.
	 *
	 * @param {string} key String key.
	 * @param {string} fallback Fallback value.
	 * @return {string} UI string.
	 */
	function uiString( key, fallback ) {
		return strings[ key ] ? String( strings[ key ] ) : fallback;
	}

	/**
	 * Extract a readable AJAX error.
	 *
	 * @param {Object} xhr jQuery request error.
	 * @param {string} fallback Fallback value.
	 * @return {string} Error message.
	 */
	function errorMessage( xhr, fallback ) {
		if (
			xhr &&
			xhr.responseJSON &&
			xhr.responseJSON.data &&
			xhr.responseJSON.data.message
		) {
			return String( xhr.responseJSON.data.message );
		}

		return fallback;
	}

	/**
	 * Post to a saved-view AJAX action.
	 *
	 * @param {string} action AJAX action.
	 * @param {Object} data Request data.
	 * @return {jQuery.jqXHR} Request.
	 */
	function post( action, data ) {
		return $.post(
			R.ajaxurl,
			Object.assign(
				{
					action: action,
					nonce: R.nonce,
					instance_id: R.instanceId,
				},
				data || {}
			)
		);
	}

	/**
	 * Return the selected saved view.
	 *
	 * @return {Object|null} Selected view.
	 */
	function selectedView() {
		return selectedId && viewsById[ selectedId ] ? viewsById[ selectedId ] : null;
	}

	/**
	 * Toggle actions that require a selected saved view.
	 *
	 * @return {void}
	 */
	function updateActions() {
		const hasSelection = Boolean( selectedView() );

		$( '#nxtcc-saved-view-update, #nxtcc-saved-view-delete' ).prop( 'disabled', ! hasSelection );
	}

	/**
	 * Render saved views into the selector.
	 *
	 * @param {Array<Object>} views Saved views.
	 * @param {string} nextSelectedId Selected ID after render.
	 * @return {void}
	 */
	function renderViews( views, nextSelectedId ) {
		const select = document.getElementById( 'nxtcc-saved-view-select' );

		if ( ! select ) {
			return;
		}

		Object.keys( viewsById ).forEach( function ( key ) {
			delete viewsById[ key ];
		} );

		while ( select.options.length > 1 ) {
			select.remove( 1 );
		}

		( views || [] ).forEach( function ( view ) {
			const id     = String( view.id || '' );
			const option = document.createElement( 'option' );

			if ( ! id ) {
				return;
			}

			viewsById[ id ]    = view;
			option.value        = id;
			option.textContent = String( view.view_name || '' ) +
				( Number( view.is_default ) === 1 ? uiString( 'saved_view_default_suffix', ' (Default)' ) : '' );
			select.appendChild( option );
		} );

		selectedId = nextSelectedId && viewsById[ nextSelectedId ] ? nextSelectedId : '';
		$( select ).val( selectedId );
		updateActions();
	}

	/**
	 * Load the current user's saved views.
	 *
	 * @param {string} nextSelectedId Selected ID after loading.
	 * @return {jQuery.Promise} Promise resolving with views.
	 */
	function loadViews( nextSelectedId ) {
		const deferred = $.Deferred();

		post( 'nxtcc_contacts_saved_views_list' )
			.done( function ( response ) {
				const views = response && response.success && response.data && Array.isArray( response.data.views )
					? response.data.views
					: [];

				renderViews( views, nextSelectedId || '' );
				deferred.resolve( views );
			} )
			.fail( function () {
				renderViews( [], '' );
				deferred.resolve( [] );
			} );

		return deferred.promise();
	}

	/**
	 * Close the saved-view modal.
	 *
	 * @return {void}
	 */
	function closeModal() {
		$( '#nxtcc-saved-view-modal' ).fadeOut( 120 );
	}

	/**
	 * Open the modal for a new or existing view.
	 *
	 * @param {Object|null} view Existing view.
	 * @return {void}
	 */
	function openModal( view ) {
		const isExisting = Boolean( view && view.id );

		$( '#nxtcc-saved-view-id' ).val( isExisting ? String( view.id ) : '' );
		$( '#nxtcc-saved-view-name' ).val( isExisting ? String( view.view_name || '' ) : '' );
		$( '#nxtcc-saved-view-default' ).prop( 'checked', isExisting && Number( view.is_default ) === 1 );
		$( '#nxtcc-saved-view-modal-title' ).text(
			isExisting
				? uiString( 'saved_view_update_title', 'Update Contact View' )
				: uiString( 'saved_view_create_title', 'Save Contact View' )
		);
		$( '#nxtcc-saved-view-submit' ).text(
			isExisting
				? uiString( 'saved_view_update_action', 'Update View' )
				: uiString( 'saved_view_create_action', 'Save View' )
		);
		$( '#nxtcc-saved-view-modal' ).fadeIn( 120, function () {
			$( '#nxtcc-saved-view-name' ).trigger( 'focus' );
		} );
	}

	$( document ).on( 'change', '#nxtcc-saved-view-select', function () {
		selectedId = String( $( this ).val() || '' );
		updateActions();

		const view = selectedView();
		if ( 'function' === typeof R.actions.applyFilters ) {
			R.actions.applyFilters( view && view.filters ? view.filters : {} );
		}
	} );

	$( document ).on( 'click', '#nxtcc-saved-view-create', function () {
		openModal( null );
	} );

	$( document ).on( 'click', '#nxtcc-saved-view-update', function () {
		const view = selectedView();
		if ( view ) {
			openModal( view );
		}
	} );

	$( document ).on( 'click', '.nxtcc-saved-view-dismiss', closeModal );

	$( document ).on( 'submit', '#nxtcc-saved-view-form', function ( event ) {
		event.preventDefault();

		const $submit = $( '#nxtcc-saved-view-submit' );
		const name    = String( $( '#nxtcc-saved-view-name' ).val() || '' ).trim();
		const filters = 'function' === typeof R.actions.getCurrentFilters
			? R.actions.getCurrentFilters()
			: {};

		if ( ! name ) {
			$( '#nxtcc-saved-view-name' ).trigger( 'focus' );
			return;
		}

		$submit.prop( 'disabled', true );
		post(
			'nxtcc_contacts_saved_view_save',
			{
				view_id: String( $( '#nxtcc-saved-view-id' ).val() || '' ),
				view_name: name,
				filters_json: JSON.stringify( filters ),
				is_default: $( '#nxtcc-saved-view-default' ).is( ':checked' ) ? 1 : 0,
			}
		)
			.done( function ( response ) {
				const view = response && response.success && response.data && response.data.view
					? response.data.view
					: null;

				closeModal();
				loadViews( view && view.id ? String( view.id ) : '' );
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr, uiString( 'saved_view_save_error', 'Unable to save the contact view.' ) ) );
			} )
			.always( function () {
				$submit.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#nxtcc-saved-view-delete', function () {
		const view = selectedView();
		if ( ! view ) {
			return;
		}

		const question = uiString( 'saved_view_delete_confirm', 'Delete "%s"?' )
			.replace( '%s', String( view.view_name || '' ) );

		if ( ! window.confirm( question ) ) {
			return;
		}

		post( 'nxtcc_contacts_saved_view_delete', { view_id: String( view.id || '' ) } )
			.done( function () {
				loadViews( '' );
				if ( 'function' === typeof R.actions.applyFilters ) {
					R.actions.applyFilters( {} );
				}
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr, uiString( 'saved_view_delete_error', 'Unable to delete the contact view.' ) ) );
			} );
	} );

	$.when( R.referenceDataReady || $.Deferred().resolve().promise(), loadViews( '' ) )
		.done( function () {
			const views       = arguments[1];
			const list        = Array.isArray( views ) ? views : [];
			const defaultView = list.find( function ( view ) {
				return Number( view.is_default ) === 1;
			} );

			if ( defaultView && defaultView.id && 'function' === typeof R.actions.applyFilters ) {
				selectedId = String( defaultView.id );
				$( '#nxtcc-saved-view-select' ).val( selectedId );
				updateActions();
				R.actions.applyFilters( defaultView.filters || {} );
			}
		} );
} );
