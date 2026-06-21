<?php
/**
 * Contact profile AJAX actions.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Format a stored UTC timestamp in the WordPress site timezone.
 *
 * @param string $value UTC timestamp.
 * @return string
 */
function nxtcc_contact_profile_format_date( string $value ): string {
	$value = sanitize_text_field( $value );
	if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
		return '';
	}

	return get_date_from_gmt(
		$value,
		get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
	);
}

/**
 * Decode contact custom fields for safe JSON output.
 *
 * @param mixed $value Stored custom fields.
 * @return array<string|int,mixed>
 */
function nxtcc_contact_profile_custom_fields( $value ): array {
	if ( is_array( $value ) ) {
		return nxtcc_contacts_sanitize_custom_fields( $value );
	}

	$decoded = json_decode( (string) $value, true );
	return is_array( $decoded ) ? nxtcc_contacts_sanitize_custom_fields( $decoded ) : array();
}

/**
 * Reduce group rows to fields required by the profile UI.
 *
 * @param array<int,mixed> $rows Group rows.
 * @return array<int,array<string,mixed>>
 */
function nxtcc_contact_profile_groups( array $rows ): array {
	$output = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$output[] = array(
			'group_name'  => sanitize_text_field( (string) ( $row['group_name'] ?? '' ) ),
			'is_verified' => ! empty( $row['is_verified'] ),
		);
	}

	return $output;
}

/**
 * Reduce tag rows to fields required by the profile UI.
 *
 * @param array<int,mixed> $rows Tag rows.
 * @return array<int,array<string,string>>
 */
function nxtcc_contact_profile_tags( array $rows ): array {
	$output = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$color    = sanitize_hex_color( (string) ( $row['color'] ?? '' ) );
		$output[] = array(
			'tag_name' => sanitize_text_field( (string) ( $row['tag_name'] ?? '' ) ),
			'color'    => is_string( $color ) ? $color : '',
		);
	}

	return $output;
}

/**
 * Read a compact tenant-scoped message summary for a contact.
 *
 * @param int                 $contact_id Contact ID.
 * @param array<string,mixed> $tenant Tenant tuple.
 * @return array<string,mixed>
 */
function nxtcc_contact_profile_message_context( int $contact_id, array $tenant ): array {
	$db        = NXTCC_DB::i();
	$table     = preg_replace( '/[^A-Za-z0-9_]/', '', $db->t_message_history() );
	$table_sql = '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	$row       = $db->get_row(
		'SELECT
			COUNT(*) AS total_messages,
			SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS inbound_messages,
			SUM(CASE WHEN status <> %s OR status IS NULL THEN 1 ELSE 0 END) AS outbound_messages,
			MAX(created_at) AS latest_message_at
		FROM ' . $table_sql . '
		WHERE contact_id = %d
			AND user_mailid = %s
			AND business_account_id = %s
			AND phone_number_id = %s
			AND deleted_at IS NULL',
		array(
			'received',
			'received',
			$contact_id,
			(string) $tenant['user_mailid'],
			(string) $tenant['business_account_id'],
			(string) $tenant['phone_number_id'],
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		$row = array();
	}

	$latest_inbound = function_exists( 'nxtcc_get_latest_inbound_at' )
		? nxtcc_get_latest_inbound_at(
			$contact_id,
			(string) $tenant['user_mailid'],
			(string) $tenant['business_account_id'],
			(string) $tenant['phone_number_id']
		)
		: null;

	return array(
		'total_messages'    => absint( $row['total_messages'] ?? 0 ),
		'inbound_messages'  => absint( $row['inbound_messages'] ?? 0 ),
		'outbound_messages' => absint( $row['outbound_messages'] ?? 0 ),
		'latest_message_at' => nxtcc_contact_profile_format_date( (string) ( $row['latest_message_at'] ?? '' ) ),
		'latest_inbound_at' => nxtcc_contact_profile_format_date( (string) $latest_inbound ),
	);
}

/**
 * Prepare a bounded activity page for profile output.
 *
 * @param int                 $contact_id Contact ID.
 * @param array<string,mixed> $tenant Tenant tuple.
 * @param int                 $before_id Cursor activity ID.
 * @return array<string,mixed>
 */
function nxtcc_contact_profile_activities( int $contact_id, array $tenant, int $before_id ): array {
	if (
		! function_exists( 'nxtcc_get_contact_crm_activities' )
		|| ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_crm_activity' ) )
	) {
		return array(
			'can_view'  => false,
			'rows'      => array(),
			'has_more'  => false,
			'before_id' => 0,
		);
	}

	$limit = 20;
	$rows  = nxtcc_get_contact_crm_activities(
		$contact_id,
		$tenant,
		array(
			'limit'     => $limit + 1,
			'before_id' => $before_id,
		)
	);

	$has_more = count( $rows ) > $limit;
	$rows     = array_slice( $rows, 0, $limit );
	$types    = function_exists( 'nxtcc_get_crm_activity_types' ) ? nxtcc_get_crm_activity_types() : array();
	$output   = array();
	$next_id  = 0;

	foreach ( $rows as $row ) {
		$type     = sanitize_key( (string) ( $row['activity_type'] ?? '' ) );
		$row_id   = absint( $row['id'] ?? 0 );
		$next_id  = $row_id > 0 && ( 0 === $next_id || $row_id < $next_id ) ? $row_id : $next_id;
		$output[] = array(
			'id'                 => $row_id,
			'activity_type'      => $type,
			'activity_label'     => sanitize_text_field( (string) ( $types[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ) ) ),
			'source'             => sanitize_key( (string) ( $row['source'] ?? '' ) ),
			'actor_label'        => sanitize_text_field( (string) ( $row['actor_label'] ?? '' ) ),
			'metadata'           => isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array(),
			'note_content'       => sanitize_textarea_field( (string) ( $row['note_content'] ?? '' ) ),
			'created_at_display' => nxtcc_contact_profile_format_date( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	return array(
		'can_view'  => true,
		'rows'      => $output,
		'has_more'  => $has_more,
		'before_id' => $next_id,
	);
}

/**
 * AJAX: Read a tenant-scoped CRM contact profile.
 *
 * @return void
 */
function nxtcc_ajax_contact_profile_get(): void {
	check_ajax_referer( 'nxtcc_contact_profile', 'nonce' );

	if (
		! NXTCC_Access_Control::current_user_can_any(
			array(
				'nxtcc_view_contacts',
				'nxtcc_manage_contacts',
				'nxtcc_access_chat',
			)
		)
	) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to view contact profiles.', 'nxt-cloud-chat' ) ), 403 );
	}

	$contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_SANITIZE_NUMBER_INT ) );
	$before_id  = absint( filter_input( INPUT_POST, 'before_id', FILTER_SANITIZE_NUMBER_INT ) );
	$tenant     = NXTCC_Access_Control::get_current_tenant_context();
	$tenant     = array(
		'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
		'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
		'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
	);

	if (
		$contact_id <= 0
		|| '' === $tenant['user_mailid']
		|| '' === $tenant['business_account_id']
		|| '' === $tenant['phone_number_id']
	) {
		wp_send_json_error( array( 'message' => __( 'The contact profile could not be loaded.', 'nxt-cloud-chat' ) ), 400 );
	}

	$contact = nxtcc_get_contact_by_id(
		$contact_id,
		$tenant['user_mailid'],
		$tenant['business_account_id'],
		$tenant['phone_number_id']
	);

	if ( ! is_array( $contact ) ) {
		wp_send_json_error( array( 'message' => __( 'Contact not found.', 'nxt-cloud-chat' ) ), 404 );
	}
	if ( ! NXTCC_CRM_Access_Policy::user_can_view_contact( $contact_id, $tenant ) ) {
		wp_send_json_error( array( 'message' => __( 'This contact is outside your assigned record scope.', 'nxt-cloud-chat' ) ), 403 );
	}

	$messages = nxtcc_contact_profile_message_context( $contact_id, $tenant );

	$can_view_contacts = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );
	if ( ! $can_view_contacts && empty( $messages['total_messages'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Contact not found.', 'nxt-cloud-chat' ) ), 404 );
	}

	$groups     = nxtcc_get_contact_groups_by_id( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
	$tags       = nxtcc_get_contact_tags_by_id( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
	$assignment = nxtcc_get_contact_assignment( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
	$activities = nxtcc_contact_profile_activities( $contact_id, $tenant, $before_id );
	$stages     = function_exists( 'nxtcc_list_lifecycle_stages' ) ? nxtcc_list_lifecycle_stages( $tenant ) : array();
	$lifecycle  = function_exists( 'nxtcc_get_contact_lifecycle_stage' ) ? nxtcc_get_contact_lifecycle_stage( $contact_id, $tenant ) : null;
	$tasks      = function_exists( 'nxtcc_list_contact_crm_tasks' ) ? nxtcc_list_contact_crm_tasks( $contact_id, $tenant, array( 'limit' => 30 ) ) : array();
	$deals      = function_exists( 'nxtcc_list_contact_crm_deals' ) && NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) )
		? nxtcc_list_contact_crm_deals( $contact_id, $tenant, array( 'limit' => 20 ) )
		: array();
	foreach ( $deals as &$deal ) {
		$deal['expected_close_display'] = nxtcc_contact_profile_format_date( (string) ( $deal['expected_close_at'] ?? '' ) );
	}
	unset( $deal );
	$duplicates = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_merge_contacts' ) ) && function_exists( 'nxtcc_find_contact_duplicate_candidates' )
		? nxtcc_find_contact_duplicate_candidates( $contact_id, $tenant, 10 )
		: array();

	wp_send_json_success(
		array(
			'contact'         => array(
				'id'                 => $contact_id,
				'name'               => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'country_code'       => preg_replace( '/\D/', '', (string) ( $contact['country_code'] ?? '' ) ),
				'phone_number'       => preg_replace( '/\D/', '', (string) ( $contact['phone_number'] ?? '' ) ),
				'is_subscribed'      => ! empty( $contact['is_subscribed'] ),
				'is_verified'        => ! empty( $contact['is_verified'] ),
				'is_wp_user_linked'  => ! empty( $contact['wp_uid'] ),
				'custom_fields'      => nxtcc_contact_profile_custom_fields( $contact['custom_fields'] ?? '' ),
				'created_at_display' => nxtcc_contact_profile_format_date( (string) ( $contact['created_at'] ?? '' ) ),
				'updated_at_display' => nxtcc_contact_profile_format_date( (string) ( $contact['updated_at'] ?? '' ) ),
			),
			'groups'          => nxtcc_contact_profile_groups( $groups ),
			'tags'            => nxtcc_contact_profile_tags( $tags ),
			'assignment'      => array(
				'label'       => is_array( $assignment ) ? sanitize_text_field( (string) ( $assignment['label'] ?? '' ) ) : '',
				'target_type' => is_array( $assignment ) ? sanitize_key( (string) ( $assignment['target_type'] ?? '' ) ) : '',
			),
			'lifecycle'       => array(
				'current' => is_array( $lifecycle ) ? $lifecycle : null,
				'stages'  => is_array( $stages ) ? $stages : array(),
			),
			'tasks'           => is_array( $tasks ) ? $tasks : array(),
			'deals'           => is_array( $deals ) ? $deals : array(),
			'duplicates'      => is_array( $duplicates ) ? $duplicates : array(),
			'permissions'     => array(
				'manage_lifecycle' => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_lifecycle_stages' ) ),
				'manage_tasks'     => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_crm_tasks' ) ),
				'merge_contacts'   => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_merge_contacts' ) ),
			),
			'message_context' => $messages,
			'activities'      => $activities,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contact_profile_get', 'nxtcc_ajax_contact_profile_get' );

/**
 * Resolve and authorize a contact-profile mutation request.
 *
 * @param string $capability Required capability.
 * @return array{contact_id:int,tenant:array<string,string>}
 */
function nxtcc_contact_profile_mutation_context( string $capability ): array {
	check_ajax_referer( 'nxtcc_contact_profile', 'nonce' );

	if ( ! current_user_can( $capability ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this CRM action.', 'nxt-cloud-chat' ) ), 403 );
	}

	$contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_SANITIZE_NUMBER_INT ) );
	$tenant     = NXTCC_Access_Control::get_current_tenant_context();
	$tenant     = array(
		'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
		'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
		'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
	);

	if (
		$contact_id <= 0
		|| '' === $tenant['user_mailid']
		|| '' === $tenant['business_account_id']
		|| '' === $tenant['phone_number_id']
		|| ! NXTCC_CRM_Access_Policy::user_can_manage_contact( $contact_id, $tenant )
	) {
		wp_send_json_error( array( 'message' => __( 'This contact is outside your manageable record scope.', 'nxt-cloud-chat' ) ), 403 );
	}

	return array(
		'contact_id' => $contact_id,
		'tenant'     => $tenant,
	);
}

/**
 * AJAX: Set a contact lifecycle stage.
 *
 * @return void
 */
function nxtcc_ajax_contact_profile_set_lifecycle(): void {
	$context  = nxtcc_contact_profile_mutation_context( 'nxtcc_manage_lifecycle_stages' );
	$stage_id = absint( filter_input( INPUT_POST, 'stage_id', FILTER_SANITIZE_NUMBER_INT ) );
	$result   = nxtcc_set_contact_lifecycle_stage(
		array_merge(
			$context['tenant'],
			array(
				'contact_id' => $context['contact_id'],
				'stage_id'   => $stage_id,
				'source'     => 'manual',
				'actor_id'   => get_current_user_id(),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The lifecycle stage could not be updated.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contact_profile_set_lifecycle', 'nxtcc_ajax_contact_profile_set_lifecycle' );

/**
 * AJAX: Create a contact follow-up task.
 *
 * @return void
 */
function nxtcc_ajax_contact_profile_create_task(): void {
	$context = nxtcc_contact_profile_mutation_context( 'nxtcc_manage_crm_tasks' );
	$title   = sanitize_text_field( (string) filter_input( INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
	$due_at  = sanitize_text_field( (string) filter_input( INPUT_POST, 'due_at', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
	$due_at  = '' !== $due_at ? get_gmt_from_date( str_replace( 'T', ' ', $due_at ) ) : '';
	$result  = nxtcc_create_crm_task(
		array_merge(
			$context['tenant'],
			array(
				'contact_id' => $context['contact_id'],
				'title'      => $title,
				'due_at'     => $due_at,
				'priority'   => sanitize_key( (string) filter_input( INPUT_POST, 'priority', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ),
				'source'     => 'manual',
				'actor_id'   => get_current_user_id(),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The follow-up task could not be created.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contact_profile_create_task', 'nxtcc_ajax_contact_profile_create_task' );

/**
 * AJAX: Update a contact task status.
 *
 * @return void
 */
function nxtcc_ajax_contact_profile_update_task(): void {
	$context = nxtcc_contact_profile_mutation_context( 'nxtcc_manage_crm_tasks' );
	$task_id = absint( filter_input( INPUT_POST, 'task_id', FILTER_SANITIZE_NUMBER_INT ) );
	$status  = sanitize_key( (string) filter_input( INPUT_POST, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
	$task    = nxtcc_get_crm_task( $task_id, $context['tenant'] );

	if ( ! is_array( $task ) || absint( $task['contact_id'] ?? 0 ) !== $context['contact_id'] ) {
		wp_send_json_error( array( 'message' => __( 'Task not found.', 'nxt-cloud-chat' ) ), 404 );
	}

	$result = nxtcc_update_crm_task(
		array_merge(
			$context['tenant'],
			array(
				'task_id'  => $task_id,
				'status'   => $status,
				'source'   => 'manual',
				'actor_id' => get_current_user_id(),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The task could not be updated.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contact_profile_update_task', 'nxtcc_ajax_contact_profile_update_task' );

/**
 * AJAX: Merge a confirmed duplicate into the open contact profile.
 *
 * @return void
 */
function nxtcc_ajax_contact_profile_merge_duplicate(): void {
	$context   = nxtcc_contact_profile_mutation_context( 'nxtcc_merge_contacts' );
	$source_id = absint( filter_input( INPUT_POST, 'source_contact_id', FILTER_SANITIZE_NUMBER_INT ) );

	if ( $source_id <= 0 || ! NXTCC_CRM_Access_Policy::user_can_manage_contact( $source_id, $context['tenant'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The duplicate contact is outside your manageable record scope.', 'nxt-cloud-chat' ) ), 403 );
	}

	$candidate_ids = array_map(
		'absint',
		wp_list_pluck( nxtcc_find_contact_duplicate_candidates( $context['contact_id'], $context['tenant'], 50 ), 'id' )
	);
	if ( ! in_array( $source_id, $candidate_ids, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Only strongly matched duplicate suggestions can be merged from this screen.', 'nxt-cloud-chat' ) ), 400 );
	}

	$result = nxtcc_merge_contacts(
		array_merge(
			$context['tenant'],
			array(
				'target_contact_id' => $context['contact_id'],
				'source_contact_id' => $source_id,
				'source'            => 'manual',
				'actor_id'          => get_current_user_id(),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => sanitize_text_field( (string) ( $result['message'] ?? __( 'The duplicate contacts could not be merged.', 'nxt-cloud-chat' ) ) ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? '' ) ),
			),
			400
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contact_profile_merge_duplicate', 'nxtcc_ajax_contact_profile_merge_duplicate' );
