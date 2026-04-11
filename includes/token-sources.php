<?php
/**
 * Token sources for message templating.
 *
 * Builds the per-recipient token context used by nxtcc_token_render().
 *
 * Built-in namespaces:
 * - contact.* → name, country_code, phone_number, created_by, created_at, updated_at, phone_e164, custom.*
 * - wp.*      → site_name, site_url, admin_email
 * - wc.*      → shop_name, shop_url, currency (when WooCommerce is active)
 *
 * Extensibility:
 * - Filter `nxtcc_token_providers` to add or override providers.
 * - Register providers at runtime with nxtcc_token_register_provider().
 *
 * Provider signature:
 * callable (int $contact_id, string $user_mailid): array
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the contacts table on $wpdb for consistent access patterns.
 *
 * @return void
 */
function nxtcc_token_sources_register_tables(): void {
	global $wpdb;

	if ( empty( $wpdb->nxtcc_contacts ) ) {
		$wpdb->nxtcc_contacts = $wpdb->prefix . 'nxtcc_contacts';
	}
}
add_action( 'plugins_loaded', 'nxtcc_token_sources_register_tables', 0 );

/**
 * Get provider map of namespace => callable.
 *
 * Providers are cached per-generation so runtime registrations rebuild cleanly.
 *
 * @return array<string, callable>
 */
function nxtcc_token_get_providers(): array {
	static $cached     = null;
	static $cached_gen = 0;

	$global_gen = 0;
	if ( isset( $GLOBALS['nxtcc_token_providers_gen'] ) ) {
		$global_gen = (int) $GLOBALS['nxtcc_token_providers_gen'];
	}

	if ( null !== $cached && $cached_gen === $global_gen ) {
		return $cached;
	}

	$defaults = array(
		'contact' => 'nxtcc_tokens_ns_contact',
		'wp'      => 'nxtcc_tokens_ns_wp',
		'wc'      => 'nxtcc_tokens_ns_wc',
	);

	$runtime = array();
	if ( ! empty( $GLOBALS['nxtcc_token_providers_runtime'] ) && is_array( $GLOBALS['nxtcc_token_providers_runtime'] ) ) {
		$runtime = $GLOBALS['nxtcc_token_providers_runtime'];
	}

	$providers = apply_filters( 'nxtcc_token_providers', array_merge( $defaults, $runtime ) );

	$out = array();
	foreach ( (array) $providers as $ns => $cb ) {
		$key = strtolower( trim( (string) $ns ) );
		if ( '' === $key || ! is_callable( $cb ) ) {
			continue;
		}
		$out[ $key ] = $cb;
	}

	$cached     = $out;
	$cached_gen = $global_gen;

	return $cached;
}

/**
 * Build the full token context for a given contact.
 *
 * @param int    $contact_id  Contact ID.
 * @param string $user_mailid Tenant hint; some providers may use this.
 * @return array<string, array>
 */
function nxtcc_token_build_context_for_contact( int $contact_id, string $user_mailid = '' ): array {
	$ctx       = array();
	$providers = nxtcc_token_get_providers();

	$contact_id  = absint( $contact_id );
	$user_mailid = (string) $user_mailid;

	foreach ( $providers as $ns => $cb ) {
		try {
			$data = $cb( $contact_id, $user_mailid );
			if ( is_array( $data ) && ! empty( $data ) ) {
				$ctx[ $ns ] = $data;
			}
		} catch ( \Throwable $e ) {
			/*
			 * Providers are optional and may depend on external plugins.
			 * We intentionally swallow exceptions so token rendering never breaks
			 * message sending flows. This matches the previous behavior.
			 */
			unset( $e );
		}
	}

	return $ctx;
}

/**
 * Contact.* provider.
 *
 * Pulls contact row from nxtcc_contacts and expands JSON custom_fields to contact.custom.*.
 *
 * @param int    $contact_id  Contact ID.
 * @param string $user_mailid Tenant hint (unused by default provider).
 * @return array<string, mixed>
 */
function nxtcc_tokens_ns_contact( int $contact_id, string $user_mailid = '' ): array {
	$user_mailid = (string) $user_mailid;

	$contact_id = absint( $contact_id );
	if ( 0 >= $contact_id ) {
		return array();
	}

	static $request_cache = array();
	if ( isset( $request_cache[ $contact_id ] ) ) {
		return $request_cache[ $contact_id ];
	}

	$row = NXTCC_Contacts_Repo::instance()->get_contact_row_by_id( $contact_id );
	if ( ! $row ) {
		$request_cache[ $contact_id ] = array();
		return $request_cache[ $contact_id ];
	}

	$country_code = preg_replace( '/\D+/', '', (string) $row->country_code );
	$phone_number = preg_replace( '/\D+/', '', (string) $row->phone_number );

	$out = array(
		'id'           => (int) $row->id,
		'name'         => (string) $row->name,
		'country_code' => $country_code,
		'phone_number' => $phone_number,
		'created_by'   => (string) $row->user_mailid,
		'created_at'   => (string) $row->created_at,
		'updated_at'   => (string) $row->updated_at,
		'phone_e164'   => (string) $country_code . (string) $phone_number,
	);

	if ( ! empty( $row->custom_fields ) ) {
		$arr = json_decode( (string) $row->custom_fields, true );
		if ( is_array( $arr ) ) {
			$custom = array();

			foreach ( $arr as $f ) {
				if ( empty( $f['label'] ) ) {
					continue;
				}

				$label = (string) $f['label'];
				$slug  = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '_', $label ), '_' ) );

				$value = '';
				if ( isset( $f['value'] ) && is_scalar( $f['value'] ) ) {
					$value = (string) $f['value'];
				}

				$custom[ $slug ] = $value;
			}

			if ( ! empty( $custom ) ) {
				$out['custom'] = $custom;
			}
		}
	}

	$request_cache[ $contact_id ] = $out;
	return $out;
}

/**
 * Wp.* provider.
 *
 * @param int    $contact_id  Contact ID (unused).
 * @param string $user_mailid Tenant hint (unused).
 * @return array<string, string>
 */
function nxtcc_tokens_ns_wp( int $contact_id, string $user_mailid = '' ): array {
	$contact_id  = (int) $contact_id;
	$user_mailid = (string) $user_mailid;

	return array(
		'site_name'   => (string) get_bloginfo( 'name' ),
		'site_url'    => (string) home_url( '/' ),
		'admin_email' => (string) get_option( 'admin_email' ),
	);
}

/**
 * Wc.* provider.
 *
 * Returns an empty array when WooCommerce is not active.
 *
 * @param int    $contact_id  Contact ID (unused).
 * @param string $user_mailid Tenant hint (unused).
 * @return array<string, string>
 */
function nxtcc_tokens_ns_wc( int $contact_id, string $user_mailid = '' ): array {
	$contact_id  = (int) $contact_id;
	$user_mailid = (string) $user_mailid;

	if ( ! class_exists( 'WooCommerce' ) ) {
		return array();
	}

	$shop_url = (string) home_url( '/' );
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$shop_url = (string) wc_get_page_permalink( 'shop' );
	}

	$currency = '';
	if ( function_exists( 'get_woocommerce_currency' ) ) {
		$currency = (string) get_woocommerce_currency();
	}

	$base = array(
		'shop_name' => (string) get_option( 'blogname' ),
		'shop_url'  => $shop_url,
		'currency'  => $currency,
	);

	return array_filter(
		$base,
		static function ( $v ): bool {
			return ( null !== $v && '' !== $v );
		}
	);
}

/**
 * Register or override a namespace provider at runtime.
 *
 * @param string   $token_namespace Namespace key (lowercase, without braces).
 * @param callable $provider_cb     Provider callable: function (int, string): array.
 * @return bool True when registered.
 */
function nxtcc_token_register_provider( string $token_namespace, $provider_cb ): bool {
	$token_namespace = strtolower( trim( $token_namespace ) );
	if ( '' === $token_namespace || ! is_callable( $provider_cb ) ) {
		return false;
	}

	if ( empty( $GLOBALS['nxtcc_token_providers_runtime'] ) || ! is_array( $GLOBALS['nxtcc_token_providers_runtime'] ) ) {
		$GLOBALS['nxtcc_token_providers_runtime'] = array();
	}

	$GLOBALS['nxtcc_token_providers_runtime'][ $token_namespace ] = $provider_cb;

	if ( ! isset( $GLOBALS['nxtcc_token_providers_gen'] ) ) {
		$GLOBALS['nxtcc_token_providers_gen'] = 1;
	} else {
		$GLOBALS['nxtcc_token_providers_gen'] = (int) $GLOBALS['nxtcc_token_providers_gen'] + 1;
	}

	return true;
}
