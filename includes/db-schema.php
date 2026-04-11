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
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_contacts (
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_contact (user_mailid(191), business_account_id(191), phone_number_id(191), country_code, phone_number),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_wp_uid (wp_uid),
  KEY idx_contacts_created_at (created_at),
  KEY idx_contacts_name (name(191)),
  KEY idx_contacts_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  KEY idx_contacts_is_subscribed (is_subscribed),
  KEY idx_contacts_unsubscribed_at (unsubscribed_at)
) {$nxtcc_charset_collate};",

			/*
			----------------------------- Groups -----------------------------
		*/

			/*
			 * Groups are tenant-scoped (user_mailid + business_account_id + phone_number_id).
			 */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_groups (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  group_name VARCHAR(255) NOT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_groupname (user_mailid(191), business_account_id(191), phone_number_id(191), group_name(191)),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_groups_is_verified (is_verified),
  KEY idx_groups_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/*
			---------------------- Group -> Contact map -----------------------
		*/

			/*
			 * Group-contact mapping is also tenant-scoped to prevent cross-tenant joins.
			 */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_group_contact_map (
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

			/* -------------------------- Message history ----------------------- */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_message_history (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id BIGINT(20) UNSIGNED NULL,
  user_mailid VARCHAR(255) NOT NULL,
  business_account_id VARCHAR(255) NOT NULL,
  phone_number_id VARCHAR(255) NOT NULL,
  group_ids TEXT NULL,
  contact_id BIGINT(20) UNSIGNED NULL,
  display_phone_number VARCHAR(30) NULL,
  template_id VARCHAR(255) NULL,
  template_name VARCHAR(255) NULL,
  template_type VARCHAR(50) NULL,
  template_data LONGTEXT NULL,
  message_content LONGTEXT NULL,
  status VARCHAR(50) NULL,
  status_timestamps LONGTEXT NULL,
  last_error TEXT NULL,
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
  KEY idx_created_at (created_at),
  KEY idx_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  KEY idx_deleted_at (deleted_at),
  KEY idx_favorite (is_favorite),
  KEY idx_meta_message_id (meta_message_id),
  KEY idx_reply_wamid (reply_to_wamid),
  KEY idx_reply_history_id (reply_to_history_id),
  KEY idx_contact_id (contact_id),
  KEY idx_thread_poll (contact_id, user_mailid(191), phone_number_id(191), deleted_at, id)
) {$nxtcc_charset_collate};",

			/* -------------------------- User settings ------------------------- */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_user_settings (
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_app_id (app_id),
  KEY idx_user_mailid (user_mailid(191)),
  KEY idx_phone_number_id (phone_number_id(191)),
  KEY idx_business_account_id (business_account_id(191)),
  KEY idx_created_at (created_at),
  KEY idx_settings_multi_tenant (user_mailid(191), business_account_id(191), phone_number_id(191)),
  UNIQUE KEY uq_settings_tenant (user_mailid(191), business_account_id(191), phone_number_id(191))
) {$nxtcc_charset_collate};",

			/* ---------------------- Schema migrations log --------------------- */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_schema_migrations (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(50) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checksum VARCHAR(255) NULL,
  notes TEXT NULL,
  PRIMARY KEY (id)
) {$nxtcc_charset_collate};",

			/* ----------------------------- Auth OTP --------------------------- */
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_auth_otp (
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
			"CREATE TABLE IF NOT EXISTS {$nxtcc_prefix}nxtcc_auth_bindings (
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
	}
}
