<?php
/**
 * Tenant-scoped conversation ticket service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned conversation ticket model shared by Inbox, Pro, and integrations.
 */
final class NXTCC_Conversations {

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
	 * Conversations table.
	 *
	 * @var string
	 */
	private string $conversations_table;

	/**
	 * Assignment history table.
	 *
	 * @var string
	 */
	private string $history_table;

	/**
	 * Watchers table.
	 *
	 * @var string
	 */
	private string $watchers_table;

	/**
	 * Shared assignment routing cursor table.
	 *
	 * @var string
	 */
	private string $routing_table;

	/**
	 * Current ticket state table.
	 *
	 * @var string
	 */
	private string $state_table;

	/**
	 * Message history table.
	 *
	 * @var string
	 */
	private string $message_history_table;

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

		$this->db                    = $wpdb;
		$this->conversations_table   = $wpdb->prefix . 'nxtcc_conversations';
		$this->history_table         = $wpdb->prefix . 'nxtcc_conversation_assignment_history';
		$this->watchers_table        = $wpdb->prefix . 'nxtcc_conversation_watchers';
		$this->routing_table         = $wpdb->prefix . 'nxtcc_assignment_routing_state';
		$this->state_table           = $wpdb->prefix . 'nxtcc_contact_ticket_state';
		$this->message_history_table = $wpdb->prefix . 'nxtcc_message_history';
		$this->contacts_table        = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * Return allowed ticket statuses.
	 *
	 * @return array<string,string>
	 */
	public function get_statuses(): array {
		return array(
			'unassigned' => __( 'Unassigned', 'nxt-cloud-chat' ),
			'open'       => __( 'Open', 'nxt-cloud-chat' ),
			'pending'    => __( 'Pending', 'nxt-cloud-chat' ),
			'snoozed'    => __( 'Snoozed', 'nxt-cloud-chat' ),
			'resolved'   => __( 'Resolved', 'nxt-cloud-chat' ),
			'closed'     => __( 'Closed', 'nxt-cloud-chat' ),
		);
	}

	/**
	 * Return allowed priorities.
	 *
	 * @return array<string,string>
	 */
	public function get_priorities(): array {
		return array(
			'low'    => __( 'Low', 'nxt-cloud-chat' ),
			'normal' => __( 'Normal', 'nxt-cloud-chat' ),
			'high'   => __( 'High', 'nxt-cloud-chat' ),
			'urgent' => __( 'Urgent', 'nxt-cloud-chat' ),
		);
	}

	/**
	 * Keep an existing conversation aligned with the shared contact assignment.
	 *
	 * @param int                      $contact_id Contact ID.
	 * @param array<string,mixed>|null $current Current assignment.
	 * @param array<string,mixed>|null $previous Previous assignment.
	 * @param array<string,mixed>      $tenant Tenant tuple.
	 * @param string                   $source Change source.
	 * @param int                      $actor_id Actor ID.
	 * @return void
	 */
	public static function sync_contact_assignment( int $contact_id, ?array $current, ?array $previous, array $tenant, string $source, int $actor_id ): void {
		unset( $previous );

		$service      = self::instance();
		$conversation = $service->get_for_contact( $contact_id, $tenant );
		if ( ! is_array( $conversation ) ) {
			return;
		}

		$assignment_target = '';
		if ( is_array( $current ) ) {
			$target_type = sanitize_key( (string) ( $current['target_type'] ?? '' ) );
			if ( 'user' === $target_type && absint( $current['assigned_user_id'] ?? 0 ) > 0 ) {
				$assignment_target = 'user:' . absint( $current['assigned_user_id'] );
			} elseif ( 'role' === $target_type && '' !== sanitize_key( (string) ( $current['assigned_role'] ?? '' ) ) ) {
				$assignment_target = 'role:' . sanitize_key( (string) $current['assigned_role'] );
			}
		}

		$service->assign(
			array_merge(
				$tenant,
				array(
					'conversation_id'   => absint( $conversation['id'] ?? 0 ),
					'assignment_target' => $assignment_target,
					'note'              => __( 'Contact assignment changed.', 'nxt-cloud-chat' ),
					'reason'            => __( 'Contact assignment changed.', 'nxt-cloud-chat' ),
					'source'            => $source,
					'actor_id'          => $actor_id,
				)
			)
		);
	}

	/**
	 * Return default SLA targets in minutes, filterable by integrations.
	 *
	 * @return array<string,array<string,int>>
	 */
	public function get_sla_targets(): array {
		$targets  = array(
			'low'    => array(
				'first_response' => 240,
				'resolution'     => 2880,
			),
			'normal' => array(
				'first_response' => 60,
				'resolution'     => 1440,
			),
			'high'   => array(
				'first_response' => 30,
				'resolution'     => 480,
			),
			'urgent' => array(
				'first_response' => 15,
				'resolution'     => 240,
			),
		);
		$filtered = apply_filters( 'nxtcc_conversation_sla_targets', $targets );

		return is_array( $filtered ) ? $filtered : $targets;
	}

	/**
	 * Get or create one conversation for a tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Optional creation arguments.
	 * @return array<string,mixed>|null
	 */
	public function get_or_create_for_contact( int $contact_id, array $tenant_args, array $args = array() ): ?array {
		$tenant   = $this->normalize_tenant( $tenant_args );
		$existing = $this->get_for_contact( $contact_id, $tenant );
		if ( null !== $existing ) {
			return $existing;
		}

		return $this->create_ticket( $contact_id, $tenant, $args );
	}

	/**
	 * Create a new ticket for a contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Creation arguments.
	 * @return array<string,mixed>|null
	 */
	public function create_ticket( int $contact_id, array $tenant_args, array $args = array() ): ?array {
		$tenant  = $this->normalize_tenant( $tenant_args );
		$contact = $this->get_contact( $contact_id, $tenant );
		if ( null === $contact ) {
			return null;
		}

		$inherit_assignment = ! isset( $args['inherit_assignment'] ) || ! empty( $args['inherit_assignment'] );
		$assignment         = $inherit_assignment && class_exists( 'NXTCC_Contact_Assignments' ) ? NXTCC_Contact_Assignments::instance()->get_assignment( $contact_id, $tenant ) : null;
		$assigned_user_id   = is_array( $assignment ) && 'user' === (string) ( $assignment['target_type'] ?? '' )
			? absint( $assignment['assigned_user_id'] ?? 0 )
			: 0;
		$assigned_role      = is_array( $assignment ) && 'role' === (string) ( $assignment['target_type'] ?? '' )
			? sanitize_key( (string) ( $assignment['assigned_role'] ?? '' ) )
			: '';
		$requested_target   = sanitize_text_field( (string) ( $args['assignment_target'] ?? '' ) );
		if ( '' !== $requested_target ) {
			$target = $this->normalize_target( $requested_target, $tenant );
			if ( isset( $target['error'] ) ) {
				return null;
			}
			$assigned_user_id = absint( $target['assigned_user_id'] ?? 0 );
			$assigned_role    = sanitize_key( (string) ( $target['assigned_role'] ?? '' ) );
		}
		$opened_at     = $this->normalize_datetime( $args['opened_at'] ?? current_time( 'mysql', true ) ) ?? current_time( 'mysql', true );
		$status        = $assigned_user_id > 0 || '' !== $assigned_role ? 'open' : 'unassigned';
		$priority      = $this->normalize_priority( (string) ( $args['priority'] ?? 'normal' ) );
		$sla           = $this->calculate_sla_deadlines( $opened_at, $priority );
		$ticket_number = 'NXT-' . strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) );
		$actor_id      = absint( $args['actor_id'] ?? 0 );
		$now           = current_time( 'mysql', true );
		$category_id   = absint( $args['category_id'] ?? 0 );
		$category_name = $this->limit_text( $args['category'] ?? '', 100 );
		if ( $category_id > 0 && class_exists( 'NXTCC_Ticket_Categories' ) ) {
			$category = NXTCC_Ticket_Categories::instance()->get( $category_id, $tenant );
			if ( ! is_array( $category ) || empty( $category['is_active'] ) ) {
				return null;
			}
			$category_name = $this->limit_text( $category['category_name'] ?? '', 100 );
		}

		$inserted = $this->db->insert(
			$this->conversations_table,
			array(
				'user_mailid'           => $tenant['user_mailid'],
				'business_account_id'   => $tenant['business_account_id'],
				'phone_number_id'       => $tenant['phone_number_id'],
				'contact_id'            => $contact_id,
				'ticket_number'         => $ticket_number,
				'subject'               => $this->limit_text( $args['subject'] ?? '', 191 ),
				'category_id'           => $category_id > 0 ? $category_id : null,
				'category'              => $category_name,
				'channel'               => 'whatsapp',
				'origin_source'         => $this->normalize_source( (string) ( $args['source'] ?? 'system' ) ),
				'status'                => $status,
				'priority'              => $priority,
				'assigned_user_id'      => $assigned_user_id > 0 ? $assigned_user_id : null,
				'assigned_role'         => '' !== $assigned_role ? $assigned_role : null,
				'assignment_source'     => '' !== $requested_target ? $this->normalize_source( (string) ( $args['source'] ?? 'manual' ) ) : 'system',
				'opened_at'             => $opened_at,
				'first_response_due_at' => $sla['first_response_due_at'],
				'resolution_due_at'     => $sla['resolution_due_at'],
				'last_inbound_at'       => $this->normalize_datetime( $args['last_inbound_at'] ?? null ),
				'last_message_at'       => $this->normalize_datetime( $args['last_message_at'] ?? null ),
				'created_by'            => $actor_id > 0 ? $actor_id : null,
				'updated_by'            => $actor_id > 0 ? $actor_id : null,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		if ( ! $inserted ) {
			return null;
		}

		$conversation = $this->get( absint( $this->db->insert_id ), $tenant );
		if ( is_array( $conversation ) ) {
			$this->set_current_for_contact( $contact_id, absint( $conversation['id'] ), $tenant, $actor_id );
			$this->record_activity(
				$conversation,
				'conversation_status_changed',
				$actor_id,
				'system',
				array(
					'previous_status' => '',
					'status'          => $status,
				)
			);
			do_action( 'nxtcc_conversation_created', $conversation, $args );
		}

		return $conversation;
	}

	/**
	 * List tickets for one contact, newest first.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_contact( int $contact_id, array $tenant_args, int $limit = 50 ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		$limit  = max( 1, min( 100, $limit ) );
		if ( $contact_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$rows        = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->conversations_table ) . '
				WHERE contact_id = %d AND channel = %s AND user_mailid = %s
				AND business_account_id = %s AND phone_number_id = %s
				ORDER BY updated_at DESC, id DESC LIMIT %d',
				$contact_id,
				'whatsapp',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$limit
			),
			ARRAY_A
		);
		$rows        = is_array( $rows ) ? $rows : array();
		$watcher_map = $this->list_watchers_for_conversations( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ), $tenant );

		return array_map(
			function ( array $row ) use ( $watcher_map ): array {
				return $this->decorate( $row, $watcher_map[ absint( $row['id'] ?? 0 ) ] ?? array() );
			},
			$rows
		);
	}

	/**
	 * Set the selected ticket for a contact and inbound routing.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $actor_id Actor ID.
	 * @return bool
	 */
	public function set_current_for_contact( int $contact_id, int $conversation_id, array $tenant_args, int $actor_id = 0 ): bool {
		$tenant       = $this->normalize_tenant( $tenant_args );
		$conversation = $this->get( $conversation_id, $tenant );
		if ( null === $conversation || absint( $conversation['contact_id'] ?? 0 ) !== $contact_id ) {
			return false;
		}

		return false !== $this->db->replace(
			$this->state_table,
			array(
				'user_mailid'             => $tenant['user_mailid'],
				'business_account_id'     => $tenant['business_account_id'],
				'phone_number_id'         => $tenant['phone_number_id'],
				'contact_id'              => $contact_id,
				'channel'                 => 'whatsapp',
				'current_conversation_id' => $conversation_id,
				'updated_by'              => $actor_id > 0 ? $actor_id : null,
				'updated_at'              => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Get one conversation by ID.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get( int $conversation_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $conversation_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->conversations_table ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$conversation_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate( $row ) : null;
	}

	/**
	 * Get one conversation by contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_for_contact( int $contact_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $contact_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT c.*, s.current_conversation_id AS selected_conversation_id FROM ' . $this->quote_table( $this->conversations_table ) . ' c
				LEFT JOIN ' . $this->quote_table( $this->state_table ) . ' s
					ON s.current_conversation_id = c.id
					AND s.user_mailid = c.user_mailid
					AND s.business_account_id = c.business_account_id
					AND s.phone_number_id = c.phone_number_id
					AND s.contact_id = c.contact_id
					AND s.channel = c.channel
				WHERE c.contact_id = %d AND c.channel = %s AND c.user_mailid = %s
				AND c.business_account_id = %s AND c.phone_number_id = %s
				ORDER BY (s.current_conversation_id = c.id) DESC, c.updated_at DESC, c.id DESC LIMIT 1',
				$contact_id,
				'whatsapp',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( absint( $row['selected_conversation_id'] ?? 0 ) <= 0 ) {
			$this->set_current_for_contact( $contact_id, absint( $row['id'] ?? 0 ), $tenant );
		}
		unset( $row['selected_conversation_id'] );
		return $this->decorate( $row );
	}

	/**
	 * Get or create conversations for multiple contacts.
	 *
	 * @param array $contact_ids Contact IDs.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_for_contacts( array $contact_ids, array $tenant ): array {
		$tenant      = $this->normalize_tenant( $tenant );
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$query_args   = array_merge(
			$contact_ids,
			array(
				'whatsapp',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			)
		);
		$rows         = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.* FROM ' . $this->quote_table( $this->conversations_table ) . ' c
				LEFT JOIN ' . $this->quote_table( $this->state_table ) . ' s
					ON s.current_conversation_id = c.id
					AND s.user_mailid = c.user_mailid
					AND s.business_account_id = c.business_account_id
					AND s.phone_number_id = c.phone_number_id
					AND s.contact_id = c.contact_id
					AND s.channel = c.channel
				WHERE c.contact_id IN (' . $placeholders . ') AND c.channel = %s
				AND c.user_mailid = %s AND c.business_account_id = %s AND c.phone_number_id = %s
				AND (
					s.current_conversation_id = c.id
					OR (
						s.current_conversation_id IS NULL
						AND c.id = (
							SELECT c2.id FROM ' . $this->quote_table( $this->conversations_table ) . ' c2
							WHERE c2.user_mailid = c.user_mailid
							AND c2.business_account_id = c.business_account_id
							AND c2.phone_number_id = c.phone_number_id
							AND c2.contact_id = c.contact_id
							AND c2.channel = c.channel
							ORDER BY c2.updated_at DESC, c2.id DESC LIMIT 1
						)
					)
				)
				ORDER BY (s.current_conversation_id = c.id) DESC, c.updated_at DESC, c.id DESC',
				...$query_args
			),
			ARRAY_A
		);
		$rows         = is_array( $rows ) ? $rows : array();
		$watcher_map  = $this->list_watchers_for_conversations( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ), $tenant );
		$map          = array();
		foreach ( $rows as $row ) {
			$contact_id = absint( $row['contact_id'] ?? 0 );
			if ( $contact_id > 0 && ! isset( $map[ $contact_id ] ) ) {
				$map[ $contact_id ] = $this->decorate( $row, $watcher_map[ absint( $row['id'] ?? 0 ) ] ?? array() );
			}
		}

		foreach ( $contact_ids as $contact_id ) {
			if ( isset( $map[ $contact_id ] ) ) {
				continue;
			}
			$conversation = $this->get_or_create_for_contact( $contact_id, $tenant );
			if ( is_array( $conversation ) ) {
				$map[ $contact_id ] = $conversation;
			}
		}

		return $map;
	}

	/**
	 * List a bounded page of active conversations with SLA deadlines.
	 *
	 * This tenant-scoped reader supports Pro catch-up scheduling without
	 * exposing or querying Free-owned conversation tables directly.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $after_id Cursor conversation ID.
	 * @param int   $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_sla_candidates( array $tenant_args, int $after_id = 0, int $limit = 100 ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		$limit  = max( 1, min( 200, $limit ) );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->conversations_table ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND id > %d AND status NOT IN (%s, %s)
				AND (first_response_due_at IS NOT NULL OR resolution_due_at IS NOT NULL)
				ORDER BY id ASC LIMIT %d',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				max( 0, $after_id ),
				'resolved',
				'closed',
				$limit
			),
			ARRAY_A
		);

		return array_map(
			function ( array $row ): array {
				return $this->decorate( $row, array() );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Update ticket details.
	 *
	 * @param array $args Update arguments.
	 * @return array<string,mixed>
	 */
	public function update( array $args ): array {
		$tenant          = $this->normalize_tenant( $args );
		$conversation_id = absint( $args['conversation_id'] ?? 0 );
		$actor_id        = absint( $args['actor_id'] ?? get_current_user_id() );
		$source          = $this->normalize_source( (string) ( $args['source'] ?? 'manual' ) );
		$conversation    = $this->get( $conversation_id, $tenant );
		if ( null === $conversation ) {
			return $this->error( 'conversation_not_found' );
		}

		$data             = array();
		$metadata         = array();
		$details_metadata = array(
			'changed_fields' => array(),
		);
		if ( ! empty( $args['customer_message'] ) ) {
			$details_metadata['changed_fields'][] = 'message';
		}
		if ( array_key_exists( 'subject', $args ) ) {
			$data['subject'] = $this->limit_text( $args['subject'], 191 );
			if ( (string) $data['subject'] !== (string) $conversation['subject'] ) {
				$details_metadata['changed_fields'][] = 'subject';
			}
		}
		if ( array_key_exists( 'category', $args ) ) {
			$data['category'] = $this->limit_text( $args['category'], 100 );
			if ( (string) $data['category'] !== (string) $conversation['category'] ) {
				$details_metadata['changed_fields'][] = 'category';
			}
		}
		if ( array_key_exists( 'category_id', $args ) ) {
			$category_id        = absint( $args['category_id'] );
			$category           = $category_id > 0 && class_exists( 'NXTCC_Ticket_Categories' )
				? NXTCC_Ticket_Categories::instance()->get( $category_id, $tenant )
				: null;
			$keeps_old_category = absint( $conversation['category_id'] ?? 0 ) === $category_id;
			if ( ! is_array( $category ) || ( empty( $category['is_active'] ) && ! $keeps_old_category ) ) {
				return $this->error( 'invalid_ticket_category', __( 'Choose an active ticket category.', 'nxt-cloud-chat' ) );
			}
			$data['category_id'] = $category_id;
			$data['category']    = $this->limit_text( $category['category_name'] ?? '', 100 );
			if ( absint( $conversation['category_id'] ?? 0 ) !== $category_id ) {
				$details_metadata['changed_fields'][] = 'category';
			}
		}
		if ( array_key_exists( 'priority', $args ) ) {
			$data['priority']              = $this->normalize_priority( (string) $args['priority'] );
			$sla                           = $this->calculate_sla_deadlines( (string) $conversation['opened_at'], $data['priority'] );
			$data['first_response_due_at'] = $sla['first_response_due_at'];
			$data['resolution_due_at']     = $sla['resolution_due_at'];
			$metadata['previous_priority'] = $conversation['priority'];
			$metadata['priority']          = $data['priority'];
		}
		if ( array_key_exists( 'status', $args ) ) {
			$requested_status = sanitize_key( (string) $args['status'] );
			$is_reopen        = 'reopened' === $requested_status;
			$status           = $is_reopen ? 'open' : $this->normalize_status( $requested_status );
			if ( $is_reopen && ! in_array( $conversation['status'], array( 'snoozed', 'resolved', 'closed' ), true ) ) {
				return $this->error( 'conversation_not_reopenable' );
			}
			if ( 'snoozed' === $status && null === $this->normalize_datetime( $args['snoozed_until'] ?? null ) ) {
				return $this->error( 'snooze_time_required' );
			}
			$data['status']        = $status;
			$data['snoozed_until'] = 'snoozed' === $status ? $this->normalize_datetime( $args['snoozed_until'] ) : null;
			$data['resolved_at']   = 'resolved' === $status
				? ( ! empty( $conversation['resolved_at'] ) ? $conversation['resolved_at'] : current_time( 'mysql', true ) )
				: ( 'closed' === $status ? $conversation['resolved_at'] : null );
			$data['closed_at']     = 'closed' === $status
				? ( ! empty( $conversation['closed_at'] ) ? $conversation['closed_at'] : current_time( 'mysql', true ) )
				: null;
			if ( $is_reopen ) {
				$sla                       = $this->calculate_sla_deadlines( current_time( 'mysql', true ), (string) $conversation['priority'] );
				$data['reopen_count']      = absint( $conversation['reopen_count'] ?? 0 ) + 1;
				$data['resolution_due_at'] = $sla['resolution_due_at'];
				$metadata['reopened']      = true;
			}
			$metadata['previous_status'] = $conversation['status'];
			$metadata['status']          = $status;
		}
		foreach ( array( 'first_response_due_at', 'resolution_due_at' ) as $deadline_field ) {
			if ( ! array_key_exists( $deadline_field, $args ) ) {
				continue;
			}
			$deadline = $this->normalize_datetime( $args[ $deadline_field ] );
			if ( null === $deadline ) {
				return $this->error( 'invalid_ticket_deadline', __( 'Enter a valid ticket SLA date and time.', 'nxt-cloud-chat' ) );
			}
			$data[ $deadline_field ] = $deadline;
			if ( (string) ( $conversation[ $deadline_field ] ?? '' ) !== (string) $deadline ) {
				$details_metadata['changed_fields'][] = $deadline_field;
			}
		}

		if ( empty( $data ) ) {
			return $this->success( $conversation, false );
		}

		$data['updated_by'] = $actor_id > 0 ? $actor_id : null;
		$data['updated_at'] = current_time( 'mysql', true );
		$updated            = $this->db->update( $this->conversations_table, $data, array( 'id' => $conversation_id ) );
		if ( false === $updated ) {
			return $this->error( 'conversation_update_failed' );
		}

		$current = $this->get( $conversation_id, $tenant );
		if ( isset( $metadata['status'] ) && $metadata['status'] !== $metadata['previous_status'] ) {
			$this->record_activity( $current, 'conversation_status_changed', $actor_id, $source, $metadata );
			if ( ! empty( $metadata['reopened'] ) ) {
				do_action( 'nxtcc_conversation_reopened', $current, $conversation, $args );
			} else {
				do_action( 'nxtcc_conversation_status_changed', $current, $conversation, $args );
			}
		}
		if ( isset( $metadata['priority'] ) && $metadata['priority'] !== $metadata['previous_priority'] ) {
			$this->record_activity( $current, 'conversation_priority_changed', $actor_id, $source, $metadata );
			do_action( 'nxtcc_conversation_priority_changed', $current, $conversation, $args );
		}
		if ( ! empty( $details_metadata['changed_fields'] ) ) {
			$this->record_activity( $current, 'conversation_details_changed', $actor_id, $source, $details_metadata );
			do_action( 'nxtcc_conversation_details_changed', $current, $conversation, $args );
		}

		return $this->success( $current, true );
	}

	/**
	 * Save all editable ticket panel fields in one request.
	 *
	 * @param array $args Ticket values and tenant tuple.
	 * @return array<string,mixed>
	 */
	public function save_ticket( array $args ): array {
		$tenant            = $this->normalize_tenant( $args );
		$conversation_id   = absint( $args['conversation_id'] ?? 0 );
		$contact_id        = absint( $args['contact_id'] ?? 0 );
		$actor_id          = absint( $args['actor_id'] ?? get_current_user_id() );
		$assignment_target = sanitize_text_field( (string) ( $args['assignment_target'] ?? '' ) );
		$subject           = $this->limit_text( $args['subject'] ?? '', 191 );
		$category_id       = absint( $args['category_id'] ?? 0 );
		$internal_note     = $this->limit_note( $args['internal_note'] ?? '' );
		$handoff_note      = $this->limit_note( $args['handoff_note'] ?? '' );
		$customer_message  = sanitize_textarea_field( (string) ( $args['customer_message'] ?? '' ) );
		$customer_message  = function_exists( 'mb_substr' ) ? mb_substr( $customer_message, 0, 4096 ) : substr( $customer_message, 0, 4096 );
		$requested_status  = sanitize_key( (string) ( $args['status'] ?? 'open' ) );
		$is_new            = $conversation_id <= 0;

		if ( ! $this->tenant_is_complete( $tenant ) || $contact_id <= 0 ) {
			return $this->error( 'invalid_ticket_context', __( 'Choose a valid contact before saving the ticket.', 'nxt-cloud-chat' ) );
		}
		if ( '' === $subject || $category_id <= 0 ) {
			return $this->error( 'ticket_required_fields', __( 'Subject and category are required.', 'nxt-cloud-chat' ) );
		}
		if ( '' === $assignment_target ) {
			return $this->error( 'ticket_assignment_required', __( 'Assign the ticket to a member or access team.', 'nxt-cloud-chat' ) );
		}
		if ( 'unassigned' === $requested_status ) {
			return $this->error( 'assigned_ticket_status_required', __( 'Assigned tickets cannot use the Unassigned status.', 'nxt-cloud-chat' ) );
		}
		$normalized_target = $this->normalize_target( $assignment_target, $tenant );
		if ( isset( $normalized_target['error'] ) ) {
			return $normalized_target;
		}

		$conversation = $is_new ? null : $this->get( $conversation_id, $tenant );
		if ( ! $is_new && ( ! is_array( $conversation ) || absint( $conversation['contact_id'] ?? 0 ) !== $contact_id ) ) {
			return $this->error( 'conversation_not_found' );
		}

		$category           = class_exists( 'NXTCC_Ticket_Categories' ) ? NXTCC_Ticket_Categories::instance()->get( $category_id, $tenant ) : null;
		$keeps_old_category = ! $is_new && absint( $conversation['category_id'] ?? 0 ) === $category_id;
		if ( ! is_array( $category ) || ( empty( $category['is_active'] ) && ! $keeps_old_category ) ) {
			return $this->error( 'invalid_ticket_category', __( 'Choose an active ticket category.', 'nxt-cloud-chat' ) );
		}

		if ( $is_new ) {
			$conversation = $this->create_ticket(
				$contact_id,
				$tenant,
				array(
					'subject'            => $subject,
					'category_id'        => $category_id,
					'priority'           => (string) ( $args['priority'] ?? 'normal' ),
					'assignment_target'  => $assignment_target,
					'customer_message'   => $customer_message,
					'source'             => 'manual',
					'actor_id'           => $actor_id,
					'inherit_assignment' => false,
				)
			);
			if ( ! is_array( $conversation ) ) {
				return $this->error( 'ticket_create_failed', __( 'Unable to create the ticket.', 'nxt-cloud-chat' ) );
			}
			$conversation_id = absint( $conversation['id'] ?? 0 );
		}

		$assignment = $this->assign(
			array_merge(
				$tenant,
				array(
					'conversation_id'   => $conversation_id,
					'assignment_target' => $assignment_target,
					'note'              => $handoff_note,
					'reason'            => $this->limit_text( $args['handoff_reason'] ?? '', 191 ),
					'customer_message'  => $customer_message,
					'actor_id'          => $actor_id,
					'source'            => 'manual',
				)
			)
		);
		if ( empty( $assignment['success'] ) ) {
			return $assignment;
		}

		$update_args = array_merge(
			$tenant,
			array(
				'conversation_id'  => $conversation_id,
				'subject'          => $subject,
				'category_id'      => $category_id,
				'status'           => $requested_status,
				'priority'         => (string) ( $args['priority'] ?? 'normal' ),
				'customer_message' => $customer_message,
				'actor_id'         => $actor_id,
				'source'           => 'manual',
			)
		);
		foreach ( array( 'snoozed_until', 'first_response_due_at', 'resolution_due_at' ) as $date_field ) {
			if ( ! empty( $args[ $date_field ] ) ) {
				$update_args[ $date_field ] = $args[ $date_field ];
			}
		}

		$updated = $this->update( $update_args );
		if ( empty( $updated['success'] ) ) {
			return $updated;
		}

		if ( '' !== $internal_note ) {
			$note_result = $this->add_note(
				array_merge(
					$tenant,
					array(
						'conversation_id' => $conversation_id,
						'note'            => $internal_note,
						'actor_id'        => $actor_id,
						'source'          => 'manual',
					)
				)
			);
			if ( empty( $note_result['success'] ) ) {
				return $note_result;
			}
		}

		$this->set_current_for_contact( $contact_id, $conversation_id, $tenant, $actor_id );
		return array(
			'success'      => true,
			'created'      => $is_new,
			'changed'      => ! empty( $assignment['changed'] ) || ! empty( $updated['changed'] ) || '' !== $internal_note,
			'conversation' => $this->get( $conversation_id, $tenant ),
		);
	}

	/**
	 * Assign or hand off a conversation.
	 *
	 * A handoff note is required whenever an existing assignment changes.
	 *
	 * @param array $args Assignment arguments.
	 * @return array<string,mixed>
	 */
	public function assign( array $args ): array {
		$tenant          = $this->normalize_tenant( $args );
		$conversation_id = absint( $args['conversation_id'] ?? 0 );
		$actor_id        = absint( $args['actor_id'] ?? get_current_user_id() );
		$source          = $this->normalize_source( (string) ( $args['source'] ?? 'manual' ) );
		$note            = $this->limit_note( $args['note'] ?? '' );
		$reason          = $this->limit_text( $args['reason'] ?? '', 191 );
		$conversation    = $this->get( $conversation_id, $tenant );
		if ( null === $conversation ) {
			return $this->error( 'conversation_not_found' );
		}

		$target = $this->normalize_target( (string) ( $args['assignment_target'] ?? '' ), $tenant );
		if ( isset( $target['error'] ) ) {
			return $target;
		}

		$previous_user = absint( $conversation['assigned_user_id'] ?? 0 );
		$previous_role = sanitize_key( (string) ( $conversation['assigned_role'] ?? '' ) );
		$changed       = absint( $target['assigned_user_id'] ?? 0 ) !== $previous_user
			|| sanitize_key( (string) ( $target['assigned_role'] ?? '' ) ) !== $previous_role;
		if ( ! $changed ) {
			return $this->success( $conversation, false );
		}

		$is_handoff = $previous_user > 0 || '' !== $previous_role;
		if ( $is_handoff && '' === $note ) {
			return $this->error( 'handoff_note_required', __( 'Add an internal handoff note before changing the assignee.', 'nxt-cloud-chat' ) );
		}

		$note_activity_id = 0;
		if ( '' !== $note ) {
			$note_result      = $this->add_note(
				array_merge(
					$tenant,
					array(
						'conversation_id' => $conversation_id,
						'note'            => $note,
						'actor_id'        => $actor_id,
						'source'          => $source,
						'note_type'       => $is_handoff ? 'handoff' : 'assignment',
					)
				)
			);
			$note_activity_id = absint( $note_result['activity_id'] ?? 0 );
			if ( empty( $note_result['success'] ) ) {
				return $note_result;
			}
		}

		$now     = current_time( 'mysql', true );
		$status  = absint( $target['assigned_user_id'] ?? 0 ) > 0 || '' !== (string) ( $target['assigned_role'] ?? '' )
			? ( 'unassigned' === $conversation['status'] ? 'open' : $conversation['status'] )
			: 'unassigned';
		$updated = $this->db->update(
			$this->conversations_table,
			array(
				'assigned_user_id'  => absint( $target['assigned_user_id'] ?? 0 ) > 0 ? absint( $target['assigned_user_id'] ) : null,
				'assigned_role'     => '' !== (string) ( $target['assigned_role'] ?? '' ) ? sanitize_key( (string) $target['assigned_role'] ) : null,
				'assignment_source' => $source,
				'status'            => $status,
				'updated_by'        => $actor_id > 0 ? $actor_id : null,
				'updated_at'        => $now,
			),
			array( 'id' => $conversation_id )
		);
		if ( false === $updated ) {
			return $this->error( 'conversation_assignment_failed' );
		}

		$this->db->insert(
			$this->history_table,
			array(
				'user_mailid'               => $tenant['user_mailid'],
				'business_account_id'       => $tenant['business_account_id'],
				'phone_number_id'           => $tenant['phone_number_id'],
				'conversation_id'           => $conversation_id,
				'previous_assigned_user_id' => $previous_user > 0 ? $previous_user : null,
				'previous_assigned_role'    => '' !== $previous_role ? $previous_role : null,
				'new_assigned_user_id'      => absint( $target['assigned_user_id'] ?? 0 ) > 0 ? absint( $target['assigned_user_id'] ) : null,
				'new_assigned_role'         => '' !== (string) ( $target['assigned_role'] ?? '' ) ? sanitize_key( (string) $target['assigned_role'] ) : null,
				'handoff_type'              => $is_handoff ? 'handoff' : 'assignment',
				'reason'                    => '' !== $reason ? $reason : null,
				'note_activity_id'          => $note_activity_id > 0 ? $note_activity_id : null,
				'source'                    => $source,
				'changed_by'                => $actor_id > 0 ? $actor_id : null,
				'created_at'                => $now,
			)
		);

		$current = $this->get( $conversation_id, $tenant );
		$this->record_activity(
			$current,
			'conversation_assigned',
			$actor_id,
			$source,
			array(
				'previous_assigned_user_id' => $previous_user,
				'previous_assigned_role'    => $previous_role,
				'assigned_user_id'          => absint( $target['assigned_user_id'] ?? 0 ),
				'assigned_role'             => sanitize_key( (string) ( $target['assigned_role'] ?? '' ) ),
				'handoff'                   => $is_handoff,
				'reason'                    => $reason,
				'note_activity_id'          => $note_activity_id,
			)
		);
		if ( $status !== (string) $conversation['status'] ) {
			$this->record_activity(
				$current,
				'conversation_status_changed',
				$actor_id,
				$source,
				array(
					'previous_status' => $conversation['status'],
					'status'          => $status,
					'changed_by'      => 'assignment',
				)
			);
			do_action( 'nxtcc_conversation_status_changed', $current, $conversation, $args );
		}
		do_action( 'nxtcc_conversation_assignment_updated', $current, $conversation, $args );

		return $this->success( $current, true );
	}

	/**
	 * Automatically assign a conversation to an eligible tenant team member.
	 *
	 * Supported strategies are round robin and least busy. Existing
	 * assignments are preserved unless overwrite is explicitly enabled.
	 *
	 * @param array $args Routing arguments.
	 * @return array<string,mixed>
	 */
	public function auto_assign( array $args ): array {
		$tenant          = $this->normalize_tenant( $args );
		$conversation_id = absint( $args['conversation_id'] ?? 0 );
		$conversation    = $this->get( $conversation_id, $tenant );
		$strategy        = sanitize_key( (string) ( $args['strategy'] ?? 'round_robin' ) );
		$role_key        = $this->normalize_assignment_pool_key( $args );
		$overwrite       = ! empty( $args['overwrite'] );

		if ( null === $conversation ) {
			return $this->error( 'conversation_not_found' );
		}

		if ( ! in_array( $strategy, array( 'round_robin', 'least_busy' ), true ) ) {
			return $this->error( 'invalid_conversation_assignment_strategy' );
		}

		if ( ( absint( $conversation['assigned_user_id'] ?? 0 ) > 0 || '' !== sanitize_key( (string) ( $conversation['assigned_role'] ?? '' ) ) ) && ! $overwrite ) {
			return array(
				'success'      => true,
				'changed'      => false,
				'skipped'      => true,
				'reason'       => 'already_assigned',
				'conversation' => $conversation,
			);
		}

		$users = $this->eligible_routing_users( $tenant, $role_key );
		if ( empty( $users ) ) {
			return $this->error( 'no_eligible_assignment_targets', __( 'No eligible tenant team members are available for this routing pool.', 'nxt-cloud-chat' ) );
		}

		if ( 'least_busy' === $strategy ) {
			$users = $this->least_busy_users( $users, $tenant );
		}

		$route_key      = $this->normalize_route_key( (string) ( $args['route_key'] ?? '' ), $strategy, $role_key );
		$cursor         = $this->next_route_cursor( $tenant, $route_key );
		$route_fallback = false;
		if ( $cursor <= 0 ) {
			$route_fallback = true;
			$cursor         = wp_rand( 1, count( $users ) );
		}

		$selected = $users[ ( $cursor - 1 ) % count( $users ) ];
		$result   = $this->assign(
			array_merge(
				$tenant,
				array(
					'conversation_id'   => $conversation_id,
					'assignment_target' => 'user:' . absint( $selected['id'] ?? 0 ),
					'note'              => (string) ( $args['note'] ?? '' ),
					'reason'            => (string) ( $args['reason'] ?? '' ),
					'source'            => (string) ( $args['source'] ?? 'integration' ),
					'actor_id'          => absint( $args['actor_id'] ?? 0 ),
				)
			)
		);

		$result['strategy']         = $strategy;
		$result['route_key']        = $route_key;
		$result['role_key']         = $role_key;
		$result['team_key']         = $role_key;
		$result['cursor']           = $cursor;
		$result['route_fallback']   = $route_fallback;
		$result['selected_user_id'] = absint( $selected['id'] ?? 0 );

		return $result;
	}

	/**
	 * Add an internal note.
	 *
	 * @param array $args Note arguments.
	 * @return array<string,mixed>
	 */
	public function add_note( array $args ): array {
		$tenant          = $this->normalize_tenant( $args );
		$conversation_id = absint( $args['conversation_id'] ?? 0 );
		$conversation    = $this->get( $conversation_id, $tenant );
		$note            = $this->limit_note( $args['note'] ?? '' );
		if ( null === $conversation ) {
			return $this->error( 'conversation_not_found' );
		}
		if ( '' === $note ) {
			return $this->error( 'internal_note_required' );
		}

		$result = NXTCC_CRM_Activities::instance()->record(
			array_merge(
				$tenant,
				array(
					'contact_id'      => absint( $conversation['contact_id'] ),
					'conversation_id' => $conversation_id,
					'activity_type'   => 'internal_note_added',
					'note_content'    => $note,
					'source'          => $this->normalize_source( (string) ( $args['source'] ?? 'manual' ) ),
					'actor_id'        => absint( $args['actor_id'] ?? get_current_user_id() ),
					'metadata'        => array(
						'note_type' => sanitize_key( (string) ( $args['note_type'] ?? 'internal' ) ),
					),
				)
			)
		);
		if ( ! empty( $result['success'] ) ) {
			do_action( 'nxtcc_conversation_internal_note_added', $conversation, $result, $args );
		}

		return $result;
	}

	/**
	 * List conversation activities.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_activity( int $conversation_id, array $tenant, int $limit = 100 ): array {
		$conversation = $this->get( $conversation_id, $tenant );
		if ( null === $conversation ) {
			return array();
		}

		return NXTCC_CRM_Activities::instance()->list_for_conversation( $conversation_id, $tenant, array( 'limit' => $limit ) );
	}

	/**
	 * Add or remove the current user as a watcher.
	 *
	 * @param array $args Watcher arguments.
	 * @return array<string,mixed>
	 */
	public function set_watcher( array $args ): array {
		$tenant          = $this->normalize_tenant( $args );
		$conversation_id = absint( $args['conversation_id'] ?? 0 );
		$user_id         = absint( $args['wp_user_id'] ?? get_current_user_id() );
		$watch           = ! empty( $args['watch'] );
		if ( null === $this->get( $conversation_id, $tenant ) || ! is_array( NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant ) ) ) {
			return $this->error( 'invalid_conversation_watcher' );
		}

		if ( ! $watch ) {
			$this->db->delete(
				$this->watchers_table,
				array(
					'conversation_id' => $conversation_id,
					'wp_user_id'      => $user_id,
				)
			);
		} else {
			$this->db->replace(
				$this->watchers_table,
				array(
					'user_mailid'             => $tenant['user_mailid'],
					'business_account_id'     => $tenant['business_account_id'],
					'phone_number_id'         => $tenant['phone_number_id'],
					'conversation_id'         => $conversation_id,
					'wp_user_id'              => $user_id,
					'notification_preference' => 'all',
					'added_by'                => absint( $args['actor_id'] ?? get_current_user_id() ),
					'created_at'              => current_time( 'mysql', true ),
				)
			);
		}

		do_action( 'nxtcc_conversation_watcher_updated', $conversation_id, $user_id, $watch, $tenant );
		return array(
			'success'  => true,
			'watching' => $watch,
			'watchers' => $this->list_watchers( $conversation_id, $tenant ),
		);
	}

	/**
	 * List conversation watchers.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_watchers( int $conversation_id, array $tenant_args ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $conversation_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT wp_user_id, notification_preference, added_by, created_at
				FROM ' . $this->quote_table( $this->watchers_table ) . '
				WHERE conversation_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s ORDER BY id ASC',
				$conversation_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$user                   = get_userdata( absint( $row['wp_user_id'] ?? 0 ) );
			$row['wp_user_id']      = absint( $row['wp_user_id'] ?? 0 );
			$row['label']           = $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : __( 'Unknown user', 'nxt-cloud-chat' );
			$row['is_current_user'] = get_current_user_id() === $row['wp_user_id'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * List watchers for multiple conversations in one query.
	 *
	 * @param array<int,mixed> $conversation_ids Conversation IDs.
	 * @param array            $tenant_args Tenant tuple.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private function list_watchers_for_conversations( array $conversation_ids, array $tenant_args ): array {
		$tenant           = $this->normalize_tenant( $tenant_args );
		$conversation_ids = array_values( array_unique( array_filter( array_map( 'absint', $conversation_ids ) ) ) );
		if ( empty( $conversation_ids ) || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $conversation_ids ), '%d' ) );
		$query_args   = array_merge(
			$conversation_ids,
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			)
		);
		$rows         = $this->db->get_results(
			$this->db->prepare(
				'SELECT conversation_id, wp_user_id, notification_preference, added_by, created_at
				FROM ' . $this->quote_table( $this->watchers_table ) . '
				WHERE conversation_id IN (' . $placeholders . ')
				AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY id ASC',
				...$query_args
			),
			ARRAY_A
		);
		$map          = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$conversation_id           = absint( $row['conversation_id'] ?? 0 );
			$user_id                   = absint( $row['wp_user_id'] ?? 0 );
			$user                      = get_userdata( $user_id );
			$row['wp_user_id']         = $user_id;
			$row['label']              = $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : __( 'Unknown user', 'nxt-cloud-chat' );
			$row['is_current_user']    = get_current_user_id() === $user_id;
			$map[ $conversation_id ][] = $row;
		}

		return $map;
	}

	/**
	 * Delete conversation ticket data for contacts being permanently deleted.
	 *
	 * @param array<int,mixed> $contact_ids Contact IDs.
	 * @return void
	 */
	public function delete_contact_data( array $contact_ids ): void {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders     = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$conversation_ids = $this->db->get_col(
			$this->db->prepare(
				'SELECT id FROM ' . $this->quote_table( $this->conversations_table ) . ' WHERE contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);
		$conversation_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $conversation_ids ) ? $conversation_ids : array() ) ) ) );

		if ( ! empty( $conversation_ids ) ) {
			$conversation_placeholders = implode( ',', array_fill( 0, count( $conversation_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
			$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->watchers_table ) . ' WHERE conversation_id IN (' . $conversation_placeholders . ')', ...$conversation_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
			$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->history_table ) . ' WHERE conversation_id IN (' . $conversation_placeholders . ')', ...$conversation_ids ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
		$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->state_table ) . ' WHERE contact_id IN (' . $placeholders . ')', ...$contact_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
		$this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->quote_table( $this->conversations_table ) . ' WHERE contact_id IN (' . $placeholders . ')', ...$contact_ids ) );
	}

	/**
	 * Capture a new inbound message and reopen resolved tickets.
	 *
	 * @param array $event Inbound event.
	 * @return void
	 */
	public static function capture_inbound( array $event ): void {
		$service      = self::instance();
		$contact_id   = absint( $event['contact_id'] ?? 0 );
		$received_at  = $service->normalize_datetime( $event['received_at'] ?? null ) ?? current_time( 'mysql', true );
		$conversation = $service->get_or_create_for_contact(
			$contact_id,
			$event,
			array(
				'opened_at'       => $received_at,
				'last_inbound_at' => $received_at,
				'last_message_at' => $received_at,
				'source'          => 'webhook',
			)
		);
		if ( null === $conversation ) {
			return;
		}

		$history_id = absint( $event['history_id'] ?? 0 );
		if ( $history_id > 0 ) {
			$service->db->update(
				$service->message_history_table,
				array( 'conversation_id' => absint( $conversation['id'] ) ),
				array(
					'id'                  => $history_id,
					'user_mailid'         => sanitize_email( (string) ( $event['user_mailid'] ?? '' ) ),
					'business_account_id' => sanitize_text_field( (string) ( $event['business_account_id'] ?? '' ) ),
					'phone_number_id'     => sanitize_text_field( (string) ( $event['phone_number_id'] ?? '' ) ),
				)
			);
		}

		$data = array(
			'last_inbound_at' => $received_at,
			'last_message_at' => $received_at,
			'updated_at'      => current_time( 'mysql', true ),
		);
		if ( in_array( $conversation['status'], array( 'snoozed', 'resolved', 'closed' ), true ) ) {
			$sla                       = $service->calculate_sla_deadlines( $received_at, (string) $conversation['priority'] );
			$data['status']            = 'open';
			$data['resolved_at']       = null;
			$data['closed_at']         = null;
			$data['snoozed_until']     = null;
			$data['resolution_due_at'] = $sla['resolution_due_at'];
			$data['reopen_count']      = absint( $conversation['reopen_count'] ?? 0 ) + 1;
		}
		$service->db->update( $service->conversations_table, $data, array( 'id' => absint( $conversation['id'] ) ) );
		if ( isset( $data['status'] ) ) {
			$current = $service->get( absint( $conversation['id'] ), $event );
			$service->record_activity(
				$current,
				'conversation_status_changed',
				0,
				'webhook',
				array(
					'previous_status' => $conversation['status'],
					'status'          => 'open',
					'reopened_by'     => 'inbound_message',
				)
			);
			do_action( 'nxtcc_conversation_reopened', $current, $conversation, $event );
		}
	}

	/**
	 * Mark an outbound reply for SLA timestamps.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param array       $tenant Tenant tuple.
	 * @param string|null $sent_at UTC timestamp.
	 * @param string      $source Change source.
	 * @return bool Whether the ticket timestamp update succeeded.
	 */
	public function touch_outbound( int $contact_id, array $tenant, ?string $sent_at = null, string $source = 'manual' ): bool {
		$conversation = $this->get_or_create_for_contact( $contact_id, $tenant );
		if ( null === $conversation ) {
			return false;
		}

		return $this->touch_outbound_for_ticket( absint( $conversation['id'] ), $tenant, $sent_at, $source );
	}

	/**
	 * Mark an outbound reply against an explicit ticket.
	 *
	 * @param int         $conversation_id Conversation ID.
	 * @param array       $tenant Tenant tuple.
	 * @param string|null $sent_at UTC timestamp.
	 * @param string      $source Change source.
	 * @return bool
	 */
	public function touch_outbound_for_ticket( int $conversation_id, array $tenant, ?string $sent_at = null, string $source = 'manual' ): bool {
		$conversation = $this->get( $conversation_id, $tenant );
		if ( null === $conversation ) {
			return false;
		}

		$source  = $this->normalize_source( $source );
		$sent_at = $this->normalize_datetime( $sent_at ) ?? current_time( 'mysql', true );
		$data    = array(
			'last_outbound_at' => $sent_at,
			'last_message_at'  => $sent_at,
			'updated_at'       => current_time( 'mysql', true ),
		);
		$updated = $this->db->update( $this->conversations_table, $data, array( 'id' => absint( $conversation['id'] ) ) );
		if ( false === $updated ) {
			return false;
		}

		$first_response_updated = false;
		if ( empty( $conversation['first_response_at'] ) ) {
			$first_response_updated = 1 === $this->db->update(
				$this->conversations_table,
				array( 'first_response_at' => $sent_at ),
				array(
					'id'                => absint( $conversation['id'] ),
					'first_response_at' => null,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
		if ( $first_response_updated ) {
			$current = $this->get( absint( $conversation['id'] ), $tenant );
			$this->record_activity(
				$current,
				'conversation_first_response_recorded',
				get_current_user_id(),
				$source,
				array(
					'first_response_at' => $sent_at,
				)
			);
			do_action(
				'nxtcc_conversation_first_response_recorded',
				$current,
				$conversation,
				array_merge(
					$tenant,
					array(
						'source' => $source,
					)
				)
			);
		}

		$this->set_current_for_contact( absint( $conversation['contact_id'] ), $conversation_id, $tenant, get_current_user_id() );
		return true;
	}

	/**
	 * Normalize one assignment target.
	 *
	 * @param string $compact Compact target.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>
	 */
	private function normalize_target( string $compact, array $tenant ): array {
		$compact = sanitize_text_field( $compact );
		if ( '' === $compact ) {
			return array(
				'assigned_user_id' => 0,
				'assigned_role'    => '',
			);
		}

		$targets = NXTCC_Contact_Assignments::instance()->list_targets( $tenant );
		if ( 0 === strpos( $compact, 'user:' ) ) {
			$user_id = absint( substr( $compact, 5 ) );
			foreach ( $targets['users'] as $target ) {
				if ( absint( $target['id'] ?? 0 ) === $user_id ) {
					return array(
						'assigned_user_id' => $user_id,
						'assigned_role'    => '',
					);
				}
			}
		}
		if ( 0 === strpos( $compact, 'role:' ) ) {
			$role_key = sanitize_key( substr( $compact, 5 ) );
			foreach ( $targets['roles'] as $target ) {
				if ( sanitize_key( (string) ( $target['key'] ?? '' ) ) === $role_key ) {
					return array(
						'assigned_user_id' => 0,
						'assigned_role'    => $role_key,
					);
				}
			}
		}

		return $this->error( 'invalid_conversation_assignment_target' );
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
	 * Return eligible users in a stable routing order.
	 *
	 * @param array  $tenant Tenant tuple.
	 * @param string $role_key Optional access role pool.
	 * @return array<int,array<string,mixed>>
	 */
	private function eligible_routing_users( array $tenant, string $role_key ): array {
		$targets = NXTCC_Contact_Assignments::instance()->list_targets( $tenant );
		$users   = isset( $targets['users'] ) && is_array( $targets['users'] ) ? $targets['users'] : array();
		$users   = array_values(
			array_filter(
				$users,
				static function ( array $user ) use ( $role_key ): bool {
					return ! empty( $user['assignment_eligible'] ) && ( '' === $role_key || sanitize_key( (string) ( $user['role_key'] ?? '' ) ) === $role_key );
				}
			)
		);

		usort(
			$users,
			static function ( array $left, array $right ): int {
				return absint( $left['id'] ?? 0 ) <=> absint( $right['id'] ?? 0 );
			}
		);

		return $users;
	}

	/**
	 * Keep only users with the lowest active conversation workload.
	 *
	 * @param array $users Eligible users.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private function least_busy_users( array $users, array $tenant ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $users, 'id' ) ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$query_args   = array_merge(
			array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ),
			$user_ids,
			array( 'open', 'pending', 'snoozed' )
		);
		$rows         = $this->db->get_results(
			$this->db->prepare(
				'SELECT assigned_user_id, COUNT(*) AS workload
				FROM ' . $this->quote_table( $this->conversations_table ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND assigned_user_id IN (' . $placeholders . ')
				AND status IN (%s, %s, %s)
				GROUP BY assigned_user_id',
				...$query_args
			),
			ARRAY_A
		);
		$workloads    = array_fill_keys( $user_ids, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = absint( $row['assigned_user_id'] ?? 0 );
			if ( isset( $workloads[ $user_id ] ) ) {
				$workloads[ $user_id ] = absint( $row['workload'] ?? 0 );
			}
		}

		$minimum = min( $workloads );
		return array_values(
			array_filter(
				$users,
				static function ( array $user ) use ( $workloads, $minimum ): bool {
					$user_id = absint( $user['id'] ?? 0 );
					return isset( $workloads[ $user_id ] ) && $minimum === $workloads[ $user_id ];
				}
			)
		);
	}

	/**
	 * Normalize an isolated conversation routing key.
	 *
	 * @param string $route_key Requested route key.
	 * @param string $strategy Routing strategy.
	 * @param string $role_key Optional role pool.
	 * @return string
	 */
	private function normalize_route_key( string $route_key, string $strategy, string $role_key ): string {
		$route_key = sanitize_key( $route_key );
		if ( '' === $route_key ) {
			$route_key = $strategy . '_' . ( '' !== $role_key ? $role_key : 'all' );
		}

		return substr( 'conversation_' . $route_key, 0, 191 );
	}

	/**
	 * Atomically advance and return one shared assignment route cursor.
	 *
	 * @param array  $tenant Tenant tuple.
	 * @param string $route_key Route key.
	 * @return int
	 */
	private function next_route_cursor( array $tenant, string $route_key ): int {
		$now        = current_time( 'mysql', true );
		$route_hash = hash( 'sha256', implode( '|', array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'], $route_key ) ) );
		$sql        = 'INSERT INTO ' . $this->quote_table( $this->routing_table ) . '
			(route_hash, user_mailid, business_account_id, phone_number_id, route_key, route_cursor, updated_at)
			VALUES (%s, %s, %s, %s, %s, LAST_INSERT_ID(1), %s)
			ON DUPLICATE KEY UPDATE route_cursor = LAST_INSERT_ID(route_cursor + 1), updated_at = VALUES(updated_at)';
		$prepared   = $this->db->prepare(
			$sql,
			$route_hash,
			$tenant['user_mailid'],
			$tenant['business_account_id'],
			$tenant['phone_number_id'],
			$route_key,
			$now
		);
		$updated    = $this->db->query( $prepared );

		if ( false === $updated && function_exists( 'nxtcc_install_db_schema' ) ) {
			nxtcc_install_db_schema();
			$updated = $this->db->query( $prepared );
		}

		return false === $updated ? 0 : absint( $this->db->get_var( 'SELECT LAST_INSERT_ID()' ) );
	}

	/**
	 * Decorate one conversation.
	 *
	 * @param array      $row Raw row.
	 * @param array|null $watchers Preloaded watchers, or null to query them.
	 * @return array<string,mixed>
	 */
	private function decorate( array $row, ?array $watchers = null ): array {
		foreach ( array( 'id', 'contact_id', 'category_id', 'assigned_user_id', 'reopen_count' ) as $field ) {
			$row[ $field ] = absint( $row[ $field ] ?? 0 );
		}
		$row['assigned_role']     = sanitize_key( (string) ( $row['assigned_role'] ?? '' ) );
		$row['origin_source']     = $this->normalize_source( (string) ( $row['origin_source'] ?? 'system' ) );
		$row['status']            = $this->normalize_status( (string) ( $row['status'] ?? '' ) );
		$row['priority']          = $this->normalize_priority( (string) ( $row['priority'] ?? '' ) );
		$row['assignment_label']  = __( 'Unassigned', 'nxt-cloud-chat' );
		$row['assignment_target'] = '';
		if ( $row['assigned_user_id'] > 0 ) {
			$user                     = get_userdata( $row['assigned_user_id'] );
			$row['assignment_label']  = $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : __( 'Unknown user', 'nxt-cloud-chat' );
			$row['assignment_target'] = 'user:' . (string) $row['assigned_user_id'];
		} elseif ( '' !== $row['assigned_role'] ) {
			$presets = NXTCC_Access_Control::get_role_presets();
			if ( class_exists( 'NXTCC_Access_Teams' ) ) {
				$presets = NXTCC_Access_Teams::merge_with_defaults(
					array(
						'user_mailid'         => isset( $row['user_mailid'] ) ? (string) $row['user_mailid'] : '',
						'business_account_id' => isset( $row['business_account_id'] ) ? (string) $row['business_account_id'] : '',
						'phone_number_id'     => isset( $row['phone_number_id'] ) ? (string) $row['phone_number_id'] : '',
					),
					array()
				);
			}
			$row['assignment_label']  = isset( $presets[ $row['assigned_role'] ]['label'] ) ? (string) $presets[ $row['assigned_role'] ]['label'] : ucwords( str_replace( '_', ' ', $row['assigned_role'] ) );
			$row['assignment_target'] = 'role:' . $row['assigned_role'];
		}
		$row['watchers'] = null === $watchers ? $this->list_watchers( $row['id'], $row ) : $watchers;
		foreach ( array( 'opened_at', 'first_response_at', 'resolved_at', 'closed_at', 'snoozed_until', 'first_response_due_at', 'resolution_due_at', 'last_inbound_at', 'last_outbound_at', 'last_message_at', 'created_at', 'updated_at' ) as $date_field ) {
			$row[ $date_field . '_display' ] = ! empty( $row[ $date_field ] )
				? get_date_from_gmt( (string) $row[ $date_field ], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				: '';
		}
		$row['snoozed_until_local_input']         = ! empty( $row['snoozed_until'] )
			? get_date_from_gmt( (string) $row['snoozed_until'], 'Y-m-d\TH:i' )
			: '';
		$row['first_response_due_at_local_input'] = ! empty( $row['first_response_due_at'] )
			? get_date_from_gmt( (string) $row['first_response_due_at'], 'Y-m-d\TH:i' )
			: '';
		$row['resolution_due_at_local_input']     = ! empty( $row['resolution_due_at'] )
			? get_date_from_gmt( (string) $row['resolution_due_at'], 'Y-m-d\TH:i' )
			: '';
		$now                                      = current_time( 'mysql', true );
		$row['first_response_overdue']            = empty( $row['first_response_at'] ) && ! empty( $row['first_response_due_at'] ) && (string) $row['first_response_due_at'] < $now;
		$row['resolution_overdue']                = ! in_array( $row['status'], array( 'resolved', 'closed' ), true ) && ! empty( $row['resolution_due_at'] ) && (string) $row['resolution_due_at'] < $now;

		return $row;
	}

	/**
	 * Record a conversation activity.
	 *
	 * @param array|null $conversation Conversation.
	 * @param string     $activity_type Activity type.
	 * @param int        $actor_id Actor ID.
	 * @param string     $source Source.
	 * @param array      $metadata Metadata.
	 * @return void
	 */
	private function record_activity( ?array $conversation, string $activity_type, int $actor_id, string $source, array $metadata ): void {
		if ( ! is_array( $conversation ) ) {
			return;
		}

		NXTCC_CRM_Activities::instance()->record(
			array(
				'user_mailid'         => $conversation['user_mailid'],
				'business_account_id' => $conversation['business_account_id'],
				'phone_number_id'     => $conversation['phone_number_id'],
				'contact_id'          => absint( $conversation['contact_id'] ),
				'conversation_id'     => absint( $conversation['id'] ),
				'activity_type'       => $activity_type,
				'source'              => $source,
				'actor_id'            => $actor_id,
				'metadata'            => $metadata,
			)
		);
	}

	/**
	 * Get a tenant contact.
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
	 * Normalize tenant tuple.
	 *
	 * @param array $args Tenant data.
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
	 * Normalize status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		return isset( $this->get_statuses()[ $status ] ) ? $status : 'open';
	}

	/**
	 * Normalize priority.
	 *
	 * @param string $priority Priority.
	 * @return string
	 */
	private function normalize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );
		return isset( $this->get_priorities()[ $priority ] ) ? $priority : 'normal';
	}

	/**
	 * Normalize source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, array( 'manual', 'workflow', 'integration', 'system', 'webhook' ), true ) ? $source : 'integration';
	}

	/**
	 * Normalize UTC datetime.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function normalize_datetime( $value ): ?string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}

		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : null;
	}

	/**
	 * Calculate SLA deadlines from one UTC start time.
	 *
	 * @param string $started_at UTC start time.
	 * @param string $priority Priority.
	 * @return array{first_response_due_at:string|null,resolution_due_at:string|null}
	 */
	private function calculate_sla_deadlines( string $started_at, string $priority ): array {
		$targets = $this->get_sla_targets();
		$target  = isset( $targets[ $priority ] ) && is_array( $targets[ $priority ] ) ? $targets[ $priority ] : array();

		return array(
			'first_response_due_at' => $this->add_minutes( $started_at, absint( $target['first_response'] ?? 0 ) ),
			'resolution_due_at'     => $this->add_minutes( $started_at, absint( $target['resolution'] ?? 0 ) ),
		);
	}

	/**
	 * Add minutes to one UTC datetime.
	 *
	 * @param string $datetime UTC datetime.
	 * @param int    $minutes Minutes.
	 * @return string|null
	 */
	private function add_minutes( string $datetime, int $minutes ): ?string {
		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp || $minutes <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp + ( $minutes * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Limit one text value.
	 *
	 * @param mixed $value Value.
	 * @param int   $length Limit.
	 * @return string
	 */
	private function limit_text( $value, int $length ): string {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/**
	 * Limit internal note.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function limit_note( $value ): string {
		$value = sanitize_textarea_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 10000 ) : substr( $value, 0, 10000 );
	}

	/**
	 * Quote controlled table name.
	 *
	 * @param string $table Table.
	 * @return string
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		return '`' . ( is_string( $clean ) && '' !== $clean ? $clean : 'nxtcc_invalid' ) . '`';
	}

	/**
	 * Error result.
	 *
	 * @param string $error Error code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private function error( string $error, string $message = '' ): array {
		return array(
			'success' => false,
			'error'   => sanitize_key( $error ),
			'message' => '' !== $message ? $message : __( 'Unable to update the conversation.', 'nxt-cloud-chat' ),
		);
	}

	/**
	 * Success result.
	 *
	 * @param array|null $conversation Conversation.
	 * @param bool       $changed Whether changed.
	 * @return array<string,mixed>
	 */
	private function success( ?array $conversation, bool $changed ): array {
		return array(
			'success'      => true,
			'changed'      => $changed,
			'conversation' => $conversation,
		);
	}
}
