<?php
/**
 * Authentication DAO.
 *
 * Encapsulates all database access for authentication/OTP/bindings/history.
 * Keeps $wpdb usage centralized and callable via prepared queries.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NXTCC_Auth_DAO
 *
 * Database access layer for auth-related tables.
 */
final class NXTCC_Auth_DAO {

	/**
	 * Return a single row as an associative array.
	 *
	 * @param string $prepared Prepared SQL string.
	 * @return array|null Row array or null.
	 */
	private static function row( string $prepared ): ?array {
		global $wpdb;

		$row = call_user_func( array( $wpdb, 'get_row' ), $prepared, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return many rows as associative arrays.
	 *
	 * @param string $prepared Prepared SQL string.
	 * @return array<int, array> Rows.
	 */
	private static function results( string $prepared ): array {
		global $wpdb;

		$rows = call_user_func( array( $wpdb, 'get_results' ), $prepared, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return a scalar value.
	 *
	 * @param string $prepared Prepared SQL string.
	 * @return mixed Scalar value.
	 */
	private static function var_value( string $prepared ) {
		global $wpdb;

		return call_user_func( array( $wpdb, 'get_var' ), $prepared );
	}

	/**
	 * Insert row.
	 *
	 * @param string             $table  Table name (with prefix).
	 * @param array              $data   Row data.
	 * @param array<int, string> $format Format array.
	 * @return int|false Insert result.
	 */
	private static function insert( string $table, array $data, array $format ) {
		global $wpdb;

		return call_user_func( array( $wpdb, 'insert' ), $table, $data, $format );
	}

	/**
	 * Update rows.
	 *
	 * @param string                  $table        Table name (with prefix).
	 * @param array                   $data         Data map.
	 * @param array                   $where        Where map.
	 * @param array<int, string>|null $format       Data format.
	 * @param array<int, string>|null $where_format Where format.
	 * @return int|false Rows affected or false.
	 */
	private static function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ) {
		global $wpdb;

		return call_user_func( array( $wpdb, 'update' ), $table, $data, $where, $format, $where_format );
	}

	/**
	 * Replace row.
	 *
	 * @param string             $table  Table name (with prefix).
	 * @param array              $data   Row data.
	 * @param array<int, string> $format Format array.
	 * @return int|false Replace result.
	 */
	private static function replace( string $table, array $data, array $format ) {
		global $wpdb;

		return call_user_func( array( $wpdb, 'replace' ), $table, $data, $format );
	}

	/**
	 * Get the latest settings row for a given connection owner email.
	 *
	 * @param string $mail Owner email.
	 * @return array|null Latest settings row.
	 */
	public static function latest_settings_for_owner( string $mail ): ?array {
		global $wpdb;

		$mail = sanitize_email( $mail );
		if ( '' === $mail ) {
			return null;
		}

		return self::row(
			$wpdb->prepare(
				'SELECT id, user_mailid, app_id, access_token_ct, access_token_nonce, business_account_id, phone_number_id, phone_number, meta_webhook_subscribed
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 WHERE user_mailid = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$mail
			)
		);
	}

	/**
	 * Get latest settings row where webhook is subscribed.
	 *
	 * @return array|null Latest settings row.
	 */
	public static function latest_settings_with_webhook(): ?array {
		global $wpdb;

		return self::row(
			$wpdb->prepare(
				'SELECT id, user_mailid, app_id, access_token_ct, access_token_nonce, business_account_id, phone_number_id, phone_number, meta_webhook_subscribed
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 WHERE meta_webhook_subscribed = %d
				 ORDER BY id DESC
				 LIMIT 1',
				1
			)
		);
	}

	/**
	 * Get the latest settings row (any owner).
	 *
	 * @return array|null Latest settings row.
	 */
	public static function latest_settings_any(): ?array {
		global $wpdb;

		return self::row(
			$wpdb->prepare(
				'SELECT id, user_mailid, app_id, access_token_ct, access_token_nonce, business_account_id, phone_number_id, phone_number, meta_webhook_subscribed
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 ORDER BY id DESC
				 LIMIT %d',
				1
			)
		);
	}

	/**
	 * For each owner_mail, return only their latest settings row.
	 *
	 * @return array<int, array> Rows.
	 */
	public static function latest_rows_per_owner(): array {
		global $wpdb;

		return self::results(
			$wpdb->prepare(
				'SELECT t.*
				 FROM (
					SELECT user_mailid, MAX(id) AS max_id
					FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
					GROUP BY user_mailid
				 ) x
				 JOIN `' . $wpdb->prefix . 'nxtcc_user_settings` t ON t.id = x.max_id
				 WHERE %d = %d
				 ORDER BY t.id DESC',
				1,
				1
			)
		);
	}

	/**
	 * Get most recent OTP row for a given session and phone.
	 *
	 * @param string $session_id Session id.
	 * @param string $phone_e164 Phone in E.164.
	 * @return array|null OTP row.
	 */
	public static function otp_find_latest( string $session_id, string $phone_e164 ): ?array {
		global $wpdb;

		return self::row(
			$wpdb->prepare(
				'SELECT *
				 FROM `' . $wpdb->prefix . 'nxtcc_auth_otp`
				 WHERE session_id = %s AND phone_e164 = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$session_id,
				$phone_e164
			)
		);
	}

	/**
	 * Get id of active OTP row for a given session and phone, if any.
	 *
	 * @param string $session_id Session id.
	 * @param string $phone_e164 Phone in E.164.
	 * @return int|null OTP id.
	 */
	public static function otp_find_active_id( string $session_id, string $phone_e164 ): ?int {
		global $wpdb;

		$id = self::var_value(
			$wpdb->prepare(
				'SELECT id
				 FROM `' . $wpdb->prefix . 'nxtcc_auth_otp`
				 WHERE session_id = %s AND phone_e164 = %s AND status = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$session_id,
				$phone_e164,
				'active'
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Update OTP row by id.
	 *
	 * @param int   $id   OTP row id.
	 * @param array $data Data to update.
	 * @return void
	 */
	public static function otp_update_by_id( int $id, array $data ): void {
		global $wpdb;

		self::update( $wpdb->prefix . 'nxtcc_auth_otp', $data, array( 'id' => $id ) );
	}

	/**
	 * Insert new OTP row and return its id.
	 *
	 * @param array $data Row data.
	 * @return int Inserted id.
	 */
	public static function otp_insert( array $data ): int {
		global $wpdb;

		self::insert(
			$wpdb->prefix . 'nxtcc_auth_otp',
			$data,
			array(
				'%s', // session_id.
				'%s', // phone_e164.
				'%d', // user_id.
				'%s', // code_hash.
				'%s', // salt.
				'%s', // expires_at.
				'%d', // attempts.
				'%d', // max_attempts.
				'%s', // status.
				'%s', // created_at.
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find phone binding row (if number already linked to a user).
	 *
	 * Returns an object because callers use object properties.
	 *
	 * @param string $phone_e164 Phone in E.164.
	 * @return object|null Binding row.
	 */
	public static function binding_find_by_phone( string $phone_e164 ) {
		global $wpdb;

		$phone_e164 = sanitize_text_field( $phone_e164 );

		return call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT * FROM `' . $wpdb->prefix . 'nxtcc_auth_bindings`
				 WHERE phone_e164 = %s
				 LIMIT 1',
				$phone_e164
			)
		);
	}

	/**
	 * Mark an existing binding as verified if it has no verified_at yet.
	 *
	 * @param int $id Binding id.
	 * @return void
	 */
	public static function binding_mark_verified_if_empty( int $id ): void {
		global $wpdb;

		self::update(
			$wpdb->prefix . 'nxtcc_auth_bindings',
			array(
				'verified_at' => current_time( 'mysql', 1 ),
				'updated_at'  => current_time( 'mysql', 1 ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Upsert a binding mapping a user to a phone number.
	 *
	 * @param int    $user_id    WP user id.
	 * @param string $phone_e164 Phone in E.164.
	 * @return void
	 */
	public static function binding_replace( int $user_id, string $phone_e164 ): void {
		global $wpdb;

		self::replace(
			$wpdb->prefix . 'nxtcc_auth_bindings',
			array(
				'user_id'     => $user_id,
				'phone_e164'  => $phone_e164,
				'verified_at' => current_time( 'mysql', 1 ),
				'created_at'  => current_time( 'mysql', 1 ),
				'updated_at'  => current_time( 'mysql', 1 ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Insert a message history row for outbound template sends.
	 *
	 * @param array $data Row data.
	 * @return void
	 */
	public static function history_insert( array $data ): void {
		global $wpdb;

		$fmt_map = array(
			'user_mailid'         => '%s',
			'business_account_id' => '%s',
			'phone_number_id'     => '%s',
			'template_name'       => '%s',
			'template_type'       => '%s',
			'template_data'       => '%s',
			'status'              => '%s',
			'status_timestamps'   => '%s',
			'origin_type'         => '%s',
			'origin_user_id'      => '%d',
			'origin_ref'          => '%s',
			'created_at'          => '%s',
			'sent_at'             => '%s',
			'meta_message_id'     => '%s',
		);
		$format  = array();

		foreach ( array_keys( $data ) as $key ) {
			$format[] = isset( $fmt_map[ $key ] ) ? $fmt_map[ $key ] : '%s';
		}

		self::insert(
			$wpdb->prefix . 'nxtcc_message_history',
			$data,
			$format
		);
	}
}
