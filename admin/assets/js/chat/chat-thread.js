/**
 * Admin chat thread controller.
 *
 * Responsibilities:
 * - Renders a single conversation thread when a contact is selected.
 * - Normalizes message content (text/media envelopes) into safe DOM nodes.
 * - Loads initial thread, polls for new messages, and prepends older messages on scroll.
 * - Calls the media proxy route for media_id-based attachments.
 * - Keeps per-thread state (selected contact, last/oldest ids, load locks).
 * - Exposes only the thread hooks used by the inbox/actions modules.
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
		if ( ! ctx || ! ctx.$chatThread || ! ctx.$chatList || ! ctx.$chatHeader ) {
			return;
		}

		const $chatList   = ctx.$chatList;
		const $chatThread = ctx.$chatThread;
		const $chatHeader = ctx.$chatHeader;
		const $profileBtn = $chatHeader.find( '.nxtcc-chat-profile-btn' );

		// Shared state/API containers across chat modules.
		ctx.state      = ctx.state || {};
		ctx.api        = ctx.api || {};
		ctx.api.thread = ctx.api.thread || {};

		// Thread state.
		ctx.state.chatContactId        = ctx.state.chatContactId || null;
		ctx.state.lastMessageId        = null;
		ctx.state.oldestMessageId      = null;
		ctx.state.lastActivityId       = null;
		ctx.state.oldestActivityId     = null;
		ctx.state.hasOlderMessages     = false;
		ctx.state.hasOlderActivities   = false;
		ctx.state.loadingOlderMessages = false;

		function syncProfileButton( contactId ) {
			const id = parseInt( contactId, 10 ) || 0;

			$profileBtn.prop( 'disabled', id <= 0 );
			if ( id > 0 ) {
				$profileBtn.attr( 'data-contact-id', String( id ) );
			} else {
				$profileBtn.removeAttr( 'data-contact-id' );
			}
		}

		syncProfileButton( ctx.state.chatContactId );

		// Polling state.
		let pollTimer         = null;
		let pollInFlight      = false;
		let pollErrorCount    = 0;
		let activeThreadToken = 0; // increments when contact changes to ignore stale callbacks.
		let loadRequestId     = 0;
		let activeInboxKey    = '';
		let pendingActivityId = 0;

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
		 * Render a submitted Flow response as a compact field list.
		 *
		 * @param {Object} response Normalized Flow response.
		 * @return {Element} Flow response card.
		 */
		function renderFlowResponseEl( response ) {
			const data = response && 'object' === typeof response ? response : {};
			const root = U.el( 'div', { class: 'nxtcc-flow-response' } );
			const head = U.el( 'div', { class: 'nxtcc-flow-response-head' } );
			const title = 'Flow Response';
			const count = Number.parseInt( data.answer_count, 10 ) || 0;
			const meta  = U.el( 'div', { class: 'nxtcc-flow-response-head-meta' } );

			U.safeAppend( head, U.el( 'div', { class: 'nxtcc-flow-response-title' }, title ) );
			U.safeAppend(
				meta,
				U.el( 'span', { class: 'nxtcc-flow-response-status' }, 'Received' )
			);
			U.safeAppend(
				meta,
				U.el(
					'span',
					{ class: 'nxtcc-flow-response-count' },
					count + ( 1 === count ? ' answer' : ' answers' )
				)
			);
			U.safeAppend( head, meta );
			U.safeAppend( root, head );

			const fields = Array.isArray( data.fields ) ? data.fields : [];
			const body   = U.el( 'div', { class: 'nxtcc-flow-response-fields' } );

			if ( fields.length ) {
				fields.forEach( function ( field ) {
					const row   = U.el( 'div', { class: 'nxtcc-flow-response-field' } );
					const label = U.toStr( field && field.label ? field.label : 'Response' );
					const value = U.toStr( field && field.value !== undefined ? field.value : '' );

					U.safeAppend( row, U.el( 'div', { class: 'nxtcc-flow-response-label' }, label ) );
					U.safeAppend(
						row,
						U.el( 'div', { class: 'nxtcc-flow-response-value' }, value || 'Not provided' )
					);
					U.safeAppend( body, row );
				} );
			} else {
				U.safeAppend(
					body,
					U.el(
						'div',
						{ class: 'nxtcc-flow-response-empty' },
						data.malformed ? 'Response details could not be read.' : 'No submitted answers.'
					)
				);
			}

			U.safeAppend( root, body );
			return root;
		}

		/**
		 * Render a button or list reply.
		 *
		 * @param {Object} reply Normalized interactive reply.
		 * @return {Element} Interactive reply card.
		 */
		function renderInteractiveReplyEl( reply ) {
			const data = reply && 'object' === typeof reply ? reply : {};
			const type = U.toStr( data.type || '' );
			const label = 'button_reply' === type ? 'Button response' : 'List response';
			const root = U.el( 'div', { class: 'nxtcc-interactive-reply' } );

			U.safeAppend( root, U.el( 'div', { class: 'nxtcc-interactive-reply-label' }, label ) );
			U.safeAppend(
				root,
				U.el(
					'div',
					{ class: 'nxtcc-interactive-reply-title' },
					U.toStr( data.title || 'Interactive response' )
				)
			);

			if ( data.description ) {
				U.safeAppend(
					root,
					U.el(
						'div',
						{ class: 'nxtcc-interactive-reply-description' },
						U.toStr( data.description )
					)
				);
			}

			return root;
		}

		/**
		 * Render WhatsApp-style inline formatting without HTML injection.
		 *
		 * @param {string} text Template text.
		 * @return {DocumentFragment} Formatted fragment.
		 */
		function renderTemplateTextFragment( text ) {
			const frag         = document.createDocumentFragment();
			const lines        = U.toStr( text || '' ).split( '\n' );
			const tokenPattern = /(\*[^*]+\*|_[^_]+_|~[^~]+~|`[^`]+`)/g;

			lines.forEach( function ( line, lineIndex ) {
				line.split( tokenPattern ).forEach( function ( token ) {
					if ( ! token ) {
						return;
					}

					let node = null;
					if ( /^\*[^*]+\*$/.test( token ) ) {
						node = U.el( 'strong', {}, token.slice( 1, -1 ) );
					} else if ( /^_[^_]+_$/.test( token ) ) {
						node = U.el( 'em', {}, token.slice( 1, -1 ) );
					} else if ( /^~[^~]+~$/.test( token ) ) {
						node = U.el( 'del', {}, token.slice( 1, -1 ) );
					} else if ( /^`[^`]+`$/.test( token ) ) {
						node = U.el( 'code', { class: 'nxtcc-template-preview-code' }, token.slice( 1, -1 ) );
					} else {
						node = document.createTextNode( token );
					}

					U.safeAppend( frag, node );
				} );

				if ( lineIndex < lines.length - 1 ) {
					U.safeAppend( frag, document.createElement( 'br' ) );
				}
			} );

			return frag;
		}

		/**
		 * Render a sent template snapshot.
		 *
		 * @param {Object} preview Normalized template preview.
		 * @return {Element} Template preview card.
		 */
		function renderTemplatePreviewEl( preview ) {
			const data    = preview && 'object' === typeof preview ? preview : {};
			const name    = U.toStr( data.template_name || '' );
			const root    = U.el(
				'div',
				{
					class: 'nxtcc-template-preview',
					title: name ? 'Template: ' + name : 'Template message'
				}
			);
			const header  = data.header && 'object' === typeof data.header ? data.header : {};
			const type    = U.toStr( header.type || '' );
			const body    = U.toStr( data.body || '' );
			const footer  = U.toStr( data.footer || '' );
			const buttons = Array.isArray( data.buttons ) ? data.buttons : [];

			if ( 'image' === type && header.media_url ) {
				U.safeAppend(
					root,
					U.el(
						'img',
						{
							class: 'nxtcc-template-preview-media',
							src: U.toStr( header.media_url ),
							alt: 'Template image'
						}
					)
				);
			} else if ( 'video' === type && header.media_url ) {
				const video = U.el( 'video', {
					class: 'nxtcc-template-preview-media',
					controls: 'controls',
					preload: 'metadata'
				} );
				video.src   = U.toStr( header.media_url );
				U.safeAppend( root, video );
			} else if ( 'document' === type ) {
				U.safeAppend(
					root,
					U.el(
						'div',
						{ class: 'nxtcc-template-preview-document' },
						U.toStr( header.filename || 'Document' )
					)
				);
			} else if ( header.text ) {
				U.safeAppend(
					root,
					U.el( 'div', { class: 'nxtcc-template-preview-header' }, U.toStr( header.text ) )
				);
			}

			const bodyWrap = U.el( 'div', { class: 'nxtcc-template-preview-body-wrap' } );
			const bodyEl   = U.el( 'div', { class: 'nxtcc-template-preview-body is-collapsed' } );
			const moreBtn  = U.el(
				'button',
				{
					type: 'button',
					class: 'nxtcc-template-preview-more',
					'aria-expanded': 'false',
					hidden: 'hidden'
				},
				'Read more...'
			);

			U.safeAppend( bodyEl, renderTemplateTextFragment( body ) );
			U.safeAppend( bodyWrap, bodyEl );
			U.safeAppend( bodyWrap, moreBtn );
			U.safeAppend( root, bodyWrap );

			moreBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				const collapsed = bodyEl.classList.toggle( 'is-collapsed' );
				moreBtn.textContent = collapsed ? 'Read more...' : 'Read less';
				moreBtn.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			} );

			window.requestAnimationFrame( function () {
				if ( bodyEl.scrollHeight <= bodyEl.clientHeight + 1 ) {
					bodyEl.classList.remove( 'is-collapsed' );
				} else {
					moreBtn.hidden = false;
				}
			} );

			if ( footer ) {
				U.safeAppend( root, U.el( 'div', { class: 'nxtcc-template-preview-footer' }, footer ) );
			}

			if ( buttons.length ) {
				const buttonWrap = U.el( 'div', { class: 'nxtcc-template-preview-buttons' } );

				buttons.forEach( function ( button ) {
					if ( ! button || ! button.text ) {
						return;
					}
					U.safeAppend(
						buttonWrap,
						U.el(
							'div',
							{ class: 'nxtcc-template-preview-button' },
							U.toStr( button.text )
						)
					);
				} );
				U.safeAppend( root, buttonWrap );
			}

			return root;
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
				line = U.toStr( rep.text || '' );
			} else if ( 'flow_response' === kind || 'interactive_reply' === kind ) {
				line = U.toStr( rep.text || rep.title || 'Interactive response' );
			} else if ( 'template_preview' === kind ) {
				line = U.toStr( rep.text || rep.template_name || 'Template message' );
			} else {
				const label = '[' + kind.charAt( 0 ).toUpperCase() + kind.slice( 1 ) + '] ';
				const meta  = U.toStr( rep.caption || rep.filename || '' );
				line        = label + meta;
			}

			const characters = Array.from( line );
			if ( characters.length > 50 ) {
				line = characters.slice( 0, 49 ).join( '' ) + '\u2026';
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
				'data-timeline-kind': 'message',
				'data-timeline-id': U.toStr( msg.id ),
				'data-timeline-time': U.toStr( msg.created_at_utc || '' ),
			} );

			if ( msg.reply ) {
				const replyEl = renderReplyQuoteEl( msg.reply );
				if ( replyEl ) {
					U.safeAppend( bubble, replyEl );
				}
			}

			if ( 'flow_response' === msg.message_kind ) {
				U.safeAppend( bubble, renderFlowResponseEl( msg.flow_response ) );
			} else if ( 'interactive_reply' === msg.message_kind ) {
				U.safeAppend( bubble, renderInteractiveReplyEl( msg.interactive_reply ) );
			} else if ( 'template_preview' === msg.message_kind ) {
				U.safeAppend( bubble, renderTemplatePreviewEl( msg.template_preview ) );
			} else {
				U.safeAppend( bubble, renderMessageFragment( msg.message_content ) );
			}

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

		function makeActivityEl( activity ) {
			const type = U.toStr( activity && activity.activity_type ? activity.activity_type : '' );
			const item = U.el( 'article', {
				class: 'nxtcc-chat-activity' + ( 'internal_note' === U.toStr( activity.item_type ) ? ' is-private-note' : '' ),
				'data-activity-id': U.toStr( activity.activity_id || '' ),
				'data-timeline-kind': 'activity',
				'data-timeline-id': U.toStr( activity.activity_id || '' ),
				'data-timeline-time': U.toStr( activity.created_at_utc || '' ),
			} );
			const line = U.el( 'div', { class: 'nxtcc-chat-activity-line' } );
			const card = U.el( 'div', { class: 'nxtcc-chat-activity-card' } );

			U.safeAppend( line, U.el( 'span', { class: 'nxtcc-chat-activity-dot', 'aria-hidden': 'true' } ) );
			U.safeAppend( item, line );
			U.safeAppend(
				card,
				U.el(
					'strong',
					{ class: 'nxtcc-chat-activity-title' },
					U.toStr( activity.activity_label || type.replace( /_/g, ' ' ) || 'Activity' )
				)
			);

			if ( activity.summary ) {
				U.safeAppend( card, U.el( 'div', { class: 'nxtcc-chat-activity-summary' }, U.toStr( activity.summary ) ) );
			}

			U.safeAppend(
				card,
				U.el(
					'div',
					{ class: 'nxtcc-chat-activity-meta' },
					[
						U.toStr( activity.actor_label || 'System' ),
						U.toStr( activity.created_at_display || '' ),
					].filter( Boolean ).join( ' | ' )
				)
			);
			U.safeAppend( item, card );
			return item;
		}

		function timelineTimestamp( value ) {
			const raw = U.toStr( value || '' ).trim();
			if ( ! raw ) {
				return 0;
			}

			const parsed = Date.parse( raw.replace( ' ', 'T' ) + ( /(?:Z|[+-]\d\d:\d\d)$/.test( raw ) ? '' : 'Z' ) );
			return Number.isFinite( parsed ) ? parsed : 0;
		}

		function compareTimelineNodes( left, right ) {
			const leftTime  = timelineTimestamp( left.getAttribute( 'data-timeline-time' ) );
			const rightTime = timelineTimestamp( right.getAttribute( 'data-timeline-time' ) );
			if ( leftTime !== rightTime ) {
				return leftTime - rightTime;
			}

			const leftKind  = U.toStr( left.getAttribute( 'data-timeline-kind' ) );
			const rightKind = U.toStr( right.getAttribute( 'data-timeline-kind' ) );
			if ( leftKind !== rightKind ) {
				return leftKind.localeCompare( rightKind );
			}

			return Number( left.getAttribute( 'data-timeline-id' ) || 0 ) - Number( right.getAttribute( 'data-timeline-id' ) || 0 );
		}

		function sortTimelineNodes( threadEl ) {
			Array.from( threadEl.querySelectorAll( '[data-timeline-kind]' ) )
				.sort( compareTimelineNodes )
				.forEach( function ( node ) {
					threadEl.appendChild( node );
				} );
		}

		function appendTimelineItems( messages, activities ) {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return 0;
			}

			let added = 0;
			( Array.isArray( messages ) ? messages : [] ).forEach( function ( msg ) {
				if ( threadEl.querySelector( '[data-timeline-kind="message"][data-timeline-id="' + String( msg.id ) + '"]' ) ) {
					return;
				}
				const bubble = makeBubbleEl( msg );
				attachBubbleData( $( bubble ), msg );
				U.safeAppend( threadEl, bubble );
				added++;
			} );
			( Array.isArray( activities ) ? activities : [] ).forEach( function ( activity ) {
				if ( threadEl.querySelector( '[data-activity-id="' + String( activity.activity_id ) + '"]' ) ) {
					return;
				}
				U.safeAppend( threadEl, makeActivityEl( activity ) );
				added++;
			} );

			if ( added ) {
				sortTimelineNodes( threadEl );
			}
			return added;
		}

		function focusRenderedActivity( activityId ) {
			const node = $chatThread.find( '[data-activity-id="' + String( activityId ) + '"]' ).get( 0 );
			if ( ! node ) {
				return false;
			}

			$chatThread.find( '.is-activity-focus' ).removeClass( 'is-activity-focus' );
			node.classList.add( 'is-activity-focus' );
			node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			window.setTimeout( function () {
				node.classList.remove( 'is-activity-focus' );
			}, 2600 );
			return true;
		}

		function focusActivity( activityId, contactId ) {
			const id        = parseInt( activityId, 10 ) || 0;
			const contact   = parseInt( contactId, 10 ) || parseInt( ctx.state.chatContactId, 10 ) || 0;
			const currentId = parseInt( ctx.state.chatContactId, 10 ) || 0;
			if ( id <= 0 || contact <= 0 ) {
				return;
			}

			if ( currentId !== contact ) {
				const $row = $chatList.find( '.nxtcc-chat-head[data-contact="' + String( contact ) + '"]' ).first();
				if ( $row.length ) {
					pendingActivityId = id;
					$row.trigger( 'click' );
				}
				return;
			}

			if ( focusRenderedActivity( id ) ) {
				return;
			}

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_focus_chat_activity',
				contact_id: contact,
				activity_id: id,
				nonce: ctx.nonce,
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success || ! resp.data ) {
					return;
				}
				appendTimelineItems( [], resp.data.items || [] );
				focusRenderedActivity( id );
			} );
		}

		function patchChatThread( messages, activities, meta ) {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return;
			}

			U.safeEmpty( threadEl );

			if ( ( ! messages || ! messages.length ) && ( ! activities || ! activities.length ) ) {
				U.setListEmptyMessage( threadEl, 'No messages or activity in this chat.', '#888' );
				updateScrollButton();
				return;
			}

			let firstUnreadFound = false;
			( messages || [] ).forEach( function ( msg ) {
				const bub = makeBubbleEl( msg );
				U.safeAppend( threadEl, bub );
				attachBubbleData( $( bub ), msg );

				if ( 'received' === msg.status && 0 === Number( msg.is_read ) && ! firstUnreadFound ) {
					$( bub ).addClass( 'nxtcc-first-unread' );
					firstUnreadFound = true;
				}
			} );
			( activities || [] ).forEach( function ( activity ) {
				U.safeAppend( threadEl, makeActivityEl( activity ) );
			} );
			sortTimelineNodes( threadEl );

			if ( messages && messages.length ) {
				ctx.state.lastMessageId   = messages[ messages.length - 1 ].id;
				ctx.state.oldestMessageId = messages[ 0 ].id;
			}
			ctx.state.lastActivityId   = Number( meta && meta.latest_activity_id ? meta.latest_activity_id : 0 ) || null;
			ctx.state.oldestActivityId = Number( meta && meta.oldest_activity_id ? meta.oldest_activity_id : 0 ) || null;
			ctx.state.hasOlderMessages   = Boolean( meta && meta.message_has_more );
			ctx.state.hasOlderActivities = Boolean( meta && meta.activity_has_more );

			setTimeout( function () {
				if ( pendingActivityId && focusRenderedActivity( pendingActivityId ) ) {
					pendingActivityId = 0;
					updateScrollButton();
					return;
				}

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
			ctx.state.lastActivityId       = null;
			ctx.state.oldestActivityId     = null;
			ctx.state.hasOlderMessages     = false;
			ctx.state.hasOlderActivities   = false;
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

		function markCurrentChatRead() {
			if ( ! ctx.state.chatContactId ) {
				return;
			}

			$.post( Chat.cfg.ajaxurl, {
				action: 'nxtcc_mark_chat_read',
				contact_id: ctx.state.chatContactId,
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
			} );
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

			const myToken   = activeThreadToken;
			const requestId = ++loadRequestId;
			const threadEl  = $chatThread.get( 0 );

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
						patchChatThread( resp.data.messages, resp.data.activities || [], resp.data );
						setComposerEnabledFromResp( resp );
						if ( ctx.api.tickets && ctx.api.tickets.renderConversation && resp.data.conversation ) {
							ctx.api.tickets.renderConversation( resp.data.conversation );
						}

						markCurrentChatRead();

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
				} )
				.always( function () {
					if ( requestId === loadRequestId ) {
						loadRequestId = 0;
					}
				} );
		}

		function reloadChatThread() {
			if ( ! ctx.state.chatContactId ) {
				return;
			}

			activeThreadToken++;
			stopChatPolling();
			resetThreadStateForContact();
			loadChatThread();

			setTimeout( function () {
				if ( ctx.state.chatContactId ) {
					startChatPolling();
				}
			}, 150 );
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
				after_activity_id: ctx.state.lastActivityId || 0,
			} )
				.done( function ( resp ) {
					if ( myToken !== activeThreadToken ) {
						return;
					}

					pollErrorCount = 0;

					const msgs =
						resp && resp.success && resp.data && resp.data.messages ? resp.data.messages : [];
					const activities =
						resp && resp.success && resp.data && resp.data.activities ? resp.data.activities : [];

					setComposerEnabledFromResp( resp );

					if ( ( ! msgs || ! msgs.length ) && ( ! activities || ! activities.length ) ) {
						return;
					}

					const threadEl = $chatThread.get( 0 );
					if ( ! threadEl ) {
						return;
					}

					const wasNear = isNearBottom( threadEl, 60 );
					appendTimelineItems( msgs, activities );
					if ( msgs.length ) {
						ctx.state.lastMessageId = msgs[ msgs.length - 1 ].id;
					}
					if ( activities.length ) {
						ctx.state.lastActivityId = Number( resp.data.latest_activity_id || activities[ activities.length - 1 ].activity_id ) || ctx.state.lastActivityId;
					}
					markCurrentChatRead();

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

		function syncSelectedContactFromInbox( contacts ) {
			if ( ! ctx.state.chatContactId || ! Array.isArray( contacts ) || ! contacts.length ) {
				return;
			}

			const selectedId = Number( ctx.state.chatContactId );
			const activeChat = contacts.find( function ( chat ) {
				return Number( chat && chat.contact_id ? chat.contact_id : 0 ) === selectedId;
			} );

			if ( ! activeChat ) {
				return;
			}

			const cc        = activeChat.country_code ? U.toStr( activeChat.country_code ) : '';
			const ph        = activeChat.phone_number ? U.toStr( activeChat.phone_number ) : '';
			const fullPhone = cc ? '+' + cc + ( ph ? ' ' + ph : '' ) : ph;
			const nameText  =
				( activeChat.name ? U.toStr( activeChat.name ) : '' ) ||
				fullPhone ||
				'Unknown';

			$chatHeader.find( '.nxtcc-chat-contact-name' ).text( nameText );
			$chatHeader.find( '.nxtcc-chat-contact-number' ).text( fullPhone );
			syncProfileButton( selectedId );
			if ( ctx.api.tickets && ctx.api.tickets.renderConversation && activeChat.conversation && ! ctx.$widget.hasClass( 'is-ticket-open' ) ) {
				ctx.api.tickets.renderConversation( activeChat.conversation );
			}

			const nextKey =
				String( selectedId ) +
				'|' +
				U.toStr( activeChat.last_msg_time || '' ) +
				'|' +
				U.toStr( activeChat.message_preview || '' ) +
				'|' +
				String( Number( activeChat.unread_count || 0 ) );

			if ( nextKey === activeInboxKey ) {
				return;
			}

			activeInboxKey = nextKey;

			if ( 0 !== loadRequestId || pollInFlight ) {
				return;
			}

			if ( ! ctx.state.lastMessageId ) {
				reloadChatThread();
				return;
			}

			if ( 'hidden' === document.visibilityState ) {
				return;
			}

			if ( ! isThreadEligibleForPolling() ) {
				pollNeedsCatchup = true;
				return;
			}

			pollChatThread();
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
			if (
				! ctx.state.chatContactId ||
				( ! ctx.state.hasOlderMessages && ! ctx.state.hasOlderActivities ) ||
				ctx.state.loadingOlderMessages
			) {
				return;
			}

			ctx.state.loadingOlderMessages = true;

			// NEW: Once user loads older messages, treat thread as paused until they return to bottom.
			threadPollPaused = true;
			pollNeedsCatchup = true;

			showThreadTopNotice( 'Loading older messages…' );

			const request = {
				action: 'nxtcc_fetch_chat_thread',
				contact_id: ctx.state.chatContactId,
				business_account_id: ctx.businessAccountId,
				phone_number_id: ctx.phoneNumberId,
				nonce: ctx.nonce,
				include_messages: ctx.state.hasOlderMessages ? 1 : 0,
				include_activities: ctx.state.hasOlderActivities ? 1 : 0,
			};

			if ( ctx.state.hasOlderMessages && ctx.state.oldestMessageId ) {
				request.before_id = ctx.state.oldestMessageId;
			}
			if ( ctx.state.hasOlderActivities && ctx.state.oldestActivityId ) {
				request.before_activity_id = ctx.state.oldestActivityId;
			}

			$.post( Chat.cfg.ajaxurl, request )
				.done( function ( resp ) {
					removeThreadTopNotice();

					const msgs =
						resp && resp.success && resp.data && resp.data.messages ? resp.data.messages : [];
					const activities =
						resp && resp.success && resp.data && resp.data.activities ? resp.data.activities : [];

					if ( resp && resp.success && resp.data ) {
						if ( ctx.state.hasOlderMessages ) {
							ctx.state.hasOlderMessages = Boolean( resp.data.message_has_more );
						}
						if ( ctx.state.hasOlderActivities ) {
							ctx.state.hasOlderActivities = Boolean( resp.data.activity_has_more );
						}
					}

					if ( msgs.length || activities.length ) {
						const threadEl = $chatThread.get( 0 );

						if ( threadEl ) {
							const scrollBefore = threadEl.scrollHeight;
							appendTimelineItems( msgs, activities );

							const scrollAfter = threadEl.scrollHeight;
							$chatThread.scrollTop( scrollAfter - scrollBefore );

							if ( msgs.length ) {
								ctx.state.oldestMessageId = msgs[ 0 ].id;
							}
							if ( activities.length ) {
								ctx.state.oldestActivityId = Number( resp.data.oldest_activity_id || activities[0].activity_id ) || ctx.state.oldestActivityId;
							}
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

		// Expose API for other modules.
		ctx.api.thread.isNearBottom        = isNearBottom;
		ctx.api.thread.loadChatThread      = loadChatThread;
		ctx.api.thread.reloadChatThread    = reloadChatThread;
		ctx.api.thread.stopChatPolling     = stopChatPolling;
		ctx.api.thread.syncSelectedContact = syncSelectedContactFromInbox;
		ctx.api.thread.focusActivity       = focusActivity;

		if ( ctx.focusActivityEventHandler ) {
			document.removeEventListener( 'nxtcc:focus-chat-activity', ctx.focusActivityEventHandler );
		}
		ctx.focusActivityEventHandler = function ( event ) {
			const detail = event && event.detail ? event.detail : {};
			focusActivity( detail.activityId, detail.contactId );
		};
		document.addEventListener( 'nxtcc:focus-chat-activity', ctx.focusActivityEventHandler );

		// Contact selection: load the thread and start polling (namespaced).
		$chatList.off( 'click' + ns, '.nxtcc-chat-head' );
		$chatList.on( 'click' + ns, '.nxtcc-chat-head', function () {
			const $row = $( this );

			$chatList.find( '.nxtcc-chat-head' ).removeClass( 'active' );
			$row.addClass( 'active' );

			const contactIdRaw = $row.attr( 'data-contact' ) || $row.data( 'contact' ) || 0;
			const contactId    = parseInt( contactIdRaw, 10 ) || 0;

			ctx.state.chatContactId = contactId;
			activeInboxKey          = '';
			syncProfileButton( contactId );

			const name  = $row.find( '.nxtcc-chat-head-name' ).text();
			const phone = U.toStr( $row.attr( 'data-phone' ) || $row.data( 'phone' ) || '' );

			$chatHeader.find( '.nxtcc-chat-contact-name' ).text( name );
			$chatHeader.find( '.nxtcc-chat-contact-number' ).text( phone );
			if ( ctx.api.tickets && ctx.api.tickets.load ) {
				ctx.api.tickets.load( contactId );
			}

			if ( ctx.api.actions && ctx.api.actions.enterChatView ) {
				ctx.api.actions.enterChatView( $row );
			}

			reloadChatThread();

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
