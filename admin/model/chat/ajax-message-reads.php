<?php
/**
 * Authenticated incoming-message view endpoints.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Record observations, refresh counts, or list a message's viewers.
 *
 * @return void
 */
function nxtcc_ajax_message_views(): void {
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'nxt-cloud-chat' ) ), 401 );
	}
	$contact_id = isset( $_POST['contact_id'] ) && is_scalar( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
	$mode       = isset( $_POST['mode'] ) && is_scalar( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'counts';
	$tenant     = NXTCC_Access_Control::get_current_tenant_context();
	foreach ( array( 'business_account_id', 'phone_number_id' ) as $field ) {
		if ( ! isset( $_POST[ $field ] ) || ! is_scalar( $_POST[ $field ] ) || sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) !== (string) ( $tenant[ $field ] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'The chat account changed. Reload the inbox.', 'nxt-cloud-chat' ) ), 403 );
		}
	}
	if ( 'viewers' === $mode ) {
		$message_id = isset( $_POST['message_id'] ) && is_scalar( $_POST['message_id'] ) ? absint( wp_unslash( $_POST['message_id'] ) ) : 0;
		$offset     = isset( $_POST['offset'] ) && is_scalar( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$result     = NXTCC_Message_Reads::viewers( $contact_id, $message_id, $tenant, $offset );
	} elseif ( in_array( $mode, array( 'record', 'counts' ), true ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The service strictly validates scalar positive IDs and batch size before any query; coercion here would hide malformed input.
		$ids    = isset( $_POST['message_ids'] ) && is_array( $_POST['message_ids'] ) ? wp_unslash( $_POST['message_ids'] ) : array();
		$result = 'record' === $mode
			? NXTCC_Message_Reads::record( $contact_id, $ids, $tenant )
			: NXTCC_Message_Reads::summaries( $contact_id, $ids, $tenant );
	} else {
		$result = new WP_Error( 'message_views_invalid', __( 'Invalid request.', 'nxt-cloud-chat' ) );
	}
	if ( is_wp_error( $result ) ) {
		$status = 'message_views_forbidden' === $result->get_error_code() ? 403 : ( 'message_views_not_found' === $result->get_error_code() ? 404 : 400 );
		if ( 'message_views_save_failed' === $result->get_error_code() ) {
			$status = 503;
		}
		wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nxtcc_message_views', 'nxtcc_ajax_message_views' );
