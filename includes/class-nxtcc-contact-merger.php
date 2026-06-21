<?php
/**
 * Guarded tenant-scoped contact merging.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Merge duplicate contacts while preserving Free-owned CRM relationships.
 */
final class NXTCC_Contact_Merger {

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
	 * Return singleton.
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
		$this->db = $wpdb;
	}

	/**
	 * Find strong duplicate candidates for one contact.
	 *
	 * Candidates are deliberately limited to contacts linked to the same
	 * WordPress user. Name-only matching is too ambiguous for automatic CRM UI.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $limit Maximum candidates.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_candidates( int $contact_id, array $tenant_args, int $limit = 20 ): array {
		$tenant  = $this->normalize_tenant( $tenant_args );
		$contact = $this->get_contact( $contact_id, $tenant );
		$wp_uid  = absint( $contact['wp_uid'] ?? 0 );
		if ( null === $contact || $wp_uid <= 0 ) {
			return array();
		}

		$limit = min( 50, max( 1, $limit ) );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				'SELECT id, name, country_code, phone_number, is_subscribed, is_verified, created_at
				FROM ' . $this->table_sql( 'nxtcc_contacts' ) . '
				WHERE id <> %d AND wp_uid = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY id ASC LIMIT %d',
				$contact_id,
				$wp_uid,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['id']            = absint( $row['id'] ?? 0 );
			$row['is_subscribed'] = ! empty( $row['is_subscribed'] );
			$row['is_verified']   = ! empty( $row['is_verified'] );
		}
		unset( $row );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Merge one source contact into a target contact.
	 *
	 * The target identity, phone number, and consent state remain authoritative.
	 * A merge is refused if both contacts already have active conversation rows,
	 * because silently choosing one ticket would lose normalized audit history.
	 *
	 * @param array $args Merge arguments.
	 * @return array<string,mixed>
	 * @throws RuntimeException When an internal merge query fails; caught and returned as a structured error.
	 */
	public function merge( array $args ): array {
		$tenant    = $this->normalize_tenant( $args );
		$target_id = absint( $args['target_contact_id'] ?? 0 );
		$source_id = absint( $args['source_contact_id'] ?? 0 );
		$actor_id  = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );
		$source    = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );

		if ( $target_id <= 0 || $source_id <= 0 || $target_id === $source_id ) {
			return array(
				'success' => false,
				'error'   => 'invalid_contact_merge',
			);
		}

		$target = $this->get_contact( $target_id, $tenant );
		$from   = $this->get_contact( $source_id, $tenant );
		if ( null === $target || null === $from ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		if ( $this->has_conversation_collision( $target_id, $source_id, $tenant ) ) {
			return array(
				'success' => false,
				'error'   => 'conversation_merge_required',
				'message' => 'Both contacts have conversation tickets. Resolve or merge those tickets before merging contacts.',
			);
		}

		$this->db->query( 'START TRANSACTION' );

		try {
			$this->merge_unique_map( 'nxtcc_group_contact_map', 'group_id', $target_id, $source_id, $tenant );
			$this->merge_unique_map( 'nxtcc_tag_contact_map', 'tag_id', $target_id, $source_id, $tenant );
			$this->merge_contact_stage( $target_id, $source_id, $tenant );
			$this->merge_contact_assignment( $target_id, $source_id, $tenant );
			$this->merge_deal_contacts( $target_id, $source_id, $tenant );

			foreach ( array(
				'nxtcc_contact_assignment_history',
				'nxtcc_crm_activities',
				'nxtcc_crm_tasks',
				'nxtcc_message_history',
				'nxtcc_conversations',
			) as $table ) {
				$this->update_contact_reference( $table, $target_id, $source_id, $tenant );
			}

			$updated = $this->db->update(
				$this->db->prefix . 'nxtcc_contacts',
				$this->merged_contact_values( $target, $from, $actor_id ),
				array(
					'id'                  => $target_id,
					'user_mailid'         => $tenant['user_mailid'],
					'business_account_id' => $tenant['business_account_id'],
					'phone_number_id'     => $tenant['phone_number_id'],
				),
				array( '%s', '%d', '%s', '%s', '%d', '%d', '%s' ),
				array( '%d', '%s', '%s', '%s' )
			);
			if ( false === $updated ) {
				throw new RuntimeException( 'target_contact_update_failed' );
			}

			$deleted = $this->db->delete(
				$this->db->prefix . 'nxtcc_contacts',
				array(
					'id'                  => $source_id,
					'user_mailid'         => $tenant['user_mailid'],
					'business_account_id' => $tenant['business_account_id'],
					'phone_number_id'     => $tenant['phone_number_id'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
			if ( 1 !== $deleted ) {
				throw new RuntimeException( 'source_contact_delete_failed' );
			}

			$this->db->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			return array(
				'success' => false,
				'error'   => sanitize_key( $error->getMessage() ),
			);
		}

		$result = array(
			'success'           => true,
			'target_contact_id' => $target_id,
			'source_contact_id' => $source_id,
			'contact'           => $this->get_contact( $target_id, $tenant ),
		);

		if ( class_exists( 'NXTCC_CRM_Activities' ) ) {
			NXTCC_CRM_Activities::instance()->record(
				array_merge(
					$tenant,
					array(
						'contact_id'    => $target_id,
						'activity_type' => 'contacts_merged',
						'source'        => $source,
						'actor_id'      => $actor_id,
						'metadata'      => array(
							'source_contact_id'   => $source_id,
							'source_contact_name' => sanitize_text_field( (string) ( $from['name'] ?? '' ) ),
						),
					)
				)
			);
		}

		do_action( 'nxtcc_contacts_merged', $result, $target, $from, $args );
		return $result;
	}

	/**
	 * Merge one unique contact-map table.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param string $relation_column Related ID column.
	 * @param int    $target_id Target contact ID.
	 * @param int    $source_id Source contact ID.
	 * @param array  $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When a map merge query fails.
	 */
	private function merge_unique_map( string $table_suffix, string $relation_column, int $target_id, int $source_id, array $tenant ): void {
		$table_sql       = $this->table_sql( $table_suffix );
		$relation_column = preg_replace( '/[^A-Za-z0-9_]/', '', $relation_column );
		if ( ! is_string( $relation_column ) || '' === $relation_column ) {
			throw new RuntimeException( 'invalid_merge_relation' );
		}

		$columns = 'user_mailid, business_account_id, phone_number_id, contact_id, ' . $relation_column;
		if ( 'nxtcc_tag_contact_map' === $table_suffix ) {
			$columns .= ', source, assigned_by, created_at';
		} else {
			$columns .= ', created_at';
		}

		$sql  = 'INSERT IGNORE INTO ' . $table_sql . ' (' . $columns . ')
			SELECT user_mailid, business_account_id, phone_number_id, %d, ' . $relation_column;
		$sql .= 'nxtcc_tag_contact_map' === $table_suffix ? ', source, assigned_by, created_at' : ', created_at';
		$sql .= ' FROM ' . $table_sql . '
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';

		$inserted = $this->db->query(
			$this->db->prepare(
				$sql,
				$target_id,
				$source_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'contact_map_merge_failed' );
		}

		$this->delete_contact_reference( $table_suffix, $source_id, $tenant );
	}

	/**
	 * Preserve target lifecycle stage, otherwise move source stage.
	 *
	 * @param int   $target_id Target contact ID.
	 * @param int   $source_id Source contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When a lifecycle merge query fails.
	 */
	private function merge_contact_stage( int $target_id, int $source_id, array $tenant ): void {
		$table = $this->table_sql( 'nxtcc_contact_lifecycle_stage' );
		$sql   = 'UPDATE IGNORE ' . $table . ' SET contact_id = %d
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		if ( false === $this->db->query( $this->db->prepare( $sql, $target_id, $source_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) ) ) {
			throw new RuntimeException( 'contact_lifecycle_merge_failed' );
		}
		$this->delete_contact_reference( 'nxtcc_contact_lifecycle_stage', $source_id, $tenant );
	}

	/**
	 * Preserve target current assignment, otherwise move source assignment.
	 *
	 * @param int   $target_id Target contact ID.
	 * @param int   $source_id Source contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When an assignment merge query fails.
	 */
	private function merge_contact_assignment( int $target_id, int $source_id, array $tenant ): void {
		$table = $this->table_sql( 'nxtcc_contact_assignments' );
		$sql   = 'UPDATE IGNORE ' . $table . ' SET contact_id = %d
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		if ( false === $this->db->query( $this->db->prepare( $sql, $target_id, $source_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) ) ) {
			throw new RuntimeException( 'contact_assignment_merge_failed' );
		}
		$this->delete_contact_reference( 'nxtcc_contact_assignments', $source_id, $tenant );
	}

	/**
	 * Move source deal links while preserving existing target links.
	 *
	 * @param int   $target_id Target contact ID.
	 * @param int   $source_id Source contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When a deal-link merge query fails.
	 */
	private function merge_deal_contacts( int $target_id, int $source_id, array $tenant ): void {
		$table            = $this->table_sql( 'nxtcc_crm_deal_contacts' );
		$primary_deal_ids = $this->db->get_col(
			$this->db->prepare(
				'SELECT deal_id FROM ' . $table . '
				WHERE contact_id = %d AND is_primary = 1 AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$source_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
		$sql              = 'UPDATE IGNORE ' . $table . ' SET contact_id = %d
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		if ( false === $this->db->query( $this->db->prepare( $sql, $target_id, $source_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) ) ) {
			throw new RuntimeException( 'deal_contact_merge_failed' );
		}
		$this->delete_contact_reference( 'nxtcc_crm_deal_contacts', $source_id, $tenant );

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $primary_deal_ids ) ) ) ) as $deal_id ) {
			$ok = $this->db->query(
				$this->db->prepare(
					'UPDATE ' . $table . ' SET is_primary = CASE WHEN contact_id = %d THEN 1 ELSE 0 END
					WHERE deal_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
					$target_id,
					$deal_id,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			);
			if ( false === $ok ) {
				throw new RuntimeException( 'deal_primary_contact_merge_failed' );
			}
		}
	}

	/**
	 * Update a tenant-scoped contact reference.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param int    $target_id Target contact ID.
	 * @param int    $source_id Source contact ID.
	 * @param array  $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When a reference update fails.
	 */
	private function update_contact_reference( string $table_suffix, int $target_id, int $source_id, array $tenant ): void {
		$sql = 'UPDATE ' . $this->table_sql( $table_suffix ) . ' SET contact_id = %d
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$ok  = $this->db->query(
			$this->db->prepare(
				$sql,
				$target_id,
				$source_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'contact_reference_merge_failed' );
		}
	}

	/**
	 * Delete a tenant-scoped contact reference.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param int    $contact_id Contact ID.
	 * @param array  $tenant Tenant tuple.
	 * @return void
	 * @throws RuntimeException When a reference cleanup fails.
	 */
	private function delete_contact_reference( string $table_suffix, int $contact_id, array $tenant ): void {
		$sql = 'DELETE FROM ' . $this->table_sql( $table_suffix ) . '
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$ok  = $this->db->query(
			$this->db->prepare(
				$sql,
				$contact_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'contact_reference_cleanup_failed' );
		}
	}

	/**
	 * Whether merging would collide two normalized conversations.
	 *
	 * @param int   $target_id Target contact ID.
	 * @param int   $source_id Source contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private function has_conversation_collision( int $target_id, int $source_id, array $tenant ): bool {
		$count = $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->table_sql( 'nxtcc_conversations' ) . '
				WHERE contact_id IN (%d, %d) AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$target_id,
				$source_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);

		return absint( $count ) > 1;
	}

	/**
	 * Build target values, preserving target identity and consent.
	 *
	 * @param array $target Target contact.
	 * @param array $source Source contact.
	 * @param int   $actor_id Actor ID.
	 * @return array<string,mixed>
	 */
	private function merged_contact_values( array $target, array $source, int $actor_id ): array {
		$target_fields = json_decode( (string) ( $target['custom_fields'] ?? '' ), true );
		$source_fields = json_decode( (string) ( $source['custom_fields'] ?? '' ), true );
		$target_fields = is_array( $target_fields ) ? $target_fields : array();
		$source_fields = is_array( $source_fields ) ? $source_fields : array();
		$custom_fields = wp_json_encode( array_merge( $source_fields, $target_fields ) );
		$group_ids     = array_values(
			array_unique(
				array_filter(
					array_map(
						'absint',
						array_merge(
							explode( ',', (string) ( $target['group_ids'] ?? '' ) ),
							explode( ',', (string) ( $source['group_ids'] ?? '' ) )
						)
					)
				)
			)
		);

		return array(
			'name'          => '' !== trim( (string) ( $target['name'] ?? '' ) ) ? (string) $target['name'] : (string) ( $source['name'] ?? '' ),
			'wp_uid'        => absint( $target['wp_uid'] ?? 0 ) > 0 ? absint( $target['wp_uid'] ) : absint( $source['wp_uid'] ?? 0 ),
			'custom_fields' => is_string( $custom_fields ) ? $custom_fields : '{}',
			'group_ids'     => implode( ',', $group_ids ),
			'is_verified'   => ! empty( $target['is_verified'] ) || ! empty( $source['is_verified'] ) ? 1 : 0,
			'updated_by'    => $actor_id > 0 ? $actor_id : null,
			'updated_at'    => current_time( 'mysql', true ),
		);
	}

	/**
	 * Read a tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_contact( int $contact_id, array $tenant ): ?array {
		if ( $contact_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table_sql( 'nxtcc_contacts' ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$contact_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Normalize tenant.
	 *
	 * @param array $args Raw tenant values.
	 * @return array<string,string>
	 */
	private function normalize_tenant( array $args ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $args['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $args['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $args['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Whether tenant is complete.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private function tenant_is_complete( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Normalize source.
	 *
	 * @param string $source Raw source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, array( 'manual', 'import', 'workflow', 'integration', 'system', 'webhook' ), true ) ? $source : 'integration';
	}

	/**
	 * Normalize actor.
	 *
	 * @param int   $actor_id Actor ID.
	 * @param array $tenant Tenant tuple.
	 * @return int
	 */
	private function normalize_actor_id( int $actor_id, array $tenant ): int {
		if ( $actor_id <= 0 ) {
			return 0;
		}
		if ( class_exists( 'NXTCC_Tenant_Access_DAO' ) && is_array( NXTCC_Tenant_Access_DAO::get_user_access( $actor_id, $tenant ) ) ) {
			return $actor_id;
		}

		$user = get_userdata( $actor_id );
		return $user instanceof WP_User && sanitize_email( (string) $user->user_email ) === $tenant['user_mailid'] ? $actor_id : 0;
	}

	/**
	 * Quote a controlled plugin table.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	private function table_sql( string $suffix ): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $this->db->prefix . $suffix );
		return '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	}
}
