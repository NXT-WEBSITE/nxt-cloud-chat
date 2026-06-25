<?php
/**
 * Normalize inbound Meta interactive messages for storage and integrations.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXTCC_Interactive_Messages' ) ) {
	/**
	 * Parser for button, list, and Flow replies received through Meta webhooks.
	 */
	final class NXTCC_Interactive_Messages {

		private const MAX_RESPONSE_BYTES = 65536;
		private const MAX_HISTORY_BYTES  = 131072;
		private const MAX_DEPTH          = 8;
		private const MAX_FIELDS         = 100;
		private const MAX_KEY_LENGTH     = 191;
		private const MAX_VALUE_LENGTH   = 4000;
		private const MAX_TOTAL_LENGTH   = 32768;

		/**
		 * Determine whether a webhook message is a Flow response.
		 *
		 * @param array $message Meta webhook message.
		 * @return bool
		 */
		public static function is_flow_response( array $message ): bool {
			if ( 'interactive' !== sanitize_key( (string) ( $message['type'] ?? '' ) ) ) {
				return false;
			}

			$interactive = isset( $message['interactive'] ) && is_array( $message['interactive'] )
				? $message['interactive']
				: array();

			return 'nfm_reply' === sanitize_key( (string) ( $interactive['type'] ?? '' ) )
				&& isset( $interactive['nfm_reply'] )
				&& is_array( $interactive['nfm_reply'] );
		}

		/**
		 * Normalize an inbound interactive webhook message.
		 *
		 * @param array $message Meta webhook message.
		 * @return array<string, mixed>
		 */
		public static function parse( array $message ): array {
			if ( 'interactive' !== sanitize_key( (string) ( $message['type'] ?? '' ) ) ) {
				return array();
			}

			$interactive = isset( $message['interactive'] ) && is_array( $message['interactive'] )
				? $message['interactive']
				: array();
			$type        = sanitize_key( (string) ( $interactive['type'] ?? '' ) );

			if ( 'nfm_reply' === $type ) {
				return self::parse_flow_reply( $interactive );
			}

			if ( in_array( $type, array( 'button_reply', 'list_reply' ), true ) ) {
				return self::parse_selection_reply( $interactive, $type );
			}

			return array(
				'kind'              => 'interactive_reply',
				'interactive_type'  => '' !== $type ? $type : 'unknown',
				'summary'           => __( 'Interactive response received', 'nxt-cloud-chat' ),
				'interactive_reply' => array(
					'type'        => '' !== $type ? $type : 'unknown',
					'title'       => __( 'Interactive response', 'nxt-cloud-chat' ),
					'description' => '',
				),
				'content'           => array(
					'kind'             => 'interactive_reply',
					'interactive_type' => '' !== $type ? $type : 'unknown',
					'title'            => __( 'Interactive response', 'nxt-cloud-chat' ),
					'description'      => '',
				),
			);
		}

		/**
		 * Normalize an interactive message from a history row.
		 *
		 * @param object|array $history Message-history row.
		 * @return array<string, mixed>
		 */
		public static function from_history( $history ): array {
			$response_json = self::read_history_value( $history, 'response_json' );

			if ( '' !== $response_json && strlen( $response_json ) <= self::MAX_HISTORY_BYTES ) {
				$decoded = json_decode( $response_json, true, self::MAX_DEPTH );
				if ( is_array( $decoded ) ) {
					$parsed = self::parse( $decoded );
					if ( ! empty( $parsed ) ) {
						return $parsed;
					}
				}
			}

			$message_content = self::read_history_value( $history, 'message_content' );
			if (
				'' === $message_content
				|| strlen( $message_content ) > self::MAX_HISTORY_BYTES
				|| '{' !== substr( ltrim( $message_content ), 0, 1 )
			) {
				return array();
			}

			$decoded = json_decode( $message_content, true, self::MAX_DEPTH );
			if ( ! is_array( $decoded ) ) {
				return array();
			}

			return self::normalize_stored_content( $decoded );
		}

		/**
		 * Parse a Flow reply.
		 *
		 * @param array $interactive Interactive webhook node.
		 * @return array<string, mixed>
		 */
		private static function parse_flow_reply( array $interactive ): array {
			$reply = isset( $interactive['nfm_reply'] ) && is_array( $interactive['nfm_reply'] )
				? $interactive['nfm_reply']
				: array();
			$body  = self::clean_text( $reply['body'] ?? '', 500 );
			$name  = self::clean_text( $reply['name'] ?? '', 255 );
			$title = __( 'Flow Response', 'nxt-cloud-chat' );

			$decoded   = self::decode_flow_response( $reply['response_json'] ?? '' );
			$fields    = array();
			$state     = array(
				'count'        => 0,
				'total_length' => 0,
			);
			$malformed = false;

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $key => $value ) {
					if ( self::is_technical_key( (string) $key ) ) {
						continue;
					}

					self::flatten_value( $value, (string) $key, $fields, 0, $state );
					if ( $state['count'] >= self::MAX_FIELDS || $state['total_length'] >= self::MAX_TOTAL_LENGTH ) {
						break;
					}
				}
			} else {
				$malformed = true;
			}

			$count   = count( $fields );
			$summary = 1 === $count
				? __( 'Flow response submitted (1 answer)', 'nxt-cloud-chat' )
				: sprintf(
					/* translators: %d: Number of submitted Flow answers. */
					__( 'Flow response submitted (%d answers)', 'nxt-cloud-chat' ),
					$count
				);

			if ( $malformed ) {
				$summary = __( 'Flow response submitted', 'nxt-cloud-chat' );
			}

			$flow_response = array(
				'title'        => $title,
				'body'         => $body,
				'name'         => $name,
				'answer_count' => $count,
				'fields'       => $fields,
				'malformed'    => $malformed,
			);

			return array(
				'kind'             => 'flow_response',
				'interactive_type' => 'nfm_reply',
				'summary'          => $summary,
				'flow_response'    => $flow_response,
				'content'          => array(
					'kind'             => 'flow_response',
					'interactive_type' => 'nfm_reply',
					'title'            => $title,
					'body'             => $body,
					'name'             => $name,
					'answer_count'     => $count,
					'fields'           => $fields,
					'malformed'        => $malformed,
					'text'             => $summary,
				),
			);
		}

		/**
		 * Parse a button or list reply.
		 *
		 * @param array  $interactive Interactive webhook node.
		 * @param string $type        Reply type.
		 * @return array<string, mixed>
		 */
		private static function parse_selection_reply( array $interactive, string $type ): array {
			$reply       = isset( $interactive[ $type ] ) && is_array( $interactive[ $type ] )
				? $interactive[ $type ]
				: array();
			$title       = self::clean_text( $reply['title'] ?? '', 500 );
			$description = self::clean_text( $reply['description'] ?? '', 1000 );
			$reply_id    = self::clean_text( $reply['id'] ?? '', self::MAX_KEY_LENGTH );

			if ( '' === $title ) {
				$title = 'button_reply' === $type
					? __( 'Button response', 'nxt-cloud-chat' )
					: __( 'List response', 'nxt-cloud-chat' );
			}

			$normalized_reply = array(
				'type'        => $type,
				'title'       => $title,
				'description' => $description,
				'id'          => $reply_id,
			);

			return array(
				'kind'              => 'interactive_reply',
				'interactive_type'  => $type,
				'summary'           => $title,
				'interactive_reply' => $normalized_reply,
				'content'           => array(
					'kind'             => 'interactive_reply',
					'interactive_type' => $type,
					'title'            => $title,
					'description'      => $description,
					'reply_id'         => $reply_id,
					'text'             => $title,
				),
			);
		}

		/**
		 * Decode Meta's response_json value.
		 *
		 * @param mixed $response_json Encoded or decoded response.
		 * @return array|null
		 */
		private static function decode_flow_response( $response_json ): ?array {
			if ( is_array( $response_json ) ) {
				return $response_json;
			}

			if ( ! is_string( $response_json ) ) {
				return null;
			}

			$response_json = trim( $response_json );
			if ( '' === $response_json || strlen( $response_json ) > self::MAX_RESPONSE_BYTES ) {
				return null;
			}

			$decoded = json_decode( $response_json, true, self::MAX_DEPTH );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * Flatten a response value into display-safe fields.
		 *
		 * @param mixed  $value  Field value.
		 * @param string $path   Field path.
		 * @param array  $fields Normalized fields.
		 * @param int    $depth  Current depth.
		 * @param array  $state  Field and character counters.
		 * @return void
		 */
		private static function flatten_value( $value, string $path, array &$fields, int $depth, array &$state ): void {
			if ( $depth >= self::MAX_DEPTH || $state['count'] >= self::MAX_FIELDS || $state['total_length'] >= self::MAX_TOTAL_LENGTH ) {
				return;
			}

			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					self::append_field( $path, '', 'empty', $fields, $state );
					return;
				}

				if ( self::is_scalar_list( $value ) ) {
					$items = array();
					foreach ( $value as $item ) {
						$items[] = self::scalar_to_text( $item );
					}
					self::append_field( $path, implode( ', ', $items ), 'list', $fields, $state );
					return;
				}

				foreach ( $value as $key => $nested ) {
					if ( self::is_technical_key( (string) $key ) ) {
						continue;
					}

					$child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;
					self::flatten_value( $nested, $child_path, $fields, $depth + 1, $state );

					if ( $state['count'] >= self::MAX_FIELDS || $state['total_length'] >= self::MAX_TOTAL_LENGTH ) {
						break;
					}
				}
				return;
			}

			$type = gettype( $value );
			if ( null === $value ) {
				$type = 'empty';
			}

			self::append_field( $path, self::scalar_to_text( $value ), $type, $fields, $state );
		}

		/**
		 * Append one safe display field.
		 *
		 * @param string $path   Field path.
		 * @param string $value  Display value.
		 * @param string $type   Value type.
		 * @param array  $fields Normalized fields.
		 * @param array  $state  Field and character counters.
		 * @return void
		 */
		private static function append_field( string $path, string $value, string $type, array &$fields, array &$state ): void {
			$source_key = self::clean_text( $path, self::MAX_KEY_LENGTH );
			$label      = self::humanize_key( $source_key );
			$value      = self::clean_text( $value, self::MAX_VALUE_LENGTH );

			$remaining = self::MAX_TOTAL_LENGTH - (int) $state['total_length'];
			if ( $remaining <= 0 ) {
				return;
			}

			$value = self::truncate( $value, $remaining );

			$fields[] = array(
				'key'   => $source_key,
				'label' => $label,
				'value' => $value,
				'type'  => sanitize_key( $type ),
			);

			++$state['count'];
			$state['total_length'] += strlen( $source_key ) + strlen( $label ) + strlen( $value );
		}

		/**
		 * Normalize a previously stored content envelope.
		 *
		 * @param array $content Stored content.
		 * @return array<string, mixed>
		 */
		private static function normalize_stored_content( array $content ): array {
			$kind = sanitize_key( (string) ( $content['kind'] ?? '' ) );

			if ( 'flow_response' === $kind ) {
				$fields = array();
				$state  = array(
					'count'        => 0,
					'total_length' => 0,
				);

				foreach ( (array) ( $content['fields'] ?? array() ) as $field ) {
					if ( ! is_array( $field ) || self::is_technical_key( (string) ( $field['key'] ?? '' ) ) ) {
						continue;
					}

					self::append_field(
						(string) ( $field['key'] ?? $field['label'] ?? '' ),
						(string) ( $field['value'] ?? '' ),
						(string) ( $field['type'] ?? 'string' ),
						$fields,
						$state
					);
				}

				$count         = count( $fields );
				$summary       = self::clean_text( $content['text'] ?? '', 500 );
				$stored_title  = self::clean_text( $content['title'] ?? '', 500 );
				$stored_body   = self::clean_text( $content['body'] ?? '', 500 );
				$body          = '' !== $stored_body
					? $stored_body
					: ( __( 'Flow Response', 'nxt-cloud-chat' ) !== $stored_title ? $stored_title : '' );
				$flow_response = array(
					'title'        => __( 'Flow Response', 'nxt-cloud-chat' ),
					'body'         => $body,
					'name'         => self::clean_text( $content['name'] ?? '', 255 ),
					'answer_count' => $count,
					'fields'       => $fields,
					'malformed'    => ! empty( $content['malformed'] ),
				);

				if ( '' === $summary ) {
					$summary = sprintf(
						/* translators: %d: Number of submitted Flow answers. */
						__( 'Flow response submitted (%d answers)', 'nxt-cloud-chat' ),
						$count
					);
				}

				return array(
					'kind'             => 'flow_response',
					'interactive_type' => 'nfm_reply',
					'summary'          => $summary,
					'flow_response'    => $flow_response,
					'content'          => array_merge(
						$flow_response,
						array(
							'kind'             => 'flow_response',
							'interactive_type' => 'nfm_reply',
							'text'             => $summary,
						)
					),
				);
			}

			if ( 'interactive_reply' === $kind ) {
				$type        = sanitize_key( (string) ( $content['interactive_type'] ?? '' ) );
				$title       = self::clean_text( $content['title'] ?? $content['text'] ?? '', 500 );
				$description = self::clean_text( $content['description'] ?? '', 1000 );
				$reply_id    = self::clean_text( $content['reply_id'] ?? '', self::MAX_KEY_LENGTH );

				return array(
					'kind'              => 'interactive_reply',
					'interactive_type'  => '' !== $type ? $type : 'unknown',
					'summary'           => '' !== $title ? $title : __( 'Interactive response received', 'nxt-cloud-chat' ),
					'interactive_reply' => array(
						'type'        => '' !== $type ? $type : 'unknown',
						'title'       => $title,
						'description' => $description,
						'id'          => $reply_id,
					),
					'content'           => $content,
				);
			}

			return array();
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
		 * Check whether an array contains only scalar values.
		 *
		 * @param array $value Candidate list.
		 * @return bool
		 */
		private static function is_scalar_list( array $value ): bool {
			$expected_index = 0;

			foreach ( $value as $key => $item ) {
				if ( $key !== $expected_index ) {
					return false;
				}
				if ( is_array( $item ) || is_object( $item ) || is_resource( $item ) ) {
					return false;
				}

				++$expected_index;
			}

			return true;
		}

		/**
		 * Convert a scalar value into readable text.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function scalar_to_text( $value ): string {
			if ( is_bool( $value ) ) {
				return $value ? __( 'Yes', 'nxt-cloud-chat' ) : __( 'No', 'nxt-cloud-chat' );
			}

			if ( null === $value ) {
				return '';
			}

			if ( is_scalar( $value ) ) {
				return (string) $value;
			}

			return '';
		}

		/**
		 * Hide transport-only Flow keys from normalized output.
		 *
		 * @param string $key Response key.
		 * @return bool
		 */
		private static function is_technical_key( string $key ): bool {
			$parts = preg_split( '/[.]+/', strtolower( trim( $key ) ) );
			$key   = is_array( $parts ) && ! empty( $parts ) ? (string) end( $parts ) : '';

			return in_array( $key, array( 'flow_token', '_flow_token' ), true );
		}

		/**
		 * Convert a response key into a readable label.
		 *
		 * @param string $key Response key.
		 * @return string
		 */
		private static function humanize_key( string $key ): string {
			$key = preg_replace( '/[._-]+/', ' ', $key );
			$key = is_string( $key ) ? trim( preg_replace( '/\s+/', ' ', $key ) ) : '';

			if ( '' === $key ) {
				return __( 'Response', 'nxt-cloud-chat' );
			}

			return ucwords( $key );
		}

		/**
		 * Sanitize and truncate text while preserving new lines and Unicode.
		 *
		 * @param mixed $value  Text value.
		 * @param int   $length Maximum length.
		 * @return string
		 */
		private static function clean_text( $value, int $length ): string {
			if ( ! is_scalar( $value ) && null !== $value ) {
				return '';
			}

			return self::truncate( sanitize_textarea_field( (string) $value ), $length );
		}

		/**
		 * Truncate text with multibyte support when available.
		 *
		 * @param string $value  Text.
		 * @param int    $length Maximum length.
		 * @return string
		 */
		private static function truncate( string $value, int $length ): string {
			$length = max( 0, $length );
			if ( function_exists( 'mb_substr' ) ) {
				return (string) mb_substr( $value, 0, $length );
			}

			return substr( $value, 0, $length );
		}
	}
}
