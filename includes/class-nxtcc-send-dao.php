<?php
/**
 * DAO helpers used by send message functions.
 *
 * This file is OO-only to satisfy PHPCS rules.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-db-sendmessage.php';

/**
 * DAO helpers used by send message functions.
 */
final class NXTCC_Send_DAO {

	/**
	 * Fetch the latest encrypted token row for a tenant.
	 *
	 * @param string $user_mailid         User email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return object|null
	 */
	public static function get_settings_row( string $user_mailid, string $business_account_id, string $phone_number_id ): ?object {
		$ck = 'settings_row:' . md5( $user_mailid . '|' . $business_account_id . '|' . $phone_number_id );

		global $wpdb;

		return NXTCC_DB_SendMessage::get_row_prepared_sql(
			$wpdb->prepare(
				'SELECT access_token_ct, access_token_nonce
							   FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
							  WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
						   ORDER BY id DESC
							  LIMIT 1',
				$user_mailid,
				$business_account_id,
				$phone_number_id
			),
			$ck
		);
	}

	/**
	 * Fetch contact phone fields for recipient formatting.
	 *
	 * @param int    $contact_id  Contact ID.
	 * @param string $user_mailid User email.
	 * @return object|null
	 */
	public static function get_contact_row( int $contact_id, string $user_mailid ): ?object {
		$ck = 'contact_row:' . md5( (string) $contact_id . '|' . $user_mailid );

		global $wpdb;

		return NXTCC_DB_SendMessage::get_row_prepared_sql(
			$wpdb->prepare(
				'SELECT phone_number, country_code
							   FROM `' . $wpdb->prefix . 'nxtcc_contacts`
							  WHERE id = %d AND user_mailid = %s',
				$contact_id,
				$user_mailid
			),
			$ck
		);
	}

	/**
	 * Resolve local history ID from Meta WAMID.
	 *
	 * @param string $wamid Meta message ID.
	 * @return int
	 */
	public static function get_history_id_by_wamid( string $wamid ): int {
		$ck = 'mh_id_by_wamid:' . md5( $wamid );

		global $wpdb;

		return (int) NXTCC_DB_SendMessage::get_var_prepared_sql(
			$wpdb->prepare(
				'SELECT id
							   FROM `' . $wpdb->prefix . 'nxtcc_message_history`
							  WHERE meta_message_id = %s
							  LIMIT 1',
				$wamid
			),
			$ck
		);
	}

	/**
	 * Insert a message-history row.
	 *
	 * @param array $row Insert row.
	 * @return int|false
	 */
	public static function insert_history( array $row ) {
		global $wpdb;

		$table = $wpdb->prefix . 'nxtcc_message_history';
		return NXTCC_DB_SendMessage::insert( $table, $row );
	}
}
