/**
 * Deals and pipelines management screen.
 *
 * @package NXTCC
 */

/* global jQuery, NXTCC_DealsData, document, window */

jQuery( function ( $ ) {
	'use strict';

	const $widget = $( '.nxtcc-deals-widget' );
	if ( ! $widget.length ) {
		return;
	}

	const config        = window.NXTCC_DealsData || {};
	const ajaxurl       = String( config.ajaxurl || window.ajaxurl || '' );
	const nonce         = String( config.nonce || $widget.data( 'nonce' ) || '' );
	const canManage     = String( $widget.data( 'can-manage' ) || '0' ) === '1';
	const canPipelines  = String( $widget.data( 'manage-pipelines' ) || '0' ) === '1';
	const hasConnection = String( $widget.data( 'has-connection' ) || '0' ) === '1';
	const activeView    = String( $widget.data( 'active-view' ) || 'deals' );
	const state         = {
		rows: [],
		pipelines: [],
		allPipelines: [],
		pipelineOverview: [],
		stages: {},
		allStages: {},
		contacts: [],
		targets: { users: [], roles: [] },
		providers: {},
		page: 1,
		perPage: 20,
		total: 0,
		currentPipeline: null,
		editDeal: null,
		editStage: null,
		currencyMismatchConfirmed: false,
		draggedStageId: '',
		itemPickerSequence: 0,
	};

	function el( tag, attrs, text ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			const value = attrs[ key ];
			if ( null === value || typeof value === 'undefined' ) {
				return;
			}
			if ( 'className' === key ) {
				node.className = String( value );
			} else if ( key in node ) {
				node[ key ] = value;
			} else {
				node.setAttribute( key, String( value ) );
			}
		} );
		if ( typeof text !== 'undefined' ) {
			node.textContent = String( text );
		}
		return node;
	}

	function empty( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function request( action, data ) {
		return $.post( ajaxurl, $.extend( { action, nonce }, data || {} ) );
	}

	function showError( response, fallback ) {
		const message = response && response.data && response.data.message ? response.data.message : fallback;
		showConfirm( message, null, 'Close' );
	}

	function showConfirm( message, callback, confirmLabel ) {
		const modal   = el( 'div', { className: 'nxtcc-groups-modal nxtcc-deal-confirm-modal' } );
		const overlay = el( 'div', { className: 'nxtcc-groups-modal-overlay' } );
		const content = el( 'div', { className: 'nxtcc-groups-modal-content nxtcc-deal-confirm-content', role: 'dialog', ariaModal: 'true' } );
		const body    = el( 'div', { className: 'nxtcc-groups-modal-form' } );
		const footer  = el( 'div', { className: 'nxtcc-groups-modal-footer' } );
		const cancel  = el( 'button', { type: 'button', className: 'nxtcc-groups-btn nxtcc-groups-btn-secondary' }, callback ? 'Cancel' : 'Close' );
		const confirm = el( 'button', { type: 'button', className: 'nxtcc-groups-btn ' + ( 'Delete' === confirmLabel ? 'nxtcc-groups-btn-danger' : 'nxtcc-groups-btn-green' ) }, confirmLabel || 'Confirm' );

		body.appendChild( el( 'p', { className: 'nxtcc-deal-confirm-message' }, message ) );
		footer.appendChild( cancel );
		if ( callback ) {
			footer.appendChild( confirm );
		}
		body.appendChild( footer );
		content.appendChild( body );
		modal.appendChild( overlay );
		modal.appendChild( content );
		document.body.appendChild( modal );

		function close() {
			modal.remove();
		}
		cancel.addEventListener( 'click', close );
		overlay.addEventListener( 'click', close );
		confirm.addEventListener( 'click', function () {
			close();
			callback();
		} );
	}

	function setOptions( selector, rows, valueKey, labelKey, firstLabel ) {
		const select = document.querySelector( selector );
		if ( ! select ) {
			return;
		}
		const current = String( select.value || '' );
		empty( select );
		if ( typeof firstLabel !== 'undefined' ) {
			select.appendChild( el( 'option', { value: '' }, firstLabel ) );
		}
		rows.forEach( function ( row ) {
			select.appendChild( el( 'option', { value: String( row[ valueKey ] || '' ) }, row[ labelKey ] || '' ) );
		} );
		if ( current ) {
			select.value = current;
		}
	}

	function renderOwnerOptions() {
		const select = document.querySelector( '#nxtcc-deal-owner' );
		if ( ! select ) {
			return;
		}

		const current = String( select.value || '' );
		const users   = state.targets.users || [];
		const teams   = state.targets.teams || state.targets.roles || [];

		empty( select );
		select.appendChild( el( 'option', { value: '' }, 'Unassigned' ) );

		if ( users.length ) {
			const usersGroup = el( 'optgroup', { label: 'Users' } );
			users.forEach( function ( row ) {
				usersGroup.appendChild( el( 'option', { value: 'user:' + String( row.id || '' ) }, row.label || row.email || row.id || '' ) );
			} );
			select.appendChild( usersGroup );
		}

		if ( teams.length ) {
			const teamsGroup = el( 'optgroup', { label: 'Teams' } );
			teams.forEach( function ( row ) {
				teamsGroup.appendChild( el( 'option', { value: 'role:' + String( row.key || '' ) }, row.label || row.key || '' ) );
			} );
			select.appendChild( teamsGroup );
		}

		if ( current ) {
			select.value = current;
		}
	}

	function pipelineStages( pipelineId, includeInactive ) {
		const source = includeInactive ? state.allStages : state.stages;
		const rows   = Array.isArray( source[ String( pipelineId ) ] ) ? source[ String( pipelineId ) ] : [];
		return includeInactive ? rows : rows.filter( function ( stage ) {
			return Number( stage.is_active || 0 ) === 1;
		} );
	}

	function allPipelineStages( pipelineId ) {
		return Array.isArray( state.allStages[ String( pipelineId ) ] ) ? state.allStages[ String( pipelineId ) ] : [];
	}

	function updateDealPipelineOptions() {
		const rows      = state.pipelines.slice();
		const currentId = Number( state.editDeal && state.editDeal.pipeline_id ? state.editDeal.pipeline_id : 0 );
		if ( currentId && ! rows.some( function ( pipeline ) { return Number( pipeline.id || 0 ) === currentId; } ) ) {
			const archived = state.allPipelines.find( function ( pipeline ) { return Number( pipeline.id || 0 ) === currentId; } );
			if ( archived ) {
				rows.push( Object.assign( {}, archived, { pipeline_name: String( archived.pipeline_name || '' ) + ' (Archived)' } ) );
			}
		}
		setOptions( '#nxtcc-deal-pipeline', rows, 'id', 'pipeline_name' );
	}

	function renderOptions() {
		setOptions( '#nxtcc-deals-filter-pipeline', state.pipelines, 'id', 'pipeline_name', 'All Pipelines' );
		updateDealPipelineOptions();
		setOptions(
			'#nxtcc-deal-contact',
			state.contacts.map( function ( row ) {
				return { id: row.id, label: ( row.name || 'Unnamed contact' ) + ' - +' + String( row.country_code || '' ) + String( row.phone_number || '' ) };
			} ),
			'id',
			'label',
			'No linked contact'
		);

		renderOwnerOptions();
		updateFilterStages();
		updateDealStages();
	}

	function updateFilterStages() {
		const pipelineId = $( '#nxtcc-deals-filter-pipeline' ).val() || '';
		const rows       = pipelineId ? pipelineStages( pipelineId ) : state.pipelines.reduce( function ( all, pipeline ) {
			return all.concat( pipelineStages( pipeline.id ) );
		}, [] );
		setOptions( '#nxtcc-deals-filter-stage', rows, 'id', 'stage_name', 'All Stages' );
	}

	function updateDealStages() {
		const pipelineId = $( '#nxtcc-deal-pipeline' ).val() || '';
		const rows       = pipelineStages( pipelineId ).slice();
		const currentId  = Number( state.editDeal && Number( state.editDeal.pipeline_id || 0 ) === Number( pipelineId ) ? state.editDeal.stage_id || 0 : 0 );
		if ( currentId && ! rows.some( function ( stage ) { return Number( stage.id || 0 ) === currentId; } ) ) {
			const archived = allPipelineStages( pipelineId ).find( function ( stage ) { return Number( stage.id || 0 ) === currentId; } );
			if ( archived ) {
				rows.push( Object.assign( {}, archived, { stage_name: String( archived.stage_name || '' ) + ' (Archived)' } ) );
			}
		}
		setOptions( '#nxtcc-deal-stage', rows, 'id', 'stage_name' );
		updateReasonRequirement();
	}

	function updateReasonRequirement() {
		const targetId = Number( $( '#nxtcc-deal-stage' ).val() || 0 );
		const previousId = Number( state.editDeal && state.editDeal.stage_id ? state.editDeal.stage_id : 0 );
		const isTransition = ! previousId || previousId !== targetId;
		const target   = pipelineStages( $( '#nxtcc-deal-pipeline' ).val(), true ).find( function ( stage ) {
			return Number( stage.id || 0 ) === targetId;
		} ) || {};
		const previous = isTransition && state.editDeal
			? pipelineStages( state.editDeal.pipeline_id, true ).find( function ( stage ) {
				return Number( stage.id || 0 ) === Number( state.editDeal.stage_id || 0 );
			} ) || {}
			: {};
		const targetRequirement   = String( target.reason_requirement || 'optional' );
		const previousRequirement = String( previous.reason_requirement || 'none' );
		const required = isTransition && (
			[ 'enter', 'both' ].indexOf( targetRequirement ) >= 0 ||
			[ 'leave', 'both' ].indexOf( previousRequirement ) >= 0
		);
		const requested = ! isTransition || 'none' !== targetRequirement || ( previousId > 0 && 'none' !== previousRequirement );
		$( '#nxtcc-deal-reason-field' ).prop( 'hidden', ! requested );
		$( '#nxtcc-deal-reason' ).prop( 'required', required ).attr( 'placeholder', required ? 'Required for this stage transition' : 'Optional stage context' );
	}

	function loadOptions() {
		return request( 'nxtcc_deals_options' ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to load deal options.' );
				return;
			}
			state.pipelines = Array.isArray( response.data.pipelines ) ? response.data.pipelines : [];
			state.allPipelines = Array.isArray( response.data.all_pipelines ) ? response.data.all_pipelines : state.pipelines;
			state.stages    = response.data.stages || {};
			state.allStages = response.data.all_stages || state.stages;
			state.contacts  = Array.isArray( response.data.contacts ) ? response.data.contacts : [];
			state.targets   = response.data.assignment_targets || { users: [], roles: [], teams: [] };
			state.providers = response.data.item_providers || {};
			renderOptions();
		} );
	}

	function renderStats( stats ) {
		stats = stats || {};
		$( '#nxtcc-deals-summary-total' ).text( Number( stats.total_deals || 0 ) );
		$( '#nxtcc-deals-summary-open' ).text( Number( stats.open_deals || 0 ) );
		$( '#nxtcc-deals-summary-won' ).text( Number( stats.won_deals || 0 ) );
		$( '#nxtcc-deals-summary-value' ).text( Number( stats.open_value || 0 ).toLocaleString() );
	}

	function stageChip( row ) {
		const chip = el( 'span', { className: 'nxtcc-deal-stage-chip' }, row.stage_name || '' );
		chip.style.setProperty( '--nxtcc-stage-color', String( row.stage_color || row.color || '#2271b1' ) );
		return chip;
	}

	function renderTable() {
		const tbody = document.getElementById( 'nxtcc-deals-tbody' );
		if ( ! tbody ) {
			return;
		}
		empty( tbody );
		if ( ! state.rows.length ) {
			const tr = el( 'tr', { className: 'nxtcc-groups-state-row' } );
			tr.appendChild( el( 'td', { className: 'nxtcc-groups-state-cell', colSpan: 99 }, 'No deals found.' ) );
			tbody.appendChild( tr );
			return;
		}
		state.rows.forEach( function ( row ) {
			const tr        = el( 'tr' );
			const titleCell = el( 'td' );
			titleCell.appendChild( el( 'span', { className: 'nxtcc-deal-title' }, row.title || '' ) );
			titleCell.appendChild( el( 'span', { className: 'nxtcc-deal-status is-' + String( row.status || 'open' ) }, row.status || 'open' ) );
			tr.appendChild( titleCell );
			tr.appendChild( el( 'td', {}, row.primary_contact_name || ( row.primary_phone_number ? '+' + String( row.primary_country_code || '' ) + row.primary_phone_number : 'Not linked' ) ) );
			const pipelineCell = el( 'td' );
			pipelineCell.appendChild( el( 'div', {}, row.pipeline_name || '' ) );
			pipelineCell.appendChild( stageChip( row ) );
			tr.appendChild( pipelineCell );
			tr.appendChild( el( 'td', {}, String( row.currency || '' ) + ' ' + Number( row.deal_value || 0 ).toLocaleString() ) );
			tr.appendChild( el( 'td', {}, row.owner_label || 'Unassigned' ) );
			tr.appendChild( el( 'td', {}, row.expected_close_local || 'Not set' ) );
			tr.appendChild( el( 'td', {}, row.updated_at_local || '' ) );
			if ( canManage ) {
				const actions = el( 'td', { className: 'actions-col' } );
				const wrap    = el( 'div', { className: 'nxtcc-contact-row-actions' } );
				const edit    = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-green nxtcc-deal-edit' }, 'Edit' );
				const remove  = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-danger nxtcc-deal-delete' }, 'Delete' );
				edit.dataset.id = String( row.id || '' );
				remove.dataset.id = String( row.id || '' );
				remove.dataset.title = String( row.title || 'this deal' );
				wrap.appendChild( edit );
				wrap.appendChild( remove );
				actions.appendChild( wrap );
				tr.appendChild( actions );
			}
			tbody.appendChild( tr );
		} );
	}

	function renderPagination() {
		const pages = Math.max( 1, Math.ceil( state.total / state.perPage ) );
		$( '#nxtcc-deals-page-label' ).text( 'Page ' + state.page + ' of ' + pages );
		$( '#nxtcc-deals-prev' ).prop( 'disabled', state.page <= 1 );
		$( '#nxtcc-deals-next' ).prop( 'disabled', state.page >= pages );
	}

	function loadDeals() {
		if ( ! hasConnection || 'deals' !== activeView ) {
			return;
		}
		request( 'nxtcc_deals_list', {
			page: state.page,
			per_page: state.perPage,
			search: $( '#nxtcc-deals-search' ).val() || '',
			pipeline_id: $( '#nxtcc-deals-filter-pipeline' ).val() || '',
			stage_id: $( '#nxtcc-deals-filter-stage' ).val() || '',
			status: $( '#nxtcc-deals-filter-status' ).val() || '',
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to load deals.' );
				return;
			}
			state.rows  = Array.isArray( response.data.rows ) ? response.data.rows : [];
			state.total = Number( response.data.total || 0 );
			renderStats( response.data.stats || {} );
			renderTable();
			renderPagination();
		} );
	}

	function providerOptions() {
		return Object.keys( state.providers ).map( function ( id ) {
			return state.providers[ id ];
		} ).filter( function ( provider ) {
			return provider && provider.available;
		} );
	}

	function unitOptions( providerId ) {
		const provider = state.providers[ providerId ] || state.providers.manual || {};
		return Object.keys( provider.quantity_types || { unit: 'Unit' } ).map( function ( id ) {
			return { id, label: provider.quantity_types[ id ] };
		} );
	}

	function normalizeCurrencyCode( value ) {
		return String( value || '' ).trim().toUpperCase();
	}

	function selectedPipelineCurrency() {
		const pipelineId = Number( $( '#nxtcc-deal-pipeline' ).val() || 0 );
		const pipeline   = state.allPipelines.concat( state.pipelines ).find( function ( row ) {
			return Number( row.id || 0 ) === pipelineId;
		} );
		return normalizeCurrencyCode( pipeline && pipeline.currency ? pipeline.currency : '' );
	}

	function hasPipelineCurrencyMismatch() {
		const pipelineCurrency = selectedPipelineCurrency();
		const dealCurrency     = normalizeCurrencyCode( $( '#nxtcc-deal-currency' ).val() );
		return Boolean( pipelineCurrency && dealCurrency && pipelineCurrency !== dealCurrency );
	}

	function recalculateLineItems() {
		let total = 0;
		state.currencyMismatchConfirmed = false;
		$( '.nxtcc-deal-product-row' ).each( function () {
			const quantity = integerQuantity( $( this ).find( '.nxtcc-deal-product-quantity' ).val() );
			const price    = Number( $( this ).find( '.nxtcc-deal-product-price' ).val() || 0 );
			const line     = quantity * price;
			total += line;
			$( this ).find( '.nxtcc-deal-product-total' ).text( line.toLocaleString( undefined, { maximumFractionDigits: 2 } ) );
		} );
		if ( 'calculated' === $( '#nxtcc-deal-value-mode' ).val() ) {
			$( '#nxtcc-deal-value' ).val( total.toFixed( 2 ) );
		}
	}

	function integerQuantity( value ) {
		const quantity = Math.floor( Number( value ) );
		return Number.isFinite( quantity ) && quantity >= 1 ? quantity : 1;
	}

	function hideProductResults( $row ) {
		$row.find( '.nxtcc-deal-product-results' ).prop( 'hidden', true ).empty();
		$row.find( '.nxtcc-deal-product-name' ).attr( 'aria-expanded', 'false' );
	}

	function renderProductResults( $row, items, status ) {
		const $results = $row.find( '.nxtcc-deal-product-results' );
		$results.empty();
		if ( status ) {
			$results.append( $( '<div>' ).addClass( 'nxtcc-deal-product-result-status' ).text( status ) );
		} else {
			items.forEach( function ( item ) {
				const button = el( 'button', { type: 'button', className: 'nxtcc-deal-product-result', role: 'option' } );
				button.appendChild( el( 'strong', {}, item.label || '' ) );
				if ( item.secondary_text ) {
					button.appendChild( el( 'small', {}, item.secondary_text ) );
				}
				$( button ).data( 'item', item );
				$results.append( button );
			} );
		}
		$results.prop( 'hidden', false );
		$row.find( '.nxtcc-deal-product-name' ).attr( 'aria-expanded', 'true' );
	}

	function selectProductResult( $row, item ) {
		if ( ! item ) {
			return;
		}
		$row.find( '.nxtcc-deal-product-name' ).val( item.label || '' ).data( 'source-item-id', item.id || '' );
		$row.find( '.nxtcc-deal-product-price' ).val( item.unit_value || 0 );
		$row.find( '.nxtcc-deal-product-currency' ).val( item.currency || $( '#nxtcc-deal-currency' ).val() || 'USD' );
		$row.find( '.nxtcc-deal-product-unit' ).val( item.quantity_type || 'unit' );
		$row.get( 0 ).dataset.sourceMetadata = JSON.stringify( item.metadata || {} );
		hideProductResults( $row );
		recalculateLineItems();
	}

	function addProductRow( product ) {
		product = product || {};
		const providerId = String( product.source_key || 'manual' );
		const row        = el( 'div', { className: 'nxtcc-deal-product-row' } );
		const source     = el( 'select', { className: 'nxtcc-deal-product-source', ariaLabel: 'Line item source' } );
		const itemWrap   = el( 'div', { className: 'nxtcc-deal-item-picker' } );
		const name       = el( 'input', { type: 'search', className: 'nxtcc-deal-product-name', value: product.product_name || '', placeholder: 'Search or enter item name', autocomplete: 'off' } );
		const results    = el( 'div', { className: 'nxtcc-deal-product-results', role: 'listbox', hidden: true } );
		const unit       = el( 'select', { className: 'nxtcc-deal-product-unit', ariaLabel: 'Unit of measure' } );
		const quantity   = el( 'input', { type: 'number', className: 'nxtcc-deal-product-quantity', value: integerQuantity( product.quantity || 1 ), min: 1, step: 1, inputMode: 'numeric', ariaLabel: 'Quantity' } );
		const price      = el( 'input', { type: 'number', className: 'nxtcc-deal-product-price', value: product.unit_price || 0, min: 0, step: 0.01, ariaLabel: 'Value each' } );
		const currency   = el( 'input', { type: 'hidden', className: 'nxtcc-deal-product-currency', value: product.currency || $( '#nxtcc-deal-currency' ).val() || 'USD' } );
		const total      = el( 'span', { className: 'nxtcc-deal-product-total' }, '0' );
		const remove     = el( 'button', { type: 'button', className: 'nxtcc-groups-btn nxtcc-groups-btn-danger nxtcc-deal-product-remove', ariaLabel: 'Remove line item' }, 'x' );
		state.itemPickerSequence += 1;
		results.id = 'nxtcc-deal-product-results-' + state.itemPickerSequence;
		name.setAttribute( 'aria-autocomplete', 'list' );
		name.setAttribute( 'aria-controls', results.id );
		name.setAttribute( 'aria-expanded', 'false' );
		row.dataset.sourceMetadata = JSON.stringify( product.source_metadata || {} );
		providerOptions().forEach( function ( provider ) {
			source.appendChild( el( 'option', { value: provider.id }, provider.label ) );
		} );
		if ( ! state.providers[ providerId ] || ! state.providers[ providerId ].available ) {
			source.appendChild( el( 'option', { value: providerId }, providerId + ' - integration unavailable' ) );
			row.classList.add( 'has-provider-warning' );
		}
		source.value = providerId;
		name.placeholder = 'manual' === providerId ? 'Enter item name' : 'Search ' + String( ( state.providers[ providerId ] || {} ).label || 'items' );
		unitOptions( providerId ).forEach( function ( option ) {
			unit.appendChild( el( 'option', { value: option.id }, option.label ) );
		} );
		unit.value = String( product.quantity_type || 'unit' );
		name.dataset.sourceItemId = String( product.source_item_id || '' );
		itemWrap.appendChild( name );
		itemWrap.appendChild( results );
		row.appendChild( source );
		row.appendChild( itemWrap );
		row.appendChild( unit );
		row.appendChild( quantity );
		row.appendChild( price );
		row.appendChild( total );
		row.appendChild( remove );
		row.appendChild( currency );
		document.getElementById( 'nxtcc-deal-products' ).appendChild( row );
		recalculateLineItems();
	}

	function dealProducts() {
		return $( '.nxtcc-deal-product-row' ).map( function () {
			const $row = $( this );
			let metadata = {};
			try {
				metadata = JSON.parse( this.dataset.sourceMetadata || '{}' );
			} catch ( error ) {
				metadata = {};
			}
			return {
				source_key: $row.find( '.nxtcc-deal-product-source' ).val() || 'manual',
				source_item_id: $row.find( '.nxtcc-deal-product-name' ).data( 'source-item-id' ) || '',
				product_name: $row.find( '.nxtcc-deal-product-name' ).val() || '',
				quantity_type: $row.find( '.nxtcc-deal-product-unit' ).val() || 'unit',
				quantity: integerQuantity( $row.find( '.nxtcc-deal-product-quantity' ).val() ),
				unit_price: $row.find( '.nxtcc-deal-product-price' ).val() || 0,
				currency: $row.find( '.nxtcc-deal-product-currency' ).val() || $( '#nxtcc-deal-currency' ).val() || 'USD',
				source_metadata: metadata,
			};
		} ).get().filter( function ( product ) {
			return String( product.product_name ).trim() !== '';
		} );
	}

	function renderStageHistory( rows ) {
		const list = document.getElementById( 'nxtcc-deal-stage-history' );
		empty( list );
		( rows || [] ).forEach( function ( row ) {
			const item = el( 'div', { className: 'nxtcc-deal-history-row' } );
			item.appendChild( el( 'strong', {}, ( row.previous_stage_name || 'Created' ) + ' to ' + ( row.stage_name || 'Stage' ) ) );
			item.appendChild( el( 'span', {}, row.reason || 'No reason recorded' ) );
			item.appendChild( el( 'small', {}, row.changed_at_local || '' ) );
			list.appendChild( item );
		} );
		$( '#nxtcc-deal-stage-history-wrap' ).prop( 'hidden', ! ( rows || [] ).length );
	}

	function openDeal( deal ) {
		deal = deal || {};
		state.editDeal = deal;
		state.currencyMismatchConfirmed = false;
		$( '#nxtcc-deal-modal-title' ).text( deal.id ? 'Edit Deal' : 'New Deal' );
		$( '#nxtcc-deal-id' ).val( deal.id || '' );
		$( '#nxtcc-deal-title' ).val( deal.title || '' );
		$( '#nxtcc-deal-description' ).val( deal.description || '' );
		$( '#nxtcc-deal-value' ).val( deal.deal_value || 0 );
		$( '#nxtcc-deal-value-mode' ).val( deal.value_mode || 'manual' );
		$( '#nxtcc-deal-currency' ).val( deal.currency || ( state.pipelines[0] && state.pipelines[0].currency ) || 'USD' );
		$( '#nxtcc-deal-close' ).val( deal.expected_close_at ? String( deal.expected_close_at ).slice( 0, 10 ) : '' );
		$( '#nxtcc-deal-reason' ).val( deal.stage_reason || '' );
		updateDealPipelineOptions();
		$( '#nxtcc-deal-pipeline' ).val( deal.pipeline_id || ( state.pipelines[0] && state.pipelines[0].id ) || '' );
		updateDealStages();
		$( '#nxtcc-deal-stage' ).val( deal.stage_id || ( pipelineStages( $( '#nxtcc-deal-pipeline' ).val() )[0] || {} ).id || '' );
		updateReasonRequirement();
		$( '#nxtcc-deal-contact' ).val( deal.primary_contact_id || '' );
		$( '#nxtcc-deal-owner' ).val( deal.assigned_user_id ? 'user:' + deal.assigned_user_id : ( deal.assigned_role ? 'role:' + deal.assigned_role : '' ) );
		empty( document.getElementById( 'nxtcc-deal-products' ) );
		( Array.isArray( deal.products ) ? deal.products : [] ).forEach( addProductRow );
		renderStageHistory( deal.stage_history || [] );
		document.getElementById( 'nxtcc-deal-modal' ).hidden = false;
		$( '#nxtcc-deal-title' ).trigger( 'focus' );
	}

	function loadPipelines() {
		if ( 'pipelines' !== activeView || ! hasConnection ) {
			return $.Deferred().resolve().promise();
		}
		return request( 'nxtcc_deals_pipelines_list' ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to load pipelines.' );
				return;
			}
			state.pipelineOverview = response.data.pipelines || [];
			renderPipelinesTable();
			if ( state.currentPipeline ) {
				state.currentPipeline = findPipeline( state.currentPipeline.id ) || state.currentPipeline;
				renderStageList();
				renderPipelineFormStages();
			}
		} );
	}

	function renderPipelinesTable() {
		const tbody = document.getElementById( 'nxtcc-pipelines-tbody' );
		if ( ! tbody ) {
			return;
		}
		empty( tbody );
		state.pipelineOverview.forEach( function ( pipeline ) {
			const tr      = el( 'tr' );
			const name    = el( 'td' );
			const actions = el( 'td', { className: 'actions-col' } );
			const wrap    = el( 'div', { className: 'nxtcc-contact-row-actions' } );
			name.appendChild( el( 'strong', {}, pipeline.pipeline_name || '' ) );
			if ( Number( pipeline.is_default || 0 ) === 1 ) {
				name.appendChild( el( 'span', { className: 'nxtcc-deal-status is-open' }, 'Default' ) );
			}
			tr.appendChild( name );
			tr.appendChild( el( 'td', {}, pipeline.currency || '' ) );
			tr.appendChild( el( 'td', {}, String( ( pipeline.stages || [] ).length ) ) );
			tr.appendChild( el( 'td', {}, canPipelines ? String( pipeline.deal_count || 0 ) : '-' ) );
			tr.appendChild( el( 'td', {}, Number( pipeline.is_active || 0 ) === 1 ? 'Active' : 'Archived' ) );
			[ [ 'Preview', 'nxtcc-pipeline-preview' ] ].forEach( function ( action ) {
				const button = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-outline ' + action[1] }, action[0] );
				button.dataset.id = String( pipeline.id || '' );
				wrap.appendChild( button );
			} );
			if ( canPipelines ) {
				[ [ 'Add Stage', 'nxtcc-pipeline-add-stage' ], [ 'Duplicate', 'nxtcc-pipeline-duplicate' ], [ 'Edit', 'nxtcc-pipeline-edit' ] ].forEach( function ( action ) {
					const button = el( 'button', { type: 'button', className: 'nxtcc-btn-sm ' + ( 'Edit' === action[0] ? 'nxtcc-btn-green ' : 'nxtcc-btn-outline ' ) + action[1] }, action[0] );
					button.dataset.id = String( pipeline.id || '' );
					wrap.appendChild( button );
				} );
			}
			actions.appendChild( wrap );
			tr.appendChild( actions );
			tbody.appendChild( tr );
		} );
	}

	function findPipeline( id ) {
		return state.pipelineOverview.find( function ( pipeline ) {
			return String( pipeline.id || '' ) === String( id || '' );
		} ) || null;
	}

	function stageReasonLabel( requirement ) {
		return {
			none: 'Not requested',
			optional: 'Optional',
			enter: 'Required when entering',
			leave: 'Required when leaving',
			both: 'Required when entering or leaving',
		}[ String( requirement || 'optional' ) ] || 'Optional';
	}

	function stageSavePayload( stage, isActive ) {
		return {
			pipeline_id: stage.pipeline_id || ( state.currentPipeline && state.currentPipeline.id ) || '',
			stage_id: stage.id || '',
			stage_name: stage.stage_name || '',
			stage_type: stage.stage_type || 'open',
			probability: stage.probability || 0,
			color: stage.color || '#2271b1',
			reason_requirement: stage.reason_requirement || 'optional',
			is_active: isActive ? 1 : 0,
		};
	}

	function findCurrentStage( stageId ) {
		return ( state.currentPipeline && state.currentPipeline.stages ? state.currentPipeline.stages : [] ).find( function ( stage ) {
			return String( stage.id || '' ) === String( stageId || '' );
		} ) || null;
	}

	function stageIsReferenced( stage ) {
		return Boolean( stage && ( Number( stage.is_referenced || 0 ) === 1 || Number( stage.deal_count || 0 ) > 0 ) );
	}

	function refreshStageManagement( closeStageModal ) {
		if ( closeStageModal ) {
			document.getElementById( 'nxtcc-stage-form-modal' ).hidden = true;
		}
		loadOptions();
		loadPipelines();
	}

	function setStageActive( stage, isActive, closeStageModal ) {
		request( 'nxtcc_deals_save_stage', stageSavePayload( stage, isActive ) ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to update stage.' );
				return;
			}
			refreshStageManagement( closeStageModal );
		} );
	}

	function deleteStage( stage, closeStageModal ) {
		request( 'nxtcc_deals_delete_stage', { stage_id: stage.id || '' } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to delete stage.' );
				return;
			}
			refreshStageManagement( closeStageModal );
		} );
	}

	function confirmStageRemoval( stage, closeStageModal ) {
		if ( ! stage ) {
			return;
		}
		if ( stageIsReferenced( stage ) ) {
			showConfirm( 'Permanently delete "' + stage.stage_name + '"? Referenced stages must be archived.', function () {
				setStageActive( stage, false, closeStageModal );
			}, 'Archive' );
			return;
		}
		showConfirm( 'Permanently delete "' + stage.stage_name + '"?', function () {
			deleteStage( stage, closeStageModal );
		}, 'Delete' );
	}

	function renderPipelineFormStages() {
		const tbody = document.getElementById( 'nxtcc-pipeline-form-stages-tbody' );
		if ( ! tbody ) {
			return;
		}
		empty( tbody );
		const stages = state.currentPipeline && Array.isArray( state.currentPipeline.stages ) ? state.currentPipeline.stages : [];
		if ( ! stages.length ) {
			const emptyRow = el( 'tr', { className: 'nxtcc-groups-state-row' } );
			emptyRow.appendChild( el( 'td', { className: 'nxtcc-groups-state-cell', colSpan: 7 }, 'No stages found. Add a stage to build this pipeline.' ) );
			tbody.appendChild( emptyRow );
			return;
		}
		stages.forEach( function ( stage ) {
			const tr        = el( 'tr' );
			const nameCell  = el( 'td' );
			const typeCell  = el( 'td' );
			const colorCell = el( 'td' );
			const active    = Number( stage.is_active || 0 ) === 1;
			const actions   = el( 'td', { className: 'actions-col' } );
			const actionRow = el( 'div', { className: 'nxtcc-contact-row-actions' } );
			const swatch    = el( 'span', { className: 'nxtcc-pipeline-stage-swatch', title: stage.color || '#2271b1' } );

			nameCell.appendChild( el( 'strong', {}, stage.stage_name || '' ) );
			typeCell.appendChild( el( 'span', { className: 'nxtcc-deal-status is-' + String( stage.stage_type || 'open' ) }, stage.stage_type || 'open' ) );
			swatch.style.backgroundColor = String( stage.color || '#2271b1' );
			colorCell.appendChild( swatch );
			colorCell.appendChild( el( 'span', {}, stage.color || '#2271b1' ) );

			[
				[ 'Edit', 'nxtcc-btn-green nxtcc-pipeline-stage-edit' ],
				[ active ? 'Archive' : 'Restore', 'nxtcc-btn-outline ' + ( active ? 'nxtcc-pipeline-stage-archive' : 'nxtcc-pipeline-stage-restore' ) ],
				[ 'Delete', 'nxtcc-btn-danger nxtcc-pipeline-stage-delete' ],
			].forEach( function ( action ) {
				const button = el( 'button', { type: 'button', className: 'nxtcc-btn-sm ' + action[1] }, action[0] );
				button.dataset.id = String( stage.id || '' );
				actionRow.appendChild( button );
			} );

			actions.appendChild( actionRow );
			tr.appendChild( nameCell );
			tr.appendChild( typeCell );
			tr.appendChild( el( 'td', {}, String( stage.probability || 0 ) + '%' ) );
			tr.appendChild( colorCell );
			tr.appendChild( el( 'td', {}, stageReasonLabel( stage.reason_requirement ) ) );
			const activeCell = el( 'td' );
			activeCell.appendChild( el( 'span', { className: 'nxtcc-pipeline-stage-state ' + ( active ? 'is-active' : 'is-archived' ) }, active ? 'Active' : 'Archived' ) );
			tr.appendChild( activeCell );
			tr.appendChild( actions );
			tbody.appendChild( tr );
		} );
	}

	function openPipelineForm( pipeline ) {
		pipeline = pipeline || {};
		state.currentPipeline = pipeline.id ? pipeline : null;
		$( '#nxtcc-pipeline-modal-title' ).text( pipeline.id ? 'Edit Pipeline' : 'Add Pipeline' );
		$( '#nxtcc-pipeline-id' ).val( pipeline.id || '' );
		$( '#nxtcc-pipeline-name' ).val( pipeline.pipeline_name || '' );
		$( '#nxtcc-pipeline-currency' ).val( pipeline.currency || 'USD' );
		$( '#nxtcc-pipeline-default' ).prop( 'checked', Number( pipeline.is_default || 0 ) === 1 );
		$( '#nxtcc-pipeline-active' ).prop( 'checked', ! pipeline.id || Number( pipeline.is_active || 0 ) === 1 );
		$( '#nxtcc-pipeline-danger' ).prop( 'hidden', ! pipeline.id );
		$( '#nxtcc-pipeline-form-stages-section' ).prop( 'hidden', ! pipeline.id );
		$( '.nxtcc-pipeline-form-modal-content' ).toggleClass( 'has-stages', Boolean( pipeline.id ) );
		renderPipelineFormStages();
		document.getElementById( 'nxtcc-pipeline-form-modal' ).hidden = false;
		$( '#nxtcc-pipeline-name' ).trigger( 'focus' );
	}

	function openStages( pipeline ) {
		if ( ! pipeline ) {
			return;
		}
		state.currentPipeline = pipeline;
		$( '#nxtcc-stages-title' ).text( pipeline.pipeline_name + ' Stages' );
		$( '#nxtcc-stages-pipeline-name' ).text( pipeline.pipeline_name );
		renderStageList();
		document.getElementById( 'nxtcc-stages-modal' ).hidden = false;
	}

	function renderStageList() {
		const list = document.getElementById( 'nxtcc-stage-list' );
		empty( list );
		( state.currentPipeline && state.currentPipeline.stages ? state.currentPipeline.stages : [] ).forEach( function ( stage, index, rows ) {
			const row     = el( 'div', { className: 'nxtcc-stage-row', draggable: canPipelines } );
			const summary = el( 'div', { className: 'nxtcc-stage-summary' } );
			const actions = el( 'div', { className: 'nxtcc-contact-row-actions' } );
			row.dataset.id = String( stage.id || '' );
			summary.appendChild( stageChip( stage ) );
			summary.appendChild( el( 'span', {}, String( stage.probability || 0 ) + '% - ' + String( stage.stage_type || 'open' ) + ( canPipelines ? ' - ' + String( stage.deal_count || 0 ) + ' deals' : '' ) ) );
			if ( canPipelines ) {
				[ [ 'Up', index > 0, -1 ], [ 'Down', index < rows.length - 1, 1 ] ].forEach( function ( move ) {
					const button = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-outline nxtcc-stage-move', disabled: ! move[1] }, move[0] );
					button.dataset.id = String( stage.id || '' );
					button.dataset.direction = String( move[2] );
					actions.appendChild( button );
				} );
				const edit = el( 'button', { type: 'button', className: 'nxtcc-btn-sm nxtcc-btn-green nxtcc-stage-edit' }, 'Edit' );
				edit.dataset.id = String( stage.id || '' );
				actions.appendChild( edit );
			}
			row.appendChild( summary );
			row.appendChild( actions );
			list.appendChild( row );
		} );
	}

	function openStageForm( stage ) {
		stage = stage || {};
		state.editStage = stage.id ? stage : null;
		$( '#nxtcc-stage-modal-title' ).text( stage.id ? 'Edit Stage' : 'Add Stage' );
		$( '#nxtcc-stage-pipeline-label' ).text( state.currentPipeline ? state.currentPipeline.pipeline_name : '' );
		$( '#nxtcc-stage-id' ).val( stage.id || '' );
		$( '#nxtcc-stage-pipeline' ).val( state.currentPipeline ? state.currentPipeline.id : '' );
		$( '#nxtcc-stage-name' ).val( stage.stage_name || '' );
		$( '#nxtcc-stage-type' ).val( stage.stage_type || 'open' );
		$( '#nxtcc-stage-probability' ).val( stage.probability || 0 );
		$( '#nxtcc-stage-color' ).val( stage.color || '#2271b1' );
		$( '#nxtcc-stage-reason-requirement' ).val( stage.reason_requirement || 'optional' );
		$( '#nxtcc-stage-active' ).prop( 'checked', ! stage.id || Number( stage.is_active || 0 ) === 1 );
		$( '#nxtcc-stage-danger' ).prop( 'hidden', ! stage.id );
		document.getElementById( 'nxtcc-stage-form-modal' ).hidden = false;
		$( '#nxtcc-stage-name' ).trigger( 'focus' );
	}

	$( '#nxtcc-deal-pipeline' ).on( 'change', function () {
		$( '#nxtcc-deal-reason' ).val( '' );
		updateDealStages();
		recalculateLineItems();
	} );
	$( '#nxtcc-deal-stage' ).on( 'change', function () {
		if ( state.editDeal && Number( state.editDeal.stage_id || 0 ) !== Number( $( this ).val() || 0 ) ) {
			$( '#nxtcc-deal-reason' ).val( '' );
		}
		updateReasonRequirement();
	} );
	$( '#nxtcc-deal-value-mode, #nxtcc-deal-currency' ).on( 'change input', recalculateLineItems );
	$( '#nxtcc-deals-filter-pipeline' ).on( 'change', function () {
		updateFilterStages();
		state.page = 1;
		loadDeals();
	} );
	$( '#nxtcc-deals-filter-stage, #nxtcc-deals-filter-status' ).on( 'change', function () {
		state.page = 1;
		loadDeals();
	} );
	let searchTimer;
	$( '#nxtcc-deals-search' ).on( 'input', function () {
		window.clearTimeout( searchTimer );
		searchTimer = window.setTimeout( function () {
			state.page = 1;
			loadDeals();
		}, 250 );
	} );
	$( '#nxtcc-deals-refresh' ).on( 'click', function () {
		loadOptions().then( loadDeals );
	} );
	$( '#nxtcc-deals-add' ).on( 'click', function () {
		openDeal();
	} );
	$( document ).on( 'click', '.nxtcc-deal-dismiss', function () {
		document.getElementById( 'nxtcc-deal-modal' ).hidden = true;
	} );
	$( '#nxtcc-deal-product-add' ).on( 'click', function () {
		addProductRow();
	} );
	$( document ).on( 'click', '.nxtcc-deal-product-remove', function () {
		$( this ).closest( '.nxtcc-deal-product-row' ).remove();
		recalculateLineItems();
	} );
	$( document ).on( 'input change', '.nxtcc-deal-product-quantity, .nxtcc-deal-product-price', recalculateLineItems );
	$( document ).on( 'change blur', '.nxtcc-deal-product-quantity', function () {
		$( this ).val( integerQuantity( this.value ) );
		recalculateLineItems();
	} );
	$( document ).on( 'keydown', '.nxtcc-deal-product-quantity', function ( event ) {
		if ( [ '.', ',', '-', '+', 'e', 'E' ].indexOf( event.key ) >= 0 ) {
			event.preventDefault();
		}
	} );
	$( document ).on( 'change', '.nxtcc-deal-product-source', function () {
		const $row       = $( this ).closest( '.nxtcc-deal-product-row' );
		const providerId = String( $( this ).val() || 'manual' );
		const $unit      = $row.find( '.nxtcc-deal-product-unit' );
		$unit.empty();
		unitOptions( providerId ).forEach( function ( option ) {
			$unit.append( $( '<option>' ).val( option.id ).text( option.label ) );
		} );
		const provider = state.providers[ providerId ] || {};
		$row.find( '.nxtcc-deal-product-name' ).val( '' ).data( 'source-item-id', '' ).attr( 'placeholder', 'manual' === providerId ? 'Enter item name' : 'Search ' + String( provider.label || 'items' ) );
		hideProductResults( $row );
		this.closest( '.nxtcc-deal-product-row' ).dataset.sourceMetadata = '{}';
	} );
	$( document ).on( 'input', '.nxtcc-deal-product-name', function () {
		const input      = this;
		const $row       = $( input ).closest( '.nxtcc-deal-product-row' );
		const providerId = String( $row.find( '.nxtcc-deal-product-source' ).val() || 'manual' );
		const provider   = state.providers[ providerId ] || {};
		const search     = String( input.value || '' ).trim();
		$( input ).data( 'source-item-id', '' );
		$row.get( 0 ).dataset.sourceMetadata = '{}';
		window.clearTimeout( input.nxtccItemSearchTimer );
		if ( 'manual' === providerId || search.length < 2 ) {
			hideProductResults( $row );
			return;
		}
		if ( ! provider.searchable ) {
			renderProductResults( $row, [], 'This source does not support item search.' );
			return;
		}
		renderProductResults( $row, [], 'Searching...' );
		input.nxtccItemSearchTimer = window.setTimeout( function () {
			request( 'nxtcc_deals_search_items', { provider: providerId, search } ).done( function ( response ) {
				if ( search !== String( input.value || '' ).trim() || providerId !== String( $row.find( '.nxtcc-deal-product-source' ).val() || '' ) ) {
					return;
				}
				const items = response && response.success ? response.data.items || [] : [];
				renderProductResults( $row, items, items.length ? '' : 'No matching items found.' );
			} ).fail( function () {
				renderProductResults( $row, [], 'Unable to load matching items.' );
			} );
		}, 250 );
	} );
	$( document ).on( 'keydown', '.nxtcc-deal-product-name', function ( event ) {
		const $row = $( this ).closest( '.nxtcc-deal-product-row' );
		if ( 'Escape' === event.key ) {
			hideProductResults( $row );
		} else if ( 'ArrowDown' === event.key ) {
			const first = $row.find( '.nxtcc-deal-product-result' ).get( 0 );
			if ( first ) {
				event.preventDefault();
				first.focus();
			}
		}
	} );
	$( document ).on( 'click', '.nxtcc-deal-product-result', function () {
		selectProductResult( $( this ).closest( '.nxtcc-deal-product-row' ), $( this ).data( 'item' ) );
	} );
	$( document ).on( 'click', function ( event ) {
		if ( ! $( event.target ).closest( '.nxtcc-deal-item-picker' ).length ) {
			$( '.nxtcc-deal-product-row' ).each( function () {
				hideProductResults( $( this ) );
			} );
		}
	} );
	$( document ).on( 'click', '.nxtcc-deal-edit', function () {
		request( 'nxtcc_deals_get', { deal_id: $( this ).data( 'id' ) || '' } ).done( function ( response ) {
			if ( response && response.success ) {
				openDeal( response.data.deal || {} );
			}
		} );
	} );
	$( document ).on( 'click', '.nxtcc-deal-delete', function () {
		const dealId = $( this ).data( 'id' ) || '';
		const title  = String( $( this ).data( 'title' ) || 'this deal' );
		showConfirm( 'Permanently delete "' + title + '" and its line items and stage history?', function () {
			request( 'nxtcc_deals_delete', { deal_id: dealId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( response, 'Unable to delete this deal.' );
					return;
				}
				if ( state.page > 1 && state.rows.length <= 1 ) {
					state.page -= 1;
				}
				loadDeals();
			} );
		}, 'Delete' );
	} );
	$( '#nxtcc-deal-form' ).on( 'submit', function ( event ) {
		event.preventDefault();
		if ( hasPipelineCurrencyMismatch() && ! state.currencyMismatchConfirmed ) {
			showConfirm( 'The deal currency does not match the selected pipeline currency. Values will be saved without currency conversion.', function () {
				state.currencyMismatchConfirmed = true;
				$( '#nxtcc-deal-form' ).trigger( 'submit' );
			}, 'Save Anyway' );
			return;
		}
		const owner   = String( $( '#nxtcc-deal-owner' ).val() || '' ).split( ':' );
		const contact = String( $( '#nxtcc-deal-contact' ).val() || '' );
		request( 'nxtcc_deals_save', {
			deal_id: $( '#nxtcc-deal-id' ).val() || '',
			title: $( '#nxtcc-deal-title' ).val() || '',
			description: $( '#nxtcc-deal-description' ).val() || '',
			pipeline_id: $( '#nxtcc-deal-pipeline' ).val() || '',
			stage_id: $( '#nxtcc-deal-stage' ).val() || '',
			deal_value: $( '#nxtcc-deal-value' ).val() || 0,
			value_mode: $( '#nxtcc-deal-value-mode' ).val() || 'manual',
			currency: $( '#nxtcc-deal-currency' ).val() || 'USD',
			expected_close_at: $( '#nxtcc-deal-close' ).val() || '',
			stage_reason: $( '#nxtcc-deal-reason' ).val() || '',
			assigned_user_id: 'user' === owner[0] ? owner[1] : '',
			assigned_role: 'role' === owner[0] ? owner[1] : '',
			contact_ids: contact ? [ contact ] : [],
			primary_contact_id: contact,
			products: JSON.stringify( dealProducts() ),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to save deal.' );
				return;
			}
			document.getElementById( 'nxtcc-deal-modal' ).hidden = true;
			loadDeals();
		} );
	} );
	$( '#nxtcc-deals-prev' ).on( 'click', function () {
		state.page = Math.max( 1, state.page - 1 );
		loadDeals();
	} );
	$( '#nxtcc-deals-next' ).on( 'click', function () {
		state.page += 1;
		loadDeals();
	} );

	if ( canPipelines ) {
		$( '#nxtcc-pipeline-add' ).on( 'click', function () {
			openPipelineForm();
		} );
		$( '#nxtcc-pipeline-form' ).on( 'submit', function ( event ) {
			event.preventDefault();
			request( 'nxtcc_deals_save_pipeline', {
				pipeline_id: $( '#nxtcc-pipeline-id' ).val() || '',
				pipeline_name: $( '#nxtcc-pipeline-name' ).val() || '',
				currency: $( '#nxtcc-pipeline-currency' ).val() || 'USD',
				is_default: $( '#nxtcc-pipeline-default' ).is( ':checked' ) ? 1 : 0,
				is_active: $( '#nxtcc-pipeline-active' ).is( ':checked' ) ? 1 : 0,
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( response, 'Unable to save pipeline.' );
					return;
				}
				document.getElementById( 'nxtcc-pipeline-form-modal' ).hidden = true;
				loadOptions();
				loadPipelines();
			} );
		} );
		$( '#nxtcc-stage-form' ).on( 'submit', function ( event ) {
			event.preventDefault();
			request( 'nxtcc_deals_save_stage', {
				pipeline_id: $( '#nxtcc-stage-pipeline' ).val() || '',
				stage_id: $( '#nxtcc-stage-id' ).val() || '',
				stage_name: $( '#nxtcc-stage-name' ).val() || '',
				stage_type: $( '#nxtcc-stage-type' ).val() || 'open',
				probability: $( '#nxtcc-stage-probability' ).val() || 0,
				color: $( '#nxtcc-stage-color' ).val() || '#2271b1',
				reason_requirement: $( '#nxtcc-stage-reason-requirement' ).val() || 'optional',
				is_active: $( '#nxtcc-stage-active' ).is( ':checked' ) ? 1 : 0,
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( response, 'Unable to save stage.' );
					return;
				}
				document.getElementById( 'nxtcc-stage-form-modal' ).hidden = true;
				loadOptions();
				loadPipelines();
			} );
		} );
	}

	$( '#nxtcc-pipelines-refresh' ).on( 'click', loadPipelines );
	$( document ).on( 'click', '.nxtcc-pipeline-dismiss', function () {
		document.getElementById( 'nxtcc-pipeline-form-modal' ).hidden = true;
	} );
	$( document ).on( 'click', '.nxtcc-stages-dismiss', function () {
		document.getElementById( 'nxtcc-stages-modal' ).hidden = true;
	} );
	$( document ).on( 'click', '.nxtcc-stage-dismiss', function () {
		document.getElementById( 'nxtcc-stage-form-modal' ).hidden = true;
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-preview', function () {
		openStages( findPipeline( $( this ).data( 'id' ) ) );
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-add-stage', function () {
		state.currentPipeline = findPipeline( $( this ).data( 'id' ) );
		openStageForm();
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-edit', function () {
		openPipelineForm( findPipeline( $( this ).data( 'id' ) ) );
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-duplicate', function () {
		const pipeline = findPipeline( $( this ).data( 'id' ) );
		showConfirm( 'Duplicate "' + pipeline.pipeline_name + '" and all of its stages?', function () {
			request( 'nxtcc_deals_duplicate_pipeline', { pipeline_id: pipeline.id, pipeline_name: pipeline.pipeline_name + ' Copy' } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( response, 'Unable to duplicate pipeline.' );
					return;
				}
				loadPipelines();
			} );
		}, 'Duplicate' );
	} );
	$( '#nxtcc-pipeline-delete' ).on( 'click', function () {
		const pipelineId = $( '#nxtcc-pipeline-id' ).val() || '';
		showConfirm( 'Permanently delete this unused pipeline?', function () {
			request( 'nxtcc_deals_delete_pipeline', { pipeline_id: pipelineId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( response, 'Unable to delete pipeline.' );
					return;
				}
				document.getElementById( 'nxtcc-pipeline-form-modal' ).hidden = true;
				loadPipelines();
			} );
		}, 'Delete' );
	} );
	$( '#nxtcc-stage-add' ).on( 'click', function () {
		openStageForm();
	} );
	$( '#nxtcc-pipeline-form-stage-add' ).on( 'click', function () {
		openStageForm();
	} );
	$( document ).on( 'click', '.nxtcc-stage-edit', function () {
		openStageForm( findCurrentStage( $( this ).data( 'id' ) ) );
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-stage-edit', function () {
		openStageForm( findCurrentStage( $( this ).data( 'id' ) ) );
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-stage-archive, .nxtcc-pipeline-stage-restore', function () {
		const stage     = findCurrentStage( $( this ).data( 'id' ) );
		const restoring = $( this ).hasClass( 'nxtcc-pipeline-stage-restore' );
		if ( ! stage ) {
			return;
		}
		showConfirm( ( restoring ? 'Restore' : 'Archive' ) + ' "' + stage.stage_name + '"?', function () {
			setStageActive( stage, restoring, false );
		}, restoring ? 'Restore' : 'Archive' );
	} );
	$( document ).on( 'click', '.nxtcc-pipeline-stage-delete', function () {
		confirmStageRemoval( findCurrentStage( $( this ).data( 'id' ) ), false );
	} );
	$( '#nxtcc-stage-delete' ).on( 'click', function () {
		confirmStageRemoval( findCurrentStage( $( '#nxtcc-stage-id' ).val() ) || state.editStage, true );
	} );
	$( document ).on( 'click', '.nxtcc-stage-move', function () {
		const id        = String( $( this ).data( 'id' ) || '' );
		const direction = Number( $( this ).data( 'direction' ) || 0 );
		const stages    = state.currentPipeline.stages || [];
		const index     = stages.findIndex( function ( stage ) {
			return String( stage.id || '' ) === id;
		} );
		const target    = index + direction;
		if ( index < 0 || target < 0 || target >= stages.length ) {
			return;
		}
		const reordered = stages.slice();
		const moved     = reordered.splice( index, 1 )[0];
		reordered.splice( target, 0, moved );
		request( 'nxtcc_deals_reorder_stages', { pipeline_id: state.currentPipeline.id, stage_ids: reordered.map( function ( stage ) { return stage.id; } ) } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to reorder stages.' );
				return;
			}
			state.currentPipeline.stages = response.data.result.stages || reordered;
			renderStageList();
		} );
	} );
	$( document ).on( 'dragstart', '.nxtcc-stage-row[draggable="true"]', function ( event ) {
		state.draggedStageId = String( $( this ).data( 'id' ) || '' );
		$( this ).addClass( 'is-dragging' );
		if ( event.originalEvent && event.originalEvent.dataTransfer ) {
			event.originalEvent.dataTransfer.effectAllowed = 'move';
			event.originalEvent.dataTransfer.setData( 'text/plain', state.draggedStageId );
		}
	} );
	$( document ).on( 'dragover', '.nxtcc-stage-row[draggable="true"]', function ( event ) {
		event.preventDefault();
		if ( event.originalEvent && event.originalEvent.dataTransfer ) {
			event.originalEvent.dataTransfer.dropEffect = 'move';
		}
	} );
	$( document ).on( 'drop', '.nxtcc-stage-row[draggable="true"]', function ( event ) {
		event.preventDefault();
		const targetId = String( $( this ).data( 'id' ) || '' );
		const sourceId = state.draggedStageId;
		if ( ! sourceId || sourceId === targetId || ! state.currentPipeline ) {
			return;
		}
		const ids       = ( state.currentPipeline.stages || [] ).map( function ( stage ) { return String( stage.id || '' ); } );
		const sourcePos = ids.indexOf( sourceId );
		const targetPos = ids.indexOf( targetId );
		if ( sourcePos < 0 || targetPos < 0 ) {
			return;
		}
		ids.splice( sourcePos, 1 );
		ids.splice( targetPos, 0, sourceId );
		request( 'nxtcc_deals_reorder_stages', { pipeline_id: state.currentPipeline.id, stage_ids: ids } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( response, 'Unable to reorder stages.' );
				return;
			}
			state.currentPipeline.stages = response.data.result.stages || [];
			renderStageList();
			loadPipelines();
		} );
	} );
	$( document ).on( 'dragend', '.nxtcc-stage-row[draggable="true"]', function () {
		state.draggedStageId = '';
		$( this ).removeClass( 'is-dragging' );
	} );

	loadOptions().then( function () {
		loadDeals();
		loadPipelines();
	} );
} );
