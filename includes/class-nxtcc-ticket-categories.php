<?php
/**
 * Tenant-scoped ticket category service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category storage shared by Inbox, workflows, and integrations.
 */
final class NXTCC_Ticket_Categories {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Category table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Conversation table.
	 *
	 * @var string
	 */
	private string $conversations_table;

	/**
	 * Return the singleton.
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

		$this->db                  = $wpdb;
		$this->table               = $wpdb->prefix . 'nxtcc_ticket_categories';
		$this->conversations_table = $wpdb->prefix . 'nxtcc_conversations';
	}

	/**
	 * List categories for one tenant.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param bool  $include_archived Include inactive categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_categories( array $tenant_args, bool $include_archived = false ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		if ( $include_archived ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					'SELECT id, category_name, category_slug, color, description, is_active, created_at, updated_at
					FROM %i
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
					ORDER BY is_active DESC, category_name ASC, id ASC',
					$this->table,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				),
				ARRAY_A
			);
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					'SELECT id, category_name, category_slug, color, description, is_active, created_at, updated_at
					FROM %i
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND is_active = 1
					ORDER BY category_name ASC, id ASC',
					$this->table,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'decorate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Get one category.
	 *
	 * @param int   $category_id Category ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get( int $category_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $category_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, category_name, category_slug, color, description, is_active, created_at, updated_at
				FROM %i
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$this->table,
				$category_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decorate( $row ) : null;
	}

	/**
	 * Create or update one category.
	 *
	 * @param array $args Category values and tenant tuple.
	 * @return array<string,mixed>
	 */
	public function save( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$category_id = absint( $args['category_id'] ?? 0 );
		$name        = $this->limit_text( $args['category_name'] ?? '', 100 );
		$slug        = sanitize_title( (string) ( $args['category_slug'] ?? $name ) );
		$color       = sanitize_hex_color( (string) ( $args['color'] ?? '#2271b1' ) );
		$description = $this->limit_text( $args['description'] ?? '', 500 );
		$actor_id    = absint( $args['actor_id'] ?? get_current_user_id() );
		$now         = current_time( 'mysql', true );

		if ( ! $this->tenant_is_complete( $tenant ) || '' === $name || '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'Enter a valid category name.', 'nxt-cloud-chat' ),
			);
		}

		$duplicate_id = absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT id FROM %i
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
					AND category_slug = %s AND id <> %d LIMIT 1',
					$this->table,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$slug,
					$category_id
				)
			)
		);
		if ( $duplicate_id > 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A ticket category with this name already exists.', 'nxt-cloud-chat' ),
			);
		}

		$data = array(
			'category_name' => $name,
			'category_slug' => substr( $slug, 0, 100 ),
			'color'         => '' !== $color ? $color : '#2271b1',
			'description'   => '' !== $description ? $description : null,
			'is_active'     => isset( $args['is_active'] ) ? ( ! empty( $args['is_active'] ) ? 1 : 0 ) : 1,
			'updated_by'    => $actor_id > 0 ? $actor_id : null,
			'updated_at'    => $now,
		);

		if ( $category_id > 0 ) {
			if ( null === $this->get( $category_id, $tenant ) ) {
				return array(
					'success' => false,
					'message' => __( 'Ticket category not found.', 'nxt-cloud-chat' ),
				);
			}
			$saved = $this->db->update( $this->table, $data, array( 'id' => $category_id ) );
		} else {
			$saved       = $this->db->insert(
				$this->table,
				array_merge(
					$tenant,
					$data,
					array(
						'created_by' => $actor_id > 0 ? $actor_id : null,
						'created_at' => $now,
					)
				)
			);
			$category_id = absint( $this->db->insert_id );
		}

		if ( false === $saved ) {
			return array(
				'success' => false,
				'message' => __( 'Unable to save the ticket category.', 'nxt-cloud-chat' ),
			);
		}

		return array(
			'success'  => true,
			'category' => $this->get( $category_id, $tenant ),
		);
	}

	/**
	 * Delete an unused category or archive a referenced category.
	 *
	 * @param int   $category_id Category ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param int   $actor_id Actor ID.
	 * @return array<string,mixed>
	 */
	public function delete_or_archive( int $category_id, array $tenant_args, int $actor_id = 0 ): array {
		$tenant   = $this->normalize_tenant( $tenant_args );
		$category = $this->get( $category_id, $tenant );
		if ( null === $category ) {
			return array(
				'success' => false,
				'message' => __( 'Ticket category not found.', 'nxt-cloud-chat' ),
			);
		}

		$references = absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT COUNT(*) FROM %i
					WHERE category_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
					$this->conversations_table,
					$category_id,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			)
		);

		if ( $references > 0 ) {
			$saved = $this->db->update(
				$this->table,
				array(
					'is_active'  => 0,
					'updated_by' => $actor_id > 0 ? $actor_id : null,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $category_id )
			);

			return array(
				'success'  => false !== $saved,
				'archived' => true,
				'message'  => __( 'Referenced categories are archived so existing tickets keep their category.', 'nxt-cloud-chat' ),
			);
		}

		$deleted = $this->db->delete( $this->table, array( 'id' => $category_id ) );
		return array(
			'success'  => false !== $deleted,
			'archived' => false,
			'message'  => __( 'Ticket category deleted.', 'nxt-cloud-chat' ),
		);
	}

	/**
	 * Decorate a category row.
	 *
	 * @param array $row Raw row.
	 * @return array<string,mixed>
	 */
	private function decorate( array $row ): array {
		$row['id']        = absint( $row['id'] ?? 0 );
		$row['is_active'] = ! empty( $row['is_active'] );
		return $row;
	}

	/**
	 * Normalize tenant values.
	 *
	 * @param array $args Raw tenant values.
	 * @return array<string,string>
	 */
	private function normalize_tenant( array $args ): array {
		return array(
			'user_mailid'         => sanitize_email( (string) ( $args['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $args['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $args['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Whether a tenant tuple is complete.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private function tenant_is_complete( array $tenant ): bool {
		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Limit plain text.
	 *
	 * @param mixed $value Raw text.
	 * @param int   $length Maximum characters.
	 * @return string
	 */
	private function limit_text( $value, int $length ): string {
		$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
