<?php
/**
 * Free-owned Pro package integrity verification helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro add-on signature verification public key.
 */
if ( ! defined( 'NXTCC_PRO_PUBLIC_KEY' ) ) {
	define( 'NXTCC_PRO_PUBLIC_KEY', 'a0S0iJVxz0s6d/EbZgv5AB1LMZ9cnBF8EFcB4rZSxTQ=' );
}


if ( ! function_exists( 'nxtcc_pro_should_bypass_integrity_check' ) ) {
	/**
	 * Whether the Free-owned Pro integrity guard is bypassed.
	 *
	 * @return bool
	 */
	function nxtcc_pro_should_bypass_integrity_check(): bool {
		return defined( 'NXTCC_PRO_INTEGRITY_BYPASS_ACTIVE' ) && true === NXTCC_PRO_INTEGRITY_BYPASS_ACTIVE;
	}
}

if ( ! function_exists( 'nxtcc_get_pro_plugin_basename' ) ) {
	/**
	 * Return the canonical Pro plugin basename.
	 *
	 * @return string
	 */
	function nxtcc_get_pro_plugin_basename(): string {
		return 'nxt-cloud-chat-pro/nxt-cloud-chat-pro.php';
	}
}

if ( ! function_exists( 'nxtcc_get_pro_plugin_file' ) ) {
	/**
	 * Return the absolute Pro main plugin file path.
	 *
	 * @return string
	 */
	function nxtcc_get_pro_plugin_file(): string {
		return trailingslashit( WP_PLUGIN_DIR ) . nxtcc_get_pro_plugin_basename();
	}
}

if ( ! function_exists( 'nxtcc_get_pro_plugin_dir' ) ) {
	/**
	 * Return the absolute Pro plugin directory path.
	 *
	 * @return string
	 */
	function nxtcc_get_pro_plugin_dir(): string {
		return trailingslashit( WP_PLUGIN_DIR ) . 'nxt-cloud-chat-pro/';
	}
}

if ( ! function_exists( 'nxtcc_pro_read_local_file_contents' ) ) {
	/**
	 * Read a local file from disk through WP_Filesystem.
	 *
	 * @param string $file_path Absolute local file path.
	 * @return string|false
	 */
	function nxtcc_pro_read_local_file_contents( string $file_path ) {
		if ( '' === $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			WP_Filesystem();
		}

		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return false;
		}

		$contents = $wp_filesystem->get_contents( $file_path );

		return is_string( $contents ) ? $contents : false;
	}
}

if ( ! function_exists( 'nxtcc_pro_clear_integrity_block_state' ) ) {
	/**
	 * Clear the stored Pro integrity block state.
	 *
	 * @param bool $set_valid Whether to reset the integrity failure plan state.
	 * @return void
	 */
	function nxtcc_pro_clear_integrity_block_state( bool $set_valid = true ): void {
		delete_option( 'nxtcc_pro_blocked' );

		if ( ! $set_valid ) {
			return;
		}

		$current_status = (string) get_option( 'nxtcc_plan_status', '' );
		if ( '' === $current_status || 'FAILED_INTEGRITY_CHECK' === $current_status ) {
			update_option( 'nxtcc_plan_status', 'VALID', false );
		}
	}
}

if ( ! function_exists( 'nxtcc_pro_set_integrity_block_state' ) ) {
	/**
	 * Persist Pro integrity failure state.
	 *
	 * @param string $reason Human-readable reason for the block state.
	 * @return void
	 */
	function nxtcc_pro_set_integrity_block_state( string $reason ): void {
		if ( nxtcc_pro_should_bypass_integrity_check() ) {
			nxtcc_pro_clear_integrity_block_state( false );
			return;
		}

		update_option(
			'nxtcc_pro_blocked',
			array(
				'reason' => $reason,
				'source' => 'free-integrity-check',
				'time'   => time(),
			),
			false
		);

		update_option( 'nxtcc_license_plan', 'FREE', false );
		update_option( 'nxtcc_plan_status', 'FAILED_INTEGRITY_CHECK', false );

		if ( ! defined( 'NXTCC_PRO_DISABLED' ) ) {
			define( 'NXTCC_PRO_DISABLED', true );
		}
	}
}

if ( ! function_exists( 'nxtcc_pro_is_integrity_blocked' ) ) {
	/**
	 * Whether Pro is currently blocked by integrity verification.
	 *
	 * @return bool
	 */
	function nxtcc_pro_is_integrity_blocked(): bool {
		if ( nxtcc_pro_should_bypass_integrity_check() ) {
			nxtcc_pro_clear_integrity_block_state( false );
			return false;
		}

		if ( defined( 'NXTCC_PRO_DISABLED' ) && NXTCC_PRO_DISABLED ) {
			return true;
		}

		$blocked = get_option( 'nxtcc_pro_blocked' );
		if ( ! is_array( $blocked ) || empty( $blocked['time'] ) ) {
			return false;
		}

		if ( ! defined( 'NXTCC_PRO_DISABLED' ) ) {
			define( 'NXTCC_PRO_DISABLED', true );
		}

		return true;
	}
}

if ( ! function_exists( 'nxtcc_pro_get_integrity_block_reason' ) ) {
	/**
	 * Read the current integrity block reason.
	 *
	 * @return string
	 */
	function nxtcc_pro_get_integrity_block_reason(): string {
		$blocked = get_option( 'nxtcc_pro_blocked' );
		if ( ! is_array( $blocked ) ) {
			return '';
		}

		$reason = isset( $blocked['reason'] ) ? (string) $blocked['reason'] : '';
		return trim( $reason );
	}
}

if ( ! function_exists( 'nxtcc_pro_integrity_block_notice' ) ) {
	/**
	 * Render an admin notice when the Pro package is blocked.
	 *
	 * @return void
	 */
	function nxtcc_pro_integrity_block_notice(): void {
		if ( nxtcc_pro_should_bypass_integrity_check() || ! current_user_can( 'manage_options' ) || ! nxtcc_pro_is_integrity_blocked() ) {
			return;
		}

		$plugin_data = get_file_data(
			nxtcc_get_pro_plugin_file(),
			array(
				'name' => 'Plugin Name',
				'uri'  => 'Plugin URI',
			),
			'plugin'
		);

		$plugin_name = isset( $plugin_data['name'] ) ? trim( (string) $plugin_data['name'] ) : __( 'NXT Cloud Chat Pro', 'nxt-cloud-chat' );
		$plugin_uri  = isset( $plugin_data['uri'] ) ? trim( (string) $plugin_data['uri'] ) : '';

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: Plugin name. */
				__( 'A malfunctioned or corrupted %1$s installation was detected. Please install the original package again.', 'nxt-cloud-chat' ),
				$plugin_name
			)
		);

		if ( '' !== $plugin_uri ) {
			echo ' ';
			echo '<a class="button button-secondary" href="' . esc_url( $plugin_uri ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $plugin_name ) . '</a>';
		}

		echo '</p></div>';
	}
}

if ( ! function_exists( 'nxtcc_run_integrity_check' ) ) {
	/**
	 * Verify the installed Pro package against its signed manifest.
	 *
	 * @return void
	 */
	function nxtcc_run_integrity_check(): void {
		if ( nxtcc_pro_should_bypass_integrity_check() ) {
			nxtcc_pro_clear_integrity_block_state( false );
			return;
		}

		if ( ! nxtcc_is_pro_active() ) {
			nxtcc_pro_clear_integrity_block_state();
			return;
		}

		$pro_dir        = nxtcc_get_pro_plugin_dir();
		$normalized_dir = trailingslashit( wp_normalize_path( $pro_dir ) );

		if ( ! is_dir( $pro_dir ) ) {
			nxtcc_pro_clear_integrity_block_state();
			return;
		}

		$manifest_path = $pro_dir . 'manifest.json';
		$sig_path      = $pro_dir . 'manifest.sig';

		if ( ! is_file( $manifest_path ) || ! is_file( $sig_path ) ) {
			nxtcc_pro_set_integrity_block_state( 'Missing manifest or signature.' );
			return;
		}

		$manifest_json = nxtcc_pro_read_local_file_contents( $manifest_path );
		$sig_text      = nxtcc_pro_read_local_file_contents( $sig_path );

		if ( false === $manifest_json || false === $sig_text ) {
			nxtcc_pro_set_integrity_block_state( 'Unable to read manifest or signature.' );
			return;
		}

		if ( ! function_exists( 'sodium_base642bin' ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			nxtcc_pro_set_integrity_block_state( 'Sodium not available on server.' );
			return;
		}

		$signature = '';
		try {
			$signature = sodium_base642bin( trim( $sig_text ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( Exception $exception ) {
			$signature = '';
		}

		if ( '' === $signature ) {
			nxtcc_pro_set_integrity_block_state( 'Invalid manifest signature format.' );
			return;
		}

		$public_key = '';
		try {
			$public_key = sodium_base642bin( NXTCC_PRO_PUBLIC_KEY, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( Exception $exception ) {
			$public_key = '';
		}

		if ( '' === $public_key || 32 !== strlen( $public_key ) ) {
			nxtcc_pro_set_integrity_block_state( 'Invalid public key.' );
			return;
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $manifest_json, $public_key ) ) {
			nxtcc_pro_set_integrity_block_state( ' ' );
			return;
		}

		$manifest = json_decode( $manifest_json, true );
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) || empty( $manifest['tree_hash'] ) ) {
			nxtcc_pro_set_integrity_block_state( 'Invalid manifest content.' );
			return;
		}

		foreach ( $manifest['files'] as $relative_path => $expected_hash ) {
			$relative_path = ltrim( (string) $relative_path, '/\\' );
			$full_path     = wp_normalize_path( $pro_dir . $relative_path );

			if ( 0 !== strpos( $full_path, $normalized_dir ) ) {
				nxtcc_pro_set_integrity_block_state( 'Invalid manifest path entry.' );
				return;
			}

			if ( ! is_file( $full_path ) ) {
				nxtcc_pro_set_integrity_block_state( 'Missing file from manifest: ' . $relative_path );
				return;
			}

			$expected_hash = strtolower( trim( (string) $expected_hash ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
				nxtcc_pro_set_integrity_block_state( 'Invalid manifest file hash entry.' );
				return;
			}

			$actual_hash = hash_file( 'sha256', $full_path );
			if ( ! is_string( $actual_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $actual_hash ) ) {
				nxtcc_pro_set_integrity_block_state( 'Unable to compute file hash: ' . $relative_path );
				return;
			}

			if ( ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
				nxtcc_pro_set_integrity_block_state( 'File hash mismatch: ' . $relative_path );
				return;
			}
		}

		$hashes = array_values( $manifest['files'] );
		sort( $hashes, SORT_STRING );

		$expected_tree_hash = strtolower( trim( (string) $manifest['tree_hash'] ) );
		$actual_tree_hash   = hash( 'sha256', implode( '', $hashes ) );

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_tree_hash ) || ! hash_equals( $expected_tree_hash, $actual_tree_hash ) ) {
			nxtcc_pro_set_integrity_block_state( 'Tree hash mismatch.' );
			return;
		}

		nxtcc_pro_clear_integrity_block_state();

		if ( ! defined( 'NXTCC_PRO_VERIFIED' ) ) {
			define( 'NXTCC_PRO_VERIFIED', true );
		}
	}
}

if ( ! function_exists( 'nxtcc_maybe_run_integrity_check_once' ) ) {
	/**
	 * Run the Pro integrity check once per short transient window.
	 *
	 * @return void
	 */
	function nxtcc_maybe_run_integrity_check_once(): void {
		if ( nxtcc_pro_should_bypass_integrity_check() ) {
			nxtcc_pro_clear_integrity_block_state( false );
			return;
		}

		if ( ! nxtcc_is_pro_active() ) {
			nxtcc_pro_clear_integrity_block_state();
			return;
		}

		if ( get_transient( 'nxtcc_pro_integrity_guard' ) ) {
			return;
		}

		set_transient( 'nxtcc_pro_integrity_guard', 1, MINUTE_IN_SECONDS );
		nxtcc_run_integrity_check();
	}
}

if ( ! function_exists( 'nxtcc_pro_integrity_ensure_schedule' ) ) {
	/**
	 * Ensure the periodic Pro integrity check is scheduled.
	 *
	 * @return void
	 */
	function nxtcc_pro_integrity_ensure_schedule(): void {
		if ( ! wp_next_scheduled( 'nxtcc_pro_integrity_daily' ) ) {
			wp_schedule_event( time(), 'daily', 'nxtcc_pro_integrity_daily' );
		}
	}
}

if ( ! function_exists( 'nxtcc_pro_integrity_activate' ) ) {
	/**
	 * Activate Pro integrity scheduling from Free.
	 *
	 * @return void
	 */
	function nxtcc_pro_integrity_activate(): void {
		nxtcc_pro_integrity_ensure_schedule();
		nxtcc_run_integrity_check();
	}
}

if ( ! function_exists( 'nxtcc_pro_integrity_deactivate' ) ) {
	/**
	 * Clear Free-owned Pro integrity schedules.
	 *
	 * @return void
	 */
	function nxtcc_pro_integrity_deactivate(): void {
		delete_transient( 'nxtcc_pro_integrity_guard' );
		wp_clear_scheduled_hook( 'nxtcc_pro_integrity_daily' );
	}
}

add_action( 'plugins_loaded', 'nxtcc_pro_integrity_ensure_schedule', 1 );
add_action( 'plugins_loaded', 'nxtcc_maybe_run_integrity_check_once', 1 );
add_action( 'nxtcc_pro_integrity_daily', 'nxtcc_run_integrity_check' );
add_action( 'admin_notices', 'nxtcc_pro_integrity_block_notice' );

if ( defined( 'NXTCC_PLUGIN_FILE' ) ) {
	register_activation_hook( NXTCC_PLUGIN_FILE, 'nxtcc_pro_integrity_activate' );
	register_deactivation_hook( NXTCC_PLUGIN_FILE, 'nxtcc_pro_integrity_deactivate' );
}
