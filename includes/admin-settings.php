<?php
/**
 * Admin settings bootstrap.
 *
 * Loads admin settings classes and initializes the settings controller.
 * Classes are split into dedicated class files to satisfy coding standards.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-nxtcc-db-adminsettings.php';
require_once __DIR__ . '/class-nxtcc-settings-dao.php';
require_once __DIR__ . '/class-nxtcc-admin-settings.php';

NXTCC_Admin_Settings::init();
