/**
 * NXT Cloud Chat – Authentication admin screen controller.
 *
 * Responsibilities:
 * - Render and populate the owner/profile dropdown used for WhatsApp accounts.
 * - Load available OTP authentication templates per selected owner.
 * - Create a default OTP template when none exist.
 * - Save OTP options + migration policy + allowed country rules.
 * - Trigger a one-time sync of verified bindings when auto-sync is enabled.
 *
 * Security/UI notes:
 * - No raw HTML strings are injected; all UI nodes are created via DOM APIs.
 * - AJAX requests use the localized admin endpoint and nonce when available.
 *
 * @package NXTCC
 */

/* global jQuery, window, document */

jQuery( function ( $ ) {
	'use strict';

	/**
	 * Escape a string for safe usage inside a CSS attribute selector.
	 *
	 * @param {*} val Value to escape.
	 * @return {string} Escaped value.
	 */
	function escapeAttrSelector( val ) {
		return String( val )
			.replace( /\\/g, '\\\\' )
			.replace( /"/g, '\\"' )
			.replace( /\]/g, '\\]' )
			.replace( /\[/g, '\\[' );
	}

	/**
	 * POST an AJAX request expecting JSON back.
	 *
	 * @param {string} url Endpoint URL.
	 * @param {Object} data Request payload.
	 * @return {jqXHR} jQuery promise.
	 */
	function postJSON( url, data ) {
		return $.ajax( {
			url,
			type: 'POST',
			data,
			dataType: 'json',
		} );
	}

	/**
	 * Print a message inside a UI element and optionally mark it as error.
	 *
	 * @param {HTMLElement|jQuery|string} el Target element.
	 * @param {string} msg Message to show.
	 * @param {boolean} isError Whether the message is an error.
	 * @return {void}
	 */
	function notify( el, msg, isError ) {
		$( el )
			.text( msg )
			.toggleClass( 'nxtcc-error', Boolean( isError ) );
	}

	/**
	 * Read a field value as typed by the admin (trimmed).
	 *
	 * @param {string} selector Field selector.
	 * @return {string} Raw value.
	 */
	function readRaw( selector ) {
		return String( $( selector ).val() || '' ).trim();
	}

	/**
	 * Normalize a path to have a leading and trailing slash.
	 *
	 * @param {*} p Candidate path.
	 * @return {string} Normalized path.
	 */
	function normalizePath( p ) {
		let path = String( p || '/nxt-whatsapp-login/' ).trim();

		if ( ! path.startsWith( '/' ) ) {
			path = '/' + path;
		}

		if ( ! path.endsWith( '/' ) ) {
			path += '/';
		}

		return path;
	}

	/**
	 * Create an element with optional className/text/attributes.
	 *
	 * @param {string} tag Tag name.
	 * @param {Object} props Props object.
	 * @return {HTMLElement} Created element.
	 */
	function el( tag, props ) {
		const node = document.createElement( tag );

		if ( props && props.className ) {
			node.className = props.className;
		}

		if ( props && props.text !== undefined ) {
			node.textContent = String( props.text );
		}

		if ( props && props.attrs ) {
			Object.keys( props.attrs ).forEach( function ( key ) {
				node.setAttribute( key, String( props.attrs[ key ] ) );
			} );
		}

		if ( props && props.style ) {
			Object.keys( props.style ).forEach( function ( key ) {
				node.style[ key ] = String( props.style[ key ] );
			} );
		}

		return node;
	}

	/**
	 * Remove all children from a DOM node.
	 *
	 * @param {HTMLElement} node Target node.
	 * @return {void}
	 */
	function clearNode( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Add an <option> to a <select>.
	 *
	 * @param {HTMLSelectElement} select Select element.
	 * @param {string} value Option value.
	 * @param {string} label Option label.
	 * @return {void}
	 */
	function addOption( select, value, label ) {
		const opt       = document.createElement( 'option' );
		opt.value       = String( value );
		opt.textContent = String( label );
		select.appendChild( opt );
	}

	// Prefer plugin-localized ajaxurl; fall back to core window.ajaxurl.
	const AJAX =
		( window.NXTCC_AUTH_ADMIN && window.NXTCC_AUTH_ADMIN.ajaxurl ) ||
		window.ajaxurl;

	const NONCE = window.NXTCC_AUTH_ADMIN ? window.NXTCC_AUTH_ADMIN.nonce : '';

	let OPTS =
		( window.NXTCC_AUTH_ADMIN && window.NXTCC_AUTH_ADMIN.opts ) || {};

	let POLICY =
		( window.NXTCC_AUTH_ADMIN && window.NXTCC_AUTH_ADMIN.policy ) || {};

	let SAVED =
		( window.NXTCC_AUTH_ADMIN &&
			( window.NXTCC_AUTH_ADMIN.savedTemplate ||
				( OPTS && OPTS.auth_template ) ) ) ||
		'';

	if ( ! AJAX ) {
		if ( window.console && window.console.warn ) {
			window.console.warn(
				'[NXTCC][AUTH] ajaxurl is not defined; aborting auth admin JS.'
			);
		}
		return;
	}

	let allowedCountriesMounted = false;

	let SELECTED_OWNER =
		OPTS && OPTS.default_tenant_key ? String( OPTS.default_tenant_key ) : '';

	/**
	 * Enable/disable migration inputs based on "Force migrate".
	 *
	 * @return {void}
	 */
	function toggleForceControls() {
		const forceMigrate = $( '#nxtcc-force-migrate' ).is( ':checked' );
		const $path        = $( '#nxtcc-force-path' );
		const $graceOn     = $( '#nxtcc-grace-enabled' );
		const $graceDays   = $( '#nxtcc-grace-days' );

		$path.prop( 'disabled', ! forceMigrate );
		$graceOn.prop( 'disabled', ! forceMigrate );
		$graceDays.prop(
			'disabled',
			! forceMigrate || ! $graceOn.is( ':checked' )
		);
	}

	/**
	 * Enable/disable grace days based on grace toggle + force migrate.
	 *
	 * @return {void}
	 */
	function toggleGraceDays() {
		const enabled = $( '#nxtcc-grace-enabled' ).is( ':checked' );
		$( '#nxtcc-grace-days' ).prop(
			'disabled',
			! enabled || ! $( '#nxtcc-force-migrate' ).is( ':checked' )
		);
	}

	/**
	 * Get current owner email from the selector.
	 *
	 * @return {string} Owner email or empty string.
	 */
	function currentOwner() {
		return String( $( '#nxtcc-auth-owner' ).val() || '' ).trim();
	}

	/**
	 * Apply saved option values to the form.
	 *
	 * @return {void}
	 */
	function hydrateFromOptions() {
		$( '#nxtcc-otp-len' ).val( String( OPTS.otp_len || 6 ) );
		$( '#nxtcc-resend-cooldown' ).val(
			String( OPTS.resend_cooldown || 30 )
		);

		$( '#nxtcc-terms-url' ).val( OPTS.terms_url || '' );
		$( '#nxtcc-privacy-url' ).val( OPTS.privacy_url || '' );

		$( '#nxtcc-auto-sync-contacts' ).prop(
			'checked',
			Boolean( parseInt( OPTS.auto_sync || 0, 10 ) )
		);

		$( '#nxtcc-sync-verified' ).prop(
			'disabled',
			! $( '#nxtcc-auto-sync-contacts' ).is( ':checked' )
		);
	}

	/**
	 * Apply saved policy values to the form (migration + branding + countries).
	 *
	 * @return {void}
	 */
	function hydrateFromPolicy() {
		const branding =
			POLICY.widget_branding !== undefined
				? Boolean( parseInt( POLICY.widget_branding, 10 ) )
				: false;

		$( '#nxtcc-widget-branding' ).prop( 'checked', branding );
		$( '#nxtcc-widget-branding' ).prop( 'disabled', false );

		const showPwd =
			POLICY.show_password !== undefined
				? Boolean( parseInt( POLICY.show_password, 10 ) )
				: true;

		const forceMigrate = Boolean( parseInt( POLICY.force_migrate || 0, 10 ) );
		const graceOn      = Boolean( parseInt( POLICY.grace_enabled || 0, 10 ) );

		const graceDays = Math.max(
			1,
			Math.min( 90, parseInt( POLICY.grace_days || 7, 10 ) )
		);

		const path = normalizePath( POLICY.force_path );

		$( '#nxtcc-show-password' ).prop( 'checked', showPwd );
		$( '#nxtcc-force-migrate' ).prop( 'checked', forceMigrate );
		$( '#nxtcc-force-path' ).val( path );
		$( '#nxtcc-grace-enabled' ).prop( 'checked', graceOn );
		$( '#nxtcc-grace-days' ).val( String( graceDays ) );

		toggleForceControls();
		toggleGraceDays();

		if (
			allowedCountriesMounted &&
			window.NXTCCAuthAllowedCountries &&
			typeof window.NXTCCAuthAllowedCountries.setSelected === 'function'
		) {
			const saved = Array.isArray( POLICY.allowed_countries )
				? POLICY.allowed_countries
				: [];
			window.NXTCCAuthAllowedCountries.setSelected( saved );
		}
	}

		/**
		 * Insert a node right after a reference node.
		 *
		 * Uses only appendChild (no insertBefore/prepend) to satisfy WP JS sniffs.
		 *
		 * @param {Node} newNode Node to insert.
		 * @param {Node} referenceNode Reference node.
		 * @return {void}
		 */
	function insertAfterNode( newNode, referenceNode ) {
		const parent = referenceNode && referenceNode.parentNode
			? referenceNode.parentNode
			: null;

		if ( ! parent ) {
			return;
		}

		// If referenceNode is the last child, append is equivalent to "after".
		if ( ! referenceNode.nextSibling ) {
			parent.appendChild( newNode );
			return;
		}

		// Otherwise, rebuild only the tail using appendChild (no insertBefore).
		const tail = document.createDocumentFragment();
		while ( referenceNode.nextSibling ) {
			tail.appendChild( referenceNode.nextSibling );
		}

		parent.appendChild( newNode );
		parent.appendChild( tail );
	}

	/**
	 * Ensure the owner selector exists in the DOM (DOM API only).
	 *
	 * @return {void}
	 */
	function ensureOwnerSelectExists() {
		if ( document.getElementById( 'nxtcc-auth-owner' ) ) {
			return;
		}

		const row = el( 'div', { className: 'nxtcc-field' } );

		const label = el( 'label', {
			className: 'nxtcc-label',
			text: 'Profile (owner)',
			attrs: { for : 'nxtcc-auth-owner' },
		} );

		const select = el( 'select', {
			className: 'nxtcc-select',
			attrs: { id: 'nxtcc-auth-owner' },
			style: { minWidth: '280px' },
		} );

		row.appendChild( label );
		row.appendChild( select );

		// Try to position the selector near the template field.
		const template = document.getElementById( 'nxtcc-auth-template' );
		const anchor   = template ? template.closest( '.nxtcc-field' ) : null;

		if ( anchor && anchor.parentNode ) {
			// We insert AFTER the anchor to avoid insertBefore().
			insertAfterNode( row, anchor );
			return;
		}

		// Fallback: append to auth body container, otherwise to <body>.
		const container = document.querySelector( '.nxtcc-auth-body' );
		if ( container ) {
			container.appendChild( row );
			return;
		}

		document.body.appendChild( row );
	}


	/**
	 * Load owners/profiles and populate the owner selector.
	 *
	 * @return {void}
	 */
	function loadOwners() {
		ensureOwnerSelectExists();

		const ownerEl = document.getElementById( 'nxtcc-auth-owner' );
		const $msg    = $( '#nxtcc-auth-msg' );

		if ( ! ownerEl ) {
			notify( $msg, 'Owner selector is missing from the page.', true );
			return;
		}

		ownerEl.disabled = true;
		clearNode( ownerEl );
		addOption( ownerEl, '', '— Select profile —' );

		postJSON( AJAX, { action: 'nxtcc_auth_list_owners', nonce: NONCE } )
			.done( function ( resp ) {
				const owners =
					resp &&
					resp.success &&
					resp.data &&
					Array.isArray( resp.data.owners )
						? resp.data.owners
						: [];

				if ( ! owners.length ) {
					notify(
						$msg,
						'No profiles found. Connect a WhatsApp Business account in Settings.'
					);
					$( '#nxtcc-auth-template' ).prop( 'disabled', true );
					$( '#nxtcc-generate-default' ).prop( 'disabled', true );
					return;
				}

				owners.forEach( function ( o ) {
					const mail  = o && o.mail ? String( o.mail ) : '';
					const label = o && o.label ? String( o.label ) : mail;

					if ( mail ) {
						addOption( ownerEl, mail, label );
					}
				} );

				const want      = String( SELECTED_OWNER || '' ).trim();
				const hasWanted =
					want &&
					ownerEl.querySelector(
						'option[value="' + escapeAttrSelector( want ) + '"]'
					);

				if ( hasWanted ) {
					ownerEl.value = want;
				} else {
					const firstReal = ownerEl.options.length > 1
						? ownerEl.options[ 1 ].value
						: '';
					if ( firstReal ) {
						ownerEl.value  = firstReal;
						SELECTED_OWNER = firstReal;
					}
				}

				loadTemplates();
			} )
			.fail( function () {
				notify( $msg, 'Failed to load profiles.', true );
			} )
			.always( function () {
				ownerEl.disabled = false;
			} );
	}

	/**
	 * Load authentication templates for the selected owner.
	 *
	 * @return {void}
	 */
	function loadTemplates() {
		const selEl = document.getElementById( 'nxtcc-auth-template' );
		const $msg  = $( '#nxtcc-auth-msg' );
		const $gen  = $( '#nxtcc-generate-default' );

		if ( ! selEl ) {
			notify( $msg, 'Template selector is missing from the page.', true );
			return;
		}

		const owner = currentOwner();

		selEl.disabled = true;
		notify( $msg, 'Loading templates...' );

		postJSON( AJAX, {
			action: 'nxtcc_auth_list_auth_templates',
			nonce: NONCE,
			owner_mailid: owner,
		} )
			.done( function ( resp ) {
				const payload = resp && resp.success ? resp.data : null;

				let list = Array.isArray( payload )
					? payload
					: payload && Array.isArray( payload.items )
					? payload.items
					: [];

				const hasAny = Array.isArray( list ) && list.length > 0;

				clearNode( selEl );
				addOption( selEl, '', '— Select template —' );

				if ( Array.isArray( list ) ) {
					list = list
						.slice()
						.sort( function ( a, b ) {
							const ta = a && a.last_updated_time
								? Date.parse( a.last_updated_time )
								: 0;
							const tb = b && b.last_updated_time
								? Date.parse( b.last_updated_time )
								: 0;
							return tb - ta;
						} );

					list.forEach( function ( t ) {
						const name = String( ( t && t.name ) || '' );
						const lang = String( ( t && t.language ) || '' );

						if ( ! name || ! lang ) {
							return;
						}

						addOption( selEl, name + '|' + lang, name + ' (' + lang + ')' );
					} );
				}

				if ( hasAny ) {
					$gen.hide();
				} else {
					$gen.show();
				}

				let picked = false;

				if (
					SAVED &&
					selEl.querySelector(
						'option[value="' + escapeAttrSelector( SAVED ) + '"]'
					)
				) {
					selEl.value = SAVED;
					picked      = true;

					notify(
						$msg,
						'Using saved template: ' +
							( selEl.options[ selEl.selectedIndex ]
								? selEl.options[ selEl.selectedIndex ].text
								: '' )
					);
				}

				if ( ! picked && list && list.length ) {
					const latest = list[ 0 ].name + '|' + list[ 0 ].language;
					selEl.value  = latest;
					picked       = true;

					notify(
						$msg,
						'Latest template selected by default: ' +
							( selEl.options[ selEl.selectedIndex ]
								? selEl.options[ selEl.selectedIndex ].text
								: '' )
					);
				}

				if ( ! picked ) {
					notify( $msg, 'No approved authentication templates found.' );
				}
			} )
			.fail( function () {
				notify(
					$msg,
					'Request failed while loading templates.',
					true
				);
			} )
			.always( function () {
				selEl.disabled = false;
			} );
	}

	/**
	 * Bind the "Generate default OTP template" action.
	 *
	 * @return {void}
	 */
	function bindGenerate() {
		let busy = false;

		$( '#nxtcc-generate-default' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( busy ) {
				return;
			}

			busy = true;

			const $btn  = $( this );
			const $msg  = $( '#nxtcc-auth-msg' );
			const owner = currentOwner();

			if ( ! owner ) {
				notify( $msg, 'Please select a profile first.', true );
				busy = false;
				return;
			}

			$btn.prop( 'disabled', true );
			notify( $msg, 'Submitting default OTP template...' );

			postJSON( AJAX, {
				action: 'nxtcc_auth_generate_default_template',
				nonce: NONCE,
				owner_mailid: owner,
				name: 'nxtcc_otp_default',
				language: 'en_US',
				expiry_minutes: 10,
			} )
				.done( function ( resp ) {
					if ( resp && resp.success && resp.data ) {
						const d = resp.data;

						if ( d.exists ) {
							notify(
								$msg,
								'Template already exists: ' +
									d.name +
									' (' +
									d.language +
									').'
							);
						} else if ( d.created ) {
							notify(
								$msg,
								'Template created: ' +
									d.name +
									' (' +
									d.language +
									').'
							);
						} else {
							notify( $msg, 'Unexpected response received.', true );
						}

						window.setTimeout( loadTemplates, 600 );
					} else {
						const m =
							resp && resp.data && resp.data.message
								? resp.data.message
								: 'Meta rejected the request.';
						notify( $msg, 'Error: ' + m, true );
					}
				} )
				.fail( function ( xhr ) {
					let m = 'Request failed.';

					try {
						const r = JSON.parse( xhr.responseText );
						if ( r && r.data && r.data.raw ) {
							m += ' ' + r.data.raw;
						}
					} catch ( err ) {
						// Keep the default message.
					}

					notify( $msg, m, true );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
					busy = false;
				} );
		} );
	}

	/**
	 * Bind saving of options + policy to the server.
	 *
	 * @return {void}
	 */
	function bindSave() {
		let saving = false;

		$( '#nxtcc-auth-save' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( saving ) {
				return;
			}

			saving = true;

			const $msg = $( '#nxtcc-save-msg' );
			const path = normalizePath(
				$( '#nxtcc-force-path' ).val() || '/nxt-whatsapp-login/'
			);

			const owner = currentOwner();

			const payload = {
				action: 'nxtcc_auth_save_options',
				nonce: NONCE,

				// Core OTP settings.
				otp_len: parseInt( $( '#nxtcc-otp-len' ).val() || 6, 10 ),
				resend_cooldown: parseInt(
					$( '#nxtcc-resend-cooldown' ).val() || 30,
					10
				),

				// Store URLs exactly as typed by the admin.
			terms_url: readRaw( '#nxtcc-terms-url' ),
			privacy_url: readRaw( '#nxtcc-privacy-url' ),

			auto_sync: $( '#nxtcc-auto-sync-contacts' ).is( ':checked' )
					? 1
					: 0,

			auth_template: $( '#nxtcc-auth-template' ).val() || '',

				// Migration behavior.
			show_password: $( '#nxtcc-show-password' ).is( ':checked' )
					? 1
					: 0,

			force_migrate: $( '#nxtcc-force-migrate' ).is( ':checked' )
					? 1
					: 0,

			force_path: path,

			grace_enabled: $( '#nxtcc-grace-enabled' ).is( ':checked' )
					? 1
					: 0,

			grace_days: parseInt( $( '#nxtcc-grace-days' ).val() || 7, 10 ),

				// Widget branding preference.
			widget_branding: $( '#nxtcc-widget-branding' ).is( ':checked' )
					? 1
					: 0,

				// Persist which owner was last selected.
			default_tenant_key: owner,
			};

			if (
				window.NXTCCAuthAllowedCountries &&
				typeof window.NXTCCAuthAllowedCountries.getSelected === 'function'
			) {
				payload.allowed_countries =
					window.NXTCCAuthAllowedCountries.getSelected();
			} else {
				payload.allowed_countries = [];
			}

			notify( $msg, 'Saving...' );

			postJSON( AJAX, payload )
				.done( function ( resp ) {
					if ( resp && resp.success ) {
						if ( resp.data && resp.data.opts ) {
							OPTS = resp.data.opts;

							if ( typeof window.NXTCC_AUTH_ADMIN === 'object' ) {
								window.NXTCC_AUTH_ADMIN.opts = OPTS;

								if ( OPTS.auth_template ) {
									window.NXTCC_AUTH_ADMIN.savedTemplate =
										OPTS.auth_template;
									SAVED                                 = OPTS.auth_template;
								}

								if ( OPTS.default_tenant_key ) {
									SELECTED_OWNER = OPTS.default_tenant_key;
								}
							}
						}

						if ( resp.data && resp.data.policy ) {
							POLICY = resp.data.policy;

							if ( typeof window.NXTCC_AUTH_ADMIN === 'object' ) {
								window.NXTCC_AUTH_ADMIN.policy = POLICY;
							}

							if (
								window.NXTCCAuthAllowedCountries &&
								typeof window.NXTCCAuthAllowedCountries
									.setSelected === 'function'
							) {
								window.NXTCCAuthAllowedCountries.setSelected(
									POLICY.allowed_countries || []
								);
							}
						}

						hydrateFromOptions();
						hydrateFromPolicy();

						notify( $msg, 'Saved.' );
					} else {
						notify( $msg, 'Save failed.', true );
					}
				} )
				.fail( function () {
					notify( $msg, 'Request failed.', true );
				} )
				.always( function () {
					saving = false;
				} );
		} );
	}

	/**
	 * Bind toggle interactions.
	 *
	 * @return {void}
	 */
	function bindToggles() {
		$( '#nxtcc-force-migrate' ).on( 'change', function () {
			toggleForceControls();
			toggleGraceDays();
		} );

		$( '#nxtcc-grace-enabled' ).on( 'change', toggleGraceDays );

		$( '#nxtcc-auto-sync-contacts' ).on( 'change', function () {
			$( '#nxtcc-sync-verified' ).prop(
				'disabled',
				! $( this ).is( ':checked' )
			);
		} );

		$( document ).on( 'change', '#nxtcc-auth-owner', function () {
			SELECTED_OWNER = currentOwner();
			loadTemplates();
		} );
	}

	/**
	 * Bind the "Sync verified" action.
	 *
	 * @return {void}
	 */
	function bindSyncVerified() {
		$( '#nxtcc-sync-verified' ).on( 'click', function ( e ) {
			e.preventDefault();

			const $btn = $( this );
			const $msg = $( '#nxtcc-sync-msg' );

			if ( $btn.is( ':disabled' ) ) {
				return;
			}

			$btn.prop( 'disabled', true );
			$msg.text( 'Syncing…' );

			postJSON( AJAX, {
				action: 'nxtcc_sync_verified_bindings',
				nonce: NONCE,
			} )
				.done( function ( resp ) {
					if ( resp && resp.success && resp.data ) {
						const d    = resp.data;
						const line =
							'Done. Inserted ' +
							( d.inserted || 0 ) +
							', already existing ' +
							( d.skipped || 0 ) +
							( typeof d.updated === 'number'
								? ', updated ' + d.updated
								: '' );

						$msg.text( line );
					} else {
						$msg.text( 'Sync failed.' );
					}
				} )
				.fail( function () {
					$msg.text( 'Request failed.' );
				} )
				.always( function () {
					$btn.prop(
						'disabled',
						! $( '#nxtcc-auto-sync-contacts' ).is( ':checked' )
					);
				} );
		} );
	}

	// Init (order matters).
	hydrateFromOptions();
	hydrateFromPolicy();
	bindToggles();

	loadOwners();
	bindGenerate();

	if (
		window.NXTCCAuthAllowedCountries &&
		document.getElementById( 'nxtcc-allowed-countries' )
	) {
		const savedList = Array.isArray( POLICY.allowed_countries )
			? POLICY.allowed_countries
			: [];

		window.NXTCCAuthAllowedCountries.mount(
			document.getElementById( 'nxtcc-allowed-countries' ),
			{
				selected: savedList,
				onChange: function () {},
			}
		);

		allowedCountriesMounted = true;
	}

	bindSave();
	bindSyncVerified();
} );
