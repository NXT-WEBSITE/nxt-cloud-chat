<?php
/**
 * Tags admin AJAX handlers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify the Tags admin nonce.
 *
 * @return void
 */
function nxtcc_tags_check_nonce(): void {
	check_ajax_referer( 'nxtcc_tags', 'nonce' );
}

/**
 * Read a sanitized Tags request string.
 *
 * @param string $key Request key.
 * @param string $fallback Fallback.
 * @return string
 */
function nxtcc_tags_request_text( string $key, string $fallback = '' ): string {
	$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $value ) {
		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	}

	return null === $value ? $fallback : sanitize_text_field( wp_unslash( (string) $value ) );
}

/**
 * Read a Tags request integer.
 *
 * @param string $key Request key.
 * @param int    $fallback Fallback.
 * @return int
 */
function nxtcc_tags_request_int( string $key, int $fallback = 0 ): int {
	$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT );
	if ( null === $value ) {
		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_NUMBER_INT );
	}

	return null === $value ? $fallback : (int) $value;
}

/**
 * Read a Tags request ID list.
 *
 * @param string $key Request key.
 * @return array<int>
 */
function nxtcc_tags_request_ids( string $key ): array {
	$values = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY );
	if ( ! is_array( $values ) ) {
		$scalar = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$values = null === $scalar ? array() : explode( ',', (string) wp_unslash( (string) $scalar ) );
	}

	return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) ), 0, 200 );
}

/**
 * Return the active tenant tuple or fail the AJAX request.
 *
 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
 */
function nxtcc_tags_current_tenant(): array {
	list( $user_mailid, $business_account_id, $phone_number_id ) = nxtcc_get_current_tenant();

	$tenant = array(
		'user_mailid'         => sanitize_email( (string) $user_mailid ),
		'business_account_id' => sanitize_text_field( (string) $business_account_id ),
		'phone_number_id'     => sanitize_text_field( (string) $phone_number_id ),
	);

	if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
		wp_send_json_error( array( 'message' => __( 'Tenant not configured.', 'nxt-cloud-chat' ) ) );
	}

	return $tenant;
}

/**
 * AJAX: List tenant tags.
 *
 * @return void
 */
function nxtcc_ajax_tags_list(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_tags', 'nxtcc_manage_tags', 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	$tenant   = nxtcc_tags_current_tenant();
	$search   = nxtcc_tags_request_text( 'search' );
	$page     = max( 1, nxtcc_tags_request_int( 'page', 1 ) );
	$per_page = max( 1, min( 100, nxtcc_tags_request_int( 'per_page', 20 ) ) );
	$service  = NXTCC_Tags::instance();
	$total    = $service->count_tags( $tenant, $search );
	$rows     = $service->list_tags(
		$tenant,
		array(
			'search' => $search,
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		)
	);

	foreach ( $rows as &$row ) {
		$row['updated_at_local'] = ! empty( $row['updated_at'] )
			? get_date_from_gmt( (string) $row['updated_at'], 'Y-m-d g:i A' )
			: '';
	}
	unset( $row );

	wp_send_json_success(
		array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'stats'    => $service->get_stats( $tenant ),
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_list', 'nxtcc_ajax_tags_list' );

/**
 * AJAX: Return all tenant tags for selectors.
 *
 * @return void
 */
function nxtcc_ajax_tags_options(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_tags', 'nxtcc_manage_tags', 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	wp_send_json_success(
		array(
			'tags' => NXTCC_Tags::instance()->list_tags(
				nxtcc_tags_current_tenant(),
				array(
					'limit'      => 1000,
					'with_count' => false,
				)
			),
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_options', 'nxtcc_ajax_tags_options' );

/**
 * AJAX: Create or update a tenant tag.
 *
 * @return void
 */
function nxtcc_ajax_tags_save(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( 'nxtcc_manage_tags' );

	$tenant      = nxtcc_tags_current_tenant();
	$tag_id      = nxtcc_tags_request_int( 'tag_id' );
	$tag_name    = nxtcc_tags_request_text( 'tag_name' );
	$color       = nxtcc_tags_request_text( 'color', '#2271b1' );
	$description = nxtcc_tags_request_text( 'description' );
	$args        = array_merge(
		$tenant,
		array(
			'tag_id'      => $tag_id,
			'tag_name'    => $tag_name,
			'color'       => $color,
			'description' => $description,
			'actor_id'    => get_current_user_id(),
		)
	);
	$result      = $tag_id > 0
		? NXTCC_Tags::instance()->update_tag( $args )
		: NXTCC_Tags::instance()->upsert_tag( $args );

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unable to save the tag. Confirm its name is unique for this tenant.', 'nxt-cloud-chat' ),
				'error'   => $result['error'] ?? 'tag_save_failed',
			)
		);
	}

	wp_send_json_success(
		array(
			'message' => $tag_id > 0 ? __( 'Tag updated.', 'nxt-cloud-chat' ) : __( 'Tag created.', 'nxt-cloud-chat' ),
			'result'  => $result,
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_save', 'nxtcc_ajax_tags_save' );

/**
 * AJAX: Delete one tenant tag.
 *
 * @return void
 */
function nxtcc_ajax_tags_delete(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( 'nxtcc_manage_tags' );

	$result = NXTCC_Tags::instance()->delete_tag(
		array_merge(
			nxtcc_tags_current_tenant(),
			array( 'tag_id' => nxtcc_tags_request_int( 'tag_id' ) )
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to delete the tag.', 'nxt-cloud-chat' ) ) );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Tag deleted. Contacts were kept.', 'nxt-cloud-chat' ),
			'result'  => $result,
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_delete', 'nxtcc_ajax_tags_delete' );

/**
 * AJAX: Delete selected tenant tags.
 *
 * @return void
 */
function nxtcc_ajax_tags_bulk_delete(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( 'nxtcc_manage_tags' );

	$tenant  = nxtcc_tags_current_tenant();
	$tag_ids = nxtcc_tags_request_ids( 'tag_ids' );
	$deleted = array();
	$failed  = array();

	foreach ( $tag_ids as $tag_id ) {
		$result = NXTCC_Tags::instance()->delete_tag( array_merge( $tenant, array( 'tag_id' => $tag_id ) ) );
		if ( ! empty( $result['success'] ) ) {
			$deleted[] = $tag_id;
		} else {
			$failed[] = $tag_id;
		}
	}

	if ( empty( $deleted ) ) {
		wp_send_json_error( array( 'message' => __( 'No selected tags could be deleted.', 'nxt-cloud-chat' ) ) );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Selected tags deleted. Contacts were kept.', 'nxt-cloud-chat' ),
			'deleted' => $deleted,
			'failed'  => $failed,
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_bulk_delete', 'nxtcc_ajax_tags_bulk_delete' );

/**
 * AJAX: Merge selected source tags into a target tag.
 *
 * @return void
 */
function nxtcc_ajax_tags_merge(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( 'nxtcc_manage_tags' );

	$result = NXTCC_Tags::instance()->merge_tags(
		array_merge(
			nxtcc_tags_current_tenant(),
			array(
				'target_tag_id'  => nxtcc_tags_request_int( 'target_tag_id' ),
				'source_tag_ids' => nxtcc_tags_request_ids( 'source_tag_ids' ),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to merge the selected tags.', 'nxt-cloud-chat' ) ) );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Tags merged successfully.', 'nxt-cloud-chat' ),
			'result'  => $result,
		)
	);
}
add_action( 'wp_ajax_nxtcc_tags_merge', 'nxtcc_ajax_tags_merge' );

/**
 * AJAX: Add or remove tags from selected contacts.
 *
 * @return void
 */
function nxtcc_ajax_contacts_bulk_update_tags(): void {
	nxtcc_tags_check_nonce();
	nxtcc_verify_caps( 'nxtcc_manage_contacts' );

	$tenant      = nxtcc_tags_current_tenant();
	$contact_ids = nxtcc_tags_request_ids( 'contact_ids' );
	$tag_ids     = nxtcc_tags_request_ids( 'tag_ids' );
	$operation   = nxtcc_tags_request_text( 'operation', 'add' );
	$repo        = NXTCC_Contacts_Handler_Repo::instance();
	$contact_ids = $repo->allowlist_contacts_in_tenant( $contact_ids, $tenant['business_account_id'], $tenant['phone_number_id'] );
	$contact_ids = NXTCC_CRM_Access_Policy::filter_contact_ids( $contact_ids, $tenant, true );
	$updated     = array();
	$failed      = array();

	if ( ! in_array( $operation, array( 'add', 'remove' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid tag operation.', 'nxt-cloud-chat' ) ) );
	}

	foreach ( array_slice( array_map( 'absint', $contact_ids ), 0, 200 ) as $contact_id ) {
		$result = nxtcc_update_contact_tags(
			array_merge(
				$tenant,
				array(
					'contact_id' => $contact_id,
					'tag_ids'    => $tag_ids,
					'operation'  => $operation,
					'source'     => 'manual',
					'actor_id'   => get_current_user_id(),
				)
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$updated[] = $contact_id;
		} else {
			$failed[] = $contact_id;
		}
	}

	if ( empty( $updated ) ) {
		wp_send_json_error( array( 'message' => __( 'No contact tags were updated.', 'nxt-cloud-chat' ) ) );
	}

	wp_send_json_success(
		array(
			'message' => 'add' === $operation ? __( 'Tags added to selected contacts.', 'nxt-cloud-chat' ) : __( 'Tags removed from selected contacts.', 'nxt-cloud-chat' ),
			'updated' => $updated,
			'failed'  => $failed,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_bulk_update_tags', 'nxtcc_ajax_contacts_bulk_update_tags' );
