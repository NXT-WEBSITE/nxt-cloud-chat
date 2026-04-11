/**
 * Admin menu unread badge updater for NXT Cloud Chat.
 *
 * Injects a badge into the plugin menu item and polls an AJAX endpoint
 * to update unread message counts.
 *
 * @package NXTCC
 */

/* global NXTCCUnread */
jQuery(
	function ( $ ) {
		'use strict';

		/**
		 * Find the admin menu anchor that matches the plugin menu slug.
		 *
		 * @param {string} menuSlug Menu slug.
		 * @return {jQuery|null} Link element or null.
		 */
		function findMenuLink( menuSlug ) {
			var selector, $link;

			if ( ! menuSlug ) {
				return null;
			}

			selector = '#adminmenu a[href*="page=' + encodeURIComponent( menuSlug ) + '"]';
			$link    = $( selector ).first();

			if ( $link.length ) {
				return $link;
			}

			$link = $( '#adminmenu a[href*="received-messages"]' ).first();
			if ( $link.length ) {
				return $link;
			}

			return null;
		}

		/**
		 * Ensure the badge exists next to the menu link.
		 *
		 * Uses native DOM insertion to avoid jQuery HTML execution warnings.
		 *
		 * @param {jQuery|null} $link Menu link.
		 * @return {jQuery|null} Badge element or null.
		 */
		function ensureBadge( $link ) {
			var $badge, linkEl, badgeEl, nameEl;

			if ( ! $link || ! $link.length ) {
				return null;
			}

			// If it already exists anywhere in this menu item, reuse it.
			$badge = $link.find( '#nxtcc-chat-badge' );
			if ( $badge.length ) {
				return $badge;
			}

			linkEl = $link.get( 0 );
			if ( ! linkEl ) {
				return null;
			}

			// Prefer inserting into the visible label container.
			nameEl = linkEl.querySelector( '.wp-menu-name' );
			if ( ! nameEl ) {
				// Fallback: insert inside the link itself.
				nameEl = linkEl;
			}

			badgeEl             = document.createElement( 'span' );
			badgeEl.id          = 'nxtcc-chat-badge';
			badgeEl.className   = 'nxtcc-badge is-hidden';
			badgeEl.textContent = '0';

			// Append badge INSIDE the label container so it stays on the same line.
			nameEl.appendChild( badgeEl );

			return $( badgeEl );
		}


		/**
		 * Update badge UI.
		 *
		 * @param {jQuery|null} $badge Badge element.
		 * @param {string}      display Display text.
		 * @return {void}
		 */
		function setBadge( $badge, display ) {
			var text;

			if ( ! $badge || ! $badge.length ) {
				return;
			}

			text = display ? String( display ) : '0';

			$badge.text( text );

			if ( '0' === text ) {
				$badge.addClass( 'is-hidden' );
				return;
			}

			$badge.removeClass( 'is-hidden' );
		}

		/**
		 * Fetch unread count and update badge.
		 *
		 * @param {jQuery|null} $badge Badge element.
		 * @return {void}
		 */
		function refresh( $badge ) {
			$.ajax(
				{
					url: NXTCCUnread.ajaxurl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: NXTCCUnread.action || 'nxtcc_unread_count',
						nonce: NXTCCUnread.nonce
					}
				}
			).done(
				function ( res ) {
					var display;

					if ( ! res || ! res.success || ! res.data ) {
						return;
					}

					display = res.data.display ? String( res.data.display ) : '0';
					setBadge( $badge, display );
				}
			);
		}

		// Boot.
		if ( 'undefined' === typeof window.NXTCCUnread || ! NXTCCUnread.ajaxurl ) {
			return;
		}

		var menuSlug, $link, $badge, interval;

		menuSlug = NXTCCUnread.menu_slug ? String( NXTCCUnread.menu_slug ) : '';
		$link    = findMenuLink( menuSlug );
		$badge   = ensureBadge( $link );

		// First fetch and periodic refresh.
		refresh( $badge );

		interval = parseInt( NXTCCUnread.interval || 30000, 10 );
		if ( interval > 0 ) {
			window.setInterval(
				function () {
					refresh( $badge );
				},
				interval
			);
		}
	}
);



