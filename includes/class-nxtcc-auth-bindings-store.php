<?php
/**
 * Auth bindings store.
 *
 * Provides read-only access to the verified WhatsApp number stored in the
 * `nxtcc_auth_bindings` table, with object caching.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data store for verified WhatsApp bindings.
 *
 * Source of truth: {$wpdb->prefix}nxtcc_auth_bindings.phone_e164 for a given user.
 * No usermeta is written for this field.
 */
final class NXTCC_Auth_Bindings_Store {

	/**
	 * Object cache group for auth-related lookups.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_auth';

	/**
	 * Cache key for bindings table existence checks.
	 *
	 * @var string
	 */
	private const TABLE_EXISTS_CACHE_KEY = 'bindings_table_exists';

	/**
	 * Cache TTL (seconds) for per-user reads.
	 *
	 * Kept as a constant for internal reference, but cache calls use a literal
	 * value because VIP sniffs cannot always evaluate class constants.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 300;

	/**
	 * Cached table name for this request.
	 *
	 * @var string|null
	 */
	private static ?string $table_name = null;

	/**
	 * Get the wpdb instance.
	 *
	 * @return wpdb Database handle.
	 */
	private static function db(): wpdb {
		/*
		 * Global database object.
		 *
		 * @var wpdb $wpdb
		 */
		global $wpdb;

		return $wpdb;
	}


	/**
	 * Get the fully-qualified bindings table name.
	 *
	 * @return string Table name.
	 */
	private static function table(): string {
		if ( null !== self::$table_name ) {
			return self::$table_name;
		}

		self::$table_name = self::db()->prefix . 'nxtcc_auth_bindings';

		return self::$table_name;
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private static function quote_table( string $table ): string {
		return '`' . str_replace( '`', '', $table ) . '`';
	}

	/**
	 * Determine whether the bindings table exists.
	 *
	 * This is cached to avoid repeating schema introspection queries.
	 *
	 * @return bool True when the table exists.
	 */
	private static function table_exists(): bool {
		$cached = wp_cache_get( self::TABLE_EXISTS_CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$db    = self::db();
		$table = self::table();

		$found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$exists = ( is_string( $found ) && $found === $table );

		// Cache existence longer than per-user reads to reduce overhead.
		wp_cache_set( self::TABLE_EXISTS_CACHE_KEY, $exists, self::CACHE_GROUP, 600 );

		return $exists;
	}

	/**
	 * Determine whether the user has a verified WhatsApp binding.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when verified.
	 */
	public static function is_user_verified( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		$cache_key = 'is_verified_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$table = self::quote_table( self::table() );
		$db    = self::db();
		$count = (int) $db->get_var(
			$db->prepare(
				'SELECT COUNT(1) FROM ' . $table . ' WHERE user_id = %d AND verified_at IS NOT NULL',
				$user_id
			)
		);

		$is_verified = ( $count > 0 );

		// Use a literal TTL for VIP sniffs.
		wp_cache_set( $cache_key, $is_verified, self::CACHE_GROUP, 300 );

		return $is_verified;
	}

	/**
	 * Get the latest verified phone number for the user in E.164 format.
	 *
	 * @param int $user_id User ID.
	 * @return string Verified E.164 number or empty string.
	 */
	public static function latest_verified_e164( int $user_id ): string {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return '';
		}

		if ( ! self::table_exists() ) {
			return '';
		}

		$cache_key = 'latest_e164_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_string( $cached ) ? $cached : '';
		}

		$table = self::quote_table( self::table() );
		$db    = self::db();
		$e164  = $db->get_var(
			$db->prepare(
				'
				SELECT phone_e164
				FROM ' . $table . '
				WHERE user_id = %d AND verified_at IS NOT NULL
				ORDER BY COALESCE(updated_at, verified_at, created_at) DESC
				LIMIT 1
			',
				$user_id
			)
		);
		$e164  = is_string( $e164 ) ? $e164 : '';

		// Use a literal TTL for VIP sniffs.
		wp_cache_set( $cache_key, $e164, self::CACHE_GROUP, 300 );

		return $e164;
	}
}
