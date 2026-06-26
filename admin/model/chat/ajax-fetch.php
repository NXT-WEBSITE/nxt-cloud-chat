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
		if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
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

if ( ! function_exists( 'nxtcc_chat_require_contact_access' ) ) {
	/**
	 * Require current CRM scope access to one chat conversation.
	 *
	 * @param int  $contact_id Contact ID.
	 * @param bool $manage Whether mutation access is required.
	 * @return void
	 */
	function nxtcc_chat_require_contact_access( int $contact_id, bool $manage = false ): void {
		$tenant       = NXTCC_Access_Control::get_current_tenant_context();
		$conversation = NXTCC_Conversations::instance()->get_or_create_for_contact( $contact_id, $tenant );
		$allowed      = is_array( $conversation ) && ( $manage
			? NXTCC_CRM_Access_Policy::user_can_manage_conversation( absint( $conversation['id'] ), $tenant )
			: NXTCC_CRM_Access_Policy::user_can_view_conversation( absint( $conversation['id'] ), $tenant ) );

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'This chat is outside your assigned record scope.', 'nxt-cloud-chat' ) ), 403 );
		}
	}
}

if ( ! function_exists( 'nxtcc_chat_filter_contact_ids_by_conversation_access' ) ) {
	/**
	 * Filter contact IDs by their conversation ticket scope.
	 *
	 * @param array $contact_ids Contact IDs.
	 * @param bool  $manage Require mutation access.
	 * @return array<int,int>
	 */
	function nxtcc_chat_filter_contact_ids_by_conversation_access( array $contact_ids, bool $manage = false ): array {
		$tenant        = NXTCC_Access_Control::get_current_tenant_context();
		$conversations = NXTCC_Conversations::instance()->get_for_contacts( $contact_ids, $tenant );
		$allowed       = NXTCC_CRM_Access_Policy::filter_conversations( array_values( $conversations ), $tenant, $manage );

		return array_values( array_filter( array_map( 'absint', wp_list_pluck( $allowed, 'contact_id' ) ) ) );
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

	$repo                  = nxtcc_chat_repo();
	$rows                  = $repo->get_inbox_summary_rows( $user_mailid, $phone_number_id );
	$tenant                = NXTCC_Access_Control::get_current_tenant_context();
	$policy                = NXTCC_CRM_Access_Policy::get_policy( 0, $tenant, 'nxtcc_access_chat' );
	$conversation_map      = NXTCC_Conversations::instance()->get_for_contacts(
		array_map(
			static function ( $row ): int {
				return isset( $row->contact_id ) ? absint( $row->contact_id ) : 0;
			},
			$rows
		),
		$tenant
	);
	$allowed_conversations = NXTCC_CRM_Access_Policy::filter_conversations( array_values( $conversation_map ), $tenant );
	$allowed_lookup        = array_fill_keys( array_map( 'absint', wp_list_pluck( $allowed_conversations, 'contact_id' ) ), true );
	$rows                  = array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $allowed_lookup ): bool {
				return isset( $allowed_lookup[ absint( $row->contact_id ?? 0 ) ] );
			}
		)
	);

	$view          = isset( $_POST['ticket_view'] ) ? sanitize_key( wp_unslash( $_POST['ticket_view'] ) ) : 'all';
	$now           = current_time( 'mysql', true );
	$recent_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	$rows          = array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $conversation_map, $view, $policy, $now, $recent_cutoff ): bool {
				$conversation = $conversation_map[ absint( $row->contact_id ?? 0 ) ] ?? null;
				if ( ! is_array( $conversation ) || 'all' === $view ) {
					return is_array( $conversation );
				}

				if ( 'mine' === $view ) {
					return absint( $conversation['assigned_user_id'] ?? 0 ) === absint( $policy['user_id'] ?? 0 );
				}
				if ( 'team' === $view ) {
					return '' !== (string) ( $policy['role_key'] ?? '' )
						&& (string) ( $conversation['assigned_role'] ?? '' ) === (string) $policy['role_key'];
				}
				if ( 'unassigned' === $view ) {
					return 0 === absint( $conversation['assigned_user_id'] ?? 0 ) && '' === (string) ( $conversation['assigned_role'] ?? '' );
				}
				if ( 'overdue' === $view ) {
					return ( ! empty( $conversation['first_response_due_at'] ) && (string) $conversation['first_response_due_at'] < $now && empty( $conversation['first_response_at'] ) )
						|| ( ! empty( $conversation['resolution_due_at'] ) && (string) $conversation['resolution_due_at'] < $now && ! in_array( $conversation['status'], array( 'resolved', 'closed' ), true ) );
				}
				if ( 'resolved' === $view ) {
					return in_array( $conversation['status'], array( 'resolved', 'closed' ), true )
						&& ! empty( $conversation['updated_at'] )
						&& (string) $conversation['updated_at'] >= $recent_cutoff;
				}

				return true;
			}
		)
	);

	foreach ( $rows as &$chat ) {
		$chat->conversation = $conversation_map[ absint( $chat->contact_id ?? 0 ) ] ?? null;
		$chat->assignment   = is_array( $chat->conversation )
			? array(
				'label'            => $chat->conversation['assignment_label'],
				'target_type'      => absint( $chat->conversation['assigned_user_id'] ) > 0 ? 'user' : ( '' !== $chat->conversation['assigned_role'] ? 'role' : '' ),
				'assigned_user_id' => absint( $chat->conversation['assigned_user_id'] ),
				'assigned_role'    => $chat->conversation['assigned_role'],
			)
			: null;
		if ( ! empty( $chat->last_msg_time ) ) {
			$chat->last_msg_time = get_date_from_gmt( $chat->last_msg_time, 'Y-m-d h:i A' );
		}

		$preview             = isset( $chat->message_preview ) ? $chat->message_preview : '';
		$interactive_preview = function_exists( 'nxtcc_chat_get_interactive_payload' )
			? nxtcc_chat_get_interactive_payload(
				array(
					'message_content' => $chat->message_preview,
					'response_json'   => isset( $chat->message_preview_json ) ? $chat->message_preview_json : '',
				)
			)
			: array();
		$template_preview    = function_exists( 'nxtcc_chat_get_template_preview_payload' )
			? nxtcc_chat_get_template_preview_payload(
				array(
					'user_mailid'         => isset( $chat->message_preview_user_mailid ) ? $chat->message_preview_user_mailid : '',
					'business_account_id' => isset( $chat->message_preview_business_account_id ) ? $chat->message_preview_business_account_id : '',
					'phone_number_id'     => isset( $chat->message_preview_phone_number_id ) ? $chat->message_preview_phone_number_id : '',
					'template_name'       => isset( $chat->message_preview_template_name ) ? $chat->message_preview_template_name : '',
					'template_type'       => isset( $chat->message_preview_template_type ) ? $chat->message_preview_template_type : '',
					'template_data'       => isset( $chat->message_preview_template_data ) ? $chat->message_preview_template_data : '',
					'message_content'     => $chat->message_preview,
				)
			)
			: array();

		if ( ! empty( $interactive_preview['message_content'] ) ) {
			$preview               = (string) $interactive_preview['message_content'];
			$chat->message_preview = $preview;
		} elseif ( ! empty( $template_preview['template_preview'] ) ) {
			$template_name         = sanitize_text_field( (string) ( $template_preview['template_preview']['template_name'] ?? '' ) );
			$chat->message_preview = '' !== $template_name
				? sprintf(
					/* translators: %s: Template name. */
					__( 'Template: %s', 'nxt-cloud-chat' ),
					$template_name
				)
				: __( 'Template message', 'nxt-cloud-chat' );
			$preview = $chat->message_preview;
		}

		if ( ( ! is_string( $preview ) || '' === trim( $preview ) ) && function_exists( 'nxtcc_chat_extract_message_content_from_message' ) ) {
			$preview               = nxtcc_chat_extract_message_content_from_message(
				array(
					'message_content' => $chat->message_preview,
					'response_json'   => isset( $chat->message_preview_json ) ? $chat->message_preview_json : '',
				)
			);
			$chat->message_preview = $preview;
		}

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

		unset(
			$chat->message_preview_json,
			$chat->message_preview_template_name,
			$chat->message_preview_template_type,
			$chat->message_preview_template_data,
			$chat->message_preview_user_mailid,
			$chat->message_preview_business_account_id,
			$chat->message_preview_phone_number_id
		);
	}
	unset( $chat );

	wp_send_json_success(
		array(
			'contacts'      => $rows,
			'access_policy' => array(
				'can_manage' => NXTCC_CRM_Access_Policy::can_manage( $policy ),
			),
		)
	);
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
	nxtcc_chat_require_contact_access( $contact_id );

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

	$after_activity_id  = isset( $_POST['after_activity_id'] ) ? absint( wp_unslash( $_POST['after_activity_id'] ) ) : 0;
	$before_activity_id = isset( $_POST['before_activity_id'] ) ? absint( wp_unslash( $_POST['before_activity_id'] ) ) : 0;
	$include_messages   = ! isset( $_POST['include_messages'] ) || rest_sanitize_boolean( wp_unslash( $_POST['include_messages'] ) );
	$include_activities = ! isset( $_POST['include_activities'] ) || rest_sanitize_boolean( wp_unslash( $_POST['include_activities'] ) );
	$limit              = 20;
	$repo               = nxtcc_chat_repo();
	$tenant             = NXTCC_Access_Control::get_current_tenant_context();
	$activity_page      = array(
		'items'              => array(),
		'has_more'           => false,
		'oldest_activity_id' => 0,
		'latest_activity_id' => 0,
	);

	if ( $include_activities && NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_crm_activity' ) ) ) {
		$activity_page = NXTCC_Chat_Timeline::get(
			$contact_id,
			$tenant,
			array(
				'limit'              => $limit,
				'after_activity_id'  => $after_activity_id,
				'before_activity_id' => $before_activity_id,
			)
		);
	}

	/*
	 * Poll-optimization:
	 * If this is an "after_id" request (polling), do a cheap existence check first.
	 * If no new rows, return early without building reply maps or formatting messages.
	 */
	if ( $include_messages && null !== $after_id && 0 < $after_id && method_exists( $repo, 'has_new_messages_after' ) ) {
		$has_new = $repo->has_new_messages_after( $contact_id, $user_mailid, $phone_number_id, (int) $after_id );

		if ( false === $has_new && empty( $activity_page['items'] ) ) {
			$last_incoming = $repo->get_last_incoming_time( $contact_id, $user_mailid );

			wp_send_json_success(
				array(
					'messages'          => array(),
					'activities'        => array(),
					'message_has_more'  => false,
					'activity_has_more' => false,
					'can_reply_24hr'    => nxtcc_chat_can_reply_24h( $last_incoming ),
				)
			);
		}
	}

	$messages = $include_messages
		? $repo->get_chat_thread_messages(
			$contact_id,
			$user_mailid,
			$phone_number_id,
			$after_id,
			$before_id,
			$limit + 1
		)
		: array();

	if ( ! is_array( $messages ) ) {
		$messages = array();
	}
	$message_has_more = count( $messages ) > $limit;
	$messages         = array_slice( $messages, 0, $limit );

	foreach ( $messages as &$msg ) {
		if ( empty( $msg->message_content ) && function_exists( 'nxtcc_chat_extract_message_content_from_message' ) ) {
			$rebuilt_content = nxtcc_chat_extract_message_content_from_message( $msg );

			if ( '' !== $rebuilt_content ) {
				$msg->message_content = $rebuilt_content;
			}
		}

		if ( empty( $msg->reply_to_wamid ) && function_exists( 'nxtcc_chat_extract_reply_wamid_from_message' ) ) {
			$derived_reply_wamid = nxtcc_chat_extract_reply_wamid_from_message( $msg );

			if ( '' !== $derived_reply_wamid ) {
				$msg->reply_to_wamid = $derived_reply_wamid;
			}
		}

		if ( function_exists( 'nxtcc_chat_get_interactive_payload' ) ) {
			$interactive_payload = nxtcc_chat_get_interactive_payload( $msg );

			if ( ! empty( $interactive_payload ) ) {
				foreach ( $interactive_payload as $key => $value ) {
					$msg->{$key} = $value;
				}
			}
		}

		if ( empty( $msg->message_kind ) && function_exists( 'nxtcc_chat_get_template_preview_payload' ) ) {
			$template_payload = nxtcc_chat_get_template_preview_payload( $msg );

			if ( ! empty( $template_payload ) ) {
				foreach ( $template_payload as $key => $value ) {
					$msg->{$key} = $value;
				}
			}
		}
	}
	unset( $msg );

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
			$msg->created_at_utc = (string) $msg->created_at;
			$msg->created_at     = get_date_from_gmt( $msg->created_at, 'Y-m-d h:i A' );
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

		unset( $msg->response_json );
		unset( $msg->template_data, $msg->template_type, $msg->template_name );
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
	$conversation  = NXTCC_Conversations::instance()->get_or_create_for_contact( $contact_id, $tenant );

	wp_send_json_success(
		array(
			'messages'           => $messages,
			'activities'         => $activity_page['items'],
			'message_has_more'   => $message_has_more,
			'activity_has_more'  => ! empty( $activity_page['has_more'] ),
			'oldest_activity_id' => absint( $activity_page['oldest_activity_id'] ?? 0 ),
			'latest_activity_id' => absint( $activity_page['latest_activity_id'] ?? 0 ),
			'can_reply_24hr'     => nxtcc_chat_can_reply_24h( $last_incoming ),
			'conversation'       => $conversation,
		)
	);
}
add_action( 'wp_ajax_nxtcc_fetch_chat_thread', 'nxtcc_ajax_fetch_chat_thread' );

/**
 * AJAX handler: Fetch an exact CRM activity with nearby timeline context.
 *
 * @return void
 */
function nxtcc_ajax_focus_chat_activity(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'nxt-cloud-chat' ) ), 401 );
	}

	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_crm_activity' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot view CRM activity.', 'nxt-cloud-chat' ) ), 403 );
	}

	$activity_id = isset( $_POST['activity_id'] ) ? absint( wp_unslash( $_POST['activity_id'] ) ) : 0;
	$contact_id  = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
	$tenant      = NXTCC_Access_Control::get_current_tenant_context();
	$activity    = NXTCC_CRM_Activities::instance()->get( $activity_id, $tenant );

	if ( ! is_array( $activity ) || 0 >= $contact_id || absint( $activity['contact_id'] ?? 0 ) !== $contact_id ) {
		wp_send_json_error( array( 'message' => __( 'Activity not found for this contact.', 'nxt-cloud-chat' ) ), 404 );
	}

	nxtcc_chat_require_contact_access( $contact_id );

	wp_send_json_success( NXTCC_Chat_Timeline::get_context( $activity_id, $tenant, 10 ) );
}
add_action( 'wp_ajax_nxtcc_focus_chat_activity', 'nxtcc_ajax_focus_chat_activity' );

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
	nxtcc_chat_require_contact_access( $contact_id, true );

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
