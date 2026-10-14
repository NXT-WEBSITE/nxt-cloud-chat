<?php
/**
 * Send-time template privacy policies.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps customer parameters separate from persistent agent-facing previews.
 */
final class NXTCC_Template_Privacy {

	/** Policy envelope stored alongside queue parameters. */
	public const META_KEY = '_nxtcc_privacy';

	/**
	 * Capture a policy before resolving parameters.
	 *
	 * @param array $params Raw parameters.
	 * @param array $masked Parameter names explicitly hidden by the workflow.
	 * @return array
	 */
	public static function policy( array $params, array $masked = array() ): array {
		$fields = array();
		foreach ( $params as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( self::META_KEY === $name || '' === $name ) {
				continue;
			}
			if ( in_array( $name, $masked, true ) ) {
				$fields[ $name ] = 'hidden';
			} elseif ( is_string( $value ) ) {
				$text = html_entity_decode( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				if ( preg_match( '/\{\{\s*(?:license\.key(?:_masked)?|order\.products_with_license_keys)\s*(?:\||\}\})/i', $text ) ) {
					$fields[ $name ] = 'license';
				}
			}
		}
		return empty( $fields ) ? array() : array(
			'version' => 1,
			'fields'  => $fields,
		);
	}

	/**
	 * Read only supported policy fields from a persisted envelope.
	 *
	 * @param array $params Queue/history parameters.
	 * @return array
	 */
	public static function read_policy( array $params ): array {
		$policy = $params[ self::META_KEY ] ?? array();
		$fields = array();
		if ( is_array( $policy ) && 1 === ( $policy['version'] ?? 0 ) && is_array( $policy['fields'] ?? null ) ) {
			foreach ( $policy['fields'] as $name => $mode ) {
				$name = sanitize_key( (string) $name );
				if ( '' !== $name && self::META_KEY !== $name && in_array( $mode, array( 'hidden', 'license' ), true ) ) {
					$fields[ $name ] = $mode;
				}
			}
		}
		return empty( $fields ) ? array() : array(
			'version' => 1,
			'fields'  => $fields,
		);
	}

	/**
	 * Replace protected parameters with safe preview values.
	 *
	 * License previews are supplied only by the server's structured resolver.
	 *
	 * @param array $params Parameters.
	 * @param array $policy Captured privacy policy.
	 * @param array $preview Server-generated redacted parameters.
	 * @return array
	 */
	public static function redact_params( array $params, array $policy, array $preview = array() ): array {
		$normalized = array();
		foreach ( $params as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name && self::META_KEY !== $name ) {
				$normalized[ $name ] = $value;
			}
		}
		$params = $normalized;
		foreach ( $policy['fields'] ?? array() as $name => $mode ) {
			$params[ $name ] = 'hidden' === $mode ? __( '[Hidden]', 'nxt-cloud-chat' )
				: ( isset( $preview[ $name ] ) && is_string( $preview[ $name ] ) ? $preview[ $name ] : __( '[License keys hidden]', 'nxt-cloud-chat' ) );
		}
		return $params;
	}

	/**
	 * Normalize stored parameter data without exposing protected values.
	 *
	 * @param array $data History row.
	 * @return array
	 */
	public static function protect_history( array $data ): array {
		$safe_error = ! empty( $data['_template_privacy_error_safe'] );
		unset( $data['_template_privacy_error_safe'] );
		$raw    = $data['template_data'] ?? array();
		$raw    = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$raw    = is_array( $raw ) ? $raw : array();
		$policy = self::read_policy( $raw );
		if ( ! empty( $policy ) && ! empty( array_diff( array_keys( $policy['fields'] ), array_map( 'sanitize_key', array_keys( $raw ) ) ) ) ) {
			$policy = array();
		}
		if ( empty( $policy ) ) {
			$policy = self::policy( $raw, isset( $raw[ self::META_KEY ] ) ? array_keys( $raw ) : array() );
		}
		$preview = is_array( $data['_template_privacy_preview'] ?? null ) ? $data['_template_privacy_preview'] : array();
		unset( $data['_template_privacy_preview'] );
		if ( empty( $policy ) ) {
			return $data;
		}
		if ( ! $safe_error && ! empty( $data['last_error'] ) ) {
			$data['last_error'] = __( 'Protected template delivery failed. Check the delivery error code.', 'nxt-cloud-chat' );
		}
		if ( ! empty( $data['response_json'] ) ) {
			$data['response_json'] = '{}';
		}
		$stored = json_decode( (string) ( $data['message_content'] ?? '' ), true );
		if ( ! empty( $stored['privacy_protected'] ) && ! isset( $data['_template_preview_params'] ) ) {
			return $data;
		}
		$params                              = is_array( $data['_template_preview_params'] ?? null ) ? $data['_template_preview_params'] : $raw;
		$params                              = self::redact_params( $params, $policy, $preview );
		$data['_template_preview_params']    = $params;
		$data['template_data']               = wp_json_encode( array_merge( $params, array( self::META_KEY => $policy ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$data['_template_privacy_protected'] = true;
		return $data;
	}

	/**
	 * Keep receipt evidence without API error text that may echo parameters.
	 *
	 * @param array $status Meta delivery receipt.
	 * @return array
	 */
	public static function redact_status( array $status ): array {
		$out = array_intersect_key( $status, array_flip( array( 'id', 'status', 'timestamp', 'recipient_id' ) ) );
		foreach ( is_array( $status['errors'] ?? null ) ? $status['errors'] : array() as $error ) {
			if ( is_array( $error ) ) {
				$out['errors'][] = array(
					'code'  => absint( $error['code'] ?? 0 ),
					'title' => __( 'Protected message delivery failure.', 'nxt-cloud-chat' ),
				);
			}
		}
		return $out;
	}
}
