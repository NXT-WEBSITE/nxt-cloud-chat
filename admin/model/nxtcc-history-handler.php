<?php
/**
 * History AJAX handlers.
 *
 * Registers AJAX endpoints for fetching, viewing, deleting and exporting
 * merged message history + broadcast queue rows.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nxtcc-request-helpers.php';
require_once __DIR__ . '/nxtcc-csv-helpers.php';
require_once __DIR__ . '/class-nxtcc-history-repo.php';

if ( ! function_exists( 'nxtcc_post_array' ) ) {
	/**
	 * Read an array POST field (e.g. filters[search]=...).
	 *
	 * Security: requires a valid nonce before reading input.
	 *
	 * @param string $key          POST key.
	 * @param string $nonce_action Nonce action to verify.
	 * @return array Sanitized array.
	 */
	function nxtcc_post_array( string $key, string $nonce_action = 'nxtcc_history_nonce' ): array {
		$nonce_raw = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $nonce_raw ) {
			$nonce_raw = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}

		$nonce = is_string( $nonce_raw ) ? sanitize_text_field( wp_unslash( $nonce_raw ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return array();
		}

		$raw = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $k => $v ) {
			$kk = sanitize_key( (string) $k );

			if ( is_array( $v ) ) {
				$out[ $kk ] = array_map(
					static function ( $vv ) {
						return sanitize_text_field( wp_unslash( (string) $vv ) );
					},
					$v
				);
			} else {
				$out[ $kk ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}

		return $out;
	}
}

/**
 * Guard for JSON AJAX endpoints.
 *
 * Performs logged-in + capability + nonce checks and returns JSON error on failure.
 *
 * @return void
 */
function nxtcc_history_requirements_ok(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	$nonce = nxtcc_post_field( 'nonce' );
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'nxtcc_history_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
	}
}

/**
 * Guard for CSV export (cannot wp_send_json_*).
 *
 * Performs logged-in + capability + nonce checks and exits with 403 on failure.
 *
 * @return void
 */
function nxtcc_history_export_requirements_ok(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'nxt-cloud-chat' ), 403 );
	}

	$nonce = nxtcc_post_field( 'nonce' );
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'nxtcc_history_nonce' ) ) {
		wp_die( esc_html__( 'Security check failed', 'nxt-cloud-chat' ), 403 );
	}
}


/**
 * Convert a DB timestamp (UTC or unix) to site timezone in 12-hour format.
 *
 * @param mixed $timestamp Timestamp string in UTC or unix epoch.
 * @return string Formatted date/time or empty string.
 */
function nxtcc_hist_site_time_12( $timestamp ): string {
	if ( empty( $timestamp ) ) {
		return '';
	}

	try {
		$dt = is_numeric( $timestamp )
			? new DateTime( '@' . (int) $timestamp )
			: new DateTime( (string) $timestamp, new DateTimeZone( 'UTC' ) );

		$dt->setTimezone( wp_timezone() );
		return wp_date( 'Y-m-d h:i:s A', $dt->getTimestamp(), wp_timezone() );
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Make a value safe for spreadsheet exports.
 *
 * Prevents formula execution when CSV is opened in spreadsheet applications.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function nxtcc_hist_excel_safe( $value ): string {
	if ( ! is_string( $value ) ) {
		$value = (string) $value;
	}

	return preg_match( '/^[=\+\-@]/', $value ) ? "\t" . $value : $value;
}

/**
 * Parse and normalize filters.
 *
 * Expected keys:
 * - search, status_any, status, range, from, to, phone_number_id, sort
 *
 * Adds:
 * - from_ts, to_ts, deeplink
 *
 * @param mixed $in Raw filter data (array expected).
 * @return array Normalized filters.
 */
function nxtcc_history_parse_filters( $in ): array {
	$in = is_array( $in ) ? $in : array();

	$f = array(
		'search'          => sanitize_text_field( $in['search'] ?? '' ),
		'status_any'      => sanitize_text_field( $in['status_any'] ?? '' ),
		'status'          => sanitize_text_field( $in['status'] ?? '' ),
		'range'           => sanitize_text_field( $in['range'] ?? '7d' ),
		'from'            => sanitize_text_field( $in['from'] ?? '' ),
		'to'              => sanitize_text_field( $in['to'] ?? '' ),
		'phone_number_id' => sanitize_text_field( $in['phone_number_id'] ?? '' ),
		'sort'            => sanitize_text_field( $in['sort'] ?? 'newest' ),
	);

	$to_ts   = time();
	$from_ts = $to_ts - 7 * DAY_IN_SECONDS;

	if ( '30d' === $f['range'] ) {
		$from_ts = $to_ts - 30 * DAY_IN_SECONDS;
	} elseif ( 'today' === $f['range'] ) {
		$dt = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$dt->setTime( 0, 0, 0 );
		$from_ts = $dt->getTimestamp();
		$to_ts   = $from_ts + DAY_IN_SECONDS;
	} elseif ( 'custom' === $f['range'] ) {
		if ( ! empty( $f['from'] ) ) {
			$maybe_from = strtotime( $f['from'] . ' 00:00:00 UTC' );
			if ( false !== $maybe_from ) {
				$from_ts = $maybe_from;
			}
		}

		if ( ! empty( $f['to'] ) ) {
			$maybe_to = strtotime( $f['to'] . ' 23:59:59 UTC' );
			if ( false !== $maybe_to ) {
				$to_ts = $maybe_to;
			}
		}

		if ( $from_ts > $to_ts ) {
			$to_ts   = time();
			$from_ts = $to_ts - 7 * DAY_IN_SECONDS;
		}
	}

	$f['from_ts'] = (int) $from_ts;
	$f['to_ts']   = (int) $to_ts;

	$f['deeplink'] = false;
	foreach ( array( 'status_any', 'status', 'phone_number_id', 'sort', 'search', 'from', 'to' ) as $key ) {
		if ( ! empty( $f[ $key ] ) ) {
			$f['deeplink'] = true;
			break;
		}
	}

	return $f;
}


/**
 * Register AJAX handlers (unchanged action names).
 *
 * @return void
 */
function nxtcc_history_register_ajax_handlers(): void {
	add_action( 'wp_ajax_nxtcc_history_fetch', 'nxtcc_history_fetch' );
	add_action( 'wp_ajax_nxtcc_history_fetch_one', 'nxtcc_history_fetch_one' );
	add_action( 'wp_ajax_nxtcc_history_bulk_delete', 'nxtcc_history_bulk_delete' );
	add_action( 'wp_ajax_nxtcc_history_export', 'nxtcc_history_export' );
}

nxtcc_history_register_ajax_handlers();

/**
 * Fetch list (paged).
 *
 * @return void
 */
function nxtcc_history_fetch(): void {
	nxtcc_history_requirements_ok();

	// JS can submit filters as filters[search]=... (array) or as JSON string "filters".
	$filters_in = nxtcc_post_array( 'filters' );
	if ( empty( $filters_in ) ) {
		$filters_in = nxtcc_post_json_array( 'filters' );
	}

	$filters = nxtcc_history_parse_filters( $filters_in );

	$page   = max( 1, absint( nxtcc_post_int( 'page', 1 ) ) );
	$limit  = min( 200, max( 1, absint( nxtcc_post_int( 'limit', 30 ) ) ) );
	$offset = ( $page - 1 ) * $limit;

	$user      = wp_get_current_user();
	$user_mail = $user ? (string) $user->user_email : '';

	$h_order = ( 'oldest' === $filters['sort'] ) ? 'ASC' : 'DESC';
	$q_order = $h_order;

	$repo = NXTCC_History_Repo::instance();
	$rows = $repo->fetch_list( $user_mail, $filters, $limit, $offset, $h_order, $q_order );

	$rows_out = array();

	foreach ( $rows as $row ) {
		$number = '';

		if ( ! empty( $row['country_code'] ) && ! empty( $row['phone_number'] ) ) {
			$number = (string) $row['country_code'] . (string) $row['phone_number'];
		} elseif ( ! empty( $row['display_phone_number'] ) ) {
			$number = (string) $row['display_phone_number'];
		}

		$id_value     = 0;
		$source_value = 'history';

		if ( ! empty( $row['history_id'] ) ) {
			$id_value     = (int) $row['history_id'];
			$source_value = 'history';
		} elseif ( ! empty( $row['queue_id'] ) ) {
			$id_value     = (int) $row['queue_id'];
			$source_value = 'queue';
		}

		$q_created_at = ! empty( $row['q_created_at'] ) ? $row['q_created_at'] : '';
		$h_created_at = ! empty( $row['h_created_at'] ) ? $row['h_created_at'] : '';
		$created_at   = $q_created_at ? $q_created_at : $h_created_at;

		$rows_out[] = array(
			'id'             => $id_value,
			'source'         => $source_value,
			'contact_name'   => ! empty( $row['contact_name'] ) ? (string) $row['contact_name'] : '',
			'contact_number' => $number,
			'template_name'  => ! empty( $row['template_name'] ) ? (string) $row['template_name'] : '',
			'message'        => ! empty( $row['message_content'] ) ? (string) $row['message_content'] : '',
			'status'         => ! empty( $row['status'] ) ? (string) $row['status'] : '',
			'sent_at'        => nxtcc_hist_site_time_12( $row['sent_at'] ?? '' ),
			'delivered_at'   => nxtcc_hist_site_time_12( $row['delivered_at'] ?? '' ),
			'read_at'        => nxtcc_hist_site_time_12( $row['read_at'] ?? '' ),
			'scheduled_at'   => nxtcc_hist_site_time_12( $row['scheduled_at'] ?? '' ),
			'created_at'     => nxtcc_hist_site_time_12( $created_at ),
			'created_by'     => ! empty( $row['created_by'] ) ? (string) $row['created_by'] : '',
		);
	}

	wp_send_json_success( array( 'rows' => $rows_out ) );
}

/**
 * Fetch one (for modal).
 *
 * @return void
 */
function nxtcc_history_fetch_one(): void {
	nxtcc_history_requirements_ok();

	$id     = absint( nxtcc_post_int( 'id', 0 ) );
	$source = nxtcc_post_field( 'source' );

	if ( '' === $source ) {
		$source = 'history';
	}

	if ( ! in_array( $source, array( 'history', 'queue' ), true ) ) {
		$source = 'history';
	}

	if ( $id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Missing id' ), 400 );
	}

	$user      = wp_get_current_user();
	$user_mail = $user ? (string) $user->user_email : '';

	$repo = NXTCC_History_Repo::instance();
	$row  = $repo->fetch_one( $user_mail, $id, $source );

	if ( ! $row ) {
		wp_send_json_error( array( 'message' => 'Not found' ), 404 );
	}

	$number = '';
	if ( ! empty( $row['country_code'] ) && ! empty( $row['phone_number'] ) ) {
		$number = (string) $row['country_code'] . (string) $row['phone_number'];
	} elseif ( ! empty( $row['display_phone_number'] ) ) {
		$number = (string) $row['display_phone_number'];
	}

	$id_value     = 0;
	$source_value = 'history';

	if ( ! empty( $row['history_id'] ) ) {
		$id_value     = (int) $row['history_id'];
		$source_value = 'history';
	} elseif ( ! empty( $row['queue_id'] ) ) {
		$id_value     = (int) $row['queue_id'];
		$source_value = 'queue';
	}

	$q_created_at = ! empty( $row['q_created_at'] ) ? $row['q_created_at'] : '';
	$h_created_at = ! empty( $row['h_created_at'] ) ? $row['h_created_at'] : '';
	$created_at   = $q_created_at ? $q_created_at : $h_created_at;

	$row_out = array(
		'id'             => $id_value,
		'source'         => $source_value,
		'contact_name'   => ! empty( $row['contact_name'] ) ? (string) $row['contact_name'] : '',
		'contact_number' => $number,
		'template_name'  => ! empty( $row['template_name'] ) ? (string) $row['template_name'] : '',
		'message'        => ! empty( $row['message_content'] ) ? (string) $row['message_content'] : '',
		'status'         => ! empty( $row['status'] ) ? (string) $row['status'] : '',
		'sent_at'        => nxtcc_hist_site_time_12( $row['sent_at'] ?? '' ),
		'delivered_at'   => nxtcc_hist_site_time_12( $row['delivered_at'] ?? '' ),
		'read_at'        => nxtcc_hist_site_time_12( $row['read_at'] ?? '' ),
		'scheduled_at'   => nxtcc_hist_site_time_12( $row['scheduled_at'] ?? '' ),
		'created_at'     => nxtcc_hist_site_time_12( $created_at ),
		'created_by'     => ! empty( $row['created_by'] ) ? (string) $row['created_by'] : '',
		'meta_id'        => ! empty( $row['meta_message_id'] ) ? (string) $row['meta_message_id'] : '',
	);

	wp_send_json_success( array( 'row' => $row_out ) );
}

/**
 * Bulk delete (source: 'history' | 'queue' | 'both').
 *
 * @return void
 */
function nxtcc_history_bulk_delete(): void {
	nxtcc_history_requirements_ok();
	check_ajax_referer( 'nxtcc_history_nonce', 'nonce', true );

	$source = nxtcc_post_field( 'source' );
	if ( ! in_array( $source, array( 'history', 'queue', 'both' ), true ) ) {
		$source = 'history';
	}

	$ids = array();

	// Prefer ids[]=... (array). Support JSON as fallback.
	$ids_arr = filter_input( INPUT_POST, 'ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY );
	if ( is_array( $ids_arr ) ) {
		$ids = $ids_arr;
	} else {
		$ids = nxtcc_post_json_array( 'ids' );
	}

	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No IDs' ), 400 );
	}

	$user      = wp_get_current_user();
	$user_mail = $user ? (string) $user->user_email : '';

	$repo    = NXTCC_History_Repo::instance();
	$deleted = $repo->bulk_delete( $user_mail, $ids, $source );

	wp_send_json_success( array( 'deleted' => (int) $deleted ) );
}

/**
 * Export CSV.
 *
 * @return void
 */
function nxtcc_history_export(): void {
	nxtcc_history_export_requirements_ok();

	// Support both "filters[...]" and flat POST fields.
	$post = nxtcc_post_array( 'filters' );
	if ( empty( $post ) ) {
		$post = array(
			'search'          => nxtcc_post_field( 'search' ),
			'status_any'      => nxtcc_post_field( 'status_any' ),
			'status'          => nxtcc_post_field( 'status' ),
			'range'           => nxtcc_post_field( 'range' ),
			'from'            => nxtcc_post_field( 'from' ),
			'to'              => nxtcc_post_field( 'to' ),
			'phone_number_id' => nxtcc_post_field( 'phone_number_id' ),
			'sort'            => nxtcc_post_field( 'sort' ),
		);
	}

	$filters   = nxtcc_history_parse_filters( $post );
	$user      = wp_get_current_user();
	$user_mail = $user ? (string) $user->user_email : '';

	$h_order = ( 'oldest' === $filters['sort'] ) ? 'ASC' : 'DESC';
	$q_order = $h_order;

	$repo = NXTCC_History_Repo::instance();
	$rows = $repo->export_rows( $user_mail, $filters, $h_order, $q_order );

	$fname = 'nxtcc-history-merged-' . gmdate( 'Ymd-His' ) . '-' . str_replace( '/', '-', wp_timezone_string() ) . '.csv';
	$fname = sanitize_file_name( $fname );
	if ( '' === $fname ) {
		$fname = 'nxtcc-history-merged-' . gmdate( 'Ymd-His' ) . '.csv';
	}

	$lines   = array();
	$lines[] = nxtcc_csv_line(
		array(
			'ID',
			'Source',
			'Contact Name',
			'Contact Number',
			'Template Name',
			'Message',
			'Status',
			'Sent At',
			'Delivered At',
			'Read At',
			'Scheduled At',
			'Created At',
			'Created By',
		)
	);

	foreach ( $rows as $row ) {
		$number = '';

		if ( ! empty( $row['country_code'] ) && ! empty( $row['phone_number'] ) ) {
			$number = (string) $row['country_code'] . (string) $row['phone_number'];
		} elseif ( ! empty( $row['display_phone_number'] ) ) {
			$number = (string) $row['display_phone_number'];
		}

		$id_value = '';
		if ( ! empty( $row['history_id'] ) ) {
			$id_value = $row['history_id'];
		} elseif ( ! empty( $row['queue_id'] ) ) {
			$id_value = $row['queue_id'];
		}

		$source_value = ! empty( $row['source'] ) ? (string) $row['source'] : 'history';

		$q_created_at = ! empty( $row['q_created_at'] ) ? $row['q_created_at'] : '';
		$h_created_at = ! empty( $row['h_created_at'] ) ? $row['h_created_at'] : '';
		$created_at   = $q_created_at ? $q_created_at : $h_created_at;

		$lines[] = nxtcc_csv_line(
			array(
				nxtcc_hist_excel_safe( $id_value ),
				nxtcc_hist_excel_safe( $source_value ),
				nxtcc_hist_excel_safe( $row['contact_name'] ?? '' ),
				nxtcc_hist_excel_safe( $number ),
				nxtcc_hist_excel_safe( $row['template_name'] ?? '' ),
				nxtcc_hist_excel_safe( $row['message_content'] ?? '' ),
				nxtcc_hist_excel_safe( $row['status'] ?? '' ),
				nxtcc_hist_site_time_12( $row['sent_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['delivered_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['read_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['scheduled_at'] ?? '' ),
				nxtcc_hist_site_time_12( $created_at ),
				nxtcc_hist_excel_safe( $row['created_by'] ?? '' ),
			)
		);
	}

	$csv = implode( "\r\n", $lines ) . "\r\n";

	nocache_headers();

	if ( function_exists( 'wp_ob_end_flush_all' ) ) {
		wp_ob_end_flush_all();
	}

	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . rawurlencode( $fname ) . '"' );

	// Output UTF-8 BOM for Excel compatibility.
	if ( 0 !== strpos( $csv, "\xEF\xBB\xBF" ) ) {
		printf( '%s', "\xEF\xBB\xBF" );
	}

		// CSV is not HTML; escaping would corrupt commas/quotes/newlines.
	if ( function_exists( 'nxtcc_output_bytes' ) ) {
		nxtcc_output_bytes( $csv );
	} else {
		call_user_func( 'printf', '%s', $csv );
	}
		exit;
}
