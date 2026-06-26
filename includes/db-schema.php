<?php
/**
 * Database schema installer.
 *
 * Creates or updates the NXT Cloud Chat database tables using dbDelta().
 * Schema definitions should remain in sync with Pro.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_run_dbdelta_sql' ) ) {
	/**
	 * Run a single dbDelta statement, loading core upgrade helpers when needed.
	 *
	 * @param string $sql SQL statement.
	 * @return void
	 */
	function nxtcc_run_dbdelta_sql( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		dbDelta( $sql );
	}
}

if ( ! function_exists( 'nxtcc_schema_current_prefix' ) ) {
	/**
	 * Resolve the active site table prefix.
	 *
	 * Uses get_blog_prefix() when available to avoid edge cases where
	 * $wpdb->prefix may be altered in non-standard bootstrap contexts.
	 *
	 * @return string
	 */
	function nxtcc_schema_current_prefix(): string {
		global $wpdb;

		$prefix = '';

		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) && function_exists( 'get_current_blog_id' ) ) {
			$prefix = (string) $wpdb->get_blog_prefix( (int) get_current_blog_id() );
		}

		if ( '' === $prefix && isset( $wpdb->prefix ) ) {
			$prefix = (string) $wpdb->prefix;
		}

		$prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $prefix );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			return '';
		}

		return $prefix;
	}
}

if ( ! function_exists( 'nxtcc_upgrade_ticket_schema' ) ) {
	/**
	 * Apply ticket schema changes that dbDelta cannot express safely.
	 *
	 * @param string $prefix Current site table prefix.
	 * @return void
	 */
	function nxtcc_upgrade_ticket_schema( string $prefix ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration queries must inspect and update the database directly.
		$conversations = $prefix . 'nxtcc_conversations';
		$state         = $prefix . 'nxtcc_contact_ticket_state';
		$categories    = $prefix . 'nxtcc_ticket_categories';
		$legacy_index  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$conversations,
				'uq_conversation_contact_channel'
			)
		);

		if ( $legacy_index > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required one-time index migration.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX `uq_conversation_contact_channel`', $conversations ) );
		}

		$state_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables
				WHERE table_schema = DATABASE() AND table_name = %s',
				$state
			)
		);
		if ( $state_exists <= 0 ) {
			return;
		}
		$categories_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables
				WHERE table_schema = DATABASE() AND table_name = %s',
				$categories
			)
		);

		/*
		 * Existing installations had one ticket per contact. Seeding the latest
		 * ticket keeps inbound routing stable after enabling multiple tickets.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i
				(user_mailid, business_account_id, phone_number_id, contact_id, channel, current_conversation_id, updated_by, updated_at)
			SELECT c.user_mailid, c.business_account_id, c.phone_number_id, c.contact_id, c.channel, c.id, NULL, c.updated_at
			FROM %i c
			INNER JOIN (
				SELECT user_mailid, business_account_id, phone_number_id, contact_id, channel, MAX(id) AS current_id
				FROM %i
				GROUP BY user_mailid, business_account_id, phone_number_id, contact_id, channel
			) latest ON latest.current_id = c.id',
				$state,
				$conversations,
				$conversations
			)
		);

		if ( $categories_exists <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration.
		$legacy_categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT user_mailid, business_account_id, phone_number_id, category
				FROM %i
				WHERE category_id IS NULL AND category IS NOT NULL AND category <> ''
				ORDER BY category ASC LIMIT 5000",
				$conversations
			),
			ARRAY_A
		);
		foreach ( is_array( $legacy_categories ) ? $legacy_categories : array() as $legacy_category ) {
			$name = sanitize_text_field( (string) ( $legacy_category['category'] ?? '' ) );
			$slug = substr( sanitize_title( $name ), 0, 100 );
			if ( '' === $name || '' === $slug ) {
				continue;
			}

			$tenant_values = array(
				sanitize_email( (string) ( $legacy_category['user_mailid'] ?? '' ) ),
				sanitize_text_field( (string) ( $legacy_category['business_account_id'] ?? '' ) ),
				sanitize_text_field( (string) ( $legacy_category['phone_number_id'] ?? '' ) ),
			);
			$now           = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i
					(user_mailid, business_account_id, phone_number_id, category_name, category_slug, color, is_active, created_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)',
					$categories,
					$tenant_values[0],
					$tenant_values[1],
					$tenant_values[2],
					$name,
					$slug,
					'#2271b1',
					$now,
					$now
				)
			);
			$category_id = absint(
				$wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM %i
						WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND category_slug = %s LIMIT 1',
						$categories,
						$tenant_values[0],
						$tenant_values[1],
						$tenant_values[2],
						$slug
					)
				)
			);
			if ( $category_id <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET category_id = %d
					WHERE user_mailid = %s AND business_account_id = %s AND phone_number_id = %s AND category = %s AND category_id IS NULL',
					$conversations,
					$category_id,
					$tenant_values[0],
					$tenant_values[1],
					$tenant_values[2],
					$name
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}

if ( ! function_exists( 'nxtcc_install_db_schema' ) ) {
	/**
	 * Install / update NXTCC DB schema (Free).
	 *
	 * @return void
	 */
	function nxtcc_install_db_schema(): void {
		global $wpdb;

		$nxtcc_prefix = nxtcc_schema_current_prefix();
		if ( '' === $nxtcc_prefix ) {
			return;
		}
		$nxtcc_charset_collate = $wpdb->get_charset_collate();

		$nxtcc_tables = array(

			/* ---------------------------- Contacts ---------------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_contacts (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  wp_uid BIGINT(20) UNSIGNED NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  country_code VARCHAR(10) NOT NULL,
  phone_number VARCHAR(30) NOT NULL,
  name VARCHAR(255) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  is_subscribed TINYINT(1) NOT NULL DEFAULT 1,
  unsubscribed_at DATETIME NULL,
  unsubscribed_reason VARCHAR(255) NULL,
  group_ids TEXT NULL,
  custom_fields LONGTEXT NULL,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_contact (user_mailid(191), business_account_id(191), phone_number_id(191), country_code, phone_number),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_wp_uid (wp_uid),
  KEY idx_contacts_created_by (created_by),
  KEY idx_contacts_updated_by (updated_by),
  KEY idx_contacts_created_at (created_at),
  KEY idx_contacts_name (name(191)),
  KEY idx_contacts_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  KEY idx_contacts_tenant_created (user_mailid(100), business_account_id(100), phone_number_id(100), created_at),
  KEY idx_contacts_is_subscribed (is_subscribed),
  KEY idx_contacts_unsubscribed_at (unsubscribed_at)
) {$nxtcc_charset_collate};",

			/*
			----------------------------- Groups -----------------------------
		*/

			/*
			 * Groups are tenant-scoped (user_mailid + business_account_id + phone_number_id).
			 */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_groups (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  group_name VARCHAR(255) NOT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_groupname (user_mailid(191), business_account_id(191), phone_number_id(191), group_name(191)),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_groups_created_by (created_by),
  KEY idx_groups_updated_by (updated_by),
  KEY idx_groups_is_verified (is_verified),
  KEY idx_groups_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/*
			---------------------- Group -> Contact map -----------------------
		*/

			/*
			 * Group-contact mapping is also tenant-scoped to prevent cross-tenant joins.
			 */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_group_contact_map (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  group_id BIGINT(20) UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_group (user_mailid(191), business_account_id(191), phone_number_id(191), contact_id, group_id),
  KEY idx_group_id (group_id),
  KEY idx_contact_id (contact_id),
  KEY idx_gcm_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/*
			------------------------------ Tags ------------------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_tags (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  tag_name VARCHAR(191) NOT NULL,
  tag_slug VARCHAR(100) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
  description VARCHAR(500) NULL,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_tag_slug (user_mailid(100), business_account_id(100), phone_number_id(100), tag_slug),
  KEY idx_tags_tenant_name (user_mailid(100), business_account_id(100), phone_number_id(100), tag_name),
  KEY idx_tags_created_by (created_by),
  KEY idx_tags_updated_by (updated_by)
) {$nxtcc_charset_collate};",

			/*
			----------------------- Tag -> Contact map ------------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_tag_contact_map (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  tag_id BIGINT(20) UNSIGNED NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  assigned_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_tag (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, tag_id),
  KEY idx_tag_contact (tag_id, contact_id),
  KEY idx_contact_tag (contact_id, tag_id),
  KEY idx_tag_map_tenant (user_mailid(100), business_account_id(100), phone_number_id(100))
) {$nxtcc_charset_collate};",

			/*
			---------------------- Contact assignments -----------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_contact_assignments (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  assigned_user_id BIGINT(20) UNSIGNED NULL,
  assigned_role VARCHAR(50) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  assigned_by BIGINT(20) UNSIGNED NULL,
  assigned_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_assignment (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id),
  KEY idx_assignment_user (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_user_id),
  KEY idx_assignment_role (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_role),
  KEY idx_assignment_contact (contact_id)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_contact_assignment_history (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  previous_target_type VARCHAR(20) NULL,
  previous_assigned_user_id BIGINT(20) UNSIGNED NULL,
  previous_assigned_role VARCHAR(50) NULL,
  new_target_type VARCHAR(20) NULL,
  new_assigned_user_id BIGINT(20) UNSIGNED NULL,
  new_assigned_role VARCHAR(50) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  changed_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_assignment_history_contact (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, created_at),
  KEY idx_assignment_history_user (new_assigned_user_id, created_at),
  KEY idx_assignment_history_role (new_assigned_role, created_at)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_assignment_routing_state (
  route_hash CHAR(64) NOT NULL,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  route_key VARCHAR(191) NOT NULL,
  route_cursor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (route_hash),
  KEY idx_assignment_route_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), route_key(91)),
  KEY idx_assignment_route_updated (updated_at)
) {$nxtcc_charset_collate};",

			/*
			------------------------ CRM activity timeline --------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_activities (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  conversation_id BIGINT(20) UNSIGNED NULL,
  task_id BIGINT(20) UNSIGNED NULL,
  deal_id BIGINT(20) UNSIGNED NULL,
  activity_type VARCHAR(60) NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'system',
  actor_user_id BIGINT(20) UNSIGNED NULL,
  metadata_json LONGTEXT NULL,
  note_content LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_crm_activity_contact (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, id),
  KEY idx_crm_activity_type (user_mailid(100), business_account_id(100), phone_number_id(100), activity_type, created_at),
  KEY idx_crm_activity_conversation (conversation_id, id),
  KEY idx_crm_activity_task (task_id, id),
  KEY idx_crm_activity_deal (deal_id, id),
  KEY idx_crm_activity_actor (actor_user_id, created_at),
  KEY idx_crm_activity_created (created_at)
) {$nxtcc_charset_collate};",

			/*
			---------------------- CRM lifecycle stages ----------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_lifecycle_stages (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  stage_name VARCHAR(120) NOT NULL,
  stage_slug VARCHAR(80) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
  sort_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lifecycle_stage (user_mailid(100), business_account_id(100), phone_number_id(100), stage_slug),
  KEY idx_lifecycle_stage_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), is_active, sort_order)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_contact_lifecycle_stage (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  stage_id BIGINT(20) UNSIGNED NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  changed_by BIGINT(20) UNSIGNED NULL,
  changed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_lifecycle (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id),
  KEY idx_contact_lifecycle_stage (user_mailid(100), business_account_id(100), phone_number_id(100), stage_id, contact_id),
  KEY idx_contact_lifecycle_contact (contact_id)
) {$nxtcc_charset_collate};",

			/*
			---------------------------- CRM tasks ---------------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_tasks (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  conversation_id BIGINT(20) UNSIGNED NULL,
  title VARCHAR(191) NOT NULL,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  due_at DATETIME NULL,
  assigned_user_id BIGINT(20) UNSIGNED NULL,
  assigned_role VARCHAR(50) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  completed_by BIGINT(20) UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_crm_task_contact (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, status, due_at),
  KEY idx_crm_task_due (user_mailid(100), business_account_id(100), phone_number_id(100), status, due_at),
  KEY idx_crm_task_user (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_user_id, status),
  KEY idx_crm_task_role (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_role, status),
  KEY idx_crm_task_conversation (conversation_id, status)
) {$nxtcc_charset_collate};",

			/*
			---------------------- CRM saved contact views -------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_saved_views (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  owner_user_id BIGINT(20) UNSIGNED NOT NULL,
  view_name VARCHAR(120) NOT NULL,
  filters_json LONGTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_saved_view_name (user_mailid(100), business_account_id(100), phone_number_id(100), owner_user_id, view_name(80)),
  KEY idx_crm_saved_view_owner (user_mailid(100), business_account_id(100), phone_number_id(100), owner_user_id, is_default),
  KEY idx_crm_saved_view_updated (updated_at)
) {$nxtcc_charset_collate};",

			/*
			----------------------- CRM sales pipelines ----------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_pipelines (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  pipeline_name VARCHAR(120) NOT NULL,
  pipeline_slug VARCHAR(80) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_pipeline (user_mailid(100), business_account_id(100), phone_number_id(100), pipeline_slug),
  KEY idx_crm_pipeline_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), is_active, is_default)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_pipeline_stages (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  pipeline_id BIGINT(20) UNSIGNED NOT NULL,
  stage_name VARCHAR(120) NOT NULL,
  stage_slug VARCHAR(80) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
  probability TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
  stage_type VARCHAR(20) NOT NULL DEFAULT 'open',
  reason_requirement VARCHAR(20) NOT NULL DEFAULT 'optional',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_pipeline_stage (pipeline_id, stage_slug),
  KEY idx_crm_pipeline_stage_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), pipeline_id, is_active, sort_order),
  KEY idx_crm_pipeline_stage_type (pipeline_id, stage_type)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_deals (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  pipeline_id BIGINT(20) UNSIGNED NOT NULL,
  stage_id BIGINT(20) UNSIGNED NOT NULL,
  title VARCHAR(191) NOT NULL,
  description TEXT NULL,
  deal_value DECIMAL(20,6) NOT NULL DEFAULT 0,
  value_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  expected_close_at DATETIME NULL,
  closed_at DATETIME NULL,
  stage_reason VARCHAR(500) NULL,
  assigned_user_id BIGINT(20) UNSIGNED NULL,
  assigned_role VARCHAR(50) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_crm_deal_tenant_status (user_mailid(100), business_account_id(100), phone_number_id(100), status, updated_at),
  KEY idx_crm_deal_tenant_updated (user_mailid(100), business_account_id(100), phone_number_id(100), updated_at),
  KEY idx_crm_deal_pipeline_stage (user_mailid(100), business_account_id(100), phone_number_id(100), pipeline_id, stage_id, status),
  KEY idx_crm_deal_user (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_user_id, status),
  KEY idx_crm_deal_role (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_role, status),
  KEY idx_crm_deal_expected_close (status, expected_close_at)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_deal_contacts (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  deal_id BIGINT(20) UNSIGNED NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_deal_contact (deal_id, contact_id),
  KEY idx_crm_deal_contact_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, deal_id),
  KEY idx_crm_deal_contact_primary (deal_id, is_primary)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_deal_products (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  deal_id BIGINT(20) UNSIGNED NOT NULL,
  product_id BIGINT(20) UNSIGNED NULL,
  source_key VARCHAR(50) NOT NULL DEFAULT 'manual',
  source_item_id VARCHAR(191) NULL,
  product_name VARCHAR(191) NOT NULL,
  quantity_type VARCHAR(50) NOT NULL DEFAULT 'unit',
  quantity_label VARCHAR(100) NULL,
  quantity DECIMAL(20,6) NOT NULL DEFAULT 1,
  unit_price DECIMAL(20,6) NOT NULL DEFAULT 0,
  line_total DECIMAL(20,6) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  source_metadata LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_crm_deal_product_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), deal_id),
  KEY idx_crm_deal_product (product_id, deal_id),
  KEY idx_crm_deal_source_item (user_mailid(100), business_account_id(100), phone_number_id(100), source_key, source_item_id(100))
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_crm_deal_stage_history (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  deal_id BIGINT(20) UNSIGNED NOT NULL,
  previous_pipeline_id BIGINT(20) UNSIGNED NULL,
  previous_stage_id BIGINT(20) UNSIGNED NULL,
  pipeline_id BIGINT(20) UNSIGNED NOT NULL,
  stage_id BIGINT(20) UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  changed_by BIGINT(20) UNSIGNED NULL,
  changed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_crm_deal_stage_history (user_mailid(100), business_account_id(100), phone_number_id(100), deal_id, changed_at),
  KEY idx_crm_deal_stage_transition (user_mailid(100), business_account_id(100), phone_number_id(100), pipeline_id, stage_id, changed_at),
  KEY idx_crm_deal_previous_stage (user_mailid(100), business_account_id(100), phone_number_id(100), previous_pipeline_id, previous_stage_id, changed_at)
) {$nxtcc_charset_collate};",

			/*
			---------------------- Conversation tickets ----------------------
		*/

			"CREATE TABLE {$nxtcc_prefix}nxtcc_conversations (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  ticket_number VARCHAR(40) NOT NULL,
  subject VARCHAR(191) NULL,
  category_id BIGINT(20) UNSIGNED NULL,
  category VARCHAR(100) NULL,
  channel VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
  origin_source VARCHAR(30) NOT NULL DEFAULT 'system',
  status VARCHAR(20) NOT NULL DEFAULT 'unassigned',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  assigned_user_id BIGINT(20) UNSIGNED NULL,
  assigned_role VARCHAR(50) NULL,
  assignment_source VARCHAR(30) NOT NULL DEFAULT 'system',
  opened_at DATETIME NOT NULL,
  first_response_at DATETIME NULL,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  snoozed_until DATETIME NULL,
  first_response_due_at DATETIME NULL,
  resolution_due_at DATETIME NULL,
  last_inbound_at DATETIME NULL,
  last_outbound_at DATETIME NULL,
  last_message_at DATETIME NULL,
  reopen_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversation_ticket (ticket_number),
  KEY idx_conversation_contact_channel (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, channel, updated_at),
  KEY idx_conversation_tenant_status (user_mailid(100), business_account_id(100), phone_number_id(100), status, updated_at),
  KEY idx_conversation_tenant_updated (user_mailid(100), business_account_id(100), phone_number_id(100), updated_at),
  KEY idx_conversation_assigned_user (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_user_id, status),
  KEY idx_conversation_assigned_role (user_mailid(100), business_account_id(100), phone_number_id(100), assigned_role, status),
  KEY idx_conversation_contact (contact_id),
  KEY idx_conversation_origin (origin_source, created_at),
  KEY idx_conversation_priority (priority, status),
  KEY idx_conversation_sla (first_response_due_at, resolution_due_at)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_ticket_categories (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  category_name VARCHAR(100) NOT NULL,
  category_slug VARCHAR(100) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
  description VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_category_slug (user_mailid(100), business_account_id(100), phone_number_id(100), category_slug),
  KEY idx_ticket_category_active (user_mailid(100), business_account_id(100), phone_number_id(100), is_active, category_name)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_contact_ticket_state (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  contact_id BIGINT(20) UNSIGNED NOT NULL,
  channel VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
  current_conversation_id BIGINT(20) UNSIGNED NOT NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_ticket_state (user_mailid(100), business_account_id(100), phone_number_id(100), contact_id, channel),
  KEY idx_contact_ticket_current (current_conversation_id),
  KEY idx_contact_ticket_updated (user_mailid(100), business_account_id(100), phone_number_id(100), updated_at)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_conversation_assignment_history (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  conversation_id BIGINT(20) UNSIGNED NOT NULL,
  previous_assigned_user_id BIGINT(20) UNSIGNED NULL,
  previous_assigned_role VARCHAR(50) NULL,
  new_assigned_user_id BIGINT(20) UNSIGNED NULL,
  new_assigned_role VARCHAR(50) NULL,
  handoff_type VARCHAR(30) NOT NULL DEFAULT 'assignment',
  reason VARCHAR(191) NULL,
  note_activity_id BIGINT(20) UNSIGNED NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  changed_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_conversation_assignment_history (conversation_id, id),
  KEY idx_conversation_assignment_next_user (new_assigned_user_id, created_at),
  KEY idx_conversation_assignment_next_role (new_assigned_role, created_at),
  KEY idx_conversation_assignment_note (note_activity_id)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_conversation_watchers (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  conversation_id BIGINT(20) UNSIGNED NOT NULL,
  wp_user_id BIGINT(20) UNSIGNED NOT NULL,
  notification_preference VARCHAR(30) NOT NULL DEFAULT 'all',
  added_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversation_watcher (conversation_id, wp_user_id),
  KEY idx_conversation_watchers_tenant (user_mailid(100), business_account_id(100), phone_number_id(100), wp_user_id),
  KEY idx_conversation_watchers_conversation (conversation_id)
) {$nxtcc_charset_collate};",

			/* -------------------------- Message history ----------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_message_history (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id BIGINT(20) UNSIGNED NULL,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  group_ids TEXT NULL,
  contact_id BIGINT(20) UNSIGNED NULL,
  conversation_id BIGINT(20) UNSIGNED NULL,
  display_phone_number VARCHAR(30) NULL,
  template_id VARCHAR(255) NULL,
  template_name VARCHAR(255) NULL,
  template_type VARCHAR(50) NULL,
  template_data LONGTEXT NULL,
  message_content LONGTEXT NULL,
  status VARCHAR(50) NULL,
  status_timestamps LONGTEXT NULL,
  last_error TEXT NULL,
  origin_type VARCHAR(30) NULL,
  origin_user_id BIGINT(20) UNSIGNED NULL,
  origin_ref VARCHAR(191) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  failed_at DATETIME NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  retrying_at DATETIME NULL,
  meta_message_id VARCHAR(191) DEFAULT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  reply_to_wamid VARCHAR(191) NULL,
  reply_to_history_id BIGINT(20) UNSIGNED NULL,
  reply_preview TEXT NULL,
  response_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY idx_queue_contact (queue_id, contact_id),
  KEY idx_queue_id (queue_id),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_status (status),
  KEY idx_origin_type (origin_type),
  KEY idx_origin_user_id (origin_user_id),
  KEY idx_origin_ref (origin_ref),
  KEY idx_created_at (created_at),
  KEY idx_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  KEY idx_deleted_at (deleted_at),
  KEY idx_favorite (is_favorite),
  KEY idx_meta_message_id (meta_message_id),
  KEY idx_reply_wamid (reply_to_wamid),
  KEY idx_reply_history_id (reply_to_history_id),
  KEY idx_contact_id (contact_id),
  KEY idx_conversation_thread (conversation_id, id),
  KEY idx_thread_poll (contact_id, user_mailid(191), phone_number_id(191), deleted_at, id)
) {$nxtcc_charset_collate};",

			/* -------------------------- User settings ------------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_user_settings (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  app_id VARCHAR(64) NOT NULL,
  access_token_ct LONGTEXT NULL,
  access_token_nonce BINARY(24) NULL,
  app_secret_ct LONGTEXT NULL,
  app_secret_nonce BINARY(24) NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  phone_number VARCHAR(30) NULL,
  waba_verified TINYINT(1) NOT NULL DEFAULT 0,
  meta_webhook_verify_token_hash CHAR(64) NULL,
  meta_webhook_subscribed TINYINT(1) NOT NULL DEFAULT 0,
  token_expires_at DATETIME NULL,
  crypto_algo VARCHAR(16) NOT NULL DEFAULT 'secretbox',
  kdf VARCHAR(16) NOT NULL DEFAULT 'hkdf256',
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_app_id (app_id),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_settings_created_by (created_by),
  KEY idx_settings_updated_by (updated_by),
  KEY idx_phone_number_id (phone_number_id(191)),
  KEY idx_business_account_id (business_account_id(191)),
  KEY idx_created_at (created_at),
  KEY idx_settings_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  UNIQUE KEY uq_settings_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/* ------------------------ Tenant user access ---------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_access_teams (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  team_key VARCHAR(50) NOT NULL,
  label VARCHAR(120) NOT NULL,
  description TEXT NULL,
  action_level VARCHAR(20) NOT NULL DEFAULT 'manage',
  data_scope VARCHAR(20) NOT NULL DEFAULT 'all',
  capabilities_json LONGTEXT NULL,
  capability_scopes_json LONGTEXT NULL,
  assignment_eligible TINYINT(1) NOT NULL DEFAULT 1,
  is_protected TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_team (user_mailid(100), business_account_id(100), phone_number_id(100), team_key),
  KEY idx_access_team_tenant (user_mailid(100), business_account_id(100), phone_number_id(100)),
  KEY idx_access_team_scope (data_scope, action_level),
  KEY idx_access_team_updated (updated_at)
) {$nxtcc_charset_collate};",

			"CREATE TABLE {$nxtcc_prefix}nxtcc_tenant_user_access (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  wp_user_id BIGINT(20) UNSIGNED NOT NULL,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  role_key VARCHAR(50) NOT NULL DEFAULT 'custom',
  capabilities_json LONGTEXT NULL,
  capability_scopes_json LONGTEXT NULL,
  action_level VARCHAR(20) NOT NULL DEFAULT 'manage',
  data_scope VARCHAR(20) NOT NULL DEFAULT 'all',
  assignment_eligible TINYINT(1) NOT NULL DEFAULT 1,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  granted_by BIGINT(20) UNSIGNED NULL,
  updated_by BIGINT(20) UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_user (wp_user_id, user_mailid(191), business_account_id(191), phone_number_id(191)),
  KEY idx_access_wp_user (wp_user_id),
  KEY idx_access_owner (is_owner),
  KEY idx_access_scope (data_scope, action_level),
  KEY idx_access_assignment_eligible (assignment_eligible),
  KEY idx_access_updated_by (updated_by),
  KEY idx_access_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/* ---------------------- Schema migrations log --------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_schema_migrations (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(50) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checksum VARCHAR(255) NULL,
  notes TEXT NULL,
  PRIMARY KEY (id)
) {$nxtcc_charset_collate};",

			/* ----------------------------- Auth OTP --------------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_auth_otp (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  phone_e164 VARCHAR(30) NOT NULL,
  user_id BIGINT(20) UNSIGNED NULL,
  code_hash CHAR(64) NOT NULL,
  salt CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_phone (phone_e164),
  KEY idx_session (session_id),
  KEY idx_status (status),
  KEY idx_expires (expires_at),
  KEY idx_session_status (session_id, status)
) {$nxtcc_charset_collate};",

			/* -------------------------- Auth Bindings ------------------------- */
			"CREATE TABLE {$nxtcc_prefix}nxtcc_auth_bindings (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  phone_e164 VARCHAR(30) NOT NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user (user_id),
  UNIQUE KEY uq_phone (phone_e164),
  KEY idx_created_at (created_at)
) {$nxtcc_charset_collate};",
		);

		foreach ( $nxtcc_tables as $nxtcc_sql ) {
			nxtcc_run_dbdelta_sql( $nxtcc_sql );
		}

		nxtcc_upgrade_ticket_schema( $nxtcc_prefix );

		if ( function_exists( 'nxtcc_schema_signature' ) ) {
			update_option( 'nxtcc_schema_signature', nxtcc_schema_signature(), false );
		}
	}
}

if ( ! function_exists( 'nxtcc_schema_signature' ) ) {
	/**
	 * Build a lightweight schema signature for upgrade checks.
	 *
	 * @return string
	 */
	function nxtcc_schema_signature(): string {
		$hash = hash_file( 'sha256', __FILE__ );

		return is_string( $hash ) ? $hash : '';
	}
}

if ( ! function_exists( 'nxtcc_schema_needs_install' ) ) {
	/**
	 * Determine whether the Free schema needs to be installed or refreshed.
	 *
	 * @return bool
	 */
	function nxtcc_schema_needs_install(): bool {
		$stored_signature = get_option( 'nxtcc_schema_signature', '' );
		return ! is_string( $stored_signature ) || nxtcc_schema_signature() !== $stored_signature;
	}
}
