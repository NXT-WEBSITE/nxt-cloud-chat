<?php
/**
 * Runtime compatibility contract and additive bridge helpers.
 *
 * These helpers expose a stable Free-owned surface for internal integrations
 * such as the Pro workflow engine without changing current plugin behavior.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-db.php';
require_once __DIR__ . '/class-nxtcc-runtime-integration.php';

if ( ! function_exists( 'nxtcc_runtime_quote_table_name' ) ) {
	/**
	 * Quote a table identifier for controlled SQL fragments.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	function nxtcc_runtime_quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}
}

if ( ! function_exists( 'nxtcc_runtime_cache_key' ) ) {
	/**
	 * Build a deterministic cache key for runtime bridge lookups.
	 *
	 * @param string $prefix Key prefix.
	 * @param array  $parts  Key parts.
	 * @return string
	 */
	function nxtcc_runtime_cache_key( string $prefix, array $parts ): string {
		return sanitize_key( $prefix ) . ':' . md5( wp_json_encode( array_values( $parts ) ) );
	}
}

if ( ! function_exists( 'nxtcc_get_runtime_contract' ) ) {
	/**
	 * Return the stable Free runtime contract for internal integrations.
	 *
	 * @return array<string, mixed>
	 */
	function nxtcc_get_runtime_contract(): array {
		$contract = array(
			'contract_version' => defined( 'NXTCC_VERSION' ) ? (string) NXTCC_VERSION : '0.0.0',
			'plugin'           => array(
				'slug'         => 'nxt-cloud-chat',
				'version'      => defined( 'NXTCC_VERSION' ) ? (string) NXTCC_VERSION : '0.0.0',
				'distribution' => defined( 'NXTCC_DISTRIBUTION' ) ? (string) NXTCC_DISTRIBUTION : 'FREE',
			),
			'capabilities'     => array(
				'compat_contract'                     => true,
				'inbound_message_persisted_hook'      => true,
				'message_history_status_updated_hook' => true,
				'auth_lifecycle_hooks'                => true,
				'auth_user_verification_checker'      => function_exists( 'nxtcc_is_user_auth_verified' ),
				'auth_verification_url_builder'       => function_exists( 'nxtcc_get_auth_verification_url' ),
				'tenant_credentials_wrapper'          => function_exists( 'nxtcc_get_tenant_api_credentials' ),
				'tenant_profile_list_reader'          => function_exists( 'nxtcc_list_tenant_profiles' ),
				'tenant_profile_reader'               => function_exists( 'nxtcc_get_tenant_profile' ),
				'primary_display_phone_number_reader' => function_exists( 'nxtcc_get_primary_display_phone_number' ),
				'session_reply_sender'                => function_exists( 'nxtcc_send_session_reply' ),
				'background_session_reply_sender'     => function_exists( 'nxtcc_send_background_session_reply' ),
				'message_history_reader'              => function_exists( 'nxtcc_get_message_history_after_id' ),
				'message_history_wamid_reader'        => function_exists( 'nxtcc_get_message_history_id_by_wamid' ),
				'contact_reader'                      => function_exists( 'nxtcc_get_contact_by_id' ),
				'contact_phone_reader'                => function_exists( 'nxtcc_get_contact_by_phone' ),
				'contact_wp_user_reader'              => function_exists( 'nxtcc_get_contact_by_wp_user' ),
				'contact_group_reader'                => function_exists( 'nxtcc_get_contact_groups_by_id' ),
				'contact_tag_reader'                  => function_exists( 'nxtcc_get_contact_tags_by_id' ),
				'contact_tag_writer'                  => function_exists( 'nxtcc_update_contact_tags' ),
				'contact_tag_definition_writer'       => function_exists( 'nxtcc_upsert_contact_tag' ),
				'contact_assignment_reader'           => function_exists( 'nxtcc_get_contact_assignment' ),
				'contact_assignment_writer'           => function_exists( 'nxtcc_update_contact_assignment' ),
				'contact_auto_assignment_writer'      => function_exists( 'nxtcc_auto_assign_contact' ),
				'contact_assignment_targets_reader'   => function_exists( 'nxtcc_list_contact_assignment_targets' ),
				'crm_access_policy_reader'            => function_exists( 'nxtcc_get_crm_access_policy' ),
				'crm_contact_access_checker'          => function_exists( 'nxtcc_user_can_view_contact' ),
				'conversation_reader'                 => function_exists( 'nxtcc_get_conversation' ),
				'conversation_create_writer'          => function_exists( 'nxtcc_get_or_create_conversation' ),
				'conversation_writer'                 => function_exists( 'nxtcc_update_conversation' ),
				'conversation_assignment_writer'      => function_exists( 'nxtcc_assign_conversation' ),
				'conversation_auto_assignment_writer' => function_exists( 'nxtcc_auto_assign_conversation' ),
				'conversation_note_writer'            => function_exists( 'nxtcc_add_conversation_note' ),
				'conversation_watcher_writer'         => function_exists( 'nxtcc_set_conversation_watcher' ),
				'conversation_activity_reader'        => function_exists( 'nxtcc_get_conversation_activities' ),
				'conversation_sla_reader'             => function_exists( 'nxtcc_get_conversation_sla_targets' ),
				'conversation_sla_candidate_reader'   => function_exists( 'nxtcc_list_conversation_sla_candidates' ),
				'conversation_access_checker'         => function_exists( 'nxtcc_user_can_view_conversation' ),
				'ticket_reader'                       => function_exists( 'nxtcc_get_ticket' ),
				'ticket_create_writer'                => function_exists( 'nxtcc_create_or_get_ticket' ),
				'ticket_list_reader'                  => function_exists( 'nxtcc_list_tickets_for_contact' ),
				'ticket_explicit_create_writer'       => function_exists( 'nxtcc_create_ticket' ),
				'ticket_current_writer'               => function_exists( 'nxtcc_set_current_ticket' ),
				'ticket_category_reader'              => function_exists( 'nxtcc_list_ticket_categories' ),
				'ticket_category_writer'              => function_exists( 'nxtcc_save_ticket_category' ),
				'ticket_writer'                       => function_exists( 'nxtcc_update_ticket' ),
				'ticket_assignment_writer'            => function_exists( 'nxtcc_assign_ticket' ),
				'ticket_auto_assignment_writer'       => function_exists( 'nxtcc_auto_assign_ticket' ),
				'ticket_note_writer'                  => function_exists( 'nxtcc_add_ticket_note' ),
				'ticket_activity_writer'              => function_exists( 'nxtcc_record_ticket_outbound' ),
				'ticket_explicit_activity_writer'     => function_exists( 'nxtcc_record_ticket_outbound_by_id' ),
				'ticket_access_checker'               => function_exists( 'nxtcc_user_can_view_ticket' ),
				'access_teams_reader'                 => function_exists( 'nxtcc_get_access_teams' ),
				'crm_activity_reader'                 => function_exists( 'nxtcc_get_contact_crm_activities' ),
				'crm_activity_writer'                 => function_exists( 'nxtcc_record_crm_activity' ),
				'chat_timeline_reader'                => function_exists( 'nxtcc_get_chat_timeline' ),
				'token_catalog_reader'                => function_exists( 'nxtcc_get_token_catalog' ),
				'token_context_builder'               => function_exists( 'nxtcc_build_token_context' ),
				'lifecycle_stage_reader'              => function_exists( 'nxtcc_list_lifecycle_stages' ),
				'lifecycle_stage_writer'              => function_exists( 'nxtcc_set_contact_lifecycle_stage' ),
				'crm_task_reader'                     => function_exists( 'nxtcc_list_contact_crm_tasks' ),
				'crm_task_writer'                     => function_exists( 'nxtcc_create_crm_task' ),
				'crm_saved_view_reader'               => function_exists( 'nxtcc_list_crm_saved_views' ),
				'crm_saved_view_writer'               => function_exists( 'nxtcc_upsert_crm_saved_view' ),
				'crm_pipeline_reader'                 => function_exists( 'nxtcc_list_crm_pipelines' ),
				'crm_pipeline_writer'                 => function_exists( 'nxtcc_upsert_crm_pipeline' ),
				'crm_pipeline_overview_reader'        => function_exists( 'nxtcc_get_crm_pipeline_overview' ),
				'crm_pipeline_lifecycle_writer'       => function_exists( 'nxtcc_delete_crm_pipeline' ),
				'crm_deal_reader'                     => function_exists( 'nxtcc_get_crm_deal' ),
				'crm_deal_writer'                     => function_exists( 'nxtcc_create_crm_deal' ) && function_exists( 'nxtcc_update_crm_deal' ),
				'crm_deal_lifecycle_writer'           => function_exists( 'nxtcc_delete_crm_deal' ),
				'crm_deal_access_checker'             => function_exists( 'nxtcc_user_can_view_crm_deal' ),
				'crm_deal_item_provider_reader'       => function_exists( 'nxtcc_get_crm_deal_item_providers' ),
				'crm_deal_item_searcher'              => function_exists( 'nxtcc_search_crm_deal_items' ),
				'crm_deal_item_resolver'              => function_exists( 'nxtcc_resolve_crm_deal_item' ),
				'crm_analytics_reader'                => function_exists( 'nxtcc_get_crm_analytics' ),
				'contact_merge_writer'                => function_exists( 'nxtcc_merge_contacts' ),
				'contact_duplicate_reader'            => function_exists( 'nxtcc_find_contact_duplicate_candidates' ),
				'contact_subscription_writer'         => function_exists( 'nxtcc_update_contact_subscription_status' ),
				'contact_integration_writer'          => function_exists( 'nxtcc_upsert_contact_for_integration' ),
				'contact_query_reader'                => function_exists( 'nxtcc_query_contact_ids' ),
				'contact_query_provider_reader'       => function_exists( 'nxtcc_get_contact_query_providers' ),
				'meta_health_status_reader'           => function_exists( 'nxtcc_get_meta_health_status' ),
				'latest_inbound_reader'               => function_exists( 'nxtcc_get_latest_inbound_at' ),
				'verified_phone_reader'               => function_exists( 'nxtcc_get_latest_verified_phone_for_user' ),
				'meta_flow_response_detector'         => function_exists( 'nxtcc_is_meta_flow_response' ),
				'meta_interactive_message_parser'     => function_exists( 'nxtcc_parse_meta_interactive_message' ),
				'history_interactive_message_reader'  => function_exists( 'nxtcc_get_interactive_message_from_history' ),
				'history_flow_response_reader'        => function_exists( 'nxtcc_get_flow_response_from_history' ),
				'template_preview_builder'            => function_exists( 'nxtcc_build_template_preview_snapshot' ),
				'template_history_normalizer'         => function_exists( 'nxtcc_normalize_template_history_row' ),
				'history_template_preview_reader'     => function_exists( 'nxtcc_get_template_preview_from_history' ),
			),
			'hooks'            => array(
				'nxtcc_inbound_message_persisted',
				'nxtcc_message_history_status_updated',
				'nxtcc_auth_otp_requested',
				'nxtcc_auth_otp_sent',
				'nxtcc_auth_otp_failed',
				'nxtcc_auth_login_succeeded',
				'nxtcc_auth_login_failed',
				'nxtcc_otp_verified',
				'nxtcc_wp_login',
				'nxtcc_contact_tag_created',
				'nxtcc_contact_tag_updated',
				'nxtcc_contact_tag_deleted',
				'nxtcc_contact_tags_updated',
				'nxtcc_contact_assignment_updated',
				'nxtcc_crm_activity_recorded',
				'nxtcc_lifecycle_stage_saved',
				'nxtcc_contact_lifecycle_stage_changed',
				'nxtcc_crm_task_created',
				'nxtcc_crm_task_updated',
				'nxtcc_crm_saved_view_created',
				'nxtcc_crm_saved_view_updated',
				'nxtcc_crm_saved_view_deleted',
				'nxtcc_crm_pipeline_saved',
				'nxtcc_crm_pipeline_stage_saved',
				'nxtcc_crm_deal_created',
				'nxtcc_crm_deal_updated',
				'nxtcc_crm_deal_deleted',
				'nxtcc_contacts_merged',
				'nxtcc_conversation_created',
				'nxtcc_conversation_status_changed',
				'nxtcc_conversation_priority_changed',
				'nxtcc_conversation_details_changed',
				'nxtcc_conversation_assignment_updated',
				'nxtcc_conversation_internal_note_added',
				'nxtcc_conversation_watcher_updated',
				'nxtcc_conversation_reopened',
				'nxtcc_conversation_first_response_recorded',
			),
			'wrappers'         => array(
				'nxtcc_get_tenant_api_credentials',
				'nxtcc_list_tenant_profiles',
				'nxtcc_get_tenant_profile',
				'nxtcc_get_primary_display_phone_number',
				'nxtcc_send_session_reply',
				'nxtcc_send_background_session_reply',
				'nxtcc_get_message_history_after_id',
				'nxtcc_get_message_history_id_by_wamid',
				'nxtcc_is_meta_flow_response',
				'nxtcc_parse_meta_interactive_message',
				'nxtcc_get_interactive_message_from_history',
				'nxtcc_get_flow_response_from_history',
				'nxtcc_build_template_preview_snapshot',
				'nxtcc_normalize_template_history_row',
				'nxtcc_get_template_preview_from_history',
				'nxtcc_get_contact_by_id',
				'nxtcc_get_contact_by_phone',
				'nxtcc_get_contact_by_wp_user',
				'nxtcc_get_contact_groups_by_id',
				'nxtcc_list_contact_tags',
				'nxtcc_get_contact_tags_by_id',
				'nxtcc_upsert_contact_tag',
				'nxtcc_update_contact_tags',
				'nxtcc_list_contact_assignment_targets',
				'nxtcc_get_contact_assignment',
				'nxtcc_update_contact_assignment',
				'nxtcc_auto_assign_contact',
				'nxtcc_get_crm_access_policy',
				'nxtcc_user_can_view_contact',
				'nxtcc_user_can_manage_contact',
				'nxtcc_filter_contact_ids_by_access',
				'nxtcc_get_conversation',
				'nxtcc_get_or_create_conversation',
				'nxtcc_update_conversation',
				'nxtcc_assign_conversation',
				'nxtcc_auto_assign_conversation',
				'nxtcc_add_conversation_note',
				'nxtcc_set_conversation_watcher',
				'nxtcc_get_conversation_activities',
				'nxtcc_get_conversation_sla_targets',
				'nxtcc_list_conversation_sla_candidates',
				'nxtcc_user_can_view_conversation',
				'nxtcc_user_can_manage_conversation',
				'nxtcc_get_ticket',
				'nxtcc_create_or_get_ticket',
				'nxtcc_list_tickets_for_contact',
				'nxtcc_create_ticket',
				'nxtcc_set_current_ticket',
				'nxtcc_list_ticket_categories',
				'nxtcc_save_ticket_category',
				'nxtcc_delete_ticket_category',
				'nxtcc_update_ticket',
				'nxtcc_assign_ticket',
				'nxtcc_auto_assign_ticket',
				'nxtcc_add_ticket_note',
				'nxtcc_record_ticket_outbound',
				'nxtcc_record_ticket_outbound_by_id',
				'nxtcc_set_ticket_watcher',
				'nxtcc_get_ticket_activities',
				'nxtcc_user_can_view_ticket',
				'nxtcc_user_can_manage_ticket',
				'nxtcc_get_access_teams',
				'nxtcc_get_crm_activity_types',
				'nxtcc_get_crm_activity',
				'nxtcc_get_contact_crm_activities',
				'nxtcc_get_chat_timeline',
				'nxtcc_get_chat_timeline_context',
				'nxtcc_record_crm_activity',
				'nxtcc_get_token_catalog',
				'nxtcc_build_token_context',
				'nxtcc_list_lifecycle_stages',
				'nxtcc_upsert_lifecycle_stage',
				'nxtcc_get_contact_lifecycle_stage',
				'nxtcc_set_contact_lifecycle_stage',
				'nxtcc_list_contact_crm_tasks',
				'nxtcc_get_crm_task',
				'nxtcc_create_crm_task',
				'nxtcc_update_crm_task',
				'nxtcc_list_crm_saved_views',
				'nxtcc_get_crm_saved_view',
				'nxtcc_upsert_crm_saved_view',
				'nxtcc_delete_crm_saved_view',
				'nxtcc_list_crm_pipelines',
				'nxtcc_upsert_crm_pipeline',
				'nxtcc_list_crm_pipeline_stages',
				'nxtcc_upsert_crm_pipeline_stage',
				'nxtcc_get_crm_pipeline_overview',
				'nxtcc_duplicate_crm_pipeline',
				'nxtcc_delete_crm_pipeline',
				'nxtcc_delete_crm_pipeline_stage',
				'nxtcc_reorder_crm_pipeline_stages',
				'nxtcc_get_crm_deal_item_providers',
				'nxtcc_search_crm_deal_items',
				'nxtcc_resolve_crm_deal_item',
				'nxtcc_get_crm_analytics',
				'nxtcc_list_crm_deals',
				'nxtcc_count_crm_deals',
				'nxtcc_get_crm_deal',
				'nxtcc_list_contact_crm_deals',
				'nxtcc_create_crm_deal',
				'nxtcc_update_crm_deal',
				'nxtcc_delete_crm_deal',
				'nxtcc_user_can_view_crm_deal',
				'nxtcc_user_can_manage_crm_deal',
				'nxtcc_find_contact_duplicate_candidates',
				'nxtcc_merge_contacts',
				'nxtcc_update_contact_subscription_status',
				'nxtcc_upsert_contact_for_integration',
				'nxtcc_normalize_contact_query_filters',
				'nxtcc_get_contact_query_providers',
				'nxtcc_query_contact_ids',
				'nxtcc_count_contacts_by_query',
				'nxtcc_contact_matches_query',
				'nxtcc_list_contact_groups',
				'nxtcc_get_meta_health_status',
				'nxtcc_get_latest_inbound_at',
				'nxtcc_get_latest_verified_phone_for_user',
				'nxtcc_is_user_auth_verified',
				'nxtcc_get_auth_verification_url',
			),
		);

		$filtered = apply_filters( 'nxtcc_runtime_contract', $contract );
		return is_array( $filtered ) ? $filtered : $contract;
	}
}

if ( ! function_exists( 'nxtcc_get_runtime_capabilities' ) ) {
	/**
	 * Return the capability map exposed by the runtime contract.
	 *
	 * @return array<string, bool>
	 */
	function nxtcc_get_runtime_capabilities(): array {
		$contract = nxtcc_get_runtime_contract();
		$caps     = isset( $contract['capabilities'] ) && is_array( $contract['capabilities'] )
			? $contract['capabilities']
			: array();

		return $caps;
	}
}

if ( ! function_exists( 'nxtcc_has_runtime_capability' ) ) {
	/**
	 * Check whether the Free runtime exposes a named capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function nxtcc_has_runtime_capability( string $capability ): bool {
		$capability = sanitize_key( $capability );
		if ( '' === $capability ) {
			return false;
		}

		$capabilities = nxtcc_get_runtime_capabilities();
		return ! empty( $capabilities[ $capability ] );
	}
}

if ( ! function_exists( 'nxtcc_get_message_history_after_id' ) ) {
	/**
	 * Read received message-history rows after a cursor id.
	 *
	 * This is intended as a stable event-reader surface for internal runtimes.
	 *
	 * Supported filters:
	 * - user_mailid
	 * - business_account_id
	 * - phone_number_id
	 * - status
	 *
	 * @param int   $after_id Cursor id.
	 * @param int   $limit    Batch size.
	 * @param array $filters  Optional tenant/status filters.
	 * @return array<int, array<string, mixed>>
	 */
	function nxtcc_get_message_history_after_id( int $after_id = 0, int $limit = 100, array $filters = array() ): array {
		$after_id            = max( 0, $after_id );
		$limit               = max( 1, min( 500, $limit ) );
		$user_mailid         = isset( $filters['user_mailid'] ) ? sanitize_email( (string) $filters['user_mailid'] ) : '';
		$business_account_id = isset( $filters['business_account_id'] ) ? sanitize_text_field( (string) $filters['business_account_id'] ) : '';
		$phone_number_id     = isset( $filters['phone_number_id'] ) ? sanitize_text_field( (string) $filters['phone_number_id'] ) : '';
		$status              = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : 'received';

		$db          = NXTCC_DB::i();
		$history_sql = nxtcc_runtime_quote_table_name( $db->t_message_history() );
		$sql         = 'SELECT id, queue_id, user_mailid, business_account_id, phone_number_id, contact_id, display_phone_number, template_name, template_type, template_data, message_content, status, status_timestamps, meta_message_id, created_at, response_json
			FROM ' . $history_sql . '
			WHERE id > %d
			  AND status = %s
			  AND queue_id IS NULL
			  AND deleted_at IS NULL';
		$args        = array( $after_id, $status );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql   .= ' ORDER BY id ASC LIMIT %d';
		$args[] = $limit;

		$rows = $db->get_results( $sql, $args, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_id' ) ) {
	/**
	 * Read a contact row by id, optionally scoped to a tenant.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_id(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return null;
		}

		$cache_key = nxtcc_runtime_cache_key(
			'contact',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = nxtcc_runtime_quote_table_name( $db->t_contacts() );
		$sql          = 'SELECT * FROM ' . $contacts_sql . ' WHERE id = %d';
		$args         = array( $contact_id );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' LIMIT 1';

		$row = $db->get_row( $sql, $args, ARRAY_A );
		$row = is_array( $row ) ? $row : null;

		wp_cache_set( $cache_key, $row, 'nxtcc_runtime', 60 );

		return $row;
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_phone' ) ) {
	/**
	 * Read a contact row by phone number, optionally scoped to a tenant.
	 *
	 * @param string $phone_number         Phone number in any common format.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_phone(
		string $phone_number,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		return NXTCC_Runtime_Integration::get_contact_by_phone(
			$phone_number,
			$user_mailid,
			$business_account_id,
			$phone_number_id
		);
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_wp_user' ) ) {
	/**
	 * Read a contact row by linked WordPress user id.
	 *
	 * Falls back to the user's latest verified WhatsApp binding when the
	 * contact row is not yet linked by `wp_uid`.
	 *
	 * @param int    $user_id              WordPress user id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_wp_user(
		int $user_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		return NXTCC_Runtime_Integration::get_contact_by_wp_user(
			$user_id,
			$user_mailid,
			$business_account_id,
			$phone_number_id
		);
	}
}

if ( ! function_exists( 'nxtcc_get_contact_groups_by_id' ) ) {
	/**
	 * Read the groups currently mapped to a contact.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<int, array<string, mixed>>
	 */
	function nxtcc_get_contact_groups_by_id(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): array {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return array();
		}

		$cache_key = nxtcc_runtime_cache_key(
			'contact_groups',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$db        = NXTCC_DB::i();
		$groups    = nxtcc_runtime_quote_table_name( $db->t_groups() );
		$group_map = nxtcc_runtime_quote_table_name( $db->t_group_contact_map() );
		$sql       = 'SELECT g.id, g.group_name, g.is_verified
			FROM ' . $group_map . ' AS m
			INNER JOIN ' . $groups . ' AS g ON g.id = m.group_id
			WHERE m.contact_id = %d';
		$args      = array( $contact_id );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND m.user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND m.business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND m.phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' ORDER BY g.group_name ASC';

		$rows = $db->get_results( $sql, $args, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		wp_cache_set( $cache_key, $rows, 'nxtcc_runtime', 60 );

		return $rows;
	}
}

if ( ! function_exists( 'nxtcc_list_contact_tags' ) ) {
	/**
	 * List tag definitions available to a tenant.
	 *
	 * Supported args: search, limit, offset, with_count.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $args List arguments.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_contact_tags( array $tenant, array $args = array() ): array {
		return NXTCC_Tags::instance()->list_tags( $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_contact_tags_by_id' ) ) {
	/**
	 * Read tags assigned to a contact.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $user_mailid Optional owner mail.
	 * @param string $business_account_id Optional business account ID.
	 * @param string $phone_number_id Optional phone number ID.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_get_contact_tags_by_id(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): array {
		return NXTCC_Tags::instance()->get_contact_tags(
			$contact_id,
			array(
				'user_mailid'         => $user_mailid,
				'business_account_id' => $business_account_id,
				'phone_number_id'     => $phone_number_id,
			)
		);
	}
}

if ( ! function_exists( 'nxtcc_upsert_contact_tag' ) ) {
	/**
	 * Create a tenant tag or return the matching existing definition.
	 *
	 * @param array<string,mixed> $args Tag arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_upsert_contact_tag( array $args ): array {
		return NXTCC_Tags::instance()->upsert_tag( $args );
	}
}

if ( ! function_exists( 'nxtcc_update_contact_tags' ) ) {
	/**
	 * Add, remove, or replace contact tags through the stable runtime wrapper.
	 *
	 * @param array<string,mixed> $args Update arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_contact_tags( array $args ): array {
		return NXTCC_Tags::instance()->update_contact_tags( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_contact_assignment_targets' ) ) {
	/**
	 * List tenant team members and access-team queues eligible for assignment.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<string,mixed>
	 */
	function nxtcc_list_contact_assignment_targets( array $tenant ): array {
		return NXTCC_Contact_Assignments::instance()->list_targets( $tenant );
	}
}

if ( ! function_exists( 'nxtcc_get_contact_assignment' ) ) {
	/**
	 * Read the current assignment shared by a contact and its chat.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $user_mailid Owner email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id Phone number ID.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_contact_assignment(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		return NXTCC_Contact_Assignments::instance()->get_assignment(
			$contact_id,
			array(
				'user_mailid'         => $user_mailid,
				'business_account_id' => $business_account_id,
				'phone_number_id'     => $phone_number_id,
			)
		);
	}
}

if ( ! function_exists( 'nxtcc_update_contact_assignment' ) ) {
	/**
	 * Set or clear the current assignment shared by a contact and its chat.
	 *
	 * @param array<string,mixed> $args Assignment arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_contact_assignment( array $args ): array {
		return NXTCC_Contact_Assignments::instance()->update_assignment( $args );
	}
}

if ( ! function_exists( 'nxtcc_auto_assign_contact' ) ) {
	/**
	 * Assign a contact to the next eligible tenant team member.
	 *
	 * Supported args: contact_id, role_key/team_key/assigned_role,
	 * route_key, overwrite, source, actor_id, and the tenant tuple.
	 *
	 * @param array<string,mixed> $args Routing arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_auto_assign_contact( array $args ): array {
		return NXTCC_Contact_Assignments::instance()->auto_assign( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_access_policy' ) ) {
	/**
	 * Read one user's normalized CRM action level and data scope.
	 *
	 * @param int    $user_id WordPress user ID. Current user when omitted.
	 * @param array  $tenant Tenant tuple. Current tenant when omitted.
	 * @param string $capability Optional capability whose data scope should be returned.
	 * @return array<string,mixed>
	 */
	function nxtcc_get_crm_access_policy( int $user_id = 0, array $tenant = array(), string $capability = '' ): array {
		return NXTCC_CRM_Access_Policy::get_policy( $user_id, $tenant, $capability );
	}
}

if ( ! function_exists( 'nxtcc_user_can_view_contact' ) ) {
	/**
	 * Check whether a user may view one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_view_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_view_contact( $contact_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_user_can_manage_contact' ) ) {
	/**
	 * Check whether a user may mutate one tenant contact or chat.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_manage_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_manage_contact( $contact_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_filter_contact_ids_by_access' ) ) {
	/**
	 * Filter contact IDs to records visible or manageable by a user.
	 *
	 * @param array $contact_ids Contact IDs.
	 * @param array $tenant Tenant tuple.
	 * @param bool  $manage Require manage access.
	 * @param int   $user_id WordPress user ID.
	 * @return array<int,int>
	 */
	function nxtcc_filter_contact_ids_by_access( array $contact_ids, array $tenant = array(), bool $manage = false, int $user_id = 0 ): array {
		return NXTCC_CRM_Access_Policy::filter_contact_ids( $contact_ids, $tenant, $manage, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_get_access_teams' ) ) {
	/**
	 * Read editable access teams for a tenant.
	 *
	 * @param array $tenant Reserved tenant tuple for forward compatibility.
	 * @return array<string,array<string,mixed>>
	 */
	function nxtcc_get_access_teams( array $tenant = array() ): array {
		if ( class_exists( 'NXTCC_Access_Teams' ) ) {
			$tenant = NXTCC_Access_Control::normalize_tenant_context( $tenant );
			if ( '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'] ) {
				return NXTCC_Access_Teams::merge_with_defaults( $tenant, array() );
			}
		}

		return NXTCC_Access_Control::get_role_presets();
	}
}

if ( ! function_exists( 'nxtcc_get_conversation' ) ) {
	/**
	 * Read one tenant conversation ticket.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_conversation( int $conversation_id, array $tenant ): ?array {
		return NXTCC_Conversations::instance()->get( $conversation_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_get_or_create_conversation' ) ) {
	/**
	 * Get or create the WhatsApp conversation for one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Optional creation arguments.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_or_create_conversation( int $contact_id, array $tenant, array $args = array() ): ?array {
		return NXTCC_Conversations::instance()->get_or_create_for_contact( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_update_conversation' ) ) {
	/**
	 * Update ticket status, priority, subject, or category.
	 *
	 * @param array $args Update arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_conversation( array $args ): array {
		return NXTCC_Conversations::instance()->update( $args );
	}
}

if ( ! function_exists( 'nxtcc_assign_conversation' ) ) {
	/**
	 * Assign or hand off a conversation.
	 *
	 * @param array $args Assignment arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_assign_conversation( array $args ): array {
		return NXTCC_Conversations::instance()->assign( $args );
	}
}

if ( ! function_exists( 'nxtcc_auto_assign_conversation' ) ) {
	/**
	 * Automatically assign a conversation using Free-owned routing rules.
	 *
	 * Supported strategies: round_robin and least_busy. Supported pool args:
	 * role_key, team_key, assigned_role, access_team, or routing_pool.
	 *
	 * @param array $args Routing arguments.
	 * @return array
	 */
	function nxtcc_auto_assign_conversation( array $args ): array {
		return NXTCC_Conversations::instance()->auto_assign( $args );
	}
}

if ( ! function_exists( 'nxtcc_add_conversation_note' ) ) {
	/**
	 * Add an internal conversation note.
	 *
	 * @param array $args Note arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_add_conversation_note( array $args ): array {
		return NXTCC_Conversations::instance()->add_note( $args );
	}
}

if ( ! function_exists( 'nxtcc_set_conversation_watcher' ) ) {
	/**
	 * Add or remove a conversation watcher.
	 *
	 * @param array $args Watcher arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_set_conversation_watcher( array $args ): array {
		return NXTCC_Conversations::instance()->set_watcher( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_conversation_activities' ) ) {
	/**
	 * Read a bounded conversation ticket timeline.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_get_conversation_activities( int $conversation_id, array $tenant, int $limit = 100 ): array {
		return NXTCC_Conversations::instance()->list_activity( $conversation_id, $tenant, $limit );
	}
}

if ( ! function_exists( 'nxtcc_get_conversation_sla_targets' ) ) {
	/**
	 * Return filterable conversation SLA targets in minutes.
	 *
	 * @return array<string,array<string,int>>
	 */
	function nxtcc_get_conversation_sla_targets(): array {
		return NXTCC_Conversations::instance()->get_sla_targets();
	}
}

if ( ! function_exists( 'nxtcc_list_conversation_sla_candidates' ) ) {
	/**
	 * List a bounded tenant-scoped page of active SLA conversations.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param int   $after_id Cursor conversation ID.
	 * @param int   $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_conversation_sla_candidates( array $tenant, int $after_id = 0, int $limit = 100 ): array {
		return NXTCC_Conversations::instance()->list_sla_candidates( $tenant, $after_id, $limit );
	}
}

if ( ! function_exists( 'nxtcc_user_can_view_conversation' ) ) {
	/**
	 * Check whether a user may view a conversation.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_view_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_view_conversation( $conversation_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_user_can_manage_conversation' ) ) {
	/**
	 * Check whether a user may manage a conversation.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_manage_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_manage_conversation( $conversation_id, $tenant, $user_id );
	}
}

/*
 * Ticket aliases preserve the existing conversation implementation and machine
 * identifiers while providing developer-facing CRM terminology.
 */
if ( ! function_exists( 'nxtcc_get_ticket' ) ) {
	/**
	 * Read one tenant ticket.
	 *
	 * @param int   $ticket_id Ticket/conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_ticket( int $ticket_id, array $tenant ): ?array {
		return nxtcc_get_conversation( $ticket_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_create_or_get_ticket' ) ) {
	/**
	 * Get or create the ticket for a tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Creation arguments.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_create_or_get_ticket( int $contact_id, array $tenant, array $args = array() ): ?array {
		return nxtcc_get_or_create_conversation( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_list_tickets_for_contact' ) ) {
	/**
	 * List tickets for one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_tickets_for_contact( int $contact_id, array $tenant, int $limit = 50 ): array {
		return NXTCC_Conversations::instance()->list_for_contact( $contact_id, $tenant, $limit );
	}
}

if ( ! function_exists( 'nxtcc_create_ticket' ) ) {
	/**
	 * Create a new ticket even when the contact already has tickets.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Creation arguments.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_create_ticket( int $contact_id, array $tenant, array $args = array() ): ?array {
		return NXTCC_Conversations::instance()->create_ticket( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_set_current_ticket' ) ) {
	/**
	 * Select the current ticket used for inbound and chat message routing.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param int   $ticket_id Ticket ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $actor_id Actor ID.
	 * @return bool
	 */
	function nxtcc_set_current_ticket( int $contact_id, int $ticket_id, array $tenant, int $actor_id = 0 ): bool {
		return NXTCC_Conversations::instance()->set_current_for_contact( $contact_id, $ticket_id, $tenant, $actor_id );
	}
}

if ( ! function_exists( 'nxtcc_list_ticket_categories' ) ) {
	/**
	 * List tenant ticket categories.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param bool  $include_archived Include archived categories.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_ticket_categories( array $tenant, bool $include_archived = false ): array {
		return NXTCC_Ticket_Categories::instance()->list_categories( $tenant, $include_archived );
	}
}

if ( ! function_exists( 'nxtcc_save_ticket_category' ) ) {
	/**
	 * Create or update a ticket category.
	 *
	 * @param array $args Category values and tenant tuple.
	 * @return array<string,mixed>
	 */
	function nxtcc_save_ticket_category( array $args ): array {
		return NXTCC_Ticket_Categories::instance()->save( $args );
	}
}

if ( ! function_exists( 'nxtcc_delete_ticket_category' ) ) {
	/**
	 * Delete an unused ticket category or archive a referenced category.
	 *
	 * @param int   $category_id Category ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $actor_id Actor ID.
	 * @return array<string,mixed>
	 */
	function nxtcc_delete_ticket_category( int $category_id, array $tenant, int $actor_id = 0 ): array {
		return NXTCC_Ticket_Categories::instance()->delete_or_archive( $category_id, $tenant, $actor_id );
	}
}

if ( ! function_exists( 'nxtcc_update_ticket' ) ) {
	/**
	 * Update ticket fields.
	 *
	 * @param array $args Update arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_ticket( array $args ): array {
		if ( ! isset( $args['conversation_id'] ) && isset( $args['ticket_id'] ) ) {
			$args['conversation_id'] = absint( $args['ticket_id'] );
		}
		return nxtcc_update_conversation( $args );
	}
}

if ( ! function_exists( 'nxtcc_assign_ticket' ) ) {
	/**
	 * Assign or hand off a ticket.
	 *
	 * @param array $args Assignment arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_assign_ticket( array $args ): array {
		if ( ! isset( $args['conversation_id'] ) && isset( $args['ticket_id'] ) ) {
			$args['conversation_id'] = absint( $args['ticket_id'] );
		}
		return nxtcc_assign_conversation( $args );
	}
}

if ( ! function_exists( 'nxtcc_auto_assign_ticket' ) ) {
	/**
	 * Automatically assign a ticket.
	 *
	 * @param array $args Routing arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_auto_assign_ticket( array $args ): array {
		if ( ! isset( $args['conversation_id'] ) && isset( $args['ticket_id'] ) ) {
			$args['conversation_id'] = absint( $args['ticket_id'] );
		}
		return nxtcc_auto_assign_conversation( $args );
	}
}

if ( ! function_exists( 'nxtcc_add_ticket_note' ) ) {
	/**
	 * Add a private internal ticket note.
	 *
	 * @param array $args Note arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_add_ticket_note( array $args ): array {
		if ( ! isset( $args['conversation_id'] ) && isset( $args['ticket_id'] ) ) {
			$args['conversation_id'] = absint( $args['ticket_id'] );
		}
		return nxtcc_add_conversation_note( $args );
	}
}

if ( ! function_exists( 'nxtcc_record_ticket_outbound' ) ) {
	/**
	 * Record an outbound message against an existing ticket.
	 *
	 * This wrapper does not create a ticket unless explicitly requested.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param array       $tenant Tenant tuple.
	 * @param string|null $sent_at UTC timestamp.
	 * @param string      $source Change source.
	 * @param bool        $create_if_missing Whether a ticket may be created.
	 * @return bool
	 */
	function nxtcc_record_ticket_outbound(
		int $contact_id,
		array $tenant,
		?string $sent_at = null,
		string $source = 'integration',
		bool $create_if_missing = false
	): bool {
		$service = NXTCC_Conversations::instance();
		$ticket  = $service->get_for_contact( $contact_id, $tenant );
		if ( ! is_array( $ticket ) && ! $create_if_missing ) {
			return false;
		}

		return $service->touch_outbound( $contact_id, $tenant, $sent_at, $source );
	}
}

if ( ! function_exists( 'nxtcc_record_ticket_outbound_by_id' ) ) {
	/**
	 * Record an outbound message against an explicit ticket.
	 *
	 * @param int         $ticket_id Ticket ID.
	 * @param array       $tenant Tenant tuple.
	 * @param string|null $sent_at UTC timestamp.
	 * @param string      $source Change source.
	 * @return bool
	 */
	function nxtcc_record_ticket_outbound_by_id(
		int $ticket_id,
		array $tenant,
		?string $sent_at = null,
		string $source = 'integration'
	): bool {
		return NXTCC_Conversations::instance()->touch_outbound_for_ticket( $ticket_id, $tenant, $sent_at, $source );
	}
}

if ( ! function_exists( 'nxtcc_set_ticket_watcher' ) ) {
	/**
	 * Add or remove a ticket watcher.
	 *
	 * @param array $args Watcher arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_set_ticket_watcher( array $args ): array {
		if ( ! isset( $args['conversation_id'] ) && isset( $args['ticket_id'] ) ) {
			$args['conversation_id'] = absint( $args['ticket_id'] );
		}
		return nxtcc_set_conversation_watcher( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_ticket_activities' ) ) {
	/**
	 * Read a bounded ticket activity timeline.
	 *
	 * @param int   $ticket_id Ticket/conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_get_ticket_activities( int $ticket_id, array $tenant, int $limit = 100 ): array {
		return nxtcc_get_conversation_activities( $ticket_id, $tenant, $limit );
	}
}

if ( ! function_exists( 'nxtcc_user_can_view_ticket' ) ) {
	/**
	 * Check whether a user may view a ticket.
	 *
	 * @param int   $ticket_id Ticket/conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_view_ticket( int $ticket_id, array $tenant = array(), int $user_id = 0 ): bool {
		return nxtcc_user_can_view_conversation( $ticket_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_user_can_manage_ticket' ) ) {
	/**
	 * Check whether a user may manage a ticket.
	 *
	 * @param int   $ticket_id Ticket/conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	function nxtcc_user_can_manage_ticket( int $ticket_id, array $tenant = array(), int $user_id = 0 ): bool {
		return nxtcc_user_can_manage_conversation( $ticket_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_activity_types' ) ) {
	/**
	 * Return registered CRM activity types.
	 *
	 * @return array<string,string>
	 */
	function nxtcc_get_crm_activity_types(): array {
		return NXTCC_CRM_Activities::instance()->get_activity_types();
	}
}

if ( ! function_exists( 'nxtcc_get_crm_activity' ) ) {
	/**
	 * Read one tenant-scoped CRM activity.
	 *
	 * @param int   $activity_id Activity ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_crm_activity( int $activity_id, array $tenant ): ?array {
		return NXTCC_CRM_Activities::instance()->get( $activity_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_get_contact_crm_activities' ) ) {
	/**
	 * Read a bounded tenant-scoped contact CRM timeline.
	 *
	 * Supported args: limit, before_id, activity_types, source.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $args Reader arguments.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_get_contact_crm_activities( int $contact_id, array $tenant, array $args = array() ): array {
		return NXTCC_CRM_Activities::instance()->list_for_contact( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_chat_timeline' ) ) {
	/**
	 * Read a bounded unified chat activity page.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Cursor arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_get_chat_timeline( int $contact_id, array $tenant, array $args = array() ): array {
		return NXTCC_Chat_Timeline::get( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_chat_timeline_context' ) ) {
	/**
	 * Read a bounded timeline window around an exact activity.
	 *
	 * @param int   $activity_id Activity ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $radius Rows on each side.
	 * @return array<string,mixed>
	 */
	function nxtcc_get_chat_timeline_context( int $activity_id, array $tenant, int $radius = 10 ): array {
		return NXTCC_Chat_Timeline::get_context( $activity_id, $tenant, $radius );
	}
}

if ( ! function_exists( 'nxtcc_record_crm_activity' ) ) {
	/**
	 * Record a tenant-scoped CRM activity.
	 *
	 * @param array<string,mixed> $args Activity arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_record_crm_activity( array $args ): array {
		return NXTCC_CRM_Activities::instance()->record( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_lifecycle_stages' ) ) {
	/**
	 * List tenant lifecycle stages.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param bool  $active_only Whether to return active stages only.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_lifecycle_stages( array $tenant, bool $active_only = true ): array {
		return NXTCC_CRM_Lifecycle_Stages::instance()->list_stages( $tenant, $active_only );
	}
}

if ( ! function_exists( 'nxtcc_upsert_lifecycle_stage' ) ) {
	/**
	 * Create or update a tenant lifecycle stage definition.
	 *
	 * @param array $args Stage arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_upsert_lifecycle_stage( array $args ): array {
		return NXTCC_CRM_Lifecycle_Stages::instance()->upsert_stage( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_contact_lifecycle_stage' ) ) {
	/**
	 * Read one contact's current lifecycle stage.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_contact_lifecycle_stage( int $contact_id, array $tenant ): ?array {
		return NXTCC_CRM_Lifecycle_Stages::instance()->get_contact_stage( $contact_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_set_contact_lifecycle_stage' ) ) {
	/**
	 * Set or clear one contact's lifecycle stage.
	 *
	 * @param array $args Update arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_set_contact_lifecycle_stage( array $args ): array {
		return NXTCC_CRM_Lifecycle_Stages::instance()->set_contact_stage( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_contact_crm_tasks' ) ) {
	/**
	 * List a bounded page of contact tasks.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_contact_crm_tasks( int $contact_id, array $tenant, array $args = array() ): array {
		return NXTCC_CRM_Tasks::instance()->list_for_contact( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_task' ) ) {
	/**
	 * Read one tenant CRM task.
	 *
	 * @param int   $task_id Task ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_crm_task( int $task_id, array $tenant ): ?array {
		return NXTCC_CRM_Tasks::instance()->get_task( $task_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_create_crm_task' ) ) {
	/**
	 * Create a tenant CRM task.
	 *
	 * @param array $args Task arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_create_crm_task( array $args ): array {
		return NXTCC_CRM_Tasks::instance()->create_task( $args );
	}
}

if ( ! function_exists( 'nxtcc_update_crm_task' ) ) {
	/**
	 * Update a tenant CRM task.
	 *
	 * @param array $args Task arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_crm_task( array $args ): array {
		return NXTCC_CRM_Tasks::instance()->update_task( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_crm_saved_views' ) ) {
	/**
	 * List personal saved contact views for one tenant user.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param int   $owner_user_id Owning WordPress user. Current user when omitted.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_crm_saved_views( array $tenant, int $owner_user_id = 0 ): array {
		return NXTCC_CRM_Saved_Views::instance()->list_views( $tenant, $owner_user_id );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_saved_view' ) ) {
	/**
	 * Read one personal saved contact view.
	 *
	 * @param int   $view_id Saved view ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $owner_user_id Owning WordPress user. Current user when omitted.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_crm_saved_view( int $view_id, array $tenant, int $owner_user_id = 0 ): ?array {
		return NXTCC_CRM_Saved_Views::instance()->get_view( $view_id, $tenant, $owner_user_id );
	}
}

if ( ! function_exists( 'nxtcc_upsert_crm_saved_view' ) ) {
	/**
	 * Create or update a personal saved contact view.
	 *
	 * @param array $args Saved view arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_upsert_crm_saved_view( array $args ): array {
		return NXTCC_CRM_Saved_Views::instance()->upsert_view( $args );
	}
}

if ( ! function_exists( 'nxtcc_delete_crm_saved_view' ) ) {
	/**
	 * Delete one personal saved contact view.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_delete_crm_saved_view( array $args ): array {
		return NXTCC_CRM_Saved_Views::instance()->delete_view( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_crm_pipelines' ) ) {
	/**
	 * List tenant sales pipelines.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param bool  $active_only Return active pipelines only.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_crm_pipelines( array $tenant, bool $active_only = true ): array {
		return NXTCC_CRM_Deals::instance()->list_pipelines( $tenant, $active_only );
	}
}

if ( ! function_exists( 'nxtcc_upsert_crm_pipeline' ) ) {
	/**
	 * Create or update a tenant sales pipeline.
	 *
	 * @param array $args Pipeline arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_upsert_crm_pipeline( array $args ): array {
		return NXTCC_CRM_Deals::instance()->upsert_pipeline( $args );
	}
}

if ( ! function_exists( 'nxtcc_list_crm_pipeline_stages' ) ) {
	/**
	 * List stages for a tenant sales pipeline.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $tenant Tenant tuple.
	 * @param bool  $active_only Return active stages only.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_crm_pipeline_stages( int $pipeline_id, array $tenant, bool $active_only = true ): array {
		return NXTCC_CRM_Deals::instance()->list_stages( $pipeline_id, $tenant, $active_only );
	}
}

if ( ! function_exists( 'nxtcc_upsert_crm_pipeline_stage' ) ) {
	/**
	 * Create or update a tenant pipeline stage.
	 *
	 * @param array $args Stage arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_upsert_crm_pipeline_stage( array $args ): array {
		return NXTCC_CRM_Deals::instance()->upsert_stage( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_pipeline_overview' ) ) {
	/**
	 * Return pipelines with stage and deal usage counts.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param bool  $include_inactive Include archived rows.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_get_crm_pipeline_overview( array $tenant, bool $include_inactive = true ): array {
		return NXTCC_CRM_Deals::instance()->get_pipeline_overview( $tenant, $include_inactive );
	}
}

if ( ! function_exists( 'nxtcc_duplicate_crm_pipeline' ) ) {
	/**
	 * Duplicate a pipeline and its stages.
	 *
	 * @param array $args Duplicate arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_duplicate_crm_pipeline( array $args ): array {
		return NXTCC_CRM_Deals::instance()->duplicate_pipeline( $args );
	}
}

if ( ! function_exists( 'nxtcc_delete_crm_pipeline' ) ) {
	/**
	 * Permanently delete an unused pipeline.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_delete_crm_pipeline( array $args ): array {
		return NXTCC_CRM_Deals::instance()->delete_pipeline( $args );
	}
}

if ( ! function_exists( 'nxtcc_delete_crm_pipeline_stage' ) ) {
	/**
	 * Permanently delete an unused stage or move deals first.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_delete_crm_pipeline_stage( array $args ): array {
		return NXTCC_CRM_Deals::instance()->delete_stage( $args );
	}
}

if ( ! function_exists( 'nxtcc_reorder_crm_pipeline_stages' ) ) {
	/**
	 * Reorder stages inside one pipeline.
	 *
	 * @param array $args Reorder arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_reorder_crm_pipeline_stages( array $args ): array {
		return NXTCC_CRM_Deals::instance()->reorder_stages( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_deal_item_providers' ) ) {
	/**
	 * Return registered deal line-item providers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function nxtcc_get_crm_deal_item_providers(): array {
		return NXTCC_CRM_Deal_Item_Providers::get_providers();
	}
}

if ( ! function_exists( 'nxtcc_search_crm_deal_items' ) ) {
	/**
	 * Search one deal line-item provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $search Search text.
	 * @param array  $tenant Tenant tuple.
	 * @param int    $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_search_crm_deal_items( string $provider_id, string $search, array $tenant, int $limit = 20 ): array {
		return NXTCC_CRM_Deal_Item_Providers::search( $provider_id, $search, $tenant, $limit );
	}
}

if ( ! function_exists( 'nxtcc_resolve_crm_deal_item' ) ) {
	/**
	 * Resolve one provider item before storage.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $item_id Provider item ID.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_resolve_crm_deal_item( string $provider_id, string $item_id, array $tenant ): ?array {
		return NXTCC_CRM_Deal_Item_Providers::resolve( $provider_id, $item_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_analytics' ) ) {
	/**
	 * Return aggregate tenant CRM analytics.
	 *
	 * The report contains aggregate totals only and never returns contacts,
	 * messages, notes, or arbitrary metadata. Date ranges default to 30 days
	 * and are bounded to 366 days.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $args Date-range arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_get_crm_analytics( array $tenant, array $args = array() ): array {
		return NXTCC_CRM_Analytics::get( $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_list_crm_deals' ) ) {
	/**
	 * List a bounded page of tenant deals.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_crm_deals( array $tenant, array $args = array() ): array {
		return NXTCC_CRM_Deals::instance()->list_deals( $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_count_crm_deals' ) ) {
	/**
	 * Count tenant deals.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param array $args Query arguments.
	 * @return int
	 */
	function nxtcc_count_crm_deals( array $tenant, array $args = array() ): int {
		return NXTCC_CRM_Deals::instance()->count_deals( $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_crm_deal' ) ) {
	/**
	 * Read one tenant deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	function nxtcc_get_crm_deal( int $deal_id, array $tenant ): ?array {
		return NXTCC_CRM_Deals::instance()->get_deal( $deal_id, $tenant );
	}
}

if ( ! function_exists( 'nxtcc_list_contact_crm_deals' ) ) {
	/**
	 * List deals linked to one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_contact_crm_deals( int $contact_id, array $tenant, array $args = array() ): array {
		return NXTCC_CRM_Deals::instance()->list_for_contact( $contact_id, $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_create_crm_deal' ) ) {
	/**
	 * Create a tenant deal.
	 *
	 * @param array $args Deal arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_create_crm_deal( array $args ): array {
		return NXTCC_CRM_Deals::instance()->create_deal( $args );
	}
}

if ( ! function_exists( 'nxtcc_update_crm_deal' ) ) {
	/**
	 * Update a tenant deal.
	 *
	 * @param array $args Deal arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_update_crm_deal( array $args ): array {
		return NXTCC_CRM_Deals::instance()->update_deal( $args );
	}
}

if ( ! function_exists( 'nxtcc_delete_crm_deal' ) ) {
	/**
	 * Permanently delete one tenant deal and its owned records.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_delete_crm_deal( array $args ): array {
		return NXTCC_CRM_Deals::instance()->delete_deal( $args );
	}
}

if ( ! function_exists( 'nxtcc_user_can_view_crm_deal' ) ) {
	/**
	 * Check whether a user may view one tenant deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID. Current user when omitted.
	 * @return bool
	 */
	function nxtcc_user_can_view_crm_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_view_deal( $deal_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_user_can_manage_crm_deal' ) ) {
	/**
	 * Check whether a user may manage one tenant deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID. Current user when omitted.
	 * @return bool
	 */
	function nxtcc_user_can_manage_crm_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool {
		return NXTCC_CRM_Access_Policy::user_can_manage_deal( $deal_id, $tenant, $user_id );
	}
}

if ( ! function_exists( 'nxtcc_find_contact_duplicate_candidates' ) ) {
	/**
	 * Find strong duplicate candidates for one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $limit Maximum candidates.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_find_contact_duplicate_candidates( int $contact_id, array $tenant, int $limit = 20 ): array {
		return NXTCC_Contact_Merger::instance()->find_candidates( $contact_id, $tenant, $limit );
	}
}

if ( ! function_exists( 'nxtcc_merge_contacts' ) ) {
	/**
	 * Merge one source contact into a target contact.
	 *
	 * @param array $args Merge arguments.
	 * @return array<string,mixed>
	 */
	function nxtcc_merge_contacts( array $args ): array {
		return NXTCC_Contact_Merger::instance()->merge( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_latest_inbound_at' ) ) {
	/**
	 * Read the latest inbound-message timestamp for a contact.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return string|null
	 */
	function nxtcc_get_latest_inbound_at(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?string {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return null;
		}

		$cache_key = nxtcc_runtime_cache_key(
			'latest_inbound',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached ) {
			return is_string( $cached ) && '' !== $cached ? $cached : null;
		}

		$db          = NXTCC_DB::i();
		$history_sql = nxtcc_runtime_quote_table_name( $db->t_message_history() );
		$sql         = 'SELECT created_at
			FROM ' . $history_sql . '
			WHERE contact_id = %d
			  AND status = %s
			  AND deleted_at IS NULL';
		$args        = array( $contact_id, 'received' );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' ORDER BY id DESC LIMIT 1';

		$value = $db->get_var( $sql, $args );
		$value = is_string( $value ) && '' !== $value ? $value : null;

		wp_cache_set( $cache_key, $value, 'nxtcc_runtime', 60 );

		return $value;
	}
}

if ( ! function_exists( 'nxtcc_get_latest_verified_phone_for_user' ) ) {
	/**
	 * Read the latest verified WhatsApp number for a WordPress user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	function nxtcc_get_latest_verified_phone_for_user( int $user_id ): string {
		return NXTCC_Runtime_Integration::get_latest_verified_phone_for_user( $user_id );
	}
}

if ( ! function_exists( 'nxtcc_is_user_auth_verified' ) ) {
	/**
	 * Check whether a WordPress user has completed NXT Cloud Chat verification.
	 *
	 * This stable wrapper is intended for external plugins. It hides the
	 * current auth-binding and force-migration implementation details.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool
	 */
	function nxtcc_is_user_auth_verified( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'nxtcc_is_user_whatsapp_verified' ) && nxtcc_is_user_whatsapp_verified( $user_id ) ) {
			return true;
		}

		if ( function_exists( 'nxtcc_fm_user_is_migrated' ) && nxtcc_fm_user_is_migrated( $user_id ) ) {
			return true;
		}

		if ( function_exists( 'nxtcc_get_latest_verified_phone_for_user' ) ) {
			return '' !== (string) nxtcc_get_latest_verified_phone_for_user( $user_id );
		}

		if ( function_exists( 'nxtcc_get_user_phone_e164' ) ) {
			return '' !== (string) nxtcc_get_user_phone_e164( $user_id );
		}

		return false;
	}
}

if ( ! function_exists( 'nxtcc_get_auth_verification_url' ) ) {
	/**
	 * Build the public NXT Cloud Chat verification URL for integrations.
	 *
	 * Supported args:
	 * - reason: short machine reason added as nxtcc_reason.
	 *
	 * @param string $return_url Optional same-site URL to return after verification.
	 * @param array  $args Optional URL args.
	 * @return string
	 */
	function nxtcc_get_auth_verification_url( string $return_url = '', array $args = array() ): string {
		$return_url = '' !== trim( $return_url ) ? wp_validate_redirect( $return_url, home_url( '/' ) ) : '';
		$reason     = isset( $args['reason'] ) ? sanitize_key( (string) $args['reason'] ) : 'integration_verification';
		$base_url   = '';

		if ( function_exists( 'nxtcc_auth_get_public_login_url' ) ) {
			$base_url = nxtcc_auth_get_public_login_url();
		}

		if ( '' === $base_url && function_exists( 'nxtcc_fm_get_options' ) && function_exists( 'nxtcc_fm_normalize_force_path' ) ) {
			$policy     = nxtcc_fm_get_options();
			$policy     = is_array( $policy ) ? $policy : array();
			$force_path = ! empty( $policy['force_path'] ) ? (string) $policy['force_path'] : '/nxt-whatsapp-login/';
			$base_url   = (string) home_url( nxtcc_fm_normalize_force_path( $force_path ) );
		}

		if ( '' === $base_url ) {
			return wp_login_url( $return_url );
		}

		$query = array(
			'nxtcc_reason' => '' !== $reason ? $reason : 'integration_verification',
		);

		if ( '' !== $return_url ) {
			$query['nxtcc_return_to'] = $return_url;
		}

		$url = add_query_arg( $query, $base_url );

		/**
		 * Filter the integration verification URL.
		 *
		 * @param string $url        Verification URL.
		 * @param string $return_url Validated return URL.
		 * @param array  $args       Original args.
		 */
		$filtered = apply_filters( 'nxtcc_auth_verification_url', $url, $return_url, $args );

		return is_string( $filtered ) && '' !== $filtered ? esc_url_raw( $filtered ) : esc_url_raw( $url );
	}
}

if ( ! function_exists( 'nxtcc_get_message_history_id_by_wamid' ) ) {
	/**
	 * Resolve a local history row id from a Meta message id.
	 *
	 * @param string $wamid Meta message id.
	 * @return int
	 */
	function nxtcc_get_message_history_id_by_wamid( string $wamid ): int {
		return NXTCC_Runtime_Integration::get_message_history_id_by_wamid( $wamid );
	}
}

if ( ! function_exists( 'nxtcc_update_contact_subscription_status' ) ) {
	/**
	 * Update a contact's subscription status through the stable runtime wrapper.
	 *
	 * Supported args:
	 * - contact_id
	 * - status: subscribed|unsubscribed
	 * - user_mailid
	 * - business_account_id
	 * - phone_number_id
	 * - reason
	 *
	 * @param array $args Update arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_update_contact_subscription_status( array $args ): array {
		return NXTCC_Runtime_Integration::update_contact_subscription_status( $args );
	}
}

if ( ! function_exists( 'nxtcc_upsert_contact_for_integration' ) ) {
	/**
	 * Create or update a contact through the stable runtime integration wrapper.
	 *
	 * Supported args:
	 * - user_mailid
	 * - business_account_id
	 * - phone_number_id
	 * - phone_number
	 * - country_code
	 * - wp_user_id
	 * - name
	 * - email
	 * - source
	 * - external_id
	 * - metadata
	 * - is_subscribed
	 * - allow_resubscribe
	 *
	 * @param array $args Contact payload.
	 * @return array<string, mixed>
	 */
	function nxtcc_upsert_contact_for_integration( array $args ): array {
		return NXTCC_Runtime_Integration::upsert_contact_for_integration( $args );
	}
}

if ( ! function_exists( 'nxtcc_normalize_contact_query_filters' ) ) {
	/**
	 * Normalize an allowlisted contact-query filter definition.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	function nxtcc_normalize_contact_query_filters( array $filters ): array {
		return NXTCC_Contact_Query::instance()->normalize_filters( $filters );
	}
}

if ( ! function_exists( 'nxtcc_query_contact_ids' ) ) {
	/**
	 * Query a bounded cursor page of tenant contact IDs.
	 *
	 * Supported args: after_id, limit, require_subscribed, contact_id.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Allowlisted contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,int>
	 */
	function nxtcc_query_contact_ids( array $tenant, array $filters = array(), array $args = array() ): array {
		return NXTCC_Contact_Query::instance()->query_ids( $tenant, $filters, $args );
	}
}

if ( ! function_exists( 'nxtcc_get_contact_query_providers' ) ) {
	/**
	 * Return registered contact-query provider definitions.
	 *
	 * External integrations should register providers with the
	 * `nxtcc_contact_query_providers` filter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function nxtcc_get_contact_query_providers(): array {
		return NXTCC_Contact_Query::instance()->get_providers();
	}
}

if ( ! function_exists( 'nxtcc_count_contacts_by_query' ) ) {
	/**
	 * Count tenant contacts matching an allowlisted query.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Allowlisted contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return int
	 */
	function nxtcc_count_contacts_by_query( array $tenant, array $filters = array(), array $args = array() ): int {
		return NXTCC_Contact_Query::instance()->count( $tenant, $filters, $args );
	}
}

if ( ! function_exists( 'nxtcc_contact_matches_query' ) ) {
	/**
	 * Check whether one tenant contact matches an allowlisted query.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Allowlisted contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return bool
	 */
	function nxtcc_contact_matches_query( int $contact_id, array $tenant, array $filters = array(), array $args = array() ): bool {
		return NXTCC_Contact_Query::instance()->matches( $contact_id, $tenant, $filters, $args );
	}
}

if ( ! function_exists( 'nxtcc_list_contact_groups' ) ) {
	/**
	 * List tenant contact groups for integration selectors.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	function nxtcc_list_contact_groups( array $tenant ): array {
		return NXTCC_Contact_Query::instance()->list_groups( $tenant );
	}
}

if ( ! function_exists( 'nxtcc_get_meta_health_status' ) ) {
	/**
	 * Fetch Meta Messaging and Calling Health Status through the Free runtime.
	 *
	 * Supported args:
	 * - node_id: Optional Meta node id. Defaults to the tenant phone_number_id.
	 * - graph_version: Optional Graph API version.
	 * - force_refresh: Bypass object cache when true.
	 *
	 * @param array $tenant Tenant context.
	 * @param array $args   Optional request arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_get_meta_health_status( array $tenant = array(), array $args = array() ): array {
		if ( ! class_exists( 'NXTCC_Meta_Health_Status' ) ) {
			return array(
				'success' => false,
				'status'  => 'unknown',
				'error'   => array(
					'code'    => 'meta_health_runtime_unavailable',
					'message' => __( 'Meta health runtime is not available.', 'nxt-cloud-chat' ),
				),
			);
		}

		return NXTCC_Meta_Health_Status::get_status( $tenant, $args );
	}
}

if ( ! function_exists( 'nxtcc_send_session_reply' ) ) {
	/**
	 * Stable wrapper for session-reply sends.
	 *
	 * This intentionally preserves the current Free runtime behavior by
	 * delegating to the existing immediate send helper.
	 *
	 * @param array $args Send arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_send_session_reply( array $args ): array {
		if ( ! function_exists( 'nxtcc_send_message_immediately' ) ) {
			return array(
				'success' => false,
				'error'   => 'send_runtime_unavailable',
			);
		}

		return nxtcc_send_message_immediately( $args );
	}
}

if ( ! function_exists( 'nxtcc_send_background_session_reply' ) ) {
	/**
	 * Stable wrapper for background-safe session replies.
	 *
	 * This bypasses current-user checks while still reusing the shared Free send
	 * path and history persistence behavior.
	 *
	 * @param array $args Send arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_send_background_session_reply( array $args ): array {
		return NXTCC_Runtime_Integration::send_background_session_reply( $args );
	}
}
