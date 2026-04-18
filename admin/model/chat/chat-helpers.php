<?php
/**
 * Chat helpers (non-DB utilities + common request parsing).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_chat_repo' ) ) {
	/**
	 * Convenience accessor for the chat repository.
	 *
	 * @return NXTCC_Chat_Handler_Repo
	 */
	function nxtcc_chat_repo(): NXTCC_Chat_Handler_Repo {
		return NXTCC_Chat_Handler_Repo::instance();
	}
}

if ( ! function_exists( 'nxtcc_chat_make_reply_payload' ) ) {
	/**
	 * Build a compact reply payload from a history row.
	 *
	 * @param object $row Message history row.
	 * @return array Normalized reply payload.
	 */
	function nxtcc_chat_make_reply_payload( $row ): array {
		$out = array(
			'history_id' => isset( $row->id ) ? (int) $row->id : 0,
			'meta_id'    => isset( $row->meta_message_id ) ? (string) $row->meta_message_id : '',
			'kind'       => 'text',
			'text'       => '',
			'caption'    => '',
			'filename'   => '',
			'link'       => '',
			'media_id'   => '',
		);

		$raw = isset( $row->message_content ) ? $row->message_content : '';

		if ( is_string( $raw ) ) {
			$raw = (string) $raw;
		} else {
			$raw = '';
		}

		$raw = trim( $raw );

		if ( '' === $raw && function_exists( 'nxtcc_chat_extract_message_content_from_message' ) ) {
			$raw = nxtcc_chat_extract_message_content_from_message( $row );
		}

		if ( '' !== $raw && '{' === substr( $raw, 0, 1 ) ) {
			$obj = json_decode( $raw, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $obj ) ) {
				if ( isset( $obj['kind'] ) ) {
					$out['kind']     = (string) $obj['kind'];
					$out['caption']  = isset( $obj['caption'] ) ? (string) $obj['caption'] : '';
					$out['filename'] = isset( $obj['filename'] ) ? (string) $obj['filename'] : '';
					$out['link']     = isset( $obj['link'] ) ? (string) $obj['link'] : '';
					$out['media_id'] = isset( $obj['media_id'] ) ? (string) $obj['media_id'] : '';

					if ( 'text' === $out['kind'] && isset( $obj['text'] ) ) {
						$out['text'] = (string) $obj['text'];
					}
				} elseif ( isset( $obj['text'] ) ) {
					$out['kind'] = 'text';
					$out['text'] = (string) $obj['text'];
				}
			} else {
				// Not valid JSON; treat as text.
				$out['kind'] = 'text';
				$out['text'] = $raw;
			}
		} else {
			$out['kind'] = 'text';
			$out['text'] = $raw;
		}

		return $out;
	}
}

if ( ! function_exists( 'nxtcc_chat_extract_message_content_from_message' ) ) {
	/**
	 * Extract displayable message content from a thread row.
	 *
	 * This is primarily used to recover older rows where `message_content` was
	 * stored empty even though the raw webhook payload in `response_json`
	 * contains enough information to rebuild a readable value.
	 *
	 * @param object|array $message Message row.
	 * @return string
	 */
	function nxtcc_chat_extract_message_content_from_message( $message ): string {
		$existing = '';

		if ( is_object( $message ) && isset( $message->message_content ) ) {
			$existing = (string) $message->message_content;
		} elseif ( is_array( $message ) && isset( $message['message_content'] ) ) {
			$existing = (string) $message['message_content'];
		}

		$existing = trim( $existing );
		if ( '' !== $existing ) {
			return $existing;
		}

		$response_json = '';

		if ( is_object( $message ) && ! empty( $message->response_json ) ) {
			$response_json = (string) $message->response_json;
		} elseif ( is_array( $message ) && ! empty( $message['response_json'] ) ) {
			$response_json = (string) $message['response_json'];
		}

		if ( '' === $response_json ) {
			return '';
		}

		$decoded = json_decode( $response_json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		if ( function_exists( 'nxtcc_build_inbound_message_content_from_webhook' ) ) {
			$rebuilt = nxtcc_build_inbound_message_content_from_webhook( $decoded );
			if ( is_string( $rebuilt ) && '' !== trim( $rebuilt ) ) {
				return trim( $rebuilt );
			}
		}

		$type = isset( $decoded['type'] ) ? sanitize_key( (string) $decoded['type'] ) : '';
		if ( 'reaction' === $type && isset( $decoded['reaction'] ) && is_array( $decoded['reaction'] ) ) {
			$emoji = isset( $decoded['reaction']['emoji'] ) ? sanitize_text_field( (string) $decoded['reaction']['emoji'] ) : '';

			if ( '' !== $emoji ) {
				return $emoji;
			}

			return ! empty( $decoded['reaction']['message_id'] ) ? 'Reaction removed' : 'Reaction';
		}

		return '';
	}
}

if ( ! function_exists( 'nxtcc_chat_extract_reply_wamid_from_message' ) ) {
	/**
	 * Extract a reply target Meta message id from a chat-thread row.
	 *
	 * Supports current rows that already store `reply_to_wamid` and older rows
	 * that only kept the raw webhook payload in `response_json`.
	 *
	 * @param object|array $message Message row.
	 * @return string
	 */
	function nxtcc_chat_extract_reply_wamid_from_message( $message ): string {
		$existing = '';

		if ( is_object( $message ) && ! empty( $message->reply_to_wamid ) ) {
			$existing = (string) $message->reply_to_wamid;
		} elseif ( is_array( $message ) && ! empty( $message['reply_to_wamid'] ) ) {
			$existing = (string) $message['reply_to_wamid'];
		}

		if ( '' !== $existing ) {
			if ( function_exists( 'nxtcc_normalize_reply_wamid' ) ) {
				return nxtcc_normalize_reply_wamid( $existing );
			}

			if ( function_exists( 'nxtcc_rest_normalize_meta_message_id' ) ) {
				return nxtcc_rest_normalize_meta_message_id( $existing );
			}

			return sanitize_text_field( $existing );
		}

		$response_json = '';

		if ( is_object( $message ) && ! empty( $message->response_json ) ) {
			$response_json = (string) $message->response_json;
		} elseif ( is_array( $message ) && ! empty( $message['response_json'] ) ) {
			$response_json = (string) $message['response_json'];
		}

		if ( '' === $response_json ) {
			return '';
		}

		$decoded = json_decode( $response_json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$context     = isset( $decoded['context'] ) && is_array( $decoded['context'] ) ? $decoded['context'] : array();
		$reply_wamid = isset( $context['id'] ) ? sanitize_text_field( (string) $context['id'] ) : '';

		if ( '' === $reply_wamid && isset( $decoded['reaction'] ) && is_array( $decoded['reaction'] ) ) {
			$reply_wamid = isset( $decoded['reaction']['message_id'] ) ? sanitize_text_field( (string) $decoded['reaction']['message_id'] ) : '';
		}

		if ( '' === $reply_wamid ) {
			return '';
		}

		if ( function_exists( 'nxtcc_normalize_reply_wamid' ) ) {
			return nxtcc_normalize_reply_wamid( $reply_wamid );
		}

		if ( function_exists( 'nxtcc_rest_normalize_meta_message_id' ) ) {
			return nxtcc_rest_normalize_meta_message_id( $reply_wamid );
		}

		return $reply_wamid;
	}
}

if ( ! function_exists( 'nxtcc_chat_resolve_user_and_pnid' ) ) {
	/**
	 * Resolve (user_mailid, phone_number_id) for the current user.
	 *
	 * @param string $requested_pnid Requested phone_number_id.
	 * @return array{0:string,1:string} Pair [user_mailid, phone_number_id].
	 */
	function nxtcc_chat_resolve_user_and_pnid( string $requested_pnid = '' ): array {
		$tenant = NXTCC_Access_Control::get_current_tenant_context();

		$user_mailid = isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '';
		$pnid        = isset( $tenant['phone_number_id'] ) ? sanitize_text_field( (string) $tenant['phone_number_id'] ) : '';

		if ( '' === $user_mailid || '' === $pnid ) {
			return array( '', '' );
		}

		$requested_pnid = sanitize_text_field( $requested_pnid );
		if ( '' !== $requested_pnid && $requested_pnid !== $pnid ) {
			return array( '', '' );
		}

		return array( $user_mailid, (string) $pnid );
	}
}

if ( ! function_exists( 'nxtcc_chat_remote_get' ) ) {
	/**
	 * Safe remote GET wrapper.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error Response.
	 */
	function nxtcc_chat_remote_get( string $url, array $args = array() ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return new WP_Error( 'bad_url', 'Invalid URL.' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 3,
				'redirection' => 3,
			)
		);

		$args['timeout']     = (int) $args['timeout'];
		$args['redirection'] = (int) $args['redirection'];

		if ( $args['timeout'] < 1 ) {
			$args['timeout'] = 1;
		}
		if ( $args['timeout'] > 3 ) {
			$args['timeout'] = 3;
		}

		if ( $args['redirection'] < 0 ) {
			$args['redirection'] = 0;
		}
		if ( $args['redirection'] > 3 ) {
			$args['redirection'] = 3;
		}

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, $args );
		}

		return wp_safe_remote_get( $url, $args );
	}
}

if ( ! function_exists( 'nxtcc_chat_stream_bytes' ) ) {
	/**
	 * Stream raw bytes to the client and terminate execution.
	 *
	 * VIP-safe approach:
	 * - Write bytes to a temp file in get_temp_dir() via WP_Filesystem.
	 * - Delete temp file via WP_Filesystem (no unlink, no @).
	 *
	 * @param string      $bytes        Raw binary.
	 * @param string      $content_type MIME type.
	 * @param string|null $filename     Optional filename.
	 * @return void
	 */
	function nxtcc_chat_stream_bytes(
		string $bytes,
		string $content_type = 'application/octet-stream',
		?string $filename = null
	): void {
		nocache_headers();

		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}

		// Init WP_Filesystem early so we can cleanup on any failure.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			wp_die( 'Filesystem init failed.', '', array( 'response' => 500 ) );
		}

		// Sanitize content type and keep it header-safe.
		$content_type = sanitize_text_field( (string) $content_type );
		$content_type = trim( $content_type );

		if ( '' === $content_type ) {
			$content_type = 'application/octet-stream';
		}

		// Prevent header injection: only allow token/token MIME.
		if ( ! preg_match( '/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $content_type ) ) {
			$content_type = 'application/octet-stream';
		}

		// Create a temp file in the VIP-safe temp directory.
		$tmp = wp_tempnam( 'nxtcc-media-' );
		if ( ! $tmp ) {
			wp_die( 'Failed to create temp file.', '', array( 'response' => 500 ) );
		}

		// Write bytes via WP_Filesystem (VIP-friendly).
		$written = $wp_filesystem->put_contents( $tmp, $bytes, FS_CHMOD_FILE );
		if ( ! $written ) {
			$wp_filesystem->delete( $tmp );
			wp_die( 'Failed to write temp file.', '', array( 'response' => 500 ) );
		}

		$filename = ( null !== $filename ) ? sanitize_file_name( (string) $filename ) : '';
		$filename = trim( $filename );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $content_type );

		$len = $wp_filesystem->size( $tmp );
		if ( false !== $len && $len > 0 ) {
			header( 'Content-Length: ' . (string) $len );
		}

		if ( '' !== $filename ) {
			header( 'Content-Disposition: inline; filename="' . rawurlencode( $filename ) . '"' );
		}

		// Stream the temp file contents via WP_Filesystem.
		$stream = $wp_filesystem->get_contents( $tmp );
		if ( false === $stream ) {
			$wp_filesystem->delete( $tmp );
			wp_die( 'Failed to read temp file.', '', array( 'response' => 500 ) );
		}

		// Cleanup temp file via WP_Filesystem (no unlink()).
		$wp_filesystem->delete( $tmp );

		if ( function_exists( 'nxtcc_output_bytes' ) ) {
			nxtcc_output_bytes( (string) $stream );
		} else {
			call_user_func( 'printf', '%s', $stream );
		}
		wp_die( '', '', array( 'response' => 200 ) );
	}
}

if ( ! function_exists( 'nxtcc_chat_download_graph_media_to_wp' ) ) {
	/**
	 * Download a Graph media_id and store it in WP uploads.
	 *
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @param string $media_id        Graph media id.
	 * @return array Result array with url/filename/mime or error.
	 */
	function nxtcc_chat_download_graph_media_to_wp(
		string $user_mailid,
		string $phone_number_id,
		string $media_id
	): array {
		$user_mailid     = sanitize_email( (string) $user_mailid );
		$phone_number_id = sanitize_text_field( (string) $phone_number_id );
		$media_id        = sanitize_text_field( (string) $media_id );

		if ( '' === $user_mailid || '' === $phone_number_id || '' === $media_id ) {
			return array( 'error' => 'bad_args' );
		}

		$repo  = nxtcc_chat_repo();
		$creds = $repo->get_user_settings_access_token( $user_mailid, $phone_number_id );

		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return array( 'error' => 'token_missing' );
		}

		$meta = nxtcc_chat_remote_get(
			'https://graph.facebook.com/v19.0/' . rawurlencode( $media_id ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $creds['access_token'],
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $meta ) ) {
			return array( 'error' => 'meta_request' );
		}

		$code = (int) wp_remote_retrieve_response_code( $meta );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'error' => 'meta_http',
				'code'  => $code,
			);
		}

		$info = json_decode( (string) wp_remote_retrieve_body( $meta ), true );

		$url  = ( is_array( $info ) && isset( $info['url'] ) ) ? (string) $info['url'] : '';
		$mime = ( is_array( $info ) && isset( $info['mime_type'] ) ) ? (string) $info['mime_type'] : 'application/octet-stream';

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return array( 'error' => 'no_url' );
		}

		$bin = nxtcc_chat_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $creds['access_token'],
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $bin ) ) {
			return array( 'error' => 'bin_request' );
		}

		$code = (int) wp_remote_retrieve_response_code( $bin );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'error' => 'bin_http',
				'code'  => $code,
			);
		}

		$body = (string) wp_remote_retrieve_body( $bin );
		if ( '' === $body ) {
			return array( 'error' => 'empty_body' );
		}

		$mime = sanitize_text_field( $mime );
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}

		$ext = nxtcc_chat_mime_to_ext( $mime );
		if ( '' === $ext ) {
			$ext = 'bin';
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $media_id );
		$fname   = 'fwd-' . substr( (string) $safe_id, 0, 12 ) . '.' . $ext;
		$fname   = sanitize_file_name( $fname );

		$upload = wp_upload_bits( $fname, null, $body );

		if ( ! empty( $upload['error'] ) ) {
			return array(
				'error'   => 'upload',
				'message' => (string) $upload['error'],
			);
		}

		return array(
			'url'      => (string) $upload['url'],
			'filename' => basename( (string) $upload['file'] ),
			'mime'     => (string) $mime,
		);
	}
}

if ( ! function_exists( 'nxtcc_chat_mime_to_ext' ) ) {
	/**
	 * Map MIME type to file extension for forwarding.
	 *
	 * @param string $mime MIME type.
	 * @return string Extension (without dot) or empty string.
	 */
	function nxtcc_chat_mime_to_ext( string $mime ): string {
		$map = array(
			'image/jpeg'               => 'jpg',
			'image/jpg'                => 'jpg',
			'image/png'                => 'png',
			'image/gif'                => 'gif',
			'image/webp'               => 'webp',

			'video/mp4'                => 'mp4',
			'video/webm'               => 'webm',

			'audio/mpeg'               => 'mp3',
			'audio/mp3'                => 'mp3',
			'audio/ogg'                => 'ogg',
			'audio/wav'                => 'wav',

			'application/pdf'          => 'pdf',
			'text/plain'               => 'txt',
			'application/zip'          => 'zip',

			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/msword'       => 'doc',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'application/vnd.ms-excel' => 'xls',
		);

		$mime = strtolower( sanitize_text_field( $mime ) );

		return isset( $map[ $mime ] ) ? (string) $map[ $mime ] : '';
	}
}
