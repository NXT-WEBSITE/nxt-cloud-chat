<?php
/**
 * AJAX endpoints: inbox summary + chat thread + mark-read.
 *
 * Endpoints:
 * - nxtcc_fetch_inbox_summary: list conversations with last message preview + unread count.
 * - nxtcc_fetch_chat_thread: fetch chat messages for a contact with reply context.
 * - nxtcc_mark_chat_read: mark all received messages for a contact as read.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_chat_ajax_require_caps' ) ) {

	/**
	 * Require proper capability for chat admin AJAX endpoints.
	 *
	 * Sends a JSON error response if the current user does not have
	 * sufficient permissions to access chat management features.
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

if ( ! function_exists( 'nxtcc_chat_can_reply_24h' ) ) {

	/**
	 * Compute whether the user can reply within the 24-hour window.
	 *
	 * @param string|null $last_incoming UTC datetime string from DB (created_at).
	 * @return bool
	 */
	function nxtcc_chat_can_reply_24h( ?string $last_incoming ): bool {
		if ( null === $last_incoming || '' === $last_incoming ) {
			return false;
		}

		$ts = strtotime( $last_incoming );
		if ( false === $ts ) {
			return false;
		}

		return ( time() - $ts ) <= ( 24 * HOUR_IN_SECONDS );
	}
}

/**
 * AJAX handler: Fetch inbox summary.
 *
 * @return void
 */
function nxtcc_ajax_fetch_inbox_summary(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid = '';
	if ( isset( $_POST['phone_number_id'] ) ) {
		$requested_pnid = sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) );
	}

	list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Phone number id not found for user.' ), 400 );
	}

	$repo = nxtcc_chat_repo();
	$rows = $repo->get_inbox_summary_rows( $user_mailid, $phone_number_id );

	foreach ( $rows as &$chat ) {
		if ( ! empty( $chat->last_msg_time ) ) {
			$chat->last_msg_time = get_date_from_gmt( $chat->last_msg_time, 'Y-m-d h:i A' );
		}

		$preview = isset( $chat->message_preview ) ? $chat->message_preview : '';

		// If preview is a JSON envelope, unwrap it into a display-friendly string.
		if ( is_string( $preview ) && '' !== $preview && '{' === $preview[0] ) {
			$obj = json_decode( $preview, true );

			if ( is_array( $obj ) ) {
				if ( isset( $obj['kind'] ) ) {
					if ( 'text' === (string) $obj['kind'] && isset( $obj['text'] ) ) {
						$chat->message_preview = (string) $obj['text'];
					} else {
						$cap = '';

						if ( isset( $obj['caption'] ) ) {
							$cap = (string) $obj['caption'];
						} elseif ( isset( $obj['filename'] ) ) {
							$cap = (string) $obj['filename'];
						} else {
							$cap = strtoupper( (string) $obj['kind'] );
						}

						if ( '' !== $cap ) {
							$chat->message_preview = '[' . (string) $obj['kind'] . '] ' . $cap;
						} else {
							$chat->message_preview = '[' . (string) $obj['kind'] . ']';
						}
					}
				} elseif ( isset( $obj['text'] ) ) {
					$chat->message_preview = (string) $obj['text'];
				}
			}
		}
	}
	unset( $chat );

	wp_send_json_success( array( 'contacts' => $rows ) );
}
add_action( 'wp_ajax_nxtcc_fetch_inbox_summary', 'nxtcc_ajax_fetch_inbox_summary' );

/**
 * AJAX handler: Fetch chat thread for a contact.
 *
 * Supports paging via:
 * - after_id: fetch newer items after id (ascending).
 * - before_id: fetch older items before id (descending, limited).
 *
 * @return void
 */
function nxtcc_ajax_fetch_chat_thread(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$contact_id = 0;
	if ( isset( $_POST['contact_id'] ) ) {
		$contact_id = absint( wp_unslash( $_POST['contact_id'] ) );
	}

	if ( 0 === $contact_id ) {
		wp_send_json_error( array( 'message' => 'Missing contact id.' ), 400 );
	}

	$requested_pnid = '';
	if ( isset( $_POST['phone_number_id'] ) ) {
		$requested_pnid = sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) );
	}

	list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Phone number id not found for user.' ), 400 );
	}

	$after_id = null;
	if ( isset( $_POST['after_id'] ) ) {
		$after_val = absint( wp_unslash( $_POST['after_id'] ) );
		if ( 0 < $after_val ) {
			$after_id = $after_val;
		}
	}

	$before_id = null;
	if ( isset( $_POST['before_id'] ) ) {
		$before_val = absint( wp_unslash( $_POST['before_id'] ) );
		if ( 0 < $before_val ) {
			$before_id = $before_val;
		}
	}

	$limit = 20;
	$repo  = nxtcc_chat_repo();

	/*
	 * Poll-optimization:
	 * If this is an "after_id" request (polling), do a cheap existence check first.
	 * If no new rows, return early without building reply maps or formatting messages.
	 */
	if ( null !== $after_id && 0 < $after_id && method_exists( $repo, 'has_new_messages_after' ) ) {
		$has_new = $repo->has_new_messages_after( $contact_id, $user_mailid, $phone_number_id, (int) $after_id );

		if ( false === $has_new ) {
			$last_incoming = $repo->get_last_incoming_time( $contact_id, $user_mailid );

			wp_send_json_success(
				array(
					'messages'       => array(),
					'can_reply_24hr' => nxtcc_chat_can_reply_24h( $last_incoming ),
				)
			);
		}
	}

	$messages = $repo->get_chat_thread_messages(
		$contact_id,
		$user_mailid,
		$phone_number_id,
		$after_id,
		$before_id,
		$limit
	);

	if ( ! is_array( $messages ) ) {
		$messages = array();
	}

	/*
	 * Build reply map for quick lookup of replied-to messages.
	 * Primary key: reply_to_history_id; fallback key: reply_to_wamid.
	 */
	$reply_ids = array();
	foreach ( $messages as $msg ) {
		if ( ! empty( $msg->reply_to_history_id ) ) {
			$reply_ids[] = (int) $msg->reply_to_history_id;
		}
	}
	$reply_ids = array_values( array_unique( array_filter( $reply_ids ) ) );

	$reply_map = array();

	if ( ! empty( $reply_ids ) ) {
		$rows = $repo->get_reply_rows_by_ids( $reply_ids );
		foreach ( $rows as $row ) {
			$reply_map[ (int) $row->id ] = $row;
		}
	}

	$wamids = array();
	foreach ( $messages as $msg ) {
		if ( empty( $msg->reply_to_history_id ) && ! empty( $msg->reply_to_wamid ) ) {
			$wamids[] = (string) $msg->reply_to_wamid;
		}
	}
	$wamids = array_values( array_unique( array_filter( $wamids ) ) );

	if ( ! empty( $wamids ) ) {
		$rows = $repo->get_reply_rows_by_wamids( $wamids, $user_mailid, $phone_number_id );
		foreach ( $rows as $row ) {
			if ( ! empty( $row->meta_message_id ) ) {
				$reply_map[ (string) $row->meta_message_id ] = $row;
			}
		}
	}

	foreach ( $messages as &$msg ) {
		if ( ! empty( $msg->created_at ) ) {
			$msg->created_at = get_date_from_gmt( $msg->created_at, 'Y-m-d h:i A' );
		}

		$msg->is_read     = isset( $msg->is_read ) ? (int) $msg->is_read : 0;
		$msg->is_favorite = isset( $msg->is_favorite ) ? (int) $msg->is_favorite : 0;

		$reply_payload = null;

		if ( ! empty( $msg->reply_to_history_id ) ) {
			$key = (int) $msg->reply_to_history_id;

			if ( isset( $reply_map[ $key ] ) ) {
				$reply_payload = nxtcc_chat_make_reply_payload( $reply_map[ $key ] );
			}
		} elseif ( ! empty( $msg->reply_to_wamid ) ) {
			$key = (string) $msg->reply_to_wamid;

			if ( isset( $reply_map[ $key ] ) ) {
				$reply_payload = nxtcc_chat_make_reply_payload( $reply_map[ $key ] );
			}
		}

		if ( null !== $reply_payload ) {
			$msg->reply = $reply_payload;
		}
	}
	unset( $msg );

	/*
	 * The UI expects chronological ordering when loading initial thread,
	 * but expects ascending order for "after_id" incremental loads.
	 */
	if ( null === $after_id ) {
		$messages = array_reverse( $messages );
	}

	$last_incoming = $repo->get_last_incoming_time( $contact_id, $user_mailid );

	wp_send_json_success(
		array(
			'messages'       => $messages,
			'can_reply_24hr' => nxtcc_chat_can_reply_24h( $last_incoming ),
		)
	);
}
add_action( 'wp_ajax_nxtcc_fetch_chat_thread', 'nxtcc_ajax_fetch_chat_thread' );

/**
 * AJAX handler: Mark chat as read for a contact.
 *
 * @return void
 */
function nxtcc_ajax_mark_chat_read(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$contact_id = 0;
	if ( isset( $_POST['contact_id'] ) ) {
		$contact_id = absint( wp_unslash( $_POST['contact_id'] ) );
	}

	if ( 0 === $contact_id ) {
		wp_send_json_error( array( 'message' => 'Missing contact id.' ), 400 );
	}

	$requested_pnid = '';
	if ( isset( $_POST['phone_number_id'] ) ) {
		$requested_pnid = sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) );
	}

	list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Phone number id not found for user.' ), 400 );
	}

	$repo = nxtcc_chat_repo();
	$repo->mark_chat_read( $contact_id, $user_mailid, $phone_number_id );

	wp_send_json_success( array( 'message' => 'Marked as read.' ) );
}
add_action( 'wp_ajax_nxtcc_mark_chat_read', 'nxtcc_ajax_mark_chat_read' );
