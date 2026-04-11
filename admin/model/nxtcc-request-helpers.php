<?php
/**
 * Request helper functions shared across admin handlers.
 *
 * These helpers avoid direct superglobal access and standardize sanitization.
 * Nonce verification must be performed in the calling handler.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nxtcc_post_field' ) ) {
	/**
	 * Read a POST field as a sanitized string.
	 *
	 * @param string $key POST key.
	 * @return string Sanitized value or empty string.
	 */
	function nxtcc_post_field( $key ) {
		$key = (string) $key;

		$raw = filter_input(
			INPUT_POST,
			$key,
			FILTER_SANITIZE_FULL_SPECIAL_CHARS
		);

		if ( null === $raw || false === $raw || '' === $raw ) {
			return '';
		}

		// Normalize slashes and sanitize for WordPress.
		return sanitize_text_field( wp_unslash( (string) $raw ) );
	}
}

if ( ! function_exists( 'nxtcc_post_int' ) ) {
	/**
	 * Read a POST field as a validated integer.
	 *
	 * @param string $key      POST key.
	 * @param int    $fallback Default fallback if missing/invalid.
	 * @return int Sanitized integer.
	 */
	function nxtcc_post_int( $key, $fallback = 0 ) {
		$key      = (string) $key;
		$fallback = (int) $fallback;

		$raw = filter_input(
			INPUT_POST,
			$key,
			FILTER_VALIDATE_INT
		);

		if ( null === $raw || false === $raw ) {
			return $fallback;
		}

		return absint( $raw );
	}
}

if ( ! function_exists( 'nxtcc_post_json_array' ) ) {
	/**
	 * Read a POST field containing JSON and return an array.
	 *
	 * Accepts JSON array only. Invalid/empty returns empty array.
	 *
	 * Security: requires a valid nonce before decoding JSON input.
	 *
	 * @param string $key          POST key.
	 * @param string $nonce_action Nonce action to verify.
	 * @return array Decoded array.
	 */
	function nxtcc_post_json_array( $key, $nonce_action = 'nxtcc_history_nonce' ) {
		$key          = (string) $key;
		$nonce_action = (string) $nonce_action;

		$nonce_raw = filter_input(
			INPUT_POST,
			'nonce',
			FILTER_SANITIZE_FULL_SPECIAL_CHARS
		);
		if ( null === $nonce_raw ) {
			$nonce_raw = filter_input(
				INPUT_GET,
				'nonce',
				FILTER_SANITIZE_FULL_SPECIAL_CHARS
			);
		}

		$nonce = is_string( $nonce_raw ) ? sanitize_text_field( wp_unslash( $nonce_raw ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return array();
		}

		$raw = filter_input(
			INPUT_POST,
			$key,
			FILTER_SANITIZE_FULL_SPECIAL_CHARS
		);

		if ( null === $raw || false === $raw || '' === $raw ) {
			return array();
		}

		$json = wp_unslash( (string) $raw );

		if ( '' === $json ) {
			return array();
		}

		// WordPress does not have wp_json_decode(); use json_decode().
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
