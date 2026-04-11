/**
 * Admin Settings UI.
 *
 * Handles:
 * - Settings tab switching.
 * - Show/hide access token toggle.
 * - Webhook verify token generation + copy.
 * - Callback URL copy.
 * - Connection diagnostics renderer (XSS-safe DOM building).
 * - Uninstall data deletion confirmation prompt.
 *
 * Security:
 * - Avoids HTML string insertion into jQuery methods (append/after/appendTo/insertBefore).
 * - Builds nodes via document.createElement/textContent.
 * - Inserts nodes using insertAdjacentElement() (no jQuery .after(), no insertBefore()).
 *
 * @package NXTCC
 */

/* global jQuery, wp, NXTCC_ADMIN, ajaxurl */

jQuery( function ( $ ) {
	'use strict';

	const $widget = $( '.nxtcc-settings-widget' );

	if ( ! $widget.length ) {
		return;
	}

	/**
	 * Resolve admin AJAX endpoint.
	 *
	 * @return {string} URL.
	 */
	function getAjaxUrl() {
		const url =
			( window.NXTCC_ADMIN && window.NXTCC_ADMIN.ajax_url ) ||
			( typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '' );

		return ( url || '' ).toString().trim();
	}

	const ajaxURL = getAjaxUrl();

	if ( ! ajaxURL ) {
		if ( window.console && console.warn ) {
			console.warn(
				'[NXTCC] settings.js: ajaxURL is empty; AJAX features disabled.'
			);
		}
		return;
	}

	/**
	 * Get request nonce from DOM or localized script.
	 *
	 * @return {string} Nonce.
	 */
	function getNonce() {
		const $field = $( '#nxtcc_admin_ajax_nonce' );

		if ( $field.length ) {
			const v = ( $field.val() || '' ).toString().trim();
			if ( v ) {
				return v;
			}
		}

		if ( window.NXTCC_ADMIN && window.NXTCC_ADMIN.nonce ) {
			return String( window.NXTCC_ADMIN.nonce ).trim();
		}

		return '';
	}

	/**
	 * Get and trim a field value inside the widget.
	 *
	 * @param {string} sel Selector.
	 * @return {string} Value.
	 */
	function getVal( sel ) {
		const v = $widget.find( sel ).val();
		return ( v == null ? '' : String( v ) ).trim();
	}

	/**
	 * Enable/disable "Copy Verify Token" based on webhook subscription + token value.
	 *
	 * @return {void}
	 */
	function toggleVerifyCopyButton() {
		const enabledWebhook = $( '#nxtcc_meta_webhook_subscribed' ).is( ':checked' );
		const val            = ( $( '#nxtcc_meta_webhook_verify_token' ).val() || '' )
			.toString()
			.trim();

		$( '#nxtcc_copy_verify_token' ).prop(
			'disabled',
			! enabledWebhook || val.length === 0
		);
	}

	/**
	 * Copy helper with Clipboard API fallback (DOM only, no HTML strings).
	 *
	 * @param {string} text Text to copy.
	 * @param {string} okMsg Success message.
	 * @param {string} failMsg Failure message.
	 * @return {void}
	 */
	function copyText( text, okMsg, failMsg ) {
		const value = ( text || '' ).toString();

		if ( ! value ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then(
				function () {
					alert( okMsg );
				},
				function () {
					alert( failMsg );
				}
			);
			return;
		}

		const ta = document.createElement( 'textarea' );
		ta.setAttribute( 'readonly', 'readonly' );
		ta.style.position = 'absolute';
		ta.style.left     = '-9999px';
		ta.style.top      = '-9999px';
		ta.value          = value;

		document.body.appendChild( ta );
		ta.select();

		try {
			document.execCommand( 'copy' );
			alert( okMsg );
		} catch ( e ) {
			alert( failMsg );
		}

		document.body.removeChild( ta );
	}

	/**
	 * Create a DOM element with optional attributes and text.
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
				if ( null == attrs[ key ] ) {
					return;
				}
				node.setAttribute( key, String( attrs[ key ] ) );
			} );
		}

		if ( null != text ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Insert a node after a reference element using insertAdjacentElement().
	 *
	 * This avoids jQuery .after() warnings and avoids insertBefore() usage.
	 *
	 * @param {Element} ref  Reference element.
	 * @param {Element} node Node to insert.
	 * @return {void}
	 */
	function insertAfterElement( ref, node ) {
		if ( ! ref || ! node || ! ref.insertAdjacentElement ) {
			return;
		}

		// Safe DOM insertion (no HTML strings involved).
		ref.insertAdjacentElement( 'afterend', node );
	}

	/**
	 * Build connection diagnostics results as DOM nodes (XSS-safe).
	 *
	 * @param {Object} results Results map keyed by label.
	 * @return {DocumentFragment} Fragment.
	 */
	function renderConnectionResults( results ) {
		const frag  = document.createDocumentFragment();
		const wrap  = el( 'div', { class: 'nxtcc-connection-results' } );
		const h3    = el( 'h3', null, 'Connection Status:' );
		const table = el( 'table', { class: 'widefat striped' } );

		const thead = el( 'thead' );
		const trh   = el( 'tr' );
		trh.appendChild( el( 'th', null, 'Check' ) );
		trh.appendChild( el( 'th', null, 'Status' ) );
		trh.appendChild( el( 'th', null, 'Details' ) );
		thead.appendChild( trh );

		const tbody = el( 'tbody' );

		Object.keys( results || {} ).forEach( function ( label ) {
			const info   = results[ label ] || {};
			const ok     = Boolean( info.success );
			const detail = info.error ? String( info.error ) : ok ? 'OK' : '';

			const tr  = el( 'tr' );
			const td1 = el( 'td', null, label );
			const td2 = el(
				'td',
				{ class: ok ? 'nxtcc-status-ok' : 'nxtcc-status-fail' },
				ok ? '✅' : '❌'
			);
			const td3 = el( 'td', null, detail );

			tr.appendChild( td1 );
			tr.appendChild( td2 );
			tr.appendChild( td3 );
			tbody.appendChild( tr );
		} );

		table.appendChild( thead );
		table.appendChild( tbody );

		wrap.appendChild( h3 );
		wrap.appendChild( table );
		frag.appendChild( wrap );

		return frag;
	}

	// Tab switching.
	$widget.on( 'click', '.nxtcc-settings-tab', function () {
		const tab = $( this ).data( 'tab' );
		if ( ! tab ) {
			return;
		}

		$widget
			.find( '.nxtcc-settings-tab' )
			.removeClass( 'active' )
			.attr( 'aria-selected', 'false' );

		$widget.find( '.nxtcc-settings-tab-content' ).hide();

		$( this ).addClass( 'active' ).attr( 'aria-selected', 'true' );

		$widget
			.find( '.nxtcc-settings-tab-content[data-tab="' + tab + '"]' )
			.show();
	} );

	// Show/hide access token.
	$widget.on( 'click', '#nxtcc_toggle_token_visibility', function () {
		const $btn = $( this );
		const $inp = $( '#nxtcc_access_token' );

		if ( ! $inp.length ) {
			return;
		}

		const isPwd    = $inp.attr( 'type' ) === 'password';
		const newType  = isPwd ? 'text' : 'password';
		const newLabel = isPwd ? 'Hide' : 'Show';

		$inp.attr( 'type', newType );
		$btn.text( newLabel );
		$btn.attr( 'aria-label', newLabel + ' token' );
	} );

	// Enable/disable verify token field + related buttons.
	$widget.on( 'change', '#nxtcc_meta_webhook_subscribed', function () {
		const enabled = $( this ).is( ':checked' );

		$( '#nxtcc_meta_webhook_verify_token, #nxtcc_generate_token' ).prop(
			'disabled',
			! enabled
		);

		toggleVerifyCopyButton();
	} );

	// Keep copy verify token button in sync with token input.
	$widget.on( 'input', '#nxtcc_meta_webhook_verify_token', function () {
		toggleVerifyCopyButton();
	} );

	// Generate webhook token via AJAX.
	$widget.on( 'click', '#nxtcc_generate_token', function () {
		const nonce = getNonce();

		if ( ! nonce ) {
			alert( '❌ Missing security token.' );
			return;
		}

		const baid = getVal( '#nxtcc_whatsapp_business_account_id' );
		const pnid = getVal( '#nxtcc_phone_number_id' );

		if ( ! baid || ! pnid ) {
			alert( 'Please fill Business Account ID and Phone Number ID first.' );
			return;
		}

		const $btn = $( this ).prop( 'disabled', true ).addClass( 'is-disabled' );

		$.ajax( {
			url: ajaxURL,
			method: 'POST',
			data: {
				action: 'nxtcc_generate_webhook_token',
				nonce: nonce,
				business_account_id: baid,
				phone_number_id: pnid,
			},
			timeout: 20000,
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.token ) {
					$( '#nxtcc_meta_webhook_verify_token' ).val( res.data.token );
					$( '#nxtcc_meta_webhook_subscribed' )
						.prop( 'checked', true )
						.trigger( 'change' );

					toggleVerifyCopyButton();

					// Ensure callback description exists and render it safely.
					if ( ! $( '#nxtcc-meta-callback-desc' ).length ) {
						const cbUrl =
							( window.NXTCC_ADMIN && window.NXTCC_ADMIN.callback_url ) || '';

						const desc = el( 'div', {
							id: 'nxtcc-meta-callback-desc',
							class: 'nxtcc-description',
						} );

						const br = el( 'br' );
						desc.appendChild( br );
						desc.appendChild( el( 'label', null, 'Callback URL:' ) );

						const wrap = el( 'div' );

						const input = el( 'input', {
							type: 'text',
							id: 'nxtcc-callback-url-input',
							class: 'nxtcc-text-field',
							readonly: 'readonly',
							style: 'width: 720px; max-width: 450px; margin-top: 4px;',
						} );
						input.value = String( cbUrl || '' );
						wrap.appendChild( input );

						const copyBtn = el(
							'button',
							{
								type: 'button',
								class: 'nxtcc-btn-outline',
								id: 'nxtcc_copy_callback_url',
							},
							'Copy'
						);
						wrap.appendChild( copyBtn );
						desc.appendChild( wrap );

						const ref = $( '#nxtcc_meta_webhook_subscribed' )
							.closest( '.nxtcc-form-row' )
							.get( 0 );

						if ( ref ) {
							insertAfterElement( ref, desc );
						}
					}

					return;
				}

				alert( '❌ Failed to generate token.' );
			} )
			.fail( function () {
				alert( '❌ Network error while generating token.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).removeClass( 'is-disabled' );
			} );
	} );

	// Copy verify token.
	$widget.on( 'click', '#nxtcc_copy_verify_token', function () {
		const val = ( $( '#nxtcc_meta_webhook_verify_token' ).val() || '' )
			.toString()
			.trim();

		copyText(
			val,
			'Verify token copied to clipboard.',
			'Could not copy verify token.'
		);
	} );

	// Copy callback URL.
	$widget.on( 'click', '#nxtcc_copy_callback_url', function () {
		let url = '';

		if ( window.NXTCC_ADMIN && window.NXTCC_ADMIN.callback_url ) {
			url = String( window.NXTCC_ADMIN.callback_url ).trim();
		} else {
			const $input = $( '#nxtcc-callback-url-input' );
			if ( $input.length ) {
				url = ( $input.val() || '' ).toString().trim();
			}

			if ( ! url ) {
				const $code = $( '#nxtcc-meta-callback-desc code' );
				if ( $code.length ) {
					url = ( $code.text() || '' ).toString().trim();
				}
			}
		}

		if ( ! url ) {
			alert( 'Callback URL not available to copy.' );
			return;
		}

		copyText(
			url,
			'Callback URL copied to clipboard.',
			'Could not copy callback URL.'
		);
	} );

	// Connection diagnostics (no token sent).
	$widget.on( 'click', '#nxtcc-check-connections', function ( e ) {
		e.preventDefault();

		const nonce = getNonce();
		if ( ! nonce ) {
			alert( '❌ Missing security token.' );
			return;
		}

		const payload = {
			action: 'nxtcc_check_connections',
			nonce: nonce,
			app_id: getVal( 'input[name="nxtcc_app_id"]' ),
			business_account_id: getVal(
				'input[name="nxtcc_whatsapp_business_account_id"]'
			),
		phone_number_id: getVal( 'input[name="nxtcc_phone_number_id"]' ),
		test_number: getVal( 'input[name="nxtcc_test_number"]' ),
		test_template: getVal( 'input[name="nxtcc_test_template"]' ),
		test_language: getVal( 'input[name="nxtcc_test_language"]' ),
		};

		const $btn = $( this )
			.text( 'Checking…' )
			.prop( 'disabled', true )
			.addClass( 'is-disabled' );

		$.ajax( {
			url: ajaxURL,
			method: 'POST',
			data: payload,
			timeout: 30000,
		} )
			.done( function ( res ) {
				const targetEl = $( '#nxtcc-connection-results' ).get( 0 );

				if ( ! targetEl ) {
					return;
				}

				while ( targetEl.firstChild ) {
					targetEl.removeChild( targetEl.firstChild );
				}

				if ( res && res.success && res.data && res.data.results ) {
					targetEl.appendChild( renderConnectionResults( res.data.results ) );
					return;
				}

				const msg =
					res && res.data && res.data.message
						? String( res.data.message )
						: 'Failed to check connections';

				const p       = el( 'p', null, '❌ ' + msg );
				p.style.color = 'red';
				targetEl.appendChild( p );
			} )
			.fail( function () {
				const targetEl = $( '#nxtcc-connection-results' ).get( 0 );
				if ( ! targetEl ) {
					return;
				}

				while ( targetEl.firstChild ) {
					targetEl.removeChild( targetEl.firstChild );
				}

				const p       = el( 'p', null, '❌ Network error' );
				p.style.color = 'red';
				targetEl.appendChild( p );
			} )
			.always( function () {
				$btn
					.text( 'Check All Connections' )
					.prop( 'disabled', false )
					.removeClass( 'is-disabled' );
			} );
	} );

	// Confirm when enabling "Delete all data on uninstall".
	$widget.on( 'change', '#nxtcc_delete_data_on_uninstall', function () {
		if ( $( this ).is( ':checked' ) ) {
			alert(
				'This will permanently delete ALL NXT Cloud Chat data (Free + Pro) for this site when you uninstall the FREE plugin.'
			);
		}
	} );

	// Initial state sync.
	toggleVerifyCopyButton();
} );


