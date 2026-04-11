<?php
/**
 * Policy bootstrap for Authentication + Force-Migration.
 *
 * This file is intentionally limited to wiring:
 * - Loads the admin controller class file.
 * - Registers the AJAX action handler.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-auth-admin-controller.php';
