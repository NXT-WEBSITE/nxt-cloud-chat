<?php
/**
 * Access control service.
 *
 * Adds tenant-scoped capability checks for Free core and allows Pro to register
 * additional capabilities on top of the same access model.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tenant-aware access control helpers.
 */
final class NXTCC_Access_Control {

	/**
	 * Option name storing the primary tenant tuple.
	 *
	 * @var string
	 */
	private const PRIMARY_TENANT_OPTION = 'nxtcc_primary_tenant_context';

	/**
	 * Internal top-level capability.
	 *
	 * @var string
	 */
	private const ACCESS_PLUGIN_CAP = 'nxtcc_access_plugin';

	/**
	 * Internal capability for the shared settings screen.
	 *
	 * @var string
	 */
	private const ACCESS_SETTINGS_CAP = 'nxtcc_access_settings';

	/**
	 * WordPress role slug for dedicated NXT Cloud Chat staff users.
	 *
	 * @var string
	 */
	private const TEAM_ROLE = 'nxtcc_team';

	/**
	 * Default capability catalog.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function default_capabilities(): array {
		return array(
			'nxtcc_access_dashboard'      => array(
				'label'       => __( 'Dashboard', 'nxt-cloud-chat' ),
				'description' => __( 'View tenant dashboard and connection overview.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'core',
			),
			'nxtcc_access_chat'           => array(
				'label'       => __( 'Chat Window', 'nxt-cloud-chat' ),
				'description' => __( 'Access the tenant chat inbox and send replies.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'messaging',
			),
			'nxtcc_view_contacts'         => array(
				'label'       => __( 'View Contacts', 'nxt-cloud-chat' ),
				'description' => __( 'Open the contacts screen and view tenant contacts.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'contacts',
			),
			'nxtcc_manage_contacts'       => array(
				'label'       => __( 'Manage Contacts', 'nxt-cloud-chat' ),
				'description' => __( 'Create, edit, import, export, and delete tenant contacts.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'contacts',
			),
			'nxtcc_view_groups'           => array(
				'label'       => __( 'View Groups', 'nxt-cloud-chat' ),
				'description' => __( 'Open the groups screen and view tenant groups.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'groups',
			),
			'nxtcc_manage_groups'         => array(
				'label'       => __( 'Manage Groups', 'nxt-cloud-chat' ),
				'description' => __( 'Create, edit, and delete tenant groups.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'groups',
			),
			'nxtcc_view_history'          => array(
				'label'       => __( 'View History', 'nxt-cloud-chat' ),
				'description' => __( 'View tenant message history.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'messaging',
			),
			'nxtcc_manage_authentication' => array(
				'label'       => __( 'Authentication', 'nxt-cloud-chat' ),
				'description' => __( 'Manage OTP/login settings for the tenant.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'authentication',
			),
			'nxtcc_manage_settings'       => array(
				'label'       => __( 'Connection Settings', 'nxt-cloud-chat' ),
				'description' => __( 'Manage tenant connection credentials and diagnostics.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'owner',
				'owner_only'  => true,
			),
			'nxtcc_manage_team_access'    => array(
				'label'       => __( 'Team Access', 'nxt-cloud-chat' ),
				'description' => __( 'Manage tenant staff access and capabilities.', 'nxt-cloud-chat' ),
				'group'       => 'free',
				'section'     => 'owner',
				'owner_only'  => true,
			),
		);
	}

	/**
	 * Default role presets for tenant staff.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function default_role_presets(): array {
		return array(
			'viewer'        => array(
				'label'        => __( 'Viewer', 'nxt-cloud-chat' ),
				'description'  => __( 'Can review tenant activity, contacts, groups, and history without editing records.', 'nxt-cloud-chat' ),
				'capabilities' => array(
					'nxtcc_access_dashboard',
					'nxtcc_view_contacts',
					'nxtcc_view_groups',
					'nxtcc_view_history',
				),
			),
			'support_agent' => array(
				'label'        => __( 'Support Agent', 'nxt-cloud-chat' ),
				'description'  => __( 'Can work in the inbox and keep contact records up to date while handling tenant conversations.', 'nxt-cloud-chat' ),
				'capabilities' => array(
					'nxtcc_access_dashboard',
					'nxtcc_access_chat',
					'nxtcc_view_contacts',
					'nxtcc_manage_contacts',
					'nxtcc_view_groups',
					'nxtcc_view_history',
				),
			),
			'operator'      => array(
				'label'        => __( 'Operator', 'nxt-cloud-chat' ),
				'description'  => __( 'Can manage day-to-day tenant operations across contacts, groups, inbox activity, and authentication.', 'nxt-cloud-chat' ),
				'capabilities' => array(
					'nxtcc_access_dashboard',
					'nxtcc_access_chat',
					'nxtcc_view_contacts',
					'nxtcc_manage_contacts',
					'nxtcc_view_groups',
					'nxtcc_manage_groups',
					'nxtcc_view_history',
					'nxtcc_manage_authentication',
				),
			),
		);
	}

	/**
	 * Return a label for a capability section.
	 *
	 * @param string $section Section key.
	 * @return string
	 */
	private static function capability_section_label( string $section ): string {
		$labels = array(
			'core'           => __( 'Core', 'nxt-cloud-chat' ),
			'messaging'      => __( 'Messaging', 'nxt-cloud-chat' ),
			'contacts'       => __( 'Contacts', 'nxt-cloud-chat' ),
			'groups'         => __( 'Groups', 'nxt-cloud-chat' ),
			'authentication' => __( 'Authentication', 'nxt-cloud-chat' ),
			'marketing'      => __( 'Pro Marketing', 'nxt-cloud-chat' ),
			'automation'     => __( 'Pro Automation', 'nxt-cloud-chat' ),
			'owner'          => __( 'Owner Only', 'nxt-cloud-chat' ),
		);

		return isset( $labels[ $section ] ) ? $labels[ $section ] : __( 'General', 'nxt-cloud-chat' );
	}

	/**
	 * Infer a capability section when one is not explicitly registered.
	 *
	 * @param string              $capability Capability key.
	 * @param array<string,mixed> $meta Capability metadata.
	 * @return string
	 */
	private static function infer_capability_section( string $capability, array $meta ): string {
		if ( ! empty( $meta['owner_only'] ) ) {
			return 'owner';
		}

		if ( ! empty( $meta['section'] ) ) {
			return sanitize_key( (string) $meta['section'] );
		}

		if ( false !== strpos( $capability, 'workflow' ) ) {
			return 'automation';
		}

		if ( false !== strpos( $capability, 'template' ) || false !== strpos( $capability, 'broadcast' ) ) {
			return 'marketing';
		}

		if ( false !== strpos( $capability, 'contact' ) ) {
			return 'contacts';
		}

		if ( false !== strpos( $capability, 'group' ) ) {
			return 'groups';
		}

		if ( false !== strpos( $capability, 'auth' ) ) {
			return 'authentication';
		}

		if ( false !== strpos( $capability, 'chat' ) || false !== strpos( $capability, 'history' ) ) {
			return 'messaging';
		}

		return 'core';
	}

	/**
	 * Register filters and bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_team_role' ), 4 );
		add_action( 'init', array( __CLASS__, 'bootstrap_primary_tenant_access' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 20, 4 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'filter_woocommerce_prevent_admin_access' ), 20, 1 );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'filter_woocommerce_disable_admin_bar' ), 20, 1 );
	}

	/**
	 * Register the dedicated plugin team role.
	 *
	 * @return void
	 */
	public static function register_team_role(): void {
		$role_key   = self::TEAM_ROLE;
		$role_label = __( 'NXT Cloud Chat Team', 'nxt-cloud-chat' );
		$role_caps  = array(
			'read' => true,
		);

		$role = get_role( $role_key );

		if ( ! $role instanceof WP_Role ) {
			if ( function_exists( 'wpcom_vip_add_role' ) ) {
				wpcom_vip_add_role( $role_key, $role_label, $role_caps );
			} else {
				$add_role_callback = 'add_role';
				$add_role_callback( $role_key, $role_label, $role_caps );
			}

			$role = get_role( $role_key );
		}

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( $role_caps as $capability => $grant ) {
			if ( $grant ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Whether a named WordPress role exists.
	 *
	 * @param string $role_key Role key.
	 * @return bool
	 */
	private static function wp_role_exists( string $role_key ): bool {
		$role_key = sanitize_key( $role_key );

		return '' !== $role_key && get_role( $role_key ) instanceof WP_Role;
	}

	/**
	 * Return eligible WordPress role keys for NXT Cloud Chat staff.
	 *
	 * @return array<int,string>
	 */
	public static function get_eligible_staff_role_keys(): array {
		$role_keys = array(
			'administrator',
			'editor',
			self::TEAM_ROLE,
		);

		if ( self::wp_role_exists( 'shop_manager' ) ) {
			$role_keys[] = 'shop_manager';
		}

		$role_keys = apply_filters( 'nxtcc_eligible_staff_roles', $role_keys );
		$role_keys = is_array( $role_keys ) ? $role_keys : array();
		$clean     = array();

		foreach ( $role_keys as $role_key ) {
			$role_key = sanitize_key( (string) $role_key );

			if ( '' !== $role_key && self::wp_role_exists( $role_key ) ) {
				$clean[] = $role_key;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		return $clean;
	}

	/**
	 * Return eligible WordPress roles with human labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_eligible_staff_roles(): array {
		global $wp_roles;

		$roles = array();

		foreach ( self::get_eligible_staff_role_keys() as $role_key ) {
			$label = ucwords( str_replace( '_', ' ', $role_key ) );

			if ( $wp_roles instanceof WP_Roles && isset( $wp_roles->role_names[ $role_key ] ) ) {
				$label = translate_user_role( (string) $wp_roles->role_names[ $role_key ] );
			}

			$roles[ $role_key ] = sanitize_text_field( $label );
		}

		return $roles;
	}

	/**
	 * Return eligible WordPress role labels only.
	 *
	 * @return array<int,string>
	 */
	public static function get_eligible_staff_role_labels(): array {
		return array_values( self::get_eligible_staff_roles() );
	}

	/**
	 * Whether one WordPress user may hold tenant access.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	public static function is_user_staff_eligible( WP_User $user ): bool {
		$roles         = is_array( $user->roles ) ? $user->roles : array();
		$eligible_keys = array_fill_keys( self::get_eligible_staff_role_keys(), true );

		foreach ( $roles as $role_key ) {
			$role_key = sanitize_key( (string) $role_key );

			if ( '' !== $role_key && isset( $eligible_keys[ $role_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a tenant tuple.
	 *
	 * @param array $tenant Tenant data.
	 * @return array<string,string>
	 */
	public static function normalize_tenant_context( array $tenant ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Build a tenant tuple from a settings row object/array.
	 *
	 * @param mixed $row Settings row.
	 * @return array<string,string>
	 */
	private static function tenant_from_settings_row( $row ): array {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}

		if ( ! is_array( $row ) ) {
			return self::normalize_tenant_context( array() );
		}

		return self::normalize_tenant_context(
			array(
				'user_mailid'         => $row['user_mailid'] ?? '',
				'business_account_id' => $row['business_account_id'] ?? '',
				'phone_number_id'     => $row['phone_number_id'] ?? '',
			)
		);
	}

	/**
	 * Return the registered tenant capabilities.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_registered_capabilities(): array {
		$capabilities = apply_filters( 'nxtcc_registered_capabilities', self::default_capabilities() );

		if ( ! is_array( $capabilities ) ) {
			return self::default_capabilities();
		}

		$normalized = array();

		foreach ( $capabilities as $capability => $meta ) {
			$capability = sanitize_key( (string) $capability );
			if ( '' === $capability || ! is_array( $meta ) ) {
				continue;
			}

			$normalized[ $capability ] = array(
				'label'       => isset( $meta['label'] ) ? sanitize_text_field( (string) $meta['label'] ) : $capability,
				'description' => isset( $meta['description'] ) ? sanitize_text_field( (string) $meta['description'] ) : '',
				'group'       => isset( $meta['group'] ) ? sanitize_key( (string) $meta['group'] ) : 'free',
				'section'     => self::infer_capability_section( $capability, $meta ),
				'owner_only'  => ! empty( $meta['owner_only'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Return capability keys only.
	 *
	 * @return array<int,string>
	 */
	public static function get_registered_capability_keys(): array {
		return array_keys( self::get_registered_capabilities() );
	}

	/**
	 * Return assignable capability definitions for staff users.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_assignable_capabilities(): array {
		$capabilities = self::get_registered_capabilities();

		return array_filter(
			$capabilities,
			static function ( array $meta ): bool {
				return empty( $meta['owner_only'] );
			}
		);
	}

	/**
	 * Return owner-only capability definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_owner_only_capabilities(): array {
		$capabilities = self::get_registered_capabilities();

		return array_filter(
			$capabilities,
			static function ( array $meta ): bool {
				return ! empty( $meta['owner_only'] );
			}
		);
	}

	/**
	 * Return registered role presets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_role_presets(): array {
		$catalog = apply_filters( 'nxtcc_registered_role_presets', self::default_role_presets() );

		if ( ! is_array( $catalog ) ) {
			$catalog = self::default_role_presets();
		}

		$normalized = array();

		foreach ( $catalog as $role_key => $role_meta ) {
			$role_key = sanitize_key( (string) $role_key );
			if ( '' === $role_key || ! is_array( $role_meta ) ) {
				continue;
			}

			$capabilities = isset( $role_meta['capabilities'] ) && is_array( $role_meta['capabilities'] )
				? self::sanitize_selected_capabilities( $role_meta['capabilities'] )
				: array();

			if ( empty( $capabilities ) ) {
				continue;
			}

			$normalized[ $role_key ] = array(
				'label'        => isset( $role_meta['label'] ) ? sanitize_text_field( (string) $role_meta['label'] ) : $role_key,
				'description'  => isset( $role_meta['description'] ) ? sanitize_text_field( (string) $role_meta['description'] ) : '',
				'capabilities' => $capabilities,
			);
		}

		return $normalized;
	}

	/**
	 * Return one registered role preset.
	 *
	 * @param string $role_key Role preset key.
	 * @return array<string,mixed>|null
	 */
	public static function get_role_preset( string $role_key ): ?array {
		$role_key = sanitize_key( $role_key );
		$catalog  = self::get_role_presets();

		return isset( $catalog[ $role_key ] ) ? $catalog[ $role_key ] : null;
	}

	/**
	 * Sanitize a role preset key.
	 *
	 * @param string $role_key Role key.
	 * @return string
	 */
	public static function sanitize_role_key( string $role_key ): string {
		$role_key = sanitize_key( $role_key );

		if ( 'custom' === $role_key || 'owner' === $role_key ) {
			return $role_key;
		}

		return isset( self::get_role_presets()[ $role_key ] ) ? $role_key : 'custom';
	}

	/**
	 * Resolve capabilities for a selected role preset or custom selection.
	 *
	 * @param string            $role_key Role key.
	 * @param array<int,string> $submitted_capabilities Submitted capabilities.
	 * @return array<int,string>
	 */
	public static function resolve_role_capabilities( string $role_key, array $submitted_capabilities = array() ): array {
		$role_key = self::sanitize_role_key( $role_key );

		if ( 'custom' === $role_key || 'owner' === $role_key ) {
			return self::sanitize_selected_capabilities( $submitted_capabilities );
		}

		$preset = self::get_role_preset( $role_key );

		if ( ! is_array( $preset ) ) {
			return self::sanitize_selected_capabilities( $submitted_capabilities );
		}

		return isset( $preset['capabilities'] ) && is_array( $preset['capabilities'] )
			? self::sanitize_selected_capabilities( $preset['capabilities'] )
			: array();
	}

	/**
	 * Return assignable capabilities grouped by UI section.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_capability_sections(): array {
		$sections      = array();
		$capabilities  = self::get_assignable_capabilities();
		$section_order = array(
			'core'           => 10,
			'messaging'      => 20,
			'contacts'       => 30,
			'groups'         => 40,
			'authentication' => 50,
			'marketing'      => 60,
			'automation'     => 70,
			'owner'          => 80,
		);

		foreach ( $capabilities as $capability => $meta ) {
			$section = isset( $meta['section'] ) ? sanitize_key( (string) $meta['section'] ) : 'core';

			if ( ! isset( $sections[ $section ] ) ) {
				$sections[ $section ] = array(
					'key'          => $section,
					'label'        => self::capability_section_label( $section ),
					'order'        => isset( $section_order[ $section ] ) ? (int) $section_order[ $section ] : 999,
					'capabilities' => array(),
				);
			}

			$sections[ $section ]['capabilities'][ $capability ] = $meta;
		}

		uasort(
			$sections,
			static function ( array $left, array $right ): int {
				return (int) $left['order'] <=> (int) $right['order'];
			}
		);

		return $sections;
	}

	/**
	 * Sanitize a selected capability list.
	 *
	 * @param array $capabilities     Submitted capabilities.
	 * @param bool  $allow_owner_only Whether owner-only capabilities may be returned.
	 * @return array<int,string>
	 */
	public static function sanitize_selected_capabilities( array $capabilities, bool $allow_owner_only = false ): array {
		$catalog = $allow_owner_only ? self::get_registered_capabilities() : self::get_assignable_capabilities();
		$allowed = array_fill_keys( array_keys( $catalog ), true );
		$clean   = array();

		foreach ( $capabilities as $capability ) {
			$capability = sanitize_key( (string) $capability );

			if ( '' !== $capability && isset( $allowed[ $capability ] ) ) {
				$clean[] = $capability;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean, SORT_STRING );

		return $clean;
	}

	/**
	 * Whether a capability key belongs to the plugin.
	 *
	 * @param string $capability Capability key.
	 * @return bool
	 */
	private static function is_plugin_capability( string $capability ): bool {
		if ( in_array( $capability, array( self::ACCESS_PLUGIN_CAP, self::ACCESS_SETTINGS_CAP ), true ) ) {
			return true;
		}

		return isset( self::get_registered_capabilities()[ $capability ] );
	}

	/**
	 * Get the primary tenant tuple for the site.
	 *
	 * @return array<string,string>
	 */
	public static function get_primary_tenant_context(): array {
		$tenant = get_option( self::PRIMARY_TENANT_OPTION, array() );

		if ( ! is_array( $tenant ) ) {
			$tenant = array();
		}

		return self::normalize_tenant_context( $tenant );
	}

	/**
	 * Persist the primary tenant tuple.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private static function set_primary_tenant_context( array $tenant ): bool {
		$tenant = self::normalize_tenant_context( $tenant );

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		return update_option( self::PRIMARY_TENANT_OPTION, $tenant, false );
	}

	/**
	 * Whether tenant access control is fully ready.
	 *
	 * @return bool
	 */
	public static function is_access_control_ready(): bool {
		$tenant = self::get_primary_tenant_context();

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		return NXTCC_Tenant_Access_DAO::tenant_has_owner( $tenant );
	}

	/**
	 * Bootstrap the primary tenant and owner access for existing installs.
	 *
	 * @return void
	 */
	public static function bootstrap_primary_tenant_access(): void {
		$tenant = self::get_primary_tenant_context();

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			$settings = NXTCC_Settings_DAO::get_latest_any();
			$tenant   = self::tenant_from_settings_row( $settings );

			if ( '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'] ) {
				self::set_primary_tenant_context( $tenant );
			}
		}

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return;
		}

		$owner = get_user_by( 'email', $tenant['user_mailid'] );
		if ( $owner instanceof WP_User ) {
			NXTCC_Tenant_Access_DAO::ensure_owner_access(
				(int) $owner->ID,
				$tenant,
				self::get_registered_capability_keys()
			);
		}
	}

	/**
	 * Sync the site's primary tenant tuple after a settings save.
	 *
	 * @param array $tenant        Tenant tuple.
	 * @param int   $owner_user_id Owner WP user ID.
	 * @return bool
	 */
	public static function sync_primary_tenant_from_settings( array $tenant, int $owner_user_id ): bool {
		$tenant = self::normalize_tenant_context( $tenant );

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$previous = self::get_primary_tenant_context();

		if (
			'' !== $previous['user_mailid'] &&
			'' !== $previous['business_account_id'] &&
			'' !== $previous['phone_number_id'] &&
			$previous !== $tenant
		) {
			NXTCC_Tenant_Access_DAO::replace_tenant_context( $previous, $tenant );
		}

		self::set_primary_tenant_context( $tenant );

		if ( $owner_user_id > 0 ) {
			NXTCC_Tenant_Access_DAO::ensure_owner_access(
				$owner_user_id,
				$tenant,
				self::get_registered_capability_keys()
			);
		}

		return true;
	}

	/**
	 * Resolve the tenant tuple available to one user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,string>
	 */
	public static function get_tenant_context_for_user( int $user_id ): array {
		$tenant = self::get_primary_tenant_context();

		if ( $user_id <= 0 || '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return self::normalize_tenant_context( array() );
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return self::normalize_tenant_context( array() );
		}

		if ( ! self::is_access_control_ready() ) {
			if ( user_can( $user, 'manage_options' ) ) {
				return $tenant;
			}

			return self::normalize_tenant_context( array() );
		}

		if ( ! self::is_user_staff_eligible( $user ) ) {
			return self::normalize_tenant_context( array() );
		}

		if ( NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant ) ) {
			return $tenant;
		}

		return self::normalize_tenant_context( array() );
	}

	/**
	 * Resolve the current user's tenant context.
	 *
	 * @return array<string,string>
	 */
	public static function get_current_tenant_context(): array {
		return self::get_tenant_context_for_user( get_current_user_id() );
	}

	/**
	 * Whether the current logged-in user is the owner of the active tenant.
	 *
	 * @return bool
	 */
	public static function current_user_is_tenant_owner(): bool {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! self::is_access_control_ready() ) {
			return current_user_can( 'manage_options' );
		}

		$tenant = self::get_current_tenant_context();

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$row = NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant );

		return is_array( $row ) && ! empty( $row['is_owner'] );
	}

	/**
	 * Get the settings row for a tenant tuple.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return object|null
	 */
	public static function get_settings_row_for_tenant( array $tenant ) {
		$tenant = self::normalize_tenant_context( $tenant );

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return null;
		}

		return NXTCC_Settings_DAO::get_row_for_tenant(
			$tenant['user_mailid'],
			$tenant['business_account_id'],
			$tenant['phone_number_id']
		);
	}

	/**
	 * Get all access rows for the current primary tenant.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_primary_tenant_access_rows(): array {
		return NXTCC_Tenant_Access_DAO::get_tenant_access_rows( self::get_primary_tenant_context() );
	}

	/**
	 * Evaluate one custom plugin capability without recursion.
	 *
	 * @param WP_User $user       User object.
	 * @param string  $capability Capability key.
	 * @param array   $allcaps    Existing cap map.
	 * @return bool
	 */
	private static function evaluate_capability( WP_User $user, string $capability, array $allcaps ): bool {
		$user_id = (int) $user->ID;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! self::is_access_control_ready() ) {
			return ! empty( $allcaps['manage_options'] );
		}

		$tenant = self::get_tenant_context_for_user( $user_id );
		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] ) {
			return false;
		}

		$row = NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant );
		if ( ! is_array( $row ) ) {
			return false;
		}

		if ( ! empty( $row['is_owner'] ) ) {
			return true;
		}

		if ( self::ACCESS_PLUGIN_CAP === $capability ) {
			return true;
		}

		if ( self::ACCESS_SETTINGS_CAP === $capability ) {
			return in_array( 'nxtcc_manage_settings', $row['capabilities'], true ) || in_array( 'nxtcc_manage_team_access', $row['capabilities'], true );
		}

		return in_array( $capability, $row['capabilities'], true );
	}

	/**
	 * Inject tenant-aware plugin capabilities into current_user_can().
	 *
	 * @param array   $allcaps Existing capabilities.
	 * @param array   $caps    Primitive caps.
	 * @param array   $args    Original args.
	 * @param WP_User $user    Current user object.
	 * @return array
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, WP_User $user ): array {
		$requested = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

		if ( '' === $requested || ! self::is_plugin_capability( $requested ) ) {
			return $allcaps;
		}

		$allcaps[ $requested ] = self::evaluate_capability( $user, $requested, $allcaps );

		return $allcaps;
	}

	/**
	 * Whether the current user has any one of the given capabilities.
	 *
	 * @param array<int,string> $capabilities Capability keys.
	 * @return bool
	 */
	public static function current_user_can_any( array $capabilities ): bool {
		foreach ( $capabilities as $capability ) {
			$capability = sanitize_key( (string) $capability );

			if ( '' !== $capability && current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the shared top-level menu capability.
	 *
	 * @return string
	 */
	public static function access_plugin_capability(): string {
		return self::ACCESS_PLUGIN_CAP;
	}

	/**
	 * Return the shared settings-screen capability.
	 *
	 * @return string
	 */
	public static function access_settings_capability(): string {
		return self::ACCESS_SETTINGS_CAP;
	}

	/**
	 * Whether the current logged-in user may keep WooCommerce admin access/admin bar.
	 *
	 * @return bool
	 */
	private static function current_user_has_staff_admin_access(): bool {
		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || $user->ID <= 0 || ! self::is_user_staff_eligible( $user ) ) {
			return false;
		}

		$tenant = self::get_tenant_context_for_user( (int) $user->ID );

		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Allow mapped staff roles into wp-admin on WooCommerce sites.
	 *
	 * @param bool $prevent_access Current WooCommerce admin-access decision.
	 * @return bool
	 */
	public static function filter_woocommerce_prevent_admin_access( bool $prevent_access ): bool {
		if ( self::current_user_has_staff_admin_access() ) {
			return false;
		}

		return $prevent_access;
	}

	/**
	 * Keep the admin bar visible for mapped staff roles on WooCommerce sites.
	 *
	 * @param bool $disabled Current WooCommerce admin-bar decision.
	 * @return bool
	 */
	public static function filter_woocommerce_disable_admin_bar( bool $disabled ): bool {
		if ( self::current_user_has_staff_admin_access() ) {
			return false;
		}

		return $disabled;
	}
}

NXTCC_Access_Control::init();
