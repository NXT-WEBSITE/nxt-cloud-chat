<?php
/**
 * Tenant-scoped aggregate CRM analytics.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned aggregate CRM reporting service.
 *
 * The service returns privacy-conscious aggregate data only. It intentionally
 * avoids contact rows, message content, notes, and arbitrary metadata.
 */
final class NXTCC_CRM_Analytics {

	/**
	 * Maximum distribution rows returned by one report.
	 *
	 * @var int
	 */
	private const MAX_DISTRIBUTION_ROWS = 50;

	/**
	 * Object-cache group and short aggregate TTL.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_crm_analytics';

	/**
	 * Return aggregate CRM analytics for one complete tenant.
	 *
	 * @param array<string,mixed> $tenant_args Tenant tuple.
	 * @param array<string,mixed> $args Reporting arguments.
	 * @return array<string,mixed>
	 */
	public static function get( array $tenant_args, array $args = array() ): array {
		$tenant = self::normalize_tenant( $tenant_args );
		if ( empty( $tenant ) ) {
			return self::error( 'invalid_tenant' );
		}

		$range     = self::analytics_range( $args );
		$cache_key = 'report:' . md5( (string) wp_json_encode( array_merge( array_values( $tenant ), array_values( $range ) ) ) );
		if ( empty( $args['force_refresh'] ) ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$contacts = self::contact_summary( $tenant, $range );
		$result   = array(
			'success'       => true,
			'generated_at'  => current_time( 'mysql', true ),
			'range'         => $range,
			'contacts'      => $contacts,
			'conversations' => self::conversation_summary( $tenant, $range ),
			'tasks'         => self::task_summary( $tenant ),
			'lifecycle'     => self::lifecycle_distribution( $tenant, absint( $contacts['total'] ?? 0 ) ),
			'tags'          => self::tag_distribution( $tenant ),
			'deals'         => self::deal_summary( $tenant, $range ),
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 300 );
		return $result;
	}

	/**
	 * Return current contact and subscription totals.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param array<string,string> $range Date range.
	 * @return array<string,int>
	 */
	private static function contact_summary( array $tenant, array $range ): array {
		$db    = NXTCC_DB::i();
		$table = self::quote_table( $db->t_contacts() );
		$row   = $db->get_row(
			"SELECT COUNT(*) AS total,
			        SUM(CASE WHEN is_subscribed = 1 THEN 1 ELSE 0 END) AS subscribed,
			        SUM(CASE WHEN is_subscribed = 0 THEN 1 ELSE 0 END) AS unsubscribed,
			        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS verified,
			        SUM(CASE WHEN created_at >= %s AND created_at <= %s THEN 1 ELSE 0 END) AS created_in_range
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s",
			array_merge(
				array( $range['date_from'], $range['date_to'] ),
				array_values( $tenant )
			),
			ARRAY_A
		);

		return self::integer_row( $row, array( 'total', 'subscribed', 'unsubscribed', 'verified', 'created_in_range' ) );
	}

	/**
	 * Return bounded conversation, SLA, and workload analytics.
	 *
	 * Date-bounded metrics use updated_at so the existing tenant/status/date
	 * index remains useful. Workload reflects current active conversations.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param array<string,string> $range Date range.
	 * @return array<string,mixed>
	 */
	private static function conversation_summary( array $tenant, array $range ): array {
		$db    = NXTCC_DB::i();
		$table = self::quote_table( $db->t_conversations() );
		$now   = current_time( 'mysql', true );
		$row   = $db->get_row(
			"SELECT COUNT(*) AS total,
			        AVG(CASE WHEN first_response_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, opened_at, first_response_at)) END) AS average_first_response_minutes,
			        AVG(CASE WHEN resolved_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, opened_at, resolved_at)) END) AS average_resolution_minutes,
			        SUM(CASE WHEN first_response_at IS NOT NULL AND first_response_due_at IS NOT NULL THEN 1 ELSE 0 END) AS first_response_sla_total,
			        SUM(CASE WHEN first_response_at IS NOT NULL AND first_response_due_at IS NOT NULL AND first_response_at <= first_response_due_at THEN 1 ELSE 0 END) AS first_response_sla_compliant,
			        SUM(CASE WHEN resolved_at IS NOT NULL AND resolution_due_at IS NOT NULL THEN 1 ELSE 0 END) AS resolution_sla_total,
			        SUM(CASE WHEN resolved_at IS NOT NULL AND resolution_due_at IS NOT NULL AND resolved_at <= resolution_due_at THEN 1 ELSE 0 END) AS resolution_sla_compliant
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND updated_at >= %s
			   AND updated_at <= %s",
			array_merge( array_values( $tenant ), array( $range['date_from'], $range['date_to'] ) ),
			ARRAY_A
		);

		$summary                                   = self::integer_row(
			$row,
			array(
				'total',
				'first_response_sla_total',
				'first_response_sla_compliant',
				'resolution_sla_total',
				'resolution_sla_compliant',
			)
		);
		$summary['average_first_response_minutes'] = self::rounded_number( $row['average_first_response_minutes'] ?? null );
		$summary['average_resolution_minutes']     = self::rounded_number( $row['average_resolution_minutes'] ?? null );
		$summary['by_status']                      = self::status_counts( $table, 'updated_at', $tenant, $range );
		$summary['current']                        = self::conversation_current_state( $table, $tenant, $now );
		$summary['workload']                       = self::conversation_workload( $table, $tenant );

		return $summary;
	}

	/**
	 * Return current conversation statuses and overdue totals.
	 *
	 * @param string               $table Quoted conversations table.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param string               $now Current UTC datetime.
	 * @return array<string,mixed>
	 */
	private static function conversation_current_state( string $table, array $tenant, string $now ): array {
		$db   = NXTCC_DB::i();
		$rows = $db->get_results(
			"SELECT status, COUNT(*) AS total,
			        SUM(CASE WHEN first_response_at IS NULL AND first_response_due_at IS NOT NULL AND first_response_due_at < %s AND status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS first_response_overdue,
			        SUM(CASE WHEN resolved_at IS NULL AND resolution_due_at IS NOT NULL AND resolution_due_at < %s AND status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS resolution_overdue
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			 GROUP BY status",
			array_merge( array( $now, $now ), array_values( $tenant ) ),
			ARRAY_A
		);
		$map  = array();
		$data = array(
			'total'                  => 0,
			'active'                 => 0,
			'unassigned'             => 0,
			'first_response_overdue' => 0,
			'resolution_overdue'     => 0,
			'by_status'              => array(),
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status                          = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$count                           = max( 0, (int) ( $row['total'] ?? 0 ) );
			$map[ $status ]                  = $count;
			$data['total']                  += $count;
			$data['first_response_overdue'] += max( 0, (int) ( $row['first_response_overdue'] ?? 0 ) );
			$data['resolution_overdue']     += max( 0, (int) ( $row['resolution_overdue'] ?? 0 ) );
			$data['active']                 += in_array( $status, array( 'unassigned', 'open', 'pending', 'snoozed' ), true ) ? $count : 0;
			$data['unassigned']             += 'unassigned' === $status ? $count : 0;
		}

		$data['by_status'] = $map;
		return $data;
	}

	/**
	 * Return current active conversation workload by assignee.
	 *
	 * @param string               $table Quoted conversations table.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private static function conversation_workload( string $table, array $tenant ): array {
		$db   = NXTCC_DB::i();
		$rows = $db->get_results(
			"SELECT assigned_user_id, assigned_role, status, COUNT(*) AS total
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND status IN (%s, %s, %s, %s)
			 GROUP BY assigned_user_id, assigned_role, status
			 ORDER BY total DESC
			 LIMIT %d",
			array_merge(
				array_values( $tenant ),
				array( 'unassigned', 'open', 'pending', 'snoozed', self::MAX_DISTRIBUTION_ROWS * 4 )
			),
			ARRAY_A
		);
		$map  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = absint( $row['assigned_user_id'] ?? 0 );
			$role    = sanitize_key( (string) ( $row['assigned_role'] ?? '' ) );
			$status  = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$key     = $user_id > 0 ? 'user:' . $user_id : ( '' !== $role ? 'role:' . $role : 'unassigned' );

			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'assignment_target' => $key,
					'total'             => 0,
					'by_status'         => array(),
				);
			}

			$count                               = max( 0, (int) ( $row['total'] ?? 0 ) );
			$map[ $key ]['total']               += $count;
			$map[ $key ]['by_status'][ $status ] = $count;
		}

		$rows = array_values( $map );
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) $right['total'] <=> (int) $left['total'];
			}
		);

		return array_slice( $rows, 0, self::MAX_DISTRIBUTION_ROWS );
	}

	/**
	 * Return current task totals and overdue count.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<string,mixed>
	 */
	private static function task_summary( array $tenant ): array {
		$db    = NXTCC_DB::i();
		$table = self::quote_table( $db->t_crm_tasks() );
		$now   = current_time( 'mysql', true );
		$rows  = $db->get_results(
			"SELECT status, COUNT(*) AS total,
			        SUM(CASE WHEN due_at IS NOT NULL AND due_at < %s AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS overdue
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			 GROUP BY status",
			array_merge( array( $now ), array_values( $tenant ) ),
			ARRAY_A
		);
		$map   = array();
		$total = 0;
		$due   = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status         = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$count          = max( 0, (int) ( $row['total'] ?? 0 ) );
			$map[ $status ] = $count;
			$total         += $count;
			$due           += max( 0, (int) ( $row['overdue'] ?? 0 ) );
		}

		return array(
			'total'     => $total,
			'overdue'   => $due,
			'by_status' => $map,
		);
	}

	/**
	 * Return current lifecycle distribution.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param int                  $contact_total Current tenant contact total.
	 * @return array<string,mixed>
	 */
	private static function lifecycle_distribution( array $tenant, int $contact_total ): array {
		$db          = NXTCC_DB::i();
		$map_table   = self::quote_table( $db->t_contact_lifecycle_stage() );
		$stage_table = self::quote_table( $db->t_crm_lifecycle_stages() );
		$rows        = $db->get_results(
			"SELECT s.id AS stage_id, s.stage_name, s.stage_slug, s.color, COUNT(*) AS total
			 FROM {$map_table} AS m
			 INNER JOIN {$stage_table} AS s
			        ON s.id = m.stage_id
			       AND s.user_mailid = m.user_mailid
			       AND s.business_account_id = m.business_account_id
			       AND s.phone_number_id = m.phone_number_id
			 WHERE m.user_mailid = %s
			   AND m.business_account_id = %s
			   AND m.phone_number_id = %s
			 GROUP BY s.id, s.stage_name, s.stage_slug, s.color
			 ORDER BY total DESC
			 LIMIT %d",
			array_merge( array_values( $tenant ), array( self::MAX_DISTRIBUTION_ROWS ) ),
			ARRAY_A
		);
		$assigned    = max(
			0,
			(int) $db->get_var(
				"SELECT COUNT(*)
				 FROM {$map_table}
				 WHERE user_mailid = %s
				   AND business_account_id = %s
				   AND phone_number_id = %s",
				array_values( $tenant )
			)
		);
		$clean       = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$count   = max( 0, (int) ( $row['total'] ?? 0 ) );
			$clean[] = array(
				'stage_id'   => absint( $row['stage_id'] ?? 0 ),
				'stage_name' => sanitize_text_field( (string) ( $row['stage_name'] ?? '' ) ),
				'stage_slug' => sanitize_key( (string) ( $row['stage_slug'] ?? '' ) ),
				'color'      => self::safe_color( $row['color'] ?? '' ),
				'total'      => $count,
			);
		}

		return array(
			'assigned'   => $assigned,
			'unassigned' => max( 0, $contact_total - $assigned ),
			'limit'      => self::MAX_DISTRIBUTION_ROWS,
			'by_stage'   => $clean,
		);
	}

	/**
	 * Return current top tag distribution.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return array<string,mixed>
	 */
	private static function tag_distribution( array $tenant ): array {
		$db        = NXTCC_DB::i();
		$map_table = self::quote_table( $db->t_tag_contact_map() );
		$tag_table = self::quote_table( $db->t_tags() );
		$rows      = $db->get_results(
			"SELECT t.id AS tag_id, t.tag_name, t.tag_slug, t.color, COUNT(DISTINCT m.contact_id) AS total
			 FROM {$map_table} AS m
			 INNER JOIN {$tag_table} AS t
			        ON t.id = m.tag_id
			       AND t.user_mailid = m.user_mailid
			       AND t.business_account_id = m.business_account_id
			       AND t.phone_number_id = m.phone_number_id
			 WHERE m.user_mailid = %s
			   AND m.business_account_id = %s
			   AND m.phone_number_id = %s
			 GROUP BY t.id, t.tag_name, t.tag_slug, t.color
			 ORDER BY total DESC
			 LIMIT %d",
			array_merge( array_values( $tenant ), array( self::MAX_DISTRIBUTION_ROWS ) ),
			ARRAY_A
		);
		$clean     = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$clean[] = array(
				'tag_id'   => absint( $row['tag_id'] ?? 0 ),
				'tag_name' => sanitize_text_field( (string) ( $row['tag_name'] ?? '' ) ),
				'tag_slug' => sanitize_key( (string) ( $row['tag_slug'] ?? '' ) ),
				'color'    => self::safe_color( $row['color'] ?? '' ),
				'total'    => max( 0, (int) ( $row['total'] ?? 0 ) ),
			);
		}

		return array(
			'limit'  => self::MAX_DISTRIBUTION_ROWS,
			'by_tag' => $clean,
		);
	}

	/**
	 * Return date-bounded deal outcomes and current open pipeline value.
	 *
	 * Values remain grouped by currency; no currency conversion is inferred.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param array<string,string> $range Date range.
	 * @return array<string,mixed>
	 */
	private static function deal_summary( array $tenant, array $range ): array {
		$db      = NXTCC_DB::i();
		$table   = self::quote_table( $db->t_crm_deals() );
		$rows    = $db->get_results(
			"SELECT status, currency, COUNT(*) AS total, SUM(deal_value) AS value
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND updated_at >= %s
			   AND updated_at <= %s
			 GROUP BY status, currency
			 ORDER BY status ASC, currency ASC",
			array_merge( array_values( $tenant ), array( $range['date_from'], $range['date_to'] ) ),
			ARRAY_A
		);
		$current = $db->get_results(
			"SELECT currency, COUNT(*) AS total, SUM(deal_value) AS value
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND status = %s
			 GROUP BY currency
			 ORDER BY currency ASC",
			array_merge( array_values( $tenant ), array( 'open' ) ),
			ARRAY_A
		);

		return array(
			'by_status_currency' => self::currency_rows( $rows, true ),
			'current_open_value' => self::currency_rows( $current, false ),
		);
	}

	/**
	 * Return grouped status counts from a controlled table and date column.
	 *
	 * @param string               $table Quoted table.
	 * @param string               $date_column Controlled date column.
	 * @param array<string,string> $tenant Tenant tuple.
	 * @param array<string,string> $range Date range.
	 * @return array<string,int>
	 */
	private static function status_counts( string $table, string $date_column, array $tenant, array $range ): array {
		$db   = NXTCC_DB::i();
		$rows = $db->get_results(
			"SELECT status, COUNT(*) AS total
			 FROM {$table}
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND {$date_column} >= %s
			   AND {$date_column} <= %s
			 GROUP BY status",
			array_merge( array_values( $tenant ), array( $range['date_from'], $range['date_to'] ) ),
			ARRAY_A
		);
		$map  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$map[ sanitize_key( (string) ( $row['status'] ?? '' ) ) ] = max( 0, (int) ( $row['total'] ?? 0 ) );
		}

		return $map;
	}

	/**
	 * Normalize a complete tenant tuple.
	 *
	 * @param array<string,mixed> $tenant Raw tuple.
	 * @return array<string,string>
	 */
	private static function normalize_tenant( array $tenant ): array {
		$normalized = array(
			'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
		);

		return in_array( '', $normalized, true ) ? array() : $normalized;
	}

	/**
	 * Normalize a bounded UTC analytics range.
	 *
	 * @param array<string,mixed> $args Range arguments.
	 * @return array<string,string>
	 */
	private static function analytics_range( array $args ): array {
		$to   = self::normalize_datetime( $args['date_to'] ?? '' );
		$to   = null !== $to ? $to : current_time( 'mysql', true );
		$from = self::normalize_datetime( $args['date_from'] ?? '' );
		$from = null !== $from ? $from : gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) - ( 30 * DAY_IN_SECONDS ) );

		if ( strtotime( $from . ' UTC' ) > strtotime( $to . ' UTC' ) ) {
			$from = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) - ( 30 * DAY_IN_SECONDS ) );
		}
		if ( strtotime( $from . ' UTC' ) < strtotime( $to . ' UTC' ) - ( 366 * DAY_IN_SECONDS ) ) {
			$from = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) - ( 366 * DAY_IN_SECONDS ) );
		}

		return array(
			'date_from' => $from,
			'date_to'   => $to,
		);
	}

	/**
	 * Normalize a MySQL UTC datetime.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function normalize_datetime( $value ): ?string {
		$value = sanitize_text_field( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return null;
		}

		return false !== strtotime( $value . ' UTC' ) ? $value : null;
	}

	/**
	 * Quote a controlled table identifier.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private static function quote_table( string $table ): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		return '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	}

	/**
	 * Convert selected row fields to non-negative integers.
	 *
	 * @param mixed             $row Raw row.
	 * @param array<int,string> $fields Field names.
	 * @return array<string,int>
	 */
	private static function integer_row( $row, array $fields ): array {
		$row   = is_array( $row ) ? $row : array();
		$clean = array();

		foreach ( $fields as $field ) {
			$clean[ $field ] = max( 0, (int) ( $row[ $field ] ?? 0 ) );
		}

		return $clean;
	}

	/**
	 * Convert grouped currency rows to a stable aggregate shape.
	 *
	 * @param mixed $rows Include rows.
	 * @param bool  $include_status Include status field.
	 * @return array<int,array<string,mixed>>
	 */
	private static function currency_rows( $rows, bool $include_status ): array {
		$clean = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$item = array(
				'currency' => substr( strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? '' ) ) ), 0, 3 ),
				'total'    => max( 0, (int) ( $row['total'] ?? 0 ) ),
				'value'    => self::rounded_number( $row['value'] ?? 0, 6 ),
			);
			if ( $include_status ) {
				$item = array_merge(
					array( 'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ) ),
					$item
				);
			}
			$clean[] = $item;
		}

		return $clean;
	}

	/**
	 * Return a rounded numeric aggregate or null.
	 *
	 * @param mixed $value Raw numeric value.
	 * @param int   $precision Decimal precision.
	 * @return float|null
	 */
	private static function rounded_number( $value, int $precision = 2 ): ?float {
		return is_numeric( $value ) ? round( (float) $value, $precision ) : null;
	}

	/**
	 * Return a sanitized display color.
	 *
	 * @param mixed $color Raw color.
	 * @return string
	 */
	private static function safe_color( $color ): string {
		$color = sanitize_hex_color( (string) $color );
		return is_string( $color ) ? $color : '#2271b1';
	}

	/**
	 * Return a structured error.
	 *
	 * @param string $code Error code.
	 * @return array<string,mixed>
	 */
	private static function error( string $code ): array {
		return array(
			'success' => false,
			'error'   => sanitize_key( $code ),
		);
	}
}
