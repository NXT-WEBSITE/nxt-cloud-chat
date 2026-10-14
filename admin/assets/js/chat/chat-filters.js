/**
 * Inbox ticket, message-source, and unread filters.
 *
 * @package NXTCC
 */
/* global jQuery */
jQuery( function ( $ ) {
	'use strict';
	const Chat = window.NXTCCChat;
	if ( ! Chat ) {
		return;
	}
	Chat.filters = {
		createMessages: function ( ctx, onChange ) {
			const $root = ctx.$widget.find( '.nxtcc-inbox-message-filters' );
			const sources = [ 'broadcast', 'workflow', 'system', 'integration' ];
			const ns = '.nxtccMessageFilters' + String( ctx.instanceId || '' );
			const userId = Number( ctx.$widget.attr( 'data-user-id' ) || 0 );
			const storageKey = $root.length && Number.isSafeInteger( userId ) && userId > 0 && ctx.businessAccountId && ctx.phoneNumberId
				? 'nxtcc:inbox-messages:' + JSON.stringify( [ Chat.cfg.ajaxurl, userId, ctx.businessAccountId, ctx.phoneNumberId ] ) : '';
			let excluded = [];
			let unreadOnly = false;
			if ( storageKey ) {
				try {
					const saved = JSON.parse( window.localStorage.getItem( storageKey ) );
					if ( saved && Array.isArray( saved.excluded_sources ) && saved.excluded_sources.length <= sources.length && typeof saved.unread_only === 'boolean' && saved.excluded_sources.every( function ( source ) { return sources.indexOf( source ) >= 0; } ) ) {
						excluded = sources.filter( function ( source ) { return saved.excluded_sources.indexOf( source ) >= 0; } );
						unreadOnly = saved.unread_only;
					}
				} catch ( error ) { /* Unavailable or corrupt browser storage keeps the defaults. */ }
			}
			function values() {
				return { excluded_sources: excluded.slice(), unread_only: unreadOnly };
			}
			function update() {
				$root.find( '.nxtcc-inbox-source-filter' ).each( function () {
					const $button = $( this );
					const enabled = excluded.indexOf( $button.attr( 'data-source' ) ) < 0;
					const label = $button.attr( enabled ? 'data-shown-label' : 'data-hidden-label' );
					$button.attr( { 'aria-pressed': String( enabled ), title: label, 'aria-label': label } );
					$button.find( '.nxtcc-msg-sender-tooltip' ).text( label );
				} );
				$root.find( '.nxtcc-inbox-unread-filter' ).attr( 'aria-pressed', String( unreadOnly ) );
			}
			$root.off( ns ).on( 'click' + ns, 'button', function ( event ) {
				event.preventDefault();
				const $button = $( this );
				if ( $button.hasClass( 'nxtcc-inbox-unread-filter' ) ) {
					unreadOnly = ! unreadOnly;
				} else {
					const source = $button.attr( 'data-source' );
					if ( sources.indexOf( source ) < 0 ) { return; }
					excluded = excluded.indexOf( source ) >= 0 ? excluded.filter( function ( value ) { return value !== source; } ) : sources.filter( function ( value ) { return value === source || excluded.indexOf( value ) >= 0; } );
				}
				update();
				if ( storageKey ) {
					try { window.localStorage.setItem( storageKey, JSON.stringify( values() ) ); } catch ( error ) { /* Filters remain usable without storage. */ }
				}
				onChange();
			} );
			update();
			return { values: values };
		},
		create: function ( ctx, onChange ) {
			const $root = ctx.$widget.find( '.nxtcc-ticket-filters' );
			const ns = '.nxtccFilters' + String( ctx.instanceId || '' );
			function values() {
				const result = { assignment: [], status: [], priority: [], overdue: $root.find( '.nxtcc-ticket-overdue' ).prop( 'checked' ) === true };
				$root.find( '.nxtcc-ticket-filter' ).each( function () {
					const key = $( this ).attr( 'data-filter' );
					if ( Array.isArray( result[ key ] ) ) {
						result[ key ] = $( this ).find( '.nxtcc-ticket-filter-option:checked:not(:disabled)' ).map( function () { return this.value; } ).get();
					}
				} );
				return result;
			}
			function close() {
				$root.find( '.nxtcc-ticket-filter-menu' ).prop( 'hidden', true );
				$root.find( '.nxtcc-ticket-filter-toggle' ).attr( 'aria-expanded', 'false' );
			}
			function update() {
				const overdue = $root.find( '.nxtcc-ticket-overdue' ).prop( 'checked' ) === true;
				const $completed = $root.find( '[data-filter="status"] .nxtcc-ticket-filter-option' ).filter( function () { return this.value === 'closed' || this.value === 'resolved'; } );
				$completed.prop( 'disabled', overdue );
				if ( overdue ) {
					$completed.prop( 'checked', false );
				}
				$completed.closest( 'label' ).attr( 'title', overdue ? $root.find( '.nxtcc-ticket-filter-disabled-hint' ).text() : '' );
				$root.find( '.nxtcc-ticket-filter' ).each( function () {
					const $group = $( this );
					const $options = $group.find( '.nxtcc-ticket-filter-option:not(:disabled)' );
					const $selected = $options.filter( ':checked' );
					let label = $selected.length === 1 ? $selected.closest( 'label' ).find( 'span' ).text() : ( $selected.length ? $group.attr( 'data-label' ) + ' (' + $selected.length + ')' : $group.attr( 'data-all' ) );
					if ( $group.attr( 'data-filter' ) === 'status' && overdue ) {
						label += ' / ' + $group.find( '.nxtcc-ticket-filter-overdue span' ).text();
					}
					$group.find( '.nxtcc-ticket-filter-value' ).text( label );
					$group.find( '.nxtcc-ticket-filter-toggle' ).attr( { 'aria-label': $group.attr( 'data-label' ) + ': ' + label, title: label } );
					$group.find( '.nxtcc-ticket-filter-any' ).prop( 'checked', !$selected.length );
					$group.find( '.nxtcc-ticket-filter-all' ).prop( { checked: $selected.length === $options.length && !!$options.length, indeterminate: $selected.length > 0 && $selected.length < $options.length } );
				} );
			}
			$root.off( ns ).on( 'click' + ns, '.nxtcc-ticket-filter-toggle', function () {
				const open = $( this ).attr( 'aria-expanded' ) !== 'true';
				close();
				$( this ).attr( 'aria-expanded', String( open ) ).siblings( '.nxtcc-ticket-filter-menu' ).prop( 'hidden', !open );
				if ( open ) {
					const panel = ctx.$widget.find( '.nxtcc-inbox-panel' ).get( 0 );
					if ( panel ) {
						const available = panel.getBoundingClientRect().bottom - this.getBoundingClientRect().bottom - 12;
						$( this ).siblings( '.nxtcc-ticket-filter-menu' ).css( 'max-height', Math.max( 0, Math.min( 320, available ) ) + 'px' );
					}
				}
			} ).on( 'change' + ns, 'input', function () {
				const $group = $( this ).closest( '.nxtcc-ticket-filter' );
				if ( $( this ).hasClass( 'nxtcc-ticket-filter-all' ) ) {
					$group.find( '.nxtcc-ticket-filter-option:not(:disabled)' ).prop( 'checked', this.checked );
				} else if ( $( this ).hasClass( 'nxtcc-ticket-filter-any' ) ) {
					$group.find( '.nxtcc-ticket-filter-option' ).prop( 'checked', false );
				}
				update();
				onChange();
			} ).on( 'click' + ns, '.nxtcc-ticket-filter-clear', function () {
				$( this ).closest( '.nxtcc-ticket-filter' ).find( 'input' ).prop( 'checked', false );
				update();
				onChange();
			} ).on( 'keydown' + ns, function ( event ) {
				if ( event.key === 'Escape' ) {
					const $button = $root.find( '.nxtcc-ticket-filter-toggle[aria-expanded="true"]' );
					close();
					$button.trigger( 'focus' );
				}
			} );
			$( document ).off( 'click' + ns ).on( 'click' + ns, function ( event ) {
				if ( !$root.get( 0 ) || !$root.get( 0 ).contains( event.target ) ) {
					close();
				}
			} );
			update();
			return { values: values };
		},
	};
} );
