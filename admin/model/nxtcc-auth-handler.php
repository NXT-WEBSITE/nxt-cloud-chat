<?php
/**
 * NXT Cloud Chat - Authentication (Admin AJAX + Public REST)
 *
 * This module powers WhatsApp OTP login/verification.
 *
 * Admin (AJAX, manage_options):
 * - Lists “owners” (settings profiles) that have complete WhatsApp credentials.
 * - Lists APPROVED AUTHENTICATION templates for an owner/business.
 * - Creates a default AUTHENTICATION template with COPY_CODE button (once).
 * - Persists OTP widget settings + policy (allowed countries, redirect path, etc).
 *
 * Public (REST, routes registered elsewhere):
 * - Request/resend OTP for a session + phone number.
 * - Verify OTP, bind/create WordPress user, log in, and return redirect.
 *
 * Security notes:
 * - Admin AJAX actions validate nonce and capability.
 * - REST endpoints validate inputs and implement resend throttling.
 * - Access tokens are stored encrypted (access_token_ct + access_token_nonce)
 *   and decrypted only when sending API calls.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXTCC_Auth_DAO' ) ) {
	require_once __DIR__ . '/class-nxtcc-auth-dao.php';
}

if ( ! defined( 'NXTCC_AUTH_HTTP_TIMEOUT' ) ) {
	/**
	 * Default outbound HTTP timeout (seconds).
	 *
	 * A small bounded timeout prevents long-hanging requests in shared hosting.
	 */
	define( 'NXTCC_AUTH_HTTP_TIMEOUT', 20 );
}

/**
 * Remote GET wrapper.
 *
 * Uses VIP transport when available, otherwise falls back to WordPress safe HTTP.
 *
 * @param string $url  Request URL.
 * @param array  $args Request args for the HTTP call.
 * @return array|WP_Error
 */
function nxtcc_auth_remote_get( string $url, array $args = array() ) {
	$args['timeout'] = isset( $args['timeout'] ) ? (int) $args['timeout'] : (int) NXTCC_AUTH_HTTP_TIMEOUT;
	$args['timeout'] = max( 3, min( 30, $args['timeout'] ) );

	if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
		return vip_safe_wp_remote_get( $url, $args );
	}

	return wp_safe_remote_get( $url, $args );
}


/**
 * Remote POST wrapper.
 *
 * Uses VIP transport when available, otherwise WordPress core HTTP API.
 * Timeout is clamped to a safe window.
 *
 * @param string $url  Request URL.
 * @param array  $args Request args for wp_remote_post().
 * @return array|WP_Error
 */
function nxtcc_auth_remote_post( string $url, array $args = array() ) {
	$args['timeout'] = isset( $args['timeout'] ) ? (int) $args['timeout'] : (int) NXTCC_AUTH_HTTP_TIMEOUT;
	$args['timeout'] = max( 3, min( 30, $args['timeout'] ) );

	if ( function_exists( 'vip_safe_wp_remote_post' ) ) {
		return vip_safe_wp_remote_post( $url, $args );
	}

	return wp_safe_remote_post( $url, $args );
}

/**
 * Read the saved default owner email for auth (stored as default_tenant_key).
 *
 * @return string Owner email or empty string.
 */
function nxtcc_auth_get_default_owner_mail(): string {
	$opts = get_option( 'nxtcc_auth_options', array() );

	if ( ! is_array( $opts ) || empty( $opts['default_tenant_key'] ) ) {
		return '';
	}

	return sanitize_email( (string) $opts['default_tenant_key'] );
}

/**
 * Pick a settings row in priority order:
 * 1) Explicit owner email (if provided).
 * 2) Saved default owner email.
 * 3) Current WP user's email.
 *
 * @param string|null $owner_mail Owner email or null.
 * @return array|null Settings row or null if not found.
 */
function nxtcc_auth_pick_settings_row( ?string $owner_mail = null ): ?array {
	$mail = '';

	if ( null !== $owner_mail && '' !== $owner_mail ) {
		$mail = sanitize_email( $owner_mail );
	} else {
		$saved = nxtcc_auth_get_default_owner_mail();
		if ( '' !== $saved ) {
			$mail = sanitize_email( $saved );
		} else {
			$user = wp_get_current_user();
			$mail = ( $user && ! empty( $user->user_email ) ) ? sanitize_email( (string) $user->user_email ) : '';
		}
	}

	if ( '' === $mail ) {
		return null;
	}

	return NXTCC_Auth_DAO::latest_settings_for_owner( $mail );
}

/**
 * Determine whether a settings row contains a complete WhatsApp connection.
 *
 * @param array|null $row Settings row.
 * @return bool True if connection fields are present.
 */
function nxtcc_auth_has_connection( ?array $row ): bool {
	if ( empty( $row ) ) {
		return false;
	}

	return ! empty( $row['app_id'] )
		&& ! empty( $row['access_token_ct'] )
		&& ! empty( $row['access_token_nonce'] )
		&& ! empty( $row['business_account_id'] )
		&& ! empty( $row['phone_number_id'] );
}

/**
 * Decrypt the stored access token for a settings row.
 *
 * @param array $row Settings row.
 * @return string|null Decrypted token or null if unavailable.
 */
function nxtcc_auth_resolve_access_token( array $row ): ?string {
	if ( empty( $row['access_token_ct'] ) || empty( $row['access_token_nonce'] ) ) {
		return null;
	}

	$pt = null;

	if ( class_exists( 'NXTCC_Helpers' ) ) {
		$pt = NXTCC_Helpers::crypto_decrypt(
			(string) $row['access_token_ct'],
			(string) $row['access_token_nonce']
		);
	} elseif ( function_exists( 'nxtcc_crypto_decrypt' ) ) {
		$pt = nxtcc_crypto_decrypt(
			(string) $row['access_token_ct'],
			(string) $row['access_token_nonce']
		);
	}

	if ( is_wp_error( $pt ) || ! is_string( $pt ) || '' === $pt ) {
		return null;
	}

	return $pt;
}

/**
 * Fetch all APPROVED AUTHENTICATION templates for the given business id.
 *
 * Paginates through the Graph API with a safety cap.
 *
 * @param string $access_token Access token.
 * @param string $business_id  WhatsApp Business Account id.
 * @return array<int, array<string, string>> Templates list.
 */
function nxtcc_auth_fetch_templates( string $access_token, string $business_id ): array {
	$fields = 'name,language,status,category,last_updated_time';
	$limit  = 100;

	$collected = array();
	$after     = null;
	$loop      = 0;

	do {
		++$loop;

		$url = "https://graph.facebook.com/v19.0/{$business_id}/message_templates?fields={$fields}&limit={$limit}";
		if ( null !== $after && '' !== $after ) {
			$url .= '&after=' . rawurlencode( $after );
		}

		$res = nxtcc_auth_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			break;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );

		if ( $code < 200 || $code >= 300 ) {
			break;
		}

		$body = json_decode( $raw, true );
		$data = ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : array();

		foreach ( $data as $tpl ) {
			$cat = strtoupper( (string) ( $tpl['category'] ?? '' ) );
			$st  = strtoupper( (string) ( $tpl['status'] ?? '' ) );

			if ( 'AUTHENTICATION' === $cat && 'APPROVED' === $st ) {
				$collected[] = array(
					'name'              => (string) ( $tpl['name'] ?? '' ),
					'language'          => (string) ( $tpl['language'] ?? '' ),
					'status'            => (string) ( $tpl['status'] ?? '' ),
					'category'          => (string) ( $tpl['category'] ?? '' ),
					'last_updated_time' => (string) ( $tpl['last_updated_time'] ?? '' ),
				);
			}
		}

		$after = $body['paging']['cursors']['after'] ?? null;

		// Safety cap against infinite paging.
		if ( $loop > 10 ) {
			break;
		}
	} while ( null !== $after );

	return $collected;
}

/**
 * Register admin AJAX handlers on init.
 *
 * @return void
 */
function nxtcc_auth_register_admin_ajax(): void {
	add_action( 'wp_ajax_nxtcc_auth_list_owners', 'nxtcc_ajax_list_owners' );
	add_action( 'wp_ajax_nxtcc_auth_list_auth_templates', 'nxtcc_ajax_list_auth_templates' );
	add_action( 'wp_ajax_nxtcc_auth_generate_default_template', 'nxtcc_ajax_generate_default_template' );
	add_action( 'wp_ajax_nxtcc_auth_save_options', 'nxtcc_ajax_save_auth_options' );
}
add_action( 'init', 'nxtcc_auth_register_admin_ajax' );

/**
 * AJAX: list tenant owners (profiles) that have usable WhatsApp credentials.
 *
 * Response:
 * - owners: [{mail,label}]
 * - default_tenant_key: string
 *
 * @return void
 */
function nxtcc_ajax_list_owners(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	check_ajax_referer( 'nxtcc_auth_admin', 'nonce', true );

	$rows = NXTCC_Auth_DAO::latest_rows_per_owner();

	$owners = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( ! nxtcc_auth_has_connection( $row ) ) {
			continue;
		}
		$owners[] = array(
			'mail'  => (string) $row['user_mailid'],
			'label' => (string) $row['user_mailid'],
		);
	}

	wp_send_json_success(
		array(
			'owners'             => $owners,
			'default_tenant_key' => nxtcc_auth_get_default_owner_mail(),
		)
	);
}

/**
 * AJAX: list APPROVED AUTHENTICATION templates for selected owner.
 *
 * @return void
 */
function nxtcc_ajax_list_auth_templates(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	check_ajax_referer( 'nxtcc_auth_admin', 'nonce', true );

	$owner = isset( $_POST['owner_mailid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['owner_mailid'] ) ) : '';

	$row = nxtcc_auth_pick_settings_row( '' !== $owner ? $owner : null );
	if ( ! nxtcc_auth_has_connection( $row ) ) {
		wp_send_json_success(
			array(
				'items'   => array(),
				'has_any' => 0,
			)
		);
	}

	$token = nxtcc_auth_resolve_access_token( $row );
	if ( null === $token ) {
		wp_send_json_success(
			array(
				'items'   => array(),
				'has_any' => 0,
			)
		);
	}

	$templates = nxtcc_auth_fetch_templates( $token, (string) $row['business_account_id'] );

	$owner_out = (string) $owner;
	if ( '' === $owner_out ) {
		$owner_out = (string) ( $row['user_mailid'] ?? '' );
	}

	wp_send_json_success(
		array(
			'items'   => $templates,
			'has_any' => count( $templates ) > 0 ? 1 : 0,
			'owner'   => $owner_out,
		)
	);
}

/**
 * Build the components payload for a default AUTHENTICATION OTP template.
 *
 * Notes:
 * - AUTHENTICATION templates do not accept custom BODY text at creation time.
 * - COPY_CODE is used so WhatsApp shows a “Copy code” style button.
 *
 * @param int $expiry_minutes Expiration minutes shown by WhatsApp.
 * @return array<int, array<string, mixed>>
 */
function nxtcc_auth_build_default_components( int $expiry_minutes = 10 ): array {
	return array(
		array(
			'type'                        => 'BODY',
			'add_security_recommendation' => true,
		),
		array(
			'type'                    => 'FOOTER',
			'code_expiration_minutes' => max( 1, $expiry_minutes ),
		),
		array(
			'type'    => 'BUTTONS',
			'buttons' => array(
				array(
					'type'     => 'OTP',
					'otp_type' => 'COPY_CODE',
					'text'     => 'Copy Code',
				),
			),
		),
	);
}

/**
 * AJAX: Create a default AUTHENTICATION OTP template if none exist yet.
 *
 * @return void
 */
function nxtcc_ajax_generate_default_template(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	check_ajax_referer( 'nxtcc_auth_admin', 'nonce', true );

	$tpl_name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : 'nxtcc_otp_default';
	$tpl_lang = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['language'] ) ) : 'en_US';

	$expiry = isset( $_POST['expiry_minutes'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_POST['expiry_minutes'] ) ) : 10;
	$expiry = max( 1, min( 60, $expiry ) );

	$owner = isset( $_POST['owner_mailid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['owner_mailid'] ) ) : '';

	$row = nxtcc_auth_pick_settings_row( '' !== $owner ? $owner : null );
	if ( ! nxtcc_auth_has_connection( $row ) ) {
		wp_send_json_error( array( 'message' => 'Missing connection for selected profile.' ), 400 );
	}

	$token = nxtcc_auth_resolve_access_token( $row );
	if ( null === $token ) {
		wp_send_json_error( array( 'message' => 'Token unavailable.' ), 400 );
	}

	$existing = nxtcc_auth_fetch_templates( $token, (string) $row['business_account_id'] );
	if ( count( $existing ) >= 1 ) {
		wp_send_json_success(
			array(
				'exists'          => true,
				'created'         => false,
				'already_has_any' => true,
				'count'           => count( $existing ),
				'name'            => $tpl_name,
				'language'        => $tpl_lang,
			)
		);
	}

	$business_id = (string) $row['business_account_id'];

	$check_url = 'https://graph.facebook.com/v19.0/' . $business_id . '/message_templates?name=' . rawurlencode( $tpl_name );
	$check     = nxtcc_auth_remote_get(
		$check_url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		)
	);

	$exists = false;
	if ( ! is_wp_error( $check ) ) {
		$code = (int) wp_remote_retrieve_response_code( $check );
		$raw  = (string) wp_remote_retrieve_body( $check );

		if ( $code >= 200 && $code < 300 ) {
			$decoded = json_decode( $raw, true );
			$exists  = ! empty( $decoded['data'] );
		}
	}

	if ( $exists ) {
		wp_send_json_success(
			array(
				'exists'   => true,
				'created'  => false,
				'name'     => $tpl_name,
				'language' => $tpl_lang,
			)
		);
	}

	$payload = array(
		'name'       => $tpl_name,
		'language'   => $tpl_lang,
		'category'   => 'AUTHENTICATION',
		'components' => nxtcc_auth_build_default_components( $expiry ),
	);

	$create_url = 'https://graph.facebook.com/v19.0/' . $business_id . '/message_templates';

	$res = nxtcc_auth_remote_post(
		$create_url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $res ) ) {
		wp_send_json_error(
			array(
				'message' => 'Meta API error.',
				'raw'     => $res->get_error_message(),
			),
			500
		);
	}

	$http = (int) wp_remote_retrieve_response_code( $res );
	$raw  = (string) wp_remote_retrieve_body( $res );

	if ( $http < 200 || $http >= 300 ) {
		wp_send_json_error(
			array(
				'message' => 'Meta API error.',
				'raw'     => $raw,
			),
			500
		);
	}

	wp_send_json_success(
		array(
			'exists'   => false,
			'created'  => true,
			'name'     => $tpl_name,
			'language' => $tpl_lang,
		)
	);
}

/**
 * AJAX: Save OTP widget settings and policy.
 *
 * - Options: otp_len, resend_cooldown, terms_url, privacy_url, auto_sync,
 *   auth_template, default_tenant_key, login page target, login button
 *   placement/appearance.
 * - Policy: show_password, force_migrate, grace_enabled, widget_branding,
 *   force_path, grace_days, redirect_wp_login, allowed_countries.
 *
 * @return void
 */
function nxtcc_ajax_save_auth_options(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	check_ajax_referer( 'nxtcc_auth_admin', 'nonce', true );

	$opts = get_option( 'nxtcc_auth_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	$defaults = nxtcc_auth_get_ui_defaults();

	$otp_len         = isset( $_POST['otp_len'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_POST['otp_len'] ) ) : (int) $defaults['otp_len'];
	$otp_len         = max( 4, min( 8, $otp_len ) );
	$opts['otp_len'] = $otp_len;

	$resend_cooldown         = isset( $_POST['resend_cooldown'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_POST['resend_cooldown'] ) ) : (int) $defaults['resend_cooldown'];
	$resend_cooldown         = max( 10, min( 300, $resend_cooldown ) );
	$opts['resend_cooldown'] = $resend_cooldown;

	$opts['terms_url']   = isset( $_POST['terms_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['terms_url'] ) ) : '';
	$opts['privacy_url'] = isset( $_POST['privacy_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['privacy_url'] ) ) : '';

	$opts['auto_sync'] = ! empty( $_POST['auto_sync'] ) ? 1 : 0;

	$auth_tpl = isset( $_POST['auth_template'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['auth_template'] ) ) : '';

	$opts['auth_template'] = $auth_tpl;

	$opts['login_button_wp'] = ! empty( $_POST['login_button_wp'] ) ? 1 : 0;
	$opts['login_button_wc'] = ! empty( $_POST['login_button_wc'] ) ? 1 : 0;

	$button_text = isset( $_POST['login_button_text'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['login_button_text'] ) ) : (string) $defaults['login_button_text'];
	$button_text = trim( $button_text );
	if ( '' === $button_text ) {
		$button_text = (string) $defaults['login_button_text'];
	}
	$opts['login_button_text'] = $button_text;

	$separator_text = isset( $_POST['login_button_separator'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['login_button_separator'] ) ) : (string) $defaults['login_button_separator'];
	$separator_text = trim( $separator_text );
	if ( '' === $separator_text ) {
		$separator_text = (string) $defaults['login_button_separator'];
	}
	$opts['login_button_separator'] = $separator_text;

	$button_bg = isset( $_POST['login_button_bg'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['login_button_bg'] ) ) : '';
	if ( ! is_string( $button_bg ) || '' === $button_bg ) {
		$button_bg = (string) $defaults['login_button_bg'];
	}
	$opts['login_button_bg'] = $button_bg;

	$button_text_color = isset( $_POST['login_button_text_color'] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['login_button_text_color'] ) ) : '';
	if ( ! is_string( $button_text_color ) || '' === $button_text_color ) {
		$button_text_color = (string) $defaults['login_button_text_color'];
	}
	$opts['login_button_text_color'] = $button_text_color;

	$button_corner = isset( $_POST['login_button_corner'] ) ? sanitize_key( wp_unslash( (string) $_POST['login_button_corner'] ) ) : (string) $defaults['login_button_corner'];
	if ( ! in_array( $button_corner, array( 'rounded', 'rectangle' ), true ) ) {
		$button_corner = (string) $defaults['login_button_corner'];
	}
	$opts['login_button_corner'] = $button_corner;

	if ( isset( $_POST['default_tenant_key'] ) ) {
		$mail                       = sanitize_email( wp_unslash( (string) $_POST['default_tenant_key'] ) );
		$opts['default_tenant_key'] = $mail;
	}

	$opts['login_page_url'] = isset( $_POST['login_page_url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['login_page_url'] ) ) : '';

	update_option( 'nxtcc_auth_options', $opts );

	$policy = get_option( 'nxtcc_auth_policy', array() );
	if ( ! is_array( $policy ) ) {
		$policy = array();
	}

	$policy['show_password']     = ! empty( $_POST['show_password'] ) ? 1 : 0;
	$policy['force_migrate']     = ! empty( $_POST['force_migrate'] ) ? 1 : 0;
	$policy['grace_enabled']     = ! empty( $_POST['grace_enabled'] ) ? 1 : 0;
	$policy['redirect_wp_login'] = ! empty( $_POST['redirect_wp_login'] ) ? 1 : 0;
	$policy['widget_branding']   = ! empty( $_POST['widget_branding'] ) ? 1 : 0;

	$force_path = isset( $_POST['force_path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['force_path'] ) ) : '/nxt-whatsapp-login/';
	$force_path = trim( $force_path );
	if ( '' === $force_path ) {
		$force_path = '/nxt-whatsapp-login/';
	}
	if ( '/' !== $force_path[0] ) {
		$force_path = '/' . $force_path;
	}
	if ( '/' !== substr( $force_path, -1 ) ) {
		$force_path .= '/';
	}
	$policy['force_path'] = $force_path;

	$grace_days           = isset( $_POST['grace_days'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_POST['grace_days'] ) ) : 7;
	$grace_days           = max( 1, min( 90, $grace_days ) );
	$policy['grace_days'] = $grace_days;

	$allowed_raw = filter_input(
		INPUT_POST,
		'allowed_countries',
		FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		FILTER_REQUIRE_ARRAY
	);

	if ( ! is_array( $allowed_raw ) ) {
		$allowed_raw = array();
	}

	$allowed_raw = wp_unslash( $allowed_raw );

	$allowed_in = array_map(
		static function ( $v ) {
			return sanitize_text_field( (string) $v );
		},
		$allowed_raw
	);

	$allowed_clean = array();
	foreach ( $allowed_in as $iso ) {
		$iso = strtoupper( trim( $iso ) );
		if ( preg_match( '/^[A-Z]{2}$/', $iso ) ) {
			$allowed_clean[ $iso ] = true;
		}
	}
	$policy['allowed_countries'] = array_keys( $allowed_clean );

	if ( function_exists( 'nxtcc_fm_update_options' ) ) {
		nxtcc_fm_update_options( $policy );
	} elseif ( function_exists( 'nxtcc_fm_update_options' ) ) {
			nxtcc_fm_update_options( $policy );
	} else {
		update_option( 'nxtcc_auth_policy', $policy );
	}

	wp_send_json_success(
		array(
			'saved'  => true,
			'opts'   => nxtcc_auth_get_ui_options(),
			'policy' => array(
				'show_password'     => (int) ( $policy['show_password'] ?? 1 ),
				'force_migrate'     => (int) ( $policy['force_migrate'] ?? 0 ),
				'force_path'        => (string) ( $policy['force_path'] ?? '/nxt-whatsapp-login/' ),
				'grace_enabled'     => (int) ( $policy['grace_enabled'] ?? 0 ),
				'grace_days'        => (int) ( $policy['grace_days'] ?? 7 ),
				'redirect_wp_login' => (int) ( $policy['redirect_wp_login'] ?? 0 ),
				'widget_branding'   => (int) ( $policy['widget_branding'] ?? 0 ),
				'allowed_countries' => array_map( 'strval', (array) ( $policy['allowed_countries'] ?? array() ) ),
			),
		)
	);
}

/**
 * Choose an AUTHENTICATION template pair (name|language) for sending OTPs.
 *
 * Strategy:
 * - If admin saved a pair and it still exists, use it.
 * - Else, if saved name exists with different language, use that.
 * - Else, pick the most recently updated approved AUTH template.
 *
 * @param array $settings Settings row.
 * @return array<int, string>|WP_Error [name, language] or error.
 */
function nxtcc_auth_pick_template_pair( array $settings ) {
	$opts       = get_option( 'nxtcc_auth_options', array() );
	$saved_pair = isset( $opts['auth_template'] ) ? (string) $opts['auth_template'] : '';

	$token = nxtcc_auth_resolve_access_token( $settings );
	if ( null === $token ) {
		return new WP_Error( 'token_unavailable', 'Token unavailable.' );
	}

	$list = nxtcc_auth_fetch_templates( $token, (string) $settings['business_account_id'] );
	if ( empty( $list ) ) {
		return new WP_Error( 'no_templates', 'No approved AUTHENTICATION templates found.' );
	}

	if ( '' !== $saved_pair && false !== strpos( $saved_pair, '|' ) ) {
		list( $saved_name, $saved_lang ) = array_map( 'trim', explode( '|', $saved_pair, 2 ) );

		foreach ( $list as $tpl ) {
			if (
				0 === strcasecmp( (string) $tpl['name'], (string) $saved_name )
				&& 0 === strcasecmp( (string) $tpl['language'], (string) $saved_lang )
			) {
				return array( (string) $tpl['name'], (string) $tpl['language'] );
			}
		}

		foreach ( $list as $tpl ) {
			if ( 0 === strcasecmp( (string) $tpl['name'], (string) $saved_name ) ) {
				return array( (string) $tpl['name'], (string) $tpl['language'] );
			}
		}
	}

	usort(
		$list,
		static function ( $a, $b ) {
			$time_a = ! empty( $a['last_updated_time'] ) ? strtotime( (string) $a['last_updated_time'] ) : 0;
			$time_b = ! empty( $b['last_updated_time'] ) ? strtotime( (string) $b['last_updated_time'] ) : 0;

			if ( $time_a === $time_b ) {
				return 0;
			}

			return ( $time_b > $time_a ) ? 1 : -1;
		}
	);

	$latest = $list[0];

	return array( (string) $latest['name'], (string) $latest['language'] );
}

/*
=============================================================================
 * Public REST helpers + handlers (registered elsewhere)
 * =============================================================================
 */

/**
 * Resolve an "active" settings row for public OTP sends.
 *
 * Priority:
 * - Default owner (if configured and complete).
 * - Latest settings row where webhook is subscribed.
 * - Latest settings row overall.
 *
 * @return array|null Settings row or null.
 */
function nxtcc_auth_get_active_settings_row(): ?array {
	$owner = nxtcc_auth_get_default_owner_mail();
	if ( '' !== $owner ) {
		$r = nxtcc_auth_pick_settings_row( $owner );
		if ( nxtcc_auth_has_connection( $r ) ) {
			return $r;
		}
	}

	$row = NXTCC_Auth_DAO::latest_settings_with_webhook();
	if ( is_array( $row ) ) {
		return $row;
	}

	$row = NXTCC_Auth_DAO::latest_settings_any();

	return is_array( $row ) ? $row : null;
}

/**
 * Convert an E.164 phone string to digits-only.
 *
 * @param string $e164 E.164 input (may include + and spaces).
 * @return string Digits-only phone.
 */
function nxtcc_auth_digits_from_e164( string $e164 ): string {
	return (string) preg_replace( '/\D+/', '', $e164 );
}

/**
 * Build a throttle key for OTP requests based on phone + session + caller IP.
 *
 * @param string $kind    Marker type (cd = cooldown).
 * @param string $phone   Phone.
 * @param string $session Session id.
 * @return string Transient key.
 */
function nxtcc_auth_throttle_key( string $kind, string $phone, string $session ): string {
	$ip_val = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
	$ip     = is_string( $ip_val ) ? $ip_val : '';

	$phone   = sanitize_text_field( $phone );
	$session = sanitize_text_field( $session );
	$kind    = sanitize_key( $kind );

	return 'nxtcc_otp_' . $kind . '_' . md5( $ip . '|' . $phone . '|' . $session );
}

/**
 * Set cooldown marker to prevent rapid OTP resend.
 *
 * @param string $phone   Phone.
 * @param string $session Session id.
 * @param int    $seconds Cooldown seconds.
 * @return void
 */
function nxtcc_auth_set_cooldown( string $phone, string $session, int $seconds ): void {
	set_transient( nxtcc_auth_throttle_key( 'cd', $phone, $session ), 1, max( 1, $seconds ) );
}

/**
 * Check if a phone+session is on cooldown.
 *
 * @param string $phone   Phone.
 * @param string $session Session id.
 * @return bool
 */
function nxtcc_auth_on_cooldown( string $phone, string $session ): bool {
	return (bool) get_transient( nxtcc_auth_throttle_key( 'cd', $phone, $session ) );
}

/**
 * Generate a numeric OTP code.
 *
 * @param int $len Length (4-8).
 * @return string OTP digits.
 */
function nxtcc_auth_generate_otp_numeric( int $len = 6 ): string {
	$len = max( 4, min( 8, (int) $len ) );
	$out = '';

	for ( $i = 0; $i < $len; $i++ ) {
		$out .= (string) wp_rand( 0, 9 );
	}

	return $out;
}

/**
 * Upsert an OTP record in the database table (nxtcc_auth_otp).
 *
 * Behaviour:
 * - If an active OTP exists for this session+phone, refresh code + expiry.
 * - Otherwise create a new active OTP row.
 *
 * @param string $session_id Session id.
 * @param string $phone_e164 E.164 phone.
 * @param string $code       OTP.
 * @param int    $ttl_secs   TTL seconds.
 * @return int OTP row id.
 */
function nxtcc_auth_upsert_otp( string $session_id, string $phone_e164, string $code, int $ttl_secs = 300 ): int {
	$salt = bin2hex( random_bytes( 16 ) );
	$hash = hash( 'sha256', $salt . '|' . $code );
	$exp  = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl_secs ) );

	$existing_id = NXTCC_Auth_DAO::otp_find_active_id( $session_id, $phone_e164 );

	if ( $existing_id ) {
		NXTCC_Auth_DAO::otp_update_by_id(
			(int) $existing_id,
			array(
				'code_hash'  => $hash,
				'salt'       => $salt,
				'expires_at' => $exp,
				'attempts'   => 0,
				'status'     => 'active',
			)
		);

		return (int) $existing_id;
	}

	return (int) NXTCC_Auth_DAO::otp_insert(
		array(
			'session_id'   => $session_id,
			'phone_e164'   => $phone_e164,
			'user_id'      => 0,
			'code_hash'    => $hash,
			'salt'         => $salt,
			'expires_at'   => $exp,
			'attempts'     => 0,
			'max_attempts' => 5,
			'status'       => 'active',
			'created_at'   => current_time( 'mysql', 1 ),
		)
	);
}

/**
 * Send OTP using a template that includes a URL button (COPY_CODE-style flow).
 *
 * IMPORTANT:
 * - When sending a template message, Meta does not accept sub_type "otp".
 * - The working payload uses:
 *   - body parameter: text code
 *   - button parameter: sub_type "url" with text code
 *
 * @param array  $settings Settings row.
 * @param string $to_e164  Destination E.164.
 * @param string $tpl_name Template name.
 * @param string $tpl_lang Template language code.
 * @param string $code     OTP code.
 * @return array{ok:bool,http:int,wamid:?string,raw:mixed,error:?string}
 */
function nxtcc_auth_send_whatsapp_copy_code(
	array $settings,
	string $to_e164,
	string $tpl_name,
	string $tpl_lang,
	string $code
): array {
	$to_digits = (string) preg_replace( '/\D+/', '', $to_e164 );

	$token = nxtcc_auth_resolve_access_token( $settings );
	if ( ! $token ) {
		return array(
			'ok'    => false,
			'error' => 'Token unavailable',
			'http'  => 0,
			'raw'   => null,
			'wamid' => null,
		);
	}

	$payload = array(
		'messaging_product' => 'whatsapp',
		'to'                => $to_digits,
		'type'              => 'template',
		'template'          => array(
			'name'       => $tpl_name,
			'language'   => array(
				'code' => $tpl_lang,
			),
			'components' => array(
				array(
					'type'       => 'body',
					'parameters' => array(
						array(
							'type' => 'text',
							'text' => (string) $code,
						),
					),
				),
				array(
					'type'       => 'button',
					'sub_type'   => 'url',
					'index'      => '0',
					'parameters' => array(
						array(
							'type' => 'text',
							'text' => (string) $code,
						),
					),
				),
			),
		),
	);

	$url = 'https://graph.facebook.com/v19.0/' . rawurlencode( (string) $settings['phone_number_id'] ) . '/messages';

	$res = nxtcc_auth_remote_post(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $res ) ) {
		return array(
			'ok'    => false,
			'error' => $res->get_error_message(),
			'http'  => 0,
			'raw'   => null,
			'wamid' => null,
		);
	}

	$http = (int) wp_remote_retrieve_response_code( $res );
	$raw  = (string) wp_remote_retrieve_body( $res );
	$body = json_decode( $raw, true );

	if ( $http < 200 || $http >= 300 ) {
		return array(
			'ok'    => false,
			'error' => (string) ( is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'Meta API error' ),
			'http'  => $http,
			'raw'   => $body,
			'wamid' => null,
		);
	}

	$wamid = null;
	if ( is_array( $body ) && isset( $body['messages'][0]['id'] ) ) {
		$wamid = (string) $body['messages'][0]['id'];
	}

	return array(
		'ok'    => true,
		'http'  => $http,
		'raw'   => $body,
		'wamid' => $wamid,
		'error' => null,
	);
}

/**
 * Log a successful template send to message history.
 *
 * This is optional bookkeeping used for reporting in the admin UI.
 *
 * @param array  $settings Settings row.
 * @param string $tpl_name Template name.
 * @param string $tpl_lang Template language.
 * @param string $wamid    Meta message id.
 * @return void
 */
function nxtcc_auth_log_history( array $settings, string $tpl_name, string $tpl_lang, string $wamid ): void {
	NXTCC_Auth_DAO::history_insert(
		array(
			'user_mailid'         => (string) ( $settings['user_mailid'] ?? '' ),
			'business_account_id' => (string) ( $settings['business_account_id'] ?? '' ),
			'phone_number_id'     => (string) ( $settings['phone_number_id'] ?? '' ),
			'template_name'       => (string) $tpl_name,
			'template_type'       => 'AUTHENTICATION',
			'template_data'       => wp_json_encode(
				array(
					'language' => $tpl_lang,
				)
			),
			'status'              => 'sent',
			'status_timestamps'   => wp_json_encode(
				array(
					'sent' => current_time( 'mysql', 1 ),
				)
			),
			'created_at'          => current_time( 'mysql', 1 ),
			'sent_at'             => current_time( 'mysql', 1 ),
			'meta_message_id'     => (string) $wamid,
		)
	);
}

/**
 * REST: request/resend OTP for a session + phone.
 *
 * Expected JSON body:
 * - session_id (string)
 * - phone_e164 (string, digits with or without '+')
 *
 * Flow:
 * - Validate params.
 * - If logged in, block numbers already bound to another user.
 * - Enforce allowed countries (optional).
 * - Enforce cooldown.
 * - Pick active settings + template.
 * - Generate OTP, store DB record, send WhatsApp template, log history.
 * - Return expiry seconds.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function nxtcc_auth_request_otp( WP_REST_Request $req ) {
	$body = json_decode( (string) $req->get_body(), true );
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$session_id = sanitize_text_field( (string) ( $body['session_id'] ?? '' ) );
	$phone_e164 = '+' . (string) preg_replace( '/\D+/', '', (string) ( $body['phone_e164'] ?? '' ) );

	if ( '' === $session_id || strlen( $phone_e164 ) < 7 ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Invalid parameters',
			),
			400
		);
	}

	$current_uid = (int) get_current_user_id();
	if ( $current_uid > 0 ) {
		$binding  = NXTCC_Auth_DAO::binding_find_by_phone( $phone_e164 );
		$owner_id = ( $binding && isset( $binding->user_id ) ) ? (int) $binding->user_id : 0;

		if ( $owner_id && $owner_id !== $current_uid ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'code'    => 'phone_in_use',
					'message' => __( 'This number is already assigned to another account. Please try a different number.', 'nxt-cloud-chat' ),
				),
				409
			);
		}
	}

	$opts = get_option( 'nxtcc_auth_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}

	$otp_len = isset( $opts['otp_len'] ) ? (int) $opts['otp_len'] : 6;
	$otp_len = max( 4, min( 8, $otp_len ) );

	$cooldown = isset( $opts['resend_cooldown'] ) ? (int) $opts['resend_cooldown'] : 30;
	$cooldown = max( 10, min( 300, $cooldown ) );

	$ttl = 300;

	$policy = get_option( 'nxtcc_auth_policy', array() );
	if ( ! is_array( $policy ) ) {
		$policy = array();
	}

	$allow = array();
	if ( isset( $policy['allowed_countries'] ) && is_array( $policy['allowed_countries'] ) ) {
		$allow = array_values( array_unique( array_map( 'strtoupper', $policy['allowed_countries'] ) ) );
	}

	if ( ! empty( $allow ) ) {
		$iso = function_exists( 'nxtcc_iso_from_e164' ) ? nxtcc_iso_from_e164( $phone_e164 ) : '';
		$iso = is_string( $iso ) ? strtoupper( $iso ) : '';

		if ( '' !== $iso && ! in_array( $iso, $allow, true ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'code'    => 'COUNTRY_NOT_ALLOWED',
					'message' => __( 'This phone number’s country isn’t allowed for verification on this site.', 'nxt-cloud-chat' ),
				),
				403
			);
		}
	}

	if ( nxtcc_auth_on_cooldown( $phone_e164, $session_id ) ) {
		return new WP_REST_Response(
			array(
				'status'      => 'error',
				'message'     => 'Please wait before resending.',
				'retry_after' => $cooldown,
			),
			429
		);
	}

	$settings = nxtcc_auth_get_active_settings_row();
	if ( ! $settings || ! nxtcc_auth_has_connection( $settings ) ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'WhatsApp connection not configured',
			),
			503
		);
	}

	$pick = nxtcc_auth_pick_template_pair( $settings );
	if ( is_wp_error( $pick ) ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => $pick->get_error_message(),
			),
			409
		);
	}

	list( $tpl_name, $tpl_lang ) = $pick;

	$code = nxtcc_auth_generate_otp_numeric( $otp_len );
	nxtcc_auth_upsert_otp( $session_id, $phone_e164, $code, $ttl );

	$send = nxtcc_auth_send_whatsapp_copy_code( $settings, $phone_e164, (string) $tpl_name, (string) $tpl_lang, $code );
	if ( empty( $send['ok'] ) ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Failed to send code. Try again later.',
			),
			502
		);
	}

	nxtcc_auth_log_history( $settings, (string) $tpl_name, (string) $tpl_lang, (string) ( $send['wamid'] ?? '' ) );
	nxtcc_auth_set_cooldown( $phone_e164, $session_id, $cooldown );

	return new WP_REST_Response(
		array(
			'status'     => 'ok',
			'expires_in' => $ttl,
		),
		200
	);
}

/**
 * REST: verify OTP for a session + phone.
 *
 * Expected JSON body:
 * - session_id
 * - phone_e164
 * - code (OTP digits)
 * - redirect_to (optional)
 *
 * Flow:
 * - Validate params.
 * - Verify code against active OTP row (hash + expiry + attempts).
 * - Bind to an existing user, current user, or create new user.
 * - Log user in (set auth cookie).
 * - Fire hooks for downstream integrations.
 * - Return a validated redirect URL.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function nxtcc_auth_verify_otp( WP_REST_Request $req ) {
	$body = json_decode( (string) $req->get_body(), true );
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$session_id = sanitize_text_field( (string) ( $body['session_id'] ?? '' ) );
	$phone_e164 = '+' . (string) preg_replace( '/\D+/', '', (string) ( $body['phone_e164'] ?? '' ) );
	$code       = (string) preg_replace( '/\D+/', '', (string) ( $body['code'] ?? '' ) );

	if ( '' === $session_id || strlen( $phone_e164 ) < 7 || '' === $code ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Invalid parameters',
			),
			400
		);
	}

	$row = NXTCC_Auth_DAO::otp_find_latest( $session_id, $phone_e164 );
	if ( ! $row || ( $row['status'] ?? '' ) !== 'active' ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Invalid or expired code.',
			),
			400
		);
	}

	if ( strtotime( (string) $row['expires_at'] ) < time() ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Code expired.',
			),
			400
		);
	}

	$attempts = (int) ( $row['attempts'] ?? 0 );
	$max_att  = (int) ( $row['max_attempts'] ?? 5 );

	if ( $attempts >= $max_att ) {
		NXTCC_Auth_DAO::otp_update_by_id( (int) $row['id'], array( 'status' => 'blocked' ) );

		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Too many attempts. Try later.',
			),
			429
		);
	}

	$calc = hash( 'sha256', (string) $row['salt'] . '|' . $code );
	if ( ! hash_equals( (string) $row['code_hash'], $calc ) ) {
		NXTCC_Auth_DAO::otp_update_by_id(
			(int) $row['id'],
			array(
				'attempts' => $attempts + 1,
			)
		);

		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => 'Incorrect code.',
			),
			400
		);
	}

	NXTCC_Auth_DAO::otp_update_by_id( (int) $row['id'], array( 'status' => 'verified' ) );

	$binding       = NXTCC_Auth_DAO::binding_find_by_phone( $phone_e164 );
	$current_uid   = (int) get_current_user_id();
	$user_to_login = 0;

	if ( $binding && isset( $binding->user_id ) && (int) $binding->user_id > 0 ) {
		$user_to_login = (int) $binding->user_id;
		if ( empty( $binding->verified_at ) ) {
			NXTCC_Auth_DAO::binding_mark_verified_if_empty( (int) $binding->id );
		}
	} elseif ( $current_uid > 0 ) {
		NXTCC_Auth_DAO::binding_replace( (int) $current_uid, $phone_e164 );
		$user_to_login = (int) $current_uid;
	} else {
		$digits     = nxtcc_auth_digits_from_e164( $phone_e164 );
		$login_base = $digits;
		$login_try  = $login_base;
		$suffix     = 0;

		while ( username_exists( $login_try ) ) {
			++$suffix;
			$login_try = $login_base . $suffix;
		}

		$pass = wp_generate_password( 20, true, true );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = ( is_string( $home_host ) && '' !== $home_host ) ? $home_host : 'example.com';
		$host      = (string) preg_replace( '/:\d+$/', '', $host );

		$email = $digits . '@' . $host;
		$i     = 1;

		while ( email_exists( $email ) ) {
			$email = $digits . $i . '@' . $host;
			++$i;
		}

		$new_uid = wp_insert_user(
			array(
				'user_login'    => $login_try,
				'user_pass'     => $pass,
				'user_email'    => $email,
				'display_name'  => $digits,
				'nickname'      => $digits,
				'user_nicename' => sanitize_title( $digits ),
			)
		);

		if ( is_wp_error( $new_uid ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Could not create account. ' . $new_uid->get_error_message(),
				),
				500
			);
		}

		NXTCC_Auth_DAO::binding_replace( (int) $new_uid, $phone_e164 );
		$user_to_login = (int) $new_uid;
	}

	$settings = nxtcc_auth_get_active_settings_row();

	$ctx = array(
		'business_account_id' => is_array( $settings ) ? (string) ( $settings['business_account_id'] ?? '' ) : '',
		'phone_number_id'     => is_array( $settings ) ? (string) ( $settings['phone_number_id'] ?? '' ) : '',
		'connection_owner'    => is_array( $settings ) ? (string) ( $settings['user_mailid'] ?? '' ) : '',
	);

	/**
	 * Fired after OTP is verified and the user account to log in is selected.
	 *
	 * @param int    $user_id     Logged-in user id.
	 * @param string $phone_e164  Verified phone number.
	 * @param array  $context     Connection context (business_account_id, phone_number_id, connection_owner).
	 */
	do_action( 'nxtcc_otp_verified', $user_to_login, $phone_e164, $ctx );

	if ( $user_to_login > 0 && ( ! is_user_logged_in() || (int) get_current_user_id() !== $user_to_login ) ) {
		wp_set_current_user( $user_to_login );
		wp_set_auth_cookie( $user_to_login, true );

		$ud = get_userdata( $user_to_login );
		if ( $ud ) {
			do_action( 'nxtcc_wp_login', $ud->user_login, $ud );
		}
	}

	if ( $user_to_login > 0 ) {
		delete_user_meta( $user_to_login, '_nxtcc_fm_login_date' );
		update_user_meta( $user_to_login, '_nxtcc_migration_complete', 1 );
	}

	$requested_redirect = isset( $body['redirect_to'] ) ? esc_url_raw( (string) $body['redirect_to'] ) : '';
	$header_referer     = (string) $req->get_header( 'referer' );
	$wp_referer         = (string) wp_get_referer();

	$redirect_to = $requested_redirect;

	if ( '' === $redirect_to ) {
		$redirect_to = $header_referer;
	}

	if ( '' === $redirect_to ) {
		$redirect_to = $wp_referer;
	}

	if ( '' === $redirect_to ) {
		$redirect_to = home_url( '/' );
	}

	$parsed = wp_parse_url( $redirect_to );
	$path   = isset( $parsed['path'] ) ? trim( (string) $parsed['path'] ) : '';

	if ( false !== stripos( $path, 'wp-login.php' ) || false !== stripos( $path, 'wp-admin' ) ) {
		$redirect_to = home_url( '/' );
	}

	$redirect_to = wp_validate_redirect( $redirect_to, home_url( '/' ) );

	return new WP_REST_Response(
		array(
			'status'      => 'ok',
			'next_action' => 'redirect',
			'redirect_to' => $redirect_to,
		),
		200
	);
}
