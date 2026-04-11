<?php
/**
 * Message History repository helper.
 *
 * Provides unread count queries for the message history table.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository for the `nxtcc_message_history` table.
 *
 * Adds a small cache around unread count lookups.
 */
final class NXTCC_Message_History_Repo {

	/**
	 * Cache group name for unread counts.
	 */
	private const CACHE_GROUP = 'nxtcc_unread';

	/**
	 * Singleton instance.
	 *
	 * @var NXTCC_Message_History_Repo|null
	 */
	private static ?NXTCC_Message_History_Repo $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return NXTCC_Message_History_Repo
	 */
	public static function instance(): NXTCC_Message_History_Repo {
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
	 * Count unread inbound messages for a tenant (by user_mailid).
	 *
	 * Unread means:
	 * - status = 'received'
	 * - AND (is_read = 0 OR is_read IS NULL)
	 *
	 * @param string $user_mailid Tenant owner email.
	 * @return int
	 */
	public function count_unread_for_mail( string $user_mailid ): int {
		$user_mailid = sanitize_text_field( $user_mailid );
		if ( '' === $user_mailid ) {
			return 0;
		}

		$cache_key = 'unread_' . md5( $user_mailid );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$db        = NXTCC_DB::i();
		$table_sql = $this->quote_table_name( $db->t_message_history() );

		$count = (int) $db->get_var(
			'SELECT COUNT(*)
			  FROM ' . $table_sql . '
			 WHERE user_mailid = %s
			   AND status = %s
			   AND ( is_read = %d OR is_read IS NULL )',
			array(
				$user_mailid,
				'received',
				0,
			)
		);

		// Cache TTL is set to 300 seconds to satisfy VIP sniff deterministically.
		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, 300 );

		return $count;
	}
}
