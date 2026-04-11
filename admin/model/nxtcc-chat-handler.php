<?php
/**
 * Chat module loader.
 *
 * Boots the chat repository, helper functions, and AJAX endpoints for:
 * - Inbox summary.
 * - Chat thread retrieval + reply payload enrichment.
 * - Read state updates, favorites, soft delete.
 * - Outbound sending (text + media) and forwarding.
 * - Secure media proxy streaming from Meta Graph API.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/chat/class-nxtcc-chat-handler-repo.php';
require_once __DIR__ . '/chat/chat-helpers.php';

/**
 * Load AJAX endpoints only when WordPress is processing an AJAX request.
 *
 * This keeps admin page loads lighter and avoids loading endpoint code when not needed.
 */
if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
	require_once __DIR__ . '/chat/ajax-fetch.php';
	require_once __DIR__ . '/chat/ajax-send.php';
	require_once __DIR__ . '/chat/ajax-forward.php';
	require_once __DIR__ . '/chat/ajax-bulk.php';
	require_once __DIR__ . '/chat/ajax-media-proxy.php';
}
