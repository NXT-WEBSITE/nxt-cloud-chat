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

		const ns = '.nxtccInbox' + U.toStr( ctx.instanceId || '' );

		let inboxPollingInterval = null;
		let pollInFlight         = false;
		let searchDebounceTimer  = null;

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

			// Cache normalized text for fast client-side filtering (no repeated DOM reads).
			row.setAttribute( 'data-name-lc', U.toStr( nameText ).toLowerCase() );
			row.setAttribute( 'data-phone-lc', U.toStr( full ).toLowerCase() );

			U.safeAppend( row, avatar );

			const main = U.el( 'div', { class: 'nxtcc-chat-head-main' } );
			U.safeAppend( main, U.el( 'div', { class: 'nxtcc-chat-head-name' }, nameText ) );
			U.safeAppend( main, U.el( 'div', { class: 'nxtcc-chat-head-preview' }, previewText ) );
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
			pollInFlight = true;

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_fetch_inbox_summary',
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
			} )
				.done( function ( resp ) {
					if ( resp && resp.success && resp.data && resp.data.contacts ) {
						patchInbox( resp.data.contacts );

						if ( ctx.api.thread && ctx.api.thread.syncSelectedContact ) {
							ctx.api.thread.syncSelectedContact( resp.data.contacts );
						}

						return;
					}

					const listEl = $chatList.get( 0 );
					if ( listEl ) {
						U.setListEmptyMessage( listEl, 'Failed to load chats.', '#f00' );
					}
				} )
				.fail( function () {
					const listEl = $chatList.get( 0 );
					if ( listEl ) {
						U.setListEmptyMessage( listEl, 'Failed to load chats.', '#f00' );
					}
				} )
				.always( function () {
					pollInFlight = false;
				} );
		}

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
