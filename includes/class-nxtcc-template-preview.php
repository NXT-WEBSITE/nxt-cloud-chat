<?php
/**
 * Free-owned sent-template preview snapshots.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXTCC_Template_Preview' ) ) {
	/**
	 * Build and normalize stable chat previews for outbound templates.
	 */
	final class NXTCC_Template_Preview {

		private const MAX_COMPONENTS    = 20;
		private const MAX_BUTTONS       = 10;
		private const MAX_TEXT_LENGTH   = 12000;
		private const MAX_BUTTON_LENGTH = 160;
		private const MAX_JSON_BYTES    = 131072;

		/**
		 * Enrich a history row with a send-time template snapshot.
		 *
		 * Internal context keys beginning with an underscore are removed before
		 * the row is returned for persistence.
		 *
		 * @param array<string, mixed> $data History row.
		 * @return array<string, mixed>
		 */
		public static function normalize_history_row( array $data ): array {
			$template_name = self::clean_text( $data['template_name'] ?? '', 255 );
			$params        = self::normalize_params( $data['_template_preview_params'] ?? $data['template_data'] ?? array() );
			$components    = self::normalize_components( $data['_template_preview_components'] ?? array() );

			unset( $data['_template_preview_params'], $data['_template_preview_components'] );

			if ( '' === $template_name || self::is_template_preview_content( $data['message_content'] ?? '' ) ) {
				return $data;
			}

			if ( empty( $components ) ) {
				$components = self::resolve_template_components(
					self::clean_text( $data['user_mailid'] ?? '', 255 ),
					self::clean_text( $data['business_account_id'] ?? '', 255 ),
					self::clean_text( $data['phone_number_id'] ?? '', 255 ),
					$template_name
				);
			}

			$snapshot = self::build_snapshot(
				array(
					'template_name' => $template_name,
					'template_type' => self::clean_text( $data['template_type'] ?? '', 50 ),
					'language'      => self::read_language( $data['template_data'] ?? array() ),
					'components'    => $components,
					'params'        => $params,
				)
			);

			$encoded = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$data['message_content'] = $encoded;
			}

			return $data;
		}

		/**
		 * Normalize a template preview from a message-history row.
		 *
		 * @param object|array $history Message-history row.
		 * @return array<string, mixed>
		 */
		public static function from_history( $history ): array {
			$content = self::read_history_value( $history, 'message_content' );

			if ( self::is_template_preview_content( $content ) ) {
				$decoded = json_decode( $content, true );
				return is_array( $decoded ) ? self::sanitize_snapshot( $decoded ) : array();
			}

			$template_name = self::read_history_value( $history, 'template_name' );
			if ( '' === $template_name && 0 === strpos( $content, 'Template: ' ) ) {
				$template_name = trim( substr( $content, 10 ) );
				$template_name = preg_replace( '/\s+\([^)]+\)$/', '', $template_name );
				$template_name = is_string( $template_name ) ? $template_name : '';
			}

			if ( '' === $template_name ) {
				return array();
			}

			$row = array(
				'user_mailid'         => self::read_history_value( $history, 'user_mailid' ),
				'business_account_id' => self::read_history_value( $history, 'business_account_id' ),
				'phone_number_id'     => self::read_history_value( $history, 'phone_number_id' ),
				'template_name'       => $template_name,
				'template_type'       => self::read_history_value( $history, 'template_type' ),
				'template_data'       => self::read_history_value( $history, 'template_data' ),
				'message_content'     => $content,
			);
			$row = self::normalize_history_row( $row );

			$normalized = isset( $row['message_content'] ) ? (string) $row['message_content'] : '';
			if ( ! self::is_template_preview_content( $normalized ) ) {
				return array();
			}

			$decoded = json_decode( $normalized, true );
			return is_array( $decoded ) ? self::sanitize_snapshot( $decoded ) : array();
		}

		/**
		 * Build a normalized template preview.
		 *
		 * @param array<string, mixed> $args Preview arguments.
		 * @return array<string, mixed>
		 */
		public static function build_snapshot( array $args ): array {
			$template_name = self::clean_text( $args['template_name'] ?? '', 255 );
			$template_type = strtoupper( self::clean_text( $args['template_type'] ?? '', 50 ) );
			$language      = self::clean_text( $args['language'] ?? '', 50 );
			$components    = self::normalize_components( $args['components'] ?? array() );
			$params        = self::normalize_params( $args['params'] ?? array() );
			$header        = array(
				'type'      => '',
				'text'      => '',
				'media_url' => '',
				'filename'  => '',
			);
			$body          = '';
			$footer        = '';
			$buttons       = array();

			foreach ( array_slice( $components, 0, self::MAX_COMPONENTS ) as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}

				$type = strtoupper( self::clean_text( $component['type'] ?? '', 30 ) );

				if ( 'HEADER' === $type ) {
					$header = self::build_header( $component, $params );
				} elseif ( 'BODY' === $type ) {
					$body = self::resolve_text(
						self::clean_text( $component['text'] ?? '', self::MAX_TEXT_LENGTH ),
						$params,
						'body'
					);
				} elseif ( 'FOOTER' === $type ) {
					$footer = self::clean_text( $component['text'] ?? '', 1000 );
				} elseif ( 'BUTTONS' === $type ) {
					$buttons = self::build_buttons( $component );
				}
			}

			if ( '' === $body && 'AUTHENTICATION' === $template_type ) {
				$code = self::first_param( $params, array( 'body_var_1', 'code', 'otp' ) );
				$body = '' !== $code
					? sprintf(
						/* translators: %s: One-time verification code. */
						__( '%s is your verification code.', 'nxt-cloud-chat' ),
						$code
					)
					: __( 'Authentication message sent.', 'nxt-cloud-chat' );

				if ( empty( $buttons ) ) {
					$buttons[] = array(
						'type' => 'copy_code',
						'text' => __( 'Copy code', 'nxt-cloud-chat' ),
					);
				}
			}

			if ( '' === $body ) {
				$body = sprintf(
					/* translators: %s: Template name. */
					__( 'Template: %s', 'nxt-cloud-chat' ),
					$template_name
				);
			}

			return self::sanitize_snapshot(
				array(
					'kind'          => 'template_preview',
					'template_name' => $template_name,
					'template_type' => strtolower( $template_type ),
					'language'      => $language,
					'header'        => $header,
					'body'          => $body,
					'footer'        => $footer,
					'buttons'       => $buttons,
				)
			);
		}

		/**
		 * Build a header preview.
		 *
		 * @param array<string, mixed>  $component Header component.
		 * @param array<string, string> $params   Resolved parameters.
		 * @return array<string, string>
		 */
		private static function build_header( array $component, array $params ): array {
			$format = strtolower( self::clean_text( $component['format'] ?? '', 30 ) );
			$header = array(
				'type'      => $format,
				'text'      => '',
				'media_url' => '',
				'filename'  => '',
			);

			if ( 'text' === $format ) {
				$header['text'] = self::resolve_text(
					self::clean_text( $component['text'] ?? '', 1000 ),
					$params,
					'header'
				);
				return $header;
			}

			if ( in_array( $format, array( 'image', 'video', 'document' ), true ) ) {
				$media = self::first_param(
					$params,
					array(
						'header_' . $format,
						'header_media',
						'header_link',
					)
				);

				if ( '' === $media && isset( $component['example']['header_handle'][0] ) ) {
					$media = (string) $component['example']['header_handle'][0];
				}

				if ( filter_var( $media, FILTER_VALIDATE_URL ) ) {
					$header['media_url'] = esc_url_raw( $media );
					$path                = wp_parse_url( $media, PHP_URL_PATH );
					$header['filename']  = is_string( $path ) ? sanitize_file_name( basename( $path ) ) : '';
				}
			}

			return $header;
		}

		/**
		 * Build non-interactive preview buttons.
		 *
		 * @param array<string, mixed> $component Buttons component.
		 * @return array<int, array<string, string>>
		 */
		private static function build_buttons( array $component ): array {
			$buttons = isset( $component['buttons'] ) && is_array( $component['buttons'] )
				? array_slice( $component['buttons'], 0, self::MAX_BUTTONS )
				: array();
			$out     = array();

			foreach ( $buttons as $button ) {
				if ( ! is_array( $button ) ) {
					continue;
				}

				$text = self::clean_text( $button['text'] ?? '', self::MAX_BUTTON_LENGTH );
				$type = strtolower( self::clean_text( $button['type'] ?? '', 40 ) );
				if ( '' === $text ) {
					continue;
				}

				$out[] = array(
					'type' => $type,
					'text' => $text,
				);
			}

			return $out;
		}

		/**
		 * Resolve placeholders in component text.
		 *
		 * @param string                $text   Component text.
		 * @param array<string, string> $params Resolved parameters.
		 * @param string                $scope  Component scope.
		 * @return string
		 */
		private static function resolve_text( string $text, array $params, string $scope ): string {
			if ( '' === $text || empty( $params ) ) {
				return $text;
			}

			$resolved = preg_replace_callback(
				'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
				static function ( array $matches ) use ( $params, $scope ): string {
					$token      = isset( $matches[1] ) ? (string) $matches[1] : '';
					$candidates = array(
						$scope . '_var_' . $token,
						$scope . '_' . $token,
						$token,
					);

					foreach ( $candidates as $candidate ) {
						$key = sanitize_key( $candidate );
						if ( isset( $params[ $key ] ) && '' !== $params[ $key ] ) {
							return $params[ $key ];
						}
					}

					return isset( $matches[0] ) ? (string) $matches[0] : '';
				},
				$text
			);

			return self::clean_text( is_string( $resolved ) ? $resolved : $text, self::MAX_TEXT_LENGTH );
		}

		/**
		 * Resolve locally cached template components.
		 *
		 * @param string $user_mailid         Tenant owner email.
		 * @param string $business_account_id Business account id.
		 * @param string $phone_number_id     Phone number id.
		 * @param string $template_name       Template name.
		 * @return array<int, array<string, mixed>>
		 */
		private static function resolve_template_components(
			string $user_mailid,
			string $business_account_id,
			string $phone_number_id,
			string $template_name
		): array {
			global $wpdb;

			if ( '' === $user_mailid || '' === $phone_number_id || '' === $template_name ) {
				return array();
			}

			$table     = $wpdb->prefix . 'nxtcc_templates';
			$cache_key = 'preview_components:' . md5( implode( '|', array( $user_mailid, $business_account_id, $phone_number_id, $template_name ) ) );
			$cached    = wp_cache_get( $cache_key, 'nxtcc_template_preview' );

			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached table-existence check for an optional plugin-owned table.
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $table_exists ) {
				wp_cache_set( $cache_key, array(), 'nxtcc_template_preview', 300 );
				return array();
			}

			if ( '' !== $business_account_id ) {
				$query = $wpdb->prepare(
					'SELECT components FROM %i
					WHERE user_mailid = %s
						AND business_account_id = %s
						AND phone_number_id = %s
						AND template_name = %s
					ORDER BY id DESC
					LIMIT 1',
					$table,
					$user_mailid,
					$business_account_id,
					$phone_number_id,
					$template_name
				);
			} else {
				$query = $wpdb->prepare(
					'SELECT components FROM %i
					WHERE user_mailid = %s
						AND phone_number_id = %s
						AND template_name = %s
					ORDER BY id DESC
					LIMIT 1',
					$table,
					$user_mailid,
					$phone_number_id,
					$template_name
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared in the tenant-specific branch above and the result is cached.
			$components_json = $wpdb->get_var( $query );
			$components      = self::normalize_components( $components_json );

			wp_cache_set( $cache_key, $components, 'nxtcc_template_preview', 300 );
			return $components;
		}

		/**
		 * Normalize template components.
		 *
		 * @param mixed $components Components.
		 * @return array<int, array<string, mixed>>
		 */
		private static function normalize_components( $components ): array {
			if ( is_string( $components ) ) {
				if ( strlen( $components ) > self::MAX_JSON_BYTES ) {
					return array();
				}
				$components = json_decode( $components, true );
			}

			return is_array( $components ) ? array_values( $components ) : array();
		}

		/**
		 * Normalize template parameters.
		 *
		 * @param mixed $params Parameters.
		 * @return array<string, string>
		 */
		private static function normalize_params( $params ): array {
			if ( is_string( $params ) ) {
				if ( strlen( $params ) > self::MAX_JSON_BYTES ) {
					return array();
				}
				$params = json_decode( $params, true );
			}

			if ( ! is_array( $params ) ) {
				return array();
			}

			if ( isset( $params['kind'] ) && 'template_preview' === $params['kind'] ) {
				return array();
			}

			$out = array();
			foreach ( $params as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$value = implode( ', ', self::scalar_values( $value ) );
				}

				if ( ! is_scalar( $value ) && null !== $value ) {
					continue;
				}

				$out[ $key ] = self::clean_text( $value, self::MAX_TEXT_LENGTH );
			}

			return $out;
		}

		/**
		 * Convert a bounded list of scalar values to strings.
		 *
		 * @param array<mixed> $values Raw values.
		 * @return array<int, string>
		 */
		private static function scalar_values( array $values ): array {
			$out = array();

			foreach ( array_slice( $values, 0, 100 ) as $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$out[] = (string) $value;
				}
			}

			return $out;
		}

		/**
		 * Sanitize a snapshot for browser and integration use.
		 *
		 * @param array<string, mixed> $snapshot Snapshot.
		 * @return array<string, mixed>
		 */
		private static function sanitize_snapshot( array $snapshot ): array {
			$header  = isset( $snapshot['header'] ) && is_array( $snapshot['header'] ) ? $snapshot['header'] : array();
			$buttons = isset( $snapshot['buttons'] ) && is_array( $snapshot['buttons'] ) ? $snapshot['buttons'] : array();
			$out     = array(
				'kind'          => 'template_preview',
				'template_name' => self::clean_text( $snapshot['template_name'] ?? '', 255 ),
				'template_type' => sanitize_key( (string) ( $snapshot['template_type'] ?? '' ) ),
				'language'      => self::clean_text( $snapshot['language'] ?? '', 50 ),
				'header'        => array(
					'type'      => sanitize_key( (string) ( $header['type'] ?? '' ) ),
					'text'      => self::clean_text( $header['text'] ?? '', 1000 ),
					'media_url' => esc_url_raw( (string) ( $header['media_url'] ?? '' ) ),
					'filename'  => sanitize_file_name( (string) ( $header['filename'] ?? '' ) ),
				),
				'body'          => self::clean_text( $snapshot['body'] ?? '', self::MAX_TEXT_LENGTH ),
				'footer'        => self::clean_text( $snapshot['footer'] ?? '', 1000 ),
				'buttons'       => array(),
			);

			foreach ( array_slice( $buttons, 0, self::MAX_BUTTONS ) as $button ) {
				if ( ! is_array( $button ) ) {
					continue;
				}

				$text = self::clean_text( $button['text'] ?? '', self::MAX_BUTTON_LENGTH );
				if ( '' === $text ) {
					continue;
				}

				$out['buttons'][] = array(
					'type' => sanitize_key( (string) ( $button['type'] ?? '' ) ),
					'text' => $text,
				);
			}

			return $out;
		}

		/**
		 * Read a language code from template data.
		 *
		 * @param mixed $template_data Template data.
		 * @return string
		 */
		private static function read_language( $template_data ): string {
			if ( is_string( $template_data ) ) {
				if ( strlen( $template_data ) > self::MAX_JSON_BYTES ) {
					return '';
				}
				$template_data = json_decode( $template_data, true );
			}

			return is_array( $template_data )
				? self::clean_text( $template_data['language'] ?? '', 50 )
				: '';
		}

		/**
		 * Read the first available parameter.
		 *
		 * @param array<string, string> $params Parameters.
		 * @param array<int, string>    $keys   Candidate keys.
		 * @return string
		 */
		private static function first_param( array $params, array $keys ): string {
			foreach ( $keys as $key ) {
				$key = sanitize_key( $key );
				if ( isset( $params[ $key ] ) && '' !== $params[ $key ] ) {
					return $params[ $key ];
				}
			}

			return '';
		}

		/**
		 * Determine whether content is already a preview snapshot.
		 *
		 * @param mixed $content Message content.
		 * @return bool
		 */
		private static function is_template_preview_content( $content ): bool {
			if (
				! is_string( $content )
				|| strlen( $content ) > self::MAX_JSON_BYTES
				|| '{' !== substr( ltrim( $content ), 0, 1 )
			) {
				return false;
			}

			$decoded = json_decode( $content, true );
			return is_array( $decoded ) && 'template_preview' === ( $decoded['kind'] ?? '' );
		}

		/**
		 * Read a string value from a history row.
		 *
		 * @param object|array $history History row.
		 * @param string       $key     Column name.
		 * @return string
		 */
		private static function read_history_value( $history, string $key ): string {
			if ( is_object( $history ) && isset( $history->{$key} ) ) {
				return (string) $history->{$key};
			}

			if ( is_array( $history ) && isset( $history[ $key ] ) ) {
				return (string) $history[ $key ];
			}

			return '';
		}

		/**
		 * Sanitize and truncate text.
		 *
		 * @param mixed $value  Text value.
		 * @param int   $length Maximum length.
		 * @return string
		 */
		private static function clean_text( $value, int $length ): string {
			if ( ! is_scalar( $value ) && null !== $value ) {
				return '';
			}

			$value = sanitize_textarea_field( (string) $value );
			if ( function_exists( 'mb_substr' ) ) {
				return (string) mb_substr( $value, 0, $length );
			}

			return substr( $value, 0, $length );
		}
	}
}
