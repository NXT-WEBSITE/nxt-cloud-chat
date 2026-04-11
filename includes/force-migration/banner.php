<?php
/**
 * Force Migration grace banner (frontend).
 *
 * Shows a fixed banner in the footer only when:
 * - user is logged in,
 * - force_migrate is enabled,
 * - grace window is enabled,
 * - user is NOT an administrator,
 * - user is NOT yet migrated,
 * - user is still within the grace period.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', 'nxtcc_fm_show_banner' );

/**
 * Render the frontend grace banner.
 *
 * @return void
 */
function nxtcc_fm_show_banner(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( ! function_exists( 'nxtcc_fm_get_options' ) ) {
		return;
	}

	$opts = nxtcc_fm_get_options();
	if ( empty( $opts['force_migrate'] ) || empty( $opts['grace_enabled'] ) ) {
		return;
	}

	$user = wp_get_current_user();
	if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
		return;
	}

	// Skip the banner if the user is already migrated.
	if ( function_exists( 'nxtcc_fm_user_is_migrated' ) && $user && nxtcc_fm_user_is_migrated( (int) $user->ID ) ) {
		return;
	}

	if ( ! $user ) {
		return;
	}

	$first_login = get_user_meta( (int) $user->ID, '_nxtcc_fm_login_date', true );
	if ( empty( $first_login ) ) {
		return;
	}

	$first_login = (int) $first_login;

	$grace_days = isset( $opts['grace_days'] ) ? (int) $opts['grace_days'] : 1;
	$grace_days = max( 1, $grace_days );

	$expiry = $first_login + ( $grace_days * DAY_IN_SECONDS );
	if ( time() >= $expiry ) {
		return;
	}

	$remaining = (int) ceil( ( $expiry - time() ) / DAY_IN_SECONDS );

	$force_path = isset( $opts['force_path'] ) ? (string) $opts['force_path'] : '';
	$force_url  = home_url( trailingslashit( $force_path ) );
	?>
	<div id="nxtcc-fm-banner" style="position:fixed;top:0;left:0;right:0;background:#0A7C66;color:#fff;padding:.75rem 1rem;text-align:center;z-index:9999;">
		<span style="font-weight:500;">Your account upgrade is pending.</span>
		<span style="margin-left:.5rem;">Please complete WhatsApp verification within <?php echo esc_html( $remaining ); ?> day<?php echo ( $remaining > 1 ) ? 's' : ''; ?>.</span>
		<a href="<?php echo esc_url( $force_url ); ?>" style="margin-left:1rem;background:#fff;color:#0A7C66;padding:.3rem .75rem;border-radius:6px;font-weight:600;">Complete Verification</a>
		<button type="button" onclick="document.getElementById('nxtcc-fm-banner').remove();" style="margin-left:1rem;background:none;border:none;color:#fff;font-size:16px;cursor:pointer;" aria-label="<?php echo esc_attr__( 'Dismiss', 'nxt-cloud-chat' ); ?>">×</button>
	</div>
	<?php
}
