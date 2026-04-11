<?php
/**
 * Master uninstall script for NXT Cloud Chat (FREE).
 *
 * If the per-site option `nxtcc_delete_data_on_uninstall` is enabled, this will wipe BOTH
 * Free + Pro plugin data (DB, options, Action Scheduler rows, uploads).
 *
 * @package NXTCC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Internal DB accessor (single gateway to $wpdb).
 *
 * @return wpdb WordPress database object.
 */
function nxtcc__db(): wpdb {
	global $wpdb;
	return $wpdb;
}

/**
 * Run a prepared query.
 *
 * @param string $query  SQL with optional placeholders.
 * @param array  $params Values for placeholders.
 * @param string $mode   'var' | 'col' | 'exec'.
 * @return mixed
 */
function nxtcc__run_prepared( string $query, array $params = array(), string $mode = 'var' ) {
	$wpdb = nxtcc__db();

	if ( empty( $params ) ) {
		$prepared = $query;
	} else {
		$prepared = call_user_func_array(
			array( $wpdb, 'prepare' ),
			array_merge( array( $query ), $params )
		);
	}

	switch ( $mode ) {
		case 'col':
			$col = call_user_func( array( $wpdb, 'get_col' ), $prepared );
			if ( empty( $col ) ) {
				return array();
			}
			return $col;

		case 'exec':
			return call_user_func( array( $wpdb, 'query' ), $prepared );

		case 'var':
		default:
			return call_user_func( array( $wpdb, 'get_var' ), $prepared );
	}
}

/**
 * Quote a table identifier for SQL usage.
 *
 * @param string $table Table name.
 * @return string Backtick-quoted table name.
 */
function nxtcc__quote_table_name( string $table ): string {
	$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );

	if ( ! is_string( $clean ) || '' === $clean ) {
		$clean = 'nxtcc_invalid';
	}

	return '`' . $clean . '`';
}

/**
 * Replace table tokens in SQL like {options}, {actions}, {logs}, {groups}.
 *
 * @param string $query     SQL query with table tokens.
 * @param array  $table_map Token => quoted table name map.
 * @return string SQL with table names substituted.
 */
function nxtcc__sql_with_table_tokens( string $query, array $table_map ): string {
	if ( '' === $query || empty( $table_map ) ) {
		return $query;
	}

	foreach ( $table_map as $token => $table_sql ) {
		$query = str_replace( '{' . (string) $token . '}', (string) $table_sql, $query );
	}

	return $query;
}

if ( ! function_exists( 'nxtcc__wipe_site_data' ) ) {

	/**
	 * Recursively delete a directory (quietly) using WP_Filesystem.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	function nxtcc__rrmdir( string $dir ): void {
		if ( '' === $dir ) {
			return;
		}

		if ( ! file_exists( $dir ) ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		if ( is_object( $wp_filesystem ) ) {
			// Recursive delete; true = delete contents and directory itself.
			$wp_filesystem->delete( $dir, true );
		}
	}

	/**
	 * Check if a table exists in the current database.
	 *
	 * @param string $table_name Table name (unquoted).
	 * @return bool
	 */
	function nxtcc__table_exists( string $table_name ): bool {
		if ( '' === $table_name ) {
			return false;
		}

		$exists = nxtcc__run_prepared(
			'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = %s',
			array( $table_name ),
			'var'
		);

		return ! empty( $exists );
	}

	/**
	 * Read an integer-like option value from a specific options table.
	 *
	 * @param string $options_table Options table name.
	 * @param string $option_name   Option name.
	 * @param int    $fallback      Default value.
	 * @return int
	 */
	function nxtcc__get_option_int_from_table( string $options_table, string $option_name, int $fallback = 0 ): int {
		$table_sql = nxtcc__quote_table_name( $options_table );
		$sql       = nxtcc__sql_with_table_tokens(
			'SELECT option_value
             FROM {options}
             WHERE option_name = %s
             LIMIT 1',
			array(
				'options' => $table_sql,
			)
		);

		$value = nxtcc__run_prepared( $sql, array( $option_name ), 'var' );
		if ( null === $value ) {
			return $fallback;
		}

		if ( is_string( $value ) ) {
			$maybe = maybe_unserialize( $value );
			if ( is_scalar( $maybe ) ) {
				return (int) $maybe;
			}
		}

		if ( is_scalar( $value ) ) {
			return (int) $value;
		}

		return $fallback;
	}

	/**
	 * Best-effort: clear WP-Cron hooks with nxtcc_* / nxtcc_pro_* / nxtcc-pro_ prefix.
	 *
	 * @param string $options_table Options table name.
	 * @return void
	 */
	function nxtcc__clear_cron_hooks( string $options_table ): void {
		if ( ! nxtcc__table_exists( $options_table ) ) {
			return;
		}

		$table_sql = nxtcc__quote_table_name( $options_table );
		$sql       = nxtcc__sql_with_table_tokens(
			'SELECT option_value
             FROM {options}
             WHERE option_name = %s
             LIMIT 1',
			array(
				'options' => $table_sql,
			)
		);

		$raw_cron = nxtcc__run_prepared( $sql, array( 'cron' ), 'var' );
		$cron     = is_string( $raw_cron ) ? maybe_unserialize( $raw_cron ) : null;

		if ( ! is_array( $cron ) ) {
			return;
		}

		$prefixes = array( 'nxtcc_', 'nxtcc-pro_', 'nxtcc_pro_' );
		$changed  = false;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( array_keys( $hooks ) as $hook ) {
				foreach ( $prefixes as $p ) {
					if ( 0 === strpos( (string) $hook, $p ) ) {
						unset( $cron[ $timestamp ][ $hook ] );
						$changed = true;
						break;
					}
				}
			}

			if ( empty( $cron[ $timestamp ] ) ) {
				unset( $cron[ $timestamp ] );
			}
		}

		if ( ! $changed ) {
			return;
		}

		$updated_sql = nxtcc__sql_with_table_tokens(
			'UPDATE {options}
             SET option_value = %s
             WHERE option_name = %s',
			array(
				'options' => $table_sql,
			)
		);

		nxtcc__run_prepared( $updated_sql, array( maybe_serialize( $cron ), 'cron' ), 'exec' );
	}

	/**
	 * Best-effort: remove NXTCC rows from Action Scheduler (does not drop AS tables).
	 *
	 * @param string $site_prefix Site DB prefix.
	 * @return void
	 */
	function nxtcc__clear_action_scheduler_rows( string $site_prefix ): void {
		$wpdb = nxtcc__db();

		$actions_table = $site_prefix . 'actionscheduler_actions';
		$logs_table    = $site_prefix . 'actionscheduler_logs';
		$groups_table  = $site_prefix . 'actionscheduler_groups';

		$actions_table_sql = nxtcc__quote_table_name( $actions_table );
		$logs_table_sql    = nxtcc__quote_table_name( $logs_table );
		$groups_table_sql  = nxtcc__quote_table_name( $groups_table );

		// Guard: ensure AS actions table exists.
		if ( ! nxtcc__table_exists( $actions_table ) ) {
			return;
		}

		$prefixes = array( 'nxtcc_', 'nxtcc_pro_', 'nxtcc-pro_' );
		$likes    = array();

		foreach ( $prefixes as $p ) {
			$likes[] = $wpdb->esc_like( $p ) . '%';
		}

		$where   = implode( ' OR ', array_fill( 0, count( $likes ), 'hook LIKE %s' ) );
		$sql_ids = nxtcc__sql_with_table_tokens(
			"SELECT action_id FROM {actions} WHERE {$where}",
			array(
				'actions' => $actions_table_sql,
			)
		);

		$action_ids = nxtcc__run_prepared( $sql_ids, $likes, 'col' );

		$action_ids = array_map( 'intval', (array) $action_ids );
		$action_ids = array_filter( $action_ids );

		if ( empty( $action_ids ) ) {
			return;
		}

		// Delete logs if logs table exists.
		if ( nxtcc__table_exists( $logs_table ) ) {
			foreach ( array_chunk( $action_ids, 500 ) as $ids ) {
				$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				if ( empty( $ids ) ) {
					continue;
				}

				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$sql_delete   = nxtcc__sql_with_table_tokens(
					"DELETE FROM {logs} WHERE action_id IN ({$placeholders})",
					array(
						'logs' => $logs_table_sql,
					)
				);
				nxtcc__run_prepared( $sql_delete, $ids, 'exec' );
			}
		}

		// Delete actions.
		foreach ( array_chunk( $action_ids, 500 ) as $ids ) {
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( empty( $ids ) ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql_delete   = nxtcc__sql_with_table_tokens(
				"DELETE FROM {actions} WHERE action_id IN ({$placeholders})",
				array(
					'actions' => $actions_table_sql,
				)
			);
			nxtcc__run_prepared( $sql_delete, $ids, 'exec' );
		}

		// Optionally clear group rows named 'nxtcc' if used (safe if absent).
		if ( nxtcc__table_exists( $groups_table ) ) {
			$sql_groups = nxtcc__sql_with_table_tokens(
				'DELETE FROM {groups} WHERE slug = %s',
				array(
					'groups' => $groups_table_sql,
				)
			);
			nxtcc__run_prepared( $sql_groups, array( 'nxtcc' ), 'exec' );
		}
	}

	/**
	 * Drop all NXTCC tables (Pro first, then Free), if they exist.
	 *
	 * @param string $site_prefix Site DB prefix.
	 * @return void
	 */
	function nxtcc__drop_nxtcc_tables( string $site_prefix ): void {
		$tables_pro = array(
			$site_prefix . 'nxtcc_broadcast_queue',
			$site_prefix . 'nxtcc_worker_log',
			$site_prefix . 'nxtcc_templates',
			$site_prefix . 'nxtcc_webhook_events_raw',
			$site_prefix . 'nxtcc_broadcast_runs',
			$site_prefix . 'nxtcc_licenses',
		);

		$tables_free = array(
			$site_prefix . 'nxtcc_message_history',
			$site_prefix . 'nxtcc_group_contact_map',
			$site_prefix . 'nxtcc_contacts',
			$site_prefix . 'nxtcc_groups',
			$site_prefix . 'nxtcc_user_settings',
			$site_prefix . 'nxtcc_auth_otp',
			$site_prefix . 'nxtcc_auth_bindings',
			$site_prefix . 'nxtcc_schema_migrations',
		);

		foreach ( array_merge( $tables_pro, $tables_free ) as $table_name ) {
			$sql = 'DROP TABLE IF EXISTS ' . nxtcc__quote_table_name( $table_name );
			nxtcc__run_prepared( $sql, array(), 'exec' );
		}
	}

	/**
	 * Delete options and transients prefixed with nxtcc_ / nxtcc_pro_ (and hyphen variant).
	 *
	 * @param string $site_prefix Site DB prefix.
	 * @return void
	 */
	function nxtcc__delete_options_and_transients( string $site_prefix ): void {
		$wpdb          = nxtcc__db();
		$opt_table     = $site_prefix . 'options';
		$opt_table_sql = nxtcc__quote_table_name( $opt_table );

		if ( ! nxtcc__table_exists( $opt_table ) ) {
			return;
		}

		foreach ( array( 'nxtcc_', 'nxtcc_pro_', 'nxtcc-pro_' ) as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$sql  = nxtcc__sql_with_table_tokens(
				'DELETE FROM {options} WHERE option_name LIKE %s',
				array(
					'options' => $opt_table_sql,
				)
			);
			nxtcc__run_prepared( $sql, array( $like ), 'exec' );
		}

		$heads   = array( '_transient_', '_transient_timeout_' );
		$needles = array( 'nxtcc_', 'nxtcc_pro_', 'nxtcc-pro_' );

		foreach ( $heads as $head ) {
			$head_like = $wpdb->esc_like( $head ) . '%';
			foreach ( $needles as $needle ) {
				$needle_like = '%' . $wpdb->esc_like( $needle ) . '%';
				$sql         = nxtcc__sql_with_table_tokens(
					'DELETE FROM {options}
					 WHERE option_name LIKE %s
					   AND option_name LIKE %s',
					array(
						'options' => $opt_table_sql,
					)
				);
				nxtcc__run_prepared( $sql, array( $head_like, $needle_like ), 'exec' );
			}
		}
	}

	/**
	 * Delete uploads folder created by the plugin.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	function nxtcc__delete_uploads_folder( int $blog_id ): void {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			$base = trailingslashit( (string) $uploads['basedir'] );

			if ( is_multisite() && $blog_id > 1 ) {
				nxtcc__rrmdir( trailingslashit( $base . 'sites/' . $blog_id ) . 'nxtcc' );
				return;
			}

			nxtcc__rrmdir( $base . 'nxtcc' );
		}
	}

	/**
	 * Perform per-site wipe if the option is enabled.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	function nxtcc__wipe_site_data( int $blog_id ): void {
		$wpdb        = nxtcc__db();
		$site_prefix = $wpdb->get_blog_prefix( $blog_id );
		$site_prefix = is_string( $site_prefix ) ? $site_prefix : $wpdb->prefix;
		$options_tbl = $site_prefix . 'options';

		$wipe = nxtcc__get_option_int_from_table( $options_tbl, 'nxtcc_delete_data_on_uninstall', 0 );
		if ( 0 === $wipe ) {
			return;
		}

		nxtcc__clear_cron_hooks( $options_tbl );
		nxtcc__clear_action_scheduler_rows( $site_prefix );

		nxtcc__drop_nxtcc_tables( $site_prefix );
		nxtcc__delete_options_and_transients( $site_prefix );
		nxtcc__delete_uploads_folder( $blog_id );
	}
}

// Multisite-aware uninstall.
if ( is_multisite() ) {
	$nxtcc_sites = array();
	if ( function_exists( 'get_sites' ) ) {
		$nxtcc_sites = get_sites( array( 'fields' => 'ids' ) );
	}

	if ( ! empty( $nxtcc_sites ) ) {
		foreach ( $nxtcc_sites as $nxtcc_blog_id ) {
			nxtcc__wipe_site_data( (int) $nxtcc_blog_id );
		}
	} else {
		nxtcc__wipe_site_data( get_current_blog_id() );
	}
} else {
	nxtcc__wipe_site_data( get_current_blog_id() );
}
