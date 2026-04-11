/**
 * Admin chat bootloader.
 *
 * Locates each `.nxtcc-whatsapp-widget` instance on the page, builds a per-widget
 * context object (`ctx`) with all required DOM handles + configuration values,
 * then starts the chat modules:
 * - inbox: renders/polls the chat list and search filtering
 * - thread: loads message history, polls new messages, loads older on scroll
 * - actions: composer + selection mode (reply/forward/favorite/delete) + UI helpers
 *
 * A global guard is used to prevent duplicate initialization when admin scripts
 * are re-enqueued, concatenated, or hot-reloaded.
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

	// Prevent duplicate init when scripts are reloaded by caching/minifiers.
	const bootKey = '__nxtcc_chat_boot__';

	if ( window[ bootKey ] ) {
		return;
	}
	window[ bootKey ] = true;

	$( '.nxtcc-whatsapp-widget' ).each( function () {
		const $widget = $( this );

		const nonceInput = $widget.find( '.nxtcc-inbox-nonce' );
		const nonceVal   = nonceInput.length ? String( nonceInput.val() || '' ) : '';

		/**
		 * Widget runtime context.
		 *
		 * This object is shared across modules so they can:
		 * - read configuration (nonce, account ids)
		 * - operate on the same DOM nodes
		 * - share state (`ctx.state`) and cross-module hooks (`ctx.api`)
		 */
		const ctx = {
			$widget: $widget,

			instanceId: String( $widget.data( 'instance' ) || 'adminchat' ),

			$inboxPanel: $widget.find( '.nxtcc-inbox-panel' ),
			$chatPanel: $widget.find( '.nxtcc-chat-panel' ),

			$chatList: $widget.find( '.nxtcc-chat-list' ),
			$chatThread: $widget.find( '.nxtcc-chat-thread' ),
			$chatHeader: $widget.find( '.nxtcc-chat-header' ),
			$backBtn: $widget.find( '.nxtcc-chat-back-btn' ),

			$search: $widget.find( '.nxtcc-inbox-search' ),

			nonce: nonceVal,
			businessAccountId: String( $widget.data( 'business-account-id' ) || '' ),
			phoneNumberId: String( $widget.data( 'phone-number-id' ) || '' ),

			// Action bar (selection mode).
			$actions: $widget.find( '.nxtcc-chat-actions' ),
			$selCount: $widget.find( '.nxtcc-chat-actions .nxtcc-selected-count' ),
			$btnReply: $widget.find( '.nxtcc-chat-actions .nxtcc-act-reply' ),
			$btnForward: $widget.find( '.nxtcc-chat-actions .nxtcc-act-forward' ),
			$btnFav: $widget.find( '.nxtcc-chat-actions .nxtcc-act-favorite' ),
			$favIcon: $widget.find( '.nxtcc-chat-actions .nxtcc-act-favorite-icon' ),
			$btnDel: $widget.find( '.nxtcc-chat-actions .nxtcc-act-delete' ),
			$btnClose: $widget.find( '.nxtcc-chat-actions .nxtcc-act-close' ), // optional.

			// Reply strip.
			$replyStrip: $widget.find( '.nxtcc-reply-strip' ),
			$replyCancel: $widget.find( '.nxtcc-reply-cancel' ),
			$replySnippet: $widget.find( '.nxtcc-reply-snippet' ),

			// Forward modal.
			$fModal: $widget.find( '.nxtcc-forward-modal' ),
			$fBackdrop: $widget.find( '.nxtcc-forward-backdrop' ),
			$fClose: $widget.find( '.nxtcc-forward-close' ),
			$fSearch: $widget.find( '.nxtcc-forward-search' ),
			$fList: $widget.find( '.nxtcc-forward-list' ),
			$fEmpty: $widget.find( '.nxtcc-forward-empty' ),
			$fSend: $widget.find( '.nxtcc-forward-send' ),
			$fSelCount: $widget.find( '.nxtcc-forward-selected-count' ),

			// Composer.
			$inputWrap: $widget.find( '.nxtcc-chat-input-wrapper' ),
			$textarea: $widget.find( '.nxtcc-chat-textarea' ),
			$sendBtn: $widget.find( '.nxtcc-send-msg-btn' ),
			$uploadBtn: $widget.find( '.nxtcc-upload-btn' ),
			$fileInput: $widget.find( '.nxtcc-file-input' ),

			// Scroll-to-latest button.
			$scrollBtn: $widget.find( '.nxtcc-scroll-bottom' ),

			// Shared per-widget state + cross-module hooks.
			state: {},
			api: {},
		};

		// Start modules (order matters).
		if ( Chat.inbox && typeof Chat.inbox.start === 'function' ) {
			Chat.inbox.start( ctx );
		}
		if ( Chat.thread && typeof Chat.thread.start === 'function' ) {
			Chat.thread.start( ctx );
		}
		if ( Chat.actions && typeof Chat.actions.start === 'function' ) {
			Chat.actions.start( ctx );
		}
	} );
} );
