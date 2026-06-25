<?php
/**
 * WooCommerce Cash on Delivery verification integration.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine whether verified login is required for COD orders.
 *
 * @return bool True when the policy is enabled.
 */
function nxtcc_cod_verification_is_enabled(): bool {
	$policy = function_exists( 'nxtcc_fm_get_options' ) ? nxtcc_fm_get_options() : get_option( 'nxtcc_auth_policy', array() );

	return is_array( $policy ) && ! empty( $policy['require_verified_cod'] );
}

/**
 * Determine whether the current customer has a verified login.
 *
 * @return bool True when the logged-in user has a verified binding.
 */
function nxtcc_cod_customer_is_verified(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user_id = get_current_user_id();
	if ( 0 >= $user_id ) {
		return false;
	}

	if ( function_exists( 'nxtcc_fm_user_is_migrated' ) ) {
		return nxtcc_fm_user_is_migrated( $user_id );
	}

	return function_exists( 'nxtcc_is_user_whatsapp_verified' )
		&& nxtcc_is_user_whatsapp_verified( $user_id );
}

/**
 * Get the checkout URL used after successful verification.
 *
 * @return string Checkout URL.
 */
function nxtcc_cod_get_checkout_return_url(): string {
	$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );

	return add_query_arg( 'nxtcc_cod_return', '1', $checkout_url );
}

/**
 * Get the Force Migration login URL for a COD checkout.
 *
 * @return string Login URL.
 */
function nxtcc_cod_get_login_url(): string {
	$policy     = function_exists( 'nxtcc_fm_get_options' ) ? nxtcc_fm_get_options() : array();
	$force_path = is_array( $policy ) && ! empty( $policy['force_path'] )
		? (string) $policy['force_path']
		: '/nxt-whatsapp-login/';

	$login_url = home_url( nxtcc_fm_normalize_force_path( $force_path ) );

	return add_query_arg(
		array(
			'nxtcc_reason'    => 'cod',
			'nxtcc_return_to' => nxtcc_cod_get_checkout_return_url(),
		),
		$login_url
	);
}

/**
 * Validate a requested post-verification return URL.
 *
 * Only same-origin HTTP(S) URLs are allowed.
 *
 * @param string $candidate Candidate return URL.
 * @param string $fallback  Fallback URL.
 * @return string Safe return URL.
 */
function nxtcc_cod_validate_return_url( string $candidate, string $fallback ): string {
	$candidate = wp_validate_redirect( $candidate, '' );
	if ( '' === $candidate ) {
		return $fallback;
	}

	$home_parts      = wp_parse_url( home_url( '/' ) );
	$candidate_parts = wp_parse_url( $candidate );

	if ( ! is_array( $home_parts ) || ! is_array( $candidate_parts ) ) {
		return $fallback;
	}

	$home_scheme      = isset( $home_parts['scheme'] ) ? strtolower( (string) $home_parts['scheme'] ) : '';
	$candidate_scheme = isset( $candidate_parts['scheme'] ) ? strtolower( (string) $candidate_parts['scheme'] ) : '';
	$home_host        = isset( $home_parts['host'] ) ? strtolower( (string) $home_parts['host'] ) : '';
	$candidate_host   = isset( $candidate_parts['host'] ) ? strtolower( (string) $candidate_parts['host'] ) : '';
	$home_port        = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : 0;
	$candidate_port   = isset( $candidate_parts['port'] ) ? (int) $candidate_parts['port'] : 0;

	if (
		'' === $candidate_host
		|| $home_scheme !== $candidate_scheme
		|| $home_host !== $candidate_host
		|| $home_port !== $candidate_port
	) {
		return $fallback;
	}

	return $candidate;
}

/**
 * Return COD verification users to checkout after OTP succeeds.
 *
 * @param string $redirect_url Existing verified-user redirect URL.
 * @return string Filtered redirect URL.
 */
function nxtcc_cod_filter_verified_redirect( string $redirect_url ): string {
	$return_to = filter_input( INPUT_GET, 'nxtcc_return_to', FILTER_SANITIZE_URL );
	if ( ! is_string( $return_to ) || '' === $return_to ) {
		return $redirect_url;
	}

	return nxtcc_cod_validate_return_url(
		esc_url_raw( wp_unslash( $return_to ) ),
		$redirect_url
	);
}
add_filter( 'nxtcc_fm_verified_redirect', 'nxtcc_cod_filter_verified_redirect' );

/**
 * Get the customer-facing verification-required message.
 *
 * @param bool $include_link Whether to include a verification link.
 * @return string Message text or permitted HTML.
 */
function nxtcc_cod_get_required_message( bool $include_link = false ): string {
	if ( ! $include_link ) {
		return __( 'Cash on Delivery requires a verified login. Complete verification, then return to checkout.', 'nxt-cloud-chat' );
	}

	return sprintf(
		/* translators: %s: link to the verification page. */
		__( 'Cash on Delivery requires a verified login. %s', 'nxt-cloud-chat' ),
		'<a href="' . esc_url( nxtcc_cod_get_login_url() ) . '">' . esc_html__( 'Verify and continue', 'nxt-cloud-chat' ) . '</a>'
	);
}

/**
 * Validate COD verification during classic WooCommerce checkout.
 *
 * @param array<string, mixed> $data   Posted checkout data.
 * @param WP_Error             $errors Checkout validation errors.
 * @return void
 */
function nxtcc_cod_validate_classic_checkout( array $data, WP_Error $errors ): void {
	if ( ! nxtcc_cod_verification_is_enabled() || nxtcc_cod_customer_is_verified() ) {
		return;
	}

	$payment_method = isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';
	if ( 'cod' !== $payment_method ) {
		return;
	}

	$errors->add(
		'nxtcc_cod_verification_required',
		wp_kses_post( nxtcc_cod_get_required_message( true ) )
	);
}
add_action( 'woocommerce_after_checkout_validation', 'nxtcc_cod_validate_classic_checkout', 10, 2 );

/**
 * Block an unverified COD request before the Store API checkout callback runs.
 *
 * @param WP_HTTP_Response|WP_Error|null $response Existing pre-callback response.
 * @param array<string, mixed>           $handler  Matched REST route handler.
 * @param WP_REST_Request                $request  REST request.
 * @return WP_HTTP_Response|WP_Error|null
 */
function nxtcc_cod_validate_store_api_checkout( $response, array $handler, WP_REST_Request $request ) {
	unset( $handler );

	if ( null !== $response || 'POST' !== strtoupper( $request->get_method() ) ) {
		return $response;
	}

	if ( 1 !== preg_match( '#^/wc/store/v[0-9]+/checkout/?$#', $request->get_route() ) ) {
		return $response;
	}

	if ( ! nxtcc_cod_verification_is_enabled() || nxtcc_cod_customer_is_verified() ) {
		return $response;
	}

	$payment_method = sanitize_key( (string) $request->get_param( 'payment_method' ) );
	if ( 'cod' !== $payment_method ) {
		return $response;
	}

	return new WP_Error(
		'nxtcc_cod_verification_required',
		nxtcc_cod_get_required_message(),
		array(
			'status'       => 403,
			'redirect_url' => nxtcc_cod_get_login_url(),
		)
	);
}
add_filter( 'rest_request_before_callbacks', 'nxtcc_cod_validate_store_api_checkout', 10, 3 );

/**
 * Enqueue the COD verification redirect on checkout pages.
 *
 * @return void
 */
function nxtcc_cod_enqueue_checkout_assets(): void {
	if (
		! class_exists( 'WooCommerce' )
		|| ! function_exists( 'is_checkout' )
		|| ! is_checkout()
		|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
		|| ! nxtcc_cod_verification_is_enabled()
		|| nxtcc_cod_customer_is_verified()
	) {
		return;
	}

	wp_enqueue_script(
		'nxtcc-cod-verification',
		NXTCC_PLUGIN_URL . 'frontend/js/cod-verification.js',
		array( 'jquery' ),
		NXTCC_VERSION,
		true
	);

	wp_localize_script(
		'nxtcc-cod-verification',
		'NXTCC_COD_VERIFICATION',
		array(
			'loginUrl' => esc_url_raw( nxtcc_cod_get_login_url() ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'nxtcc_cod_enqueue_checkout_assets', 30 );
