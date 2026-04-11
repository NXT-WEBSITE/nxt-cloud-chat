<?php
/**
 * Groups AJAX handlers.
 *
 * Registers admin-only AJAX actions for managing groups.
 * All database operations are delegated to the repository layer and are scoped
 * to the current tenant (owner + business account + phone number).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_nxtcc_fetch_groups_list', 'nxtcc_fetch_groups_list_handler' );
add_action( 'wp_ajax_nxtcc_fetch_single_group', 'nxtcc_fetch_single_group_handler' );
add_action( 'wp_ajax_nxtcc_save_group', 'nxtcc_save_group_handler' );
add_action( 'wp_ajax_nxtcc_delete_group', 'nxtcc_delete_group_handler' );
add_action( 'wp_ajax_nxtcc_groups_bulk_action', 'nxtcc_groups_bulk_action_handler' );

/**
 * Send a consistent "not logged in" JSON error.
 *
 * @return void
 */
function nxtcc_groups_send_not_logged_in(): void {
	wp_send_json_error( array( 'message' => 'Not logged in.' ) );
}

/**
 * Send a consistent "unauthorized" JSON error.
 *
 * @return void
 */
function nxtcc_groups_send_unauthorized(): void {
	wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
}

/**
 * Ensure nonce is verified before reading any request data.
 *
 * VIP sniff requires nonce verification before any $_POST processing. We do it
 * once per request and then allow helper reads.
 *
 * @return void
 */
function nxtcc_groups_require_nonce(): void {
	static $verified = false;

	if ( $verified ) {
		return;
	}

	// This will wp_die() on failure.
	check_ajax_referer( 'nxtcc_groups', 'nonce' );

	$verified = true;
}

/**
 * Read a POST scalar safely (nonce verified, sanitized at input + normalized).
 *
 * @param string $key POST key.
 * @return string
 */
function nxtcc_groups_post_text( string $key ): string {
	nxtcc_groups_require_nonce();

	$raw = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $raw || false === $raw ) {
		return '';
	}

	// Normalize to plain text for app usage.
	return sanitize_text_field( (string) $raw );
}

/**
 * Read a POST int safely (nonce verified, sanitized at input).
 *
 * @param string $key POST key.
 * @return int
 */
function nxtcc_groups_post_absint( string $key ): int {
	nxtcc_groups_require_nonce();

	$raw = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT );
	if ( null === $raw || false === $raw ) {
		return 0;
	}

	return absint( $raw );
}

/**
 * Read a POST array field safely (supports array or CSV/JSON fallback).
 *
 * Supports:
 * - group_ids[] (recommended)
 * - CSV: "1,2,3"
 * - JSON: "[1,2,3]"
 *
 * @param string $key POST key.
 * @return array<int>
 */
function nxtcc_groups_post_id_array( string $key ): array {
	nxtcc_groups_require_nonce();

	// Primary: array input (group_ids[]).
	$arr = filter_input(
		INPUT_POST,
		$key,
		FILTER_SANITIZE_NUMBER_INT,
		array(
			'flags' => FILTER_REQUIRE_ARRAY,
		)
	);

	if ( is_array( $arr ) ) {
		$vals = array_map(
			static function ( $v ) {
				return is_scalar( $v ) ? (string) $v : '';
			},
			$arr
		);

		return array_values( array_filter( array_map( 'absint', $vals ) ) );
	}

	// Fallback: scalar input (CSV/JSON).
	$raw = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $raw || false === $raw ) {
		return array();
	}

	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return array();
	}

	// JSON list support.
	if ( '[' === $raw[0] ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$vals = array_map(
				static function ( $v ) {
					return is_scalar( $v ) ? (string) $v : '';
				},
				$decoded
			);
			return array_values( array_filter( array_map( 'absint', $vals ) ) );
		}
	}

	// CSV support.
	$parts = array_map( 'trim', explode( ',', $raw ) );
	return array_values( array_filter( array_map( 'absint', $parts ) ) );
}

/**
 * Resolve tenant identifiers safely for handlers.
 *
 * @return array{0:string,1:string,2:string} Tenant tuple.
 */
function nxtcc_groups_get_tenant_safe(): array {
	list( $owner, $baid, $pnid ) = nxtcc_groups_get_current_tenant();

	$owner_safe = is_string( $owner ) ? $owner : '';
	$baid_safe  = is_string( $baid ) ? $baid : '';
	$pnid_safe  = is_string( $pnid ) ? $pnid : '';

	return array( $owner_safe, $baid_safe, $pnid_safe );
}

/**
 * Fetch groups list for the current tenant.
 *
 * @return void
 */
function nxtcc_fetch_groups_list_handler(): void {
	if ( ! is_user_logged_in() ) {
		nxtcc_groups_send_not_logged_in();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		nxtcc_groups_send_unauthorized();
	}

	// Nonce is verified inside input helpers.
	$search  = nxtcc_groups_post_text( 'search' );
	$sortkey = sanitize_key( nxtcc_groups_post_text( 'sort_key' ) );
	$sortdir = strtolower( nxtcc_groups_post_text( 'sort_dir' ) );

	list( $owner_safe, $baid_safe, $pnid_safe ) = nxtcc_groups_get_tenant_safe();

	if ( '' === $owner_safe || '' === $baid_safe || '' === $pnid_safe ) {
		wp_send_json_success(
			array(
				'groups'     => array(),
				'has_tenant' => false,
			)
		);
	}

	if ( '' === $sortkey ) {
		$sortkey = 'group_name';
	}
	if ( ! in_array( $sortdir, array( 'asc', 'desc' ), true ) ) {
		$sortdir = 'asc';
	}

	$rows = NXTCC_Groups_Repo::i()->list_user_groups(
		$owner_safe,
		$baid_safe,
		$pnid_safe,
		$search,
		$sortkey,
		$sortdir
	);

	foreach ( $rows as &$row ) {
		$email = isset( $row['user_mailid'] ) ? sanitize_email( (string) $row['user_mailid'] ) : '';

		$row['created_by']       = $email;
		$row['is_verified']      = (int) ( $row['is_verified'] ?? 0 );
		$row['count']            = (int) ( $row['count'] ?? 0 );
		$row['subscribed_count'] = (int) ( $row['subscribed_count'] ?? 0 );
	}
	unset( $row );

	wp_send_json_success(
		array(
			'groups'     => $rows,
			'has_tenant' => true,
		)
	);
}

/**
 * Fetch a single group row for edit modal.
 *
 * @return void
 */
function nxtcc_fetch_single_group_handler(): void {
	if ( ! is_user_logged_in() ) {
		nxtcc_groups_send_not_logged_in();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		nxtcc_groups_send_unauthorized();
	}

	$id = nxtcc_groups_post_absint( 'group_id' );

	list( $owner_safe, $baid_safe, $pnid_safe ) = nxtcc_groups_get_tenant_safe();

	if ( 0 === $id ) {
		wp_send_json_error( array( 'message' => 'Invalid group.' ) );
	}

	$row = NXTCC_Groups_Repo::i()->get_group_for_owner( $id, $owner_safe, $baid_safe, $pnid_safe );
	if ( empty( $row ) ) {
		wp_send_json_error( array( 'message' => 'Group not found.' ) );
	}

	$row['is_verified'] = (int) ( $row['is_verified'] ?? 0 );
	wp_send_json_success( $row );
}

/**
 * Create or rename a group.
 *
 * @return void
 */
function nxtcc_save_group_handler(): void {
	if ( ! is_user_logged_in() ) {
		nxtcc_groups_send_not_logged_in();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		nxtcc_groups_send_unauthorized();
	}

	$id         = nxtcc_groups_post_absint( 'group_id' );
	$group_name = nxtcc_groups_post_text( 'group_name' );

	list( $owner_safe, $baid_safe, $pnid_safe ) = nxtcc_groups_get_tenant_safe();

	if ( '' === $group_name ) {
		wp_send_json_error( array( 'message' => 'Group name required.' ) );
	}

	if ( '' === $owner_safe || '' === $baid_safe || '' === $pnid_safe ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$new_is_verified = ( 0 === strcasecmp( $group_name, 'verified' ) ) ? 1 : 0;

	$repo = NXTCC_Groups_Repo::i();

	$dupe = $repo->count_dupe( $owner_safe, $baid_safe, $pnid_safe, $group_name, $id );
	if ( 0 < $dupe ) {
		wp_send_json_error( array( 'message' => 'A group with this name already exists.' ) );
	}

	if ( 0 < $id ) {
		$prev = $repo->get_group_min( $id );

		if (
			! $prev
			|| 0 !== strcasecmp( (string) $prev->user_mailid, (string) $owner_safe )
			|| (string) $prev->business_account_id !== (string) $baid_safe
			|| (string) $prev->phone_number_id !== (string) $pnid_safe
		) {
			wp_send_json_error( array( 'message' => 'Group not found.' ) );
		}

		if ( 1 === (int) $prev->is_verified ) {
			wp_send_json_error( array( 'message' => 'Verified group cannot be edited.' ) );
		}

		if ( ! $repo->update_group_name( $id, $group_name, $owner_safe, $baid_safe, $pnid_safe ) ) {
			wp_send_json_error( array( 'message' => 'Failed to save group.' ) );
		}

		$curr = $repo->get_group_min( $id );

		wp_send_json_success(
			array(
				'message'     => 'Group saved.',
				'group_id'    => (int) $id,
				'is_verified' => $curr ? (int) $curr->is_verified : 0,
			)
		);
	}

	$new_id = $repo->insert_group( $group_name, $owner_safe, $baid_safe, $pnid_safe, $new_is_verified );
	if ( 0 === (int) $new_id ) {
		wp_send_json_error( array( 'message' => 'Failed to create group.' ) );
	}

	wp_send_json_success(
		array(
			'message'     => 'Group saved.',
			'group_id'    => (int) $new_id,
			'is_verified' => (int) $new_is_verified,
		)
	);
}

/**
 * Delete a single group (blocked for verified groups).
 *
 * @return void
 */
function nxtcc_delete_group_handler(): void {
	if ( ! is_user_logged_in() ) {
		nxtcc_groups_send_not_logged_in();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		nxtcc_groups_send_unauthorized();
	}

	$id = nxtcc_groups_post_absint( 'group_id' );

	if ( 0 === $id ) {
		wp_send_json_error( array( 'message' => 'Invalid group.' ) );
	}

	list( $owner_safe, $baid_safe, $pnid_safe ) = nxtcc_groups_get_tenant_safe();

	$repo = NXTCC_Groups_Repo::i();

	$row = $repo->get_group_for_owner( $id, $owner_safe, $baid_safe, $pnid_safe );
	if ( empty( $row ) ) {
		wp_send_json_error( array( 'message' => 'Group not found.' ) );
	}

	if ( 1 === (int) ( $row['is_verified'] ?? 0 ) ) {
		wp_send_json_error( array( 'message' => 'Verified group cannot be deleted.' ) );
	}

	$contact_ids = $repo->get_contact_ids_for_group( $id );

	$repo->delete_mappings_for_group( $id, $owner_safe, $baid_safe, $pnid_safe );

	$deleted = $repo->delete_group( $id, $owner_safe, $baid_safe, $pnid_safe );
	if ( false === $deleted ) {
		wp_send_json_error( array( 'message' => 'Failed to delete group.' ) );
	}

	if ( ! empty( $contact_ids ) ) {
		$repo->recompute_contacts_verification( $contact_ids );
	}

	wp_send_json_success( array( 'message' => 'Group deleted.' ) );
}

/**
 * Bulk handler for groups.
 *
 * Supports:
 * - delete
 * - set_subscription (expects set_to=subscribed|unsubscribed)
 *
 * @return void
 */
function nxtcc_groups_bulk_action_handler(): void {
	if ( ! is_user_logged_in() ) {
		nxtcc_groups_send_not_logged_in();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		nxtcc_groups_send_unauthorized();
	}

	$bulk_action   = strtolower( nxtcc_groups_post_text( 'bulk_action' ) );
	$requested_ids = nxtcc_groups_post_id_array( 'group_ids' );

	if ( '' === $bulk_action || empty( $requested_ids ) ) {
		wp_send_json_error( array( 'message' => 'Invalid request.' ) );
	}

	if ( ! in_array( $bulk_action, array( 'delete', 'set_subscription' ), true ) ) {
		wp_send_json_error( array( 'message' => 'Unknown bulk action.' ) );
	}

	list( $owner_safe, $baid_safe, $pnid_safe ) = nxtcc_groups_get_tenant_safe();

	$repo      = NXTCC_Groups_Repo::i();
	$owned_min = $repo->get_owned_rows_min( $requested_ids, $owner_safe, $baid_safe, $pnid_safe );

	if ( empty( $owned_min ) ) {
		wp_send_json_error( array( 'message' => 'No matching groups.' ) );
	}

	$deletable = array();
	$blocked   = array();

	foreach ( $owned_min as $row ) {
		$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		if ( ! $id ) {
			continue;
		}

		if ( 1 === (int) ( $row['is_verified'] ?? 0 ) ) {
			$blocked[] = $id;
		} else {
			$deletable[] = $id;
		}
	}

	if ( 'delete' === $bulk_action ) {
		if ( empty( $deletable ) ) {
			wp_send_json_error(
				array(
					'message'     => 'Nothing deleted. Verified groups cannot be deleted.',
					'blocked_ids' => $blocked,
				)
			);
		}

		$contact_ids = $repo->get_contact_ids_for_groups( $deletable );

		$repo->delete_mappings_for_groups( $deletable, $owner_safe, $baid_safe, $pnid_safe );
		$repo->delete_groups( $deletable, $owner_safe, $baid_safe, $pnid_safe );

		if ( ! empty( $contact_ids ) ) {
			$repo->recompute_contacts_verification( $contact_ids );
		}

		wp_send_json_success(
			array(
				'message'     => empty( $blocked ) ? 'Groups deleted.' : 'Groups deleted (Verified groups were skipped).',
				'deleted_ids' => $deletable,
				'blocked_ids' => $blocked,
			)
		);
	}

	$set_to = strtolower( nxtcc_groups_post_text( 'set_to' ) );

	if ( ! in_array( $set_to, array( 'subscribed', 'unsubscribed' ), true ) ) {
		wp_send_json_error( array( 'message' => 'Invalid subscription value.' ) );
	}

	$flag = ( 'subscribed' === $set_to ) ? 1 : 0;

	$repo->update_contacts_subscription_by_groups( $requested_ids, $flag, $owner_safe, $baid_safe, $pnid_safe );

	wp_send_json_success(
		array(
			'message'   => 'Subscription updated for contacts in selected groups.',
			'set_to'    => $set_to,
			'group_ids' => $requested_ids,
		)
	);
}
