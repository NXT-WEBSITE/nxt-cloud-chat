<?php
/**
 * CSV helper functions shared across admin handlers.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nxtcc_csv_field' ) ) {
	/**
	 * CSV escape a single field (RFC4180-ish).
	 *
	 * - Quotes are doubled.
	 * - Field is wrapped in quotes if it contains: comma, quote, CR or LF.
	 *
	 * @param mixed $value Field value.
	 * @return string Escaped field.
	 */
	function nxtcc_csv_field( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = '';
		}

		$s = ( null === $value ) ? '' : (string) $value;

		// Normalize CRLF/CR to LF.
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );

		$needs_quotes = ( false !== strpos( $s, ',' ) )
			|| ( false !== strpos( $s, '"' ) )
			|| ( false !== strpos( $s, "\n" ) );

		if ( $needs_quotes ) {
			$s = str_replace( '"', '""', $s );
			return '"' . $s . '"';
		}

		return $s;
	}
}

if ( ! function_exists( 'nxtcc_csv_line' ) ) {
	/**
	 * Build a CSV line (no trailing newline).
	 *
	 * @param array $fields Fields.
	 * @return string CSV line.
	 */
	function nxtcc_csv_line( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();

		$out = array();
		foreach ( $fields as $field ) {
			$out[] = nxtcc_csv_field( $field );
		}

		return implode( ',', $out );
	}
}

if ( ! function_exists( 'nxtcc_csv_build' ) ) {
	/**
	 * Build CSV content with BOM (Excel-friendly).
	 *
	 * @param array $lines CSV lines.
	 * @return string CSV content.
	 */
	function nxtcc_csv_build( $lines ) {
		$lines = is_array( $lines ) ? $lines : array();

		// UTF-8 BOM + CRLF line endings.
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}
}
