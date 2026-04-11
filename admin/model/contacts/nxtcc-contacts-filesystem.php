<?php
/**
 * Contacts filesystem + CSV helpers.
 *
 * Provides a thin wrapper around WP_Filesystem for safe file operations,
 * plus CSV utilities used by import/export flows.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nxtcc_fs_or_error' ) ) {
	/**
	 * Initialize WP_Filesystem and return the filesystem instance.
	 *
	 * All filesystem access in this module goes through WP_Filesystem to keep behavior
	 * consistent across environments. When initialization fails, this returns a JSON error
	 * because these helpers are used by AJAX handlers.
	 *
	 * @return WP_Filesystem_Base Filesystem instance.
	 */
	function nxtcc_fs_or_error() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			wp_send_json_error(
				array(
					/* translators: 1: function name */
					'message' => sprintf( __( '%s: Filesystem initialization failed.', 'nxt-cloud-chat' ), sanitize_text_field( __FUNCTION__ ) ),
				)
			);
		}

		return $wp_filesystem;
	}
}

if ( ! function_exists( 'nxtcc_fs_mkdir_p' ) ) {
	/**
	 * Recursively create a directory path using WP_Filesystem (mkdir -p behavior).
	 *
	 * WP_Filesystem::mkdir() may not create nested paths in one call across all transports.
	 * This helper builds the directory path segment-by-segment and applies FS_CHMOD_DIR.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True on success, false on failure.
	 */
	function nxtcc_fs_mkdir_p( string $path ): bool {
		$fs   = nxtcc_fs_or_error();
		$path = untrailingslashit( wp_normalize_path( $path ) );

		if ( true === $fs->is_dir( $path ) ) {
			return true;
		}

		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );

		/*
		 * Preserve a safe prefix:
		 * - Linux root "/"
		 * - Windows drive "C:"
		 */
		$prefix = '';
		if ( 0 === strpos( $path, '/' ) ) {
			$prefix = '/';
		} elseif ( 1 === preg_match( '/^[A-Za-z]:/', $path, $m ) ) {
			$prefix = $m[0];
		}

		$build = $prefix;

		foreach ( $segments as $seg ) {
			$build = ( '' === $build ) ? $seg : rtrim( $build, '/' ) . '/' . $seg;

			if ( true === $fs->is_dir( $build ) ) {
				continue;
			}

			if ( true !== $fs->mkdir( $build, FS_CHMOD_DIR ) ) {
				return false;
			}

			$fs->chmod( $build, FS_CHMOD_DIR );
		}

		return true;
	}
}

if ( ! function_exists( 'nxtcc_fs_get' ) ) {
	/**
	 * Read file contents using WP_Filesystem.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false File contents on success, false if file does not exist/read fails.
	 */
	function nxtcc_fs_get( string $path ) {
		$fs   = nxtcc_fs_or_error();
		$path = wp_normalize_path( $path );

		if ( true !== $fs->exists( $path ) ) {
			return false;
		}

		return $fs->get_contents( $path );
	}
}

if ( ! function_exists( 'nxtcc_fs_put' ) ) {
	/**
	 * Write content using WP_Filesystem and apply correct permissions.
	 *
	 * Creates parent directory if needed (mkdir -p behavior) when writing files.
	 *
	 * @param string $path   Absolute file/directory path.
	 * @param string $data   Data to write.
	 * @param bool   $is_dir Whether the path represents a directory.
	 * @return bool True on success, false on failure.
	 */
	function nxtcc_fs_put( string $path, string $data, bool $is_dir = false ): bool {
		$fs   = nxtcc_fs_or_error();
		$path = wp_normalize_path( $path );

		if ( false === $is_dir ) {
			$parent = wp_normalize_path( dirname( $path ) );

			if ( ( true !== $fs->is_dir( $parent ) ) && ( true !== nxtcc_fs_mkdir_p( $parent ) ) ) {
				return false;
			}
		}

		$chmod = ( true === $is_dir ) ? FS_CHMOD_DIR : FS_CHMOD_FILE;
		$ok    = $fs->put_contents( $path, $data, $chmod );

		if ( true !== $ok ) {
			return false;
		}

		$fs->chmod( $path, $chmod );

		return true;
	}
}

if ( ! function_exists( 'nxtcc_csv_build' ) ) {
	/**
	 * Convert rows into CSV lines (CRLF) while mitigating spreadsheet formula injection.
	 *
	 * - Prefix values that start with "= + - @" by tab to reduce formula execution.
	 * - Quote values that contain separators/quotes/newlines/tabs or leading/trailing spaces.
	 * - Escape quotes by doubling them.
	 *
	 * @param array $rows List of rows. Each row is an array of scalar values.
	 * @return string CSV output (CRLF). Includes trailing CRLF when rows exist.
	 */
	function nxtcc_csv_build( array $rows ): string {
		$out = array();

		foreach ( $rows as $row ) {
			$line_vals = array();

			foreach ( (array) $row as $val ) {
				$val = (string) $val;

				if ( 1 === preg_match( '/^[=\+\-@]/', $val ) ) {
					$val = "\t" . $val;
				}

				$needs_quotes = ( false !== strpbrk( $val, ",\"\r\n\t" ) )
					|| ( ltrim( $val ) !== $val )
					|| ( rtrim( $val ) !== $val );

				if ( true === $needs_quotes ) {
					$val = '"' . str_replace( '"', '""', $val ) . '"';
				}

				$line_vals[] = $val;
			}

			$out[] = implode( ',', $line_vals );
		}

		return implode( "\r\n", $out ) . ( ( 0 !== count( $out ) ) ? "\r\n" : '' );
	}
}

if ( ! function_exists( 'nxtcc_fs_append_csv_lines' ) ) {
	/**
	 * Append CSV rows to a file.
	 *
	 * WP_Filesystem does not guarantee a portable append operation, so we read the existing
	 * file (if present) and write back the combined content.
	 *
	 * @param string $path            Absolute file path.
	 * @param array  $lines_as_arrays Rows to append (each row is an array of scalar values).
	 * @return bool True on success, false on failure.
	 */
	function nxtcc_fs_append_csv_lines( string $path, array $lines_as_arrays ): bool {
		$existing = nxtcc_fs_get( $path );
		$buf      = ( true === is_string( $existing ) ) ? $existing : '';
		$buf     .= nxtcc_csv_build( $lines_as_arrays );

		return nxtcc_fs_put( $path, $buf, false );
	}
}

if ( ! function_exists( 'nxtcc_output_bytes' ) ) {
	/**
	 * Stream raw bytes to the HTTP response via WP_Filesystem.
	 *
	 * Uses php://output as the target, allowing consistent streaming behavior.
	 *
	 * @param string $bytes Bytes to output.
	 * @return void
	 */
	function nxtcc_output_bytes( string $bytes ): void {
		$fs = nxtcc_fs_or_error();

		$ok = $fs->put_contents( 'php://output', $bytes, false );

		if ( false === $ok ) {
			wp_die( esc_html__( 'Failed to stream the file to the browser.', 'nxt-cloud-chat' ) );
		}
	}
}
