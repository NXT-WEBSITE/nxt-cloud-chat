<?php
/**
 * Tenant access DAO.
 *
 * Stores WordPress user access assignments for one tenant while keeping the
 * underlying business data tenant-scoped.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access for tenant user access rows.
 */
final class NXTCC_Tenant_Access_DAO {

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'nxtcc_access';

	/**
	 * Fully qualified table name.
	 *
	 * @var string
	 */
	private static string $table = '';

	/**
	 * Initialise the table name.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::$table = NXTCC_DB_AdminSettings::prefix() . 'nxtcc_tenant_user_access';
	}

	/**
	 * Ensure the DAO is booted.
	 *
	 * @return void
	 */
	private static function ensure_booted(): void {
		if ( '' === self::$table ) {
			self::boot();
		}
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private static function quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );

		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array $tenant Tenant data.
	 * @return array<string,string>
	 */
	private static function normalize_tenant( array $tenant ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Normalize a capabilities list.
	 *
	 * @param array $capabilities Raw capabilities.
	 * @return array<int,string>
	 */
	private static function normalize_capabilities( array $capabilities ): array {
		$clean = array();

		foreach ( $capabilities as $capability ) {
			$capability = sanitize_key( (string) $capability );

			if ( '' !== $capability ) {
				$clean[] = $capability;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean, SORT_STRING );

		return $clean;
	}

	/**
	 * Cache key for one user + tenant lookup.
	 *
	 * @param int   $user_id User ID.
	 * @param array $tenant  Tenant tuple.
	 * @return string
	 */
	private static function cache_key_for_user( int $user_id, array $tenant ): string {
		return 'user:' . $user_id . ':' . md5( implode( '|', $tenant ) );
	}

	/**
	 * Cache key for all rows in one tenant.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return string
	 */
	private static function cache_key_for_tenant( array $tenant ): string {
		return 'tenant:' . md5( implode( '|', $tenant ) );
	}

	/**
	 * Convert a DB row into a normalized access row.
	 *
	 * @param array $row Database row.
	 * @return array<string,mixed>
	 */
	private static function map_row( array $row ): array {
		$capabilities = array();
		$decoded      = json_decode( (string) ( $row['capabilities_json'] ?? '' ), true );

		if ( is_array( $decoded ) ) {
			$capabilities = self::normalize_capabilities( $decoded );
		}

		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'wp_user_id'          => (int) ( $row['wp_user_id'] ?? 0 ),
			'user_mailid'         => sanitize_email( (string) ( $row['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $row['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $row['phone_number_id'] ?? '' ) ),
			'role_key'            => sanitize_key( (string) ( $row['role_key'] ?? 'custom' ) ),
			'capabilities'        => $capabilities,
			'is_owner'            => ! empty( $row['is_owner'] ),
			'granted_by'          => (int) ( $row['granted_by'] ?? 0 ),
			'updated_by'          => (int) ( $row['updated_by'] ?? 0 ),
			'created_at'          => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'updated_at'          => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
		);
	}

	/**
	 * Clear cached rows for a tenant and optional user IDs.
	 *
	 * @param array $tenant   Tenant tuple.
	 * @param array $user_ids User IDs to clear.
	 * @return void
	 */
	private static function flush_cache( array $tenant, array $user_ids = array() ): void {
		$tenant = self::normalize_tenant( $tenant );
		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return;
		}

		wp_cache_delete( self::cache_key_for_tenant( $tenant ), self::CACHE_GROUP );

		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			if ( $user_id > 0 ) {
				wp_cache_delete( self::cache_key_for_user( $user_id, $tenant ), self::CACHE_GROUP );
			}
		}
	}

	/**
	 * Get one access row for a user within a tenant.
	 *
	 * @param int   $user_id User ID.
	 * @param array $tenant  Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public static function get_user_access( int $user_id, array $tenant ): ?array {
		self::ensure_booted();

		$tenant = self::normalize_tenant( $tenant );
		if ( $user_id <= 0 || '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return null;
		}

		$cache_key = self::cache_key_for_user( $user_id, $tenant );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$table_sql = self::quote_table_name( self::$table );
		$row       = NXTCC_DB_AdminSettings::get_row_prepared_query(
			'SELECT *
			   FROM ' . $table_sql . '
			  WHERE wp_user_id = %d
			    AND user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
			  LIMIT 1',
			array(
				$user_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			),
			'',
			ARRAY_A
		);

		$mapped = is_array( $row ) ? self::map_row( $row ) : null;
		wp_cache_set( $cache_key, $mapped, self::CACHE_GROUP, 300 );

		return $mapped;
	}

	/**
	 * Get all access rows for a tenant.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_tenant_access_rows( array $tenant ): array {
		self::ensure_booted();

		$tenant = self::normalize_tenant( $tenant );
		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return array();
		}

		$cache_key = self::cache_key_for_tenant( $tenant );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$table_sql = self::quote_table_name( self::$table );
		$rows      = NXTCC_DB_AdminSettings::get_results_prepared_query(
			'SELECT *
			   FROM ' . $table_sql . '
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
			  ORDER BY is_owner DESC, wp_user_id ASC',
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			),
			''
		);

		$mapped = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$mapped[] = self::map_row( $row );
				}
			}
		}

		wp_cache_set( $cache_key, $mapped, self::CACHE_GROUP, 300 );

		return $mapped;
	}

	/**
	 * Check whether a tenant already has an owner row.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	public static function tenant_has_owner( array $tenant ): bool {
		$rows = self::get_tenant_access_rows( $tenant );

		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_owner'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Insert or update one access row.
	 *
	 * @param int    $user_id      User ID.
	 * @param array  $tenant       Tenant tuple.
	 * @param array  $capabilities Capabilities.
	 * @param int    $granted_by   Acting user ID.
	 * @param bool   $is_owner     Whether this row is the tenant owner.
	 * @param string $role_key     Role/preset key.
	 * @return bool
	 */
	public static function upsert_access( int $user_id, array $tenant, array $capabilities, int $granted_by = 0, bool $is_owner = false, string $role_key = 'custom' ): bool {
		self::ensure_booted();

		$tenant = self::normalize_tenant( $tenant );
		if ( $user_id <= 0 || '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$capabilities = self::normalize_capabilities( $capabilities );
		$now_utc      = current_time( 'mysql', 1 );
		$data         = array(
			'role_key'          => '' !== sanitize_key( $role_key ) ? sanitize_key( $role_key ) : 'custom',
			'capabilities_json' => wp_json_encode( array_values( $capabilities ) ),
			'is_owner'          => $is_owner ? 1 : 0,
			'granted_by'        => $granted_by > 0 ? $granted_by : null,
			'updated_by'        => $granted_by > 0 ? $granted_by : null,
			'updated_at'        => $now_utc,
		);

		$existing = self::get_user_access( $user_id, $tenant );
		$ok       = false;

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$ok = false !== NXTCC_DB_AdminSettings::update(
				self::$table,
				$data,
				array(
					'id' => (int) $existing['id'],
				)
			);
		} else {
			$data['wp_user_id']          = $user_id;
			$data['user_mailid']         = $tenant['user_mailid'];
			$data['business_account_id'] = $tenant['business_account_id'];
			$data['phone_number_id']     = $tenant['phone_number_id'];
			$data['created_at']          = $now_utc;
			$ok                          = false !== NXTCC_DB_AdminSettings::insert( self::$table, $data );
		}

		if ( $ok ) {
			self::flush_cache( $tenant, array( $user_id ) );
		}

		return (bool) $ok;
	}

	/**
	 * Ensure one user is the owner for a tenant.
	 *
	 * @param int   $user_id      Owner user ID.
	 * @param array $tenant       Tenant tuple.
	 * @param array $capabilities All granted capabilities.
	 * @return bool
	 */
	public static function ensure_owner_access( int $user_id, array $tenant, array $capabilities ): bool {
		self::ensure_booted();

		$tenant = self::normalize_tenant( $tenant );
		if ( $user_id <= 0 || '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$rows = self::get_tenant_access_rows( $tenant );

		$table_sql = self::quote_table_name( self::$table );
		NXTCC_DB_AdminSettings::query_prepared(
			'UPDATE ' . $table_sql . '
			    SET is_owner = %d
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s',
			array(
				0,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			)
		);

		$ok = self::upsert_access( $user_id, $tenant, $capabilities, $user_id, true, 'owner' );

		$user_ids = array( $user_id );
		foreach ( $rows as $row ) {
			$user_ids[] = (int) $row['wp_user_id'];
		}
		self::flush_cache( $tenant, $user_ids );

		return $ok;
	}

	/**
	 * Delete one non-owner access row.
	 *
	 * @param int   $user_id User ID.
	 * @param array $tenant  Tenant tuple.
	 * @return bool
	 */
	public static function delete_access( int $user_id, array $tenant ): bool {
		self::ensure_booted();

		$tenant = self::normalize_tenant( $tenant );
		if ( $user_id <= 0 || '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$row = self::get_user_access( $user_id, $tenant );
		if ( ! is_array( $row ) || ! empty( $row['is_owner'] ) ) {
			return false;
		}

		$table_sql = self::quote_table_name( self::$table );
		$deleted   = NXTCC_DB_AdminSettings::query_prepared(
			'DELETE FROM ' . $table_sql . '
			  WHERE wp_user_id = %d
			    AND user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s',
			array(
				$user_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			)
		);

		if ( false !== $deleted && 0 < (int) $deleted ) {
			self::flush_cache( $tenant, array( $user_id ) );
			return true;
		}

		return false;
	}

	/**
	 * Move all access rows from one tenant tuple to another.
	 *
	 * This is used when the site's single active tenant changes identifiers.
	 *
	 * @param array $from Old tenant tuple.
	 * @param array $to   New tenant tuple.
	 * @return bool
	 */
	public static function replace_tenant_context( array $from, array $to ): bool {
		self::ensure_booted();

		$from = self::normalize_tenant( $from );
		$to   = self::normalize_tenant( $to );

		if ( '' === $from['user_mailid'] || '' === $from['business_account_id'] || '' === $from['phone_number_id'] ) {
			return true;
		}

		if ( '' === $to['user_mailid'] || '' === $to['business_account_id'] || '' === $to['phone_number_id'] ) {
			return false;
		}

		if ( $from === $to ) {
			return true;
		}

		$rows = self::get_tenant_access_rows( $from );
		if ( empty( $rows ) ) {
			return true;
		}

		$table_sql = self::quote_table_name( self::$table );
		$result    = NXTCC_DB_AdminSettings::query_prepared(
			'UPDATE ' . $table_sql . '
			    SET user_mailid = %s,
			        business_account_id = %s,
			        phone_number_id = %s,
			        updated_at = %s
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s',
			array(
				$to['user_mailid'],
				$to['business_account_id'],
				$to['phone_number_id'],
				current_time( 'mysql', 1 ),
				$from['user_mailid'],
				$from['business_account_id'],
				$from['phone_number_id'],
			)
		);

		$user_ids = array();
		foreach ( $rows as $row ) {
			$user_ids[] = (int) $row['wp_user_id'];
		}

		self::flush_cache( $from, $user_ids );
		self::flush_cache( $to, $user_ids );

		return false !== $result;
	}
}

NXTCC_Tenant_Access_DAO::boot();
