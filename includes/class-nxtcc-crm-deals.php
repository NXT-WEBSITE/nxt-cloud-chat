<?php
/**
 * Tenant-scoped sales pipelines and deals.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned sales CRM service shared by admin, Pro, and integrations.
 */
final class NXTCC_CRM_Deals {

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
	 * Controlled table names.
	 *
	 * @var array<string,string>
	 */
	private array $tables;

	/**
	 * Return singleton.
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

		$this->db     = $wpdb;
		$this->tables = array(
			'pipelines' => $wpdb->prefix . 'nxtcc_crm_pipelines',
			'stages'    => $wpdb->prefix . 'nxtcc_crm_pipeline_stages',
			'deals'     => $wpdb->prefix . 'nxtcc_crm_deals',
			'contacts'  => $wpdb->prefix . 'nxtcc_crm_deal_contacts',
			'products'  => $wpdb->prefix . 'nxtcc_crm_deal_products',
			'history'   => $wpdb->prefix . 'nxtcc_crm_deal_stage_history',
			'people'    => $wpdb->prefix . 'nxtcc_contacts',
		);
	}

	/**
	 * List tenant pipelines.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param bool  $active_only Return active rows only.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_pipelines( array $tenant_args, bool $active_only = true ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$this->ensure_defaults( $tenant );
		$sql   = 'SELECT * FROM ' . $this->quote_table( $this->tables['pipelines'] ) . '
			WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$query = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY is_default DESC, pipeline_name ASC, id ASC';

		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$query ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create or update a pipeline.
	 *
	 * @param array $args Pipeline arguments.
	 * @return array<string,mixed>
	 */
	public function upsert_pipeline( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$pipeline_id = absint( $args['pipeline_id'] ?? 0 );
		$previous    = $pipeline_id > 0 ? $this->get_pipeline( $pipeline_id, $tenant ) : null;
		$name        = substr( sanitize_text_field( (string) ( $args['pipeline_name'] ?? '' ) ), 0, 120 );
		$slug        = sanitize_title( (string) ( $args['pipeline_slug'] ?? $previous['pipeline_slug'] ?? $name ) );
		$currency    = $this->normalize_currency( (string) ( $args['currency'] ?? $previous['currency'] ?? 'USD' ) );
		$actor_id    = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );

		if ( ! $this->tenant_is_complete( $tenant ) || '' === $name || '' === $slug ) {
			return array(
				'success' => false,
				'error'   => 'invalid_pipeline',
			);
		}

		$now        = current_time( 'mysql', true );
		$is_default = array_key_exists( 'is_default', $args ) ? (int) ! empty( $args['is_default'] ) : (int) ( $previous['is_default'] ?? 0 );
		$is_active  = array_key_exists( 'is_active', $args ) ? (int) ! empty( $args['is_active'] ) : (int) ( $previous['is_active'] ?? 1 );
		if ( $is_default ) {
			$is_active = 1;
		}
		if ( is_array( $previous ) && ! empty( $previous['is_default'] ) && ! $is_default ) {
			return array(
				'success' => false,
				'error'   => 'default_pipeline_required',
			);
		}
		if ( is_array( $previous ) && ! $is_active && ! empty( $previous['is_active'] ) && ( ! empty( $previous['is_default'] ) || count( $this->list_pipelines( $tenant ) ) <= 1 ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_cannot_be_archived',
			);
		}
		$data = array(
			'pipeline_name' => $name,
			'pipeline_slug' => substr( $slug, 0, 80 ),
			'currency'      => $currency,
			'is_default'    => $is_default,
			'is_active'     => $is_active,
			'updated_by'    => $actor_id > 0 ? $actor_id : null,
			'updated_at'    => $now,
		);

		if ( $pipeline_id > 0 ) {
			if ( null === $previous ) {
				return array(
					'success' => false,
					'error'   => 'pipeline_not_found',
				);
			}
			$success = false !== $this->db->update(
				$this->tables['pipelines'],
				$data,
				array_merge( array( 'id' => $pipeline_id ), $tenant ),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
				array( '%d', '%s', '%s', '%s' )
			);
		} else {
			$data    = array_merge(
				$tenant,
				$data,
				array(
					'created_by' => $actor_id > 0 ? $actor_id : null,
					'created_at' => $now,
				)
			);
			$success = (bool) $this->db->insert(
				$this->tables['pipelines'],
				$data,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
			if ( $success ) {
				$pipeline_id = absint( $this->db->insert_id );
			}
		}

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_save_failed',
			);
		}

		if ( ! empty( $data['is_default'] ) ) {
			$this->clear_other_default_pipelines( $pipeline_id, $tenant );
		}

		$pipeline = $this->get_pipeline( $pipeline_id, $tenant );
		$result   = array(
			'success'  => true,
			'pipeline' => $pipeline,
		);
		do_action( 'nxtcc_crm_pipeline_saved', $result, $args );
		return $result;
	}

	/**
	 * List tenant pipeline stages.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param bool  $active_only Return active stages only.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_stages( int $pipeline_id, array $tenant_args, bool $active_only = true ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( null === $this->get_pipeline( $pipeline_id, $tenant ) ) {
			return array();
		}

		$sql   = 'SELECT * FROM ' . $this->quote_table( $this->tables['stages'] ) . '
			WHERE pipeline_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		$query = array( $pipeline_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';

		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$query ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create or update a pipeline stage.
	 *
	 * @param array $args Stage arguments.
	 * @return array<string,mixed>
	 */
	public function upsert_stage( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$pipeline_id = absint( $args['pipeline_id'] ?? 0 );
		$stage_id    = absint( $args['stage_id'] ?? 0 );
		$previous    = $stage_id > 0 ? $this->get_stage( $stage_id, $tenant ) : null;
		$name        = substr( sanitize_text_field( (string) ( $args['stage_name'] ?? '' ) ), 0, 120 );
		$slug        = sanitize_title( (string) ( $args['stage_slug'] ?? $previous['stage_slug'] ?? $name ) );
		$stage_type  = $this->normalize_status( (string) ( $args['stage_type'] ?? $previous['stage_type'] ?? 'open' ) );
		$color       = sanitize_hex_color( (string) ( $args['color'] ?? $previous['color'] ?? '#2271b1' ) );
		$actor_id    = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );

		if ( null === $this->get_pipeline( $pipeline_id, $tenant ) || '' === $name || '' === $slug ) {
			return array(
				'success' => false,
				'error'   => 'invalid_pipeline_stage',
			);
		}

		$now        = current_time( 'mysql', true );
		$is_active  = array_key_exists( 'is_active', $args ) ? (int) ! empty( $args['is_active'] ) : (int) ( $previous['is_active'] ?? 1 );
		$sort_order = absint( $args['sort_order'] ?? $previous['sort_order'] ?? 0 );
		if ( null === $previous && ! array_key_exists( 'sort_order', $args ) ) {
			$sort_order = absint(
				$this->db->get_var(
					$this->db->prepare(
						'SELECT MAX(sort_order) FROM ' . $this->quote_table( $this->tables['stages'] ) . '
						WHERE pipeline_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
						$pipeline_id,
						$tenant['user_mailid'],
						$tenant['business_account_id'],
						$tenant['phone_number_id']
					)
				)
			) + 10;
		}
		if ( is_array( $previous ) && ! $is_active && ! empty( $previous['is_active'] ) && count( $this->list_stages( $pipeline_id, $tenant ) ) <= 1 ) {
			return array(
				'success' => false,
				'error'   => 'stage_cannot_be_archived',
			);
		}
		$data = array(
			'pipeline_id'        => $pipeline_id,
			'stage_name'         => $name,
			'stage_slug'         => substr( $slug, 0, 80 ),
			'color'              => is_string( $color ) ? $color : '#2271b1',
			'probability'        => min( 100, absint( $args['probability'] ?? $previous['probability'] ?? 0 ) ),
			'sort_order'         => $sort_order,
			'stage_type'         => $stage_type,
			'reason_requirement' => $this->normalize_reason_requirement( (string) ( $args['reason_requirement'] ?? $previous['reason_requirement'] ?? 'optional' ) ),
			'is_active'          => $is_active,
			'updated_by'         => $actor_id > 0 ? $actor_id : null,
			'updated_at'         => $now,
		);

		if ( $stage_id > 0 ) {
			if ( null === $previous ) {
				return array(
					'success' => false,
					'error'   => 'stage_not_found',
				);
			}
			$success = false !== $this->db->update(
				$this->tables['stages'],
				$data,
				array_merge( array( 'id' => $stage_id ), $tenant ),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ),
				array( '%d', '%s', '%s', '%s' )
			);
		} else {
			$data    = array_merge(
				$tenant,
				$data,
				array(
					'created_by' => $actor_id > 0 ? $actor_id : null,
					'created_at' => $now,
				)
			);
			$success = (bool) $this->db->insert(
				$this->tables['stages'],
				$data,
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
			);
			if ( $success ) {
				$stage_id = absint( $this->db->insert_id );
			}
		}

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'stage_save_failed',
			);
		}

		$stage  = $this->get_stage( $stage_id, $tenant );
		$result = array(
			'success' => true,
			'stage'   => $stage,
		);
		do_action( 'nxtcc_crm_pipeline_stage_saved', $result, $args );
		return $result;
	}

	/**
	 * Return pipelines with stage and deal usage counts.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param bool  $include_inactive Include archived rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pipeline_overview( array $tenant_args, bool $include_inactive = true ): array {
		$tenant    = $this->normalize_tenant( $tenant_args );
		$pipelines = $this->list_pipelines( $tenant, ! $include_inactive );
		if ( empty( $pipelines ) ) {
			return array();
		}

		$stage_sql = 'SELECT * FROM ' . $this->quote_table( $this->tables['stages'] ) . '
			WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s';
		if ( ! $include_inactive ) {
			$stage_sql .= ' AND is_active = 1';
		}
		$stage_sql                 .= ' ORDER BY pipeline_id ASC, sort_order ASC, id ASC';
		$stages                     = $this->db->get_results(
			$this->db->prepare(
				$stage_sql,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		$counts                     = $this->db->get_results(
			$this->db->prepare(
				'SELECT pipeline_id, stage_id, COUNT(*) deal_count
				FROM ' . $this->quote_table( $this->tables['deals'] ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				GROUP BY pipeline_id, stage_id',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		$history_stage_ids          = $this->db->get_col(
			$this->db->prepare(
				'SELECT DISTINCT stage_id
				FROM ' . $this->quote_table( $this->tables['history'] ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND stage_id > 0',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
		$previous_history_stage_ids = $this->db->get_col(
			$this->db->prepare(
				'SELECT DISTINCT previous_stage_id
				FROM ' . $this->quote_table( $this->tables['history'] ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				AND previous_stage_id > 0',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);

		$pipeline_counts = array();
		$stage_counts    = array();
		$history_stages  = array();
		foreach ( is_array( $counts ) ? $counts : array() as $count ) {
			$pipeline_id                     = absint( $count['pipeline_id'] ?? 0 );
			$stage_id                        = absint( $count['stage_id'] ?? 0 );
			$deal_count                      = absint( $count['deal_count'] ?? 0 );
			$pipeline_counts[ $pipeline_id ] = ( $pipeline_counts[ $pipeline_id ] ?? 0 ) + $deal_count;
			$stage_counts[ $stage_id ]       = $deal_count;
		}
		foreach ( array_merge( is_array( $history_stage_ids ) ? $history_stage_ids : array(), is_array( $previous_history_stage_ids ) ? $previous_history_stage_ids : array() ) as $history_stage_id ) {
			$history_stages[ absint( $history_stage_id ) ] = true;
		}

		$indexes = array();
		foreach ( $pipelines as $index => &$pipeline ) {
			$pipeline_id             = absint( $pipeline['id'] ?? 0 );
			$pipeline['deal_count']  = $pipeline_counts[ $pipeline_id ] ?? 0;
			$pipeline['stages']      = array();
			$indexes[ $pipeline_id ] = $index;
		}
		unset( $pipeline );
		foreach ( is_array( $stages ) ? $stages : array() as $stage ) {
			$pipeline_id = absint( $stage['pipeline_id'] ?? 0 );
			$stage_id    = absint( $stage['id'] ?? 0 );
			if ( isset( $indexes[ $pipeline_id ] ) ) {
				$stage['deal_count']                               = $stage_counts[ $stage_id ] ?? 0;
				$stage['is_referenced']                            = ! empty( $stage['deal_count'] ) || isset( $history_stages[ $stage_id ] );
				$pipelines[ $indexes[ $pipeline_id ] ]['stages'][] = $stage;
			}
		}
		return array_values( $pipelines );
	}

	/**
	 * Duplicate a pipeline and its stages.
	 *
	 * @param array $args Duplicate arguments.
	 * @return array<string,mixed>
	 */
	public function duplicate_pipeline( array $args ): array {
		$tenant   = $this->normalize_tenant( $args );
		$pipeline = $this->get_pipeline( absint( $args['pipeline_id'] ?? 0 ), $tenant );
		if ( ! is_array( $pipeline ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_not_found',
			);
		}
		$base_name = substr( sanitize_text_field( (string) ( $args['pipeline_name'] ?? $pipeline['pipeline_name'] . ' Copy' ) ), 0, 110 );
		$name      = $base_name;
		$used      = array_fill_keys( wp_list_pluck( $this->list_pipelines( $tenant, false ), 'pipeline_slug' ), true );
		$name_slug = sanitize_title( $name );
		for ( $copy = 2; isset( $used[ $name_slug ] ) && $copy <= 9999; ++$copy ) {
			$name      = substr( $base_name . ' ' . $copy, 0, 120 );
			$name_slug = sanitize_title( $name );
		}
		if ( isset( $used[ $name_slug ] ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_duplicate_name_unavailable',
			);
		}
		$result = $this->upsert_pipeline(
			array_merge(
				$tenant,
				array(
					'pipeline_name' => $name,
					'currency'      => $pipeline['currency'] ?? 'USD',
					'is_active'     => 1,
					'actor_id'      => absint( $args['actor_id'] ?? 0 ),
				)
			)
		);
		$new_id = absint( $result['pipeline']['id'] ?? 0 );
		if ( empty( $result['success'] ) || $new_id <= 0 ) {
			return $result;
		}
		foreach ( $this->list_stages_without_defaults( absint( $pipeline['id'] ), $tenant ) as $stage ) {
			$this->upsert_stage(
				array_merge(
					$tenant,
					array(
						'pipeline_id'        => $new_id,
						'stage_name'         => $stage['stage_name'] ?? '',
						'stage_type'         => $stage['stage_type'] ?? 'open',
						'probability'        => absint( $stage['probability'] ?? 0 ),
						'sort_order'         => absint( $stage['sort_order'] ?? 0 ),
						'color'              => $stage['color'] ?? '#2271b1',
						'reason_requirement' => $stage['reason_requirement'] ?? 'optional',
						'is_active'          => ! empty( $stage['is_active'] ),
						'actor_id'           => absint( $args['actor_id'] ?? 0 ),
					)
				)
			);
		}
		return array(
			'success'  => true,
			'pipeline' => $this->get_pipeline( $new_id, $tenant ),
		);
	}

	/**
	 * Reorder pipeline stages.
	 *
	 * @param array $args Reorder arguments.
	 * @return array<string,mixed>
	 */
	public function reorder_stages( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$pipeline_id = absint( $args['pipeline_id'] ?? 0 );
		$stage_ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['stage_ids'] ?? array() ) ) ) ) );
		if ( null === $this->get_pipeline( $pipeline_id, $tenant ) || empty( $stage_ids ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_stage_order',
			);
		}
		foreach ( $stage_ids as $index => $stage_id ) {
			$stage = $this->get_stage( $stage_id, $tenant );
			if ( ! is_array( $stage ) || absint( $stage['pipeline_id'] ?? 0 ) !== $pipeline_id ) {
				return array(
					'success' => false,
					'error'   => 'invalid_stage_order',
				);
			}
			$this->db->update(
				$this->tables['stages'],
				array(
					'sort_order' => ( $index + 1 ) * 10,
					'updated_at' => current_time( 'mysql', true ),
				),
				array_merge( array( 'id' => $stage_id ), $tenant ),
				array( '%d', '%s' ),
				array( '%d', '%s', '%s', '%s' )
			);
		}
		return array(
			'success' => true,
			'stages'  => $this->list_stages( $pipeline_id, $tenant, false ),
		);
	}

	/**
	 * Permanently delete an unused pipeline.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	public function delete_pipeline( array $args ): array {
		$tenant      = $this->normalize_tenant( $args );
		$pipeline_id = absint( $args['pipeline_id'] ?? 0 );
		$pipeline    = $this->get_pipeline( $pipeline_id, $tenant );
		if ( ! is_array( $pipeline ) ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_not_found',
			);
		}
		if ( ! empty( $pipeline['is_default'] ) || $this->count_deals( $tenant, array( 'pipeline_id' => $pipeline_id ) ) > 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_is_referenced',
			);
		}
		$history_count = absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT COUNT(*) FROM ' . $this->quote_table( $this->tables['history'] ) . '
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
					AND (pipeline_id = %d OR previous_pipeline_id = %d)',
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$pipeline_id,
					$pipeline_id
				)
			)
		);
		if ( $history_count > 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_has_history',
			);
		}
		$active = $this->list_pipelines( $tenant );
		if ( ! empty( $pipeline['is_active'] ) && count( $active ) <= 1 ) {
			return array(
				'success' => false,
				'error'   => 'last_active_pipeline',
			);
		}
		$this->db->delete( $this->tables['stages'], array_merge( array( 'pipeline_id' => $pipeline_id ), $tenant ), array( '%d', '%s', '%s', '%s' ) );
		$deleted = $this->db->delete( $this->tables['pipelines'], array_merge( array( 'id' => $pipeline_id ), $tenant ), array( '%d', '%s', '%s', '%s' ) );
		return array( 'success' => false !== $deleted && $deleted > 0 );
	}

	/**
	 * Permanently delete an unused stage.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	public function delete_stage( array $args ): array {
		$tenant   = $this->normalize_tenant( $args );
		$stage_id = absint( $args['stage_id'] ?? 0 );
		$stage    = $this->get_stage( $stage_id, $tenant );
		if ( ! is_array( $stage ) ) {
			return array(
				'success' => false,
				'error'   => 'stage_not_found',
			);
		}
		$history_count = absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT COUNT(*) FROM ' . $this->quote_table( $this->tables['history'] ) . '
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
					AND (stage_id = %d OR previous_stage_id = %d)',
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$stage_id,
					$stage_id
				)
			)
		);
		$deal_count    = $this->count_deals( $tenant, array( 'stage_id' => $stage_id ) );
		if ( $history_count > 0 ) {
			return array(
				'success' => false,
				'error'   => 'stage_has_history',
			);
		}
		if ( $deal_count > 0 ) {
			return array(
				'success' => false,
				'error'   => 'stage_is_referenced',
			);
		}
		$active_stages = $this->list_stages( absint( $stage['pipeline_id'] ?? 0 ), $tenant );
		if ( ! empty( $stage['is_active'] ) && count( $active_stages ) <= 1 ) {
			return array(
				'success' => false,
				'error'   => 'last_active_stage',
			);
		}
		$deleted = $this->db->delete( $this->tables['stages'], array_merge( array( 'id' => $stage_id ), $tenant ), array( '%d', '%s', '%s', '%s' ) );
		return array( 'success' => false !== $deleted && $deleted > 0 );
	}

	/**
	 * List a bounded tenant deal page.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_deals( array $tenant_args, array $args = array() ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$this->ensure_defaults( $tenant );
		$limit  = min( 500, max( 1, absint( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$sql    = $this->deal_select_sql() . '
			WHERE d.user_mailid = %s AND d.business_account_id = %s AND d.phone_number_id = %s';
		$query  = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		$this->append_deal_filters( $sql, $query, $args );
		$sql    .= ' ORDER BY d.updated_at DESC, d.id DESC LIMIT %d OFFSET %d';
		$query[] = $limit;
		$query[] = $offset;
		$rows    = $this->db->get_results( $this->db->prepare( $sql, ...$query ), ARRAY_A );

		return is_array( $rows ) ? $this->decorate_deal_rows( $rows ) : array();
	}

	/**
	 * Count tenant deals matching filters.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Query arguments.
	 * @return int
	 */
	public function count_deals( array $tenant_args, array $args = array() ): int {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return 0;
		}

		$sql   = 'SELECT COUNT(DISTINCT d.id) FROM ' . $this->quote_table( $this->tables['deals'] ) . ' d
			LEFT JOIN ' . $this->quote_table( $this->tables['contacts'] ) . ' dc ON dc.deal_id = d.id
			LEFT JOIN ' . $this->quote_table( $this->tables['people'] ) . ' c ON c.id = dc.contact_id AND dc.is_primary = 1
			WHERE d.user_mailid = %s AND d.business_account_id = %s AND d.phone_number_id = %s';
		$query = array( $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] );
		$this->append_deal_filters( $sql, $query, $args );

		return absint( $this->db->get_var( $this->db->prepare( $sql, ...$query ) ) );
	}

	/**
	 * Return pipeline summary values.
	 *
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>
	 */
	public function get_stats( array $tenant_args ): array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( ! $this->tenant_is_complete( $tenant ) ) {
			return array();
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) total_deals,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) open_deals,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) won_deals,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) lost_deals,
					SUM(CASE WHEN status = %s THEN deal_value ELSE 0 END) open_value
				FROM ' . $this->quote_table( $this->tables['deals'] ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				'open',
				'won',
				'lost',
				'open',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Read one tenant deal with links and products.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant_args Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public function get_deal( int $deal_id, array $tenant_args ): ?array {
		$tenant = $this->normalize_tenant( $tenant_args );
		if ( $deal_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				$this->deal_select_sql() . '
				WHERE d.id = %d AND d.user_mailid = %s AND d.business_account_id = %s AND d.phone_number_id = %s
				LIMIT 1',
				$deal_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$rows                  = $this->decorate_deal_rows( array( $row ) );
		$deal                  = $rows[0] ?? array();
		$deal['contacts']      = $this->list_deal_contacts( $deal_id, $tenant );
		$deal['products']      = $this->list_deal_products( $deal_id, $tenant );
		$deal['stage_history'] = $this->list_stage_history( $deal_id, $tenant );
		return $deal;
	}

	/**
	 * Read deals linked to one contact.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant_args Tenant tuple.
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_contact( int $contact_id, array $tenant_args, array $args = array() ): array {
		$args['contact_id'] = $contact_id;
		return $this->list_deals( $tenant_args, $args );
	}

	/**
	 * Create a deal.
	 *
	 * @param array $args Deal arguments.
	 * @return array<string,mixed>
	 */
	public function create_deal( array $args ): array {
		$args['deal_id'] = 0;
		return $this->save_deal( $args );
	}

	/**
	 * Update a deal.
	 *
	 * @param array $args Deal arguments.
	 * @return array<string,mixed>
	 */
	public function update_deal( array $args ): array {
		return $this->save_deal( $args );
	}

	/**
	 * Permanently delete a tenant deal and its owned records.
	 *
	 * @param array $args Delete arguments.
	 * @return array<string,mixed>
	 */
	public function delete_deal( array $args ): array {
		$tenant  = $this->normalize_tenant( $args );
		$deal_id = absint( $args['deal_id'] ?? 0 );
		$deal    = $this->get_deal( $deal_id, $tenant );
		if ( ! is_array( $deal ) ) {
			return array(
				'success' => false,
				'error'   => 'deal_not_found',
			);
		}

		$deleted = $this->db->delete(
			$this->tables['deals'],
			array_merge( array( 'id' => $deal_id ), $tenant ),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( false === $deleted || 0 === $deleted ) {
			return array(
				'success' => false,
				'error'   => 'deal_delete_failed',
			);
		}

		foreach ( array( 'contacts', 'products', 'history' ) as $table_key ) {
			$this->db->delete(
				$this->tables[ $table_key ],
				array_merge( array( 'deal_id' => $deal_id ), $tenant ),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		$result = array(
			'success' => true,
			'deal'    => $deal,
		);
		do_action( 'nxtcc_crm_deal_deleted', $result, $args );
		return $result;
	}

	/**
	 * Remove deal links for contacts being permanently deleted.
	 *
	 * Deal records are retained because they may remain useful sales history or
	 * may still be linked to other contacts.
	 *
	 * @param array<int,mixed> $contact_ids Contact IDs.
	 * @return void
	 */
	public function delete_contact_data( array $contact_ids ): void {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$deal_ids     = $this->db->get_col(
			$this->db->prepare(
				'SELECT DISTINCT deal_id FROM ' . $this->quote_table( $this->tables['contacts'] ) . ' WHERE is_primary = 1 AND contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Controlled table identifier and integer-only placeholders.
		$this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->quote_table( $this->tables['contacts'] ) . ' WHERE contact_id IN (' . $placeholders . ')',
				...$contact_ids
			)
		);

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $deal_ids ) ) ) ) as $deal_id ) {
			$replacement_id = absint(
				$this->db->get_var(
					$this->db->prepare(
						'SELECT id FROM ' . $this->quote_table( $this->tables['contacts'] ) . ' WHERE deal_id = %d ORDER BY id ASC LIMIT 1',
						$deal_id
					)
				)
			);
			if ( $replacement_id > 0 ) {
				$this->db->update(
					$this->tables['contacts'],
					array( 'is_primary' => 1 ),
					array( 'id' => $replacement_id ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Create or update a deal.
	 *
	 * @param array $args Deal arguments.
	 * @return array<string,mixed>
	 */
	private function save_deal( array $args ): array {
		$tenant   = $this->normalize_tenant( $args );
		$deal_id  = absint( $args['deal_id'] ?? 0 );
		$previous = $deal_id > 0 ? $this->get_deal( $deal_id, $tenant ) : null;
		$source   = $this->normalize_source( (string) ( $args['source'] ?? 'integration' ) );
		$actor_id = $this->normalize_actor_id( absint( $args['actor_id'] ?? get_current_user_id() ), $tenant );

		if ( ! $this->tenant_is_complete( $tenant ) || ( $deal_id > 0 && null === $previous ) ) {
			return array(
				'success' => false,
				'error'   => $deal_id > 0 ? 'deal_not_found' : 'invalid_tenant',
			);
		}

		$this->ensure_defaults( $tenant );
		$pipeline_id = absint( $args['pipeline_id'] ?? $previous['pipeline_id'] ?? 0 );
		$stage_id    = absint( $args['stage_id'] ?? $previous['stage_id'] ?? 0 );
		$pipeline    = $this->get_pipeline( $pipeline_id, $tenant );
		$stage       = $this->get_stage( $stage_id, $tenant );
		$title       = substr( sanitize_text_field( (string) ( $args['title'] ?? $previous['title'] ?? '' ) ), 0, 191 );

		if ( null === $pipeline || null === $stage || absint( $stage['pipeline_id'] ?? 0 ) !== $pipeline_id || '' === $title ) {
			return array(
				'success' => false,
				'error'   => 'invalid_deal',
			);
		}

		$status         = $this->normalize_status( (string) ( $stage['stage_type'] ?? 'open' ) );
		$closed_at      = 'open' === $status ? null : ( $previous['closed_at'] ?? current_time( 'mysql', true ) );
		$stage_changed  = ! is_array( $previous ) || absint( $previous['stage_id'] ?? 0 ) !== $stage_id;
		$reason_default = $stage_changed ? '' : (string) ( $previous['stage_reason'] ?? '' );
		$reason         = substr( sanitize_text_field( (string) ( $args['stage_reason'] ?? $reason_default ) ), 0, 500 );
		if ( $this->stage_transition_requires_reason( $previous, $stage ) && '' === $reason ) {
			return array(
				'success' => false,
				'error'   => 'stage_reason_required',
			);
		}
		$assignee = $this->normalize_assignee(
			$args['assigned_user_id'] ?? $previous['assigned_user_id'] ?? 0,
			$args['assigned_role'] ?? $previous['assigned_role'] ?? '',
			$tenant
		);
		if ( ! $assignee['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'invalid_deal_owner',
			);
		}
		$product_result = array_key_exists( 'products', $args ) ? $this->normalize_deal_products( (array) $args['products'], $tenant ) : null;
		if ( is_array( $product_result ) && ! empty( $product_result['invalid'] ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_deal_line_item',
			);
		}
		$products   = is_array( $product_result ) ? $product_result['items'] : null;
		$value_mode = $this->normalize_value_mode( (string) ( $args['value_mode'] ?? $previous['value_mode'] ?? 'manual' ) );
		$deal_value = max( 0, (float) ( $args['deal_value'] ?? $previous['deal_value'] ?? 0 ) );
		if ( 'calculated' === $value_mode && is_array( $products ) ) {
			$deal_value = array_sum( wp_list_pluck( $products, 'line_total' ) );
		} elseif ( 'calculated' === $value_mode && is_array( $previous ) ) {
			$deal_value = $this->sum_deal_products( $deal_id, $tenant );
		}
		$now  = current_time( 'mysql', true );
		$data = array(
			'pipeline_id'       => $pipeline_id,
			'stage_id'          => $stage_id,
			'title'             => $title,
			'description'       => substr( sanitize_textarea_field( (string) ( $args['description'] ?? $previous['description'] ?? '' ) ), 0, 5000 ),
			'deal_value'        => $deal_value,
			'value_mode'        => $value_mode,
			'currency'          => $this->normalize_currency( (string) ( $args['currency'] ?? $previous['currency'] ?? $pipeline['currency'] ?? 'USD' ) ),
			'status'            => $status,
			'expected_close_at' => $this->normalize_utc_date( (string) ( $args['expected_close_at'] ?? $previous['expected_close_at'] ?? '' ) ),
			'closed_at'         => $closed_at,
			'stage_reason'      => $reason,
			'assigned_user_id'  => $assignee['user_id'] > 0 ? $assignee['user_id'] : null,
			'assigned_role'     => '' !== $assignee['role'] ? $assignee['role'] : null,
			'source'            => $source,
			'updated_by'        => $actor_id > 0 ? $actor_id : null,
			'updated_at'        => $now,
		);

		if ( null === $previous ) {
			$insert = array_merge(
				$tenant,
				$data,
				array(
					'created_by' => $actor_id > 0 ? $actor_id : null,
					'created_at' => $now,
				)
			);
			$saved  = (bool) $this->db->insert(
				$this->tables['deals'],
				$insert,
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
			);
			if ( $saved ) {
				$deal_id = absint( $this->db->insert_id );
			}
		} else {
			$saved = false !== $this->db->update(
				$this->tables['deals'],
				$data,
				array_merge( array( 'id' => $deal_id ), $tenant ),
				array( '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		if ( ! $saved ) {
			return array(
				'success' => false,
				'error'   => 'deal_save_failed',
			);
		}

		if ( array_key_exists( 'contact_ids', $args ) ) {
			$this->replace_deal_contacts( $deal_id, $tenant, (array) $args['contact_ids'], absint( $args['primary_contact_id'] ?? 0 ) );
		} elseif ( null === $previous && absint( $args['contact_id'] ?? 0 ) > 0 ) {
			$this->replace_deal_contacts( $deal_id, $tenant, array( absint( $args['contact_id'] ) ), absint( $args['contact_id'] ) );
		}
		if ( is_array( $products ) ) {
			$this->replace_deal_products( $deal_id, $tenant, $products );
		}

		$deal   = $this->get_deal( $deal_id, $tenant );
		$result = array(
			'success'  => true,
			'created'  => null === $previous,
			'deal'     => $deal,
			'previous' => $previous,
		);
		if ( null === $previous || absint( $previous['stage_id'] ?? 0 ) !== absint( $deal['stage_id'] ?? 0 ) ) {
			$this->record_stage_history( $deal, $previous, $reason, $source, $actor_id );
		}
		$this->record_deal_activity( $result, $args, $source, $actor_id );
		if ( null === $previous ) {
			do_action( 'nxtcc_crm_deal_created', $result, $args );
		} else {
			do_action( 'nxtcc_crm_deal_updated', $result, $args );
		}
		return $result;
	}

	/**
	 * Replace linked contacts for one deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $contact_ids Contact IDs.
	 * @param int   $primary_contact_id Primary contact ID.
	 * @return void
	 */
	private function replace_deal_contacts( int $deal_id, array $tenant, array $contact_ids, int $primary_contact_id ): void {
		$contact_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) ), 0, 100 );
		$valid       = array();
		foreach ( $contact_ids as $contact_id ) {
			if ( $this->contact_exists( $contact_id, $tenant ) ) {
				$valid[] = $contact_id;
			}
		}
		if ( ! in_array( $primary_contact_id, $valid, true ) ) {
			$primary_contact_id = $valid[0] ?? 0;
		}

		$this->db->delete( $this->tables['contacts'], array_merge( array( 'deal_id' => $deal_id ), $tenant ), array( '%d', '%s', '%s', '%s' ) );
		foreach ( $valid as $contact_id ) {
			$this->db->insert(
				$this->tables['contacts'],
				array_merge(
					$tenant,
					array(
						'deal_id'    => $deal_id,
						'contact_id' => $contact_id,
						'is_primary' => $contact_id === $primary_contact_id ? 1 : 0,
						'created_at' => current_time( 'mysql', true ),
					)
				),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Replace products for one deal.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @param array $products Product rows.
	 * @return void
	 */
	private function replace_deal_products( int $deal_id, array $tenant, array $products ): void {
		$this->db->delete( $this->tables['products'], array_merge( array( 'deal_id' => $deal_id ), $tenant ), array( '%d', '%s', '%s', '%s' ) );
		$now = current_time( 'mysql', true );
		foreach ( array_slice( $products, 0, 100 ) as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$name     = substr( sanitize_text_field( (string) ( $product['product_name'] ?? '' ) ), 0, 191 );
			$quantity = max( 1, (int) floor( (float) ( $product['quantity'] ?? 1 ) ) );
			$price    = max( 0, (float) ( $product['unit_price'] ?? 0 ) );
			$item_id  = substr( sanitize_text_field( (string) ( $product['source_item_id'] ?? '' ) ), 0, 191 );
			$label    = substr( sanitize_text_field( (string) ( $product['quantity_label'] ?? '' ) ), 0, 100 );
			if ( '' === $name ) {
				continue;
			}
			$this->db->insert(
				$this->tables['products'],
				array_merge(
					$tenant,
					array(
						'deal_id'         => $deal_id,
						'product_id'      => $this->nullable_id( $product['product_id'] ?? 0 ),
						'source_key'      => sanitize_key( (string) ( $product['source_key'] ?? 'manual' ) ),
						'source_item_id'  => '' !== $item_id ? $item_id : null,
						'product_name'    => $name,
						'quantity_type'   => sanitize_key( (string) ( $product['quantity_type'] ?? 'unit' ) ),
						'quantity_label'  => '' !== $label ? $label : null,
						'quantity'        => $quantity,
						'unit_price'      => $price,
						'line_total'      => $quantity * $price,
						'currency'        => $this->normalize_currency( (string) ( $product['currency'] ?? 'USD' ) ),
						'source_metadata' => wp_json_encode( $product['source_metadata'] ?? array() ),
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Read linked contacts.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_deal_contacts( int $deal_id, array $tenant ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT dc.contact_id, dc.is_primary, c.name, c.country_code, c.phone_number
				FROM ' . $this->quote_table( $this->tables['contacts'] ) . ' dc
				INNER JOIN ' . $this->quote_table( $this->tables['people'] ) . ' c ON c.id = dc.contact_id
				WHERE dc.deal_id = %d AND dc.user_mailid = %s AND dc.business_account_id = %s AND dc.phone_number_id = %s
				ORDER BY dc.is_primary DESC, dc.id ASC',
				$deal_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Read linked products.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_deal_products( int $deal_id, array $tenant ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT id, product_id, source_key, source_item_id, product_name, quantity_type, quantity_label, quantity, unit_price, line_total, currency, source_metadata
				FROM ' . $this->quote_table( $this->tables['products'] ) . '
				WHERE deal_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY id ASC',
				$deal_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$metadata               = json_decode( (string) ( $row['source_metadata'] ?? '' ), true );
			$row['source_metadata'] = is_array( $metadata ) ? $metadata : array();
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Sum persisted deal line items.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @return float
	 */
	private function sum_deal_products( int $deal_id, array $tenant ): float {
		return max(
			0,
			(float) $this->db->get_var(
				$this->db->prepare(
					'SELECT SUM(line_total) FROM ' . $this->quote_table( $this->tables['products'] ) . '
					WHERE deal_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
					$deal_id,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			)
		);
	}

	/**
	 * Read a bounded stage-transition history.
	 *
	 * @param int   $deal_id Deal ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_stage_history( int $deal_id, array $tenant ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT h.*, previous_stage.stage_name previous_stage_name, next_stage.stage_name stage_name
				FROM ' . $this->quote_table( $this->tables['history'] ) . ' h
				LEFT JOIN ' . $this->quote_table( $this->tables['stages'] ) . ' previous_stage ON previous_stage.id = h.previous_stage_id
				LEFT JOIN ' . $this->quote_table( $this->tables['stages'] ) . ' next_stage ON next_stage.id = h.stage_id
				WHERE h.deal_id = %d AND h.user_mailid = %s AND h.business_account_id = %s AND h.phone_number_id = %s
				ORDER BY h.id DESC LIMIT 100',
				$deal_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record an authoritative deal stage transition.
	 *
	 * @param array      $deal Saved deal.
	 * @param array|null $previous Previous deal.
	 * @param string     $reason Stage reason.
	 * @param string     $source Mutation source.
	 * @param int        $actor_id Actor ID.
	 * @return void
	 */
	private function record_stage_history( array $deal, ?array $previous, string $reason, string $source, int $actor_id ): void {
		$this->db->insert(
			$this->tables['history'],
			array_merge(
				$this->normalize_tenant( $deal ),
				array(
					'deal_id'              => absint( $deal['id'] ?? 0 ),
					'previous_pipeline_id' => $this->nullable_id( $previous['pipeline_id'] ?? 0 ),
					'previous_stage_id'    => $this->nullable_id( $previous['stage_id'] ?? 0 ),
					'pipeline_id'          => absint( $deal['pipeline_id'] ?? 0 ),
					'stage_id'             => absint( $deal['stage_id'] ?? 0 ),
					'reason'               => '' !== $reason ? $reason : null,
					'source'               => $source,
					'changed_by'           => $actor_id > 0 ? $actor_id : null,
					'changed_at'           => current_time( 'mysql', true ),
				)
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Normalize and verify line items before storage.
	 *
	 * @param array $products Raw line items.
	 * @param array $tenant Tenant tuple.
	 * @return array{items:array<int,array<string,mixed>>,invalid:bool}
	 */
	private function normalize_deal_products( array $products, array $tenant ): array {
		$providers = NXTCC_CRM_Deal_Item_Providers::get_providers();
		$output    = array();
		$invalid   = false;
		foreach ( array_slice( $products, 0, 100 ) as $product ) {
			if ( ! is_array( $product ) ) {
				$invalid = true;
				continue;
			}
			$source_key     = sanitize_key( (string) ( $product['source_key'] ?? 'manual' ) );
			$source_key     = '' !== $source_key ? $source_key : 'manual';
			$source_item_id = substr( sanitize_text_field( (string) ( $product['source_item_id'] ?? '' ) ), 0, 191 );
			$resolved       = 'manual' !== $source_key && '' !== $source_item_id
				? NXTCC_CRM_Deal_Item_Providers::resolve( $source_key, $source_item_id, $tenant )
				: null;
			$provider       = $providers[ $source_key ] ?? array();
			if ( 'manual' !== $source_key && isset( $providers[ $source_key ] ) && ! empty( $providers[ $source_key ]['available'] ) && ! is_array( $resolved ) ) {
				$invalid = true;
				continue;
			}
			$name          = substr( sanitize_text_field( (string) ( $resolved['label'] ?? $product['product_name'] ?? '' ) ), 0, 191 );
			$quantity_type = sanitize_key( (string) ( $product['quantity_type'] ?? $resolved['quantity_type'] ?? 'unit' ) );
			$allowed_types = isset( $provider['quantity_types'] ) && is_array( $provider['quantity_types'] ) ? $provider['quantity_types'] : array( 'unit' => 'Unit' );
			if ( ! isset( $allowed_types[ $quantity_type ] ) ) {
				$quantity_type = 'unit';
			}
			$quantity = max( 1, (int) floor( (float) ( $product['quantity'] ?? 1 ) ) );
			$price    = max( 0, (float) ( $product['unit_price'] ?? $resolved['unit_value'] ?? 0 ) );
			if ( '' === $name ) {
				$invalid = true;
				continue;
			}
			$output[] = array(
				'product_id'      => 'woocommerce' === $source_key ? absint( $source_item_id ) : 0,
				'source_key'      => $source_key,
				'source_item_id'  => $source_item_id,
				'product_name'    => $name,
				'quantity_type'   => $quantity_type,
				'quantity_label'  => 'custom' === $quantity_type ? substr( sanitize_text_field( (string) ( $product['quantity_label'] ?? '' ) ), 0, 100 ) : ( $allowed_types[ $quantity_type ] ?? 'Unit' ),
				'quantity'        => $quantity,
				'unit_price'      => $price,
				'line_total'      => $quantity * $price,
				'currency'        => $this->normalize_currency( (string) ( $product['currency'] ?? $resolved['currency'] ?? 'USD' ) ),
				'source_metadata' => is_array( $resolved['metadata'] ?? null )
					? $resolved['metadata']
					: $this->normalize_source_metadata( $product['source_metadata'] ?? array() ),
			);
		}
		return array(
			'items'   => $output,
			'invalid' => $invalid,
		);
	}

	/**
	 * Whether a transition requires a reason.
	 *
	 * @param array|null $previous Previous deal.
	 * @param array      $target_stage Target stage.
	 * @return bool
	 */
	private function stage_transition_requires_reason( ?array $previous, array $target_stage ): bool {
		if ( is_array( $previous ) && absint( $previous['stage_id'] ?? 0 ) === absint( $target_stage['id'] ?? 0 ) ) {
			return false;
		}
		$target_requirement = $this->normalize_reason_requirement( (string) ( $target_stage['reason_requirement'] ?? 'optional' ) );
		if ( in_array( $target_requirement, array( 'enter', 'both' ), true ) ) {
			return true;
		}
		if ( ! is_array( $previous ) ) {
			return false;
		}
		$previous_stage = $this->get_stage( absint( $previous['stage_id'] ?? 0 ), $this->normalize_tenant( $previous ) );
		$requirement    = $this->normalize_reason_requirement( (string) ( $previous_stage['reason_requirement'] ?? 'optional' ) );
		return in_array( $requirement, array( 'leave', 'both' ), true );
	}

	/**
	 * Normalize a bounded external line-item snapshot.
	 *
	 * @param mixed $metadata Raw metadata.
	 * @return array<string,string>
	 */
	private function normalize_source_metadata( $metadata ): array {
		$output = array();
		foreach ( is_array( $metadata ) ? array_slice( $metadata, 0, 20, true ) : array() as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && is_scalar( $value ) ) {
				$output[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
			}
		}
		return $output;
	}

	/**
	 * Keep only one default pipeline per tenant.
	 *
	 * @param int   $pipeline_id Current default.
	 * @param array $tenant Tenant tuple.
	 * @return void
	 */
	private function clear_other_default_pipelines( int $pipeline_id, array $tenant ): void {
		$this->db->query(
			$this->db->prepare(
				'UPDATE ' . $this->quote_table( $this->tables['pipelines'] ) . ' SET is_default = 0
				WHERE id <> %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s',
				$pipeline_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			)
		);
	}

	/**
	 * Ensure every tenant has a usable default pipeline.
	 *
	 * @param array $tenant Tenant tuple.
	 * @return void
	 */
	private function ensure_defaults( array $tenant ): void {
		$pipeline = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->tables['pipelines'] ) . '
				WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY is_default DESC, id ASC LIMIT 1',
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $pipeline ) ) {
			$result   = $this->upsert_pipeline(
				array_merge(
					$tenant,
					array(
						'pipeline_name' => 'Sales',
						'pipeline_slug' => 'sales',
						'is_default'    => 1,
						'source'        => 'system',
					)
				)
			);
			$pipeline = isset( $result['pipeline'] ) && is_array( $result['pipeline'] ) ? $result['pipeline'] : array();
		}

		$pipeline_id = absint( $pipeline['id'] ?? 0 );
		if ( $pipeline_id <= 0 || ! empty( $this->list_stages_without_defaults( $pipeline_id, $tenant ) ) ) {
			return;
		}

		$defaults = array(
			array(
				'stage_name'  => 'New',
				'stage_slug'  => 'new',
				'probability' => 10,
				'sort_order'  => 10,
				'color'       => '#2271b1',
			),
			array(
				'stage_name'  => 'Qualified',
				'stage_slug'  => 'qualified',
				'probability' => 30,
				'sort_order'  => 20,
				'color'       => '#3858e9',
			),
			array(
				'stage_name'  => 'Proposal',
				'stage_slug'  => 'proposal',
				'probability' => 60,
				'sort_order'  => 30,
				'color'       => '#8a4b00',
			),
			array(
				'stage_name'  => 'Negotiation',
				'stage_slug'  => 'negotiation',
				'probability' => 80,
				'sort_order'  => 40,
				'color'       => '#9b51e0',
			),
			array(
				'stage_name'  => 'Won',
				'stage_slug'  => 'won',
				'probability' => 100,
				'sort_order'  => 50,
				'stage_type'  => 'won',
				'color'       => '#008a20',
			),
			array(
				'stage_name'  => 'Lost',
				'stage_slug'  => 'lost',
				'probability' => 0,
				'sort_order'  => 60,
				'stage_type'  => 'lost',
				'color'       => '#646970',
			),
		);
		foreach ( $defaults as $stage ) {
			$this->upsert_stage(
				array_merge(
					$tenant,
					$stage,
					array(
						'pipeline_id' => $pipeline_id,
						'source'      => 'system',
					)
				)
			);
		}
	}

	/**
	 * List stages without recursively ensuring defaults.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_stages_without_defaults( int $pipeline_id, array $tenant ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->tables['stages'] ) . '
				WHERE pipeline_id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s
				ORDER BY sort_order ASC, id ASC',
				$pipeline_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Read a pipeline.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_pipeline( int $pipeline_id, array $tenant ): ?array {
		if ( $pipeline_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->tables['pipelines'] ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$pipeline_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read a stage.
	 *
	 * @param int   $stage_id Stage ID.
	 * @param array $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	private function get_stage( int $stage_id, array $tenant ): ?array {
		if ( $stage_id <= 0 || ! $this->tenant_is_complete( $tenant ) ) {
			return null;
		}
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->quote_table( $this->tables['stages'] ) . '
				WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
				$stage_id,
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id']
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Add supported filters to a deal query.
	 *
	 * @param string $sql SQL string.
	 * @param array  $query Query parameters.
	 * @param array  $args Filter arguments.
	 * @return void
	 */
	private function append_deal_filters( string &$sql, array &$query, array $args ): void {
		$pipeline_id = absint( $args['pipeline_id'] ?? 0 );
		$stage_id    = absint( $args['stage_id'] ?? 0 );
		$contact_id  = absint( $args['contact_id'] ?? 0 );
		$status      = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$search      = substr( sanitize_text_field( (string) ( $args['search'] ?? '' ) ), 0, 120 );

		if ( $pipeline_id > 0 ) {
			$sql    .= ' AND d.pipeline_id = %d';
			$query[] = $pipeline_id;
		}
		if ( $stage_id > 0 ) {
			$sql    .= ' AND d.stage_id = %d';
			$query[] = $stage_id;
		}
		if ( $contact_id > 0 ) {
			$sql    .= ' AND dc.contact_id = %d';
			$query[] = $contact_id;
		}
		if ( in_array( $status, array( 'open', 'won', 'lost' ), true ) ) {
			$sql    .= ' AND d.status = %s';
			$query[] = $status;
		}
		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$sql    .= ' AND (d.title LIKE %s OR c.name LIKE %s OR c.phone_number LIKE %s)';
			$query[] = $like;
			$query[] = $like;
			$query[] = $like;
		}
	}

	/**
	 * Controlled joined deal SELECT.
	 *
	 * @return string
	 */
	private function deal_select_sql(): string {
		return 'SELECT DISTINCT d.*, p.pipeline_name, s.stage_name, s.color stage_color, s.probability,
				c.id primary_contact_id, c.name primary_contact_name, c.country_code primary_country_code, c.phone_number primary_phone_number
			FROM ' . $this->quote_table( $this->tables['deals'] ) . ' d
			INNER JOIN ' . $this->quote_table( $this->tables['pipelines'] ) . ' p ON p.id = d.pipeline_id
			INNER JOIN ' . $this->quote_table( $this->tables['stages'] ) . ' s ON s.id = d.stage_id
			LEFT JOIN ' . $this->quote_table( $this->tables['contacts'] ) . ' dc ON dc.deal_id = d.id
			LEFT JOIN ' . $this->quote_table( $this->tables['people'] ) . ' c ON c.id = dc.contact_id AND dc.is_primary = 1';
	}

	/**
	 * Decorate deal list rows.
	 *
	 * @param array $rows Deal rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_deal_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			$user_id            = absint( $row['assigned_user_id'] ?? 0 );
			$user               = $user_id > 0 ? get_userdata( $user_id ) : false;
			$row['owner_label'] = $user instanceof WP_User
				? sanitize_text_field( $user->display_name )
				: sanitize_text_field( (string) ( $row['assigned_role'] ?? '' ) );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Record deal activity for all linked contacts.
	 *
	 * @param array  $result Save result.
	 * @param array  $args Original arguments.
	 * @param string $source Source.
	 * @param int    $actor_id Actor user ID.
	 * @return void
	 */
	private function record_deal_activity( array $result, array $args, string $source, int $actor_id ): void {
		$deal     = isset( $result['deal'] ) && is_array( $result['deal'] ) ? $result['deal'] : array();
		$previous = isset( $result['previous'] ) && is_array( $result['previous'] ) ? $result['previous'] : array();
		$type     = ! empty( $result['created'] ) ? 'deal_created' : 'deal_updated';
		if ( ! empty( $previous ) && absint( $previous['stage_id'] ?? 0 ) !== absint( $deal['stage_id'] ?? 0 ) ) {
			$type = 'deal_stage_changed';
		}
		if ( 'won' === (string) ( $deal['status'] ?? '' ) && 'won' !== (string) ( $previous['status'] ?? '' ) ) {
			$type = 'deal_won';
		} elseif ( 'lost' === (string) ( $deal['status'] ?? '' ) && 'lost' !== (string) ( $previous['status'] ?? '' ) ) {
			$type = 'deal_lost';
		}

		foreach ( (array) ( $deal['contacts'] ?? array() ) as $contact ) {
			NXTCC_CRM_Activities::instance()->record(
				array_merge(
					$this->normalize_tenant( $deal ),
					array(
						'contact_id'    => absint( $contact['contact_id'] ?? 0 ),
						'deal_id'       => absint( $deal['id'] ?? 0 ),
						'activity_type' => $type,
						'source'        => $source,
						'actor_id'      => $actor_id,
						'metadata'      => array(
							'deal_title'        => sanitize_text_field( (string) ( $deal['title'] ?? '' ) ),
							'deal_value'        => (float) ( $deal['deal_value'] ?? 0 ),
							'currency'          => sanitize_text_field( (string) ( $deal['currency'] ?? '' ) ),
							'status'            => sanitize_key( (string) ( $deal['status'] ?? '' ) ),
							'stage_name'        => sanitize_text_field( (string) ( $deal['stage_name'] ?? '' ) ),
							'previous_stage_id' => absint( $previous['stage_id'] ?? 0 ),
						),
					)
				)
			);
		}
	}

	/**
	 * Whether a contact belongs to a tenant.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $tenant Tenant tuple.
	 * @return bool
	 */
	private function contact_exists( int $contact_id, array $tenant ): bool {
		return 0 < $contact_id && absint(
			$this->db->get_var(
				$this->db->prepare(
					'SELECT id FROM ' . $this->quote_table( $this->tables['people'] ) . '
					WHERE id = %d AND user_mailid = %s AND business_account_id = %s AND phone_number_id = %s LIMIT 1',
					$contact_id,
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id']
				)
			)
		) === $contact_id;
	}

	/**
	 * Normalize tenant tuple.
	 *
	 * @param array $args Raw arguments.
	 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
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
		return ! in_array( '', $tenant, true );
	}

	/**
	 * Normalize status/stage type.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, array( 'open', 'won', 'lost' ), true ) ? $status : 'open';
	}

	/**
	 * Normalize a stage reason requirement.
	 *
	 * @param string $requirement Raw requirement.
	 * @return string
	 */
	private function normalize_reason_requirement( string $requirement ): string {
		$requirement = sanitize_key( $requirement );
		return in_array( $requirement, array( 'none', 'optional', 'enter', 'leave', 'both' ), true ) ? $requirement : 'optional';
	}

	/**
	 * Normalize deal value calculation mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private function normalize_value_mode( string $mode ): string {
		return 'calculated' === sanitize_key( $mode ) ? 'calculated' : 'manual';
	}

	/**
	 * Normalize currency.
	 *
	 * @param string $currency Raw currency.
	 * @return string
	 */
	private function normalize_currency( string $currency ): string {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) );
		return 3 === strlen( $currency ) ? $currency : 'USD';
	}

	/**
	 * Normalize a UTC-compatible date value.
	 *
	 * @param string $value Date value.
	 * @return string|null
	 */
	private function normalize_utc_date( string $value ): ?string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Normalize source.
	 *
	 * @param string $source Raw source.
	 * @return string
	 */
	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return '' !== $source ? substr( $source, 0, 30 ) : 'integration';
	}

	/**
	 * Normalize actor to a tenant user.
	 *
	 * @param int   $actor_id Actor user ID.
	 * @param array $tenant Tenant tuple.
	 * @return int
	 */
	private function normalize_actor_id( int $actor_id, array $tenant ): int {
		if ( $actor_id <= 0 || ! get_userdata( $actor_id ) ) {
			return 0;
		}
		$access = NXTCC_Tenant_Access_DAO::get_user_access( $actor_id, $tenant );
		return is_array( $access ) ? $actor_id : 0;
	}

	/**
	 * Validate a requested deal owner against tenant assignment targets.
	 *
	 * @param mixed $user_id Raw user ID.
	 * @param mixed $role Raw role.
	 * @param array $tenant Tenant tuple.
	 * @return array{valid:bool,user_id:int,role:string}
	 */
	private function normalize_assignee( $user_id, $role, array $tenant ): array {
		$user_id = absint( $user_id );
		$role    = sanitize_key( (string) $role );
		if ( $user_id <= 0 && '' === $role ) {
			return array(
				'valid'   => true,
				'user_id' => 0,
				'role'    => '',
			);
		}

		$targets = NXTCC_Contact_Assignments::instance()->list_targets( $tenant );
		foreach ( (array) ( $targets['users'] ?? array() ) as $target ) {
			if ( $user_id > 0 && absint( $target['id'] ?? 0 ) === $user_id ) {
				return array(
					'valid'   => true,
					'user_id' => $user_id,
					'role'    => '',
				);
			}
		}
		foreach ( (array) ( $targets['roles'] ?? array() ) as $target ) {
			if ( '' !== $role && sanitize_key( (string) ( $target['key'] ?? '' ) ) === $role ) {
				return array(
					'valid'   => true,
					'user_id' => 0,
					'role'    => $role,
				);
			}
		}
		return array(
			'valid'   => false,
			'user_id' => 0,
			'role'    => '',
		);
	}

	/**
	 * Return nullable ID.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function nullable_id( $value ): ?int {
		$value = absint( $value );
		return $value > 0 ? $value : null;
	}

	/**
	 * Return nullable key.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function nullable_key( $value ): ?string {
		$value = sanitize_key( (string) $value );
		return '' !== $value ? substr( $value, 0, 50 ) : null;
	}

	/**
	 * Quote a controlled table identifier.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private function quote_table( string $table ): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		return '`' . ( is_string( $table ) && '' !== $table ? $table : 'nxtcc_invalid' ) . '`';
	}
}
