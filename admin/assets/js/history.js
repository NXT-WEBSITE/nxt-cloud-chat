/**
 * History screen JS (Admin).
 *
 * Handles: fetch + infinite scroll, deep-link filters, row modal, bulk actions.
 *
 * @package NXTCC
 */

jQuery(
	function ( $ ) {
		'use strict';

		const cfg = window.NXTCC_History || {};

		// ---- Harden ajaxurl + nonce (type guard + same-origin enforcement). ----
		const ajaxurl =
		'string' === typeof cfg.ajaxurl && cfg.ajaxurl
		? cfg.ajaxurl
		: ( 'string' === typeof window.ajaxurl ? window.ajaxurl : '' );
		const nonce   = 'string' === typeof cfg.nonce ? cfg.nonce : '';

		let sameOrigin = true;

		try {
				const origin = 'string' === typeof document.location.origin ? document.location.origin : '';
				const urlObj = new URL( ajaxurl, origin );
				sameOrigin   = '' !== origin && urlObj.origin === origin;
		} catch ( e ) {
			sameOrigin = false;
		}

		if ( ! sameOrigin ) {
			// eslint-disable-next-line no-console.
			console.warn( '[NXTCC] Blocked cross-origin ajaxurl:', ajaxurl );
		}

		const PAGE_LIMIT = parseInt( cfg.limit || 30, 10 );

		const $tbody   = $( '#nxtcc-history-tbody' );
		const $loading = $( '#nxtcc-history-loading-row' );
		const $end     = $( '#nxtcc-history-end-row' );

		const $search = $( '#nxtcc-history-search' );
		const $status = $( '#nxtcc-history-status' );
		const $btnRef = $( '#nxtcc-history-refresh' );

		const $bulkSelAll = $( '#nxtcc-history-select-all' );
		const $bulkSel    = $( '#nxtcc-history-bulk-action' );
		const $bulkApply  = $( '#nxtcc-history-apply' );

		// Modal.
		const $modal        = $( '#nxtcc-history-modal' );
		const $modalClose1  = $( '#nxtcc-history-modal-close' );
		const $modalClose2  = $( '#nxtcc-history-modal-close-2' );
		const $modalOverlay = $modal.find( '.nxtcc-modal-overlay' );

		const COLSPAN = 12;

		// State.
		let page      = 1;
		let done      = false;
		let isLoading = false;

		/**
		 * Normalize any value to string.
		 *
		 * @param {*} v Value.
		 * @return {string} String.
		 */
		function toStr( v ) {
				return null == v ? '' : String( v );
		}

		// Deep link support (server-sanitized via PHP and passed as cfg.deeplink).
		const deepLinkFilters = Object.assign(
			{
				status_any: '',
				status: '',
				range: '7d',
				from: '',
				to: '',
				phone_number_id: '',
				sort: 'newest',
				search: '',
			},
			cfg.deeplink || {}
		);

		if ( deepLinkFilters.search ) {
			$search.val( deepLinkFilters.search );
		}

		if (
		deepLinkFilters.status &&
		$(
			'#nxtcc-history-status option[value="' +
			deepLinkFilters.status +
			'"]'
		).length
		) {
				$status.val( deepLinkFilters.status );
		} else if (
		deepLinkFilters.status_any &&
		-1 === deepLinkFilters.status_any.indexOf( ',' )
		) {
			if (
				$(
					'#nxtcc-history-status option[value="' +
					deepLinkFilters.status_any +
					'"]'
				).length
			) {
				$status.val( deepLinkFilters.status_any );
			}
		}

		/**
		 * Build a status badge element (DOM node, not HTML string).
		 *
		 * @param {string} status Status.
		 * @return {HTMLElement} Badge element.
		 */
		function statusBadge( status ) {
			const st = ( status || '' ).toLowerCase();
			let cls  = 'is-unknown';

			if ( 'sent' === st ) {
				cls = 'is-sent';
			} else if ( 'delivered' === st ) {
				cls = 'is-delivered';
			} else if ( 'read' === st ) {
				cls = 'is-read';
			} else if ( 'failed' === st ) {
				cls = 'is-failed';
			} else if ( 'sending' === st ) {
				cls = 'is-sending';
			} else if ( 'pending' === st ) {
				cls = 'is-pending';
			} else if ( 'scheduled' === st ) {
				cls = 'is-scheduled';
			} else if ( 'received' === st ) {
				cls = 'is-received';
			}

			const span       = document.createElement( 'span' );
			span.className   = 'nxtcc-status-badge ' + cls;
			span.textContent = status || '—';

			return span;
		}

		/**
		 * Get current filters (deep link + UI).
		 *
		 * @return {Object} Filters.
		 */
		function currentFilters() {
				const base = {
					search: ( $search.val() || '' ).trim(),
					status: ( $status.val() || '' ).trim(),
			};

				const merged = {
					status_any: deepLinkFilters.status_any || '',
					range: deepLinkFilters.range || '7d',
					from: deepLinkFilters.from || '',
					to: deepLinkFilters.to || '',
					phone_number_id: deepLinkFilters.phone_number_id || '',
					sort: deepLinkFilters.sort || 'newest',
			};

			if ( base.status ) {
				const sel         = base.status.toLowerCase();
				merged.status_any = 'pending' === sel ? 'pending,queued' : sel; // Alias queued.
			}

			merged.search = base.search || deepLinkFilters.search || '';

			return merged;
		}

		/**
		 * Build a single <tr> row as DOM node (no HTML string concatenation).
		 *
		 * @param {Object} r Row data.
		 * @return {HTMLElement} <tr>.
		 */
		function buildRow( r ) {
				const id     = null != r.id ? r.id : '';
				const source = r.source || 'history';

				const tr = document.createElement( 'tr' );
				tr.setAttribute( 'data-id', toStr( id ) );
				tr.setAttribute( 'data-source', toStr( source ) );

			function tdText( value ) {
				const td       = document.createElement( 'td' );
				td.textContent = value ? toStr( value ) : '—';
				return td;
			}

			// Checkbox cell.
			{
				const td        = document.createElement( 'td' );
				const input     = document.createElement( 'input' );
				input.type      = 'checkbox';
				input.className = 'nxtcc-row-check';
				input.setAttribute( 'data-id', toStr( id ) );
				input.setAttribute( 'data-source', toStr( source ) );
				td.appendChild( input );
				tr.appendChild( td );
			}

				tr.appendChild( tdText( r.contact_name ) );
				tr.appendChild( tdText( r.contact_number ) );
				tr.appendChild( tdText( r.template_name ) );
				tr.appendChild( tdText( r.message ) );

				// Status badge cell.
				{
					const td = document.createElement( 'td' );
					td.appendChild( statusBadge( r.status ) );
					tr.appendChild( td );
			}

				tr.appendChild( tdText( r.sent_at ) );
				tr.appendChild( tdText( r.delivered_at ) );
				tr.appendChild( tdText( r.read_at ) );
				tr.appendChild( tdText( r.scheduled_at ) );
				tr.appendChild( tdText( r.created_at ) );
				tr.appendChild( tdText( r.created_by ) );

				return tr;
		}

		/**
		 * Append a simple message row (centered) to tbody.
		 *
		 * @param {string} message Message.
		 * @param {string} color   Optional color.
		 */
		function appendMessageRow( message, color ) {
				const tr = document.createElement( 'tr' );
				const td = document.createElement( 'td' );

				td.setAttribute( 'colspan', String( COLSPAN ) );
				td.style.textAlign = 'center';
				td.textContent     = message;

			if ( color ) {
				td.style.color = color;
			}

			tr.appendChild( td );
			$tbody.get( 0 ).appendChild( tr );
		}

		/**
		 * Toggle loading row.
		 *
		 * @param {boolean} show Whether to show.
		 */
		function toggleLoading( show ) {
				$loading.toggle( ! ! show );
		}

		/**
		 * Reset table state.
		 */
		function resetTable() {
				page = 1;
				done = false;
				$tbody.empty();
				$end.hide();
				$bulkSelAll.prop( 'checked', false );
		}

		/**
		 * Safe POST helper.
		 *
		 * @param {Object} data Data.
		 * @return {jqXHR|Promise} Promise.
		 */
		function safePost( data ) {
			if ( ! sameOrigin || ! ajaxurl ) {
				return $.Deferred().reject().promise();
			}

			return $.post( ajaxurl, data ).fail(
				function () {
					if ( 0 === $tbody.children().length ) {
						appendMessageRow( 'Error loading data.', '#b00' );
					}
				} 
			);
		}

		/**
		 * Fetch next page.
		 */
		function fetchPage() {
			if ( isLoading || done ) {
				return;
			}

			if ( ! Number.isFinite( page ) || page <= 0 ) {
				page = 1;
			}

			isLoading = true;
			toggleLoading( true );

			const filters = currentFilters();

			safePost(
				{
					action: 'nxtcc_history_fetch',
					nonce,
					page,
					limit: PAGE_LIMIT,
					filters,
					} 
			)
					.done(
						function ( resp ) {
							if ( ! resp || ! resp.success ) {
								return;
							}

							const rows = resp.data && resp.data.rows ? resp.data.rows : [];

							if ( ! rows.length ) {
								if ( 1 === page ) {
									appendMessageRow( 'No records found.', '#777' );
								} else {
									$end.show();
								}

								done = true;
								return;
							}

							const frag = document.createDocumentFragment();

							rows.forEach(
								function ( row ) {
									frag.appendChild( buildRow( row ) );
								} 
							);

							$tbody.get( 0 ).appendChild( frag );

							if ( rows.length < PAGE_LIMIT ) {
								$end.show();
								done = true;
							} else {
								page = ( page + 1 ) | 0;
							}
						} 
					)
					.always(
						function () {
							isLoading = false;
							toggleLoading( false );
						} 
					);
		}

		// Refresh, search, status change.
		$btnRef.on(
			'click',
			function () {
				resetTable();
				fetchPage();
			} 
		);

		$search.on(
			'keydown',
			function ( e ) {
				if ( 'Enter' === e.key ) {
						e.preventDefault();
						$btnRef.trigger( 'click' );
				}
			} 
		);

		$status.on(
			'change',
			function () {
				$btnRef.trigger( 'click' );
			} 
		);

		// Throttled infinite scroll on table wrap.
		const $wrap       = $( '.nxtcc-history-table-wrap' );
		let scrollTicking = false;

		$wrap.on(
			'scroll',
			function () {
				if ( scrollTicking ) {
						return;
				}

				scrollTicking = true;

				window.requestAnimationFrame(
					function () {
						const el = $wrap.get( 0 );

						if (
						el &&
						el.scrollTop + el.clientHeight >= el.scrollHeight - 30
						) {
								fetchPage();
						}

						scrollTicking = false;
					} 
				);
			} 
		);

		// Modal open.
		$( document ).on(
			'click',
			'.nxtcc-history-table tbody tr',
			function ( e ) {
				if ( $( e.target ).is( 'input[type="checkbox"]' ) ) {
						return;
				}

				const id     = $( this ).data( 'id' );
				const source = $( this ).data( 'source' ) || 'history';

				safePost(
					{
						action: 'nxtcc_history_fetch_one',
						nonce,
						id,
						source,
					} 
				).done(
					function ( resp ) {
						if ( ! resp || ! resp.success ) {
							return;
						}

						const r = resp.data && resp.data.row ? resp.data.row : {};

						$( '#kv-contact' ).text( r.contact_name || '—' );
						$( '#kv-number' ).text( r.contact_number || '—' );
						$( '#kv-template' ).text( r.template_name || '—' );
						$( '#kv-status' ).text( r.status || '—' );
						$( '#kv-meta-id' ).text( r.meta_id || '—' );
						$( '#kv-sent' ).text( r.sent_at || '—' );
						$( '#kv-delivered' ).text( r.delivered_at || '—' );
						$( '#kv-read' ).text( r.read_at || '—' );
						$( '#kv-created-at' ).text( r.created_at || '—' );
						$( '#kv-created-by' ).text( r.created_by || '—' );
						$( '#kv-message' ).text( r.message || '' );
						$( '#kv-media-wrap' ).hide();

						$modal.attr( 'aria-hidden', 'false' ).show();
					} 
				);
			} 
		);

		/**
		 * Close modal.
		 */
		function closeModal() {
			$modal.attr( 'aria-hidden', 'true' ).hide();
		}

		$modalClose1.on( 'click', closeModal );
		$modalClose2.on( 'click', closeModal );
		$modalOverlay.on( 'click', closeModal );

		$( document ).on(
			'keydown',
			function ( e ) {
				if ( 'Escape' === e.key ) {
					closeModal();
				}
			} 
		);

		// Bulk select all.
		$bulkSelAll.on(
			'change',
			function () {
				const checked = $( this ).is( ':checked' );
				$( '.nxtcc-row-check' ).prop( 'checked', checked );
			} 
		);

		// Bulk Apply (disable button during action to avoid double-submit).
		$bulkApply.on(
			'click',
			function () {
				const action = $bulkSel.val();

				if ( ! action ) {
						return;
				}

				const ids     = [];
				const sources = new Set();

				$( '.nxtcc-row-check:checked' ).each(
					function () {
						const rowId = parseInt( $( this ).data( 'id' ), 10 );

						if ( ! Number.isNaN( rowId ) ) {
								ids.push( rowId );
								sources.add( $( this ).data( 'source' ) || 'history' );
						}
					} 
				);

				if ( ! ids.length ) {
						window.alert( 'Please select at least one row.' );
						return;
				}

				let sourceParam = 'history';

				if ( sources.has( 'queue' ) && sources.has( 'history' ) ) {
						sourceParam = 'both';
				} else if ( sources.has( 'queue' ) && ! sources.has( 'history' ) ) {
					sourceParam = 'queue';
				}

				if ( 'delete' === action ) {
					if (
					! window.confirm(
						'Delete selected rows? This cannot be undone.'
					)
					) {
						return;
					}

					$bulkApply.prop( 'disabled', true );

					safePost(
						{
							action: 'nxtcc_history_bulk_delete',
							nonce,
							source: sourceParam,
							ids,
						} 
					)
						.done(
							function ( resp ) {
								if ( resp && resp.success ) {
										$( '.nxtcc-row-check:checked' )
									.closest( 'tr' )
									.remove();
								} else {
									const msg =
										resp && resp.data && resp.data.message
									? resp.data.message
									: 'Delete failed.';
									window.alert( msg );
								}
							} 
						)
						.always(
							function () {
								$bulkApply.prop( 'disabled', false );
							} 
						);

					return;
				}

				if ( 'export' === action ) {
					const filters = currentFilters();

					const form  = document.createElement( 'form' );
					form.method = 'POST';
					form.action = ajaxurl;
					form.target = '_blank';

					function addHidden( name, value ) {
						const input = document.createElement( 'input' );
						input.type  = 'hidden';
						input.name  = name;
						input.value = toStr( value );
						form.appendChild( input );
					}

					addHidden( 'action', 'nxtcc_history_export' );
					addHidden( 'nonce', nonce );
					addHidden( 'source', sourceParam );

					addHidden( 'status_any', filters.status_any || '' );
					addHidden( 'status', filters.status || '' );
					addHidden( 'search', filters.search || '' );
					addHidden( 'range', filters.range || '' );
					addHidden( 'from', filters.from || '' );
					addHidden( 'to', filters.to || '' );
					addHidden( 'phone_number_id', filters.phone_number_id || '' );
					addHidden( 'sort', filters.sort || '' );

					document.body.appendChild( form );
					form.submit();
					document.body.removeChild( form );
				}
			} 
		);

		// Initial.
		resetTable();
		fetchPage();
	} 
);


