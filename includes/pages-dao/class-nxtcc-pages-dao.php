<?php
/**
 * Pages DAO for admin view templates.
 *
 * Centralizes data access used by files under /admin/pages/.
 *
 * Current scope:
 * - Fetch the latest WhatsApp connection settings row for a given admin user.
 *
 * Design goals:
 * - Keep view templates free of SQL.
 * - Use short object caching to limit repeated reads.
 * - Keep DB access centralized in one DAO.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access layer for admin pages.
 */
final class NXTCC_Pages_DAO {

	/**
	 * Cache group for admin page lookups.
	 */
	private const CACHE_GROUP = 'nxtcc_pages_dao';

	/**
	 * Fetch latest settings row for a given admin email.
	 *
	 * Returns stdClass (similar to $wpdb->get_row()) so templates can read:
	 * $row->business_account_id, $row->phone_number_id, etc.
	 *
	 * @param string $user_mailid Admin email.
	 * @return stdClass|null Settings row or null.
	 */
	public static function get_latest_settings_row_for_user( string $user_mailid ) {
		global $wpdb;

		$user_mailid = sanitize_email( $user_mailid );
		if ( '' === $user_mailid ) {
			return null;
		}

		$cache_key = 'latest_settings_row_' . md5( strtolower( $user_mailid ) );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return ( $cached instanceof stdClass ) ? $cached : null;
		}

		$row = call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT *
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 WHERE user_mailid = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$user_mailid
			)
		);
		$row = ( $row instanceof stdClass ) ? $row : null;

		wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 300 );

		return $row;
	}

	/**
	 * Flush cached settings row for a user.
	 *
	 * Call this after settings are updated.
	 *
	 * @param string $user_mailid Admin email.
	 * @return void
	 */
	public static function flush_latest_settings_row_cache( string $user_mailid ): void {
		$user_mailid = sanitize_email( $user_mailid );
		if ( '' === $user_mailid ) {
			return;
		}

		$cache_key = 'latest_settings_row_' . md5( strtolower( $user_mailid ) );

		wp_cache_delete( $cache_key, self::CACHE_GROUP );
	}
}
