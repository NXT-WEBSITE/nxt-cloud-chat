<?php
/**
 * Tenant access teams.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores editable tenant defaults used by Team Access.
 */
final class NXTCC_Access_Teams {

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_access_teams';

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array $tenant Tenant tuple.
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
	 * Whether a tenant tuple is complete.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private static function tenant_ready( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Quote the controlled table name.
	 *
	 * @return string
	 */
	private static function table_sql(): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', NXTCC_DB_AdminSettings::prefix() . 'nxtcc_access_teams' );
		return '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	}

	/**
	 * Return the raw table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		return NXTCC_DB_AdminSettings::prefix() . 'nxtcc_access_teams';
	}

	/**
	 * Normalize an action level.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_action_level( string $value ): string {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'view_only', 'manage' ), true ) ? $value : 'manage';
	}

	/**
	 * Normalize a data scope.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_data_scope( string $value ): string {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'assigned', 'team', 'all' ), true ) ? $value : 'all';
	}

	/**
	 * Normalize per-capability data scopes.
	 *
	 * @param array  $scopes Raw scope map keyed by capability.
	 * @param array  $capabilities Optional allowed capability keys.
	 * @param string $fallback Default scope for selected capabilities without a row scope.
	 * @return array<string,string>
	 */
	public static function sanitize_capability_scopes( array $scopes, array $capabilities = array(), string $fallback = 'all' ): array {
		$fallback     = self::sanitize_data_scope( $fallback );
		$capabilities = self::normalize_capabilities( $capabilities );
		$allowed      = ! empty( $capabilities ) ? array_fill_keys( $capabilities, true ) : array();
		$clean        = array();

		foreach ( $scopes as $capability => $scope ) {
			$capability = sanitize_key( (string) $capability );

			if ( '' === $capability ) {
				continue;
			}

			if ( ! empty( $allowed ) && ! isset( $allowed[ $capability ] ) ) {
				continue;
			}

			$clean[ $capability ] = self::sanitize_data_scope( (string) $scope );
		}

		foreach ( $capabilities as $capability ) {
			if ( ! isset( $clean[ $capability ] ) ) {
				$clean[ $capability ] = $fallback;
			}
		}

		ksort( $clean, SORT_STRING );

		return $clean;
	}

	/**
	 * Normalize capability keys.
	 *
	 * @param array $capabilities Capability keys.
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
	 * Normalize an access team.
	 *
	 * @param string $team_key Access team key.
	 * @param array  $team Access team data.
	 * @return array<string,mixed>
	 */
	private static function normalize_team( string $team_key, array $team ): array {
		$capabilities = isset( $team['capabilities'] ) && is_array( $team['capabilities'] )
			? self::normalize_capabilities( $team['capabilities'] )
			: array();
		$label        = sanitize_text_field( (string) ( $team['label'] ?? $team_key ) );
		$data_scope   = self::sanitize_data_scope( (string) ( $team['data_scope'] ?? 'all' ) );
		if ( '' === $label ) {
			$label = $team_key;
		}

		return array(
			'team_key'            => sanitize_key( $team_key ),
			'label'               => $label,
			'description'         => sanitize_textarea_field( (string) ( $team['description'] ?? '' ) ),
			'action_level'        => self::sanitize_action_level( (string) ( $team['action_level'] ?? 'manage' ) ),
			'data_scope'          => $data_scope,
			'capabilities'        => $capabilities,
			'capability_scopes'   => self::sanitize_capability_scopes(
				isset( $team['capability_scopes'] ) && is_array( $team['capability_scopes'] ) ? $team['capability_scopes'] : array(),
				$capabilities,
				$data_scope
			),
			'assignment_eligible' => ! empty( $team['assignment_eligible'] ),
			'is_protected'        => ! empty( $team['is_protected'] ),
		);
	}

	/**
	 * Cache key for one tenant.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return string
	 */
	private static function cache_key( array $tenant ): string {
		return 'tenant:' . md5( implode( '|', $tenant ) );
	}

	/**
	 * Read stored access teams for a tenant.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_stored_teams( array $tenant ): array {
		$tenant = self::normalize_tenant( $tenant );
		if ( ! self::tenant_ready( $tenant ) ) {
			return array();
		}

		$cache_key = self::cache_key( $tenant );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$rows = NXTCC_DB_AdminSettings::get_results_prepared_query(
			'SELECT team_key, label, description, action_level, data_scope, capabilities_json, capability_scopes_json, assignment_eligible, is_protected
			   FROM ' . self::table_sql() . '
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
			  ORDER BY id ASC',
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			)
		);

		$teams = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['team_key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$decoded                  = json_decode( (string) ( $row['capabilities_json'] ?? '' ), true );
			$row['capabilities']      = is_array( $decoded ) ? $decoded : array();
			$scope_map                = json_decode( (string) ( $row['capability_scopes_json'] ?? '' ), true );
			$row['capability_scopes'] = is_array( $scope_map ) ? $scope_map : array();
			$teams[ $key ]            = self::normalize_team( $key, $row );
		}

		wp_cache_set( $cache_key, $teams, self::CACHE_GROUP, 300 );
		return $teams;
	}

	/**
	 * Merge tenant overrides into registered defaults.
	 *
	 * @param array                             $tenant Tenant tuple.
	 * @param array<string,array<string,mixed>> $defaults Registered defaults.
	 * @return array<string,array<string,mixed>>
	 */
	public static function merge_with_defaults( array $tenant, array $defaults ): array {
		$teams = array();

		foreach ( $defaults as $key => $team ) {
			if ( is_array( $team ) ) {
				$teams[ sanitize_key( (string) $key ) ] = self::normalize_team( (string) $key, $team );
			}
		}

		foreach ( self::get_stored_teams( $tenant ) as $key => $team ) {
			if ( isset( $teams[ $key ] ) ) {
				$teams[ $key ] = array_merge( $teams[ $key ], $team );
			} else {
				$teams[ $key ] = $team;
			}
		}

		return $teams;
	}

	/**
	 * Save an editable default access team.
	 *
	 * @param array  $tenant Tenant tuple.
	 * @param string $team_key Access team key.
	 * @param array  $team Access team data.
	 * @param int    $actor_id Acting user ID.
	 * @return bool
	 */
	public static function upsert_team( array $tenant, string $team_key, array $team, int $actor_id = 0 ): bool {
		$tenant   = self::normalize_tenant( $tenant );
		$team_key = sanitize_key( $team_key );
		if ( ! self::tenant_ready( $tenant ) || '' === $team_key || in_array( $team_key, array( 'owner', 'custom' ), true ) ) {
			return false;
		}

		$team = self::normalize_team( $team_key, $team );
		if ( empty( $team['capabilities'] ) ) {
			return false;
		}

		$now = current_time( 'mysql', 1 );
		$sql = 'INSERT INTO ' . self::table_sql() . '
			(user_mailid, business_account_id, phone_number_id, team_key, label, description, action_level, data_scope, capabilities_json, capability_scopes_json, assignment_eligible, is_protected, created_by, updated_by, created_at, updated_at)
			VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				label = VALUES(label),
				description = VALUES(description),
				action_level = VALUES(action_level),
				data_scope = VALUES(data_scope),
				capabilities_json = VALUES(capabilities_json),
				capability_scopes_json = VALUES(capability_scopes_json),
				assignment_eligible = VALUES(assignment_eligible),
				updated_by = VALUES(updated_by),
				updated_at = VALUES(updated_at)';

		$result = NXTCC_DB_AdminSettings::query_prepared(
			$sql,
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$team_key,
				$team['label'],
				$team['description'],
				$team['action_level'],
				$team['data_scope'],
				wp_json_encode( $team['capabilities'] ),
				wp_json_encode( $team['capability_scopes'] ),
				$team['assignment_eligible'] ? 1 : 0,
				1,
				$actor_id,
				$actor_id,
				$now,
				$now,
			)
		);

		if ( false !== $result ) {
			wp_cache_delete( self::cache_key( $tenant ), self::CACHE_GROUP );
			return true;
		}

		return false;
	}

	/**
	 * Delete an editable access team.
	 *
	 * @param array  $tenant Tenant tuple.
	 * @param string $team_key Access team key.
	 * @return bool
	 */
	public static function delete_team( array $tenant, string $team_key ): bool {
		$tenant   = self::normalize_tenant( $tenant );
		$team_key = sanitize_key( $team_key );

		if ( ! self::tenant_ready( $tenant ) || '' === $team_key || in_array( $team_key, array( 'owner', 'custom' ), true ) ) {
			return false;
		}

		$result = NXTCC_DB_AdminSettings::query_prepared(
			'DELETE FROM ' . self::table_sql() . '
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
			    AND team_key = %s',
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$team_key,
			)
		);

		if ( false === $result ) {
			return false;
		}

		wp_cache_delete( self::cache_key( $tenant ), self::CACHE_GROUP );

		return (int) $result > 0;
	}

	/**
	 * Move access team rows when the primary tenant identifiers change.
	 *
	 * @param array $from Previous tenant.
	 * @param array $to New tenant.
	 * @return bool
	 */
	public static function replace_tenant_context( array $from, array $to ): bool {
		$from = self::normalize_tenant( $from );
		$to   = self::normalize_tenant( $to );

		if ( ! self::tenant_ready( $from ) || ! self::tenant_ready( $to ) || $from === $to ) {
			return true;
		}

		$result = NXTCC_DB_AdminSettings::query_prepared(
			'UPDATE ' . self::table_sql() . '
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

		wp_cache_delete( self::cache_key( $from ), self::CACHE_GROUP );
		wp_cache_delete( self::cache_key( $to ), self::CACHE_GROUP );

		return false !== $result;
	}
}
