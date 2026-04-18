<?php
/**
 * Chat repository (DB access layer).
 *
 * All SQL and $wpdb access for chat UI and webhook-adjacent helpers live here.
 * Controllers/AJAX handlers call this repository rather than $wpdb directly.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository for chat-related reads/writes.
 */
final class NXTCC_Chat_Handler_Repo {

	/**
	 * Cache group used for chat repo lookups.
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'nxtcc_chat';

	/**
	 * Minimum TTL allowed for persistent object cache writes (VIP requirement).
	 *
	 * @var int
	 */
	private const CACHE_TTL_MIN = 300;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * DB table prefix.
	 *
	 * @var string
	 */
	private string $prefix = '';

	/**
	 * Request-local cache for boolean lookups (avoids tiny TTLs in wp_cache_set()).
	 *
	 * @var array<string, bool>
	 */
	private array $bool_runtime_cache = array();

	/**
	 * Request-local cache for short-lived non-boolean lookups.
	 *
	 * @var array<string, mixed>
	 */
	private array $runtime_cache = array();

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->prefix = (string) $wpdb->prefix;
	}

	/**
	 * Get wpdb instance.
	 *
	 * @return wpdb
	 */
	private function db(): wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Build a fully-qualified table name.
	 *
	 * @param string $name Table suffix without prefix.
	 * @return string
	 */
	private function table( string $name ): string {
		return $this->prefix . $name;
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = $table;
		}
		return '`' . $clean . '`';
	}

	/**
	 * Prepare SQL that contains table tokens like {history}, {contacts}, {settings}.
	 *
	 * Table identifiers are swapped after wpdb::prepare() using sentinels so value
	 * placeholder ordering remains unchanged.
	 *
	 * @param string $query     SQL with table tokens.
	 * @param array  $table_map Token => quoted table name.
	 * @param array  $args      Value placeholder args.
	 * @return string Prepared SQL or empty string on failure.
	 */
	private function prepare_with_table_tokens( string $query, array $table_map, array $args = array() ): string {
		$search  = array();
		$replace = array();

		foreach ( $table_map as $token => $table_sql ) {
			$marker = '{' . (string) $token . '}';
			if ( false === strpos( $query, $marker ) ) {
				continue;
			}

			$clean_token = preg_replace( '/[^A-Za-z0-9_]+/', '_', (string) $token );
			if ( ! is_string( $clean_token ) || '' === $clean_token ) {
				$clean_token = 'TABLE';
			}

			$sentinel = '__NXTCC_TABLE_' . strtoupper( (string) $clean_token ) . '__';

			$query = str_replace( $marker, "'" . $sentinel . "'", $query );

			$search[]  = "'" . $sentinel . "'";
			$replace[] = (string) $table_sql;
			$search[]  = $sentinel;
			$replace[] = (string) $table_sql;
		}

		if ( empty( $args ) ) {
			$prepared = $query;
		} else {
			$prepared = $this->db()->prepare( $query, ...$args );
		}

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return '';
		}

		if ( empty( $search ) ) {
			return $prepared;
		}

		return str_replace( $search, $replace, $prepared );
	}

	/**
	 * Clamp a LIMIT-like number.
	 *
	 * @param int $limit Requested limit.
	 * @param int $min   Minimum.
	 * @param int $max   Maximum.
	 * @param int $def   Default when invalid.
	 * @return int
	 */
	private function clamp_int( int $limit, int $min, int $max, int $def ): int {
		if ( $limit < $min ) {
			return $def;
		}
		if ( $limit > $max ) {
			return $max;
		}
		return $limit;
	}

	/**
	 * Cache a boolean without colliding with wp_cache_get() "false means miss".
	 *
	 * VIP/PHPCS requires a literal cache TTL >= 300 seconds.
	 *
	 * @param string $key Cache key.
	 * @param bool   $val Value.
	 * @param int    $ttl TTL seconds (bucketed).
	 * @return void
	 */
	private function cache_set_bool( string $key, bool $val, int $ttl ): void {
		$ttl = (int) $ttl;

		// 15 minutes.
		if ( $ttl >= 900 ) {
			wp_cache_set( $key, array( 'v' => $val ? 1 : 0 ), self::CACHE_GROUP, 900 );
			return;
		}

		// 10 minutes.
		if ( $ttl >= 600 ) {
			wp_cache_set( $key, array( 'v' => $val ? 1 : 0 ), self::CACHE_GROUP, 600 );
			return;
		}

		// Minimum allowed: 5 minutes (300s).
		wp_cache_set( $key, array( 'v' => $val ? 1 : 0 ), self::CACHE_GROUP, 300 );
	}

	/**
	 * Read a cached boolean. Returns null if not set.
	 *
	 * @param string $key Cache key.
	 * @return bool|null
	 */
	private function cache_get_bool( string $key ): ?bool {
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
			return ( 1 === (int) $cached['v'] );
		}

		return null;
	}

	/**
	 * Runtime-only set for very short-lived boolean caching.
	 *
	 * @param string $key Cache key.
	 * @param bool   $val Value.
	 * @return void
	 */
	private function runtime_set_bool( string $key, bool $val ): void {
		$this->bool_runtime_cache[ $key ] = (bool) $val;
	}

	/**
	 * Runtime-only get for very short-lived boolean caching.
	 *
	 * @param string $key Cache key.
	 * @return bool|null
	 */
	private function runtime_get_bool( string $key ): ?bool {
		if ( array_key_exists( $key, $this->bool_runtime_cache ) ) {
			return (bool) $this->bool_runtime_cache[ $key ];
		}
		return null;
	}

	/**
	 * Runtime-only set for short-lived non-boolean cache values.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Cached value.
	 * @return void
	 */
	private function runtime_set( string $key, $value ): void {
		$this->runtime_cache[ $key ] = $value;
	}

	/**
	 * Runtime-only get for short-lived non-boolean cache values.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null
	 */
	private function runtime_get( string $key ) {
		if ( array_key_exists( $key, $this->runtime_cache ) ) {
			return $this->runtime_cache[ $key ];
		}

		return null;
	}

	/**
	 * Runtime-only check for cache key existence.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	private function runtime_has( string $key ): bool {
		return array_key_exists( $key, $this->runtime_cache );
	}

	/**
	 * Runtime-only delete by exact key.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	private function runtime_delete( string $key ): void {
		unset( $this->runtime_cache[ $key ] );
	}

	/**
	 * Runtime-only delete by key prefix.
	 *
	 * @param string $prefix Cache key prefix.
	 * @return void
	 */
	private function runtime_delete_prefix( string $prefix ): void {
		foreach ( array_keys( $this->runtime_cache ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $this->runtime_cache[ $key ] );
			}
		}
	}

	/**
	 * Best-effort cache busting for frequently changing UI bits.
	 *
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @param int    $contact_id      Contact id.
	 * @param array  $message_ids     Optional message ids.
	 * @return void
	 */
	private function bust_hot_caches(
		string $user_mailid,
		string $phone_number_id,
		int $contact_id = 0,
		array $message_ids = array()
	): void {
		// Inbox summary cache.
		$inbox_key = 'inbox_summary:' . md5( $user_mailid . '|' . $phone_number_id );
		wp_cache_delete( $inbox_key, self::CACHE_GROUP );
		$this->runtime_delete( $inbox_key );

		// Last incoming cache.
		if ( $contact_id > 0 ) {
			$last_incoming_key = 'last_incoming:' . (string) $contact_id . ':' . md5( $user_mailid );
			wp_cache_delete( $last_incoming_key, self::CACHE_GROUP );
			$this->runtime_delete( $last_incoming_key );
		}

		// Favorite row caches.
		if ( ! empty( $message_ids ) ) {
			foreach ( $message_ids as $mid ) {
				$mid = (int) $mid;
				if ( $mid > 0 ) {
					$fav_key = 'favorite_row:' . (string) $mid . ':' . md5( $user_mailid . '|' . $phone_number_id );
					wp_cache_delete(
						$fav_key,
						self::CACHE_GROUP
					);
					$this->runtime_delete( $fav_key );
				}
			}
		}

		// Thread keys are request-local for short-lived freshness.
		$this->runtime_delete_prefix( 'thread:' );

		// We can't reliably delete all possible thread:* keys without a key registry,
		// but invalidating inbox + last_incoming covers the "list + reply window" UX.
	}

	/**
	 * Build IN() placeholders for prepare().
	 *
	 * @param int    $count Number of placeholders.
	 * @param string $type Placeholder type: '%d' or '%s'.
	 * @return string
	 */
	private function in_placeholders( int $count, string $type ): string {
		$count = max( 0, (int) $count );
		if ( 0 === $count ) {
			return '';
		}
		return implode( ',', array_fill( 0, $count, $type ) );
	}

	/* ========================= TENANT / USER PHONE SETTINGS ========================= */

	/**
	 * Resolve a user's phone_number_id.
	 *
	 * Rules:
	 * - If a requested PNID is provided, it must belong to the user.
	 * - Otherwise, return the latest PNID from nxtcc_user_settings.
	 *
	 * @param string $user_mailid    User email.
	 * @param string $requested_pnid PNID requested by UI (optional).
	 * @return string Phone number id or empty string.
	 */
	public function get_user_phone_number_id( string $user_mailid, string $requested_pnid ): string {
		$user_mailid    = (string) $user_mailid;
		$requested_pnid = (string) $requested_pnid;

		$db         = $this->db();
		$t_settings = $this->quote_table( $this->table( 'nxtcc_user_settings' ) );
		$table_map  = array(
			'settings' => $t_settings,
		);

		// Validate requested PNID belongs to the user.
		if ( '' !== $requested_pnid ) {
			$cache_key = 'user_pnid_valid:' . md5( $user_mailid . '|' . $requested_pnid );
			$cached    = $this->cache_get_bool( $cache_key );

			if ( null === $cached ) {
				$query = $this->prepare_with_table_tokens(
					'SELECT 1
					 FROM {settings}
					 WHERE user_mailid = %s
					   AND phone_number_id = %s
					 LIMIT 1',
					$table_map,
					array( $user_mailid, $requested_pnid )
				);
				if ( '' === $query ) {
					return '';
				}

				$val   = $db->get_var( $query );
				$valid = ! empty( $val );

				// Cache for 10 minutes.
				$this->cache_set_bool( $cache_key, $valid, 600 );
				$cached = $valid;
			}

			if ( true === $cached ) {
				return $requested_pnid;
			}
		}

		// Fallback: latest PNID for the user.
		$cache_key = 'user_default_pnid:' . md5( $user_mailid );
		$pnid      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $pnid ) {
			$query = $this->prepare_with_table_tokens(
				'SELECT phone_number_id
				 FROM {settings}
				 WHERE user_mailid = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$table_map,
				array( $user_mailid )
			);
			if ( '' === $query ) {
				return '';
			}

			$row  = $db->get_row( $query );
			$pnid = ( $row && ! empty( $row->phone_number_id ) ) ? (string) $row->phone_number_id : '';

			// Cache for 10 minutes.
			wp_cache_set( $cache_key, $pnid, self::CACHE_GROUP, 600 );
		}

		return (string) $pnid;
	}

	/**
	 * Lookup tenant settings by phone_number_id.
	 *
	 * Used by webhook + proxy flows to resolve user_mailid and business account id.
	 *
	 * @param string $phone_number_id Phone number id.
	 * @return object|null Settings row or null.
	 */
	public function get_tenant_settings_by_phone_number_id( string $phone_number_id ): ?object {
		$phone_number_id = (string) $phone_number_id;

		if ( '' === $phone_number_id ) {
			return null;
		}

		$db         = $this->db();
		$t_settings = $this->quote_table( $this->table( 'nxtcc_user_settings' ) );
		$table_map  = array(
			'settings' => $t_settings,
		);
		$cache_key  = 'tenant_by_pnid:' . md5( $phone_number_id );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return ( $cached instanceof stdClass ) ? $cached : null;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT user_mailid, business_account_id, phone_number_id
			 FROM {settings}
			 WHERE phone_number_id = %s
			 ORDER BY id DESC
			 LIMIT 1',
			$table_map,
			array( $phone_number_id )
		);
		if ( '' === $query ) {
			return null;
		}

		$row = $db->get_row( $query );
		$row = ( $row instanceof stdClass ) ? $row : null;

		// Cache for 10 minutes.
		wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 600 );

		return $row;
	}

	/* ========================= POLLING HELPERS ========================= */

	/**
	 * Fast check: does this thread have any messages newer than $after_id?
	 *
	 * Designed for polling loops.
	 *
	 * @param int    $contact_id      Contact id.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @param int    $after_id        Last seen history id.
	 * @return bool True if at least one message exists with id > $after_id.
	 */
	public function has_new_messages_after(
		int $contact_id,
		string $user_mailid,
		string $phone_number_id,
		int $after_id
	): bool {
		$contact_id      = (int) $contact_id;
		$user_mailid     = (string) $user_mailid;
		$phone_number_id = (string) $phone_number_id;
		$after_id        = (int) $after_id;

		if ( $contact_id <= 0 || '' === $user_mailid || '' === $phone_number_id || $after_id <= 0 ) {
			return false;
		}

		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);
		$table_map = array(
			'history' => $t_h,
		);

		$cache_key = 'has_new_after:' . md5(
			(string) $contact_id . '|' . $user_mailid . '|' . $phone_number_id . '|' . (string) $after_id
		);

		// Very short-lived cache: runtime only (avoid wp_cache_set TTL < 300).
		$cached = $this->runtime_get_bool( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT 1
			 FROM {history}
			 WHERE contact_id = %d
			   AND user_mailid = %s
			   AND phone_number_id = %s
			   AND deleted_at IS NULL
			   AND id > %d
			 LIMIT 1',
			$table_map,
			array( $contact_id, $user_mailid, $phone_number_id, $after_id )
		);
		if ( '' === $query ) {
			return false;
		}

		$val = $db->get_var( $query );
		$has = ! empty( $val );

		// Dampen bursts within the same request.
		$this->runtime_set_bool( $cache_key, $has );

		return $has;
	}

	/* ========================= INBOX SUMMARY ========================= */

	/**
	 * Get inbox summary rows for a user + phone number id.
	 *
	 * Returns each contact with last message preview + unread count.
	 *
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @return array Rows.
	 */
	public function get_inbox_summary_rows( string $user_mailid, string $phone_number_id ): array {
		$db        = $this->db();
		$t_c       = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'contacts' => $t_c,
			'history'  => $t_h,
		);

		$cache_key = 'inbox_summary:' . md5( $user_mailid . '|' . $phone_number_id );

		if ( $this->runtime_has( $cache_key ) ) {
			$cached = $this->runtime_get( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$query = $this->prepare_with_table_tokens(
			"SELECT
				c.id AS contact_id,
				c.name,
				c.country_code,
				c.phone_number,
				m.id AS last_msg_id,
				m.message_content AS message_preview,
				m.response_json AS message_preview_json,
				m.created_at AS last_msg_time,
				m.status,
				(
					SELECT COUNT(*) FROM {history} im
					 WHERE im.contact_id = c.id
					   AND im.status = 'received'
					   AND im.is_read = 0
					   AND im.deleted_at IS NULL
					   AND im.user_mailid = %s
					   AND im.phone_number_id = %s
				) AS unread_count
			 FROM {contacts} c
			 LEFT JOIN {history} m ON m.id = (
				SELECT MAX(id) FROM {history}
				 WHERE contact_id = c.id
				   AND deleted_at IS NULL
				   AND user_mailid = %s
				   AND phone_number_id = %s
			 )
			 WHERE c.user_mailid = %s
			   AND m.id IS NOT NULL
			 ORDER BY m.created_at DESC",
			$table_map,
			array(
				$user_mailid,
				$phone_number_id,
				$user_mailid,
				$phone_number_id,
				$user_mailid,
			)
		);
		if ( '' === $query ) {
			return array();
		}

		$rows = $db->get_results( $query );
		$rows = $rows ? $rows : array();

		// Request-local cache only (avoid persistent cache TTL < 300).
		$this->runtime_set( $cache_key, $rows );

		return $rows;
	}

	/* ========================= CHAT THREAD ========================= */

	/**
	 * Get messages for a chat thread.
	 *
	 * Supports paging by after_id / before_id.
	 *
	 * NOTE: We do NOT cache "after_id" polling queries because caching can
	 * delay new message delivery in the UI.
	 *
	 * @param int      $contact_id      Contact id.
	 * @param string   $user_mailid     User email.
	 * @param string   $phone_number_id Phone number id.
	 * @param int|null $after_id        Fetch messages after this id (ASC).
	 * @param int|null $before_id       Fetch messages before this id (DESC).
	 * @param int      $limit           Max rows.
	 * @return array Rows.
	 */
	public function get_chat_thread_messages(
		int $contact_id,
		string $user_mailid,
		string $phone_number_id,
		?int $after_id,
		?int $before_id,
		int $limit
	): array {
		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$contact_id      = (int) $contact_id;
		$user_mailid     = (string) $user_mailid;
		$phone_number_id = (string) $phone_number_id;

		$limit = $this->clamp_int( (int) $limit, 1, 200, 20 );

		$is_polling = ( null !== $after_id && (int) $after_id > 0 );

		$cache_key = '';
		if ( ! $is_polling ) {
			$cache_key = implode(
				':',
				array(
					'thread',
					(string) $contact_id,
					md5( $user_mailid ),
					$phone_number_id,
					(string) (int) $after_id,
					(string) (int) $before_id,
					(string) $limit,
				)
			);

			if ( $this->runtime_has( $cache_key ) ) {
				$cached = $this->runtime_get( $cache_key );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}
		}

		if ( null !== $after_id && (int) $after_id > 0 ) {
			$query = $this->prepare_with_table_tokens(
				'SELECT id, contact_id, message_content, status, created_at, is_read, is_favorite,
						meta_message_id, reply_to_history_id, reply_to_wamid, response_json
				 FROM {history}
				 WHERE contact_id = %d
				   AND user_mailid = %s
				   AND phone_number_id = %s
				   AND deleted_at IS NULL
				   AND id > %d
				 ORDER BY id ASC',
				$table_map,
				array( $contact_id, $user_mailid, $phone_number_id, (int) $after_id )
			);
			if ( '' === $query ) {
				return array();
			}

			$rows = $db->get_results( $query );
		} elseif ( null !== $before_id && (int) $before_id > 0 ) {
			$query = $this->prepare_with_table_tokens(
				'SELECT id, contact_id, message_content, status, created_at, is_read, is_favorite,
						meta_message_id, reply_to_history_id, reply_to_wamid, response_json
				 FROM {history}
				 WHERE contact_id = %d
				   AND user_mailid = %s
				   AND phone_number_id = %s
				   AND deleted_at IS NULL
				   AND id < %d
				 ORDER BY id DESC
				 LIMIT %d',
				$table_map,
				array( $contact_id, $user_mailid, $phone_number_id, (int) $before_id, $limit )
			);
			if ( '' === $query ) {
				return array();
			}

			$rows = $db->get_results( $query );
		} else {
			$query = $this->prepare_with_table_tokens(
				'SELECT id, contact_id, message_content, status, created_at, is_read, is_favorite,
						meta_message_id, reply_to_history_id, reply_to_wamid, response_json
				 FROM {history}
				 WHERE contact_id = %d
				   AND user_mailid = %s
				   AND phone_number_id = %s
				   AND deleted_at IS NULL
				 ORDER BY id DESC
				 LIMIT %d',
				$table_map,
				array( $contact_id, $user_mailid, $phone_number_id, $limit )
			);
			if ( '' === $query ) {
				return array();
			}

			$rows = $db->get_results( $query );
		}

		$rows = $rows ? $rows : array();

		if ( ! $is_polling && '' !== $cache_key ) {
			$this->runtime_set( $cache_key, $rows );
		}

		return $rows;
	}

	/* ========================= REPLIES ========================= */

	/**
	 * Get reply source rows by message history IDs.
	 *
	 * @param array $ids History ids.
	 * @return array Rows.
	 */
	public function get_reply_rows_by_ids( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$placeholders = $this->in_placeholders( count( $ids ), '%d' );

		$query = $this->prepare_with_table_tokens(
			"SELECT id, meta_message_id, message_content, response_json
			FROM {history}
			WHERE id IN ({$placeholders})",
			$table_map,
			$ids
		);
		if ( '' === $query ) {
			return array();
		}

		$rows = $db->get_results( $query );

		return $rows ? $rows : array();
	}

	/**
	 * Get reply source rows by WAMIDs.
	 *
	 * @param array  $wamids          WAMIDs.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @return array Rows.
	 */
	public function get_reply_rows_by_wamids( array $wamids, string $user_mailid, string $phone_number_id ): array {
		$wamids = array_values( array_filter( array_map( 'strval', (array) $wamids ) ) );
		$wamids = array_values( array_unique( $wamids ) );

		if ( empty( $wamids ) ) {
			return array();
		}

		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$out = array();

		foreach ( $wamids as $wamid ) {
			$cache_key = 'reply_row_wamid:' . md5( $wamid . '|' . $user_mailid . '|' . $phone_number_id );

			$row = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false === $row ) {
				$query = $this->prepare_with_table_tokens(
					'SELECT id, meta_message_id, message_content, response_json
					 FROM {history}
					 WHERE meta_message_id = %s
					   AND user_mailid = %s
					   AND phone_number_id = %s',
					$table_map,
					array( $wamid, $user_mailid, $phone_number_id )
				);
				if ( '' === $query ) {
					continue;
				}

				$row = $db->get_row( $query );

				// Cache for 10 minutes.
				wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 600 );
			}

			if ( $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/* ========================= LAST INCOMING ========================= */

	/**
	 * Get timestamp of last received message for a contact.
	 *
	 * @param int    $contact_id  Contact id.
	 * @param string $user_mailid User email.
	 * @return string|null UTC datetime or null.
	 */
	public function get_last_incoming_time( int $contact_id, string $user_mailid ): ?string {
		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$cache_key = 'last_incoming:' . (string) $contact_id . ':' . md5( $user_mailid );

		if ( $this->runtime_has( $cache_key ) ) {
			$cached = $this->runtime_get( $cache_key );
			return $cached ? (string) $cached : null;
		}

		$query = $this->prepare_with_table_tokens(
			"SELECT created_at
			 FROM {history}
			 WHERE contact_id = %d
			   AND user_mailid = %s
			   AND status = 'received'
			   AND deleted_at IS NULL
			 ORDER BY id DESC
			 LIMIT 1",
			$table_map,
			array( $contact_id, $user_mailid )
		);
		if ( '' === $query ) {
			return null;
		}

		$val = $db->get_var( $query );
		$val = $val ? (string) $val : null;

		$this->runtime_set( $cache_key, $val );

		return $val;
	}

	/* ========================= READ / FAVORITE / DELETE ========================= */

	/**
	 * Mark all received messages for a contact as read.
	 *
	 * @param int    $contact_id      Contact id.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @return void
	 */
	public function mark_chat_read( int $contact_id, string $user_mailid, string $phone_number_id ): void {
		$db  = $this->db();
		$t_h = $this->table( 'nxtcc_message_history' );

		$db->update(
			$t_h,
			array( 'is_read' => 1 ),
			array(
				'contact_id'      => $contact_id,
				'user_mailid'     => $user_mailid,
				'phone_number_id' => $phone_number_id,
				'status'          => 'received',
			),
			array( '%d' ),
			array( '%d', '%s', '%s', '%s' )
		);

		$this->bust_hot_caches( $user_mailid, $phone_number_id, $contact_id );
	}

	/**
	 * Get favorite flag row for a message id.
	 *
	 * @param int    $id              Message history id.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @return object|null Row with id + is_favorite or null.
	 */
	public function get_message_favorite_row( int $id, string $user_mailid, string $phone_number_id ): ?object {
		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$cache_key = 'favorite_row:' . (string) $id . ':' . md5( $user_mailid . '|' . $phone_number_id );

		if ( $this->runtime_has( $cache_key ) ) {
			$cached = $this->runtime_get( $cache_key );
			return ( $cached instanceof stdClass ) ? $cached : null;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT id, is_favorite
			 FROM {history}
			 WHERE id = %d
			   AND user_mailid = %s
			   AND phone_number_id = %s',
			$table_map,
			array( $id, $user_mailid, $phone_number_id )
		);
		if ( '' === $query ) {
			return null;
		}

		$row = $db->get_row( $query );
		$row = ( $row instanceof stdClass ) ? $row : null;

		$this->runtime_set( $cache_key, $row );

		return $row;
	}

	/**
	 * Update message favorite flag.
	 *
	 * @param int    $id              Message id.
	 * @param int    $value           1 to favorite, 0 to unfavorite.
	 * @param string $user_mailid     User email (for cache bust).
	 * @param string $phone_number_id Phone number id (for cache bust).
	 * @return void
	 */
	public function update_message_favorite( int $id, int $value, string $user_mailid = '', string $phone_number_id = '' ): void {
		$db  = $this->db();
		$t_h = $this->table( 'nxtcc_message_history' );

		$db->update(
			$t_h,
			array( 'is_favorite' => ( 1 === (int) $value ) ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( '' !== $user_mailid && '' !== $phone_number_id ) {
			$this->bust_hot_caches( $user_mailid, $phone_number_id, 0, array( $id ) );
		}
	}

	/**
	 * Soft delete messages by setting deleted_at timestamp.
	 *
	 * @param array  $ids             Message ids.
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @param string $now             UTC mysql datetime.
	 * @return void
	 */
	public function soft_delete_messages( array $ids, string $user_mailid, string $phone_number_id, string $now ): void {
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return;
		}

		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$placeholders = $this->in_placeholders( count( $ids ), '%d' );

		$args  = array_merge( array( $now, $user_mailid, $phone_number_id ), $ids );
		$query = $this->prepare_with_table_tokens(
			"UPDATE {history}
			SET deleted_at = %s
			WHERE user_mailid = %s
			  AND phone_number_id = %s
			  AND id IN ({$placeholders})",
			$table_map,
			$args
		);
		if ( '' !== $query ) {
			$db->query( $query );
		}

		$this->bust_hot_caches( $user_mailid, $phone_number_id, 0, $ids );
	}

	/* ========================= ACCESS TOKENS / SETTINGS ========================= */

	/**
	 * Get decrypted API credentials for a user + phone_number_id.
	 *
	 * Uses the central tenant credentials helper.
	 *
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @return array|null Credentials array or null.
	 */
	public function get_user_settings_access_token( string $user_mailid, string $phone_number_id ): ?array {
		$user_mailid     = (string) $user_mailid;
		$phone_number_id = (string) $phone_number_id;

		if ( '' === $user_mailid || '' === $phone_number_id ) {
			return null;
		}

		$db         = $this->db();
		$t_settings = $this->quote_table( $this->table( 'nxtcc_user_settings' ) );
		$table_map  = array(
			'settings' => $t_settings,
		);
		$cache_key  = 'user_token:' . md5( $user_mailid . '|' . $phone_number_id );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT business_account_id
			 FROM {settings}
			 WHERE user_mailid = %s
			   AND phone_number_id = %s
			 ORDER BY id DESC
			 LIMIT 1',
			$table_map,
			array( $user_mailid, $phone_number_id )
		);
		if ( '' === $query ) {
			return null;
		}

		$row = $db->get_row( $query );

		if ( ! $row || empty( $row->business_account_id ) ) {
			wp_cache_set( $cache_key, null, self::CACHE_GROUP, 600 );
			return null;
		}

		if ( ! function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
			$helpers_class = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
			if ( file_exists( $helpers_class ) ) {
				require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
			}

			$helpers_functions = NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
			if ( file_exists( $helpers_functions ) ) {
				require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
			}
		}

		$creds = nxtcc_get_tenant_api_credentials(
			$user_mailid,
			(string) $row->business_account_id,
			$phone_number_id
		);

		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			wp_cache_set( $cache_key, null, self::CACHE_GROUP, 600 );
			return null;
		}

		wp_cache_set( $cache_key, $creds, self::CACHE_GROUP, 600 );

		return $creds;
	}

	/* ========================= REPLY RESOLUTION ========================= */

	/**
	 * Resolve a reply-to WAMID using a message history id.
	 *
	 * @param int    $history_id  History id.
	 * @param string $user_mailid User email.
	 * @return string WAMID or empty string.
	 */
	public function resolve_reply_to_message_id_by_history( int $history_id, string $user_mailid ): string {
		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$cache_key = 'reply_wamid_history:' . (string) $history_id . ':' . md5( $user_mailid );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$query = $this->prepare_with_table_tokens(
			'SELECT meta_message_id
			 FROM {history}
			 WHERE id = %d
			   AND user_mailid = %s',
			$table_map,
			array( $history_id, $user_mailid )
		);
		if ( '' === $query ) {
			return '';
		}

		$val = $db->get_var( $query );
		$val = $val ? (string) $val : '';

		wp_cache_set( $cache_key, $val, self::CACHE_GROUP, 600 );

		return $val;
	}

	/* ========================= FORWARD TARGETS / FORWARDING ========================= */

	/**
	 * List forwarding target contacts (only those with inbound activity in the last 24 hours).
	 *
	 * NOTE: Must be scoped to phone_number_id to prevent cross-number mixing.
	 *
	 * @param string $user_mailid     User email.
	 * @param string $phone_number_id Phone number id.
	 * @param string $search          Search phrase (name/phone).
	 * @param int    $per             Page size.
	 * @param int    $off             Offset.
	 * @return array Rows.
	 */
	public function list_forward_targets(
		string $user_mailid,
		string $phone_number_id,
		string $search,
		int $per,
		int $off
	): array {
		$db        = $this->db();
		$t_c       = $this->quote_table( $this->table( 'nxtcc_contacts' ) );
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'contacts' => $t_c,
			'history'  => $t_h,
		);

		$user_mailid     = (string) $user_mailid;
		$phone_number_id = (string) $phone_number_id;
		$search          = (string) $search;

		if ( '' === $user_mailid || '' === $phone_number_id ) {
			return array();
		}

		$per = $this->clamp_int( (int) $per, 1, 200, 20 );
		$off = (int) $off;
		if ( $off < 0 ) {
			$off = 0;
		}

		if ( '' !== $search ) {
			$like = '%' . $db->esc_like( $search ) . '%';

			$query = $this->prepare_with_table_tokens(
				"SELECT
					c.id AS contact_id,
					c.name,
					c.country_code,
					c.phone_number,
					MAX(h.created_at) AS last_inbound_at
				 FROM {contacts} c
				 JOIN {history} h
				   ON h.contact_id = c.id
				  AND h.user_mailid = c.user_mailid
				  AND h.phone_number_id = %s
				  AND h.status = 'received'
				  AND h.deleted_at IS NULL
				 WHERE c.user_mailid = %s
				   AND (
						c.name LIKE %s
						OR CONCAT('+', c.country_code, ' ', c.phone_number) LIKE %s
						OR c.phone_number LIKE %s
				   )
				 GROUP BY c.id
				 HAVING MAX(h.created_at) >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
				 ORDER BY last_inbound_at DESC
				 LIMIT %d OFFSET %d",
				$table_map,
				array(
					$phone_number_id,
					$user_mailid,
					$like,
					$like,
					$like,
					$per,
					$off,
				)
			);
			if ( '' === $query ) {
				return array();
			}

			$rows = $db->get_results( $query );
		} else {
			$query = $this->prepare_with_table_tokens(
				"SELECT
					c.id AS contact_id,
					c.name,
					c.country_code,
					c.phone_number,
					MAX(h.created_at) AS last_inbound_at
				 FROM {contacts} c
				 JOIN {history} h
				   ON h.contact_id = c.id
				  AND h.user_mailid = c.user_mailid
				  AND h.phone_number_id = %s
				  AND h.status = 'received'
				  AND h.deleted_at IS NULL
				 WHERE c.user_mailid = %s
				 GROUP BY c.id
				 HAVING MAX(h.created_at) >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
				 ORDER BY last_inbound_at DESC
				 LIMIT %d OFFSET %d",
				$table_map,
				array(
					$phone_number_id,
					$user_mailid,
					$per,
					$off,
				)
			);
			if ( '' === $query ) {
				return array();
			}

			$rows = $db->get_results( $query );
		}

		return $rows ? $rows : array();
	}

	/**
	 * Fetch message rows needed for forwarding by message ids.
	 *
	 * @param array  $message_ids Message ids.
	 * @param string $user_mailid  User email.
	 * @return array Rows (id + message_content).
	 */
	public function get_messages_for_forwarding( array $message_ids, string $user_mailid ): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) $message_ids ) ) );
		$ids = array_values( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$db        = $this->db();
		$t_h       = $this->quote_table( $this->table( 'nxtcc_message_history' ) );
		$table_map = array(
			'history' => $t_h,
		);

		$placeholders = $this->in_placeholders( count( $ids ), '%d' );

		$args  = array_merge( array( $user_mailid ), $ids );
		$query = $this->prepare_with_table_tokens(
			"SELECT id, message_content
			FROM {history}
			WHERE user_mailid = %s
			  AND id IN ({$placeholders})",
			$table_map,
			$args
		);
		if ( '' === $query ) {
			return array();
		}

		$rows = $db->get_results( $query );

		return $rows ? $rows : array();
	}
}
