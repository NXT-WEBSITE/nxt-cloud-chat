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
	if ( ! is_user_logged_in() || ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_history' ) ) ) {
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
	if ( ! is_user_logged_in() || ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_history' ) ) ) {
		wp_die( esc_html__( 'Forbidden', 'nxt-cloud-chat' ), 403 );
	}

	$nonce = nxtcc_post_field( 'nonce' );
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'nxtcc_history_nonce' ) ) {
		wp_die( esc_html__( 'Security check failed', 'nxt-cloud-chat' ), 403 );
	}
}


/**
 * Resolve the active tenant owner email for history queries.
 *
 * @return string
 */
function nxtcc_history_current_owner_mail(): string {
	$tenant = NXTCC_Access_Control::get_current_tenant_context();

	return isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '';
}

/**
 * Resolve the active tenant context for history queries.
 *
 * @return array<string,string>
 */
function nxtcc_history_current_tenant(): array {
	$tenant = NXTCC_Access_Control::get_current_tenant_context();

	return array(
		'user_mailid'         => isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '',
		'business_account_id' => isset( $tenant['business_account_id'] ) ? sanitize_text_field( (string) $tenant['business_account_id'] ) : '',
		'phone_number_id'     => isset( $tenant['phone_number_id'] ) ? sanitize_text_field( (string) $tenant['phone_number_id'] ) : '',
	);
}

/**
 * Build a human actor label for one history/broadcast row.
 *
 * @param array<string,mixed>            $row                    Row data.
 * @param array<int,array<string,mixed>> $actor_map             Resolved user map.
 * @param array<string,string>           $broadcast_actor_map Broadcast actor map.
 * @return string
 */
function nxtcc_history_created_by_label( array $row, array $actor_map, array $broadcast_actor_map ): string {
	$origin_type    = isset( $row['origin_type'] ) ? sanitize_key( (string) $row['origin_type'] ) : '';
	$origin_user_id = isset( $row['origin_user_id'] ) ? (int) $row['origin_user_id'] : 0;
	$broadcast_id   = isset( $row['broadcast_id'] ) ? sanitize_text_field( (string) $row['broadcast_id'] ) : '';
	$origin_name    = isset( $row['origin_user_name'] ) ? sanitize_text_field( (string) $row['origin_user_name'] ) : '';
	$origin_email   = isset( $row['origin_user_email'] ) ? sanitize_email( (string) $row['origin_user_email'] ) : '';

	if ( '' !== $origin_name || '' !== $origin_email ) {
		if ( class_exists( 'NXTCC_Actor_Audit' ) ) {
			$label = NXTCC_Actor_Audit::format_user_label( $origin_name, $origin_email, '' );
		} else {
			$label = $origin_email;
		}

		if ( '' !== $label ) {
			return $label;
		}
	}

	if ( $origin_user_id > 0 && class_exists( 'NXTCC_Actor_Audit' ) ) {
		$label = NXTCC_Actor_Audit::label_for_user_id( $origin_user_id, $actor_map, '' );
		if ( '' !== $label ) {
			return $label;
		}
	}

	if ( '' !== $broadcast_id && isset( $broadcast_actor_map[ $broadcast_id ] ) ) {
		$label = sanitize_text_field( (string) $broadcast_actor_map[ $broadcast_id ] );
		if ( '' !== $label ) {
			return $label;
		}
	}

	if ( 'inbound' === $origin_type ) {
		return __( 'Customer', 'nxt-cloud-chat' );
	}

	if ( 'broadcast' === $origin_type || ( '' !== $broadcast_id && 'queue' === (string) ( $row['source'] ?? '' ) ) ) {
		return __( 'Broadcast', 'nxt-cloud-chat' );
	}

	if ( 'workflow' === $origin_type ) {
		return __( 'Workflow', 'nxt-cloud-chat' );
	}

	if ( 'system' === $origin_type ) {
		return __( 'System', 'nxt-cloud-chat' );
	}

	if ( 'chat_user' === $origin_type ) {
		return '';
	}

	return '';
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
		'message_type'    => sanitize_key( (string) ( $in['message_type'] ?? '' ) ),
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
	foreach ( array( 'message_type', 'status_any', 'status', 'phone_number_id', 'sort', 'search', 'from', 'to' ) as $key ) {
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

	$tenant    = nxtcc_history_current_tenant();
	$user_mail = $tenant['user_mailid'];

	$h_order = ( 'oldest' === $filters['sort'] ) ? 'ASC' : 'DESC';
	$q_order = $h_order;

	$repo          = NXTCC_History_Repo::instance();
	$rows          = $repo->fetch_list( $user_mail, $filters, $limit, $offset, $h_order, $q_order );
	$actor_ids     = array();
	$broadcast_ids = array();

	foreach ( $rows as $row ) {
		if ( ! empty( $row['origin_user_id'] ) ) {
			$actor_ids[] = (int) $row['origin_user_id'];
		}

		if ( ! empty( $row['broadcast_id'] ) ) {
			$broadcast_ids[] = (string) $row['broadcast_id'];
		}
	}

	$broadcast_actor_map = $repo->broadcast_creator_map( $tenant, $broadcast_ids );
	$actor_map           = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::get_user_map( $actor_ids ) : array();

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
			'broadcast_id'   => ! empty( $row['broadcast_id'] ) ? (string) $row['broadcast_id'] : '',
			'message_type'   => ( ! empty( $row['broadcast_id'] ) || ! empty( $row['queue_id'] ) ) ? 'broadcast' : 'individual',
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
			'created_by'     => nxtcc_history_created_by_label( $row, $actor_map, $broadcast_actor_map ),
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

	$tenant    = nxtcc_history_current_tenant();
	$user_mail = $tenant['user_mailid'];

	$repo = NXTCC_History_Repo::instance();
	$row  = $repo->fetch_one( $user_mail, $id, $source );

	if ( ! $row ) {
		wp_send_json_error( array( 'message' => 'Not found' ), 404 );
	}

	$actor_ids = array();

	if ( ! empty( $row['origin_user_id'] ) ) {
		$actor_ids[] = (int) $row['origin_user_id'];
	}

	$broadcast_ids       = ! empty( $row['broadcast_id'] ) ? array( (string) $row['broadcast_id'] ) : array();
	$broadcast_actor_map = $repo->broadcast_creator_map( $tenant, $broadcast_ids );
	$actor_map           = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::get_user_map( $actor_ids ) : array();

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
		'broadcast_id'   => ! empty( $row['broadcast_id'] ) ? (string) $row['broadcast_id'] : '',
		'message_type'   => ( ! empty( $row['broadcast_id'] ) || ! empty( $row['queue_id'] ) ) ? 'broadcast' : 'individual',
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
		'created_by'     => nxtcc_history_created_by_label( $row, $actor_map, $broadcast_actor_map ),
		'meta_id'        => ! empty( $row['meta_message_id'] ) ? (string) $row['meta_message_id'] : '',
		'last_error'     => ! empty( $row['last_error'] ) ? (string) $row['last_error'] : '',
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

	$tenant    = nxtcc_history_current_tenant();
	$user_mail = $tenant['user_mailid'];

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
	$tenant    = nxtcc_history_current_tenant();
	$user_mail = $tenant['user_mailid'];

	$h_order = ( 'oldest' === $filters['sort'] ) ? 'ASC' : 'DESC';
	$q_order = $h_order;

	$repo          = NXTCC_History_Repo::instance();
	$rows          = $repo->export_rows( $user_mail, $filters, $h_order, $q_order );
	$actor_ids     = array();
	$broadcast_ids = array();

	foreach ( $rows as $row ) {
		if ( ! empty( $row['origin_user_id'] ) ) {
			$actor_ids[] = (int) $row['origin_user_id'];
		}

		if ( ! empty( $row['broadcast_id'] ) ) {
			$broadcast_ids[] = (string) $row['broadcast_id'];
		}
	}

	$broadcast_actor_map = $repo->broadcast_creator_map( $tenant, $broadcast_ids );
	$actor_map           = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::get_user_map( $actor_ids ) : array();

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
				nxtcc_hist_excel_safe( ! empty( $row['status'] ) ? (string) $row['status'] : '' ),
				nxtcc_hist_site_time_12( $row['sent_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['delivered_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['read_at'] ?? '' ),
				nxtcc_hist_site_time_12( $row['scheduled_at'] ?? '' ),
				nxtcc_hist_site_time_12( $created_at ),
				nxtcc_hist_excel_safe( nxtcc_history_created_by_label( $row, $actor_map, $broadcast_actor_map ) ),
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
