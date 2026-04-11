<?php
/**
 * Core helpers for NXT Cloud Chat.
 *
 * Contains DB-free helpers used across the plugin:
 * - Crypto helpers for encrypting/decrypting stored secrets.
 * - Cache helpers built on object cache.
 * - Template payload builders and UI field extraction.
 * - Country code lookup utilities and visitor country detection.
 *
 * Data access is handled through filters (DAO wires those filters separately).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * DB-free helper utilities for NXT Cloud Chat.
 */
final class NXTCC_Helpers {

	/**
	 * Cache group for wp_cache_* calls.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'nxtcc';

	/**
	 * Default cache TTL in seconds (must be >= 300 for VIP cache sniff).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 300;

	/**
	 * Transient key used to store country codes parsed from JSON.
	 *
	 * @var string
	 */
	private const T_COUNTRY_CODES = 'nxtcc_country_codes_v1';

	/**
	 * Cache namespace for template list.
	 *
	 * @var string
	 */
	private const CKEY_TEMPLATES = 'templates:list';

	/**
	 * Cache namespace for the set of template names.
	 *
	 * @var string
	 */
	private const CKEY_TEMPLATE_SET = 'templates:names';

	/**
	 * Cache namespace for tenant credentials.
	 *
	 * @var string
	 */
	private const CKEY_TENANT = 'tenant_creds';

	/**
	 * Prefix for ciphertexts encrypted with the OpenSSL fallback.
	 *
	 * @var string
	 */
	private const CRYPTO_PREFIX_OPENSSL = 'v2:';

	/**
	 * Check if libsodium secretbox functions are available.
	 *
	 * @return bool
	 */
	private static function crypto_can_use_sodium(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Check if OpenSSL AEAD functions are available.
	 *
	 * @return bool
	 */
	private static function crypto_can_use_openssl(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Check whether at least one crypto backend is available.
	 *
	 * @return bool
	 */
	public static function crypto_backend_available(): bool {
		return self::crypto_can_use_sodium() || self::crypto_can_use_openssl();
	}

	/**
	 * Encode binary data as base64 using sodium when possible.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function crypto_b64_encode( string $bytes ): string {
		if ( function_exists( 'sodium_bin2base64' ) ) {
			$variant = defined( 'SODIUM_BASE64_VARIANT_ORIGINAL' ) ? (int) SODIUM_BASE64_VARIANT_ORIGINAL : 1;
			return sodium_bin2base64( $bytes, $variant );
		}

		$encoded = bin2hex( $bytes );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Decode base64 data to binary using sodium when possible.
	 *
	 * @param string $encoded Base64 text.
	 * @return string|false
	 */
	private static function crypto_b64_decode( string $encoded ) {
		if ( function_exists( 'sodium_base642bin' ) ) {
			try {
				$variant = defined( 'SODIUM_BASE64_VARIANT_ORIGINAL' ) ? (int) SODIUM_BASE64_VARIANT_ORIGINAL : 1;
				return sodium_base642bin( $encoded, $variant, '' );
			} catch ( Throwable $e ) {
				return false;
			}
		}

		if ( '' === $encoded || ( strlen( $encoded ) % 2 ) !== 0 ) {
			return false;
		}

		if ( ! ctype_xdigit( $encoded ) ) {
			return false;
		}

		$decoded = hex2bin( $encoded );
		return is_string( $decoded ) ? $decoded : false;
	}

	/**
	 * Derive a key-encryption key (KEK) from WordPress salts.
	 *
	 * @return string Binary key material.
	 */
	public static function crypto_derive_kek(): string {
		$key_len  = defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ? (int) SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;
		$material = (string) ( AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY );
		$info     = 'nxtcc/v1/kek';
		$salt     = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = 'localhost';
		}

		if ( function_exists( 'hash_hkdf' ) ) {
			$derived = hash_hkdf( 'sha256', $material, $key_len, $info, $salt );
			return (string) substr( (string) $derived, 0, $key_len );
		}

		$prk     = hash_hmac( 'sha256', $material, $salt, true );
		$t       = '';
		$okm     = '';
		$okm_len = 0;
		$i       = 1;

		while ( $okm_len < $key_len ) {
			$t    = hash_hmac( 'sha256', $t . $info . chr( $i ), $prk, true );
			$okm .= $t;

			$okm_len = strlen( $okm );
			++$i;
		}

		return (string) substr( $okm, 0, $key_len );
	}

	/**
	 * Encrypt plaintext using libsodium secretbox, with OpenSSL fallback.
	 *
	 * @param string $plaintext Plain text to encrypt.
	 * @return array{0:?string,1:?string} [ciphertext_b64, nonce_raw] or [null, null] on failure.
	 */
	public static function crypto_encrypt( string $plaintext ): array {
		$key       = self::crypto_derive_kek();
		$nonce_len = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ? (int) SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;

		if ( self::crypto_can_use_sodium() ) {
			try {
				$nonce = random_bytes( $nonce_len );
				$ct    = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				$b64   = self::crypto_b64_encode( $ct );

				if ( '' !== $b64 ) {
					return array( $b64, $nonce );
				}
			} catch ( Throwable $e ) {
				self::log_api_response( 'nxtcc_crypto_encrypt_sodium_failed' );
			}
		}

		if ( ! self::crypto_can_use_openssl() ) {
			return array( null, null );
		}

		try {
			/*
			 * Keep nonce storage length compatible with existing BINARY(24) columns.
			 * Use first 12 bytes as AES-GCM IV (recommended IV size).
			 */
			$nonce = random_bytes( $nonce_len );
			$iv    = substr( $nonce, 0, 12 );

			if ( 12 !== strlen( $iv ) ) {
				return array( null, null );
			}

			$tag = '';
			$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( ! is_string( $ct ) || ! is_string( $tag ) || 16 !== strlen( $tag ) ) {
				return array( null, null );
			}

			$payload = self::crypto_b64_encode( $ct . $tag );
			if ( '' === $payload ) {
				return array( null, null );
			}

			return array( self::CRYPTO_PREFIX_OPENSSL . $payload, $nonce );
		} catch ( Throwable $e ) {
			return array( null, null );
		}
	}

	/**
	 * Decrypt ciphertext using libsodium secretbox or OpenSSL fallback.
	 *
	 * @param string|null $ct_b64    Ciphertext base64.
	 * @param string|null $nonce_raw Nonce raw bytes.
	 * @return string|\WP_Error Plaintext string or WP_Error on failure.
	 */
	public static function crypto_decrypt( ?string $ct_b64, ?string $nonce_raw ) {
		if ( null === $ct_b64 || null === $nonce_raw ) {
			return new WP_Error( 'nxtcc_crypto_missing', 'Cannot decrypt.' );
		}

		$key = self::crypto_derive_kek();

		// OpenSSL fallback payloads are prefixed so decrypt can choose the algorithm.
		if ( 0 === strpos( $ct_b64, self::CRYPTO_PREFIX_OPENSSL ) ) {
			if ( ! self::crypto_can_use_openssl() ) {
				return new WP_Error( 'nxtcc_crypto_missing', 'Cannot decrypt.' );
			}

			$packed_b64 = substr( $ct_b64, strlen( self::CRYPTO_PREFIX_OPENSSL ) );
			$packed     = self::crypto_b64_decode( (string) $packed_b64 );

			if ( ! is_string( $packed ) || 16 >= strlen( $packed ) ) {
				return new WP_Error( 'nxtcc_crypto_b64', 'Invalid ciphertext.' );
			}

			$ct  = substr( $packed, 0, -16 );
			$tag = substr( $packed, -16 );
			$iv  = substr( $nonce_raw, 0, 12 );

			if ( ! is_string( $ct ) || ! is_string( $tag ) || 12 !== strlen( $iv ) ) {
				return new WP_Error( 'nxtcc_crypto_open', 'Decrypt failed.' );
			}

			try {
				$pt = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			} catch ( Throwable $e ) {
				return new WP_Error( 'nxtcc_crypto_exception', 'Decrypt failed.' );
			}

			if ( false === $pt ) {
				return new WP_Error( 'nxtcc_crypto_open', 'Decrypt failed.' );
			}

			return (string) $pt;
		}

		if ( ! self::crypto_can_use_sodium() ) {
			return new WP_Error( 'nxtcc_crypto_missing', 'Cannot decrypt.' );
		}

		$ct = self::crypto_b64_decode( $ct_b64 );
		if ( ! is_string( $ct ) ) {
			return new WP_Error( 'nxtcc_crypto_b64', 'Invalid ciphertext.' );
		}

		try {
			$pt = sodium_crypto_secretbox_open( $ct, $nonce_raw, $key );
		} catch ( Throwable $e ) {
			return new WP_Error( 'nxtcc_crypto_exception', 'Decrypt failed.' );
		}

		if ( false === $pt ) {
			return new WP_Error( 'nxtcc_crypto_open', 'Decrypt failed.' );
		}

		return (string) $pt;
	}

	/**
	 * Normalize a phone number to digits only.
	 *
	 * @param mixed $phone_number Input phone number.
	 * @return string Digits only.
	 */
	public static function sanitize_phone_number( $phone_number ): string {
		$out = preg_replace( '/[^0-9]/', '', (string) $phone_number );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * Generate a random token.
	 *
	 * @param int $length Requested length (hex length).
	 * @return string Token string.
	 */
	public static function generate_token( int $length = 32 ): string {
		$n = max( 2, $length );
		if ( 1 === ( $n % 2 ) ) {
			++$n;
		}

		try {
			return bin2hex( random_bytes( (int) ( $n / 2 ) ) );
		} catch ( Throwable $e ) {
			return wp_generate_password( $n, false, false );
		}
	}

	/**
	 * Debug helper to serialize responses without forcing an output sink.
	 *
	 * @param mixed $response Any value.
	 * @return void
	 */
	public static function log_api_response( $response ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$str = is_string( $response ) ? $response : wp_json_encode( $response );
		if ( is_string( $str ) ) {
			$str = substr( $str, 0, 4000 );
		}

		/*
		 * Intentionally no error_log() call here to avoid noisy logs in production.
		 * The return value is prepared so a caller may log it if desired.
		 */
	}

	/**
	 * Build a stable cache key from a namespace and key parts.
	 *
	 * @param string $ns    Namespace.
	 * @param array  $parts Parts to hash.
	 * @return string Cache key.
	 */
	public static function ckey( string $ns, array $parts ): string {
		$parts = array_map(
			static function ( $v ) {
				if ( is_scalar( $v ) ) {
					return (string) $v;
				}
				return wp_json_encode( $v );
			},
			$parts
		);

		return $ns . ':' . md5( implode( '|', $parts ) );
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or false.
	 */
	private static function cget( string $key ) {
		return wp_cache_get( $key, self::CACHE_GROUP );
	}

	/**
	 * Set a cached value.
	 *
	 * Uses a literal TTL so cache sniffs can evaluate it (>= 300 seconds).
	 *
	 * @param string $key Cache key.
	 * @param mixed  $val Cache value.
	 * @return void
	 */
	private static function cset( string $key, $val ): void {
		// PHPCS/VIP requires a literal TTL value.
		wp_cache_set( $key, $val, self::CACHE_GROUP, 300 );
	}

	/**
	 * Delete a cached value.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	private static function cdel( string $key ): void {
		wp_cache_delete( $key, self::CACHE_GROUP );
	}

	/**
	 * Fetch tenant-scoped API credentials via DAO filter and cache them briefly.
	 *
	 * @param string $user_mailid         User identifier/email.
	 * @param string $business_account_id WABA ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return array|false Credential array or false.
	 */
	public static function get_tenant_api_credentials( string $user_mailid, string $business_account_id, string $phone_number_id ) {
		$ckey = self::ckey( self::CKEY_TENANT, array( $user_mailid, $business_account_id, $phone_number_id ) );
		$hit  = self::cget( $ckey );
		if ( false !== $hit ) {
			return $hit;
		}

		$row = apply_filters( 'nxtcc_db_get_tenant_creds', null, $user_mailid, $business_account_id, $phone_number_id );
		if ( ! is_array( $row ) ) {
			self::cset( $ckey, false );
			return false;
		}

		$token = $row['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			$token = '';

			if ( isset( $row['access_token_ct'], $row['access_token_nonce'] ) ) {
				$dec = self::crypto_decrypt(
					is_string( $row['access_token_ct'] ) ? $row['access_token_ct'] : null,
					is_string( $row['access_token_nonce'] ) ? $row['access_token_nonce'] : null
				);

				if ( is_string( $dec ) && '' !== $dec ) {
					$token = $dec;
				}
			}

			if ( '' === $token ) {
				self::cset( $ckey, false );
				return false;
			}

			$row['access_token'] = $token;
		}

		$out = array(
			'app_id'              => (string) ( $row['app_id'] ?? '' ),
			'access_token'        => (string) $row['access_token'],
			'business_account_id' => (string) ( $row['business_account_id'] ?? $business_account_id ),
			'phone_number_id'     => (string) ( $row['phone_number_id'] ?? $phone_number_id ),
			'phone_number'        => (string) ( $row['phone_number'] ?? '' ),
		);

		self::cset( $ckey, $out );
		return $out;
	}

	/**
	 * Return cached templates list for a user/phone number.
	 *
	 * @param string $user_mailid     User identifier/email.
	 * @param string $phone_number_id Phone number ID.
	 * @return array Templates list.
	 */
	public static function get_cached_templates( string $user_mailid, string $phone_number_id ): array {
		$ckey = self::ckey( self::CKEY_TEMPLATES, array( $user_mailid, $phone_number_id ) );
		$hit  = self::cget( $ckey );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		$rows = apply_filters( 'nxtcc_db_get_templates', array(), $user_mailid, $phone_number_id );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		self::cset( $ckey, $rows );
		return $rows;
	}

	/**
	 * Get cached list of template names for diffing (private helper).
	 *
	 * @param string $user_mailid         User identifier/email.
	 * @param string $business_account_id WABA ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return array List of template names.
	 */
	private static function get_cached_template_names( string $user_mailid, string $business_account_id, string $phone_number_id ): array {
		$ckey = self::ckey( self::CKEY_TEMPLATE_SET, array( $user_mailid, $business_account_id, $phone_number_id ) );
		$hit  = self::cget( $ckey );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		$names = apply_filters( 'nxtcc_db_get_template_names', array(), $user_mailid, $business_account_id, $phone_number_id );
		if ( ! is_array( $names ) ) {
			$names = array();
		}

		self::cset( $ckey, $names );
		return $names;
	}

	/**
	 * Sync templates from Meta into local storage using DAO actions.
	 *
	 * @param string $user_mailid         User identifier/email.
	 * @param string $access_token        Decrypted access token.
	 * @param string $business_account_id WABA ID.
	 * @param string $phone_number_id     Phone number ID.
	 * @return bool True on success.
	 */
	public static function sync_templates_from_meta( string $user_mailid, string $access_token, string $business_account_id, string $phone_number_id ): bool {
		$url  = 'https://graph.facebook.com/v19.0/' . rawurlencode( $business_account_id ) . '/message_templates?limit=1000';
		$resp = nxtcc_safe_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( (string) $body, true );

		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return false;
		}

		foreach ( $data['data'] as $tpl ) {
			do_action(
				'nxtcc_db_upsert_template',
				array(
					'user_mailid'         => $user_mailid,
					'phone_number_id'     => $phone_number_id,
					'business_account_id' => $business_account_id,
					'template_name'       => isset( $tpl['name'] ) ? (string) $tpl['name'] : '',
					'category'            => isset( $tpl['category'] ) ? (string) $tpl['category'] : null,
					'language'            => isset( $tpl['language'] ) ? (string) $tpl['language'] : null,
					'status'              => isset( $tpl['status'] ) ? (string) $tpl['status'] : null,
					'components'          => isset( $tpl['components'] ) ? wp_json_encode( $tpl['components'] ) : null,
					'last_synced'         => current_time( 'mysql', 1 ),
					'created_at'          => current_time( 'mysql', 1 ),
					'updated_at'          => current_time( 'mysql', 1 ),
				)
			);
		}

		$meta_names = array();
		foreach ( $data['data'] as $tpl ) {
			if ( ! empty( $tpl['name'] ) ) {
				$meta_names[] = (string) $tpl['name'];
			}
		}

		$existing_names = self::get_cached_template_names( $user_mailid, $business_account_id, $phone_number_id );
		$to_delete      = array_values( array_diff( $existing_names, $meta_names ) );

		foreach ( $to_delete as $name ) {
			do_action(
				'nxtcc_db_delete_template',
				array(
					'user_mailid'         => $user_mailid,
					'business_account_id' => $business_account_id,
					'phone_number_id'     => $phone_number_id,
					'template_name'       => (string) $name,
				)
			);
		}

		self::cdel( self::ckey( self::CKEY_TEMPLATES, array( $user_mailid, $phone_number_id ) ) );
		self::cdel( self::ckey( self::CKEY_TEMPLATE_SET, array( $user_mailid, $business_account_id, $phone_number_id ) ) );

		return true;
	}

	/**
	 * Build WhatsApp template components payload from UI params and button definitions.
	 *
	 * @param array $params           User provided values.
	 * @param array $template_buttons Template button schema.
	 * @return array Components array.
	 */
	public static function build_template_components( array $params = array(), array $template_buttons = array() ): array {
		$components = array();

		if ( ! empty( $params['header_image'] ) ) {
			$components[] = array(
				'type'       => 'header',
				'parameters' => array(
					array(
						'type'  => 'image',
						'image' => array(
							'link' => esc_url_raw( (string) $params['header_image'] ),
						),
					),
				),
			);
		}

		$body_parameters = array();
		foreach ( $params as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'body_var_' ) ) {
				$body_parameters[] = array(
					'type' => 'text',
					'text' => sanitize_text_field( (string) $value ),
				);
			}
		}

		if ( ! empty( $body_parameters ) ) {
			$components[] = array(
				'type'       => 'body',
				'parameters' => $body_parameters,
			);
		}

		foreach ( $template_buttons as $i => $btn ) {
			$type = strtoupper( (string) ( $btn['type'] ?? '' ) );

			if ( 'URL' === $type ) {
				$url = (string) ( $btn['url'] ?? '' );

				if ( (bool) preg_match( '/{{\d+}}/', $url ) ) {
					$key_var    = 'button_url_var_' . ( $i + 1 );
					$key_static = 'button_url_' . ( $i + 1 );

					$value = '';
					if ( isset( $params[ $key_var ] ) ) {
						$value = (string) $params[ $key_var ];
					} elseif ( isset( $params[ $key_static ] ) ) {
						$value = (string) $params[ $key_static ];
					}

					$components[] = array(
						'type'       => 'button',
						'sub_type'   => 'url',
						'index'      => (int) $i,
						'parameters' => array(
							array(
								'type' => 'text',
								'text' => sanitize_text_field( $value ),
							),
						),
					);
				}
			} elseif ( 'COPY_CODE' === $type ) {
				$key = 'button_code_var_' . ( $i + 1 );

				$components[] = array(
					'type'       => 'button',
					'sub_type'   => 'copy_code',
					'index'      => (int) $i,
					'parameters' => array(
						array(
							'type'        => 'coupon_code',
							'coupon_code' => sanitize_text_field( (string) ( $params[ $key ] ?? '' ) ),
						),
					),
				);
			} elseif ( 'FLOW' === $type ) {
				$components[] = array(
					'type'       => 'button',
					'sub_type'   => 'flow',
					'index'      => (int) $i,
					'parameters' => array(),
				);
			}
		}

		return $components;
	}

	/**
	 * Extract template variable fields for UI rendering based on template components.
	 *
	 * @param mixed $components Components array.
	 * @return array UI field definitions.
	 */
	public static function extract_template_fields_for_ui( $components ): array {
		$fields = array();

		foreach ( (array) $components as $component ) {
			if ( ( $component['type'] ?? '' ) === 'body' && ! empty( $component['text'] ) ) {
				$matches = array();
				$found   = preg_match_all( '/\{\{(\d+)\}\}/', (string) $component['text'], $matches );

				if ( $found && ! empty( $matches[1] ) ) {
					foreach ( $matches[1] as $index ) {
						$fields[] = array(
							'type'      => 'text',
							'name'      => 'body_var_' . (string) $index,
							'label'     => 'Body Variable {{' . (string) $index . '}}',
							'value'     => '',
							'is_static' => false,
						);
					}
				}
			}

			if ( ( $component['type'] ?? '' ) === 'button' && ( $component['sub_type'] ?? '' ) === 'url' ) {
				$is_static = true;

				if ( ! empty( $component['parameters'] ) ) {
					foreach ( (array) $component['parameters'] as $param ) {
						if ( ( $param['type'] ?? '' ) === 'text' ) {
							$is_static = false;
							break;
						}
					}
				}

				$idx = (int) ( $component['index'] ?? 0 );

				$fields[] = array(
					'type'      => 'url',
					'name'      => 'button_url_' . (string) $idx,
					'label'     => 'Button URL ' . (string) ( $idx + 1 ),
					'value'     => $is_static ? (string) ( $component['url'] ?? '' ) : '',
					'is_static' => $is_static,
				);
			}
		}

		return $fields;
	}

	/**
	 * Get WP_Filesystem instance.
	 *
	 * @return \WP_Filesystem_Base|null Filesystem object or null when unavailable.
	 */
	private static function fs(): ?WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return null;
		}

		global $wp_filesystem;

		return ( $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
	}

	/**
	 * Load country code mapping from the plugin JSON file.
	 *
	 * @return array<int,array{iso2:string,dial:string,name:string}>
	 */
	public static function get_country_codes(): array {
		$hit = get_transient( self::T_COUNTRY_CODES );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$fs = self::fs();
		if ( ! $fs ) {
			return array();
		}

		$file = trailingslashit( NXTCC_PLUGIN_DIR ) . 'languages/nxtcc-country-codes.json';
		if ( ! $fs->exists( $file ) ) {
			return array();
		}

		$raw = $fs->get_contents( $file );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			return array();
		}

		$out = array();

		foreach ( $json as $row ) {
			$iso  = strtoupper( trim( (string) ( $row['iso2'] ?? '' ) ) );
			$dial = trim( (string) ( $row['dial_code'] ?? '' ) );
			$name = trim( (string) ( $row['country_name'] ?? '' ) );

			if ( '' === $iso || '' === $dial ) {
				continue;
			}

			$dial = ltrim( $dial, '+' );
			if ( ! preg_match( '/^\d+$/', $dial ) ) {
				continue;
			}

			$out[] = array(
				'iso2' => $iso,
				'dial' => $dial,
				'name' => ( '' !== $name ? $name : $iso ),
			);
		}

		set_transient( self::T_COUNTRY_CODES, $out, (int) ( HOUR_IN_SECONDS / 2 ) );
		return $out;
	}

	/**
	 * Map an E.164 phone number to ISO2 based on known dial codes.
	 *
	 * @param string $e164 Phone number in E.164 format.
	 * @return string|null ISO2 or null.
	 */
	public static function iso_from_e164( string $e164 ): ?string {
		$digits = preg_replace( '/\D+/', '', (string) $e164 );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return null;
		}

		$rows = self::get_country_codes();
		if ( empty( $rows ) ) {
			return null;
		}

		$map = array();
		foreach ( $rows as $r ) {
			$map[ (string) $r['dial'] ] = (string) $r['iso2'];
		}

		uksort(
			$map,
			static function ( $a, $b ) {
				$la = strlen( (string) $a );
				$lb = strlen( (string) $b );
				if ( $la === $lb ) {
					return strcmp( (string) $a, (string) $b );
				}
				return ( $lb <=> $la );
			}
		);

		foreach ( $map as $dial => $iso ) {
			$dial_len = strlen( (string) $dial );
			if ( 0 === strncmp( (string) $digits, (string) $dial, $dial_len ) ) {
				return (string) $iso;
			}
		}

		return null;
	}

	/**
	 * Extract ISO2 country code from a locale string.
	 *
	 * @param string|null $locale Locale string.
	 * @return string|null ISO2 or null.
	 */
	public static function iso_from_locale( ?string $locale ): ?string {
		$locale = (string) $locale;
		$m      = array();

		if ( preg_match( '/[_\-]([A-Za-z]{2})$/', $locale, $m ) ) {
			return strtoupper( (string) $m[1] );
		}

		return null;
	}

	/**
	 * Extract ISO2 country code from an Accept-Language header.
	 *
	 * @param string|null $hdr Accept-Language header value.
	 * @return string|null ISO2 or null.
	 */
	public static function iso_from_accept_language( ?string $hdr ): ?string {
		if ( ! is_string( $hdr ) || '' === $hdr ) {
			return null;
		}

		foreach ( explode( ',', $hdr ) as $p ) {
			$tag = preg_replace( '/;q=\d(\.\d+)?$/i', '', trim( (string) $p ) );
			if ( '' === (string) $tag ) {
				continue;
			}

			$m = array();
			if ( preg_match( '/^[a-z]{2,3}[_-]([a-z]{2})$/i', (string) $tag, $m ) ) {
				return strtoupper( (string) $m[1] );
			}
		}

		return null;
	}

	/**
	 * Read a single server value safely.
	 *
	 * @param string $key Server key name.
	 * @return string Sanitized value or empty string when missing.
	 */
	private static function read_server_value( string $key ): string {
		$raw = filter_input(
			INPUT_SERVER,
			$key,
			FILTER_SANITIZE_FULL_SPECIAL_CHARS
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		return sanitize_text_field( $raw );
	}

	/**
	 * Detect visitor country using common proxy/CDN headers, WooCommerce geolocation,
	 * Accept-Language, and the site locale as fallbacks.
	 *
	 * @return string ISO2 country code.
	 */
	public static function detect_visitor_country(): string {
		$candidates = array();

		$keys = array(
			'HTTP_CF_IPCOUNTRY',
			'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
			'HTTP_X_APPENGINE_COUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'HTTP_GEOIP_COUNTRY_CODE',
			'GEOIP_COUNTRY_CODE',
		);

		foreach ( $keys as $k ) {
			$val = self::read_server_value( $k );
			if ( '' !== $val ) {
				$candidates[] = strtoupper( trim( $val ) );
			}
		}

		if ( empty( $candidates ) && class_exists( 'WC_Geolocation' ) ) {
			try {
				$geo = \WC_Geolocation::geolocate_ip();
				if ( is_array( $geo ) && ! empty( $geo['country'] ) ) {
					$candidates[] = strtoupper( trim( (string) $geo['country'] ) );
				}
			} catch ( Throwable $e ) {
				$geo = array();
			}
		}

		if ( empty( $candidates ) ) {
			$al  = self::read_server_value( 'HTTP_ACCEPT_LANGUAGE' );
			$iso = self::iso_from_accept_language( $al );
			if ( is_string( $iso ) && '' !== $iso ) {
				$candidates[] = $iso;
			}
		}

		if ( empty( $candidates ) ) {
			$iso = self::iso_from_locale( get_locale() );
			if ( is_string( $iso ) && '' !== $iso ) {
				$candidates[] = $iso;
			}
		}

		if ( empty( $candidates ) ) {
			$candidates[] = 'IN';
		}

		foreach ( $candidates as $iso ) {
			if ( preg_match( '/^[A-Z]{2}$/', (string) $iso ) ) {
				return (string) apply_filters( 'nxtcc_detect_visitor_country', (string) $iso, $candidates );
			}
		}

		return 'IN';
	}
}
