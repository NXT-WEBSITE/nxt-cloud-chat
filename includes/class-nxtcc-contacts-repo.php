<?php
/**
 * Contacts repository helper for token rendering.
 *
 * Provides a small cached read helper for contact rows used by token providers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository for the `nxtcc_contacts` table.
 *
 * Adds a small cache around contact lookups by ID.
 */
final class NXTCC_Contacts_Repo {

	/**
	 * Cache group for contact token data.
	 *
	 * @var string
	 */
	private string $cache_group = 'nxtcc_tokens_contact';

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Prefixed table name for contacts.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Singleton instance.
	 *
	 * @var NXTCC_Contacts_Repo|null
	 */
	private static ?NXTCC_Contacts_Repo $instance = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database instance.
	 */
	private function __construct( wpdb $db ) {
		$this->db    = $db;
		$this->table = $this->db->prefix . 'nxtcc_contacts';
	}

	/**
	 * Get singleton instance.
	 *
	 * @return NXTCC_Contacts_Repo
	 */
	public static function instance(): NXTCC_Contacts_Repo {
		if ( null === self::$instance ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}

		return self::$instance;
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Fetch the contact row (required columns only) by ID.
	 *
	 * @param int $contact_id Contact row ID.
	 * @return stdClass|false Contact row object or false when not found.
	 */
	public function get_contact_row_by_id( int $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( 0 >= $contact_id ) {
			return false;
		}

		$cache_key = 'contact_row:' . $contact_id;

		$cached = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$table_sql = $this->quote_table_name( $this->table );

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, user_mailid, name, country_code, phone_number, custom_fields, created_at, updated_at
				 FROM ' . $table_sql . '
				 WHERE id = %d
				 LIMIT %d',
				$contact_id,
				1
			)
		);

		$result = $row ? $row : false;

		// VIP PHPCS requires a literal, determinable cache TTL (>= 300 seconds).
		wp_cache_set(
			$cache_key,
			$result,
			$this->cache_group,
			300
		);

		return $result;
	}
}
