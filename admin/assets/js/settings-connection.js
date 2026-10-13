/**
 * Connection settings UI.
 *
 * Handles token visibility, webhook token actions, diagnostics, and the
 * uninstall warning prompt.
 *
 * @package NXTCC
 */

/* global jQuery, NXTCC_ADMIN, ajaxurl */

jQuery( function ( $ ) {
	'use strict';

	const $connection = $( '.nxtcc-settings-connection' );
	let generatingToken = false;

	if ( ! $connection.length ) {
		return;
	}

	function getAjaxUrl() {
		const url =
			( window.NXTCC_ADMIN && window.NXTCC_ADMIN.ajax_url ) ||
			( typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '' );

		return ( url || '' ).toString().trim();
	}

	function getNonce() {
		const $field = $( '#nxtcc_admin_ajax_nonce' );

		if ( $field.length ) {
			return String( $field.val() || '' ).trim();
		}

		if ( window.NXTCC_ADMIN && window.NXTCC_ADMIN.nonce ) {
			return String( window.NXTCC_ADMIN.nonce ).trim();
		}

		return '';
	}

	function getVal( selector ) {
		return String( $( selector ).val() || '' ).trim();
	}

	function ajaxErrorMessage( xhr, fallback ) {
		const data = xhr.responseJSON && xhr.responseJSON.data;
		return data && data.message ? String( data.message ) : fallback;
	}

	const identifierFields = [ 'nxtcc_whatsapp_business_account_id', 'nxtcc_phone_number_id' ];

	function setIdentifierError( input, message ) {
		const error = document.getElementById( input.id + '_error' );
		input.setCustomValidity( message );
		input.setAttribute( 'aria-invalid', message ? 'true' : 'false' );
		if ( error ) {
			error.textContent = message;
			error.hidden = ! message;
		}
	}

	function validateIdentifier( input, required ) {
		const value = input.value.trim();
		const message = ! value ? ( required ? input.dataset.requiredError : '' )
			: ( /^[0-9]+$/.test( value ) && value.length <= 255 ? '' : input.dataset.formatError );
		setIdentifierError( input, message || '' );
		return ! message;
	}

	function validateIdentifiers() {
		let firstInvalid = null;
		identifierFields.forEach( function ( id ) {
			const input = document.getElementById( id );
			if ( input && ! validateIdentifier( input, true ) && ! firstInvalid ) { firstInvalid = input; }
		} );
		if ( firstInvalid ) { firstInvalid.focus(); }
		return ! firstInvalid;
	}

	function createEl( tag, attrs, text ) {
		const node = document.createElement( tag );

		if ( attrs && 'object' === typeof attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				if ( null != attrs[ key ] ) {
					node.setAttribute( key, String( attrs[ key ] ) );
				}
			} );
		}

		if ( null != text ) {
			node.textContent = String( text );
		}

		return node;
	}

	function replaceResults( node ) {
		const target = document.getElementById( 'nxtcc-connection-results' );

		if ( ! target ) {
			return;
		}

		while ( target.firstChild ) {
			target.removeChild( target.firstChild );
		}

		if ( node ) {
			target.appendChild( node );
		}
	}

	function toggleVerifyCopyButton() {
		const enabledWebhook = $( '#nxtcc_meta_webhook_subscribed' ).is( ':checked' );
		const hasToken       = getVal( '#nxtcc_meta_webhook_verify_token' ).length > 0;

		$( '#nxtcc_meta_webhook_verify_token' ).prop(
			'disabled',
			! enabledWebhook
		);
		$( '#nxtcc_generate_token' ).prop( 'disabled', ! enabledWebhook || generatingToken );

		$( '#nxtcc_copy_verify_token' ).prop(
			'disabled',
			! enabledWebhook || ! hasToken
		);
	}

	function copyText( value, okMessage, failMessage ) {
		const text = String( value || '' );

		if ( ! text ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					window.alert( okMessage );
				},
				function () {
					window.alert( failMessage );
				}
			);
			return;
		}

		const area = document.createElement( 'textarea' );
		area.setAttribute( 'readonly', 'readonly' );
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		area.style.top = '-9999px';
		area.value = text;

		document.body.appendChild( area );
		area.select();

		try {
			document.execCommand( 'copy' );
			window.alert( okMessage );
		} catch ( e ) {
			window.alert( failMessage );
		}

		document.body.removeChild( area );
	}

	function renderConnectionResults( results ) {
		const target = document.getElementById( 'nxtcc-connection-results' );
		const labels = target ? target.dataset : {};
		const panel = createEl( 'div', { class: 'nxtcc-connection-results-panel' } );
		const table = createEl( 'table', { class: 'nxtcc-connection-results-table' } );
		const thead = createEl( 'thead' );
		const headRow = createEl( 'tr' );
		const tbody = createEl( 'tbody' );

		headRow.appendChild( createEl( 'th', null, 'Check' ) );
		headRow.appendChild( createEl( 'th', null, 'Status' ) );
		headRow.appendChild( createEl( 'th', null, 'Details' ) );
		thead.appendChild( headRow );

		Object.keys( results || {} ).forEach( function ( label ) {
			const info = results[ label ] || {};
			const ok = Boolean( info.success );
			const detail = info.error ? String( info.error ) : ok ? 'OK' : '';
			const row = createEl( 'tr' );
			const statusCell = createEl( 'td' );
			const statusPill = createEl(
				'span',
				{ class: ok ? 'nxtcc-settings-status-pill is-success' : 'nxtcc-settings-status-pill is-fail' },
				ok ? 'Pass' : 'Fail'
			);

			row.appendChild( createEl( 'td', null, label ) );
			statusCell.appendChild( statusPill );
			row.appendChild( statusCell );
			const detailCell = createEl( 'td', null, detail );
			if ( null != info.error_code ) {
				const code = createEl( 'div', { class: 'nxtcc-connection-error-code' }, ( labels.metaErrorLabel || 'Meta error code:' ) + ' ' );
				code.appendChild( createEl( 'code', null, info.error_code ) );
				if ( null != info.error_subcode ) {
					code.appendChild( document.createTextNode( ' ' + ( labels.metaSubcodeLabel || 'Subcode:' ) + ' ' ) );
					code.appendChild( createEl( 'code', null, info.error_subcode ) );
				}
				detailCell.appendChild( code );
			}
			row.appendChild( detailCell );
			tbody.appendChild( row );
		} );

		table.appendChild( thead );
		table.appendChild( tbody );
		panel.appendChild( table );

		return panel;
	}

	function renderError(message) {
		return createEl( 'p', { class: 'nxtcc-connection-results-message' }, message );
	}

	const ajaxURL = getAjaxUrl();

	$connection.on( 'input blur', '#nxtcc_whatsapp_business_account_id, #nxtcc_phone_number_id', function ( event ) {
		validateIdentifier( this, 'blur' === event.type || 'true' === this.getAttribute( 'aria-invalid' ) );
	} );

	$connection.on( 'submit', 'form', function ( event ) {
		if ( $( this ).find( 'input[name="nxtcc_settings_action"]' ).val() === 'save_connection_settings' && ! validateIdentifiers() ) {
			event.preventDefault();
		}
	} );

	$connection.on( 'click', '#nxtcc_toggle_token_visibility', function () {
		const $button = $( this );
		const $input = $( '#nxtcc_access_token' );

		if ( ! $input.length ) {
			return;
		}

		if ( 'password' === $input.attr( 'type' ) ) {
			$input.attr( 'type', 'text' );
			$button.text( 'Hide' );
			$button.attr( 'aria-label', 'Hide access token' );
			return;
		}

		$input.attr( 'type', 'password' );
		$button.text( 'Show' );
		$button.attr( 'aria-label', 'Show access token' );
	} );

	$connection.on( 'change', '#nxtcc_meta_webhook_subscribed', function () {
		toggleVerifyCopyButton();
	} );

	$connection.on( 'input', '#nxtcc_meta_webhook_verify_token', function () {
		toggleVerifyCopyButton();
	} );

	$connection.on( 'click', '#nxtcc_generate_token', function () {
		if ( generatingToken || ! $( '#nxtcc_meta_webhook_subscribed' ).is( ':checked' ) ) {
			return;
		}
		const nonce = getNonce();
		const baid = getVal( '#nxtcc_whatsapp_business_account_id' );
		const pnid = getVal( '#nxtcc_phone_number_id' );
		const $button = $( this );

		if ( ! ajaxURL || ! nonce ) {
			window.alert( 'Missing security token.' );
			return;
		}

		if ( ! validateIdentifiers() ) {
			return;
		}

		generatingToken = true;
		$button.addClass( 'is-disabled' );
		toggleVerifyCopyButton();

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
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.token ) {
					if ( baid !== getVal( '#nxtcc_whatsapp_business_account_id' ) || pnid !== getVal( '#nxtcc_phone_number_id' ) ) {
						window.alert( $button.attr( 'data-identifier-changed-error' ) || 'Connection identifiers changed. Generate a token for the current account.' );
						return;
					}
					$( '#nxtcc_meta_webhook_verify_token' ).val( response.data.token );
					toggleVerifyCopyButton();
					return;
				}

				window.alert( response && response.data && response.data.message ? String( response.data.message ) : 'Failed to generate token.' );
			} )
			.fail( function ( xhr ) {
				window.alert( ajaxErrorMessage( xhr, 'Network error while generating token.' ) );
			} )
			.always( function () {
				generatingToken = false;
				$button.removeClass( 'is-disabled' );
				toggleVerifyCopyButton();
			} );
	} );

	$connection.on( 'click', '#nxtcc_copy_verify_token', function () {
		copyText(
			getVal( '#nxtcc_meta_webhook_verify_token' ),
			'Verify token copied to clipboard.',
			'Could not copy verify token.'
		);
	} );

	$connection.on( 'click', '#nxtcc_copy_callback_url', function () {
		copyText(
			getVal( '#nxtcc-callback-url-input' ),
			'Callback URL copied to clipboard.',
			'Could not copy callback URL.'
		);
	} );

	$connection.on( 'click', '#nxtcc-check-connections', function ( event ) {
		const nonce = getNonce();
		const $button = $( this );

		event.preventDefault();

		if ( ! ajaxURL || ! nonce ) {
			window.alert( 'Missing security token.' );
			return;
		}
		if ( ! validateIdentifiers() ) { return; }

		$button
			.text( 'Checking...' )
			.prop( 'disabled', true )
			.addClass( 'is-disabled' );

		$.ajax( {
			url: ajaxURL,
			method: 'POST',
			data: {
				action: 'nxtcc_check_connections',
				nonce: nonce,
				app_id: getVal( '#nxtcc_app_id' ),
				business_account_id: getVal( '#nxtcc_whatsapp_business_account_id' ),
				phone_number_id: getVal( '#nxtcc_phone_number_id' ),
				test_number: getVal( '#nxtcc_test_number' ),
				test_template: getVal( '#nxtcc_test_template' ),
				test_language: getVal( '#nxtcc_test_language' ),
			},
			timeout: 30000,
		} )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.results ) {
					replaceResults( renderConnectionResults( response.data.results ) );
					return;
				}

				replaceResults(
					renderError(
						response && response.data && response.data.message
							? String( response.data.message )
							: 'Failed to check connections.'
					)
				);
			} )
			.fail( function ( xhr ) {
				replaceResults( renderError( ajaxErrorMessage( xhr, 'Network error while checking connections.' ) ) );
			} )
			.always( function () {
				$button
					.text( 'Check All Connections' )
					.prop( 'disabled', false )
					.removeClass( 'is-disabled' );
			} );
	} );

	$connection.on( 'change', '#nxtcc_delete_data_on_uninstall', function () {
		if ( $( this ).is( ':checked' ) ) {
			window.alert(
				'This will permanently delete all NXT Cloud Chat data for this site when the Free plugin is uninstalled.'
			);
		}
	} );

	toggleVerifyCopyButton();
} );
