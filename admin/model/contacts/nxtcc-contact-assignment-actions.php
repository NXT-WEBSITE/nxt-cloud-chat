<?php
/**
 * Contact assignment admin AJAX handlers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the current Contacts tenant tuple.
 *
 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
 */
function nxtcc_assignments_current_tenant(): array {
	list( $user_mailid, $business_account_id, $phone_number_id ) = nxtcc_get_current_tenant();

	$tenant = array(
		'user_mailid'         => sanitize_email( (string) $user_mailid ),
		'business_account_id' => sanitize_text_field( (string) $business_account_id ),
		'phone_number_id'     => sanitize_text_field( (string) $phone_number_id ),
	);

	if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
		wp_send_json_error( array( 'message' => __( 'Tenant not configured.', 'nxt-cloud-chat' ) ), 400 );
	}

	return $tenant;
}

/**
 * Parse a compact assignment target such as user:12 or role:sales_support.
 *
 * @param string $value Compact target.
 * @return array<string,mixed>
 */
function nxtcc_assignments_parse_target( string $value ): array {
	$value = sanitize_text_field( $value );
	if ( '' === $value || 'unassigned' === $value ) {
		return array(
			'target_type'      => '',
			'assigned_user_id' => 0,
			'assigned_role'    => '',
		);
	}

	$parts = explode( ':', $value, 2 );
	$type  = sanitize_key( (string) ( $parts[0] ?? '' ) );
	$key   = (string) ( $parts[1] ?? '' );

	return array(
		'target_type'      => $type,
		'assigned_user_id' => 'user' === $type ? absint( $key ) : 0,
		'assigned_role'    => 'role' === $type ? sanitize_key( $key ) : '',
	);
}

/**
 * Save a compact assignment target for one contact.
 *
 * @param int                  $contact_id Contact ID.
 * @param string               $target_value Compact target.
 * @param array<string,string> $tenant Tenant tuple.
 * @param string               $source Source.
 * @return array<string,mixed>
 */
function nxtcc_assignments_save_target( int $contact_id, string $target_value, array $tenant, string $source = 'manual' ): array {
	return nxtcc_update_contact_assignment(
		array_merge(
			$tenant,
			nxtcc_assignments_parse_target( $target_value ),
			array(
				'contact_id' => $contact_id,
				'source'     => $source,
				'actor_id'   => get_current_user_id(),
			)
		)
	);
}

/**
 * AJAX: List assignment targets.
 *
 * @return void
 */
function nxtcc_ajax_assignment_targets(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_assignments', 'nxtcc_manage_assignments', 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	wp_send_json_success(
		array(
			'targets' => nxtcc_list_contact_assignment_targets( nxtcc_assignments_current_tenant() ),
		)
	);
}
add_action( 'wp_ajax_nxtcc_assignment_targets', 'nxtcc_ajax_assignment_targets' );

/**
 * AJAX: Save one contact assignment.
 *
 * @return void
 */
function nxtcc_ajax_contact_assignment_save(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_manage_assignments', 'nxtcc_manage_contacts' ) );

	$tenant     = nxtcc_assignments_current_tenant();
	$contact_id = nxtcc_contacts_request_int( 'contact_id', 0 );
	nxtcc_contacts_require_record_access( $contact_id, $tenant, true );

	$result = nxtcc_assignments_save_target(
		$contact_id,
		nxtcc_contacts_request_text( 'assignment_target', '' ),
		$tenant
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => isset( $result['message'] ) ? (string) $result['message'] : __( 'Unable to update the assignment.', 'nxt-cloud-chat' ),
				'error'   => $result['error'] ?? 'assignment_update_failed',
			),
			400
		);
	}

	wp_send_json_success(
		array(
			'message' => __( 'Assignment updated.', 'nxt-cloud-chat' ),
			'result'  => $result,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contact_assignment_save', 'nxtcc_ajax_contact_assignment_save' );

/**
 * AJAX: Update assignments for selected contacts.
 *
 * @return void
 */
function nxtcc_ajax_contacts_bulk_update_assignment(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_manage_assignments', 'nxtcc_manage_contacts' ) );

	$tenant      = nxtcc_assignments_current_tenant();
	$contact_ids = nxtcc_contacts_int_list( nxtcc_contacts_post_list_raw( 'contact_ids' ) );
	$contact_ids = NXTCC_Contacts_Handler_Repo::instance()->allowlist_contacts_in_tenant(
		array_slice( $contact_ids, 0, 200 ),
		$tenant['business_account_id'],
		$tenant['phone_number_id']
	);
	$contact_ids = NXTCC_CRM_Access_Policy::filter_contact_ids( $contact_ids, $tenant, true );
	$target      = nxtcc_contacts_post_text( 'assignment_target', '' );
	$updated     = array();
	$failed      = array();

	foreach ( $contact_ids as $contact_id ) {
		$result = nxtcc_assignments_save_target( absint( $contact_id ), $target, $tenant );
		if ( ! empty( $result['success'] ) ) {
			$updated[] = absint( $contact_id );
		} else {
			$failed[] = absint( $contact_id );
		}
	}

	if ( empty( $updated ) ) {
		wp_send_json_error( array( 'message' => __( 'No assignments were updated.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Assignments updated.', 'nxt-cloud-chat' ),
			'updated' => $updated,
			'failed'  => $failed,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_bulk_update_assignment', 'nxtcc_ajax_contacts_bulk_update_assignment' );
