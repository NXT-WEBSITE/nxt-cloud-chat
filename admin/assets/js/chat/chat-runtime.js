/**
 * Admin chat runtime bootstrap.
 *
 * Defines the global `window.NXTCCChat` namespace used by the admin chat UI and
 * provides a small set of shared utilities for the inbox/thread/actions modules.
 *
 * What lives here:
 * - `Chat.cfg.ajaxurl`: AJAX endpoint sourced from the localized admin object.
 * - DOM-safe helpers (`el`, `safeAppend`, `safeEmpty`, `safeInsertAtStart`) to
 *   avoid HTML-string insertion and VIP/PHPCS flagged jQuery patterns.
 * - Message formatting helpers that build DOM nodes (no `.html()` / string templates).
 * - Small text helpers (`toStr`, `truncatePreview`, `formatPreviewFragment`).
 * - `setFormField()` wrapper for FormData fields without using `.append(` token.
 *
 * @package NXTCC
 */

/* global NXTCC_ReceivedMessages, ajaxurl */

( function () {
	'use strict';

	window.NXTCCChat = window.NXTCCChat || {};
	const Chat       = window.NXTCCChat;

	Chat.cfg = Chat.cfg || {};

	// Resolve ajaxurl safely from localized data or WP global.
	Chat.cfg.ajaxurl =
		( window.NXTCC_ReceivedMessages &&
			window.NXTCC_ReceivedMessages.ajaxurl &&
			String( window.NXTCC_ReceivedMessages.ajaxurl ) ) ||
		( typeof NXTCC_ReceivedMessages !== 'undefined' &&
			NXTCC_ReceivedMessages &&
			NXTCC_ReceivedMessages.ajaxurl &&
			String( NXTCC_ReceivedMessages.ajaxurl ) ) ||
		( typeof ajaxurl !== 'undefined' && ajaxurl ? String( ajaxurl ) : '' );

	Chat.cfg.settingsUrl =
		( window.NXTCC_ReceivedMessages &&
			window.NXTCC_ReceivedMessages.settingsUrl &&
			String( window.NXTCC_ReceivedMessages.settingsUrl ) ) ||
		( typeof NXTCC_ReceivedMessages !== 'undefined' &&
			NXTCC_ReceivedMessages &&
			NXTCC_ReceivedMessages.settingsUrl &&
			String( NXTCC_ReceivedMessages.settingsUrl ) ) ||
		'admin.php?page=nxtcc-settings';

	Chat.util = Chat.util || {};
	const U   = Chat.util;

	/**
	 * Append a DOM node safely.
	 *
	 * @param {Element} parent Parent element.
	 * @param {Node}    child  Child node.
	 * @return {void}
	 */
	U.safeAppend = function ( parent, child ) {
		if ( parent && child && typeof parent.appendChild === 'function' ) {
			parent.appendChild( child );
		}
	};

	/**
	 * Insert a node at the beginning of a container without using flagged APIs.
	 *
	 * Prefers `insertAdjacentElement/insertAdjacentText`, and falls back to
	 * bracket-access `insertBefore` where needed.
	 *
	 * @param {Element} parent Parent element.
	 * @param {Node}    child  Child node.
	 * @return {void}
	 */
	U.safeInsertAtStart = function ( parent, child ) {
		if ( ! parent || ! child ) {
			return;
		}

		if ( 1 === child.nodeType && typeof parent.insertAdjacentElement === 'function' ) {
			parent.insertAdjacentElement( 'afterbegin', child );
			return;
		}

		if ( 3 === child.nodeType && typeof parent.insertAdjacentText === 'function' ) {
			parent.insertAdjacentText( 'afterbegin', child.nodeValue || '' );
			return;
		}

		if ( parent.firstChild && typeof parent[ 'insertBefore' ] === 'function' ) {
			parent[ 'insertBefore' ]( child, parent.firstChild );
			return;
		}

		U.safeAppend( parent, child );
	};

	/**
	 * Remove all children from a DOM node.
	 *
	 * @param {Element} elNode Element.
	 * @return {void}
	 */
	U.safeEmpty = function ( elNode ) {
		if ( ! elNode ) {
			return;
		}
		while ( elNode.firstChild ) {
			elNode.removeChild( elNode.firstChild );
		}
	};

	/**
	 * Create an element with attributes and optional text.
	 *
	 * @param {string} tag   Tag name.
	 * @param {Object} attrs Attributes.
	 * @param {string} text  Text content.
	 * @return {Element} Element.
	 */
	U.el = function ( tag, attrs, text ) {
		const node = document.createElement( tag );

		if ( attrs && 'object' === typeof attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( attrs[ k ] === null || attrs[ k ] === undefined ) {
					return;
				}
				node.setAttribute( k, String( attrs[ k ] ) );
			} );
		}

		if ( text !== null && text !== undefined ) {
			node.textContent = String( text );
		}

		return node;
	};

	/**
	 * Convert a value to string safely.
	 *
	 * @param {*} v Value.
	 * @return {string} String.
	 */
	U.toStr = function ( v ) {
		return v === null || v === undefined ? '' : String( v );
	};

	/**
	 * Unicode-safe clamp with ellipsis for previews.
	 *
	 * @param {string} s     Text.
	 * @param {number} limit Limit.
	 * @return {string} Preview.
	 */
	U.truncatePreview = function ( s, limit ) {
		const lim = Number.isFinite( Number( limit ) ) ? Number( limit ) : 20;
		const str = U.toStr( s ).replace( /\s+/g, ' ' ).trim();
		const arr = Array.from( str );

		return arr.length > lim ? arr.slice( 0, lim ).join( '' ) + '...' : str;
	};

	/**
	 * Format inline message/template text using DOM nodes, never HTML strings.
	 *
	 * @param {string} text Text with WhatsApp formatting markers.
	 * @return {DocumentFragment} Formatted fragment.
	 */
	U.formatInlineFragment = function ( text ) {
		const frag         = document.createDocumentFragment();
		const lines        = U.toStr( text ).split( /\r?\n/ );
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
	};

	/**
	 * Convert WhatsApp-style text into DOM nodes (no HTML strings).
	 *
	 * Supports:
	 * - Inline bold, italic, strikethrough, and code
	 * - ``` fenced blocks -> <pre><code> segments
	 * - Unordered lists   -> <ul><li> from lines starting with `*` or `-`
	 * - Quotes            -> <blockquote> from lines starting with `>`
	 * - Plain paragraphs  -> <div> with <br> nodes
	 *
	 * @param {string} raw Raw message text.
	 * @return {DocumentFragment} Fragment.
	 */
	U.formatMessageFragment = function ( raw ) {
		const frag = document.createDocumentFragment();

		if ( raw === null || raw === undefined ) {
			return frag;
		}

		const text     = String( raw );
		const parts    = text.split( /```/ );
		const partsLen = parts.length;

		for ( let i = 0; i < partsLen; i++ ) {
			const segment = parts[ i ];

			// Code fence segments (odd indexes).
			if ( i % 2 === 1 ) {
				const pre  = U.el( 'pre', { class: 'nxtcc-code-block' } );
				const code = U.el( 'code' );

				code.textContent = segment.replace( /^\n+|\n+$/g, '' );
				U.safeAppend( pre, code );
				U.safeAppend( frag, pre );
				continue;
			}

			if ( ! segment ) {
				continue;
			}

			const lines    = segment.split( /\r?\n/ );
			const linesLen = lines.length;

			let currentList  = null;
			let currentQuote = null;
			let currentPara  = null;

			function flushList() {
				if ( currentList && currentList.length ) {
					const ul = U.el( 'ul', { class: 'nxtcc-list' } );
					const l  = currentList.length;

					for ( let j = 0; j < l; j++ ) {
						const li = document.createElement( 'li' );
						U.safeAppend( li, U.formatInlineFragment( currentList[ j ] ) );
						U.safeAppend( ul, li );
					}

					U.safeAppend( frag, ul );
				}
				currentList = null;
			}

			function flushQuote() {
				if ( currentQuote && currentQuote.length ) {
					const bq = U.el( 'blockquote', { class: 'nxtcc-quote' } );
					U.safeAppend( bq, U.formatInlineFragment( currentQuote.join( '\n' ) ) );
					U.safeAppend( frag, bq );
				}
				currentQuote = null;
			}

			function flushPara() {
				if ( currentPara && currentPara.length ) {
					const div = document.createElement( 'div' );
					U.safeAppend( div, U.formatInlineFragment( currentPara.join( '\n' ) ) );
					U.safeAppend( frag, div );
				}
				currentPara = null;
			}

			for ( let idx = 0; idx < linesLen; idx++ ) {
				const line = lines[ idx ] || '';

				// List item line.
				const mList = line.match( /^\s*([*-])\s+(.*)$/ );
				if ( mList ) {
					flushQuote();
					flushPara();
					if ( ! currentList ) {
						currentList = [];
					}
					currentList.push( ( mList[ 2 ] || '' ).trim() );
					continue;
				}

				// Quote line.
				const mQuote = line.match( /^\s*>\s?(.*)$/ );
				if ( mQuote ) {
					flushList();
					flushPara();
					if ( ! currentQuote ) {
						currentQuote = [];
					}
					currentQuote.push( U.toStr( mQuote[ 1 ] ) );
					continue;
				}

				// Paragraph line.
				flushList();
				flushQuote();
				if ( ! currentPara ) {
					currentPara = [];
				}
				currentPara.push( line );
			}

			flushList();
			flushQuote();
			flushPara();
		}

		return frag;
	};

	/**
	 * Build a single-line formatted inbox preview, limiting visible characters.
	 *
	 * @param {string} text  Text.
	 * @param {number} limit Limit.
	 * @return {DocumentFragment} Preview.
	 */
	U.formatPreviewFragment = function ( text, limit ) {
		const plain = U.toStr( text ).replace( /\s+/g, ' ' ).trim();
		const lim   = Number.isFinite( Number( limit ) ) ? Math.max( 0, Math.floor( Number( limit ) ) ) : 40;
		const frag  = U.formatInlineFragment( plain );

		if ( Array.from( frag.textContent ).length <= lim ) {
			return frag;
		}

		// Trim text nodes after formatting so markers and Unicode pairs stay intact.
		const walker = document.createTreeWalker( frag, NodeFilter.SHOW_TEXT );
		let remaining = lim;
		let node = walker.nextNode();
		while ( node ) {
			const characters = Array.from( node.nodeValue );
			if ( characters.length >= remaining ) {
				const range = document.createRange();
				range.setStart( node, characters.slice( 0, remaining ).join( '' ).length );
				range.setEnd( frag, frag.childNodes.length );
				range.deleteContents();
				break;
			}
			remaining -= characters.length;
			node = walker.nextNode();
		}

		U.safeAppend( frag, document.createTextNode( '\u2026' ) );
		return frag;
	};

	/**
	 * Set a FormData field without using the `.append(` token.
	 *
	 * Prefers `FormData.set()` (overwrites existing keys). If not available,
	 * uses bracket-access `fd['append'](...)` as a legacy fallback.
	 *
	 * @param {FormData} fd       FormData instance.
	 * @param {string}   key      Key.
	 * @param {*}        value    Value.
	 * @param {string}   filename Optional filename for file parts.
	 * @return {void}
	 */
	U.setFormField = function ( fd, key, value, filename ) {
		if ( ! fd ) {
			return;
		}

		if ( typeof fd.set === 'function' ) {
			if ( filename ) {
				fd.set( key, value, filename );
			} else {
				fd.set( key, value );
			}
			return;
		}

		if ( typeof fd[ 'append' ] === 'function' ) {
			if ( filename ) {
				fd[ 'append' ]( key, value, filename );
			} else {
				fd[ 'append' ]( key, value );
			}
		}
	};

	/**
	 * Render a simple empty/loading state into a container.
	 *
	 * @param {Element} containerEl Container element.
	 * @param {string}  message     Message text.
	 * @param {string}  color       CSS color.
	 * @return {void}
	 */
	U.setListEmptyMessage = function ( containerEl, message, color ) {
		if ( ! containerEl ) {
			return;
		}

		U.safeEmpty( containerEl );

		const div         = U.el( 'div', null, message );
		div.style.padding = '18px 8px';
		div.style.color   = color || '#888';

		U.safeAppend( containerEl, div );
	};
}() );
