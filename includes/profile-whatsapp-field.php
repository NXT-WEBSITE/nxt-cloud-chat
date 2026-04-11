<?php
/**
 * Profile: Verified WhatsApp number field.
 *
 * Adds a read-only "Verified WhatsApp number" to the user Contact Info section.
 * The value is read from the auth bindings table via NXTCC_Auth_Bindings_Store.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXTCC_Auth_Bindings_Store' ) ) {
	require_once __DIR__ . '/class-nxtcc-auth-bindings-store.php';
}

/**
 * Check whether a user has a verified WhatsApp number.
 *
 * @param int $user_id User ID.
 * @return bool True when verified.
 */
function nxtcc_is_user_whatsapp_verified( $user_id ): bool {
	return NXTCC_Auth_Bindings_Store::is_user_verified( (int) $user_id );
}

/**
 * Get the user's verified WhatsApp number in E.164 format.
 *
 * @param int $user_id User ID.
 * @return string E.164 number or empty string.
 */
function nxtcc_get_user_phone_e164( $user_id ): string {
	return NXTCC_Auth_Bindings_Store::latest_verified_e164( (int) $user_id );
}

/**
 * Register the read-only contact method label.
 *
 * @param array $methods Contact methods.
 * @return array Updated methods.
 */
function nxtcc_verified_whatsapp_register_contact_method( array $methods ): array {
	$label = 'Verified WhatsApp number';

	if ( did_action( 'init' ) && function_exists( '__' ) ) {
		$label = __( 'Verified WhatsApp number', 'nxt-cloud-chat' );
	}

	$methods['nxtcc_verified_whatsapp'] = $label;

	return $methods;
}
add_filter( 'user_contactmethods', 'nxtcc_verified_whatsapp_register_contact_method', 99 );

/**
 * Provide a virtual value for the "nxtcc_verified_whatsapp" meta key.
 *
 * WordPress reads contact methods through usermeta. This filter supplies the
 * value from the bindings table without storing anything in usermeta.
 *
 * @param mixed  $value     Current value (short-circuit).
 * @param int    $object_id User ID.
 * @param string $meta_key  Meta key.
 * @param bool   $single    Whether a single value is requested.
 * @return mixed Filtered value.
 */
function nxtcc_verified_whatsapp_get_user_metadata( $value, $object_id, $meta_key, $single ) {
	if ( 'nxtcc_verified_whatsapp' !== $meta_key ) {
		return $value;
	}

	$e164 = nxtcc_get_user_phone_e164( (int) $object_id );

	if ( $single ) {
		return (string) $e164;
	}

	return ( '' === $e164 ) ? array() : array( (string) $e164 );
}
add_filter( 'get_user_metadata', 'nxtcc_verified_whatsapp_get_user_metadata', 10, 4 );

/**
 * Block writes to the virtual "nxtcc_verified_whatsapp" meta key.
 *
 * Returning true short-circuits the update and reports success to WordPress.
 *
 * @param mixed  $check      Short-circuit value.
 * @param int    $user_id    User ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * @return mixed Short-circuit value.
 */
function nxtcc_verified_whatsapp_pre_update_user_metadata( $check, $user_id, $meta_key, $meta_value ) {
	// WordPress filter signature requires this parameter.
	unset( $meta_value );

	if ( 'nxtcc_verified_whatsapp' === $meta_key ) {
		return true;
	}

	return $check;
}
add_filter( 'pre_update_user_metadata', 'nxtcc_verified_whatsapp_pre_update_user_metadata', 10, 4 );

/**
 * Block deletes for the virtual "nxtcc_verified_whatsapp" meta key.
 *
 * Returning true short-circuits the delete and reports success to WordPress.
 *
 * @param mixed  $check      Short-circuit value.
 * @param int    $user_id    User ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * @param bool   $delete_all Whether to delete all values.
 * @return mixed Short-circuit value.
 */
function nxtcc_verified_whatsapp_pre_delete_user_metadata( $check, $user_id, $meta_key, $meta_value, $delete_all ) {
	// WordPress filter signature requires these parameters.
	unset( $meta_value, $delete_all );

	if ( 'nxtcc_verified_whatsapp' === $meta_key ) {
		return true;
	}

	return $check;
}
add_filter( 'pre_delete_user_metadata', 'nxtcc_verified_whatsapp_pre_delete_user_metadata', 10, 5 );

/**
 * Enqueue admin CSS/JS to make the contact field read-only in profile screens.
 *
 * Uses WP enqueue + inline helpers.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function nxtcc_verified_whatsapp_enqueue_profile_lock( string $hook ): void {
	if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return;
	}

	/*
	 * Register "empty" handles so we can attach inline style/script safely.
	 */
	wp_register_style( 'nxtcc-profile-whatsapp', false, array(), '1.0.0' );
	wp_enqueue_style( 'nxtcc-profile-whatsapp' );

	$css = 'input[name="nxtcc_verified_whatsapp"]{background:#f6f7f7!important;color:#1d2327!important;border-color:#dcdcde!important;pointer-events:none!important;}';
	wp_add_inline_style( 'nxtcc-profile-whatsapp', $css );

	wp_register_script( 'nxtcc-profile-whatsapp', false, array(), '1.0.0', true );
	wp_enqueue_script( 'nxtcc-profile-whatsapp' );

	$placeholder = __( 'Not verified yet', 'nxt-cloud-chat' );

	$js = '(function(){document.addEventListener("DOMContentLoaded",function(){var input=document.querySelector(\'input[name="nxtcc_verified_whatsapp"]\');if(!input){return;}input.setAttribute("readonly","readonly");input.setAttribute("disabled","disabled");if(!input.value){input.placeholder=' . wp_json_encode( $placeholder ) . ';}});})();';
	wp_add_inline_script( 'nxtcc-profile-whatsapp', $js );
}
add_action( 'admin_enqueue_scripts', 'nxtcc_verified_whatsapp_enqueue_profile_lock' );
