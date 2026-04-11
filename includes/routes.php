<?php
/**
 * Bootstrap routes (AJAX + REST).
 *
 * This file must be loaded on every request (including admin-ajax.php).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-db.php';
require_once __DIR__ . '/class-nxtcc-routes.php';
require_once __DIR__ . '/rest-api.php';

/**
 * Wire up hooks.
 *
 * @return void
 */
function nxtcc_routes_bootstrap(): void {
	if ( class_exists( 'NXTCC_Routes' ) ) {
		NXTCC_Routes::init();
	}
}
add_action( 'init', 'nxtcc_routes_bootstrap' );
