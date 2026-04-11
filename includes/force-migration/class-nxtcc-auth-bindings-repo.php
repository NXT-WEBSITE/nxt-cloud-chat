<?php
/**
 * Auth bindings repository (force migration).
 *
 * Encapsulates reads to nxtcc_auth_bindings table and provides caching.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access wrapper for the auth bindings table.
 */
final class NXTCC_Auth_Bindings_Repo {

	/**
	 * Cache group for auth-related keys.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_auth';

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
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Check if a user has at least one verified binding row.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when a verified binding exists.
	 */
	public function user_has_binding( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( 0 >= $user_id ) {
			return false;
		}

		$cache_key = 'has_verified_binding_' . (string) $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;

		// Ensure the custom table property exists.
		if ( empty( $wpdb->nxtcc_auth_bindings ) ) {
			$wpdb->nxtcc_auth_bindings = $wpdb->prefix . 'nxtcc_auth_bindings';
		}

		$count = (int) call_user_func(
			array( $wpdb, 'get_var' ),
			$wpdb->prepare(
				'SELECT COUNT(1) FROM `' . $wpdb->prefix . 'nxtcc_auth_bindings` WHERE user_id = %d AND verified_at IS NOT NULL LIMIT 1',
				$user_id
			)
		);

		$has = ( $count > 0 );

		// Cache for 5 minutes. Use a literal so VIP sniff can validate it is >= 300.
		wp_cache_set( $cache_key, $has, self::CACHE_GROUP, 300 );

		return $has;
	}
}
