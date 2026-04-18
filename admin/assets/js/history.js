/**
 * History screen JS (Admin).
 *
 * Handles paged fetch, filters, modal details, and bulk actions.
 *
 * @package NXTCC
 */

jQuery(
	function ( $ ) {
		'use strict';

		var cfg = window.NXTCC_History || {};
		var ajaxurl = 'string' === typeof cfg.ajaxurl && cfg.ajaxurl
			? cfg.ajaxurl
			: ( 'string' === typeof window.ajaxurl ? window.ajaxurl : '' );
		var nonce = 'string' === typeof cfg.nonce ? cfg.nonce : '';
		var pageLimit = parseInt( cfg.limit || 30, 10 );
		var sameOrigin = true;
		var colspan = 12;
		var page = 1;
		var done = false;
		var isLoading = false;
		var scrollTicking = false;

		if ( ! pageLimit || pageLimit < 1 ) {
			pageLimit = 30;
		}

		try {
			var origin = 'string' === typeof document.location.origin ? document.location.origin : '';
			var urlObj = new URL( ajaxurl, origin );

			sameOrigin = '' !== origin && urlObj.origin === origin;
		} catch ( error ) {
			sameOrigin = false;
		}

		if ( ! sameOrigin ) {
			window.console.warn( '[NXTCC] Blocked cross-origin ajaxurl:', ajaxurl );
		}

		var $tbody = $( '#nxtcc-history-tbody' );
		var $loading = $( '#nxtcc-history-loading-row' );
		var $end = $( '#nxtcc-history-end-row' );
		var $search = $( '#nxtcc-history-search' );
		var $messageType = $( '#nxtcc-history-message-type' );
		var $status = $( '#nxtcc-history-status' );
		var $refresh = $( '#nxtcc-history-refresh' );
		var $bulkSelectAll = $( '#nxtcc-history-select-all' );
		var $bulkAction = $( '#nxtcc-history-bulk-action' );
		var $bulkApply = $( '#nxtcc-history-apply' );
		var $modal = $( '#nxtcc-history-modal' );
		var $modalClosePrimary = $( '#nxtcc-history-modal-close' );
		var $modalCloseSecondary = $( '#nxtcc-history-modal-close-2' );
		var $modalOverlay = $( '.nxtcc-history-modal-overlay' );
		var $tableWrap = $( '.nxtcc-history-table-wrap' );

		var deepLinkFilters = $.extend(
			{
				message_type: '',
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
			deepLinkFilters.message_type &&
			$(
				'#nxtcc-history-message-type option[value="' +
				deepLinkFilters.message_type +
				'"]'
			).length
		) {
			$messageType.val( deepLinkFilters.message_type );
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
			-1 === deepLinkFilters.status_any.indexOf( ',' ) &&
			$(
				'#nxtcc-history-status option[value="' +
				deepLinkFilters.status_any +
				'"]'
			).length
		) {
			$status.val( deepLinkFilters.status_any );
		}

		/**
		 * Normalize any value to a readable text string.
		 *
		 * @param {*} value Raw value.
		 * @return {string} Display string.
		 */
		function displayText( value ) {
			if ( null === value || 'undefined' === typeof value ) {
				return '-';
			}

			value = String( value );

			return '' === value ? '-' : value;
		}

		/**
		 * Build a status badge node.
		 *
		 * @param {string} status Status text.
		 * @return {HTMLElement} Badge element.
		 */
		function buildStatusBadge( status ) {
			var normalizedStatus = String( status || '' ).toLowerCase();
			var badgeClass = 'is-unknown';
			var badge = document.createElement( 'span' );

			if ( 'sent' === normalizedStatus ) {
				badgeClass = 'is-sent';
			} else if ( 'delivered' === normalizedStatus ) {
				badgeClass = 'is-delivered';
			} else if ( 'read' === normalizedStatus ) {
				badgeClass = 'is-read';
			} else if ( 'failed' === normalizedStatus ) {
				badgeClass = 'is-failed';
			} else if ( 'sending' === normalizedStatus ) {
				badgeClass = 'is-sending';
			} else if ( 'pending' === normalizedStatus ) {
				badgeClass = 'is-pending';
			} else if ( 'scheduled' === normalizedStatus ) {
				badgeClass = 'is-scheduled';
			} else if ( 'received' === normalizedStatus ) {
				badgeClass = 'is-received';
			}

			badge.className = 'nxtcc-status-badge ' + badgeClass;
			badge.textContent = displayText( status );

			return badge;
		}

		/**
		 * Build current UI filters plus deeplink-only values.
		 *
		 * @return {Object} Filter object.
		 */
		function currentFilters() {
			var selectedStatus = String( $status.val() || '' ).trim().toLowerCase();
			var statusAny = selectedStatus;

			if ( 'pending' === selectedStatus ) {
				statusAny = 'pending,queued';
			}

			if ( ! statusAny ) {
				statusAny = deepLinkFilters.status_any || '';
			}

			return {
				search: String( $search.val() || '' ).trim(),
				message_type: String( $messageType.val() || '' ).trim(),
				status: selectedStatus,
				status_any: statusAny,
				range: deepLinkFilters.range || '7d',
				from: deepLinkFilters.from || '',
				to: deepLinkFilters.to || '',
				phone_number_id: deepLinkFilters.phone_number_id || '',
				sort: deepLinkFilters.sort || 'newest',
			};
		}

		/**
		 * Create a plain text table cell.
		 *
		 * @param {*} value Cell value.
		 * @return {HTMLElement} Table cell.
		 */
		function buildTextCell( value ) {
			var cell = document.createElement( 'td' );

			cell.textContent = displayText( value );

			return cell;
		}

		/**
		 * Build a table row node.
		 *
		 * @param {Object} row Row payload.
		 * @return {HTMLElement} Table row.
		 */
		function buildRow( row ) {
			var id = null !== row.id && 'undefined' !== typeof row.id ? row.id : '';
			var source = row.source || 'history';
			var tr = document.createElement( 'tr' );
			var checkboxCell = document.createElement( 'td' );
			var checkbox = document.createElement( 'input' );
			var statusCell = document.createElement( 'td' );

			tr.setAttribute( 'data-id', String( id ) );
			tr.setAttribute( 'data-source', String( source ) );

			checkbox.type = 'checkbox';
			checkbox.className = 'nxtcc-row-check';
			checkbox.setAttribute( 'data-id', String( id ) );
			checkbox.setAttribute( 'data-source', String( source ) );
			checkboxCell.appendChild( checkbox );
			tr.appendChild( checkboxCell );

			tr.appendChild( buildTextCell( row.contact_name ) );
			tr.appendChild( buildTextCell( row.contact_number ) );
			tr.appendChild( buildTextCell( row.template_name ) );
			tr.appendChild( buildTextCell( row.message ) );

			statusCell.appendChild( buildStatusBadge( row.status ) );
			tr.appendChild( statusCell );

			tr.appendChild( buildTextCell( row.sent_at ) );
			tr.appendChild( buildTextCell( row.delivered_at ) );
			tr.appendChild( buildTextCell( row.read_at ) );
			tr.appendChild( buildTextCell( row.scheduled_at ) );
			tr.appendChild( buildTextCell( row.created_at ) );
			tr.appendChild( buildTextCell( row.created_by ) );

			return tr;
		}

		/**
		 * Append a centered state row to the table body.
		 *
		 * @param {string} message Message text.
		 */
		function appendMessageRow( message ) {
			var tr = document.createElement( 'tr' );
			var td = document.createElement( 'td' );

			td.setAttribute( 'colspan', String( colspan ) );
			td.textContent = message;
			tr.className = 'nxtcc-history-state-row';
			tr.appendChild( td );
			$tbody.get( 0 ).appendChild( tr );
		}

		/**
		 * Reset list state before a fresh fetch.
		 */
		function resetTable() {
			page = 1;
			done = false;
			isLoading = false;
			$tbody.empty();
			$loading.prop( 'hidden', true );
			$end.prop( 'hidden', true );
			$bulkSelectAll.prop( 'checked', false );
		}

		/**
		 * Post to AJAX endpoint safely.
		 *
		 * @param {Object} data Request payload.
		 * @return {jqXHR|Promise} AJAX promise.
		 */
		function safePost( data ) {
			if ( ! sameOrigin || ! ajaxurl ) {
				return $.Deferred().reject().promise();
			}

			return $.post( ajaxurl, data );
		}

		/**
		 * Fetch the next history page.
		 */
		function fetchPage() {
			var filters;

			if ( isLoading || done ) {
				return;
			}

			isLoading = true;
			$loading.prop( 'hidden', false );
			filters = currentFilters();

			safePost(
				{
					action: 'nxtcc_history_fetch',
					nonce: nonce,
					page: page,
					limit: pageLimit,
					filters: filters,
				}
			)
				.done(
					function ( response ) {
						var rows;
						var fragment;

						if ( ! response || ! response.success ) {
							if ( 1 === page && 0 === $tbody.children().length ) {
								appendMessageRow( 'Error loading data.' );
							}

							done = true;
							return;
						}

						rows = response.data && response.data.rows ? response.data.rows : [];

						if ( ! rows.length ) {
							if ( 1 === page ) {
								appendMessageRow( 'No records found.' );
							} else {
								$end.prop( 'hidden', false );
							}

							done = true;
							return;
						}

						fragment = document.createDocumentFragment();

						rows.forEach(
							function ( row ) {
								fragment.appendChild( buildRow( row ) );
							}
						);

						$tbody.get( 0 ).appendChild( fragment );

						if ( rows.length < pageLimit ) {
							done = true;
							$end.prop( 'hidden', false );
						} else {
							page += 1;
						}
					}
				)
				.fail(
					function () {
						if ( 1 === page && 0 === $tbody.children().length ) {
							appendMessageRow( 'Error loading data.' );
						}

						done = true;
					}
				)
				.always(
					function () {
						isLoading = false;
						$loading.prop( 'hidden', true );
					}
				);
		}

		/**
		 * Open the detail modal for one row.
		 *
		 * @param {number} id Row id.
		 * @param {string} source Row source.
		 */
		function openModal( id, source ) {
			safePost(
				{
					action: 'nxtcc_history_fetch_one',
					nonce: nonce,
					id: id,
					source: source,
				}
			).done(
				function ( response ) {
					var row;
					var hasBroadcast;

					if ( ! response || ! response.success ) {
						return;
					}

					row = response.data && response.data.row ? response.data.row : {};
					hasBroadcast = !! row.broadcast_id;

					$( '#kv-contact' ).text( displayText( row.contact_name ) );
					$( '#kv-number' ).text( displayText( row.contact_number ) );
					$( '#kv-template' ).text( displayText( row.template_name ) );
					$( '#kv-status' ).text( displayText( row.status ) );
					$( '#kv-meta-id' ).text( displayText( row.meta_id ) );
					$( '#kv-broadcast-id' ).text( displayText( row.broadcast_id ) );
					$( '#kv-last-error' ).text( displayText( row.last_error ) );
					$( '#kv-sent' ).text( displayText( row.sent_at ) );
					$( '#kv-delivered' ).text( displayText( row.delivered_at ) );
					$( '#kv-read' ).text( displayText( row.read_at ) );
					$( '#kv-created-at' ).text( displayText( row.created_at ) );
					$( '#kv-created-by' ).text( displayText( row.created_by ) );
					$( '#kv-message' ).text( displayText( row.message ) );
					$( '#kv-broadcast-row' ).prop( 'hidden', ! hasBroadcast );
					$( '#kv-last-error-row' ).prop( 'hidden', ! row.last_error );
					$( '#kv-media-wrap' ).prop( 'hidden', true );
					$modal.attr( 'aria-hidden', 'false' ).prop( 'hidden', false );
				}
			);
		}

		/**
		 * Close the detail modal.
		 */
		function closeModal() {
			$modal.attr( 'aria-hidden', 'true' ).prop( 'hidden', true );
		}

		$refresh.on(
			'click',
			function () {
				resetTable();
				fetchPage();
			}
		);

		$search.on(
			'keydown',
			function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					$refresh.trigger( 'click' );
				}
			}
		);

		$status.on( 'change', function () {
			$refresh.trigger( 'click' );
		} );

		$messageType.on( 'change', function () {
			$refresh.trigger( 'click' );
		} );

		$tableWrap.on(
			'scroll',
			function () {
				if ( scrollTicking ) {
					return;
				}

				scrollTicking = true;

				window.requestAnimationFrame(
					function () {
						var element = $tableWrap.get( 0 );

						if (
							element &&
							element.scrollTop + element.clientHeight >= element.scrollHeight - 30
						) {
							fetchPage();
						}

						scrollTicking = false;
					}
				);
			}
		);

		$( document ).on(
			'click',
			'.nxtcc-history-table tbody tr',
			function ( event ) {
				var id;
				var source;

				if ( $( event.target ).is( 'input[type="checkbox"]' ) ) {
					return;
				}

				id = parseInt( $( this ).data( 'id' ), 10 );
				source = String( $( this ).data( 'source' ) || 'history' );

				if ( ! id ) {
					return;
				}

				openModal( id, source );
			}
		);

		$modalClosePrimary.on( 'click', closeModal );
		$modalCloseSecondary.on( 'click', closeModal );
		$modalOverlay.on( 'click', closeModal );

		$( document ).on(
			'keydown',
			function ( event ) {
				if ( 'Escape' === event.key ) {
					closeModal();
				}
			}
		);

		$bulkSelectAll.on(
			'change',
			function () {
				var checked = $( this ).is( ':checked' );

				$( '.nxtcc-row-check' ).prop( 'checked', checked );
			}
		);

		$bulkApply.on(
			'click',
			function () {
				var action = String( $bulkAction.val() || '' );
				var ids = [];
				var sourceMap = {};

				if ( ! action ) {
					return;
				}

				$( '.nxtcc-row-check:checked' ).each(
					function () {
						var rowId = parseInt( $( this ).data( 'id' ), 10 );
						var source = String( $( this ).data( 'source' ) || 'history' );

						if ( rowId ) {
							ids.push( rowId );
							sourceMap[ source ] = true;
						}
					}
				);

				if ( ! ids.length ) {
					window.alert( 'Please select at least one row.' );
					return;
				}

				var sourceParam = 'history';
				var filters = currentFilters();

				if ( sourceMap.queue && sourceMap.history ) {
					sourceParam = 'both';
				} else if ( sourceMap.queue ) {
					sourceParam = 'queue';
				}

				if ( 'delete' === action ) {
					if ( ! window.confirm( 'Delete selected rows? This cannot be undone.' ) ) {
						return;
					}

					$bulkApply.prop( 'disabled', true );

					safePost(
						{
							action: 'nxtcc_history_bulk_delete',
							nonce: nonce,
							source: sourceParam,
							ids: ids,
						}
					)
						.done(
							function ( response ) {
								if ( response && response.success ) {
									$( '.nxtcc-row-check:checked' ).closest( 'tr' ).remove();
									$bulkSelectAll.prop( 'checked', false );
								} else {
									window.alert(
										response && response.data && response.data.message
											? response.data.message
											: 'Delete failed.'
									);
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
					var form = document.createElement( 'form' );

					function addHiddenField( name, value ) {
						var input = document.createElement( 'input' );

						input.type = 'hidden';
						input.name = name;
						input.value = String( value || '' );
						form.appendChild( input );
					}

					form.method = 'POST';
					form.action = ajaxurl;
					form.target = '_blank';

					addHiddenField( 'action', 'nxtcc_history_export' );
					addHiddenField( 'nonce', nonce );
					addHiddenField( 'source', sourceParam );
					addHiddenField( 'search', filters.search || '' );
					addHiddenField( 'message_type', filters.message_type || '' );
					addHiddenField( 'status', filters.status || '' );
					addHiddenField( 'status_any', filters.status_any || '' );
					addHiddenField( 'range', filters.range || '' );
					addHiddenField( 'from', filters.from || '' );
					addHiddenField( 'to', filters.to || '' );
					addHiddenField( 'phone_number_id', filters.phone_number_id || '' );
					addHiddenField( 'sort', filters.sort || '' );

					document.body.appendChild( form );
					form.submit();
					document.body.removeChild( form );
				}
			}
		);

		resetTable();
		fetchPage();
	}
);
