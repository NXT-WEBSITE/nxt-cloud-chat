/* global NXTCC_COD_VERIFICATION, jQuery */
( function ( window, document, $ ) {
	'use strict';

	const config = window.NXTCC_COD_VERIFICATION || {};
	const storageKey = 'nxtcc_cod_checkout_fields';

	if ( ! config.loginUrl ) {
		return;
	}

	/**
	 * Persist non-sensitive classic checkout fields for the verification round trip.
	 *
	 * @return {void}
	 */
	function persistClassicCheckoutFields() {
		const values = {};

		$( 'form.checkout' )
			.find( 'input, select, textarea' )
			.each( function () {
				const field = this;
				const name = String( field.name || '' );
				const type = String( field.type || '' ).toLowerCase();
				const supportedName =
					/^(billing_|shipping_|order_comments$|ship_to_different_address$)/.test(
						name
					);
				const uncheckedChoice =
					( 'checkbox' === type || 'radio' === type ) && ! field.checked;

				if (
					! supportedName ||
					'password' === type ||
					'hidden' === type ||
					uncheckedChoice
				) {
					return;
				}

				values[ name ] = String( $( field ).val() || '' );
			} );

		try {
			window.sessionStorage.setItem( storageKey, JSON.stringify( values ) );
		} catch ( error ) {
			// Checkout still remains protected when browser storage is unavailable.
		}
	}

	/**
	 * Restore classic checkout fields after verification.
	 *
	 * @return {void}
	 */
	function restoreClassicCheckoutFields() {
		const url = new URL( window.location.href );
		if ( '1' !== url.searchParams.get( 'nxtcc_cod_return' ) ) {
			return;
		}

		let values = {};

		try {
			const storedValues = JSON.parse(
				window.sessionStorage.getItem( storageKey ) || '{}'
			);

			if (
				storedValues &&
				'object' === typeof storedValues &&
				! Array.isArray( storedValues )
			) {
				values = storedValues;
			}

			window.sessionStorage.removeItem( storageKey );
		} catch ( error ) {
			values = {};
		}

		Object.keys( values ).forEach( function ( name ) {
			const $fields = $( document.getElementsByName( name ) ).filter(
				function () {
					return 0 < $( this ).closest( 'form.checkout' ).length;
				}
			);

			$fields.each( function () {
				const type = String( this.type || '' ).toLowerCase();
				if ( 'checkbox' === type || 'radio' === type ) {
					this.checked = String( $( this ).val() || '' ) === values[ name ];
					return;
				}

				$( this ).val( values[ name ] );
			} );
		} );

		if ( Object.keys( values ).length ) {
			$( document.body ).trigger( 'update_checkout' );
		}

		url.searchParams.delete( 'nxtcc_cod_return' );
		window.history.replaceState( {}, document.title, url.toString() );
	}

	/**
	 * Redirect the shopper to the existing Force Migration login page.
	 *
	 * @return {void}
	 */
	function redirectForVerification() {
		persistClassicCheckoutFields();
		window.location.assign( config.loginUrl );
	}

	/**
	 * Bind the classic checkout gateway event.
	 *
	 * @return {void}
	 */
	function bindClassicCheckout() {
		$( 'form.checkout' )
			.off( 'checkout_place_order_cod.nxtccCodVerification' )
			.on( 'checkout_place_order_cod.nxtccCodVerification', function () {
				redirectForVerification();
				return false;
			} );
	}

	/**
	 * Get the selected Checkout Block payment method.
	 *
	 * @return {string} Payment method ID.
	 */
	function getBlockPaymentMethod() {
		const selected = document.querySelector(
			'input[name="radio-control-wc-payment-method-options"]:checked,' +
			'input[name*="payment-method"]:checked,' +
			'input[type="radio"][value="cod"]:checked'
		);

		return selected ? String( selected.value || '' ) : '';
	}

	/**
	 * Intercept the Checkout Block place-order button before its request starts.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	function handleBlockPlaceOrder( event ) {
		const target = event.target;
		const button = target && target.closest
			? target.closest(
				'.wc-block-components-checkout-place-order-button,' +
				'.wc-block-checkout__actions_row button'
			)
			: null;

		if ( ! button || 'cod' !== getBlockPaymentMethod() ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();
		redirectForVerification();
	}

	$( function () {
		restoreClassicCheckoutFields();
		bindClassicCheckout();
	} );

	$( document.body ).on( 'updated_checkout', bindClassicCheckout );
	document.addEventListener( 'click', handleBlockPlaceOrder, true );
}( window, document, jQuery ) );
