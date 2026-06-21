<?php
/**
 * Tenant-scoped CRM tasks and follow-up reminders.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned CRM task service.
 */
final class NXTCC_CRM_Tasks {

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
	 * Tasks table.
	 *
	 * @var string
	 */
	private string $tasks_table;

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

		$this->db             = $wpdb;
		$this->tasks_table    = $wpdb->prefix . 'nxtcc_crm_tasks';
		$this->contacts_table = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * Read a bounded task list for one contact.
	 *
	 * Supported args: status, limit, before_id.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_contact( int $contact_id, array $tenant_args, array $args = array() ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return array();
		}

		$limit     = min( 100, max( 1, absint( $args['limit'] ?? 30 ) ) );
		$before_id = absint( $args['before_id'] ?? 0 );
		$statuses  = $this->normalize_statuses( $args['status'] ?? array() );
		$sql       = 'SELECT * FROM ' . $this->quote_table( $this->tasks_table ) . '
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$query     = array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );

		if ( $before_id > 0 ) {
			$sql    .= ' AND id < %d';
			$query[] = $before_id;
		}
		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$sql         .= ' AND status IN (' . $placeholders . ')';
			$query        = array_merge( $query, $statuses );
		}

		$sql    .= " ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END ASC, due_at IS NULL ASC, due_at ASC, id DESC LIMIT %d";
		$query[] = $limit;
		$rows    = $this->db->get_results( $this->db->prepare( $sql, ...$query ), ARRAY_A );

		return $this->decorate_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Read one tenant task.
	 *
	 * @param int   $task_id Task ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_task( int $task_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $task_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->tasks_table ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$task_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		$rows = is_array( $row ) ? $this->decorate_rows( array( $row ) ) : array();
		return $rows[0] ?? null;
	}

	/**
	 * Create a task.
	 *
	 * @param array $args Task arguments.
	 * @return array<string,mixed>
	 */
	public function create_task( array $args ): array {
		$tenant     = $this->normalize_tenant( $args );
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$title      = substr( sanitize_text_field( (string) ( $args['title'] ?? '' ) ), 0, 191 );
		$priority   = $this->normalize_priority( (string) ( $args['priority'] ?? 'normal' ) );
		$source     = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$actor_id   = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );

		if ( '' === $title || null === $this->get_contact( $contact_id, $tenant ) ) {
			return array(
				'success' => false,
				'error'   => '' === $title ? 'task_title_required' : 'contact_not_found',
			);
		}

		$assignee = $this->normalize_assignee( $args, $tenant );
		if ( ! $assignee['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'invalid_task_assignee',
			);
		}

		$now      = current_time( 'mysql', true );
		$inserted = $this->db->insert(
			$this->tasks_table,
			array(
				'user_mailid'         => $tenant['user_mailid'],
				'business_account_id' => $tenant['business_account_id'],
				'phone_number_id'     => $tenant['phone_number_id'],
				'contact_id'          => $contact_id,
				'conversation_id'     => $this->nullable_id( $args['conversation_id'] ?? 0 ),
				'title'               => $title,
				'description'         => substr( sanitize_textarea_field( (string) ( $args['description'] ?? '' ) ), 0, 5000 ),
				'status'              => 'open',
				'priority'            => $priority,
				'due_at'              => $this->normalize_utc_date( (string) ( $args['due_at'] ?? '' ) ),
				'assigned_user_id'    => $assignee['user_id'] > 0 ? $assignee['user_id'] : null,
				'assigned_role'       => '' !== $assignee['role'] ? $assignee['role'] : null,
				'source'              => $source,
				'created_by'          => $actor_id > 0 ? $actor_id : null,
				'updated_by'          => $actor_id > 0 ? $actor_id : null,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'error'   => 'task_create_failed',
			);
		}

		$task   = $this->get_task( absint( $this->db->insert_id ), $tenant );
		$result = array(
			'success' => true,
			'created' => true,
			'task'    => $task,
		);
		$this->record_activity( $task, 'task_created', $source, $actor_id );
		do_action( 'nxtcc_crm_task_created', $result, $args );

		return $result;
	}

	/**
	 * Update task details or status.
	 *
	 * @param array $args Task arguments.
	 * @return array<string,mixed>
	 */
	public function update_task( array $args ): array {
		$tenant   = $this->normalize_tenant( $args );
		$task_id  = absint( $args['task_id'] ?? 0 );
		$previous = $this->get_task( $task_id, $tenant );
		$source   = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$actor_id = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );

		if ( null === $previous ) {
			return array(
				'success' => false,
				'error'   => 'task_not_found',
			);
		}

		$data    = array();
		$formats = array();
		if ( array_key_exists( 'title', $args ) ) {
			$title = substr( sanitize_text_field( (string) $args['title'] ), 0, 191 );
			if ( '' === $title ) {
				return array(
					'success' => false,
					'error'   => 'task_title_required',
				);
			}
			$data['title'] = $title;
			$formats[]     = '%s';
		}
		if ( array_key_exists( 'description', $args ) ) {
			$data['description'] = substr( sanitize_textarea_field( (string) $args['description'] ), 0, 5000 );
			$formats[]           = '%s';
		}
		if ( array_key_exists( 'priority', $args ) ) {
			$data['priority'] = $this->normalize_priority( (string) $args['priority'] );
			$formats[]        = '%s';
		}
		if ( array_key_exists( 'due_at', $args ) ) {
			$data['due_at'] = $this->normalize_utc_date( (string) $args['due_at'] );
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'status', $args ) ) {
			$status = sanitize_key( (string) $args['status'] );
			if ( ! in_array( $status, array( 'open', 'completed', 'cancelled' ), true ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_task_status',
				);
			}
			$data['status'] = $status;
			$formats[]      = '%s';
			if ( 'completed' === $status ) {
				$data['completed_by'] = $actor_id > 0 ? $actor_id : null;
				$data['completed_at'] = current_time( 'mysql', true );
				$formats[]            = '%d';
				$formats[]            = '%s';
			} elseif ( 'completed' === sanitize_key( (string) ( $previous['status'] ?? '' ) ) ) {
				$data['completed_by'] = null;
				$data['completed_at'] = null;
				$formats[]            = '%d';
				$formats[]            = '%s';
			}
		}
		if ( array_key_exists( 'assigned_user_id', $args ) || array_key_exists( 'assigned_role', $args ) ) {
			$assignee = $this->normalize_assignee( $args, $tenant );
			if ( ! $assignee['valid'] ) {
				return array(
					'success' => false,
					'error'   => 'invalid_task_assignee',
				);
			}
			$data['assigned_user_id'] = $assignee['user_id'] > 0 ? $assignee['user_id'] : null;
			$data['assigned_role']    = '' !== $assignee['role'] ? $assignee['role'] : null;
			$formats[]                = '%d';
			$formats[]                = '%s';
		}

		if ( empty( $data ) ) {
			return array(
				'success' => true,
				'changed' => false,
				'task'    => $previous,
			);
		}

		$data['source']     = $source;
		$data['updated_by'] = $actor_id > 0 ? $actor_id : null;
		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';
		$formats[]          = '%d';
		$formats[]          = '%s';

		$updated = $this->db->update( $this->tasks_table, $data, array( 'id' => $task_id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			return array(
				'success' => false,
				'error'   => 'task_update_failed',
			);
		}

		$task          = $this->get_task( $task_id, $tenant );
		$activity_type = 'task_updated';
		if ( 'completed' === sanitize_key( (string) ( $task['status'] ?? '' ) ) && 'completed' !== sanitize_key( (string) ( $previous['status'] ?? '' ) ) ) {
			$activity_type = 'task_completed';
		} elseif ( 'cancelled' === sanitize_key( (string) ( $task['status'] ?? '' ) ) && 'cancelled' !== sanitize_key( (string) ( $previous['status'] ?? '' ) ) ) {
			$activity_type = 'task_cancelled';
		}

		$result = array(
			'success'  => true,
			'changed'  => $updated > 0,
			'previous' => $previous,
			'task'     => $task,
		);
		if ( $updated > 0 ) {
			$this->record_activity( $task, $activity_type, $source, $actor_id );
			do_action( 'nxtcc_crm_task_updated', $result, $args );
		}

		return $result;
	}

	/**
	 * Remove task rows for permanently deleted contacts.
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
		$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->tasks_table ) . ' WHERE contact_id IN (' . $placeholders . ')', ...$contact_ids ) );
	}

	/**
	 * Record a task timeline activity.
	 *
	 * @param array|null $task Task row.
	 * @param string     $activity_type Activity type.
	 * @param string     $source Source.
	 * @param int        $actor_id Actor ID.
	 * @return void
	 */
	private function record_activity( ?array $task, string $activity_type, string $source, int $actor_id ): void {
		if ( ! is_array( $task ) || ! class_exists( 'NXTCC_CRM_Activities' ) ) {
			return;
		}

		NXTCC_CRM_Activities::instance()->record(
			array(
				'user_mailid'         => $task['user_mailid'] ?? '',
				'business_account_id' => $task['business_account_id'] ?? '',
				'phone_number_id'     => $task['phone_number_id'] ?? '',
				'contact_id'          => absint( $task['contact_id'] ?? 0 ),
				'conversation_id'     => absint( $task['conversation_id'] ?? 0 ),
				'task_id'             => absint( $task['id'] ?? 0 ),
				'activity_type'       => $activity_type,
				'source'              => $source,
				'actor_id'            => $actor_id,
				'metadata'            => array(
					'title'    => sanitize_text_field( (string) ( $task['title'] ?? '' ) ),
					'status'   => sanitize_key( (string) ( $task['status'] ?? '' ) ),
					'priority' => sanitize_key( (string) ( $task['priority'] ?? '' ) ),
					'due_at'   => sanitize_text_field( (string) ( $task['due_at'] ?? '' ) ),
				),
			)
		);
	}

	/**
	 * Normalize task assignee.
	 *
	 * @param array $args Task arguments.
	 * @param array $tenant Tenant tuple.
	 * @return array{valid:bool,user_id:int,role:string}
	 */
	private function normalize_assignee( array $args, array $tenant ): array {
		$user_id = absint( $args['assigned_user_id'] ?? 0 );
		$role    = sanitize_key( (string) ( $args['assigned_role'] ?? '' ) );

		if ( $user_id > 0 ) {
			$access = class_exists( 'NXTCC_Tenant_Access_DAO' ) ? NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant ) : null;
			return array(
				'valid'   => is_array( $access ),
				'user_id' => is_array( $access ) ? $user_id : 0,
				'role'    => '',
			);
		}

		if ( '' !== $role ) {
			$targets = class_exists( 'NXTCC_Contact_Assignments' ) ? NXTCC_Contact_Assignments::instance()->list_targets( $tenant ) : array();
			$roles   = isset( $targets['roles'] ) && is_array( $targets['roles'] ) ? wp_list_pluck( $targets['roles'], 'key' ) : array();
			return array(
				'valid'   => in_array( $role, $roles, true ),
				'user_id' => 0,
				'role'    => in_array( $role, $roles, true ) ? $role : '',
			);
		}

		return array(
			'valid'   => true,
			'user_id' => 0,
			'role'    => '',
		);
	}

	/**
	 * Decorate task rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_rows( array $rows ): array {
		$user_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'assigned_user_id' ) ) ) );
		$user_map = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::get_user_map( $user_ids ) : array();
		$now      = time();

		foreach ( $rows as &$row ) {
			foreach ( array( 'id', 'contact_id', 'conversation_id', 'assigned_user_id', 'created_by', 'updated_by', 'completed_by' ) as $field ) {
				$row[ $field ] = absint( $row[ $field ] ?? 0 );
			}
			$due_timestamp         = ! empty( $row['due_at'] ) ? strtotime( (string) $row['due_at'] . ' UTC' ) : false;
			$row['is_overdue']     = 'open' === (string) ( $row['status'] ?? '' ) && false !== $due_timestamp && $due_timestamp < $now;
			$row['due_at_display'] = ! empty( $row['due_at'] ) ? get_date_from_gmt( (string) $row['due_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
			$row['assignee_label'] = '';
			$assigned_user_id      = absint( $row['assigned_user_id'] ?? 0 );
			$assigned_role         = sanitize_key( (string) ( $row['assigned_role'] ?? '' ) );
			if ( $assigned_user_id > 0 && class_exists( 'NXTCC_Actor_Audit' ) ) {
				$row['assignee_label'] = NXTCC_Actor_Audit::label_for_user_id( $assigned_user_id, $user_map, '' );
			} elseif ( '' !== $assigned_role ) {
				$row['assignee_label'] = ucwords( str_replace( '_', ' ', $assigned_role ) );
			}
		}
		unset( $row );

		return $rows;
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
				'SELECT id FROM ' . $this->quote_table( $this->contacts_table ) . '
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
	 * Normalize priority.
	 *
	 * @param string $priority Raw priority.
	 * @return string
	 */
	private function normalize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );
		return in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ? $priority : 'normal';
	}

	/**
	 * Normalize status filters.
	 *
	 * @param mixed $statuses Raw statuses.
	 * @return array<int,string>
	 */
	private function normalize_statuses( $statuses ): array {
		$statuses = is_array( $statuses ) ? $statuses : array( $statuses );
		$statuses = array_map( 'sanitize_key', array_slice( $statuses, 0, 3 ) );
		return array_values( array_intersect( array_unique( $statuses ), array( 'open', 'completed', 'cancelled' ) ) );
	}

	/**
	 * Normalize UTC MySQL date.
	 *
	 * @param string $value Date value.
	 * @return string|null
	 */
	private function normalize_utc_date( string $value ): ?string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ( false === stripos( $value, 'UTC' ) ? ' UTC' : '' ) );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
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
	 * Return nullable positive ID.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function nullable_id( $value ): ?int {
		$id = absint( $value );
		return $id > 0 ? $id : null;
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
