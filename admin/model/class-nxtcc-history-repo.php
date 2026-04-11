<?php
/**
 * Message history repository
 *
 * DB access layer for message history, broadcast queue and contacts tables.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository: Message history & queue DB access.
 */
final class NXTCC_History_Repo {

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
	 * History table name.
	 *
	 * @var string
	 */
	private string $hist_tbl;

	/**
	 * Queue table name.
	 *
	 * @var string
	 */
	private string $queue_tbl;

	/**
	 * Contacts table name.
	 *
	 * @var string
	 */
	private string $cont_tbl;

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->db        = $wpdb;
		$prefix          = $wpdb->prefix;
		$this->hist_tbl  = $prefix . 'nxtcc_message_history';
		$this->queue_tbl = $prefix . 'nxtcc_broadcast_queue';
		$this->cont_tbl  = $prefix . 'nxtcc_contacts';
	}

	/**
	 * Get repository singleton instance.
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
	 * Check if a database table exists (cached for 5 minutes).
	 *
	 * @param string $table Table name (with prefix).
	 * @return bool True if table exists.
	 */
	public function table_exists( string $table ): bool {
		$cache_key = 'nxtcc_tbl_exists_' . md5( $table );
		$cached    = wp_cache_get( $cache_key, 'nxtcc_db' );

		// Only use cached value when it actually exists; false can be a cache miss.
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$exists = ( $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table );

		wp_cache_set( $cache_key, $exists ? 1 : 0, 'nxtcc_db', 300 );

		return $exists;
	}

	/**
	 * Prepare a term for LIKE search (wrap + esc_like).
	 *
	 * @param string $term Search term.
	 * @return string|null LIKE-ready string or null when empty.
	 */
	private function like( string $term ): ?string {
		$term = trim( $term );
		if ( '' === $term ) {
			return null;
		}
		return '%' . $this->db->esc_like( $term ) . '%';
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Prepare SQL that contains table tokens like {history}, {queue}, {contacts}.
	 *
	 * Table identifiers are replaced after wpdb::prepare() using sentinels so
	 * placeholder ordering for value args remains intact.
	 *
	 * @param string $query     SQL query with table tokens.
	 * @param array  $table_map Token => quoted table name.
	 * @param array  $args      Value placeholder args.
	 * @return string Prepared SQL or empty string on failure.
	 */
	private function prepare_with_table_tokens( string $query, array $table_map, array $args = array() ): string {
		$search  = array();
		$replace = array();

		foreach ( $table_map as $token => $table_sql ) {
			$marker = '{' . (string) $token . '}';
			if ( false === strpos( $query, $marker ) ) {
				continue;
			}

			$clean_token = preg_replace( '/[^A-Za-z0-9_]+/', '_', (string) $token );
			if ( ! is_string( $clean_token ) || '' === $clean_token ) {
				$clean_token = 'TABLE';
			}

			$sentinel = '__NXTCC_TABLE_' . strtoupper( (string) $clean_token ) . '__';

			$query = str_replace( $marker, "'" . $sentinel . "'", $query );

			$search[]  = "'" . $sentinel . "'";
			$replace[] = (string) $table_sql;
			$search[]  = $sentinel;
			$replace[] = (string) $table_sql;
		}

		if ( empty( $args ) ) {
			$prepared = $query;
		} else {
			$prepared = $this->db->prepare( $query, ...$args );
		}

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return '';
		}

		if ( empty( $search ) ) {
			return $prepared;
		}

		return str_replace( $search, $replace, $prepared );
	}

	/**
	 * Build a comma-separated placeholder list for integer IN clauses.
	 *
	 * @param array<int,mixed> $ids Integer IDs.
	 * @return string Placeholder list (e.g. "%d,%d,%d"), or empty string.
	 */
	private function int_placeholders( array $ids ): string {
		$count = count( $ids );
		if ( 0 === $count ) {
			return '';
		}

		return implode( ',', array_fill( 0, $count, '%d' ) );
	}

	/**
	 * Normalize SQL order direction.
	 *
	 * @param string $direction Direction candidate.
	 * @return string ASC or DESC.
	 */
	private function normalize_order( string $direction ): string {
		return ( 'ASC' === strtoupper( $direction ) ) ? 'ASC' : 'DESC';
	}

	/**
	 * Combine UNION SQL arms without using dynamic implode patterns.
	 *
	 * @param string[] $parts SQL arms.
	 * @return string Combined SQL.
	 */
	private function combine_union_sql( array $parts ): string {
		$parts = array_values( $parts );
		$count = count( $parts );
		if ( 0 === $count ) {
			return '';
		}

		$sql = (string) $parts[0];

		for ( $i = 1; $i < $count; $i++ ) {
			$sql .= "\nUNION ALL\n" . (string) $parts[ $i ];
		}

		return $sql;
	}

	/**
	 * Build WHERE clause for history queries and push params into $params.
	 *
	 * @param array  $filters       Filter data.
	 * @param string $user_mail     User email.
	 * @param array  $params        Prepared statement params (by reference).
	 * @param bool   $with_like     Whether to include search LIKE clause.
	 * @param bool   $with_phone_id Whether to include phone_number_id filter.
	 * @return string WHERE clause without the "WHERE" keyword.
	 */
	private function build_where_history(
		array $filters,
		string $user_mail,
		array &$params,
		bool $with_like,
		bool $with_phone_id
	): string {

		$where = array(
			'h.user_mailid = %s',
			'h.created_at BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)',
		);

		$params[] = $user_mail;
		$params[] = (int) $filters['from_ts'];
		$params[] = (int) $filters['to_ts'];

		$status_any = '';
		if ( ! empty( $filters['status_any'] ) ) {
			$status_any = (string) $filters['status_any'];
		} elseif ( ! empty( $filters['status'] ) ) {
			$status_any = (string) $filters['status'];
		}

		if ( '' !== $status_any ) {
			$list = array_filter( array_map( 'trim', explode( ',', $status_any ) ) );
			if ( ! empty( $list ) ) {
				$where[] = 'h.status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
				$params  = array_merge( $params, $list );
			}
		}

		if ( $with_phone_id && ! empty( $filters['phone_number_id'] ) ) {
			$where[]  = 'h.phone_number_id = %s';
			$params[] = (string) $filters['phone_number_id'];
		}

		if ( $with_like && ! empty( $filters['search_like'] ) ) {
			$where[] = '(c.name LIKE %s OR CONCAT(c.country_code,c.phone_number) LIKE %s OR h.template_name LIKE %s OR h.message_content LIKE %s)';
			$params  = array_merge(
				$params,
				array(
					(string) $filters['search_like'],
					(string) $filters['search_like'],
					(string) $filters['search_like'],
					(string) $filters['search_like'],
				)
			);
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Build WHERE clause for queue queries and push params into $params.
	 *
	 * @param array  $filters       Filter data.
	 * @param string $user_mail     User email.
	 * @param array  $params        Prepared statement params (by reference).
	 * @param bool   $with_like     Whether to include search LIKE clause.
	 * @param bool   $with_phone_id Whether to include phone_number_id filter.
	 * @return string WHERE clause without the "WHERE" keyword.
	 */
	private function build_where_queue(
		array $filters,
		string $user_mail,
		array &$params,
		bool $with_like,
		bool $with_phone_id
	): string {

		$where = array(
			'q.user_mailid = %s',
			'q.created_at BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)',
		);

		$params[] = $user_mail;
		$params[] = (int) $filters['from_ts'];
		$params[] = (int) $filters['to_ts'];

		$status_any = '';
		if ( ! empty( $filters['status_any'] ) ) {
			$status_any = (string) $filters['status_any'];
		} elseif ( ! empty( $filters['status'] ) ) {
			$status_any = (string) $filters['status'];
		}

		if ( '' !== $status_any ) {
			$list = array_filter( array_map( 'trim', explode( ',', $status_any ) ) );
			if ( ! empty( $list ) ) {
				$where[] = 'q.status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
				$params  = array_merge( $params, $list );
			}
		}

		if ( $with_phone_id && ! empty( $filters['phone_number_id'] ) ) {
			$where[]  = 'q.phone_number_id = %s';
			$params[] = (string) $filters['phone_number_id'];
		}

		if ( $with_like && ! empty( $filters['search_like'] ) ) {
			$where[] = '(c.name LIKE %s OR CONCAT(c.country_code,c.phone_number) LIKE %s OR q.template_name LIKE %s)';
			$params  = array_merge(
				$params,
				array(
					(string) $filters['search_like'],
					(string) $filters['search_like'],
					(string) $filters['search_like'],
				)
			);
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Fetch merged rows for the list view (paged).
	 *
	 * @param string $user_mail User email.
	 * @param array  $filters   Filters (from_ts, to_ts, search, etc.).
	 * @param int    $limit     Row limit.
	 * @param int    $offset    Row offset.
	 * @param string $h_order   History order direction (ASC/DESC).
	 * @param string $q_order   Queue order direction (ASC/DESC).
	 * @return array[] List rows.
	 */
	public function fetch_list(
		string $user_mail,
		array $filters,
		int $limit,
		int $offset,
		string $h_order,
		string $q_order
	): array {

		$hist_exists  = $this->table_exists( $this->hist_tbl );
		$queue_exists = $this->table_exists( $this->queue_tbl );

		if ( ! $hist_exists && ! $queue_exists ) {
			return array();
		}

		$search_term = '';
		if ( isset( $filters['search'] ) ) {
			$search_term = (string) $filters['search'];
		}
		$filters['search_like'] = $this->like( $search_term );
		$hist_tbl               = $this->quote_table( $this->hist_tbl );
		$queue_tbl              = $this->quote_table( $this->queue_tbl );
		$cont_tbl               = $this->quote_table( $this->cont_tbl );
		$h_order                = $this->normalize_order( $h_order );
		$q_order                = $this->normalize_order( $q_order );
		$table_map              = array(
			'history'  => $hist_tbl,
			'queue'    => $queue_tbl,
			'contacts' => $cont_tbl,
		);

		$union_sql = array();

		// LEFT ARM (history).
		if ( $hist_exists ) {
			$left_params = array();
			$left_where  = $this->build_where_history( $filters, $user_mail, $left_params, true, true );

			if ( $queue_exists ) {
				$prepared = $this->prepare_with_table_tokens(
					"SELECT
						h.id                             AS history_id,
						q.id                             AS queue_id,
						q.created_at                     AS q_created_at,
						h.created_at                     AS h_created_at,
						COALESCE(q.created_at, h.created_at) AS display_created_at,
						COALESCE(h.contact_id, q.contact_id) AS contact_id,
						COALESCE(h.template_name, q.template_name) AS template_name,
						h.message_content                 AS message_content,
						COALESCE(h.status, q.status)      AS status,
						h.sent_at, h.delivered_at, h.read_at, h.failed_at,
						q.scheduled_at                    AS scheduled_at,
						COALESCE(h.user_mailid, q.user_mailid) AS created_by,
						h.meta_message_id,
						h.display_phone_number,
						'history'                         AS source
					FROM {history} h
					LEFT JOIN {queue} q ON q.id = h.queue_id
					LEFT JOIN {contacts} c ON c.id = COALESCE(h.contact_id, q.contact_id)
					WHERE {$left_where}",
					$table_map,
					$left_params
				);
				if ( '' !== $prepared ) {
					$union_sql[] = $prepared;
				}
			} else {
				$prepared = $this->prepare_with_table_tokens(
					"SELECT
						h.id                 AS history_id,
						NULL                 AS queue_id,
						NULL                 AS q_created_at,
						h.created_at         AS h_created_at,
						h.created_at         AS display_created_at,
						h.contact_id         AS contact_id,
						h.template_name      AS template_name,
						h.message_content    AS message_content,
						h.status             AS status,
						h.sent_at, h.delivered_at, h.read_at, h.failed_at,
						NULL                 AS scheduled_at,
						h.user_mailid        AS created_by,
						h.meta_message_id,
						h.display_phone_number,
						'history'            AS source
					FROM {history} h
					LEFT JOIN {contacts} c ON c.id = h.contact_id
					WHERE {$left_where}",
					$table_map,
					$left_params
				);
				if ( '' !== $prepared ) {
					$union_sql[] = $prepared;
				}
			}
		}

		// RIGHT ARM (queue-only).
		if ( $queue_exists ) {
			$right_params = array();
			$right_where  = $this->build_where_queue( $filters, $user_mail, $right_params, true, true );

			$right_params[] = (int) $filters['from_ts'];
			$right_params[] = (int) $filters['to_ts'];

			$prepared = $this->prepare_with_table_tokens(
				"SELECT
					NULL                AS history_id,
					q.id                AS queue_id,
					q.created_at        AS q_created_at,
					NULL                AS h_created_at,
					q.created_at        AS display_created_at,
					q.contact_id        AS contact_id,
					q.template_name     AS template_name,
					NULL                AS message_content,
					q.status            AS status,
					NULL AS sent_at, NULL AS delivered_at, NULL AS read_at, NULL AS failed_at,
					q.scheduled_at      AS scheduled_at,
					q.user_mailid       AS created_by,
					NULL                AS meta_message_id,
					NULL                AS display_phone_number,
					'queue'             AS source
				FROM {queue} q
				LEFT JOIN {contacts} c ON c.id = q.contact_id
					WHERE {$right_where}
				  AND NOT EXISTS (
						SELECT 1 FROM {history} h2
						WHERE h2.queue_id = q.id
						  AND h2.user_mailid = q.user_mailid
						  AND h2.created_at BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)
				  )",
				$table_map,
				$right_params
			);
			if ( '' !== $prepared ) {
				$union_sql[] = $prepared;
			}
		}

		if ( empty( $union_sql ) ) {
			return array();
		}

		$inner_union_sql = $this->combine_union_sql( $union_sql );

		$query = $this->prepare_with_table_tokens(
			"SELECT
				T.*,
				c.name AS contact_name,
				c.country_code,
				c.phone_number
			FROM (
				{$inner_union_sql}
			) T
			LEFT JOIN {contacts} c ON c.id = T.contact_id
			ORDER BY
				(T.q_created_at IS NULL) ASC,
				T.q_created_at {$q_order},
				T.h_created_at {$h_order}
			LIMIT %d OFFSET %d",
			array(
				'contacts' => $cont_tbl,
			),
			array( $limit, $offset )
		);
		if ( '' === $query ) {
			return array();
		}

		$rows = $this->db->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single row for the modal view.
	 *
	 * @param string $user_mail User email.
	 * @param int    $id        History ID or Queue ID (depending on $source).
	 * @param string $source    'history' or 'queue'.
	 * @return array|null Row data or null if not found.
	 */
	public function fetch_one( string $user_mail, int $id, string $source ): ?array {
		$queue_exists = $this->table_exists( $this->queue_tbl );
		$hist_tbl     = $this->quote_table( $this->hist_tbl );
		$queue_tbl    = $this->quote_table( $this->queue_tbl );
		$cont_tbl     = $this->quote_table( $this->cont_tbl );
		$table_map    = array(
			'history'  => $hist_tbl,
			'queue'    => $queue_tbl,
			'contacts' => $cont_tbl,
		);

		if ( 'history' === $source ) {
			if ( $queue_exists ) {
				$query = $this->prepare_with_table_tokens(
					'SELECT
						h.id AS history_id, h.queue_id, h.contact_id,
						h.display_phone_number, h.template_name, h.message_content, h.status,
						h.sent_at, h.delivered_at, h.read_at, h.failed_at,
						h.created_at AS h_created_at, q.created_at AS q_created_at,
						q.scheduled_at,
						h.meta_message_id, h.user_mailid AS created_by,
						c.name AS contact_name, c.country_code, c.phone_number
					FROM {history} h
					LEFT JOIN {queue} q ON q.id = h.queue_id
					LEFT JOIN {contacts} c ON c.id = COALESCE(h.contact_id, q.contact_id)
					WHERE h.user_mailid = %s AND h.id = %d
					LIMIT 1',
					$table_map,
					array( $user_mail, $id )
				);
				if ( '' === $query ) {
					return null;
				}

				$row = $this->db->get_row( $query, ARRAY_A );

				return $row ? $row : null;
			}

			$query = $this->prepare_with_table_tokens(
				'SELECT
					h.id AS history_id, h.queue_id, h.contact_id,
					h.display_phone_number, h.template_name, h.message_content, h.status,
					h.sent_at, h.delivered_at, h.read_at, h.failed_at,
					h.created_at AS h_created_at, NULL AS q_created_at,
					NULL AS scheduled_at,
					h.meta_message_id, h.user_mailid AS created_by,
					c.name AS contact_name, c.country_code, c.phone_number
				FROM {history} h
				LEFT JOIN {contacts} c ON c.id = h.contact_id
				WHERE h.user_mailid = %s AND h.id = %d
				LIMIT 1',
				$table_map,
				array( $user_mail, $id )
			);
			if ( '' === $query ) {
				return null;
			}

			$row = $this->db->get_row( $query, ARRAY_A );

			return $row ? $row : null;
		}

		if ( ! $queue_exists ) {
			return null;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT
				q.id AS queue_id, q.contact_id, q.template_name, q.status,
				q.scheduled_at, q.created_at AS q_created_at, q.user_mailid AS created_by,
				h.id AS history_id, h.message_content, h.sent_at, h.delivered_at, h.read_at, h.failed_at,
				h.created_at AS h_created_at, h.meta_message_id, h.display_phone_number,
				c.name AS contact_name, c.country_code, c.phone_number
			FROM {queue} q
			LEFT JOIN {history} h ON h.queue_id = q.id AND h.user_mailid = q.user_mailid
			LEFT JOIN {contacts} c ON c.id = q.contact_id
			WHERE q.user_mailid = %s AND q.id = %d
			LIMIT 1',
			$table_map,
			array( $user_mail, $id )
		);
		if ( '' === $query ) {
			return null;
		}

		$row = $this->db->get_row( $query, ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Bulk delete rows from history and/or queue tables.
	 *
	 * @param string $user_mail User email.
	 * @param array  $ids       IDs to delete.
	 * @param string $source    'history', 'queue', or 'both'.
	 * @return int Number of rows deleted.
	 */
	public function bulk_delete( string $user_mail, array $ids, string $source ): int {
		$deleted   = 0;
		$hist_tbl  = $this->quote_table( $this->hist_tbl );
		$queue_tbl = $this->quote_table( $this->queue_tbl );
		$table_map = array(
			'history' => $hist_tbl,
			'queue'   => $queue_tbl,
		);

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = $this->int_placeholders( $ids );

		if ( ( 'history' === $source || 'both' === $source ) && $this->table_exists( $this->hist_tbl ) ) {
			$args  = array_merge( array( $user_mail ), $ids );
			$query = $this->prepare_with_table_tokens(
				'DELETE FROM {history} WHERE user_mailid = %s AND id IN (' . $placeholders . ')',
				$table_map,
				$args
			);
			if ( '' !== $query ) {
				$this->db->query( $query );
			}
			$deleted += (int) $this->db->rows_affected;
		}

		if ( ( 'queue' === $source || 'both' === $source ) && $this->table_exists( $this->queue_tbl ) ) {
			$args  = array_merge( array( $user_mail ), $ids );
			$query = $this->prepare_with_table_tokens(
				'DELETE FROM {queue} WHERE user_mailid = %s AND id IN (' . $placeholders . ')',
				$table_map,
				$args
			);
			if ( '' !== $query ) {
				$this->db->query( $query );
			}
			$deleted += (int) $this->db->rows_affected;
		}

		return $deleted;
	}

	/**
	 * Export merged rows for CSV output (capped).
	 *
	 * @param string $user_mail User email.
	 * @param array  $filters   Filters (from_ts, to_ts, search, etc.).
	 * @param string $h_order   History order direction (ASC/DESC).
	 * @param string $q_order   Queue order direction (ASC/DESC).
	 * @return array[] Rows for export.
	 */
	public function export_rows(
		string $user_mail,
		array $filters,
		string $h_order,
		string $q_order
	): array {

		$hist_exists  = $this->table_exists( $this->hist_tbl );
		$queue_exists = $this->table_exists( $this->queue_tbl );

		$search_term = '';
		if ( isset( $filters['search'] ) ) {
			$search_term = (string) $filters['search'];
		}
		$filters['search_like'] = $this->like( $search_term );
		$hist_tbl               = $this->quote_table( $this->hist_tbl );
		$queue_tbl              = $this->quote_table( $this->queue_tbl );
		$cont_tbl               = $this->quote_table( $this->cont_tbl );
		$h_order                = $this->normalize_order( $h_order );
		$q_order                = $this->normalize_order( $q_order );
		$table_map              = array(
			'history'  => $hist_tbl,
			'queue'    => $queue_tbl,
			'contacts' => $cont_tbl,
		);

		$union_sql = array();

		// History arm.
		if ( $hist_exists ) {
			$left_params = array();
			$left_where  = $this->build_where_history( $filters, $user_mail, $left_params, true, true );

			if ( $queue_exists ) {
				$prepared = $this->prepare_with_table_tokens(
					"SELECT
						h.id                             AS history_id,
						q.id                             AS queue_id,
						q.created_at                     AS q_created_at,
						h.created_at                     AS h_created_at,
						COALESCE(q.created_at, h.created_at) AS display_created_at,
						COALESCE(h.contact_id, q.contact_id) AS contact_id,
						COALESCE(h.template_name, q.template_name) AS template_name,
						h.message_content                 AS message_content,
						COALESCE(h.status, q.status)      AS status,
						h.sent_at, h.delivered_at, h.read_at, h.failed_at,
						q.scheduled_at                    AS scheduled_at,
						COALESCE(h.user_mailid, q.user_mailid) AS created_by,
						h.meta_message_id,
						h.display_phone_number,
						'history'                         AS source
					FROM {history} h
					LEFT JOIN {queue} q ON q.id = h.queue_id
					LEFT JOIN {contacts} c ON c.id = COALESCE(h.contact_id, q.contact_id)
					WHERE {$left_where}",
					$table_map,
					$left_params
				);
				if ( '' !== $prepared ) {
					$union_sql[] = $prepared;
				}
			} else {
				$prepared = $this->prepare_with_table_tokens(
					"SELECT
						h.id                 AS history_id,
						NULL                 AS queue_id,
						NULL                 AS q_created_at,
						h.created_at         AS h_created_at,
						h.created_at         AS display_created_at,
						h.contact_id         AS contact_id,
						h.template_name      AS template_name,
						h.message_content    AS message_content,
						h.status             AS status,
						h.sent_at, h.delivered_at, h.read_at, h.failed_at,
						NULL                 AS scheduled_at,
						h.user_mailid        AS created_by,
						h.meta_message_id,
						h.display_phone_number,
						'history'            AS source
					FROM {history} h
					LEFT JOIN {contacts} c ON c.id = h.contact_id
					WHERE {$left_where}",
					$table_map,
					$left_params
				);
				if ( '' !== $prepared ) {
					$union_sql[] = $prepared;
				}
			}
		}

		// Queue arm.
		if ( $queue_exists ) {
			$right_params = array();
			$right_where  = $this->build_where_queue( $filters, $user_mail, $right_params, true, true );

			$right_params[] = (int) $filters['from_ts'];
			$right_params[] = (int) $filters['to_ts'];

			$prepared = $this->prepare_with_table_tokens(
				"SELECT
					NULL                AS history_id,
					q.id                AS queue_id,
					q.created_at        AS q_created_at,
					NULL                AS h_created_at,
					q.created_at        AS display_created_at,
					q.contact_id        AS contact_id,
					q.template_name     AS template_name,
					NULL                AS message_content,
					q.status            AS status,
					NULL AS sent_at, NULL AS delivered_at, NULL AS read_at, NULL AS failed_at,
					q.scheduled_at      AS scheduled_at,
					q.user_mailid       AS created_by,
					NULL                AS meta_message_id,
					NULL                AS display_phone_number,
					'queue'             AS source
				FROM {queue} q
				LEFT JOIN {contacts} c ON c.id = q.contact_id
					WHERE {$right_where}
				  AND NOT EXISTS (
						SELECT 1 FROM {history} h2
						WHERE h2.queue_id = q.id
						  AND h2.user_mailid = q.user_mailid
						  AND h2.created_at BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)
				  )",
				$table_map,
				$right_params
			);
			if ( '' !== $prepared ) {
				$union_sql[] = $prepared;
			}
		}

		if ( empty( $union_sql ) ) {
			return array();
		}

		$inner_union_sql = $this->combine_union_sql( $union_sql );

		$query = $this->prepare_with_table_tokens(
			"SELECT
				T.*, c.name AS contact_name, c.country_code, c.phone_number
			FROM ({$inner_union_sql}) T
			LEFT JOIN {contacts} c ON c.id = T.contact_id
			ORDER BY
				(T.q_created_at IS NULL) ASC,
				T.q_created_at {$q_order},
				T.h_created_at {$h_order}
			LIMIT %d",
			array(
				'contacts' => $cont_tbl,
			),
			array( 50000 )
		);
		if ( '' === $query ) {
			return array();
		}

		$rows = $this->db->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
