<?php
/**
 * Groups AJAX actions.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_ajax_groups_list' ) ) {
	/**
	 * AJAX: List groups for current tenant.
	 *
	 * @return void
	 */
	function nxtcc_ajax_groups_list(): void {
		check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
		nxtcc_verify_caps( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

		list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();

		if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
			wp_send_json_error( array( 'message' => 'Tenant not identified.' ) );
		}

		$repo = NXTCC_Contacts_Handler_Repo::instance();
		$rows = $repo->list_user_groups( (string) $user_mailid, (string) $baid, (string) $pnid );

		wp_send_json_success( array( 'groups' => $rows ) );
	}
}
add_action( 'wp_ajax_nxtcc_groups_list', 'nxtcc_ajax_groups_list' );

if ( ! function_exists( 'nxtcc_ajax_groups_create' ) ) {
	/**
	 * AJAX: Create group.
	 *
	 * @return void
	 */
	function nxtcc_ajax_groups_create(): void {
		check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
		nxtcc_verify_caps( 'nxtcc_manage_groups' );

		list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();

		if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
			wp_send_json_error( array( 'message' => 'Tenant not identified.' ) );
		}

		$name = '';
		if ( isset( $_POST['group_name'] ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['group_name'] ) );
		}

		$name = trim( $name );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'Group name required.' ) );
		}

		$repo = NXTCC_Contacts_Handler_Repo::instance();
		$out  = $repo->create_group_if_absent(
			(string) $user_mailid,
			(string) $baid,
			(string) $pnid,
			(string) $name
		);

		// Optional: keep if you have it, but ideally make this tenant-aware too.
		if ( function_exists( 'nxtcc_invalidate_user_groups_cache' ) ) {
			nxtcc_invalidate_user_groups_cache( (string) $user_mailid );
		}

		if ( empty( $out ) ) {
			wp_send_json_error( array( 'message' => 'Failed to create group.' ) );
		}

		wp_send_json_success(
			array(
				'group' => $out,
			)
		);
	}
}
add_action( 'wp_ajax_nxtcc_groups_create', 'nxtcc_ajax_groups_create' );
