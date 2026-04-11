<?php
/**
 * Bootstrap for User Settings table registration on $wpdb.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register custom table on $wpdb so SQL can reference $wpdb->nxtcc_user_settings.
 *
 * Runs early on plugins_loaded.
 *
 * @return void
 */
function nxtcc_register_user_settings_table_on_wpdb(): void {
	global $wpdb;

	if ( empty( $wpdb->nxtcc_user_settings ) ) {
		$wpdb->nxtcc_user_settings = $wpdb->prefix . 'nxtcc_user_settings';
	}
}

add_action( 'plugins_loaded', 'nxtcc_register_user_settings_table_on_wpdb', 0 );
