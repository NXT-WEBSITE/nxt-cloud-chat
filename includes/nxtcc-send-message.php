<?php
/**
 * Send message helpers (text & media).
 *
 * Provides functional wrappers to send WhatsApp Cloud API messages and store
 * message history rows. This file is functions-only to satisfy PHPCS rules.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-db-sendmessage.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-send-dao.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';
require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-remote.php';

if ( class_exists( 'NXTCC_DAO' ) ) {
	NXTCC_DAO::init();
}

/**
 * Static knowledge of wp_nxtcc_message_history columns.
 *
 * Avoids schema inspection queries. Extend this map when schema changes.
 *
 * @return array<string, bool>
 */
function nxtcc_mh_columns(): array {
	static $cols = null;

	if ( null === $cols ) {
		$cols = array(
			'user_mailid'          => true,
			'business_account_id'  => true,
			'phone_number_id'      => true,
			'contact_id'           => true,
			'display_phone_number' => true,
			'message_content'      => true,
			'status'               => true,
			'meta_message_id'      => true,
			'status_timestamps'    => true,
			'last_error'           => true,
			'origin_type'          => true,
			'origin_user_id'       => true,
			'origin_ref'           => true,
			'response_json'        => true,
			'created_at'           => true,
			'sent_at'              => true,
			'delivered_at'         => true,
			'read_at'              => true,
			'failed_at'            => true,
			'is_read'              => true,
			'reply_to_wamid'       => true,
			'reply_to_history_id'  => true,
		);
	}

	return $cols;
}

/**
 * Check if a message-history column exists in our static map.
 *
 * @param string $col Column name.
 * @return bool
 */
function nxtcc_mh_has_column( string $col ): bool {
	$cols = nxtcc_mh_columns();
	return isset( $cols[ $col ] );
}

/**
 * Get a column max-length used for safe clipping.
 *
 * @param string $col Column name.
 * @return int|null
 */
function nxtcc_mh_col_maxlen( string $col ): ?int {
	static $max = array(
		'reply_to_wamid'       => 191,
		'meta_message_id'      => 191,
		'display_phone_number' => 32,
	);

	return isset( $max[ $col ] ) ? (int) $max[ $col ] : null;
}

/**
 * Decrypt access token for a (user, business_account_id, phone_number_id) tuple.
 *
 * @param string $user_mailid         User email.
 * @param string $business_account_id Business account ID.
 * @param string $phone_number_id     Phone number ID.
 * @return string|\WP_Error
 */
function nxtcc_get_decrypted_token( string $user_mailid, string $business_account_id, string $phone_number_id ) {
	$row = NXTCC_Send_DAO::get_settings_row( $user_mailid, $business_account_id, $phone_number_id );

	if ( ! $row ) {
		return new WP_Error( 'nxtcc_token_missing', 'Access token not found.' );
	}

	$token = nxtcc_crypto_decrypt(
		isset( $row->access_token_ct ) ? (string) $row->access_token_ct : null,
		isset( $row->access_token_nonce ) ? $row->access_token_nonce : null
	);

	if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
		return new WP_Error( 'nxtcc_token_decrypt_failed', 'Access token decryption failed.' );
	}

	return $token;
}

/**
 * Normalize recipient number into digits-only (E.164 digits).
 *
 * @param string $cc  Country code digits.
 * @param string $num Phone number digits.
 * @return string
 */
function nxtcc_normalize_recipient( string $cc, string $num ): string {
	$raw    = trim( $cc . $num );
	$digits = preg_replace( '/\D+/', '', $raw );

	if ( ! is_string( $digits ) ) {
		$digits = '';
	}

	$max = nxtcc_mh_col_maxlen( 'display_phone_number' );
	if ( null !== $max && strlen( $digits ) > $max ) {
		$digits = substr( $digits, 0, $max );
	}

	return $digits;
}

/**
 * Clip Meta message IDs if the schema has a limit.
 *
 * @param string|null $id Meta message ID.
 * @return string|null
 */
function nxtcc_clip_meta_message_id( ?string $id ): ?string {
	if ( null === $id || '' === $id ) {
		return null;
	}

	$max = nxtcc_mh_col_maxlen( 'meta_message_id' );
	if ( null !== $max && strlen( $id ) > $max ) {
		return substr( $id, 0, $max );
	}

	return $id;
}

/**
 * Validate/normalize a WhatsApp message "kind".
 *
 * @param string $kind Input kind.
 * @return string Normalized kind.
 */
function nxtcc_normalize_kind( string $kind ): string {
	$kind = strtolower( sanitize_text_field( $kind ) );

	$allowed = array( 'image', 'video', 'audio', 'document', 'sticker' );
	if ( in_array( $kind, $allowed, true ) ) {
		return $kind;
	}

	return 'document';
}

/**
 * Validate and clip reply-to message id (WAMID / Graph id).
 *
 * @param string $wamid Input id.
 * @return string Cleaned id (may be empty).
 */
function nxtcc_normalize_reply_wamid( string $wamid ): string {
	$wamid = sanitize_text_field( $wamid );
	$wamid = trim( $wamid );

	if ( '' === $wamid ) {
		return '';
	}

	if ( function_exists( 'nxtcc_clip_meta_message_id' ) ) {
		$clipped = nxtcc_clip_meta_message_id( $wamid );
		return is_string( $clipped ) ? sanitize_text_field( $clipped ) : '';
	}

	$max = nxtcc_mh_col_maxlen( 'reply_to_wamid' );
	if ( null !== $max && strlen( $wamid ) > $max ) {
		$wamid = substr( $wamid, 0, $max );
	}

	return sanitize_text_field( $wamid );
}

/**
 * Safe JSON encode helper (returns empty string on failure).
 *
 * @param mixed $value Value.
 * @return string
 */
function nxtcc_json_encode_safe( $value ): string {
	$json = wp_json_encode( $value );
	return is_string( $json ) ? $json : '';
}

/**
 * Validate a local path as a file inside WordPress uploads directory.
 *
 * @param string $path Candidate absolute path.
 * @return string Safe normalized path or empty string.
 */
function nxtcc_validate_upload_local_path( string $path ): string {
	$path = wp_normalize_path( trim( $path ) );
	if ( '' === $path ) {
		return '';
	}

	$uploads = wp_get_upload_dir();
	$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	$basedir = wp_normalize_path( trailingslashit( $basedir ) );

	if ( '' === $basedir || 0 !== strpos( $path, $basedir ) ) {
		return '';
	}

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return '';
	}

	return $path;
}

/**
 * Map a public uploads URL to a validated local uploads path.
 *
 * @param string $url Public media URL.
 * @return string Local path or empty string.
 */
function nxtcc_upload_url_to_local_path( string $url ): string {
	$url = esc_url_raw( trim( $url ) );
	if ( '' === $url ) {
		return '';
	}

	$uploads = wp_get_upload_dir();
	$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
	$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

	$baseurl = trailingslashit( $baseurl );
	$basedir = trailingslashit( $basedir );

	if ( '' === $baseurl || '' === $basedir || 0 !== strpos( $url, $baseurl ) ) {
		return '';
	}

	$relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );
	$relative = str_replace( array( '../', '..\\' ), '', (string) $relative );

	$path = wp_normalize_path( $basedir . $relative );
	return nxtcc_validate_upload_local_path( $path );
}

/**
 * Upload a local file to WhatsApp media endpoint and return media id.
 *
 * @param string $phone_number_id Phone number id.
 * @param string $token           Access token.
 * @param string $file_path       Local absolute file path.
 * @param string $mime_type       Mime type.
 * @param string $filename        Filename to report to API.
 * @return array<string,mixed> Result shape: success(bool), id(string), error(string), http_code(int), response(array)
 */
function nxtcc_upload_media_to_whatsapp( string $phone_number_id, string $token, string $file_path, string $mime_type, string $filename ): array {
	$phone_number_id = sanitize_text_field( $phone_number_id );
	$token           = sanitize_text_field( $token );
	$file_path       = nxtcc_validate_upload_local_path( $file_path );
	$mime_type       = sanitize_text_field( $mime_type );
	$filename        = sanitize_file_name( $filename );

	if ( '' === $phone_number_id || '' === $token || '' === $file_path ) {
		return array(
			'success' => false,
			'error'   => 'invalid_media_upload_input',
		);
	}

	if ( '' === $filename ) {
		$filename = sanitize_file_name( wp_basename( $file_path ) );
	}

	if ( '' === $mime_type ) {
		$ft        = wp_check_filetype( $filename );
		$mime_type = isset( $ft['type'] ) ? (string) $ft['type'] : '';
	}
	if ( '' === $mime_type ) {
		$mime_type = 'application/octet-stream';
	}

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	global $wp_filesystem;
	if ( empty( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
		WP_Filesystem();
	}

	$file_bytes = ( is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'get_contents' ) )
		? $wp_filesystem->get_contents( $file_path )
		: false;
	if ( false === $file_bytes || '' === $file_bytes ) {
		return array(
			'success' => false,
			'error'   => 'media_file_read_failed',
		);
	}

	$url      = 'https://graph.facebook.com/v19.0/' . rawurlencode( $phone_number_id ) . '/media';
	$eol      = "\r\n";
	$boundary = '--------------------------nxtcc' . wp_generate_password( 12, false, false );

	$multipart_body  = '--' . $boundary . $eol;
	$multipart_body .= 'Content-Disposition: form-data; name="messaging_product"' . $eol . $eol;
	$multipart_body .= 'whatsapp' . $eol;
	$multipart_body .= '--' . $boundary . $eol;
	$multipart_body .= 'Content-Disposition: form-data; name="type"' . $eol . $eol;
	$multipart_body .= $mime_type . $eol;
	$multipart_body .= '--' . $boundary . $eol;
	$multipart_body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
	$multipart_body .= 'Content-Type: ' . $mime_type . $eol . $eol;
	$multipart_body .= $file_bytes . $eol;
	$multipart_body .= '--' . $boundary . '--' . $eol;

	$response = nxtcc_safe_remote_post(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $multipart_body,
			'timeout' => 3,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'error'   => $response->get_error_message(),
		);
	}

	$http_code = (int) wp_remote_retrieve_response_code( $response );
	$raw       = wp_remote_retrieve_body( $response );
	$parsed    = json_decode( (string) $raw, true );
	$parsed    = is_array( $parsed ) ? $parsed : array();

	if ( $http_code >= 200 && $http_code < 300 && ! empty( $parsed['id'] ) ) {
		return array(
			'success' => true,
			'id'      => sanitize_text_field( (string) $parsed['id'] ),
		);
	}

	$error = 'media_upload_failed';
	if ( ! empty( $parsed['error']['message'] ) ) {
		$error = sanitize_text_field( (string) $parsed['error']['message'] );
	}

	return array(
		'success'   => false,
		'error'     => $error,
		'http_code' => $http_code,
		'response'  => $parsed,
	);
}

/**
 * Normalize media caption for WhatsApp API payloads.
 *
 * WhatsApp Cloud API media captions are limited to 1024 chars.
 *
 * @param string $caption Raw caption.
 * @return string Sanitized caption (possibly truncated).
 */
function nxtcc_normalize_media_caption( string $caption ): string {
	$caption = sanitize_textarea_field( $caption );
	$caption = trim( $caption );

	if ( '' === $caption ) {
		return '';
	}

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $caption, 'UTF-8' ) > 1024 ) {
			$caption = mb_substr( $caption, 0, 1024, 'UTF-8' );
		}
	} elseif ( strlen( $caption ) > 1024 ) {
		$caption = substr( $caption, 0, 1024 );
	}

	return $caption;
}

/**
 * Verify that the current admin user may send chat traffic for the given tenant.
 *
 * @param string $user_mailid         Tenant owner email.
 * @param string $business_account_id Tenant business account ID.
 * @param string $phone_number_id     Tenant phone number ID.
 * @return bool
 */
function nxtcc_current_user_can_access_chat_tenant( string $user_mailid, string $business_account_id, string $phone_number_id ): bool {
	if ( ! is_user_logged_in() || ! class_exists( 'NXTCC_Access_Control' ) ) {
		return false;
	}

	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
		return false;
	}

	$tenant = NXTCC_Access_Control::get_current_tenant_context();

	return (
		isset( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] )
		&& sanitize_email( (string) $tenant['user_mailid'] ) === $user_mailid
		&& sanitize_text_field( (string) $tenant['business_account_id'] ) === $business_account_id
		&& sanitize_text_field( (string) $tenant['phone_number_id'] ) === $phone_number_id
	);
}

/**
 * Internal shared helper for sending a text message and inserting history.
 *
 * @param array $args              Input args.
 * @param bool  $require_user_auth Whether a logged-in user check is required.
 * @return array<string, mixed>
 */
function nxtcc_send_text_message_internal( array $args, bool $require_user_auth = true ): array {
	global $wpdb;

	$required = array( 'user_mailid', 'business_account_id', 'phone_number_id', 'contact_id', 'message_content' );
	foreach ( $required as $key ) {
		if ( ! isset( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
			return array(
				'success' => false,
				'error'   => 'Missing param: ' . $key,
			);
		}
	}

	$user_mailid         = sanitize_email( (string) $args['user_mailid'] );
	$business_account_id = sanitize_text_field( (string) $args['business_account_id'] );
	$phone_number_id     = sanitize_text_field( (string) $args['phone_number_id'] );
	$contact_id          = (int) $args['contact_id'];

	$message_content = sanitize_textarea_field( (string) $args['message_content'] );
	$message_content = trim( $message_content );

	$reply_to_message_id = isset( $args['reply_to_message_id'] ) ? nxtcc_normalize_reply_wamid( (string) $args['reply_to_message_id'] ) : '';
	$reply_to_history_id = isset( $args['reply_to_history_id'] ) ? (int) $args['reply_to_history_id'] : 0;
	$origin_type         = isset( $args['origin_type'] ) ? sanitize_key( (string) $args['origin_type'] ) : ( $require_user_auth ? 'chat_user' : 'system' );
	$origin_user_id      = isset( $args['origin_user_id'] ) ? (int) $args['origin_user_id'] : ( $require_user_auth ? (int) get_current_user_id() : 0 );
	$origin_ref          = isset( $args['origin_ref'] ) ? sanitize_text_field( (string) $args['origin_ref'] ) : '';

	if ( ! in_array( $origin_type, array( 'inbound', 'chat_user', 'broadcast', 'workflow', 'system' ), true ) ) {
		$origin_type = $require_user_auth ? 'chat_user' : 'system';
	}

	if ( strlen( $origin_ref ) > 191 ) {
		$origin_ref = substr( $origin_ref, 0, 191 );
	}

	if ( $require_user_auth ) {
		if ( ! nxtcc_current_user_can_access_chat_tenant( $user_mailid, $business_account_id, $phone_number_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Unauthorized',
			);
		}
	}

	if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id || 0 === $contact_id ) {
		return array(
			'success' => false,
			'error'   => 'Invalid input',
		);
	}

	if ( '' === $message_content ) {
		return array(
			'success' => false,
			'error'   => 'Empty message',
		);
	}

	$contact = NXTCC_Send_DAO::get_contact_row( $contact_id, $user_mailid );
	if ( ! $contact ) {
		return array(
			'success' => false,
			'error'   => 'Invalid contact',
		);
	}

	$recipient = nxtcc_normalize_recipient(
		isset( $contact->country_code ) ? (string) $contact->country_code : '',
		isset( $contact->phone_number ) ? (string) $contact->phone_number : ''
	);

	if ( '' === $recipient ) {
		return array(
			'success' => false,
			'error'   => 'Invalid recipient number',
		);
	}

	$token = nxtcc_get_decrypted_token( $user_mailid, $business_account_id, $phone_number_id );
	if ( is_wp_error( $token ) ) {
		return array(
			'success' => false,
			'error'   => $token->get_error_message(),
		);
	}

	$url = 'https://graph.facebook.com/v19.0/' . rawurlencode( $phone_number_id ) . '/messages';

	$payload = array(
		'messaging_product' => 'whatsapp',
		'to'                => $recipient,
		'type'              => 'text',
		'text'              => array( 'body' => $message_content ),
	);

	if ( '' !== $reply_to_message_id ) {
		$payload['context'] = array( 'message_id' => $reply_to_message_id );
	}

	$body_json = nxtcc_json_encode_safe( $payload );
	if ( '' === $body_json ) {
		return array(
			'success' => false,
			'error'   => 'json_encode_failed',
		);
	}

	$response = nxtcc_safe_remote_post(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body_json,
			'timeout' => 3,
		)
	);

	$timestamp = current_time( 'mysql', 1 );
	$meta_raw  = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
	$parsed    = array();

	if ( is_string( $meta_raw ) ) {
		$decoded = json_decode( $meta_raw, true );
		if ( is_array( $decoded ) ) {
			$parsed = $decoded;
		}
	}

	$meta_status = 'failed';
	$meta_msg_id = null;
	$meta_err    = null;
	$timestamps  = array();

	if ( ! is_wp_error( $response ) && ! empty( $parsed['messages'][0]['id'] ) ) {
		$meta_status        = 'sent';
		$meta_msg_id        = nxtcc_clip_meta_message_id( (string) $parsed['messages'][0]['id'] );
		$timestamps['sent'] = $timestamp;
	} elseif ( ! is_wp_error( $response ) && ! empty( $parsed['error'] ) ) {
		$meta_err = isset( $parsed['error']['message'] ) ? (string) $parsed['error']['message'] : 'Graph error';
	} else {
		$meta_err = is_wp_error( $response ) ? $response->get_error_message() : null;
	}

	$row = array(
		'user_mailid'          => $user_mailid,
		'business_account_id'  => $business_account_id,
		'phone_number_id'      => $phone_number_id,
		'contact_id'           => $contact_id,
		'display_phone_number' => $recipient,
		'message_content'      => $message_content,
		'status'               => $meta_status,
		'meta_message_id'      => $meta_msg_id,
		'status_timestamps'    => nxtcc_json_encode_safe( $timestamps ),
		'last_error'           => $meta_err,
		'response_json'        => is_string( $meta_raw ) ? (string) $meta_raw : nxtcc_json_encode_safe( $parsed ),
		'created_at'           => $timestamp,
		'sent_at'              => isset( $timestamps['sent'] ) ? $timestamps['sent'] : null,
		'delivered_at'         => null,
		'read_at'              => null,
		'failed_at'            => ( 'failed' === $meta_status ) ? $timestamp : null,
		'is_read'              => 1,
	);

	if ( nxtcc_mh_has_column( 'origin_type' ) ) {
		$row['origin_type'] = $origin_type;
	}

	if ( $origin_user_id > 0 && nxtcc_mh_has_column( 'origin_user_id' ) ) {
		$row['origin_user_id'] = $origin_user_id;
	}

	if ( '' !== $origin_ref && nxtcc_mh_has_column( 'origin_ref' ) ) {
		$row['origin_ref'] = $origin_ref;
	}

	if ( 0 === $reply_to_history_id && '' !== $reply_to_message_id && nxtcc_mh_has_column( 'reply_to_history_id' ) ) {
		$reply_to_history_id = NXTCC_Send_DAO::get_history_id_by_wamid( $reply_to_message_id );
	}

	if ( 0 !== $reply_to_history_id && nxtcc_mh_has_column( 'reply_to_history_id' ) ) {
		$row['reply_to_history_id'] = $reply_to_history_id;
	}

	if ( '' !== $reply_to_message_id && nxtcc_mh_has_column( 'reply_to_wamid' ) ) {
		$row['reply_to_wamid'] = $reply_to_message_id;
	}

	$ok = NXTCC_Send_DAO::insert_history( $row );

	if ( ! $ok ) {
		$db_err = ( is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ? $wpdb->last_error : 'insert_failed';

		return array(
			'success'       => false,
			'status'        => $meta_status,
			'error'         => 'db_insert_failed',
			'db_error'      => $db_err,
			'meta_response' => $parsed,
		);
	}

	return array(
		'success'             => ( 'sent' === $meta_status ),
		'status'              => $meta_status,
		'meta_response'       => $parsed,
		'insert_id'           => (int) $wpdb->insert_id,
		'meta_message_id'     => $meta_msg_id,
		'reply_to_message_id' => ( '' !== $reply_to_message_id ) ? $reply_to_message_id : null,
	);
}

/**
 * Send a text message via WhatsApp Cloud API and insert history.
 *
 * Required args:
 * - user_mailid
 * - business_account_id
 * - phone_number_id
 * - contact_id
 * - message_content
 *
 * Optional args:
 * - reply_to_message_id
 * - reply_to_history_id
 *
 * @param array $args Input args.
 * @return array<string, mixed>
 */
function nxtcc_send_message_immediately( array $args ): array {
	return nxtcc_send_text_message_internal( $args, true );
}

/**
 * Send a media message (link) via WhatsApp Cloud API and insert history.
 *
 * Required args:
 * - user_mailid
 * - business_account_id
 * - phone_number_id
 * - contact_id
 * - kind (image|video|audio|document|sticker)
 * - link
 *
 * Optional args:
 * - caption
 * - filename
 * - mime_type
 * - reply_to_message_id
 * - reply_to_history_id
 *
 * @param array $args Input args.
 * @return array<string, mixed>
 */
function nxtcc_send_media_link_immediately( array $args ): array {
	global $wpdb;

	$required = array( 'user_mailid', 'business_account_id', 'phone_number_id', 'contact_id', 'kind', 'link' );
	foreach ( $required as $key ) {
		if ( ! isset( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
			return array(
				'success' => false,
				'error'   => 'Missing param: ' . $key,
			);
		}
	}

	$user_mailid         = sanitize_email( (string) $args['user_mailid'] );
	$business_account_id = sanitize_text_field( (string) $args['business_account_id'] );
	$phone_number_id     = sanitize_text_field( (string) $args['phone_number_id'] );
	$contact_id          = (int) $args['contact_id'];

	$kind_in             = nxtcc_normalize_kind( (string) $args['kind'] );
	$link                = esc_url_raw( (string) $args['link'] );
	$caption             = isset( $args['caption'] ) ? nxtcc_normalize_media_caption( (string) $args['caption'] ) : '';
	$filename            = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
	$mime_type           = isset( $args['mime_type'] ) ? sanitize_text_field( (string) $args['mime_type'] ) : '';
	$local_path          = isset( $args['local_path'] ) ? nxtcc_validate_upload_local_path( (string) $args['local_path'] ) : '';
	$reply_to_message_id = isset( $args['reply_to_message_id'] ) ? nxtcc_normalize_reply_wamid( (string) $args['reply_to_message_id'] ) : '';
	$reply_to_history_id = isset( $args['reply_to_history_id'] ) ? (int) $args['reply_to_history_id'] : 0;
	$origin_type         = isset( $args['origin_type'] ) ? sanitize_key( (string) $args['origin_type'] ) : 'chat_user';
	$origin_user_id      = isset( $args['origin_user_id'] ) ? (int) $args['origin_user_id'] : (int) get_current_user_id();
	$origin_ref          = isset( $args['origin_ref'] ) ? sanitize_text_field( (string) $args['origin_ref'] ) : '';

	if ( ! in_array( $origin_type, array( 'inbound', 'chat_user', 'broadcast', 'workflow', 'system' ), true ) ) {
		$origin_type = 'chat_user';
	}

	if ( strlen( $origin_ref ) > 191 ) {
		$origin_ref = substr( $origin_ref, 0, 191 );
	}

	if ( ! nxtcc_current_user_can_access_chat_tenant( $user_mailid, $business_account_id, $phone_number_id ) ) {
		return array(
			'success' => false,
			'error'   => 'Unauthorized',
		);
	}

	if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id || 0 === $contact_id || '' === $link ) {
		return array(
			'success' => false,
			'error'   => 'Invalid input',
		);
	}

	$contact = NXTCC_Send_DAO::get_contact_row( $contact_id, $user_mailid );
	if ( ! $contact ) {
		return array(
			'success' => false,
			'error'   => 'Invalid contact',
		);
	}

	$recipient = nxtcc_normalize_recipient(
		isset( $contact->country_code ) ? (string) $contact->country_code : '',
		isset( $contact->phone_number ) ? (string) $contact->phone_number : ''
	);

	if ( '' === $recipient ) {
		return array(
			'success' => false,
			'error'   => 'Invalid recipient number',
		);
	}

	$token = nxtcc_get_decrypted_token( $user_mailid, $business_account_id, $phone_number_id );
	if ( is_wp_error( $token ) ) {
		return array(
			'success' => false,
			'error'   => $token->get_error_message(),
		);
	}

	$url = 'https://graph.facebook.com/v19.0/' . rawurlencode( $phone_number_id ) . '/messages';

	// Keep your existing behavior: sticker sends as image link (not an actual WA "sticker" type).
	$kind_for_api = ( 'sticker' === $kind_in ) ? 'image' : $kind_in;
	$media_id     = '';
	$upload_error = '';

	// Prefer media_id for local uploads to avoid 3rd-party fetch/rate-limit failures on weblinks.
	if ( '' === $local_path ) {
		$local_path = nxtcc_upload_url_to_local_path( $link );
	}

	if ( '' !== $local_path ) {
		$upload_result = nxtcc_upload_media_to_whatsapp(
			$phone_number_id,
			$token,
			$local_path,
			$mime_type,
			$filename
		);

		if ( ! empty( $upload_result['success'] ) && ! empty( $upload_result['id'] ) ) {
			$media_id = (string) $upload_result['id'];
		} else {
			$upload_error = isset( $upload_result['error'] ) ? (string) $upload_result['error'] : 'media_upload_failed';
		}
	}

	$payload = array(
		'messaging_product' => 'whatsapp',
		'to'                => $recipient,
	);

	if ( 'image' === $kind_for_api ) {
		$payload['type']  = 'image';
		$payload['image'] = ( '' !== $media_id ) ? array( 'id' => $media_id ) : array( 'link' => $link );
		if ( '' !== $caption ) {
			$payload['image']['caption'] = $caption;
		}
	} elseif ( 'video' === $kind_for_api ) {
		$payload['type']  = 'video';
		$payload['video'] = ( '' !== $media_id ) ? array( 'id' => $media_id ) : array( 'link' => $link );
		if ( '' !== $caption ) {
			$payload['video']['caption'] = $caption;
		}
	} elseif ( 'audio' === $kind_for_api ) {
		$payload['type']  = 'audio';
		$payload['audio'] = ( '' !== $media_id ) ? array( 'id' => $media_id ) : array( 'link' => $link );
	} else {
		$payload['type']     = 'document';
		$payload['document'] = ( '' !== $media_id ) ? array( 'id' => $media_id ) : array( 'link' => $link );
		if ( '' !== $caption ) {
			$payload['document']['caption'] = $caption;
		}
		if ( '' !== $filename ) {
			$payload['document']['filename'] = $filename;
		}
	}

	if ( '' !== $reply_to_message_id ) {
		$payload['context'] = array( 'message_id' => $reply_to_message_id );
	}

	$body_json = nxtcc_json_encode_safe( $payload );
	if ( '' === $body_json ) {
		return array(
			'success' => false,
			'error'   => 'json_encode_failed',
		);
	}

	$response = nxtcc_safe_remote_post(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body_json,
			'timeout' => 3,
		)
	);

	$timestamp = current_time( 'mysql', 1 );
	$meta_raw  = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
	$parsed    = array();

	if ( is_string( $meta_raw ) ) {
		$decoded = json_decode( $meta_raw, true );
		if ( is_array( $decoded ) ) {
			$parsed = $decoded;
		}
	}

	$meta_status = 'failed';
	$meta_msg_id = null;
	$meta_err    = null;
	$timestamps  = array();

	if ( ! is_wp_error( $response ) && ! empty( $parsed['messages'][0]['id'] ) ) {
		$meta_status        = 'sent';
		$meta_msg_id        = nxtcc_clip_meta_message_id( (string) $parsed['messages'][0]['id'] );
		$timestamps['sent'] = $timestamp;
	} elseif ( ! is_wp_error( $response ) && ! empty( $parsed['error'] ) ) {
		$meta_err = isset( $parsed['error']['message'] ) ? (string) $parsed['error']['message'] : 'Graph error';
	} else {
		$meta_err = is_wp_error( $response ) ? $response->get_error_message() : null;
		if ( '' === (string) $meta_err && '' !== $upload_error ) {
			$meta_err = $upload_error;
		}
	}

	$normalized = array(
		'kind'      => $kind_in,
		'link'      => $link,
		'media_id'  => ( '' !== $media_id ) ? $media_id : null,
		'caption'   => ( '' !== $caption ) ? $caption : null,
		'filename'  => ( '' !== $filename ) ? $filename : null,
		'mime_type' => ( '' !== $mime_type ) ? $mime_type : null,
	);

	$row = array(
		'user_mailid'          => $user_mailid,
		'business_account_id'  => $business_account_id,
		'phone_number_id'      => $phone_number_id,
		'contact_id'           => $contact_id,
		'display_phone_number' => $recipient,
		'message_content'      => nxtcc_json_encode_safe( $normalized ),
		'status'               => $meta_status,
		'meta_message_id'      => $meta_msg_id,
		'status_timestamps'    => nxtcc_json_encode_safe( $timestamps ),
		'last_error'           => $meta_err,
		'response_json'        => is_string( $meta_raw ) ? (string) $meta_raw : nxtcc_json_encode_safe( $parsed ),
		'created_at'           => $timestamp,
		'sent_at'              => isset( $timestamps['sent'] ) ? $timestamps['sent'] : null,
		'delivered_at'         => null,
		'read_at'              => null,
		'failed_at'            => ( 'failed' === $meta_status ) ? $timestamp : null,
		'is_read'              => 1,
	);

	if ( nxtcc_mh_has_column( 'origin_type' ) ) {
		$row['origin_type'] = $origin_type;
	}

	if ( $origin_user_id > 0 && nxtcc_mh_has_column( 'origin_user_id' ) ) {
		$row['origin_user_id'] = $origin_user_id;
	}

	if ( '' !== $origin_ref && nxtcc_mh_has_column( 'origin_ref' ) ) {
		$row['origin_ref'] = $origin_ref;
	}

	if ( 0 === $reply_to_history_id && '' !== $reply_to_message_id && nxtcc_mh_has_column( 'reply_to_history_id' ) ) {
		$reply_to_history_id = NXTCC_Send_DAO::get_history_id_by_wamid( $reply_to_message_id );
	}

	if ( 0 !== $reply_to_history_id && nxtcc_mh_has_column( 'reply_to_history_id' ) ) {
		$row['reply_to_history_id'] = $reply_to_history_id;
	}

	if ( '' !== $reply_to_message_id && nxtcc_mh_has_column( 'reply_to_wamid' ) ) {
		$row['reply_to_wamid'] = $reply_to_message_id;
	}

	$ok = NXTCC_Send_DAO::insert_history( $row );

	if ( ! $ok ) {
		$db_err = ( is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ? $wpdb->last_error : 'insert_failed';

		return array(
			'success'       => false,
			'status'        => $meta_status,
			'error'         => 'db_insert_failed',
			'db_error'      => $db_err,
			'meta_response' => $parsed,
		);
	}

	$out = array(
		'success'         => ( 'sent' === $meta_status ),
		'status'          => $meta_status,
		'meta_response'   => $parsed,
		'insert_id'       => (int) $wpdb->insert_id,
		'meta_message_id' => $meta_msg_id,
	);

	if ( 'sent' !== $meta_status ) {
		$out['error'] = ( is_string( $meta_err ) && '' !== $meta_err ) ? $meta_err : 'Graph error';
	}

	return $out;
}
