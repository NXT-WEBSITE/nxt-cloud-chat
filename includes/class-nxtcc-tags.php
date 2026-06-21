<?php
/**
 * Tenant-safe contact tag service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core Free-owned tag definitions and contact assignments.
 */
final class NXTCC_Tags {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_tags';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Tags table.
	 *
	 * @var string
	 */
	private string $tags_table;

	/**
	 * Tag-contact map table.
	 *
	 * @var string
	 */
	private string $map_table;

	/**
	 * Contacts table.
	 *
	 * @var string
	 */
	private string $contacts_table;

	/**
	 * Return the singleton.
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

		$this->db             = $wpdb;
		$this->tags_table     = $wpdb->prefix . 'nxtcc_tags';
		$this->map_table      = $wpdb->prefix . 'nxtcc_tag_contact_map';
		$this->contacts_table = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * Quote a controlled table identifier.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array<string,mixed> $args Raw tenant values.
	 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
	 */
	private function normalize_tenant( array $args ): array {
		return array(
			'user_mailid'         => isset( $args['user_mailid'] ) ? sanitize_email( (string) $args['user_mailid'] ) : '',
			'business_account_id' => isset( $args['business_account_id'] ) ? sanitize_text_field( (string) $args['business_account_id'] ) : '',
			'phone_number_id'     => isset( $args['phone_number_id'] ) ? sanitize_text_field( (string) $args['phone_number_id'] ) : '',
		);
	}

	/**
	 * Check whether a tenant tuple is complete.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return bool
	 */
	private function tenant_is_complete( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Normalize tag name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function normalize_name( string $name ): string {
		return substr( trim( sanitize_text_field( $name ) ), 0, 191 );
	}

	/**
	 * Normalize tag slug.
	 *
	 * @param string $value Name or slug.
	 * @return string
	 */
	private function normalize_slug( string $value ): string {
		return substr( sanitize_title( $value ), 0, 100 );
	}

	/**
	 * Normalize tag color.
	 *
	 * @param string $color Raw color.
	 * @return string
	 */
	private function normalize_color( string $color ): string {
		$color = sanitize_hex_color( $color );
		return is_string( $color ) && '' !== $color ? strtolower( $color ) : '#2271b1';
	}

	/**
	 * Normalize assignment source.
	 *
	 * @param string $source Raw source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source  = sanitize_key( $source );
		$allowed = array( 'manual', 'import', 'workflow', 'integration', 'system' );

		return in_array( $source, $allowed, true ) ? $source : 'integration';
	}

	/**
	 * Normalize a list of positive IDs.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int>
	 */
	private function normalize_ids( $values ): array {
		if ( ! is_array( $values ) ) {
			$values = explode( ',', (string) $values );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
	}

	/**
	 * Resolve and verify the contact tenant.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string,string> $tenant Tenant hint.
	 * @return array<string,mixed>|null
	 */
	private function get_contact( int $contact_id, array $tenant ): ?array {
		$contact_id   = absint( $contact_id );
		$contacts_sql = $this->quote_table( $this->contacts_table );
		$sql          = 'SELECT id, user_mailid, business_account_id, phone_number_id
			FROM ' . $contacts_sql . '
			WHERE id = %d';
		$args         = array( $contact_id );

		if ( $contact_id <= 0 ) {
			return null;
		}

		if ( '' !== $tenant['user_mailid'] ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $tenant['user_mailid'];
		}

		if ( '' !== $tenant['business_account_id'] ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $tenant['business_account_id'];
		}

		if ( '' !== $tenant['phone_number_id'] ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $tenant['phone_number_id'];
		}

		$sql .= ' LIMIT 1';

		$row = $this->db->get_row( $this->db->prepare( $sql, ...$args ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return a complete tenant tuple from a contact row.
	 *
	 * @param array<string,mixed> $contact Contact row.
	 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
	 */
	private function tenant_from_contact( array $contact ): array {
		return $this->normalize_tenant( $contact );
	}

	/**
	 * Flush contact tag caches.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return void
	 */
	private function flush_contact_cache( int $contact_id, array $tenant ): void {
		wp_cache_delete( 'contact:' . $contact_id, self::CACHE_GROUP );
		wp_cache_delete(
			'contact:' . md5( implode( '|', array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) ) ),
			self::CACHE_GROUP
		);
	}

	/**
	 * List tag definitions for a tenant.
	 *
	 * Supported args: search, limit, offset, with_count.
	 *
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @param array<string,mixed> $args List arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tags( array $tenant_args, array $args = array() ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$search     = isset( $args['search'] ) ? trim( sanitize_text_field( (string) $args['search'] ) ) : '';
		$limit      = isset( $args['limit'] ) ? max( 1, min( 1000, absint( $args['limit'] ) ) ) : 500;
		$offset     = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;
		$with_count = ! array_key_exists( 'with_count', $args ) || ! empty( $args['with_count'] );
		$tags_sql   = $this->quote_table( $this->tags_table );
		$map_sql    = $this->quote_table( $this->map_table );

		$sql = $with_count
			? 'SELECT t.*, COUNT(m.id) AS contact_count
				FROM ' . $tags_sql . ' AS t
				LEFT JOIN ' . $map_sql . ' AS m
					ON m.tag_id = t.id
					AND m.user_mailid = t.user_mailid
					AND m.business_account_id = t.business_account_id
					AND m.phone_number_id = t.phone_number_id'
			: 'SELECT t.* FROM ' . $tags_sql . ' AS t';

		$sql_args = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		$sql     .= ' WHERE t.user_mailid = %s AND t.business_account_id = %s AND t.phone_number_id = %s';

		if ( '' !== $search ) {
			$like       = '%' . $this->db->esc_like( $search ) . '%';
			$sql       .= ' AND (t.tag_name LIKE %s OR t.description LIKE %s)';
			$sql_args[] = $like;
			$sql_args[] = $like;
		}

		if ( $with_count ) {
			$sql .= ' GROUP BY t.id';
		}

		$sql       .= ' ORDER BY t.tag_name ASC, t.id ASC LIMIT %d OFFSET %d';
		$sql_args[] = $limit;
		$sql_args[] = $offset;

		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$sql_args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count tag definitions for a tenant.
	 *
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @param string              $search Search term.
	 * @return int
	 */
	public function count_tags( array $tenant_args, string $search = '' ): int {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return 0;
		}

		$tags_sql = $this->quote_table( $this->tags_table );
		$sql      = 'SELECT COUNT(*) FROM ' . $tags_sql . '
			WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$args     = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		$search   = trim( sanitize_text_field( $search ) );

		if ( '' !== $search ) {
			$like   = '%' . $this->db->esc_like( $search ) . '%';
			$sql   .= ' AND (tag_name LIKE %s OR description LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$args ) );
	}

	/**
	 * Return compact tag statistics for a tenant.
	 *
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array{total_tags:int,tagged_contacts:int,assignments:int}
	 */
	public function get_stats( array $tenant_args ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		$empty  = array(
			'total_tags'      => 0,
			'tagged_contacts' => 0,
			'assignments'     => 0,
		);

		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return $empty;
		}

		$tags_sql = $this->quote_table( $this->tags_table );
		$map_sql  = $this->quote_table( $this->map_table );
		$args     = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		$row      = $this->db->get_row(
			$this->db->prepare(
				'SELECT
					(SELECT COUNT(*) FROM ' . $tags_sql . ' WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s) AS total_tags,
					COUNT(DISTINCT contact_id) AS tagged_contacts,
					COUNT(*) AS assignments
				FROM ' . $map_sql . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				...array_merge( $args, $args )
			),
			ARRAY_A
		);

		return is_array( $row )
			? array(
				'total_tags'      => absint( $row['total_tags'] ?? 0 ),
				'tagged_contacts' => absint( $row['tagged_contacts'] ?? 0 ),
				'assignments'     => absint( $row['assignments'] ?? 0 ),
			)
			: $empty;
	}

	/**
	 * Create or return a tag definition.
	 *
	 * @param array<string,mixed> $args Tag arguments.
	 * @return array<string,mixed>
	 */
	public function upsert_tag( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$name        = $this->normalize_name( isset( $args['tag_name'] ) ? (string) $args['tag_name'] : (string) ( $args['name'] ?? '' ) );
		$slug        = $this->normalize_slug( isset( $args['tag_slug'] ) ? (string) $args['tag_slug'] : $name );
		$color       = $this->normalize_color( isset( $args['color'] ) ? (string) $args['color'] : '' );
		$description = isset( $args['description'] ) ? substr( sanitize_textarea_field( (string) $args['description'] ), 0, 500 ) : '';
		$actor_id    = isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : absint( get_current_user_id() );
		$tags_sql    = $this->quote_table( $this->tags_table );

		if ( ! $this->tenant_is_complete( $tenant ) || '' === $name || '' === $slug ) {
			return array(
				'success' => false,
				'error'   => 'invalid_tag',
			);
		}

		$existing = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $tags_sql . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND tag_slug = %s
				LIMIT 1',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$slug
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			return array(
				'success' => true,
				'created' => false,
				'tag_id'  => absint( $existing['id'] ),
				'tag'     => $existing,
			);
		}

		$now      = current_time( 'mysql', true );
		$inserted = $this->db->insert(
			$this->tags_table,
			array(
				'user_mailid'         => $tenant['user_mailid'],
				'business_account_id' => $tenant['business_account_id'],
				'phone_number_id'     => $tenant['phone_number_id'],
				'tag_name'            => $name,
				'tag_slug'            => $slug,
				'color'               => $color,
				'description'         => '' !== $description ? $description : null,
				'created_by'          => $actor_id > 0 ? $actor_id : null,
				'updated_by'          => $actor_id > 0 ? $actor_id : null,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'error'   => 'tag_insert_failed',
			);
		}

		$tag_id = absint( $this->db->insert_id );
		$tag    = $this->get_tag( $tag_id, $tenant );
		$result = array(
			'success' => true,
			'created' => true,
			'tag_id'  => $tag_id,
			'tag'     => is_array( $tag ) ? $tag : array(),
		);

		do_action( 'nxtcc_contact_tag_created', $result, $args );

		return $result;
	}

	/**
	 * Read a tag within a tenant.
	 *
	 * @param int                 $tag_id Tag ID.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_tag( int $tag_id, array $tenant_args ): ?array {
		$tenant   = $this->normalize_tenant( $tenant_args );
		$tags_sql = $this->quote_table( $this->tags_table );

		if ( $tag_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $tags_sql . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				LIMIT 1',
				$tag_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update a tenant tag definition.
	 *
	 * @param array<string,mixed> $args Tag arguments.
	 * @return array<string,mixed>
	 */
	public function update_tag( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$tag_id      = isset( $args['tag_id'] ) ? absint( $args['tag_id'] ) : absint( $args['id'] ?? 0 );
		$name        = $this->normalize_name( isset( $args['tag_name'] ) ? (string) $args['tag_name'] : (string) ( $args['name'] ?? '' ) );
		$slug        = $this->normalize_slug( isset( $args['tag_slug'] ) ? (string) $args['tag_slug'] : $name );
		$color       = $this->normalize_color( isset( $args['color'] ) ? (string) $args['color'] : '' );
		$description = isset( $args['description'] ) ? substr( sanitize_textarea_field( (string) $args['description'] ), 0, 500 ) : '';
		$actor_id    = isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : absint( get_current_user_id() );
		$existing    = $this->get_tag( $tag_id, $tenant );

		if ( ! is_array( $existing ) || '' === $name || '' === $slug ) {
			return array(
				'success' => false,
				'error'   => 'invalid_tag',
			);
		}

		$updated = $this->db->update(
			$this->tags_table,
			array(
				'tag_name'    => $name,
				'tag_slug'    => $slug,
				'color'       => $color,
				'description' => '' !== $description ? $description : null,
				'updated_by'  => $actor_id > 0 ? $actor_id : null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id'                  => $tag_id,
				'user_mailid'         => $tenant['user_mailid'],
				'business_account_id' => $tenant['business_account_id'],
				'phone_number_id'     => $tenant['phone_number_id'],
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $updated ) {
			return array(
				'success' => false,
				'error'   => 'tag_update_failed',
			);
		}

		$result = array(
			'success' => true,
			'tag_id'  => $tag_id,
			'changed' => $updated > 0,
			'tag'     => $this->get_tag( $tag_id, $tenant ),
		);

		if ( $updated > 0 ) {
			do_action( 'nxtcc_contact_tag_updated', $result, $existing, $args );
		}

		return $result;
	}

	/**
	 * Delete a tenant tag and its mappings.
	 *
	 * @param array<string,mixed> $args Tag arguments.
	 * @return array<string,mixed>
	 */
	public function delete_tag( array $args ): array {
		$tenant   = $this->normalize_tenant( $args );
		$tag_id   = isset( $args['tag_id'] ) ? absint( $args['tag_id'] ) : absint( $args['id'] ?? 0 );
		$existing = $this->get_tag( $tag_id, $tenant );
		$map_sql  = $this->quote_table( $this->map_table );
		$tags_sql = $this->quote_table( $this->tags_table );

		if ( ! is_array( $existing ) ) {
			return array(
				'success' => false,
				'error'   => 'tag_not_found',
			);
		}

		$contact_ids = $this->db->get_col(
			$this->db->prepare(
				'SELECT contact_id FROM ' . $map_sql . '
				WHERE tag_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$tag_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);

		$mappings_deleted = $this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $map_sql . '
				WHERE tag_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$tag_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);

		if ( false === $mappings_deleted ) {
			return array(
				'success' => false,
				'error'   => 'tag_mapping_delete_failed',
			);
		}

		$deleted = $this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $tags_sql . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				LIMIT 1',
				$tag_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);

		if ( $deleted <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'tag_delete_failed',
			);
		}

		foreach ( $this->normalize_ids( $contact_ids ) as $contact_id ) {
			$this->flush_contact_cache( $contact_id, $tenant );
		}

		$result = array(
			'success'          => true,
			'tag_id'           => $tag_id,
			'contact_ids'      => $this->normalize_ids( $contact_ids ),
			'assignments_gone' => count( $contact_ids ),
		);

		do_action( 'nxtcc_contact_tag_deleted', $result, $existing, $args );

		return $result;
	}

	/**
	 * Merge one or more source tags into a target tag.
	 *
	 * @param array<string,mixed> $args Merge arguments.
	 * @return array<string,mixed>
	 */
	public function merge_tags( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$target_id  = isset( $args['target_tag_id'] ) ? absint( $args['target_tag_id'] ) : 0;
		$source_ids = $this->normalize_ids( $args['source_tag_ids'] ?? array() );
		$source_ids = array_values( array_diff( $source_ids, array( $target_id ) ) );
		$target     = $this->get_tag( $target_id, $tenant );

		if ( ! is_array( $target ) || empty( $source_ids ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_merge',
			);
		}

		$source_ids = array_values(
			array_filter(
				$source_ids,
				fn( int $tag_id ): bool => is_array( $this->get_tag( $tag_id, $tenant ) )
			)
		);

		if ( empty( $source_ids ) ) {
			return array(
				'success' => false,
				'error'   => 'source_tags_not_found',
			);
		}

		$map_sql      = $this->quote_table( $this->map_table );
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		$query_args   = array_merge(
			array(
				$target_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			),
			$source_ids
		);
		$contact_ids  = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT contact_id FROM {$map_sql}
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND tag_id IN ({$placeholders})",
				...array_merge(
					array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ),
					$source_ids
				)
			)
		);

		$assignment_result = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$map_sql}
					(user_mailid, business_account_id, phone_number_id, contact_id, tag_id, source, assigned_by, created_at)
				SELECT user_mailid, business_account_id, phone_number_id, contact_id, %d, source, assigned_by, created_at
				FROM {$map_sql}
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND tag_id IN ({$placeholders})",
				...$query_args
			)
		);

		if ( false === $assignment_result ) {
			return array(
				'success' => false,
				'error'   => 'merge_assignment_failed',
			);
		}

		foreach ( $source_ids as $source_id ) {
			$this->delete_tag(
				array_merge(
					$tenant,
					array(
						'tag_id' => $source_id,
						'source' => 'system',
					)
				)
			);
		}

		foreach ( $this->normalize_ids( $contact_ids ) as $contact_id ) {
			$this->flush_contact_cache( $contact_id, $tenant );
		}

		return array(
			'success'        => true,
			'target_tag_id'  => $target_id,
			'source_tag_ids' => $source_ids,
			'contact_ids'    => $this->normalize_ids( $contact_ids ),
		);
	}

	/**
	 * Read tags assigned to one contact.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant_args Optional tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_contact_tags( int $contact_id, array $tenant_args = array() ): array {
		$tenant  = $this->normalize_tenant( $tenant_args );
		$contact = $this->get_contact( $contact_id, $tenant );
		if ( ! is_array( $contact ) ) {
			return array();
		}

		$tenant    = $this->tenant_from_contact( $contact );
		$cache_key = 'contact:' . md5( implode( '|', array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tags_sql = $this->quote_table( $this->tags_table );
		$map_sql  = $this->quote_table( $this->map_table );
		$rows     = $this->db->get_results(
			$this->db->prepare(
				'SELECT t.id, t.tag_name, t.tag_slug, t.color, t.description, m.source, m.assigned_by, m.created_at AS assigned_at
				FROM ' . $map_sql . ' AS m
				INNER JOIN ' . $tags_sql . ' AS t ON t.id = m.tag_id
				WHERE m.contact_id = %d AND m.user_mailid = %s AND m.business_account_id = %s AND m.phone_number_id = %s
				ORDER BY t.tag_name ASC, t.id ASC',
				$contact_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		$rows     = is_array( $rows ) ? $rows : array();

		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, 300 );

		return $rows;
	}

	/**
	 * Read tags for multiple tenant contacts in one query.
	 *
	 * @param array<int,mixed>    $contact_ids Contact IDs.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public function get_tags_for_contacts( array $contact_ids, array $tenant_args ): array {
		$contact_ids = $this->normalize_ids( $contact_ids );
		$tenant      = $this->normalize_tenant( $tenant_args );
		if ( empty( $contact_ids ) || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$tags_sql     = $this->quote_table( $this->tags_table );
		$map_sql      = $this->quote_table( $this->map_table );
		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$args         = array_merge(
			$contact_ids,
			array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] )
		);
		$rows         = $this->db->get_results(
			$this->db->prepare(
				"SELECT m.contact_id, t.id, t.tag_name, t.tag_slug, t.color, t.description, m.source, m.assigned_by, m.created_at AS assigned_at
				FROM {$map_sql} AS m
				INNER JOIN {$tags_sql} AS t ON t.id = m.tag_id
				WHERE m.contact_id IN ({$placeholders})
				AND m.user_mailid = %s AND m.business_account_id = %s AND m.phone_number_id = %s
				ORDER BY t.tag_name ASC, t.id ASC",
				...$args
			),
			ARRAY_A
		);
		$out          = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$contact_id = isset( $row['contact_id'] ) ? absint( $row['contact_id'] ) : 0;
			if ( $contact_id <= 0 ) {
				continue;
			}

			unset( $row['contact_id'] );
			$out[ $contact_id ][] = $row;
		}

		return $out;
	}

	/**
	 * Resolve valid tag IDs for a tenant.
	 *
	 * @param array<int,mixed>     $tag_ids Tag IDs.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<int>
	 */
	private function allowlist_tag_ids( array $tag_ids, array $tenant ): array {
		$tag_ids = $this->normalize_ids( $tag_ids );
		if ( empty( $tag_ids ) ) {
			return array();
		}

		$tags_sql     = $this->quote_table( $this->tags_table );
		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
		$args         = array_merge(
			$tag_ids,
			array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] )
		);
		$ids          = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$tags_sql}
				WHERE id IN ({$placeholders})
				AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s",
				...$args
			)
		);

		return $this->normalize_ids( $ids );
	}

	/**
	 * Resolve tag names/slugs from an integration payload.
	 *
	 * @param array<string,mixed>  $args Tag update arguments.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<int>
	 */
	private function resolve_requested_tag_ids( array $args, array $tenant ): array {
		$ids    = $this->normalize_ids( $args['tag_ids'] ?? array() );
		$values = isset( $args['tags'] ) && is_array( $args['tags'] ) ? $args['tags'] : array();

		foreach ( $values as $value ) {
			if ( is_numeric( $value ) ) {
				$ids[] = absint( $value );
				continue;
			}

			$name = $this->normalize_name( (string) $value );
			if ( '' === $name ) {
				continue;
			}

			$result = $this->upsert_tag(
				array_merge(
					$tenant,
					array(
						'tag_name' => $name,
						'color'    => isset( $args['default_color'] ) ? (string) $args['default_color'] : '',
						'actor_id' => isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : 0,
					)
				)
			);

			if ( ! empty( $result['success'] ) && ! empty( $result['tag_id'] ) ) {
				$ids[] = absint( $result['tag_id'] );
			}
		}

		return $this->allowlist_tag_ids( array_values( array_unique( $ids ) ), $tenant );
	}

	/**
	 * Add, remove, or replace contact tags.
	 *
	 * @param array<string,mixed> $args Update arguments.
	 * @return array<string,mixed>
	 */
	public function update_contact_tags( array $args ): array {
		$contact_id = isset( $args['contact_id'] ) ? absint( $args['contact_id'] ) : 0;
		$operation  = isset( $args['operation'] ) ? sanitize_key( (string) $args['operation'] ) : 'add';
		$source     = $this->normalize_source( isset( $args['source'] ) ? (string) $args['source'] : 'integration' );
		$actor_id   = isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : absint( get_current_user_id() );
		$tenant     = $this->normalize_tenant( $args );
		$contact    = $this->get_contact( $contact_id, $tenant );

		if ( ! in_array( $operation, array( 'add', 'remove', 'replace' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_tag_operation',
			);
		}

		if ( ! is_array( $contact ) ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		$tenant        = $this->tenant_from_contact( $contact );
		$requested_ids = $this->resolve_requested_tag_ids( $args, $tenant );
		$current_rows  = $this->get_contact_tags( $contact_id, $tenant );
		$current_ids   = $this->normalize_ids( wp_list_pluck( $current_rows, 'id' ) );
		$add_ids       = array();
		$remove_ids    = array();

		if ( 'add' === $operation ) {
			$add_ids = array_values( array_diff( $requested_ids, $current_ids ) );
		} elseif ( 'remove' === $operation ) {
			$remove_ids = array_values( array_intersect( $requested_ids, $current_ids ) );
		} else {
			$add_ids    = array_values( array_diff( $requested_ids, $current_ids ) );
			$remove_ids = array_values( array_diff( $current_ids, $requested_ids ) );
		}

		$map_sql = $this->quote_table( $this->map_table );
		$now     = current_time( 'mysql', true );
		$failed  = false;

		foreach ( $add_ids as $tag_id ) {
			$inserted = $this->db->query(
				$this->db->prepare(
					'INSERT IGNORE INTO ' . $map_sql . '
						(user_mailid, business_account_id, phone_number_id, contact_id, tag_id, source, assigned_by, created_at)
					VALUES (%s, %s, %s, %d, %d, %s, %d, %s)',
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$contact_id,
					$tag_id,
					$source,
					$actor_id,
					$now
				)
			);

			if ( false === $inserted ) {
				$failed = true;
			}
		}

		if ( ! empty( $remove_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $remove_ids ), '%d' ) );
			$query_args   = array_merge(
				array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ),
				$remove_ids
			);
			$removed      = $this->db->query(
				$this->db->prepare(
					"DELETE FROM {$map_sql}
					WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
					AND tag_id IN ({$placeholders})",
					...$query_args
				)
			);

			if ( false === $removed ) {
				$failed = true;
			}
		}

		if ( $failed ) {
			$this->flush_contact_cache( $contact_id, $tenant );

			return array(
				'success'    => false,
				'error'      => 'contact_tag_write_failed',
				'contact_id' => $contact_id,
				'tags'       => $this->get_contact_tags( $contact_id, $tenant ),
			);
		}

		$changed = ! empty( $add_ids ) || ! empty( $remove_ids );
		if ( $changed ) {
			$this->flush_contact_cache( $contact_id, $tenant );
		}

		$result = array(
			'success'     => true,
			'contact_id'  => $contact_id,
			'operation'   => $operation,
			'added'       => $add_ids,
			'removed'     => $remove_ids,
			'changed'     => $changed,
			'current_ids' => 'replace' === $operation ? $requested_ids : array_values( array_unique( array_merge( array_diff( $current_ids, $remove_ids ), $add_ids ) ) ),
			'tags'        => $changed ? $this->get_contact_tags( $contact_id, $tenant ) : $current_rows,
		);

		if ( $changed ) {
			do_action( 'nxtcc_contact_tags_updated', $result, $contact, $args );
		}

		return $result;
	}

	/**
	 * Delete tag mappings for contacts being deleted.
	 *
	 * @param array<int,mixed> $contact_ids Contact IDs.
	 * @return int Deleted row count.
	 */
	public function delete_contact_mappings( array $contact_ids ): int {
		$contact_ids = $this->normalize_ids( $contact_ids );
		if ( empty( $contact_ids ) ) {
			return 0;
		}

		$map_sql      = $this->quote_table( $this->map_table );
		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$query        = $this->db->prepare( "DELETE FROM {$map_sql} WHERE contact_id IN ({$placeholders})", ...$contact_ids );

		return (int) $this->db->query( $query );
	}
}
