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
 * @param bool $manage Require mutation access.
 * @return array<string,mixed>
 */
function nxtcc_chat_ajax_conversation( bool $manage = false ): array {
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

	$allowed = $manage
		? NXTCC_CRM_Access_Policy::user_can_manage_conversation( absint( $conversation['id'] ), $tenant )
		: NXTCC_CRM_Access_Policy::user_can_view_conversation( absint( $conversation['id'] ), $tenant );
	if ( ! $allowed ) {
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
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	$conversation      = nxtcc_chat_ajax_conversation();
	$tenant            = NXTCC_Access_Control::get_current_tenant_context();
	$can_view_activity = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_crm_activity' ) );

	wp_send_json_success(
		array(
			'conversation'       => $conversation,
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
 * Update ticket fields.
 *
 * @return void
 */
function nxtcc_ajax_update_conversation_ticket(): void {
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	$conversation = nxtcc_chat_ajax_conversation( true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_resolve_conversations' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot update conversation ticket details.', 'nxt-cloud-chat' ) ), 403 );
	}

	$args = array_merge(
		NXTCC_Access_Control::get_current_tenant_context(),
		array(
			'conversation_id' => absint( $conversation['id'] ),
			'actor_id'        => get_current_user_id(),
		)
	);
	foreach ( array( 'status', 'priority', 'subject', 'category', 'snoozed_until' ) as $field ) {
		if ( isset( $_POST[ $field ] ) ) {
			$args[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		}
	}
	if ( ! empty( $args['snoozed_until'] ) ) {
		$snoozed_until         = str_replace( 'T', ' ', (string) $args['snoozed_until'] );
		$args['snoozed_until'] = get_gmt_from_date( 16 === strlen( $snoozed_until ) ? $snoozed_until . ':00' : $snoozed_until );
	}

	$result = NXTCC_Conversations::instance()->update( $args );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Unable to update the conversation.', 'nxt-cloud-chat' ) ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_update_conversation_ticket', 'nxtcc_ajax_update_conversation_ticket' );

/**
 * Assign or hand off a ticket.
 *
 * @return void
 */
function nxtcc_ajax_assign_conversation_ticket(): void {
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	$conversation = nxtcc_chat_ajax_conversation( true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_reassign_conversations' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot reassign conversations.', 'nxt-cloud-chat' ) ), 403 );
	}

	$result = NXTCC_Conversations::instance()->assign(
		array_merge(
			NXTCC_Access_Control::get_current_tenant_context(),
			array(
				'conversation_id'   => absint( $conversation['id'] ),
				'assignment_target' => isset( $_POST['assignment_target'] ) ? sanitize_text_field( wp_unslash( $_POST['assignment_target'] ) ) : '',
				'note'              => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				'reason'            => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				'actor_id'          => get_current_user_id(),
				'source'            => 'manual',
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'error'   => (string) ( $result['error'] ?? '' ),
				'message' => (string) ( $result['message'] ?? __( 'Unable to assign the conversation.', 'nxt-cloud-chat' ) ),
			),
			400
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_assign_conversation_ticket', 'nxtcc_ajax_assign_conversation_ticket' );

/**
 * Add an internal ticket note.
 *
 * @return void
 */
function nxtcc_ajax_add_conversation_note(): void {
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	$conversation = nxtcc_chat_ajax_conversation( true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_crm_notes' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot add internal notes.', 'nxt-cloud-chat' ) ), 403 );
	}

	$result = NXTCC_Conversations::instance()->add_note(
		array_merge(
			NXTCC_Access_Control::get_current_tenant_context(),
			array(
				'conversation_id' => absint( $conversation['id'] ),
				'note'            => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				'actor_id'        => get_current_user_id(),
				'source'          => 'manual',
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Add a valid internal note.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_add_conversation_note', 'nxtcc_ajax_add_conversation_note' );

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
