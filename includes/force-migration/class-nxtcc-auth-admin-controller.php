<?php
/**
 * Admin policy controller for Authentication + Force-Migration settings.
 *
 * This controller provides an admin-only AJAX handler that saves the
 * policy stored in the WordPress option "nxtcc_auth_policy".
 *
 * Security:
 * - Requires a logged-in administrator (manage_options).
 * - Verifies the admin nonce when provided (action: "nxtcc_auth_admin").
 *
 * Data handling:
 * - Reads request values via filter_input() (no direct superglobal access).
 * - Normalizes and sanitizes fields before persisting.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Controller for saving Authentication + Force-Migration policy via AJAX.
 */
final class NXTCC_Auth_Admin_Controller {

	/**
	 * Handle admin AJAX request to save policy.
	 *
	 * @return void
	 */
	public static function handle_save_policy(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		/*
		 * Nonce is required for all save requests.
		 * Fail closed when missing or invalid.
		 */
		$nonce = self::post_string( 'nonce', '' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'nxtcc_auth_admin' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
		}

		// Start from existing options so partial updates keep prior values.
		$opts = function_exists( 'nxtcc_fm_get_options' ) ? (array) nxtcc_fm_get_options() : array();

		// Checkbox-like values stored as 0/1.
		$opts['show_password']   = self::post_bool( 'show_password' ) ? 1 : 0;
		$opts['force_migrate']   = self::post_bool( 'force_migrate' ) ? 1 : 0;
		$opts['grace_enabled']   = self::post_bool( 'grace_enabled' ) ? 1 : 0;
		$opts['widget_branding'] = self::post_bool( 'widget_branding' ) ? 1 : 0;

		// Grace days (1..90).
		$opts['grace_days'] = self::post_int_bounded( 'grace_days', 7, 1, 90 );

		// Force path normalization (expects a site-relative "/path/").
		$path = self::post_string( 'force_path', '/nxt-whatsapp-login/' );
		$path = sanitize_text_field( (string) $path );

		if ( '' === trim( $path ) ) {
			$path = '/nxt-whatsapp-login/';
		}

		$path               = self::ensure_leading_slash( $path );
		$path               = self::ensure_trailing_slash( $path );
		$opts['force_path'] = $path;

		// Allowed countries: expects array of ISO2 strings.
		$allowed_raw = filter_input(
			INPUT_POST,
			'allowed_countries',
			FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			FILTER_REQUIRE_ARRAY
		);

		if ( ! is_array( $allowed_raw ) ) {
			$allowed_raw = array();
		}

		$allowed_raw = wp_unslash( $allowed_raw );

		$allowed_clean = array();

		foreach ( $allowed_raw as $iso ) {
			$iso = strtoupper( trim( sanitize_text_field( (string) $iso ) ) );

			if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
				$allowed_clean[ $iso ] = true;
			}
		}

		$opts['allowed_countries'] = array_keys( $allowed_clean );

		// Persist through the shared options layer when available.
		if ( function_exists( 'nxtcc_fm_update_options' ) ) {
			nxtcc_fm_update_options( $opts );
		} else {
			update_option( 'nxtcc_auth_policy', $opts );
		}

		wp_send_json_success(
			array(
				'saved'  => true,
				'policy' => $opts,
			)
		);
	}

	/**
	 * Read a POST value as a string using filter_input(), then unslash.
	 *
	 * @param string      $key      Input key.
	 * @param string|null $fallback Fallback when missing.
	 * @return string|null Value or fallback.
	 */
	private static function post_string( string $key, ?string $fallback ): ?string {
		$val = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $val ) {
			return $fallback;
		}

		return wp_unslash( (string) $val );
	}

	/**
	 * Read a checkbox-like POST value.
	 *
	 * Accepted truthy values: "1", "true", "on", "yes".
	 *
	 * @param string $key Input key.
	 * @return bool True when checked/truthy.
	 */
	private static function post_bool( string $key ): bool {
		$val = self::post_string( $key, null );
		if ( null === $val ) {
			return false;
		}

		$val = strtolower( trim( $val ) );
		return in_array( $val, array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Read an integer POST value with bounds and a fallback.
	 *
	 * @param string $key      Input key.
	 * @param int    $fallback Default when missing.
	 * @param int    $min      Minimum allowed.
	 * @param int    $max      Maximum allowed.
	 * @return int Bounded integer.
	 */
	private static function post_int_bounded( string $key, int $fallback, int $min, int $max ): int {
		$raw = self::post_string( $key, null );
		$val = ( null === $raw ) ? $fallback : absint( $raw );

		if ( $val < $min ) {
			return $min;
		}

		if ( $val > $max ) {
			return $max;
		}

		return $val;
	}

	/**
	 * Ensure a leading slash exists.
	 *
	 * @param string $path Path value.
	 * @return string Path with a leading slash.
	 */
	private static function ensure_leading_slash( string $path ): string {
		$path = ltrim( $path, " \t\n\r\0\x0B" );

		if ( '' !== $path && '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return $path;
	}

	/**
	 * Ensure a trailing slash exists.
	 *
	 * @param string $path Path value.
	 * @return string Path with a trailing slash.
	 */
	private static function ensure_trailing_slash( string $path ): string {
		$path = rtrim( $path, " \t\n\r\0\x0B" );

		if ( '' === $path || '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}

		return $path;
	}
}
