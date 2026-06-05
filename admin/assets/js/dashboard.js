/**
 * Admin dashboard script for NXT Cloud Chat.
 *
 * Fetches and renders the Connection card, including Meta health_status
 * diagnostics, using DOM APIs.
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

		const nonce   = $widget.data( 'nonce' ) || '';
		const ajaxurl =
			$widget.data( 'ajax' ) || ( 'string' === typeof window.ajaxurl ? window.ajaxurl : '' );

		const canSendLabels = {
			AVAILABLE: 'Available',
			LIMITED: 'Limited',
			BLOCKED: 'Blocked',
			UNKNOWN: 'Unknown',
		};

		/**
		 * Normalize status to the dashboard style set.
		 *
		 * @param {boolean|string} status Status value.
		 * @return {string} Normalized status.
		 */
		function normalizeStatus( status ) {
			if ( status === true ) {
				return 'ok';
			}

			if ( status === false ) {
				return 'fail';
			}

			status = String( status || 'unknown' ).toLowerCase();

			if ( [ 'ok', 'warn', 'fail', 'unknown' ].indexOf( status ) !== -1 ) {
				return status;
			}

			if ( 'available' === status ) {
				return 'ok';
			}

			if ( 'limited' === status ) {
				return 'warn';
			}

			if ( 'blocked' === status ) {
				return 'fail';
			}

			return 'unknown';
		}

		/**
		 * Map can_send_message to the dashboard style set.
		 *
		 * @param {string} value Meta can_send_message value.
		 * @return {string} Dashboard status.
		 */
		function statusFromCanSend( value ) {
			value = String( value || 'UNKNOWN' ).toUpperCase();

			if ( 'AVAILABLE' === value ) {
				return 'ok';
			}

			if ( 'LIMITED' === value ) {
				return 'warn';
			}

			if ( 'BLOCKED' === value ) {
				return 'fail';
			}

			return 'unknown';
		}

		/**
		 * Map status to a badge CSS class.
		 *
		 * @param {boolean|string} status Status value.
		 * @return {string} CSS class name.
		 */
		function badgeClass( status ) {
			status = normalizeStatus( status );

			if ( 'ok' === status ) {
				return 'nxtcc-badge-ok';
			}

			if ( 'warn' === status ) {
				return 'nxtcc-badge-warn';
			}

			if ( 'fail' === status ) {
				return 'nxtcc-badge-fail';
			}

			return 'nxtcc-badge-neutral';
		}

		/**
		 * Return a readable label for a dashboard status.
		 *
		 * @param {string} status Status.
		 * @return {string} Label.
		 */
		function statusLabel( status ) {
			status = normalizeStatus( status );

			if ( 'ok' === status ) {
				return 'OK';
			}

			if ( 'warn' === status ) {
				return 'Warn';
			}

			if ( 'fail' === status ) {
				return 'Fail';
			}

			return 'Unknown';
		}

		/**
		 * Return a readable can_send_message label.
		 *
		 * @param {string} value Meta can_send_message value.
		 * @return {string} Label.
		 */
		function canSendLabel( value ) {
			value = String( value || 'UNKNOWN' ).toUpperCase();
			return canSendLabels[ value ] || value.replace( /_/g, ' ' );
		}

		/**
		 * Clear a DOM element.
		 *
		 * @param {HTMLElement} el Target element.
		 * @return {void}
		 */
		function clearEl( el ) {
			if ( el ) {
				el.textContent = '';
			}
		}

		/**
		 * Create an element.
		 *
		 * @param {string} tag Tag name.
		 * @param {string=} className Class name.
		 * @param {string=} text Text content.
		 * @return {HTMLElement} Element.
		 */
		function el( tag, className, text ) {
			const node = document.createElement( tag );

			if ( className ) {
				node.className = className;
			}

			if ( typeof text !== 'undefined' ) {
				node.textContent = text;
			}

			return node;
		}

		/**
		 * Create a status pill.
		 *
		 * @param {string} status Status.
		 * @param {string=} label Label.
		 * @return {HTMLElement} Pill.
		 */
		function statusPill( status, label ) {
			return el(
				'span',
				'nxtcc-status-pill is-' + normalizeStatus( status ),
				label || statusLabel( status )
			);
		}

		/**
		 * Create a compact status row.
		 *
		 * @param {string} label Row label.
		 * @param {string} status Row status.
		 * @param {string=} message Row message.
		 * @return {HTMLElement} Row.
		 */
		function statusRow( label, status, message ) {
			const row    = el( 'div', 'nxtcc-status-row' );
			const left   = el( 'div', 'nxtcc-status-row-main' );
			const title  = el( 'span', 'nxtcc-status-row-title', label );
			const detail = el( 'span', 'nxtcc-status-row-message', message || '' );

			left.appendChild( title );
			row.appendChild( left );
			row.appendChild( statusPill( status ) );

			if ( message ) {
				row.appendChild( detail );
			}

			return row;
		}

		/**
		 * Render a key/value row.
		 *
		 * @param {string} key Label.
		 * @param {string} value Value.
		 * @return {HTMLElement} Row.
		 */
		function keyValueRow( key, value ) {
			const row = el( 'div', 'nxtcc-health-kv' );
			row.appendChild( el( 'span', 'nxtcc-health-kv-key', key ) );
			row.appendChild( el( 'span', 'nxtcc-health-kv-value', value ) );
			return row;
		}

		/**
		 * Format a detail value.
		 *
		 * @param {*} value Detail value.
		 * @return {string} Formatted value.
		 */
		function formatValue( value ) {
			if ( value === null || typeof value === 'undefined' ) {
				return '';
			}

			if ( 'object' === typeof value ) {
				try {
					return JSON.stringify( value );
				} catch ( err ) {
					return String( value );
				}
			}

			return String( value );
		}

		/**
		 * Append a list section when values exist.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {string} title Section title.
		 * @param {Array} values List values.
		 * @return {boolean} Whether content was appended.
		 */
		function appendListSection( target, title, values ) {
			if ( ! Array.isArray( values ) || ! values.length ) {
				return false;
			}

			const section = el( 'div', 'nxtcc-health-detail-section' );
			const list    = el( 'ul', 'nxtcc-health-detail-list' );

			section.appendChild( el( 'h4', '', title ) );

			values.forEach(
				function ( value ) {
					list.appendChild( el( 'li', '', formatValue( value ) ) );
				}
			);

			section.appendChild( list );
			target.appendChild( section );
			return true;
		}

		/**
		 * Append error cards when values exist.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Array} errors Error list.
		 * @return {boolean} Whether content was appended.
		 */
		function appendErrors( target, errors ) {
			if ( ! Array.isArray( errors ) || ! errors.length ) {
				return false;
			}

			const section = el( 'div', 'nxtcc-health-detail-section' );
			section.appendChild( el( 'h4', '', 'Errors' ) );

			errors.forEach(
				function ( item ) {
					const card = el( 'div', 'nxtcc-health-error' );

					if ( item.error_code ) {
						card.appendChild( keyValueRow( 'Code', String( item.error_code ) ) );
					}

					if ( item.error_description ) {
						card.appendChild( keyValueRow( 'Reason', String( item.error_description ) ) );
					}

					if ( item.possible_solution ) {
						card.appendChild( keyValueRow( 'Possible Solution', String( item.possible_solution ) ) );
					}

					appendDetailsMap( card, item.details || {}, 'Other Error Details' );
					section.appendChild( card );
				}
			);

			target.appendChild( section );
			return true;
		}

		/**
		 * Append a details map when values exist.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Object} details Detail map.
		 * @param {string} title Section title.
		 * @return {boolean} Whether content was appended.
		 */
		function appendDetailsMap( target, details, title ) {
			if ( ! details || 'object' !== typeof details || ! Object.keys( details ).length ) {
				return false;
			}

			const section = el( 'div', 'nxtcc-health-detail-section' );
			section.appendChild( el( 'h4', '', title || 'Other Details' ) );

			Object.keys( details ).forEach(
				function ( key ) {
					section.appendChild( keyValueRow( key, formatValue( details[ key ] ) ) );
				}
			);

			target.appendChild( section );
			return true;
		}

		/**
		 * Append entity/root detail sections.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Object} item Health item.
		 * @return {void}
		 */
		function appendHealthDetails( target, item ) {
			let hasContent = false;

			hasContent = appendListSection( target, 'Additional Info', item.additional_info || [] ) || hasContent;
			hasContent = appendErrors( target, item.errors || [] ) || hasContent;
			hasContent = appendDetailsMap( target, item.details || {}, 'Other Details' ) || hasContent;

			if ( ! hasContent ) {
				target.appendChild( el( 'p', 'nxtcc-health-empty', 'No issues reported.' ) );
			}
		}

		/**
		 * Aggregate connection basics into one card status.
		 *
		 * @param {Array} basics Basic rows.
		 * @param {boolean|string} fallback Fallback status.
		 * @return {string} Aggregate status.
		 */
		function aggregateBasicsStatus( basics, fallback ) {
			let hasWarn = false;

			if ( ! Array.isArray( basics ) || ! basics.length ) {
				return normalizeStatus( fallback );
			}

			for ( const item of basics ) {
				const status = normalizeStatus( item && item.status );

				if ( 'fail' === status ) {
					return 'fail';
				}

				if ( 'warn' === status || 'unknown' === status ) {
					hasWarn = true;
				}
			}

			return hasWarn ? 'warn' : 'ok';
		}

		/**
		 * Render the Connection card header badge.
		 *
		 * @param {jQuery} $container Card container.
		 * @param {Object} connection Connection payload.
		 * @return {void}
		 */
		function renderConnectionBadge( $container, connection ) {
			const $badge = $container.find( '[data-role="connection-badge"]' );
			const status = aggregateBasicsStatus(
				connection && connection.basics ? connection.basics : [],
				connection && connection.ok
			);

			$badge.attr( 'class', 'nxtcc-badge ' + badgeClass( status ) ).text( statusLabel( status ) );
		}

		/**
		 * Render the Health card header badge.
		 *
		 * @param {jQuery} $container Card container.
		 * @param {Object} health Health payload.
		 * @return {void}
		 */
		function renderHealthBadge( $container, health ) {
			const $badge  = $container.find( '[data-role="health-badge"]' );
			const canSend = health && health.can_send_message ? health.can_send_message : 'UNKNOWN';
			let status    = health && health.status ? health.status : statusFromCanSend( canSend );
			let label     = statusLabel( status );

			if ( 'UNKNOWN' !== String( canSend ).toUpperCase() ) {
				status = statusFromCanSend( canSend );
				label  = canSendLabel( canSend );
			}

			$badge.attr( 'class', 'nxtcc-badge ' + badgeClass( status ) ).text( label );
		}

		/**
		 * Render local connection basics.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Array} basics Basic rows.
		 * @return {void}
		 */
		function renderBasics( target, basics ) {
			clearEl( target );

			if ( ! Array.isArray( basics ) || ! basics.length ) {
				target.appendChild( el( 'p', 'nxtcc-health-empty', 'No connection details available.' ) );
				return;
			}

			basics.forEach(
				function ( item ) {
					target.appendChild(
						statusRow(
							String( item.label || '' ),
							item.status || 'unknown',
							item.message || ''
						)
					);
				}
			);
		}

		/**
		 * Render the overall health summary.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Object} health Health payload.
		 * @return {void}
		 */
		function renderHealthSummary( target, health ) {
			clearEl( target );

			const canSend = health.can_send_message || 'UNKNOWN';
			const status  = health.status || statusFromCanSend( canSend );

			target.appendChild( statusRow( 'Overall', status, canSendLabel( canSend ) ) );

			if ( health.error && health.error.message ) {
				target.appendChild( keyValueRow( 'Reason', String( health.error.message ) ) );
			}

			if (
				( Array.isArray( health.additional_info ) && health.additional_info.length ) ||
				( Array.isArray( health.errors ) && health.errors.length ) ||
				( health.details && Object.keys( health.details ).length )
			) {
				const rootDetails = el( 'details', 'nxtcc-health-entity nxtcc-health-overall-details' );
				const summary     = el( 'summary', 'nxtcc-health-entity-summary' );
				const body        = el( 'div', 'nxtcc-health-entity-body' );

				summary.appendChild( el( 'span', 'nxtcc-health-entity-title', 'Overall Details' ) );
				summary.appendChild( statusPill( status ) );
				rootDetails.appendChild( summary );

				appendHealthDetails( body, health );
				rootDetails.appendChild( body );
				target.appendChild( rootDetails );
			}
		}

		/**
		 * Render entity-level health details.
		 *
		 * @param {HTMLElement} target Target.
		 * @param {Array} entities Entity list.
		 * @return {void}
		 */
		function renderHealthEntities( target, entities ) {
			clearEl( target );

			if ( ! Array.isArray( entities ) || ! entities.length ) {
				target.appendChild( el( 'p', 'nxtcc-health-empty', 'No entity status returned by Meta.' ) );
				return;
			}

			entities.forEach(
				function ( entity ) {
					const status  = entity.status || statusFromCanSend( entity.can_send_message );
					const details = el( 'details', 'nxtcc-health-entity is-' + normalizeStatus( status ) );
					const summary = el( 'summary', 'nxtcc-health-entity-summary' );
					const body    = el( 'div', 'nxtcc-health-entity-body' );

					summary.appendChild( el( 'span', 'nxtcc-health-entity-title', entity.entity_type || 'Entity' ) );
					summary.appendChild( statusPill( status, canSendLabel( entity.can_send_message ) ) );
					details.appendChild( summary );

					if ( entity.entity_type_raw ) {
						body.appendChild( keyValueRow( 'Entity Type', String( entity.entity_type_raw ) ) );
					}

					body.appendChild( keyValueRow( 'Can Send Message', canSendLabel( entity.can_send_message ) ) );
					appendHealthDetails( body, entity );

					details.appendChild( body );
					target.appendChild( details );
				}
			);
		}

		/**
		 * Render health status.
		 *
		 * @param {jQuery} $card Connection card.
		 * @param {Object} health Health payload.
		 * @return {void}
		 */
		function renderHealth( $card, health ) {
			const summaryEl = $card.find( '[data-role="health-summary"]' ).get( 0 );
			const entityEl  = $card.find( '[data-role="health-entities"]' ).get( 0 );
			const checkedEl = $card.find( '[data-role="health-checked-at"]' ).get( 0 );

			health = health || {};
			renderHealthBadge( $card, health );

			if ( checkedEl ) {
				checkedEl.textContent = health.checked_at_local ? 'Checked ' + health.checked_at_local : '';
			}

			if ( summaryEl ) {
				renderHealthSummary( summaryEl, health );
			}

			if ( entityEl ) {
				renderHealthEntities( entityEl, health.entities || [] );
			}
		}

		/**
		 * Render the Connection card from the overview payload.
		 *
		 * @param {Object} connection Connection payload.
		 * @return {void}
		 */
		function renderConnection( connection ) {
			const $connectionCard = $( '#nxtcc-card-connection' );
			const $healthCard     = $( '#nxtcc-card-health' );
			const basicsEl        = $connectionCard.find( '[data-role="connection-basics"]' ).get( 0 );

			connection = connection || {};

			renderConnectionBadge( $connectionCard, connection );

			if ( basicsEl ) {
				renderBasics( basicsEl, connection.basics || [] );
			}

			renderHealth( $healthCard, connection.health || {} );
		}

		/**
		 * Fetch minimal dashboard overview data via AJAX.
		 *
		 * @param {boolean=} forceRefresh Whether to bypass cached health.
		 * @return {jqXHR} jQuery promise.
		 */
		function fetchOverview( forceRefresh ) {
			return $.post(
				ajaxurl,
				{
					action: 'nxtcc_dashboard_fetch_overview',
					nonce,
					force_refresh: forceRefresh ? '1' : '0',
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

		$( document ).on(
			'click',
			'#nxtcc-connection-refresh, #nxtcc-health-refresh',
			function ( e ) {
				e.preventDefault();
				e.stopPropagation();

				const $btn = $( this );
				$btn.addClass( 'is-rotating' );

				fetchOverview( this.id === 'nxtcc-health-refresh' ).always(
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

		fetchOverview( false );
	}
);
