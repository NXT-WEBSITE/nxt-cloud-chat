<?php
/**
 * Database wrapper (singleton) for safe, encapsulated DB usage.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access wrapper for safe, encapsulated DB usage.
 */
final class NXTCC_DB {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $i = null;

	/**
	 * Wpdb instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function i(): self {
		if ( null === self::$i ) {
			self::$i = new self();
		}
		return self::$i;
	}

	/**
	 * User settings table.
	 *
	 * @return string
	 */
	public function t_user_settings(): string {
		return $this->db->prefix . 'nxtcc_user_settings';
	}

	/**
	 * Groups table.
	 *
	 * @return string
	 */
	public function t_groups(): string {
		return $this->db->prefix . 'nxtcc_groups';
	}

	/**
	 * Contacts table.
	 *
	 * @return string
	 */
	public function t_contacts(): string {
		return $this->db->prefix . 'nxtcc_contacts';
	}

	/**
	 * Group-contact map table.
	 *
	 * @return string
	 */
	public function t_group_contact_map(): string {
		return $this->db->prefix . 'nxtcc_group_contact_map';
	}

	/**
	 * Tags table.
	 *
	 * @return string
	 */
	public function t_tags(): string {
		return $this->db->prefix . 'nxtcc_tags';
	}

	/**
	 * Tag-contact map table.
	 *
	 * @return string
	 */
	public function t_tag_contact_map(): string {
		return $this->db->prefix . 'nxtcc_tag_contact_map';
	}

	/**
	 * Current contact assignments table.
	 *
	 * @return string
	 */
	public function t_contact_assignments(): string {
		return $this->db->prefix . 'nxtcc_contact_assignments';
	}

	/**
	 * Contact assignment history table.
	 *
	 * @return string
	 */
	public function t_contact_assignment_history(): string {
		return $this->db->prefix . 'nxtcc_contact_assignment_history';
	}

	/**
	 * Assignment routing cursor table.
	 *
	 * @return string
	 */
	public function t_assignment_routing_state(): string {
		return $this->db->prefix . 'nxtcc_assignment_routing_state';
	}

	/**
	 * CRM activity timeline table.
	 *
	 * @return string
	 */
	public function t_crm_activities(): string {
		return $this->db->prefix . 'nxtcc_crm_activities';
	}

	/**
	 * CRM lifecycle stage definitions table.
	 *
	 * @return string
	 */
	public function t_crm_lifecycle_stages(): string {
		return $this->db->prefix . 'nxtcc_crm_lifecycle_stages';
	}

	/**
	 * Contact lifecycle stage table.
	 *
	 * @return string
	 */
	public function t_contact_lifecycle_stage(): string {
		return $this->db->prefix . 'nxtcc_contact_lifecycle_stage';
	}

	/**
	 * CRM tasks table.
	 *
	 * @return string
	 */
	public function t_crm_tasks(): string {
		return $this->db->prefix . 'nxtcc_crm_tasks';
	}

	/**
	 * CRM saved contact views table.
	 *
	 * @return string
	 */
	public function t_crm_saved_views(): string {
		return $this->db->prefix . 'nxtcc_crm_saved_views';
	}

	/**
	 * CRM sales pipelines table.
	 *
	 * @return string
	 */
	public function t_crm_pipelines(): string {
		return $this->db->prefix . 'nxtcc_crm_pipelines';
	}

	/**
	 * CRM sales pipeline stages table.
	 *
	 * @return string
	 */
	public function t_crm_pipeline_stages(): string {
		return $this->db->prefix . 'nxtcc_crm_pipeline_stages';
	}

	/**
	 * CRM deals table.
	 *
	 * @return string
	 */
	public function t_crm_deals(): string {
		return $this->db->prefix . 'nxtcc_crm_deals';
	}

	/**
	 * CRM deal contacts table.
	 *
	 * @return string
	 */
	public function t_crm_deal_contacts(): string {
		return $this->db->prefix . 'nxtcc_crm_deal_contacts';
	}

	/**
	 * CRM deal products table.
	 *
	 * @return string
	 */
	public function t_crm_deal_products(): string {
		return $this->db->prefix . 'nxtcc_crm_deal_products';
	}

	/**
	 * CRM deal stage history table.
	 *
	 * @return string
	 */
	public function t_crm_deal_stage_history(): string {
		return $this->db->prefix . 'nxtcc_crm_deal_stage_history';
	}

	/**
	 * Conversation tickets table.
	 *
	 * @return string
	 */
	public function t_conversations(): string {
		return $this->db->prefix . 'nxtcc_conversations';
	}

	/**
	 * Conversation assignment history table.
	 *
	 * @return string
	 */
	public function t_conversation_assignment_history(): string {
		return $this->db->prefix . 'nxtcc_conversation_assignment_history';
	}

	/**
	 * Conversation watchers table.
	 *
	 * @return string
	 */
	public function t_conversation_watchers(): string {
		return $this->db->prefix . 'nxtcc_conversation_watchers';
	}

	/**
	 * Tenant access teams table.
	 *
	 * @return string
	 */
	public function t_access_teams(): string {
		return $this->db->prefix . 'nxtcc_access_teams';
	}

	/**
	 * Tenant user access table.
	 *
	 * @return string
	 */
	public function t_tenant_user_access(): string {
		return $this->db->prefix . 'nxtcc_tenant_user_access';
	}

	/**
	 * Auth bindings table.
	 *
	 * @return string
	 */
	public function t_auth_bindings(): string {
		return $this->db->prefix . 'nxtcc_auth_bindings';
	}

	/**
	 * Message history table.
	 *
	 * @return string
	 */
	public function t_message_history(): string {
		return $this->db->prefix . 'nxtcc_message_history';
	}

	/**
	 * Prepare a SQL query safely.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Placeholder args (array or variadic values).
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		// Backward-compatible: allow either prepare( $sql, array( ... ) ) or variadic args.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		return $this->db->prepare( $sql, ...$args );
	}

	/**
	 * Build an IN(...) placeholders fragment safely.
	 *
	 * @param array  $values Values to include.
	 * @param string $type   Placeholder type (e.g. %d, %s).
	 * @return array{0:string,1:array} [placeholder_fragment, args]
	 */
	public function prepare_in_fragment( array $values, string $type = '%d' ): array {
		$values = array_values( $values );

		if ( empty( $values ) ) {
			return array( '', array() );
		}

		$ph = implode( ',', array_fill( 0, count( $values ), $type ) );
		return array( $ph, $values );
	}

	/**
	 * Get a row.
	 *
	 * @param string $sql    SQL with placeholders (or full SQL if $args empty).
	 * @param array  $args   Placeholder args.
	 * @param string $output OBJECT|ARRAY_A|ARRAY_N.
	 * @return mixed
	 */
	public function get_row( string $sql, array $args = array(), $output = OBJECT ) {
		$q = $sql;
		if ( ! empty( $args ) ) {
			$q = $this->prepare( $sql, $args );
		}
		return $this->db->get_row( $q, $output );
	}

	/**
	 * Get a single value.
	 *
	 * @param string $sql  SQL with placeholders (or full SQL if $args empty).
	 * @param array  $args Placeholder args.
	 * @return mixed
	 */
	public function get_var( string $sql, array $args = array() ) {
		$q = $sql;
		if ( ! empty( $args ) ) {
			$q = $this->prepare( $sql, $args );
		}
		return $this->db->get_var( $q );
	}

	/**
	 * Get multiple rows.
	 *
	 * @param string $sql    SQL with placeholders (or full SQL if $args empty).
	 * @param array  $args   Placeholder args.
	 * @param string $output ARRAY_A|OBJECT.
	 * @return mixed
	 */
	public function get_results( string $sql, array $args = array(), $output = ARRAY_A ) {
		$q = $sql;
		if ( ! empty( $args ) ) {
			$q = $this->prepare( $sql, $args );
		}
		return $this->db->get_results( $q, $output );
	}

	/**
	 * Get a single column.
	 *
	 * @param string $sql  SQL with placeholders (or full SQL if $args empty).
	 * @param array  $args Placeholder args.
	 * @return array
	 */
	public function get_col( string $sql, array $args = array() ): array {
		$q = $sql;
		if ( ! empty( $args ) ) {
			$q = $this->prepare( $sql, $args );
		}

		$res = $this->db->get_col( $q );
		return is_array( $res ) ? $res : array();
	}

	/**
	 * Insert row.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Column=>value.
	 * @param array  $format Optional formats.
	 * @return bool
	 */
	public function insert( string $table, array $data, array $format = array() ): bool {
		$ok = $this->db->insert( $table, $data, ! empty( $format ) ? $format : null );
		return (bool) $ok;
	}

	/**
	 * Update row(s).
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column=>value.
	 * @param array  $where Where clause.
	 * @return bool
	 */
	public function update( string $table, array $data, array $where ): bool {
		$ok = $this->db->update( $table, $data, $where );
		return (bool) $ok;
	}

	/**
	 * Run a query (prepared if args given).
	 *
	 * @param string $sql  SQL with placeholders (or full SQL if $args empty).
	 * @param array  $args Placeholder args.
	 * @return int Rows affected.
	 */
	public function query( string $sql, array $args = array() ): int {
		$q = $sql;
		if ( ! empty( $args ) ) {
			$q = $this->prepare( $sql, $args );
		}

		$this->db->query( $q );
		return (int) $this->db->rows_affected;
	}

	/**
	 * Last insert ID.
	 *
	 * @return int
	 */
	public function insert_id(): int {
		return (int) $this->db->insert_id;
	}
}
