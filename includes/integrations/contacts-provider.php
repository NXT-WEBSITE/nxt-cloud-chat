<?php
/**
 * Contacts provider loader.
 *
 * Loads the contacts token provider class and exposes the provider function.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-contacts-provider.php';

if ( ! function_exists( 'nxtcc_provider_contact_context' ) ) {
	/**
	 * Inbuilt Contacts provider.
	 *
	 * Exposes:
	 * - contact.name
	 * - contact.country_code
	 * - contact.phone_number
	 * - contact.custom.<label_slug> (from custom_fields JSON).
	 *
	 * @param int    $contact_id  Contact ID.
	 * @param string $user_mailid Tenant hint (unused by this provider).
	 * @return array
	 */
	function nxtcc_provider_contact_context( $contact_id, $user_mailid = '' ): array {
		return NXTCC_Contacts_Provider::instance()->build_contact_context(
			(int) $contact_id,
			(string) $user_mailid
		);
	}
}
