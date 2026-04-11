<?php
/**
 * Contacts import AJAX actions.
 *
 * Admin workflow implemented here:
 * 1) Sample: download a CSV template.
 * 2) Upload: accept a CSV, store it under uploads, detect columns + count data rows, return a token.
 * 3) Validate: parse the stored CSV, compute stats, and store mapping/settings for the run step.
 * 4) Run: chunked import using offset and mode (skip/update), accumulating row-level errors.
 *
 * VIP compatibility:
 * - This file avoids fopen()/fwrite() and other local stream/file handles that can trigger VIP sniffs.
 * - CSV parsing is done with string operations and str_getcsv(), using bytes read via plugin FS helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return plugin uploads directory (contacts import/export storage).
 *
 * @return array{0:string,1:string} [dir, url].
 */
function nxtcc_contacts_upload_dir(): array {
	$u   = wp_get_upload_dir();
	$dir = trailingslashit( $u['basedir'] ) . 'nxtcc-contacts/';
	$url = trailingslashit( $u['baseurl'] ) . 'nxtcc-contacts/';
	return array( $dir, $url );
}

/**
 * Strip UTF-8 BOM from a string.
 *
 * @param string $s String.
 * @return string
 */
function nxtcc_strip_utf8_bom( string $s ): string {
	return (string) preg_replace( '/^\xEF\xBB\xBF/', '', $s );
}

/**
 * Detect CSV delimiter from a line by counting common separators.
 *
 * @param string $line First non-empty line.
 * @return string One of: , ; \t |
 */
function nxtcc_contacts_detect_csv_delimiter_from_line( string $line ): string {
	$candidates = array(
		','  => substr_count( $line, ',' ),
		';'  => substr_count( $line, ';' ),
		"\t" => substr_count( $line, "\t" ),
		'|'  => substr_count( $line, '|' ),
	);

	$best = ',';
	$max  = -1;

	foreach ( $candidates as $delim => $count ) {
		if ( $max < $count ) {
			$max  = $count;
			$best = $delim;
		}
	}

	return ( 0 < $max ) ? $best : ',';
}

/**
 * Split raw CSV bytes into logical lines.
 *
 * @param string $raw CSV bytes.
 * @return array<int,string>
 */
function nxtcc_contacts_csv_lines_from_bytes( string $raw ): array {
	$raw = (string) $raw;

	// Normalize line endings to "\n", then split.
	$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

	$lines = explode( "\n", $raw );
	if ( ! is_array( $lines ) ) {
		return array();
	}

	return $lines;
}

/**
 * Read the first non-empty line from CSV bytes.
 *
 * @param string $raw CSV bytes.
 * @return string
 */
function nxtcc_contacts_read_first_non_empty_line_from_bytes( string $raw ): string {
	$lines = nxtcc_contacts_csv_lines_from_bytes( $raw );

	foreach ( $lines as $line ) {
		if ( '' !== trim( (string) $line ) ) {
			return (string) $line;
		}
	}

	return '';
}

/**
 * Parse a CSV row string using delimiter.
 *
 * @param string $line      One CSV line.
 * @param string $delimiter Delimiter.
 * @return array<int,string>
 */
function nxtcc_contacts_parse_csv_line( string $line, string $delimiter ): array {
	// str_getcsv handles quotes and escaped quotes.
	$row = str_getcsv( $line, $delimiter );

	if ( ! is_array( $row ) ) {
		return array();
	}

	$out = array();
	foreach ( $row as $cell ) {
		$out[] = is_string( $cell ) ? $cell : (string) $cell;
	}

	return $out;
}

/**
 * Read header (first row) from CSV bytes.
 *
 * @param string $raw       CSV bytes.
 * @param string $delimiter Delimiter.
 * @return array<int,string>
 */
function nxtcc_contacts_read_csv_header_from_bytes( string $raw, string $delimiter ): array {
	$lines = nxtcc_contacts_csv_lines_from_bytes( $raw );

	foreach ( $lines as $line ) {
		$line = (string) $line;

		if ( '' === trim( $line ) ) {
			continue;
		}

		$row = nxtcc_contacts_parse_csv_line( $line, $delimiter );
		if ( empty( $row ) ) {
			return array();
		}

		foreach ( $row as $i => $v ) {
			$cell = trim( (string) $v );
			if ( 0 === $i ) {
				$cell = nxtcc_strip_utf8_bom( $cell );
			}
			$row[ $i ] = $cell;
		}

		$all_empty = true;
		foreach ( $row as $v ) {
			if ( '' !== (string) $v ) {
				$all_empty = false;
				break;
			}
		}

		return ( true === $all_empty ) ? array() : $row;
	}

	return array();
}

/**
 * Count non-empty data rows in CSV bytes.
 *
 * @param string $raw        CSV bytes.
 * @param string $delimiter  Delimiter.
 * @param bool   $has_header Whether first row is a header row.
 * @return int
 */
function nxtcc_contacts_count_csv_rows_from_bytes( string $raw, string $delimiter, bool $has_header ): int {
	$lines = nxtcc_contacts_csv_lines_from_bytes( $raw );

	$count      = 0;
	$seen_first = false;

	foreach ( $lines as $line ) {
		$line = (string) $line;

		if ( '' === trim( $line ) ) {
			continue;
		}

		// Skip the first non-empty row if it is a header.
		if ( true === $has_header && false === $seen_first ) {
			$seen_first = true;
			continue;
		}
		$seen_first = true;

		$row = nxtcc_contacts_parse_csv_line( $line, $delimiter );
		if ( empty( $row ) ) {
			continue;
		}

		$empty = true;
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				$empty = false;
				break;
			}
		}
		if ( true === $empty ) {
			continue;
		}

		++$count;
	}

	return (int) $count;
}

/**
 * Build sample CSV contents for import.
 *
 * @return string
 */
function nxtcc_contacts_import_sample_csv(): string {
	$rows = array(
		array( 'name', 'country_code', 'phone_number' ),
		array(
			'John Doe',
			'91',
			'9876543210',
		),
	);

	return nxtcc_csv_build( $rows );
}

/**
 * Load import metadata from transient.
 *
 * @param string $token Token.
 * @return array<string,mixed>|null
 */
function nxtcc_contacts_import_get_meta( string $token ) {
	$meta = get_transient( 'nxtcc_contacts_import_' . $token );
	return is_array( $meta ) ? $meta : null;
}

/**
 * Save import metadata to transient.
 *
 * @param string $token Token.
 * @param array  $meta  Meta.
 * @return void
 */
function nxtcc_contacts_import_set_meta( string $token, array $meta ): void {
	set_transient( 'nxtcc_contacts_import_' . $token, $meta, HOUR_IN_SECONDS );
}

/**
 * Parse mapping input (JSON string of [{csvIndex,target}]).
 *
 * @param string $json JSON string.
 * @return array<int,array{csvIndex:int,target:string}>
 */
function nxtcc_contacts_import_parse_mapping( string $json ): array {
	$out = array();

	$m = json_decode( $json, true );
	if ( ! is_array( $m ) ) {
		return $out;
	}

	foreach ( $m as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$idx    = isset( $item['csvIndex'] ) ? (int) $item['csvIndex'] : null;
		$target = isset( $item['target'] ) ? (string) $item['target'] : '';
		$target = trim( $target );

		if ( null === $idx || 0 > $idx || '' === $target || 'ignore' === $target ) {
			continue;
		}

		$out[] = array(
			'csvIndex' => $idx,
			'target'   => $target,
		);
	}

	return $out;
}

/**
 * Extract required fields from a parsed CSV row using mapping.
 *
 * Targets:
 * - name
 * - country_code
 * - phone_number
 * - custom:<Label>
 *
 * @param array $row     Parsed CSV row.
 * @param array $mapping Mapping array.
 * @return array{0:string,1:string,2:string,3:array<int,array<string,mixed>>} [name, country_code, phone_number, custom_fields_array]
 */
function nxtcc_contacts_import_extract_fields_from_row( array $row, array $mapping ): array {
	$name = '';
	$cc   = '';
	$pn   = '';
	$cf   = array();

	foreach ( $mapping as $m ) {
		$idx    = (int) $m['csvIndex'];
		$target = (string) $m['target'];

		$val = isset( $row[ $idx ] ) ? (string) $row[ $idx ] : '';
		$val = trim( $val );

		if ( 'name' === $target ) {
			$name = $val;
		} elseif ( 'country_code' === $target ) {
			$cc = (string) preg_replace( '/\D/', '', $val );
		} elseif ( 'phone_number' === $target ) {
			$pn = (string) preg_replace( '/\D/', '', $val );
		} elseif ( 0 === strpos( $target, 'custom:' ) ) {
			$key = trim( substr( $target, 7 ) );
			if ( '' !== $key && '' !== $val ) {
				$cf[] = array(
					'label' => $key,
					'type'  => 'text',
					'value' => $val,
				);
			}
		}
	}

	return array( $name, $cc, $pn, $cf );
}

/**
 * AJAX: Download a sample CSV file.
 *
 * @return void
 */
function nxtcc_ajax_contacts_import_sample(): void {
	check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
	nxtcc_verify_caps( 'manage_options' );

	$csv = nxtcc_contacts_import_sample_csv();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=nxtcc-contacts-import-sample.csv' );

	nxtcc_output_bytes( $csv );
	exit;
}
add_action( 'wp_ajax_nxtcc_contacts_import_sample', 'nxtcc_ajax_contacts_import_sample' );

/**
 * AJAX: Upload a CSV and return token + columns + total_rows.
 *
 * Expects:
 * - $_FILES['file'].
 * - has_header (0/1).
 * - delimiter (auto | , | ; | \t | |).
 *
 * @return void
 */
function nxtcc_ajax_contacts_import_upload(): void {
	check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
	nxtcc_verify_caps( 'manage_options' );

	if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
		wp_send_json_error( array( 'message' => 'No file uploaded.' ) );
	}

	$has_header = false;
	if ( isset( $_POST['has_header'] ) ) {
		$has_header = ( '1' === (string) sanitize_text_field( wp_unslash( $_POST['has_header'] ) ) );
	}

	$delimiter = 'auto';
	if ( isset( $_POST['delimiter'] ) ) {
		$delimiter = (string) sanitize_text_field( wp_unslash( $_POST['delimiter'] ) );
	}
	$delimiter = trim( $delimiter );
	if ( '' === $delimiter ) {
		$delimiter = 'auto';
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$uploaded = wp_handle_upload(
		$_FILES['file'],
		array( 'test_form' => false )
	);

	if ( empty( $uploaded['file'] ) ) {
		wp_send_json_error(
			array(
				'message' => 'Upload failed.',
				'error'   => isset( $uploaded['error'] ) ? (string) $uploaded['error'] : '',
			)
		);
	}

	$src = wp_normalize_path( (string) $uploaded['file'] );
	$ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );

	$fs = nxtcc_fs_or_error();

	if ( 'csv' !== $ext ) {
		if ( true === $fs->exists( $src ) ) {
			$fs->delete( $src );
		}
		wp_send_json_error( array( 'message' => 'Only CSV files allowed.' ) );
	}

	$bytes = nxtcc_fs_get( $src );

	if ( true === $fs->exists( $src ) ) {
		$fs->delete( $src );
	}

	if ( false === $bytes || '' === $bytes ) {
		wp_send_json_error( array( 'message' => 'Failed to read uploaded file.' ) );
	}

	list( $dir ) = nxtcc_contacts_upload_dir();
	if ( true !== nxtcc_fs_mkdir_p( $dir ) ) {
		wp_send_json_error( array( 'message' => 'Failed to prepare uploads directory.' ) );
	}

	$token = wp_generate_password( 16, false, false );
	$dest  = $dir . 'import-' . $token . '.csv';

	if ( true !== nxtcc_fs_put( $dest, (string) $bytes, false ) ) {
		wp_send_json_error( array( 'message' => 'Failed to store uploaded file.' ) );
	}

	if ( 'auto' === strtolower( $delimiter ) ) {
		$first_line = nxtcc_contacts_read_first_non_empty_line_from_bytes( (string) $bytes );
		$delimiter  = ( '' !== $first_line ) ? nxtcc_contacts_detect_csv_delimiter_from_line( $first_line ) : ',';
	}

	if ( '\t' === $delimiter || 'tab' === strtolower( $delimiter ) ) {
		$delimiter = "\t";
	}
	if ( ! in_array( $delimiter, array( ',', ';', "\t", '|' ), true ) ) {
		$delimiter = ',';
	}

	$columns = nxtcc_contacts_read_csv_header_from_bytes( (string) $bytes, $delimiter );

	if ( false === $has_header && ! empty( $columns ) ) {
		$count = count( $columns );
		$gen   = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$gen[] = 'Column ' . $i;
		}
		$columns = $gen;
	}

	$total_rows = nxtcc_contacts_count_csv_rows_from_bytes( (string) $bytes, $delimiter, $has_header );

	nxtcc_contacts_import_set_meta(
		$token,
		array(
			'path'               => $dest,
			'delimiter'          => $delimiter,
			'has_header'         => $has_header ? 1 : 0,
			'columns'            => $columns,
			'total_rows'         => (int) $total_rows,
			'created_at'         => time(),
			'mapping'            => null,
			'default_groups'     => array(),
			'default_subscribed' => 1,
			'errors'             => array(),
		)
	);

	if ( empty( $columns ) ) {
		wp_send_json_error(
			array(
				'message' => 'No CSV columns detected. Please check the uploaded file format.',
				'token'   => $token,
			)
		);
	}

	wp_send_json_success(
		array(
			'token'      => $token,
			'columns'    => $columns,
			'total_rows' => (int) $total_rows,
			'delimiter'  => ( "\t" === $delimiter ) ? '\t' : $delimiter,
			'has_header' => $has_header ? 1 : 0,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_import_upload', 'nxtcc_ajax_contacts_import_upload' );

/**
 * AJAX: Validate an uploaded CSV.
 *
 * Request:
 * - token.
 * - mapping (JSON).
 * - default_groups (JSON).
 * - default_subscribed (0/1).
 *
 * Response:
 * - stats {total, valid, invalid, dup_in_file, dup_in_db}.
 *
 * @return void
 */
function nxtcc_ajax_contacts_import_validate(): void {
	check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$token = '';
	if ( isset( $_POST['token'] ) ) {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ) );
	}

	$mapping_json = '';
	if ( isset( $_POST['mapping'] ) ) {
		$mapping_json = (string) sanitize_textarea_field( wp_unslash( $_POST['mapping'] ) );
	}

	$default_groups_json = '[]';
	if ( isset( $_POST['default_groups'] ) ) {
		$default_groups_json = (string) sanitize_textarea_field( wp_unslash( $_POST['default_groups'] ) );
	}

	$default_subscribed = '1';
	if ( isset( $_POST['default_subscribed'] ) ) {
		$default_subscribed = (string) sanitize_text_field( wp_unslash( $_POST['default_subscribed'] ) );
	}

	if ( '' === $token ) {
		wp_send_json_error( array( 'message' => 'Missing token.' ) );
	}

	$meta = nxtcc_contacts_import_get_meta( $token );
	if ( ! $meta || empty( $meta['path'] ) ) {
		wp_send_json_error( array( 'message' => 'Upload token expired. Please upload again.' ) );
	}

	$path       = (string) $meta['path'];
	$delimiter  = isset( $meta['delimiter'] ) ? (string) $meta['delimiter'] : ',';
	$has_header = ! empty( $meta['has_header'] );

	$raw = nxtcc_fs_get( $path );
	if ( ! is_string( $raw ) || '' === $raw ) {
		wp_send_json_error( array( 'message' => 'File empty or unreadable.' ) );
	}

	$mapping = nxtcc_contacts_import_parse_mapping( $mapping_json );
	if ( empty( $mapping ) ) {
		wp_send_json_error( array( 'message' => 'Missing or invalid mapping.' ) );
	}

	$default_groups = json_decode( $default_groups_json, true );
	$default_groups = is_array( $default_groups ) ? $default_groups : array();
	$default_groups = array_values( array_filter( array_map( 'intval', $default_groups ) ) );

	$meta['mapping']            = $mapping;
	$meta['default_groups']     = $default_groups;
	$meta['default_subscribed'] = ( '1' === $default_subscribed ) ? 1 : 0;

	nxtcc_contacts_import_set_meta( $token, $meta );

	$lines = nxtcc_contacts_csv_lines_from_bytes( $raw );

	$total       = 0;
	$valid       = 0;
	$invalid     = 0;
	$dup_in_file = 0;
	$dup_in_db   = 0;

	$seen = array(); // Tracks unique country-code and phone pairs seen in this file.

	$repo       = NXTCC_Contacts_Handler_Repo::instance();
	$seen_first = false;

	foreach ( $lines as $line ) {
		$line = (string) $line;

		if ( '' === trim( $line ) ) {
			continue;
		}

		if ( true === $has_header && false === $seen_first ) {
			$seen_first = true;
			$row        = nxtcc_contacts_parse_csv_line( $line, $delimiter );
			if ( isset( $row[0] ) ) {
				$row[0] = nxtcc_strip_utf8_bom( (string) $row[0] );
			}
			continue;
		}
		$seen_first = true;

		$row = nxtcc_contacts_parse_csv_line( $line, $delimiter );
		if ( empty( $row ) ) {
			continue;
		}

		$empty = true;
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				$empty = false;
				break;
			}
		}
		if ( true === $empty ) {
			continue;
		}

		++$total;

		list( $name, $cc, $pn ) = nxtcc_contacts_import_extract_fields_from_row( $row, $mapping );

		if ( '' === $name || '' === $cc || '' === $pn ) {
			++$invalid;
			continue;
		}

		$key = $cc . '|' . $pn;
		if ( isset( $seen[ $key ] ) ) {
			++$dup_in_file;
			++$valid;
			continue;
		}

		$seen[ $key ] = true;

		$dup = $repo->find_duplicate_in_tenant( $baid, $pnid, $cc, $pn );
		if ( $dup ) {
			++$dup_in_db;
		}

		++$valid;
	}

	wp_send_json_success(
		array(
			'stats' => array(
				'total'       => (int) $total,
				'valid'       => (int) $valid,
				'invalid'     => (int) $invalid,
				'dup_in_file' => (int) $dup_in_file,
				'dup_in_db'   => (int) $dup_in_db,
			),
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_import_validate', 'nxtcc_ajax_contacts_import_validate' );

/**
 * AJAX: Run import in chunks.
 *
 * Request:
 * - token.
 * - mode: skip|update (default skip).
 * - offset (0-based offset in data rows, excluding header).
 *
 * Response:
 * - total, done, inserted, updated, skipped, next_offset, finished, logs[], error_csv_url.
 *
 * @return void
 */
function nxtcc_ajax_contacts_import_run(): void {
	check_ajax_referer( 'nxtcc_contacts_nonce', 'security' );
	nxtcc_verify_caps( 'manage_options' );

	list( $user_mailid, $baid, $pnid ) = nxtcc_get_current_tenant();
	if ( empty( $user_mailid ) || empty( $baid ) || empty( $pnid ) ) {
		wp_send_json_error( array( 'message' => 'Tenant not configured.' ) );
	}

	$token = '';
	if ( isset( $_POST['token'] ) ) {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ) );
	}

	$mode = 'skip';
	if ( isset( $_POST['mode'] ) ) {
		$mode = sanitize_text_field( wp_unslash( $_POST['mode'] ) );
	}

	$offset = 0;
	if ( isset( $_POST['offset'] ) ) {
		$offset = (int) sanitize_text_field( wp_unslash( $_POST['offset'] ) );
	}

	if ( '' === $token ) {
		wp_send_json_error( array( 'message' => 'Missing token.' ) );
	}

	$mode = strtolower( (string) $mode );
	$mode = in_array( $mode, array( 'update', 'upsert', 'overwrite' ), true ) ? 'update' : 'skip';

	if ( 0 > $offset ) {
		$offset = 0;
	}

	$meta = nxtcc_contacts_import_get_meta( $token );
	if ( ! $meta || empty( $meta['path'] ) ) {
		wp_send_json_error( array( 'message' => 'Upload token expired. Please upload again.' ) );
	}

	$path       = (string) $meta['path'];
	$delimiter  = isset( $meta['delimiter'] ) ? (string) $meta['delimiter'] : ',';
	$has_header = ! empty( $meta['has_header'] );
	$total_rows = isset( $meta['total_rows'] ) ? (int) $meta['total_rows'] : 0;

	$mapping = ( isset( $meta['mapping'] ) && is_array( $meta['mapping'] ) ) ? $meta['mapping'] : array();
	if ( empty( $mapping ) ) {
		wp_send_json_error( array( 'message' => 'Missing mapping. Please run validation first.' ) );
	}

	$default_groups     = ( isset( $meta['default_groups'] ) && is_array( $meta['default_groups'] ) ) ? $meta['default_groups'] : array();
	$default_subscribed = isset( $meta['default_subscribed'] ) ? (int) $meta['default_subscribed'] : 1;

	$raw = nxtcc_fs_get( $path );
	if ( ! is_string( $raw ) || '' === $raw ) {
		wp_send_json_error( array( 'message' => 'File empty or unreadable.' ) );
	}

	$chunk_size = 200;

	$repo = NXTCC_Contacts_Handler_Repo::instance();
	$now  = current_time( 'mysql', 1 );

	$logs     = array();
	$inserted = 0;
	$updated  = 0;
	$skipped  = 0;

	if ( ! isset( $meta['errors'] ) || ! is_array( $meta['errors'] ) ) {
		$meta['errors'] = array();
	}

	$lines = nxtcc_contacts_csv_lines_from_bytes( $raw );

	$data_i     = 0; // Data rows excluding header, skipping empty rows.
	$processed  = 0;
	$seen_first = false;

	foreach ( $lines as $line ) {
		$line = (string) $line;

		if ( '' === trim( $line ) ) {
			continue;
		}

		if ( true === $has_header && false === $seen_first ) {
			$seen_first = true;
			continue;
		}
		$seen_first = true;

		$row = nxtcc_contacts_parse_csv_line( $line, $delimiter );
		if ( empty( $row ) ) {
			continue;
		}

		$empty = true;
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				$empty = false;
				break;
			}
		}
		if ( true === $empty ) {
			continue;
		}

		if ( $data_i < $offset ) {
			++$data_i;
			continue;
		}

		if ( $chunk_size <= $processed ) {
			break;
		}

		++$data_i;
		++$processed;

		list( $name, $cc, $pn, $incoming_cf ) = nxtcc_contacts_import_extract_fields_from_row( $row, $mapping );

		if ( '' === $name || '' === $cc || '' === $pn ) {
			++$skipped;
			$meta['errors'][] = array(
				'row'   => $data_i + ( $has_header ? 1 : 0 ),
				'error' => 'Missing required fields (name/country_code/phone_number).',
			);
			continue;
		}

		$dup = $repo->find_duplicate_in_tenant( $baid, $pnid, $cc, $pn );

		$group_ids   = $repo->allowlist_user_groups( $user_mailid, $default_groups );
		$is_verified = $repo->contact_verified_flag_from_groups( $group_ids );

		if ( $dup ) {
			if ( 'skip' === $mode ) {
				++$skipped;
				continue;
			}

			$merged_json = nxtcc_merge_custom_fields( $dup->custom_fields, $incoming_cf );

			$repo->upsert_contact_custom_fields( (int) $dup->id, $name, $merged_json, $default_subscribed );
			$repo->replace_contact_groups( (int) $dup->id, $group_ids );
			$repo->update_contact_basic(
				(int) $dup->id,
				array(
					'is_verified'         => $is_verified,
					'business_account_id' => $baid,
					'phone_number_id'     => $pnid,
					'updated_at'          => $now,
				)
			);

			++$updated;
			continue;
		}

		$merged_json = nxtcc_merge_custom_fields( '', $incoming_cf );

		$new_id = $repo->insert_contact(
			array(
				'user_mailid'         => $user_mailid,
				'business_account_id' => $baid,
				'phone_number_id'     => $pnid,
				'name'                => $name,
				'country_code'        => $cc,
				'phone_number'        => $pn,
				'custom_fields'       => $merged_json,
				'is_verified'         => $is_verified,
				'is_subscribed'       => ( 1 === (int) $default_subscribed ) ? 1 : 0,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);

		if ( 0 >= (int) $new_id ) {
			++$skipped;
			$meta['errors'][] = array(
				'row'   => $data_i + ( $has_header ? 1 : 0 ),
				'error' => 'Failed to create contact.',
			);
			continue;
		}

		$repo->map_groups_for_new_contact( (int) $new_id, $group_ids );
		++$inserted;
	}

	nxtcc_contacts_import_set_meta( $token, $meta );

	$done = $offset + $processed;

	$finished = false;
	if ( 0 < $total_rows ) {
		$finished = ( $total_rows <= $done );
	} else {
		$finished = ( 0 === $processed );
	}

	$error_csv_url = '';
	if ( true === $finished && ! empty( $meta['errors'] ) ) {
		list( $dir, $url ) = nxtcc_contacts_upload_dir();

		if ( true !== nxtcc_fs_mkdir_p( $dir ) ) {
			wp_send_json_error( array( 'message' => 'Failed to prepare error log directory.' ) );
		}

		$err_path = $dir . 'import-errors-' . $token . '.csv';
		$rows     = array( array( 'row', 'error' ) );

		foreach ( $meta['errors'] as $e ) {
			$rows[] = array(
				(string) ( isset( $e['row'] ) ? $e['row'] : '' ),
				(string) ( isset( $e['error'] ) ? $e['error'] : '' ),
			);
		}

		$csv = nxtcc_csv_build( $rows );

		if ( true === nxtcc_fs_put( $err_path, $csv, false ) ) {
			$error_csv_url = $url . 'import-errors-' . $token . '.csv';
		}
	}

	if ( true === $finished ) {
		$fs = nxtcc_fs_or_error();
		if ( true === $fs->exists( $path ) ) {
			$fs->delete( $path );
		}
		delete_transient( 'nxtcc_contacts_import_' . $token );
	}

	wp_send_json_success(
		array(
			'total'         => (int) $total_rows,
			'done'          => (int) ( ( 0 < $total_rows ) ? min( $done, $total_rows ) : $done ),
			'inserted'      => (int) $inserted,
			'updated'       => (int) $updated,
			'skipped'       => (int) $skipped,
			'next_offset'   => (int) $done,
			'finished'      => (bool) $finished,
			'logs'          => $logs,
			'error_csv_url' => $error_csv_url,
		)
	);
}
add_action( 'wp_ajax_nxtcc_contacts_import_run', 'nxtcc_ajax_contacts_import_run' );
