<?php
/**
 * Shared template parameter limits.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates customer values before a template payload is built.
 */
final class NXTCC_Template_Parameters {

	/**
	 * Return a field-specific character limit.
	 *
	 * @param string $name Parameter name.
	 * @param array  $components Template definition.
	 * @return int
	 */
	public static function limit( string $name, array $components = array() ): int {
		if ( 0 === strpos( $name, 'button_code_' ) ) {
			return 15;
		}
		if ( 0 === strpos( $name, 'button_url_' ) || in_array( $name, array( 'header_image', 'header_video', 'header_document', 'order_details_payment_link_uri' ), true ) ) {
			return 2000;
		}
		if ( 0 === strpos( $name, 'header_' ) ) {
			return 60;
		}
		$body_only = ! empty( $components );
		foreach ( $components as $component ) {
			if ( 'BODY' !== strtoupper( (string) ( $component['type'] ?? '' ) ) ) {
				$body_only = false;
				break;
			}
		}
		return $body_only ? 32768 : 1024;
	}

	/**
	 * Count Unicode code points without requiring mbstring.
	 *
	 * @param string $value Text.
	 * @return int
	 */
	public static function length( string $value ): int {
		$count = preg_match_all( '/./us', $value );
		return false === $count ? strlen( $value ) : $count;
	}

	/**
	 * Validate parameters and fully expanded body/header text.
	 *
	 * @param array $params Resolved customer parameters.
	 * @param array $descriptor Selected template descriptor.
	 * @param bool  $allow_tokens Skip values not yet resolvable in the editor.
	 * @return true|WP_Error
	 */
	public static function validate( array $params, array $descriptor = array(), bool $allow_tokens = false ) {
		$components = is_array( $descriptor['components'] ?? null ) ? $descriptor['components'] : array();
		foreach ( $params as $name => $value ) {
			if ( ! is_scalar( $value ) || '_nxtcc_privacy' === $name || ! preg_match( '/^(?:body_var_|header_|button_(?:url|code)_|order_details_payment_link_uri)/', (string) $name ) ) {
				continue;
			}
			$value = (string) $value;
			if ( $allow_tokens && false !== strpos( $value, '{{' ) ) {
				continue;
			}
			$error = self::check_length( sanitize_key( (string) $name ), $value, self::limit( (string) $name, $components ) );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		foreach ( $components as $component ) {
			$scope = strtolower( (string) ( $component['type'] ?? '' ) );
			if ( ! in_array( $scope, array( 'body', 'header' ), true ) || ! isset( $component['text'] ) ) {
				continue;
			}
			$unresolved = false;
			$text       = preg_replace_callback(
				'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
				static function ( $matches ) use ( $params, $scope, $allow_tokens, &$unresolved ) {
					$key = $scope . '_var_' . $matches[1];
					if ( ! isset( $params[ $key ] ) || ( $allow_tokens && false !== strpos( (string) $params[ $key ], '{{' ) ) ) {
						$unresolved = true;
						return $matches[0];
					}
					return (string) $params[ $key ];
				},
				(string) $component['text'] 
			);
			if ( ! is_string( $text ) || $unresolved ) {
				continue;
			}
			$error = self::check_length( $scope, $text, self::limit( $scope . '_var_1', $components ) );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		$button_index = 0;
		foreach ( $components as $component ) {
			if ( 'BUTTONS' !== strtoupper( (string) ( $component['type'] ?? '' ) ) ) {
				continue;
			}
			foreach ( $component['buttons'] ?? array() as $button ) {
				++$button_index;
				if ( 'URL' !== strtoupper( (string) ( $button['type'] ?? '' ) ) ) {
					continue;
				}
				$url   = (string) ( $button['url'] ?? '' );
				$value = $params[ 'button_url_var_' . $button_index ] ?? $params[ 'button_url_' . $button_index ] ?? '';
				if ( $allow_tokens && false !== strpos( (string) $value, '{{' ) ) {
					continue;
				}
				$url   = preg_replace_callback(
					'/\{\{\s*\d+\s*\}\}/',
					static function () use ( $value ) {
						return (string) $value;
					},
					$url 
				);
				$error = self::check_length( 'button_url_' . $button_index, (string) $url, 2000 );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
		}
		return true;
	}

	/**
	 * Report only a safe field identifier and lengths, never the value.
	 *
	 * @param string $name Field name.
	 * @param string $value Customer value.
	 * @param int    $limit Maximum character count.
	 * @return true|WP_Error
	 */
	private static function check_length( string $name, string $value, int $limit ) {
		$length = self::length( $value );
		if ( $length <= $limit ) {
			return true;
		}
		return new WP_Error(
			'nxtcc_template_parameter_too_long',
			sprintf(
			/* translators: 1: Field name, 2: Actual length, 3: Allowed length. */
				__( 'Template field %1$s has %2$d characters; the maximum is %3$d.', 'nxt-cloud-chat' ),
				$name,
				$length,
				$limit
			) 
		);
	}
}
