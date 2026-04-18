<?php
/**
 * Force migration options helper.
 *
 * Keeps a single policy array under option "nxtcc_auth_policy".
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get force migration policy options merged with defaults.
 *
 * Note: This function returns stored values as-is (no sanitization here).
 * Writes should go via nxtcc_fm_update_options(), which sanitizes/normalizes.
 *
 * @return array Policy options.
 */
function nxtcc_fm_get_options(): array {
	$defaults = array(
		'show_password'     => 1,
		'force_migrate'     => 0,
		'force_path'        => '/nxt-whatsapp-login/',
		'grace_enabled'     => 0,
		'grace_days'        => 7,
		'redirect_wp_login' => 0,
		// Show frontend attribution by default unless an admin turns it off.
		'widget_branding'   => 1,
		'allowed_countries' => array(),
	);

	$opts = get_option( 'nxtcc_auth_policy', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}

	return array_merge( $defaults, $opts );
}

/**
 * Whether frontend widget branding is explicitly enabled.
 *
 * Default remains enabled unless the admin disables it.
 *
 * @return bool True when branding is enabled by policy.
 */
function nxtcc_should_show_widget_branding(): bool {
	$opts = nxtcc_fm_get_options();

	return ! empty( $opts['widget_branding'] );
}

/**
 * Normalize a URL path to "/slug/" shape for the force path.
 *
 * @param string $path Raw path or URL.
 * @return string Normalized path.
 */
function nxtcc_fm_normalize_force_path( string $path ): string {
	$path = trim( $path );

	// If a full URL is passed, keep only its path.
	$maybe_url = wp_parse_url( $path );
	if ( is_array( $maybe_url ) && isset( $maybe_url['path'] ) && is_string( $maybe_url['path'] ) ) {
		$path = (string) $maybe_url['path'];
	}

	// Strip whitespace + extra slashes and enforce leading/trailing slash.
	$path = '/' . ltrim( $path, "/ \t\n\r\0\x0B" );
	$path = rtrim( $path, "/ \t\n\r\0\x0B" ) . '/';

	return $path;
}

/**
 * Save or update policy cleanly (sanitized + normalized).
 *
 * @param array $incoming New (possibly partial) policy values.
 * @return bool True on success.
 */
function nxtcc_fm_update_options( array $incoming ): bool {
	$old    = nxtcc_fm_get_options();
	$merged = array_merge( $old, $incoming );

	$clean = array();

	// Boolean-ish toggles (stored as 0/1).
	$bool_keys = array(
		'show_password',
		'force_migrate',
		'grace_enabled',
		'redirect_wp_login',
		'widget_branding',
	);

	foreach ( $bool_keys as $key ) {
		$clean[ $key ] = ! empty( $merged[ $key ] ) ? 1 : 0;
	}

	// Grace days (1..90).
	$grace_days          = isset( $merged['grace_days'] ) ? absint( $merged['grace_days'] ) : 7;
	$clean['grace_days'] = max( 1, min( 90, $grace_days ) );

	// Force path: text field + normalized path.
	$raw_force_path = isset( $merged['force_path'] ) ? (string) $merged['force_path'] : '/nxt-whatsapp-login/';
	$raw_force_path = sanitize_text_field( $raw_force_path );

	$clean['force_path'] = nxtcc_fm_normalize_force_path( $raw_force_path );

	// Allowed countries: array of ISO-2 codes.
	$allowed = array();

	if ( isset( $merged['allowed_countries'] ) && is_array( $merged['allowed_countries'] ) ) {
		foreach ( $merged['allowed_countries'] as $iso ) {
			$iso = strtoupper( trim( sanitize_text_field( (string) $iso ) ) );

			if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
				$allowed[ $iso ] = true;
			}
		}
	}

	$clean['allowed_countries'] = array_keys( $allowed );

	return (bool) update_option( 'nxtcc_auth_policy', $clean );
}
