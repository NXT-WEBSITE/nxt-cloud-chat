<?php
/**
 * Auth + Templates database helper.
 *
 * Provides database operations for WhatsApp authentication:
 * - Verify whether a user has a confirmed WhatsApp binding.
 * - Remove bindings when a WordPress user is deleted.
 * - Fetch approved WhatsApp templates for a tenant + phone number.
 *
 * Read operations use the object cache to reduce repeated queries.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper for authentication bindings and template lookups.
 */
final class NXTCC_Auth_DB {

	/**
	 * Object cache group for this helper.
	 */
	const CACHE_GROUP = 'nxtcc_auth';

	/**
	 * Cache lifetime in seconds (VIP sniff prefers literal 300+ at call sites).
	 */
	const CACHE_TTL = 300;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function i(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
	 * Check whether a WordPress user has a verified WhatsApp binding.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True when a verified binding exists, otherwise false.
	 */
	public function user_has_verified_binding( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$cache_key = 'auth_verified:' . $user_id;

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return ( (int) $cached ) > 0;
		}

		$db                  = NXTCC_DB::i();
		$table_auth_bindings = $this->quote_table_name( $db->t_auth_bindings() );
		$count               = (int) $db->get_var(
			'SELECT 1
				FROM ' . $table_auth_bindings . '
				WHERE user_id = %d
					AND verified_at IS NOT NULL
				LIMIT 1',
			array( $user_id )
		);

		// VIP sniff: pass a literal TTL (>=300).
		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, 300 );

		return $count > 0;
	}

	/**
	 * Delete all WhatsApp auth bindings for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function delete_bindings_for_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$db = NXTCC_DB::i();

		// If your NXTCC_DB has a query() wrapper, prefer it.
		// If not, add one there (recommended) rather than using $wpdb here.
		if ( method_exists( $db, 'query' ) ) {
			$table_auth_bindings = $this->quote_table_name( $db->t_auth_bindings() );

			$db->query(
				'DELETE FROM ' . $table_auth_bindings . ' WHERE user_id = %d',
				array( $user_id )
			);
		} else {
			// Fallback: use delete() wrapper if you have it.
			// If your wrapper doesn't support this yet, add query() to NXTCC_DB.
			$db->delete(
				$db->t_auth_bindings(),
				array( 'user_id' => $user_id ),
				array( '%d' )
			);
		}

		wp_cache_delete( 'auth_verified:' . $user_id, self::CACHE_GROUP );
	}

	/**
	 * Get approved templates for a tenant (email) and phone number ID pair.
	 *
	 * @param string $user_mailid     Tenant identifier (email).
	 * @param string $phone_number_id WhatsApp phone number ID.
	 * @return array List of template rows (associative arrays).
	 */
	public function get_approved_templates( string $user_mailid, string $phone_number_id ): array {
		$user_mailid     = sanitize_email( $user_mailid );
		$phone_number_id = sanitize_text_field( $phone_number_id );

		if ( '' === $user_mailid || '' === $phone_number_id ) {
			return array();
		}

		$cache_key = 'tpl:' . md5( strtolower( $user_mailid ) . '|' . $phone_number_id );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$db              = NXTCC_DB::i();
		$table_templates = $this->quote_table_name( $db->t_templates() );
		$rows            = $db->get_results(
			'SELECT template_name, language
				FROM ' . $table_templates . '
				WHERE user_mailid = %s
					AND phone_number_id = %s
					AND UPPER(status) = %s',
			array(
				$user_mailid,
				$phone_number_id,
				'APPROVED',
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// VIP sniff: pass a literal TTL (>=300).
		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, 300 );

		return $rows;
	}
}
