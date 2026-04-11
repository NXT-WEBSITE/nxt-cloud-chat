/**
 * Admin chat thread controller.
 *
 * Responsibilities:
 * - Renders a single conversation thread when a contact is selected.
 * - Normalizes message content (text/media envelopes) into safe DOM nodes.
 * - Loads initial thread, polls for new messages, and prepends older messages on scroll.
 * - Calls the media proxy route for media_id-based attachments.
 * - Keeps per-thread state (selected contact, last/oldest ids, load locks).
 * - Exposes a small API used by other modules (actions/inbox).
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

	Chat.thread = Chat.thread || {};

	Chat.thread.start = function ( ctx ) {
		if ( ! ctx || ! ctx.$widget || ! ctx.$chatThread || ! ctx.$chatList ) {
			return;
		}

		const $chatList   = ctx.$chatList;
		const $chatThread = ctx.$chatThread;
		const $chatHeader = ctx.$chatHeader;

		// Shared state/API containers across chat modules.
		ctx.state      = ctx.state || {};
		ctx.api        = ctx.api || {};
		ctx.api.thread = ctx.api.thread || {};

		// Thread state.
		ctx.state.chatContactId        = ctx.state.chatContactId || null;
		ctx.state.lastMessageId        = null;
		ctx.state.oldestMessageId      = null;
		ctx.state.loadingOlderMessages = false;

		// Polling state.
		let pollTimer         = null;
		let pollInFlight      = false;
		let pollErrorCount    = 0;
		let activeThreadToken = 0; // increments when contact changes to ignore stale callbacks.

		// NEW: Pause thread polling while user is reading older messages (not at bottom).
		let threadPollPaused = false;
		let pollNeedsCatchup = false;

		// Event namespace per widget instance.
		const ns = '.nxtccThread' + U.toStr( ctx.instanceId || '' );

		function mediaProxyUrl( mediaId ) {
			return (
				Chat.cfg.ajaxurl +
				'?action=nxtcc_media_proxy' +
				'&mid=' +
				encodeURIComponent( mediaId ) +
				'&pnid=' +
				encodeURIComponent( ctx.phoneNumberId ) +
				'&nonce=' +
				encodeURIComponent( ctx.nonce )
			);
		}

		function isNearBottom( elNode, pad ) {
			const padding = Number.isFinite( Number( pad ) ) ? Number( pad ) : 20;

			if ( ! elNode ) {
				return true;
			}

			return elNode.scrollHeight - ( elNode.scrollTop + elNode.clientHeight ) <= padding;
		}

		function updateScrollButton() {
			if ( ctx.api.actions && ctx.api.actions.updateScrollButton ) {
				ctx.api.actions.updateScrollButton();
			}
		}

		function findBubbleById( id ) {
			const msgId = parseInt( id, 10 ) || 0;
			if ( msgId <= 0 ) {
				return $();
			}
			return $chatThread.find( '.nxtcc-chat-bubble[data-msg-id="' + msgId + '"]' );
		}

		/**
		 * Optionally used by actions module: return selected bubble ids.
		 *
		 * Selection checkmark is a simple .nxtcc-check element inside bubble.
		 * Your actions module likely sets a class; we support both.
		 *
		 * @return {number[]} ids
		 */
		function getSelectedMessageIds() {
			const ids = [];

			$chatThread.find( '.nxtcc-chat-bubble' ).each( function () {
				const $b       = $( this );
				const selected =
					$b.hasClass( 'selected' ) ||
					$b.attr( 'data-selected' ) === '1' ||
					$b.find( '.nxtcc-check' ).hasClass( 'active' );

				if ( ! selected ) {
					return;
				}

				const raw = $b.attr( 'data-msg-id' );
				const id  = parseInt( raw, 10 ) || 0;
				if ( id > 0 ) {
					ids.push( id );
				}
			} );

			return ids;
		}

		/**
		 * Render normalized media object as DOM nodes.
		 *
		 * Supported formats:
		 * - { kind, media_id, caption?, filename? } (proxy)
		 * - { kind, link, caption?, filename? } (direct)
		 *
		 * @param {Object} obj Media object.
		 * @return {DocumentFragment} Fragment.
		 */
		function renderMediaFragment( obj ) {
			const frag = document.createDocumentFragment();

			if ( ! obj || 'object' !== typeof obj ) {
				return frag;
			}

			const kind = obj.kind;
			if ( ! kind ) {
				return frag;
			}

			const caption = U.toStr( obj.caption || '' ).trim();
			let captionEl = null;

			if ( caption ) {
				captionEl = U.el( 'div', { class: 'nxtcc-msg-caption' }, caption );
			}

			function appendCaptionIfAny() {
				if ( captionEl ) {
					U.safeAppend( frag, captionEl );
				}
			}

			// media_id based (served via proxy).
			if ( obj.media_id ) {
				const src = mediaProxyUrl( obj.media_id );

				if ( 'image' === kind || 'sticker' === kind ) {
					const img = U.el( 'img', { class: 'nxtcc-msg-media', src: src, alt: 'image' } );
					U.safeAppend( frag, img );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'video' === kind ) {
					const vid = U.el( 'video', { class: 'nxtcc-msg-media', controls: 'controls' } );
					vid.src   = src;
					U.safeAppend( frag, vid );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'audio' === kind ) {
					const aud = U.el( 'audio', { class: 'nxtcc-msg-media', controls: 'controls' } );
					aud.src   = src;
					U.safeAppend( frag, aud );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'document' === kind ) {
					const name = U.toStr( obj.filename || 'document' );
					const a    = U.el(
						'a',
						{ class: 'nxtcc-msg-media', href: src, target: '_blank', download: 'download' },
						name
					);
					U.safeAppend( frag, a );
					appendCaptionIfAny();
					return frag;
				}
			}

			// direct link based.
			if ( obj.link ) {
				const href = U.toStr( obj.link );

				if ( 'image' === kind || 'sticker' === kind ) {
					const img = U.el( 'img', { class: 'nxtcc-msg-media', src: href, alt: 'image' } );
					U.safeAppend( frag, img );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'video' === kind ) {
					const vid = U.el( 'video', { class: 'nxtcc-msg-media', controls: 'controls' } );
					vid.src   = href;
					U.safeAppend( frag, vid );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'audio' === kind ) {
					const aud = U.el( 'audio', { class: 'nxtcc-msg-media', controls: 'controls' } );
					aud.src   = href;
					U.safeAppend( frag, aud );
					appendCaptionIfAny();
					return frag;
				}

				if ( 'document' === kind ) {
					const name = U.toStr( obj.filename || 'document' );
					const a    = U.el(
						'a',
						{ class: 'nxtcc-msg-media', href: href, target: '_blank', download: 'download' },
						name
					);
					U.safeAppend( frag, a );
					appendCaptionIfAny();
					return frag;
				}
			}

			return frag;
		}

		/**
		 * Message body renderer as DOM nodes.
		 *
		 * @param {*} content Content.
		 * @return {DocumentFragment} Fragment.
		 */
		function renderMessageFragment( content ) {
			const frag = document.createDocumentFragment();

			if ( content === null || content === undefined ) {
				return frag;
			}

			if ( 'string' === typeof content ) {
				const trimmed = content.trim();

				if ( trimmed.startsWith( '{' ) ) {
					try {
						const obj = JSON.parse( trimmed );

						if ( obj && 'text' === obj.kind && 'string' === typeof obj.text ) {
							return U.formatMessageFragment( obj.text );
						}

						return renderMediaFragment( obj );
					} catch ( e ) {
						// Not JSON: treat as text.
					}
				}

				return U.formatMessageFragment( content );
			}

			if ( 'object' === typeof content ) {
				return renderMediaFragment( content );
			}

			const s = String( content );

			if ( /\.(jpg|jpeg|png|gif|webp)$/i.test( s ) ) {
				U.safeAppend( frag, U.el( 'img', { class: 'nxtcc-msg-media', src: s, alt: 'image' } ) );
				return frag;
			}

			if ( /\.(mp3|wav|ogg)$/i.test( s ) ) {
				const aud = U.el( 'audio', { class: 'nxtcc-msg-media', controls: 'controls' } );
				aud.src   = s;
				U.safeAppend( frag, aud );
				return frag;
			}

			if ( /\.(mp4|webm|mov)$/i.test( s ) ) {
				const vid = U.el( 'video', { class: 'nxtcc-msg-media', controls: 'controls' } );
				vid.src   = s;
				U.safeAppend( frag, vid );
				return frag;
			}

			if ( /^https?:\/\//i.test( s ) ) {
				U.safeAppend(
					frag,
					U.el( 'a', { class: 'nxtcc-msg-media', href: s, target: '_blank' }, 'View Link' )
				);
				return frag;
			}

			return U.formatMessageFragment( s );
		}

		/**
		 * Render quoted-reply header for a message bubble.
		 *
		 * @param {Object} rep Reply payload.
		 * @return {Element|null} Element.
		 */
		function renderReplyQuoteEl( rep ) {
			if ( ! rep ) {
				return null;
			}

			const kind = rep.kind || 'text';
			const root = U.el( 'div', { class: 'nxtcc-reply-quote' } );

			if ( 'image' === kind || 'sticker' === kind ) {
				if ( rep.media_id ) {
					U.safeAppend(
						root,
						U.el( 'img', { class: 'nxtcc-reply-thumb', src: mediaProxyUrl( rep.media_id ), alt: 'thumb' } )
					);
				} else if ( rep.link ) {
					U.safeAppend(
						root,
						U.el( 'img', { class: 'nxtcc-reply-thumb', src: U.toStr( rep.link ), alt: 'thumb' } )
					);
				}
			}

			const content = U.el( 'div', { class: 'nxtcc-reply-content' } );
			const textEl  = U.el( 'div', { class: 'nxtcc-reply-text' } );

			let line = '';
			if ( 'text' === kind ) {
				line = U.toStr( rep.text || '' ).slice( 0, 140 );
			} else {
				const label = '[' + kind.charAt( 0 ).toUpperCase() + kind.slice( 1 ) + '] ';
				const meta  = U.toStr( rep.caption || rep.filename || '' );
				line        = ( label + meta ).slice( 0, 140 );
			}

			textEl.textContent = line || '(media)';
			U.safeAppend( content, textEl );
			U.safeAppend( root, content );

			return root;
		}

		/**
		 * Status tick indicator (sent/delivered/read/retrying/failed).
		 *
		 * @param {string} status Status.
		 * @return {Element|null} Element.
		 */
		function getStatusTickEl( status ) {
			if ( 'sent' === status ) {
				return U.el( 'span', { class: 'nxtcc-status-tick', title: 'Sent' }, '✓' );
			}
			if ( 'delivered' === status ) {
				return U.el( 'span', { class: 'nxtcc-status-tick', title: 'Delivered' }, '✓✓' );
			}
			if ( 'read' === status ) {
				return U.el( 'span', { class: 'nxtcc-status-tick read', title: 'Read' }, '✓✓' );
			}
			if ( 'retrying' === status ) {
				return U.el( 'span', { class: 'nxtcc-status-retry', title: 'Retrying' }, '↻' );
			}
			if ( 'failed' === status ) {
				return U.el( 'span', { class: 'nxtcc-status-fail', title: 'Failed' }, '✖' );
			}
			return null;
		}

		function attachBubbleData( $bub, msg ) {
			$bub.data( 'raw', msg.message_content );
			$bub.data( 'metaId', msg.meta_message_id || '' );
			$bub.data( 'fav', msg.is_favorite ? 1 : 0 );
			$bub.attr( 'data-fav', msg.is_favorite ? '1' : '0' );
		}

		/**
		 * Apply favorite UI state to a bubble element.
		 *
		 * @param {jQuery} $bub Bubble.
		 * @param {boolean} isFav Favorite?
		 * @return {void}
		 */
		function applyFavoriteToBubble( $bub, isFav ) {
			if ( ! $bub || ! $bub.length ) {
				return;
			}

			const favVal = isFav ? 1 : 0;

			$bub.data( 'fav', favVal );
			$bub.attr( 'data-fav', isFav ? '1' : '0' );

			const $meta = $bub.find( '.nxtcc-msg-meta' ).first();
			if ( ! $meta.length ) {
				return;
			}

			const metaEl = $meta.get( 0 );
			if ( ! metaEl ) {
				return;
			}

			const existing = metaEl.querySelector( '.nxtcc-fav-star-inline' );

			if ( isFav ) {
				if ( ! existing ) {
					const star             = U.el(
						'span',
						{ class: 'nxtcc-fav-star-inline', title: 'Favorited' },
						'★'
					);
					star.style.marginRight = '4px';

					// IMPORTANT: Avoid jQuery .prepend() (VIP warns it can execute HTML).
					U.safeInsertAtStart( metaEl, star );
				}
				return;
			}

			if ( existing ) {
				metaEl.removeChild( existing );
			}
		}

		/**
		 * Build a message bubble element (no HTML string injection).
		 *
		 * @param {Object} msg Message row.
		 * @return {Element} Bubble element.
		 */
		function makeBubbleEl( msg ) {
			const isSent = msg.status !== 'received';

			const bubble = U.el( 'div', {
				class: 'nxtcc-chat-bubble ' + ( isSent ? 'sent' : 'received' ),
				'data-msg-id': U.toStr( msg.id ),
				'data-meta-id': U.toStr( msg.meta_message_id || '' ),
			} );

			if ( msg.reply ) {
				const replyEl = renderReplyQuoteEl( msg.reply );
				if ( replyEl ) {
					U.safeAppend( bubble, replyEl );
				}
			}

			U.safeAppend( bubble, renderMessageFragment( msg.message_content ) );

			const meta = U.el( 'div', { class: 'nxtcc-msg-meta' } );

			if ( msg.is_favorite ) {
				const star             = U.el(
					'span',
					{ class: 'nxtcc-fav-star-inline', title: 'Favorited' },
					'★'
				);
				star.style.marginRight = '4px';
				U.safeAppend( meta, star );
			}

			U.safeAppend( meta, document.createTextNode( U.toStr( msg.created_at || '' ) + ' ' ) );

			const tick = getStatusTickEl( msg.status );
			if ( tick ) {
				U.safeAppend( meta, tick );
			}

			U.safeAppend( bubble, meta );
			U.safeAppend( bubble, U.el( 'div', { class: 'nxtcc-check' }, '✓' ) );

			return bubble;
		}

		function patchChatThread( messages ) {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return;
			}

			U.safeEmpty( threadEl );

			if ( ! messages || ! messages.length ) {
				U.setListEmptyMessage( threadEl, 'No messages in this chat.', '#888' );
				updateScrollButton();
				return;
			}

			let firstUnreadFound = false;
			const frag           = document.createDocumentFragment();

			messages.forEach( function ( msg ) {
				const bub = makeBubbleEl( msg );
				U.safeAppend( frag, bub );
				attachBubbleData( $( bub ), msg );

				if ( 'received' === msg.status && 0 === Number( msg.is_read ) && ! firstUnreadFound ) {
					$( bub ).addClass( 'nxtcc-first-unread' );
					firstUnreadFound = true;
				}
			} );

			U.safeAppend( threadEl, frag );

			ctx.state.lastMessageId   = messages[ messages.length - 1 ].id;
			ctx.state.oldestMessageId = messages[ 0 ].id;

			setTimeout( function () {
				const $firstUnread = $chatThread.find( '.nxtcc-first-unread' );

				if ( $firstUnread.length ) {
					threadEl.scrollTo( { top: $firstUnread.get( 0 ).offsetTop - 40, behavior: 'smooth' } );
				} else {
					threadEl.scrollTo( { top: threadEl.scrollHeight, behavior: 'smooth' } );
				}

				setTimeout( updateScrollButton, 60 );
			}, 50 );
		}

		function showThreadTopNotice( text ) {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return;
			}

			const notice           = U.el( 'div', { class: 'nxtcc-load-older' }, text );
			notice.style.textAlign = 'center';
			notice.style.padding   = '5px';
			notice.style.color     = '#888';

			U.safeInsertAtStart( threadEl, notice );
		}

		function removeThreadTopNotice() {
			$chatThread.find( '.nxtcc-load-older' ).remove();
		}

		function resetThreadStateForContact() {
			ctx.state.lastMessageId        = null;
			ctx.state.oldestMessageId      = null;
			ctx.state.loadingOlderMessages = false;

			threadPollPaused = false;
			pollNeedsCatchup = false;

			const threadEl = $chatThread.get( 0 );
			if ( threadEl ) {
				U.safeEmpty( threadEl );
			}

			updateScrollButton();
		}

		function setComposerEnabledFromResp( resp ) {
			if ( ! ctx.api.actions || ! ctx.api.actions.setComposerEnabled ) {
				return;
			}

			if ( resp && resp.data && false === resp.data.can_reply_24hr ) {
				ctx.api.actions.setComposerEnabled( false );
				return;
			}

			ctx.api.actions.setComposerEnabled( true );
		}

		function isThreadEligibleForPolling() {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return true;
			}
			return isNearBottom( threadEl, 60 );
		}

		function maybeResumePollingFromScroll() {
			if ( ! ctx.state.chatContactId || ! ctx.state.lastMessageId ) {
				return;
			}

			if ( 'hidden' === document.visibilityState ) {
				return;
			}

			if ( ! threadPollPaused && ! pollNeedsCatchup ) {
				return;
			}

			if ( ! isThreadEligibleForPolling() ) {
				return;
			}

			threadPollPaused = false;
			pollNeedsCatchup = false;

			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}

			// Immediate catch-up poll when user returns to bottom.
			pollChatThread();
		}

		function loadChatThread() {
			if ( ! ctx.state.chatContactId ) {
				return;
			}

			const myToken  = activeThreadToken;
			const threadEl = $chatThread.get( 0 );

			threadPollPaused = false;
			pollNeedsCatchup = false;

			if ( threadEl ) {
				U.safeEmpty( threadEl );
				U.setListEmptyMessage( threadEl, 'Loading…', '#888' );
			}

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_fetch_chat_thread',
				contact_id: ctx.state.chatContactId,
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
			} )
				.done( function ( resp ) {
					if ( myToken !== activeThreadToken ) {
						return;
					}

					if ( resp && resp.success && resp.data && resp.data.messages ) {
						patchChatThread( resp.data.messages );
						setComposerEnabledFromResp( resp );

						$.post( Chat.cfg.ajaxurl, {
							action: 'nxtcc_mark_chat_read',
							contact_id: ctx.state.chatContactId,
							business_account_id: ctx.businessAccountId,
							phone_number_id: ctx.phoneNumberId,
							nonce: ctx.nonce,
						} );

						return;
					}

					if ( threadEl ) {
						U.safeEmpty( threadEl );
						U.setListEmptyMessage( threadEl, 'Failed to load messages.', '#f00' );
					}

					updateScrollButton();
				} )
				.fail( function () {
					if ( myToken !== activeThreadToken ) {
						return;
					}

					if ( threadEl ) {
						U.safeEmpty( threadEl );
						U.setListEmptyMessage( threadEl, 'Failed to load messages.', '#f00' );
					}

					updateScrollButton();
				} );
		}

		function computeNextPollDelayMs() {
			const threadEl = $chatThread.get( 0 );
			const near     = isNearBottom( threadEl, 60 );

			let base = near ? 5000 : 12000;

			if ( pollErrorCount > 0 ) {
				base = Math.min( 30000, base + pollErrorCount * 5000 );
			}

			return base;
		}

		function scheduleNextPoll() {
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}

			if ( ! ctx.state.chatContactId || ! ctx.state.lastMessageId ) {
				return;
			}

			if ( 'hidden' === document.visibilityState ) {
				return;
			}

			// NEW: If user is not at bottom, pause thread polling (inbox polling continues elsewhere).
			if ( ! isThreadEligibleForPolling() ) {
				threadPollPaused = true;
				pollNeedsCatchup = true;
				return;
			}

			if ( threadPollPaused ) {
				return;
			}

			const delay = computeNextPollDelayMs();

			pollTimer = setTimeout( function () {
				pollChatThread();
			}, delay );
		}

		function pollChatThread() {
			if ( ! ctx.state.chatContactId || ! ctx.state.lastMessageId ) {
				return;
			}

			if ( 'hidden' === document.visibilityState ) {
				return;
			}

			// NEW: If user is reading older messages, pause polling and wait until they return to bottom.
			if ( ! isThreadEligibleForPolling() ) {
				threadPollPaused = true;
				pollNeedsCatchup = true;
				return;
			}

			if ( threadPollPaused ) {
				return;
			}

			if ( pollInFlight ) {
				return;
			}
			pollInFlight = true;

			const myToken = activeThreadToken;

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_fetch_chat_thread',
				contact_id: ctx.state.chatContactId,
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
				after_id: ctx.state.lastMessageId,
			} )
				.done( function ( resp ) {
					if ( myToken !== activeThreadToken ) {
						return;
					}

					pollErrorCount = 0;

					const msgs =
						resp && resp.success && resp.data && resp.data.messages ? resp.data.messages : [];

					setComposerEnabledFromResp( resp );

					if ( ! msgs || ! msgs.length ) {
						return;
					}

					const threadEl = $chatThread.get( 0 );
					if ( ! threadEl ) {
						return;
					}

					const wasNear = isNearBottom( threadEl, 60 );
					const frag    = document.createDocumentFragment();

					msgs.forEach( function ( msg ) {
						const bub = makeBubbleEl( msg );
						attachBubbleData( $( bub ), msg );
						U.safeAppend( frag, bub );
						ctx.state.lastMessageId = msg.id;
					} );

					U.safeAppend( threadEl, frag );

					if ( wasNear ) {
						threadEl.scrollTo( { top: threadEl.scrollHeight, behavior: 'smooth' } );
					}

					updateScrollButton();
				} )
				.fail( function () {
					if ( myToken !== activeThreadToken ) {
						return;
					}
					pollErrorCount++;
				} )
				.always( function () {
					pollInFlight = false;

					if ( myToken !== activeThreadToken ) {
						return;
					}

					scheduleNextPoll();
				} );
		}

		function startChatPolling() {
			pollErrorCount = 0;

			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}

			// NEW: Only start polling when user is at bottom; otherwise mark catchup needed.
			if ( ! isThreadEligibleForPolling() ) {
				threadPollPaused = true;
				pollNeedsCatchup = true;
				return;
			}

			threadPollPaused = false;

			pollChatThread();
		}

		function stopChatPolling() {
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}
			pollInFlight   = false;
			pollErrorCount = 0;

			threadPollPaused = false;
			pollNeedsCatchup = false;
		}

		function loadOlderMessages() {
			if ( ! ctx.state.chatContactId || ! ctx.state.oldestMessageId || ctx.state.loadingOlderMessages ) {
				return;
			}

			ctx.state.loadingOlderMessages = true;

			// NEW: Once user loads older messages, treat thread as paused until they return to bottom.
			threadPollPaused = true;
			pollNeedsCatchup = true;

			showThreadTopNotice( 'Loading older messages…' );

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_fetch_chat_thread',
				contact_id: ctx.state.chatContactId,
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
				before_id: ctx.state.oldestMessageId,
			} )
				.done( function ( resp ) {
					removeThreadTopNotice();

					const msgs =
						resp && resp.success && resp.data && resp.data.messages ? resp.data.messages : [];

					if ( msgs.length ) {
						const threadEl = $chatThread.get( 0 );

						if ( threadEl ) {
							const scrollBefore = threadEl.scrollHeight;
							const frag         = document.createDocumentFragment();

							msgs.forEach( function ( msg ) {
								const bub = makeBubbleEl( msg );
								attachBubbleData( $( bub ), msg );
								U.safeAppend( frag, bub );
							} );

							const newNodes = Array.from( frag.childNodes );
							for ( let i = newNodes.length - 1; i >= 0; i-- ) {
								U.safeInsertAtStart( threadEl, newNodes[ i ] );
							}

							const scrollAfter = threadEl.scrollHeight;
							$chatThread.scrollTop( scrollAfter - scrollBefore );

							ctx.state.oldestMessageId = msgs[ 0 ].id;
						}
					}

					ctx.state.loadingOlderMessages = false;
					updateScrollButton();

					// If user is already back at bottom for some reason, resume immediately.
					maybeResumePollingFromScroll();
				} )
				.fail( function () {
					removeThreadTopNotice();
					ctx.state.loadingOlderMessages = false;
					updateScrollButton();
				} );
		}

		// Pause/resume polling when tab visibility changes.
		$( document ).off( 'visibilitychange' + ns );
		$( document ).on( 'visibilitychange' + ns, function () {
			if ( 'hidden' === document.visibilityState ) {
				stopChatPolling();
				return;
			}

			if ( ctx.state.chatContactId && ctx.state.lastMessageId ) {
				// NEW: if user is not at bottom, don't resume; wait for scroll-to-bottom.
				if ( ! isThreadEligibleForPolling() ) {
					threadPollPaused = true;
					pollNeedsCatchup = true;
					return;
				}
				startChatPolling();
			}
		} );

		/**
		 * Exposed methods for the actions module:
		 * - applyFavoriteState(ids, isFav)
		 * - removeMessagesByIds(ids)
		 * - getSelectedMessageIds()
		 */
		function applyFavoriteState( ids, isFav ) {
			const list = Array.isArray( ids ) ? ids : [];
			list.forEach( function ( id ) {
				const $b = findBubbleById( id );
				applyFavoriteToBubble( $b, Boolean( isFav ) );
			} );
		}

		function removeMessagesByIds( ids ) {
			const list = Array.isArray( ids ) ? ids : [];
			list.forEach( function ( id ) {
				findBubbleById( id ).remove();
			} );
			updateScrollButton();
		}

		// Expose API for other modules.
		ctx.api.thread.mediaProxyUrl         = mediaProxyUrl;
		ctx.api.thread.isNearBottom          = isNearBottom;
		ctx.api.thread.patchChatThread       = patchChatThread;
		ctx.api.thread.loadChatThread        = loadChatThread;
		ctx.api.thread.pollChatThread        = pollChatThread;
		ctx.api.thread.startChatPolling      = startChatPolling;
		ctx.api.thread.stopChatPolling       = stopChatPolling;
		ctx.api.thread.applyFavoriteState    = applyFavoriteState;
		ctx.api.thread.removeMessagesByIds   = removeMessagesByIds;
		ctx.api.thread.getSelectedMessageIds = getSelectedMessageIds;

		// Contact selection: load the thread and start polling (namespaced).
		$chatList.off( 'click' + ns, '.nxtcc-chat-head' );
		$chatList.on( 'click' + ns, '.nxtcc-chat-head', function () {
			const $row = $( this );

			$chatList.find( '.nxtcc-chat-head' ).removeClass( 'active' );
			$row.addClass( 'active' );

			const contactIdRaw = $row.attr( 'data-contact' ) || $row.data( 'contact' ) || 0;
			const contactId    = parseInt( contactIdRaw, 10 ) || 0;

			ctx.state.chatContactId = contactId;

			const name  = $row.find( '.nxtcc-chat-head-name' ).text();
			const phone = U.toStr( $row.attr( 'data-phone' ) || $row.data( 'phone' ) || '' );

			$chatHeader.find( '.nxtcc-chat-contact-name' ).text( name );
			$chatHeader.find( '.nxtcc-chat-contact-number' ).text( phone );

			if ( ctx.api.actions && ctx.api.actions.enterChatView ) {
				ctx.api.actions.enterChatView( $row );
			}

			// Invalidate old in-flight callbacks.
			activeThreadToken++;

			stopChatPolling();
			resetThreadStateForContact();

			loadChatThread();

			setTimeout( function () {
				startChatPolling();
			}, 150 );

			$row.find( '.nxtcc-chat-head-unread' ).fadeOut( 200, function () {
				$( this ).remove();
			} );

			setTimeout( updateScrollButton, 100 );
		} );

		// Infinite scroll: when scrolled to top, request older messages (namespaced).
		$chatThread.off( 'scroll' + ns );
		$chatThread.on( 'scroll' + ns, function () {
			if ( $chatThread.scrollTop() <= 0 ) {
				loadOlderMessages();
			}

			// NEW: If user comes back to bottom, resume polling and catch up immediately.
			maybeResumePollingFromScroll();

			updateScrollButton();
		} );
	};
} );
