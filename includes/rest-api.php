<?php
/**
 * REST API endpoints + utility functions.
 *
 * IMPORTANT:
 * - Functions only (no classes) to satisfy PHPCS "no mixed OO + functions".
 * - Webhook GET verification must echo hub.challenge as plain text and exit.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_rest_quote_table_name' ) ) {
	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	function nxtcc_rest_quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}
		return '`' . $clean . '`';
	}
}

if ( ! function_exists( 'nxtcc_rest_sql_with_table_tokens' ) ) {
	/**
	 * Replace table tokens in SQL like {history}, {contacts}, {user_settings}.
	 *
	 * @param string $query     SQL with table tokens.
	 * @param array  $table_map Token => quoted table name map.
	 * @return string SQL with table names substituted.
	 */
	function nxtcc_rest_sql_with_table_tokens( string $query, array $table_map ): string {
		if ( '' === $query || empty( $table_map ) ) {
			return $query;
		}

		foreach ( $table_map as $token => $table_sql ) {
			$query = str_replace( '{' . (string) $token . '}', (string) $table_sql, $query );
		}

		return $query;
	}
}

/**
 * Split an MSISDN (E.164-ish) into [country_code, local_number].
 *
 * @param string $msisdn Input number.
 * @return array{0:string,1:string}
 */
function nxtcc_split_msisdn( string $msisdn ): array {
	$digits = preg_replace( '/\D+/', '', (string) $msisdn );
	$len    = strlen( (string) $digits );

	if ( $len >= 11 && $len <= 15 ) {
		return array( substr( (string) $digits, 0, $len - 10 ), substr( (string) $digits, -10 ) );
	}

	if ( 10 === $len ) {
		return array( '', (string) $digits );
	}

	if ( $len >= 7 ) {
		$min_local = 6;
		$cc_len    = max( 1, min( 4, $len - $min_local ) );
		return array( substr( (string) $digits, 0, $cc_len ), substr( (string) $digits, $cc_len ) );
	}

	return array( '', (string) $digits );
}

/**
 * Read a query parameter from WP_REST_Request (query params).
 *
 * Meta sends hub.mode / hub.verify_token / hub.challenge.
 * WordPress may normalize dots, so we support both forms.
 *
 * @param WP_REST_Request $request REST request.
 * @param string          $key_dot Original key e.g. "hub.mode".
 * @param string          $key_alt Alternate key e.g. "hub_mode".
 * @return string
 */
function nxtcc_rest_get_query_param( WP_REST_Request $request, string $key_dot, string $key_alt ): string {
	$val = $request->get_param( $key_dot );
	if ( null === $val || '' === $val ) {
		$val = $request->get_param( $key_alt );
	}

	// Avoid direct $_GET access to satisfy NonceVerification / InputNotSanitized sniffs.
	if ( null === $val || '' === $val ) {
		$q = $request->get_query_params();

		if ( isset( $q[ $key_dot ] ) ) {
			$val = $q[ $key_dot ];
		} elseif ( isset( $q[ $key_alt ] ) ) {
			$val = $q[ $key_alt ];
		}
	}

	if ( null === $val ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( (string) $val ) );
}

/**
 * Extract all phone_number_id values present in a webhook payload.
 *
 * @param array $data Decoded webhook payload.
 * @return array<int,string> Unique phone_number_id values.
 */
function nxtcc_rest_payload_phone_number_ids( array $data ): array {
	$out = array();

	$entries = isset( $data['entry'] ) && is_array( $data['entry'] ) ? $data['entry'] : array();
	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$changes = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$value    = isset( $change['value'] ) && is_array( $change['value'] ) ? $change['value'] : array();
			$metadata = isset( $value['metadata'] ) && is_array( $value['metadata'] ) ? $value['metadata'] : array();
			$pnid     = isset( $metadata['phone_number_id'] ) ? sanitize_text_field( (string) $metadata['phone_number_id'] ) : '';

			if ( '' !== $pnid ) {
				$out[ $pnid ] = true;
			}
		}
	}

	return array_values( array_keys( $out ) );
}

/**
 * Normalize and parse X-Hub-Signature-256 header.
 *
 * Header format expected: "sha256=<hex>".
 *
 * @param string $header Signature header value.
 * @return string Lowercase hex digest, or empty string when invalid.
 */
function nxtcc_rest_parse_signature_header( string $header ): string {
	$header = trim( $header );
	if ( '' === $header ) {
		return '';
	}

	$prefix = 'sha256=';
	if ( 0 !== strpos( strtolower( $header ), $prefix ) ) {
		return '';
	}

	$hash = strtolower( trim( substr( $header, strlen( $prefix ) ) ) );

	return preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
}

/**
 * Add a potential secret value to a normalized secret list.
 *
 * @param array  $secrets Secret list (associative set map).
 * @param string $secret  Candidate secret.
 * @return array Updated secret set map.
 */
function nxtcc_rest_add_secret( array $secrets, string $secret ): array {
	$secret = trim( $secret );
	if ( '' === $secret ) {
		return $secrets;
	}

	$secrets[ $secret ] = true;
	return $secrets;
}

/**
 * Resolve decrypted app secret from tenant settings for a phone_number_id.
 *
 * @param string $phone_number_id Phone number id.
 * @return string|null
 */
function nxtcc_rest_get_db_app_secret( string $phone_number_id ): ?string {
	static $cache       = array();
	static $has_columns = null;

	$phone_number_id = trim( $phone_number_id );
	if ( '' === $phone_number_id ) {
		return null;
	}

	if ( array_key_exists( $phone_number_id, $cache ) ) {
		return is_string( $cache[ $phone_number_id ] ) ? $cache[ $phone_number_id ] : null;
	}

	$db                  = NXTCC_DB::i();
	$table_user_settings = nxtcc_rest_quote_table_name( $db->t_user_settings() );
	$table_map           = array(
		'user_settings' => $table_user_settings,
	);

	if ( null === $has_columns ) {
		$has_columns = false;
		$cols_sql    = nxtcc_rest_sql_with_table_tokens( 'SHOW COLUMNS FROM {user_settings}', $table_map );

		if ( '' !== $cols_sql ) {
			$cols = $db->get_col( $cols_sql );
			if ( is_array( $cols ) ) {
				$set = array();
				foreach ( $cols as $col_name ) {
					if ( is_string( $col_name ) && '' !== $col_name ) {
						$set[ $col_name ] = true;
					}
				}
				$has_columns = ( isset( $set['app_secret_ct'] ) && isset( $set['app_secret_nonce'] ) );
			}
		}
	}

	if ( ! $has_columns ) {
		$cache[ $phone_number_id ] = null;
		return null;
	}

	$row = $db->get_row(
		nxtcc_rest_sql_with_table_tokens(
			'SELECT app_secret_ct, app_secret_nonce
		   FROM {user_settings}
		  WHERE phone_number_id = %s
	   ORDER BY id DESC
		  LIMIT 1',
			$table_map
		),
		array( $phone_number_id ),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		$cache[ $phone_number_id ] = null;
		return null;
	}

	$ct    = isset( $row['app_secret_ct'] ) ? (string) $row['app_secret_ct'] : '';
	$nonce = isset( $row['app_secret_nonce'] ) ? $row['app_secret_nonce'] : null;

	if ( '' === $ct || ! is_string( $nonce ) || '' === $nonce ) {
		$cache[ $phone_number_id ] = null;
		return null;
	}

	$pt = null;
	if ( class_exists( 'NXTCC_Helpers' ) ) {
		$pt = NXTCC_Helpers::crypto_decrypt( $ct, $nonce );
	} elseif ( function_exists( 'nxtcc_crypto_decrypt' ) ) {
		$pt = nxtcc_crypto_decrypt( $ct, $nonce );
	}

	$secret = ( ! is_wp_error( $pt ) && is_string( $pt ) && '' !== trim( $pt ) ) ? trim( $pt ) : null;

	$cache[ $phone_number_id ] = $secret;
	return $secret;
}

/**
 * Resolve webhook app-secret candidates for a phone_number_id.
 *
 * Strict mode: only the encrypted tenant secret stored in DB is accepted.
 *
 * @param string $phone_number_id Phone number id from webhook metadata.
 * @return array<int,string> Secret candidates.
 */
function nxtcc_rest_get_webhook_app_secrets( string $phone_number_id ): array {
	$secrets   = array();
	$db_secret = nxtcc_rest_get_db_app_secret( $phone_number_id );

	if ( is_string( $db_secret ) ) {
		$secrets = nxtcc_rest_add_secret( $secrets, $db_secret );
	}

	return array_values( array_keys( $secrets ) );
}

/**
 * Verify webhook signature for a specific phone_number_id.
 *
 * @param WP_REST_Request $request         REST request.
 * @param string          $raw_body        Raw POST body.
 * @param string          $phone_number_id Phone number id.
 * @return bool
 */
function nxtcc_rest_verify_signature_for_phone( WP_REST_Request $request, string $raw_body, string $phone_number_id ): bool {
	$header = $request->get_header( 'x-hub-signature-256' );
	if ( ! is_string( $header ) || '' === $header ) {
		$header = $request->get_header( 'X-Hub-Signature-256' );
	}

	$received_hash = nxtcc_rest_parse_signature_header( is_string( $header ) ? $header : '' );
	if ( '' === $received_hash ) {
		return false;
	}

	$secrets = nxtcc_rest_get_webhook_app_secrets( $phone_number_id );
	if ( empty( $secrets ) ) {
		return false;
	}

	foreach ( $secrets as $secret ) {
		$calc = hash_hmac( 'sha256', $raw_body, (string) $secret );
		if ( hash_equals( $received_hash, strtolower( (string) $calc ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Verify webhook signature across all phone_number_id values in payload.
 *
 * @param WP_REST_Request $request  REST request.
 * @param string          $raw_body Raw POST body.
 * @param array           $data     Decoded payload.
 * @return bool
 */
function nxtcc_rest_verify_webhook_signature( WP_REST_Request $request, string $raw_body, array $data ): bool {
	$pnids = nxtcc_rest_payload_phone_number_ids( $data );
	if ( empty( $pnids ) ) {
		return false;
	}

	foreach ( $pnids as $pnid ) {
		if ( ! nxtcc_rest_verify_signature_for_phone( $request, $raw_body, (string) $pnid ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Build message_content to store for an inbound webhook message.
 *
 * - Text messages: store plain text body.
 * - Media messages: store JSON payload (kind/caption/filename/link/media_id/text).
 * - Template messages:
 *   - If header has IMAGE/VIDEO/DOCUMENT, store JSON payload with kind + media_id/link.
 *   - Otherwise store a readable fallback: "Template: <name> (<lang>)".
 *
 * NOTE: The admin UI preview parser expects JSON when it begins with "{" and uses keys:
 * - kind, text, caption, filename, link, media_id
 *
 * @param array $m Webhook message object.
 * @return string Message content to store (plain text or JSON string).
 */
function nxtcc_build_inbound_message_content_from_webhook( array $m ): string {
	$type = isset( $m['type'] ) ? sanitize_key( (string) $m['type'] ) : '';

	// -------------------------
	// Text
	// -------------------------
	if ( 'text' === $type && isset( $m['text'] ) && is_array( $m['text'] ) && isset( $m['text']['body'] ) ) {
		return sanitize_textarea_field( (string) $m['text']['body'] );
	}

	// -------------------------
	// Direct media types
	// -------------------------
	if ( in_array( $type, array( 'image', 'video', 'audio', 'document', 'sticker' ), true ) ) {
		$node = ( isset( $m[ $type ] ) && is_array( $m[ $type ] ) ) ? $m[ $type ] : array();

		$payload = array(
			'kind'     => $type,
			'text'     => '',
			'caption'  => isset( $node['caption'] ) ? sanitize_text_field( (string) $node['caption'] ) : '',
			'filename' => isset( $node['filename'] ) ? sanitize_file_name( (string) $node['filename'] ) : '',
			'link'     => isset( $node['link'] ) ? esc_url_raw( (string) $node['link'] ) : '',
			'media_id' => isset( $node['id'] ) ? sanitize_text_field( (string) $node['id'] ) : '',
		);

		$json = wp_json_encode( $payload );
		return is_string( $json ) ? $json : '';
	}

	// -------------------------
	// Template messages
	// -------------------------
	if ( 'template' === $type && isset( $m['template'] ) && is_array( $m['template'] ) ) {
		$template   = $m['template'];
		$tpl_name   = isset( $template['name'] ) ? sanitize_text_field( (string) $template['name'] ) : '';
		$tpl_lang   = '';
		$components = isset( $template['components'] ) && is_array( $template['components'] )
			? $template['components']
			: array();

		if ( isset( $template['language'] ) && is_array( $template['language'] ) && isset( $template['language']['code'] ) ) {
			$tpl_lang = sanitize_text_field( (string) $template['language']['code'] );
		}

		// If template includes header media, store it like normal media payload.
		foreach ( $components as $comp ) {
			if ( ! is_array( $comp ) ) {
				continue;
			}

			$c_type = isset( $comp['type'] ) ? strtolower( (string) $comp['type'] ) : '';
			if ( 'header' !== $c_type ) {
				continue;
			}

			$format = isset( $comp['format'] ) ? strtolower( (string) $comp['format'] ) : '';

			// WhatsApp template header format: IMAGE / VIDEO / DOCUMENT (sometimes TEXT).
			if ( in_array( $format, array( 'image', 'video', 'document' ), true ) ) {
				$node = ( isset( $comp[ $format ] ) && is_array( $comp[ $format ] ) ) ? $comp[ $format ] : array();

				$cap = 'Template';
				if ( '' !== $tpl_name ) {
					$cap = 'Template: ' . $tpl_name;
				}

				$payload = array(
					'kind'     => $format,
					'text'     => '',
					'caption'  => sanitize_text_field( $cap ),
					'filename' => isset( $node['filename'] ) ? sanitize_file_name( (string) $node['filename'] ) : '',
					'link'     => isset( $node['link'] ) ? esc_url_raw( (string) $node['link'] ) : '',
					'media_id' => isset( $node['id'] ) ? sanitize_text_field( (string) $node['id'] ) : '',
				);

				$json = wp_json_encode( $payload );
				return is_string( $json ) ? $json : '';
			}

			// Header text format (rare): store as text.
			if ( 'text' === $format && isset( $comp['text'] ) && is_array( $comp['text'] ) && isset( $comp['text']['text'] ) ) {
				return sanitize_textarea_field( (string) $comp['text']['text'] );
			}
		}

		// Fallback: store readable template identifier (so it never shows empty).
		$fallback = 'Template';
		if ( '' !== $tpl_name ) {
			$fallback .= ': ' . $tpl_name;
		}
		if ( '' !== $tpl_lang ) {
			$fallback .= ' (' . $tpl_lang . ')';
		}

		return sanitize_text_field( $fallback );
	}

	// Unknown type: empty.
	return '';
}

/**
 * Extract a safe error message from a webhook status object.
 *
 * @param array $st Status object.
 * @return string
 */
function nxtcc_rest_extract_status_error_message( array $st ): string {
	if ( empty( $st['errors'] ) || ! is_array( $st['errors'] ) ) {
		return '';
	}

	$e0 = $st['errors'][0];
	if ( ! is_array( $e0 ) ) {
		return '';
	}

	if ( isset( $e0['title'] ) && is_string( $e0['title'] ) && '' !== $e0['title'] ) {
		return sanitize_text_field( $e0['title'] );
	}

	if ( isset( $e0['message'] ) && is_string( $e0['message'] ) && '' !== $e0['message'] ) {
		return sanitize_text_field( $e0['message'] );
	}

	return '';
}

/**
 * Merge/append a timestamp into existing status_timestamps JSON.
 *
 * @param string $existing_json Existing JSON (may be empty).
 * @param string $key          Key to set (sent|delivered|read|failed...).
 * @param string $mysql_utc     MySQL datetime (UTC).
 * @return string JSON
 */
function nxtcc_rest_merge_status_timestamp( string $existing_json, string $key, string $mysql_utc ): string {
	$out = array();

	if ( '' !== $existing_json ) {
		$decoded = json_decode( $existing_json, true );
		if ( is_array( $decoded ) ) {
			$out = $decoded;
		}
	}

	$key = sanitize_key( $key );
	if ( '' !== $key ) {
		$out[ $key ] = sanitize_text_field( $mysql_utc );
	}

	$json = wp_json_encode( $out );
	return is_string( $json ) ? $json : '';
}

/**
 * Update message history row by meta_message_id (wamid) for webhook statuses.
 *
 * Sets:
 * - status
 * - delivered_at / read_at / failed_at (when present in schema)
 * - last_error (when present)
 * - response_json (append raw status blob)
 * - status_timestamps (merge a key)
 *
 * @param NXTCC_DB $db         DB wrapper.
 * @param string   $wamid      Meta message id.
 * @param string   $new_status New status (sent|delivered|read|failed).
 * @param int      $unix_ts    Status timestamp (unix seconds) from Meta (may be 0).
 * @param string   $err_msg    Optional error message.
 * @param array    $status_obj Raw status object.
 * @return void
 */
function nxtcc_rest_update_message_history_status( NXTCC_DB $db, string $wamid, string $new_status, int $unix_ts, string $err_msg, array $status_obj ): void {
	$wamid      = sanitize_text_field( $wamid );
	$new_status = sanitize_key( $new_status );
	$err_msg    = sanitize_text_field( $err_msg );
	$table_hist = nxtcc_rest_quote_table_name( $db->t_message_history() );
	$table_map  = array(
		'history' => $table_hist,
	);

	if ( '' === $wamid || '' === $new_status ) {
		return;
	}

	// Find the row first (need current status_timestamps + response_json).
	$row = $db->get_row(
		nxtcc_rest_sql_with_table_tokens(
			'SELECT id, status_timestamps, response_json
		   FROM {history}
		  WHERE meta_message_id = %s
		  LIMIT 1',
			$table_map
		),
		array( $wamid ),
		OBJECT
	);

	if ( ! $row || empty( $row->id ) ) {
		return;
	}

	$id = (int) $row->id;

	$ts_mysql = '';
	if ( $unix_ts > 0 ) {
		$ts_mysql = gmdate( 'Y-m-d H:i:s', $unix_ts );
	} else {
		$ts_mysql = current_time( 'mysql', 1 );
	}

	$existing_ts = isset( $row->status_timestamps ) ? (string) $row->status_timestamps : '';
	$merged_ts   = nxtcc_rest_merge_status_timestamp( $existing_ts, $new_status, $ts_mysql );

	// Append status object into response_json in a safe way.
	$resp_blob = array(
		'webhook_status' => $status_obj,
		'updated_utc'    => $ts_mysql,
	);

	$existing_resp = isset( $row->response_json ) ? (string) $row->response_json : '';
	$decoded_resp  = json_decode( $existing_resp, true );
	if ( ! is_array( $decoded_resp ) ) {
		$decoded_resp = array();
	}
	$decoded_resp[] = $resp_blob;

	$resp_json = wp_json_encode( $decoded_resp );
	$resp_json = is_string( $resp_json ) ? $resp_json : $existing_resp;

	$update = array(
		'status'            => $new_status,
		'status_timestamps' => $merged_ts,
		'response_json'     => $resp_json,
	);

	// Update the appropriate *_at field if the schema includes it.
	if ( 'delivered' === $new_status ) {
		$update['delivered_at'] = $ts_mysql;
	} elseif ( 'read' === $new_status ) {
		$update['read_at'] = $ts_mysql;
	} elseif ( 'failed' === $new_status ) {
		$update['failed_at']  = $ts_mysql;
		$update['last_error'] = ( '' !== $err_msg ) ? $err_msg : 'failed';
	} elseif ( 'sent' === $new_status ) {
		$update['sent_at'] = $ts_mysql;
	}

	// Ensure we don't try to update columns that don't exist.
	$show_cols_sql = nxtcc_rest_sql_with_table_tokens( 'SHOW COLUMNS FROM {history}', $table_map );

	$cols = $db->get_col( $show_cols_sql );

	$has = array();
	if ( is_array( $cols ) ) {
		foreach ( $cols as $col_name ) {
			if ( is_string( $col_name ) && '' !== $col_name ) {
				$has[ $col_name ] = true;
			}
		}
	}

	if ( ! empty( $has ) ) {
		foreach ( array_keys( $update ) as $k ) {
			if ( ! isset( $has[ $k ] ) ) {
				unset( $update[ $k ] );
			}
		}
	}

	// Perform update.
	$db->update(
		$db->t_message_history(),
		$update,
		array( 'id' => $id )
	);
}

/**
 * Permission callback for public auth REST endpoints.
 *
 * These endpoints are intentionally public (no logged-in capability required),
 * but they must include a valid REST nonce to reduce CSRF risk.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function nxtcc_rest_auth_permission( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );

	if ( ! is_string( $nonce ) || '' === $nonce ) {
		$nonce = $request->get_param( '_wpnonce' );
	}
	if ( ! is_string( $nonce ) || '' === $nonce ) {
		$nonce = $request->get_param( 'nonce' );
	}

	$nonce = sanitize_text_field( wp_unslash( (string) $nonce ) );

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error(
			'nxtcc_rest_forbidden',
			__( 'Invalid nonce.', 'nxt-cloud-chat' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Register REST API routes.
 *
 * @return void
 */
function nxtcc_register_rest_routes(): void {
	register_rest_route(
		'nxtcc/v1',
		'/webhook/',
		array(
			'methods'             => array( 'GET', 'HEAD', 'POST' ),
			'callback'            => 'nxtcc_whatsapp_webhook_handler',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'nxtcc/v1',
		'/auth/request-otp',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'nxtcc_rest_auth_permission',
			'callback'            => 'nxtcc_rest_auth_request_otp',
		)
	);

	register_rest_route(
		'nxtcc/v1',
		'/auth/resend-otp',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'nxtcc_rest_auth_permission',
			'callback'            => 'nxtcc_rest_auth_request_otp',
		)
	);

	register_rest_route(
		'nxtcc/v1',
		'/auth/verify-otp',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'nxtcc_rest_auth_permission',
			'callback'            => 'nxtcc_rest_auth_verify_otp',
		)
	);
}
add_action( 'rest_api_init', 'nxtcc_register_rest_routes' );

/**
 * REST callback: request/resend OTP.
 *
 * @param WP_REST_Request $req Request.
 * @return mixed
 */
function nxtcc_rest_auth_request_otp( WP_REST_Request $req ) {
	if ( ! function_exists( 'nxtcc_auth_request_otp' ) ) {
		$auth_handler_file = NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-auth-handler.php';
		if ( file_exists( $auth_handler_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-auth-handler.php';
		}
	}

	return nxtcc_auth_request_otp( $req );
}

/**
 * REST callback: verify OTP.
 *
 * @param WP_REST_Request $req Request.
 * @return mixed
 */
function nxtcc_rest_auth_verify_otp( WP_REST_Request $req ) {
	if ( ! function_exists( 'nxtcc_auth_verify_otp' ) ) {
		$auth_handler_file = NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-auth-handler.php';
		if ( file_exists( $auth_handler_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-auth-handler.php';
		}
	}

	return nxtcc_auth_verify_otp( $req );
}

/**
 * WhatsApp webhook handler.
 *
 * GET  = Meta verification (must echo hub.challenge as plain text).
 * POST = Webhook events payload.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function nxtcc_whatsapp_webhook_handler( WP_REST_Request $request ): WP_REST_Response {
	$db                    = NXTCC_DB::i();
	$table_user_settings   = nxtcc_rest_quote_table_name( $db->t_user_settings() );
	$table_message_history = nxtcc_rest_quote_table_name( $db->t_message_history() );
	$table_contacts        = nxtcc_rest_quote_table_name( $db->t_contacts() );
	$table_map             = array(
		'user_settings' => $table_user_settings,
		'history'       => $table_message_history,
		'contacts'      => $table_contacts,
	);

	// -------------------------
	// GET: verification
	// -------------------------
	if ( in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
		$mode      = nxtcc_rest_get_query_param( $request, 'hub.mode', 'hub_mode' );
		$token     = nxtcc_rest_get_query_param( $request, 'hub.verify_token', 'hub_verify_token' );
		$challenge = nxtcc_rest_get_query_param( $request, 'hub.challenge', 'hub_challenge' );
		$deny_code = 'invalid_request';

		if ( 'subscribe' === $mode && '' !== $token && '' !== $challenge ) {
			$token_hash = hash( 'sha256', (string) $token );

			$has = (int) $db->get_var(
				nxtcc_rest_sql_with_table_tokens(
					'SELECT COUNT(*)
					FROM {user_settings}
					WHERE meta_webhook_verify_token_hash IN (%s, %s)',
					$table_map
				),
				array(
					$token_hash,
					$token,
				)
			);

			if ( $has > 0 ) {
				$charset = get_bloginfo( 'charset' );
				header( 'Content-Type: text/plain; charset=' . sanitize_text_field( (string) $charset ) );
				status_header( 200 );

				// Must be returned as plain text for Meta verification.
				// $challenge is sanitized in nxtcc_rest_get_query_param().
				echo esc_html( $challenge );
				exit;
			}

			$deny_code = 'token_mismatch';
		} elseif ( 'subscribe' !== $mode ) {
			$deny_code = 'invalid_mode';
		} elseif ( '' === $token ) {
			$deny_code = 'missing_verify_token';
		} elseif ( '' === $challenge ) {
			$deny_code = 'missing_challenge';
		}

		$charset = get_bloginfo( 'charset' );
		header( 'Content-Type: text/plain; charset=' . sanitize_text_field( (string) $charset ) );
		header( 'X-NXTCC-Webhook-Reason: ' . sanitize_key( $deny_code ) );
		status_header( 403 );
		echo esc_html__( 'Forbidden', 'nxt-cloud-chat' );
		exit;
	}

	// -------------------------
	// POST: webhook events
	// -------------------------
	if ( 'POST' !== $request->get_method() ) {
		return new WP_REST_Response( 'Method Not Allowed', 405 );
	}

	$raw  = (string) $request->get_body();
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return new WP_REST_Response( 'Bad payload', 400 );
	}

	// Only accept WABA payloads (still ack so Meta doesn't retry).
	if ( empty( $data['object'] ) || 'whatsapp_business_account' !== (string) $data['object'] ) {
		return new WP_REST_Response( 'EVENT_RECEIVED', 200 );
	}

	if ( ! nxtcc_rest_verify_webhook_signature( $request, $raw, $data ) ) {
		return new WP_REST_Response( 'Forbidden', 403 );
	}

	$entries = isset( $data['entry'] ) && is_array( $data['entry'] ) ? $data['entry'] : array();

	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$changes = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			if ( empty( $change['field'] ) || 'messages' !== (string) $change['field'] ) {
				continue;
			}

			$value = isset( $change['value'] ) && is_array( $change['value'] ) ? $change['value'] : array();

			$metadata             = isset( $value['metadata'] ) && is_array( $value['metadata'] ) ? $value['metadata'] : array();
			$phone_number_id      = isset( $metadata['phone_number_id'] ) ? sanitize_text_field( (string) $metadata['phone_number_id'] ) : '';
			$display_phone_number = isset( $metadata['display_phone_number'] ) ? sanitize_text_field( (string) $metadata['display_phone_number'] ) : '';

			if ( '' === $phone_number_id ) {
				continue;
			}

			// Resolve tenant settings by phone_number_id.
			$settings = $db->get_row(
				nxtcc_rest_sql_with_table_tokens(
					'SELECT user_mailid, business_account_id, phone_number_id
				   FROM {user_settings}
				  WHERE phone_number_id = %s
			   ORDER BY id DESC
				  LIMIT 1',
					$table_map
				),
				array( $phone_number_id ),
				OBJECT
			);

			if ( ! $settings || empty( $settings->business_account_id ) || empty( $settings->user_mailid ) ) {
				continue;
			}

			$user_mailid         = sanitize_email( (string) $settings->user_mailid );
			$business_account_id = sanitize_text_field( (string) $settings->business_account_id );

			// -------------------------
			// 1) Webhook statuses (for outgoing messages)
			// -------------------------
			$statuses = isset( $value['statuses'] ) && is_array( $value['statuses'] ) ? $value['statuses'] : array();

			foreach ( $statuses as $st ) {
				if ( ! is_array( $st ) ) {
					continue;
				}

				$wamid  = isset( $st['id'] ) ? sanitize_text_field( (string) $st['id'] ) : '';
				$status = isset( $st['status'] ) ? sanitize_key( (string) $st['status'] ) : '';
				$ts     = isset( $st['timestamp'] ) ? (int) $st['timestamp'] : 0;

				if ( '' === $wamid || '' === $status ) {
					continue;
				}

				$err_msg = nxtcc_rest_extract_status_error_message( $st );

				nxtcc_rest_update_message_history_status(
					$db,
					$wamid,
					$status,
					$ts,
					$err_msg,
					$st
				);

				if ( function_exists( 'nxtcc_invalidate_tenant_caches' ) ) {
					nxtcc_invalidate_tenant_caches( $business_account_id, $phone_number_id );
				}
			}

			// -------------------------
			// 2) Inbound messages (received from user)
			// -------------------------
			$contacts = isset( $value['contacts'] ) && is_array( $value['contacts'] ) ? $value['contacts'] : array();
			$messages = isset( $value['messages'] ) && is_array( $value['messages'] ) ? $value['messages'] : array();

			// Build wa_id => name map.
			$name_by_wa = array();
			foreach ( $contacts as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}

				$wa_id = isset( $c['wa_id'] ) ? preg_replace( '/\D+/', '', (string) $c['wa_id'] ) : '';
				$prof  = isset( $c['profile'] ) && is_array( $c['profile'] ) ? $c['profile'] : array();
				$name  = isset( $prof['name'] ) ? sanitize_text_field( (string) $prof['name'] ) : '';

				if ( '' !== $wa_id ) {
					$name_by_wa[ $wa_id ] = $name;
				}
			}

			foreach ( $messages as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}

				$meta_message_id = isset( $m['id'] ) ? sanitize_text_field( (string) $m['id'] ) : '';
				$from_wa         = isset( $m['from'] ) ? preg_replace( '/\D+/', '', (string) $m['from'] ) : '';
				$type            = isset( $m['type'] ) ? sanitize_key( (string) $m['type'] ) : '';
				$wa_ts           = isset( $m['timestamp'] ) ? (int) $m['timestamp'] : 0;

				if ( '' === $meta_message_id || '' === $from_wa ) {
					continue;
				}

				// Dedupe (your schema uses meta_message_id).
				$exists = (int) $db->get_var(
					nxtcc_rest_sql_with_table_tokens(
						'SELECT COUNT(*) FROM {history} WHERE meta_message_id = %s',
						$table_map
					),
					array( $meta_message_id )
				);

				if ( $exists > 0 ) {
					continue;
				}

				// Build message_content for ALL supported types (text/media/template).
				$message_content = nxtcc_build_inbound_message_content_from_webhook( $m );

				// Sender name (if provided by Meta).
				$sender_name = isset( $name_by_wa[ $from_wa ] ) ? (string) $name_by_wa[ $from_wa ] : '';

				// Find/create contact row so UI can link inbound message to a contact.
				list( $cc, $local ) = nxtcc_split_msisdn( $from_wa );
				$cc                 = preg_replace( '/\D+/', '', (string) $cc );
				$local              = preg_replace( '/\D+/', '', (string) $local );

				$contact_id = null;

				if ( '' !== $local ) {
					$found_contact_id = $db->get_var(
						nxtcc_rest_sql_with_table_tokens(
							'SELECT id FROM {contacts}
						  WHERE business_account_id = %s
						    AND phone_number_id = %s
						    AND country_code = %s
						    AND phone_number = %s
						  LIMIT 1',
							$table_map
						),
						array( $business_account_id, $phone_number_id, $cc, $local )
					);

					if ( $found_contact_id ) {
						$contact_id = (int) $found_contact_id;
					} else {
						$ins = $db->insert(
							$db->t_contacts(),
							array(
								'user_mailid'         => $user_mailid,
								'business_account_id' => $business_account_id,
								'phone_number_id'     => $phone_number_id,
								'country_code'        => $cc,
								'phone_number'        => $local,
								'name'                => $sender_name,
								'is_verified'         => 0,
								'is_subscribed'       => 1,
								'created_at'          => current_time( 'mysql', 1 ),
								'updated_at'          => current_time( 'mysql', 1 ),
							)
						);

						if ( $ins ) {
							$contact_id = (int) $db->insert_id();
						}
					}
				}

				// Store a small timestamp blob (your schema has status_timestamps LONGTEXT).
				$status_ts = array(
					'received_unix' => $wa_ts,
					'received_utc'  => gmdate( 'Y-m-d H:i:s', $wa_ts > 0 ? $wa_ts : time() ),
				);

				$json_ts = wp_json_encode( $status_ts );
				$json_m  = wp_json_encode( $m );

				// Insert inbound message into your schema.
				$db->insert(
					$db->t_message_history(),
					array(
						'user_mailid'          => $user_mailid,
						'business_account_id'  => $business_account_id,
						'phone_number_id'      => $phone_number_id,
						'contact_id'           => $contact_id,
						'display_phone_number' => $display_phone_number,
						'template_type'        => $type,
						'message_content'      => $message_content,
						'status'               => 'received',
						'status_timestamps'    => is_string( $json_ts ) ? $json_ts : '',
						'meta_message_id'      => $meta_message_id,
						'response_json'        => is_string( $json_m ) ? $json_m : '',
						'created_at'           => current_time( 'mysql', 1 ),
						'is_read'              => 0,
					)
				);

				if ( function_exists( 'nxtcc_invalidate_tenant_caches' ) ) {
					nxtcc_invalidate_tenant_caches( $business_account_id, $phone_number_id );
				}
			}
		}
	}

	return new WP_REST_Response( 'EVENT_RECEIVED', 200 );
}

/**
 * Handle WP user deletion:
 * - Force is_verified = 0 and wp_uid = NULL for related contacts.
 * - Remove Verified-group mappings for those contacts.
 *
 * @param int $user_id User ID.
 * @return void
 */
function nxtcc_on_delete_user( int $user_id ): void {
	$db              = NXTCC_DB::i();
	$table_contacts  = nxtcc_rest_quote_table_name( $db->t_contacts() );
	$table_groups    = nxtcc_rest_quote_table_name( $db->t_groups() );
	$table_group_map = nxtcc_rest_quote_table_name( $db->t_group_contact_map() );
	$table_map       = array(
		'contacts'  => $table_contacts,
		'groups'    => $table_groups,
		'group_map' => $table_group_map,
	);

	$user_id = (int) $user_id;

	$affected_ids = $db->get_col(
		nxtcc_rest_sql_with_table_tokens(
			'SELECT id FROM {contacts} WHERE wp_uid = %d',
			$table_map
		),
		array( $user_id )
	);

	if ( empty( $affected_ids ) ) {
		return;
	}

	list( $ph_ids, $args_ids ) = $db->prepare_in_fragment( $affected_ids, '%d' );
	if ( '' !== $ph_ids ) {
		$db->query(
			nxtcc_rest_sql_with_table_tokens(
				"UPDATE {contacts}
			 SET is_verified = %d, wp_uid = NULL, updated_at = %s
			 WHERE id IN ({$ph_ids})",
				$table_map
			),
			array_merge(
				array( 0, current_time( 'mysql', 1 ) ),
				array_map( 'intval', $args_ids )
			)
		);
	}

	$verified_group_ids = $db->get_col(
		nxtcc_rest_sql_with_table_tokens(
			'SELECT id FROM {groups} WHERE is_verified = %d',
			$table_map
		),
		array( 1 )
	);

	if ( empty( $verified_group_ids ) ) {
		return;
	}

	list( $ph_c, $arg_c ) = $db->prepare_in_fragment( $affected_ids, '%d' );
	list( $ph_g, $arg_g ) = $db->prepare_in_fragment( $verified_group_ids, '%d' );

	if ( '' !== $ph_c && '' !== $ph_g ) {
		$db->query(
			nxtcc_rest_sql_with_table_tokens(
				"DELETE FROM {group_map}
			 WHERE contact_id IN ({$ph_c}) AND group_id IN ({$ph_g})",
				$table_map
			),
			array_merge( array_map( 'intval', $arg_c ), array_map( 'intval', $arg_g ) )
		);
	}
}
add_action( 'delete_user', 'nxtcc_on_delete_user' );
