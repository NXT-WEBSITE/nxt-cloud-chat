/**
 * Admin chat inbox controller.
 *
 * Responsibilities:
 * - Polls the server for the inbox summary (latest chat heads + unread counts).
 * - Renders each chat head as a DOM-only row (no HTML string injection).
 * - Supports client-side filtering by name/phone; pauses polling while filtering.
 * - Syncs the currently selected conversation with the thread module.
 *
 * @package NXTCC
 */

/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	const Chat = window.NXTCCChat;
	if ( ! Chat || ! Chat.util ) {
		return;
	}

	const U = Chat.util;

	Chat.inbox = Chat.inbox || {};

	Chat.inbox.start = function ( ctx ) {
		if ( ! ctx || ! ctx.$widget || ! ctx.$chatList ) {
			return;
		}

		const $widget   = ctx.$widget;
		const $chatList = ctx.$chatList;

		// Shared state/API containers across chat modules.
		ctx.state = ctx.state || {};
		ctx.api   = ctx.api || {};
		ctx.api.inbox = ctx.api.inbox || {};

		const ns = '.nxtccInbox' + U.toStr( ctx.instanceId || '' );

		let inboxPollingInterval = null;
		let pollInFlight         = false;
		let searchDebounceTimer  = null;

		/**
		 * Render the missing/invalid connection notice with a settings link.
		 *
		 * @param {Element} listEl Inbox list element.
		 * @param {Object}  data   Optional AJAX error data.
		 * @return {void}
		 */
		function renderInvalidConnectionMessage( listEl, data ) {
			if ( ! listEl ) {
				return;
			}

			U.safeEmpty( listEl );

			const notice = U.el( 'div', { class: 'nxtcc-chat-list-notice is-error' } );
			notice.style.padding = '18px 8px';
			notice.style.color   = '#b32d2e';

			const settingsUrl = data && data.settings_url ? U.toStr( data.settings_url ) : U.toStr( Chat.cfg.settingsUrl || 'admin.php?page=nxtcc-settings' );
			U.safeAppend( notice, document.createTextNode( 'Invalid WhatsApp connection. Please set up your WhatsApp connection in ' ) );
			U.safeAppend( notice, U.el( 'a', { href: settingsUrl }, 'Settings' ) );
			U.safeAppend( notice, document.createTextNode( '.' ) );
			U.safeAppend( listEl, notice );
		}

		/**
		 * Determine whether an AJAX error represents missing connection settings.
		 *
		 * @param {Object} data AJAX error data.
		 * @return {boolean} Whether this is an invalid connection response.
		 */
		function isInvalidConnectionError( data ) {
			const code    = data && data.code ? U.toStr( data.code ) : '';
			const message = data && data.message ? U.toStr( data.message ).toLowerCase() : '';

			return 'invalid_connection' === code ||
				message.indexOf( 'connection' ) !== -1 ||
				message.indexOf( 'phone number id not found' ) !== -1;
		}

		/**
		 * Extract WordPress AJAX error data from a failed jQuery request.
		 *
		 * @param {Object} xhr jQuery XHR object.
		 * @return {Object} Error data.
		 */
		function ajaxErrorData( xhr ) {
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) {
				return xhr.responseJSON.data;
			}

			return {};
		}

		/**
		 * Build one inbox row element for a contact thread.
		 *
		 * @param {Object} chat Inbox item returned by the server.
		 * @return {Element} Row element.
		 */
		function buildInboxRow( chat ) {
			const contactId = Number( chat && chat.contact_id ? chat.contact_id : 0 );

			const cc   = chat && chat.country_code ? U.toStr( chat.country_code ) : '';
			const ph   = chat && chat.phone_number ? U.toStr( chat.phone_number ) : '';
			const full = cc ? '+' + cc + ( ph ? ' ' + ph : '' ) : ph;

			const nameText =
				( chat && chat.name ? U.toStr( chat.name ) : '' ) ||
				full ||
				'Unknown';

			const initial = U.toStr(
				chat && ( chat.name || chat.phone_number ) ? ( chat.name || chat.phone_number ) : '?'
			)
				.trim()
				.charAt( 0 );

			const avatar = U.el(
				'div',
				{ class: 'nxtcc-avatar' },
				initial ? initial.toUpperCase() : '?'
			);

			const previewText = U.formatPreviewText(
				U.toStr( chat && chat.message_preview ? chat.message_preview : '' ),
				40
			);

			const row = U.el( 'div', {
				class: 'nxtcc-chat-head',
				'data-contact': contactId ? String( contactId ) : '0',
				'data-phone': U.toStr( full ),
			} );
			const conversation = chat && chat.conversation && 'object' === typeof chat.conversation ? chat.conversation : null;
			if ( conversation ) {
				row.setAttribute( 'data-ticket-id', U.toStr( conversation.id || '' ) );
			}
			const assignment = chat && chat.assignment && 'object' === typeof chat.assignment ? chat.assignment : null;
			let assignmentTarget = '';
			if ( assignment && 'user' === U.toStr( assignment.target_type ) ) {
				assignmentTarget = 'user:' + U.toStr( assignment.assigned_user_id );
			} else if ( assignment && 'role' === U.toStr( assignment.target_type ) ) {
				assignmentTarget = 'role:' + U.toStr( assignment.assigned_role );
			}
			row.setAttribute( 'data-assignment-target', assignmentTarget );

			// Cache normalized text for fast client-side filtering (no repeated DOM reads).
			row.setAttribute( 'data-name-lc', U.toStr( nameText ).toLowerCase() );
			row.setAttribute( 'data-phone-lc', U.toStr( full ).toLowerCase() );

			U.safeAppend( row, avatar );

			const main = U.el( 'div', { class: 'nxtcc-chat-head-main' } );
			U.safeAppend( main, U.el( 'div', { class: 'nxtcc-chat-head-name' }, nameText ) );
			U.safeAppend( main, U.el( 'div', { class: 'nxtcc-chat-head-preview' }, previewText ) );
			U.safeAppend( main, U.el( 'div', { class: 'nxtcc-chat-head-assignment' }, assignment && assignment.label ? U.toStr( assignment.label ) : 'Unassigned' ) );
			if ( conversation ) {
				const ticketMeta = U.el( 'div', { class: 'nxtcc-chat-head-ticket-meta' } );
				U.safeAppend( ticketMeta, U.el( 'span', { class: 'nxtcc-ticket-chip status-' + U.toStr( conversation.status || 'open' ) }, U.toStr( conversation.status || 'open' ) ) );
				U.safeAppend( ticketMeta, U.el( 'span', { class: 'nxtcc-ticket-chip priority-' + U.toStr( conversation.priority || 'normal' ) }, U.toStr( conversation.priority || 'normal' ) ) );
				U.safeAppend( main, ticketMeta );
			}
			U.safeAppend( row, main );

			const meta = U.el( 'div', { class: 'nxtcc-chat-head-meta' } );
			U.safeAppend(
				meta,
				U.el(
					'div',
					{ class: 'nxtcc-chat-head-time' },
					U.toStr( chat && chat.last_msg_time ? chat.last_msg_time : '' )
				)
			);

			const unread = Number( chat && chat.unread_count ? chat.unread_count : 0 );
			if ( unread > 0 ) {
				U.safeAppend(
					meta,
					U.el( 'span', { class: 'nxtcc-chat-head-unread' }, String( unread ) )
				);
			}

			U.safeAppend( row, meta );

			return row;
		}

		/**
		 * Replace the inbox list with a new set of rows.
		 *
		 * @param {Array} contacts Contact threads array.
		 * @return {void}
		 */
		function patchInbox( contacts ) {
			const listEl = $chatList.get( 0 );
			if ( ! listEl ) {
				return;
			}

			U.safeEmpty( listEl );

			if ( ! contacts || ! contacts.length ) {
				U.setListEmptyMessage( listEl, 'No chats found.', '#888' );
				return;
			}

			const frag = document.createDocumentFragment();

			contacts.forEach( function ( chat ) {
				U.safeAppend( frag, buildInboxRow( chat ) );
			} );

			U.safeAppend( listEl, frag );

			if ( ctx.state.chatContactId ) {
				$chatList
					.find( '.nxtcc-chat-head[data-contact="' + String( ctx.state.chatContactId ) + '"]' )
					.addClass( 'active' );
			}
		}

		/**
		 * Fetch inbox summary from the server.
		 *
		 * @return {void}
		 */
		function pollInbox() {
			if ( pollInFlight ) {
				return;
			}

			if ( ! ctx.phoneNumberId ) {
				renderInvalidConnectionMessage( $chatList.get( 0 ), {} );
				return;
			}

			pollInFlight = true;

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_fetch_inbox_summary',
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				ticket_view: U.toStr( $widget.find( '.nxtcc-ticket-view' ).val() || 'all' ),
				nonce: ctx.nonce,
			} )
				.done( function ( resp ) {
					if ( resp && resp.success && resp.data && resp.data.contacts ) {
						ctx.state.accessPolicy = resp.data.access_policy || { can_manage: false };
						$widget.toggleClass( 'is-view-only', ! ctx.state.accessPolicy.can_manage );
						patchInbox( resp.data.contacts );

						if ( ctx.api.thread && ctx.api.thread.syncSelectedContact ) {
							ctx.api.thread.syncSelectedContact( resp.data.contacts );
						}

						return;
					}

					const listEl = $chatList.get( 0 );
					if ( listEl ) {
						if ( resp && resp.data && isInvalidConnectionError( resp.data ) ) {
							renderInvalidConnectionMessage( listEl, resp.data );
							return;
						}

						U.setListEmptyMessage( listEl, 'Failed to load chats.', '#f00' );
					}
				} )
				.fail( function ( xhr ) {
					const listEl = $chatList.get( 0 );
					if ( listEl ) {
						const data = ajaxErrorData( xhr );
						if ( isInvalidConnectionError( data ) ) {
							renderInvalidConnectionMessage( listEl, data );
							return;
						}

						U.setListEmptyMessage( listEl, 'Failed to load chats.', '#f00' );
					}
				} )
				.always( function () {
					pollInFlight = false;
				} );
		}

		ctx.api.inbox.refresh = pollInbox;

		$widget.find( '.nxtcc-ticket-view' ).off( 'change' + ns ).on( 'change' + ns, function () {
			pollInbox();
		} );

		/**
		 * Start inbox polling interval.
		 *
		 * @return {void}
		 */
		function startInboxPolling() {
			if ( inboxPollingInterval ) {
				clearInterval( inboxPollingInterval );
				inboxPollingInterval = null;
			}

			pollInbox();
			inboxPollingInterval = setInterval( pollInbox, 8000 );
		}

		/**
		 * Stop inbox polling interval.
		 *
		 * @return {void}
		 */
		function stopInboxPolling() {
			if ( inboxPollingInterval ) {
				clearInterval( inboxPollingInterval );
				inboxPollingInterval = null;
			}
		}

		/**
		 * Apply client-side filtering.
		 *
		 * @param {string} q Query.
		 * @return {void}
		 */
		function applyFilter( q ) {
			const query = U.toStr( q ).toLowerCase();

			stopInboxPolling();

			$chatList.find( '.nxtcc-chat-head' ).each( function () {
				const row   = this;
				const name  = U.toStr( row.getAttribute( 'data-name-lc' ) || '' );
				const phone = U.toStr( row.getAttribute( 'data-phone-lc' ) || '' );

				const show = name.indexOf( query ) !== -1 || phone.indexOf( query ) !== -1;
				$( row ).toggle( show );
			} );

			if ( '' === query ) {
				startInboxPolling();
			}
		}

		// Client-side filter: pause polling while user is typing (debounced).
		$widget
			.find( '.nxtcc-inbox-search' )
			.off( 'input' + ns )
			.on( 'input' + ns, function () {
				const val = U.toStr( $( this ).val() );

				if ( searchDebounceTimer ) {
					clearTimeout( searchDebounceTimer );
					searchDebounceTimer = null;
				}

				searchDebounceTimer = setTimeout( function () {
					applyFilter( val );
				}, 150 );
			} );

		startInboxPolling();
	};
} );
