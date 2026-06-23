<?php
/**
 * DAO wiring for NXT Cloud Chat.
 *
 * Registers filter/action callbacks that provide DB access for NXTCC_Helpers
 * without introducing direct $wpdb calls inside helper methods.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access object (DAO) to bridge helpers and the database layer.
 *
 * This class wires filters/actions consumed by NXTCC_Helpers:
 * - Tenant credentials lookup.
 * - Templates list and template-names lookup.
 * - Insert/update and delete template rows.
 */
final class NXTCC_DAO {

	/**
	 * Object cache group name.
	 *
	 * @var string
	 */
	private const GROUP = 'nxtcc';

	/**
	 * Register hooks for DAO filters/actions.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'nxtcc_db_get_tenant_creds', array( __CLASS__, 'get_tenant_creds' ), 10, 4 );
		add_filter( 'nxtcc_db_list_tenant_profiles', array( __CLASS__, 'list_tenant_profiles' ), 10, 1 );
		add_filter( 'nxtcc_db_get_tenant_profile', array( __CLASS__, 'get_tenant_profile' ), 10, 4 );
		add_filter( 'nxtcc_db_get_primary_display_phone_number', array( __CLASS__, 'get_primary_display_phone_number' ), 10, 1 );
		add_filter( 'nxtcc_db_get_templates', array( __CLASS__, 'get_templates' ), 10, 3 );
		add_filter( 'nxtcc_db_get_template_names', array( __CLASS__, 'get_template_names' ), 10, 4 );
		add_action( 'nxtcc_db_upsert_template', array( __CLASS__, 'upsert_template' ), 10, 1 );
		add_action( 'nxtcc_db_delete_template', array( __CLASS__, 'delete_template' ), 10, 1 );
	}

	/**
	 * Filter callback: List the latest configured tenant profiles.
	 *
	 * The result intentionally excludes credentials and is bounded for safe use
	 * in integration selectors.
	 *
	 * @param mixed $profiles Filter passthrough.
	 * @return array<int, array<string, string>> Tenant profiles.
	 */
	public static function list_tenant_profiles( $profiles ): array {
		global $wpdb;
		unset( $profiles );

		$ckey = NXTCC_Helpers::ckey( 'dao:tenant:profiles', array( 'all' ) );
		$hit  = wp_cache_get( $ckey, self::GROUP );
		if ( false !== $hit ) {
			return is_array( $hit ) ? $hit : array();
		}

		$rows = call_user_func(
			array( $wpdb, 'get_results' ),
			$wpdb->prepare(
				'SELECT s.user_mailid, s.business_account_id, s.phone_number_id, s.phone_number
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings` s
				 INNER JOIN (
					SELECT user_mailid, business_account_id, phone_number_id, MAX(id) AS latest_id
					FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
					WHERE user_mailid <> %s AND business_account_id <> %s AND phone_number_id <> %s
					GROUP BY user_mailid, business_account_id, phone_number_id
				 ) latest ON latest.latest_id = s.id
				 WHERE s.phone_number <> %s
				 ORDER BY s.user_mailid ASC, s.id DESC
				 LIMIT %d',
				'',
				'',
				'',
				'',
				500
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$profile = self::sanitize_tenant_profile( $row );
			if ( ! empty( $profile ) ) {
				$out[] = $profile;
			}
		}

		wp_cache_set( $ckey, $out, self::GROUP, 300 );
		return $out;
	}

	/**
	 * Filter callback: Resolve one exact tenant profile without credentials.
	 *
	 * @param mixed  $profile             Filter passthrough.
	 * @param string $user_mailid         Connection owner email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return array<string, string>|false Tenant profile or false.
	 */
	public static function get_tenant_profile( $profile, $user_mailid, $business_account_id, $phone_number_id ) {
		global $wpdb;

		$user_mailid         = sanitize_email( (string) $user_mailid );
		$business_account_id = sanitize_text_field( (string) $business_account_id );
		$phone_number_id     = sanitize_text_field( (string) $phone_number_id );
		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id ) {
			return false;
		}

		$ckey = NXTCC_Helpers::ckey( 'dao:tenant:profile', array( $user_mailid, $business_account_id, $phone_number_id ) );
		$hit  = wp_cache_get( $ckey, self::GROUP );
		if ( false !== $hit ) {
			return is_array( $hit ) ? $hit : false;
		}

		$row = call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT user_mailid, business_account_id, phone_number_id, phone_number
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				 ORDER BY id DESC LIMIT 1',
				$user_mailid,
				$business_account_id,
				$phone_number_id
			),
			ARRAY_A
		);

		$out = is_array( $row ) ? self::sanitize_tenant_profile( $row ) : array();
		$out = ! empty( $out ) ? $out : false;
		wp_cache_set( $ckey, $out, self::GROUP, 300 );
		return $out;
	}

	/**
	 * Filter callback: Get the primary connection's display phone number.
	 *
	 * @param mixed $phone_number Filter passthrough.
	 * @return string Digits-only display phone number, or an empty string.
	 */
	public static function get_primary_display_phone_number( $phone_number ): string {
		unset( $phone_number );

		if ( ! class_exists( 'NXTCC_Settings_DAO' ) ) {
			return '';
		}

		$row = null;
		if ( class_exists( 'NXTCC_Access_Control' ) ) {
			$tenant = NXTCC_Access_Control::get_primary_tenant_context();
			if ( ! in_array( '', $tenant, true ) ) {
				$row = NXTCC_Settings_DAO::get_row_for_tenant(
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				);
			}
		}

		// Existing installations may not have bootstrapped the primary tenant yet.
		if ( ! is_object( $row ) ) {
			$row = NXTCC_Settings_DAO::get_latest_any();
		}

		return is_object( $row )
			? NXTCC_Helpers::sanitize_phone_number( $row->phone_number ?? '' )
			: '';
	}

	/**
	 * Invalidate connection caches after a tenant settings write.
	 *
	 * @param string $user_mailid         Connection owner email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return void
	 */
	public static function invalidate_tenant_connection_caches( string $user_mailid, string $business_account_id, string $phone_number_id ): void {
		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tenant:profiles', array( 'all' ) ), self::GROUP );

		$user_mailid         = sanitize_email( $user_mailid );
		$business_account_id = sanitize_text_field( $business_account_id );
		$phone_number_id     = sanitize_text_field( $phone_number_id );
		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id ) {
			return;
		}

		$tenant = array( $user_mailid, $business_account_id, $phone_number_id );
		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tenant:profile', $tenant ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tenant', $tenant ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'tenant_creds', $tenant ), self::GROUP );
	}

	/**
	 * Normalize one credential-free tenant profile.
	 *
	 * @param mixed $row Raw database row.
	 * @return array<string, string> Profile or an empty array.
	 */
	private static function sanitize_tenant_profile( $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$profile = array(
			'user_mailid'         => sanitize_email( (string) ( $row['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $row['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $row['phone_number_id'] ?? '' ) ),
			'phone_number'        => NXTCC_Helpers::sanitize_phone_number( $row['phone_number'] ?? '' ),
		);

		return in_array( '', $profile, true ) ? array() : $profile;
	}

	/**
	 * Execute an insert query for a table.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $format Formats.
	 * @return void
	 */
	private static function db_insert( string $table, array $data, array $format ): void {
		global $wpdb;

		call_user_func( array( $wpdb, 'insert' ), $table, $data, $format );
	}

	/**
	 * Execute an update query for a table.
	 *
	 * @param string $table        Table name.
	 * @param array  $data         Row data.
	 * @param array  $where        Where clause.
	 * @param array  $format       Value formats.
	 * @param array  $where_format Where formats.
	 * @return void
	 */
	private static function db_update( string $table, array $data, array $where, array $format, array $where_format ): void {
		global $wpdb;

		call_user_func( array( $wpdb, 'update' ), $table, $data, $where, $format, $where_format );
	}

	/**
	 * Execute a delete query for a table.
	 *
	 * @param string $table        Table name.
	 * @param array  $where        Where clause.
	 * @param array  $where_format Where formats.
	 * @return void
	 */
	private static function db_delete( string $table, array $where, array $where_format ): void {
		global $wpdb;

		call_user_func( array( $wpdb, 'delete' ), $table, $where, $where_format );
	}

	/**
	 * Filter callback: Fetch tenant credentials row.
	 *
	 * @param mixed  $unused              Unused value (filter passthrough).
	 * @param string $user_mailid         User email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return array|false Row array or false when not found/invalid.
	 */
	public static function get_tenant_creds( $unused, $user_mailid, $business_account_id, $phone_number_id ) {
		global $wpdb;

		$user_mailid         = sanitize_email( (string) $user_mailid );
		$business_account_id = sanitize_text_field( (string) $business_account_id );
		$phone_number_id     = sanitize_text_field( (string) $phone_number_id );

		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id ) {
			return false;
		}

		$ckey = NXTCC_Helpers::ckey(
			'dao:tenant',
			array( $user_mailid, $business_account_id, $phone_number_id )
		);

		$hit = wp_cache_get( $ckey, self::GROUP );
		if ( false !== $hit ) {
			return $hit;
		}

		$row = call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT app_id, access_token_ct, access_token_nonce, business_account_id, phone_number_id, phone_number
				 FROM `' . $wpdb->prefix . 'nxtcc_user_settings`
				 WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$user_mailid,
				$business_account_id,
				$phone_number_id
			),
			ARRAY_A
		);

		$out = is_array( $row )
			? array(
				'app_id'              => (string) ( $row['app_id'] ?? '' ),
				'access_token_ct'     => isset( $row['access_token_ct'] ) ? (string) $row['access_token_ct'] : null,
				'access_token_nonce'  => isset( $row['access_token_nonce'] ) ? $row['access_token_nonce'] : null,
				'business_account_id' => (string) ( $row['business_account_id'] ?? '' ),
				'phone_number_id'     => (string) ( $row['phone_number_id'] ?? '' ),
				'phone_number'        => (string) ( $row['phone_number'] ?? '' ),
			)
			: false;

		// Use literal TTL so VIP cache sniff can evaluate it (>= 300 seconds).
		wp_cache_set( $ckey, $out, self::GROUP, 300 );

		return $out;
	}

	/**
	 * Filter callback: Fetch templates list for a user/phone_number_id.
	 *
	 * @param mixed  $rows            Filter passthrough.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number ID.
	 * @return array Templates rows.
	 */
	public static function get_templates( $rows, $user_mailid, $phone_number_id ) {
		global $wpdb;

		$user_mailid     = sanitize_email( (string) $user_mailid );
		$phone_number_id = sanitize_text_field( (string) $phone_number_id );

		if ( '' === $user_mailid || '' === $phone_number_id ) {
			return array();
		}

		$ckey = NXTCC_Helpers::ckey(
			'dao:tpl:list',
			array( $user_mailid, $phone_number_id )
		);

		$hit = wp_cache_get( $ckey, self::GROUP );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		$rows = call_user_func(
			array( $wpdb, 'get_results' ),
			$wpdb->prepare(
				'SELECT template_name, category, language, status, components, business_account_id, phone_number_id, last_synced, created_by, updated_by, created_at, updated_at
				 FROM `' . $wpdb->prefix . 'nxtcc_templates`
				 WHERE user_mailid = %s AND phone_number_id = %s
				 ORDER BY template_name ASC',
				$user_mailid,
				$phone_number_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		// Use literal TTL so VIP cache sniff can evaluate it (>= 300 seconds).
		wp_cache_set( $ckey, $rows, self::GROUP, 300 );

		return $rows;
	}

	/**
	 * Filter callback: Fetch template names list for diffing.
	 *
	 * @param mixed  $names             Filter passthrough.
	 * @param string $user_mailid        User email.
	 * @param string $business_account_id Business account ID.
	 * @param string $phone_number_id    Phone number ID.
	 * @return array Template names.
	 */
	public static function get_template_names( $names, $user_mailid, $business_account_id, $phone_number_id ) {
		global $wpdb;

		$user_mailid         = sanitize_email( (string) $user_mailid );
		$business_account_id = sanitize_text_field( (string) $business_account_id );
		$phone_number_id     = sanitize_text_field( (string) $phone_number_id );

		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id ) {
			return array();
		}

		$ckey = NXTCC_Helpers::ckey(
			'dao:tpl:names',
			array( $user_mailid, $business_account_id, $phone_number_id )
		);

		$hit = wp_cache_get( $ckey, self::GROUP );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		$col = call_user_func(
			array( $wpdb, 'get_col' ),
			$wpdb->prepare(
				'SELECT template_name
				 FROM `' . $wpdb->prefix . 'nxtcc_templates`
				 WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$user_mailid,
				$business_account_id,
				$phone_number_id
			)
		);
		$col = is_array( $col ) ? $col : array();

		$out = array();
		foreach ( $col as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}

		// Use literal TTL so VIP cache sniff can evaluate it (>= 300 seconds).
		wp_cache_set( $ckey, $out, self::GROUP, 300 );

		return $out;
	}

	/**
	 * Action callback: Upsert a template row.
	 *
	 * @param mixed $row Row data array.
	 * @return void
	 */
	public static function upsert_template( $row ): void {
		global $wpdb;

		$user_mailid         = isset( $row['user_mailid'] ) ? sanitize_email( (string) $row['user_mailid'] ) : '';
		$phone_number_id     = isset( $row['phone_number_id'] ) ? sanitize_text_field( (string) $row['phone_number_id'] ) : '';
		$business_account_id = isset( $row['business_account_id'] ) ? sanitize_text_field( (string) $row['business_account_id'] ) : '';
		$template_name       = isset( $row['template_name'] ) ? sanitize_text_field( (string) $row['template_name'] ) : '';

		$category   = isset( $row['category'] ) ? sanitize_text_field( (string) $row['category'] ) : null;
		$language   = isset( $row['language'] ) ? sanitize_text_field( (string) $row['language'] ) : null;
		$status     = isset( $row['status'] ) ? sanitize_text_field( (string) $row['status'] ) : null;
		$components = isset( $row['components'] ) ? (string) $row['components'] : null;
		$created_by = isset( $row['created_by'] ) ? absint( $row['created_by'] ) : 0;
		$updated_by = isset( $row['updated_by'] ) ? absint( $row['updated_by'] ) : 0;

		$now         = current_time( 'mysql', 1 );
		$last_synced = isset( $row['last_synced'] ) ? (string) $row['last_synced'] : $now;
		$created_at  = isset( $row['created_at'] ) ? (string) $row['created_at'] : $now;
		$updated_at  = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : $now;

		if ( '' === $user_mailid || '' === $phone_number_id || '' === $business_account_id || '' === $template_name ) {
			return;
		}

		$table = $wpdb->prefix . 'nxtcc_templates';
		$actor = $updated_by;

		if ( $actor <= 0 ) {
			$actor = $created_by;
		}

		if ( $actor <= 0 && class_exists( 'NXTCC_Actor_Audit' ) ) {
			$actor = NXTCC_Actor_Audit::current_user_id();
		}

		$existing = call_user_func(
			array( $wpdb, 'get_row' ),
			$wpdb->prepare(
				'SELECT id
				 FROM `' . $wpdb->prefix . 'nxtcc_templates`
				 WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND template_name = %s
				 LIMIT 1',
				$user_mailid,
				$business_account_id,
				$phone_number_id,
				$template_name
			),
			ARRAY_A
		);

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$data   = array(
				'category'    => $category,
				'language'    => $language,
				'status'      => $status,
				'components'  => $components,
				'last_synced' => $last_synced,
				'updated_at'  => $updated_at,
			);
			$format = array( '%s', '%s', '%s', '%s', '%s', '%s' );

			if ( $actor > 0 ) {
				$data['updated_by'] = $actor;
				$format[]           = '%d';
			}

			self::db_update(
				$table,
				$data,
				array(
					'id' => absint( $existing['id'] ),
				),
				$format,
				array( '%d' )
			);
		} else {
			$data   = array(
				'user_mailid'         => $user_mailid,
				'phone_number_id'     => $phone_number_id,
				'business_account_id' => $business_account_id,
				'template_name'       => $template_name,
				'category'            => $category,
				'language'            => $language,
				'status'              => $status,
				'components'          => $components,
				'last_synced'         => $last_synced,
				'created_at'          => $created_at,
				'updated_at'          => $updated_at,
			);
			$format = array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			);

			if ( $created_by <= 0 ) {
				$created_by = $actor;
			}
			if ( $updated_by <= 0 ) {
				$updated_by = $actor;
			}

			if ( $created_by > 0 ) {
				$data['created_by'] = $created_by;
				$format[]           = '%d';
			}

			if ( $updated_by > 0 ) {
				$data['updated_by'] = $updated_by;
				$format[]           = '%d';
			}

			self::db_insert( $table, $data, $format );
		}

		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tpl:list', array( $user_mailid, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tpl:names', array( $user_mailid, $business_account_id, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'templates:list', array( $user_mailid, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'templates:names', array( $user_mailid, $business_account_id, $phone_number_id ) ), self::GROUP );
	}

	/**
	 * Action callback: Delete a template row.
	 *
	 * @param mixed $where Where clause array.
	 * @return void
	 */
	public static function delete_template( $where ): void {
		global $wpdb;

		$user_mailid         = isset( $where['user_mailid'] ) ? sanitize_email( (string) $where['user_mailid'] ) : '';
		$business_account_id = isset( $where['business_account_id'] ) ? sanitize_text_field( (string) $where['business_account_id'] ) : '';
		$phone_number_id     = isset( $where['phone_number_id'] ) ? sanitize_text_field( (string) $where['phone_number_id'] ) : '';
		$template_name       = isset( $where['template_name'] ) ? sanitize_text_field( (string) $where['template_name'] ) : '';

		if ( '' === $user_mailid || '' === $business_account_id || '' === $phone_number_id || '' === $template_name ) {
			return;
		}

		$table = $wpdb->prefix . 'nxtcc_templates';

		self::db_delete(
			$table,
			array(
				'user_mailid'         => $user_mailid,
				'business_account_id' => $business_account_id,
				'phone_number_id'     => $phone_number_id,
				'template_name'       => $template_name,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tpl:list', array( $user_mailid, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'dao:tpl:names', array( $user_mailid, $business_account_id, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'templates:list', array( $user_mailid, $phone_number_id ) ), self::GROUP );
		wp_cache_delete( NXTCC_Helpers::ckey( 'templates:names', array( $user_mailid, $business_account_id, $phone_number_id ) ), self::GROUP );
	}
}

NXTCC_DAO::init();
