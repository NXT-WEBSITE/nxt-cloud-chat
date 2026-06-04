<?php
/**
 * Runtime integration helpers for public bridge wrappers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-db.php';

if ( ! class_exists( 'NXTCC_Auth_Bindings_Store' ) ) {
	require_once __DIR__ . '/class-nxtcc-auth-bindings-store.php';
}

/**
 * Runtime integration helpers for stable add-on wrappers.
 */
final class NXTCC_Runtime_Integration {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_runtime';

	/**
	 * Quote a table identifier for safe SQL fragments.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private static function quote_table( string $table ): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $table ) || '' === $table ) {
			$table = 'nxtcc_invalid';
		}

		return '`' . $table . '`';
	}

	/**
	 * Build a deterministic cache key.
	 *
	 * @param string $prefix Key prefix.
	 * @param array  $parts  Key parts.
	 * @return string
	 */
	private static function cache_key( string $prefix, array $parts ): string {
		$json = wp_json_encode( array_values( $parts ) );
		$json = is_string( $json ) ? $json : '[]';

		return sanitize_key( $prefix ) . ':' . md5( $json );
	}

	/**
	 * Normalize a phone number to digits only.
	 *
	 * @param string $phone_number Raw phone number.
	 * @return string
	 */
	private static function normalize_phone( string $phone_number ): string {
		if ( function_exists( 'nxtcc_sanitize_phone_number' ) ) {
			return nxtcc_sanitize_phone_number( $phone_number );
		}

		$digits = preg_replace( '/\D+/', '', $phone_number );
		return is_string( $digits ) ? $digits : '';
	}

	/**
	 * Read a contact row by phone number, optionally scoped to a tenant.
	 *
	 * @param string $phone_number         Phone number in any common format.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	public static function get_contact_by_phone(
		string $phone_number,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		$phone_number        = self::normalize_phone( $phone_number );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( '' === $phone_number ) {
			return null;
		}

		$cache_key = self::cache_key(
			'contact_phone',
			array( $phone_number, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$sql          = 'SELECT *
			FROM ' . $contacts_sql . '
			WHERE (
				CONCAT(country_code, phone_number) = %s
				OR phone_number = %s
			)';
		$args         = array( $phone_number, $phone_number );

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

		$sql .= ' ORDER BY is_verified DESC, updated_at DESC, id DESC LIMIT 1';

		$row = $db->get_row( $sql, $args, ARRAY_A );
		$row = is_array( $row ) ? $row : null;

		wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 300 );

		return $row;
	}

	/**
	 * Read a contact row by linked WordPress user id.
	 *
	 * Falls back to the latest verified WhatsApp binding when the contact row is
	 * not yet linked by `wp_uid`.
	 *
	 * @param int    $user_id              WordPress user id.
	 * @param string $user_mailid          Optional owner mail.
	 * @param string $business_account_id  Optional business account id.
	 * @param string $phone_number_id      Optional phone number id.
	 * @return array<string, mixed>|null
	 */
	public static function get_contact_by_wp_user(
		int $user_id,
		string $user_mailid = '',
		string $business_account_id = '',
		string $phone_number_id = ''
	): ?array {
		$user_id             = absint( $user_id );
		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );

		if ( $user_id <= 0 ) {
			return null;
		}

		$cache_key = self::cache_key(
			'contact_wp_user',
			array( $user_id, $user_mailid, $business_account_id, $phone_number_id )
		);
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$sql          = 'SELECT *
			FROM ' . $contacts_sql . '
			WHERE wp_uid = %d';
		$args         = array( $user_id );

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

		$sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';

		$row = $db->get_row( $sql, $args, ARRAY_A );
		$row = is_array( $row ) ? $row : null;

		if ( ! is_array( $row ) ) {
			$verified_phone = self::get_latest_verified_phone_for_user( $user_id );
			if ( '' !== $verified_phone ) {
				$row = self::get_contact_by_phone(
					$verified_phone,
					$user_mailid,
					$business_account_id,
					$phone_number_id
				);
			}
		}

		wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 300 );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read the latest verified WhatsApp number for a WordPress user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function get_latest_verified_phone_for_user( int $user_id ): string {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'NXTCC_Auth_Bindings_Store' ) ) {
			return '';
		}

		$phone_number = NXTCC_Auth_Bindings_Store::latest_verified_e164( $user_id );
		return is_string( $phone_number ) ? sanitize_text_field( $phone_number ) : '';
	}

	/**
	 * Resolve a local history row id from a Meta message id.
	 *
	 * @param string $wamid Meta message id.
	 * @return int
	 */
	public static function get_message_history_id_by_wamid( string $wamid ): int {
		if ( function_exists( 'nxtcc_normalize_reply_wamid' ) ) {
			$wamid = nxtcc_normalize_reply_wamid( $wamid );
		} else {
			$wamid = sanitize_text_field( $wamid );
		}

		if ( '' === $wamid ) {
			return 0;
		}

		$cache_key = self::cache_key( 'history_wamid', array( $wamid ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return absint( $cached );
		}

		$db          = NXTCC_DB::i();
		$history_sql = self::quote_table( $db->t_message_history() );
		$value       = $db->get_var(
			'SELECT id FROM ' . $history_sql . ' WHERE meta_message_id = %s LIMIT 1',
			array( $wamid )
		);
		$value       = absint( $value );

		wp_cache_set( $cache_key, $value, self::CACHE_GROUP, 300 );

		return $value;
	}

	/**
	 * Update a contact subscription status through the runtime contract.
	 *
	 * @param array<string, mixed> $args Update arguments.
	 * @return array<string, mixed>
	 */
	public static function update_contact_subscription_status( array $args ): array {
		$contact_id = isset( $args['contact_id'] ) ? absint( $args['contact_id'] ) : 0;
		$status     = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

		if ( '' === $status && isset( $args['subscription_status'] ) ) {
			$status = sanitize_key( (string) $args['subscription_status'] );
		}

		if ( '' === $status && array_key_exists( 'is_subscribed', $args ) ) {
			$status = ! empty( $args['is_subscribed'] ) ? 'subscribed' : 'unsubscribed';
		}

		if ( $contact_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'invalid_contact_id',
			);
		}

		if ( ! in_array( $status, array( 'subscribed', 'unsubscribed' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_subscription_status',
			);
		}

		$user_mailid         = isset( $args['user_mailid'] ) ? sanitize_email( (string) $args['user_mailid'] ) : '';
		$business_account_id = isset( $args['business_account_id'] ) ? sanitize_text_field( (string) $args['business_account_id'] ) : '';
		$phone_number_id     = isset( $args['phone_number_id'] ) ? sanitize_text_field( (string) $args['phone_number_id'] ) : '';
		$reason              = isset( $args['reason'] ) ? sanitize_text_field( (string) $args['reason'] ) : '';
		$reason              = substr( $reason, 0, 255 );

		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$sql          = 'SELECT id, user_mailid, business_account_id, phone_number_id, country_code, phone_number, wp_uid, is_subscribed, unsubscribed_at, unsubscribed_reason
			FROM ' . $contacts_sql . '
			WHERE id = %d';
		$query_args   = array( $contact_id );

		if ( '' !== $user_mailid ) {
			$sql         .= ' AND user_mailid = %s';
			$query_args[] = $user_mailid;
		}

		if ( '' !== $business_account_id ) {
			$sql         .= ' AND business_account_id = %s';
			$query_args[] = $business_account_id;
		}

		if ( '' !== $phone_number_id ) {
			$sql         .= ' AND phone_number_id = %s';
			$query_args[] = $phone_number_id;
		}

		$sql .= ' LIMIT 1';

		$row = $db->get_row( $sql, $query_args, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array(
				'success' => false,
				'error'   => 'contact_not_found',
			);
		}

		$current_flag     = ! empty( $row['is_subscribed'] ) ? 1 : 0;
		$next_flag        = 'subscribed' === $status ? 1 : 0;
		$row_user_mailid  = isset( $row['user_mailid'] ) ? sanitize_email( (string) $row['user_mailid'] ) : '';
		$row_business_id  = isset( $row['business_account_id'] ) ? sanitize_text_field( (string) $row['business_account_id'] ) : '';
		$row_phone_id     = isset( $row['phone_number_id'] ) ? sanitize_text_field( (string) $row['phone_number_id'] ) : '';
		$now              = current_time( 'mysql', true );
		$current_unsub_at = isset( $row['unsubscribed_at'] ) ? trim( (string) $row['unsubscribed_at'] ) : '';
		$current_reason   = isset( $row['unsubscribed_reason'] ) ? trim( (string) $row['unsubscribed_reason'] ) : '';
		$changed          = false;
		$affected         = 0;

		if ( 1 === $next_flag ) {
			$changed = 1 !== $current_flag || '' !== $current_unsub_at || '' !== $current_reason;

			if ( $changed ) {
				$affected = $db->query(
					'UPDATE ' . $contacts_sql . '
					 SET is_subscribed = %d,
						 unsubscribed_at = NULL,
						 unsubscribed_reason = NULL,
						 updated_at = %s
					 WHERE id = %d
					   AND user_mailid = %s
					   AND business_account_id = %s
					   AND phone_number_id = %s
					 LIMIT 1',
					array(
						1,
						$now,
						$contact_id,
						$row_user_mailid,
						$row_business_id,
						$row_phone_id,
					)
				);
			}
		} else {
			$next_reason       = '' !== $reason ? $reason : ( '' !== $current_reason ? $current_reason : 'workflow' );
			$next_unsubscribed = '' !== $current_unsub_at ? $current_unsub_at : $now;
			$changed           = 0 !== $current_flag || '' === $current_unsub_at || $next_reason !== $current_reason;

			if ( $changed ) {
				$affected = $db->query(
					'UPDATE ' . $contacts_sql . '
					 SET is_subscribed = %d,
						 unsubscribed_at = %s,
						 unsubscribed_reason = %s,
						 updated_at = %s
					 WHERE id = %d
					   AND user_mailid = %s
					   AND business_account_id = %s
					   AND phone_number_id = %s
					 LIMIT 1',
					array(
						0,
						$next_unsubscribed,
						$next_reason,
						$now,
						$contact_id,
						$row_user_mailid,
						$row_business_id,
						$row_phone_id,
					)
				);
			}
		}

		if ( $changed && $affected <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'subscription_update_failed',
			);
		}

		self::flush_contact_runtime_cache( $row );

		if ( function_exists( 'nxtcc_invalidate_tenant_caches' ) ) {
			nxtcc_invalidate_tenant_caches( $row_business_id, $row_phone_id );
		}

		$result = array(
			'success'             => true,
			'contact_id'          => $contact_id,
			'status'              => $status,
			'is_subscribed'       => $next_flag,
			'previous_status'     => 1 === $current_flag ? 'subscribed' : 'unsubscribed',
			'previous_subscribed' => $current_flag,
			'changed'             => $changed,
		);

		/**
		 * Fires after the runtime contract updates a contact subscription status.
		 *
		 * @param array<string, mixed> $result Update result payload.
		 * @param array<string, mixed> $row    Original contact row.
		 * @param array<string, mixed> $args   Original update arguments.
		 */
		do_action( 'nxtcc_contact_subscription_status_updated', $result, $row, $args );

		return $result;
	}

	/**
	 * Create or update a contact for an external integration.
	 *
	 * This wrapper is intentionally generic so add-ons and future platform
	 * integrations can write contacts through one Free-owned runtime surface.
	 *
	 * @param array<string, mixed> $args Contact payload.
	 * @return array<string, mixed>
	 */
	public static function upsert_contact_for_integration( array $args ): array {
		$user_mailid         = isset( $args['user_mailid'] ) ? sanitize_email( (string) $args['user_mailid'] ) : '';
		$business_account_id = isset( $args['business_account_id'] ) ? sanitize_text_field( (string) $args['business_account_id'] ) : '';
		$phone_number_id     = isset( $args['phone_number_id'] ) ? sanitize_text_field( (string) $args['phone_number_id'] ) : '';
		$raw_phone           = isset( $args['phone_number'] ) ? (string) $args['phone_number'] : ( isset( $args['phone'] ) ? (string) $args['phone'] : '' );
		$phone_number        = self::normalize_phone( $raw_phone );
		$country_code        = isset( $args['country_code'] ) ? self::normalize_phone( (string) $args['country_code'] ) : '';
		$wp_user_id          = isset( $args['wp_user_id'] ) ? absint( $args['wp_user_id'] ) : ( isset( $args['wp_uid'] ) ? absint( $args['wp_uid'] ) : 0 );
		$name                = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$email               = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
		$source              = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'integration';
		$external_id         = isset( $args['external_id'] ) ? sanitize_text_field( (string) $args['external_id'] ) : '';
		$metadata            = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? self::sanitize_metadata_value( $args['metadata'] ) : array();
		$allow_resubscribe   = ! empty( $args['allow_resubscribe'] );
		$now                 = current_time( 'mysql', true );

		if ( '' === $source ) {
			$source = 'integration';
		}

		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id ) {
			return array(
				'success' => false,
				'error'   => 'missing_tenant',
			);
		}

		list( $country_code, $local_phone ) = self::split_contact_phone( $phone_number, $country_code );
		if ( '' === $local_phone ) {
			return array(
				'success' => false,
				'error'   => 'invalid_phone_number',
			);
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$phone_e164   = self::normalize_phone( $country_code . $local_phone );
		$existing     = self::get_contact_by_phone( $phone_e164, $user_mailid, $business_account_id, $phone_number_id );

		if ( ! is_array( $existing ) && $wp_user_id > 0 ) {
			$existing = self::get_contact_by_wp_user( $wp_user_id, $user_mailid, $business_account_id, $phone_number_id );
		}

		$integration_fields = self::build_integration_custom_fields(
			$source,
			$external_id,
			$email,
			$metadata,
			$now
		);

		if ( is_array( $existing ) ) {
			$contact_id    = isset( $existing['id'] ) ? absint( $existing['id'] ) : 0;
			$custom_fields = self::merge_contact_custom_fields(
				isset( $existing['custom_fields'] ) ? $existing['custom_fields'] : '',
				$integration_fields
			);
			$update        = array(
				'custom_fields' => $custom_fields,
				'updated_at'    => $now,
			);

			if ( '' !== $name && ( empty( $existing['name'] ) || $name !== (string) $existing['name'] ) ) {
				$update['name'] = $name;
			}

			$existing_wp_uid = isset( $existing['wp_uid'] ) ? absint( $existing['wp_uid'] ) : 0;
			if ( $wp_user_id > 0 && ( 0 === $existing_wp_uid || $wp_user_id === $existing_wp_uid ) ) {
				$update['wp_uid'] = $wp_user_id;
			}

			if ( array_key_exists( 'is_subscribed', $args ) ) {
				$requested_subscribed = ! empty( $args['is_subscribed'] ) ? 1 : 0;
				$current_subscribed   = ! empty( $existing['is_subscribed'] ) ? 1 : 0;

				if ( 0 === $requested_subscribed ) {
					$update['is_subscribed']       = 0;
					$update['unsubscribed_at']     = ! empty( $existing['unsubscribed_at'] ) ? $existing['unsubscribed_at'] : $now;
					$update['unsubscribed_reason'] = 'integration';
				} elseif ( $allow_resubscribe || 1 === $current_subscribed ) {
					$update['is_subscribed']       = 1;
					$update['unsubscribed_at']     = null;
					$update['unsubscribed_reason'] = null;
				}
			}

			$updated = $db->update(
				$db->t_contacts(),
				$update,
				array(
					'id'                  => $contact_id,
					'user_mailid'         => $user_mailid,
					'business_account_id' => $business_account_id,
					'phone_number_id'     => $phone_number_id,
				)
			);

			if ( ! $updated ) {
				$contact = self::read_contact_by_id( $contact_id, $user_mailid, $business_account_id, $phone_number_id );
				if ( ! is_array( $contact ) ) {
					return array(
						'success' => false,
						'error'   => 'contact_update_failed',
					);
				}

				$result = array(
					'success'       => true,
					'contact_id'    => $contact_id,
					'created'       => false,
					'updated'       => false,
					'is_subscribed' => ! empty( $contact['is_subscribed'] ) ? 1 : 0,
					'contact'       => $contact,
				);

				do_action( 'nxtcc_contact_upserted_for_integration', $result, $args );

				return $result;
			}

			self::flush_contact_runtime_cache( $existing );
			$contact = self::read_contact_by_id( $contact_id, $user_mailid, $business_account_id, $phone_number_id );

			$result = array(
				'success'       => true,
				'contact_id'    => $contact_id,
				'created'       => false,
				'updated'       => true,
				'is_subscribed' => is_array( $contact ) && ! empty( $contact['is_subscribed'] ) ? 1 : 0,
				'contact'       => is_array( $contact ) ? $contact : array(),
			);

			do_action( 'nxtcc_contact_upserted_for_integration', $result, $args );

			return $result;
		}

		$is_subscribed = array_key_exists( 'is_subscribed', $args ) ? ( ! empty( $args['is_subscribed'] ) ? 1 : 0 ) : 1;
		$custom_json   = wp_json_encode( $integration_fields );
		$row           = array(
			'user_mailid'         => $user_mailid,
			'wp_uid'              => $wp_user_id > 0 ? $wp_user_id : null,
			'business_account_id' => $business_account_id,
			'phone_number_id'     => $phone_number_id,
			'country_code'        => $country_code,
			'phone_number'        => $local_phone,
			'name'                => '' !== $name ? $name : null,
			'is_verified'         => 0,
			'is_subscribed'       => $is_subscribed,
			'unsubscribed_at'     => 1 === $is_subscribed ? null : $now,
			'unsubscribed_reason' => 1 === $is_subscribed ? null : 'integration',
			'custom_fields'       => is_string( $custom_json ) ? $custom_json : '{}',
			'created_by'          => get_current_user_id() > 0 ? get_current_user_id() : null,
			'updated_by'          => get_current_user_id() > 0 ? get_current_user_id() : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		$inserted      = $db->insert( $db->t_contacts(), $row );
		$contact_id    = $inserted ? $db->insert_id() : 0;

		if ( $contact_id <= 0 ) {
			$duplicate = self::read_contact_by_exact_phone(
				$user_mailid,
				$business_account_id,
				$phone_number_id,
				$country_code,
				$local_phone
			);

			if ( is_array( $duplicate ) && ! empty( $duplicate['id'] ) ) {
				self::flush_contact_runtime_cache( $duplicate );

				return self::upsert_contact_for_integration(
					array_merge(
						$args,
						array(
							'allow_resubscribe' => false,
						)
					)
				);
			}

			return array(
				'success' => false,
				'error'   => 'contact_insert_failed',
			);
		}

		$contact = self::read_contact_by_id( $contact_id, $user_mailid, $business_account_id, $phone_number_id );
		if ( is_array( $contact ) ) {
			self::flush_contact_runtime_cache( $contact );
		}

		$result = array(
			'success'       => true,
			'contact_id'    => $contact_id,
			'created'       => true,
			'updated'       => false,
			'is_subscribed' => $is_subscribed,
			'contact'       => is_array( $contact ) ? $contact : array(),
		);

		do_action( 'nxtcc_contact_upserted_for_integration', $result, $args );

		return $result;
	}

	/**
	 * Flush known runtime contact cache keys after a contact write.
	 *
	 * @param array<string, mixed> $row Contact row.
	 * @return void
	 */
	private static function flush_contact_runtime_cache( array $row ): void {
		$contact_id          = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		$user_mailid         = isset( $row['user_mailid'] ) ? sanitize_email( (string) $row['user_mailid'] ) : '';
		$business_account_id = isset( $row['business_account_id'] ) ? sanitize_text_field( (string) $row['business_account_id'] ) : '';
		$phone_number_id     = isset( $row['phone_number_id'] ) ? sanitize_text_field( (string) $row['phone_number_id'] ) : '';

		if ( $contact_id <= 0 ) {
			return;
		}

		wp_cache_delete( self::cache_key( 'contact', array( $contact_id, '', '', '' ) ), self::CACHE_GROUP );
		wp_cache_delete( self::cache_key( 'contact', array( $contact_id, $user_mailid, $business_account_id, $phone_number_id ) ), self::CACHE_GROUP );

		$phone_e164 = ( isset( $row['country_code'] ) ? (string) $row['country_code'] : '' ) . ( isset( $row['phone_number'] ) ? (string) $row['phone_number'] : '' );
		$phone_e164 = self::normalize_phone( $phone_e164 );
		if ( '' !== $phone_e164 ) {
			wp_cache_delete( self::cache_key( 'contact_phone', array( $phone_e164, '', '', '' ) ), self::CACHE_GROUP );
			wp_cache_delete( self::cache_key( 'contact_phone', array( $phone_e164, $user_mailid, $business_account_id, $phone_number_id ) ), self::CACHE_GROUP );
		}

		$wp_uid = isset( $row['wp_uid'] ) ? absint( $row['wp_uid'] ) : 0;
		if ( $wp_uid > 0 ) {
			wp_cache_delete( self::cache_key( 'contact_wp_user', array( $wp_uid, '', '', '' ) ), self::CACHE_GROUP );
			wp_cache_delete( self::cache_key( 'contact_wp_user', array( $wp_uid, $user_mailid, $business_account_id, $phone_number_id ) ), self::CACHE_GROUP );
		}
	}

	/**
	 * Split a phone number into stored country/local columns.
	 *
	 * @param string $phone_number Normalized full phone digits.
	 * @param string $country_code Optional normalized country code.
	 * @return array{0:string,1:string}
	 */
	private static function split_contact_phone( string $phone_number, string $country_code ): array {
		if ( '' === $phone_number ) {
			return array( '', '' );
		}

		if ( '' === $country_code ) {
			return array( '', substr( $phone_number, 0, 30 ) );
		}

		$local_phone = $phone_number;
		if ( 0 === strpos( $phone_number, $country_code ) ) {
			$local_phone = substr( $phone_number, strlen( $country_code ) );
		}

		return array(
			substr( $country_code, 0, 10 ),
			substr( self::normalize_phone( $local_phone ), 0, 30 ),
		);
	}

	/**
	 * Read one contact by id using tenant guards.
	 *
	 * @param int    $contact_id          Contact id.
	 * @param string $user_mailid         Tenant owner email.
	 * @param string $business_account_id Business account id.
	 * @param string $phone_number_id     Phone number id.
	 * @return array<string, mixed>|null
	 */
	private static function read_contact_by_id( int $contact_id, string $user_mailid, string $business_account_id, string $phone_number_id ): ?array {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$row          = $db->get_row(
			'SELECT *
			 FROM ' . $contacts_sql . '
			 WHERE id = %d
			   AND user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			 LIMIT 1',
			array( $contact_id, $user_mailid, $business_account_id, $phone_number_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read one contact by the table's unique phone tuple.
	 *
	 * @param string $user_mailid         Tenant owner email.
	 * @param string $business_account_id Business account id.
	 * @param string $phone_number_id     Phone number id.
	 * @param string $country_code        Stored country code.
	 * @param string $phone_number        Stored phone number.
	 * @return array<string, mixed>|null
	 */
	private static function read_contact_by_exact_phone(
		string $user_mailid,
		string $business_account_id,
		string $phone_number_id,
		string $country_code,
		string $phone_number
	): ?array {
		$db           = NXTCC_DB::i();
		$contacts_sql = self::quote_table( $db->t_contacts() );
		$row          = $db->get_row(
			'SELECT *
			 FROM ' . $contacts_sql . '
			 WHERE user_mailid = %s
			   AND business_account_id = %s
			   AND phone_number_id = %s
			   AND country_code = %s
			   AND phone_number = %s
			 LIMIT 1',
			array( $user_mailid, $business_account_id, $phone_number_id, $country_code, $phone_number ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Build custom field payload for integration writes.
	 *
	 * @param string               $source      Integration source.
	 * @param string               $external_id External source id.
	 * @param string               $email       Contact email.
	 * @param array<string, mixed> $metadata    Sanitized metadata.
	 * @param string               $now         Current UTC time.
	 * @return array<string, mixed>
	 */
	private static function build_integration_custom_fields( string $source, string $external_id, string $email, array $metadata, string $now ): array {
		$payload = array(
			'integration_source'  => $source,
			'integration_seen_at' => $now,
		);

		if ( '' !== $external_id ) {
			$payload['integration_external_id'] = substr( $external_id, 0, 191 );
		}

		if ( '' !== $email ) {
			$payload['email'] = $email;
		}

		if ( ! empty( $metadata ) ) {
			$payload['integration_metadata'] = $metadata;
		}

		return $payload;
	}

	/**
	 * Merge integration custom fields into an existing JSON payload.
	 *
	 * @param mixed                $stored      Stored custom fields.
	 * @param array<string, mixed> $integration Integration fields.
	 * @return string
	 */
	private static function merge_contact_custom_fields( $stored, array $integration ): string {
		$current = array();
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$current = self::sanitize_metadata_value( $decoded );
			}
		} elseif ( is_array( $stored ) ) {
			$current = self::sanitize_metadata_value( $stored );
		}

		$merged = array_merge( $current, $integration );
		$json   = wp_json_encode( $merged );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Sanitize scalar or nested metadata values for JSON storage.
	 *
	 * @param mixed $value Raw metadata value.
	 * @param int   $depth Nesting depth.
	 * @return mixed
	 */
	private static function sanitize_metadata_value( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $child ) {
				$clean_key = is_string( $key ) ? sanitize_key( $key ) : (string) absint( $key );
				if ( '' === $clean_key ) {
					continue;
				}

				$clean[ $clean_key ] = self::sanitize_metadata_value( $child, $depth + 1 );
			}

			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Send a background-safe session reply through the shared text-send helper.
	 *
	 * @param array<string, mixed> $args Send arguments.
	 * @return array<string, mixed>
	 */
	public static function send_background_session_reply( array $args ): array {
		if ( ! function_exists( 'nxtcc_send_text_message_internal' ) ) {
			return array(
				'success' => false,
				'error'   => 'background_send_runtime_unavailable',
			);
		}

		return nxtcc_send_text_message_internal( $args, false );
	}
}
