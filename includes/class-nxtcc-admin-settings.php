<?php
/**
 * Admin settings controller.
 *
 * Handles:
 * - Registering plugin settings.
 * - Rendering the admin settings page (saving tenant connection settings).
 * - AJAX actions for generating webhook verify token and checking connections.
 *
 * This file contains only the controller class to satisfy PHPCS rules.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main admin settings controller.
 */
final class NXTCC_Admin_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_nxtcc_generate_webhook_token', array( __CLASS__, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_nxtcc_check_connections', array( __CLASS__, 'ajax_check_connections' ) );
	}

	/**
	 * Register plain WP option(s).
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'nxtcc_settings_group',
			'nxtcc_delete_data_on_uninstall',
			array(
				'type'              => 'integer',
				'sanitize_callback' => static function ( $value ) {
					return ! empty( $value ) ? 1 : 0;
				},
				'default'           => 0,
			)
		);

		register_setting(
			'nxtcc_settings_group',
			'nxtcc_data_cleanup_settings',
			array(
				'type'    => 'array',
				'default' => array(),
			)
		);
	}

	/**
	 * Load helper/DAO dependencies for settings workflows.
	 *
	 * This keeps dependency loading centralized and prevents repeated requires.
	 *
	 * @return void
	 */
	private static function load_helpers(): void {
		$helpers_class_file = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
		if ( file_exists( $helpers_class_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
		}

		$helpers_funcs_file = NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
		if ( file_exists( $helpers_funcs_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
		}

		$dao_class_file = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';
		if ( file_exists( $dao_class_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';
		}

		if ( class_exists( 'NXTCC_DAO' ) ) {
			NXTCC_DAO::init();
		}
	}

	/**
	 * Check if POST key exists.
	 *
	 * Uses a sanitizing filter to avoid using raw superglobals.
	 *
	 * @param string $key Key name.
	 * @return bool True when present.
	 */
	private static function post_has( string $key ): bool {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ( null !== $value );
	}

	/**
	 * Read a sanitized text field from POST.
	 *
	 * @param string $key Key name.
	 * @return string Sanitized value.
	 */
	private static function post_text( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $value ) {
			$value = '';
		}
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Read a positive integer from POST.
	 *
	 * @param string $key Key name.
	 * @return int
	 */
	private static function post_int( string $key ): int {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT );
		return absint( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Read a list of values from POST.
	 *
	 * @param string $key Key name.
	 * @return array<int,string>
	 */
	private static function post_array( string $key ): array {
		$value = filter_input(
			INPUT_POST,
			$key,
			FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			array(
				'flags' => FILTER_REQUIRE_ARRAY,
			)
		);

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map(
			static function ( $item ): string {
				return sanitize_text_field( wp_unslash( (string) $item ) );
			},
			$value
		);
	}

	/**
	 * Read a sanitized "secret" (token, verify token etc.) from POST.
	 *
	 * This allows a broader safe character set than sanitize_text_field(), while
	 * still stripping tags and control characters.
	 *
	 * @param string $key Key name.
	 * @return string Sanitized secret.
	 */
	private static function post_secret( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $value ) {
			$value = '';
		}

		$value = (string) wp_unslash( $value );
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		$value = preg_replace( '/[^A-Za-z0-9_\-\.\/=\+\~@#:]/u', '', $value );

		return trim( (string) $value );
	}

	/**
	 * Current logged-in user's email.
	 *
	 * @return string
	 */
	private static function current_user_mailid(): string {
		$user = wp_get_current_user();

		return $user instanceof WP_User ? sanitize_email( (string) $user->user_email ) : '';
	}

	/**
	 * Resolve the active tenant for the current user.
	 *
	 * @return array<string,string>
	 */
	private static function active_tenant_context(): array {
		if ( class_exists( 'NXTCC_Access_Control' ) ) {
			return NXTCC_Access_Control::get_current_tenant_context();
		}

		return array(
			'user_mailid'         => '',
			'business_account_id' => '',
			'phone_number_id'     => '',
		);
	}

	/**
	 * Resolve the owner email to use for settings writes.
	 *
	 * @param array  $tenant           Active tenant tuple.
	 * @param string $fallback_mailid Current user email.
	 * @return string
	 */
	private static function active_settings_owner_mailid( array $tenant, string $fallback_mailid ): string {
		$mailid = isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '';

		if ( '' !== $mailid ) {
			return $mailid;
		}

		return sanitize_email( $fallback_mailid );
	}

	/**
	 * Resolve one settings row for the active tenant.
	 *
	 * @param array  $tenant           Active tenant tuple.
	 * @param string $fallback_mailid Current user email.
	 * @return object|null
	 */
	private static function active_settings_row( array $tenant, string $fallback_mailid ) {
		if (
			class_exists( 'NXTCC_Access_Control' ) &&
			! empty( $tenant['user_mailid'] ) &&
			! empty( $tenant['business_account_id'] ) &&
			! empty( $tenant['phone_number_id'] )
		) {
			$row = NXTCC_Access_Control::get_settings_row_for_tenant( $tenant );

			if ( is_object( $row ) ) {
				return $row;
			}
		}

		if ( '' !== $fallback_mailid ) {
			return NXTCC_Settings_DAO::get_latest_for_user( $fallback_mailid );
		}

		return null;
	}

	/**
	 * Resolve readable WordPress role labels for one user.
	 *
	 * @param WP_User $user User object.
	 * @return array<int,string>
	 */
	private static function wp_user_role_labels( WP_User $user ): array {
		global $wp_roles;

		$labels = array();
		$roles  = is_array( $user->roles ) ? $user->roles : array();

		foreach ( $roles as $role_key ) {
			$role_key = sanitize_key( (string) $role_key );

			if ( '' === $role_key ) {
				continue;
			}

			if ( $wp_roles instanceof WP_Roles && isset( $wp_roles->roles[ $role_key ]['name'] ) ) {
				$labels[] = sanitize_text_field( (string) $wp_roles->roles[ $role_key ]['name'] );
			} else {
				$labels[] = ucwords( str_replace( '_', ' ', $role_key ) );
			}
		}

		$labels = array_values( array_unique( $labels ) );

		return $labels;
	}

	/**
	 * Return the eligible WordPress staff-role labels.
	 *
	 * @return array<int,string>
	 */
	private static function eligible_staff_role_labels(): array {
		if ( class_exists( 'NXTCC_Access_Control' ) ) {
			return NXTCC_Access_Control::get_eligible_staff_role_labels();
		}

		return array();
	}

	/**
	 * Return the eligible WordPress staff-role list as text.
	 *
	 * @return string
	 */
	private static function eligible_staff_role_list(): string {
		$labels = self::eligible_staff_role_labels();

		return ! empty( $labels ) ? implode( ', ', $labels ) : __( 'Administrator, Editor, and NXT Cloud Chat Team', 'nxt-cloud-chat' );
	}

	/**
	 * Build a small WP user list for team access management.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function team_access_users(): array {
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);

		$rows = array();

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$role_keys = array_map(
				static function ( $role_key ): string {
					return sanitize_key( (string) $role_key );
				},
				is_array( $user->roles ) ? $user->roles : array()
			);

			$rows[] = array(
				'ID'                => (int) $user->ID,
				'display_name'      => sanitize_text_field( (string) $user->display_name ),
				'user_email'        => sanitize_email( (string) $user->user_email ),
				'user_login'        => sanitize_user( (string) $user->user_login, true ),
				'roles'             => self::wp_user_role_labels( $user ),
				'role_keys'         => array_values( array_unique( array_filter( $role_keys ) ) ),
				'is_staff_eligible' => class_exists( 'NXTCC_Access_Control' ) && NXTCC_Access_Control::is_user_staff_eligible( $user ),
			);
		}

		return $rows;
	}

	/**
	 * Build a quick lookup table for team users.
	 *
	 * @param array<int,array<string,mixed>> $users User rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function team_access_users_by_id( array $users ): array {
		$lookup = array();

		foreach ( $users as $user ) {
			if ( ! is_array( $user ) || empty( $user['ID'] ) ) {
				continue;
			}

			$lookup[ (int) $user['ID'] ] = $user;
		}

		return $lookup;
	}

	/**
	 * Return assignable users who are not already mapped to the tenant.
	 *
	 * @param array<int,array<string,mixed>> $users All users.
	 * @param array<int,array<string,mixed>> $access_rows Access rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function team_access_available_users( array $users, array $access_rows ): array {
		$assigned = array();

		foreach ( $access_rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['wp_user_id'] ) ) {
				$assigned[] = (int) $row['wp_user_id'];
			}
		}

		$assigned  = array_values( array_unique( $assigned ) );
		$available = array();

		foreach ( $users as $user ) {
			if ( ! is_array( $user ) || empty( $user['ID'] ) ) {
				continue;
			}

			if ( empty( $user['is_staff_eligible'] ) ) {
				continue;
			}

			if ( in_array( (int) $user['ID'], $assigned, true ) ) {
				continue;
			}

			$available[] = $user;
		}

		return $available;
	}

	/**
	 * Format one UTC MySQL datetime into a local admin label.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private static function admin_local_datetime( string $datetime ): string {
		$datetime = trim( $datetime );

		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d h:i A', $timestamp, wp_timezone() );
	}

	/**
	 * Build Team Access member rows for the view.
	 *
	 * @param array<int,array<string,mixed>>    $access_rows Access rows.
	 * @param array<int,array<string,mixed>>    $users       WordPress users.
	 * @param array<string,array<string,mixed>> $capabilities Capability catalog.
	 * @param array<string,array<string,mixed>> $role_presets Role presets.
	 * @return array<int,array<string,mixed>>
	 */
	private static function team_access_members(
		array $access_rows,
		array $users,
		array $capabilities,
		array $role_presets
	): array {
		$users_by_id = self::team_access_users_by_id( $users );
		$members     = array();
		$role_list   = self::eligible_staff_role_list();

		foreach ( $access_rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['wp_user_id'] ) ) {
				continue;
			}

			$user_id     = (int) $row['wp_user_id'];
			$user        = isset( $users_by_id[ $user_id ] ) ? $users_by_id[ $user_id ] : array();
			$is_owner    = ! empty( $row['is_owner'] );
			$cap_list    = isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ? array_values( $row['capabilities'] ) : array();
			$role_key    = $is_owner ? 'owner' : NXTCC_Access_Control::sanitize_role_key( (string) ( $row['role_key'] ?? 'custom' ) );
			$role_preset = isset( $role_presets[ $role_key ] ) ? $role_presets[ $role_key ] : null;
			$role_label  = $is_owner ? __( 'Tenant Owner', 'nxt-cloud-chat' ) : ( is_array( $role_preset ) ? (string) $role_preset['label'] : __( 'Custom', 'nxt-cloud-chat' ) );
			$role_note   = $is_owner ? __( 'Has every tenant capability.', 'nxt-cloud-chat' ) : ( is_array( $role_preset ) ? (string) $role_preset['description'] : __( 'Uses a custom permission mix for this tenant.', 'nxt-cloud-chat' ) );
			$role_labels = isset( $user['roles'] ) && is_array( $user['roles'] ) ? array_values( $user['roles'] ) : array();
			$is_eligible = ! empty( $user['is_staff_eligible'] );
			$cap_labels  = array();
			$status_note = $is_eligible
				? ''
				: sprintf(
					/* translators: %s: list of allowed WordPress roles */
					__( 'This WordPress user cannot access NXT Cloud Chat until the role is changed to one of: %s.', 'nxt-cloud-chat' ),
					$role_list
				);

			foreach ( $cap_list as $capability ) {
				$capability = sanitize_key( (string) $capability );

				if ( '' !== $capability && isset( $capabilities[ $capability ]['label'] ) ) {
					$cap_labels[] = (string) $capabilities[ $capability ]['label'];
				}
			}

			$cap_labels = array_values( array_unique( $cap_labels ) );
			$preview    = array_slice( $cap_labels, 0, 4 );
			$search     = strtolower(
				implode(
					' ',
					array_filter(
						array(
							isset( $user['display_name'] ) ? (string) $user['display_name'] : '',
							isset( $user['user_email'] ) ? (string) $user['user_email'] : '',
							isset( $user['user_login'] ) ? (string) $user['user_login'] : '',
							$is_eligible ? '' : __( 'inactive role', 'nxt-cloud-chat' ),
							implode( ' ', $role_labels ),
							$role_label,
							implode( ' ', $cap_labels ),
						)
					)
				)
			);

			$members[] = array(
				'user_id'                => $user_id,
				'display_name'           => isset( $user['display_name'] ) && '' !== (string) $user['display_name'] ? (string) $user['display_name'] : __( 'Unknown user', 'nxt-cloud-chat' ),
				'user_email'             => isset( $user['user_email'] ) ? (string) $user['user_email'] : '',
				'user_login'             => isset( $user['user_login'] ) ? (string) $user['user_login'] : '',
				'roles'                  => $role_labels,
				'roles_display'          => ! empty( $role_labels ) ? implode( ', ', $role_labels ) : __( 'No WordPress role', 'nxt-cloud-chat' ),
				'wp_role_eligible'       => $is_eligible,
				'wp_role_status_note'    => $status_note,
				'role_key'               => $role_key,
				'role_label'             => $role_label,
				'role_note'              => $role_note,
				'capabilities'           => $cap_list,
				'capability_labels'      => $cap_labels,
				'capability_preview'     => $preview,
				'extra_capability_count' => max( 0, count( $cap_labels ) - count( $preview ) ),
				'is_owner'               => $is_owner,
				'updated_at'             => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
				'updated_at_display'     => self::admin_local_datetime( isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '' ),
				'search_text'            => $search,
			);
		}

		return $members;
	}

	/**
	 * Whether the current user may manage active tenant settings.
	 *
	 * @return bool
	 */
	private static function can_manage_settings(): bool {
		return NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_settings' ) );
	}

	/**
	 * Whether the current user may manage active tenant team access.
	 *
	 * @return bool
	 */
	private static function can_manage_team_access(): bool {
		return NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_team_access' ) );
	}

	/**
	 * Handle Team Access add/update/remove submissions.
	 *
	 * @param array $tenant Primary tenant tuple.
	 * @return void
	 */
	private static function handle_team_access_submission( array $tenant ): void {
		$action = self::post_text( 'nxtcc_team_access_action' );

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'nxtcc_team_access_save', 'nxtcc_team_access_nonce' );

		if ( ! self::can_manage_team_access() ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_forbidden',
				__( 'You do not have permission to manage tenant team access.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		if ( empty( $tenant['user_mailid'] ) || empty( $tenant['business_account_id'] ) || empty( $tenant['phone_number_id'] ) ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_missing_tenant',
				__( 'Save the tenant connection first before managing team access.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$target_user_id = self::post_int( 'nxtcc_team_user_id' );
		$acting_user_id = get_current_user_id();
		$target_user    = $target_user_id > 0 ? get_userdata( $target_user_id ) : false;

		if ( ! $target_user instanceof WP_User ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_invalid_user',
				__( 'Select a valid WordPress user to manage tenant access.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$existing_row = NXTCC_Tenant_Access_DAO::get_user_access( $target_user_id, $tenant );
		if ( is_array( $existing_row ) && ! empty( $existing_row['is_owner'] ) && 'remove' === $action ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_owner_remove',
				__( 'The tenant owner cannot be removed from Team Access.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		if ( in_array( $action, array( 'add', 'update' ), true ) ) {
			if ( ! class_exists( 'NXTCC_Access_Control' ) || ! NXTCC_Access_Control::is_user_staff_eligible( $target_user ) ) {
				add_settings_error(
					'nxtcc_settings',
					'nxtcc_team_access_ineligible_wp_role',
					sprintf(
						/* translators: %s: list of allowed WordPress roles */
						__( 'Only these WordPress roles can receive tenant access: %s.', 'nxt-cloud-chat' ),
						self::eligible_staff_role_list()
					),
					'error'
				);
				return;
			}

			$role_key     = NXTCC_Access_Control::sanitize_role_key( self::post_text( 'nxtcc_team_role_key' ) );
			$capabilities = NXTCC_Access_Control::resolve_role_capabilities( $role_key, self::post_array( 'nxtcc_team_caps' ) );

			if ( empty( $capabilities ) ) {
				add_settings_error(
					'nxtcc_settings',
					'nxtcc_team_access_missing_caps',
					__( 'Select at least one capability before saving team access.', 'nxt-cloud-chat' ),
					'error'
				);
				return;
			}

			$ok = NXTCC_Tenant_Access_DAO::upsert_access(
				$target_user_id,
				$tenant,
				$capabilities,
				$acting_user_id,
				false,
				$role_key
			);

			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_saved_' . $target_user_id,
				$ok
					? __( 'Team access saved.', 'nxt-cloud-chat' )
					: __( 'Could not save the selected team access.', 'nxt-cloud-chat' ),
				$ok ? 'updated' : 'error'
			);

			return;
		}

		if ( 'remove' === $action ) {
			$ok = NXTCC_Tenant_Access_DAO::delete_access( $target_user_id, $tenant );

			add_settings_error(
				'nxtcc_settings',
				'nxtcc_team_access_removed_' . $target_user_id,
				$ok
					? __( 'Team access removed.', 'nxt-cloud-chat' )
					: __( 'Could not remove the selected team access.', 'nxt-cloud-chat' ),
				$ok ? 'updated' : 'error'
			);
		}
	}

	/**
	 * AJAX: Generate & persist webhook verify token hash for the tenant.
	 *
	 * POST: nonce, business_account_id, phone_number_id.
	 *
	 * @return void
	 */
	public static function ajax_generate_token(): void {
		if ( ! self::can_manage_settings() ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$baid = self::post_text( 'business_account_id' );
		$pnid = self::post_text( 'phone_number_id' );

		if ( '' === $baid || '' === $pnid ) {
			wp_send_json_error( array( 'message' => 'Missing tenant identifiers' ), 400 );
		}

		$user_mailid = self::active_settings_owner_mailid( self::active_tenant_context(), self::current_user_mailid() );
		if ( '' === $user_mailid ) {
			wp_send_json_error( array( 'message' => 'Cannot resolve user' ), 400 );
		}

		$token = 'nxtcc-webhook-verify-' . wp_generate_password( 8, false );
		$hash  = hash( 'sha256', $token );

		$ok = NXTCC_Settings_DAO::upsert_verify_token_hash( $user_mailid, $baid, $pnid, $hash );

		if ( $ok ) {
			wp_send_json_success( array( 'token' => $token ) );
		}

		wp_send_json_error( array( 'message' => 'DB write failed' ), 500 );
	}

	/**
	 * Render settings page HTML.
	 *
	 * Logic only; the view is located at admin/pages/settings-view.php.
	 *
	 * @return void
	 */
	public static function settings_page_html(): void {
		if ( ! current_user_can( NXTCC_Access_Control::access_settings_capability() ) ) {
			return;
		}

		$current_user_id       = get_current_user_id();
		$user_mailid           = self::current_user_mailid();
		$active_tenant         = self::active_tenant_context();
		$settings_owner_mailid = self::active_settings_owner_mailid( $active_tenant, $user_mailid );
		$nxtcc_active_tab      = self::post_text( 'nxtcc_settings_active_tab' );
		$nxtcc_settings_action = self::post_text( 'nxtcc_settings_action' );

		if ( '' === $nxtcc_active_tab ) {
			$nxtcc_active_tab = self::can_manage_settings() ? 'connection' : 'team-access';
		}

		if ( self::post_has( 'nxtcc_save_settings' ) || 'save_connection_settings' === $nxtcc_settings_action ) {
			check_admin_referer( 'nxtcc_settings_save', 'nxtcc_settings_nonce' );

			$nxtcc_active_tab = 'connection';

			if ( ! self::can_manage_settings() ) {
				add_settings_error(
					'nxtcc_settings',
					'nxtcc_settings_forbidden',
					__( 'You do not have permission to update tenant connection settings.', 'nxt-cloud-chat' ),
					'error'
				);
			} else {

				$app_id              = self::post_text( 'nxtcc_app_id' );
				$access_token_plain  = self::post_secret( 'nxtcc_access_token' );
				$app_secret_plain    = self::post_secret( 'nxtcc_app_secret' );
				$business_account_id = self::post_text( 'nxtcc_whatsapp_business_account_id' );
				$phone_number_id     = self::post_text( 'nxtcc_phone_number_id' );
				$phone_number        = self::post_text( 'nxtcc_phone_number' );
				$verify_token_input  = self::post_secret( 'nxtcc_meta_webhook_verify_token' );

				$webhook_subscribed = self::post_has( 'nxtcc_meta_webhook_subscribed' ) ? 1 : 0;

				$errors = array();
				if ( '' === $app_id ) {
					$errors[] = 'App ID is required.';
				}
				if ( '' === $business_account_id ) {
					$errors[] = 'Business Account ID is required.';
				}
				if ( '' === $phone_number_id ) {
					$errors[] = 'Phone Number ID is required.';
				}

				if ( 1 === (int) $webhook_subscribed && '' !== $settings_owner_mailid ) {
					if ( ! NXTCC_Settings_DAO::supports_app_secret_columns() ) {
						$errors[] = 'App Secret storage is not ready. Please refresh the plugin schema and try again.';
					} elseif (
						'' === $app_secret_plain
						&& ! NXTCC_Settings_DAO::has_saved_app_secret_for_tenant( $settings_owner_mailid, $business_account_id, $phone_number_id )
					) {
						$errors[] = 'App Secret is required when Webhook (Incoming) is enabled.';
					}
				}

				if ( ! empty( $errors ) ) {
					foreach ( $errors as $msg ) {
						add_settings_error(
							'nxtcc_settings',
							'nxtcc_settings_error_' . md5( (string) $msg ),
							sanitize_text_field( (string) $msg ),
							'error'
						);
					}
				}

				if ( empty( $errors ) && '' === $settings_owner_mailid ) {
					add_settings_error(
						'nxtcc_settings',
						'nxtcc_settings_missing_owner',
						__( 'Could not resolve the active tenant owner for these settings.', 'nxt-cloud-chat' ),
						'error'
					);
				}

				if ( empty( $errors ) && '' !== $settings_owner_mailid ) {
					$data = array(
						'user_mailid'             => $settings_owner_mailid,
						'app_id'                  => $app_id,
						'business_account_id'     => $business_account_id,
						'phone_number_id'         => $phone_number_id,
						'phone_number'            => $phone_number,
						'meta_webhook_subscribed' => (int) $webhook_subscribed,
					);

					if ( '' !== $access_token_plain || '' !== $app_secret_plain ) {
						self::load_helpers();
					}

					$access_token_save_failed = false;
					$app_secret_save_failed   = false;

					if ( '' !== $access_token_plain ) {
						if ( function_exists( 'nxtcc_crypto_encrypt' ) ) {
							$enc = nxtcc_crypto_encrypt( $access_token_plain );

							if ( is_array( $enc ) && 2 === count( $enc ) ) {
								$ct_b64    = (string) $enc[0];
								$nonce_raw = $enc[1];

								if ( '' !== $ct_b64 && ! empty( $nonce_raw ) ) {
									$data['access_token_ct']    = $ct_b64;
									$data['access_token_nonce'] = $nonce_raw;
								} else {
									$access_token_save_failed = true;
								}
							} else {
								$access_token_save_failed = true;
							}
						} else {
							$access_token_save_failed = true;
						}
					}

					if ( '' !== $app_secret_plain ) {
						if ( ! NXTCC_Settings_DAO::supports_app_secret_columns() ) {
							$app_secret_save_failed = true;
						} elseif ( function_exists( 'nxtcc_crypto_encrypt' ) ) {
							$enc = nxtcc_crypto_encrypt( $app_secret_plain );

							if ( is_array( $enc ) && 2 === count( $enc ) ) {
								$ct_b64    = (string) $enc[0];
								$nonce_raw = $enc[1];

								if ( '' !== $ct_b64 && ! empty( $nonce_raw ) ) {
									$data['app_secret_ct']    = $ct_b64;
									$data['app_secret_nonce'] = $nonce_raw;
								} else {
									$app_secret_save_failed = true;
								}
							} else {
								$app_secret_save_failed = true;
							}
						} else {
							$app_secret_save_failed = true;
						}
					}

					if ( '' !== $access_token_plain && $access_token_save_failed ) {
						add_settings_error(
							'nxtcc_settings',
							'nxtcc_settings_error_access_token_encrypt',
							'Could not securely save Access Token.',
							'error'
						);
					}

					if ( '' !== $app_secret_plain && $app_secret_save_failed ) {
						add_settings_error(
							'nxtcc_settings',
							'nxtcc_settings_error_app_secret_encrypt',
							'Could not securely save App Secret.',
							'error'
						);
					}

					if ( ! $access_token_save_failed && ! $app_secret_save_failed ) {
						if ( '' !== $verify_token_input ) {
							$data['meta_webhook_verify_token_hash'] = hash( 'sha256', $verify_token_input );
						}

						if ( NXTCC_Settings_DAO::upsert_settings( $data ) ) {
							NXTCC_Access_Control::sync_primary_tenant_from_settings(
								array(
									'user_mailid'         => $settings_owner_mailid,
									'business_account_id' => $business_account_id,
									'phone_number_id'     => $phone_number_id,
								),
								$current_user_id
							);
						}
					}
				}
			}
		}

		if ( self::post_has( 'nxtcc_save_uninstall_settings' ) || 'save_uninstall_settings' === $nxtcc_settings_action ) {
			check_admin_referer( 'nxtcc_settings_uninstall_save', 'nxtcc_settings_uninstall_nonce' );

			$nxtcc_active_tab = 'connection';

			if ( ! self::can_manage_settings() ) {
				add_settings_error(
					'nxtcc_settings',
					'nxtcc_settings_uninstall_forbidden',
					__( 'You do not have permission to update uninstall preferences.', 'nxt-cloud-chat' ),
					'error'
				);
			} else {
				update_option(
					'nxtcc_delete_data_on_uninstall',
					self::post_has( 'nxtcc_delete_data_on_uninstall' ) ? 1 : 0
				);
			}
		}

		if ( self::post_has( 'nxtcc_save_cleanup_schedule' ) || 'save_cleanup_schedule_settings' === $nxtcc_settings_action ) {
			check_admin_referer( 'nxtcc_cleanup_schedule_save', 'nxtcc_cleanup_schedule_nonce' );

			$nxtcc_active_tab = 'tools';

			if ( class_exists( 'NXTCC_Data_Cleanup' ) ) {
				NXTCC_Data_Cleanup::save_schedule_settings_from_post();
			}
		}

		if ( self::post_has( 'nxtcc_reset_cleanup_retention' ) || 'reset_cleanup_retention_settings' === $nxtcc_settings_action ) {
			check_admin_referer( 'nxtcc_cleanup_retention_save', 'nxtcc_cleanup_retention_nonce' );

			$nxtcc_active_tab = 'tools';

			if ( class_exists( 'NXTCC_Data_Cleanup' ) ) {
				NXTCC_Data_Cleanup::reset_retention_settings_to_defaults_from_post();
			}
		} elseif ( self::post_has( 'nxtcc_save_cleanup_retention' ) || 'save_cleanup_retention_settings' === $nxtcc_settings_action ) {
			check_admin_referer( 'nxtcc_cleanup_retention_save', 'nxtcc_cleanup_retention_nonce' );

			$nxtcc_active_tab = 'tools';

			if ( class_exists( 'NXTCC_Data_Cleanup' ) ) {
				NXTCC_Data_Cleanup::save_retention_settings_from_post();
			}
		}

		$primary_tenant = NXTCC_Access_Control::get_primary_tenant_context();
		if ( '' !== self::post_text( 'nxtcc_team_access_action' ) ) {
			$nxtcc_active_tab = 'team-access';
			self::handle_team_access_submission( $primary_tenant );
		}

		$active_tenant         = self::active_tenant_context();
		$settings_owner_mailid = self::active_settings_owner_mailid( $active_tenant, $user_mailid );
		$settings              = self::active_settings_row( $active_tenant, $settings_owner_mailid );

		$app_id                  = is_object( $settings ) && isset( $settings->app_id ) ? (string) $settings->app_id : '';
		$business_account_id     = is_object( $settings ) && isset( $settings->business_account_id ) ? (string) $settings->business_account_id : '';
		$phone_number_id         = is_object( $settings ) && isset( $settings->phone_number_id ) ? (string) $settings->phone_number_id : '';
		$phone_number            = is_object( $settings ) && isset( $settings->phone_number ) ? (string) $settings->phone_number : '';
		$meta_webhook_subscribed = is_object( $settings ) && isset( $settings->meta_webhook_subscribed ) ? (int) $settings->meta_webhook_subscribed : 0;
		$callback_url            = esc_url( set_url_scheme( site_url( '/wp-json/nxtcc/v1/webhook/' ), 'https' ) );

		if ( self::post_has( 'nxtcc_sync_templates' ) && '' !== $settings_owner_mailid ) {
			self::load_helpers();

			if ( function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
				$creds = nxtcc_get_tenant_api_credentials( $settings_owner_mailid, $business_account_id, $phone_number_id );

				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) && function_exists( 'nxtcc_sync_templates_from_meta' ) ) {
					nxtcc_sync_templates_from_meta(
						$settings_owner_mailid,
						(string) $creds['access_token'],
						(string) $creds['business_account_id'],
						(string) $creds['phone_number_id']
					);
				}
			}
		}

		$nxtcc_can_manage_settings          = self::can_manage_settings();
		$nxtcc_can_manage_team_access       = self::can_manage_team_access();
		$nxtcc_primary_tenant               = NXTCC_Access_Control::get_primary_tenant_context();
		$nxtcc_team_eligible_wp_roles       = $nxtcc_can_manage_team_access ? NXTCC_Access_Control::get_eligible_staff_roles() : array();
		$nxtcc_team_eligible_wp_role_labels = $nxtcc_can_manage_team_access ? array_values( $nxtcc_team_eligible_wp_roles ) : array();
		$nxtcc_team_capabilities            = $nxtcc_can_manage_team_access ? NXTCC_Access_Control::get_assignable_capabilities() : array();
		$nxtcc_team_capability_sections     = $nxtcc_can_manage_team_access ? NXTCC_Access_Control::get_capability_sections() : array();
		$nxtcc_team_owner_only_capabilities = $nxtcc_can_manage_team_access ? NXTCC_Access_Control::get_owner_only_capabilities() : array();
		$nxtcc_team_role_presets            = $nxtcc_can_manage_team_access ? NXTCC_Access_Control::get_role_presets() : array();
		$nxtcc_team_access_rows             = $nxtcc_can_manage_team_access ? NXTCC_Tenant_Access_DAO::get_tenant_access_rows( $nxtcc_primary_tenant ) : array();
		$nxtcc_team_access_users            = $nxtcc_can_manage_team_access ? self::team_access_users() : array();
		$nxtcc_team_available_users         = $nxtcc_can_manage_team_access ? self::team_access_available_users( $nxtcc_team_access_users, $nxtcc_team_access_rows ) : array();
		$nxtcc_team_access_members          = $nxtcc_can_manage_team_access ? self::team_access_members( $nxtcc_team_access_rows, $nxtcc_team_access_users, NXTCC_Access_Control::get_registered_capabilities(), $nxtcc_team_role_presets ) : array();
		$nxtcc_cleanup_targets              = class_exists( 'NXTCC_Data_Cleanup' ) ? NXTCC_Data_Cleanup::get_tools_view_targets() : array();
		$nxtcc_cleanup_settings             = class_exists( 'NXTCC_Data_Cleanup' ) ? NXTCC_Data_Cleanup::get_settings() : array();
		$nxtcc_cleanup_last_run             = class_exists( 'NXTCC_Data_Cleanup' ) ? NXTCC_Data_Cleanup::get_last_run_view_data() : array();
		$nxtcc_cleanup_next_run_label       = class_exists( 'NXTCC_Data_Cleanup' ) ? NXTCC_Data_Cleanup::get_next_run_label() : '';
		$nxtcc_cleanup_can_manage           = class_exists( 'NXTCC_Data_Cleanup' ) ? NXTCC_Data_Cleanup::current_user_can_manage_tools() : current_user_can( 'manage_options' );

		include plugin_dir_path( __FILE__ ) . '/../admin/pages/settings-view.php';
	}

	/**
	 * AJAX: Check all connections (server decrypts token).
	 *
	 * @return void
	 */
	public static function ajax_check_connections(): void {
		if ( ! self::can_manage_settings() ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$app_id              = self::post_text( 'app_id' );
		$business_account_id = self::post_text( 'business_account_id' );
		$phone_number_id     = self::post_text( 'phone_number_id' );
		$test_number         = self::post_text( 'test_number' );
		$test_template       = self::post_text( 'test_template' );
		$test_language       = self::post_text( 'test_language' );

		if ( '' === $test_language ) {
			$test_language = 'en_US';
		}

		if ( '' === $app_id || '' === $business_account_id || '' === $phone_number_id ) {
			wp_send_json_error( array( 'message' => 'Missing credentials' ), 400 );
		}

		self::load_helpers();

		require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-user-settings-repo.php';
		require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-api-connection.php';

		$user_mailid = self::active_settings_owner_mailid( self::active_tenant_context(), self::current_user_mailid() );
		if ( '' === $user_mailid ) {
			wp_send_json_error( array( 'message' => 'Cannot resolve user' ), 400 );
		}

		if ( ! function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
			wp_send_json_error( array( 'message' => 'Helpers not available' ), 500 );
		}

		$creds = nxtcc_get_tenant_api_credentials( $user_mailid, $business_account_id, $phone_number_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			wp_send_json_error( array( 'message' => 'Access token not found; please save settings' ), 400 );
		}

		$results = NXTCC_API_Connection::check_all_connections(
			$app_id,
			(string) $creds['access_token'],
			$business_account_id,
			$phone_number_id,
			$test_number,
			$test_template,
			$test_language
		);

		wp_send_json_success( array( 'results' => $results ) );
	}
}
