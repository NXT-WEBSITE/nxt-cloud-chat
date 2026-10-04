<?php
/**
 * First-view receipts for incoming messages, independent of Meta read receipts.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tenant-scoped, session-actor-only message view storage and readers.
 */
final class NXTCC_Message_Reads {

	/**
	 * Validate the current agent's read access without requiring write permission.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return array|WP_Error
	 */
	private static function scope( int $contact_id, array $tenant ) {
		$tenant  = NXTCC_Access_Control::normalize_tenant_context( $tenant );
		$current = NXTCC_Access_Control::normalize_tenant_context( NXTCC_Access_Control::get_current_tenant_context() );
		if ( ! is_user_logged_in() || $contact_id <= 0 || in_array( '', $tenant, true ) || $tenant !== $current || ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) || ! NXTCC_CRM_Access_Policy::user_can_access_chat( $contact_id, $tenant, false ) ) {
			return new WP_Error( 'message_views_forbidden', __( 'You cannot access this chat.', 'nxt-cloud-chat' ) );
		}
		return $tenant;
	}

	/**
	 * Validate a bounded list of positive message IDs.
	 *
	 * @param array $ids Message IDs.
	 * @return array|WP_Error
	 */
	private static function ids( array $ids ) {
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'message_views_invalid', __( 'Too many message IDs.', 'nxt-cloud-chat' ) );
		}
		foreach ( $ids as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 ) {
				return new WP_Error( 'message_views_invalid', __( 'Invalid message ID.', 'nxt-cloud-chat' ) );
			}
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Batch counts and current-agent acknowledgements, only for eligible messages.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $message_ids Message IDs (maximum 100).
	 * @param array $tenant Tenant tuple.
	 * @return array|WP_Error Map keyed by message ID.
	 */
	public static function summaries( int $contact_id, array $message_ids, array $tenant ) {
		$tenant = self::scope( $contact_id, $tenant );
		$ids    = self::ids( $message_ids );
		if ( is_wp_error( $tenant ) || is_wp_error( $ids ) ) {
			return is_wp_error( $tenant ) ? $tenant : $ids;
		}
		if ( empty( $ids ) ) {
			return array();
		}
		$db      = NXTCC_DB::i();
		$history = $db->t_message_history();
		$reads   = $db->t_message_reads();
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = $db->get_results(
			"SELECT m.id, m.is_read, COUNT(r.id) AS view_count, MAX(CASE WHEN r.wp_user_id = %d THEN 1 ELSE 0 END) AS viewed_by_me
			FROM {$history} m LEFT JOIN {$reads} r ON r.message_id = m.id AND r.contact_id = m.contact_id
			AND r.user_mailid = m.user_mailid AND r.business_account_id = m.business_account_id AND r.phone_number_id = m.phone_number_id
			WHERE m.contact_id = %d AND m.user_mailid = %s AND m.business_account_id = %s AND m.phone_number_id = %s
			AND m.status = 'received' AND m.deleted_at IS NULL AND m.id IN ({$in}) GROUP BY m.id, m.is_read",
			array_merge( array( get_current_user_id(), $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] ), $ids )
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'message_views_save_failed', __( 'Message views are temporarily unavailable.', 'nxt-cloud-chat' ) );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['id'] ] = array(
				'view_count'   => (int) $row['view_count'],
				'viewed_by_me' => ! empty( $row['viewed_by_me'] ),
				'is_read'      => ! empty( $row['is_read'] ),
			);
		}
		return $result;
	}

	/**
	 * Record first views for the authenticated actor, never an actor from input.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $message_ids Observed message IDs.
	 * @param array $tenant Tenant tuple.
	 * @return array|WP_Error Confirmed summaries.
	 */
	public static function record( int $contact_id, array $message_ids, array $tenant ) {
		$eligible = self::summaries( $contact_id, $message_ids, $tenant );
		if ( is_wp_error( $eligible ) || empty( $eligible ) ) {
			return $eligible;
		}
		$tenant  = NXTCC_Access_Control::normalize_tenant_context( $tenant );
		$db      = NXTCC_DB::i();
		$history = $db->t_message_history();
		$reads   = $db->t_message_reads();
		$ids     = array_keys( $eligible );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$scope   = array( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		// The unique key makes concurrent tabs idempotent; a duplicate never changes its timestamp.
		$db->query(
			"INSERT INTO {$reads} (message_id, wp_user_id, contact_id, user_mailid, business_account_id, phone_number_id, first_read_at)
			SELECT m.id, %d, m.contact_id, m.user_mailid, m.business_account_id, m.phone_number_id, %s FROM {$history} m
			WHERE m.contact_id = %d AND m.user_mailid = %s AND m.business_account_id = %s AND m.phone_number_id = %s
			AND m.status = 'received' AND m.deleted_at IS NULL AND m.id IN ({$in}) ON DUPLICATE KEY UPDATE first_read_at = {$reads}.first_read_at",
			array_merge( array( get_current_user_id(), current_time( 'mysql', true ) ), $scope, $ids )
		);
		$confirmed = self::summaries( $contact_id, $ids, $tenant );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}
		$seen = array();
		foreach ( $confirmed as $id => $summary ) {
			if ( $summary['viewed_by_me'] ) {
				$seen[] = $id;
			}
		}
		if ( empty( $seen ) ) {
			return new WP_Error( 'message_views_save_failed', __( 'The message view could not be saved.', 'nxt-cloud-chat' ) );
		}
		$in      = implode( ',', array_fill( 0, count( $seen ), '%d' ) );
		$updated = $db->query_succeeded(
			"UPDATE {$history} SET is_read = 1 WHERE contact_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
			AND status = 'received' AND deleted_at IS NULL AND is_read = 0 AND id IN ({$in})",
			array_merge( $scope, $seen )
		);
		if ( ! $updated ) {
			return new WP_Error( 'message_views_save_failed', __( 'The message unread status could not be updated.', 'nxt-cloud-chat' ) );
		}
		foreach ( $seen as $id ) {
			$confirmed[ $id ]['is_read'] = true;
		}
		if ( function_exists( 'nxtcc_chat_repo' ) ) {
			nxtcc_chat_repo()->invalidate_read_cache( $contact_id, $tenant['user_mailid'], $tenant['phone_number_id'] );
		}
		return $confirmed;
	}

	/**
	 * Fetch a bounded viewer page with safe public identity fields.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param int   $message_id Incoming message ID.
	 * @param array $tenant Tenant tuple.
	 * @param int   $offset Page offset.
	 * @return array|WP_Error
	 */
	public static function viewers( int $contact_id, int $message_id, array $tenant, int $offset = 0 ) {
		$summary = self::summaries( $contact_id, array( $message_id ), $tenant );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		if ( ! isset( $summary[ $message_id ] ) ) {
			return new WP_Error( 'message_views_not_found', __( 'The incoming message is unavailable.', 'nxt-cloud-chat' ) );
		}
		$tenant = NXTCC_Access_Control::normalize_tenant_context( $tenant );
		$db     = NXTCC_DB::i();
		$reads  = $db->t_message_reads();
		$rows   = $db->get_results(
			"SELECT wp_user_id, first_read_at FROM {$reads} WHERE message_id = %d AND contact_id = %d
			AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s ORDER BY first_read_at, id LIMIT 51 OFFSET %d",
			array( $message_id, $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'], max( 0, $offset ) )
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'message_views_save_failed', __( 'Message views are temporarily unavailable.', 'nxt-cloud-chat' ) );
		}
		$has_more = count( $rows ) > 50;
		$rows     = array_slice( $rows, 0, 50 );
		$users    = NXTCC_Actor_Audit::get_user_map( array_column( $rows, 'wp_user_id' ) );
		$viewers  = array();
		foreach ( $rows as $row ) {
			$id        = (int) $row['wp_user_id'];
			$active    = isset( $users[ $id ] ) && NXTCC_Access_Control::normalize_tenant_context( NXTCC_Access_Control::get_tenant_context_for_user( $id ) ) === $tenant;
			$viewers[] = array(
				'name'          => $active ? sanitize_text_field( $users[ $id ]['display_name'] ) : __( 'Former agent', 'nxt-cloud-chat' ),
				'is_me'         => get_current_user_id() === $id,
				'avatar_url'    => $active && get_option( 'show_avatars' ) ? esc_url_raw( get_avatar_url( $id, array( 'size' => 32 ) ) ) : '',
				'first_view_at' => $row['first_read_at'],
				'viewed_at'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['first_read_at'] . ' UTC' ), wp_timezone() ),
			);
		}
		return array(
			'viewers'     => $viewers,
			'has_more'    => $has_more,
			'next_offset' => max( 0, $offset ) + count( $rows ),
			'view_count'  => $summary[ $message_id ]['view_count'],
		);
	}

	/**
	 * Remove receipts only after their parent messages have actually been deleted.
	 *
	 * @param array $message_ids Deleted parent candidates.
	 * @return void
	 */
	public static function delete_orphaned_for_messages( array $message_ids ): void {
		foreach ( array_chunk( $message_ids, 100 ) as $batch ) {
			$ids = self::ids( $batch );
			if ( is_wp_error( $ids ) || empty( $ids ) ) {
				continue;
			}
			$db      = NXTCC_DB::i();
			$history = $db->t_message_history();
			$reads   = $db->t_message_reads();
			$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$db->query( "DELETE r FROM {$reads} r LEFT JOIN {$history} m ON m.id = r.message_id WHERE r.message_id IN ({$in}) AND m.id IS NULL", $ids );
		}
	}

	/**
	 * Remove receipts after confirmed contact deletion, retaining failed deletes.
	 *
	 * @param array $contact_ids Deleted contact candidates.
	 * @return void
	 */
	public static function delete_orphaned_for_contacts( array $contact_ids ): void {
		foreach ( array_chunk( $contact_ids, 100 ) as $batch ) {
			$ids = self::ids( $batch );
			if ( is_wp_error( $ids ) || empty( $ids ) ) {
				continue;
			}
			$db       = NXTCC_DB::i();
			$contacts = $db->t_contacts();
			$reads    = $db->t_message_reads();
			$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$db->query( "DELETE r FROM {$reads} r LEFT JOIN {$contacts} c ON c.id = r.contact_id WHERE r.contact_id IN ({$in}) AND c.id IS NULL", $ids );
		}
	}
}
