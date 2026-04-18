/**
 * Admin Chat Actions UI.
 *
 * Handles UI-only actions for the inbox/chat screen:
 * - Message selection toolbar (reply, forward, favorite, local delete)
 * - Reply strip (quoted reply target)
 * - Forward modal (pick recent contacts and forward selected messages)
 * - Composer controls (send text, send attachments via file input or WP media modal)
 * - Panel navigation (back to inbox) and responsive/fullscreen helpers
 * - "Scroll to latest" floating button visibility and behavior
 *
 * Notes:
 * - This module does not render the chat thread; thread loading/polling is handled
 *   by chat-thread.js. We call thread APIs through ctx.api.thread when available.
 * - DOM updates are done via safe DOM helpers to avoid HTML-injection patterns.
 *
 * @package NXTCC
 */

/* global jQuery, wp */

jQuery( function ( $ ) {
	'use strict';

	const Chat = window.NXTCCChat;
	if ( ! Chat || ! Chat.util ) {
		return;
	}
	const U = Chat.util;

	Chat.actions = Chat.actions || {};

	Chat.actions.start = function ( ctx ) {
		if ( ! ctx || ! ctx.$widget ) {
			return;
		}

		const $widget     = ctx.$widget;
		const $inboxPanel = ctx.$inboxPanel;
		const $chatPanel  = ctx.$chatPanel;
		const $chatThread = ctx.$chatThread;
		const $chatList   = ctx.$chatList;
		const $chatHeader = ctx.$chatHeader;
		const $backBtn    = ctx.$backBtn;

		if ( ! Chat.cfg || ! Chat.cfg.ajaxurl ) {
			// eslint-disable-next-line no-console.
			console.warn( '[NXTCC] Missing Chat.cfg.ajaxurl' );
			return;
		}

		// Ensure shared state/api containers exist.
		ctx.state       = ctx.state || {};
		ctx.api         = ctx.api || {};
		ctx.api.actions = ctx.api.actions || {};

		// Selection toolbar (visible when one or more bubbles are selected).
		const $actions    = $widget.find( '.nxtcc-chat-actions' );
		const $selCount   = $actions.find( '.nxtcc-selected-count' );
		const $btnReply   = $actions.find( '.nxtcc-act-reply' );
		const $btnForward = $actions.find( '.nxtcc-act-forward' );
		const $btnFav     = $actions.find( '.nxtcc-act-favorite' );
		const $favIcon    = $actions.find( '.nxtcc-act-favorite-icon' );
		const $btnDel     = $actions.find( '.nxtcc-act-delete' );
		const $btnClose   = $actions.find( '.nxtcc-act-close' ); // Optional in markup.

		// Reply strip shown above the composer when replying to a message.
		const $replyStrip   = $widget.find( '.nxtcc-reply-strip' );
		const $replyCancel  = $replyStrip.find( '.nxtcc-reply-cancel' );
		const $replySnippet = $replyStrip.find( '.nxtcc-reply-snippet' );

		// Forward modal: lists recent contacts and sends selected message IDs.
		const $fModal    = $widget.find( '.nxtcc-forward-modal' );
		const $fBackdrop = $fModal.find( '.nxtcc-forward-backdrop' );
		const $fClose    = $fModal.find( '.nxtcc-forward-close' );
		const $fSearch   = $fModal.find( '.nxtcc-forward-search' );
		const $fList     = $fModal.find( '.nxtcc-forward-list' );
		const $fEmpty    = $fModal.find( '.nxtcc-forward-empty' );
		const $fSend     = $fModal.find( '.nxtcc-forward-send' );
		const $fSelCount = $fModal.find( '.nxtcc-forward-selected-count' );

		// Composer controls: textarea, send button, upload via file input/WP media.
		const $textarea  = $widget.find( '.nxtcc-chat-textarea' );
		const $sendBtn   = $widget.find( '.nxtcc-send-msg-btn' );
		const $uploadBtn = $widget.find( '.nxtcc-upload-btn' );
		const $fileInput = $widget.find( '.nxtcc-file-input' );

		// Scroll-to-latest floating button.
		const $scrollBtn = $widget.find( '.nxtcc-scroll-bottom' );

		// Fullscreen persistence key (per-widget instance).
		const fullscreenKey = 'nxtcc_fullscreen_' + U.toStr( ctx.instanceId || '' );
		let isFullscreen    = false;

		// Selection mode state.
		const selected  = new Map(); // msgId -> { id, meta_id, preview, is_favorite }.
		let inSelection = false;

		// Reply state: used by send endpoints.
		let replyTo = null; // { meta_id, history_id, snippet }.

		// WP Media Library frame (lazy initialized).
		let mediaFrame = null;

		/**
		 * Lint-safe alert/confirm wrappers.
		 *
		 * We keep window.alert/confirm (simple admin UI), but avoid eslint no-alert errors
		 * by centralizing with a single disable.
		 */
		function uiAlert( message ) {
			// eslint-disable-next-line no-alert.
			window.alert( U.toStr( message ) );
		}

		function uiConfirm( message ) {
			// eslint-disable-next-line no-alert.
			return window.confirm( U.toStr( message ) );
		}

		function ajaxPayloadBase() {
			return {
				nonce: ctx.nonce,
				// Some endpoints still accept this; server should NOT trust it, but okay to send.
				business_account_id: ctx.businessAccountId,
				// IMPORTANT: required for tenant scoping on forwarding endpoints.
				phone_number_id: ctx.phoneNumberId,
			};
		}

		function updateActions() {
			const count = selected.size;

			if ( $selCount.length ) {
				$selCount.text( String( count ) );
			}

			if ( $btnReply.length ) {
				$btnReply.prop( 'disabled', count !== 1 );
			}
			if ( $btnForward.length ) {
				$btnForward.prop( 'disabled', count === 0 );
			}
			if ( $btnFav.length ) {
				$btnFav.prop( 'disabled', count === 0 );
			}
			if ( $btnDel.length ) {
				$btnDel.prop( 'disabled', count === 0 );
			}
		}

		function computeSelectionFavState() {
			if ( 0 === selected.size ) {
				return null;
			}

			let allFav   = true;
			let allUnfav = true;

			selected.forEach( function ( sel ) {
				const $b = $chatThread.find(
					'.nxtcc-chat-bubble[data-msg-id="' + String( sel.id ) + '"]'
				);

				const isFav = $b.attr( 'data-fav' ) === '1' || $b.data( 'fav' ) === 1;

				if ( isFav ) {
					allUnfav = false;
				} else {
					allFav = false;
				}
			} );

			return { allFav: allFav, allUnfav: allUnfav };
		}

		function refreshFavActionIcon() {
			if ( ! $favIcon.length ) {
				return;
			}

			if ( 0 === selected.size ) {
				$favIcon.text( '☆' );
				return;
			}

			const st = computeSelectionFavState();
			$favIcon.text( st && st.allFav ? '★' : '☆' );
		}

		function enterSelection() {
			inSelection = true;

			// Template may have inline style="display:none"; force visible on entry.
			$actions.css( 'display', '' ).show();

			updateActions();
			refreshFavActionIcon();
		}

		function exitSelection() {
			inSelection = false;
			selected.clear();
			$actions.hide();
			$chatThread.find( '.nxtcc-chat-bubble' ).removeClass( 'nxtcc-selected' );

			if ( $favIcon.length ) {
				$favIcon.text( '☆' );
			}

			updateActions();
		}

		function toggleSelectBubble( $bub, msg ) {
			const id = String( msg.id );

			if ( selected.has( id ) ) {
				selected.delete( id );
				$bub.removeClass( 'nxtcc-selected' );
			} else {
				selected.set( id, msg );
				$bub.addClass( 'nxtcc-selected' );
			}

			// If user unselects last message, exit selection.
			if ( 0 === selected.size ) {
				exitSelection();
				return;
			}

			updateActions();
			refreshFavActionIcon();
		}

		function isNearBottom( elNode, pad ) {
			if ( ctx.api.thread && ctx.api.thread.isNearBottom ) {
				return ctx.api.thread.isNearBottom( elNode, pad );
			}

			const padding = Number.isFinite( Number( pad ) ) ? Number( pad ) : 20;
			if ( ! elNode ) {
				return true;
			}

			return elNode.scrollHeight - ( elNode.scrollTop + elNode.clientHeight ) <= padding;
		}

		function updateScrollButton() {
			const elNode = $chatThread.get( 0 );
			if ( ! elNode ) {
				return;
			}
			if ( elNode.scrollHeight <= elNode.clientHeight ) {
				$scrollBtn.removeClass( 'show' );
				return;
			}
			$scrollBtn.toggleClass( 'show', ! isNearBottom( elNode, 20 ) );
		}

		function setComposerEnabled( enabled ) {
			const on = Boolean( enabled );

			$textarea.prop( 'disabled', ! on );
			$sendBtn.prop( 'disabled', ! on );

			if ( ! on ) {
				$sendBtn.css( { opacity: 0.5, cursor: 'not-allowed' } );
			} else {
				$sendBtn.css( { opacity: '', cursor: '' } );
			}
		}

		// Small public API used by other modules.
		ctx.api.actions.updateScrollButton = updateScrollButton;
		ctx.api.actions.setComposerEnabled = setComposerEnabled;

		function loadChatThread() {
			if ( ctx.api.thread && ctx.api.thread.loadChatThread ) {
				ctx.api.thread.loadChatThread();
			}
		}

		function reloadChatThread() {
			if ( ctx.api.thread && ctx.api.thread.reloadChatThread ) {
				ctx.api.thread.reloadChatThread();
				return;
			}

			loadChatThread();
		}

		function stopChatPolling() {
			if ( ctx.api.thread && ctx.api.thread.stopChatPolling ) {
				ctx.api.thread.stopChatPolling();
			}
		}

		/* ========================= COMPOSER ========================= */

		function sendMessage() {
			const message = U.toStr( $textarea.val() ).trim();

			if ( ! message || ! ctx.state.chatContactId ) {
				return;
			}

			$sendBtn.prop( 'disabled', true );

			$.post(
				Chat.cfg.ajaxurl,
				{
					action: 'nxtcc_send_message',
					nonce: ctx.nonce,
					business_account_id: ctx.businessAccountId,
					phone_number_id: ctx.phoneNumberId,
					contact_id: ctx.state.chatContactId,
					message_content: message,
					reply_to_message_id: replyTo ? U.toStr( replyTo.meta_id ) : '',
					reply_to_history_id: replyTo ? Number( replyTo.history_id || 0 ) : 0,
				},
				function ( resp ) {
					$sendBtn.prop( 'disabled', false );

					if ( resp && resp.success ) {
						$textarea.val( '' );
						if ( replyTo ) {
							replyTo = null;
							$replyStrip.hide();
						}
						reloadChatThread();
						return;
					}

					const msg =
						resp && resp.data && resp.data.message
							? resp.data.message
							: 'Failed to send message.';
					uiAlert( msg );
					// eslint-disable-next-line no-console.
					console.warn( '[NXTCC] Message send error:', resp );
				}
			).fail( function ( xhr, status, error ) {
				$sendBtn.prop( 'disabled', false );
				uiAlert( 'AJAX error: ' + error );
				// eslint-disable-next-line no-console.
				console.error( '[NXTCC] AJAX failure:', error, xhr && xhr.responseText );
			} );
		}

		function sendMediaFile( file, caption ) {
			if ( ! file || ! ctx.state.chatContactId ) {
				return;
			}

			const fd = new FormData();
			U.setFormField( fd, 'action', 'nxtcc_send_media' );
			U.setFormField( fd, 'nonce', ctx.nonce );
			U.setFormField( fd, 'business_account_id', ctx.businessAccountId );
			U.setFormField( fd, 'phone_number_id', ctx.phoneNumberId );
			U.setFormField( fd, 'contact_id', String( ctx.state.chatContactId ) );
			U.setFormField( fd, 'caption', caption || '' );
			U.setFormField( fd, 'reply_to_message_id', replyTo ? U.toStr( replyTo.meta_id ) : '' );
			U.setFormField(
				fd,
				'reply_to_history_id',
				replyTo ? String( replyTo.history_id || 0 ) : '0'
			);
			U.setFormField( fd, 'file', file, file.name );

			$sendBtn.prop( 'disabled', true );

			$.ajax( {
				url: Chat.cfg.ajaxurl,
				method: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				success: function ( resp ) {
					$sendBtn.prop( 'disabled', false );

					if ( resp && resp.success ) {
						if ( replyTo ) {
							replyTo = null;
							$replyStrip.hide();
						}
						reloadChatThread();
						return;
					}

					const msg =
						resp && resp.data && resp.data.message
							? resp.data.message
							: 'Failed to send media.';

					let detail = '';
					if ( resp && resp.data ) {
						detail = U.toStr( resp.data.error || '' );
					}

					uiAlert( detail ? msg + '\n' + detail : msg );
					// eslint-disable-next-line no-console.
					console.warn( '[NXTCC] Media send error:', resp );
				},
				error: function ( xhr, status, err ) {
					$sendBtn.prop( 'disabled', false );
					uiAlert( 'AJAX error: ' + err );
				},
			} );
		}

		function sendMediaByUrl( url, mime, filename, caption ) {
			if ( ! url || ! ctx.state.chatContactId ) {
				return;
			}

			$sendBtn.prop( 'disabled', true );

			let kind = 'document';

			if ( mime && mime.indexOf( '/' ) > -1 ) {
				const head = mime.split( '/' )[ 0 ];
				if ( 'image' === head ) {
					kind = 'image';
				} else if ( 'video' === head ) {
					kind = 'video';
				} else if ( 'audio' === head ) {
					kind = 'audio';
				}
			}

			$.post(
				Chat.cfg.ajaxurl,
				{
					action: 'nxtcc_send_media_by_url',
					nonce: ctx.nonce,
					business_account_id: ctx.businessAccountId,
					phone_number_id: ctx.phoneNumberId,
					contact_id: ctx.state.chatContactId,
					kind: kind,
					media_url: url,
					filename: filename || '',
					caption: caption || '',
					reply_to_message_id: replyTo ? U.toStr( replyTo.meta_id ) : '',
					reply_to_history_id: replyTo ? Number( replyTo.history_id || 0 ) : 0,
				},
				function ( resp ) {
					$sendBtn.prop( 'disabled', false );

					if ( resp && resp.success ) {
						$textarea.val( '' );
						if ( replyTo ) {
							replyTo = null;
							$replyStrip.hide();
						}
						reloadChatThread();
						return;
					}

					const msg =
						resp && resp.data && resp.data.message
							? resp.data.message
							: 'Failed to send media.';

					let detail = '';
					if ( resp && resp.data ) {
						detail = U.toStr( resp.data.error || '' );
					}

					uiAlert( detail ? msg + '\n' + detail : msg );
					// eslint-disable-next-line no-console.
					console.warn( '[NXTCC] send_media_by_url error:', resp );
				}
			).fail( function ( xhr, status, err ) {
				$sendBtn.prop( 'disabled', false );
				uiAlert( 'AJAX error: ' + err );
			} );
		}

		$sendBtn.off( 'click.nxtccSend' ).on( 'click.nxtccSend', sendMessage );

		$textarea.off( 'keypress.nxtccSend' ).on( 'keypress.nxtccSend', function ( e ) {
			if ( 13 === e.which && ! e.shiftKey ) {
				e.preventDefault();
				sendMessage();
			}
		} );

		$uploadBtn.off( 'click.nxtccUpload' ).on( 'click.nxtccUpload', function ( e ) {
			e.preventDefault();

			if ( window.wp && wp.media && 'function' === typeof wp.media ) {
				if ( ! mediaFrame ) {
					mediaFrame = wp.media( {
						title: 'Select media to send',
						multiple: true,
						library: { type: [ 'image', 'video', 'audio', 'application' ] },
					} );

					mediaFrame.on( 'select', function () {
						const selection = mediaFrame.state().get( 'selection' );
						const items     = selection ? selection.toJSON() : [];
						const len       = items.length;

						if ( ! len ) {
							return;
						}

						const baseCaption = U.toStr( $textarea.val() ).trim();

						( async function () {
							for ( let i = 0; i < len; i++ ) {
								const att   = items[ i ] || {};
								const url   = att.url || '';
								const mime  = att.mime || att.mime_type || '';
								const fname = att.filename || att.title || '';
								const cap   = 0 === i ? baseCaption : '';

								// eslint-disable-next-line no-await-in-loop.
								await new Promise( function ( resolve ) {
									sendMediaByUrl( url, mime, fname, cap );
									setTimeout( resolve, 300 );
								} );
							}
						} )();
					} );
				}

				mediaFrame.open();
				return;
			}

			$fileInput.trigger( 'click' );
		} );

		$fileInput.off( 'change.nxtccUpload' ).on( 'change.nxtccUpload', function () {
			const files = Array.from( this.files || [] );
			const len   = files.length;

			if ( ! len ) {
				return;
			}

			const baseCaption = U.toStr( $textarea.val() ).trim();

			( async function () {
				for ( let i = 0; i < len; i++ ) {
					const cap = 0 === i ? baseCaption : '';

					// eslint-disable-next-line no-await-in-loop.
					await new Promise( function ( resolve ) {
						sendMediaFile( files[ i ], cap );
						setTimeout( resolve, 400 );
					} );
				}

				$textarea.val( '' );
				$fileInput.val( '' );
			} )();
		} );

		/* ========================= SELECTION ========================= */

		$chatThread
			.off( 'click.nxtccSelect' )
			.on( 'click.nxtccSelect', '.nxtcc-chat-bubble', function () {
				const $b  = $( this );
				const raw = $b.data( 'raw' );

				let preview = '';

				if ( 'string' === typeof raw ) {
					preview = $b
						.clone()
						.find( '.nxtcc-msg-meta,.nxtcc-check,.nxtcc-reply-quote' )
						.remove()
						.end()
						.text()
						.trim();
				} else if ( raw && 'object' === typeof raw ) {
					preview = raw.caption || raw.text || raw.filename || '(media)';
				}

				const msg = {
					id: parseInt( $b.attr( 'data-msg-id' ), 10 ),
					meta_id: $b.data( 'metaId' ) || $b.attr( 'data-meta-id' ) || '',
					preview: U.truncatePreview( preview || '', 120 ),
					is_favorite: $b.attr( 'data-fav' ) === '1' || $b.data( 'fav' ) === 1,
				};

				if ( ! msg.id ) {
					return;
				}

				if ( ! inSelection ) {
					enterSelection();
				}

				toggleSelectBubble( $b, msg );
			} );

		if ( $btnClose.length ) {
			$btnClose.off( 'click.nxtccClose' ).on( 'click.nxtccClose', exitSelection );
		}

		$btnReply.off( 'click.nxtccReply' ).on( 'click.nxtccReply', function () {
			if ( selected.size !== 1 ) {
				return;
			}

			const msg = Array.from( selected.values() )[ 0 ];

			replyTo = {
				meta_id: msg.meta_id || '',
				history_id: msg.id || 0,
				snippet: msg.preview || '',
			};

			$replySnippet.text( replyTo.snippet || '(media)' );
			$replyStrip.show();

			exitSelection();
			$textarea.trigger( 'focus' );
		} );

		$replyCancel.off( 'click.nxtccReplyCancel' ).on( 'click.nxtccReplyCancel', function () {
			replyTo = null;
			$replyStrip.hide();
		} );

		/* ========================= FORWARD MODAL ========================= */

		function openForwardModal() {
			$fModal.show();
			$fSend.prop( 'disabled', true );
			$fSelCount.text( '0 selected' );
			loadForwardTargets( '' );
		}

		function closeForwardModal() {
			$fModal.hide();
			$fList.empty();
			$fSearch.val( '' );
		}

		$fBackdrop.off( 'click.nxtccFwdClose' ).on( 'click.nxtccFwdClose', closeForwardModal );
		$fClose.off( 'click.nxtccFwdClose' ).on( 'click.nxtccFwdClose', closeForwardModal );

		$btnForward.off( 'click.nxtccForward' ).on( 'click.nxtccForward', function () {
			if ( selected.size === 0 ) {
				return;
			}
			openForwardModal();
		} );

		$fSearch.off( 'input.nxtccFwdSearch' ).on( 'input.nxtccFwdSearch', function () {
			loadForwardTargets( U.toStr( $( this ).val() ).trim() );
		} );

		function buildForwardRow( r ) {
			const row = U.el( 'div', {
				class: 'nxtcc-forward-row',
				'data-contact': U.toStr( r.contact_id ),
			} );

			const left  = U.el( 'div' );
			const label =
				r.name ||
				( ( r.country_code ? '+' + r.country_code + ' ' : '' ) + r.phone_number );

			U.safeAppend( left, U.el( 'div', null, label ) );

			if ( r.last_inbound_local ) {
				U.safeAppend(
					left,
					U.el( 'div', { class: 'meta' }, 'Last received: ' + r.last_inbound_local )
				);
			}

			const right = U.el( 'div' );
			U.safeAppend( right, U.el( 'input', { type: 'checkbox', class: 'nxtcc-forward-pick' } ) );

			U.safeAppend( row, left );
			U.safeAppend( row, right );

			return row;
		}

		function updateForwardSelection() {
			const count = $fList.find( '.nxtcc-forward-pick:checked' ).length;
			$fSelCount.text( String( count ) + ' selected' );
			$fSend.prop( 'disabled', count === 0 );
		}

		$fList
			.off( 'change.nxtccFwdPick' )
			.on( 'change.nxtccFwdPick', '.nxtcc-forward-pick', updateForwardSelection );

		function loadForwardTargets( q ) {
			$.post(
				Chat.cfg.ajaxurl,
				$.extend(
					{
						action: 'nxtcc_list_forward_targets',
						q: q || '',
						page: 1,
						per: 25,
					},
					ajaxPayloadBase()
				),
				function ( resp ) {
					const listEl = $fList.get( 0 );
					if ( ! listEl ) {
						return;
					}

					U.safeEmpty( listEl );

					if ( ! resp || ! resp.success ) {
						const msg       = U.el( 'div', null, 'Failed to load contacts.' );
						msg.style.color = '#d33';
						U.safeAppend( listEl, msg );
						$fEmpty.hide();
						return;
					}

					const rows = resp.data && resp.data.rows ? resp.data.rows : [];
					const len  = rows.length;

					if ( ! len ) {
						$fEmpty.show();
						$fSend.prop( 'disabled', true );
						$fSelCount.text( '0 selected' );
						return;
					}

					$fEmpty.hide();

					const frag = document.createDocumentFragment();
					for ( let i = 0; i < len; i++ ) {
						U.safeAppend( frag, buildForwardRow( rows[ i ] ) );
					}
					U.safeAppend( listEl, frag );

					updateForwardSelection();
				}
			);
		}

		$fSend.off( 'click.nxtccFwdSend' ).on( 'click.nxtccFwdSend', function () {
			const contactIds = $fList
				.find( '.nxtcc-forward-pick:checked' )
				.map( function () {
					return parseInt(
						$( this ).closest( '.nxtcc-forward-row' ).attr( 'data-contact' ),
						10
					);
				} )
				.get()
				.filter( function ( v ) {
					return v && Number.isFinite( v );
				} );

			if ( ! contactIds.length ) {
				return;
			}

			const messageIds = Array.from( selected.keys() )
				.map( function ( id ) {
					return parseInt( id, 10 );
				} )
				.filter( function ( v ) {
					return v && Number.isFinite( v );
				} );

			if ( ! messageIds.length ) {
				return;
			}

			$fSend.prop( 'disabled', true );

			$.post(
				Chat.cfg.ajaxurl,
				$.extend(
					{
						action: 'nxtcc_forward_messages',
						contact_ids: contactIds,
						message_ids: messageIds,
					},
					ajaxPayloadBase()
				),
				function ( resp ) {
					$fSend.prop( 'disabled', false );

					if ( resp && resp.success ) {
						closeForwardModal();
						exitSelection();
						if ( ctx.state.chatContactId ) {
							reloadChatThread();
						}
						uiAlert( 'Forwarded.' );
						return;
					}

					const msg =
						resp && resp.data && resp.data.message
							? resp.data.message
							: 'Forward failed.';
					uiAlert( msg );
					// eslint-disable-next-line no-console.
					console.warn( '[NXTCC] Forward failed', resp );
				}
			).fail( function ( xhr, status, err ) {
				$fSend.prop( 'disabled', false );
				uiAlert( 'AJAX error: ' + err );
			} );
		} );

		/* ========================= BULK FAVORITE / DELETE ========================= */

		$btnFav.off( 'click.nxtccFav' ).on( 'click.nxtccFav', function () {
			if ( selected.size === 0 ) {
				return;
			}

			const ids = Array.from( selected.keys() )
				.map( function ( id ) {
					return parseInt( id, 10 );
				} )
				.filter( function ( v ) {
					return v && Number.isFinite( v );
				} );

			if ( ! ids.length ) {
				return;
			}

			function setBubbleFavorite( $bub, isFav ) {
				$bub.data( 'fav', isFav ? 1 : 0 ).attr( 'data-fav', isFav ? '1' : '0' );

				const metaEl = $bub.find( '.nxtcc-msg-meta' ).get( 0 );
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
						U.safeInsertAtStart( metaEl, star );
					}
					return;
				}

				if ( existing ) {
					metaEl.removeChild( existing );
				}
			}

			$.post(
				Chat.cfg.ajaxurl,
				$.extend(
					{
						action: 'nxtcc_chat_toggle_favorite',
						ids: ids,
					},
					ajaxPayloadBase()
				),
				function ( resp ) {
					if ( ! resp || ! resp.success ) {
						const msg =
							resp && resp.data && resp.data.message
								? resp.data.message
								: 'Failed to toggle favorite.';
						uiAlert( msg );
						return;
					}

					selected.forEach( function ( sel ) {
						const $b = $chatThread.find(
							'.nxtcc-chat-bubble[data-msg-id="' + String( sel.id ) + '"]'
						);

						const current = $b.attr( 'data-fav' ) === '1' || $b.data( 'fav' ) === 1;

						setBubbleFavorite( $b, ! current );
						sel.is_favorite = ! current;
					} );

					refreshFavActionIcon();
				}
			);
		} );

		$btnDel.off( 'click.nxtccDel' ).on( 'click.nxtccDel', function () {
			if ( selected.size === 0 ) {
				return;
			}

			if ( ! uiConfirm( 'Delete selected messages from this view? (Local soft delete)' ) ) {
				return;
			}

			const ids = Array.from( selected.keys() )
				.map( function ( id ) {
					return parseInt( id, 10 );
				} )
				.filter( function ( v ) {
					return v && Number.isFinite( v );
				} );

			if ( ! ids.length ) {
				return;
			}

			$.post(
				Chat.cfg.ajaxurl,
				$.extend(
					{
						action: 'nxtcc_chat_soft_delete',
						ids: ids,
					},
					ajaxPayloadBase()
				),
				function ( resp ) {
					if ( resp && resp.success ) {
						ids.forEach( function ( id ) {
							$chatThread
								.find( '.nxtcc-chat-bubble[data-msg-id="' + String( id ) + '"]' )
								.remove();
						} );
						exitSelection();
						updateScrollButton();
						return;
					}

					const msg =
						resp && resp.data && resp.data.message
							? resp.data.message
							: 'Failed to delete.';
					uiAlert( msg );
				}
			);
		} );

		/* ========================= NAVIGATION ========================= */

		function enterChatView( $clickedRow ) {
			if ( $clickedRow && $clickedRow.length ) {
				$chatList.find( '.nxtcc-chat-head' ).removeClass( 'active' );
				$clickedRow.addClass( 'active' );

				$clickedRow.find( '.nxtcc-chat-head-unread' ).fadeOut( 200, function () {
					$( this ).remove();
				} );
			}

			$inboxPanel.addClass( 'hide' );
			$chatPanel.addClass( 'active' );
			$widget.find( '.nxtcc-chat-input-wrapper' ).show();

			exitSelection();
			$replyStrip.hide();
			replyTo = null;

			setTimeout( updateScrollButton, 100 );
		}

		ctx.api.actions.enterChatView = enterChatView;

		$backBtn.off( 'click.nxtccBack' ).on( 'click.nxtccBack', function () {
			$inboxPanel.removeClass( 'hide' );
			$chatPanel.removeClass( 'active' );
			ctx.state.chatContactId = null;
			stopChatPolling();
			$widget.find( '.nxtcc-chat-input-wrapper' ).hide();
			exitSelection();
			$replyStrip.hide();
			replyTo = null;
			updateScrollButton();
		} );

		/* ========================= ESCAPE / SCROLL ========================= */

		const selNS = '.nxtccSel' + U.toStr( ctx.instanceId || '' );

		function handleOutsideClick( e ) {
			if ( ! inSelection ) {
				return;
			}
			if ( $fModal.is( ':visible' ) ) {
				return;
			}

			const $t            = $( e.target );
			const clickedInside =
				$t.closest( '.nxtcc-chat-bubble' ).length ||
				$t.closest( '.nxtcc-chat-actions' ).length;

			if ( ! clickedInside ) {
				exitSelection();
			}
		}

		function handleKeydown( e ) {
			if ( inSelection && ( 'Escape' === e.key || 'Esc' === e.key || 27 === e.which ) ) {
				exitSelection();
			}
		}

		$( document )
			.off( 'mousedown' + selNS )
			.off( 'keydown' + selNS )
			.on( 'mousedown' + selNS, handleOutsideClick )
			.on( 'keydown' + selNS, handleKeydown );

		$scrollBtn.off( 'click.nxtccScroll' ).on( 'click.nxtccScroll', function () {
			const threadEl = $chatThread.get( 0 );
			if ( ! threadEl ) {
				return;
			}
			threadEl.scrollTo( { top: threadEl.scrollHeight, behavior: 'smooth' } );
			setTimeout( updateScrollButton, 250 );
		} );

		/* ========================= FULLSCREEN ========================= */

		function toggleFullscreen( forceState ) {
			if ( 'boolean' === typeof forceState ) {
				isFullscreen = forceState;
			} else {
				isFullscreen = ! isFullscreen;
			}

			$widget.toggleClass( 'nxtcc-fullscreen', isFullscreen );

			try {
				if ( window.localStorage ) {
					window.localStorage.setItem( fullscreenKey, isFullscreen ? '1' : '0' );
				}
			} catch ( e ) {
				// Ignore storage errors.
			}

			setTimeout( updateScrollButton, 100 );
		}

		$widget
			.find( '.nxtcc-inbox-header, .nxtcc-chat-header' )
			.off( 'dblclick.nxtccFull' )
			.on( 'dblclick.nxtccFull', function ( e ) {
				e.preventDefault();
				toggleFullscreen();
			} );

		$( document )
			.off( 'keydown.nxtccFull' + U.toStr( ctx.instanceId || '' ) )
			.on( 'keydown.nxtccFull' + U.toStr( ctx.instanceId || '' ), function ( e ) {
				if ( ! isFullscreen ) {
					return;
				}
				if ( 'Escape' === e.key || 'Esc' === e.key || 27 === e.which ) {
					toggleFullscreen( false );
				}
			} );

		try {
			if ( window.localStorage && '1' === window.localStorage.getItem( fullscreenKey ) ) {
				toggleFullscreen( true );
			}
		} catch ( e ) {
			// Ignore.
		}

		/* ========================= RESPONSIVE ========================= */

		function checkResponsive() {
			if ( window.innerWidth > 750 ) {
				$inboxPanel.removeClass( 'hide' );
				$chatPanel.addClass( 'active' );
			}
		}
		$( window )
			.off( 'resize.nxtccResp' + U.toStr( ctx.instanceId || '' ) )
			.on( 'resize.nxtccResp' + U.toStr( ctx.instanceId || '' ), checkResponsive );
		checkResponsive();

		// Ensure the action bar starts hidden (template may also hide it inline).
		$actions.hide();
		updateActions();
		updateScrollButton();
	};
} );
