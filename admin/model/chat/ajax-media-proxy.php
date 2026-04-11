<?php
/**
 * AJAX endpoint: Media proxy for WhatsApp Graph downloads.
 *
 * Uses shared network helpers (nxtcc_chat_remote_get) and streaming helper
 * (nxtcc_chat_stream_bytes) defined in chat-helpers.php.
 *
 * Expected GET params:
 * - mid  : Graph media id.
 * - pnid : phone_number_id (optional; validated for current user).
 * - nonce: nonce for action 'nxtcc_received_messages'
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure shared helpers are loaded (repo + remote + stream).
 * This file can be invoked directly by admin-ajax, so do not assume load order.
 */
if ( ! function_exists( 'nxtcc_chat_repo' ) || ! function_exists( 'nxtcc_chat_remote_get' ) || ! function_exists( 'nxtcc_chat_stream_bytes' ) ) {
	require_once NXTCC_PLUGIN_DIR . 'admin/model/chat/chat-helpers.php';
}

if ( ! function_exists( 'nxtcc_chat_ajax_require_caps' ) ) {

	/**
	 * Require proper capability for chat admin AJAX endpoints.
	 *
	 * @return void
	 */
	function nxtcc_chat_ajax_require_caps(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Forbidden', 'nxt-cloud-chat' ),
				'',
				array( 'response' => 403 )
			);
		}
	}
}

if ( ! function_exists( 'nxtcc_chat_proxy_die' ) ) {

	/**
	 * End request with a given status code and safe message.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $msg    Message.
	 * @return void
	 */
	function nxtcc_chat_proxy_die( int $status, string $msg ): void {
		wp_die(
			esc_html( $msg ),
			'',
			array( 'response' => absint( $status ) )
		);
	}
}

if ( ! function_exists( 'nxtcc_chat_is_allowed_graph_media_url' ) ) {

	/**
	 * Validate that a URL looks like a Meta/Graph-provided media URL we expect.
	 *
	 * Important: Avoid substring checks like "strpos($host,'facebook.com')" which
	 * can match "evilfacebook.com". We require exact host or dot-suffix match.
	 *
	 * @param string $url URL from Graph metadata.
	 * @return bool
	 */
	function nxtcc_chat_is_allowed_graph_media_url( string $url ): bool {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		/**
		 * Graph media "url" can come from several Meta domains.
		 * Keep a tight allow-list.
		 */
		$allowed_hosts = array(
			'facebook.com',
			'fbcdn.net',
			'fbsbx.com',
			'whatsapp.net',
			'cdninstagram.com',
		);

		foreach ( $allowed_hosts as $allowed ) {
			$allowed = strtolower( (string) $allowed );

			// Exact host match.
			if ( $host === $allowed ) {
				return true;
			}

			// Subdomain match: *.allowed.
			$needle = '.' . $allowed;
			$hlen   = strlen( $host );
			$nlen   = strlen( $needle );

			if ( $hlen > $nlen && substr( $host, -$nlen ) === $needle ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * AJAX handler: Media proxy (download from Graph and stream).
 *
 * @return void
 */
function nxtcc_ajax_media_proxy(): void {

	if ( ! is_user_logged_in() ) {
		nxtcc_chat_proxy_die( 403, 'Forbidden' );
	}

	nxtcc_chat_ajax_require_caps();

	// Verify nonce BEFORE reading any request input.
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$media_id = '';
	if ( isset( $_GET['mid'] ) ) {
		$media_id = sanitize_text_field( wp_unslash( $_GET['mid'] ) );
	}

	// Tight allow-pattern for Graph media IDs (letters/numbers/_/- only).
	if ( '' === $media_id || 1 !== preg_match( '/^[a-zA-Z0-9_-]{5,200}$/', $media_id ) ) {
		nxtcc_chat_proxy_die( 400, 'Bad Request' );
	}

	$requested_pnid = '';
	if ( isset( $_GET['pnid'] ) ) {
		$requested_pnid = sanitize_text_field( wp_unslash( $_GET['pnid'] ) );
	}

	/*
	 * IMPORTANT:
	 * Do not trust pnid from input. Validate it for this user, and fall back
	 * to the user's current/last pnid using the shared helper (if available).
	 */
	$user_mailid     = '';
	$phone_number_id = '';

	if ( function_exists( 'nxtcc_chat_resolve_user_and_pnid' ) ) {
		list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );
		$user_mailid                           = sanitize_email( (string) $user_mailid );
		$phone_number_id                       = sanitize_text_field( (string) $phone_number_id );
	} else {
		$user            = wp_get_current_user();
		$user_mailid     = ( $user instanceof WP_User ) ? sanitize_email( (string) $user->user_email ) : '';
		$phone_number_id = sanitize_text_field( (string) $requested_pnid );
	}

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		nxtcc_chat_proxy_die( 403, 'Forbidden' );
	}

	$repo  = nxtcc_chat_repo();
	$creds = $repo->get_user_settings_access_token( $user_mailid, $phone_number_id );

	if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
		nxtcc_chat_proxy_die( 403, 'Forbidden' );
	}

	$token = (string) $creds['access_token'];

	// 1) Fetch media metadata from Graph.
	$meta_url  = 'https://graph.facebook.com/v19.0/' . rawurlencode( $media_id );
	$meta_resp = nxtcc_chat_remote_get(
		$meta_url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
			'timeout' => 3,
		)
	);

	if ( is_wp_error( $meta_resp ) ) {
		nxtcc_chat_proxy_die( 502, 'Bad Gateway' );
	}

	$meta_code = (int) wp_remote_retrieve_response_code( $meta_resp );
	if ( $meta_code < 200 || $meta_code >= 300 ) {
		nxtcc_chat_proxy_die( 502, 'Bad Gateway' );
	}

	$info = json_decode( (string) wp_remote_retrieve_body( $meta_resp ), true );

	$url  = '';
	$mime = 'application/octet-stream';

	if ( is_array( $info ) ) {
		if ( isset( $info['url'] ) ) {
			$url = (string) $info['url'];
		}
		if ( isset( $info['mime_type'] ) ) {
			$mime = (string) $info['mime_type'];
		}
	}

	$url  = esc_url_raw( $url );
	$mime = sanitize_text_field( $mime );
	if ( '' === $mime ) {
		$mime = 'application/octet-stream';
	}

	if ( '' === $url ) {
		nxtcc_chat_proxy_die( 404, 'Not Found' );
	}

	if ( ! nxtcc_chat_is_allowed_graph_media_url( $url ) ) {
		nxtcc_chat_proxy_die( 403, 'Forbidden' );
	}

	// 2) Download media bytes and stream.
	$bin_resp = nxtcc_chat_remote_get(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
			'timeout' => 3,
		)
	);

	if ( is_wp_error( $bin_resp ) ) {
		nxtcc_chat_proxy_die( 502, 'Bad Gateway' );
	}

	$bin_code = (int) wp_remote_retrieve_response_code( $bin_resp );
	if ( $bin_code < 200 || $bin_code >= 300 ) {
		nxtcc_chat_proxy_die( 502, 'Bad Gateway' );
	}

	$body = (string) wp_remote_retrieve_body( $bin_resp );
	if ( '' === $body ) {
		nxtcc_chat_proxy_die( 404, 'Not Found' );
	}

	$ctype = wp_remote_retrieve_header( $bin_resp, 'content-type' );
	$ctype = ( is_string( $ctype ) && '' !== $ctype ) ? sanitize_text_field( $ctype ) : $mime;

	/*
	 * Safety guard: prevent huge memory usage.
	 * Keep it reasonable for admin UI.
	 */
	$max_bytes = 10 * 1024 * 1024; // 10 MB.
	if ( strlen( $body ) > $max_bytes ) {
		nxtcc_chat_proxy_die( 413, 'Payload Too Large' );
	}

	// Stream bytes via shared helper (adds headers and exits safely).
	nxtcc_chat_stream_bytes( $body, (string) $ctype, null );
}

add_action( 'wp_ajax_nxtcc_media_proxy', 'nxtcc_ajax_media_proxy' );
