<?php
/**
 * Admin-side AJAX routes for NXT Cloud Chat.
 *
 * Handles:
 * - Admin connection test.
 * - Media proxy (Graph media download to uploads cache).
 * - Manual sync of verified bindings to contacts.
 * - Auto-sync of verified contacts on OTP verification hook.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX routes (admin-side) + helper utilities.
 */
class NXTCC_Routes {

	/**
	 * Register AJAX endpoints and internal hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_nxtcc_test_api_connection', array( __CLASS__, 'test_api_connection' ) );
		// Prefer the dedicated chat module handler when it is loaded on AJAX requests.
		if ( ! function_exists( 'nxtcc_ajax_media_proxy' ) ) {
			add_action( 'wp_ajax_nxtcc_media_proxy', array( __CLASS__, 'media_proxy' ) );
		}
		add_action( 'wp_ajax_nxtcc_sync_verified_bindings', array( __CLASS__, 'sync_verified_bindings' ) );

		// Current OTP flow fires "nxtcc_otp_verified"; keep legacy slash variant too.
		add_action( 'nxtcc_otp_verified', array( __CLASS__, 'autosync_verified_contact' ), 10, 3 );
		add_action( 'nxtcc/otp_verified', array( __CLASS__, 'autosync_verified_contact' ), 10, 3 );
	}

	/**
	 * Require an admin capability for admin-side routes.
	 *
	 * @param string $cap Capability.
	 * @return void
	 */
	private static function require_cap( string $cap = 'manage_options' ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
	}

	/**
	 * Require a nonce for admin-ajax routes.
	 *
	 * @param string $action Nonce action.
	 * @param string $key    Request key (defaults to "nonce").
	 * @return void
	 */
	private static function require_ajax_nonce( string $action, string $key = 'nonce' ): void {
		// check_ajax_referer() will fail if the nonce is missing OR invalid.
		check_ajax_referer( $action, $key );
	}

	/**
	 * Read a request value from INPUT_POST and sanitize as a string.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function post_text( string $key ): string {
		$raw = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $raw || false === $raw ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $raw ) );
	}

	/**
	 * Read a request value from INPUT_GET and sanitize as a string.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function get_text( string $key ): string {
		$raw = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $raw || false === $raw ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $raw ) );
	}

	/**
	 * Read from POST first, then GET, sanitize as a string.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function request_text( string $key ): string {
		$val = self::post_text( $key );
		if ( '' !== $val ) {
			return $val;
		}
		return self::get_text( $key );
	}

	/**
	 * Perform a safe GET request.
	 *
	 * Uses vip_safe_wp_remote_get() when available. Falls back to wp_safe_remote_get()
	 * for non-VIP environments.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Arguments for the request.
	 * @return array|WP_Error
	 */
	private static function safe_remote_get( string $url, array $args ) {
		$timeout = 3;
		if ( isset( $args['timeout'] ) ) {
			$timeout = (int) $args['timeout'];
		}

		if ( 1 > $timeout ) {
			$timeout = 1;
		}
		if ( 3 < $timeout ) {
			$timeout = 3;
		}

		$args['timeout'] = $timeout;

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, $args );
		}

		// Fallback for WordPress.org / standard hosting.
		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private static function quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = $table;
		}

		return '`' . $clean . '`';
	}

	/**
	 * Resolve + decrypt the WhatsApp access token for a given owner + phone number ID.
	 *
	 * Uses object cache to avoid repeated decrypt work.
	 *
	 * @param string $user_mailid     Tenant owner email.
	 * @param string $phone_number_id WhatsApp phone_number_id.
	 * @return string|null
	 */
	private static function resolve_access_token_for( string $user_mailid, string $phone_number_id ): ?string {
		$db                  = NXTCC_DB::i();
		$table_user_settings = self::quote_table_name( $db->t_user_settings() );

		$cache_key = 'tok:' . md5( $user_mailid . '|' . $phone_number_id );
		$cached    = wp_cache_get( $cache_key, 'nxtcc' );
		if ( false !== $cached ) {
			return '' !== $cached ? (string) $cached : null;
		}

		$row = $db->get_row(
			$db->prepare(
				'SELECT access_token_ct, access_token_nonce
			   FROM ' . $table_user_settings . '
			  WHERE user_mailid = %s AND phone_number_id = %s
		   ORDER BY id DESC LIMIT 1',
				$user_mailid,
				$phone_number_id
			),
			array(),
			ARRAY_A
		);

		if ( ! $row || empty( $row['access_token_ct'] ) || empty( $row['access_token_nonce'] ) ) {
			wp_cache_set( $cache_key, '', 'nxtcc', 300 );
			return null;
		}

		$pt = null;
		if ( class_exists( 'NXTCC_Helpers' ) ) {
			$pt = NXTCC_Helpers::crypto_decrypt( (string) $row['access_token_ct'], (string) $row['access_token_nonce'] );
		} elseif ( function_exists( 'nxtcc_crypto_decrypt' ) ) {
			$pt = nxtcc_crypto_decrypt( (string) $row['access_token_ct'], (string) $row['access_token_nonce'] );
		}

		$token = ( ! is_wp_error( $pt ) && is_string( $pt ) && '' !== $pt ) ? $pt : null;

		wp_cache_set( $cache_key, $token ? $token : '', 'nxtcc', 300 );
		return $token;
	}

	/**
	 * Grab last configured tenant row for an owner (owner + baid + pnid).
	 *
	 * @param string $user_mailid Owner email.
	 * @return object|null
	 */
	private static function latest_tenant_row_for_owner( string $user_mailid ) {
		$db                  = NXTCC_DB::i();
		$table_user_settings = self::quote_table_name( $db->t_user_settings() );

		$cache_key = 'tenant:' . md5( $user_mailid );
		$cached    = wp_cache_get( $cache_key, 'nxtcc' );
		if ( false !== $cached ) {
			return '' !== $cached ? $cached : null;
		}

		$row = $db->get_row(
			$db->prepare(
				'SELECT user_mailid, business_account_id, phone_number_id
			   FROM ' . $table_user_settings . '
			  WHERE user_mailid = %s
		   ORDER BY id DESC LIMIT 1',
				$user_mailid
			),
			array()
		);

		wp_cache_set( $cache_key, $row ? $row : '', 'nxtcc', 300 );
		return $row ? $row : null;
	}

	/**
	 * Get WP_Filesystem instance (or false).
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private static function get_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		global $wp_filesystem;
		return ( isset( $wp_filesystem ) && $wp_filesystem ) ? $wp_filesystem : false;
	}

	/**
	 * Resolve media cache dir & URL under uploads (creates base path if needed).
	 *
	 * @param WP_Filesystem_Base $fs Filesystem handler.
	 * @return array{0:string,1:string}
	 */
	private static function media_cache_dir( $fs ): array {
		$uploads   = wp_upload_dir();
		$base_dir  = trailingslashit( (string) $uploads['basedir'] ) . 'nxtcc/media-proxy';
		$base_url  = trailingslashit( (string) $uploads['baseurl'] ) . 'nxtcc/media-proxy';
		$nxtcc_dir = trailingslashit( (string) $uploads['basedir'] ) . 'nxtcc';

		if ( ! $fs->is_dir( $nxtcc_dir ) ) {
			$fs->mkdir( $nxtcc_dir );
		}
		if ( ! $fs->is_dir( $base_dir ) ) {
			$fs->mkdir( $base_dir );
		}

		return array( $base_dir, $base_url );
	}

	/**
	 * Delete cached files older than a TTL using WP_Filesystem dirlist.
	 *
	 * @param WP_Filesystem_Base $fs  Filesystem handler.
	 * @param string             $dir Cache directory.
	 * @param int                $ttl Time to live in seconds.
	 * @return void
	 */
	private static function media_cache_janitor( $fs, string $dir, int $ttl ): void {
		$list = $fs->dirlist( $dir, false, true );
		if ( ! is_array( $list ) ) {
			return;
		}

		$now = time();
		foreach ( $list as $name => $entry ) {
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			if ( 'f' !== $type ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $name;
			$last = isset( $entry['lastmodunix'] ) ? (int) $entry['lastmodunix'] : $now;

			if ( 0 < $last && $ttl < ( $now - $last ) ) {
				$fs->delete( $path );
			}
		}
	}

	/**
	 * Merge a group ID into a comma-separated list of IDs.
	 *
	 * @param string $csv Existing CSV list.
	 * @param int    $gid Group ID to add.
	 * @return string
	 */
	private static function merge_group_ids( string $csv, int $gid ): string {
		$parts = array_filter( array_map( 'trim', explode( ',', $csv ) ) );

		$ints = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$ints[ (int) $p ] = true;
		}

		$ints[ (int) $gid ] = true;

		return implode( ',', array_keys( $ints ) );
	}

	/**
	 * Ensure a "Verified" group exists for an owner+tenant and return its ID.
	 *
	 * @param string $owner_mailid Owner email.
	 * @param string $baid         Business account ID.
	 * @param string $pnid         Phone number ID.
	 * @return int
	 */
	private static function ensure_verified_group_id( string $owner_mailid, string $baid, string $pnid ): int {
		$db           = NXTCC_DB::i();
		$table_groups = self::quote_table_name( $db->t_groups() );

		$cache_key = 'grp_verified:' . md5( $owner_mailid . '|' . $baid . '|' . $pnid );
		$cached    = wp_cache_get( $cache_key, 'nxtcc' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$group = $db->get_row(
			$db->prepare(
				'SELECT id FROM ' . $table_groups . ' WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND group_name = %s AND is_verified = %d LIMIT 1',
				$owner_mailid,
				$baid,
				$pnid,
				'Verified',
				1
			),
			array()
		);

		if ( $group ) {
			$gid = (int) $group->id;
			wp_cache_set( $cache_key, $gid, 'nxtcc', 300 );
			return $gid;
		}

		$db->insert(
			$db->t_groups(),
			array(
				'user_mailid'         => $owner_mailid,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'group_name'          => 'Verified',
				'is_verified'         => 1,
			)
		);

		$gid = (int) $db->insert_id();
		wp_cache_set( $cache_key, $gid, 'nxtcc', 300 );
		return $gid;
	}

	/**
	 * AJAX: Validate API connection for admin settings.
	 *
	 * @return void
	 */
	public static function test_api_connection(): void {
		self::require_ajax_nonce( 'nxtcc_admin', 'nonce' );
		self::require_cap( 'manage_options' );

		$app_id              = self::post_text( 'app_id' );
		$access_token        = self::post_text( 'access_token' );
		$business_account_id = self::post_text( 'business_account_id' );
		$phone_number_id     = self::post_text( 'phone_number_id' );

		if ( ! class_exists( 'NXTCC_API_Connection' ) ) {
			$api_connection_file = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-api-connection.php';
			if ( file_exists( $api_connection_file ) ) {
				require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-api-connection.php';
			}
		}

		if ( '' === $app_id || '' === $access_token || '' === $business_account_id || '' === $phone_number_id ) {
			wp_send_json_error( array( 'message' => 'Missing credentials' ), 400 );
		}

		if ( class_exists( 'NXTCC_API_Connection' ) && method_exists( 'NXTCC_API_Connection', 'check_all_connections' ) ) {
			$res = NXTCC_API_Connection::check_all_connections(
				$app_id,
				$access_token,
				$business_account_id,
				$phone_number_id,
				'',
				'',
				'en_US'
			);

			$ok = true;
			if ( is_array( $res ) ) {
				foreach ( $res as $item ) {
					if ( is_array( $item ) && array_key_exists( 'success', $item ) && empty( $item['success'] ) ) {
						$ok = false;
						break;
					}
				}
			} else {
				$ok = (bool) $res;
			}

			wp_send_json_success(
				array(
					'connection_valid' => $ok,
					'results'          => $res,
				)
			);
		}

		wp_send_json_error( array( 'message' => 'No validation method available' ), 500 );
	}

	/**
	 * AJAX: Media proxy for WhatsApp media messages.
	 *
	 * @return void
	 */
	public static function media_proxy(): void {
		// This is admin-side; ensure capability + nonce. Do not allow anonymous requests.
		self::require_ajax_nonce( 'nxtcc_received_messages', 'nonce' );
		self::require_cap( 'manage_options' );

		$mid  = self::request_text( 'mid' );
		$pnid = self::request_text( 'pnid' );

		if ( '' === $mid || '' === $pnid ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Missing parameters', 'nxt-cloud-chat' ) ),
				400
			);
		}

		$user        = wp_get_current_user();
		$user_mailid = (string) $user->user_email;

		if ( '' === $user_mailid ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Unauthorized', 'nxt-cloud-chat' ) ),
				403
			);
		}

		$token = self::resolve_access_token_for( $user_mailid, $pnid );
		if ( null === $token ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Access token not found', 'nxt-cloud-chat' ) ),
				403
			);
		}

		$meta = self::safe_remote_get(
			'https://graph.facebook.com/v19.0/' . rawurlencode( $mid ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $meta ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Meta request failed', 'nxt-cloud-chat' ) ),
				502
			);
		}

		$info      = json_decode( (string) wp_remote_retrieve_body( $meta ), true );
		$media_url = isset( $info['url'] ) ? (string) $info['url'] : '';

		if ( '' === $media_url ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Media URL not available', 'nxt-cloud-chat' ) ),
				404
			);
		}

		$bin = self::safe_remote_get(
			$media_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $bin ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Binary fetch failed', 'nxt-cloud-chat' ) ),
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $bin );
		$body = (string) wp_remote_retrieve_body( $bin );

		if ( 200 > $code || 300 <= $code || '' === $body ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Binary fetch error', 'nxt-cloud-chat' ),
					'status'  => $code,
				),
				502
			);
		}

		$fs = self::get_filesystem();
		if ( ! $fs ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Filesystem unavailable', 'nxt-cloud-chat' ) ),
				500
			);
		}

		list( $cache_dir, $cache_url ) = self::media_cache_dir( $fs );
		self::media_cache_janitor( $fs, $cache_dir, 15 * MINUTE_IN_SECONDS );

		$fname = 'mid-' . md5( $mid . '|' . $pnid . '|' . microtime( true ) ) . '-' . wp_generate_password( 20, false ) . '.bin';
		$fpath = trailingslashit( $cache_dir ) . $fname;

		if ( ! $fs->put_contents( $fpath, $body, FS_CHMOD_FILE ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Could not save media', 'nxt-cloud-chat' ) ),
				500
			);
		}

		$download_name = sanitize_file_name( $mid );

		header( 'X-Content-Type-Options: nosniff' );
		wp_safe_redirect(
			trailingslashit( $cache_url ) . rawurlencode( $fname ) . '#name=' . rawurlencode( $download_name ),
			302
		);
		exit;
	}

	/**
	 * AJAX: Manual sync of verified bindings into the tenant contacts table.
	 *
	 * @return void
	 */
	public static function sync_verified_bindings(): void {
		self::require_ajax_nonce( 'nxtcc_auth_admin', 'nonce' );
		self::require_cap( 'manage_options' );

		$db = NXTCC_DB::i();

		$user        = wp_get_current_user();
		$user_mailid = (string) $user->user_email;

		$settings = self::latest_tenant_row_for_owner( $user_mailid );

		if ( ! $settings || empty( $settings->business_account_id ) || empty( $settings->phone_number_id ) ) {
			wp_send_json_error( array( 'message' => 'Connection not configured for this admin' ), 400 );
		}

		$owner_mailid = (string) $settings->user_mailid;
		$baid         = (string) $settings->business_account_id;
		$pnid         = (string) $settings->phone_number_id;

		$contacts_table      = $db->t_contacts();
		$map_table           = $db->t_group_contact_map();
		$table_auth_bindings = self::quote_table_name( $db->t_auth_bindings() );
		$table_contacts      = self::quote_table_name( $db->t_contacts() );
		$table_group_map     = self::quote_table_name( $db->t_group_contact_map() );

		$verified_gid = self::ensure_verified_group_id( $owner_mailid, $baid, $pnid );

		$query_bindings = 'SELECT user_id, phone_e164 FROM ' . $table_auth_bindings . ' WHERE verified_at IS NOT NULL';

		$bindings = $db->get_results(
			$query_bindings,
			array(),
			ARRAY_A
		);

		$inserted = 0;
		$skipped  = 0;
		$updated  = 0;

		foreach ( $bindings as $b ) {
			$uid  = isset( $b['user_id'] ) ? (int) $b['user_id'] : 0;
			$e164 = isset( $b['phone_e164'] ) ? (string) $b['phone_e164'] : '';

			if ( 0 >= $uid || '' === $e164 ) {
				++$skipped;
				continue;
			}

			if ( ! function_exists( 'nxtcc_split_msisdn' ) ) {
				++$skipped;
				continue;
			}

			list( $cc, $local ) = nxtcc_split_msisdn( $e164 );

			$cc    = preg_replace( '/\D+/', '', (string) $cc );
			$local = preg_replace( '/\D+/', '', (string) $local );

			if ( '' === $local ) {
				++$skipped;
				continue;
			}

			$ud = get_userdata( $uid );
			if ( ! $ud ) {
				++$skipped;
				continue;
			}

			$first = get_user_meta( $uid, 'first_name', true );
			$last  = get_user_meta( $uid, 'last_name', true );

			$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
			if ( '' === $name ) {
				$name = ! empty( $ud->user_nicename ) ? (string) $ud->user_nicename : (string) $ud->user_login;
			}

			$existing = $db->get_row(
				$db->prepare(
					'SELECT id, wp_uid, group_ids FROM ' . $table_contacts . '
                  WHERE user_mailid = %s
                    AND business_account_id = %s AND phone_number_id = %s
                    AND country_code = %s AND phone_number = %s
                  LIMIT 1',
					$owner_mailid,
					$baid,
					$pnid,
					$cc,
					$local
				),
				array(),
				ARRAY_A
			);

			if ( $existing ) {
				$update = array();

				if ( empty( $existing['wp_uid'] ) ) {
					$update['wp_uid'] = (int) $uid;
				}

				$existing_group_ids = isset( $existing['group_ids'] ) ? (string) $existing['group_ids'] : '';
				$new_csv            = self::merge_group_ids( $existing_group_ids, $verified_gid );

				if ( $new_csv !== $existing_group_ids ) {
					$update['group_ids'] = $new_csv;
				}

				$update['is_verified'] = 1;

				if ( ! empty( $update ) ) {
					$update['updated_at'] = current_time( 'mysql', 1 );
					$db->update( $contacts_table, $update, array( 'id' => (int) $existing['id'] ) );
					++$updated;
				}

				$exists_map = (int) $db->get_var(
					$db->prepare(
						'SELECT COUNT(*) FROM ' . $table_group_map . ' WHERE group_id = %d AND contact_id = %d',
						$verified_gid,
						(int) $existing['id']
					),
					array()
				);

				if ( 0 === $exists_map ) {
					$db->insert(
						$map_table,
						array(
							'group_id'   => $verified_gid,
							'contact_id' => (int) $existing['id'],
						)
					);
				}

				continue;
			}

			$db->insert(
				$contacts_table,
				array(
					'user_mailid'         => $owner_mailid,
					'business_account_id' => $baid,
					'phone_number_id'     => $pnid,
					'name'                => $name,
					'country_code'        => $cc,
					'phone_number'        => $local,
					'wp_uid'              => (int) $uid,
					'group_ids'           => (string) $verified_gid,
					'is_verified'         => 1,
					'custom_fields'       => wp_json_encode( array() ),
					'created_at'          => current_time( 'mysql', 1 ),
					'updated_at'          => current_time( 'mysql', 1 ),
				)
			);

			$contact_id = (int) $db->insert_id();
			if ( 0 < $contact_id ) {
				$db->insert(
					$map_table,
					array(
						'group_id'   => $verified_gid,
						'contact_id' => $contact_id,
					)
				);
				++$inserted;
			} else {
				++$skipped;
			}
		}

		wp_send_json_success(
			array(
				'inserted' => $inserted,
				'updated'  => $updated,
				'skipped'  => $skipped,
				'group_id' => (int) $verified_gid,
			)
		);
	}

	/**
	 * Auto-sync verified contact on OTP verification.
	 *
	 * @param int   $user_id    WordPress user ID.
	 * @param mixed $phone_e164 Verified phone number (E.164).
	 * @param mixed $ctx        Context array/object from OTP verification.
	 * @return void
	 */
	public static function autosync_verified_contact( $user_id, $phone_e164, $ctx = array() ): void {
		$opts = get_option( 'nxtcc_auth_options', array() );

		$auto = 0;
		if ( is_array( $opts ) && ( ! empty( $opts['auto_sync'] ) || ! empty( $opts['add_verified_to_contacts'] ) ) ) {
			$auto = 1;
		}
		if ( 0 === $auto ) {
			return;
		}

		$db                  = NXTCC_DB::i();
		$table_user_settings = self::quote_table_name( $db->t_user_settings() );

		$baid       = '';
		$pnid       = '';
		$owner_mail = '';

		if ( is_array( $ctx ) ) {
			$baid       = isset( $ctx['business_account_id'] ) ? (string) $ctx['business_account_id'] : '';
			$pnid       = isset( $ctx['phone_number_id'] ) ? (string) $ctx['phone_number_id'] : '';
			$owner_mail = isset( $ctx['connection_owner'] ) ? (string) $ctx['connection_owner'] : '';
		} elseif ( is_object( $ctx ) ) {
			$baid       = isset( $ctx->business_account_id ) ? (string) $ctx->business_account_id : '';
			$pnid       = isset( $ctx->phone_number_id ) ? (string) $ctx->phone_number_id : '';
			$owner_mail = isset( $ctx->connection_owner ) ? (string) $ctx->connection_owner : '';
		}

		if ( '' === $baid || '' === $pnid || '' === $owner_mail ) {
			$query_latest = 'SELECT user_mailid, business_account_id, phone_number_id
                   FROM ' . $table_user_settings . '
               ORDER BY id DESC LIMIT 1';

			$row = $db->get_row(
				$query_latest
			);

			if ( $row ) {
				if ( '' === $baid ) {
					$baid = (string) $row->business_account_id;
				}
				if ( '' === $pnid ) {
					$pnid = (string) $row->phone_number_id;
				}
				if ( '' === $owner_mail ) {
					$owner_mail = (string) $row->user_mailid;
				}
			}
		}

		if ( '' === $baid || '' === $pnid || '' === $owner_mail ) {
			return;
		}

		$ud = get_userdata( (int) $user_id );
		if ( ! $ud ) {
			return;
		}

		if ( ! function_exists( 'nxtcc_split_msisdn' ) ) {
			return;
		}

		$first = get_user_meta( $user_id, 'first_name', true );
		$last  = get_user_meta( $user_id, 'last_name', true );

		$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
		if ( '' === $name ) {
			$name = ! empty( $ud->user_nicename ) ? (string) $ud->user_nicename : (string) $ud->user_login;
		}

		list( $cc, $local ) = nxtcc_split_msisdn( (string) $phone_e164 );

		$cc    = preg_replace( '/\D+/', '', (string) $cc );
		$local = preg_replace( '/\D+/', '', (string) $local );

		if ( '' === $local ) {
			return;
		}

		$verified_gid = self::ensure_verified_group_id( $owner_mail, $baid, $pnid );

		$contacts_table  = $db->t_contacts();
		$map_table       = $db->t_group_contact_map();
		$table_contacts  = self::quote_table_name( $db->t_contacts() );
		$table_group_map = self::quote_table_name( $db->t_group_contact_map() );

		$existing = $db->get_row(
			$db->prepare(
				'SELECT id, wp_uid, group_ids FROM ' . $table_contacts . '
              WHERE user_mailid = %s
                AND business_account_id = %s AND phone_number_id = %s
                AND country_code = %s AND phone_number = %s
              LIMIT 1',
				$owner_mail,
				$baid,
				$pnid,
				$cc,
				$local
			),
			array(),
			ARRAY_A
		);

		if ( $existing ) {
			$update = array();

			if ( empty( $existing['wp_uid'] ) ) {
				$update['wp_uid'] = (int) $user_id;
			}

			$existing_group_ids = isset( $existing['group_ids'] ) ? (string) $existing['group_ids'] : '';
			$new_csv            = self::merge_group_ids( $existing_group_ids, $verified_gid );

			if ( $new_csv !== $existing_group_ids ) {
				$update['group_ids'] = $new_csv;
			}

			$update['is_verified'] = 1;

			if ( ! empty( $update ) ) {
				$update['updated_at'] = current_time( 'mysql', 1 );
				$db->update( $contacts_table, $update, array( 'id' => (int) $existing['id'] ) );
			}

			$exists_map = (int) $db->get_var(
				$db->prepare(
					'SELECT COUNT(*) FROM ' . $table_group_map . ' WHERE group_id = %d AND contact_id = %d',
					$verified_gid,
					(int) $existing['id']
				),
				array()
			);

			if ( 0 === $exists_map ) {
				$db->insert(
					$map_table,
					array(
						'group_id'   => $verified_gid,
						'contact_id' => (int) $existing['id'],
					)
				);
			}

			return;
		}

		$db->insert(
			$contacts_table,
			array(
				'user_mailid'         => $owner_mail,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'name'                => $name,
				'country_code'        => $cc,
				'phone_number'        => $local,
				'wp_uid'              => (int) $user_id,
				'group_ids'           => (string) $verified_gid,
				'is_verified'         => 1,
				'custom_fields'       => wp_json_encode( array() ),
				'created_at'          => current_time( 'mysql', 1 ),
				'updated_at'          => current_time( 'mysql', 1 ),
			)
		);

		$cid = (int) $db->insert_id();
		if ( 0 < $cid ) {
			$db->insert(
				$map_table,
				array(
					'group_id'   => $verified_gid,
					'contact_id' => $cid,
				)
			);
		}
	}
}
