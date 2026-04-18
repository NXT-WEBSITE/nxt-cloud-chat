<?php
/**
 * Runtime compatibility contract and additive bridge helpers.
 *
 * These helpers expose a stable Free-owned surface for internal integrations
 * such as the Pro workflow engine without changing current plugin behavior.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-db.php';
require_once __DIR__ . '/class-nxtcc-runtime-integration.php';

if ( ! function_exists( 'nxtcc_runtime_quote_table_name' ) ) {
	/**
	 * Quote a table identifier for controlled SQL fragments.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	function nxtcc_runtime_quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}
}

if ( ! function_exists( 'nxtcc_runtime_cache_key' ) ) {
	/**
	 * Build a deterministic cache key for runtime bridge lookups.
	 *
	 * @param string $prefix Key prefix.
	 * @param array  $parts  Key parts.
	 * @return string
	 */
	function nxtcc_runtime_cache_key( string $prefix, array $parts ): string {
		return sanitize_key( $prefix ) . ':' . md5( wp_json_encode( array_values( $parts ) ) );
	}
}

if ( ! function_exists( 'nxtcc_get_runtime_contract' ) ) {
	/**
	 * Return the stable Free runtime contract for internal integrations.
	 *
	 * @return array<string, mixed>
	 */
	function nxtcc_get_runtime_contract(): array {
		static $contract = null;

		if ( null !== $contract ) {
			return $contract;
		}

		$contract = array(
			'contract_version' => defined( 'NXTCC_VERSION' ) ? (string) NXTCC_VERSION : '0.0.0',
			'plugin'           => array(
				'slug'         => 'nxt-cloud-chat',
				'version'      => defined( 'NXTCC_VERSION' ) ? (string) NXTCC_VERSION : '0.0.0',
				'distribution' => defined( 'NXTCC_DISTRIBUTION' ) ? (string) NXTCC_DISTRIBUTION : 'FREE',
			),
			'capabilities'     => array(
				'compat_contract'                     => true,
				'inbound_message_persisted_hook'      => true,
				'message_history_status_updated_hook' => true,
				'auth_lifecycle_hooks'                => true,
				'tenant_credentials_wrapper'          => function_exists( 'nxtcc_get_tenant_api_credentials' ),
				'session_reply_sender'                => function_exists( 'nxtcc_send_session_reply' ),
				'background_session_reply_sender'     => function_exists( 'nxtcc_send_background_session_reply' ),
				'message_history_reader'              => function_exists( 'nxtcc_get_message_history_after_id' ),
				'message_history_wamid_reader'        => function_exists( 'nxtcc_get_message_history_id_by_wamid' ),
				'contact_reader'                      => function_exists( 'nxtcc_get_contact_by_id' ),
				'contact_phone_reader'                => function_exists( 'nxtcc_get_contact_by_phone' ),
				'contact_wp_user_reader'              => function_exists( 'nxtcc_get_contact_by_wp_user' ),
				'contact_group_reader'                => function_exists( 'nxtcc_get_contact_groups_by_id' ),
				'latest_inbound_reader'               => function_exists( 'nxtcc_get_latest_inbound_at' ),
				'verified_phone_reader'               => function_exists( 'nxtcc_get_latest_verified_phone_for_user' ),
			),
			'hooks'            => array(
				'nxtcc_inbound_message_persisted',
				'nxtcc_message_history_status_updated',
				'nxtcc_auth_otp_requested',
				'nxtcc_auth_otp_sent',
				'nxtcc_auth_otp_failed',
				'nxtcc_auth_login_succeeded',
				'nxtcc_auth_login_failed',
				'nxtcc_otp_verified',
				'nxtcc_wp_login',
			),
			'wrappers'         => array(
				'nxtcc_get_tenant_api_credentials',
				'nxtcc_send_session_reply',
				'nxtcc_send_background_session_reply',
				'nxtcc_get_message_history_after_id',
				'nxtcc_get_message_history_id_by_wamid',
				'nxtcc_get_contact_by_id',
				'nxtcc_get_contact_by_phone',
				'nxtcc_get_contact_by_wp_user',
				'nxtcc_get_contact_groups_by_id',
				'nxtcc_get_latest_inbound_at',
				'nxtcc_get_latest_verified_phone_for_user',
			),
		);

		$filtered = apply_filters( 'nxtcc_runtime_contract', $contract );
		$contract = is_array( $filtered ) ? $filtered : $contract;

		return $contract;
	}
}

if ( ! function_exists( 'nxtcc_get_runtime_capabilities' ) ) {
	/**
	 * Return the capability map exposed by the runtime contract.
	 *
	 * @return array<string, bool>
	 */
	function nxtcc_get_runtime_capabilities(): array {
		$contract = nxtcc_get_runtime_contract();
		$caps     = isset( $contract['capabilities'] ) && is_array( $contract['capabilities'] )
			? $contract['capabilities']
			: array();

		return $caps;
	}
}

if ( ! function_exists( 'nxtcc_has_runtime_capability' ) ) {
	/**
	 * Check whether the Free runtime exposes a named capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function nxtcc_has_runtime_capability( string $capability ): bool {
		$capability = sanitize_key( $capability );
		if ( '' === $capability ) {
			return false;
		}

		$capabilities = nxtcc_get_runtime_capabilities();
		return ! empty( $capabilities[ $capability ] );
	}
}

if ( ! function_exists( 'nxtcc_get_message_history_after_id' ) ) {
	/**
	 * Read received message-history rows after a cursor id.
	 *
	 * This is intended as a stable event-reader surface for internal runtimes.
	 *
	 * Supported filters:
	 * - user_mailid
	 * - business_account_id
	 * - phone_number_id
	 * - status
	 *
	 * @param int   $after_id Cursor id.
	 * @param int   $limit    Batch size.
	 * @param array $filters  Optional tenant/status filters.
	 * @return array<int, array<string, mixed>>
	 */
	function nxtcc_get_message_history_after_id( int $after_id = 0, int $limit = 100, array $filters = array() ): array {
		$after_id            = max( 0, $after_id );
		$limit               = max( 1, min( 500, $limit ) );
		$user_mailid         = isset( $filters['user_mailid'] ) ? sanitize_email( (string) $filters['user_mailid'] ) : '';
		$business_account_id = isset( $filters['business_account_id'] ) ? sanitize_text_field( (string) $filters['business_account_id'] ) : '';
		$phone_number_id     = isset( $filters['phone_number_id'] ) ? sanitize_text_field( (string) $filters['phone_number_id'] ) : '';
		$status              = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : 'received';

		$db          = NXTCC_DB::i();
		$history_sql = nxtcc_runtime_quote_table_name( $db->t_message_history() );
		$sql         = 'SELECT id, queue_id, user_mailid, business_account_id, phone_number_id, contact_id, display_phone_number, template_type, message_content, status, status_timestamps, meta_message_id, created_at, response_json
			FROM ' . $history_sql . '
			WHERE id > %d
			  AND status = %s
			  AND queue_id IS NULL
			  AND deleted_at IS NULL';
		$args        = array( $after_id, $status );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql   .= ' ORDER BY id ASC LIMIT %d';
		$args[] = $limit;

		$rows = $db->get_results( $sql, $args, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_id' ) ) {
	/**
	 * Read a contact row by id, optionally scoped to a tenant.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_id(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return null;
		}

		$cache_key = nxtcc_runtime_cache_key(
			'contact',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = nxtcc_runtime_quote_table_name( $db->t_contacts() );
		$sql          = 'SELECT * FROM ' . $contacts_sql . ' WHERE id = %d';
		$args         = array( $contact_id );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' LIMIT 1';

		$row = $db->get_row( $sql, $args, ARRAY_A );
		$row = is_array( $row ) ? $row : null;

		wp_cache_set( $cache_key, $row, 'nxtcc_runtime', 60 );

		return $row;
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_phone' ) ) {
	/**
	 * Read a contact row by phone number, optionally scoped to a tenant.
	 *
	 * @param string $phone_number         Phone number in any common format.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_phone(
		string $phone_number,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		return NXTCC_Runtime_Integration::get_contact_by_phone(
			$phone_number,
			$user_mailid,
			$business_account_id,
			$phone_number_id
		);
	}
}

if ( ! function_exists( 'nxtcc_get_contact_by_wp_user' ) ) {
	/**
	 * Read a contact row by linked WordPress user id.
	 *
	 * Falls back to the user's latest verified WhatsApp binding when the
	 * contact row is not yet linked by `wp_uid`.
	 *
	 * @param int    $user_id              WordPress user id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	function nxtcc_get_contact_by_wp_user(
		int $user_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		return NXTCC_Runtime_Integration::get_contact_by_wp_user(
			$user_id,
			$user_mailid,
			$business_account_id,
			$phone_number_id
		);
	}
}

if ( ! function_exists( 'nxtcc_get_contact_groups_by_id' ) ) {
	/**
	 * Read the groups currently mapped to a contact.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<int, array<string, mixed>>
	 */
	function nxtcc_get_contact_groups_by_id(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): array {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return array();
		}

		$cache_key = nxtcc_runtime_cache_key(
			'contact_groups',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$db        = NXTCC_DB::i();
		$groups    = nxtcc_runtime_quote_table_name( $db->t_groups() );
		$group_map = nxtcc_runtime_quote_table_name( $db->t_group_contact_map() );
		$sql       = 'SELECT g.id, g.group_name, g.is_verified
			FROM ' . $group_map . ' AS m
			INNER JOIN ' . $groups . ' AS g ON g.id = m.group_id
			WHERE m.contact_id = %d';
		$args      = array( $contact_id );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND m.user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND m.business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND m.phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' ORDER BY g.group_name ASC';

		$rows = $db->get_results( $sql, $args, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		wp_cache_set( $cache_key, $rows, 'nxtcc_runtime', 60 );

		return $rows;
	}
}

if ( ! function_exists( 'nxtcc_get_latest_inbound_at' ) ) {
	/**
	 * Read the latest inbound-message timestamp for a contact.
	 *
	 * @param int    $contact_id           Contact id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return string|null
	 */
	function nxtcc_get_latest_inbound_at(
		int $contact_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?string {
		$contact_id          = absint( $contact_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $contact_id <= 0 ) {
			return null;
		}

		$cache_key = nxtcc_runtime_cache_key(
			'latest_inbound',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, 'nxtcc_runtime' );
		if ( false !== $cached ) {
			return is_string( $cached ) && '' !== $cached ? $cached : null;
		}

		$db          = NXTCC_DB::i();
		$history_sql = nxtcc_runtime_quote_table_name( $db->t_message_history() );
		$sql         = 'SELECT created_at
			FROM ' . $history_sql . '
			WHERE contact_id = %d
			  AND status = %s
			  AND deleted_at IS NULL';
		$args        = array( $contact_id, 'received' );

		if ( '' !== $user_mailid ) {
			$sql   .= ' AND user_mailid = %s';
			$args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql   .= ' AND business_account_id = %s';
			$args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql   .= ' AND phone_number_id = %s';
			$args[] = $phone_number_id;
		}

		$sql .= ' ORDER BY id DESC LIMIT 1';

		$value = $db->get_var( $sql, $args );
		$value = is_string( $value ) && '' !== $value ? $value : null;

		wp_cache_set( $cache_key, $value, 'nxtcc_runtime', 60 );

		return $value;
	}
}

if ( ! function_exists( 'nxtcc_get_latest_verified_phone_for_user' ) ) {
	/**
	 * Read the latest verified WhatsApp number for a WordPress user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	function nxtcc_get_latest_verified_phone_for_user( int $user_id ): string {
		return NXTCC_Runtime_Integration::get_latest_verified_phone_for_user( $user_id );
	}
}

if ( ! function_exists( 'nxtcc_get_message_history_id_by_wamid' ) ) {
	/**
	 * Resolve a local history row id from a Meta message id.
	 *
	 * @param string $wamid Meta message id.
	 * @return int
	 */
	function nxtcc_get_message_history_id_by_wamid( string $wamid ): int {
		return NXTCC_Runtime_Integration::get_message_history_id_by_wamid( $wamid );
	}
}

if ( ! function_exists( 'nxtcc_send_session_reply' ) ) {
	/**
	 * Stable wrapper for session-reply sends.
	 *
	 * This intentionally preserves the current Free runtime behavior by
	 * delegating to the existing immediate send helper.
	 *
	 * @param array $args Send arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_send_session_reply( array $args ): array {
		if ( ! function_exists( 'nxtcc_send_message_immediately' ) ) {
			return array(
				'success' => false,
				'error'   => 'send_runtime_unavailable',
			);
		}

		return nxtcc_send_message_immediately( $args );
	}
}

if ( ! function_exists( 'nxtcc_send_background_session_reply' ) ) {
	/**
	 * Stable wrapper for background-safe session replies.
	 *
	 * This bypasses current-user checks while still reusing the shared Free send
	 * path and history persistence behavior.
	 *
	 * @param array $args Send arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_send_background_session_reply( array $args ): array {
		return NXTCC_Runtime_Integration::send_background_session_reply( $args );
	}
}
