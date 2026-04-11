<?php
/**
 * Admin settings controller.
 *
 * Handles:
 * - Registering plugin settings.
 * - Rendering the admin settings page (saving tenant connection settings).
 * - AJAX actions for generating webhook verify token and checking connections.
 *
 * This file contains only the controller class to satisfy PHPCS rules.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main admin settings controller.
 */
final class NXTCC_Admin_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_nxtcc_generate_webhook_token', array( __CLASS__, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_nxtcc_check_connections', array( __CLASS__, 'ajax_check_connections' ) );
	}

	/**
	 * Register plain WP option(s).
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'nxtcc_settings_group',
			'nxtcc_delete_data_on_uninstall',
			array(
				'type'              => 'integer',
				'sanitize_callback' => static function ( $value ) {
					return ! empty( $value ) ? 1 : 0;
				},
				'default'           => 0,
			)
		);
	}

	/**
	 * Load helper/DAO dependencies for settings workflows.
	 *
	 * This keeps dependency loading centralized and prevents repeated requires.
	 *
	 * @return void
	 */
	private static function load_helpers(): void {
		$helpers_class_file = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
		if ( file_exists( $helpers_class_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
		}

		$helpers_funcs_file = NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
		if ( file_exists( $helpers_funcs_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
		}

		$dao_class_file = NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';
		if ( file_exists( $dao_class_file ) ) {
			require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';
		}

		if ( class_exists( 'NXTCC_DAO' ) ) {
			NXTCC_DAO::init();
		}
	}

	/**
	 * Check if POST key exists.
	 *
	 * Uses a sanitizing filter to avoid using raw superglobals.
	 *
	 * @param string $key Key name.
	 * @return bool True when present.
	 */
	private static function post_has( string $key ): bool {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ( null !== $value );
	}

	/**
	 * Read a sanitized text field from POST.
	 *
	 * @param string $key Key name.
	 * @return string Sanitized value.
	 */
	private static function post_text( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $value ) {
			$value = '';
		}
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Read a sanitized "secret" (token, verify token etc.) from POST.
	 *
	 * This allows a broader safe character set than sanitize_text_field(), while
	 * still stripping tags and control characters.
	 *
	 * @param string $key Key name.
	 * @return string Sanitized secret.
	 */
	private static function post_secret( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $value ) {
			$value = '';
		}

		$value = (string) wp_unslash( $value );
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		$value = preg_replace( '/[^A-Za-z0-9_\-\.\/=\+\~@#:]/u', '', $value );

		return trim( (string) $value );
	}

	/**
	 * AJAX: Generate & persist webhook verify token hash for the tenant.
	 *
	 * POST: nonce, business_account_id, phone_number_id.
	 *
	 * @return void
	 */
	public static function ajax_generate_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$baid = self::post_text( 'business_account_id' );
		$pnid = self::post_text( 'phone_number_id' );

		if ( '' === $baid || '' === $pnid ) {
			wp_send_json_error( array( 'message' => 'Missing tenant identifiers' ), 400 );
		}

		$user        = wp_get_current_user();
		$user_mailid = is_object( $user ) ? sanitize_email( (string) $user->user_email ) : '';
		if ( '' === $user_mailid ) {
			wp_send_json_error( array( 'message' => 'Cannot resolve user' ), 400 );
		}

		$token = 'nxtcc-webhook-verify-' . wp_generate_password( 8, false );
		$hash  = hash( 'sha256', $token );

		$ok = NXTCC_Settings_DAO::upsert_verify_token_hash( $user_mailid, $baid, $pnid, $hash );

		if ( $ok ) {
			wp_send_json_success( array( 'token' => $token ) );
		}

		wp_send_json_error( array( 'message' => 'DB write failed' ), 500 );
	}

	/**
	 * Render settings page HTML.
	 *
	 * Logic only; the view is located at admin/pages/settings-view.php.
	 *
	 * @return void
	 */
	public static function settings_page_html(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user        = wp_get_current_user();
		$user_mailid = is_object( $user ) ? sanitize_email( (string) $user->user_email ) : '';

		if ( self::post_has( 'nxtcc_save_settings' ) ) {
			check_admin_referer( 'nxtcc_settings_save', 'nxtcc_settings_nonce' );

			$app_id              = self::post_text( 'nxtcc_app_id' );
			$access_token_plain  = self::post_secret( 'nxtcc_access_token' );
			$app_secret_plain    = self::post_secret( 'nxtcc_app_secret' );
			$business_account_id = self::post_text( 'nxtcc_whatsapp_business_account_id' );
			$phone_number_id     = self::post_text( 'nxtcc_phone_number_id' );
			$phone_number        = self::post_text( 'nxtcc_phone_number' );
			$verify_token_input  = self::post_secret( 'nxtcc_meta_webhook_verify_token' );

			$webhook_subscribed = self::post_has( 'nxtcc_meta_webhook_subscribed' ) ? 1 : 0;

			$errors = array();
			if ( '' === $app_id ) {
				$errors[] = 'App ID is required.';
			}
			if ( '' === $business_account_id ) {
				$errors[] = 'Business Account ID is required.';
			}
			if ( '' === $phone_number_id ) {
				$errors[] = 'Phone Number ID is required.';
			}

			if ( 1 === (int) $webhook_subscribed && '' !== $user_mailid ) {
				if ( ! NXTCC_Settings_DAO::supports_app_secret_columns() ) {
					$errors[] = 'App Secret storage is not ready. Please refresh the plugin schema and try again.';
				} elseif (
					'' === $app_secret_plain
					&& ! NXTCC_Settings_DAO::has_saved_app_secret_for_tenant( $user_mailid, $business_account_id, $phone_number_id )
				) {
					$errors[] = 'App Secret is required when Webhook (Incoming) is enabled.';
				}
			}

			if ( ! empty( $errors ) ) {
				foreach ( $errors as $msg ) {
					add_settings_error(
						'nxtcc_settings',
						'nxtcc_settings_error_' . md5( (string) $msg ),
						sanitize_text_field( (string) $msg ),
						'error'
					);
				}
			}

			if ( empty( $errors ) && '' !== $user_mailid ) {
				$data = array(
					'user_mailid'             => $user_mailid,
					'app_id'                  => $app_id,
					'business_account_id'     => $business_account_id,
					'phone_number_id'         => $phone_number_id,
					'phone_number'            => $phone_number,
					'meta_webhook_subscribed' => (int) $webhook_subscribed,
				);

				if ( '' !== $access_token_plain || '' !== $app_secret_plain ) {
					self::load_helpers();
				}

				$access_token_save_failed = false;
				$app_secret_save_failed   = false;

				if ( '' !== $access_token_plain ) {
					if ( function_exists( 'nxtcc_crypto_encrypt' ) ) {
						$enc = nxtcc_crypto_encrypt( $access_token_plain );

						if ( is_array( $enc ) && 2 === count( $enc ) ) {
							$ct_b64    = (string) $enc[0];
							$nonce_raw = $enc[1];

							if ( '' !== $ct_b64 && ! empty( $nonce_raw ) ) {
								$data['access_token_ct']    = $ct_b64;
								$data['access_token_nonce'] = $nonce_raw;
							} else {
								$access_token_save_failed = true;
							}
						} else {
							$access_token_save_failed = true;
						}
					} else {
						$access_token_save_failed = true;
					}
				}

				if ( '' !== $app_secret_plain ) {
					if ( ! NXTCC_Settings_DAO::supports_app_secret_columns() ) {
						$app_secret_save_failed = true;
					} elseif ( function_exists( 'nxtcc_crypto_encrypt' ) ) {
						$enc = nxtcc_crypto_encrypt( $app_secret_plain );

						if ( is_array( $enc ) && 2 === count( $enc ) ) {
							$ct_b64    = (string) $enc[0];
							$nonce_raw = $enc[1];

							if ( '' !== $ct_b64 && ! empty( $nonce_raw ) ) {
								$data['app_secret_ct']    = $ct_b64;
								$data['app_secret_nonce'] = $nonce_raw;
							} else {
								$app_secret_save_failed = true;
							}
						} else {
							$app_secret_save_failed = true;
						}
					} else {
						$app_secret_save_failed = true;
					}
				}

				if ( '' !== $access_token_plain && $access_token_save_failed ) {
					add_settings_error(
						'nxtcc_settings',
						'nxtcc_settings_error_access_token_encrypt',
						'Could not securely save Access Token.',
						'error'
					);
				}

				if ( '' !== $app_secret_plain && $app_secret_save_failed ) {
					add_settings_error(
						'nxtcc_settings',
						'nxtcc_settings_error_app_secret_encrypt',
						'Could not securely save App Secret.',
						'error'
					);
				}

				if ( ! $access_token_save_failed && ! $app_secret_save_failed ) {
					if ( '' !== $verify_token_input ) {
						$data['meta_webhook_verify_token_hash'] = hash( 'sha256', $verify_token_input );
					}

					NXTCC_Settings_DAO::upsert_settings( $data );

					update_option(
						'nxtcc_delete_data_on_uninstall',
						self::post_has( 'nxtcc_delete_data_on_uninstall' ) ? 1 : 0
					);
				}
			}
		}

		$settings = null;
		if ( '' !== $user_mailid ) {
			$settings = NXTCC_Settings_DAO::get_latest_for_user( $user_mailid );
		}

		$app_id                  = is_object( $settings ) && isset( $settings->app_id ) ? (string) $settings->app_id : '';
		$business_account_id     = is_object( $settings ) && isset( $settings->business_account_id ) ? (string) $settings->business_account_id : '';
		$phone_number_id         = is_object( $settings ) && isset( $settings->phone_number_id ) ? (string) $settings->phone_number_id : '';
		$phone_number            = is_object( $settings ) && isset( $settings->phone_number ) ? (string) $settings->phone_number : '';
		$meta_webhook_subscribed = is_object( $settings ) && isset( $settings->meta_webhook_subscribed ) ? (int) $settings->meta_webhook_subscribed : 0;
		$callback_url            = esc_url( set_url_scheme( site_url( '/wp-json/nxtcc/v1/webhook/' ), 'https' ) );

		if ( self::post_has( 'nxtcc_sync_templates' ) && '' !== $user_mailid ) {
			self::load_helpers();

			if ( function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
				$creds = nxtcc_get_tenant_api_credentials( $user_mailid, $business_account_id, $phone_number_id );

				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) && function_exists( 'nxtcc_sync_templates_from_meta' ) ) {
					nxtcc_sync_templates_from_meta(
						$user_mailid,
						(string) $creds['access_token'],
						(string) $creds['business_account_id'],
						(string) $creds['phone_number_id']
					);
				}
			}
		}

		include plugin_dir_path( __FILE__ ) . '/../admin/pages/settings-view.php';
	}

	/**
	 * AJAX: Check all connections (server decrypts token).
	 *
	 * @return void
	 */
	public static function ajax_check_connections(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$app_id              = self::post_text( 'app_id' );
		$business_account_id = self::post_text( 'business_account_id' );
		$phone_number_id     = self::post_text( 'phone_number_id' );
		$test_number         = self::post_text( 'test_number' );
		$test_template       = self::post_text( 'test_template' );
		$test_language       = self::post_text( 'test_language' );

		if ( '' === $test_language ) {
			$test_language = 'en_US';
		}

		if ( '' === $app_id || '' === $business_account_id || '' === $phone_number_id ) {
			wp_send_json_error( array( 'message' => 'Missing credentials' ), 400 );
		}

		self::load_helpers();

		require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-user-settings-repo.php';
		require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-api-connection.php';

		$user        = wp_get_current_user();
		$user_mailid = is_object( $user ) ? sanitize_email( (string) $user->user_email ) : '';
		if ( '' === $user_mailid ) {
			wp_send_json_error( array( 'message' => 'Cannot resolve user' ), 400 );
		}

		if ( ! function_exists( 'nxtcc_get_tenant_api_credentials' ) ) {
			wp_send_json_error( array( 'message' => 'Helpers not available' ), 500 );
		}

		$creds = nxtcc_get_tenant_api_credentials( $user_mailid, $business_account_id, $phone_number_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			wp_send_json_error( array( 'message' => 'Access token not found; please save settings' ), 400 );
		}

		$results = NXTCC_API_Connection::check_all_connections(
			$app_id,
			(string) $creds['access_token'],
			$business_account_id,
			$phone_number_id,
			$test_number,
			$test_template,
			$test_language
		);

		wp_send_json_success( array( 'results' => $results ) );
	}
}
