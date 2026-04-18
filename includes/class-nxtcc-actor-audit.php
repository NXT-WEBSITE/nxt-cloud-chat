<?php
/**
 * Actor audit helpers.
 *
 * Resolves WordPress users into compact display labels for tenant-scoped
 * records while keeping business tables keyed by tenant identifiers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared actor label helpers for Free and Pro modules.
 */
final class NXTCC_Actor_Audit {

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_actor_audit';

	/**
	 * Return the current user ID when logged in.
	 *
	 * @return int
	 */
	public static function current_user_id(): int {
		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	/**
	 * Normalize a user-id list.
	 *
	 * @param array<int,mixed> $user_ids Raw user IDs.
	 * @return array<int,int>
	 */
	private static function normalize_user_ids( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		sort( $user_ids, SORT_NUMERIC );

		return $user_ids;
	}

	/**
	 * Build a compact user-mail label from WordPress user fields.
	 *
	 * @param string $display_name Display name.
	 * @param string $user_email   User email.
	 * @param string $fallback     Fallback text.
	 * @return string
	 */
	public static function format_user_label( string $display_name, string $user_email, string $fallback = '' ): string {
		$display_name = sanitize_text_field( $display_name );
		$user_email   = sanitize_email( $user_email );
		$fallback     = sanitize_text_field( $fallback );

		if ( '' !== $user_email ) {
			return $user_email;
		}

		if ( '' !== $display_name ) {
			return $display_name;
		}

		return $fallback;
	}

	/**
	 * Resolve a map of user IDs to display metadata.
	 *
	 * @param array<int,mixed> $user_ids User IDs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_user_map( array $user_ids ): array {
		$user_ids = self::normalize_user_ids( $user_ids );
		if ( empty( $user_ids ) ) {
			return array();
		}

		$cache_key = 'map:v2:' . md5( implode( ',', $user_ids ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'include',
				'fields'  => array( 'ID', 'display_name', 'user_email', 'user_login' ),
			)
		);

		$map = array();

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User || $user->ID <= 0 ) {
				continue;
			}

			$display_name = sanitize_text_field( (string) $user->display_name );
			$user_email   = sanitize_email( (string) $user->user_email );
			$user_login   = sanitize_user( (string) $user->user_login, true );

			$map[ (int) $user->ID ] = array(
				'ID'           => (int) $user->ID,
				'display_name' => $display_name,
				'user_email'   => $user_email,
				'user_login'   => $user_login,
				'label'        => '' !== $user_login
					? $user_login
					: self::format_user_label(
						$display_name,
						$user_email,
						$user_login
					),
			);
		}

		wp_cache_set( $cache_key, $map, self::CACHE_GROUP, 300 );

		return $map;
	}

	/**
	 * Resolve one display label from a user ID.
	 *
	 * @param int                            $user_id   User ID.
	 * @param array<int,array<string,mixed>> $user_map  Optional preloaded user map.
	 * @param string                         $fallback  Fallback label.
	 * @return string
	 */
	public static function label_for_user_id( int $user_id, array $user_map = array(), string $fallback = '' ): string {
		if ( $user_id <= 0 ) {
			return sanitize_text_field( $fallback );
		}

		if ( isset( $user_map[ $user_id ]['label'] ) && is_string( $user_map[ $user_id ]['label'] ) ) {
			return (string) $user_map[ $user_id ]['label'];
		}

		$resolved = self::get_user_map( array( $user_id ) );

		if ( isset( $resolved[ $user_id ]['label'] ) && is_string( $resolved[ $user_id ]['label'] ) ) {
			return (string) $resolved[ $user_id ]['label'];
		}

		return sanitize_text_field( $fallback );
	}

	/**
	 * Resolve one user email from a user ID.
	 *
	 * @param int                            $user_id   User ID.
	 * @param array<int,array<string,mixed>> $user_map  Optional preloaded user map.
	 * @param string                         $fallback  Fallback email.
	 * @return string
	 */
	public static function email_for_user_id( int $user_id, array $user_map = array(), string $fallback = '' ): string {
		if ( $user_id <= 0 ) {
			return sanitize_email( $fallback );
		}

		if ( isset( $user_map[ $user_id ]['user_email'] ) && is_string( $user_map[ $user_id ]['user_email'] ) ) {
			return sanitize_email( (string) $user_map[ $user_id ]['user_email'] );
		}

		$resolved = self::get_user_map( array( $user_id ) );

		if ( isset( $resolved[ $user_id ]['user_email'] ) && is_string( $resolved[ $user_id ]['user_email'] ) ) {
			return sanitize_email( (string) $resolved[ $user_id ]['user_email'] );
		}

		return sanitize_email( $fallback );
	}
}
