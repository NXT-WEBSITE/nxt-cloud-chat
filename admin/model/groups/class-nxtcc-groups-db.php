<?php
/**
 * Groups database layer.
 *
 * This is the only layer allowed to touch $wpdb. All reads are cached with
 * wp_cache_* and writes invalidate relevant caches and bump the tenant list version.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access layer for Groups.
 *
 * Only this class is allowed to interact with $wpdb for the Groups module.
 * All reads are cached and writes bump a tenant-scoped "list version" to invalidate
 * list caches.
 *
 * Tenant scope:
 * - user_mailid (owner)
 * - business_account_id
 * - phone_number_id
 */
final class NXTCC_Groups_DB {

	/**
	 * Cache group for this module.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'nxtcc_groups';

	/**
	 * Cache TTL in seconds (documentation only).
	 *
	 * Note: Some PHPCS sniffs cannot statically evaluate constants passed into
	 * wp_cache_set(), so we use a literal integer in wp_cache_set() calls.
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $inst = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function i(): self {
		if ( null === self::$inst ) {
			self::$inst = new self();
		}
		return self::$inst;
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Get wpdb.
	 *
	 * @return wpdb
	 */
	private function db(): wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Build a fully-qualified table name.
	 *
	 * @param string $suffix Table suffix without prefix.
	 * @return string
	 */
	private function table( string $suffix ): string {
		$wpdb = $this->db();
		return $wpdb->prefix . $suffix;
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = $table;
		}
		return '`' . $clean . '`';
	}

	/**
	 * Build a stable tenant key for caching.
	 *
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return string
	 */
	private function tenant_key( string $owner, string $baid, string $pnid ): string {
		return md5( strtolower( $owner ) . '|' . $baid . '|' . $pnid );
	}

	/**
	 * Build list-version cache key for a tenant.
	 *
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return string
	 */
	private function lver_key( string $owner, string $baid, string $pnid ): string {
		return 'lver:' . $this->tenant_key( $owner, $baid, $pnid );
	}

	/**
	 * Get tenant list version.
	 *
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return int
	 */
	public function lver_get( string $owner, string $baid, string $pnid ): int {
		$hit = wp_cache_get( $this->lver_key( $owner, $baid, $pnid ), self::CACHE_GROUP );
		if ( false === $hit ) {
			return 1;
		}
		return (int) $hit;
	}

	/**
	 * Bump tenant list version to invalidate list caches.
	 *
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function lver_bump( string $owner, string $baid, string $pnid ): void {
		$new = $this->lver_get( $owner, $baid, $pnid ) + 1;

		// Use literal TTL to satisfy PHPCS sniffs that can't infer constant value.
		wp_cache_set( $this->lver_key( $owner, $baid, $pnid ), $new, self::CACHE_GROUP, 300 );
	}

	/**
	 * Fetch and cache a list result.
	 *
	 * @param string   $ckey   Cache key.
	 * @param callable $runner Runner callback.
	 * @return array
	 */
	private function cache_results( string $ckey, callable $runner ): array {
		$hit = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return is_array( $hit ) ? $hit : array();
		}

		$res = (array) $runner();

		// Use literal TTL to satisfy PHPCS sniffs that can't infer constant value.
		wp_cache_set( $ckey, $res, self::CACHE_GROUP, 300 );

		return $res;
	}

	/**
	 * Fetch and cache a single row.
	 *
	 * @param string   $ckey   Cache key.
	 * @param callable $runner Runner callback.
	 * @return array|null
	 */
	private function cache_row( string $ckey, callable $runner ): ?array {
		$hit = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return is_array( $hit ) ? $hit : null;
		}

		$res = $runner();
		$row = is_array( $res ) ? $res : null;

		// Use literal TTL to satisfy PHPCS sniffs that can't infer constant value.
		wp_cache_set( $ckey, $row, self::CACHE_GROUP, 300 );

		return $row;
	}

	/**
	 * Fetch and cache a column (array of values).
	 *
	 * @param string   $ckey   Cache key.
	 * @param callable $runner Runner callback.
	 * @return array
	 */
	private function cache_col( string $ckey, callable $runner ): array {
		$hit = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return is_array( $hit ) ? $hit : array();
		}

		$res = (array) $runner();

		// Use literal TTL to satisfy PHPCS sniffs that can't infer constant value.
		wp_cache_set( $ckey, $res, self::CACHE_GROUP, 300 );

		return $res;
	}

	/**
	 * Fetch and cache an integer scalar.
	 *
	 * @param string   $ckey   Cache key.
	 * @param callable $runner Runner callback.
	 * @return int
	 */
	private function cache_var( string $ckey, callable $runner ): int {
		$hit = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return (int) $hit;
		}

		$res = (int) $runner();

		// Use literal TTL to satisfy PHPCS sniffs that can't infer constant value.
		wp_cache_set( $ckey, $res, self::CACHE_GROUP, 300 );

		return $res;
	}

	/**
	 * Execute a prepared SQL statement.
	 *
	 * @param string $prepared Prepared SQL statement.
	 * @param string $mode     Mode.
	 * @param mixed  $extra    ARRAY_A for row/results modes.
	 * @return mixed
	 */
	private function run_prepared_sql( string $prepared, string $mode = 'results', $extra = ARRAY_A ) {
		$wpdb = $this->db();

		if ( '' === trim( $prepared ) ) {
			return ( 'var' === $mode ) ? 0 : ( ( 'row' === $mode ) ? null : array() );
		}

		if ( 'results' === $mode ) {
			$out = call_user_func( array( $wpdb, 'get_results' ), $prepared, $extra );
			return $out ? $out : array();
		}

		if ( 'row' === $mode ) {
			$out = call_user_func( array( $wpdb, 'get_row' ), $prepared, $extra );
			return $out ? $out : null;
		}

		if ( 'col' === $mode ) {
			$out = call_user_func( array( $wpdb, 'get_col' ), $prepared );
			return $out ? $out : array();
		}

		if ( 'var' === $mode ) {
			return (int) call_user_func( array( $wpdb, 'get_var' ), $prepared );
		}

		return call_user_func( array( $wpdb, 'query' ), $prepared );
	}

	/**
	 * Prepare SQL containing table tokens like {groups}, {group_map}, {contacts}.
	 *
	 * @param string $query     SQL query with table tokens.
	 * @param array  $table_map Token => quoted table name.
	 * @param array  $params    Placeholder values.
	 * @return string Prepared SQL or empty string on failure.
	 */
	private function prepare_with_table_tokens( string $query, array $table_map, array $params = array() ): string {
		$search  = array();
		$replace = array();

		foreach ( $table_map as $token => $table_sql ) {
			$marker = '{' . (string) $token . '}';
			if ( false === strpos( $query, $marker ) ) {
				continue;
			}

			$clean_token = preg_replace( '/[^A-Za-z0-9_]+/', '_', (string) $token );
			if ( ! is_string( $clean_token ) || '' === $clean_token ) {
				$clean_token = 'TABLE';
			}

			$sentinel = '__NXTCC_TABLE_' . strtoupper( (string) $clean_token ) . '__';
			$query    = str_replace( $marker, "'" . $sentinel . "'", $query );

			$search[]  = "'" . $sentinel . "'";
			$replace[] = (string) $table_sql;
			$search[]  = $sentinel;
			$replace[] = (string) $table_sql;
		}

		if ( empty( $params ) ) {
			$prepared = $query;
		} else {
			$prepared = call_user_func_array(
				array( $this->db(), 'prepare' ),
				array_merge( array( $query ), $params )
			);
		}

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return '';
		}

		if ( empty( $search ) ) {
			return $prepared;
		}

		return str_replace( $search, $replace, $prepared );
	}

	/**
	 * Run a prepared query using indirect calls (VIP-friendly).
	 *
	 * Modes:
	 * - results: get_results()
	 * - row:     get_row()
	 * - col:     get_col()
	 * - var:     get_var()
	 * - exec:    query()
	 *
	 * @param string $query  SQL query with placeholders.
	 * @param array  $params Placeholder params.
	 * @param string $mode   Mode.
	 * @param mixed  $extra  ARRAY_A for results/row.
	 * @param array  $table_map Token => quoted table name map.
	 * @return mixed
	 */
	private function run_prepared( string $query, array $params, string $mode = 'results', $extra = ARRAY_A, array $table_map = array() ) {
		if ( '' === trim( $query ) ) {
			return ( 'var' === $mode ) ? 0 : ( ( 'row' === $mode ) ? null : array() );
		}

		if ( ! empty( $table_map ) ) {
			$prepared = $this->prepare_with_table_tokens( $query, $table_map, $params );
		} else {
			$wpdb     = $this->db();
			$prepared = call_user_func_array(
				array( $wpdb, 'prepare' ),
				array_merge( array( $query ), $params )
			);
		}

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return ( 'var' === $mode ) ? 0 : ( ( 'row' === $mode ) ? null : array() );
		}

		return $this->run_prepared_sql( $prepared, $mode, $extra );
	}

	/**
	 * Build placeholders for an IN() list of integers.
	 *
	 * @param int[] $ids IDs.
	 * @return string Placeholders like "%d,%d,%d" or empty string.
	 */
	private function sql_in_placeholders( array $ids ): string {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return '';
		}
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * List groups for a tenant with optional search and sorting.
	 *
	 * @param string $owner  Owner identifier (user_mailid).
	 * @param string $baid   Business account ID.
	 * @param string $pnid   Phone number ID.
	 * @param string $search Search term (already sanitized by caller).
	 * @param string $col    Sort column key.
	 * @param string $dir    Sort direction (asc|desc).
	 * @return array
	 */
	public function list_groups( string $owner, string $baid, string $pnid, string $search, string $col, string $dir ): array {
		if ( '' === $owner || '' === $baid || '' === $pnid ) {
			return array();
		}

		$dir_lower = strtolower( $dir );
		$dir_safe  = ( 'desc' === $dir_lower ) ? 'DESC' : 'ASC';

		$allowed_cols = array( 'group_name', 'count', 'created_by', 'is_verified', 'subscribed_count' );
		$col_safe     = in_array( $col, $allowed_cols, true ) ? $col : 'group_name';

		$lver = $this->lver_get( $owner, $baid, $pnid );
		$ckey = 'L:' . $lver . ':' . md5( $this->tenant_key( $owner, $baid, $pnid ) . '|' . $search . '|' . $col_safe . '|' . $dir_safe );

		$wpdb       = $this->db();
		$has_search = ( '' !== $search );

		$like = '';
		if ( $has_search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$groups_table = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$map_table    = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$contacts_tbl = $this->quote_table( $this->table( 'nxtcc_contacts' ) );

		$order_col = 'g.group_name';
		if ( 'count' === $col_safe ) {
			$order_col = 'count';
		} elseif ( 'created_by' === $col_safe ) {
			$order_col = 'g.user_mailid';
		} elseif ( 'is_verified' === $col_safe ) {
			$order_col = 'g.is_verified';
		} elseif ( 'subscribed_count' === $col_safe ) {
			$order_col = 'subscribed_count';
		}

		$query = 'SELECT
				g.id,
				g.group_name,
				g.user_mailid,
				g.business_account_id,
				g.phone_number_id,
				g.is_verified,
				COUNT(m.contact_id) AS count,
				SUM(CASE WHEN c.is_subscribed = 1 THEN 1 ELSE 0 END) AS subscribed_count
			FROM {groups} AS g
			LEFT JOIN {group_map} AS m ON m.group_id = g.id
			LEFT JOIN {contacts} AS c ON c.id = m.contact_id
			WHERE g.user_mailid = %s
			  AND g.business_account_id = %s
			  AND g.phone_number_id = %s';

		$params = array( $owner, $baid, $pnid );

		if ( $has_search ) {
			$query   .= ' AND g.group_name LIKE %s';
			$params[] = $like;
		}

		$query .= " GROUP BY g.id ORDER BY {$order_col} {$dir_safe}";

		return $this->cache_results(
			$ckey,
			function () use ( $query, $params, $groups_table, $map_table, $contacts_tbl ) {
				return $this->run_prepared(
					$query,
					$params,
					'results',
					ARRAY_A,
					array(
						'groups'    => $groups_table,
						'group_map' => $map_table,
						'contacts'  => $contacts_tbl,
					)
				);
			}
		);
	}

	/**
	 * Get a group row for a specific tenant.
	 *
	 * @param int    $id    Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return array|null
	 */
	public function get_group_for_owner( int $id, string $owner, string $baid, string $pnid ): ?array {
		if ( 0 >= $id || '' === $owner || '' === $baid || '' === $pnid ) {
			return null;
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = 'SELECT * FROM {groups} WHERE id=%d AND user_mailid=%s AND business_account_id=%s AND phone_number_id=%s';
		$params = array( $id, $owner, $baid, $pnid );

		$ckey = 'G:' . $id . ':' . $this->tenant_key( $owner, $baid, $pnid );

		return $this->cache_row(
			$ckey,
			function () use ( $query, $params, $groups ) {
				return $this->run_prepared( $query, $params, 'row', ARRAY_A, array( 'groups' => $groups ) );
			}
		);
	}

	/**
	 * Get minimal group fields for permission/verification checks.
	 *
	 * @param int $id Group ID.
	 * @return object|null
	 */
	public function get_group_min( int $id ): ?object {
		if ( 0 >= $id ) {
			return null;
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = 'SELECT id,is_verified,user_mailid,business_account_id,phone_number_id FROM {groups} WHERE id=%d';
		$params = array( $id );

		$row = $this->cache_row(
			'GMIN:' . $id,
			function () use ( $query, $params, $groups ) {
				return $this->run_prepared( $query, $params, 'row', ARRAY_A, array( 'groups' => $groups ) );
			}
		);

		if ( is_array( $row ) ) {
			return (object) $row;
		}

		return null;
	}

	/**
	 * Count duplicate group name for a tenant.
	 *
	 * @param string $owner      Owner identifier (user_mailid).
	 * @param string $baid       Business account ID.
	 * @param string $pnid       Phone number ID.
	 * @param string $name       Group name.
	 * @param int    $exclude_id Optional excluded group ID.
	 * @return int
	 */
	public function count_dupe( string $owner, string $baid, string $pnid, string $name, int $exclude_id = 0 ): int {
		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$ckey   = 'DUPE:' . md5( $this->tenant_key( $owner, $baid, $pnid ) . '|' . strtolower( $name ) . '|' . $exclude_id );

		if ( 0 < $exclude_id ) {
			$query  = 'SELECT COUNT(*) FROM {groups} WHERE user_mailid=%s AND business_account_id=%s AND phone_number_id=%s AND group_name=%s AND id!=%d';
			$params = array( $owner, $baid, $pnid, $name, $exclude_id );
		} else {
			$query  = 'SELECT COUNT(*) FROM {groups} WHERE user_mailid=%s AND business_account_id=%s AND phone_number_id=%s AND group_name=%s';
			$params = array( $owner, $baid, $pnid, $name );
		}

		return $this->cache_var(
			$ckey,
			function () use ( $query, $params, $groups ) {
				return $this->run_prepared( $query, $params, 'var', ARRAY_A, array( 'groups' => $groups ) );
			}
		);
	}

	/**
	 * Get contact IDs mapped to a group.
	 *
	 * @param int $gid Group ID.
	 * @return int[]
	 */
	public function contact_ids_for_group( int $gid ): array {
		if ( 0 >= $gid ) {
			return array();
		}

		$map    = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$query  = 'SELECT DISTINCT contact_id FROM {group_map} WHERE group_id=%d';
		$params = array( $gid );

		return $this->cache_col(
			'CID:' . $gid,
			function () use ( $query, $params, $map ) {
				$ids = $this->run_prepared( $query, $params, 'col', ARRAY_A, array( 'group_map' => $map ) );
				return array_map( 'absint', $ids );
			}
		);
	}

	/**
	 * Get contact IDs mapped to any of the given group IDs.
	 *
	 * @param int[] $gids Group IDs.
	 * @return int[]
	 */
	public function contact_ids_for_groups( array $gids ): array {
		$gids = array_values( array_filter( array_map( 'absint', $gids ) ) );
		if ( empty( $gids ) ) {
			return array();
		}
		sort( $gids );

		$placeholders = $this->sql_in_placeholders( $gids );
		if ( '' === $placeholders ) {
			return array();
		}

		$map   = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$query = "SELECT DISTINCT contact_id FROM {group_map} WHERE group_id IN ({$placeholders})";

		return $this->cache_col(
			'CIDS:' . md5( implode( ',', $gids ) ),
			function () use ( $query, $gids, $map ) {
				$ids = $this->run_prepared( $query, $gids, 'col', ARRAY_A, array( 'group_map' => $map ) );
				return array_map( 'absint', $ids );
			}
		);
	}

	/**
	 * Get minimal owned group rows for a set of IDs (tenant scoped).
	 *
	 * @param int[]  $ids   Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return array
	 */
	public function owned_rows_min( array $ids, string $owner, string $baid, string $pnid ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		sort( $ids );

		$placeholders = $this->sql_in_placeholders( $ids );
		if ( '' === $placeholders ) {
			return array();
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = "SELECT id,is_verified FROM {groups}
			WHERE id IN ({$placeholders})
			  AND user_mailid=%s
			  AND business_account_id=%s
			  AND phone_number_id=%s";

		$params = array_merge( $ids, array( $owner, $baid, $pnid ) );

		return $this->cache_results(
			'OWN:' . md5( $this->tenant_key( $owner, $baid, $pnid ) . '|' . implode( ',', $ids ) ),
			function () use ( $query, $params, $groups ) {
				return $this->run_prepared( $query, $params, 'results', ARRAY_A, array( 'groups' => $groups ) );
			}
		);
	}

	/**
	 * Insert a group (tenant scoped).
	 *
	 * @param string $name        Group name.
	 * @param string $owner       Owner identifier (user_mailid).
	 * @param string $baid        Business account ID.
	 * @param string $pnid        Phone number ID.
	 * @param int    $is_verified Verified flag.
	 * @return int New group ID.
	 */
	public function insert_group( string $name, string $owner, string $baid, string $pnid, int $is_verified ): int {
		if ( '' === $name || '' === $owner || '' === $baid || '' === $pnid ) {
			return 0;
		}

		$wpdb   = $this->db();
		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = 'INSERT INTO {groups} (user_mailid, business_account_id, phone_number_id, group_name, is_verified, created_at)
			VALUES (%s,%s,%s,%s,%d,%s)';
		$params = array( $owner, $baid, $pnid, $name, ( 0 !== $is_verified ) ? 1 : 0, current_time( 'mysql', 1 ) );

		$this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'groups' => $groups ) );

		$new_id = (int) $wpdb->insert_id;
		if ( 0 < $new_id ) {
			wp_cache_delete( 'GMIN:' . $new_id, self::CACHE_GROUP );
			wp_cache_delete( 'G:' . $new_id . ':' . $this->tenant_key( $owner, $baid, $pnid ), self::CACHE_GROUP );
			$this->lver_bump( $owner, $baid, $pnid );
		}

		return $new_id;
	}

	/**
	 * Update a group name (tenant scoped).
	 *
	 * @param int    $id    Group ID.
	 * @param string $name  New name.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return bool
	 */
	public function update_group_name( int $id, string $name, string $owner, string $baid, string $pnid ): bool {
		if ( 0 >= $id || '' === $name || '' === $owner || '' === $baid || '' === $pnid ) {
			return false;
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = 'UPDATE {groups}
			SET group_name=%s
			WHERE id=%d AND user_mailid=%s AND business_account_id=%s AND phone_number_id=%s';

		$params = array( $name, $id, $owner, $baid, $pnid );

		$res = $this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'groups' => $groups ) );
		if ( false !== $res ) {
			wp_cache_delete( 'GMIN:' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'G:' . $id . ':' . $this->tenant_key( $owner, $baid, $pnid ), self::CACHE_GROUP );
			$this->lver_bump( $owner, $baid, $pnid );
			return true;
		}

		return false;
	}

	/**
	 * Delete mappings for a single group.
	 *
	 * @param int    $gid   Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_mappings_for_group( int $gid, string $owner, string $baid, string $pnid ): void {
		if ( 0 >= $gid ) {
			return;
		}

		$map    = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$query  = 'DELETE FROM {group_map} WHERE group_id=%d';
		$params = array( $gid );

		$this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'group_map' => $map ) );

		wp_cache_delete( 'CID:' . $gid, self::CACHE_GROUP );
		$this->lver_bump( $owner, $baid, $pnid );
	}

	/**
	 * Delete mappings for multiple groups.
	 *
	 * @param int[]  $gids  Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_mappings_for_groups( array $gids, string $owner, string $baid, string $pnid ): void {
		$gids = array_values( array_filter( array_map( 'absint', $gids ) ) );
		if ( empty( $gids ) ) {
			return;
		}

		$placeholders = $this->sql_in_placeholders( $gids );
		if ( '' === $placeholders ) {
			return;
		}

		$map   = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$query = "DELETE FROM {group_map} WHERE group_id IN ({$placeholders})";

		$this->run_prepared( $query, $gids, 'exec', ARRAY_A, array( 'group_map' => $map ) );

		foreach ( $gids as $gid ) {
			wp_cache_delete( 'CID:' . (int) $gid, self::CACHE_GROUP );
		}

		$this->lver_bump( $owner, $baid, $pnid );
	}

	/**
	 * Delete a single group (tenant scoped).
	 *
	 * @param int    $gid   Group ID.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return bool
	 */
	public function delete_group( int $gid, string $owner, string $baid, string $pnid ): bool {
		if ( 0 >= $gid || '' === $owner || '' === $baid || '' === $pnid ) {
			return false;
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = 'DELETE FROM {groups}
			WHERE id=%d AND user_mailid=%s AND business_account_id=%s AND phone_number_id=%s';

		$params = array( $gid, $owner, $baid, $pnid );

		$deleted = $this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'groups' => $groups ) );
		if ( false !== $deleted ) {
			wp_cache_delete( 'GMIN:' . $gid, self::CACHE_GROUP );
			wp_cache_delete( 'G:' . $gid . ':' . $this->tenant_key( $owner, $baid, $pnid ), self::CACHE_GROUP );
			$this->lver_bump( $owner, $baid, $pnid );
			return true;
		}

		return false;
	}

	/**
	 * Delete multiple groups (tenant scoped).
	 *
	 * @param int[]  $gids  Group IDs.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function delete_groups( array $gids, string $owner, string $baid, string $pnid ): void {
		$gids = array_values( array_filter( array_map( 'absint', $gids ) ) );
		if ( empty( $gids ) ) {
			return;
		}

		$placeholders = $this->sql_in_placeholders( $gids );
		if ( '' === $placeholders ) {
			return;
		}

		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = "DELETE FROM {groups}
			WHERE id IN ({$placeholders})
			  AND user_mailid=%s
			  AND business_account_id=%s
			  AND phone_number_id=%s";

		$params = array_merge( $gids, array( $owner, $baid, $pnid ) );

		$this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'groups' => $groups ) );

		foreach ( $gids as $gid ) {
			wp_cache_delete( 'GMIN:' . (int) $gid, self::CACHE_GROUP );
			wp_cache_delete( 'G:' . (int) $gid . ':' . $this->tenant_key( $owner, $baid, $pnid ), self::CACHE_GROUP );
		}

		$this->lver_bump( $owner, $baid, $pnid );
	}

	/**
	 * Recompute verification flags for contacts based on membership in verified groups.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return void
	 */
	public function recompute_contacts_verification( array $contact_ids ): void {
		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = $this->sql_in_placeholders( $contact_ids );
		if ( '' === $placeholders ) {
			return;
		}

		$contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$map      = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$groups   = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$now      = current_time( 'mysql', 1 );

		$q1 = "UPDATE {contacts} c
			SET c.is_verified=1, c.updated_at=%s
			WHERE c.id IN ({$placeholders})
			AND EXISTS (
				SELECT 1
				FROM {group_map} m
				JOIN {groups} g ON g.id=m.group_id AND g.is_verified=1
				WHERE m.contact_id=c.id
			)";

		$this->run_prepared(
			$q1,
			array_merge( array( $now ), $contact_ids ),
			'exec',
			ARRAY_A,
			array(
				'contacts'  => $contacts,
				'group_map' => $map,
				'groups'    => $groups,
			)
		);

		$q2 = "UPDATE {contacts} c
			SET c.is_verified=0, c.wp_uid=NULL, c.updated_at=%s
			WHERE c.id IN ({$placeholders})
			AND NOT EXISTS (
				SELECT 1
				FROM {group_map} m
				JOIN {groups} g ON g.id=m.group_id AND g.is_verified=1
				WHERE m.contact_id=c.id
			)";

		$this->run_prepared(
			$q2,
			array_merge( array( $now ), $contact_ids ),
			'exec',
			ARRAY_A,
			array(
				'contacts'  => $contacts,
				'group_map' => $map,
				'groups'    => $groups,
			)
		);
	}

	/**
	 * Update subscription flag for explicit contact IDs.
	 *
	 * @param int[]  $contact_ids Contact IDs.
	 * @param int    $set_to      1 to subscribe, 0 to unsubscribe.
	 * @param string $owner       Owner identifier (user_mailid).
	 * @param string $baid        Business account ID.
	 * @param string $pnid        Phone number ID.
	 * @return void
	 */
	public function update_contacts_subscription( array $contact_ids, int $set_to, string $owner, string $baid, string $pnid ): void {
		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = $this->sql_in_placeholders( $contact_ids );
		if ( '' === $placeholders ) {
			return;
		}

		$contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query    = "UPDATE {contacts}
			SET is_subscribed=%d, updated_at=%s
			WHERE id IN ({$placeholders})";

		$params = array_merge(
			array(
				( 0 !== $set_to ) ? 1 : 0,
				current_time( 'mysql', 1 ),
			),
			$contact_ids
		);

		$this->run_prepared( $query, $params, 'exec', ARRAY_A, array( 'contacts' => $contacts ) );
		$this->lver_bump( $owner, $baid, $pnid );
	}

	/**
	 * Update subscription flag for contacts inside group IDs (tenant scoped via groups table).
	 *
	 * @param int[]  $gids  Group IDs.
	 * @param int    $set_to 1 to subscribe, 0 to unsubscribe.
	 * @param string $owner Owner identifier (user_mailid).
	 * @param string $baid  Business account ID.
	 * @param string $pnid  Phone number ID.
	 * @return void
	 */
	public function update_contacts_subscription_by_groups( array $gids, int $set_to, string $owner, string $baid, string $pnid ): void {
		$gids = array_values( array_filter( array_map( 'absint', $gids ) ) );
		if ( empty( $gids ) ) {
			return;
		}

		$placeholders = $this->sql_in_placeholders( $gids );
		if ( '' === $placeholders ) {
			return;
		}

		$contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$map      = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$groups   = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$now      = current_time( 'mysql', 1 );

		$q = "UPDATE {contacts} c
			JOIN {group_map} m ON m.contact_id = c.id
			JOIN {groups} g ON g.id = m.group_id
			SET c.is_subscribed=%d, c.updated_at=%s
			WHERE m.group_id IN ({$placeholders})
			  AND g.user_mailid=%s
			  AND g.business_account_id=%s
			  AND g.phone_number_id=%s";

		$params = array_merge(
			array(
				( 0 !== $set_to ) ? 1 : 0,
				$now,
			),
			$gids,
			array( $owner, $baid, $pnid )
		);

		$this->run_prepared(
			$q,
			$params,
			'exec',
			ARRAY_A,
			array(
				'contacts'  => $contacts,
				'group_map' => $map,
				'groups'    => $groups,
			)
		);
		$this->lver_bump( $owner, $baid, $pnid );
	}
}
