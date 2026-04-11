<?php
/**
 * Remote request helpers for NXT Cloud Chat.
 *
 * Provides wrappers for HTTP calls that prefer VIP safe functions when available.
 * Keeps request timeouts conservative for admin AJAX usage.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_safe_remote_get' ) ) {
	/**
	 * Perform a safe GET request for environments that provide VIP HTTP helpers.
	 *
	 * Uses vip_safe_wp_remote_get() when available. Falls back to wp_safe_remote_get()
	 * in non-VIP environments.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error
	 */
	function nxtcc_safe_remote_get( string $url, array $args ) {
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, 3, 20, $args );
		}

		return wp_safe_remote_get( $url, $args );
	}
}

if ( ! function_exists( 'nxtcc_safe_remote_post' ) ) {
	/**
	 * Perform a safe POST request for environments that provide VIP HTTP helpers.
	 *
	 * Uses vip_safe_wp_remote_post() when available. Falls back to wp_safe_remote_post()
	 * in non-VIP environments.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error
	 */
	function nxtcc_safe_remote_post( string $url, array $args ) {
		if ( function_exists( 'vip_safe_wp_remote_post' ) ) {
			return vip_safe_wp_remote_post( $url, '', 3, 3, 20, $args );
		}

		return wp_safe_remote_post( $url, $args );
	}
}
