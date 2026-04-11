/**
 * Login widget (phone + OTP) for NXT Cloud Chat.
 *
 * @package NXTCC
 */

/* global wp */

jQuery( function ( $ ) {
	'use strict';

	function nxtccInitAuthWidget( widgetEl ) {
		const $widget = $( widgetEl );
		if ( ! $widget.length || $widget.data( 'nxtccInit' ) ) {
			return;
		}

		$widget.data( 'nxtccInit', 1 );

	/**
	 * I18n helper (fallback to identity if wp.i18n not present).
	 *
	 * @param {string} s Text.
	 * @return {string} Translated text.
	 */
	const __ =
		window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function'
			? window.wp.i18n.__
			: ( s ) => s;

	const AUTH = window.NXTCC_AUTH && typeof window.NXTCC_AUTH === 'object' ? window.NXTCC_AUTH : {};

	// Elements (Step 1).
	const $country  = $widget.find( '.nxtcc-country' );
	const $phone    = $widget.find( '.nxtcc-phone' );
	const $btnSend  = $widget.find( '.nxtcc-btn-send' );
	const $errPhone = $widget.find( '.nxtcc-error-phone' );

	// Elements (Step 2 - OTP).
	const $stepPhone  = $widget.find( '.nxtcc-step-phone' );
	const $stepOtp    = $widget.find( '.nxtcc-step-otp' );
	const $otpTarget  = $widget.find( '.nxtcc-otp-target' );
	const $otpInputs  = $widget.find( '.nxtcc-otp-inputs' );
	const $btnVerify  = $widget.find( '.nxtcc-btn-verify' );
	const $btnChange  = $widget.find( '.nxtcc-btn-change' );
	const $btnResend  = $widget.find( '.nxtcc-btn-resend' );
	const $errOtp     = $widget.find( '.nxtcc-error-otp' );
	const $expiryText = $widget.find( '.nxtcc-expiry' );

	// Data.
	const sessionId      = String( $widget.data( 'session-id' ) || '' );
	const defaultCountry = String( $widget.data( 'default-country' ) || 'IN' ).toUpperCase();

	const REST_BASE =
		AUTH.rest && AUTH.rest.url
			? String( AUTH.rest.url ).replace( /\/+$/, '' )
			: '';

	const NONCE = AUTH.nonce ? String( AUTH.nonce ) : '';

	// Dynamic values: prefer opts, then policy, then hard default.
	let otpLen =
		( AUTH.opts && Number( AUTH.opts.otp_len ) ) ||
		( AUTH.policy && Number( AUTH.policy.otpLen ) ) ||
		6;

	let resendCooldown =
		( AUTH.opts && Number( AUTH.opts.resend_cooldown ) ) ||
		( AUTH.policy && Number( AUTH.policy.resendCooldown ) ) ||
		30;

	otpLen         = Math.max( 4, Math.min( 8, parseInt( otpLen, 10 ) || 6 ) );
	resendCooldown = Math.max( 10, Math.min( 300, parseInt( resendCooldown, 10 ) || 30 ) );

	const COUNTRY_JSON_URL =
		AUTH.countryJson ? String( AUTH.countryJson ) : null;

	const ALLOWED =
		AUTH.policy && Array.isArray( AUTH.policy.allowedCountries )
			? AUTH.policy.allowedCountries.map( ( x ) => String( x || '' ).toUpperCase() )
			: [];

	const COUNTRY_MAP = {}; // { "IN": { name, dial_code, example_format }, ... }.
	let lastE164      = null; // Phone used for current OTP session.
	let expiryTimer   = null; // setInterval handle.
	let expiryUntil   = 0; // Timestamp when OTP expires.
	let resendTimer   = null; // Resend cooldown timer.
	let resendLeft    = 0; // Seconds remaining for resend.

	// ---------------- helpers ----------------.

	const digitsOnly = ( s ) => String( s || '' ).replace( /\D+/g, '' );

	const exDigitsLen = ( fmt ) => {
		const d       = digitsOnly( fmt );
		return d.length || 6;
	};

	const showErrorPhone = ( msg ) => {
		$errPhone.text( msg || '' ).attr( 'hidden', ! msg );
	};

	const showErrorOtp = ( msg ) => {
		$errOtp.text( msg || '' ).attr( 'hidden', ! msg );
	};

	/**
	 * Sanitize redirect URL (same-origin only).
	 *
	 * @param {string} urlCandidate Candidate.
	 * @return {string} Safe URL or empty string.
	 */
	function sanitizeRedirectUrl( urlCandidate ) {
		if ( ! urlCandidate ) {
			return '';
		}

		try {
			const url = new URL( String( urlCandidate ), window.location.origin );

			if ( url.origin !== window.location.origin ) {
				return '';
			}

			if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
				return '';
			}

			return url.toString();
		} catch ( err ) {
			return '';
		}
	}

	function getSafeRedirectTo() {
		return sanitizeRedirectUrl( document.referrer || '' );
	}

	function selectedCode() {
		const val = String( $country.val() || '' ).toUpperCase();

		if ( COUNTRY_MAP[ val ] ) {
			return val;
		}

		if ( /^\+?\d+$/.test( val ) ) {
			const want = digitsOnly( val );

			// eslint-disable-next-line no-restricted-syntax.
			for ( const c in COUNTRY_MAP ) {
				if ( ! Object.prototype.hasOwnProperty.call( COUNTRY_MAP, c ) ) {
					continue;
				}
				const dc = digitsOnly( COUNTRY_MAP[ c ].dial_code || '' );
				if ( dc === want ) {
					return c;
				}
			}
		}

		return defaultCountry;
	}

	function getSelectedMeta() {
		const code = selectedCode();
		return { code, meta: COUNTRY_MAP[ code ] || {} };
	}

	function setPlaceholderFromExample() {
		const meta = getSelectedMeta().meta;
		if ( meta.example_format ) {
			$phone.attr( 'placeholder', meta.example_format );
		}
	}

	function ensureOptionDialData() {
		const meta = getSelectedMeta().meta;
		const dial = meta.dial_code || '';
		const $opt = $country.find( 'option:selected' );

		if ( $opt.length && dial && ! $opt.attr( 'data-dial' ) ) {
			$opt.attr( 'data-dial', dial );
		}
	}

	function formatDialLabel( dial ) {
		const cleanDial = String( dial || '' ).trim();
		if ( ! cleanDial ) {
			return '';
		}

		return cleanDial.startsWith( '+' ) ? cleanDial : '+' + cleanDial;
	}

	function formatCountryOptionLabel( iso, isDropdownOpen ) {
		const meta = COUNTRY_MAP[ iso ] || {};
		const name = String( meta.name || iso ).trim();
		const code = String( iso || '' ).toUpperCase();
		const dial = formatDialLabel( meta.dial_code || '' );
		const dialWrapped = dial ? '(' + dial + ')' : '';

		if ( isDropdownOpen ) {
			return dialWrapped ? name + ' ' + dialWrapped : name;
		}

		return dialWrapped ? code + ' ' + dialWrapped : code;
	}

	function syncCountryOptionLabels( isDropdownOpen ) {
		const selectedIso = selectedCode();

		$country.find( 'option' ).each( function () {
			const iso = String( $( this ).val() || '' ).toUpperCase();
			if ( ! COUNTRY_MAP[ iso ] ) {
				return;
			}

			if ( ! isDropdownOpen && iso !== selectedIso ) {
				$( this ).text( formatCountryOptionLabel( iso, true ) );
				return;
			}

			$( this ).text( formatCountryOptionLabel( iso, isDropdownOpen ) );
		} );
	}

	function isPhoneValid() {
		const meta = getSelectedMeta().meta;
		const need = exDigitsLen( meta.example_format );
		const have = digitsOnly( $phone.val() ).length;

		return have === need;
	}

	function renderSendEnabled() {
		const ok = isPhoneValid();

		$btnSend.prop( 'disabled', ! ok );
		$widget.toggleClass( 'nxtcc-phone-valid', ok );

		return ok;
	}

	function setCountryOptions( finalKeys ) {
		$country.empty();

		finalKeys.forEach( ( iso ) => {
			const m    = COUNTRY_MAP[ iso ];
			const dial = String( m.dial_code || '' ).trim();
			if ( ! dial ) {
				return;
			}

			const label = formatDialLabel( dial );
			const $opt  = $( '<option>', {
				value: iso,
				'data-dial': label,
				'data-country-name': String( m.name || iso ),
				text: formatCountryOptionLabel( iso, true ),
			} );

			$country.get( 0 ).appendChild( $opt.get( 0 ) );
		} );
	}

	function populateCountries( data ) {
		if ( Array.isArray( data ) ) {
			data.forEach( ( o ) => {
				const iso = String( o.code || o.alpha2 || o.iso2 || '' ).toUpperCase();
				if ( ! iso ) {
					return;
				}

				COUNTRY_MAP[ iso ] = {
					name: o.name || iso,
					dial_code: o.dial_code || o.dial || o.phone_code || '',
					example_format: o.example_format || o.example || '',
				};
			} );
		} else if ( data && typeof data === 'object' ) {
			Object.keys( data ).forEach( ( k ) => {
				const iso = String( k || '' ).toUpperCase();
				const o   = data[ k ] || {};
				if ( ! iso ) {
					return;
				}

				COUNTRY_MAP[ iso ] = {
					name: o.name || iso,
					dial_code: o.dial_code || o.dial || o.phone_code || '',
					example_format: o.example_format || o.example || '',
				};
			} );
		}

		if ( $country.children( 'option' ).length <= 1 ) {
			const isAllowed = ( iso ) =>
				ALLOWED.length === 0 || ALLOWED.indexOf( iso ) !== -1;

			const keys  = Object.keys( COUNTRY_MAP ).filter( ( iso ) => {
				const m = COUNTRY_MAP[ iso ];
				return m && m.dial_code && isAllowed( iso );
			} );

			const finalKeys = keys.length
				? keys
				: Object.keys( COUNTRY_MAP ).filter(
					( iso ) => COUNTRY_MAP[ iso ] && COUNTRY_MAP[ iso ].dial_code
				);

			finalKeys.sort( ( a, b ) => {
				const A = COUNTRY_MAP[ a ].name || a;
				const B = COUNTRY_MAP[ b ].name || b;
				return A.localeCompare( B );
			} );

			setCountryOptions( finalKeys );

			if (
				COUNTRY_MAP[ defaultCountry ] &&
				( ALLOWED.length === 0 || ALLOWED.indexOf( defaultCountry ) !== -1 ) &&
				$country.find( 'option[value="' + defaultCountry + '"]' ).length
			) {
				$country.val( defaultCountry );
			} else if ( finalKeys.length ) {
				$country.val( finalKeys[ 0 ] );
			}
		} else {
			$country.find( 'option' ).each( function () {
				const iso  = String( $( this ).val() || '' ).toUpperCase();
				const meta = COUNTRY_MAP[ iso ];

				if ( ! meta ) {
					return;
				}

				let dial = meta.dial_code || $( this ).attr( 'data-dial' ) || '';
				dial     = String( dial ).trim();

				if ( ! dial ) {
					return;
				}

				const label = formatDialLabel( dial );

				$( this )
					.attr( 'data-dial', label )
					.attr( 'data-country-name', String( meta.name || iso ) )
					.text( formatCountryOptionLabel( iso, true ) );
			} );
		}

		ensureOptionDialData();
		syncCountryOptionLabels( false );
		setPlaceholderFromExample();
		renderSendEnabled();
	}

	function loadCountryJson() {
		if ( ! COUNTRY_JSON_URL ) {
			renderSendEnabled();
			return;
		}

		fetch( COUNTRY_JSON_URL, { credentials: 'same-origin' } )
			.then( ( r ) => ( r.ok ? r.json() : Promise.reject() ) )
			.then( populateCountries )
			.catch( () => renderSendEnabled() );
	}

	function buildPhoneE164() {
		const meta = getSelectedMeta().meta;

		const attrDial =
			$country.find( 'option:selected' ).attr( 'data-dial' ) || '';
		const rawDial  = String( meta.dial_code || attrDial || '' ).replace( /\s+/g, '' );
		const plusDial = rawDial.startsWith( '+' ) ? rawDial : '+' + rawDial;

		return plusDial + digitsOnly( $phone.val() );
	}

	function setSendLoading( loading ) {
		const btn = $btnSend.get( 0 );

		if ( loading ) {
			if ( ! $btnSend.data( 'orig-text' ) ) {
				$btnSend.data( 'orig-text', $btnSend.text() );
			}

			const base = String( $btnSend.data( 'orig-text' ) || '' );

			$btnSend.addClass( 'is-loading' ).prop( 'disabled', true );
			$btnSend.empty();

			btn.appendChild( document.createTextNode( base ) );

			const spinner     = document.createElement( 'span' );
			spinner.className = 'nxtcc-spinner';
			spinner.setAttribute( 'role', 'status' );
			spinner.setAttribute( 'aria-label', __( 'Sending...', 'nxt-cloud-chat' ) );

			btn.appendChild( spinner );
		} else {
			const base =
				$btnSend.data( 'orig-text' ) ||
				__( 'Send code on WhatsApp', 'nxt-cloud-chat' );

			$btnSend.removeClass( 'is-loading' ).text( base );
			$btnSend.prop( 'disabled', ! isPhoneValid() );
		}
	}

	function maskE164( e164 ) {
		const d = digitsOnly( e164 );
		if ( ! d ) {
			return e164;
		}

		const ccLocal =
			d.length > 10
				? [ d.slice( 0, d.length - 10 ), d.slice( -10 ) ]
				: [ '', d ];

		const cc    = ccLocal[ 0 ];
		const local = ccLocal[ 1 ];

		const visible =
			local.length <= 2 ? local : '********' + local.slice( -2 );

		return '+' + cc + ' ' + visible;
	}

	function buildOtpInputs( len ) {
		$otpInputs.empty();

		for ( let i = 0; i < len; i++ ) {
			const $box = $( '<input>', {
				type: 'tel',
				inputmode: 'numeric',
				maxlength: 1,
				class: 'nxtcc-otp-box',
				'aria-label': __( 'Digit', 'nxt-cloud-chat' ) + ' ' + ( i + 1 ),
			} );

			$otpInputs.get( 0 ).appendChild( $box.get( 0 ) );
		}

		const $boxes = $otpInputs.find( '.nxtcc-otp-box' );

		function getOtpValue() {
			let v = '';
			$boxes.each( function () {
				v += digitsOnly( $( this ).val() );
			} );
			return v;
		}

		function updateVerifyEnabled() {
			const val = getOtpValue();
			$btnVerify.prop( 'disabled', val.length !== otpLen );

			if ( val.length === otpLen ) {
				showErrorOtp( '' );
			}
		}

		$boxes.on( 'input', function () {
			const v = digitsOnly( $( this ).val() ).slice( -1 );
			$( this ).val( v );

			if ( v && this.nextElementSibling ) {
				this.nextElementSibling.focus();
			}

			updateVerifyEnabled();
		} );

		$boxes.on( 'keydown', function ( e ) {
			if ( e.key === 'Backspace' && ! $( this ).val() && this.previousElementSibling ) {
				this.previousElementSibling.focus();
			}
		} );

		$boxes.on( 'paste', function ( e ) {
			const clip =
				( e.originalEvent && e.originalEvent.clipboardData ) ||
				window.clipboardData ||
				null;

			const t =
				clip && typeof clip.getData === 'function'
					? clip.getData( 'text' )
					: '';

			const digits = digitsOnly( t ).slice( 0, otpLen );

			if ( ! digits ) {
				return;
			}

			e.preventDefault();

			$boxes.each( function ( idx ) {
				$( this ).val( digits[ idx ] || '' );
			} );

			updateVerifyEnabled();

			const lastFilled = Math.min( digits.length, otpLen ) - 1;
			if ( lastFilled >= 0 ) {
				$boxes.get( lastFilled ).focus();
			}
		} );

		$boxes.first().focus();
		updateVerifyEnabled();

		return {
			getValue: getOtpValue,
			focusFirst: () => $boxes.first().focus(),
		};
	}

	function startExpiryCountdown( ttlSecs ) {
		stopExpiryCountdown();

		const ttl   = Math.max( 1, parseInt( ttlSecs || 300, 10 ) );
		expiryUntil = Math.floor( Date.now() / 1000 ) + ttl;

		function render() {
			const left = Math.max( 0, expiryUntil - Math.floor( Date.now() / 1000 ) );

			const m       = Math.floor( left / 60 );
			const s       = left % 60;
			const sPadded = s < 10 ? '0' + s : String( s );

			$expiryText.text(
				__( 'Code expires in', 'nxt-cloud-chat' ) + ' ' + m + 'm ' + sPadded + 's'
			);

			if ( left <= 0 ) {
				stopExpiryCountdown();
			}
		}

		expiryTimer = window.setInterval( render, 1000 );
		render();
	}

	function stopExpiryCountdown() {
		if ( expiryTimer ) {
			window.clearInterval( expiryTimer );
			expiryTimer = null;
		}
	}

	function startResendCooldown( sec ) {
		stopResendCooldown();

		resendLeft = Math.max( 1, parseInt( sec || resendCooldown, 10 ) );
		$btnResend.prop( 'disabled', true );

		function tick() {
			resendLeft--;
			if ( resendLeft <= 0 ) {
				stopResendCooldown();
			}
		}

		resendTimer = window.setInterval( tick, 1000 );
	}

	function stopResendCooldown() {
		if ( resendTimer ) {
			window.clearInterval( resendTimer );
			resendTimer = null;
		}

		$btnResend.prop( 'disabled', false );
	}

	function resetVerifyButton() {
		$btnVerify
			.prop( 'disabled', false )
			.text( __( 'Verify & Continue', 'nxt-cloud-chat' ) );
	}

	function showOtpStep( e164, expiresIn ) {
		lastE164 = e164;

		showErrorPhone( '' );

		$stepPhone.attr( 'hidden', true );
		$stepOtp.removeAttr( 'hidden' );

		$otpTarget.text(
			__( 'Enter the code sent to', 'nxt-cloud-chat' ) + ' ' + maskE164( e164 )
		);

		const otp = buildOtpInputs( otpLen );

		startExpiryCountdown( expiresIn || 300 );
		startResendCooldown( resendCooldown );

		$btnVerify.off( 'click' ).on( 'click', function ( ev ) {
			ev.preventDefault();
			showErrorOtp( '' );

			const code = otp.getValue();
			if ( code.length !== otpLen || ! REST_BASE ) {
				return;
			}

			$btnVerify.prop( 'disabled', true ).text( __( 'Verifying...', 'nxt-cloud-chat' ) );

			fetch( REST_BASE + '/auth/verify-otp', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					session_id: sessionId,
					phone_e164: lastE164,
					code,
					redirect_to: getSafeRedirectTo(),
				} ),
			} )
				.then( ( r ) => r.json().then( ( j ) => ( { ok: r.ok, j } ) ) )
				.then( ( res ) => {
					const ok = res.ok;
					const j  = res.j;

					if ( ! ok ) {
						showErrorOtp(
							j && j.message ? j.message : __( 'Verification failed.', 'nxt-cloud-chat' )
						);
						resetVerifyButton();
						otp.focusFirst();
						return;
					}

					if ( j && j.next_action === 'redirect' && j.redirect_to ) {
						const safe = sanitizeRedirectUrl( j.redirect_to );
						if ( safe ) {
							window.location.assign( safe );
							return;
						}
					}

					if ( j && j.next_action === 'link_required' ) {
						showErrorOtp(
							__( 'Phone verified. Please link or create account.', 'nxt-cloud-chat' )
						);
						resetVerifyButton();
						return;
					}

					resetVerifyButton();
					window.location.reload();
				} )
				.catch( () => {
					showErrorOtp( __( 'Could not verify. Try again.', 'nxt-cloud-chat' ) );
					resetVerifyButton();
				} );
		} );

		$btnChange.off( 'click' ).on( 'click', function ( ev ) {
			ev.preventDefault();

			stopExpiryCountdown();
			stopResendCooldown();
			showErrorOtp( '' );

			$stepOtp.attr( 'hidden', true );
			$stepPhone.removeAttr( 'hidden' );

			$btnSend.prop( 'disabled', ! isPhoneValid() );
			$phone.focus();
		} );

		$btnResend.off( 'click' ).on( 'click', function ( ev ) {
			ev.preventDefault();

			if ( ! REST_BASE || ! lastE164 ) {
				return;
			}

			$btnResend.prop( 'disabled', true ).text( __( 'Resending...', 'nxt-cloud-chat' ) );

			fetch( REST_BASE + '/auth/resend-otp', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					session_id: sessionId,
					phone_e164: lastE164,
				} ),
			} )
				.then( ( r ) => r.json().then( ( j ) => ( { ok: r.ok, j } ) ) )
				.then( ( res ) => {
					const ok = res.ok;
					const j  = res.j;

					if ( ! ok ) {
						const retry =
							j && j.retry_after
								? parseInt( j.retry_after, 10 )
								: resendCooldown;

						startResendCooldown( retry );

						showErrorOtp(
							j && j.message
								? j.message
								: __( 'Please wait before resending.', 'nxt-cloud-chat' )
						);

						return;
					}

					startExpiryCountdown( j && j.expires_in ? j.expires_in : 300 );
					startResendCooldown( resendCooldown );
					showErrorOtp( '' );
				} )
				.catch( () => {
					startResendCooldown( resendCooldown );
					showErrorOtp( __( 'Could not resend. Try again later.', 'nxt-cloud-chat' ) );
				} )
				.finally( () => {
					$btnResend.text( __( 'Resend code', 'nxt-cloud-chat' ) );
				} );
		} );
	}

	// ---------------- Bindings (Step 1) ----------------.

	$country.on( 'focus mousedown touchstart keydown', function () {
		syncCountryOptionLabels( true );
	} );

	$country.on( 'change', function () {
		ensureOptionDialData();
		window.setTimeout( () => syncCountryOptionLabels( false ), 0 );
		setPlaceholderFromExample();
		renderSendEnabled();
		showErrorPhone( '' );
	} );

	$country.on( 'blur', function () {
		window.setTimeout( () => syncCountryOptionLabels( false ), 0 );
	} );

	$phone.on( 'input blur', function () {
		renderSendEnabled();
	} );

	$btnSend.on( 'click', function ( ev ) {
		ev.preventDefault();

		showErrorPhone( '' );

		if ( ! renderSendEnabled() ) {
			return;
		}

		setSendLoading( true );

		if ( ! REST_BASE ) {
			window.setTimeout( () => setSendLoading( false ), 500 );
			return;
		}

		const e164 = buildPhoneE164();

		fetch( REST_BASE + '/auth/request-otp', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				session_id: sessionId,
				phone_e164: e164,
			} ),
		} )
			.then( ( r ) =>
				r.json().then( ( j ) => ( { ok: r.ok, j, status: r.status } ) )
			)
			.then( ( res ) => {
				const ok     = res.ok;
				const j      = res.j;
				const status = res.status;

				if ( ! ok ) {
					if ( status === 409 || ( j && j.code === 'phone_in_use' ) ) {
						showErrorPhone(
							j && j.message
								? j.message
								: __( 'This number is already assigned to another account.', 'nxt-cloud-chat' )
						);
					} else if ( status === 403 || ( j && j.code === 'COUNTRY_NOT_ALLOWED' ) ) {
						showErrorPhone(
							j && j.message
								? j.message
								: __( 'This phone number’s country isn’t allowed for verification on this site.', 'nxt-cloud-chat' )
						);
					} else {
						showErrorPhone(
							j && j.message
								? j.message
								: __( 'Failed to send the code.', 'nxt-cloud-chat' )
						);
					}
					return;
				}

				showOtpStep( e164, j && j.expires_in ? j.expires_in : 300 );
			} )
			.catch( () => {
				showErrorPhone( __( 'Request failed. Try again later.', 'nxt-cloud-chat' ) );
			} )
			.finally( () => setSendLoading( false ) );
	} );

	// Init.
	$btnSend.prop( 'disabled', true );
	loadCountryJson();
	$( window ).on( 'resize.nxtcc-' + String( $widget.attr( 'id' ) || 'auth' ).replace( /[^A-Za-z0-9_-]/g, '' ), function () {
		syncCountryOptionLabels( false );
	} );
	}

	$( '.nxtcc-auth-widget' ).each( function () {
		nxtccInitAuthWidget( this );
	} );

	if (
		window.elementorFrontend &&
		window.elementorFrontend.hooks &&
		typeof window.elementorFrontend.hooks.addAction === 'function'
	) {
		window.elementorFrontend.hooks.addAction(
			'frontend/element_ready/global',
			function ( $scope ) {
				$scope.find( '.nxtcc-auth-widget' ).each( function () {
					nxtccInitAuthWidget( this );
				} );
			}
		);
	}
} );
