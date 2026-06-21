<?php
/**
 * Tenant-scoped personal CRM saved views.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores reusable, allowlisted contact-filter definitions.
 */
final class NXTCC_CRM_Saved_Views {

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
	 * Saved views table.
	 *
	 * @var string
	 */
	private string $views_table;

	/**
	 * Groups table.
	 *
	 * @var string
	 */
	private string $groups_table;

	/**
	 * Tags table.
	 *
	 * @var string
	 */
	private string $tags_table;

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

		$this->db           = $wpdb;
		$this->views_table  = $wpdb->prefix . 'nxtcc_crm_saved_views';
		$this->groups_table = $wpdb->prefix . 'nxtcc_groups';
		$this->tags_table   = $wpdb->prefix . 'nxtcc_tags';
	}

	/**
	 * List personal saved views for one tenant user.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $owner_user_id Owning WordPress user.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_views( array $tenant_args, int $owner_user_id = 0 ): array {
		$tenant        = $this->normalize_tenant( $tenant_args );
		$owner_user_id = $this->normalize_owner_user_id( $owner_user_id, $tenant );
		if ( ! $this->tenant_is_complete( $tenant ) || $owner_user_id <= 0 ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->views_table ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND owner_user_id = %d
				ORDER BY is_default DESC, view_name ASC, id ASC',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$owner_user_id
			),
			ARRAY_A
		);

		return $this->decorate_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Read one personal saved view.
	 *
	 * @param int   $view_id Saved view ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $owner_user_id Owning WordPress user.
	 * @return array<string,mixed>|null
	 */
	public function get_view( int $view_id, array $tenant_args, int $owner_user_id = 0 ): ?array {
		$tenant        = $this->normalize_tenant( $tenant_args );
		$owner_user_id = $this->normalize_owner_user_id( $owner_user_id, $tenant );
		if ( $view_id <= 0 || ! $this->tenant_is_complete( $tenant ) || $owner_user_id <= 0 ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->views_table ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND owner_user_id = %d
				LIMIT 1',
				$view_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$owner_user_id
			),
			ARRAY_A
		);

		$rows = is_array( $row ) ? $this->decorate_rows( array( $row ) ) : array();
		return $rows[0] ?? null;
	}

	/**
	 * Create or update a personal saved view.
	 *
	 * @param array $args Saved view arguments.
	 * @return array<string,mixed>
	 */
	public function upsert_view( array $args ): array {
		$tenant        = $this->normalize_tenant( $args );
		$owner_user_id = $this->normalize_owner_user_id( absint( $args['owner_user_id'] ?? 0 ), $tenant );
		$view_id       = absint( $args['view_id'] ?? 0 );
		$view_name     = substr( sanitize_text_field( (string) ( $args['view_name'] ?? '' ) ), 0, 120 );
		$is_default    = ! empty( $args['is_default'] ) ? 1 : 0;
		$filters       = $this->normalize_filters( $args['filters'] ?? array(), $tenant );
		$previous      = $view_id > 0 ? $this->get_view( $view_id, $tenant, $owner_user_id ) : null;

		if ( ! $this->tenant_is_complete( $tenant ) || $owner_user_id <= 0 || '' === $view_name ) {
			return array(
				'success' => false,
				'error'   => 'invalid_saved_view',
			);
		}
		if ( $view_id > 0 && null === $previous ) {
			return array(
				'success' => false,
				'error'   => 'saved_view_not_found',
			);
		}
		if ( $this->name_exists( $view_name, $tenant, $owner_user_id, $view_id ) ) {
			return array(
				'success' => false,
				'error'   => 'saved_view_name_exists',
			);
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'view_name'    => $view_name,
			'filters_json' => wp_json_encode( $filters ),
			'is_default'   => $is_default,
			'updated_by'   => $owner_user_id,
			'updated_at'   => $now,
		);

		if ( $is_default ) {
			$this->clear_default( $tenant, $owner_user_id );
		}

		if ( $view_id > 0 ) {
			$saved = false !== $this->db->update(
				$this->views_table,
				$data,
				array_merge(
					array(
						'id'            => $view_id,
						'owner_user_id' => $owner_user_id,
					),
					$tenant
				),
				array( '%s', '%s', '%d', '%d', '%s' ),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		} else {
			$data    = array_merge(
				$tenant,
				array(
					'owner_user_id' => $owner_user_id,
					'created_by'    => $owner_user_id,
					'created_at'    => $now,
				),
				$data
			);
			$saved   = (bool) $this->db->insert(
				$this->views_table,
				$data,
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			$view_id = $saved ? absint( $this->db->insert_id ) : 0;
		}

		if ( ! $saved || $view_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'saved_view_write_failed',
			);
		}

		$result = array(
			'success' => true,
			'created' => null === $previous,
			'view'    => $this->get_view( $view_id, $tenant, $owner_user_id ),
		);
		if ( null === $previous ) {
			do_action( 'nxtcc_crm_saved_view_created', $result, $args );
		} else {
			do_action( 'nxtcc_crm_saved_view_updated', $result, $args );
		}
		return $result;
	}

	/**
	 * Delete one personal saved view.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	public function delete_view( array $args ): array {
		$tenant        = $this->normalize_tenant( $args );
		$owner_user_id = $this->normalize_owner_user_id( absint( $args['owner_user_id'] ?? 0 ), $tenant );
		$view_id       = absint( $args['view_id'] ?? 0 );
		$view          = $this->get_view( $view_id, $tenant, $owner_user_id );
		if ( null === $view ) {
			return array(
				'success' => false,
				'error'   => 'saved_view_not_found',
			);
		}

		$deleted = $this->db->delete(
			$this->views_table,
			array_merge(
				array(
					'id'            => $view_id,
					'owner_user_id' => $owner_user_id,
				),
				$tenant
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		$result  = array(
			'success' => false !== $deleted && $deleted > 0,
			'view'    => $view,
		);
		if ( $result['success'] ) {
			do_action( 'nxtcc_crm_saved_view_deleted', $result, $args );
		}
		return $result;
	}

	/**
	 * Normalize an allowlisted contact saved-view filter definition.
	 *
	 * @param mixed $filters Raw filters.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>
	 */
	public function normalize_filters( $filters, array $tenant_args ): array {
		$tenant  = $this->normalize_tenant( $tenant_args );
		$filters = $this->normalize_filter_shape( $filters );

		$filters['filter_group']      = $this->allowlist_group_id( (int) $filters['filter_group'], $tenant );
		$filters['filter_tags']       = $this->allowlist_tag_ids( $filters['filter_tags'], $tenant );
		$filters['filter_assignment'] = $this->allowlist_assignment( $filters['filter_assignment'], $tenant );

		return $filters;
	}

	/**
	 * Normalize the stored shape without performing reference-data queries.
	 *
	 * @param mixed $filters Raw filters.
	 * @return array<string,mixed>
	 */
	private function normalize_filter_shape( $filters ): array {
		if ( is_string( $filters ) ) {
			$decoded = json_decode( $filters, true );
			$filters = is_array( $decoded ) ? $decoded : array();
		}
		$filters = is_array( $filters ) ? $filters : array();

		$group_id     = absint( $filters['filter_group'] ?? 0 );
		$tag_ids      = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $filters['filter_tags'] ?? array() ) ) ) ) ), 0, 50 );
		$assignment   = sanitize_text_field( (string) ( $filters['filter_assignment'] ?? '' ) );
		$country      = preg_replace( '/\D+/', '', (string) ( $filters['filter_country'] ?? '' ) );
		$created_by   = sanitize_email( (string) ( $filters['filter_created_by'] ?? '' ) );
		$created_from = $this->normalize_date( (string) ( $filters['filter_created_from'] ?? '' ) );
		$created_to   = $this->normalize_date( (string) ( $filters['filter_created_to'] ?? '' ) );
		$subscription = (string) ( $filters['filter_subscription'] ?? '' );

		return array(
			'filter_group'        => $group_id,
			'filter_tags'         => $tag_ids,
			'filter_tag_match'    => 'any',
			'filter_country'      => is_string( $country ) ? substr( $country, 0, 8 ) : '',
			'filter_created_by'   => $created_by,
			'filter_created_from' => $created_from,
			'filter_created_to'   => $created_to,
			'filter_subscription' => in_array( $subscription, array( '0', '1' ), true ) ? $subscription : '',
			'filter_assignment'   => $assignment,
			'search'              => substr( sanitize_text_field( (string) ( $filters['search'] ?? '' ) ), 0, 191 ),
		);
	}

	/**
	 * Decorate database rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			foreach ( array( 'id', 'owner_user_id', 'is_default', 'created_by', 'updated_by' ) as $field ) {
				$row[ $field ] = absint( $row[ $field ] ?? 0 );
			}
			$decoded        = json_decode( (string) ( $row['filters_json'] ?? '' ), true );
			$row['filters'] = $this->normalize_filter_shape( is_array( $decoded ) ? $decoded : array() );
			unset( $row['filters_json'] );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Whether a view name already exists.
	 *
	 * @param string $view_name View name.
	 * @param array  $tenant Tenant tuple.
	 * @param int    $owner_user_id Owner user ID.
	 * @param int    $exclude_id Excluded view ID.
	 * @return bool
	 */
	private function name_exists( string $view_name, array $tenant, int $owner_user_id, int $exclude_id ): bool {
		$sql   = 'SELECT id FROM ' . $this->quote_table( $this->views_table ) . '
			WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
			AND owner_user_id = %d AND view_name = %s';
		$query = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'], $owner_user_id, $view_name );
		if ( $exclude_id > 0 ) {
			$sql    .= ' AND id != %d';
			$query[] = $exclude_id;
		}
		$sql .= ' LIMIT 1';
		return absint( $this->db->get_var( $this->db->prepare( $sql, ...$query ) ) ) > 0;
	}

	/**
	 * Clear another default saved view for the owner.
	 *
	 * @param array $tenant Tenant tuple.
	 * @param int   $owner_user_id Owner user ID.
	 * @return void
	 */
	private function clear_default( array $tenant, int $owner_user_id ): void {
		$where = array_merge( array( 'owner_user_id' => $owner_user_id ), $tenant );
		$this->db->update( $this->views_table, array( 'is_default' => 0 ), $where, array( '%d' ), array( '%d', '%s', '%s', '%s' ) );
	}

	/**
	 * Allowlist one group ID.
	 *
	 * @param int   $group_id Group ID.
	 * @param array $tenant Tenant tuple.
	 * @return int
	 */
	private function allowlist_group_id( int $group_id, array $tenant ): int {
		if ( $group_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return 0;
		}
		return absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT id FROM ' . $this->quote_table( $this->groups_table ) . '
					WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
					$group_id,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			)
		);
	}

	/**
	 * Allowlist tag IDs.
	 *
	 * @param array $tag_ids Tag IDs.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,int>
	 */
	private function allowlist_tag_ids( array $tag_ids, array $tenant ): array {
		if ( empty( $tag_ids ) || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
		$query        = array_merge( array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ), $tag_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholder list.
		$rows = $this->db->get_col( $this->db->prepare( 'SELECT id FROM ' . $this->quote_table( $this->tags_table ) . ' WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND id IN (' . $placeholders . ') ORDER BY id ASC', ...$query ) );
		return array_values( array_map( 'absint', is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Allowlist an assignment target.
	 *
	 * @param string $assignment Assignment target.
	 * @param array  $tenant Tenant tuple.
	 * @return string
	 */
	private function allowlist_assignment( string $assignment, array $tenant ): string {
		if ( '' === $assignment || 'unassigned' === $assignment ) {
			return $assignment;
		}
		if ( ! preg_match( '/^(user:\d+|role:[a-z0-9_-]+)$/', $assignment ) || ! class_exists( 'NXTCC_Contact_Assignments' ) ) {
			return '';
		}
		$targets = NXTCC_Contact_Assignments::instance()->list_targets( $tenant );
		$allowed = array();
		foreach ( (array) ( $targets['users'] ?? array() ) as $user ) {
			$allowed[] = 'user:' . absint( $user['id'] ?? 0 );
		}
		foreach ( (array) ( $targets['roles'] ?? array() ) as $role ) {
			$allowed[] = 'role:' . sanitize_key( (string) ( $role['key'] ?? '' ) );
		}
		return in_array( $assignment, $allowed, true ) ? $assignment : '';
	}

	/**
	 * Normalize an owner user ID and verify tenant membership.
	 *
	 * @param int   $owner_user_id Owner user ID.
	 * @param array $tenant Tenant tuple.
	 * @return int
	 */
	private function normalize_owner_user_id( int $owner_user_id, array $tenant ): int {
		$owner_user_id = $owner_user_id > 0 ? $owner_user_id : get_current_user_id();
		if ( $owner_user_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return 0;
		}
		if ( class_exists( 'NXTCC_Tenant_Access_DAO' ) && is_array( NXTCC_Tenant_Access_DAO::get_user_access( $owner_user_id, $tenant ) ) ) {
			return $owner_user_id;
		}
		$user = get_userdata( $owner_user_id );
		return $user instanceof WP_User && sanitize_email( (string) $user->user_email ) === $tenant['user_mailid'] ? $owner_user_id : 0;
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
	 * Normalize a date filter.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function normalize_date( string $date ): string {
		$date = sanitize_text_field( $date );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
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
