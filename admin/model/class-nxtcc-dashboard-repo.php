<?php
/**
 * Dashboard repository for NXT Cloud Chat.
 *
 * Provides a small, cached read layer used by the admin dashboard to fetch
 * connection-related settings for the current user.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only repository used by the dashboard handler.
 */
final class NXTCC_Dashboard_Repo {

	/**
	 * Object cache group used for dashboard lookups.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_dashboard';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Table prefix for the current WordPress installation.
	 *
	 * @var string
	 */
	private $prefix = '';

	/**
	 * Get singleton instance.
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
	 *
	 * @return void
	 */
	private function __construct() {
		global $wpdb;

		$this->prefix = (string) $wpdb->prefix;
	}

	/**
	 * Get wpdb instance.
	 *
	 * @return wpdb
	 */
	private function db() {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Build a fully qualified table name.
	 *
	 * @param string $name Un-prefixed table name.
	 * @return string
	 */
	private function table( string $name ): string {
		return $this->prefix . $name;
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table( string $table ): string {
		return '`' . str_replace( '`', '', $table ) . '`';
	}

	/**
	 * Check whether a table exists in the current database.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		$db = $this->db();

		$exists = $db->get_var(
			$db->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		return ( $exists === $table );
	}

	/**
	 * Cached get_row wrapper for dashboard queries.
	 *
	 * Cache entries use a 300-second TTL to satisfy VIP cache guidance and to keep
	 * dashboard reads inexpensive without becoming stale for long periods.
	 *
	 * @param string $cache_key Cache key.
	 * @param string $prepared  Prepared SQL query.
	 * @return array<string, mixed>|null
	 */
	private function cache_get_row( string $cache_key, string $prepared ) {
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$db  = $this->db();
		$row = $db->get_row( $prepared, ARRAY_A );

		$data = is_array( $row ) ? $row : null;

		// Use a literal TTL so PHPCS can verify it is >= 300 seconds.
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 300 );

		return $data;
	}

	/**
	 * Fetch the newest user settings row for a given email address.
	 *
	 * Used by the dashboard Connection card to determine whether the user has
	 * completed the WhatsApp Cloud API configuration steps.
	 *
	 * @param string $user_mailid User email address.
	 * @return array<string, mixed>|null
	 */
	public function get_latest_user_settings( string $user_mailid ) {
		$user_mailid = (string) $user_mailid;

		if ( '' === $user_mailid ) {
			return null;
		}

		$t_settings = $this->table( 'nxtcc_user_settings' );

		if ( ! $this->table_exists( $t_settings ) ) {
			return null;
		}

		$t_settings_sql = $this->quote_table( $t_settings );

		$cache_key = 'latest_settings:' . md5( strtolower( $user_mailid ) );

		return $this->cache_get_row(
			$cache_key,
			$this->db()->prepare(
				'SELECT app_id, business_account_id, phone_number_id, meta_webhook_subscribed
					FROM ' . $t_settings_sql . '
					WHERE user_mailid = %s
					ORDER BY id DESC
					LIMIT 1',
				$user_mailid
			)
		);
	}
}
