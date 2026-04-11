<?php
/**
 * Contacts cache helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NXTCC_CONTACTS_CACHE_GROUP' ) ) {
	define( 'NXTCC_CONTACTS_CACHE_GROUP', 'nxtcc_contacts' );
}

/**
 * Invalidate tenant-scoped caches that depend on contacts data.
 *
 * @param string $baid Business account ID.
 * @param string $pnid Phone number ID.
 * @return void
 */
function nxtcc_invalidate_tenant_caches( string $baid, string $pnid ): void {
	$key = md5( $baid . '|' . $pnid );
	wp_cache_delete( 'creators:' . $key, NXTCC_CONTACTS_CACHE_GROUP );
	wp_cache_delete( 'country_codes:' . $key, NXTCC_CONTACTS_CACHE_GROUP );
}

/**
 * Invalidate user-owned groups listing cache.
 *
 * @param string $user_mailid User email.
 * @return void
 */
function nxtcc_invalidate_user_groups_cache( string $user_mailid ): void {
	if ( '' !== $user_mailid ) {
		wp_cache_delete( 'groups:' . md5( $user_mailid ), NXTCC_CONTACTS_CACHE_GROUP );
	}
}
