<?php
/**
 * Central CRM record access policy.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enforces action level and assigned/team/all record scopes.
 */
final class NXTCC_CRM_Access_Policy {

	/**
	 * Whether a contact belongs to the policy tenant.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private static function contact_exists( int $contact_id, array $tenant ): bool {
		if ( $contact_id <= 0 || empty( $tenant['user_mailid'] ) || empty( $tenant['business_account_id'] ) || empty( $tenant['phone_number_id'] ) ) {
			return false;
		}

		$db    = NXTCC_DB::i();
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $db->t_contacts() );
		$sql   = 'SELECT id FROM `' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`
			WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1';

		return absint(
			$db->get_var(
				$sql,
				array(
					$contact_id,
					(string) $tenant['user_mailid'],
					(string) $tenant['business_account_id'],
					(string) $tenant['phone_number_id'],
				)
			)
		) === $contact_id;
	}

	/**
	 * Resolve the policy scope for a capability.
	 *
	 * @param array<string,string> $scope_map Scope map.
	 * @param string               $capability Capability key.
	 * @param string               $fallback Fallback scope.
	 * @return string
	 */
	private static function scope_for_capability( array $scope_map, string $capability, string $fallback ): string {
		$capability = sanitize_key( $capability );
		$fallback   = NXTCC_Access_Teams::sanitize_data_scope( $fallback );

		if ( '' !== $capability && isset( $scope_map[ $capability ] ) ) {
			return NXTCC_Access_Teams::sanitize_data_scope( (string) $scope_map[ $capability ] );
		}

		return $fallback;
	}

	/**
	 * Get one user's normalized CRM policy.
	 *
	 * @param int    $user_id WordPress user ID. Current user when omitted.
	 * @param array  $tenant Tenant tuple. Current tenant when omitted.
	 * @param string $capability Optional capability whose record scope should be resolved.
	 * @return array<string,mixed>
	 */
	public static function get_policy( int $user_id = 0, array $tenant = array(), string $capability = '' ): array {
		$user_id    = $user_id > 0 ? $user_id : get_current_user_id();
		$tenant     = ! empty( $tenant ) ? NXTCC_Access_Control::normalize_tenant_context( $tenant ) : NXTCC_Access_Control::get_tenant_context_for_user( $user_id );
		$row        = $user_id > 0 ? NXTCC_Tenant_Access_DAO::get_user_access( $user_id, $tenant ) : null;
		$capability = sanitize_key( $capability );

		if ( ! is_array( $row ) ) {
			return array(
				'user_id'             => $user_id,
				'role_key'            => '',
				'action_level'        => 'view_only',
				'data_scope'          => 'assigned',
				'base_data_scope'     => 'assigned',
				'capability'          => $capability,
				'capability_scopes'   => array(),
				'assignment_eligible' => false,
				'is_owner'            => false,
				'tenant'              => $tenant,
			);
		}

		$is_owner          = ! empty( $row['is_owner'] );
		$base_data_scope   = $is_owner ? 'all' : NXTCC_Access_Teams::sanitize_data_scope( (string) ( $row['data_scope'] ?? 'all' ) );
		$capability_scopes = isset( $row['capability_scopes'] ) && is_array( $row['capability_scopes'] )
			? NXTCC_Access_Teams::sanitize_capability_scopes( $row['capability_scopes'], isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ? $row['capabilities'] : array(), $base_data_scope )
			: array();

		return array(
			'user_id'             => $user_id,
			'role_key'            => sanitize_key( (string) ( $row['role_key'] ?? '' ) ),
			'action_level'        => $is_owner ? 'manage' : NXTCC_Access_Teams::sanitize_action_level( (string) ( $row['action_level'] ?? 'manage' ) ),
			'data_scope'          => $is_owner ? 'all' : self::scope_for_capability( $capability_scopes, $capability, $base_data_scope ),
			'base_data_scope'     => $base_data_scope,
			'capability'          => $capability,
			'capability_scopes'   => $is_owner ? NXTCC_Access_Teams::sanitize_capability_scopes( array(), isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ? $row['capabilities'] : array(), 'all' ) : $capability_scopes,
			'assignment_eligible' => $is_owner || ! empty( $row['assignment_eligible'] ),
			'is_owner'            => $is_owner,
			'tenant'              => $tenant,
		);
	}

	/**
	 * Whether a policy allows record mutation.
	 *
	 * @param array $policy Policy.
	 * @return bool
	 */
	public static function can_manage( array $policy ): bool {
		return 'manage' === (string) ( $policy['action_level'] ?? 'view_only' );
	}

	/**
	 * Whether a contact assignment is visible under a policy.
	 *
	 * @param array<string,mixed>|null $assignment Assignment row.
	 * @param array                    $policy Policy.
	 * @return bool
	 */
	private static function assignment_matches( ?array $assignment, array $policy ): bool {
		$scope = NXTCC_Access_Teams::sanitize_data_scope( (string) ( $policy['data_scope'] ?? 'assigned' ) );
		if ( 'all' === $scope ) {
			return true;
		}

		$user_id  = absint( $policy['user_id'] ?? 0 );
		$role_key = sanitize_key( (string) ( $policy['role_key'] ?? '' ) );
		if ( ! is_array( $assignment ) ) {
			return 'team' === $scope;
		}

		if ( 'user' === (string) ( $assignment['target_type'] ?? '' ) && absint( $assignment['assigned_user_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		return 'team' === $scope
			&& 'role' === (string) ( $assignment['target_type'] ?? '' )
			&& '' !== $role_key
			&& sanitize_key( (string) ( $assignment['assigned_role'] ?? '' ) ) === $role_key;
	}

	/**
	 * Whether a conversation assignment is visible under a policy.
	 *
	 * @param array $conversation Conversation row.
	 * @param array $policy Policy.
	 * @return bool
	 */
	private static function conversation_matches( array $conversation, array $policy ): bool {
		return self::assigned_record_matches( $conversation, $policy );
	}

	/**
	 * Whether a generic assigned CRM record is visible under a policy.
	 *
	 * @param array $record Record with assigned_user_id and assigned_role fields.
	 * @param array $policy Policy.
	 * @return bool
	 */
	private static function assigned_record_matches( array $record, array $policy ): bool {
		$scope = NXTCC_Access_Teams::sanitize_data_scope( (string) ( $policy['data_scope'] ?? 'assigned' ) );
		if ( 'all' === $scope ) {
			return true;
		}

		$user_id       = absint( $policy['user_id'] ?? 0 );
		$role_key      = sanitize_key( (string) ( $policy['role_key'] ?? '' ) );
		$assigned_user = absint( $record['assigned_user_id'] ?? 0 );
		$assigned_role = sanitize_key( (string) ( $record['assigned_role'] ?? '' ) );
		if ( $assigned_user === $user_id && $user_id > 0 ) {
			return true;
		}

		return 'team' === $scope
			&& ( ( 0 === $assigned_user && '' === $assigned_role ) || ( '' !== $role_key && $assigned_role === $role_key ) );
	}

	/**
	 * Whether a user may view one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_view_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy = self::get_policy( $user_id, $tenant, 'nxtcc_view_contacts' );
		if ( $contact_id <= 0 || empty( $policy['tenant']['user_mailid'] ) ) {
			return false;
		}
		if ( ! self::contact_exists( $contact_id, $policy['tenant'] ) ) {
			return false;
		}

		$assignment = NXTCC_Contact_Assignments::instance()->get_assignment( $contact_id, $policy['tenant'] );
		return self::assignment_matches( $assignment, $policy );
	}

	/**
	 * Whether a user may manage one tenant contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_manage_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy = self::get_policy( $user_id, $tenant, 'nxtcc_manage_contacts' );
		if ( ! self::can_manage( $policy ) || $contact_id <= 0 || empty( $policy['tenant']['user_mailid'] ) ) {
			return false;
		}
		if ( ! self::contact_exists( $contact_id, $policy['tenant'] ) ) {
			return false;
		}

		$assignment = NXTCC_Contact_Assignments::instance()->get_assignment( $contact_id, $policy['tenant'] );
		return self::assignment_matches( $assignment, $policy );
	}

	/**
	 * Whether a user may view one conversation ticket.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_view_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy       = self::get_policy( $user_id, $tenant, 'nxtcc_access_chat' );
		$conversation = NXTCC_Conversations::instance()->get( $conversation_id, $policy['tenant'] );
		return is_array( $conversation ) && self::conversation_matches( $conversation, $policy );
	}

	/**
	 * Whether a user may manage one conversation ticket.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_manage_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy       = self::get_policy( $user_id, $tenant, 'nxtcc_access_chat' );
		$conversation = NXTCC_Conversations::instance()->get( $conversation_id, $policy['tenant'] );
		return self::can_manage( $policy ) && is_array( $conversation ) && self::conversation_matches( $conversation, $policy );
	}

	/**
	 * Whether a user may view one tenant deal.
	 *
	 * Users with assigned/team scopes must be able to view at least one linked
	 * contact. All-scope users may also view deals that are not linked yet.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_view_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy = self::get_policy( $user_id, $tenant, 'nxtcc_view_deals' );
		$deal   = NXTCC_CRM_Deals::instance()->get_deal( $deal_id, $policy['tenant'] );
		if ( ! is_array( $deal ) ) {
			return false;
		}
		if ( 'all' === (string) ( $policy['data_scope'] ?? '' ) ) {
			return true;
		}
		if ( self::assigned_record_matches( $deal, $policy ) ) {
			return true;
		}
		foreach ( (array) ( $deal['contacts'] ?? array() ) as $contact ) {
			if ( self::user_can_view_contact( absint( $contact['contact_id'] ?? 0 ), $policy['tenant'], absint( $policy['user_id'] ?? 0 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user may manage one tenant deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_manage_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool {
		$policy = self::get_policy( $user_id, $tenant, 'nxtcc_manage_deals' );
		$deal   = NXTCC_CRM_Deals::instance()->get_deal( $deal_id, $policy['tenant'] );
		if ( ! self::can_manage( $policy ) || ! is_array( $deal ) ) {
			return false;
		}
		if ( 'all' === (string) ( $policy['data_scope'] ?? '' ) || self::assigned_record_matches( $deal, $policy ) ) {
			return true;
		}

		foreach ( (array) ( $deal['contacts'] ?? array() ) as $contact ) {
			if ( self::user_can_manage_contact( absint( $contact['contact_id'] ?? 0 ), $policy['tenant'], absint( $policy['user_id'] ?? 0 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter conversations using one user's record scope.
	 *
	 * @param array $conversations Conversation rows.
	 * @param array $tenant Tenant tuple.
	 * @param bool  $manage Require mutation access.
	 * @param int   $user_id WordPress user ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_conversations( array $conversations, array $tenant = array(), bool $manage = false, int $user_id = 0 ): array {
		$policy = self::get_policy( $user_id, $tenant, 'nxtcc_access_chat' );
		if ( $manage && ! self::can_manage( $policy ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$conversations,
				static function ( $conversation ) use ( $policy ): bool {
					return is_array( $conversation ) && self::conversation_matches( $conversation, $policy );
				}
			)
		);
	}

	/**
	 * Filter contact IDs using one assignment map query.
	 *
	 * @param array $contact_ids Contact IDs.
	 * @param array $tenant Tenant tuple.
	 * @param bool  $manage Require manage access.
	 * @param int   $user_id WordPress user ID.
	 * @return array<int,int>
	 */
	public static function filter_contact_ids( array $contact_ids, array $tenant = array(), bool $manage = false, int $user_id = 0 ): array {
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		$policy = self::get_policy( $user_id, $tenant, $manage ? 'nxtcc_manage_contacts' : 'nxtcc_view_contacts' );

		if ( empty( $ids ) || ( $manage && ! self::can_manage( $policy ) ) ) {
			return array();
		}

		if ( 'all' === (string) $policy['data_scope'] ) {
			return $ids;
		}

		$assignments = NXTCC_Contact_Assignments::instance()->get_assignments_for_contacts( $ids, $policy['tenant'] );
		$allowed     = array();

		foreach ( $ids as $contact_id ) {
			$assignment = isset( $assignments[ $contact_id ] ) && is_array( $assignments[ $contact_id ] ) ? $assignments[ $contact_id ] : null;
			if ( self::assignment_matches( $assignment, $policy ) ) {
				$allowed[] = $contact_id;
			}
		}

		return $allowed;
	}

	/**
	 * Return safe query-scope metadata for repositories.
	 *
	 * @param array  $tenant Tenant tuple.
	 * @param int    $user_id WordPress user ID.
	 * @param string $capability Capability whose scope should be returned.
	 * @return array<string,mixed>
	 */
	public static function get_query_scope( array $tenant = array(), int $user_id = 0, string $capability = 'nxtcc_view_contacts' ): array {
		$policy = self::get_policy( $user_id, $tenant, $capability );

		return array(
			'user_id'    => absint( $policy['user_id'] ?? 0 ),
			'role_key'   => sanitize_key( (string) ( $policy['role_key'] ?? '' ) ),
			'data_scope' => NXTCC_Access_Teams::sanitize_data_scope( (string) ( $policy['data_scope'] ?? 'assigned' ) ),
			'capability' => sanitize_key( $capability ),
		);
	}
}
