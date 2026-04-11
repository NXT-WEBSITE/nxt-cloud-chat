<?php
/**
 * Groups module loader.
 *
 * Loads tenant helper, DB layer, repository, and AJAX handlers for Groups.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/groups/nxtcc-groups-tenant.php';
require_once __DIR__ . '/groups/class-nxtcc-groups-db.php';
require_once __DIR__ . '/groups/class-nxtcc-groups-repo.php';
require_once __DIR__ . '/groups/nxtcc-groups-ajax.php';
