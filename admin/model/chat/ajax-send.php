<?php
/**
 * AJAX endpoints: send text messages and send media (upload or URL).
 *
 * Endpoints:
 * - nxtcc_send_message      : send a text message (optionally as a reply).
 * - nxtcc_send_media        : upload media to WP and send by public URL.
 * - nxtcc_send_media_by_url : send media using an existing public URL.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_chat_ajax_require_caps' ) ) {

	/**
	 * Require proper capability for chat admin AJAX endpoints.
	 *
	 * @return void
	 */
	function nxtcc_chat_ajax_require_caps(): void {
		if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
			wp_send_json_error(
				array( 'message' => 'Insufficient permissions.' ),
				403
			);
		}
	}
}

if ( ! function_exists( 'nxtcc_chat_allowed_media_mimes' ) ) {
	/**
	 * Allowed MIME types for chat uploads (filterable).
	 *
	 * @return array<string,string> Map of ext => mime.
	 */
	function nxtcc_chat_allowed_media_mimes(): array {
		$mimes = array(
			// Images.
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'gif'      => 'image/gif',
			'webp'     => 'image/webp',

			// Audio.
			'mp3'      => 'audio/mpeg',
			'ogg'      => 'audio/ogg',
			'wav'      => 'audio/wav',

			// Video.
			'mp4'      => 'video/mp4',
			'webm'     => 'video/webm',

			// Documents.
			'pdf'      => 'application/pdf',
			'txt'      => 'text/plain',
			'zip'      => 'application/zip',
			'doc'      => 'application/msword',
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'      => 'application/vnd.ms-excel',
			'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);

		/**
		 * Filter allowed chat upload mimes.
		 *
		 * @param array $mimes Allowed mime map.
		 */
		return (array) apply_filters( 'nxtcc_chat_upload_mimes', $mimes );
	}
}

if ( ! function_exists( 'nxtcc_chat_normalize_kind' ) ) {
	/**
	 * Normalize/validate media kind.
	 *
	 * @param string $kind Kind string.
	 * @return string Normalized kind.
	 */
	function nxtcc_chat_normalize_kind( string $kind ): string {
		$kind = strtolower( sanitize_text_field( $kind ) );

		$allowed = array( 'image', 'video', 'audio', 'document', 'sticker' );
		if ( in_array( $kind, $allowed, true ) ) {
			return $kind;
		}

		return 'document';
	}
}

if ( ! function_exists( 'nxtcc_chat_kind_from_mime' ) ) {
	/**
	 * Infer message kind from MIME type.
	 *
	 * @param string $mime Mime type.
	 * @return string Kind.
	 */
	function nxtcc_chat_kind_from_mime( string $mime ): string {
		$mime = strtolower( sanitize_text_field( $mime ) );

		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}

		return 'document';
	}
}

if ( ! function_exists( 'nxtcc_chat_max_upload_bytes' ) ) {
	/**
	 * Max upload size for chat media (filterable).
	 *
	 * @return int Bytes.
	 */
	function nxtcc_chat_max_upload_bytes(): int {
		$default = 10 * 1024 * 1024; // 10MB default.
		$max     = (int) apply_filters( 'nxtcc_chat_max_upload_bytes', $default );

		if ( $max < 1024 * 1024 ) {
			$max = 1024 * 1024; // minimum 1MB.
		}

		return $max;
	}
}

if ( ! function_exists( 'nxtcc_chat_resolve_tenant_context' ) ) {
	/**
	 * Resolve tenant context for the current admin user.
	 *
	 * Does not trust business_account_id/phone_number_id from input. Instead:
	 * - Validates requested phone_number_id belongs to the current user.
	 * - Resolves latest business_account_id for that phone_number_id.
	 *
	 * @param string $requested_pnid Requested phone_number_id (optional).
	 * @return array{0:string,1:string,2:string} user_mailid, phone_number_id, business_account_id
	 */
	function nxtcc_chat_resolve_tenant_context( string $requested_pnid ): array {
		$tenant = NXTCC_Access_Control::get_current_tenant_context();

		$user_mailid         = isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '';
		$phone_number_id     = isset( $tenant['phone_number_id'] ) ? sanitize_text_field( (string) $tenant['phone_number_id'] ) : '';
		$business_account_id = isset( $tenant['business_account_id'] ) ? sanitize_text_field( (string) $tenant['business_account_id'] ) : '';

		if ( '' === $user_mailid || '' === $phone_number_id || '' === $business_account_id ) {
			return array( '', '', '' );
		}

		$requested_pnid = sanitize_text_field( $requested_pnid );
		if ( '' !== $requested_pnid && $requested_pnid !== $phone_number_id ) {
			return array( '', '', '' );
		}

		return array( $user_mailid, $phone_number_id, $business_account_id );
	}
}

/**
 * AJAX handler: Send a text message (optionally with reply context).
 *
 * @return void
 */
function nxtcc_ajax_send_message(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid = '';
	if ( isset( $_POST['phone_number_id'] ) ) {
		$requested_pnid = sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) );
	}

	list( $user_mailid, $phone_number_id, $business_account_id ) = nxtcc_chat_resolve_tenant_context( $requested_pnid );

	if ( '' === $user_mailid ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	if ( '' === $phone_number_id || '' === $business_account_id ) {
		wp_send_json_error( array( 'message' => 'Tenant settings not found.' ), 400 );
	}

	$contact_id = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;

	$message_content = '';
	if ( isset( $_POST['message_content'] ) ) {
		$message_content = sanitize_textarea_field( wp_unslash( $_POST['message_content'] ) );
	}

	$reply_to_message_id = isset( $_POST['reply_to_message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_message_id'] ) ) : '';
	$reply_to_history_id = isset( $_POST['reply_to_history_id'] ) ? absint( wp_unslash( $_POST['reply_to_history_id'] ) ) : 0;

	if ( 0 === $contact_id ) {
		wp_send_json_error( array( 'message' => 'Missing contact id.' ), 400 );
	}

	if ( '' === trim( (string) $message_content ) ) {
		wp_send_json_error( array( 'message' => 'Empty message.' ), 400 );
	}

	// Resolve WAMID if only a history id is provided.
	if ( '' === $reply_to_message_id && 0 < $reply_to_history_id ) {
		if ( ! function_exists( 'nxtcc_chat_repo' ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/chat/chat-helpers.php';
		}
		$repo                = nxtcc_chat_repo();
		$reply_to_message_id = $repo->resolve_reply_to_message_id_by_history( $reply_to_history_id, $user_mailid );
	}

	$args = array(
		'user_mailid'         => $user_mailid,
		'business_account_id' => $business_account_id,
		'phone_number_id'     => $phone_number_id,
		'contact_id'          => $contact_id,
		'message_content'     => $message_content,
		'origin_type'         => 'chat_user',
		'origin_user_id'      => (int) get_current_user_id(),
	);

	if ( '' !== $reply_to_message_id ) {
		$args['reply_to_message_id'] = $reply_to_message_id;
	}

	if ( 0 < $reply_to_history_id ) {
		$args['reply_to_history_id'] = $reply_to_history_id;
	}

	if ( ! function_exists( 'nxtcc_send_message_immediately' ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-send-message.php';
	}

	$result = nxtcc_send_message_immediately( $args );

	if ( is_array( $result ) && ! empty( $result['success'] ) ) {
		wp_send_json_success(
			array(
				'message' => 'Message sent.',
				'meta'    => $result,
			)
		);
	}

	wp_send_json_error(
		array(
			'message' => 'Failed to send message.',
			'error'   => ( is_array( $result ) && isset( $result['error'] ) ) ? (string) $result['error'] : 'Unknown error.',
			'meta'    => is_array( $result ) ? $result : array(),
		),
		500
	);
}
add_action( 'wp_ajax_nxtcc_send_message', 'nxtcc_ajax_send_message' );

/**
 * AJAX handler: Upload media to WP uploads and send by public URL.
 *
 * @return void
 */
function nxtcc_ajax_send_media(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid = isset( $_POST['phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) ) : '';
	list( $user_mailid, $phone_number_id, $business_account_id ) = nxtcc_chat_resolve_tenant_context( $requested_pnid );

	if ( '' === $user_mailid ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	if ( '' === $phone_number_id || '' === $business_account_id ) {
		wp_send_json_error( array( 'message' => 'Tenant settings not found.' ), 400 );
	}

	$contact_id = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
	if ( 0 === $contact_id ) {
		wp_send_json_error( array( 'message' => 'Missing contact id.' ), 400 );
	}

	if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
		wp_send_json_error( array( 'message' => 'Missing file.' ), 400 );
	}

	$file_name = isset( $_FILES['file']['name'] ) ? sanitize_file_name( (string) wp_unslash( $_FILES['file']['name'] ) ) : '';

	/*
	 * tmp_name is a server path, but VIP sniffs still require sanitization.
	 * We sanitize + normalize, then validate using is_uploaded_file().
	 *
	 * IMPORTANT: Do not access $_FILES['file']['tmp_name'] anywhere else in this file.
	 */
	$file_tmp_name = isset( $_FILES['file']['tmp_name'] )
		? wp_normalize_path( sanitize_text_field( (string) wp_unslash( $_FILES['file']['tmp_name'] ) ) )
		: '';

	$file_error = isset( $_FILES['file']['error'] ) ? absint( $_FILES['file']['error'] ) : 0;
	$file_size  = isset( $_FILES['file']['size'] ) ? absint( $_FILES['file']['size'] ) : 0;

	// Type hint is not trusted; only used as a best-effort hint for wp_handle_upload.
	$file_type_hint = isset( $_FILES['file']['type'] ) ? sanitize_text_field( (string) wp_unslash( $_FILES['file']['type'] ) ) : '';

	if ( '' === $file_name || '' === $file_tmp_name ) {
		wp_send_json_error( array( 'message' => 'Missing file.' ), 400 );
	}

	if ( 0 !== $file_error ) {
		wp_send_json_error(
			array(
				'message' => 'Upload failed.',
				'error'   => 'upload_error_' . (string) $file_error,
			),
			400
		);
	}

	if ( $file_size <= 0 ) {
		wp_send_json_error( array( 'message' => 'Empty upload.' ), 400 );
	}

	if ( $file_size > nxtcc_chat_max_upload_bytes() ) {
		wp_send_json_error( array( 'message' => 'File too large.' ), 413 );
	}

	// Ensure this is an actual upload created by PHP.
	if ( ! is_uploaded_file( $file_tmp_name ) ) {
		wp_send_json_error( array( 'message' => 'Invalid upload.' ), 400 );
	}

	// Validate filetype and extension with WordPress.
	$mimes     = nxtcc_chat_allowed_media_mimes();
	$check     = wp_check_filetype_and_ext( $file_tmp_name, $file_name, $mimes );
	$ext       = isset( $check['ext'] ) ? (string) $check['ext'] : '';
	$mime_type = isset( $check['type'] ) ? (string) $check['type'] : '';

	if ( '' === $ext || '' === $mime_type ) {
		wp_send_json_error( array( 'message' => 'File type not allowed.' ), 415 );
	}

	// Build sanitized upload array for wp_handle_upload().
	$file = array(
		'name'     => $file_name,
		'type'     => $file_type_hint,
		'tmp_name' => $file_tmp_name,
		'error'    => $file_error,
		'size'     => $file_size,
	);

	$caption             = isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '';
	$reply_to_message_id = isset( $_POST['reply_to_message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_message_id'] ) ) : '';
	$reply_to_history_id = isset( $_POST['reply_to_history_id'] ) ? absint( wp_unslash( $_POST['reply_to_history_id'] ) ) : 0;

	// Resolve WAMID if only history id is provided.
	if ( '' === $reply_to_message_id && 0 < $reply_to_history_id ) {
		if ( ! function_exists( 'nxtcc_chat_repo' ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/chat/chat-helpers.php';
		}
		$repo                = nxtcc_chat_repo();
		$reply_to_message_id = $repo->resolve_reply_to_message_id_by_history( $reply_to_history_id, $user_mailid );
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$uploaded = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => $mimes,
		)
	);

	if ( ! is_array( $uploaded ) || isset( $uploaded['error'] ) ) {
		wp_send_json_error(
			array(
				'message' => 'Upload failed.',
				'error'   => isset( $uploaded['error'] ) ? (string) $uploaded['error'] : 'Unknown upload error.',
			),
			500
		);
	}

	$url  = isset( $uploaded['url'] ) ? esc_url_raw( (string) $uploaded['url'] ) : '';
	$type = isset( $uploaded['type'] ) ? sanitize_text_field( (string) $uploaded['type'] ) : '';
	$path = isset( $uploaded['file'] ) ? (string) $uploaded['file'] : '';

	if ( '' === $url ) {
		wp_send_json_error( array( 'message' => 'Upload failed.' ), 500 );
	}

	$kind = nxtcc_chat_kind_from_mime( $type );
	$base = ( '' !== $path ) ? basename( $path ) : '';
	$base = sanitize_file_name( $base );

	$payload = array(
		'user_mailid'         => $user_mailid,
		'business_account_id' => $business_account_id,
		'phone_number_id'     => $phone_number_id,
		'contact_id'          => $contact_id,
		'kind'                => $kind,
		'link'                => $url,
		'local_path'          => wp_normalize_path( (string) $path ),
		'mime_type'           => $type,
		'filename'            => $base,
		'caption'             => $caption,
		'origin_type'         => 'chat_user',
		'origin_user_id'      => (int) get_current_user_id(),
	);

	if ( '' !== $reply_to_message_id ) {
		$payload['reply_to_message_id'] = $reply_to_message_id;
	}

	if ( 0 < $reply_to_history_id ) {
		$payload['reply_to_history_id'] = $reply_to_history_id;
	}

	if ( ! function_exists( 'nxtcc_send_media_link_immediately' ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-send-message.php';
	}

	$result = nxtcc_send_media_link_immediately( $payload );

	if ( is_array( $result ) && ! empty( $result['success'] ) ) {
		wp_send_json_success(
			array(
				'message' => 'Media sent.',
				'meta'    => $result,
			)
		);
	}

	wp_send_json_error(
		array(
			'message' => 'Failed to send media.',
			'error'   => ( is_array( $result ) && isset( $result['error'] ) ) ? (string) $result['error'] : 'Unknown error.',
			'meta'    => is_array( $result ) ? $result : array(),
		),
		500
	);
}
add_action( 'wp_ajax_nxtcc_send_media', 'nxtcc_ajax_send_media' );

/**
 * AJAX handler: Send media using an existing public URL.
 *
 * @return void
 */
function nxtcc_ajax_send_media_by_url(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid = isset( $_POST['phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) ) : '';
	list( $user_mailid, $phone_number_id, $business_account_id ) = nxtcc_chat_resolve_tenant_context( $requested_pnid );

	if ( '' === $user_mailid ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	if ( '' === $phone_number_id || '' === $business_account_id ) {
		wp_send_json_error( array( 'message' => 'Tenant settings not found.' ), 400 );
	}

	$contact_id = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;

	$kind_raw = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : '';
	$kind     = nxtcc_chat_normalize_kind( $kind_raw );

	$link     = isset( $_POST['media_url'] ) ? esc_url_raw( wp_unslash( $_POST['media_url'] ) ) : '';
	$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
	$caption  = isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '';

	$reply_to_message_id = isset( $_POST['reply_to_message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_to_message_id'] ) ) : '';
	$reply_to_history_id = isset( $_POST['reply_to_history_id'] ) ? absint( wp_unslash( $_POST['reply_to_history_id'] ) ) : 0;

	// Resolve WAMID if only history id is provided.
	if ( '' === $reply_to_message_id && 0 < $reply_to_history_id ) {
		if ( ! function_exists( 'nxtcc_chat_repo' ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/chat/chat-helpers.php';
		}
		$repo                = nxtcc_chat_repo();
		$reply_to_message_id = $repo->resolve_reply_to_message_id_by_history( $reply_to_history_id, $user_mailid );
	}

	if ( 0 === $contact_id || '' === $link ) {
		wp_send_json_error( array( 'message' => 'Missing contact or media URL.' ), 400 );
	}

	$args = array(
		'user_mailid'         => $user_mailid,
		'business_account_id' => $business_account_id,
		'phone_number_id'     => $phone_number_id,
		'contact_id'          => $contact_id,
		'kind'                => $kind,
		'link'                => $link,
		'filename'            => $filename,
		'caption'             => $caption,
		'origin_type'         => 'chat_user',
		'origin_user_id'      => (int) get_current_user_id(),
	);

	if ( '' !== $reply_to_message_id ) {
		$args['reply_to_message_id'] = $reply_to_message_id;
	}

	if ( 0 < $reply_to_history_id ) {
		$args['reply_to_history_id'] = $reply_to_history_id;
	}

	if ( ! function_exists( 'nxtcc_send_media_link_immediately' ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-send-message.php';
	}

	$result = nxtcc_send_media_link_immediately( $args );

	if ( is_array( $result ) && ! empty( $result['success'] ) ) {
		wp_send_json_success(
			array(
				'message' => 'Media sent.',
				'meta'    => $result,
			)
		);
	}

	wp_send_json_error(
		array(
			'message' => 'Failed to send media.',
			'error'   => ( is_array( $result ) && isset( $result['error'] ) ) ? (string) $result['error'] : 'Unknown error.',
			'meta'    => is_array( $result ) ? $result : array(),
		),
		500
	);
}
add_action( 'wp_ajax_nxtcc_send_media_by_url', 'nxtcc_ajax_send_media_by_url' );
