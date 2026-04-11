<?php
/**
 * Contacts provider + repository for token rendering.
 *
 * Provides cached contact lookups and builds contact.* token context.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contacts repository + provider utilities.
 */
final class NXTCC_Contacts_Provider {

	/**
	 * Cache group used for contact lookups.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_contacts';

	/**
	 * Singleton instance.
	 *
	 * @var NXTCC_Contacts_Provider|null
	 */
	private static ?NXTCC_Contacts_Provider $instance = null;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Contacts table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Get singleton instance.
	 *
	 * @return NXTCC_Contacts_Provider
	 */
	public static function instance(): NXTCC_Contacts_Provider {
		if ( null === self::$instance ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database instance.
	 */
	private function __construct( wpdb $db ) {
		$this->db    = $db;
		$this->table = $this->db->prefix . 'nxtcc_contacts';
	}

	/**
	 * Quote a table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string Backtick-quoted table name.
	 */
	private function quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		if ( ! is_string( $clean ) || '' === $clean ) {
			$clean = 'nxtcc_invalid';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Fetch a single contact row by ID (only required columns).
	 *
	 * Returns stdClass|false (same semantics as $wpdb->get_row()).
	 *
	 * @param int $contact_id Contact row ID.
	 * @return stdClass|false
	 */
	public function get_contact_basic_by_id( int $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( 0 >= $contact_id ) {
			return false;
		}

		$cache_key = 'contact_basic_' . $contact_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			// Cache stores stdClass|false; return as-is.
			return $cached;
		}

		$table_sql = $this->quote_table_name( $this->table );

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT name, country_code, phone_number, custom_fields
				 FROM ' . $table_sql . '
				 WHERE id = %d
				 LIMIT %d',
				$contact_id,
				1
			)
		);

		// VIP PHPCS requires a literal, determinable cache TTL (>= 300 seconds).
		wp_cache_set( $cache_key, $row ? $row : false, self::CACHE_GROUP, 300 );

		return $row ? $row : false;
	}

	/**
	 * Slugify a custom field label.
	 *
	 * Preserves prior behavior: lowercase, remove non-word chars (unicode-aware),
	 * collapse spaces/dashes to underscores, trim underscores, and default to "field".
	 *
	 * @param string $label Label text.
	 * @return string
	 */
	public function slugify_label( string $label ): string {
		$s = strtolower( $label );
		$s = preg_replace( '/[^\p{L}\p{N}_\s\-]+/u', '', $s );
		$s = preg_replace( '/[\s\-]+/u', '_', (string) $s );
		$s = trim( (string) $s, '_' );

		return '' !== $s ? $s : 'field';
	}

	/**
	 * Build the contact provider context.
	 *
	 * @param int    $contact_id  Contact ID.
	 * @param string $user_mailid Tenant hint (unused; kept for signature compatibility).
	 * @return array
	 */
	public function build_contact_context( int $contact_id, string $user_mailid = '' ): array {
		$contact_id  = absint( $contact_id );
		$user_mailid = sanitize_email( $user_mailid );

		$row = $this->get_contact_basic_by_id( $contact_id );
		if ( ! $row ) {
			return array();
		}

		$contact = array(
			'name'         => isset( $row->name ) ? (string) $row->name : '',
			'country_code' => isset( $row->country_code ) ? (string) $row->country_code : '',
			'phone_number' => isset( $row->phone_number ) ? (string) $row->phone_number : '',
			'custom'       => array(),
		);

		// Flatten custom_fields into contact.custom.<slug>.
		if ( ! empty( $row->custom_fields ) ) {
			$arr = json_decode( (string) $row->custom_fields, true );
			if ( is_array( $arr ) ) {
				foreach ( $arr as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}

					$label = isset( $f['label'] ) ? (string) $f['label'] : '';
					$value = $f['value'] ?? '';

					if ( '' === $label ) {
						continue;
					}

					$slug = $this->slugify_label( $label );

					// If duplicate slugs occur, last one wins.
					$contact['custom'][ $slug ] = is_scalar( $value ) ? (string) $value : '';
				}
			}
		}

		return array( 'contact' => $contact );
	}
}
