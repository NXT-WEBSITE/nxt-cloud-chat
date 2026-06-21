<?php
/**
 * Tenant-scoped CRM lifecycle stages.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned lifecycle stage definitions and contact state.
 */
final class NXTCC_CRM_Lifecycle_Stages {

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
	 * Stage definitions table.
	 *
	 * @var string
	 */
	private string $stages_table;

	/**
	 * Contact stage table.
	 *
	 * @var string
	 */
	private string $contact_stage_table;

	/**
	 * Contacts table.
	 *
	 * @var string
	 */
	private string $contacts_table;

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

		$this->db                  = $wpdb;
		$this->stages_table        = $wpdb->prefix . 'nxtcc_crm_lifecycle_stages';
		$this->contact_stage_table = $wpdb->prefix . 'nxtcc_contact_lifecycle_stage';
		$this->contacts_table      = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * List lifecycle stages, lazily creating defaults for a tenant.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param bool  $active_only Whether to return active stages only.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_stages( array $tenant_args, bool $active_only = true ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$this->ensure_defaults( $tenant );
		$sql  = 'SELECT id, stage_name, stage_slug, color, sort_order, is_active, created_by, updated_by, created_at, updated_at
			FROM ' . $this->quote_table( $this->stages_table ) . '
			WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$args = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );

		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}

		$sql .= ' ORDER BY sort_order ASC, id ASC';
		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$args ), ARRAY_A );

		return array_map( array( $this, 'decorate_stage' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Create or update one lifecycle stage definition.
	 *
	 * @param array $args Stage arguments.
	 * @return array<string,mixed>
	 */
	public function upsert_stage( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$stage_name = substr( sanitize_text_field( (string) ( $args['stage_name'] ?? '' ) ), 0, 120 );
		$stage_slug = sanitize_title( (string) ( $args['stage_slug'] ?? $stage_name ) );
		$stage_slug = substr( $stage_slug, 0, 80 );
		$color      = sanitize_hex_color( (string) ( $args['color'] ?? '' ) );
		$color      = is_string( $color ) ? $color : '#2271b1';
		$actor_id   = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );
		$now        = current_time( 'mysql', true );

		if ( ! $this->tenant_is_complete( $tenant ) || '' === $stage_name || '' === $stage_slug ) {
			return array(
				'success' => false,
				'error'   => 'invalid_lifecycle_stage',
			);
		}

		$sql = 'INSERT INTO ' . $this->quote_table( $this->stages_table ) . '
			(user_mailid, business_account_id, phone_number_id, stage_name, stage_slug, color, sort_order, is_active, created_by, updated_by, created_at, updated_at)
			VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				stage_name = VALUES(stage_name),
				color = VALUES(color),
				sort_order = VALUES(sort_order),
				is_active = VALUES(is_active),
				updated_by = VALUES(updated_by),
				updated_at = VALUES(updated_at)';
		$ok  = $this->db->query(
			$this->db->prepare(
				$sql,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$stage_name,
				$stage_slug,
				$color,
				absint( $args['sort_order'] ?? 0 ),
				array_key_exists( 'is_active', $args ) && empty( $args['is_active'] ) ? 0 : 1,
				$actor_id > 0 ? $actor_id : null,
				$actor_id > 0 ? $actor_id : null,
				$now,
				$now
			)
		);

		if ( false === $ok ) {
			return array(
				'success' => false,
				'error'   => 'lifecycle_stage_save_failed',
			);
		}

		$stage = $this->get_stage_by_slug( $stage_slug, $tenant );
		do_action( 'nxtcc_lifecycle_stage_saved', $stage, $tenant, $args );

		return array(
			'success' => true,
			'stage'   => $stage,
		);
	}

	/**
	 * Read one contact's current lifecycle stage.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_contact_stage( int $contact_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT cs.contact_id, cs.stage_id, cs.source, cs.changed_by, cs.changed_at,
					s.stage_name, s.stage_slug, s.color, s.sort_order, s.is_active
				FROM ' . $this->quote_table( $this->contact_stage_table ) . ' cs
				INNER JOIN ' . $this->quote_table( $this->stages_table ) . ' s ON s.id = cs.stage_id
				WHERE cs.contact_id = %d AND cs.user_mailid = %s AND cs.business_account_id = %s AND cs.phone_number_id = %s
				LIMIT 1',
				$contact_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate_stage( $row ) : null;
	}

	/**
	 * Set or clear one contact's lifecycle stage.
	 *
	 * @param array $args Update arguments.
	 * @return array<string,mixed>
	 */
	public function set_contact_stage( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$stage_id   = absint( $args['stage_id'] ?? 0 );
		$source     = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$actor_id   = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );
		$contact    = $this->get_contact( $contact_id, $tenant );
		$previous   = $this->get_contact_stage( $contact_id, $tenant );

		if ( null === $contact ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		$stage = $stage_id > 0 ? $this->get_stage_by_id( $stage_id, $tenant ) : null;
		if ( $stage_id > 0 && null === $stage ) {
			return array(
				'success' => false,
				'error'   => 'lifecycle_stage_not_found',
			);
		}

		if ( ( 0 === $stage_id && null === $previous ) || ( is_array( $previous ) && absint( $previous['stage_id'] ?? 0 ) === $stage_id ) ) {
			return array(
				'success' => true,
				'changed' => false,
				'stage'   => $previous,
			);
		}

		$now = current_time( 'mysql', true );
		if ( 0 === $stage_id ) {
			$ok = $this->db->delete(
				$this->contact_stage_table,
				array(
					'contact_id'          => $contact_id,
					'user_mailid'         => $tenant['user_mailid'],
					'business_account_id' => $tenant['business_account_id'],
					'phone_number_id'     => $tenant['phone_number_id'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		} else {
			$sql = 'INSERT INTO ' . $this->quote_table( $this->contact_stage_table ) . '
				(user_mailid, business_account_id, phone_number_id, contact_id, stage_id, source, changed_by, changed_at)
				VALUES (%s, %s, %s, %d, %d, %s, %d, %s)
				ON DUPLICATE KEY UPDATE stage_id = VALUES(stage_id), source = VALUES(source), changed_by = VALUES(changed_by), changed_at = VALUES(changed_at)';
			$ok  = $this->db->query(
				$this->db->prepare(
					$sql,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$contact_id,
					$stage_id,
					$source,
					$actor_id > 0 ? $actor_id : null,
					$now
				)
			);
		}

		if ( false === $ok ) {
			return array(
				'success' => false,
				'error'   => 'contact_lifecycle_update_failed',
			);
		}

		$current = $this->get_contact_stage( $contact_id, $tenant );
		$result  = array(
			'success'    => true,
			'changed'    => true,
			'contact_id' => $contact_id,
			'previous'   => $previous,
			'stage'      => $current,
		);

		if ( class_exists( 'NXTCC_CRM_Activities' ) ) {
			NXTCC_CRM_Activities::instance()->record(
				array_merge(
					$tenant,
					array(
						'contact_id'    => $contact_id,
						'activity_type' => 'lifecycle_stage_changed',
						'source'        => $source,
						'actor_id'      => $actor_id,
						'metadata'      => array(
							'previous_stage_id'   => absint( $previous['stage_id'] ?? 0 ),
							'previous_stage_name' => sanitize_text_field( (string) ( $previous['stage_name'] ?? '' ) ),
							'stage_id'            => absint( $current['stage_id'] ?? 0 ),
							'stage_name'          => sanitize_text_field( (string) ( $current['stage_name'] ?? '' ) ),
						),
					)
				)
			);
		}

		do_action( 'nxtcc_contact_lifecycle_stage_changed', $result, $contact, $args );
		return $result;
	}

	/**
	 * Remove lifecycle mappings for permanently deleted contacts.
	 *
	 * @param array $contact_ids Contact IDs.
	 * @return void
	 */
	public function delete_contact_data( array $contact_ids ): void {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
		$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->contact_stage_table ) . ' WHERE contact_id IN (' . $placeholders . ')', ...$contact_ids ) );
	}

	/**
	 * Seed standard lifecycle stages.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return void
	 */
	private function ensure_defaults( array $tenant ): void {
		$count = absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT COUNT(*) FROM ' . $this->quote_table( $this->stages_table ) . '
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			)
		);
		if ( $count > 0 ) {
			return;
		}

		$defaults = array(
			array( 'New Lead', 'new-lead', '#2271b1' ),
			array( 'Qualified', 'qualified', '#3858e9' ),
			array( 'Opportunity', 'opportunity', '#8a4b00' ),
			array( 'Customer', 'customer', '#008a20' ),
			array( 'Inactive', 'inactive', '#646970' ),
			array( 'Lost', 'lost', '#b32d2e' ),
		);

		foreach ( $defaults as $index => $default ) {
			$this->upsert_stage(
				array_merge(
					$tenant,
					array(
						'stage_name' => $default[0],
						'stage_slug' => $default[1],
						'color'      => $default[2],
						'sort_order' => ( $index + 1 ) * 10,
						'source'     => 'system',
						'actor_id'   => 0,
					)
				)
			);
		}
	}

	/**
	 * Read a stage by ID.
	 *
	 * @param int   $stage_id Stage ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_stage_by_id( int $stage_id, array $tenant ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->stages_table ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$stage_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate_stage( $row ) : null;
	}

	/**
	 * Read a stage by slug.
	 *
	 * @param string $slug Stage slug.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_stage_by_slug( string $slug, array $tenant ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->stages_table ) . '
				WHERE stage_slug = %s AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$slug,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate_stage( $row ) : null;
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
				'SELECT * FROM ' . $this->quote_table( $this->contacts_table ) . '
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
	 * Decorate one stage row.
	 *
	 * @param array $row Stage row.
	 * @return array<string,mixed>
	 */
	private function decorate_stage( array $row ): array {
		foreach ( array( 'id', 'stage_id', 'contact_id', 'sort_order', 'created_by', 'updated_by', 'changed_by' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$row[ $field ] = absint( $row[ $field ] );
			}
		}
		if ( array_key_exists( 'is_active', $row ) ) {
			$row['is_active'] = ! empty( $row['is_active'] );
		}

		return $row;
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
	 * Whether tenant tuple is complete.
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
	 * Keep actor inside supplied tenant.
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
	 * Quote controlled table identifier.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		return '`' . ( is_string( $clean ) && '' !== $clean ? $clean : 'nxtcc_invalid' ) . '`';
	}
}
