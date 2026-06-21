<?php
/**
 * Tenant-scoped CRM activity timeline service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned CRM activity storage shared by core, Pro, and integrations.
 */
final class NXTCC_CRM_Activities {

	/**
	 * Maximum encoded metadata size.
	 *
	 * @var int
	 */
	private const MAX_METADATA_BYTES = 32768;

	/**
	 * Maximum internal note length.
	 *
	 * @var int
	 */
	private const MAX_NOTE_LENGTH = 10000;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether mutation hooks are registered.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Activity table.
	 *
	 * @var string
	 */
	private string $activities_table;

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
	 * Register adapters for existing Free CRM mutations.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'nxtcc_contact_tags_updated', array( __CLASS__, 'capture_contact_tags_updated' ), 10, 3 );
		add_action( 'nxtcc_contact_assignment_updated', array( __CLASS__, 'capture_contact_assignment_updated' ), 10, 6 );
		add_action( 'nxtcc_contact_subscription_status_updated', array( __CLASS__, 'capture_subscription_status_updated' ), 10, 3 );
		add_action( 'nxtcc_contact_upserted_for_integration', array( __CLASS__, 'capture_integration_contact_upserted' ), 10, 2 );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->db               = $wpdb;
		$this->activities_table = $wpdb->prefix . 'nxtcc_crm_activities';
		$this->contacts_table   = $wpdb->prefix . 'nxtcc_contacts';
	}

	/**
	 * Return registered CRM activity types.
	 *
	 * @return array<string,string>
	 */
	public function get_activity_types(): array {
		$types = array(
			'contact_created'               => 'Contact created',
			'contact_updated'               => 'Contact updated',
			'contact_deleted'               => 'Contact deleted',
			'contact_tags_added'            => 'Contact tags added',
			'contact_tags_removed'          => 'Contact tags removed',
			'subscription_status_changed'   => 'Subscription status changed',
			'contact_assignment_changed'    => 'Contact assignment changed',
			'conversation_assigned'         => 'Conversation assigned',
			'conversation_status_changed'   => 'Conversation status changed',
			'conversation_priority_changed' => 'Conversation priority changed',
			'conversation_details_changed'  => 'Conversation details changed',
			'internal_note_added'           => 'Internal note added',
			'task_created'                  => 'Task created',
			'task_updated'                  => 'Task updated',
			'task_completed'                => 'Task completed',
			'task_cancelled'                => 'Task cancelled',
			'lifecycle_stage_changed'       => 'Lifecycle stage changed',
			'contacts_merged'               => 'Contacts merged',
			'deal_created'                  => 'Deal created',
			'deal_updated'                  => 'Deal updated',
			'deal_stage_changed'            => 'Deal stage changed',
			'deal_won'                      => 'Deal won',
			'deal_lost'                     => 'Deal lost',
		);

		$filtered = apply_filters( 'nxtcc_crm_activity_types', $types );
		if ( ! is_array( $filtered ) ) {
			$filtered = $types;
		}

		$normalized = array();
		foreach ( $filtered as $type => $label ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type ) {
				continue;
			}

			$normalized[ $type ] = sanitize_text_field( (string) $label );
		}

		return $normalized;
	}

	/**
	 * Record one tenant-scoped CRM activity.
	 *
	 * @param array<string,mixed> $args Activity arguments.
	 * @return array<string,mixed>
	 */
	public function record( array $args ): array {
		$tenant        = $this->normalize_tenant( $args );
		$contact_id    = absint( $args['contact_id'] ?? 0 );
		$activity_type = sanitize_key( (string) ( $args['activity_type'] ?? '' ) );
		$source        = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$actor_user_id = absint( $args['actor_user_id'] ?? $args['actor_id'] ?? get_current_user_id() );
		$note_content  = isset( $args['note_content'] ) ? substr( sanitize_textarea_field( (string) $args['note_content'] ), 0, self::MAX_NOTE_LENGTH ) : '';

		if ( ! isset( $this->get_activity_types()[ $activity_type ] ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_activity_type',
			);
		}

		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		$actor_user_id = $this->normalize_actor_id( $actor_user_id, $tenant );
		$metadata      = $this->sanitize_metadata( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array() );
		$metadata_json = wp_json_encode( $metadata );
		if ( ! is_string( $metadata_json ) || strlen( $metadata_json ) > self::MAX_METADATA_BYTES ) {
			return array(
				'success' => false,
				'error'   => 'activity_metadata_too_large',
			);
		}

		$created_at = current_time( 'mysql', true );
		$inserted   = $this->db->insert(
			$this->activities_table,
			array(
				'user_mailid'         => $tenant['user_mailid'],
				'business_account_id' => $tenant['business_account_id'],
				'phone_number_id'     => $tenant['phone_number_id'],
				'contact_id'          => $contact_id,
				'conversation_id'     => $this->nullable_id( $args['conversation_id'] ?? 0 ),
				'task_id'             => $this->nullable_id( $args['task_id'] ?? 0 ),
				'deal_id'             => $this->nullable_id( $args['deal_id'] ?? 0 ),
				'activity_type'       => $activity_type,
				'source'              => $source,
				'actor_user_id'       => $actor_user_id > 0 ? $actor_user_id : null,
				'metadata_json'       => $metadata_json,
				'note_content'        => '' !== $note_content ? $note_content : null,
				'created_at'          => $created_at,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'error'   => 'activity_insert_failed',
			);
		}

		$result = array(
			'success'       => true,
			'activity_id'   => absint( $this->db->insert_id ),
			'contact_id'    => $contact_id,
			'activity_type' => $activity_type,
		);

		do_action( 'nxtcc_crm_activity_recorded', $result, $args );

		return $result;
	}

	/**
	 * Read a bounded contact timeline.
	 *
	 * Supported args: limit, before_id, activity_types, source.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @param array<string,mixed> $args Reader arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_contact( int $contact_id, array $tenant_args, array $args = array() ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( null === $this->get_contact( $contact_id, $tenant ) ) {
			return array();
		}

		$limit           = max( 1, min( 200, absint( $args['limit'] ?? 50 ) ) );
		$before_id       = absint( $args['before_id'] ?? 0 );
		$source          = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		$types_requested = array_key_exists( 'activity_types', $args );
		$types           = $this->normalize_activity_types( $args['activity_types'] ?? array() );
		if ( $types_requested && empty( $types ) ) {
			return array();
		}

		$table_sql = $this->quote_table( $this->activities_table );
		$sql       = 'SELECT * FROM ' . $table_sql . '
			WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$query     = array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );

		if ( $before_id > 0 ) {
			$sql    .= ' AND id < %d';
			$query[] = $before_id;
		}

		if ( '' !== $source ) {
			$sql    .= ' AND source = %s';
			$query[] = $source;
		}

		if ( ! empty( $types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$sql         .= ' AND activity_type IN (' . $placeholders . ')';
			$query        = array_merge( $query, $types );
		}

		$sql    .= ' ORDER BY id DESC LIMIT %d';
		$query[] = $limit;
		$rows    = $this->db->get_results( $this->db->prepare( $sql, ...$query ), ARRAY_A );
		$rows    = is_array( $rows ) ? $rows : array();

		return $this->decorate_rows( $rows );
	}

	/**
	 * Read a bounded conversation timeline.
	 *
	 * @param int                 $conversation_id Conversation ID.
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @param array<string,mixed> $args Reader arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_conversation( int $conversation_id, array $tenant_args, array $args = array() ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		$limit  = max( 1, min( 200, absint( $args['limit'] ?? 100 ) ) );
		if ( $conversation_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->activities_table ) . '
				WHERE conversation_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY id DESC LIMIT %d',
				$conversation_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
				$limit
			),
			ARRAY_A
		);

		return $this->decorate_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Delete CRM activity for contacts being permanently deleted.
	 *
	 * @param array<int,mixed> $contact_ids Contact IDs.
	 * @return void
	 */
	public function delete_contact_data( array $contact_ids ): void {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
		$this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->quote_table( $this->activities_table ) . ' WHERE contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);
	}

	/**
	 * Record tag assignment activities.
	 *
	 * @param array<string,mixed> $result Update result.
	 * @param array<string,mixed> $contact Contact row.
	 * @param array<string,mixed> $args Original arguments.
	 * @return void
	 */
	public static function capture_contact_tags_updated( array $result, array $contact, array $args ): void {
		$service = self::instance();
		$base    = array(
			'contact_id'          => absint( $result['contact_id'] ?? $contact['id'] ?? 0 ),
			'user_mailid'         => $contact['user_mailid'] ?? $args['user_mailid'] ?? '',
			'business_account_id' => $contact['business_account_id'] ?? $args['business_account_id'] ?? '',
			'phone_number_id'     => $contact['phone_number_id'] ?? $args['phone_number_id'] ?? '',
			'source'              => $args['source'] ?? 'integration',
			'actor_id'            => $args['actor_id'] ?? 0,
		);

		foreach ( array(
			'added'   => 'contact_tags_added',
			'removed' => 'contact_tags_removed',
		) as $result_key => $activity_type ) {
			$tag_ids = isset( $result[ $result_key ] ) && is_array( $result[ $result_key ] ) ? array_values( array_filter( array_map( 'absint', $result[ $result_key ] ) ) ) : array();
			if ( empty( $tag_ids ) ) {
				continue;
			}

			$service->record(
				array_merge(
					$base,
					array(
						'activity_type' => $activity_type,
						'metadata'      => array(
							'tag_ids'     => $tag_ids,
							'operation'   => sanitize_key( (string) ( $result['operation'] ?? '' ) ),
							'current_ids' => isset( $result['current_ids'] ) && is_array( $result['current_ids'] ) ? array_map( 'absint', $result['current_ids'] ) : array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Record a contact assignment change.
	 *
	 * @param int                      $contact_id Contact ID.
	 * @param array<string,mixed>|null $current Current assignment.
	 * @param array<string,mixed>|null $previous Previous assignment.
	 * @param array<string,mixed>      $tenant Tenant tuple.
	 * @param string                   $source Source.
	 * @param int                      $actor_id Actor ID.
	 * @return void
	 */
	public static function capture_contact_assignment_updated( int $contact_id, ?array $current, ?array $previous, array $tenant, string $source, int $actor_id ): void {
		self::instance()->record(
			array_merge(
				$tenant,
				array(
					'contact_id'    => $contact_id,
					'activity_type' => 'contact_assignment_changed',
					'source'        => $source,
					'actor_id'      => $actor_id,
					'metadata'      => array(
						'previous' => self::assignment_metadata( $previous ),
						'current'  => self::assignment_metadata( $current ),
					),
				)
			)
		);
	}

	/**
	 * Record a subscription status change.
	 *
	 * @param array<string,mixed> $result Update result.
	 * @param array<string,mixed> $row Original contact row.
	 * @param array<string,mixed> $args Original arguments.
	 * @return void
	 */
	public static function capture_subscription_status_updated( array $result, array $row, array $args ): void {
		if ( empty( $result['changed'] ) ) {
			return;
		}

		self::instance()->record(
			array(
				'contact_id'          => absint( $result['contact_id'] ?? $row['id'] ?? 0 ),
				'user_mailid'         => $row['user_mailid'] ?? $args['user_mailid'] ?? '',
				'business_account_id' => $row['business_account_id'] ?? $args['business_account_id'] ?? '',
				'phone_number_id'     => $row['phone_number_id'] ?? $args['phone_number_id'] ?? '',
				'activity_type'       => 'subscription_status_changed',
				'source'              => $args['source'] ?? $args['reason'] ?? 'integration',
				'actor_id'            => $args['actor_id'] ?? 0,
				'metadata'            => array(
					'previous_status' => sanitize_key( (string) ( $result['previous_status'] ?? '' ) ),
					'status'          => sanitize_key( (string) ( $result['status'] ?? '' ) ),
					'reason'          => sanitize_text_field( (string) ( $args['reason'] ?? '' ) ),
				),
			)
		);
	}

	/**
	 * Record an integration contact create or update.
	 *
	 * @param array<string,mixed> $result Upsert result.
	 * @param array<string,mixed> $args Original arguments.
	 * @return void
	 */
	public static function capture_integration_contact_upserted( array $result, array $args ): void {
		if ( empty( $result['created'] ) && empty( $result['updated'] ) ) {
			return;
		}

		$contact = isset( $result['contact'] ) && is_array( $result['contact'] ) ? $result['contact'] : array();
		$service = self::instance();
		$base    = array(
			'contact_id'          => absint( $result['contact_id'] ?? $contact['id'] ?? 0 ),
			'user_mailid'         => $contact['user_mailid'] ?? $args['user_mailid'] ?? '',
			'business_account_id' => $contact['business_account_id'] ?? $args['business_account_id'] ?? '',
			'phone_number_id'     => $contact['phone_number_id'] ?? $args['phone_number_id'] ?? '',
			'source'              => $args['source'] ?? 'integration',
			'actor_id'            => $args['actor_id'] ?? 0,
		);

		$service->record(
			array_merge(
				$base,
				array(
					'activity_type' => ! empty( $result['created'] ) ? 'contact_created' : 'contact_updated',
					'metadata'      => array(
						'external_id' => sanitize_text_field( (string) ( $args['external_id'] ?? '' ) ),
					),
				)
			)
		);

		if (
			! empty( $result['updated'] )
			&& array_key_exists( 'previous_subscribed', $result )
			&& (int) ( $result['is_subscribed'] ?? 0 ) !== (int) $result['previous_subscribed']
		) {
			$service->record(
				array_merge(
					$base,
					array(
						'activity_type' => 'subscription_status_changed',
						'metadata'      => array(
							'previous_status' => ! empty( $result['previous_subscribed'] ) ? 'subscribed' : 'unsubscribed',
							'status'          => ! empty( $result['is_subscribed'] ) ? 'subscribed' : 'unsubscribed',
						),
					)
				)
			);
		}
	}

	/**
	 * Normalize assignment metadata.
	 *
	 * @param array<string,mixed>|null $assignment Assignment row.
	 * @return array<string,mixed>
	 */
	private static function assignment_metadata( ?array $assignment ): array {
		if ( ! is_array( $assignment ) ) {
			return array(
				'target_type'      => 'unassigned',
				'assigned_user_id' => 0,
				'assigned_role'    => '',
			);
		}

		return array(
			'target_type'      => sanitize_key( (string) ( $assignment['target_type'] ?? '' ) ),
			'assigned_user_id' => absint( $assignment['assigned_user_id'] ?? 0 ),
			'assigned_role'    => sanitize_key( (string) ( $assignment['assigned_role'] ?? '' ) ),
		);
	}

	/**
	 * Decorate timeline rows for consumers.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_rows( array $rows ): array {
		$user_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'actor_user_id' ) ) ) );
		$user_map = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::get_user_map( $user_ids ) : array();

		foreach ( $rows as &$row ) {
			$actor_id                  = absint( $row['actor_user_id'] ?? 0 );
			$decoded                   = json_decode( (string) ( $row['metadata_json'] ?? '{}' ), true );
			$row['id']                 = absint( $row['id'] ?? 0 );
			$row['contact_id']         = absint( $row['contact_id'] ?? 0 );
			$row['actor_user_id']      = $actor_id;
			$row['actor_label']        = class_exists( 'NXTCC_Actor_Audit' ) ? NXTCC_Actor_Audit::label_for_user_id( $actor_id, $user_map, 'System' ) : '';
			$row['metadata']           = is_array( $decoded ) ? $decoded : array();
			$row['created_at_display'] = ! empty( $row['created_at'] )
				? get_date_from_gmt( (string) $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				: '';
			unset( $row['metadata_json'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Resolve a tenant contact.
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
				'SELECT id FROM ' . $this->quote_table( $this->contacts_table ) . '
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
	 * Whether a tenant tuple is complete.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return bool
	 */
	private function tenant_is_complete( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Normalize an activity source.
	 *
	 * @param string $source Raw source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, array( 'manual', 'import', 'workflow', 'integration', 'system', 'webhook' ), true ) ? $source : 'integration';
	}

	/**
	 * Normalize and allowlist activity-type filters.
	 *
	 * @param mixed $types Raw activity types.
	 * @return array<int,string>
	 */
	private function normalize_activity_types( $types ): array {
		if ( ! is_array( $types ) ) {
			$types = array( $types );
		}

		$registered = $this->get_activity_types();
		$clean      = array();
		foreach ( array_slice( $types, 0, 20 ) as $type ) {
			if ( ! is_scalar( $type ) ) {
				continue;
			}

			$type = sanitize_key( (string) $type );
			if ( '' !== $type && isset( $registered[ $type ] ) ) {
				$clean[] = $type;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Keep actor attribution inside the supplied tenant.
	 *
	 * Unknown or cross-tenant actors are recorded as system activity.
	 *
	 * @param int                  $actor_user_id Actor user ID.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return int
	 */
	private function normalize_actor_id( int $actor_user_id, array $tenant ): int {
		if ( $actor_user_id <= 0 ) {
			return 0;
		}

		if ( class_exists( 'NXTCC_Tenant_Access_DAO' ) && is_array( NXTCC_Tenant_Access_DAO::get_user_access( $actor_user_id, $tenant ) ) ) {
			return $actor_user_id;
		}

		$user = get_userdata( $actor_user_id );
		return $user instanceof WP_User && sanitize_email( (string) $user->user_email ) === $tenant['user_mailid'] ? $actor_user_id : 0;
	}

	/**
	 * Sanitize nested metadata.
	 *
	 * @param array<string|int,mixed> $metadata Raw metadata.
	 * @param int                     $depth Current depth.
	 * @return array<string|int,mixed>
	 */
	private function sanitize_metadata( array $metadata, int $depth = 0 ): array {
		if ( $depth >= 5 ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( $metadata, 0, 100, true ) as $key => $value ) {
			$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = $this->sanitize_metadata( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $clean_key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $clean_key ] = substr( sanitize_text_field( (string) $value ), 0, 1000 );
			}
		}

		return $clean;
	}

	/**
	 * Return a nullable positive ID.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function nullable_id( $value ): ?int {
		$id = absint( $value );
		return $id > 0 ? $id : null;
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
}
