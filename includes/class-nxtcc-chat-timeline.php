<?php
/**
 * Unified chat activity timeline helpers.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Projects Free-owned CRM activities into the Chat Window.
 */
final class NXTCC_Chat_Timeline {

	/**
	 * Read a bounded activity page for one contact.
	 *
	 * @param int                 $contact_id Contact ID.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param array<string,mixed> $args Cursor arguments.
	 * @return array<string,mixed>
	 */
	public static function get( int $contact_id, array $tenant, array $args = array() ): array {
		$limit     = max( 1, min( 50, absint( $args['limit'] ?? 20 ) ) );
		$before_id = absint( $args['before_activity_id'] ?? 0 );
		$after_id  = absint( $args['after_activity_id'] ?? 0 );
		$rows      = NXTCC_CRM_Activities::instance()->list_for_contact(
			$contact_id,
			$tenant,
			array(
				'limit'     => $limit + 1,
				'before_id' => $before_id,
				'after_id'  => $after_id,
			)
		);
		$has_more  = count( $rows ) > $limit;
		$rows      = array_slice( $rows, 0, $limit );

		if ( 0 === $after_id ) {
			$rows = array_reverse( $rows );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$item = self::format_activity( $row );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'items'              => $items,
			'has_more'           => $has_more,
			'oldest_activity_id' => ! empty( $items ) ? absint( $items[0]['activity_id'] ?? 0 ) : 0,
			'latest_activity_id' => ! empty( $items ) ? absint( $items[ count( $items ) - 1 ]['activity_id'] ?? 0 ) : 0,
		);
	}

	/**
	 * Read an exact activity and a bounded surrounding activity window.
	 *
	 * @param int                 $activity_id Activity ID.
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @param int                 $radius Context radius.
	 * @return array<string,mixed>
	 */
	public static function get_context( int $activity_id, array $tenant, int $radius = 10 ): array {
		$rows  = NXTCC_CRM_Activities::instance()->get_context( $activity_id, $tenant, $radius );
		$items = array();

		foreach ( $rows as $row ) {
			$item = self::format_activity( $row );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'target_activity_id' => $activity_id,
			'contact_id'         => ! empty( $items ) ? absint( $items[0]['contact_id'] ?? 0 ) : 0,
			'items'              => $items,
		);
	}

	/**
	 * Format one activity for browser and integration consumers.
	 *
	 * @param array<string,mixed> $row Activity row.
	 * @return array<string,mixed>
	 */
	public static function format_activity( array $row ): array {
		$activity_id = absint( $row['id'] ?? 0 );
		$contact_id  = absint( $row['contact_id'] ?? 0 );
		if ( $activity_id <= 0 || $contact_id <= 0 ) {
			return array();
		}

		$type     = sanitize_key( (string) ( $row['activity_type'] ?? '' ) );
		$types    = NXTCC_CRM_Activities::instance()->get_activity_types();
		$metadata = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array();
		$summary  = self::summary( $type, $metadata, (string) ( $row['note_content'] ?? '' ) );
		$created  = sanitize_text_field( (string) ( $row['created_at'] ?? '' ) );

		return array(
			'item_type'          => 'internal_note_added' === $type ? 'internal_note' : 'activity',
			'activity_id'        => $activity_id,
			'contact_id'         => $contact_id,
			'conversation_id'    => absint( $row['conversation_id'] ?? 0 ),
			'activity_type'      => $type,
			'activity_label'     => sanitize_text_field( (string) ( $types[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ) ) ),
			'actor_label'        => sanitize_text_field( (string) ( $row['actor_label'] ?? __( 'System', 'nxt-cloud-chat' ) ) ),
			'source'             => sanitize_key( (string) ( $row['source'] ?? '' ) ),
			'summary'            => $summary,
			'created_at_utc'     => $created,
			'created_at_display' => sanitize_text_field( (string) ( $row['created_at_display'] ?? '' ) ),
		);
	}

	/**
	 * Build a safe compact activity summary.
	 *
	 * @param string              $type Activity type.
	 * @param array<string,mixed> $metadata Metadata.
	 * @param string              $note Internal note.
	 * @return string
	 */
	private static function summary( string $type, array $metadata, string $note ): string {
		if ( 'internal_note_added' === $type ) {
			return self::limit( sanitize_textarea_field( $note ), 1000 );
		}

		$parts = array();
		foreach ( array( 'previous_status', 'status', 'previous_priority', 'priority', 'reason' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) && '' !== (string) $metadata[ $key ] ) {
				$parts[] = sanitize_text_field( (string) $metadata[ $key ] );
			}
		}
		if ( isset( $metadata['changed_fields'] ) && is_array( $metadata['changed_fields'] ) ) {
			$fields = array();
			foreach ( array_slice( $metadata['changed_fields'], 0, 10 ) as $field ) {
				if ( is_scalar( $field ) ) {
					$fields[] = str_replace( '_', ' ', sanitize_key( (string) $field ) );
				}
			}
			if ( ! empty( $fields ) ) {
				$parts[] = implode( ', ', $fields );
			}
		}

		return self::limit( implode( ' -> ', array_values( array_unique( $parts ) ) ), 500 );
	}

	/**
	 * Bound text without requiring mbstring.
	 *
	 * @param string $value Text.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function limit( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
