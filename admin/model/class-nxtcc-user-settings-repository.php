<?php
/**
 * User Settings repository.
 *
 * Encapsulates database access for the nxtcc_user_settings table and provides
 * cached read helpers for dashboard and admin flows.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository for reading user settings rows.
 *
 * - Encapsulates all database access for nxtcc_user_settings.
 * - Uses prepared statements for SQL safety.
 * - Caches lookups to reduce repeated reads on admin pages.
 */
class NXTCC_User_Settings_Repository {

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	private $cache_group = 'nxtcc_settings';

	/**
	 * WordPress DB instance.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Fully qualified table name (with wpdb prefix).
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WPDB instance.
	 */
	public function __construct( $db ) {
		$this->db    = $db;
		$this->table = $this->db->prefix . 'nxtcc_user_settings';
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table_name( string $table ): string {
		return '`' . str_replace( '`', '', $table ) . '`';
	}

	/**
	 * Get the most recent settings row for a specific email address.
	 *
	 * Returns the latest row as an object (stdClass) or null when no row exists.
	 * Only selects the fields required by the caller.
	 *
	 * @param string $email User email address (raw or sanitized).
	 * @return object|null Latest settings row or null.
	 */
	public function get_latest_by_email( $email ) {
		$email = (string) $email;
		$key   = 'latest:' . md5( strtolower( $email ) );

		$cached = wp_cache_get( $key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$table_sql = $this->quote_table_name( $this->table );
		$result    = $this->db->get_row(
			$this->db->prepare(
				'SELECT business_account_id, phone_number_id
				 FROM ' . $table_sql . '
				 WHERE user_mailid = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$email
			)
		);

		// Use a literal TTL so PHPCS can verify it is >= 300 seconds.
		wp_cache_set( $key, $result, $this->cache_group, 300 );

		return $result;
	}
}
