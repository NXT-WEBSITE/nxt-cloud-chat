/**
 * Admin dashboard script for NXT Cloud Chat.
 *
 * Fetches and renders the Connection card status on the plugin dashboard:
 * - Reads nonce and AJAX endpoint from the dashboard widget element.
 * - Requests overview data via admin-ajax.
 * - Builds list items using DOM APIs (no HTML strings, no jQuery append).
 *
 * @package NXTCC
 */

jQuery(
	function ( $ ) {
		'use strict';

		const $widget = $( '.nxtcc-dashboard-widget' );

		if ( ! $widget.length ) {
				return;
		}

		// Values provided by the dashboard PHP view.
		const nonce   = $widget.data( 'nonce' ) || '';
		const ajaxurl =
		$widget.data( 'ajax' ) || ( 'string' === typeof window.ajaxurl ? window.ajaxurl : '' );

		/**
		 * Map a status value to a badge CSS class.
		 *
		 * @param {boolean|string} ok Status value: true | false | 'warn' | other.
		 * @return {string} CSS class name.
		 */
		function badgeClass( ok ) {
			if ( ok === true ) {
				return 'nxtcc-badge-ok';
			}

			if ( ok === 'warn' ) {
				return 'nxtcc-badge-warn';
			}

			if ( ok === false ) {
				return 'nxtcc-badge-fail';
			}

			return 'nxtcc-badge-neutral';
		}

		/**
		 * Set the badge text and color in a card header.
		 *
		 * @param {jQuery} $container Card container element.
		 * @param {boolean|string} okOrText Status value or string label.
		 * @param {string=} explicitText Optional explicit label (reserved for future).
		 * @return {void}
		 */
		function setHeaderBadge( $container, okOrText, explicitText ) {
			const $badge = $container.find( '.nxtcc-card-head [data-role="badge"]' );

			// String mode: literal label only.
			if ( typeof okOrText === 'string' && typeof explicitText === 'undefined' ) {
				$badge
				.attr( 'class', 'nxtcc-badge nxtcc-badge-neutral' )
				.text( okOrText );
				return;
			}

			const ok    = okOrText;
			const label =
			ok === true ? 'OK' : ok === 'warn' ? 'Warn' : ok === false ? 'Fail' : '—';

			$badge.attr( 'class', 'nxtcc-badge ' + badgeClass( ok ) ).text( label );
		}

		/**
		 * Render the Connection card from the overview payload.
		 *
		 * @param {Object} connection Connection payload.
		 * @return {void}
		 */
		function renderConnection( connection ) {
				const $card = $( '#nxtcc-card-connection' );
				const ok    = connection && connection.ok;

				setHeaderBadge( $card, ok );

				const checks = ( connection && connection.checks ) || {};
				const lines  = [
					[ 'WABA Profile', Boolean( checks.waba_profile ) ],
					[ 'Templates List', Boolean( checks.templates_list ) ],
					[ 'Phone Number Profile', Boolean( checks.phone_number_profile ) ],
					[ 'Webhook', Boolean( checks.webhook ) ],
				];

				const listEl = $card.find( '[data-role="connection-details"]' ).get( 0 );

			if ( ! listEl ) {
				return;
			}

			// Clear existing list safely.
			listEl.textContent = '';

			lines.forEach(
				function ( item ) {
					const label = item[ 0 ];
					const isOk  = item[ 1 ];
					const icon  = isOk ? '✅' : '❌';

					const li     = document.createElement( 'li' );
					const strong = document.createElement( 'strong' );

					strong.textContent = label;

					li.appendChild( strong );
					li.appendChild( document.createTextNode( '  ' ) );
					li.appendChild( document.createTextNode( icon ) );

					listEl.appendChild( li );
				} 
			);
		}

		/**
		 * Fetch minimal dashboard overview data via AJAX.
		 *
		 * @return {jqXHR} jQuery promise.
		 */
		function fetchOverview() {
				return $.post(
					ajaxurl,
					{
						action: 'nxtcc_dashboard_fetch_overview',
						nonce,
					} 
				)
					.done(
						function ( resp ) {
							if ( resp && resp.success ) {
								const data = resp.data || {};
								renderConnection( data.connection || {} );
							}
						} 
					)
					.fail(
						function ( xhr ) {
							if ( window.console && window.console.error ) {
								window.console.error( 'Dashboard overview request failed:', xhr );
							}
						} 
					);
		}

		// Refresh button on the Connection card.
		$( document ).on(
			'click',
			'#nxtcc-connection-refresh',
			function ( e ) {
				e.preventDefault();
				e.stopPropagation();

				const $btn = $( this );
				$btn.addClass( 'is-rotating' );

				fetchOverview().always(
					function () {
						setTimeout(
							function () {
								$btn.removeClass( 'is-rotating' );
							},
							250 
						);
					} 
				);
			} 
		);

		// Initial load.
		fetchOverview();
	} 
);


