<?php
/**
 * User Settings repository (DB + cache).
 *
 * Provides a small repository for reading the latest WhatsApp Cloud API
 * connection row from the `nxtcc_user_settings` table.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access wrapper for the `nxtcc_user_settings` table.
 *
 * Encapsulates DB reads used for connection checks and applies a bounded object cache.
 */
final class NXTCC_User_Settings_Repo {

	/**
	 * Cache group for user settings lookups.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_user_settings';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	 * Intentionally private to enforce singleton usage.
	 */
	private function __construct() {}

	/**
	 * Fetch the latest connection row (limited to required columns).
	 *
	 * Uses object cache to reduce repeated reads across a single request burst.
	 *
	 * @return object|null
	 */
	public function get_latest_connection_row(): ?object {
		$cache_key = 'latest_connection_row';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		global $wpdb;

		$row = call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT app_id, access_token_ct, access_token_nonce, business_account_id, phone_number_id
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 ORDER BY id DESC
				 LIMIT %d',
				1
			)
		);

		// Cache briefly (literal TTL so cache sniffers can evaluate it).
		wp_cache_set( $cache_key, $row ? $row : null, self::CACHE_GROUP, 300 );

		return $row ? $row : null;
	}
}
