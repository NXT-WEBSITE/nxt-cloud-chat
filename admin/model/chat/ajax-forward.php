<?php
/**
 * AJAX endpoints: forwarding targets + forwarding messages.
 *
 * Endpoints:
 * - nxtcc_list_forward_targets : list contacts eligible for forwarding (24h inbound window).
 * - nxtcc_forward_messages     : forward selected message(s) to selected contact(s).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * -------------------------------------------------------------------------
 * Capability / Tenant Helpers
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'nxtcc_chat_ajax_require_caps' ) ) {

	/**
	 * Require proper capability for chat admin AJAX endpoints.
	 *
	 * @return void
	 */
	function nxtcc_chat_ajax_require_caps(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => 'Insufficient permissions.' ),
				403
			);
		}
	}
}

if ( ! function_exists( 'nxtcc_chat_resolve_tenant_context' ) ) {

	/**
	 * Resolve tenant context for the current admin user.
	 *
	 * Do not trust business_account_id/phone_number_id from input.
	 * Validates requested phone_number_id belongs to the current user, and
	 * resolves business_account_id from stored settings.
	 *
	 * @param string $requested_pnid Requested phone_number_id (optional).
	 * @return array{0:string,1:string,2:string} user_mailid, phone_number_id, business_account_id
	 */
	function nxtcc_chat_resolve_tenant_context( string $requested_pnid ): array {

		$user = wp_get_current_user();

		$user_mailid = ( $user instanceof WP_User )
			? sanitize_email( (string) $user->user_email )
			: '';

		if ( '' === $user_mailid ) {
			return array( '', '', '' );
		}

		// Defense-in-depth: sanitize caller-provided PNID here too.
		$requested_pnid = sanitize_text_field( $requested_pnid );

		$repo = nxtcc_chat_repo();
		if ( ! $repo ) {
			return array( $user_mailid, '', '' );
		}

		// If $requested_pnid is provided, repo validates it belongs to this user.
		$phone_number_id = $repo->get_user_phone_number_id(
			$user_mailid,
			$requested_pnid
		);

		$phone_number_id = (string) $phone_number_id;

		if ( '' === $phone_number_id ) {
			return array( $user_mailid, '', '' );
		}

		$settings = $repo->get_tenant_settings_by_phone_number_id( $phone_number_id );

		$business_account_id = ( $settings && ! empty( $settings->business_account_id ) )
			? (string) $settings->business_account_id
			: '';

		return array(
			$user_mailid,
			$phone_number_id,
			$business_account_id,
		);
	}
}

/**
 * -------------------------------------------------------------------------
 * Forward Helpers
 * -------------------------------------------------------------------------
 */

/**
 * Convert a raw value (array or CSV string) into a sanitized list of IDs.
 *
 * @param mixed $raw Raw value (may be slashed).
 * @return int[] List of positive integers.
 */
function nxtcc_forward_int_array_from_raw( $raw ): array {

	if ( is_array( $raw ) ) {
		$ids = array_map(
			static function ( $v ) {
				return absint( wp_unslash( $v ) );
			},
			$raw
		);
	} else {
		$str   = (string) wp_unslash( $raw );
		$parts = explode( ',', $str );
		$ids   = array_map( 'absint', $parts );
	}

	return array_values( array_filter( $ids ) );
}

/**
 * Get a sanitized ID list from a specific POST key.
 *
 * @param string $key Key name.
 * @return int[] List of positive integers.
 */
function nxtcc_forward_int_array_from_post( string $key ): array {
	$raw = filter_input(
		INPUT_POST,
		$key,
		FILTER_SANITIZE_NUMBER_INT,
		FILTER_REQUIRE_ARRAY
	);

	if ( is_array( $raw ) ) {
		return nxtcc_forward_int_array_from_raw( $raw );
	}

	$raw_csv = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $raw_csv || false === $raw_csv || '' === $raw_csv ) {
		return array();
	}

	return nxtcc_forward_int_array_from_raw(
		sanitize_text_field( wp_unslash( (string) $raw_csv ) )
	);
}

/**
 * Parse a message_content payload into a normalized "forwardable" structure.
 *
 * @param mixed $raw Message content from DB.
 * @return array{kind:string,text:?string,media:?array}
 */
function nxtcc_forward_parse_message_content( $raw ): array {

	$kind  = 'text';
	$text  = null;
	$media = null;

	if ( is_string( $raw ) ) {
		$trim = ltrim( $raw );

		if ( '' !== $trim && '{' === $trim[0] ) {
			$obj = json_decode( $trim, true );

			if ( is_array( $obj ) && ! empty( $obj['kind'] ) ) {
				$kind  = (string) $obj['kind'];
				$media = $obj;
			} elseif ( is_array( $obj ) && isset( $obj['text'] ) ) {
				$kind = 'text';
				$text = (string) $obj['text'];
			} else {
				$kind = 'text';
				$text = (string) $raw;
			}
		} else {
			$kind = 'text';
			$text = (string) $raw;
		}
	} else {
		$kind = 'text';
		$text = '';
	}

	return array(
		'kind'  => $kind,
		'text'  => $text,
		'media' => $media,
	);
}

/**
 * Normalize outbound kind for sending.
 *
 * - WhatsApp sticker payloads are forwarded as images.
 * - Any unknown kind is treated as document for safest compatibility.
 *
 * @param string $kind Parsed kind.
 * @return string Outbound kind.
 */
function nxtcc_forward_normalize_kind_to_send( string $kind ): string {

	$allowed = array( 'image', 'video', 'audio', 'document', 'sticker' );

	if ( in_array( $kind, $allowed, true ) ) {
		return ( 'sticker' === $kind ) ? 'image' : $kind;
	}

	return 'document';
}

/**
 * -------------------------------------------------------------------------
 * AJAX: List Forward Targets
 * -------------------------------------------------------------------------
 */

/**
 * AJAX handler: List forwarding target contacts.
 *
 * Only contacts with inbound activity in the last 24 hours are returned.
 * IMPORTANT: this is scoped to the resolved phone_number_id.
 *
 * @return void
 */
function nxtcc_ajax_list_forward_targets(): void {

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	// Verify nonce before reading request payload.
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid_raw = filter_input( INPUT_POST, 'phone_number_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$requested_pnid     = is_string( $requested_pnid_raw ) ? sanitize_text_field( wp_unslash( $requested_pnid_raw ) ) : '';

	// Option A returns 3 values; BAID is not needed here.
	list( $user_mailid, $phone_number_id, $business_account_id ) = nxtcc_chat_resolve_tenant_context( $requested_pnid );
	unset( $business_account_id );

	if ( '' === $user_mailid ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	if ( '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Tenant phone_number_id not found.' ), 400 );
	}

	$q_raw = filter_input( INPUT_POST, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$q     = is_string( $q_raw ) ? sanitize_text_field( wp_unslash( $q_raw ) ) : '';

	$page     = 1;
	$page_raw = filter_input( INPUT_POST, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( is_string( $page_raw ) && '' !== $page_raw ) {
		$page = absint( wp_unslash( $page_raw ) );
		if ( $page < 1 ) {
			$page = 1;
		}
	}

	$per     = 25;
	$per_raw = filter_input( INPUT_POST, 'per', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( is_string( $per_raw ) && '' !== $per_raw ) {
		$per = absint( wp_unslash( $per_raw ) );
		if ( $per < 1 ) {
			$per = 1;
		} elseif ( $per > 50 ) {
			$per = 50;
		}
	}

	$off = ( $page - 1 ) * $per;

	$repo = nxtcc_chat_repo();
	if ( ! $repo ) {
		wp_send_json_error( array( 'message' => 'Repository unavailable.' ), 500 );
	}

	// IMPORTANT: repo method must scope by phone_number_id.
	$rows = $repo->list_forward_targets( $user_mailid, $phone_number_id, $q, $per, $off );

	$out = array();

	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}

		$row->last_inbound_local = null;

		if ( ! empty( $row->last_inbound_at ) ) {
			$row->last_inbound_local = get_date_from_gmt(
				(string) $row->last_inbound_at,
				'Y-m-d h:i A'
			);
		}

		$out[] = $row;
	}

	wp_send_json_success( array( 'rows' => $out ) );
}

add_action( 'wp_ajax_nxtcc_list_forward_targets', 'nxtcc_ajax_list_forward_targets' );

/**
 * -------------------------------------------------------------------------
 * AJAX: Forward Messages
 * -------------------------------------------------------------------------
 */

/**
 * AJAX handler: Forward selected messages to selected contacts.
 *
 * - Text messages are forwarded as text.
 * - Media payloads are forwarded by URL.
 * - If a media payload has media_id without link, the media is downloaded and uploaded to WP first.
 *
 * @return void
 */
function nxtcc_ajax_forward_messages(): void {

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	// Verify nonce before reading request payload.
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid_raw = filter_input( INPUT_POST, 'phone_number_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$requested_pnid     = is_string( $requested_pnid_raw ) ? sanitize_text_field( wp_unslash( $requested_pnid_raw ) ) : '';

	list( $user_mailid, $phone_number_id, $business_account_id ) = nxtcc_chat_resolve_tenant_context( $requested_pnid );

	if ( '' === $user_mailid ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 401 );
	}

	if ( '' === $phone_number_id || '' === $business_account_id ) {
		wp_send_json_error( array( 'message' => 'Tenant settings not found.' ), 400 );
	}

	$message_ids = nxtcc_forward_int_array_from_post( 'message_ids' );
	$contact_ids = nxtcc_forward_int_array_from_post( 'contact_ids' );

	if ( empty( $message_ids ) || empty( $contact_ids ) ) {
		wp_send_json_error( array( 'message' => 'Nothing to forward.' ), 400 );
	}

	$repo = nxtcc_chat_repo();
	if ( ! $repo ) {
		wp_send_json_error( array( 'message' => 'Repository unavailable.' ), 500 );
	}

	$rows = $repo->get_messages_for_forwarding( $message_ids, $user_mailid );

	if ( empty( $rows ) ) {
		wp_send_json_error( array( 'message' => 'Selected messages not found.' ), 404 );
	}

	if (
		! function_exists( 'nxtcc_send_message_immediately' ) ||
		! function_exists( 'nxtcc_send_media_link_immediately' )
	) {
		require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-send-message.php';
	}

	$sent_count = 0;

	foreach ( $contact_ids as $cid ) {
		foreach ( $rows as $row ) {

			$raw = ( $row && isset( $row->message_content ) ) ? $row->message_content : '';

			$parsed = nxtcc_forward_parse_message_content( $raw );
			$kind   = (string) $parsed['kind'];

			// Forward text.
			if ( 'text' === $kind ) {

				$text = is_string( $parsed['text'] ) ? (string) $parsed['text'] : '';

				if ( '' === $text ) {
					continue;
				}

				nxtcc_send_message_immediately(
					array(
						'user_mailid'         => $user_mailid,
						'business_account_id' => $business_account_id,
						'phone_number_id'     => $phone_number_id,
						'contact_id'          => (int) $cid,
						'message_content'     => $text,
					)
				);

				++$sent_count;
				continue;
			}

			// Forward media.
			$media = is_array( $parsed['media'] ) ? $parsed['media'] : array();

			$caption  = isset( $media['caption'] ) ? (string) $media['caption'] : '';
			$filename = isset( $media['filename'] ) ? (string) $media['filename'] : '';
			$link     = isset( $media['link'] ) ? (string) $media['link'] : '';

			// If no link, materialize link by downloading media_id to WP uploads.
			if ( '' === $link && ! empty( $media['media_id'] ) ) {

				$dl = nxtcc_chat_download_graph_media_to_wp(
					$user_mailid,
					$phone_number_id,
					(string) $media['media_id']
				);

				if ( is_array( $dl ) && empty( $dl['error'] ) ) {

					$link = isset( $dl['url'] ) ? (string) $dl['url'] : '';

					if ( '' === $filename && isset( $dl['filename'] ) ) {
						$filename = (string) $dl['filename'];
					}
				}
			}

			if ( '' === $link ) {
				continue;
			}

			$kind_to_send = nxtcc_forward_normalize_kind_to_send( $kind );

			nxtcc_send_media_link_immediately(
				array(
					'user_mailid'         => $user_mailid,
					'business_account_id' => $business_account_id,
					'phone_number_id'     => $phone_number_id,
					'contact_id'          => (int) $cid,
					'kind'                => $kind_to_send,
					'link'                => $link,
					'filename'            => $filename,
					'caption'             => $caption,
				)
			);

			++$sent_count;
		}
	}

	wp_send_json_success(
		array(
			'forwarded' => $sent_count,
		)
	);
}

add_action( 'wp_ajax_nxtcc_forward_messages', 'nxtcc_ajax_forward_messages' );
