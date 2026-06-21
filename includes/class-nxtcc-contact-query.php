<?php
/**
 * Allowlisted tenant contact query service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds bounded, prepared contact queries for internal and external integrations.
 */
final class NXTCC_Contact_Query {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Database wrapper.
	 *
	 * @var NXTCC_DB
	 */
	private NXTCC_DB $db;

	/**
	 * Return the singleton instance.
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
		$this->db = NXTCC_DB::i();
	}

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<string,string>
	 */
	public function normalize_tenant( array $tenant ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Determine whether a tenant tuple is complete.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return bool
	 */
	public function tenant_is_ready( array $tenant ): bool {
		$tenant = $this->normalize_tenant( $tenant );
		return ! in_array( '', $tenant, true );
	}

	/**
	 * Normalize the supported contact-query filters.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	public function normalize_filters( array $filters ): array {
		$normalized = array(
			'match_mode' => in_array( sanitize_key( (string) ( $filters['match_mode'] ?? 'all' ) ), array( 'all', 'any' ), true )
				? sanitize_key( (string) ( $filters['match_mode'] ?? 'all' ) )
				: 'all',
		);

		$subscription = sanitize_key( (string) ( $filters['subscription'] ?? '' ) );
		if ( in_array( $subscription, array( 'subscribed', 'unsubscribed' ), true ) ) {
			$normalized['subscription'] = $subscription;
		}

		$search = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$normalized['search'] = $this->limit_text( $search, 100 );
		}

		$this->normalize_id_filter( $normalized, $filters, 'tag_ids', 'tag_match' );
		$this->normalize_id_filter( $normalized, $filters, 'group_ids', 'group_match' );

		$stage_ids = $this->normalize_ids( $filters['lifecycle_stage_ids'] ?? array() );
		if ( ! empty( $stage_ids ) ) {
			$normalized['lifecycle_stage_ids'] = $stage_ids;
		}

		$assignment_target = sanitize_text_field( (string) ( $filters['assignment_target'] ?? '' ) );
		if (
			in_array( $assignment_target, array( 'assigned', 'unassigned' ), true )
			|| preg_match( '/^(user:\d+|role:[a-z0-9_\-]+)$/', $assignment_target )
		) {
			$normalized['assignment_target'] = $this->limit_text( $assignment_target, 80 );
		}

		foreach ( array( 'created_from', 'created_to', 'last_contacted_from', 'last_contacted_to' ) as $date_key ) {
			$date = $this->normalize_date( (string) ( $filters[ $date_key ] ?? '' ) );
			if ( '' !== $date ) {
				$normalized[ $date_key ] = $date;
			}
		}

		$statuses = array_values(
			array_intersect(
				$this->normalize_keys( $filters['conversation_statuses'] ?? array(), 20 ),
				array( 'unassigned', 'open', 'pending', 'snoozed', 'resolved', 'closed' )
			)
		);
		if ( ! empty( $statuses ) ) {
			$normalized['conversation_statuses'] = $statuses;
		}

		$task_mode = sanitize_key( (string) ( $filters['task_mode'] ?? '' ) );
		if ( in_array( $task_mode, array( 'open', 'overdue', 'completed', 'none_open' ), true ) ) {
			$normalized['task_mode'] = $task_mode;
		}

		$provider_filters = $this->normalize_provider_filters( $filters['provider_filters'] ?? array() );
		if ( ! empty( $provider_filters ) ) {
			$normalized['provider_filters'] = $provider_filters;
		}

		return $normalized;
	}

	/**
	 * Return registered contact-query provider definitions.
	 *
	 * Providers are trusted PHP integrations. User input is never accepted as a
	 * callback or SQL fragment.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_providers(): array {
		$providers = apply_filters( 'nxtcc_contact_query_providers', array() );
		if ( ! is_array( $providers ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $providers, 0, 25, true ) as $provider_id => $provider ) {
			$provider_id = sanitize_key( (string) $provider_id );
			if ( '' === $provider_id || ! is_array( $provider ) ) {
				continue;
			}

			$normalize_callback = $provider['normalize_callback'] ?? null;
			$criterion_callback = $provider['criterion_callback'] ?? null;
			if ( ! is_callable( $normalize_callback ) || ! is_callable( $criterion_callback ) ) {
				continue;
			}

			$normalized[ $provider_id ] = $provider;
		}

		return $normalized;
	}

	/**
	 * Query a bounded page of contact IDs.
	 *
	 * Supported args: after_id, limit, require_subscribed, contact_id.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,int>
	 */
	public function query_ids( array $tenant, array $filters = array(), array $args = array() ): array {
		$query = $this->build_query( $tenant, $filters, $args, false );
		if ( '' === $query['sql'] ) {
			return array();
		}

		$ids = $this->db->get_col( $query['sql'], $query['args'] );
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Count contacts matching an allowlisted query.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $tenant, array $filters = array(), array $args = array() ): int {
		$query = $this->build_query( $tenant, $filters, $args, true );
		if ( '' === $query['sql'] ) {
			return 0;
		}

		return max( 0, (int) $this->db->get_var( $query['sql'], $query['args'] ) );
	}

	/**
	 * Test whether one contact matches an allowlisted query.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @return bool
	 */
	public function matches( int $contact_id, array $tenant, array $filters = array(), array $args = array() ): bool {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return false;
		}

		$args['contact_id'] = $contact_id;
		$args['limit']      = 1;
		return ! empty( $this->query_ids( $tenant, $filters, $args ) );
	}

	/**
	 * List tenant groups for integration selectors.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_groups( array $tenant ): array {
		$tenant = $this->normalize_tenant( $tenant );
		if ( ! $this->tenant_is_ready( $tenant ) ) {
			return array();
		}

		$table = $this->quote_table( $this->db->t_groups() );
		$rows  = $this->db->get_results(
			"SELECT id, group_name, is_verified
			FROM {$table}
			WHERE user_mailid = %s
			  AND business_account_id = %s
			  AND phone_number_id = %s
			ORDER BY group_name ASC, id ASC",
			array_values( $tenant ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build the prepared query fragments.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $filters Contact filters.
	 * @param array<string,mixed> $args Query arguments.
	 * @param bool                $count Whether to build a count query.
	 * @return array{sql:string,args:array<int,mixed>}
	 */
	private function build_query( array $tenant, array $filters, array $args, bool $count ): array {
		$tenant  = $this->normalize_tenant( $tenant );
		$filters = $this->normalize_filters( $filters );
		if ( ! $this->tenant_is_ready( $tenant ) ) {
			return array(
				'sql'  => '',
				'args' => array(),
			);
		}

		$contacts      = $this->quote_table( $this->db->t_contacts() );
		$tag_map       = $this->quote_table( $this->db->t_tag_contact_map() );
		$group_map     = $this->quote_table( $this->db->t_group_contact_map() );
		$lifecycle     = $this->quote_table( $this->db->t_contact_lifecycle_stage() );
		$assignments   = $this->quote_table( $this->db->t_contact_assignments() );
		$conversation  = $this->quote_table( $this->db->t_conversations() );
		$tasks         = $this->quote_table( $this->db->t_crm_tasks() );
		$tenant_where  = array(
			'c.user_mailid = %s',
			'c.business_account_id = %s',
			'c.phone_number_id = %s',
		);
		$tenant_args   = array_values( $tenant );
		$criteria      = array();
		$criteria_args = array();

		if ( isset( $filters['subscription'] ) ) {
			$criteria[]      = 'c.is_subscribed = %d';
			$criteria_args[] = 'subscribed' === $filters['subscription'] ? 1 : 0;
		}

		if ( isset( $filters['search'] ) ) {
			$like            = '%' . $this->wpdb_esc_like( (string) $filters['search'] ) . '%';
			$criteria[]      = '(c.name LIKE %s OR c.phone_number LIKE %s)';
			$criteria_args[] = $like;
			$criteria_args[] = $like;
		}

		$this->append_map_criterion( $criteria, $criteria_args, $filters, 'tag_ids', 'tag_match', $tag_map, 'tag_id' );
		$this->append_map_criterion( $criteria, $criteria_args, $filters, 'group_ids', 'group_match', $group_map, 'group_id' );

		if ( ! empty( $filters['lifecycle_stage_ids'] ) ) {
			list( $placeholders, $ids ) = $this->db->prepare_in_fragment( $filters['lifecycle_stage_ids'], '%d' );
			$criteria[]                 = "EXISTS (SELECT 1 FROM {$lifecycle} ls WHERE ls.contact_id = c.id AND ls.user_mailid = c.user_mailid AND ls.business_account_id = c.business_account_id AND ls.phone_number_id = c.phone_number_id AND ls.stage_id IN ({$placeholders}))";
			$criteria_args              = array_merge( $criteria_args, $ids );
		}

		$this->append_assignment_criterion( $criteria, $criteria_args, $filters, $assignments );

		$created_range      = array();
		$created_range_args = array();
		if ( isset( $filters['created_from'] ) ) {
			$created_range[]      = 'c.created_at >= %s';
			$created_range_args[] = $filters['created_from'] . ' 00:00:00';
		}
		if ( isset( $filters['created_to'] ) ) {
			$created_range[]      = 'c.created_at <= %s';
			$created_range_args[] = $filters['created_to'] . ' 23:59:59';
		}
		if ( ! empty( $created_range ) ) {
			$criteria[]    = '(' . implode( ' AND ', $created_range ) . ')';
			$criteria_args = array_merge( $criteria_args, $created_range_args );
		}

		if ( ! empty( $filters['conversation_statuses'] ) ) {
			list( $placeholders, $statuses ) = $this->db->prepare_in_fragment( $filters['conversation_statuses'], '%s' );
			$criteria[]                      = "EXISTS (SELECT 1 FROM {$conversation} cv WHERE cv.contact_id = c.id AND cv.user_mailid = c.user_mailid AND cv.business_account_id = c.business_account_id AND cv.phone_number_id = c.phone_number_id AND cv.status IN ({$placeholders}))";
			$criteria_args                   = array_merge( $criteria_args, $statuses );
		}

		$last_contacted_range      = array();
		$last_contacted_range_args = array();
		if ( isset( $filters['last_contacted_from'] ) ) {
			$last_contacted_range[]      = 'cvl.last_message_at >= %s';
			$last_contacted_range_args[] = $filters['last_contacted_from'] . ' 00:00:00';
		}
		if ( isset( $filters['last_contacted_to'] ) ) {
			$last_contacted_range[]      = 'cvl.last_message_at <= %s';
			$last_contacted_range_args[] = $filters['last_contacted_to'] . ' 23:59:59';
		}
		if ( ! empty( $last_contacted_range ) ) {
			$criteria[]    = "EXISTS (SELECT 1 FROM {$conversation} cvl WHERE cvl.contact_id = c.id AND cvl.user_mailid = c.user_mailid AND cvl.business_account_id = c.business_account_id AND cvl.phone_number_id = c.phone_number_id AND " . implode( ' AND ', $last_contacted_range ) . ')';
			$criteria_args = array_merge( $criteria_args, $last_contacted_range_args );
		}

		$this->append_task_criterion( $criteria, $criteria_args, $filters, $tasks );
		$this->append_provider_criteria( $criteria, $criteria_args, $filters, $tenant );

		if ( ! empty( $args['require_subscribed'] ) ) {
			$tenant_where[] = 'c.is_subscribed = 1';
		}

		$contact_id = absint( $args['contact_id'] ?? 0 );
		if ( $contact_id > 0 ) {
			$tenant_where[] = 'c.id = %d';
			$tenant_args[]  = $contact_id;
		}

		$after_id = absint( $args['after_id'] ?? 0 );
		if ( ! $count && $after_id > 0 ) {
			$tenant_where[] = 'c.id > %d';
			$tenant_args[]  = $after_id;
		}

		$where = implode( ' AND ', $tenant_where );
		if ( ! empty( $criteria ) ) {
			$glue   = 'any' === $filters['match_mode'] ? ' OR ' : ' AND ';
			$where .= ' AND (' . implode( $glue, $criteria ) . ')';
		}

		$sql        = $count ? "SELECT COUNT(*) FROM {$contacts} c WHERE {$where}" : "SELECT c.id FROM {$contacts} c WHERE {$where} ORDER BY c.id ASC LIMIT %d";
		$query_args = array_merge( $tenant_args, $criteria_args );

		if ( ! $count ) {
			$query_args[] = max( 1, min( 500, absint( $args['limit'] ?? 100 ) ) );
		}

		return array(
			'sql'  => $sql,
			'args' => $query_args,
		);
	}

	/**
	 * Normalize registered provider filters.
	 *
	 * @param mixed $raw_filters Raw provider filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_provider_filters( $raw_filters ): array {
		if ( ! is_array( $raw_filters ) ) {
			return array();
		}

		$providers  = $this->get_providers();
		$normalized = array();
		foreach ( array_slice( $raw_filters, 0, 25 ) as $raw_filter ) {
			if ( ! is_array( $raw_filter ) ) {
				continue;
			}

			$provider_id = sanitize_key( (string) ( $raw_filter['provider'] ?? '' ) );
			if ( '' === $provider_id || empty( $providers[ $provider_id ]['normalize_callback'] ) ) {
				continue;
			}

			try {
				$rule = call_user_func( $providers[ $provider_id ]['normalize_callback'], $raw_filter );
			} catch ( Throwable $exception ) {
				unset( $exception );
				continue;
			}
			if ( ! is_array( $rule ) || empty( $rule['property'] ) || empty( $rule['operator'] ) ) {
				continue;
			}

			$rule['provider'] = $provider_id;
			$normalized[]     = $rule;
		}

		return $normalized;
	}

	/**
	 * Append criteria from registered providers.
	 *
	 * @param array<int,string>    $criteria SQL criteria.
	 * @param array<int,mixed>     $criteria_args SQL args.
	 * @param array<string,mixed>  $filters Normalized filters.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return void
	 */
	private function append_provider_criteria( array &$criteria, array &$criteria_args, array $filters, array $tenant ): void {
		if ( empty( $filters['provider_filters'] ) || ! is_array( $filters['provider_filters'] ) ) {
			return;
		}

		$providers = $this->get_providers();
		foreach ( $filters['provider_filters'] as $rule ) {
			$provider_id = sanitize_key( (string) ( $rule['provider'] ?? '' ) );
			if ( '' === $provider_id || empty( $providers[ $provider_id ]['criterion_callback'] ) ) {
				continue;
			}

			try {
				$criterion = call_user_func( $providers[ $provider_id ]['criterion_callback'], $rule, $tenant );
			} catch ( Throwable $exception ) {
				unset( $exception );
				continue;
			}
			if (
				! is_array( $criterion )
				|| empty( $criterion['sql'] )
				|| ! is_string( $criterion['sql'] )
				|| strlen( $criterion['sql'] ) > 8000
			) {
				continue;
			}

			$criteria[] = '(' . $criterion['sql'] . ')';
			if ( isset( $criterion['args'] ) && is_array( $criterion['args'] ) ) {
				$criteria_args = array_merge( $criteria_args, array_slice( $criterion['args'], 0, 100 ) );
			}
		}
	}

	/**
	 * Append a tag/group map criterion.
	 *
	 * @param array<int,string> $criteria SQL criteria.
	 * @param array<int,mixed>  $criteria_args SQL args.
	 * @param array             $filters Normalized filters.
	 * @param string            $ids_key IDs key.
	 * @param string            $match_key Match-mode key.
	 * @param string            $table Quoted map table.
	 * @param string            $column Map ID column.
	 * @return void
	 */
	private function append_map_criterion( array &$criteria, array &$criteria_args, array $filters, string $ids_key, string $match_key, string $table, string $column ): void {
		if ( empty( $filters[ $ids_key ] ) ) {
			return;
		}

		list( $placeholders, $ids ) = $this->db->prepare_in_fragment( $filters[ $ids_key ], '%d' );
		$scope                      = "m.contact_id = c.id AND m.user_mailid = c.user_mailid AND m.business_account_id = c.business_account_id AND m.phone_number_id = c.phone_number_id AND m.{$column} IN ({$placeholders})";
		$mode                       = sanitize_key( (string) ( $filters[ $match_key ] ?? 'any' ) );

		if ( 'none' === $mode ) {
			$criteria[] = "NOT EXISTS (SELECT 1 FROM {$table} m WHERE {$scope})";
		} elseif ( 'all' === $mode ) {
			$criteria[] = "(SELECT COUNT(DISTINCT m.{$column}) FROM {$table} m WHERE {$scope}) = %d";
			$ids[]      = count( $filters[ $ids_key ] );
		} else {
			$criteria[] = "EXISTS (SELECT 1 FROM {$table} m WHERE {$scope})";
		}

		$criteria_args = array_merge( $criteria_args, $ids );
	}

	/**
	 * Append contact assignment criterion.
	 *
	 * @param array<int,string> $criteria SQL criteria.
	 * @param array<int,mixed>  $criteria_args SQL args.
	 * @param array             $filters Normalized filters.
	 * @param string            $table Quoted assignment table.
	 * @return void
	 */
	private function append_assignment_criterion( array &$criteria, array &$criteria_args, array $filters, string $table ): void {
		$target = sanitize_text_field( (string) ( $filters['assignment_target'] ?? '' ) );
		if ( '' === $target ) {
			return;
		}

		$scope = 'a.contact_id = c.id AND a.user_mailid = c.user_mailid AND a.business_account_id = c.business_account_id AND a.phone_number_id = c.phone_number_id';
		if ( 'assigned' === $target ) {
			$criteria[] = "EXISTS (SELECT 1 FROM {$table} a WHERE {$scope})";
			return;
		}
		if ( 'unassigned' === $target ) {
			$criteria[] = "NOT EXISTS (SELECT 1 FROM {$table} a WHERE {$scope})";
			return;
		}
		if ( 0 === strpos( $target, 'user:' ) ) {
			$criteria[]      = "EXISTS (SELECT 1 FROM {$table} a WHERE {$scope} AND a.target_type = %s AND a.assigned_user_id = %d)";
			$criteria_args[] = 'user';
			$criteria_args[] = absint( substr( $target, 5 ) );
			return;
		}

		$criteria[]      = "EXISTS (SELECT 1 FROM {$table} a WHERE {$scope} AND a.target_type = %s AND a.assigned_role = %s)";
		$criteria_args[] = 'role';
		$criteria_args[] = sanitize_key( substr( $target, 5 ) );
	}

	/**
	 * Append CRM task criterion.
	 *
	 * @param array<int,string> $criteria SQL criteria.
	 * @param array<int,mixed>  $criteria_args SQL args.
	 * @param array             $filters Normalized filters.
	 * @param string            $table Quoted tasks table.
	 * @return void
	 */
	private function append_task_criterion( array &$criteria, array &$criteria_args, array $filters, string $table ): void {
		$mode = sanitize_key( (string) ( $filters['task_mode'] ?? '' ) );
		if ( '' === $mode ) {
			return;
		}

		$scope = 't.contact_id = c.id AND t.user_mailid = c.user_mailid AND t.business_account_id = c.business_account_id AND t.phone_number_id = c.phone_number_id';
		if ( 'none_open' === $mode ) {
			$criteria[]      = "NOT EXISTS (SELECT 1 FROM {$table} t WHERE {$scope} AND t.status = %s)";
			$criteria_args[] = 'open';
			return;
		}
		if ( 'overdue' === $mode ) {
			$criteria[]      = "EXISTS (SELECT 1 FROM {$table} t WHERE {$scope} AND t.status = %s AND t.due_at IS NOT NULL AND t.due_at < %s)";
			$criteria_args[] = 'open';
			$criteria_args[] = current_time( 'mysql', true );
			return;
		}

		$criteria[]      = "EXISTS (SELECT 1 FROM {$table} t WHERE {$scope} AND t.status = %s)";
		$criteria_args[] = $mode;
	}

	/**
	 * Normalize tag/group ID and match-mode fields.
	 *
	 * @param array<string,mixed> $normalized Normalized filters.
	 * @param array<string,mixed> $filters Raw filters.
	 * @param string              $ids_key IDs key.
	 * @param string              $match_key Match key.
	 * @return void
	 */
	private function normalize_id_filter( array &$normalized, array $filters, string $ids_key, string $match_key ): void {
		$ids = $this->normalize_ids( $filters[ $ids_key ] ?? array() );
		if ( empty( $ids ) ) {
			return;
		}

		$mode                     = sanitize_key( (string) ( $filters[ $match_key ] ?? 'any' ) );
		$normalized[ $ids_key ]   = $ids;
		$normalized[ $match_key ] = in_array( $mode, array( 'any', 'all', 'none' ), true ) ? $mode : 'any';
	}

	/**
	 * Normalize a bounded list of positive IDs.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int,int>
	 */
	private function normalize_ids( $values ): array {
		if ( ! is_array( $values ) ) {
			$values = is_scalar( $values ) ? explode( ',', (string) $values ) : array();
		}

		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) ), 0, 100 );
	}

	/**
	 * Normalize a bounded list of keys.
	 *
	 * @param mixed $values Raw values.
	 * @param int   $limit Maximum values.
	 * @return array<int,string>
	 */
	private function normalize_keys( $values, int $limit ): array {
		if ( ! is_array( $values ) ) {
			$values = is_scalar( $values ) ? explode( ',', (string) $values ) : array();
		}

		return array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', $values ) ) ) ), 0, $limit );
	}

	/**
	 * Normalize an ISO date.
	 *
	 * @param string $value Raw date.
	 * @return string
	 */
	private function normalize_date( string $value ): string {
		$value = sanitize_text_field( $value );
		$parts = array();
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
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
	 * Escape a LIKE value through wpdb.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function wpdb_esc_like( string $value ): string {
		global $wpdb;
		return $wpdb->esc_like( $value );
	}

	/**
	 * Limit a sanitized text value.
	 *
	 * @param string $value Value.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function limit_text( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
