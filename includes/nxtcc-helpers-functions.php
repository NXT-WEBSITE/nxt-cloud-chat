<?php
/**
 * Procedural wrapper functions for NXTCC_Helpers.
 *
 * These functions preserve backwards compatibility with older code paths
 * while the plugin is migrated to class-based helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_crypto_derive_kek' ) ) {
	/**
	 * Wrapper for deriving a key-encryption key.
	 *
	 * @return string Binary key material.
	 */
	function nxtcc_crypto_derive_kek(): string {
		return NXTCC_Helpers::crypto_derive_kek();
	}
}

if ( ! function_exists( 'nxtcc_crypto_encrypt' ) ) {
	/**
	 * Wrapper for encrypting plaintext.
	 *
	 * @param string $plaintext Plain text.
	 * @return array{0:?string,1:?string} [ciphertext_b64, nonce_raw] or [null, null].
	 */
	function nxtcc_crypto_encrypt( string $plaintext ): array {
		return NXTCC_Helpers::crypto_encrypt( $plaintext );
	}
}

if ( ! function_exists( 'nxtcc_crypto_decrypt' ) ) {
	/**
	 * Wrapper for decrypting ciphertext.
	 *
	 * @param string|null $ct_b64    Ciphertext base64.
	 * @param string|null $nonce_raw Nonce raw bytes.
	 * @return string|\WP_Error Plaintext or WP_Error.
	 */
	function nxtcc_crypto_decrypt( ?string $ct_b64, ?string $nonce_raw ) {
		return NXTCC_Helpers::crypto_decrypt( $ct_b64, $nonce_raw );
	}
}

if ( ! function_exists( 'nxtcc_sanitize_phone_number' ) ) {
	/**
	 * Wrapper for sanitizing a phone number to digits.
	 *
	 * @param mixed $phone_number Input phone number.
	 * @return string Digits only.
	 */
	function nxtcc_sanitize_phone_number( $phone_number ): string {
		return NXTCC_Helpers::sanitize_phone_number( $phone_number );
	}
}

if ( ! function_exists( 'nxtcc_generate_token' ) ) {
	/**
	 * Wrapper for generating a token.
	 *
	 * @param int $length Token length.
	 * @return string Token.
	 */
	function nxtcc_generate_token( int $length = 32 ): string {
		return NXTCC_Helpers::generate_token( $length );
	}
}

if ( ! function_exists( 'nxtcc_log_api_response' ) ) {
	/**
	 * Wrapper for debug serialization of API responses.
	 *
	 * @param mixed $response Any value.
	 * @return void
	 */
	function nxtcc_log_api_response( $response ): void {
		NXTCC_Helpers::log_api_response( $response );
	}
}

if ( ! function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
	/**
	 * Wrapper for fetching tenant credentials.
	 *
	 * @param string $user_mailid         User identifier/email.
	 * @param string $business_account_id WABA ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return array|false Credential array or false.
	 */
	function nxtcc_get_tenant_api_credentials( string $user_mailid, string $business_account_id, string $phone_number_id ) {
		return NXTCC_Helpers::get_tenant_api_credentials( $user_mailid, $business_account_id, $phone_number_id );
	}
}

if ( ! function_exists( 'nxtcc_sync_templates_from_meta' ) ) {
	/**
	 * Wrapper for syncing templates.
	 *
	 * @param string $user_mailid         User identifier/email.
	 * @param string $access_token        Access token.
	 * @param string $business_account_id WABA ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return bool True on success.
	 */
	function nxtcc_sync_templates_from_meta( string $user_mailid, string $access_token, string $business_account_id, string $phone_number_id ): bool {
		return NXTCC_Helpers::sync_templates_from_meta( $user_mailid, $access_token, $business_account_id, $phone_number_id );
	}
}

if ( ! function_exists( 'nxtcc_get_cached_templates' ) ) {
	/**
	 * Wrapper for template list retrieval.
	 *
	 * @param string $user_mailid     User identifier/email.
	 * @param string $phone_number_id Phone number ID.
	 * @return array Templates list.
	 */
	function nxtcc_get_cached_templates( string $user_mailid, string $phone_number_id ): array {
		return NXTCC_Helpers::get_cached_templates( $user_mailid, $phone_number_id );
	}
}

if ( ! function_exists( 'nxtcc_build_template_components' ) ) {
	/**
	 * Wrapper for building template components payload.
	 *
	 * @param array $params           Parameters.
	 * @param array $template_buttons Template buttons schema.
	 * @return array Components.
	 */
	function nxtcc_build_template_components( array $params = array(), array $template_buttons = array() ): array {
		return NXTCC_Helpers::build_template_components( $params, $template_buttons );
	}
}

if ( ! function_exists( 'nxtcc_extract_template_fields_for_ui' ) ) {
	/**
	 * Wrapper for extracting UI fields from template components.
	 *
	 * @param mixed $components Template components.
	 * @return array UI fields.
	 */
	function nxtcc_extract_template_fields_for_ui( $components ): array {
		return NXTCC_Helpers::extract_template_fields_for_ui( $components );
	}
}

if ( ! function_exists( 'nxtcc_get_country_codes' ) ) {
	/**
	 * Wrapper for country codes list.
	 *
	 * @return array Country codes.
	 */
	function nxtcc_get_country_codes(): array {
		return NXTCC_Helpers::get_country_codes();
	}
}

if ( ! function_exists( 'nxtcc_iso_from_e164' ) ) {
	/**
	 * Wrapper for E.164 to ISO2 mapping.
	 *
	 * @param string $e164 Phone number.
	 * @return string|null ISO2 or null.
	 */
	function nxtcc_iso_from_e164( string $e164 ): ?string {
		return NXTCC_Helpers::iso_from_e164( $e164 );
	}
}

if ( ! function_exists( 'nxtcc_iso_from_locale' ) ) {
	/**
	 * Wrapper for locale to ISO2 mapping.
	 *
	 * @param string|null $locale Locale.
	 * @return string|null ISO2 or null.
	 */
	function nxtcc_iso_from_locale( ?string $locale ): ?string {
		return NXTCC_Helpers::iso_from_locale( $locale );
	}
}

if ( ! function_exists( 'nxtcc_iso_from_accept_language' ) ) {
	/**
	 * Wrapper for Accept-Language to ISO2 mapping.
	 *
	 * @param string|null $hdr Header value.
	 * @return string|null ISO2 or null.
	 */
	function nxtcc_iso_from_accept_language( ?string $hdr ): ?string {
		return NXTCC_Helpers::iso_from_accept_language( $hdr );
	}
}

if ( ! function_exists( 'nxtcc_detect_visitor_country' ) ) {
	/**
	 * Wrapper for visitor country detection.
	 *
	 * @return string ISO2.
	 */
	function nxtcc_detect_visitor_country(): string {
		return NXTCC_Helpers::detect_visitor_country();
	}
}
