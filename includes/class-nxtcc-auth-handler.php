<?php
/**
 * Authentication handler utilities.
 *
 * Provides lightweight helpers for checking whether WhatsApp Cloud API
 * credentials are configured, and for exposing the saved authentication policy.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Authentication checks and helper accessors.
 */
class NXTCC_Auth_Handler {

	/**
	 * Check whether the WhatsApp Cloud API connection is configured.
	 *
	 * @return bool True when access token, phone number id, and business id exist.
	 */
	public static function is_connected(): bool {
		$access_token = NXTCC_API_Connection::get_access_token();
		$phone_id     = NXTCC_API_Connection::get_phone_number_id();
		$business_id  = NXTCC_API_Connection::get_business_account_id();

		return ( ! empty( $access_token ) && ! empty( $phone_id ) && ! empty( $business_id ) );
	}

	/**
	 * Get the unified authentication policy for UI/shortcode consumption.
	 *
	 * @return array Authentication policy/options.
	 */
	public static function get_policy(): array {
		if ( ! function_exists( 'nxtcc_fm_get_options' ) ) {
			require_once __DIR__ . '/force-migration/options.php';
		}

		$policy = nxtcc_fm_get_options();

		return is_array( $policy ) ? $policy : array();
	}
}
