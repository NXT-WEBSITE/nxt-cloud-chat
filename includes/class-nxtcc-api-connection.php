<?php
/**
 * WhatsApp Cloud API connection diagnostics.
 *
 * Provides connection validation (based on saved settings) and a diagnostics routine
 * that checks WABA profile, templates list, phone number profile, and optionally
 * sends a test template message.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'nxtcc-remote.php';

/**
 * Handles WhatsApp Cloud API connection logic and diagnostics.
 */
final class NXTCC_API_Connection {

	/**
	 * Validate the WhatsApp API connection using the latest saved settings row.
	 *
	 * This is a lightweight validation: it ensures required identifiers exist and
	 * an encrypted access token is present in storage.
	 *
	 * @return bool True when required settings exist; otherwise false.
	 */
	public static function validate_connection(): bool {
		// Hard-guard in case the repo class is not loaded yet.
		if ( ! class_exists( 'NXTCC_User_Settings_Repo' ) || ! method_exists( 'NXTCC_User_Settings_Repo', 'instance' ) ) {
			return false;
		}

		$repo = NXTCC_User_Settings_Repo::instance();
		if ( ! $repo || ! method_exists( $repo, 'get_latest_connection_row' ) ) {
			return false;
		}

		$row = $repo->get_latest_connection_row();

		return (bool) (
			$row
			&& ! empty( $row->app_id )
			&& ! empty( $row->access_token_ct )
			&& ! empty( $row->access_token_nonce )
			&& ! empty( $row->business_account_id )
			&& ! empty( $row->phone_number_id )
		);
	}

	/**
	 * Run diagnostics against Meta Graph endpoints.
	 *
	 * Note: Caller must supply the decrypted $access_token.
	 *
	 * @param string      $app_id              Meta App ID.
	 * @param string      $access_token        Decrypted access token.
	 * @param string      $business_account_id WhatsApp Business Account ID (WABA).
	 * @param string      $phone_number_id     WhatsApp Phone Number ID.
	 * @param string|null $test_number         Optional E.164 number for a test send.
	 * @param string|null $test_template       Optional template name for a test send.
	 * @param string      $test_language       Template language code.
	 * @return array<string, array> Results keyed by check name.
	 */
	public static function check_all_connections(
		$app_id,
		$access_token,
		$business_account_id,
		$phone_number_id,
		$test_number = null,
		$test_template = null,
		$test_language = 'en_US'
	): array {
		$app_id              = (string) $app_id;
		$access_token        = (string) $access_token;
		$business_account_id = (string) $business_account_id;
		$phone_number_id     = (string) $phone_number_id;
		$test_number         = null !== $test_number ? (string) $test_number : null;
		$test_template       = null !== $test_template ? (string) $test_template : null;
		$test_language       = (string) $test_language;

		$results = array();

		// Basic input sanity (avoid pointless remote calls).
		if ( '' === $business_account_id ) {
			$results['WABA Profile'] = array(
				'success' => false,
				'error'   => 'Missing business_account_id.',
			);
			return $results;
		}
		if ( '' === $phone_number_id ) {
			$results['Phone Number Profile'] = array(
				'success' => false,
				'error'   => 'Missing phone_number_id.',
			);
			return $results;
		}
		if ( '' === $access_token ) {
			$results['Access Token'] = array(
				'success' => false,
				'error'   => 'Missing access token.',
			);
			return $results;
		}

		// 1) WABA Profile.
		$url                     = 'https://graph.facebook.com/v19.0/' . rawurlencode( $business_account_id );
		$response                = nxtcc_safe_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 3,
			)
		);
		$results['WABA Profile'] = self::format_result( 'WABA Profile', $url, $response );

		// 2) Templates List.
		$url                       = 'https://graph.facebook.com/v19.0/' . rawurlencode( $business_account_id ) . '/message_templates';
		$response                  = nxtcc_safe_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 3,
			)
		);
		$results['Templates List'] = self::format_result( 'Templates List', $url, $response );

		// 3) Phone Number Profile.
		$url                             = 'https://graph.facebook.com/v19.0/' . rawurlencode( $phone_number_id );
		$response                        = nxtcc_safe_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 3,
			)
		);
		$results['Phone Number Profile'] = self::format_result( 'Phone Number Profile', $url, $response );

		// 4) Optional: Send a template message to confirm messaging permissions and template validity.
		if ( $test_number && $test_template ) {
			$url     = 'https://graph.facebook.com/v19.0/' . rawurlencode( $phone_number_id ) . '/messages';
			$payload = array(
				'messaging_product' => 'whatsapp',
				'to'                => $test_number,
				'type'              => 'template',
				'template'          => array(
					'name'     => $test_template,
					'language' => array( 'code' => $test_language ),
				),
			);

			$response = nxtcc_safe_remote_post(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 3,
				)
			);

			$results['Send Test Message'] = self::format_result( 'Send Test Message', $url, $response );
		}

		return $results;
	}

	/**
	 * Normalize an HTTP response into a compact success/error structure.
	 *
	 * @param string          $label    Human readable label for this check.
	 * @param string          $url      Endpoint URL used.
	 * @param array|\WP_Error $response HTTP API response.
	 * @return array{success: bool, error?: string}
	 */
	private static function format_result( string $label, string $url, $response ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// If Meta returns non-2xx, treat it as an error even if body isn't empty.
		if ( 200 > $code || 300 <= $code ) {
			$message = $label . ' returned HTTP ' . $code . '.';

			$data = json_decode( $body, true );
			if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
				if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) && '' !== $data['error']['message'] ) {
					$message = $data['error']['message'];
				}
			}

			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		if ( '' === $body ) {
			return array(
				'success' => false,
				'error'   => $label . ' returned an empty response body.',
			);
		}

		$data = json_decode( $body, true );

		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$error   = $data['error'];
			$message = 'Meta error.';

			if ( is_array( $error ) && isset( $error['message'] ) && is_string( $error['message'] ) && '' !== $error['message'] ) {
				$message = $error['message'];
			}

			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		return array( 'success' => true );
	}
}
