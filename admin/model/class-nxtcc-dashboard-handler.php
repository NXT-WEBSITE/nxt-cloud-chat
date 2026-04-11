<?php
/**
 * Dashboard AJAX handler for NXT Cloud Chat.
 *
 * Exposes a minimal admin-only endpoint used by the dashboard UI to fetch
 * connection status checks for the current user.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles dashboard AJAX requests.
 */
final class NXTCC_Dashboard_Handler {

	/**
	 * AJAX action name used by the dashboard.
	 *
	 * @var string
	 */
	private const AJAX_ACTION = 'nxtcc_dashboard_fetch_overview';

	/**
	 * Nonce action name used for dashboard requests.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'nxtcc_dashboard';

	/**
	 * Nonce field name expected in POST data.
	 *
	 * @var string
	 */
	private const NONCE_FIELD = 'nonce';

	/**
	 * Register hooks for this handler.
	 *
	 * Call this once from your plugin bootstrap/admin loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'fetch_overview' ) );
	}

	/**
	 * Enforce access rules for dashboard endpoints.
	 *
	 * Requirements:
	 * - Must be logged in.
	 * - Must have manage_options capability.
	 * - Must pass a valid nonce for the dashboard action.
	 *
	 * @return void
	 */
	private static function enforce_access(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You must be logged in.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		$verified = check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false );

		if ( 1 !== $verified ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'nxt-cloud-chat' ),
				),
				400
			);
		}
	}

	/**
	 * Build connection checks for the dashboard "Connection" card.
	 *
	 * Output shape:
	 * - ok: false when core checks fail, 'warn' when core checks pass but webhook is off,
	 *       true when all checks pass.
	 * - checks: detailed booleans for each check.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_connection_status(): array {
		$checks = array(
			'waba_profile'         => false,
			'templates_list'       => false,
			'phone_number_profile' => false,
			'webhook'              => false,
		);

		$current_user = wp_get_current_user();

		if ( ! ( $current_user instanceof WP_User ) || empty( $current_user->user_email ) ) {
			return array(
				'ok'     => false,
				'checks' => $checks,
			);
		}

		$user_mailid = sanitize_email( $current_user->user_email );

		if ( '' === $user_mailid ) {
			return array(
				'ok'     => false,
				'checks' => $checks,
			);
		}

		$repo     = NXTCC_Dashboard_Repo::instance();
		$settings = $repo->get_latest_user_settings( $user_mailid );

		if (
			is_array( $settings ) &&
			! empty( $settings['app_id'] ) &&
			! empty( $settings['business_account_id'] ) &&
			! empty( $settings['phone_number_id'] )
		) {
			$checks['waba_profile']         = true;
			$checks['templates_list']       = true;
			$checks['phone_number_profile'] = true;
			$checks['webhook']              = ! empty( $settings['meta_webhook_subscribed'] );
		}

		$core_all_ok   = ( $checks['waba_profile'] && $checks['templates_list'] && $checks['phone_number_profile'] );
		$connection_ok = $core_all_ok ? ( $checks['webhook'] ? true : 'warn' ) : false;

		return array(
			'ok'     => $connection_ok,
			'checks' => $checks,
		);
	}

	/**
	 * AJAX: Return minimal dashboard overview data.
	 *
	 * Response:
	 * {
	 *   success: true,
	 *   data: {
	 *     connection: { ok, checks }
	 *   }
	 * }
	 *
	 * @return void
	 */
	public static function fetch_overview(): void {
		self::enforce_access();

		wp_send_json_success(
			array(
				'connection' => self::build_connection_status(),
			)
		);
	}
}
NXTCC_Dashboard_Handler::register();
