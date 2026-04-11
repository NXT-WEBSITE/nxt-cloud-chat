<?php
/**
 * Database wrapper for admin settings.
 *
 * Provides minimal helper methods around wpdb reads/writes, with optional
 * object caching for read helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal DB wrapper used by the settings DAO.
 */
final class NXTCC_DB_AdminSettings {

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'nxtcc_settings';

	/**
	 * Get wpdb prefix safely.
	 *
	 * @return string Table prefix.
	 */
	public static function prefix(): string {
		return isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : 'wp_';
	}

	/**
	 * Execute a SQL query via get_var() with explicit wpdb::prepare() args.
	 *
	 * Note: Cache TTL is intentionally a literal (300) to satisfy VIP sniffs.
	 *
	 * @param string $query SQL with placeholders.
	 * @param array  $args  Placeholder values.
	 * @param string $ckey  Optional cache key.
	 * @return mixed Cached value or DB value.
	 */
	public static function get_var_prepared_query( string $query, array $args = array(), string $ckey = '' ) {
		if ( '' !== $ckey ) {
			$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$db = $GLOBALS['wpdb'];

		if ( empty( $args ) ) {
			return null;
		}

		$value = $db->get_var(
			$db->prepare( $query, ...$args )
		);

		if ( '' !== $ckey ) {
			wp_cache_set( $ckey, $value, self::CACHE_GROUP, 300 );
		}

		return $value;
	}

	/**
	 * Execute a SQL query via get_row() with explicit wpdb::prepare() args.
	 *
	 * Note: Cache TTL is intentionally a literal (300) to satisfy VIP sniffs.
	 *
	 * @param string $query SQL with placeholders.
	 * @param array  $args  Placeholder values.
	 * @param string $ckey  Optional cache key.
	 * @return object|null Cached row or DB row.
	 */
	public static function get_row_prepared_query( string $query, array $args = array(), string $ckey = '' ) {
		if ( '' !== $ckey ) {
			$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$db = $GLOBALS['wpdb'];

		if ( empty( $args ) ) {
			return null;
		}

		$row = $db->get_row(
			$db->prepare( $query, ...$args )
		);

		if ( '' !== $ckey ) {
			wp_cache_set( $ckey, $row, self::CACHE_GROUP, 300 );
		}

		return $row;
	}

	/**
	 * Insert passthrough.
	 *
	 * @param string $table Table name (prefixed).
	 * @param array  $data  Data to insert.
	 * @return int|false Rows affected or false on error.
	 */
	public static function insert( string $table, array $data ) {
		return $GLOBALS['wpdb']->insert( $table, $data );
	}

	/**
	 * Update passthrough.
	 *
	 * @param string $table Table name (prefixed).
	 * @param array  $data  Data to update.
	 * @param array  $where Where clause.
	 * @return int|false Rows affected or false on error.
	 */
	public static function update( string $table, array $data, array $where ) {
		return $GLOBALS['wpdb']->update( $table, $data, $where );
	}

	/**
	 * Delete a cached entry by key.
	 *
	 * @param string $ckey Cache key.
	 * @return void
	 */
	public static function cache_delete( string $ckey ): void {
		wp_cache_delete( $ckey, self::CACHE_GROUP );
	}
}
