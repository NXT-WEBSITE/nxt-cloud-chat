<?php
/**
 * Deals admin AJAX handlers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify Deals AJAX access.
 *
 * @param string|array $capabilities Required capabilities.
 * @return void
 */
function nxtcc_deals_verify_access( $capabilities ): void {
	check_ajax_referer( 'nxtcc_deals', 'nonce' );
	nxtcc_verify_caps( $capabilities );
}

/**
 * Return the active tenant or fail.
 *
 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
 */
function nxtcc_deals_current_tenant(): array {
	$tenant = NXTCC_Access_Control::get_current_tenant_context();
	$tenant = NXTCC_Access_Control::normalize_tenant_context( is_array( $tenant ) ? $tenant : array() );
	if ( in_array( '', $tenant, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Tenant not configured.', 'nxt-cloud-chat' ) ), 400 );
	}
	return $tenant;
}

/**
 * Read a request text value.
 *
 * @param string $key Request key.
 * @param string $fallback Fallback.
 * @return string
 */
function nxtcc_deals_request_text( string $key, string $fallback = '' ): string {
	$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $value ) {
		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	}
	return null === $value ? $fallback : sanitize_text_field( wp_unslash( (string) $value ) );
}

/**
 * Read a request integer.
 *
 * @param string $key Request key.
 * @param int    $fallback Fallback.
 * @return int
 */
function nxtcc_deals_request_int( string $key, int $fallback = 0 ): int {
	$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT );
	if ( null === $value ) {
		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_NUMBER_INT );
	}
	return null === $value ? $fallback : absint( $value );
}

/**
 * Read a bounded request ID list.
 *
 * @param string $key Request key.
 * @return array<int,int>
 */
function nxtcc_deals_request_ids( string $key ): array {
	$values = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY );
	if ( ! is_array( $values ) ) {
		$raw    = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$values = null === $raw ? array() : explode( ',', sanitize_text_field( wp_unslash( (string) $raw ) ) );
	}
	return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) ), 0, 100 );
}

/**
 * Format a stored UTC timestamp for the WordPress site timezone.
 *
 * @param string $datetime UTC MySQL datetime.
 * @param string $format Optional display format.
 * @return string
 */
function nxtcc_deals_format_site_datetime( string $datetime, string $format = '' ): string {
	$datetime = trim( sanitize_text_field( $datetime ) );
	if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
		return '';
	}

	$timestamp = strtotime( $datetime . ' UTC' );
	if ( false === $timestamp ) {
		return '';
	}

	if ( '' === $format ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	return wp_date( $format, $timestamp, wp_timezone() );
}

/**
 * Normalize bounded line-item source metadata.
 *
 * @param mixed $metadata Raw metadata.
 * @return array<string,string>
 */
function nxtcc_deals_sanitize_source_metadata( $metadata ): array {
	$output = array();
	foreach ( is_array( $metadata ) ? array_slice( $metadata, 0, 20, true ) : array() as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' !== $key && is_scalar( $value ) ) {
			$output[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
		}
	}
	return $output;
}

/**
 * Read and sanitize deal products JSON.
 *
 * @return array<int,array<string,mixed>>
 */
function nxtcc_deals_request_products(): array {
	// phpcs:ignore WordPressVIPMinimum.Security.PHPFilterFunctions.RestrictedFilter -- JSON is decoded, bounded, and each accepted field is sanitized below.
	$raw     = filter_input( INPUT_POST, 'products', FILTER_UNSAFE_RAW );
	$decoded = null === $raw ? array() : json_decode( wp_unslash( (string) $raw ), true );
	$output  = array();
	foreach ( is_array( $decoded ) ? array_slice( $decoded, 0, 100 ) : array() as $product ) {
		if ( ! is_array( $product ) ) {
			continue;
		}
		$output[] = array(
			'product_id'      => absint( $product['product_id'] ?? 0 ),
			'source_key'      => sanitize_key( (string) ( $product['source_key'] ?? 'manual' ) ),
			'source_item_id'  => substr( sanitize_text_field( (string) ( $product['source_item_id'] ?? '' ) ), 0, 191 ),
			'product_name'    => substr( sanitize_text_field( (string) ( $product['product_name'] ?? '' ) ), 0, 191 ),
			'quantity_type'   => sanitize_key( (string) ( $product['quantity_type'] ?? 'unit' ) ),
			'quantity_label'  => substr( sanitize_text_field( (string) ( $product['quantity_label'] ?? '' ) ), 0, 100 ),
			'quantity'        => max( 1, (int) floor( (float) ( $product['quantity'] ?? 1 ) ) ),
			'unit_price'      => max( 0, (float) ( $product['unit_price'] ?? 0 ) ),
			'currency'        => substr( sanitize_text_field( (string) ( $product['currency'] ?? 'USD' ) ), 0, 3 ),
			'source_metadata' => nxtcc_deals_sanitize_source_metadata( $product['source_metadata'] ?? array() ),
		);
	}
	return $output;
}

/**
 * Whether the current user may view a deal through a linked contact.
 *
 * @param array $deal Deal row.
 * @param array $tenant Tenant tuple.
 * @param bool  $manage Require manage scope.
 * @return bool
 */
function nxtcc_deals_user_can_access_deal( array $deal, array $tenant, bool $manage = false ): bool {
	$policy = NXTCC_CRM_Access_Policy::get_policy( 0, $tenant, $manage ? 'nxtcc_manage_deals' : 'nxtcc_view_deals' );
	if ( $manage && ! NXTCC_CRM_Access_Policy::can_manage( $policy ) ) {
		return false;
	}
	if ( 'all' === (string) ( $policy['data_scope'] ?? '' ) ) {
		return true;
	}

	$assigned_user = absint( $deal['assigned_user_id'] ?? 0 );
	$assigned_role = sanitize_key( (string) ( $deal['assigned_role'] ?? '' ) );
	$policy_user   = absint( $policy['user_id'] ?? 0 );
	$policy_role   = sanitize_key( (string) ( $policy['role_key'] ?? '' ) );
	$scope         = sanitize_key( (string) ( $policy['data_scope'] ?? 'assigned' ) );

	if ( $assigned_user > 0 && $assigned_user === $policy_user ) {
		return true;
	}

	if ( 'team' === $scope && ( ( 0 === $assigned_user && '' === $assigned_role ) || ( '' !== $policy_role && $assigned_role === $policy_role ) ) ) {
		return true;
	}

	$contacts = isset( $deal['contacts'] ) && is_array( $deal['contacts'] )
		? $deal['contacts']
		: array( array( 'contact_id' => absint( $deal['primary_contact_id'] ?? 0 ) ) );
	foreach ( $contacts as $contact ) {
		$contact_id = absint( $contact['contact_id'] ?? 0 );
		if (
			$contact_id > 0
			&& ( $manage
				? NXTCC_CRM_Access_Policy::user_can_manage_contact( $contact_id, $tenant )
				: NXTCC_CRM_Access_Policy::user_can_view_contact( $contact_id, $tenant )
			)
		) {
			return true;
		}
	}
	return false;
}

/**
 * Filter deal rows by the current CRM record scope.
 *
 * @param array $rows Deal rows.
 * @param array $tenant Tenant tuple.
 * @return array<int,array<string,mixed>>
 */
function nxtcc_deals_filter_rows( array $rows, array $tenant ): array {
	return array_values(
		array_filter(
			$rows,
			static function ( $deal ) use ( $tenant ): bool {
				return is_array( $deal ) && nxtcc_deals_user_can_access_deal( $deal, $tenant );
			}
		)
	);
}

/**
 * Build summary values from already authorized deal rows.
 *
 * @param array $rows Authorized deal rows.
 * @return array<string,mixed>
 */
function nxtcc_deals_stats_from_rows( array $rows ): array {
	$stats = array(
		'total_deals' => count( $rows ),
		'open_deals'  => 0,
		'won_deals'   => 0,
		'lost_deals'  => 0,
		'open_value'  => 0,
	);
	foreach ( $rows as $row ) {
		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( isset( $stats[ $status . '_deals' ] ) ) {
			++$stats[ $status . '_deals' ];
		}
		if ( 'open' === $status ) {
			$stats['open_value'] += (float) ( $row['deal_value'] ?? 0 );
		}
	}
	return $stats;
}

/**
 * List compact contact selector options under the current record scope.
 *
 * @param array $tenant Tenant tuple.
 * @return array<int,array<string,mixed>>
 */
function nxtcc_deals_contact_options( array $tenant ): array {
	$db        = NXTCC_DB::i();
	$table     = preg_replace( '/[^A-Za-z0-9_]/', '', $db->t_contacts() );
	$table_sql = '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	$rows      = $db->get_results(
		'SELECT id, name, country_code, phone_number FROM ' . $table_sql . '
		WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
		ORDER BY updated_at DESC, id DESC LIMIT 500',
		array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ),
		ARRAY_A
	);
	$rows      = is_array( $rows ) ? $rows : array();
	$allowed   = array_fill_keys(
		NXTCC_CRM_Access_Policy::filter_contact_ids( wp_list_pluck( $rows, 'id' ), $tenant, false ),
		true
	);

	return array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $allowed ): bool {
				return is_array( $row ) && isset( $allowed[ absint( $row['id'] ?? 0 ) ] );
			}
		)
	);
}

/**
 * Return JSON-safe line-item provider options.
 *
 * @return array<string,array<string,mixed>>
 */
function nxtcc_deals_item_provider_options(): array {
	$output = array();
	foreach ( nxtcc_get_crm_deal_item_providers() as $provider_id => $provider ) {
		$output[ $provider_id ] = array(
			'id'             => sanitize_key( (string) $provider_id ),
			'label'          => sanitize_text_field( (string) ( $provider['label'] ?? $provider_id ) ),
			'available'      => ! empty( $provider['available'] ),
			'searchable'     => ! empty( $provider['searchable'] ),
			'quantity_types' => isset( $provider['quantity_types'] ) && is_array( $provider['quantity_types'] ) ? $provider['quantity_types'] : array(),
		);
	}
	return $output;
}

/**
 * AJAX: Return deal screen options.
 *
 * @return void
 */
function nxtcc_ajax_deals_options(): void {
	nxtcc_deals_verify_access( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) );
	$tenant    = nxtcc_deals_current_tenant();
	$service   = NXTCC_CRM_Deals::instance();
	$pipelines = $service->list_pipelines( $tenant );
	$stages    = array();
	foreach ( $pipelines as $pipeline ) {
		$pipeline_id            = absint( $pipeline['id'] ?? 0 );
		$stages[ $pipeline_id ] = $service->list_stages( $pipeline_id, $tenant );
	}
	$all_pipelines = array();
	$all_stages    = array();
	foreach ( $service->get_pipeline_overview( $tenant, true ) as $pipeline ) {
		$pipeline_id = absint( $pipeline['id'] ?? 0 );
		$stage_rows  = isset( $pipeline['stages'] ) && is_array( $pipeline['stages'] ) ? $pipeline['stages'] : array();
		unset( $pipeline['deal_count'], $pipeline['stages'] );
		foreach ( $stage_rows as &$stage ) {
			unset( $stage['deal_count'], $stage['is_referenced'] );
		}
		unset( $stage );
		$all_pipelines[]            = $pipeline;
		$all_stages[ $pipeline_id ] = $stage_rows;
	}

	wp_send_json_success(
		array(
			'pipelines'          => $pipelines,
			'stages'             => $stages,
			'all_pipelines'      => $all_pipelines,
			'all_stages'         => $all_stages,
			'contacts'           => nxtcc_deals_contact_options( $tenant ),
			'assignment_targets' => function_exists( 'nxtcc_list_contact_assignment_targets' ) ? nxtcc_list_contact_assignment_targets( $tenant ) : array(),
			'item_providers'     => function_exists( 'nxtcc_get_crm_deal_item_providers' ) ? nxtcc_deals_item_provider_options() : array(),
			'permissions'        => array(
				'manage_deals'     => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_deals' ) ),
				'manage_pipelines' => NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_pipelines' ) ),
			),
		)
	);
}
add_action( 'wp_ajax_nxtcc_deals_options', 'nxtcc_ajax_deals_options' );

/**
 * AJAX: List deals.
 *
 * @return void
 */
function nxtcc_ajax_deals_list(): void {
	nxtcc_deals_verify_access( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) );
	$tenant   = nxtcc_deals_current_tenant();
	$page     = max( 1, nxtcc_deals_request_int( 'page', 1 ) );
	$per_page = min( 100, max( 1, nxtcc_deals_request_int( 'per_page', 20 ) ) );
	$filters  = array(
		'pipeline_id' => nxtcc_deals_request_int( 'pipeline_id' ),
		'stage_id'    => nxtcc_deals_request_int( 'stage_id' ),
		'status'      => nxtcc_deals_request_text( 'status' ),
		'search'      => nxtcc_deals_request_text( 'search' ),
		'limit'       => 500,
		'offset'      => 0,
	);
	$service  = NXTCC_CRM_Deals::instance();
	$policy   = NXTCC_CRM_Access_Policy::get_policy( 0, $tenant, 'nxtcc_view_deals' );
	if ( 'all' === (string) ( $policy['data_scope'] ?? '' ) ) {
		$total             = $service->count_deals( $tenant, $filters );
		$filters['limit']  = $per_page;
		$filters['offset'] = ( $page - 1 ) * $per_page;
		$rows              = $service->list_deals( $tenant, $filters );
		$stats             = $service->get_stats( $tenant );
	} else {
		$authorized = nxtcc_deals_filter_rows( $service->list_deals( $tenant, $filters ), $tenant );
		$total      = count( $authorized );
		$stats      = nxtcc_deals_stats_from_rows( $authorized );
		$rows       = array_slice( $authorized, ( $page - 1 ) * $per_page, $per_page );
	}

	foreach ( $rows as &$row ) {
		$row['expected_close_local'] = empty( $row['expected_close_at'] ) ? '' : nxtcc_deals_format_site_datetime( (string) $row['expected_close_at'], get_option( 'date_format' ) );
		$row['updated_at_local']     = empty( $row['updated_at'] ) ? '' : nxtcc_deals_format_site_datetime( (string) $row['updated_at'] );
	}
	unset( $row );

	wp_send_json_success(
		array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'stats'    => $stats,
		)
	);
}
add_action( 'wp_ajax_nxtcc_deals_list', 'nxtcc_ajax_deals_list' );

/**
 * AJAX: Read one deal.
 *
 * @return void
 */
function nxtcc_ajax_deals_get(): void {
	nxtcc_deals_verify_access( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) );
	$tenant = nxtcc_deals_current_tenant();
	$deal   = NXTCC_CRM_Deals::instance()->get_deal( nxtcc_deals_request_int( 'deal_id' ), $tenant );
	if ( ! is_array( $deal ) || ! nxtcc_deals_user_can_access_deal( $deal, $tenant ) ) {
		wp_send_json_error( array( 'message' => __( 'Deal not found or outside your record scope.', 'nxt-cloud-chat' ) ), 404 );
	}
	if ( isset( $deal['stage_history'] ) && is_array( $deal['stage_history'] ) ) {
		foreach ( $deal['stage_history'] as $index => $history ) {
			$deal['stage_history'][ $index ]['changed_at_local'] = empty( $history['changed_at'] ) ? '' : nxtcc_deals_format_site_datetime( (string) $history['changed_at'] );
		}
	}
	wp_send_json_success( array( 'deal' => $deal ) );
}
add_action( 'wp_ajax_nxtcc_deals_get', 'nxtcc_ajax_deals_get' );

/**
 * AJAX: Create or update a deal.
 *
 * @return void
 */
function nxtcc_ajax_deals_save(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_deals' );
	$tenant      = nxtcc_deals_current_tenant();
	$deal_id     = nxtcc_deals_request_int( 'deal_id' );
	$contact_ids = nxtcc_deals_request_ids( 'contact_ids' );
	$policy      = NXTCC_CRM_Access_Policy::get_policy( 0, $tenant, 'nxtcc_manage_deals' );
	if ( 'all' !== (string) ( $policy['data_scope'] ?? '' ) && empty( $contact_ids ) ) {
		wp_send_json_error( array( 'message' => __( 'Choose a contact inside your record scope before saving this deal.', 'nxt-cloud-chat' ) ), 403 );
	}
	foreach ( $contact_ids as $contact_id ) {
		if ( ! NXTCC_CRM_Access_Policy::user_can_manage_contact( $contact_id, $tenant ) ) {
			wp_send_json_error( array( 'message' => __( 'One or more contacts are outside your manageable record scope.', 'nxt-cloud-chat' ) ), 403 );
		}
	}
	if ( $deal_id > 0 ) {
		$current = NXTCC_CRM_Deals::instance()->get_deal( $deal_id, $tenant );
		if ( ! is_array( $current ) || ! nxtcc_deals_user_can_access_deal( $current, $tenant, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This deal is outside your manageable record scope.', 'nxt-cloud-chat' ) ), 403 );
		}
	}

	$args   = array_merge(
		$tenant,
		array(
			'deal_id'            => $deal_id,
			'pipeline_id'        => nxtcc_deals_request_int( 'pipeline_id' ),
			'stage_id'           => nxtcc_deals_request_int( 'stage_id' ),
			'title'              => nxtcc_deals_request_text( 'title' ),
			'description'        => nxtcc_deals_request_text( 'description' ),
			'deal_value'         => (float) nxtcc_deals_request_text( 'deal_value', '0' ),
			'value_mode'         => nxtcc_deals_request_text( 'value_mode', 'manual' ),
			'currency'           => nxtcc_deals_request_text( 'currency', 'USD' ),
			'expected_close_at'  => nxtcc_deals_request_text( 'expected_close_at' ),
			'stage_reason'       => nxtcc_deals_request_text( 'stage_reason' ),
			'assigned_user_id'   => nxtcc_deals_request_int( 'assigned_user_id' ),
			'assigned_role'      => nxtcc_deals_request_text( 'assigned_role' ),
			'contact_ids'        => $contact_ids,
			'primary_contact_id' => nxtcc_deals_request_int( 'primary_contact_id' ),
			'products'           => nxtcc_deals_request_products(),
			'source'             => 'manual',
			'actor_id'           => get_current_user_id(),
		)
	);
	$result = $deal_id > 0 ? nxtcc_update_crm_deal( $args ) : nxtcc_create_crm_deal( $args );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unable to save the deal. Confirm the title, pipeline, stage, and contact access.', 'nxt-cloud-chat' ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? 'deal_save_failed' ) ),
			),
			400
		);
	}
	wp_send_json_success(
		array(
			'message' => $deal_id > 0 ? __( 'Deal updated.', 'nxt-cloud-chat' ) : __( 'Deal created.', 'nxt-cloud-chat' ),
			'result'  => $result,
		)
	);
}
add_action( 'wp_ajax_nxtcc_deals_save', 'nxtcc_ajax_deals_save' );

/**
 * AJAX: Permanently delete one manageable deal.
 *
 * @return void
 */
function nxtcc_ajax_deals_delete(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_deals' );
	$tenant  = nxtcc_deals_current_tenant();
	$deal_id = nxtcc_deals_request_int( 'deal_id' );
	$deal    = NXTCC_CRM_Deals::instance()->get_deal( $deal_id, $tenant );
	if ( ! is_array( $deal ) || ! nxtcc_deals_user_can_access_deal( $deal, $tenant, true ) ) {
		wp_send_json_error( array( 'message' => __( 'This deal is outside your manageable record scope.', 'nxt-cloud-chat' ) ), 403 );
	}

	$result = nxtcc_delete_crm_deal(
		array_merge(
			$tenant,
			array(
				'deal_id'  => $deal_id,
				'source'   => 'manual',
				'actor_id' => get_current_user_id(),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unable to delete this deal.', 'nxt-cloud-chat' ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? 'deal_delete_failed' ) ),
			),
			400
		);
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_delete', 'nxtcc_ajax_deals_delete' );

/**
 * AJAX: Create or update a pipeline.
 *
 * @return void
 */
function nxtcc_ajax_deals_save_pipeline(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_upsert_crm_pipeline(
		array_merge(
			nxtcc_deals_current_tenant(),
			array(
				'pipeline_id'   => nxtcc_deals_request_int( 'pipeline_id' ),
				'pipeline_name' => nxtcc_deals_request_text( 'pipeline_name' ),
				'currency'      => nxtcc_deals_request_text( 'currency', 'USD' ),
				'is_default'    => nxtcc_deals_request_int( 'is_default' ),
				'is_active'     => nxtcc_deals_request_int( 'is_active', 1 ),
				'actor_id'      => get_current_user_id(),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to save the pipeline.', 'nxt-cloud-chat' ) ), 400 );
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_save_pipeline', 'nxtcc_ajax_deals_save_pipeline' );

/**
 * AJAX: Create or update a pipeline stage.
 *
 * @return void
 */
function nxtcc_ajax_deals_save_stage(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_upsert_crm_pipeline_stage(
		array_merge(
			nxtcc_deals_current_tenant(),
			array(
				'pipeline_id'        => nxtcc_deals_request_int( 'pipeline_id' ),
				'stage_id'           => nxtcc_deals_request_int( 'stage_id' ),
				'stage_name'         => nxtcc_deals_request_text( 'stage_name' ),
				'stage_type'         => nxtcc_deals_request_text( 'stage_type', 'open' ),
				'probability'        => nxtcc_deals_request_int( 'probability' ),
				'color'              => nxtcc_deals_request_text( 'color', '#2271b1' ),
				'reason_requirement' => nxtcc_deals_request_text( 'reason_requirement', 'optional' ),
				'is_active'          => nxtcc_deals_request_int( 'is_active', 1 ),
				'actor_id'           => get_current_user_id(),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to save the pipeline stage.', 'nxt-cloud-chat' ) ), 400 );
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_save_stage', 'nxtcc_ajax_deals_save_stage' );

/**
 * AJAX: Return pipeline management rows.
 *
 * @return void
 */
function nxtcc_ajax_deals_pipelines_list(): void {
	nxtcc_deals_verify_access( array( 'nxtcc_view_deals', 'nxtcc_manage_pipelines' ) );
	$pipelines = nxtcc_get_crm_pipeline_overview( nxtcc_deals_current_tenant(), true );
	if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_pipelines' ) ) ) {
		foreach ( $pipelines as &$pipeline ) {
			$pipeline['deal_count'] = null;
			if ( isset( $pipeline['stages'] ) && is_array( $pipeline['stages'] ) ) {
				foreach ( $pipeline['stages'] as &$stage ) {
					$stage['deal_count']    = null;
					$stage['is_referenced'] = null;
				}
				unset( $stage );
			}
		}
		unset( $pipeline );
	}
	wp_send_json_success(
		array(
			'pipelines' => $pipelines,
		)
	);
}
add_action( 'wp_ajax_nxtcc_deals_pipelines_list', 'nxtcc_ajax_deals_pipelines_list' );

/**
 * AJAX: Search one line-item provider.
 *
 * @return void
 */
function nxtcc_ajax_deals_search_items(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_deals' );
	wp_send_json_success(
		array(
			'items' => nxtcc_search_crm_deal_items(
				nxtcc_deals_request_text( 'provider' ),
				nxtcc_deals_request_text( 'search' ),
				nxtcc_deals_current_tenant(),
				20
			),
		)
	);
}
add_action( 'wp_ajax_nxtcc_deals_search_items', 'nxtcc_ajax_deals_search_items' );

/**
 * AJAX: Duplicate a pipeline.
 *
 * @return void
 */
function nxtcc_ajax_deals_duplicate_pipeline(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_duplicate_crm_pipeline(
		array_merge(
			nxtcc_deals_current_tenant(),
			array(
				'pipeline_id'   => nxtcc_deals_request_int( 'pipeline_id' ),
				'pipeline_name' => nxtcc_deals_request_text( 'pipeline_name' ),
				'actor_id'      => get_current_user_id(),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unable to duplicate the pipeline.', 'nxt-cloud-chat' ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? '' ) ),
			),
			400
		);
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_duplicate_pipeline', 'nxtcc_ajax_deals_duplicate_pipeline' );

/**
 * AJAX: Delete an unused pipeline.
 *
 * @return void
 */
function nxtcc_ajax_deals_delete_pipeline(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_delete_crm_pipeline(
		array_merge(
			nxtcc_deals_current_tenant(),
			array( 'pipeline_id' => nxtcc_deals_request_int( 'pipeline_id' ) )
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Referenced, default, or last active pipelines must be archived instead of deleted.', 'nxt-cloud-chat' ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? '' ) ),
			),
			400
		);
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_delete_pipeline', 'nxtcc_ajax_deals_delete_pipeline' );

/**
 * AJAX: Delete an unused stage.
 *
 * @return void
 */
function nxtcc_ajax_deals_delete_stage(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_delete_crm_pipeline_stage(
		array_merge(
			nxtcc_deals_current_tenant(),
			array( 'stage_id' => nxtcc_deals_request_int( 'stage_id' ) )
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'This stage is referenced or cannot be deleted. Archive it instead.', 'nxt-cloud-chat' ),
				'error'   => sanitize_key( (string) ( $result['error'] ?? '' ) ),
			),
			400
		);
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_delete_stage', 'nxtcc_ajax_deals_delete_stage' );

/**
 * AJAX: Reorder stages.
 *
 * @return void
 */
function nxtcc_ajax_deals_reorder_stages(): void {
	nxtcc_deals_verify_access( 'nxtcc_manage_pipelines' );
	$result = nxtcc_reorder_crm_pipeline_stages(
		array_merge(
			nxtcc_deals_current_tenant(),
			array(
				'pipeline_id' => nxtcc_deals_request_int( 'pipeline_id' ),
				'stage_ids'   => nxtcc_deals_request_ids( 'stage_ids' ),
			)
		)
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to reorder stages.', 'nxt-cloud-chat' ) ), 400 );
	}
	wp_send_json_success( array( 'result' => $result ) );
}
add_action( 'wp_ajax_nxtcc_deals_reorder_stages', 'nxtcc_ajax_deals_reorder_stages' );
