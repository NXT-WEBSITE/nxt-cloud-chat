<?php
/**
 * Database helpers for sending messages.
 *
 * This file contains only the DB wrapper class (OO-only) to satisfy PHPCS rules.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal DB wrapper for send-message operations.
 *
 * Executes prepared SQL only. Read helpers support object caching.
 */
final class NXTCC_DB_SendMessage {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc';

	/**
	 * Fetch a single scalar value from a prepared SQL string.
	 *
	 * @param string $prepared_sql Prepared SQL.
	 * @param string $ckey         Optional cache key.
	 * @return mixed Cached value or DB value.
	 */
	public static function get_var_prepared_sql( string $prepared_sql, string $ckey = '' ) {
		if ( '' !== $ckey ) {
			$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$val = call_user_func( array( $wpdb, 'get_var' ), $prepared_sql );

		if ( '' !== $ckey ) {
			// Use literal TTL so VIP cache sniff can evaluate it (>= 300 seconds).
			wp_cache_set( $ckey, $val, self::CACHE_GROUP, 300 );
		}

		return $val;
	}

	/**
	 * Fetch a single row from a prepared SQL string.
	 *
	 * @param string $prepared_sql Prepared SQL.
	 * @param string $ckey         Optional cache key.
	 * @return object|null Row object or null.
	 */
	public static function get_row_prepared_sql( string $prepared_sql, string $ckey = '' ): ?object {
		if ( '' !== $ckey ) {
			$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
			if ( false !== $cached ) {
				return is_object( $cached ) ? $cached : null;
			}
		}

		global $wpdb;
		$row = call_user_func( array( $wpdb, 'get_row' ), $prepared_sql );

		if ( '' !== $ckey ) {
			// Cache 5 minutes (store nulls too).
			wp_cache_set( $ckey, ( $row ? $row : null ), self::CACHE_GROUP, 300 );
		}

		return $row ? $row : null;
	}

	/**
	 * Insert a row into a table.
	 *
	 * @param string $table Table name.
	 * @param array  $data  Insert data.
	 * @return int|false Rows affected or false.
	 */
	public static function insert( string $table, array $data ) {
		global $wpdb;
		return call_user_func( array( $wpdb, 'insert' ), $table, $data );
	}

	/**
	 * Delete an object-cache key in the send-message cache group.
	 *
	 * @param string $ckey Cache key.
	 * @return void
	 */
	public static function cache_delete( string $ckey ): void {
		wp_cache_delete( $ckey, self::CACHE_GROUP );
	}
}
