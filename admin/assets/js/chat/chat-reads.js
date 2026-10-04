/**
 * Incoming-message observations and first-view receipt popover.
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
	const labels = Object.assign( {
		viewedBy: 'Viewed by', you: 'You', close: 'Close', loading: 'Loading...',
		empty: 'No views yet.', error: 'Could not load viewers.', more: 'Load more', retry: 'Retry',
	}, ( window.NXTCC_ReceivedMessages || {} ).viewLabels || {} );
	Chat.reads = {
		start: function ( ctx ) {
			const root = ctx.$chatThread.get( 0 );
			if ( ! root ) {
				return;
			}
			if ( ctx.api.reads && ctx.api.reads.destroy ) { ctx.api.reads.destroy(); }
			let generation = 0;
			let recording = false;
			let refreshing = false;
			let nextRefresh = 0;
			let retryAt = 0;
			let popup = null;
			let popupSerial = 0;
			const entries = new Map();
			const candidates = new Set();
			const observer = window.IntersectionObserver ? new window.IntersectionObserver( function ( changes ) {
				changes.forEach( function ( change ) {
					if ( change.isIntersecting ) {
						candidates.add( change.target );
					} else {
						candidates.delete( change.target );
						const entry = entries.get( Number( change.target.dataset.msgId ) );
						if ( entry ) { entry.since = 0; }
					}
				} );
			}, { root: root, threshold: [ 0, 0.5, 1 ] } ) : null;

			function active() {
				return Boolean( ctx.state.chatContactId ) && ! document.hidden && document.hasFocus() && root.isConnected;
			}

			function visible( bubble ) {
				if ( ! active() || ! bubble.isConnected || ! root.contains( bubble ) ) { return false; }
				const box = bubble.getBoundingClientRect();
				const frame = root.getBoundingClientRect();
				const left = Math.max( box.left, frame.left, 0 );
				const right = Math.min( box.right, frame.right, window.innerWidth );
				const top = Math.max( box.top, frame.top, 0 );
				const bottom = Math.min( box.bottom, frame.bottom, window.innerHeight );
				if ( box.height <= 0 || box.width <= 0 || bottom - top < Math.min( 80, box.height * 0.5 ) || right - left < box.width * 0.5 ) { return false; }
				const hit = document.elementFromPoint( ( left + right ) / 2, ( top + bottom ) / 2 );
				return Boolean( hit && bubble.contains( hit ) );
			}

			function request( mode, data ) {
				return $.post( Chat.cfg.ajaxurl, Object.assign( {
					action: 'nxtcc_message_views', mode: mode, contact_id: ctx.state.chatContactId,
					business_account_id: ctx.businessAccountId, phone_number_id: ctx.phoneNumberId, nonce: ctx.nonce,
				}, data ) );
			}

			function apply( summaries ) {
				Object.keys( summaries || {} ).forEach( function ( id ) {
					const entry = entries.get( Number( id ) );
					if ( ! entry ) { return; }
					const summary = summaries[ id ];
					entry.seen = entry.seen || Boolean( summary.viewed_by_me );
					entry.readSynced = entry.readSynced || Number( summary.is_read ) === 1;
					const count = Math.max( Number( entry.count.textContent ) || 0, Number( summary.view_count ) || 0 );
					entry.button.hidden = count === 0;
					entry.count.textContent = String( count );
					entry.button.title = labels.viewedBy + ' ' + count;
					entry.button.setAttribute( 'aria-label', entry.button.title );
				} );
			}

			function closePopup( restoreFocus ) {
				if ( ! popup ) { return; }
				const button = popup.button;
				popup.el.remove();
				button.setAttribute( 'aria-expanded', 'false' );
				button.removeAttribute( 'aria-controls' );
				popup = null;
				if ( restoreFocus && button.isConnected ) { button.focus(); }
			}

			function positionPopup() {
				if ( ! popup ) { return; }
				if ( ! popup.button.isConnected ) { closePopup( false ); return; }
				const anchor = popup.button.getBoundingClientRect();
				const frame = root.getBoundingClientRect();
				if ( anchor.bottom <= Math.max( 0, frame.top ) || anchor.top >= Math.min( window.innerHeight, frame.bottom ) ) { closePopup( false ); return; }
				const box = popup.el.getBoundingClientRect();
				popup.el.style.left = Math.max( 12, Math.min( anchor.left, window.innerWidth - box.width - 12 ) ) + 'px';
				popup.el.style.top = Math.max( 12, Math.min( anchor.bottom + 6, window.innerHeight - box.height - 12 ) ) + 'px';
			}

			function openPopup( entry ) {
				if ( popup && popup.button === entry.button ) { closePopup( true ); return; }
				closePopup( false );
				const id = 'nxtcc-viewers-' + String( ctx.instanceId || 'chat' ) + '-' + String( ++popupSerial );
				const el = U.el( 'section', { id: id, class: 'nxtcc-message-viewers', role: 'dialog', 'aria-labelledby': id + '-title' } );
				const header = U.el( 'div', { class: 'nxtcc-message-viewers-header' } );
				const heading = U.el( 'strong', { id: id + '-title' }, labels.viewedBy );
				const close = U.el( 'button', { type: 'button', class: 'nxtcc-viewers-close', title: labels.close, 'aria-label': labels.close } );
				U.safeAppend( close, U.el( 'span', { class: 'fa-solid fa-xmark', 'aria-hidden': 'true' } ) );
				const list = U.el( 'div', { class: 'nxtcc-message-viewers-list', 'aria-live': 'polite' } );
				const state = U.el( 'div', { class: 'nxtcc-message-viewers-state', role: 'status' } );
				const more = U.el( 'button', { type: 'button', class: 'nxtcc-viewers-more' }, labels.more );
				more.hidden = true;
				U.safeAppend( header, heading ); U.safeAppend( header, close );
				U.safeAppend( el, header ); U.safeAppend( el, list ); U.safeAppend( el, state ); U.safeAppend( el, more );
				U.safeAppend( document.body, el );
				popup = { el: el, button: entry.button };
				entry.button.setAttribute( 'aria-expanded', 'true' );
				entry.button.setAttribute( 'aria-controls', id );
				close.addEventListener( 'click', function () { closePopup( true ); } );
				let offset = 0;
				let loading = false;
				const token = generation;
				function load() {
					if ( loading ) { return; }
					loading = true;
					state.textContent = labels.loading;
					more.disabled = true;
					request( 'viewers', { message_id: entry.id, offset: offset } ).done( function ( response ) {
						if ( token !== generation || ! popup || popup.el !== el ) { return; }
						if ( ! response || ! response.success ) { failed(); return; }
						const data = response.data;
						( data.viewers || [] ).forEach( function ( viewer ) {
							const row = U.el( 'div', { class: 'nxtcc-message-viewer' } );
							if ( viewer.avatar_url && /^https?:\/\//i.test( viewer.avatar_url ) ) {
								U.safeAppend( row, U.el( 'img', { src: viewer.avatar_url, alt: '', width: '32', height: '32', loading: 'lazy', referrerpolicy: 'no-referrer' } ) );
							} else {
								U.safeAppend( row, U.el( 'span', { class: 'nxtcc-viewer-initial', 'aria-hidden': 'true' }, String( viewer.name || '?' ).slice( 0, 1 ) ) );
							}
							const details = U.el( 'div', { class: 'nxtcc-message-viewer-details' } );
							U.safeAppend( details, U.el( 'strong', {}, viewer.is_me ? labels.you : String( viewer.name || '' ) ) );
							U.safeAppend( details, U.el( 'time', { datetime: String( viewer.first_view_at || '' ).replace( ' ', 'T' ) + 'Z' }, String( viewer.viewed_at || '' ) ) );
							U.safeAppend( row, details ); U.safeAppend( list, row );
						} );
						offset = Number( data.next_offset ) || offset;
						state.textContent = offset ? '' : labels.empty;
						more.textContent = labels.more;
						more.hidden = ! data.has_more;
						positionPopup();
					} ).fail( failed ).always( function () { loading = false; more.disabled = false; } );
				}
				function failed() {
					if ( token !== generation || ! popup || popup.el !== el ) { return; }
					state.textContent = labels.error;
					more.textContent = labels.retry;
					more.hidden = false;
					positionPopup();
				}
				more.addEventListener( 'click', load );
				positionPopup(); close.focus(); load();
			}

			function decorate( bubble, msg, meta ) {
				if ( msg.status !== 'received' ) { return; }
				const button = U.el( 'button', { type: 'button', class: 'nxtcc-message-views', 'aria-expanded': 'false' } );
				U.safeAppend( button, U.el( 'span', { class: 'fa-regular fa-eye', 'aria-hidden': 'true' } ) );
				const count = U.el( 'span', { class: 'nxtcc-message-views-count' } );
				U.safeAppend( button, count ); U.safeAppend( meta, button );
				const entry = { id: Number( msg.id ), bubble: bubble, button: button, count: count, seen: Boolean( msg.viewed_by_me ), readSynced: Number( msg.is_read ) === 1, since: 0, observed: false };
				entries.set( entry.id, entry );
				button.addEventListener( 'click', function ( event ) { event.stopPropagation(); openPopup( entry ); } );
				apply( { [ entry.id ]: { view_count: msg.view_count || 0, viewed_by_me: entry.seen } } );
			}

			function scan() {
				entries.forEach( function ( entry, id ) {
					if ( ! root.contains( entry.bubble ) ) {
						if ( entry.observed ) {
							if ( observer ) { observer.unobserve( entry.bubble ); }
							candidates.delete( entry.bubble ); entries.delete( id );
						}
						return;
					}
					if ( ! entry.observed ) {
						entry.observed = true;
						if ( observer ) { observer.observe( entry.bubble ); } else { candidates.add( entry.bubble ); }
					}
				} );
			}

			function tick() {
				if ( ! root.isConnected ) { destroy(); return; }
				if ( ! active() ) { blur(); return; }
				scan();
				const now = Date.now();
				const shown = [];
				const ready = [];
				entries.forEach( function ( entry ) {
					if ( ! candidates.has( entry.bubble ) || ! visible( entry.bubble ) ) { entry.since = 0; return; }
					shown.push( entry.id );
					if ( entry.seen && entry.readSynced ) { return; }
					if ( ! entry.since ) { entry.since = now; }
					if ( now - entry.since >= 1000 ) { ready.push( entry.id ); }
				} );
				const token = generation;
				if ( ready.length && ! recording && now >= retryAt ) {
					recording = true;
					request( 'record', { message_ids: ready.slice( 0, 100 ) } ).done( function ( response ) {
						if ( token === generation && response && response.success ) {
							apply( response.data );
							if ( ctx.api.inbox && ctx.api.inbox.refresh ) { ctx.api.inbox.refresh(); }
						}
					} ).always( function () {
						if ( token === generation ) { recording = false; retryAt = Date.now() + 5000; }
					} );
				}
				if ( shown.length && ! recording && ! refreshing && now >= nextRefresh ) {
					refreshing = true;
					nextRefresh = now + 15000;
					request( 'counts', { message_ids: shown.slice( 0, 100 ) } ).done( function ( response ) {
						if ( token === generation && response && response.success ) { apply( response.data ); }
					} ).always( function () { if ( token === generation ) { refreshing = false; } } );
				}
			}

			function reset() {
				generation++;
				if ( observer ) { observer.disconnect(); }
				entries.clear(); candidates.clear(); closePopup( false );
				recording = false; refreshing = false; nextRefresh = 0; retryAt = 0;
			}
			function blur() { entries.forEach( function ( entry ) { entry.since = 0; } ); }
			function outside( event ) {
				if ( popup && ! popup.el.contains( event.target ) && ! popup.button.contains( event.target ) ) { closePopup( false ); }
			}
			function keydown( event ) {
				if ( event.key === 'Escape' && popup ) { event.preventDefault(); closePopup( true ); return; }
				if ( event.key === 'Tab' && popup && popup.el.contains( document.activeElement ) ) {
					const buttons = Array.from( popup.el.querySelectorAll( 'button:not([disabled]):not([hidden])' ) );
					const first = buttons[ 0 ];
					const last = buttons[ buttons.length - 1 ];
					if ( event.shiftKey && document.activeElement === first ) { event.preventDefault(); last.focus(); }
					if ( ! event.shiftKey && document.activeElement === last ) { event.preventDefault(); first.focus(); }
				}
			}
			function destroy() {
				reset(); window.clearInterval( interval );
				window.removeEventListener( 'blur', blur ); window.removeEventListener( 'resize', positionPopup );
				window.removeEventListener( 'scroll', positionPopup );
				document.removeEventListener( 'visibilitychange', blur ); document.removeEventListener( 'pointerdown', outside );
				document.removeEventListener( 'keydown', keydown ); root.removeEventListener( 'scroll', positionPopup );
			}
			window.addEventListener( 'blur', blur ); window.addEventListener( 'resize', positionPopup );
			window.addEventListener( 'scroll', positionPopup, { passive: true } );
			document.addEventListener( 'visibilitychange', blur ); document.addEventListener( 'pointerdown', outside );
			document.addEventListener( 'keydown', keydown ); root.addEventListener( 'scroll', positionPopup, { passive: true } );
			const interval = window.setInterval( tick, 250 );
			ctx.api.reads = { decorate: decorate, reset: reset, destroy: destroy };
		},
	};
} );
