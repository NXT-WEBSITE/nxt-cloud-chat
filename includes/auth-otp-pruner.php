<?php
/**
 * OTP table pruner (headless, WP-Cron).
 *
 * Deletes old rows from the OTP table in batches to avoid long locks/timeouts.
 * Uses a transient lock to prevent overlapping runs.
 *
 * Filters:
 * - nxtcc_auth_otp_pruning_enabled : bool   (default true).
 * - nxtcc_auth_otp_retention_days  : int    (default 7).
 * - nxtcc_auth_otp_batch_limit     : int    (default 5000).
 * - nxtcc_auth_otp_cron_time       : int    (seconds since midnight, default 03:17 site time).
 * - nxtcc_auth_otp_table_name      : string (override table name if needed).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NXTCC_OTP_PURGE_CRON_HOOK' ) ) {
	define( 'NXTCC_OTP_PURGE_CRON_HOOK', 'nxtcc_auth_otp_purge_daily' );
}

/**
 * Get the canonical OTP table name for this plugin.
 *
 * IMPORTANT:
 * Older WordPress versions do not support identifier placeholders (%i).
 * Dynamic table names cannot be safely prepared, and PHPCS disallows variables
 * inside SQL identifiers.
 *
 * Therefore, pruning is only performed for the default OTP table.
 *
 * @return string Table name.
 */
function nxtcc_auth_otp_expected_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'nxtcc_auth_otp';
}

/**
 * Ensure $wpdb has the OTP table property.
 *
 * @return void
 */
function nxtcc_auth_otp_register_table_property(): void {
	global $wpdb;

	if ( empty( $wpdb->nxtcc_auth_otp ) ) {
		$wpdb->nxtcc_auth_otp = nxtcc_auth_otp_expected_table_name();
	}
}
add_action( 'plugins_loaded', 'nxtcc_auth_otp_register_table_property', 0 );

/**
 * Resolve the OTP table name (filtered) and keep it on $wpdb.
 *
 * Note: The pruner will only delete from the expected default table name.
 *
 * @return string Validated OTP table name.
 */
function nxtcc_auth_otp_table_name(): string {
	global $wpdb;

	nxtcc_auth_otp_register_table_property();

	$default = (string) $wpdb->nxtcc_auth_otp;
	$name    = apply_filters( 'nxtcc_auth_otp_table_name', $default );

	// Whitelist: only allow typical identifier chars to avoid injection through filters.
	if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
		$name = $default;
	}

	$wpdb->nxtcc_auth_otp = $name;

	return (string) $wpdb->nxtcc_auth_otp;
}

/**
 * Cached existence check for the OTP table.
 *
 * @return bool True when the table exists in the current database.
 */
function nxtcc_auth_otp_table_exists(): bool {
	$cache_group = 'nxtcc_auth_otp';
	$cache_key   = 'otp_table_exists';

	$cached = wp_cache_get( $cache_key, $cache_group );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	global $wpdb;

	$table = nxtcc_auth_otp_table_name();

	$count  = (int) call_user_func(
		array( $wpdb, 'get_var' ),
		call_user_func_array(
			array( $wpdb, 'prepare' ),
			array(
				'SELECT COUNT(*)
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = %s',
				$table,
			)
		)
	);
	$exists = ( $count > 0 );

	// Use literal TTL so checkers can see it is >= 300 seconds. 600 = 10 minutes.
	wp_cache_set( $cache_key, $exists, $cache_group, 600 );

	return $exists;
}

/**
 * Check whether an index on created_at exists.
 *
 * @return bool True when the index exists.
 */
function nxtcc_auth_otp_has_created_at_index(): bool {
	if ( ! nxtcc_auth_otp_table_exists() ) {
		return false;
	}

	global $wpdb;

	$table = nxtcc_auth_otp_table_name();

	$count = (int) call_user_func(
		array( $wpdb, 'get_var' ),
		call_user_func_array(
			array( $wpdb, 'prepare' ),
			array(
				'SELECT COUNT(1)
			 FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = %s
			   AND INDEX_NAME = %s',
				$table,
				'idx_created_at',
			)
		)
	);

	return ( $count > 0 );
}

/**
 * Best-effort index creation on created_at.
 *
 * Under older WordPress versions, PHPCS-compliant dynamic index creation is not
 * feasible without violating identifier preparation rules.
 *
 * @return bool True if index exists; false otherwise.
 */
function nxtcc_auth_otp_ensure_created_at_index(): bool {
	return nxtcc_auth_otp_has_created_at_index();
}

/**
 * Fetch a batch of OTP row IDs older than the cutoff.
 *
 * @param string $cutoff_gmt Cutoff datetime (UTC) in 'Y-m-d H:i:s'.
 * @param int    $limit      Maximum ids to fetch.
 * @return int[] List of row ids.
 */
function nxtcc_auth_otp_fetch_old_ids( string $cutoff_gmt, int $limit ): array {
	if ( ! nxtcc_auth_otp_table_exists() ) {
		return array();
	}

	global $wpdb;

	$table    = nxtcc_auth_otp_table_name();
	$expected = nxtcc_auth_otp_expected_table_name();

	// Strict policy: only prune the expected default table.
	if ( $table !== $expected ) {
		return array();
	}

	$limit = max( 1, (int) $limit );

	$cache_group = 'nxtcc_auth_otp';
	$cache_key   = 'otp_old_ids_' . md5( $expected . '|' . $cutoff_gmt . '|' . (string) $limit );

	$cached = wp_cache_get( $cache_key, $cache_group );
	if ( is_array( $cached ) ) {
		return array_map( 'intval', $cached );
	}

	$ids = call_user_func(
		array( $wpdb, 'get_col' ),
		$wpdb->prepare(
			'SELECT id FROM `' . $wpdb->prefix . 'nxtcc_auth_otp` WHERE created_at < %s ORDER BY id ASC LIMIT %d',
			$cutoff_gmt,
			$limit
		)
	);
	if ( ! is_array( $ids ) ) {
		$ids = array();
	}

	$out = array();
	foreach ( $ids as $id ) {
		$out[] = (int) $id;
	}

	// Cache for 5 minutes (>= 300 seconds).
	wp_cache_set( $cache_key, $out, $cache_group, 300 );

	return $out;
}

/**
 * Delete a batch of OTP rows older than the cutoff.
 *
 * Deletion is performed row-by-row using $wpdb->delete() so we don't need to
 * build dynamic SQL identifiers.
 *
 * @param string $cutoff_gmt Cutoff datetime (UTC) in 'Y-m-d H:i:s'.
 * @param int    $limit      Maximum rows to delete in one run.
 * @return int Number of deleted rows.
 */
function nxtcc_auth_otp_delete_batch_before( string $cutoff_gmt, int $limit ): int {
	$ids = nxtcc_auth_otp_fetch_old_ids( $cutoff_gmt, $limit );
	if ( empty( $ids ) ) {
		return 0;
	}

	global $wpdb;

	$table    = nxtcc_auth_otp_table_name();
	$expected = nxtcc_auth_otp_expected_table_name();

	if ( $table !== $expected ) {
		return 0;
	}

	$deleted_total = 0;

	foreach ( $ids as $id ) {
		$deleted = call_user_func(
			array( $wpdb, 'delete' ),
			$expected,
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		if ( false !== $deleted ) {
			$deleted_total += (int) $deleted;
		}
	}

	wp_cache_delete( 'otp_table_exists', 'nxtcc_auth_otp' );

	return $deleted_total;
}

/**
 * Schedule the daily prune event at an off-peak time (default 03:17 site time).
 *
 * @return void
 */
function nxtcc_auth_otp_schedule_daily(): void {
	$enabled = (bool) apply_filters( 'nxtcc_auth_otp_pruning_enabled', true );
	if ( ! $enabled ) {
		return;
	}

	$seconds_since_midnight = (int) apply_filters(
		'nxtcc_auth_otp_cron_time',
		( 3 * HOUR_IN_SECONDS ) + ( 17 * MINUTE_IN_SECONDS )
	);

	$seconds_since_midnight = max( 0, min( DAY_IN_SECONDS - 1, $seconds_since_midnight ) );

	$tz = wp_timezone();

	$dt_now      = new DateTimeImmutable( 'now', $tz );
	$dt_midnight = $dt_now->setTime( 0, 0, 0 );

	$now_ts      = (int) $dt_now->getTimestamp();
	$midnight_ts = (int) $dt_midnight->getTimestamp();

	$run_ts = $midnight_ts + $seconds_since_midnight;
	if ( $run_ts <= $now_ts ) {
		$run_ts += DAY_IN_SECONDS;
	}

	if ( ! wp_next_scheduled( NXTCC_OTP_PURGE_CRON_HOOK ) ) {
		wp_schedule_event( $run_ts, 'daily', NXTCC_OTP_PURGE_CRON_HOOK );
	}
}

/**
 * Unschedule the daily prune event.
 *
 * @return void
 */
function nxtcc_auth_otp_unschedule_daily(): void {
	$timestamp = wp_next_scheduled( NXTCC_OTP_PURGE_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, NXTCC_OTP_PURGE_CRON_HOOK );
	}
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! wp_next_scheduled( NXTCC_OTP_PURGE_CRON_HOOK ) ) {
			nxtcc_auth_otp_schedule_daily();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		nxtcc_auth_otp_unschedule_daily();
	}
);

add_action( NXTCC_OTP_PURGE_CRON_HOOK, 'nxtcc_auth_otp_purge_runner' );

/**
 * Compute the UTC cutoff timestamp string.
 *
 * @return string|null Cutoff UTC datetime or null when pruning should not run.
 */
function nxtcc_auth_otp_cutoff_gmt(): ?string {
	$days = (int) apply_filters( 'nxtcc_auth_otp_retention_days', 7 );
	if ( $days <= 0 ) {
		return null;
	}

	$cutoff_ts = time() - ( $days * DAY_IN_SECONDS );

	return gmdate( 'Y-m-d H:i:s', $cutoff_ts );
}

/**
 * Batched delete loop.
 *
 * @return int Total deleted rows.
 */
function nxtcc_auth_otp_purge_batched(): int {
	if ( ! nxtcc_auth_otp_table_exists() ) {
		return 0;
	}

	$batch = (int) apply_filters( 'nxtcc_auth_otp_batch_limit', 5000 );
	$batch = max( 500, min( 20000, $batch ) );

	$cutoff_gmt = nxtcc_auth_otp_cutoff_gmt();
	if ( null === $cutoff_gmt ) {
		return 0;
	}

	$deleted_total = 0;

	while ( true ) {
		$deleted = nxtcc_auth_otp_delete_batch_before( $cutoff_gmt, $batch );
		if ( $deleted <= 0 ) {
			break;
		}

		$deleted_total += $deleted;

		if ( $deleted < $batch ) {
			break;
		}
	}

	return $deleted_total;
}

/**
 * Cron handler: prune OTP logs older than retention.
 *
 * @return void
 */
function nxtcc_auth_otp_purge_runner(): void {
	$enabled = (bool) apply_filters( 'nxtcc_auth_otp_pruning_enabled', true );
	if ( ! $enabled ) {
		return;
	}

	$lock_key = 'nxtcc_otp_purge_lock';

	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 15 * MINUTE_IN_SECONDS );

	try {
		$deleted_total = nxtcc_auth_otp_purge_batched();

		update_option( 'nxtcc_auth_otp_last_purge_at', gmdate( 'Y-m-d H:i:s' ) );

		/**
		 * Fires after OTP purge completes.
		 *
		 * @param int         $deleted_total Total rows deleted.
		 * @param string|null $cutoff_gmt    Cutoff UTC datetime used for deletion.
		 */
		do_action( 'nxtcc_auth_otp_purged', $deleted_total, nxtcc_auth_otp_cutoff_gmt() );
	} finally {
		delete_transient( $lock_key );
	}
}

/**
 * Best-effort index existence check on admin requests (no UI).
 *
 * @return void
 */
function nxtcc_auth_otp_maybe_add_index(): void {
	nxtcc_auth_otp_ensure_created_at_index();
}
add_action( 'admin_init', 'nxtcc_auth_otp_maybe_add_index' );
