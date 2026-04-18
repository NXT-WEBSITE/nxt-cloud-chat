<?php
/**
 * AJAX endpoints: bulk actions for chat messages.
 *
 * Endpoints:
 * - nxtcc_chat_toggle_favorite : toggle favorite flag for selected message ids.
 * - nxtcc_chat_soft_delete     : soft delete selected message ids.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_chat_ajax_require_caps' ) ) {

	/**
	 * Require proper capability for chat admin AJAX endpoints.
	 *
	 * Adjust capability if your plugin uses a custom capability.
	 *
	 * @return void
	 */
	function nxtcc_chat_ajax_require_caps(): void {
		if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
			wp_send_json_error(
				array( 'message' => 'Insufficient permissions.' ),
				403
			);
		}
	}
}

if ( ! function_exists( 'nxtcc_chat_int_array_from_request' ) ) {
	/**
	 * Read an array of integers from a specific POST key.
	 *
	 * Accepts either:
	 * - ids[]=1&ids[]=2
	 * - ids="1,2,3"
	 *
	 * @param string $key Key to read.
	 * @return int[] List of positive integers.
	 */
	function nxtcc_chat_int_array_from_request( string $key ): array {
		$raw = filter_input(
			INPUT_POST,
			$key,
			FILTER_SANITIZE_NUMBER_INT,
			FILTER_REQUIRE_ARRAY
		);

		if ( is_array( $raw ) ) {
			$ids = array_map(
				static function ( $v ) {
					return absint( $v );
				},
				$raw
			);
		} else {
			$raw_csv = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( null === $raw_csv || false === $raw_csv || '' === $raw_csv ) {
				return array();
			}

			$str   = sanitize_text_field( wp_unslash( (string) $raw_csv ) );
			$parts = array_map( 'trim', explode( ',', $str ) );
			$ids   = array_map( 'absint', $parts );
		}

		$ids = array_values( array_filter( $ids ) );
		$ids = array_values( array_unique( $ids ) );

		return $ids;
	}
}

/**
 * AJAX handler: Toggle favorite for selected messages.
 *
 * The handler flips the current favorite flag for each message id.
 *
 * @return void
 */
function nxtcc_ajax_chat_toggle_favorite(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	// Nonce MUST be verified before reading any request input.
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid_raw = filter_input( INPUT_POST, 'phone_number_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$requested_pnid     = is_string( $requested_pnid_raw ) ? sanitize_text_field( wp_unslash( $requested_pnid_raw ) ) : '';

	list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Phone number id not found for user.' ), 400 );
	}

	$ids = nxtcc_chat_int_array_from_request( 'ids' );

	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No messages selected.' ), 400 );
	}

	$repo = nxtcc_chat_repo();

	foreach ( $ids as $id ) {
		$row = $repo->get_message_favorite_row( (int) $id, $user_mailid, $phone_number_id );

		if ( ! $row || ! isset( $row->is_favorite ) ) {
			continue;
		}

		$is_fav = (int) $row->is_favorite;

		// Pass user+pnid so repo can bust caches immediately.
		$repo->update_message_favorite(
			(int) $id,
			( 1 === $is_fav ) ? 0 : 1,
			$user_mailid,
			$phone_number_id
		);
	}

	wp_send_json_success(
		array(
			'ok'  => true,
			'ids' => $ids,
		)
	);
}
add_action( 'wp_ajax_nxtcc_chat_toggle_favorite', 'nxtcc_ajax_chat_toggle_favorite' );

/**
 * AJAX handler: Soft delete selected messages.
 *
 * Messages are not removed from DB, but deleted_at is set.
 *
 * @return void
 */
function nxtcc_ajax_chat_soft_delete(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	nxtcc_chat_ajax_require_caps();

	// Nonce MUST be verified before reading any request input.
	check_ajax_referer( 'nxtcc_received_messages', 'nonce', true );

	$requested_pnid_raw = filter_input( INPUT_POST, 'phone_number_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$requested_pnid     = is_string( $requested_pnid_raw ) ? sanitize_text_field( wp_unslash( $requested_pnid_raw ) ) : '';

	list( $user_mailid, $phone_number_id ) = nxtcc_chat_resolve_user_and_pnid( $requested_pnid );

	if ( '' === $user_mailid || '' === $phone_number_id ) {
		wp_send_json_error( array( 'message' => 'Phone number id not found for user.' ), 400 );
	}

	$ids = nxtcc_chat_int_array_from_request( 'ids' );

	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No messages selected.' ), 400 );
	}

	$now  = current_time( 'mysql', true );
	$repo = nxtcc_chat_repo();

	$repo->soft_delete_messages( $ids, $user_mailid, $phone_number_id, $now );

	wp_send_json_success(
		array(
			'ok'      => true,
			'deleted' => $ids,
		)
	);
}
add_action( 'wp_ajax_nxtcc_chat_soft_delete', 'nxtcc_ajax_chat_soft_delete' );
