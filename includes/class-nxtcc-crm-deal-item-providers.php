<?php
/**
 * Extensible deal line-item source providers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-owned provider registry shared by admin, Pro, and external plugins.
 */
final class NXTCC_CRM_Deal_Item_Providers {

	/**
	 * Return normalized provider definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_providers(): array {
		$providers = array(
			'manual' => array(
				'label'          => __( 'Manual', 'nxt-cloud-chat' ),
				'available'      => true,
				'searchable'     => false,
				'quantity_types' => self::default_quantity_types(),
			),
		);

		if ( function_exists( 'wc_get_products' ) ) {
			$providers['woocommerce'] = array(
				'label'            => __( 'WooCommerce', 'nxt-cloud-chat' ),
				'available'        => true,
				'searchable'       => true,
				'search_callback'  => array( __CLASS__, 'search_woocommerce' ),
				'resolve_callback' => array( __CLASS__, 'resolve_woocommerce' ),
				'quantity_types'   => self::default_quantity_types(),
			);
		}

		if ( post_type_exists( 'property' ) ) {
			$providers['houzez'] = array(
				'label'            => __( 'Houzez', 'nxt-cloud-chat' ),
				'available'        => true,
				'searchable'       => true,
				'search_callback'  => array( __CLASS__, 'search_houzez' ),
				'resolve_callback' => array( __CLASS__, 'resolve_houzez' ),
				'quantity_types'   => array_merge(
					self::default_quantity_types(),
					array( 'property' => __( 'Property', 'nxt-cloud-chat' ) )
				),
			);
		}

		$providers = apply_filters( 'nxtcc_crm_deal_item_providers', $providers );
		if ( ! is_array( $providers ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $providers, 0, 30, true ) as $provider_id => $provider ) {
			$provider_id = sanitize_key( (string) $provider_id );
			if ( '' === $provider_id || ! is_array( $provider ) ) {
				continue;
			}
			$normalized[ $provider_id ] = array(
				'id'               => $provider_id,
				'label'            => sanitize_text_field( (string) ( $provider['label'] ?? $provider_id ) ),
				'available'        => ! empty( $provider['available'] ),
				'searchable'       => ! empty( $provider['searchable'] ) && is_callable( $provider['search_callback'] ?? null ),
				'search_callback'  => is_callable( $provider['search_callback'] ?? null ) ? $provider['search_callback'] : null,
				'resolve_callback' => is_callable( $provider['resolve_callback'] ?? null ) ? $provider['resolve_callback'] : null,
				'quantity_types'   => self::normalize_quantity_types( $provider['quantity_types'] ?? self::default_quantity_types() ),
			);
		}

		return $normalized;
	}

	/**
	 * Search one provider using a bounded result size.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $search Search text.
	 * @param array  $tenant Tenant tuple.
	 * @param int    $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( string $provider_id, string $search, array $tenant, int $limit = 20 ): array {
		if ( empty( $tenant['user_mailid'] ) || empty( $tenant['business_account_id'] ) || empty( $tenant['phone_number_id'] ) ) {
			return array();
		}
		$providers = self::get_providers();
		$provider  = $providers[ sanitize_key( $provider_id ) ] ?? array();
		if ( empty( $provider['available'] ) || empty( $provider['searchable'] ) || ! is_callable( $provider['search_callback'] ?? null ) ) {
			return array();
		}

		$rows = call_user_func( $provider['search_callback'], substr( sanitize_text_field( $search ), 0, 120 ), $tenant, min( 20, max( 1, $limit ) ) );
		return self::normalize_items( is_array( $rows ) ? $rows : array(), $provider_id, $provider );
	}

	/**
	 * Resolve one provider item before it is stored.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $item_id Provider item ID.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( string $provider_id, string $item_id, array $tenant ): ?array {
		if ( empty( $tenant['user_mailid'] ) || empty( $tenant['business_account_id'] ) || empty( $tenant['phone_number_id'] ) ) {
			return null;
		}
		$providers = self::get_providers();
		$provider  = $providers[ sanitize_key( $provider_id ) ] ?? array();
		if ( empty( $provider['available'] ) || ! is_callable( $provider['resolve_callback'] ?? null ) ) {
			return null;
		}

		$row  = call_user_func( $provider['resolve_callback'], substr( sanitize_text_field( $item_id ), 0, 191 ), $tenant );
		$rows = self::normalize_items( is_array( $row ) ? array( $row ) : array(), $provider_id, $provider );
		return $rows[0] ?? null;
	}

	/**
	 * Search WooCommerce products.
	 *
	 * @param string $search Search text.
	 * @param array  $tenant Tenant tuple.
	 * @param int    $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search_woocommerce( string $search, array $tenant, int $limit ): array {
		unset( $tenant );
		$products = wc_get_products(
			array(
				'limit'   => $limit,
				'status'  => 'publish',
				'search'  => $search,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$rows     = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}
			$rows[] = self::woocommerce_item( $product );
		}
		return $rows;
	}

	/**
	 * Resolve a WooCommerce product.
	 *
	 * @param string $item_id Product ID.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_woocommerce( string $item_id, array $tenant ): ?array {
		unset( $tenant );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $item_id ) ) : false;
		return is_object( $product ) && method_exists( $product, 'get_status' ) && 'publish' === $product->get_status()
			? self::woocommerce_item( $product )
			: null;
	}

	/**
	 * Search Houzez properties.
	 *
	 * @param string $search Search text.
	 * @param array  $tenant Tenant tuple.
	 * @param int    $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search_houzez( string $search, array $tenant, int $limit ): array {
		unset( $tenant );
		$ids  = get_posts(
			array(
				'post_type'              => 'property',
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				's'                      => $search,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = self::houzez_item( absint( $id ) );
		}
		return $rows;
	}

	/**
	 * Resolve a Houzez property.
	 *
	 * @param string $item_id Property ID.
	 * @param array  $tenant Tenant tuple.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_houzez( string $item_id, array $tenant ): ?array {
		unset( $tenant );
		$post = get_post( absint( $item_id ) );
		return $post instanceof WP_Post && 'property' === $post->post_type && 'publish' === $post->post_status
			? self::houzez_item( $post->ID )
			: null;
	}

	/**
	 * Default units of measure.
	 *
	 * @return array<string,string>
	 */
	private static function default_quantity_types(): array {
		return array(
			'unit'   => __( 'Unit', 'nxt-cloud-chat' ),
			'packet' => __( 'Packet', 'nxt-cloud-chat' ),
			'box'    => __( 'Box', 'nxt-cloud-chat' ),
			'hour'   => __( 'Hour', 'nxt-cloud-chat' ),
			'day'    => __( 'Day', 'nxt-cloud-chat' ),
			'month'  => __( 'Month', 'nxt-cloud-chat' ),
			'custom' => __( 'Custom', 'nxt-cloud-chat' ),
		);
	}

	/**
	 * Normalize provider quantity types.
	 *
	 * @param mixed $types Raw quantity types.
	 * @return array<string,string>
	 */
	private static function normalize_quantity_types( $types ): array {
		$output = array();
		foreach ( is_array( $types ) ? array_slice( $types, 0, 30, true ) : array() as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				$output[ $key ] = substr( sanitize_text_field( (string) $label ), 0, 100 );
			}
		}
		return ! empty( $output ) ? $output : self::default_quantity_types();
	}

	/**
	 * Normalize provider item rows.
	 *
	 * @param array  $rows Raw rows.
	 * @param string $provider_id Provider ID.
	 * @param array  $provider Provider definition.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_items( array $rows, string $provider_id, array $provider ): array {
		$output = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = substr( sanitize_text_field( (string) ( $row['id'] ?? '' ) ), 0, 191 );
			$label = substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 191 );
			if ( '' === $id || '' === $label ) {
				continue;
			}
			$quantity_type = sanitize_key( (string) ( $row['quantity_type'] ?? 'unit' ) );
			if ( ! isset( $provider['quantity_types'][ $quantity_type ] ) ) {
				$quantity_type = 'unit';
			}
			$output[] = array(
				'id'             => $id,
				'provider'       => sanitize_key( $provider_id ),
				'label'          => $label,
				'secondary_text' => substr( sanitize_text_field( (string) ( $row['secondary_text'] ?? '' ) ), 0, 191 ),
				'unit_value'     => max( 0, (float) ( $row['unit_value'] ?? 0 ) ),
				'currency'       => self::normalize_currency( (string) ( $row['currency'] ?? '' ) ),
				'quantity_type'  => $quantity_type,
				'metadata'       => self::normalize_metadata( $row['metadata'] ?? array() ),
			);
		}
		return $output;
	}

	/**
	 * Build one WooCommerce item.
	 *
	 * @param object $product Product.
	 * @return array<string,mixed>
	 */
	private static function woocommerce_item( $product ): array {
		return array(
			'id'             => (string) $product->get_id(),
			'label'          => $product->get_name(),
			'secondary_text' => $product->get_sku() ? 'SKU: ' . $product->get_sku() : '',
			'unit_value'     => (float) $product->get_price(),
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'quantity_type'  => 'unit',
			'metadata'       => array( 'sku' => $product->get_sku() ),
		);
	}

	/**
	 * Build one Houzez item.
	 *
	 * @param int $post_id Property ID.
	 * @return array<string,mixed>
	 */
	private static function houzez_item( int $post_id ): array {
		return array(
			'id'             => (string) $post_id,
			'label'          => get_the_title( $post_id ),
			'secondary_text' => sanitize_text_field( (string) get_post_meta( $post_id, 'fave_property_id', true ) ),
			'unit_value'     => (float) get_post_meta( $post_id, 'fave_property_price', true ),
			'currency'       => '',
			'quantity_type'  => 'property',
			'metadata'       => array( 'permalink' => get_permalink( $post_id ) ),
		);
	}

	/**
	 * Normalize ISO-style currency.
	 *
	 * @param string $currency Raw currency.
	 * @return string
	 */
	private static function normalize_currency( string $currency ): string {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) );
		return 3 === strlen( $currency ) ? $currency : '';
	}

	/**
	 * Normalize bounded provider metadata.
	 *
	 * @param mixed $metadata Raw metadata.
	 * @return array<string,string>
	 */
	private static function normalize_metadata( $metadata ): array {
		$output = array();
		foreach ( is_array( $metadata ) ? array_slice( $metadata, 0, 20, true ) : array() as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && is_scalar( $value ) ) {
				$output[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
			}
		}
		return $output;
	}
}
