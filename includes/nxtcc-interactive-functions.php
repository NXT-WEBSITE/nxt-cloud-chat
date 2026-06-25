<?php
/**
 * Public interactive-message integration wrappers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_is_meta_flow_response' ) ) {
	/**
	 * Determine whether a Meta webhook message is a Flow response.
	 *
	 * @param array $message Meta webhook message.
	 * @return bool
	 */
	function nxtcc_is_meta_flow_response( array $message ): bool {
		return NXTCC_Interactive_Messages::is_flow_response( $message );
	}
}

if ( ! function_exists( 'nxtcc_parse_meta_interactive_message' ) ) {
	/**
	 * Normalize a Meta interactive webhook message.
	 *
	 * @param array $message Meta webhook message.
	 * @return array<string, mixed>
	 */
	function nxtcc_parse_meta_interactive_message( array $message ): array {
		return NXTCC_Interactive_Messages::parse( $message );
	}
}

if ( ! function_exists( 'nxtcc_get_interactive_message_from_history' ) ) {
	/**
	 * Normalize interactive content from a message-history row.
	 *
	 * @param object|array $history Message-history row.
	 * @return array<string, mixed>
	 */
	function nxtcc_get_interactive_message_from_history( $history ): array {
		return NXTCC_Interactive_Messages::from_history( $history );
	}
}

if ( ! function_exists( 'nxtcc_get_flow_response_from_history' ) ) {
	/**
	 * Read a normalized Flow response from a message-history row.
	 *
	 * @param object|array $history Message-history row.
	 * @return array<string, mixed>
	 */
	function nxtcc_get_flow_response_from_history( $history ): array {
		$interactive = NXTCC_Interactive_Messages::from_history( $history );
		return 'flow_response' === ( $interactive['kind'] ?? '' )
			? (array) ( $interactive['flow_response'] ?? array() )
			: array();
	}
}
