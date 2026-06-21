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
	var actionLevelSelect = document.getElementById( 'nxtcc_team_action_level' );
	var dataScopeSelect = document.getElementById( 'nxtcc_team_data_scope' );
	var assignmentEligible = document.getElementById( 'nxtcc_team_assignment_eligible' );
	var capabilitiesPanel = document.getElementById( 'nxtcc-team-access-capabilities' );
	var roleDescription = document.getElementById( 'nxtcc-team-access-role-description' );
	var addButton = document.getElementById( 'nxtcc-team-access-add-member' );
	var newAccessTeamButton = document.getElementById( 'nxtcc-team-access-new-team' );
	var newAccessTeamPanel = document.getElementById( 'nxtcc-team-access-team-create' );
	var newAccessTeamCancel = document.getElementById( 'nxtcc-team-access-team-create-cancel' );
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
	var capabilityScopeInputs = Array.prototype.slice.call(
		document.querySelectorAll( '#nxtcc-team-access-capabilities .nxtcc-team-access-row-scope' )
	);
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

	/**
	 * Find a permission checkbox by value inside a root node.
	 *
	 * @param {Element} root Root node.
	 * @param {string} capability Capability key.
	 * @return {HTMLInputElement|null} Matching checkbox.
	 */
	function findPermissionInput( root, capability ) {
		var inputs;
		var i;

		if ( ! root || ! capability ) {
			return null;
		}

		inputs = root.querySelectorAll( '.nxtcc-team-access-permission-input' );
		for ( i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].value === capability ) {
				return inputs[ i ];
			}
		}

		return null;
	}

	/**
	 * Keep manage permissions and their view partner consistent.
	 *
	 * @param {Element} root Root node.
	 * @return {void}
	 */
	function normalizePermissionMatrix( root ) {
		var manageInputs;

		if ( ! root ) {
			return;
		}

		manageInputs = root.querySelectorAll( '.nxtcc-team-access-permission-manage' );
		Array.prototype.forEach.call( manageInputs, function ( input ) {
			var viewCap = input.getAttribute( 'data-view-cap' ) || '';
			var viewInput;

			if ( ! input.checked || ! viewCap ) {
				return;
			}

			viewInput = findPermissionInput( root, viewCap );
			if ( viewInput ) {
				viewInput.checked = true;
			}
		} );
	}

	/**
	 * React to one permission checkbox change.
	 *
	 * @param {HTMLInputElement} input Changed input.
	 * @return {void}
	 */
	function handlePermissionChange( input ) {
		var root;
		var linkedCap;
		var linkedInput;

		if ( ! input ) {
			return;
		}

		root = input.closest( 'form' ) || input.closest( '.nxtcc-team-access-permission-matrix' ) || document;

		if ( input.classList.contains( 'nxtcc-team-access-permission-manage' ) && input.checked ) {
			linkedCap = input.getAttribute( 'data-view-cap' ) || '';
			linkedInput = findPermissionInput( root, linkedCap );
			if ( linkedInput ) {
				linkedInput.checked = true;
			}
		}

		if ( input.classList.contains( 'nxtcc-team-access-permission-view' ) && ! input.checked ) {
			linkedCap = input.getAttribute( 'data-manage-cap' ) || '';
			linkedInput = findPermissionInput( root, linkedCap );
			if ( linkedInput ) {
				linkedInput.checked = false;
			}
		}

		normalizePermissionMatrix( root );
		syncActionLevel( root );
	}

	/**
	 * Get the hidden action-level input for a form.
	 *
	 * @param {Element} root Root node.
	 * @return {HTMLInputElement|null} Action-level field.
	 */
	function getActionLevelField( root ) {
		return root ? root.querySelector( '.nxtcc-team-access-action-field' ) : null;
	}

	/**
	 * Store the form action level from checked manage permissions.
	 *
	 * @param {Element} root Root node.
	 * @return {void}
	 */
	function syncActionLevel( root ) {
		var actionField = getActionLevelField( root );
		var manageInputs;
		var hasManage = false;

		if ( ! root || ! actionField || actionField.disabled ) {
			return;
		}

		manageInputs = root.querySelectorAll( '.nxtcc-team-access-permission-manage' );
		Array.prototype.forEach.call( manageInputs, function ( input ) {
			if ( input.checked ) {
				hasManage = true;
			}
		} );

		actionField.value = hasManage ? 'manage' : 'view_only';
	}

	/**
	 * Get the master data-scope select for a form.
	 *
	 * @param {Element} root Root node.
	 * @return {HTMLSelectElement|null} Master select.
	 */
	function getMasterScope( root ) {
		return root ? root.querySelector( '.nxtcc-team-access-master-scope' ) : null;
	}

	/**
	 * Mirror one visible row-scope select into its hidden POST inputs.
	 *
	 * @param {HTMLSelectElement} select Row scope select.
	 * @return {void}
	 */
	function syncRowScopeInputs( select ) {
		var row;

		if ( ! select ) {
			return;
		}

		row = select.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		Array.prototype.forEach.call( row.querySelectorAll( '.nxtcc-team-access-row-scope-input' ), function ( input ) {
			input.value = select.value || 'all';
		} );
	}

	/**
	 * Sync all hidden row-scope inputs inside a root node.
	 *
	 * @param {Element} root Root node.
	 * @return {void}
	 */
	function syncAllRowScopeInputs( root ) {
		if ( ! root ) {
			return;
		}

		Array.prototype.forEach.call( root.querySelectorAll( '.nxtcc-team-access-row-scope' ), syncRowScopeInputs );
	}

	/**
	 * Apply a per-capability scope map to visible row scope selects.
	 *
	 * @param {Element} root Root node.
	 * @param {Object} scopeMap Scope map keyed by capability.
	 * @param {string} fallback Fallback scope.
	 * @return {void}
	 */
	function applyCapabilityScopes( root, scopeMap, fallback ) {
		scopeMap = scopeMap || {};
		fallback = fallback || 'all';

		if ( ! root ) {
			return;
		}

		Array.prototype.forEach.call( root.querySelectorAll( '.nxtcc-team-access-row-scope' ), function ( select ) {
			var viewCap = select.getAttribute( 'data-view-cap' ) || '';
			var manageCap = select.getAttribute( 'data-manage-cap' ) || '';
			var scope = fallback;

			if ( manageCap && Object.prototype.hasOwnProperty.call( scopeMap, manageCap ) ) {
				scope = scopeMap[ manageCap ];
			} else if ( viewCap && Object.prototype.hasOwnProperty.call( scopeMap, viewCap ) ) {
				scope = scopeMap[ viewCap ];
			}

			select.value = scope || fallback;
			syncRowScopeInputs( select );
		} );
	}

	/**
	 * Initialize all matrix rows inside one form.
	 *
	 * @param {Element} root Root node.
	 * @return {void}
	 */
	function initializeMatrix( root ) {
		normalizePermissionMatrix( root );
		syncAllRowScopeInputs( root );
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
	 * Apply capability checkbox state from keys.
	 *
	 * @param {Array<string>} capabilities Capability keys.
	 * @param {string} actionLevel Action level.
	 * @return {void}
	 */
	function setCapabilities( capabilities, actionLevel ) {
		var lookup = {};
		var allowManage = 'manage' === String( actionLevel || ( actionLevelSelect ? actionLevelSelect.value : 'manage' ) || 'manage' );

		( capabilities || [] ).forEach( function ( capability ) {
			lookup[ capability ] = true;
		} );

		capabilityInputs.forEach( function ( input ) {
			input.checked = !! lookup[ input.value ] && ( allowManage || ! input.classList.contains( 'nxtcc-team-access-permission-manage' ) );
		} );

		normalizePermissionMatrix( capabilitiesPanel );
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

		if ( seedPreset && ! isCustom && preset ) {
			if ( actionLevelSelect ) {
				actionLevelSelect.value = preset.action_level || 'manage';
			}
			if ( dataScopeSelect ) {
				dataScopeSelect.value = preset.data_scope || 'all';
			}
			if ( assignmentEligible ) {
				assignmentEligible.checked = !! preset.assignment_eligible;
			}
			if ( capabilitiesPanel ) {
				applyCapabilityScopes( capabilitiesPanel, preset.capability_scopes || {}, preset.data_scope || 'all' );
			}
		}

		if ( seedPreset && ! isCustom && presetCaps.length ) {
			setCapabilities( presetCaps, preset && preset.action_level ? preset.action_level : 'manage' );
		}

		capabilityInputs.forEach( function ( input ) {
			input.disabled = ! isCustom;
		} );
		capabilityScopeInputs.forEach( function ( input ) {
			input.disabled = ! isCustom;
		} );
		if ( actionLevelSelect ) {
			actionLevelSelect.disabled = ! isCustom;
		}
		if ( dataScopeSelect ) {
			dataScopeSelect.disabled = ! isCustom;
		}
		if ( assignmentEligible ) {
			assignmentEligible.disabled = ! isCustom;
		}

		if ( roleKeyField ) {
			roleKeyField.value = selectedRole;
		}

		if ( capabilitiesPanel ) {
			capabilitiesPanel.classList.toggle( 'is-readonly', ! isCustom );
			syncAllRowScopeInputs( capabilitiesPanel );
		}
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

		setCapabilities( [], 'view_only' );
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

		if ( actionLevelSelect ) {
			actionLevelSelect.value = member.action_level || 'manage';
		}
		if ( dataScopeSelect ) {
			dataScopeSelect.value = member.data_scope || 'all';
		}
		if ( assignmentEligible ) {
			assignmentEligible.checked = !! member.assignment_eligible;
		}
		setCapabilities( $.isArray( member.capabilities ) ? member.capabilities : [], member.action_level || 'manage' );
		syncRoleState( !! preset );
		if ( capabilitiesPanel ) {
			applyCapabilityScopes( capabilitiesPanel, member.capability_scopes || {}, member.data_scope || 'all' );
		}
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

	if ( newAccessTeamButton && newAccessTeamPanel ) {
		newAccessTeamButton.addEventListener( 'click', function () {
			newAccessTeamPanel.hidden = false;
			initializeMatrix( newAccessTeamPanel );

			if ( newAccessTeamPanel.scrollIntoView ) {
				newAccessTeamPanel.scrollIntoView( { block: 'nearest' } );
			}
		} );
	}

	if ( newAccessTeamCancel && newAccessTeamPanel ) {
		newAccessTeamCancel.addEventListener( 'click', function () {
			newAccessTeamPanel.hidden = true;
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
			handlePermissionChange( input );
		} );
	} );

	$( document ).on( 'change', '.nxtcc-team-access-team-form .nxtcc-team-access-permission-input', function () {
		handlePermissionChange( this );
	} );

	$( document ).on( 'change', '.nxtcc-team-access-master-scope', function () {
		var root = this.closest( 'form' ) || this.closest( '.nxtcc-team-access-team' ) || document;

		applyCapabilityScopes( root, {}, this.value || 'all' );
	} );

	$( document ).on( 'change', '.nxtcc-team-access-row-scope', function () {
		var root = this.closest( 'form' ) || this.closest( '.nxtcc-team-access-capabilities' ) || document;
		var masterScope = getMasterScope( root );

		if ( masterScope && ! masterScope.disabled ) {
			masterScope.value = this.value;
		}
		syncRowScopeInputs( this );
	} );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var action = actionField ? String( actionField.value || 'add' ) : 'add';
			var selectedUser = userSelect ? String( userSelect.value || '' ) : '';

			syncActionLevel( form );

			if ( ( 'add' === action || 'update' === action ) && ! selectedUser ) {
				event.preventDefault();
				window.alert( strings.userRequired || 'Select a WordPress user before saving access.' );
			}
		} );
	}

	/**
	 * Confirm access team deletion.
	 *
	 * @param {HTMLButtonElement} button Delete button.
	 * @return {boolean} Whether deletion may continue.
	 */
	function confirmTeamDelete( button ) {
		var teamName = button ? button.getAttribute( 'data-team-name' ) : '';
		var pattern = strings.deleteConfirm || 'Delete access team "%s"? This cannot be undone.';
		var message = pattern.replace( '%s', teamName || 'this access team' );

		return window.confirm( message );
	}

	$( document ).on( 'submit', '.nxtcc-team-access-team-form', function ( event ) {
		var submitter = event.originalEvent && event.originalEvent.submitter ? event.originalEvent.submitter : null;

		if ( submitter && submitter.classList && submitter.classList.contains( 'nxtcc-team-access-team-delete' ) ) {
			if ( '1' === this.getAttribute( 'data-nxtcc-delete-confirmed' ) ) {
				this.removeAttribute( 'data-nxtcc-delete-confirmed' );
				return true;
			}

			if ( ! confirmTeamDelete( submitter ) ) {
				event.preventDefault();
				return false;
			}

			return true;
		}

		syncActionLevel( this );
	} );

	$( document ).on( 'click', '.nxtcc-team-access-team-delete', function ( event ) {
		if ( ! confirmTeamDelete( this ) ) {
			event.preventDefault();
			return false;
		}

		if ( this.form ) {
			this.form.setAttribute( 'data-nxtcc-delete-confirmed', '1' );
		}

		return true;
	} );

	$( widget ).on( 'submit', '.nxtcc-team-access-remove-form', function () {
		return window.confirm( strings.removeConfirm || 'Remove this team member from the current tenant?' );
	} );

	if ( searchInput ) {
		searchInput.addEventListener( 'input', applyFilters );
	}

	if ( roleFilter ) {
		roleFilter.addEventListener( 'change', applyFilters );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.nxtcc-team-access-team-form' ), initializeMatrix );
	applyFilters();
	syncRoleState( true );
} );
