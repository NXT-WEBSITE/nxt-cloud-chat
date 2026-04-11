<?php
/**
 * Contacts DB repository.
 *
 * Repository: All DB access lives here (single source of truth).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository: All DB access lives here (single source of truth).
 */
final class NXTCC_Contacts_Handler_Repo {

	/**
	 * Cache group.
	 */
	public const CACHE_GROUP = NXTCC_CONTACTS_CACHE_GROUP;

	/**
	 * Short TTL (seconds).
	 */
	public const TTL_SHORT = 60;

	/**
	 * Medium TTL (seconds).
	 */
	public const TTL_MEDIUM = 300;

	/**
	 * Long TTL (seconds).
	 */
	public const TTL_LONG = 900;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * DB prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->prefix = $wpdb->prefix;
	}

	/**
	 * Get wpdb instance.
	 *
	 * @return wpdb
	 */
	private function db(): wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Get full table name for plugin tables.
	 *
	 * @param string $name Table short name (without prefix).
	 * @return string
	 */
	private function table( string $name ): string {
		return $this->prefix . $name;
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
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Build a comma-separated placeholder list for integer IN clauses.
	 *
	 * @param array<int,mixed> $ids Integer IDs.
	 * @return string Placeholder list (e.g. "%d,%d,%d"), or empty string.
	 */
	private function int_placeholders( array $ids ): string {
		$count = count( $ids );
		if ( 0 === $count ) {
			return '';
		}

		return implode( ',', array_fill( 0, $count, '%d' ) );
	}

	/**
	 * Prepare SQL containing table tokens like {contacts}, {groups}, {settings}.
	 *
	 * @param string $query     SQL query with table tokens.
	 * @param array  $table_map Token => quoted table name.
	 * @param array  $args      Placeholder values.
	 * @return string Prepared SQL or empty string on failure.
	 */
	private function prepare_with_table_tokens( string $query, array $table_map, array $args = array() ): string {
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

		if ( empty( $args ) ) {
			$prepared = $query;
		} else {
			$prepared = $this->db()->prepare( $query, ...$args );
		}

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return '';
		}

		if ( empty( $search ) ) {
			return $prepared;
		}

		return str_replace( $search, $replace, $prepared );
	}

	// ========================= TENANT / USER SETTINGS =========================

	/**
	 * Get current tenant for a WP user.
	 *
	 * Returns a 4-item array:
	 *  - [0] string|null user email
	 *  - [1] string|null business_account_id
	 *  - [2] string|null phone_number_id
	 *  - [3] object|null raw DB row
	 *
	 * @param int $wp_user_id WP user id.
	 * @return array{0:?string,1:?string,2:?string,3:mixed}
	 */
	public function get_current_tenant_for_user( int $wp_user_id ): array {
		if ( $wp_user_id <= 0 ) {
			return array( null, null, null, null );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return array( null, null, null, null );
		}

		$email  = (string) $user->user_email;
		$ckey   = 'tenant:' . md5( $email );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db       = $this->db();
		$settings = $this->quote_table( $this->table( 'nxtcc_user_settings' ) );
		$query    = $this->prepare_with_table_tokens(
			'SELECT business_account_id, phone_number_id
			   FROM {settings}
			  WHERE user_mailid = %s
		   ORDER BY id DESC
			  LIMIT 1',
			array(
				'settings' => $settings,
			),
			array( $email )
		);
		$row      = '' !== $query ? $db->get_row( $query ) : null;

		if ( ! $row || empty( $row->business_account_id ) || empty( $row->phone_number_id ) ) {
			$out = array( $email, null, null, null );
			// Inline TTL to satisfy VIP sniff (must be >= 300).
			wp_cache_set( $ckey, $out, self::CACHE_GROUP, 300 );
			return $out;
		}

		$out = array( $email, (string) $row->business_account_id, (string) $row->phone_number_id, $row );
		// Inline TTL to satisfy VIP sniff (must be >= 300).
		wp_cache_set( $ckey, $out, self::CACHE_GROUP, 300 );

		return $out;
	}

	// ========================= GROUPS / MAPPINGS HELPERS =========================

	/**
	 * Check if any group id from the list is verified.
	 *
	 * @param array<int|string> $group_ids Group IDs.
	 * @return bool
	 */
	public function any_verified_group( array $group_ids ): bool {
		$ids = array_values( array_filter( array_map( 'intval', (array) $group_ids ) ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$db           = $this->db();
		$groups       = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT COUNT(*)
		          FROM {groups}
		         WHERE is_verified = 1
		           AND id IN ({$placeholders})",
			array(
				'groups' => $groups,
			),
			$ids
		);
		$count        = (int) ( '' !== $query ? $db->get_var( $query ) : 0 );

		return $count > 0;
	}

	/**
	 * Remove verified groups from a group id list.
	 *
	 * @param array<int|string> $group_ids Group IDs.
	 * @return array<int>
	 */
	public function strip_verified_groups( array $group_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) $group_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db           = $this->db();
		$groups       = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT id
			   FROM {groups}
			  WHERE is_verified = 0
			    AND id IN ({$placeholders})",
			array(
				'groups' => $groups,
			),
			$ids
		);
		$kept         = '' !== $query ? $db->get_col( $query ) : array();

		return array_map( 'intval', $kept ? $kept : array() );
	}

	/**
	 * Filter group IDs that are owned by a user.
	 *
	 * Note: This is *user-scoped* only (not tenant-scoped). If you need tenant scoping,
	 * add a new method with baid/pnid and use that in callers.
	 *
	 * @param string           $user_mailid User email.
	 * @param array<int|mixed> $group_ids Group IDs.
	 * @return array<int>
	 */
	public function user_owned_group_ids( string $user_mailid, array $group_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $group_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db           = $this->db();
		$groups       = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT id
		             FROM {groups}
		            WHERE id IN ({$placeholders})
		              AND user_mailid = %s",
			array(
				'groups' => $groups,
			),
			array_merge( $ids, array( $user_mailid ) )
		);
		$rows         = '' !== $query ? $db->get_col( $query ) : array();

		return array_values( array_map( 'intval', $rows ? $rows : array() ) );
	}

	/**
	 * List all groups created by a user inside a tenant.
	 *
	 * @param string $user_mailid User email.
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_user_groups( string $user_mailid, string $baid, string $pnid ): array {
		$ckey   = 'groups:' . md5( $baid . '|' . $pnid . '|' . $user_mailid );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db     = $this->db();
		$groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query  = $this->prepare_with_table_tokens(
			'SELECT id, group_name, is_verified
			   FROM {groups}
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
		   ORDER BY id DESC',
			array(
				'groups' => $groups,
			),
			array( $user_mailid, $baid, $pnid )
		);
		$rows   = '' !== $query ? $db->get_results( $query, ARRAY_A ) : array();

		$rows = is_array( $rows ) ? $rows : array();

		// Inline TTL to satisfy VIP sniff (must be >= 300).
		wp_cache_set( $ckey, $rows, self::CACHE_GROUP, 900 );

		return $rows;
	}

	/**
	 * Create a group if it does not exist for the user + tenant.
	 *
	 * @param string $user_mailid User email.
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @param string $group_name Group name.
	 * @return array<string, mixed> Group data (empty array on failure).
	 */
	public function create_group_if_absent( string $user_mailid, string $baid, string $pnid, string $group_name ): array {
		$group_name = trim( $group_name );

		if ( '' === $group_name ) {
			return array();
		}

		$db       = $this->db();
		$groups   = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$query    = $this->prepare_with_table_tokens(
			'SELECT id, group_name, is_verified
			   FROM {groups}
			  WHERE user_mailid = %s
			    AND business_account_id = %s
			    AND phone_number_id = %s
			    AND group_name = %s
			  LIMIT 1',
			array(
				'groups' => $groups,
			),
			array( $user_mailid, $baid, $pnid, $group_name )
		);
		$existing = '' !== $query ? $db->get_row( $query ) : null;

		$ckey = 'groups:' . md5( $baid . '|' . $pnid . '|' . $user_mailid );

		if ( $existing ) {
			wp_cache_delete( $ckey, self::CACHE_GROUP );

			return array(
				'id'          => (int) $existing->id,
				'group_name'  => (string) $existing->group_name,
				'is_verified' => (int) $existing->is_verified,
				'existed'     => true,
			);
		}

		$is_verified = ( 0 === strcasecmp( $group_name, 'Verified' ) ) ? 1 : 0;

		$ins = $db->insert(
			$this->table( 'nxtcc_groups' ),
			array(
				'user_mailid'         => $user_mailid,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'group_name'          => $group_name,
				'is_verified'         => $is_verified,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $ins ) {
			return array();
		}

		wp_cache_delete( $ckey, self::CACHE_GROUP );

		return array(
			'id'          => (int) $db->insert_id,
			'group_name'  => $group_name,
			'is_verified' => $is_verified,
		);
	}

	// ========================= CONTACTS QUERYING =========================

	/**
	 * Count contacts in a tenant with filters.
	 *
	 * Expected keys in $args (as used by your calling code):
	 * baid, pnid, country, name_like, created_by, subscription, created_from,
	 * created_to, search_like, group_id.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public function count_contacts( array $args ): int {
		$baid = (string) $args['baid'];
		$pnid = (string) $args['pnid'];

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$table_map      = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );

		$where  = array( 'c.business_account_id = %s', 'c.phone_number_id = %s' );
		$params = array( $baid, $pnid );

		if ( '' !== (string) $args['country'] ) {
			$where[]  = 'c.country_code = %s';
			$params[] = (string) $args['country'];
		}

		if ( '' !== (string) $args['name_like'] ) {
			$where[]  = 'c.name LIKE %s';
			$params[] = (string) $args['name_like'];
		}

		if ( '' !== (string) $args['created_by'] ) {
			$where[]  = 'c.user_mailid = %s';
			$params[] = (string) $args['created_by'];
		}

		if ( '' !== (string) $args['subscription'] ) {
			$where[]  = 'c.is_subscribed = %d';
			$params[] = (int) $args['subscription'];
		}

		if ( '' !== (string) $args['created_from'] ) {
			$where[]  = 'DATE(c.created_at) >= %s';
			$params[] = (string) $args['created_from'];
		}

		if ( '' !== (string) $args['created_to'] ) {
			$where[]  = 'DATE(c.created_at) <= %s';
			$params[] = (string) $args['created_to'];
		}

		if ( '' !== (string) $args['search_like'] ) {
			$where[]  = '(c.name LIKE %s OR c.phone_number LIKE %s)';
			$params[] = (string) $args['search_like'];
			$params[] = (string) $args['search_like'];
		}

		$sql        = 'SELECT COUNT(*) FROM {contacts} c';
		$query_args = array();
		$table_map  = array(
			'contacts'  => $table_contacts,
			'group_map' => $table_map,
		);

		if ( ! empty( $args['group_id'] ) ) {
			$sql         .= ' INNER JOIN {group_map} gm ON gm.contact_id = c.id AND gm.group_id = %d';
			$query_args[] = (int) $args['group_id'];
		}

		$sql       .= ' WHERE ' . implode( ' AND ', $where );
		$query_args = array_merge( $query_args, $params );
		$query      = $this->prepare_with_table_tokens( $sql, $table_map, $query_args );

		return (int) ( '' !== $query ? $db->get_var( $query ) : 0 );
	}

	/**
	 * List contacts in a tenant with filters.
	 *
	 * Expected keys in $args are the same as count_contacts(), plus:
	 * per_page, offset.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object>
	 */
	public function list_contacts( array $args ): array {
		$baid = (string) $args['baid'];
		$pnid = (string) $args['pnid'];

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$table_map      = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );

		$where  = array( 'c.business_account_id = %s', 'c.phone_number_id = %s' );
		$params = array( $baid, $pnid );

		if ( '' !== (string) $args['country'] ) {
			$where[]  = 'c.country_code = %s';
			$params[] = (string) $args['country'];
		}

		if ( '' !== (string) $args['name_like'] ) {
			$where[]  = 'c.name LIKE %s';
			$params[] = (string) $args['name_like'];
		}

		if ( '' !== (string) $args['created_by'] ) {
			$where[]  = 'c.user_mailid = %s';
			$params[] = (string) $args['created_by'];
		}

		if ( '' !== (string) $args['subscription'] ) {
			$where[]  = 'c.is_subscribed = %d';
			$params[] = (int) $args['subscription'];
		}

		if ( '' !== (string) $args['created_from'] ) {
			$where[]  = 'DATE(c.created_at) >= %s';
			$params[] = (string) $args['created_from'];
		}

		if ( '' !== (string) $args['created_to'] ) {
			$where[]  = 'DATE(c.created_at) <= %s';
			$params[] = (string) $args['created_to'];
		}

		if ( '' !== (string) $args['search_like'] ) {
			$where[]  = '(c.name LIKE %s OR c.phone_number LIKE %s)';
			$params[] = (string) $args['search_like'];
			$params[] = (string) $args['search_like'];
		}

		$sql        = 'SELECT c.* FROM {contacts} c';
		$query_args = array();
		$table_map  = array(
			'contacts'  => $table_contacts,
			'group_map' => $table_map,
		);

		if ( ! empty( $args['group_id'] ) ) {
			$sql         .= ' INNER JOIN {group_map} gm ON gm.contact_id = c.id AND gm.group_id = %d';
			$query_args[] = (int) $args['group_id'];
		}

		$sql .= ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY c.id DESC';

		if ( (int) $args['per_page'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['per_page'];
			$params[] = (int) $args['offset'];
		}

		$query_args = array_merge( $query_args, $params );
		$query      = $this->prepare_with_table_tokens( $sql, $table_map, $query_args );
		$rows       = '' !== $query ? $db->get_results( $query ) : array();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a map of contact_id => [group_id, ...] for given contact IDs.
	 *
	 * @param array<int|mixed> $contact_ids Contact IDs.
	 * @return array<int, array<int>>
	 */
	public function group_map_for_contacts( array $contact_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $contact_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db           = $this->db();
		$table_map    = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT contact_id, group_id FROM {group_map} WHERE contact_id IN ({$placeholders})",
			array(
				'group_map' => $table_map,
			),
			$ids
		);
		$rows         = '' !== $query ? $db->get_results( $query ) : array();

		$map = array();

		foreach ( (array) $rows as $r ) {
			$cid = (int) $r->contact_id;

			if ( ! isset( $map[ $cid ] ) ) {
				$map[ $cid ] = array();
			}

			$map[ $cid ][] = (int) $r->group_id;
		}

		return $map;
	}

	/**
	 * Get group names indexed by group ID.
	 *
	 * @param array<int|mixed> $group_ids Group IDs.
	 * @return array<int, string>
	 */
	public function group_names_by_ids( array $group_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $group_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$ckey   = 'group_names:' . md5( implode( ',', $ids ) );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db           = $this->db();
		$table_groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT id, group_name FROM {groups} WHERE id IN ({$placeholders})",
			array(
				'groups' => $table_groups,
			),
			$ids
		);
		$rows         = '' !== $query ? $db->get_results( $query ) : array();

		$out = array();

		foreach ( (array) $rows as $g ) {
			$out[ (int) $g->id ] = (string) $g->group_name;
		}

		// Inline TTL to satisfy VIP sniff (must be >= 300).
		wp_cache_set( $ckey, $out, self::CACHE_GROUP, 300 );

		return $out;
	}

	/**
	 * Get distinct creators (user_mailid) for a tenant.
	 *
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return array<int, string>
	 */
	public function creators_for_tenant( string $baid, string $pnid ): array {
		$ckey   = 'creators:' . md5( $baid . '|' . $pnid );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			'SELECT DISTINCT user_mailid
			   FROM {contacts}
			  WHERE business_account_id = %s
			    AND phone_number_id = %s
		   ORDER BY user_mailid ASC',
			array(
				'contacts' => $table_contacts,
			),
			array( $baid, $pnid )
		);
		$rows           = '' !== $query ? $db->get_col( $query ) : array();

		$rows = array_values( array_filter( $rows ? $rows : array() ) );

		// Inline TTL to satisfy VIP sniff (must be >= 300).
		wp_cache_set( $ckey, $rows, self::CACHE_GROUP, 900 );

		return $rows;
	}

	/**
	 * Get distinct country codes used by contacts in a tenant.
	 *
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return array<int, string>
	 */
	public function country_codes_for_tenant( string $baid, string $pnid ): array {
		$ckey   = 'country_codes:' . md5( $baid . '|' . $pnid );
		$cached = wp_cache_get( $ckey, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			"SELECT DISTINCT country_code
				   FROM {contacts}
				  WHERE business_account_id = %s
				    AND phone_number_id = %s
				    AND country_code != ''",
			array(
				'contacts' => $table_contacts,
			),
			array( $baid, $pnid )
		);
		$rows           = '' !== $query ? $db->get_col( $query ) : array();

		$rows = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $c ) {
							return preg_replace( '/\D/', '', (string) $c );
						},
						$rows ? $rows : array()
					)
				)
			)
		);

		// Inline TTL to satisfy VIP sniff (must be >= 300).
		wp_cache_set( $ckey, $rows, self::CACHE_GROUP, 300 );

		return $rows;
	}

	// ========================= CONTACT CRUD / RULES =========================

	/**
	 * Find a contact inside a tenant.
	 *
	 * @param int    $id Contact id.
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return object|null
	 */
	public function find_contact_in_tenant( int $id, string $baid, string $pnid ) {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			'SELECT * FROM {contacts}
			  WHERE id = %d
			    AND business_account_id = %s
			    AND phone_number_id = %s',
			array(
				'contacts' => $table_contacts,
			),
			array( $id, $baid, $pnid )
		);

		return '' !== $query ? $db->get_row( $query ) : null;
	}

	/**
	 * Get duplicate contact id (same tenant + country_code + phone_number).
	 *
	 * @param string   $baid Business account id.
	 * @param string   $pnid Phone number id.
	 * @param string   $cc Country code.
	 * @param string   $pn Phone number.
	 * @param int|null $exclude_id Optional contact id to exclude.
	 * @return int|null
	 */
	public function duplicate_contact_id( string $baid, string $pnid, string $cc, string $pn, ?int $exclude_id = null ): ?int {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$table_map      = array(
			'contacts' => $table_contacts,
		);

		if ( null !== $exclude_id && $exclude_id > 0 ) {
			$query = $this->prepare_with_table_tokens(
				'SELECT id
	             FROM {contacts}
	            WHERE business_account_id = %s
	              AND phone_number_id = %s
	              AND country_code = %s
	              AND phone_number = %s
	              AND id != %d',
				$table_map,
				array(
					$baid,
					$pnid,
					$cc,
					$pn,
					$exclude_id,
				)
			);
			$id    = '' !== $query ? $db->get_var( $query ) : null;
		} else {
			$query = $this->prepare_with_table_tokens(
				'SELECT id
	             FROM {contacts}
	            WHERE business_account_id = %s
	              AND phone_number_id = %s
	              AND country_code = %s
	              AND phone_number = %s',
				$table_map,
				array(
					$baid,
					$pnid,
					$cc,
					$pn,
				)
			);
			$id    = '' !== $query ? $db->get_var( $query ) : null;
		}

		return $id ? (int) $id : null;
	}

	/**
	 * Update basic contact fields by id.
	 *
	 * @param int                 $id Contact id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return bool True on success.
	 */
	public function update_contact_basic( int $id, array $data ): bool {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );

		$fmt_map = array(
			'name'                => '%s',
			'country_code'        => '%s',
			'phone_number'        => '%s',
			'custom_fields'       => '%s',
			'business_account_id' => '%s',
			'phone_number_id'     => '%s',
			'is_verified'         => '%d',
			'is_subscribed'       => '%d',
			'updated_at'          => '%s',
		);

		$format = array();
		$clean  = array();

		foreach ( $data as $k => $v ) {
			if ( isset( $fmt_map[ $k ] ) ) {
				$clean[ $k ] = $v;
				$format[]    = $fmt_map[ $k ];
			}
		}

		if ( empty( $clean ) ) {
			return true;
		}

		$ok = false !== $db->update( $table_contacts, $clean, array( 'id' => $id ), $format, array( '%d' ) );

		if ( $ok && isset( $data['business_account_id'], $data['phone_number_id'] ) ) {
			nxtcc_invalidate_tenant_caches( (string) $data['business_account_id'], (string) $data['phone_number_id'] );
		}

		return $ok;
	}

	/**
	 * Force unlink a contact from a WP user (sets wp_uid to NULL).
	 *
	 * @param int $id Contact id.
	 * @return void
	 */
	public function force_unlink_contact( int $id ): void {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			'UPDATE {contacts} SET wp_uid = NULL WHERE id = %d',
			array(
				'contacts' => $table_contacts,
			),
			array( $id )
		);

		if ( '' !== $query ) {
			$db->query( $query );
		}
	}

	/**
	 * Replace group mappings for a contact.
	 *
	 * @param int              $contact_id Contact id.
	 * @param array<int|mixed> $group_ids Group ids to set.
	 * @return void
	 */
	public function replace_contact_groups( int $contact_id, array $group_ids ): void {
		$db        = $this->db();
		$table_map = $this->table( 'nxtcc_group_contact_map' );

		$db->delete( $table_map, array( 'contact_id' => $contact_id ), array( '%d' ) );

		foreach ( $group_ids as $gid ) {
			$db->insert(
				$table_map,
				array(
					'group_id'   => (int) $gid,
					'contact_id' => $contact_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Calculate is_verified flag based on group ids.
	 *
	 * @param array<int|mixed> $group_ids Group IDs.
	 * @return int 1 if any verified group exists, otherwise 0.
	 */
	public function contact_verified_flag_from_groups( array $group_ids ): int {
		if ( empty( $group_ids ) ) {
			return 0;
		}

		return $this->any_verified_group( $group_ids ) ? 1 : 0;
	}

	/**
	 * Insert a contact and return inserted id.
	 *
	 * @param array<string, mixed> $data Contact data to insert.
	 * @return int Inserted id (0 on failure).
	 */
	public function insert_contact( array $data ): int {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );

		if ( array_key_exists( 'wp_uid', $data ) && null === $data['wp_uid'] ) {
			unset( $data['wp_uid'] );
		}

		$fmt_map = array(
			'user_mailid'         => '%s',
			'business_account_id' => '%s',
			'phone_number_id'     => '%s',
			'name'                => '%s',
			'country_code'        => '%s',
			'phone_number'        => '%s',
			'custom_fields'       => '%s',
			'is_verified'         => '%d',
			'wp_uid'              => '%d',
			'is_subscribed'       => '%d',
			'created_at'          => '%s',
			'updated_at'          => '%s',
		);

		$format = array();
		foreach ( $data as $col => $_ ) {
			$format[] = isset( $fmt_map[ $col ] ) ? $fmt_map[ $col ] : '%s';
		}

		$ok = $db->insert( $table_contacts, $data, $format );
		$id = $ok ? (int) $db->insert_id : 0;

		if ( $id && isset( $data['business_account_id'], $data['phone_number_id'] ) ) {
			nxtcc_invalidate_tenant_caches( (string) $data['business_account_id'], (string) $data['phone_number_id'] );
		}

		return $id;
	}

	/**
	 * Delete a contact and its group mappings.
	 *
	 * @param int    $id Contact id.
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return bool True.
	 */
	public function delete_contact_with_mappings( int $id, string $baid, string $pnid ): bool {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );
		$table_map      = $this->table( 'nxtcc_group_contact_map' );

		$db->delete( $table_map, array( 'contact_id' => $id ), array( '%d' ) );

		$db->delete(
			$table_contacts,
			array(
				'id'                  => $id,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
			),
			array( '%d', '%s', '%s' )
		);

		nxtcc_invalidate_tenant_caches( $baid, $pnid );

		return true;
	}

	/**
	 * Get state for a list of contact IDs in a tenant (id, is_verified, wp_uid).
	 *
	 * @param array<int|mixed> $ids Contact ids.
	 * @param string           $baid Business account id.
	 * @param string           $pnid Phone number id.
	 * @return array<int, object>
	 */
	public function tenant_contacts_state( array $ids, string $baid, string $pnid ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$placeholders   = $this->int_placeholders( $ids );
		$query          = $this->prepare_with_table_tokens(
			"SELECT id, is_verified, wp_uid
		          FROM {contacts}
		         WHERE id IN ({$placeholders})
		           AND business_account_id = %s
		           AND phone_number_id = %s",
			array(
				'contacts' => $table_contacts,
			),
			array_merge(
				$ids,
				array( $baid, $pnid )
			)
		);

		$rows = '' !== $query ? $db->get_results( $query ) : array();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Bulk delete contacts (and mapping rows) by IDs inside a tenant.
	 *
	 * @param array<int|mixed> $ids Contact ids.
	 * @param string           $baid Business account id.
	 * @param string           $pnid Phone number id.
	 * @return void
	 */
	public function bulk_delete_contacts( array $ids, string $baid, string $pnid ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$db               = $this->db();
		$table_contacts   = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$table_map        = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$placeholders     = $this->int_placeholders( $ids );
		$query_delete_map = $this->prepare_with_table_tokens(
			"DELETE FROM {group_map} WHERE contact_id IN ({$placeholders})",
			array(
				'group_map' => $table_map,
			),
			$ids
		);
		if ( '' !== $query_delete_map ) {
			$db->query( $query_delete_map );
		}

		$query_delete_contacts = $this->prepare_with_table_tokens(
			"DELETE FROM {contacts}
				  WHERE id IN ({$placeholders})
				    AND business_account_id = %s
				    AND phone_number_id = %s",
			array(
				'contacts' => $table_contacts,
			),
			array_merge(
				$ids,
				array( $baid, $pnid )
			)
		);
		if ( '' !== $query_delete_contacts ) {
			$db->query( $query_delete_contacts );
		}

		nxtcc_invalidate_tenant_caches( $baid, $pnid );
	}

	/**
	 * Allowlist only contact IDs that belong to the tenant.
	 *
	 * @param array<int|mixed> $ids Contact ids.
	 * @param string           $baid Business account id.
	 * @param string           $pnid Phone number id.
	 * @return array<int, string|int>
	 */
	public function allowlist_contacts_in_tenant( array $ids, string $baid, string $pnid ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$placeholders   = $this->int_placeholders( $ids );
		$query          = $this->prepare_with_table_tokens(
			"SELECT id
				   FROM {contacts}
				  WHERE id IN ({$placeholders})
				    AND business_account_id = %s
				    AND phone_number_id = %s",
			array(
				'contacts' => $table_contacts,
			),
			array_merge(
				$ids,
				array( $baid, $pnid )
			)
		);
		$rows           = '' !== $query ? $db->get_col( $query ) : array();

		return $rows ? $rows : array();
	}

	/**
	 * Allowlist only group IDs that belong to the user.
	 *
	 * Note: This remains user-scoped. If you now require tenant scoped
	 * group allowlisting, implement allowlist_user_groups_in_tenant().
	 *
	 * @param string           $user_mailid User email.
	 * @param array<int|mixed> $ids Group ids.
	 * @return array<int>
	 */
	public function allowlist_user_groups( string $user_mailid, array $ids ): array {
		return $this->user_owned_group_ids( $user_mailid, $ids );
	}

	/**
	 * Get current group IDs mapped to a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @return array<int>
	 */
	public function current_groups_for_contact( int $contact_id ): array {
		$db        = $this->db();
		$table_map = $this->quote_table( $this->table( 'nxtcc_group_contact_map' ) );
		$query     = $this->prepare_with_table_tokens(
			'SELECT group_id FROM {group_map} WHERE contact_id = %d',
			array(
				'group_map' => $table_map,
			),
			array( $contact_id )
		);
		$rows      = '' !== $query ? $db->get_col( $query ) : array();

		return array_map( 'intval', $rows ? $rows : array() );
	}

	/**
	 * Filter only verified group IDs from a pool of IDs.
	 *
	 * @param array<int|mixed> $ids Group ids.
	 * @return array<int>
	 */
	public function verified_groups_from_pool( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db           = $this->db();
		$table_groups = $this->quote_table( $this->table( 'nxtcc_groups' ) );
		$placeholders = $this->int_placeholders( $ids );
		$query        = $this->prepare_with_table_tokens(
			"SELECT id FROM {groups} WHERE is_verified = 1 AND id IN ({$placeholders})",
			array(
				'groups' => $table_groups,
			),
			$ids
		);
		$rows         = '' !== $query ? $db->get_col( $query ) : array();

		return array_map( 'intval', $rows ? $rows : array() );
	}

	/**
	 * Update subscription flag for a contact.
	 *
	 * @param int    $contact_id Contact id.
	 * @param int    $flag 1 subscribed, 0 unsubscribed.
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return void
	 */
	public function update_subscription( int $contact_id, int $flag, string $baid, string $pnid ): void {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );

		$db->update(
			$table_contacts,
			array(
				'is_subscribed' => $flag ? 1 : 0,
				'updated_at'    => current_time( 'mysql', 1 ),
			),
			array( 'id' => $contact_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		nxtcc_invalidate_tenant_caches( $baid, $pnid );
	}

	// ========================= IMPORT / EXPORT HELPERS =========================

	/**
	 * Find a duplicate contact in tenant by country_code + phone_number.
	 *
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @param string $cc Country code.
	 * @param string $pn Phone number.
	 * @return object|null
	 */
	public function find_duplicate_in_tenant( string $baid, string $pnid, string $cc, string $pn ) {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			'SELECT id, custom_fields
			   FROM {contacts}
			  WHERE business_account_id = %s
			    AND phone_number_id = %s
			    AND country_code = %s
			    AND phone_number = %s
			  LIMIT 1',
			array(
				'contacts' => $table_contacts,
			),
			array( $baid, $pnid, $cc, $pn )
		);

		return '' !== $query ? $db->get_row( $query ) : null;
	}

	/**
	 * Update custom_fields and subscription for an existing contact.
	 *
	 * @param int    $id Contact id.
	 * @param string $name Contact name.
	 * @param string $merged_json JSON for custom_fields.
	 * @param int    $subscribed 1 subscribed, 0 unsubscribed.
	 * @return void
	 */
	public function upsert_contact_custom_fields( int $id, string $name, string $merged_json, int $subscribed ): void {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );

		$db->update(
			$table_contacts,
			array(
				'name'          => $name,
				'custom_fields' => $merged_json,
				'is_subscribed' => $subscribed ? 1 : 0,
				'updated_at'    => current_time( 'mysql', 1 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Insert group mapping rows for a new contact.
	 *
	 * @param int              $contact_id Contact id.
	 * @param array<int|mixed> $group_ids Group ids.
	 * @return void
	 */
	public function map_groups_for_new_contact( int $contact_id, array $group_ids ): void {
		$db        = $this->db();
		$table_map = $this->table( 'nxtcc_group_contact_map' );

		foreach ( $group_ids as $gid ) {
			$db->insert(
				$table_map,
				array(
					'group_id'   => (int) $gid,
					'contact_id' => $contact_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Escape a value for safe LIKE queries.
	 *
	 * @param string $s Search string.
	 * @return string
	 */
	public function esc_like( string $s ): string {
		return $this->db()->esc_like( $s );
	}

	/**
	 * List contacts by IDs inside a tenant.
	 *
	 * @param array<int|mixed> $ids Contact ids.
	 * @param string           $baid Business account id.
	 * @param string           $pnid Phone number id.
	 * @return array<int, object>
	 */
	public function list_contacts_by_ids( array $ids, string $baid, string $pnid ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$placeholders   = $this->int_placeholders( $ids );
		$query          = $this->prepare_with_table_tokens(
			"SELECT *
				   FROM {contacts}
				  WHERE id IN ({$placeholders})
				    AND business_account_id = %s
				    AND phone_number_id = %s
			   ORDER BY id DESC",
			array(
				'contacts' => $table_contacts,
			),
			array_merge(
				$ids,
				array( $baid, $pnid )
			)
		);
		$rows           = '' !== $query ? $db->get_results( $query ) : array();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a contact verified.
	 *
	 * @param int $id Contact id.
	 * @return void
	 */
	public function mark_verified( int $id ): void {
		$db             = $this->db();
		$table_contacts = $this->table( 'nxtcc_contacts' );

		$db->update(
			$table_contacts,
			array(
				'is_verified' => 1,
				'updated_at'  => current_time( 'mysql', 1 ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a contact unverified and unlink from WP user.
	 *
	 * @param int $id Contact id.
	 * @return void
	 */
	public function mark_unverified_and_unlink( int $id ): void {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			'UPDATE {contacts}
			    SET is_verified = %d,
			        wp_uid = NULL,
			        updated_at = %s
			  WHERE id = %d',
			array(
				'contacts' => $table_contacts,
			),
			array(
				0,
				current_time( 'mysql', 1 ),
				$id,
			)
		);

		if ( '' !== $query ) {
			$db->query( $query );
		}
	}

	/**
	 * Get unique custom field labels used in a tenant.
	 *
	 * @param string $baid Business account id.
	 * @param string $pnid Phone number id.
	 * @return array<int, string>
	 */
	public function custom_field_labels_for_tenant( string $baid, string $pnid ): array {
		$db             = $this->db();
		$table_contacts = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$query          = $this->prepare_with_table_tokens(
			"SELECT custom_fields
				   FROM {contacts}
				  WHERE business_account_id = %s
				    AND phone_number_id = %s
				    AND custom_fields IS NOT NULL
				    AND custom_fields != ''",
			array(
				'contacts' => $table_contacts,
			),
			array( $baid, $pnid )
		);
		$rows           = '' !== $query ? $db->get_results( $query ) : array();

		$labels = array();

		foreach ( (array) $rows as $r ) {
			$arr = json_decode( (string) $r->custom_fields, true );

			if ( is_array( $arr ) ) {
				foreach ( $arr as $f ) {
					if ( is_array( $f ) && ! empty( $f['label'] ) ) {
						$labels[ (string) $f['label'] ] = true;
					}
				}
			}
		}

		return array_keys( $labels );
	}
}
