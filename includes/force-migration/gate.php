<?php
/**
 * Force Migration gatekeeper.
 *
 * When enabled, non-admin users must complete WhatsApp verification before
 * accessing the site, with support for a grace period and safe request
 * allowlisting for essential endpoints and assets.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-auth-bindings-repo.php';

/**
 * Register custom tables on $wpdb for consistent access and PHPCS compatibility.
 *
 * @return void
 */
function nxtcc_fm_register_tables(): void {
	global $wpdb;

	if ( empty( $wpdb->nxtcc_auth_bindings ) ) {
		$wpdb->nxtcc_auth_bindings = $wpdb->prefix . 'nxtcc_auth_bindings';
	}
}
add_action( 'plugins_loaded', 'nxtcc_fm_register_tables', 0 );

/**
 * Check whether a user has completed WhatsApp migration.
 *
 * @param int $user_id User ID.
 * @return bool True when migration is complete.
 */
function nxtcc_fm_user_is_migrated( int $user_id ): bool {
	$user_id = absint( $user_id );
	if ( 0 >= $user_id ) {
		return false;
	}

	$flag = get_user_meta( $user_id, '_nxtcc_migration_complete', true );

	if ( function_exists( 'nxtcc_is_user_whatsapp_verified' ) ) {
		$is_verified = (bool) nxtcc_is_user_whatsapp_verified( $user_id );
		if ( $is_verified ) {
			return true;
		}

		if ( ! empty( $flag ) ) {
			delete_user_meta( $user_id, '_nxtcc_migration_complete' );
		}

		return false;
	}

	$has_binding = NXTCC_Auth_Bindings_Repo::instance()->user_has_binding( $user_id );
	if ( $has_binding ) {
		return true;
	}

	if ( ! empty( $flag ) ) {
		delete_user_meta( $user_id, '_nxtcc_migration_complete' );
	}

	return false;
}

/**
 * Determine whether a user should be exempt from force-migration redirects.
 *
 * @param WP_User $user User object.
 * @return bool True when user is exempt.
 */
function nxtcc_fm_user_is_exempt( WP_User $user ): bool {
	$is_admin_role = in_array( 'administrator', (array) $user->roles, true );
	$can_manage    = user_can( $user, 'manage_options' );

	/**
	 * Filter whether a user should bypass force migration.
	 *
	 * @param bool    $is_exempt Computed exemption.
	 * @param WP_User $user      User object.
	 */
	return (bool) apply_filters( 'nxtcc_fm_user_is_exempt', ( $is_admin_role || $can_manage ), $user );
}

/**
 * Get the normalized site path prefix from home_url() (e.g. "/", "/blog/").
 *
 * @return string
 */
function nxtcc_fm_home_path_prefix(): string {
	$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( ! is_string( $home_path ) || '' === trim( $home_path ) ) {
		return '/';
	}

	$home_path = '/' . trim( $home_path, "/ \t\n\r\0\x0B" );
	$home_path = trailingslashit( $home_path );

	return ( '/' === $home_path ) ? '/' : $home_path;
}

/**
 * Prefix a site-relative path with home_url() path when needed.
 *
 * @param string $path Site-relative path (e.g. "/nxt-whatsapp-login/").
 * @return string Path aligned with REQUEST_URI path.
 */
function nxtcc_fm_with_home_prefix( string $path ): string {
	$path = trailingslashit( '/' . ltrim( $path, "/ \t\n\r\0\x0B" ) );
	$home = nxtcc_fm_home_path_prefix();

	if ( '/' === $home || 0 === strpos( $path, $home ) ) {
		return $path;
	}

	return trailingslashit( $home . ltrim( $path, "/ \t\n\r\0\x0B" ) );
}

/**
 * Normalize an option value to a site-relative path with leading/trailing slashes.
 *
 * Accepts a full URL or a relative path and returns a normalized path like "/terms/".
 *
 * @param mixed $val Raw URL or path.
 * @return string Normalized path.
 */
function nxtcc_fm_normalize_path_from_value( $val ): string {
	$v = trim( (string) $val );
	if ( '' === $v ) {
		return '';
	}

	$maybe_url = wp_parse_url( esc_url_raw( $v ) );
	if ( is_array( $maybe_url ) && isset( $maybe_url['path'] ) && is_string( $maybe_url['path'] ) ) {
		$v = (string) $maybe_url['path'];
	}

	if ( '' !== $v && '/' !== $v[0] ) {
		$v = '/' . $v;
	}

	return nxtcc_fm_with_home_prefix( trailingslashit( $v ) );
}

/**
 * Determine if the current request should bypass force-migration enforcement.
 *
 * Allows access to the migration page, terms/privacy pages, REST API routes,
 * admin-ajax endpoint, logout flow, and static assets required for rendering.
 *
 * @param string $force_path Site-relative migration path (e.g., "/nxt-whatsapp-login/").
 * @return bool True if the request is allowlisted.
 */
function nxtcc_fm_is_whitelisted_request( string $force_path ): bool {
	$raw = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( empty( $raw ) ) {
		$raw = '/';
	}

	$raw  = sanitize_text_field( wp_unslash( (string) $raw ) );
	$uri  = wp_parse_url( esc_url_raw( $raw ) );
	$path = ( isset( $uri['path'] ) && is_string( $uri['path'] ) ) ? (string) $uri['path'] : '/';
	$path = trailingslashit( $path );

	$opts         = get_option( 'nxtcc_auth_options', array() );
	$terms_path   = ! empty( $opts['terms_url'] ) ? nxtcc_fm_normalize_path_from_value( $opts['terms_url'] ) : '';
	$privacy_path = ! empty( $opts['privacy_url'] ) ? nxtcc_fm_normalize_path_from_value( $opts['privacy_url'] ) : '';

	$force_path = nxtcc_fm_with_home_prefix( trailingslashit( $force_path ) );

	if ( $path === $force_path ) {
		return true;
	}

	if ( ( '' !== $terms_path ) && ( $path === $terms_path ) ) {
		return true;
	}
	if ( ( '' !== $privacy_path ) && ( $path === $privacy_path ) ) {
		return true;
	}

	if ( 0 === strpos( $path, '/wp-json/' ) ) {
		return true;
	}

	$ajax_path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
	if ( is_string( $ajax_path ) && '' !== $ajax_path ) {
		$ajax_path = trailingslashit( $ajax_path );
		if ( $path === $ajax_path ) {
			return true;
		}
	}

	$action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	$action = is_string( $action ) ? sanitize_key( $action ) : '';
	if ( ( 0 === strpos( $path, '/wp-login.php' ) ) && ( 'logout' === $action ) ) {
		return true;
	}

	$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, array( 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot' ), true ) ) {
		return true;
	}

	return false;
}

/**
 * Enforce migration for logged-in non-admin users when force-migration is enabled.
 *
 * Users who have completed migration are allowed through and any grace metadata
 * is cleaned up. Non-migrated users may pass during the grace period; otherwise
 * they are redirected to the migration page.
 *
 * @return void
 */
function nxtcc_fm_check_gate(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$opts = function_exists( 'nxtcc_fm_get_options' ) ? (array) nxtcc_fm_get_options() : array();
	if ( empty( $opts['force_migrate'] ) ) {
		return;
	}

	$user = wp_get_current_user();
	if ( ! $user ) {
		return;
	}
	if ( $user instanceof WP_User && nxtcc_fm_user_is_exempt( $user ) ) {
		return;
	}

	$force_path = isset( $opts['force_path'] ) ? trailingslashit( (string) $opts['force_path'] ) : '/nxt-whatsapp-login/';

	if ( nxtcc_fm_is_whitelisted_request( $force_path ) ) {
		return;
	}

	if ( nxtcc_fm_user_is_migrated( (int) $user->ID ) ) {
		delete_user_meta( (int) $user->ID, '_nxtcc_fm_login_date' );
		return;
	}

	$meta_key    = '_nxtcc_fm_login_date';
	$first_login = get_user_meta( (int) $user->ID, $meta_key, true );
	if ( empty( $first_login ) ) {
		$first_login = time();
		update_user_meta( (int) $user->ID, $meta_key, (int) $first_login );
	} else {
		$first_login = (int) $first_login;
	}

	if ( ! empty( $opts['grace_enabled'] ) ) {
		$grace_days = isset( $opts['grace_days'] ) ? (int) $opts['grace_days'] : 1;
		$grace_days = max( 1, $grace_days );

		$expiry = $first_login + ( $grace_days * DAY_IN_SECONDS );
		if ( time() < $expiry ) {
			return;
		}
	}

	wp_safe_redirect( home_url( $force_path ) );
	exit;
}

/**
 * Force migration redirect immediately after successful password login.
 *
 * @param string                 $redirect_to       Redirect destination.
 * @param string                 $requested_redirect Requested destination from login form.
 * @param WP_User|WP_Error|mixed $user           Authenticated user (or error).
 * @return string
 */
function nxtcc_fm_login_redirect( string $redirect_to, string $requested_redirect, $user ): string {
	// Keep WordPress login failures/default flow unchanged.
	if ( ! ( $user instanceof WP_User ) ) {
		return $redirect_to;
	}
	if ( nxtcc_fm_user_is_exempt( $user ) ) {
		return $redirect_to;
	}

	// WordPress passes this parameter by filter signature.
	unset( $requested_redirect );

	$opts = function_exists( 'nxtcc_fm_get_options' ) ? (array) nxtcc_fm_get_options() : array();
	if ( empty( $opts['force_migrate'] ) ) {
		return $redirect_to;
	}

	if ( nxtcc_fm_user_is_migrated( (int) $user->ID ) ) {
		return $redirect_to;
	}

	if ( ! empty( $opts['grace_enabled'] ) ) {
		$meta_key    = '_nxtcc_fm_login_date';
		$first_login = get_user_meta( (int) $user->ID, $meta_key, true );

		if ( empty( $first_login ) ) {
			$first_login = time();
			update_user_meta( (int) $user->ID, $meta_key, (int) $first_login );
		} else {
			$first_login = (int) $first_login;
		}

		$grace_days = isset( $opts['grace_days'] ) ? (int) $opts['grace_days'] : 1;
		$grace_days = max( 1, $grace_days );
		$expiry     = $first_login + ( $grace_days * DAY_IN_SECONDS );

		if ( time() < $expiry ) {
			return $redirect_to;
		}
	}

	$force_path = isset( $opts['force_path'] ) ? trailingslashit( (string) $opts['force_path'] ) : '/nxt-whatsapp-login/';
	return (string) home_url( $force_path );
}

add_action( 'init', 'nxtcc_fm_check_gate', 1 );
add_filter( 'login_redirect', 'nxtcc_fm_login_redirect', 50, 3 );
