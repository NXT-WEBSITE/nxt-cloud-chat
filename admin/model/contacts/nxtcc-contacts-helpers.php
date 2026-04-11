<?php
/**
 * Contacts pure helpers (no direct action hooks).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch current tenant (delegates to repo; same return contract).
 *
 * @return array
 */
function nxtcc_get_current_tenant() {
	if ( ! is_user_logged_in() ) {
		return array( null, null, null, null );
	}

	return NXTCC_Contacts_Handler_Repo::instance()->get_current_tenant_for_user( get_current_user_id() );
}

/**
 * Capability gate.
 *
 * @param string $cap Required capability.
 * @return void
 */
function nxtcc_verify_caps( $cap = 'manage_options' ) {
	if ( ! current_user_can( $cap ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
	}
}

/**
 * Merge custom fields.
 *
 * Rules:
 * - Label is the key.
 * - If incoming value is empty => remove that label from stored JSON.
 * - Never store empty label or empty value in final JSON.
 * - Options preserved when provided.
 *
 * @param mixed $existing_json Existing JSON string (or array, or empty).
 * @param mixed $incoming_arr Incoming array (or JSON string).
 * @return string JSON array.
 */
function nxtcc_merge_custom_fields( $existing_json, $incoming_arr ) {

	/**
	 * Normalize to array.
	 *
	 * @param mixed $val Value.
	 * @return array
	 */
	$normalize = static function ( $val ) {
		if ( is_array( $val ) ) {
			return $val;
		}
		if ( is_string( $val ) ) {
			$s = trim( $val );
			if ( '' === $s ) {
				return array();
			}
			$tmp = json_decode( wp_unslash( $s ), true );
			return is_array( $tmp ) ? $tmp : array();
		}
		return array();
	};

	/**
	 * Sanitize one field array.
	 *
	 * @param array $f Field.
	 * @return array|null
	 */
	$sanitize_field = static function ( $f ) {
		if ( ! is_array( $f ) ) {
			return null;
		}

		$label = isset( $f['label'] ) ? trim( (string) $f['label'] ) : '';
		$type  = isset( $f['type'] ) ? trim( (string) $f['type'] ) : 'text';
		$value = isset( $f['value'] ) ? trim( (string) $f['value'] ) : '';

		// Options must be an array of strings.
		$options = array();
		if ( isset( $f['options'] ) && is_array( $f['options'] ) ) {
			foreach ( $f['options'] as $opt ) {
				$opt = trim( (string) $opt );
				if ( '' !== $opt ) {
					$options[] = $opt;
				}
			}
		}

		if ( '' === $label ) {
			return null;
		}

		return array(
			'label'   => $label,
			'type'    => ( '' !== $type ? $type : 'text' ),
			'value'   => $value, // Value can be empty; removal handled by merge rules.
			'options' => $options,
		);
	};

	$existing_arr = $normalize( $existing_json );
	$incoming_arr = $normalize( $incoming_arr );

	// Build existing map by label (drop empties).
	$map = array();
	foreach ( $existing_arr as $f ) {
		$sf = $sanitize_field( $f );
		if ( ! $sf ) {
			continue;
		}
		if ( '' === trim( (string) $sf['value'] ) ) {
			continue; // Never keep empty values.
		}
		$map[ $sf['label'] ] = $sf;
	}

	// Apply incoming updates.
	foreach ( $incoming_arr as $f ) {
		$sf = $sanitize_field( $f );
		if ( ! $sf ) {
			continue;
		}

		$label = $sf['label'];
		$value = trim( (string) $sf['value'] );

		// If incoming value is empty, remove from stored JSON.
		if ( '' === $value ) {
			unset( $map[ $label ] );
			continue;
		}

		// Upsert.
		$map[ $label ] = array(
			'label'   => $label,
			'type'    => $sf['type'],
			'value'   => $value,
			'options' => $sf['options'],
		);
	}

	// Output list, final safety drop empties.
	$out = array();
	foreach ( $map as $label => $sf ) {
		$label = trim( (string) $label );
		$val   = isset( $sf['value'] ) ? trim( (string) $sf['value'] ) : '';
		if ( '' === $label || '' === $val ) {
			continue;
		}
		$out[] = $sf;
	}

	return wp_json_encode( array_values( $out ), JSON_UNESCAPED_UNICODE );
}

/**
 * Site timezone string.
 *
 * @return string
 */
function nxtcc_get_site_timezone_string() {
	$tz = get_option( 'timezone_string' );

	if ( ! $tz || ! is_string( $tz ) ) {
		$offset = get_option( 'gmt_offset' );

		if ( false === $offset ) {
			return 'UTC';
		}

		$seconds = (int) ( floatval( $offset ) * HOUR_IN_SECONDS );
		$tz      = timezone_name_from_abbr( '', $seconds, 0 );

		if ( ! $tz ) {
			$tz = 'UTC';
		}
	}

	return $tz;
}

/**
 * Excel safe.
 *
 * @param mixed $v Value.
 * @return string
 */
function nxtcc_excel_safe( $v ) {
	if ( ! is_string( $v ) ) {
		$v = (string) $v;
	}
	return preg_match( '/^[=\+\-@]/', $v ) ? "\t" . $v : $v;
}

/**
 * Proxy: Any verified group.
 *
 * @param mixed $group_ids Group IDs.
 * @return bool
 */
function nxtcc_any_verified_group( $group_ids ) {
	return NXTCC_Contacts_Handler_Repo::instance()->any_verified_group( (array) $group_ids );
}

/**
 * Proxy: Strip verified groups.
 *
 * @param mixed $group_ids Group IDs.
 * @return array
 */
function nxtcc_strip_verified_groups( $group_ids ) {
	return NXTCC_Contacts_Handler_Repo::instance()->strip_verified_groups( (array) $group_ids );
}
