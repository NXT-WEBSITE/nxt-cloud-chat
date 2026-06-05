<?php
/**
 * Meta WhatsApp Cloud API health status wrapper.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch and normalize Meta Messaging and Calling Health Status.
 */
final class NXTCC_Meta_Health_Status {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc_meta_health_status';

	/**
	 * Default Graph API version.
	 *
	 * @var string
	 */
	private const DEFAULT_GRAPH_VERSION = 'v19.0';

	/**
	 * Fetch health status for a tenant/node.
	 *
	 * @param array<string,mixed> $tenant Tenant context.
	 * @param array<string,mixed> $args   Optional args: node_id, graph_version, force_refresh.
	 * @return array<string,mixed>
	 */
	public static function get_status( array $tenant = array(), array $args = array() ): array {
		$tenant        = self::resolve_tenant( $tenant );
		$node_id       = isset( $args['node_id'] ) ? sanitize_text_field( (string) $args['node_id'] ) : '';
		$graph_version = self::graph_version( isset( $args['graph_version'] ) ? (string) $args['graph_version'] : '' );
		$force_refresh = ! empty( $args['force_refresh'] );

		if ( '' === $node_id ) {
			$node_id = $tenant['phone_number_id'];
		}

		if ( '' === $tenant['user_mailid'] || '' === $tenant['business_account_id'] || '' === $tenant['phone_number_id'] || '' === $node_id ) {
			return self::failure(
				'not_configured',
				__( 'Complete the connection settings to fetch Meta health status.', 'nxt-cloud-chat' ),
				'unknown',
				$node_id,
				$graph_version
			);
		}

		$cache_key = self::cache_key( $tenant, $node_id, $graph_version );
		if ( ! $force_refresh ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
			return self::cache_and_return(
				$cache_key,
				self::failure(
					'credentials_runtime_unavailable',
					__( 'The credential runtime is not available.', 'nxt-cloud-chat' ),
					'unknown',
					$node_id,
					$graph_version
				)
			);
		}

		$creds = nxtcc_get_tenant_api_credentials(
			$tenant['user_mailid'],
			$tenant['business_account_id'],
			$tenant['phone_number_id']
		);

		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return self::cache_and_return(
				$cache_key,
				self::failure(
					'credentials_unavailable',
					__( 'Saved credentials could not be decrypted for this tenant.', 'nxt-cloud-chat' ),
					'fail',
					$node_id,
					$graph_version
				)
			);
		}

		$url      = add_query_arg(
			array(
				'fields' => 'health_status',
			),
			'https://graph.facebook.com/' . rawurlencode( $graph_version ) . '/' . rawurlencode( $node_id )
		);
		$response = nxtcc_safe_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $creds['access_token'],
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::cache_and_return(
				$cache_key,
				self::failure(
					'remote_error',
					$response->get_error_message(),
					'unknown',
					$node_id,
					$graph_version
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : array();

		if ( 200 > $code || 300 <= $code ) {
			return self::cache_and_return(
				$cache_key,
				self::failure(
					'graph_error',
					self::graph_error_message( $data, $code ),
					'fail',
					$node_id,
					$graph_version,
					self::graph_error_details( $data )
				)
			);
		}

		if ( empty( $data['health_status'] ) || ! is_array( $data['health_status'] ) ) {
			return self::cache_and_return(
				$cache_key,
				self::failure(
					'health_status_missing',
					__( 'Meta did not return a health_status object for this node.', 'nxt-cloud-chat' ),
					'unknown',
					$node_id,
					$graph_version
				)
			);
		}

		return self::cache_and_return(
			$cache_key,
			self::normalize_response( $data, $node_id, $graph_version )
		);
	}

	/**
	 * Resolve tenant context.
	 *
	 * @param array<string,mixed> $tenant Tenant context.
	 * @return array{user_mailid:string,business_account_id:string,phone_number_id:string}
	 */
	private static function resolve_tenant( array $tenant ): array {
		if ( empty( $tenant ) && class_exists( 'NXTCC_Access_Control' ) ) {
			$tenant = NXTCC_Access_Control::get_current_tenant_context();
		}

		return array(
			'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
			'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
			'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
		);
	}

	/**
	 * Resolve Graph API version.
	 *
	 * @param string $graph_version Requested version.
	 * @return string
	 */
	private static function graph_version( string $graph_version ): string {
		$default = (string) apply_filters( 'nxtcc_meta_health_graph_version', self::DEFAULT_GRAPH_VERSION );
		$version = '' !== $graph_version ? $graph_version : $default;
		$version = sanitize_text_field( $version );

		if ( ! preg_match( '/^v[0-9]+\.[0-9]+$/', $version ) ) {
			return self::DEFAULT_GRAPH_VERSION;
		}

		return $version;
	}

	/**
	 * Build object-cache key.
	 *
	 * @param array<string,string> $tenant        Tenant context.
	 * @param string               $node_id       Node id.
	 * @param string               $graph_version Graph API version.
	 * @return string
	 */
	private static function cache_key( array $tenant, string $node_id, string $graph_version ): string {
		return 'meta_health:' . md5(
			wp_json_encode(
				array(
					$tenant['user_mailid'],
					$tenant['business_account_id'],
					$tenant['phone_number_id'],
					$node_id,
					$graph_version,
				)
			)
		);
	}

	/**
	 * Cache and return a payload.
	 *
	 * @param string              $cache_key Cache key.
	 * @param array<string,mixed> $payload   Payload.
	 * @return array<string,mixed>
	 */
	private static function cache_and_return( string $cache_key, array $payload ): array {
		wp_cache_set( $cache_key, $payload, self::CACHE_GROUP, 300 );
		return $payload;
	}

	/**
	 * Normalize a successful Graph response.
	 *
	 * @param array<string,mixed> $data          Graph response.
	 * @param string              $node_id       Node id.
	 * @param string              $graph_version Graph API version.
	 * @return array<string,mixed>
	 */
	private static function normalize_response( array $data, string $node_id, string $graph_version ): array {
		$health          = is_array( $data['health_status'] ) ? $data['health_status'] : array();
		$can_send        = self::normalize_can_send( $health['can_send_message'] ?? '' );
		$entities_raw    = isset( $health['entities'] ) && is_array( $health['entities'] ) ? $health['entities'] : array();
		$entities        = array();
		$entity_statuses = array();

		foreach ( $entities_raw as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$normalized = self::normalize_entity( $entity );
			$entities[] = $normalized;

			if ( ! empty( $normalized['can_send_message'] ) ) {
				$entity_statuses[] = (string) $normalized['can_send_message'];
			}
		}

		return array(
			'success'          => true,
			'status'           => self::status_from_can_send( $can_send, $entity_statuses ),
			'can_send_message' => $can_send,
			'node_id'          => sanitize_text_field( $node_id ),
			'graph_version'    => sanitize_text_field( $graph_version ),
			'checked_at'       => current_time( 'mysql', 1 ),
			'checked_at_local' => self::site_time_label( time() ),
			'entities'         => $entities,
			'additional_info'  => self::normalize_text_list( $health['additional_info'] ?? array() ),
			'errors'           => self::normalize_errors( $health['errors'] ?? array() ),
			'details'          => self::unknown_details( $health, array( 'can_send_message', 'entities', 'additional_info', 'errors' ) ),
			'response_details' => self::unknown_details( $data, array( 'health_status', 'id' ) ),
		);
	}

	/**
	 * Normalize one entity.
	 *
	 * @param array<string,mixed> $entity Entity payload.
	 * @return array<string,mixed>
	 */
	private static function normalize_entity( array $entity ): array {
		return array(
			'entity_type'      => self::entity_type_label( (string) ( $entity['entity_type'] ?? '' ) ),
			'entity_type_raw'  => sanitize_text_field( (string) ( $entity['entity_type'] ?? '' ) ),
			'id'               => sanitize_text_field( (string) ( $entity['id'] ?? '' ) ),
			'can_send_message' => self::normalize_can_send( $entity['can_send_message'] ?? '' ),
			'status'           => self::status_from_can_send( self::normalize_can_send( $entity['can_send_message'] ?? '' ) ),
			'additional_info'  => self::normalize_text_list( $entity['additional_info'] ?? array() ),
			'errors'           => self::normalize_errors( $entity['errors'] ?? array() ),
			'details'          => self::unknown_details( $entity, array( 'entity_type', 'id', 'can_send_message', 'additional_info', 'errors' ) ),
		);
	}

	/**
	 * Normalize Meta can_send_message value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_can_send( $value ): string {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		if ( in_array( $value, array( 'AVAILABLE', 'LIMITED', 'BLOCKED' ), true ) ) {
			return $value;
		}

		return 'UNKNOWN';
	}

	/**
	 * Convert can_send_message to UI status.
	 *
	 * @param string            $can_send        Overall can_send_message value.
	 * @param array<int,string> $entity_statuses Entity status values.
	 * @return string
	 */
	private static function status_from_can_send( string $can_send, array $entity_statuses = array() ): string {
		if ( 'BLOCKED' === $can_send || in_array( 'BLOCKED', $entity_statuses, true ) ) {
			return 'fail';
		}

		if ( 'LIMITED' === $can_send || in_array( 'LIMITED', $entity_statuses, true ) ) {
			return 'warn';
		}

		if ( 'AVAILABLE' === $can_send ) {
			return 'ok';
		}

		return 'unknown';
	}

	/**
	 * Normalize text list.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private static function normalize_text_list( $value ): array {
		if ( is_scalar( $value ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$text = sanitize_text_field( (string) $item );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}

		return $out;
	}

	/**
	 * Normalize errors list.
	 *
	 * @param mixed $value Raw errors.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_errors( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}

			$out[] = array(
				'error_code'        => sanitize_text_field( (string) ( $error['error_code'] ?? '' ) ),
				'error_description' => sanitize_text_field( (string) ( $error['error_description'] ?? '' ) ),
				'possible_solution' => sanitize_textarea_field( (string) ( $error['possible_solution'] ?? '' ) ),
				'details'           => self::unknown_details( $error, array( 'error_code', 'error_description', 'possible_solution' ) ),
			);
		}

		return $out;
	}

	/**
	 * Return safe unknown fields for future Meta response properties.
	 *
	 * @param array<string,mixed> $data         Source data.
	 * @param array<int,string>   $known_fields Known field names.
	 * @return array<string,mixed>
	 */
	private static function unknown_details( array $data, array $known_fields ): array {
		$out = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, $known_fields, true ) || self::is_sensitive_key( $key ) ) {
				continue;
			}

			$out[ $key ] = self::safe_value( $value );
		}

		return $out;
	}

	/**
	 * Sanitize nested value for UI/API output.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function safe_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return sanitize_text_field( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( '' === (string) $clean_key || self::is_sensitive_key( (string) $clean_key ) ) {
				continue;
			}

			$out[ $clean_key ] = self::safe_value( $item );
		}

		return $out;
	}

	/**
	 * Check for sensitive keys that should never be exposed.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	private static function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( '/token|secret|password|authorization|credential|key/i', $key );
	}

	/**
	 * Build a failed payload.
	 *
	 * @param string              $error_code    Error code.
	 * @param string              $message       Message.
	 * @param string              $status        Status.
	 * @param string              $node_id       Node id.
	 * @param string              $graph_version Graph API version.
	 * @param array<string,mixed> $details       Optional details.
	 * @return array<string,mixed>
	 */
	private static function failure( string $error_code, string $message, string $status, string $node_id, string $graph_version, array $details = array() ): array {
		return array(
			'success'          => false,
			'status'           => sanitize_key( $status ),
			'can_send_message' => 'UNKNOWN',
			'node_id'          => sanitize_text_field( $node_id ),
			'graph_version'    => sanitize_text_field( $graph_version ),
			'checked_at'       => current_time( 'mysql', 1 ),
			'checked_at_local' => self::site_time_label( time() ),
			'entities'         => array(),
			'additional_info'  => array(),
			'errors'           => array(),
			'details'          => $details,
			'error'            => array(
				'code'    => sanitize_key( $error_code ),
				'message' => sanitize_text_field( $message ),
			),
		);
	}

	/**
	 * Extract a safe Graph API error message.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @param int                 $code HTTP response code.
	 * @return string
	 */
	private static function graph_error_message( array $data, int $code ): string {
		if ( isset( $data['error'] ) && is_array( $data['error'] ) && ! empty( $data['error']['message'] ) ) {
			return sanitize_text_field( (string) $data['error']['message'] );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Meta returned HTTP %d while fetching health status.', 'nxt-cloud-chat' ),
			$code
		);
	}

	/**
	 * Extract safe Graph error details.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @return array<string,mixed>
	 */
	private static function graph_error_details( array $data ): array {
		if ( empty( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return array();
		}

		return self::unknown_details( $data['error'], array( 'message' ) );
	}

	/**
	 * Format a site-local timestamp label.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private static function site_time_label( int $timestamp ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $timestamp, wp_timezone() );
	}

	/**
	 * Convert entity type into a friendly label.
	 *
	 * @param string $entity_type Entity type.
	 * @return string
	 */
	private static function entity_type_label( string $entity_type ): string {
		$entity_type = strtoupper( sanitize_text_field( $entity_type ) );
		$labels      = array(
			'PHONE_NUMBER'     => __( 'Phone Number', 'nxt-cloud-chat' ),
			'WABA'             => __( 'WABA', 'nxt-cloud-chat' ),
			'BUSINESS'         => __( 'Business', 'nxt-cloud-chat' ),
			'APP'              => __( 'App', 'nxt-cloud-chat' ),
			'MESSAGE_TEMPLATE' => __( 'Message Template', 'nxt-cloud-chat' ),
		);

		return isset( $labels[ $entity_type ] ) ? $labels[ $entity_type ] : ucwords( strtolower( str_replace( '_', ' ', $entity_type ) ) );
	}
}
