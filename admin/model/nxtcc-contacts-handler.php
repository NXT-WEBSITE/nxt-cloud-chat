<?php
/**
 * Contacts module loader.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/contacts/nxtcc-contacts-cache.php';
require_once __DIR__ . '/contacts/class-nxtcc-contacts-handler-repo.php';
require_once __DIR__ . '/contacts/nxtcc-contacts-filesystem.php';
require_once __DIR__ . '/contacts/nxtcc-contacts-helpers.php';

require_once __DIR__ . '/contacts/nxtcc-contacts-actions.php';
require_once __DIR__ . '/contacts/nxtcc-contacts-groups-actions.php';
require_once __DIR__ . '/contacts/nxtcc-contacts-import-actions.php';
require_once __DIR__ . '/contacts/nxtcc-contacts-export-actions.php';
