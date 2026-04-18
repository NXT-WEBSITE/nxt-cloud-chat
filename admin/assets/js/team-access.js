/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	var widget = document.querySelector( '.nxtcc-team-access-widget' );
	var bootNode = document.getElementById( 'nxtcc-team-access-boot' );

	if ( ! widget || ! bootNode ) {
		return;
	}

	var modal = document.getElementById( 'nxtcc-team-access-modal' );
	var form = document.getElementById( 'nxtcc-team-access-form' );
	var title = document.getElementById( 'nxtcc-team-access-modal-title' );
	var actionField = document.getElementById( 'nxtcc_team_access_action' );
	var roleKeyField = document.getElementById( 'nxtcc_team_role_key' );
	var userPickerGroup = document.getElementById( 'nxtcc-team-access-user-picker-group' );
	var userSummaryGroup = document.getElementById( 'nxtcc-team-access-user-summary-group' );
	var userSelect = document.getElementById( 'nxtcc_team_user_id_modal' );
	var userPickerNote = document.getElementById( 'nxtcc-team-access-user-picker-note' );
	var userPreviewNote = document.getElementById( 'nxtcc-team-access-user-preview-note' );
	var roleSelect = document.getElementById( 'nxtcc_team_role_preset' );
	var capabilitiesPanel = document.getElementById( 'nxtcc-team-access-capabilities' );
	var roleDescription = document.getElementById( 'nxtcc-team-access-role-description' );
	var summaryRole = document.getElementById( 'nxtcc-team-access-summary-role' );
	var summaryCopy = document.getElementById( 'nxtcc-team-access-summary-copy' );
	var summaryChips = document.getElementById( 'nxtcc-team-access-summary-chips' );
	var addButton = document.getElementById( 'nxtcc-team-access-add-member' );
	var submitButton = document.getElementById( 'nxtcc-team-access-submit' );
	var closeButton = document.getElementById( 'nxtcc-team-access-modal-close' );
	var cancelButton = document.getElementById( 'nxtcc-team-access-cancel' );
	var searchInput = document.getElementById( 'nxtcc-team-access-search' );
	var roleFilter = document.getElementById( 'nxtcc-team-access-role-filter' );
	var emptyFilteredRow = widget.querySelector( '.nxtcc-team-access-empty-row.is-filtered' );
	var memberRows = Array.prototype.slice.call( widget.querySelectorAll( 'tbody tr[data-team-role]' ) );
	var capabilityInputs = Array.prototype.slice.call(
		document.querySelectorAll( '#nxtcc-team-access-capabilities input[name="nxtcc_team_caps[]"]' )
	);
	var capabilityLabels = {};
	var boot = {};
	var rolePresets = {};
	var strings = {};

	try {
		boot = JSON.parse( bootNode.textContent || '{}' );
	} catch ( e ) {
		boot = {};
	}

	rolePresets = boot.rolePresets || {};
	strings = boot.strings || {};

	capabilityInputs.forEach( function ( input ) {
		capabilityLabels[ input.value ] = input.getAttribute( 'data-cap-label' ) || input.value;
	} );

	/**
	 * Create a chip node.
	 *
	 * @param {string} label Chip text.
	 * @param {boolean} muted Whether chip uses muted style.
	 * @return {Element} Chip node.
	 */
	function createChip( label, muted ) {
		var chip = document.createElement( 'span' );
		chip.className = muted ? 'nxtcc-team-access-chip is-muted' : 'nxtcc-team-access-chip';
		chip.textContent = label;
		return chip;
	}

	/**
	 * Remove all child nodes.
	 *
	 * @param {Element} node Target node.
	 * @return {void}
	 */
	function clearNode( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Parse a member payload.
	 *
	 * @param {string} raw Raw JSON.
	 * @return {Object} Parsed payload.
	 */
	function parseMember( raw ) {
		try {
			return JSON.parse( raw || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	/**
	 * Return the currently checked capability keys.
	 *
	 * @return {Array<string>} Capability keys.
	 */
	function getCheckedCapabilities() {
		var selected = [];

		capabilityInputs.forEach( function ( input ) {
			if ( input.checked ) {
				selected.push( input.value );
			}
		} );

		return selected;
	}

	/**
	 * Apply capability checkbox state from keys.
	 *
	 * @param {Array<string>} capabilities Capability keys.
	 * @return {void}
	 */
	function setCapabilities( capabilities ) {
		var lookup = {};

		( capabilities || [] ).forEach( function ( capability ) {
			lookup[ capability ] = true;
		} );

		capabilityInputs.forEach( function ( input ) {
			input.checked = !! lookup[ input.value ];
		} );
	}

	/**
	 * Get a role preset object.
	 *
	 * @param {string} key Role key.
	 * @return {Object|null} Role preset.
	 */
	function getRolePreset( key ) {
		key = ( key || '' ).toString();

		if ( Object.prototype.hasOwnProperty.call( rolePresets, key ) ) {
			return rolePresets[ key ];
		}

		return null;
	}

	/**
	 * Ensure the user select contains a specific option.
	 *
	 * @param {Object} member Member payload.
	 * @return {void}
	 */
	function ensureUserOption( member ) {
		var userId = member && member.user_id ? String( member.user_id ) : '';
		var option;

		if ( ! userId || ! userSelect ) {
			return;
		}

		option = userSelect.querySelector( 'option[value="' + userId.replace( /"/g, '\\"' ) + '"]' );
		if ( option ) {
			return;
		}

		option = document.createElement( 'option' );
		option.value = userId;
		option.textContent = ( member.display_name || '' ) + ' (' + ( member.user_email || '' ) + ')';
		userSelect.appendChild( option );
	}

	/**
	 * Return the active role key.
	 *
	 * @return {string} Role key.
	 */
	function getActiveRoleKey() {
		var selectedRole = roleSelect ? String( roleSelect.value || 'custom' ) : 'custom';
		return selectedRole;
	}

	/**
	 * Return the active capability set.
	 *
	 * @return {Array<string>} Capability keys.
	 */
	function getActiveCapabilities() {
		var selectedRole = roleSelect ? String( roleSelect.value || 'custom' ) : 'custom';
		var preset = getRolePreset( selectedRole );

		if ( 'custom' === selectedRole ) {
			return getCheckedCapabilities();
		}

		if ( preset && $.isArray( preset.capabilities ) ) {
			return preset.capabilities.slice();
		}

		return [];
	}

	/**
	 * Render the side summary.
	 *
	 * @return {void}
	 */
	function syncSummary() {
		var roleKey = getActiveRoleKey();
		var preset = getRolePreset( roleSelect ? roleSelect.value : '' );
		var labels = [];
		var summaryLabel = strings.customRoleLabel || 'Custom';
		var summaryDesc = strings.customRoleDesc || '';
		var capabilities = getActiveCapabilities();

		if ( 'custom' !== roleKey && preset ) {
			summaryLabel = preset.label || summaryLabel;
			summaryDesc = preset.description || '';
		}

		if ( summaryRole ) {
			summaryRole.textContent = summaryLabel;
		}

		if ( summaryCopy ) {
			summaryCopy.textContent = summaryDesc;
		}

		clearNode( summaryChips );

		capabilities.forEach( function ( capability ) {
			if ( Object.prototype.hasOwnProperty.call( capabilityLabels, capability ) ) {
				labels.push( capabilityLabels[ capability ] );
			}
		} );

		labels = labels.slice( 0, 8 );

		if ( ! labels.length ) {
			summaryChips.appendChild( createChip( strings.noPermissions || 'No permissions selected yet.', true ) );
			return;
		}

		labels.forEach( function ( label ) {
			summaryChips.appendChild( createChip( label, false ) );
		} );
	}

	/**
	 * Refresh form state based on the selected role.
	 *
	 * @param {boolean} seedPreset Whether to seed preset capabilities.
	 * @return {void}
	 */
	function syncRoleState( seedPreset ) {
		var selectedRole = roleSelect ? String( roleSelect.value || 'custom' ) : 'custom';
		var preset = getRolePreset( selectedRole );
		var isCustom = 'custom' === selectedRole;
		var presetCaps = preset && $.isArray( preset.capabilities ) ? preset.capabilities.slice() : [];

		if ( roleDescription ) {
			if ( isCustom ) {
				roleDescription.textContent = strings.customRoleDesc || '';
			} else if ( preset ) {
				roleDescription.textContent = preset.description || '';
			} else {
				roleDescription.textContent = '';
			}
		}

		if ( seedPreset && ! isCustom && presetCaps.length ) {
			setCapabilities( presetCaps );
		}

		capabilityInputs.forEach( function ( input ) {
			input.disabled = ! isCustom;
		} );

		if ( roleKeyField ) {
			roleKeyField.value = selectedRole;
		}

		if ( capabilitiesPanel ) {
			capabilitiesPanel.classList.toggle( 'is-readonly', ! isCustom );
		}

		syncSummary();
	}

	/**
	 * Open the modal.
	 *
	 * @return {void}
	 */
	function openModal() {
		if ( ! modal ) {
			return;
		}

		modal.hidden = false;
		document.body.classList.add( 'nxtcc-team-access-modal-open' );
	}

	/**
	 * Close the modal.
	 *
	 * @return {void}
	 */
	function closeModal() {
		if ( ! modal ) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove( 'nxtcc-team-access-modal-open' );
	}

	/**
	 * Prepare add mode.
	 *
	 * @return {void}
	 */
	function prepareAddMode() {
		var defaultRole = boot.defaultRole || 'custom';
		var hasAvailableUsers = userSelect && userSelect.options.length > 1;

		if ( form ) {
			form.reset();
		}

		if ( title ) {
			title.textContent = strings.addTitle || 'Add Team Member';
		}

		if ( actionField ) {
			actionField.value = 'add';
		}

		if ( roleSelect ) {
			roleSelect.value = defaultRole;
			if ( ! roleSelect.value ) {
				roleSelect.value = 'custom';
			}
		}

		if ( userPickerGroup ) {
			userPickerGroup.hidden = false;
		}

		if ( userSummaryGroup ) {
			userSummaryGroup.hidden = true;
		}

		if ( userPickerNote ) {
			userPickerNote.hidden = hasAvailableUsers;
		}

		if ( userPreviewNote ) {
			userPreviewNote.textContent = '';
			userPreviewNote.hidden = true;
		}

		if ( submitButton ) {
			submitButton.disabled = ! hasAvailableUsers;
		}

		setCapabilities( [] );
		syncRoleState( true );
		openModal();

		if ( userSelect ) {
			userSelect.focus();
		}
	}

	/**
	 * Prepare edit mode.
	 *
	 * @param {Object} member Member payload.
	 * @return {void}
	 */
	function prepareEditMode( member ) {
		var preset = null;
		var isEligible = true;

		if ( ! member || member.is_owner ) {
			return;
		}

		isEligible = false !== member.wp_role_eligible;

		ensureUserOption( member );

		if ( form ) {
			form.reset();
		}

		if ( title ) {
			title.textContent = strings.editTitle || 'Update Team Access';
		}

		if ( actionField ) {
			actionField.value = 'update';
		}

		if ( userSelect ) {
			userSelect.value = member.user_id ? String( member.user_id ) : '';
		}

		if ( userPickerGroup ) {
			userPickerGroup.hidden = true;
		}

		if ( userSummaryGroup ) {
			userSummaryGroup.hidden = false;
		}

		if ( userPickerNote ) {
			userPickerNote.hidden = true;
		}

		if ( userPreviewNote ) {
			userPreviewNote.textContent = ! isEligible && member.wp_role_status_note ? member.wp_role_status_note : '';
			userPreviewNote.hidden = isEligible || ! member.wp_role_status_note;
		}

		if ( submitButton ) {
			submitButton.disabled = ! isEligible;
		}

		$( '#nxtcc-team-access-user-preview-name' ).text( member.display_name || '' );
		$( '#nxtcc-team-access-user-preview-email' ).text( member.user_email || '' );
		$( '#nxtcc-team-access-user-preview-roles' ).text( member.roles_display || '' );

		preset = getRolePreset( member.role_key || '' );

		if ( roleSelect ) {
			roleSelect.value = preset ? member.role_key : 'custom';
		}

		setCapabilities( $.isArray( member.capabilities ) ? member.capabilities : [] );
		syncRoleState( !! preset );
		openModal();

		if ( roleSelect ) {
			roleSelect.focus();
		}
	}

	/**
	 * Apply table filters.
	 *
	 * @return {void}
	 */
	function applyFilters() {
		var query = searchInput ? String( searchInput.value || '' ).toLowerCase() : '';
		var selectedRole = roleFilter ? String( roleFilter.value || '' ) : '';
		var visibleCount = 0;

		memberRows.forEach( function ( row ) {
			var searchText = String( row.getAttribute( 'data-team-search' ) || '' );
			var roleKey = String( row.getAttribute( 'data-team-role' ) || '' );
			var matchesSearch = ! query || searchText.indexOf( query ) !== -1;
			var matchesRole = ! selectedRole || roleKey === selectedRole;
			var showRow = matchesSearch && matchesRole;

			row.hidden = ! showRow;

			if ( showRow ) {
				visibleCount += 1;
			}
		} );

		if ( emptyFilteredRow ) {
			emptyFilteredRow.hidden = 0 === memberRows.length || visibleCount !== 0;
		}
	}

	if ( addButton ) {
		addButton.addEventListener( 'click', function () {
			if ( addButton.disabled ) {
				return;
			}

			prepareAddMode();
		} );
	}

	$( widget ).on( 'click', '.nxtcc-team-access-edit', function () {
		var raw = $( this ).attr( 'data-member' ) || '{}';
		prepareEditMode( parseMember( raw ) );
	} );

	if ( closeButton ) {
		closeButton.addEventListener( 'click', closeModal );
	}

	if ( cancelButton ) {
		cancelButton.addEventListener( 'click', closeModal );
	}

	if ( modal ) {
		modal.addEventListener( 'click', function ( event ) {
			if ( event.target === modal ) {
				closeModal();
			}
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && modal && ! modal.hidden ) {
			closeModal();
		}
	} );

	if ( roleSelect ) {
		roleSelect.addEventListener( 'change', function () {
			syncRoleState( true );
		} );
	}

	capabilityInputs.forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			syncSummary();
		} );
	} );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var action = actionField ? String( actionField.value || 'add' ) : 'add';
			var selectedUser = userSelect ? String( userSelect.value || '' ) : '';

			if ( ( 'add' === action || 'update' === action ) && ! selectedUser ) {
				event.preventDefault();
				window.alert( strings.userRequired || 'Select a WordPress user before saving access.' );
			}
		} );
	}

	$( widget ).on( 'submit', '.nxtcc-team-access-remove-form', function () {
		return window.confirm( strings.removeConfirm || 'Remove this team member from the current tenant?' );
	} );

	if ( searchInput ) {
		searchInput.addEventListener( 'input', applyFilters );
	}

	if ( roleFilter ) {
		roleFilter.addEventListener( 'change', applyFilters );
	}

	applyFilters();
	syncRoleState( true );
} );
