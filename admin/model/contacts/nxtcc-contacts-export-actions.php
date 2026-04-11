<?php
/**
 * Contacts export AJAX actions.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect which nonce field is present for export requests.
 *
 * Some UIs send "nonce", others send "security".
 *
 * @return string
 */
function nxtcc_export_nonce_field(): string {
	$nonce = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $nonce ) {
		return 'nonce';
	}

	$nonce = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $nonce ) {
		return 'nonce';
	}

	$sec = filter_input( INPUT_GET, 'security', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $sec ) {
		return 'security';
	}

	$sec = filter_input( INPUT_POST, 'security', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $sec ) {
		return 'security';
	}

	return '';
}

/**
 * Verify AJAX nonce for export (same nonce action).
 *
 * @return void
 */
function nxtcc_export_check_nonce(): void {
	$nonce_field = nxtcc_export_nonce_field();

	if ( '' === $nonce_field ) {
		wp_send_json_error( array( 'message' => 'Missing security token.' ) );
	}

	check_ajax_referer( 'nxtcc_contacts_nonce', $nonce_field );
}

/**
 * Read selected IDs from the request.
 *
 * Accepts:
 * - ids[] array
 * - ids as comma-separated string
 *
 * @return mixed
 */
function nxtcc_export_request_ids_raw() {
	$raw = filter_input( INPUT_POST, 'ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
	if ( null !== $raw && is_array( $raw ) ) {
		return wp_unslash( $raw );
	}

	$raw = filter_input( INPUT_GET, 'ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
	if ( null !== $raw && is_array( $raw ) ) {
		return wp_unslash( $raw );
	}

	$scalar = filter_input( INPUT_POST, 'ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $scalar ) {
		return (string) wp_unslash( (string) $scalar );
	}

	$scalar = filter_input( INPUT_GET, 'ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null !== $scalar ) {
		return (string) wp_unslash( (string) $scalar );
	}

	return array();
}

/**
 * Build export rows from contacts + group map.
 *
 * @param array $contacts List of contact objects.
 * @param array $group_map contact_id => [group_ids].
 * @param array $group_names group_id => name.
 * @return array
 */
function nxtcc_contacts_export_rows( array $contacts, array $group_map, array $group_names ): array {
	$rows   = array();
	$rows[] = array(
		'id',
		'name',
		'country_code',
		'phone_number',
		'is_subscribed',
		'is_verified',
		'wp_uid',
		'user_mailid',
		'created_at',
		'updated_at',
		'groups',
		'custom_fields_json',
	);

	foreach ( $contacts as $c ) {
		if ( ! is_object( $c ) || ! isset( $c->id ) ) {
			continue;
		}

		$cid = (int) $c->id;

		$gids  = array();
		$cid_s = (string) $cid;
		$cid_i = $cid;

		if ( isset( $group_map[ $cid_i ] ) ) {
			$gids = (array) $group_map[ $cid_i ];
		} elseif ( isset( $group_map[ $cid_s ] ) ) {
			$gids = (array) $group_map[ $cid_s ];
		}

			$gnames = array();
		foreach ( $gids as $gid ) {
			$gid = (int) $gid;
			if ( isset( $group_names[ $gid ] ) ) {
				$gnames[] = (string) $group_names[ $gid ];
			}
		}

		$custom_fields = '';
		if ( isset( $c->custom_fields ) ) {
			if ( is_string( $c->custom_fields ) ) {
				$custom_fields = $c->custom_fields;
			} else {
				$custom_fields = wp_json_encode( $c->custom_fields );
			}
		} elseif ( isset( $c->custom_fields_json ) ) {
			$custom_fields = is_string( $c->custom_fields_json ) ? $c->custom_fields_json : wp_json_encode( $c->custom_fields_json );
		}

		if ( '' === $custom_fields && isset( $c->custom_fields ) ) {
			$custom_fields = (string) $c->custom_fields;
		}

			$rows[] = array(
				nxtcc_excel_safe( (string) $cid ),
				nxtcc_excel_safe( isset( $c->name ) ? (string) $c->name : '' ),
				nxtcc_excel_safe( isset( $c->country_code ) ? (string) $c->country_code : '' ),
				nxtcc_excel_safe( isset( $c->phone_number ) ? (string) $c->phone_number : '' ),
				(string) (int) ( $c->is_subscribed ?? 0 ),
				(string) (int) ( $c->is_verified ?? 0 ),
				nxtcc_excel_safe( ( '' !== (string) ( $c->wp_uid ?? '' ) ) ? (string) $c->wp_uid : '' ),
				nxtcc_excel_safe( isset( $c->user_mailid ) ? (string) $c->user_mailid : '' ),
				nxtcc_excel_safe( isset( $c->created_at ) ? (string) $c->created_at : '' ),
				nxtcc_excel_safe( isset( $c->updated_at ) ? (string) $c->updated_at : '' ),
				nxtcc_excel_safe( implode( '|', $gnames ) ),
				nxtcc_excel_safe( (string) $custom_fields ),
			);
	}

	return $rows;
}

/**
 * Export helper: output CSV to browser.
 *
 * @param string $filename File name.
 * @param array  $rows CSV rows.
 * @return void
 */
function nxtcc_contacts_export_output( string $filename, array $rows ): void {
	$filename = sanitize_file_name( $filename );
	if ( '' === $filename ) {
		$filename = 'nxtcc-contacts-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
	}

	$csv = nxtcc_csv_build( $rows );

	nocache_headers();

	if ( function_exists( 'wp_ob_end_flush_all' ) ) {
		wp_ob_end_flush_all();
	}

	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	// Output UTF-8 BOM for Excel compatibility.
	if ( 0 !== strpos( $csv, "\xEF\xBB\xBF" ) ) {
		printf( '%s', "\xEF\xBB\xBF" );
	}

	// CSV must be sent raw; escaping would corrupt the file.
	nxtcc_output_bytes( $csv );
	exit;
}

/**
 * AJAX: Export filtered (uses same filters as list).
 */
function nxtcc_ajax_contacts_export_filtered(): void {
	nxtcc_export_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	unset( $user_mailid );

	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$repo   = NXTCC_Contacts_Handler_Repo::instance();
	$filter = nxtcc_contacts_read_filters();

	$args = array_merge(
		(array) $filter,
		array(
			'baid'     => $baid,
			'pnid'     => $pnid,
			'per_page' => 0,
			'offset'   => 0,
		)
	);

	$rows = $repo->list_contacts( $args );

	$ids = array();
	foreach ( (array) $rows as $r ) {
		if ( is_object( $r ) && isset( $r->id ) ) {
			$ids[] = (int) $r->id;
		}
	}

	$group_map     = $ids ? $repo->group_map_for_contacts( $ids ) : array();
	$all_group_ids = array();

	foreach ( (array) $group_map as $gids ) {
		foreach ( (array) $gids as $gid ) {
			$all_group_ids[ (int) $gid ] = true;
		}
	}

	$group_names = $all_group_ids ? $repo->group_names_by_ids( array_keys( $all_group_ids ) ) : array();
	$out_rows    = nxtcc_contacts_export_rows( (array) $rows, (array) $group_map, (array) $group_names );

	$filename = 'nxtcc-contacts-export-filtered-' . gmdate( 'Y-m-d-His' ) . '.csv';
	nxtcc_contacts_export_output( $filename, $out_rows );
}
add_action( 'wp_ajax_nxtcc_contacts_export_filtered', 'nxtcc_ajax_contacts_export_filtered' );

/**
 * AJAX: Export selected IDs.
 *
 * Request:
 * - ids (array or comma string)
 */
function nxtcc_ajax_contacts_export_selected(): void {
	nxtcc_export_check_nonce();
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	unset( $user_mailid );

	if ( empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$ids = nxtcc_export_request_ids_raw();

	$ids = nxtcc_contacts_int_list( $ids );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No contacts selected.' ) );
	}

	$repo = NXTCC_Contacts_Handler_Repo::instance();

	$allowed = $repo->allowlist_contacts_in_tenant( $ids, $baid, $pnid );
	$allowed = nxtcc_contacts_int_list( $allowed );

	if ( empty( $allowed ) ) {
		wp_send_json_error( array( 'message' => 'No valid contacts selected.' ) );
	}

	$rows = $repo->list_contacts_by_ids( $allowed, $baid, $pnid );

	$ids2 = array();
	foreach ( (array) $rows as $r ) {
		if ( is_object( $r ) && isset( $r->id ) ) {
			$ids2[] = (int) $r->id;
		}
	}

	$group_map     = $ids2 ? $repo->group_map_for_contacts( $ids2 ) : array();
	$all_group_ids = array();

	foreach ( (array) $group_map as $gids ) {
		foreach ( (array) $gids as $gid ) {
			$all_group_ids[ (int) $gid ] = true;
		}
	}

	$group_names = $all_group_ids ? $repo->group_names_by_ids( array_keys( $all_group_ids ) ) : array();
	$out_rows    = nxtcc_contacts_export_rows( (array) $rows, (array) $group_map, (array) $group_names );

	$filename = 'nxtcc-contacts-export-selected-' . gmdate( 'Y-m-d-His' ) . '.csv';
	nxtcc_contacts_export_output( $filename, $out_rows );
}
add_action( 'wp_ajax_nxtcc_contacts_export_selected', 'nxtcc_ajax_contacts_export_selected' );
