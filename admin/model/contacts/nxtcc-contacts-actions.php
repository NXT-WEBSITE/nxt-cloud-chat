<?php
/**
 * Contacts AJAX actions (CRUD + bulk operations).
 *
 * This file contains AJAX endpoints used by the Contacts admin UI.
 * All database access is delegated to NXTCC_Contacts_Handler_Repo.
 *
 * Security:
 * - Every AJAX endpoint verifies the request nonce.
 * - Every AJAX endpoint verifies user capabilities.
 * - Request input is sanitized before use.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine which nonce field name the client sent.
 *
 * Some UI requests send "nonce", while others send "security".
 *
 * @return string Nonce field key.
 */
function nxtcc_contacts_nonce_field(): string {
	$nonce_get = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $nonce_get ) {
		return 'nonce';
	}

	$nonce_post = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $nonce_post ) {
		return 'nonce';
	}

	return 'security';
}

/**
 * Verify the nonce for contacts AJAX requests.
 *
 * @return void
 */
function nxtcc_contacts_check_nonce(): void {
	$field = nxtcc_contacts_nonce_field();
	check_ajax_referer( 'nxtcc_contacts_nonce', $field );
}

/**
 * Normalize a list of integers.
 *
 * Accepts an array of values or a comma-separated string.
 *
 * @param mixed $ids Raw ids value.
 * @return array<int> Normalized, non-zero integer ids.
 */
function nxtcc_contacts_int_list( $ids ): array {
	if ( ! is_array( $ids ) ) {
		$ids = explode( ',', (string) $ids );
	}

	$ids = array_map( 'intval', $ids );
	$ids = array_values( array_filter( $ids ) );

	return $ids;
}

/**
 * Get a raw request value from GET first, then POST.
 *
 * This helper avoids direct access to superglobals and supports both
 * GET-driven lists and POST-driven form submissions.
 *
 * @param string $key Parameter name.
 * @return string|null Raw value if present.
 */
function nxtcc_contacts_request_raw( string $key ): ?string {
	$val = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $val ) {
		return $val;
	}

	$val = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $val ) {
		return $val;
	}

	return null;
}

/**
 * Read a request value as sanitized text.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key Request key.
 * @param string $fallback Fallback value.
 * @return string Sanitized text.
 */
function nxtcc_contacts_request_text( string $key, string $fallback = '' ): string {
	$raw = nxtcc_contacts_request_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	return sanitize_text_field( wp_unslash( $raw ) );
}

/**
 * Read a request value as sanitized email.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key Request key.
 * @param string $fallback Fallback value.
 * @return string Sanitized email.
 */
function nxtcc_contacts_request_email( string $key, string $fallback = '' ): string {
	$raw = nxtcc_contacts_request_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	return sanitize_email( wp_unslash( $raw ) );
}

/**
 * Read a request value as integer.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key Request key.
 * @param int    $fallback Fallback value.
 * @return int Integer value.
 */
function nxtcc_contacts_request_int( string $key, int $fallback = 0 ): int {
	$raw = nxtcc_contacts_request_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	return (int) $raw;
}

/**
 * Read a POST value (sanitized scalar).
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key POST key.
 * @return string|null Raw value if present.
 */
function nxtcc_contacts_post_raw( string $key ): ?string {
	$val = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	return $val;
}

/**
 * Read a POST value as integer.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key POST key.
 * @param int    $fallback Fallback value.
 * @return int Integer value.
 */
function nxtcc_contacts_post_int( string $key, int $fallback = 0 ): int {
	$raw = nxtcc_contacts_post_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	return (int) $raw;
}

/**
 * Read a POST value as sanitized text.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key POST key.
 * @param string $fallback Fallback value.
 * @return string Sanitized text.
 */
function nxtcc_contacts_post_text( string $key, string $fallback = '' ): string {
	$raw = nxtcc_contacts_post_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	return sanitize_text_field( wp_unslash( (string) $raw ) );
}

/**
 * Read a POST value as digits-only text.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key POST key.
 * @param string $fallback Fallback value.
 * @return string Digits-only string.
 */
function nxtcc_contacts_post_digits( string $key, string $fallback = '' ): string {
	$raw = nxtcc_contacts_post_raw( $key );
	if ( null === $raw ) {
		return $fallback;
	}

	$out = preg_replace( '/\D/', '', (string) wp_unslash( (string) $raw ) );
	return is_string( $out ) ? $out : $fallback;
}

/**
 * Read a POST list value (array or comma-separated string).
 *
 * Supports keys like ids[] / group_ids[] in form-encoded requests.
 * Input is sanitized at read time and returned unslashed.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @param string $key POST key.
 * @return mixed Array (unslashed) or string (unslashed) depending on payload.
 */
function nxtcc_contacts_post_list_raw( string $key ) {
	$raw_array = filter_input(
		INPUT_POST,
		$key,
		FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		FILTER_REQUIRE_ARRAY
	);

	if ( is_array( $raw_array ) ) {
		return wp_unslash( $raw_array );
	}

	$raw_scalar = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $raw_scalar ) {
		return array();
	}

	return (string) wp_unslash( (string) $raw_scalar );
}


/**
 * Sanitize custom fields payload recursively.
 *
 * Custom fields may contain nested arrays; this function sanitizes keys and values
 * as plain text and applies a small recursion depth limit.
 *
 * @param array $fields Raw custom fields array.
 * @param int   $depth Current recursion depth.
 * @return array Sanitized custom fields array.
 */
function nxtcc_contacts_sanitize_custom_fields( array $fields, int $depth = 0 ): array {
	if ( $depth > 3 ) {
		return array();
	}

	$out = array();

	foreach ( $fields as $k => $v ) {
		$key = sanitize_text_field( (string) $k );
		if ( '' === $key ) {
			continue;
		}

		if ( is_array( $v ) ) {
			$out[ $key ] = nxtcc_contacts_sanitize_custom_fields( $v, $depth + 1 );
			continue;
		}

		if ( is_scalar( $v ) || null === $v ) {
			$out[ $key ] = sanitize_text_field( (string) $v );
		}
	}

	return $out;
}

/**
 * Read custom fields from POST.
 *
 * Supports:
 * - custom_fields (array).
 * - custom_fields_json (JSON string).
 * - custom_fields (JSON string) for backward compatibility.
 *
 * Note: Call this only after nxtcc_contacts_check_nonce().
 *
 * @return array Sanitized custom fields array.
 */
function nxtcc_contacts_post_custom_fields(): array {
	/*
	 * Array payload: custom_fields[].
	 *
	 * FULL_SPECIAL_CHARS is fine here because we are not JSON-decoding;
	 * we sanitize again recursively in nxtcc_contacts_sanitize_custom_fields().
	 */
	$raw_array = filter_input(
		INPUT_POST,
		'custom_fields',
		FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		FILTER_REQUIRE_ARRAY
	);

	if ( is_array( $raw_array ) ) {
		$raw_array = wp_unslash( $raw_array );
		return nxtcc_contacts_sanitize_custom_fields( $raw_array );
	}

	/*
	 * JSON payload: custom_fields_json.
	 *
	 * FULL_SPECIAL_CHARS encodes quotes, so we must decode entities back
	 * before json_decode().
	 */
	$json = filter_input( INPUT_POST, 'custom_fields_json', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( is_string( $json ) ) {
		$json = trim( (string) wp_unslash( $json ) );
		if ( '' !== $json ) {
			$json = html_entity_decode( $json, ENT_QUOTES, 'UTF-8' );

			$tmp = json_decode( $json, true );
			if ( is_array( $tmp ) ) {
				return nxtcc_contacts_sanitize_custom_fields( $tmp );
			}
		}
	}

	/*
	 * Backward compatibility: custom_fields provided as a JSON string.
	 */
	$json_legacy = filter_input( INPUT_POST, 'custom_fields', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( is_string( $json_legacy ) ) {
		$json_legacy = trim( (string) wp_unslash( $json_legacy ) );
		if ( '' !== $json_legacy ) {
			$json_legacy = html_entity_decode( $json_legacy, ENT_QUOTES, 'UTF-8' );

			$tmp = json_decode( $json_legacy, true );
			if ( is_array( $tmp ) ) {
				return nxtcc_contacts_sanitize_custom_fields( $tmp );
			}
		}
	}

	return array();
}

/**
 * Parse common list filters from request.
 *
 * @return array<string, mixed> Filter arguments suitable for repo queries.
 */
function nxtcc_contacts_read_filters(): array {
	$repo = NXTCC_Contacts_Handler_Repo::instance();

	// Accept both new keys and legacy keys used by older UI versions.
	$country = '';
	if ( null !== nxtcc_contacts_request_raw( 'country' ) ) {
		$country = nxtcc_contacts_request_text( 'country' );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_country' ) ) {
		$country = nxtcc_contacts_request_text( 'filter_country' );
	}

	$created_by = '';
	if ( null !== nxtcc_contacts_request_raw( 'created_by' ) ) {
		$created_by = nxtcc_contacts_request_email( 'created_by' );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_created_by' ) ) {
		$created_by = nxtcc_contacts_request_email( 'filter_created_by' );
	}

	$subscription = '';
	if ( null !== nxtcc_contacts_request_raw( 'subscription' ) ) {
		$subscription = nxtcc_contacts_request_text( 'subscription' );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_subscription' ) ) {
		$subscription = nxtcc_contacts_request_text( 'filter_subscription' );
	}

	$created_from = '';
	if ( null !== nxtcc_contacts_request_raw( 'created_from' ) ) {
		$created_from = nxtcc_contacts_request_text( 'created_from' );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_created_from' ) ) {
		$created_from = nxtcc_contacts_request_text( 'filter_created_from' );
	}

	$created_to = '';
	if ( null !== nxtcc_contacts_request_raw( 'created_to' ) ) {
		$created_to = nxtcc_contacts_request_text( 'created_to' );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_created_to' ) ) {
		$created_to = nxtcc_contacts_request_text( 'filter_created_to' );
	}

	$search = nxtcc_contacts_request_text( 'search', '' );

	// Group id supports group_id or legacy filter_group.
	$group_id = 0;
	if ( null !== nxtcc_contacts_request_raw( 'group_id' ) ) {
		$group_id = nxtcc_contacts_request_int( 'group_id', 0 );
	} elseif ( null !== nxtcc_contacts_request_raw( 'filter_group' ) ) {
		$group_id = nxtcc_contacts_request_int( 'filter_group', 0 );
	}

	$page     = max( 1, nxtcc_contacts_request_int( 'page', 1 ) );
	$per_page = max( 1, nxtcc_contacts_request_int( 'per_page', 25 ) );

	$offset      = ( $page - 1 ) * $per_page;
	$search_like = '';
	$name_like   = '';

	if ( '' !== $search ) {
		$like        = '%' . $repo->esc_like( $search ) . '%';
		$search_like = $like;
		$name_like   = '';
	}

	// Subscription value may be empty, zero, or one.
	$sub = '';
	if ( '0' === $subscription || '1' === $subscription ) {
		$sub = $subscription;
	}

	// Normalize dates to prevent malformed inputs.
	$created_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created_from ) ? $created_from : '';
	$created_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created_to ) ? $created_to : '';

	return array(
		'country'      => $country,
		'created_by'   => $created_by,
		'subscription' => $sub,
		'created_from' => $created_from,
		'created_to'   => $created_to,
		'search_like'  => $search_like,
		'name_like'    => $name_like,
		'group_id'     => $group_id,
		'page'         => $page,
		'per_page'     => $per_page,
		'offset'       => $offset,
	);
}

/**
 * AJAX: List contacts (tenant-scoped).
 *
 * Response:
 * - rows: contacts list.
 * - total: total count.
 * - group_map: contact_id => group_ids[].
 * - group_names: group_id => group name.
 *
 * @return void
 */
function nxtcc_ajax_contacts_list(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$repo   = NXTCC_Contacts_Handler_Repo::instance();
	$filter = nxtcc_contacts_read_filters();

	$args = array_merge(
		$filter,
		array(
			'baid' => $baid,
			'pnid' => $pnid,
		)
	);

	$total = $repo->count_contacts( $args );
	$rows  = $repo->list_contacts( $args );

	$ids = array();
	foreach ( (array) $rows as $r ) {
		$ids[] = (int) $r->id;
	}

	$group_map     = $repo->group_map_for_contacts( $ids );
	$all_group_ids = array();

	foreach ( $group_map as $gid_list ) {
		foreach ( (array) $gid_list as $gid ) {
			$all_group_ids[ (int) $gid ] = true;
		}
	}

	$group_names = $repo->group_names_by_ids( array_keys( $all_group_ids ) );

	wp_send_json_success(
		array(
			'rows'        => $rows,
			'total'       => $total,
			'page'        => (int) $filter['page'],
			'per_page'    => (int) $filter['per_page'],
			'group_map'   => $group_map,
			'group_names' => $group_names,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_list', 'nxtcc_ajax_contacts_list' );

/**
 * AJAX: Get a single contact by ID (tenant-scoped).
 *
 * @return void
 */
function nxtcc_ajax_contacts_get(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$id = nxtcc_contacts_request_int( 'id', 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Invalid contact id.' ) );
	}

	$repo    = NXTCC_Contacts_Handler_Repo::instance();
	$contact = $repo->find_contact_in_tenant( $id, $baid, $pnid );

	if ( ! $contact ) {
		wp_send_json_error( array( 'message' => 'Contact not found.' ) );
	}

	$groups = $repo->current_groups_for_contact( (int) $contact->id );

	wp_send_json_success(
		array(
			'contact'   => $contact,
			'group_ids' => $groups,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_get', 'nxtcc_ajax_contacts_get' );

/**
 * AJAX: Create or update a contact (tenant-scoped).
 *
 * Request fields:
 * - id (optional).
 * - name, country_code, phone_number.
 * - is_subscribed (0/1).
 * - group_ids (array or comma string).
 * - custom_fields (array) OR custom_fields_json.
 *
 * @return void
 */
function nxtcc_ajax_contacts_save(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$id            = nxtcc_contacts_post_int( 'id', 0 );
	$name          = trim( nxtcc_contacts_post_text( 'name', '' ) );
	$country_code  = nxtcc_contacts_post_digits( 'country_code', '' );
	$phone_number  = nxtcc_contacts_post_digits( 'phone_number', '' );
	$is_subscribed = nxtcc_contacts_post_int( 'is_subscribed', 1 );
	$group_ids     = nxtcc_contacts_post_list_raw( 'group_ids' );

	$incoming_custom_fields = nxtcc_contacts_post_custom_fields();

	if ( '' === $name || '' === $country_code || '' === $phone_number ) {
		wp_send_json_error( array( 'message' => 'Name, country code, and phone number are required.' ) );
	}

	$is_subscribed = $is_subscribed ? 1 : 0;

	$repo = NXTCC_Contacts_Handler_Repo::instance();

	// Restrict group IDs to groups owned/visible to the current user.
	$group_ids = nxtcc_contacts_int_list( $group_ids );
	$group_ids = $repo->allowlist_user_groups( $user_mailid, $group_ids );

	// Verified flag is derived from membership in verified groups.
	$is_verified = $repo->contact_verified_flag_from_groups( $group_ids );

	// Duplicate detection is tenant-scoped and excludes the current contact when updating.
	$dup_id = $repo->duplicate_contact_id( $baid, $pnid, $country_code, $phone_number, $id ? $id : null );
	if ( $dup_id ) {
		wp_send_json_error(
			array(
				'message'   => 'Duplicate contact exists for this number.',
				'duplicate' => (int) $dup_id,
			)
		);
	}

	$now = current_time( 'mysql', 1 );

	if ( $id > 0 ) {
		$existing = $repo->find_contact_in_tenant( $id, $baid, $pnid );
		if ( ! $existing ) {
			wp_send_json_error( array( 'message' => 'Contact not found.' ) );
		}

		$merged_json = nxtcc_merge_custom_fields( $existing->custom_fields, $incoming_custom_fields );

		$ok = $repo->update_contact_basic(
			$id,
			array(
				'name'                => $name,
				'country_code'        => $country_code,
				'phone_number'        => $phone_number,
				'custom_fields'       => $merged_json,
				'is_verified'         => $is_verified,
				'is_subscribed'       => $is_subscribed,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'updated_at'          => $now,
			)
		);

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'Failed to update contact.' ) );
		}

		$repo->replace_contact_groups( $id, $group_ids );

		wp_send_json_success(
			array(
				'message'     => 'Contact updated.',
				'id'          => (int) $id,
				'is_verified' => (int) $is_verified,
			)
		);
	}

	$merged_json = nxtcc_merge_custom_fields( '', $incoming_custom_fields );

	$data = array(
		'user_mailid'         => $user_mailid,
		'business_account_id' => $baid,
		'phone_number_id'     => $pnid,
		'name'                => $name,
		'country_code'        => $country_code,
		'phone_number'        => $phone_number,
		'custom_fields'       => $merged_json,
		'is_verified'         => $is_verified,
		'is_subscribed'       => $is_subscribed,
		'created_at'          => $now,
		'updated_at'          => $now,
	);

	$new_id = $repo->insert_contact( $data );
	if ( $new_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Failed to create contact.' ) );
	}

	$repo->map_groups_for_new_contact( $new_id, $group_ids );

	wp_send_json_success(
		array(
			'message'     => 'Contact created.',
			'id'          => (int) $new_id,
			'is_verified' => (int) $is_verified,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_save', 'nxtcc_ajax_contacts_save' );

/**
 * AJAX: Delete a contact (tenant-scoped).
 *
 * @return void
 */
function nxtcc_ajax_contacts_delete(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$id = nxtcc_contacts_request_int( 'id', 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Invalid contact id.' ) );
	}

	$repo = NXTCC_Contacts_Handler_Repo::instance();
	$repo->delete_contact_with_mappings( $id, $baid, $pnid );

	wp_send_json_success( array( 'message' => 'Contact deleted.' ) );
}
add_action( 'wp_ajax_nxtcc_contacts_delete', 'nxtcc_ajax_contacts_delete' );

/**
 * AJAX: Bulk delete contacts (tenant-scoped).
 *
 * @return void
 */
function nxtcc_ajax_contacts_bulk_delete(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$ids = nxtcc_contacts_post_list_raw( 'ids' );

	$ids  = nxtcc_contacts_int_list( $ids );
	$repo = NXTCC_Contacts_Handler_Repo::instance();

	// Only contacts within the current tenant may be deleted in bulk.
	$allowed = $repo->allowlist_contacts_in_tenant( $ids, $baid, $pnid );
	$allowed = nxtcc_contacts_int_list( $allowed );

	if ( ! $allowed ) {
		wp_send_json_error( array( 'message' => 'No valid contacts selected.' ) );
	}

	$repo->bulk_delete_contacts( $allowed, $baid, $pnid );

	wp_send_json_success(
		array(
			'message' => 'Contacts deleted.',
			'deleted' => $allowed,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_bulk_delete', 'nxtcc_ajax_contacts_bulk_delete' );

/**
 * AJAX: Bulk update subscription flag (tenant-scoped).
 *
 * @return void
 */
function nxtcc_ajax_contacts_bulk_update_subscription(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$ids  = nxtcc_contacts_post_list_raw( 'ids' );
	$flag = nxtcc_contacts_post_int( 'is_subscribed', 1 );

	$ids  = nxtcc_contacts_int_list( $ids );
	$flag = $flag ? 1 : 0;

	$repo    = NXTCC_Contacts_Handler_Repo::instance();
	$allowed = $repo->allowlist_contacts_in_tenant( $ids, $baid, $pnid );
	$allowed = nxtcc_contacts_int_list( $allowed );

	if ( ! $allowed ) {
		wp_send_json_error( array( 'message' => 'No valid contacts selected.' ) );
	}

	foreach ( $allowed as $cid ) {
		$repo->update_subscription( (int) $cid, $flag, $baid, $pnid );
	}

	wp_send_json_success(
		array(
			'message' => 'Subscription updated.',
			'updated' => $allowed,
			'flag'    => $flag,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_bulk_update_subscription', 'nxtcc_ajax_contacts_bulk_update_subscription' );

/**
 * AJAX: Bulk replace groups for selected contacts (tenant-scoped).
 *
 * @return void
 */
function nxtcc_ajax_contacts_bulk_update_groups(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$ids       = nxtcc_contacts_post_list_raw( 'ids' );
	$group_ids = nxtcc_contacts_post_list_raw( 'group_ids' );

	$ids       = nxtcc_contacts_int_list( $ids );
	$group_ids = nxtcc_contacts_int_list( $group_ids );

	$repo = NXTCC_Contacts_Handler_Repo::instance();

	$allowed_contacts = $repo->allowlist_contacts_in_tenant( $ids, $baid, $pnid );
	$allowed_contacts = nxtcc_contacts_int_list( $allowed_contacts );

	if ( ! $allowed_contacts ) {
		wp_send_json_error( array( 'message' => 'No valid contacts selected.' ) );
	}

	$group_ids = $repo->allowlist_user_groups( $user_mailid, $group_ids );

	foreach ( $allowed_contacts as $cid ) {
		$is_verified = $repo->contact_verified_flag_from_groups( $group_ids );

		$repo->replace_contact_groups( (int) $cid, $group_ids );

		// Keep the verified flag consistent with the assigned groups.
		$repo->update_contact_basic(
			(int) $cid,
			array(
				'is_verified'         => $is_verified,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'updated_at'          => current_time( 'mysql', 1 ),
			)
		);
	}

	wp_send_json_success(
		array(
			'message'  => 'Groups updated.',
			'contacts' => $allowed_contacts,
			'groups'   => $group_ids,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_bulk_update_groups', 'nxtcc_ajax_contacts_bulk_update_groups' );

/**
 * AJAX: List creators for the current tenant.
 *
 * @return void
 */
function nxtcc_ajax_contacts_creators(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$repo = NXTCC_Contacts_Handler_Repo::instance();
	$rows = $repo->creators_for_tenant( $baid, $pnid );

	wp_send_json_success( array( 'creators' => $rows ) );
}
add_action( 'wp_ajax_nxtcc_contacts_creators', 'nxtcc_ajax_contacts_creators' );

/**
 * AJAX: List country codes for the current tenant.
 *
 * @return void
 */
function nxtcc_ajax_contacts_country_codes(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( , $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$repo = NXTCC_Contacts_Handler_Repo::instance();
	$rows = $repo->country_codes_for_tenant( $baid, $pnid );

	wp_send_json_success( array( 'country_codes' => $rows ) );
}
add_action( 'wp_ajax_nxtcc_contacts_country_codes', 'nxtcc_ajax_contacts_country_codes' );
