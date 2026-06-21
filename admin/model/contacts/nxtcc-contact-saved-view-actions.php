<?php
/**
 * Contacts saved-view AJAX actions.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the current complete tenant tuple.
 *
 * @return array<string,string>
 */
function nxtcc_saved_views_current_tenant(): array {
	list( $user_mailid, $business_account_id, $phone_number_id ) = nxtcc_get_current_tenant();
	return array(
		'user_mailid'         => sanitize_email( (string) $user_mailid ),
		'business_account_id' => sanitize_text_field( (string) $business_account_id ),
		'phone_number_id'     => sanitize_text_field( (string) $phone_number_id ),
	);
}

/**
 * Return a raw POST JSON string.
 *
 * @param string $key Request key.
 * @return string
 */
function nxtcc_saved_views_post_json( string $key ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verifies the Contacts nonce; the saved-view service decodes and allowlists every filter value.
	$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	return $value;
}

/**
 * AJAX: list the current user's tenant saved views.
 *
 * @return void
 */
function nxtcc_ajax_contacts_saved_views_list(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	wp_send_json_success(
		array(
			'views' => nxtcc_list_crm_saved_views( nxtcc_saved_views_current_tenant(), get_current_user_id() ),
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_saved_views_list', 'nxtcc_ajax_contacts_saved_views_list' );

/**
 * AJAX: create or update the current user's tenant saved view.
 *
 * @return void
 */
function nxtcc_ajax_contacts_saved_view_save(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	$filters_json = nxtcc_saved_views_post_json( 'filters_json' );
	if ( strlen( $filters_json ) > 20000 ) {
		wp_send_json_error( array( 'message' => __( 'The saved-view filter definition is too large.', 'nxt-cloud-chat' ) ), 400 );
	}

	$result = nxtcc_upsert_crm_saved_view(
		array_merge(
			nxtcc_saved_views_current_tenant(),
			array(
				'owner_user_id' => get_current_user_id(),
				'view_id'       => nxtcc_contacts_post_int( 'view_id', 0 ),
				'view_name'     => nxtcc_contacts_post_text( 'view_name', '' ),
				'filters'       => $filters_json,
				'is_default'    => nxtcc_contacts_post_int( 'is_default', 0 ),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		$error   = sanitize_key( (string) ( $result['error'] ?? '' ) );
		$message = 'saved_view_name_exists' === $error
			? __( 'A saved view with this name already exists.', 'nxt-cloud-chat' )
			: __( 'Unable to save the contact view.', 'nxt-cloud-chat' );
		wp_send_json_error(
			array(
				'message' => $message,
				'error'   => $error,
			),
			400
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contacts_saved_view_save', 'nxtcc_ajax_contacts_saved_view_save' );

/**
 * AJAX: delete the current user's tenant saved view.
 *
 * @return void
 */
function nxtcc_ajax_contacts_saved_view_delete(): void {
	nxtcc_contacts_check_nonce();
	nxtcc_verify_caps( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) );

	$result = nxtcc_delete_crm_saved_view(
		array_merge(
			nxtcc_saved_views_current_tenant(),
			array(
				'owner_user_id' => get_current_user_id(),
				'view_id'       => nxtcc_contacts_post_int( 'view_id', 0 ),
			)
		)
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unable to delete the contact view.', 'nxt-cloud-chat' ) ), 400 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_contacts_saved_view_delete', 'nxtcc_ajax_contacts_saved_view_delete' );
