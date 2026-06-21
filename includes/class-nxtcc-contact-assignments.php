<?php
/**
 * Tenant-safe contact ownership assignments.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned assignment service shared by Contacts, Inbox, and integrations.
 */
final class NXTCC_Contact_Assignments {

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
	 * Current assignment table.
	 *
	 * @var string
	 */
	private string $assignments_table;

	/**
	 * Assignment history table.
	 *
	 * @var string
	 */
	private string $history_table;

	/**
	 * Round-robin routing cursor table.
	 *
	 * @var string
	 */
	private string $routing_table;

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

		$this->db                = $wpdb;
		$this->assignments_table = $wpdb->prefix . 'nxtcc_contact_assignments';
		$this->history_table     = $wpdb->prefix . 'nxtcc_contact_assignment_history';
		$this->routing_table     = $wpdb->prefix . 'nxtcc_assignment_routing_state';
		$this->contacts_table    = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * Quote a controlled table identifier.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		return '`' . ( is_string( $clean ) && '' !== $clean ? $clean : 'nxtcc_invalid' ) . '`';
	}

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array<string,mixed> $args Raw tenant values.
	 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
	 */
	private function normalize_tenant( array $args ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $args['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $args['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $args['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Whether the tenant tuple is complete.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return bool
	 */
	private function tenant_is_complete( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Normalize assignment source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, array( 'manual', 'workflow', 'integration', 'system' ), true ) ? $source : 'integration';
	}

	/**
	 * Resolve a tenant-scoped contact.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_contact( int $contact_id, array $tenant ): ?array {
		if ( $contact_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, user_mailid, business_account_id, phone_number_id
				FROM ' . $this->quote_table( $this->contacts_table ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				LIMIT 1',
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
	 * Return access teams for the exact tenant that requested assignment targets.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_access_team_presets( array $tenant ): array {
		if ( class_exists( 'NXTCC_Access_Teams' ) ) {
			return NXTCC_Access_Teams::merge_with_defaults( $tenant, array() );
		}

		return class_exists( 'NXTCC_Access_Control' ) ? NXTCC_Access_Control::get_role_presets() : array();
	}

	/**
	 * Return assignment targets available within a tenant.
	 *
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array{users:array<int,array<string,mixed>>,roles:array<int,array<string,mixed>>,teams:array<int,array<string,mixed>>}
	 */
	public function list_targets( array $tenant_args ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		$result = array(
			'users' => array(),
			'roles' => array(),
			'teams' => array(),
		);

		if ( ! $this->tenant_is_complete( $tenant ) || ! class_exists( 'NXTCC_Tenant_Access_DAO' ) ) {
			return $result;
		}

		$team_keys  = array();
		$seen_users = array();
		foreach ( NXTCC_Tenant_Access_DAO::get_tenant_access_rows( $tenant ) as $access ) {
			$user_id = absint( $access['wp_user_id'] ?? 0 );
			$user    = 0 < $user_id ? get_userdata( $user_id ) : false;

			if ( $user instanceof WP_User && ! isset( $seen_users[ $user_id ] ) ) {
				$seen_users[ $user_id ] = true;
				$result['users'][]      = array(
					'id'                  => $user_id,
					'label'               => sanitize_text_field( '' !== $user->display_name ? $user->display_name : $user->user_login ),
					'email'               => sanitize_email( $user->user_email ),
					'role_key'            => ! empty( $access['is_owner'] ) ? 'owner' : sanitize_key( (string) ( $access['role_key'] ?? 'custom' ) ),
					'team_key'            => ! empty( $access['is_owner'] ) ? 'owner' : sanitize_key( (string) ( $access['role_key'] ?? 'custom' ) ),
					'is_owner'            => ! empty( $access['is_owner'] ),
					'assignment_eligible' => ! empty( $access['is_owner'] ) || ! empty( $access['assignment_eligible'] ),
				);
			}
		}

		$presets = $this->get_access_team_presets( $tenant );
		foreach ( $presets as $role_key => $preset ) {
			$role_key = sanitize_key( (string) $role_key );
			if ( '' === $role_key || in_array( $role_key, array( 'custom', 'owner' ), true ) || ! is_array( $preset ) ) {
				continue;
			}

			$team_keys[ $role_key ] = true;
		}

		foreach ( array_keys( $team_keys ) as $role_key ) {
			$label = isset( $presets[ $role_key ]['label'] )
				? (string) $presets[ $role_key ]['label']
				: ucwords( str_replace( '_', ' ', $role_key ) );

			$target = array(
				'key'   => $role_key,
				'label' => sanitize_text_field( $label ),
			);

			$result['roles'][] = $target;
			$result['teams'][] = $target;
		}

		usort(
			$result['users'],
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);
		usort(
			$result['roles'],
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);
		usort(
			$result['teams'],
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $result;
	}

	/**
	 * Validate and normalize one requested target.
	 *
	 * @param string              $target_type Target type.
	 * @param int                 $user_id User ID.
	 * @param string              $role_key Role key.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<string,mixed>|WP_Error
	 */
	private function normalize_target( string $target_type, int $user_id, string $role_key, array $tenant ) {
		$target_type = sanitize_key( $target_type );
		$user_id     = absint( $user_id );
		$role_key    = sanitize_key( $role_key );

		if ( '' === $target_type || 'unassigned' === $target_type ) {
			return array(
				'target_type'      => '',
				'assigned_user_id' => null,
				'assigned_role'    => null,
			);
		}

		$targets = $this->list_targets( $tenant );

		if ( 'user' === $target_type ) {
			foreach ( $targets['users'] as $target ) {
				if ( absint( $target['id'] ?? 0 ) === $user_id ) {
					return array(
						'target_type'      => 'user',
						'assigned_user_id' => $user_id,
						'assigned_role'    => null,
					);
				}
			}
		}

		if ( 'role' === $target_type ) {
			foreach ( $targets['roles'] as $target ) {
				if ( sanitize_key( (string) ( $target['key'] ?? '' ) ) === $role_key ) {
					return array(
						'target_type'      => 'role',
						'assigned_user_id' => null,
						'assigned_role'    => $role_key,
					);
				}
			}
		}

		return new WP_Error( 'nxtcc_invalid_assignment_target', __( 'Choose an assignment target that belongs to this tenant.', 'nxt-cloud-chat' ) );
	}

	/**
	 * Decorate an assignment row with a display label.
	 *
	 * @param array<string,mixed> $row Assignment row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$row['contact_id']       = absint( $row['contact_id'] ?? 0 );
		$row['assigned_user_id'] = absint( $row['assigned_user_id'] ?? 0 );
		$row['target_type']      = sanitize_key( (string) ( $row['target_type'] ?? '' ) );
		$row['assigned_role']    = sanitize_key( (string) ( $row['assigned_role'] ?? '' ) );
		$row['label']            = '';

		if ( 'user' === $row['target_type'] && $row['assigned_user_id'] > 0 ) {
			$user         = get_userdata( $row['assigned_user_id'] );
			$row['label'] = $user instanceof WP_User
				? sanitize_text_field( '' !== $user->display_name ? $user->display_name : $user->user_login )
				: __( 'Unknown user', 'nxt-cloud-chat' );
		} elseif ( 'role' === $row['target_type'] && '' !== $row['assigned_role'] ) {
			$presets      = $this->get_access_team_presets( $this->normalize_tenant( $row ) );
			$row['label'] = isset( $presets[ $row['assigned_role'] ]['label'] )
				? sanitize_text_field( (string) $presets[ $row['assigned_role'] ]['label'] )
				: ucwords( str_replace( '_', ' ', $row['assigned_role'] ) );
		}

		return $row;
	}

	/**
	 * Get one current contact assignment.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_assignment( int $contact_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->assignments_table ) . '
				WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				LIMIT 1',
				$contact_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate_row( $row ) : null;
	}

	/**
	 * Get current assignments for multiple tenant contacts.
	 *
	 * @param array<int>          $contact_ids Contact IDs.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_assignments_for_contacts( array $contact_ids, array $tenant_args ): array {
		$tenant      = $this->normalize_tenant( $tenant_args );
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );

		if ( empty( $contact_ids ) || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$args         = array_merge( $contact_ids, array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ) );
		$rows         = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->assignments_table ) . '
				WHERE contact_id IN (' . $placeholders . ')
				AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				...$args
			),
			ARRAY_A
		);
		$map          = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$decorated                             = $this->decorate_row( $row );
			$map[ (int) $decorated['contact_id'] ] = $decorated;
		}

		return $map;
	}

	/**
	 * Set or clear one contact assignment.
	 *
	 * @param array<string,mixed> $args Assignment arguments.
	 * @return array<string,mixed>
	 */
	public function update_assignment( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$actor_id   = absint( $args['actor_id'] ?? 0 );
		$source     = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$contact    = $this->get_contact( $contact_id, $tenant );

		if ( null === $contact ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		$target = $this->normalize_target(
			(string) ( $args['target_type'] ?? '' ),
			absint( $args['assigned_user_id'] ?? 0 ),
			(string) ( $args['assigned_role'] ?? '' ),
			$tenant
		);

		if ( is_wp_error( $target ) ) {
			return array(
				'success' => false,
				'error'   => $target->get_error_code(),
				'message' => $target->get_error_message(),
			);
		}

		$previous = $this->get_assignment( $contact_id, $tenant );
		$changed  = is_array( $previous )
			? (string) $previous['target_type'] !== (string) $target['target_type']
				|| absint( $previous['assigned_user_id'] ?? 0 ) !== absint( $target['assigned_user_id'] ?? 0 )
				|| (string) $previous['assigned_role'] !== (string) $target['assigned_role']
			: '' !== $target['target_type'];

		if ( ! $changed ) {
			if ( is_array( $previous ) && class_exists( 'NXTCC_Conversations' ) ) {
				NXTCC_Conversations::sync_contact_assignment( $contact_id, $previous, $previous, $tenant, $source, $actor_id );
			}

			return array(
				'success'    => true,
				'changed'    => false,
				'assignment' => $previous,
			);
		}

		$now = current_time( 'mysql', true );

		if ( '' === $target['target_type'] ) {
			$ok = false !== $this->db->delete(
				$this->assignments_table,
				array(
					'contact_id'          => $contact_id,
					'user_mailid'         => $tenant['user_mailid'],
					'business_account_id' => $tenant['business_account_id'],
					'phone_number_id'     => $tenant['phone_number_id'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		} else {
			$sql = 'INSERT INTO ' . $this->quote_table( $this->assignments_table ) . '
				(user_mailid, business_account_id, phone_number_id, contact_id, target_type, assigned_user_id, assigned_role, source, assigned_by, assigned_at, updated_at)
				VALUES (%s, %s, %s, %d, %s, NULLIF(%d, 0), NULLIF(%s, \'\'), %s, NULLIF(%d, 0), %s, %s)
				ON DUPLICATE KEY UPDATE
					target_type = VALUES(target_type),
					assigned_user_id = VALUES(assigned_user_id),
					assigned_role = VALUES(assigned_role),
					source = VALUES(source),
					assigned_by = VALUES(assigned_by),
					assigned_at = VALUES(assigned_at),
					updated_at = VALUES(updated_at)';
			$ok  = false !== $this->db->query(
				$this->db->prepare(
					$sql,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$contact_id,
					$target['target_type'],
					absint( $target['assigned_user_id'] ?? 0 ),
					(string) ( $target['assigned_role'] ?? '' ),
					$source,
					$actor_id,
					$now,
					$now
				)
			);
		}

		if ( ! $ok ) {
			return array(
				'success' => false,
				'error'   => 'assignment_update_failed',
			);
		}

		$this->db->insert(
			$this->history_table,
			array(
				'user_mailid'               => $tenant['user_mailid'],
				'business_account_id'       => $tenant['business_account_id'],
				'phone_number_id'           => $tenant['phone_number_id'],
				'contact_id'                => $contact_id,
				'previous_target_type'      => is_array( $previous ) ? (string) $previous['target_type'] : null,
				'previous_assigned_user_id' => is_array( $previous ) && ! empty( $previous['assigned_user_id'] ) ? absint( $previous['assigned_user_id'] ) : null,
				'previous_assigned_role'    => is_array( $previous ) && ! empty( $previous['assigned_role'] ) ? (string) $previous['assigned_role'] : null,
				'new_target_type'           => '' !== $target['target_type'] ? $target['target_type'] : null,
				'new_assigned_user_id'      => $target['assigned_user_id'],
				'new_assigned_role'         => $target['assigned_role'],
				'source'                    => $source,
				'changed_by'                => $actor_id > 0 ? $actor_id : null,
				'created_at'                => $now,
			)
		);

		$current = $this->get_assignment( $contact_id, $tenant );
		do_action( 'nxtcc_contact_assignment_updated', $contact_id, $current, $previous, $tenant, $source, $actor_id );

		return array(
			'success'    => true,
			'changed'    => true,
			'assignment' => $current,
		);
	}

	/**
	 * Assign a contact to the next eligible tenant team member.
	 *
	 * Round-robin cursors are isolated by the caller-provided route key. Existing
	 * assignments are preserved unless overwrite is explicitly enabled.
	 *
	 * @param array<string,mixed> $args Routing arguments.
	 * @return array<string,mixed>
	 */
	public function auto_assign( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$role_key   = $this->normalize_assignment_pool_key( $args );
		$overwrite  = ! empty( $args['overwrite'] );
		$existing   = $this->get_assignment( $contact_id, $tenant );

		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		if ( is_array( $existing ) && ! $overwrite ) {
			if ( class_exists( 'NXTCC_Conversations' ) ) {
				NXTCC_Conversations::sync_contact_assignment(
					$contact_id,
					$existing,
					$existing,
					$tenant,
					(string) ( $args['source'] ?? 'integration' ),
					absint( $args['actor_id'] ?? 0 )
				);
			}

			return array(
				'success'    => true,
				'changed'    => false,
				'skipped'    => true,
				'assignment' => $existing,
				'reason'     => 'already_assigned',
			);
		}

		$users = $this->eligible_routing_users( $tenant, $role_key );
		if ( empty( $users ) ) {
			return array(
				'success' => false,
				'error'   => 'no_eligible_assignment_targets',
				'message' => __( 'No eligible tenant team members are available for this routing pool.', 'nxt-cloud-chat' ),
			);
		}

		$route_key      = $this->normalize_route_key( (string) ( $args['route_key'] ?? '' ), $role_key );
		$cursor         = $this->next_route_cursor( $tenant, $route_key );
		$route_fallback = false;
		if ( $cursor <= 0 ) {
			$route_fallback = true;
			$cursor         = wp_rand( 1, count( $users ) );
		}

		$selected = $users[ ( $cursor - 1 ) % count( $users ) ];
		$result   = $this->update_assignment(
			array_merge(
				$tenant,
				array(
					'contact_id'       => $contact_id,
					'target_type'      => 'user',
					'assigned_user_id' => absint( $selected['id'] ?? 0 ),
					'source'           => (string) ( $args['source'] ?? 'integration' ),
					'actor_id'         => absint( $args['actor_id'] ?? 0 ),
				)
			)
		);

		$result['route_key']        = $route_key;
		$result['role_key']         = $role_key;
		$result['team_key']         = $role_key;
		$result['cursor']           = $cursor;
		$result['route_fallback']   = $route_fallback;
		$result['selected_user_id'] = absint( $selected['id'] ?? 0 );

		return $result;
	}

	/**
	 * Normalize the optional assignment team/pool key from legacy and current callers.
	 *
	 * @param array<string,mixed> $args Routing arguments.
	 * @return string
	 */
	private function normalize_assignment_pool_key( array $args ): string {
		foreach ( array( 'role_key', 'team_key', 'assigned_role', 'access_team', 'routing_pool' ) as $field ) {
			if ( ! isset( $args[ $field ] ) || ! is_scalar( $args[ $field ] ) ) {
				continue;
			}

			$value = sanitize_key( (string) $args[ $field ] );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Return tenant-valid users in a stable routing order.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param string               $role_key Optional role pool.
	 * @return array<int,array<string,mixed>>
	 */
	private function eligible_routing_users( array $tenant, string $role_key ): array {
		$users = $this->list_targets( $tenant )['users'];
		$users = array_values(
			array_filter(
				$users,
				static function ( array $user ) use ( $role_key ): bool {
					return ! empty( $user['assignment_eligible'] ) && ( '' === $role_key || sanitize_key( (string) ( $user['role_key'] ?? '' ) ) === $role_key );
				}
			)
		);

		usort(
			$users,
			static function ( array $a, array $b ): int {
				return absint( $a['id'] ?? 0 ) <=> absint( $b['id'] ?? 0 );
			}
		);

		return $users;
	}

	/**
	 * Normalize a caller-owned route key.
	 *
	 * @param string $route_key Requested route key.
	 * @param string $role_key Role pool.
	 * @return string
	 */
	private function normalize_route_key( string $route_key, string $role_key ): string {
		$route_key = sanitize_key( $route_key );
		if ( '' === $route_key ) {
			$route_key = 'default_' . ( '' !== $role_key ? $role_key : 'all' );
		}

		return substr( $route_key, 0, 191 );
	}

	/**
	 * Atomically advance and return one route cursor.
	 *
	 * LAST_INSERT_ID(expr) is connection-scoped, allowing concurrent callers to
	 * receive distinct cursor values without holding a PHP-side lock.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param string               $route_key Route key.
	 * @return int
	 */
	private function next_route_cursor( array $tenant, string $route_key ): int {
		$now        = current_time( 'mysql', true );
		$route_hash = hash( 'sha256', implode( '|', array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'], $route_key ) ) );
		$sql        = 'INSERT INTO ' . $this->quote_table( $this->routing_table ) . '
			(route_hash, user_mailid, business_account_id, phone_number_id, route_key, route_cursor, updated_at)
			VALUES (%s, %s, %s, %s, %s, LAST_INSERT_ID(1), %s)
			ON DUPLICATE KEY UPDATE route_cursor = LAST_INSERT_ID(route_cursor + 1), updated_at = VALUES(updated_at)';

		$prepared = $this->db->prepare(
			$sql,
			$route_hash,
			$tenant['user_mailid'],
			$tenant['business_account_id'],
			$tenant['phone_number_id'],
			$route_key,
			$now
		);
		$ok       = $this->db->query( $prepared );

		if ( false === $ok && function_exists( 'nxtcc_install_db_schema' ) ) {
			nxtcc_install_db_schema();
			$ok = $this->db->query( $prepared );
		}

		return false === $ok ? 0 : absint( $this->db->get_var( 'SELECT LAST_INSERT_ID()' ) );
	}

	/**
	 * Delete current and historical assignment data for removed contacts.
	 *
	 * @param array<int> $contact_ids Contact IDs.
	 * @return void
	 */
	public function delete_contact_data( array $contact_ids ): void {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->quote_table( $this->history_table ) . ' WHERE contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);
		$this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->quote_table( $this->assignments_table ) . ' WHERE contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);
	}
}
