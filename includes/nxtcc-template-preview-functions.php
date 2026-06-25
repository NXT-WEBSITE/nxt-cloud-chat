<?php
/**
 * Public template-preview wrappers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_normalize_template_history_row' ) ) {
	/**
	 * Add a stable sent-template preview snapshot to a history row.
	 *
	 * @param array<string, mixed> $data History row.
	 * @return array<string, mixed>
	 */
	function nxtcc_normalize_template_history_row( array $data ): array {
		return NXTCC_Template_Preview::normalize_history_row( $data );
	}
}

if ( ! function_exists( 'nxtcc_build_template_preview_snapshot' ) ) {
	/**
	 * Build a normalized template preview snapshot.
	 *
	 * @param array<string, mixed> $args Preview arguments.
	 * @return array<string, mixed>
	 */
	function nxtcc_build_template_preview_snapshot( array $args ): array {
		return NXTCC_Template_Preview::build_snapshot( $args );
	}
}

if ( ! function_exists( 'nxtcc_get_template_preview_from_history' ) ) {
	/**
	 * Read or reconstruct a template preview from a history row.
	 *
	 * @param object|array $history History row.
	 * @return array<string, mixed>
	 */
	function nxtcc_get_template_preview_from_history( $history ): array {
		return NXTCC_Template_Preview::from_history( $history );
	}
}
