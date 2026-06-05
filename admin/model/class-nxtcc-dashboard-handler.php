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
	 * - Must have dashboard access for the active tenant.
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

		if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_dashboard' ) ) ) {
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
	 * Build one dashboard connection item.
	 *
	 * @param string $id      Item id.
	 * @param string $label   Item label.
	 * @param string $status  Item status.
	 * @param string $message Item message.
	 * @return array<string, string>
	 */
	private static function connection_item( string $id, string $label, string $status, string $message ): array {
		return array(
			'id'      => sanitize_key( $id ),
			'label'   => $label,
			'status'  => sanitize_key( $status ),
			'message' => $message,
		);
	}

	/**
	 * Build connection checks for the dashboard "Connection" card.
	 *
	 * Output shape:
	 * - ok: false when required checks fail, 'warn' when limited/unknown,
	 *       true when all checks pass.
	 * - basics: rows for the local connection basics UI.
	 * - health: Meta health_status payload.
	 *
	 * @param bool $force_refresh Whether to bypass cached Meta health.
	 * @return array<string, mixed>
	 */
	private static function build_connection_status( bool $force_refresh = false ): array {
		$checks = array(
			'tenant_context'       => false,
			'app_id'               => false,
			'access_token'         => false,
			'waba_profile'         => false,
			'phone_number_profile' => false,
			'webhook'              => false,
		);

		$tenant = NXTCC_Access_Control::get_current_tenant_context();
		$health = function_exists( 'nxtcc_get_meta_health_status' )
			? nxtcc_get_meta_health_status( $tenant, array( 'force_refresh' => $force_refresh ) )
			: array(
				'success' => false,
				'status'  => 'unknown',
				'error'   => array(
					'message' => __( 'Meta health runtime is not available.', 'nxt-cloud-chat' ),
				),
			);

		$tenant_ok = ! empty( $tenant['user_mailid'] ) && ! empty( $tenant['business_account_id'] ) && ! empty( $tenant['phone_number_id'] );

		if ( ! $tenant_ok ) {
			return array(
				'ok'     => false,
				'basics' => array(
					self::connection_item( 'tenant_context', __( 'Tenant Context', 'nxt-cloud-chat' ), 'fail', __( 'No active tenant connection is available for this user.', 'nxt-cloud-chat' ) ),
				),
				'health' => $health,
			);
		}

		$checks['tenant_context'] = true;
		$settings                 = NXTCC_Access_Control::get_settings_row_for_tenant( $tenant );
		$creds                    = false;

		if ( function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
			$creds = nxtcc_get_tenant_api_credentials(
				(string) $tenant['user_mailid'],
				(string) $tenant['business_account_id'],
				(string) $tenant['phone_number_id']
			);
		}

		if (
			is_object( $settings )
			&& ! empty( $settings->app_id )
			&& ! empty( $settings->business_account_id )
			&& ! empty( $settings->phone_number_id )
		) {
			$checks['app_id']               = true;
			$checks['waba_profile']         = true;
			$checks['phone_number_profile'] = true;
			$checks['webhook']              = ! empty( $settings->meta_webhook_subscribed );
		}

		$checks['access_token'] = is_array( $creds ) && ! empty( $creds['access_token'] );
		$core_all_ok            = ( $checks['tenant_context'] && $checks['app_id'] && $checks['access_token'] && $checks['waba_profile'] && $checks['phone_number_profile'] );
		$health_status          = isset( $health['status'] ) ? sanitize_key( (string) $health['status'] ) : 'unknown';
		$connection_ok          = false;

		if ( $core_all_ok ) {
			if ( 'fail' === $health_status ) {
				$connection_ok = false;
			} elseif ( ! $checks['webhook'] || in_array( $health_status, array( 'warn', 'unknown' ), true ) ) {
				$connection_ok = 'warn';
			} else {
				$connection_ok = true;
			}
		}

		return array(
			'ok'     => $connection_ok,
			'basics' => array(
				self::connection_item( 'tenant_context', __( 'Tenant Context', 'nxt-cloud-chat' ), 'ok', __( 'Active tenant resolved.', 'nxt-cloud-chat' ) ),
				self::connection_item( 'app_id', __( 'App ID', 'nxt-cloud-chat' ), $checks['app_id'] ? 'ok' : 'fail', $checks['app_id'] ? __( 'Saved.', 'nxt-cloud-chat' ) : __( 'Missing from connection settings.', 'nxt-cloud-chat' ) ),
				self::connection_item( 'waba_profile', __( 'Business Account ID', 'nxt-cloud-chat' ), $checks['waba_profile'] ? 'ok' : 'fail', $checks['waba_profile'] ? __( 'Saved.', 'nxt-cloud-chat' ) : __( 'Missing from connection settings.', 'nxt-cloud-chat' ) ),
				self::connection_item( 'phone_number_profile', __( 'Phone Number ID', 'nxt-cloud-chat' ), $checks['phone_number_profile'] ? 'ok' : 'fail', $checks['phone_number_profile'] ? __( 'Saved.', 'nxt-cloud-chat' ) : __( 'Missing from connection settings.', 'nxt-cloud-chat' ) ),
				self::connection_item( 'access_token', __( 'Access Token', 'nxt-cloud-chat' ), $checks['access_token'] ? 'ok' : 'fail', $checks['access_token'] ? __( 'Saved.', 'nxt-cloud-chat' ) : __( 'Missing or could not be decrypted.', 'nxt-cloud-chat' ) ),
				self::connection_item( 'webhook', __( 'Webhook', 'nxt-cloud-chat' ), $checks['webhook'] ? 'ok' : 'warn', $checks['webhook'] ? __( 'Enabled.', 'nxt-cloud-chat' ) : __( 'Not enabled.', 'nxt-cloud-chat' ) ),
			),
			'health' => $health,
		);
	}

	/**
	 * AJAX: Return minimal dashboard overview data.
	 *
	 * Response:
	 * {
	 *   success: true,
	 *   data: {
	 *     connection: { ok, basics, health }
	 *   }
	 * }
	 *
	 * @return void
	 */
	public static function fetch_overview(): void {
		self::enforce_access();

		$force_refresh = filter_input( INPUT_POST, 'force_refresh', FILTER_VALIDATE_BOOLEAN );
		$force_refresh = ( true === $force_refresh );

		wp_send_json_success(
			array(
				'connection' => self::build_connection_status( $force_refresh ),
			)
		);
	}
}
NXTCC_Dashboard_Handler::register();
