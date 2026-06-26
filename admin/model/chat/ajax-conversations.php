<?php
/**
 * AJAX endpoints for conversation ticket management.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve and authorize one conversation from a request.
 *
 * @return array<string,mixed>
 */
function nxtcc_chat_ajax_conversation(): array {
	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$conversation_id = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
	$contact_id      = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
	$tenant          = NXTCC_Access_Control::get_current_tenant_context();
	$conversation    = $conversation_id > 0
		? NXTCC_Conversations::instance()->get( $conversation_id, $tenant )
		: NXTCC_Conversations::instance()->get_or_create_for_contact( $contact_id, $tenant );

	if ( ! is_array( $conversation ) ) {
		wp_send_json_error( array( 'message' => __( 'Conversation not found.', 'nxt-cloud-chat' ) ), 404 );
	}

	if ( ! NXTCC_CRM_Access_Policy::user_can_view_conversation( absint( $conversation['id'] ), $tenant ) ) {
		wp_send_json_error( array( 'message' => __( 'This conversation is outside your assigned record scope.', 'nxt-cloud-chat' ) ), 403 );
	}

	return $conversation;
}

/**
 * Fetch ticket details and audit timeline.
 *
 * @return void
 */
function nxtcc_ajax_get_conversation_ticket(): void {
	$conversation      = nxtcc_chat_ajax_conversation();
	$tenant            = NXTCC_Access_Control::get_current_tenant_context();
	$can_view_activity = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_crm_activity' ) );
	NXTCC_Conversations::instance()->set_current_for_contact(
		absint( $conversation['contact_id'] ?? 0 ),
		absint( $conversation['id'] ?? 0 ),
		$tenant,
		get_current_user_id()
	);
	$tickets = array_values(
		array_filter(
			NXTCC_Conversations::instance()->list_for_contact( absint( $conversation['contact_id'] ?? 0 ), $tenant ),
			static function ( array $ticket ) use ( $tenant ): bool {
				return NXTCC_CRM_Access_Policy::user_can_view_conversation( absint( $ticket['id'] ?? 0 ), $tenant );
			}
		)
	);

	wp_send_json_success(
		array(
			'conversation'       => $conversation,
			'tickets'            => $tickets,
			'categories'         => NXTCC_Ticket_Categories::instance()->list_categories( $tenant, true ),
			'activity'           => $can_view_activity ? NXTCC_Conversations::instance()->list_activity( absint( $conversation['id'] ), $tenant ) : array(),
			'statuses'           => NXTCC_Conversations::instance()->get_statuses(),
			'priorities'         => NXTCC_Conversations::instance()->get_priorities(),
			'assignment_targets' => nxtcc_list_contact_assignment_targets( $tenant ),
			'permissions'        => array(
				'can_manage'        => NXTCC_CRM_Access_Policy::user_can_manage_conversation( absint( $conversation['id'] ), $tenant ),
				'can_view_activity' => $can_view_activity,
				'can_reassign'      => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_reassign_conversations' ) ),
				'can_resolve'       => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_resolve_conversations' ) ),
				'can_note'          => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_crm_notes' ) ),
			),
		)
	);
}
add_action( 'wp_ajax_nxtcc_get_conversation_ticket', 'nxtcc_ajax_get_conversation_ticket' );

/**
 * Convert a local datetime input into UTC.
 *
 * @param string $value Local datetime input.
 * @return string
 */
function nxtcc_chat_ticket_local_datetime_to_gmt( string $value ): string {
	$value = sanitize_text_field( str_replace( 'T', ' ', $value ) );
	if ( 16 === strlen( $value ) ) {
		$value .= ':00';
	}

	return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? get_gmt_from_date( $value ) : '';
}

/**
 * Save a complete ticket panel and optionally send its customer message.
 *
 * @return void
 */
function nxtcc_ajax_save_conversation_ticket(): void {
	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$tenant          = NXTCC_Access_Control::get_current_tenant_context();
	$conversation_id = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
	$contact_id      = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
	$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$internal_note   = isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : '';
	$send_message    = isset( $_POST['send_message'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['send_message'] ) );
	$is_new          = $conversation_id <= 0;

	if ( $contact_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Choose a valid contact before saving the ticket.', 'nxt-cloud-chat' ) ), 400 );
	}
	nxtcc_chat_require_contact_access( $contact_id, true );
	if ( $conversation_id > 0 ) {
		$existing = NXTCC_Conversations::instance()->get( $conversation_id, $tenant );
		if ( ! is_array( $existing )
			|| absint( $existing['contact_id'] ?? 0 ) !== $contact_id
			|| ! NXTCC_CRM_Access_Policy::user_can_manage_conversation( $conversation_id, $tenant )
		) {
			wp_send_json_error( array( 'message' => __( 'This ticket is outside your assigned record scope.', 'nxt-cloud-chat' ) ), 403 );
		}
	}

	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_resolve_conversations' ) )
		|| ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_reassign_conversations' ) )
	) {
		wp_send_json_error( array( 'message' => __( 'You cannot create or update assigned tickets.', 'nxt-cloud-chat' ) ), 403 );
	}
	if ( '' !== trim( $internal_note )
		&& ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_crm_notes' ) )
	) {
		wp_send_json_error( array( 'message' => __( 'You cannot add internal notes.', 'nxt-cloud-chat' ) ), 403 );
	}
	if ( $is_new && ( ! $send_message || '' === trim( $message ) ) ) {
		wp_send_json_error( array( 'message' => __( 'A customer message is required when creating a ticket.', 'nxt-cloud-chat' ) ), 400 );
	}
	if ( $send_message && '' === trim( $message ) ) {
		wp_send_json_error( array( 'message' => __( 'Enter a message before sending.', 'nxt-cloud-chat' ) ), 400 );
	}
	$message_length = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
	if ( $message_length > 4096 ) {
		wp_send_json_error( array( 'message' => __( 'Ticket messages cannot exceed 4,096 characters.', 'nxt-cloud-chat' ) ), 400 );
	}

	$args = array_merge(
		$tenant,
		array(
			'conversation_id'   => $conversation_id,
			'contact_id'        => $contact_id,
			'subject'           => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'category_id'       => isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0,
			'status'            => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'open',
			'priority'          => isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'normal',
			'assignment_target' => isset( $_POST['assignment_target'] ) ? sanitize_text_field( wp_unslash( $_POST['assignment_target'] ) ) : '',
			'handoff_note'      => isset( $_POST['handoff_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['handoff_note'] ) ) : '',
			'handoff_reason'    => isset( $_POST['handoff_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['handoff_reason'] ) ) : '',
			'internal_note'     => $internal_note,
			'customer_message'  => $message,
			'actor_id'          => get_current_user_id(),
		)
	);
	foreach ( array( 'snoozed_until', 'first_response_due_at', 'resolution_due_at' ) as $date_field ) {
		$date_value = isset( $_POST[ $date_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $date_field ] ) ) : '';
		if ( '' !== $date_value ) {
			$args[ $date_field ] = nxtcc_chat_ticket_local_datetime_to_gmt( $date_value );
		}
	}

	$result = NXTCC_Conversations::instance()->save_ticket( $args );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'error'   => (string) ( $result['error'] ?? '' ),
				'message' => (string) ( $result['message'] ?? __( 'Unable to save the ticket.', 'nxt-cloud-chat' ) ),
			),
			400
		);
	}

	$message_sent  = false;
	$message_error = '';
	$conversation  = is_array( $result['conversation'] ?? null ) ? $result['conversation'] : array();
	if ( $send_message ) {
		if ( ! function_exists( 'nxtcc_chat_repo' ) ) {
			require_once NXTCC_PLUGIN_DIR . 'admin/model/chat/chat-helpers.php';
		}
		$last_incoming = nxtcc_chat_repo()->get_last_incoming_time( $contact_id, (string) $tenant['user_mailid'] );
		if ( function_exists( 'nxtcc_chat_can_reply_24h' ) && ! nxtcc_chat_can_reply_24h( $last_incoming ) ) {
			$message_error = __( 'Ticket saved, but the message was not sent because the 24-hour reply window is closed.', 'nxt-cloud-chat' );
		} else {
			$send_result  = nxtcc_send_message_immediately(
				array_merge(
					$tenant,
					array(
						'contact_id'      => $contact_id,
						'conversation_id' => absint( $conversation['id'] ?? 0 ),
						'message_content' => $message,
						'origin_type'     => 'chat_user',
						'origin_user_id'  => get_current_user_id(),
						'origin_ref'      => 'ticket:' . absint( $conversation['id'] ?? 0 ),
					)
				)
			);
			$message_sent = ! empty( $send_result['success'] );
			if ( $message_sent ) {
				NXTCC_Conversations::instance()->touch_outbound_for_ticket( absint( $conversation['id'] ?? 0 ), $tenant );
			} else {
				$message_error = isset( $send_result['error'] )
					? sanitize_text_field( (string) $send_result['error'] )
					: __( 'Ticket saved, but the customer message could not be sent.', 'nxt-cloud-chat' );
			}
		}
	}

	$result['message_sent']  = $message_sent;
	$result['message_error'] = $message_error;
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_save_conversation_ticket', 'nxtcc_ajax_save_conversation_ticket' );

/**
 * Save a tenant ticket category.
 *
 * @return void
 */
function nxtcc_ajax_save_ticket_category(): void {
	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_resolve_conversations' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot manage ticket categories.', 'nxt-cloud-chat' ) ), 403 );
	}

	$result = NXTCC_Ticket_Categories::instance()->save(
		array_merge(
			NXTCC_Access_Control::get_current_tenant_context(),
			array(
				'category_id'   => isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0,
				'category_name' => isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '',
				'color'         => isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '#2271b1',
				'description'   => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
				'is_active'     => ! isset( $_POST['is_active'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['is_active'] ) ),
				'actor_id'      => get_current_user_id(),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result, 400 );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_save_ticket_category', 'nxtcc_ajax_save_ticket_category' );

/**
 * Delete or archive a tenant ticket category.
 *
 * @return void
 */
function nxtcc_ajax_delete_ticket_category(): void {
	nxtcc_chat_ajax_require_caps();
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_resolve_conversations' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot manage ticket categories.', 'nxt-cloud-chat' ) ), 403 );
	}

	$result = NXTCC_Ticket_Categories::instance()->delete_or_archive(
		isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0,
		NXTCC_Access_Control::get_current_tenant_context(),
		get_current_user_id()
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result, 400 );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_delete_ticket_category', 'nxtcc_ajax_delete_ticket_category' );

/**
 * Follow or unfollow a ticket.
 *
 * @return void
 */
function nxtcc_ajax_set_conversation_watcher(): void {
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	$conversation = nxtcc_chat_ajax_conversation();
	$result       = NXTCC_Conversations::instance()->set_watcher(
		array_merge(
			NXTCC_Access_Control::get_current_tenant_context(),
			array(
				'conversation_id' => absint( $conversation['id'] ),
				'wp_user_id'      => get_current_user_id(),
				'actor_id'        => get_current_user_id(),
				'watch'           => isset( $_POST['watch'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['watch'] ) ),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array( 'message' => (string) ( $result['message'] ?? __( 'Unable to update ticket followers.', 'nxt-cloud-chat' ) ) ),
			400
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_set_conversation_watcher', 'nxtcc_ajax_set_conversation_watcher' );
