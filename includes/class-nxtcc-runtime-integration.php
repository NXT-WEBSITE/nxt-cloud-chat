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
