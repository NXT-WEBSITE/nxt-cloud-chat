<?php
/**
 * Groups tenant helper.
 *
 * Provides a tenant resolution helper for Groups by delegating to the Contacts
 * repository when available. This file does not touch the database directly.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_groups_get_current_tenant' ) ) {
	/**
	 * Resolve the current tenant for the logged-in user.
	 *
	 * Returns the same signature as the contacts repository helper:
	 * [ user_email|null, baid|null, pnid|null, settings_row|null ].
	 *
	 * @return array{0:?string,1:mixed,2:mixed,3:mixed} Tenant tuple.
	 */
	function nxtcc_groups_get_current_tenant(): array {
		if ( ! is_user_logged_in() ) {
			return array( null, null, null, null );
		}

		/*
		 * If the Contacts repo exists, reuse its tenant resolution logic.
		 * That code already handles caching and database access correctly.
		 */
		if ( class_exists( 'NXTCC_Contacts_Handler_Repo' ) ) {
			return NXTCC_Contacts_Handler_Repo::instance()->get_current_tenant_for_user( get_current_user_id() );
		}

		/*
		 * Fallback: If contacts repo is not available, return only the user email.
		 * This indicates that a tenant is not configured.
		 */
		$user  = wp_get_current_user();
		$email = null;

		if ( $user instanceof WP_User && ! empty( $user->user_email ) ) {
			$email = sanitize_email( (string) $user->user_email );
			if ( '' === $email ) {
				$email = null;
			}
		}

		return array( $email, null, null, null );
	}
}
