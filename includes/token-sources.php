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

/**
 * Return the public grouped token catalog.
 *
 * Integrations may add namespaces or fields with `nxtcc_token_catalog`.
 *
 * @param array<string,mixed> $args Discovery arguments.
 * @return array<string,array<int,array<string,mixed>>>
 */
function nxtcc_get_token_catalog( array $args = array() ): array {
	$catalog = array(
		'contact'    => array(
			array(
				'key'   => 'contact.name',
				'label' => __( 'Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'contact.country_code',
				'label' => __( 'Country Code', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'contact.phone_number',
				'label' => __( 'Phone Number', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'contact.phone_e164',
				'label' => __( 'Phone E164', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'contact.created_at',
				'label' => __( 'Created At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'contact.updated_at',
				'label' => __( 'Updated At', 'nxt-cloud-chat' ),
			),
		),
		'ticket'     => array(
			array(
				'key'   => 'ticket.number',
				'label' => __( 'Ticket Number', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.subject',
				'label' => __( 'Subject', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.message',
				'label' => __( 'Customer Message', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.category',
				'label' => __( 'Category', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.status',
				'label' => __( 'Status', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.priority',
				'label' => __( 'Priority', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.source',
				'label' => __( 'Source', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.opened_at',
				'label' => __( 'Opened At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.updated_at',
				'label' => __( 'Updated At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.first_response_at',
				'label' => __( 'First Response At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.first_response_due_at',
				'label' => __( 'First Response Due At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.resolution_due_at',
				'label' => __( 'Resolution Due At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.reopen_count',
				'label' => __( 'Reopen Count', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.assignee_name',
				'label' => __( 'Assignee Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'ticket.team_name',
				'label' => __( 'Team Name', 'nxt-cloud-chat' ),
			),
		),
		'assignment' => array(
			array(
				'key'   => 'assignment.type',
				'label' => __( 'Assignment Type', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'assignment.label',
				'label' => __( 'Assigned To', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'assignment.user_id',
				'label' => __( 'Assigned User ID', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'assignment.team_key',
				'label' => __( 'Assigned Team Key', 'nxt-cloud-chat' ),
			),
		),
		'lifecycle'  => array(
			array(
				'key'   => 'lifecycle.name',
				'label' => __( 'Stage Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'lifecycle.slug',
				'label' => __( 'Stage Slug', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'lifecycle.changed_at',
				'label' => __( 'Changed At', 'nxt-cloud-chat' ),
			),
		),
		'task'       => array(
			array(
				'key'   => 'task.title',
				'label' => __( 'Task Title', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'task.status',
				'label' => __( 'Task Status', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'task.priority',
				'label' => __( 'Task Priority', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'task.due_at',
				'label' => __( 'Task Due At', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'task.assignee_name',
				'label' => __( 'Task Assignee', 'nxt-cloud-chat' ),
			),
		),
		'deal'       => array(
			array(
				'key'   => 'deal.title',
				'label' => __( 'Deal Title', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.pipeline_name',
				'label' => __( 'Pipeline Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.stage_name',
				'label' => __( 'Stage Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.value',
				'label' => __( 'Deal Value', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.currency',
				'label' => __( 'Currency', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.reason',
				'label' => __( 'Stage Reason', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'deal.expected_close_at',
				'label' => __( 'Expected Close At', 'nxt-cloud-chat' ),
			),
		),
		'event'      => array(
			array(
				'key'   => 'event.type',
				'label' => __( 'Event Type', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.source',
				'label' => __( 'Event Source', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.previous_status',
				'label' => __( 'Previous Status', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.new_status',
				'label' => __( 'New Status', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.previous_priority',
				'label' => __( 'Previous Priority', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.new_priority',
				'label' => __( 'New Priority', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'event.changed_field',
				'label' => __( 'Changed Field', 'nxt-cloud-chat' ),
			),
		),
		'wp'         => array(
			array(
				'key'   => 'wp.site_name',
				'label' => __( 'Site Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'wp.site_url',
				'label' => __( 'Site URL', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'wp.admin_email',
				'label' => __( 'Admin Email', 'nxt-cloud-chat' ),
			),
		),
		'wc'         => array(),
	);

	if ( class_exists( 'WooCommerce' ) || ! empty( $args['include_wc'] ) ) {
		$catalog['wc'] = array(
			array(
				'key'   => 'wc.shop_name',
				'label' => __( 'Shop Name', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'wc.shop_url',
				'label' => __( 'Shop URL', 'nxt-cloud-chat' ),
			),
			array(
				'key'   => 'wc.currency',
				'label' => __( 'Currency', 'nxt-cloud-chat' ),
			),
		);
	}

	$catalog = apply_filters( 'nxtcc_token_catalog', $catalog, $args );
	return is_array( $catalog ) ? $catalog : array();
}

/**
 * Compatibility discovery alias used by existing Pro surfaces.
 *
 * @param array<string,mixed> $args Discovery arguments.
 * @return array<string,array<int,array<string,mixed>>>
 */
function nxtcc_token_discover_catalog( array $args = array() ): array {
	return nxtcc_get_token_catalog( $args );
}

/**
 * Build a tenant-scoped CRM token context.
 *
 * Internal note content, credentials, raw metadata, and private user fields are
 * intentionally excluded. Integrations may add scalar namespaces with
 * `nxtcc_token_context`.
 *
 * @param array<string,mixed> $args Context arguments.
 * @return array<string,mixed>
 */
function nxtcc_build_token_context( array $args ): array {
	$tenant     = isset( $args['tenant'] ) && is_array( $args['tenant'] ) ? $args['tenant'] : $args;
	$tenant     = array(
		'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
		'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
		'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
	);
	$contact_id = absint( $args['contact_id'] ?? 0 );
	$contact    = $contact_id > 0 && function_exists( 'nxtcc_get_contact_by_id' )
		? nxtcc_get_contact_by_id( $contact_id, $tenant['user_mailid'], $tenant['business_account_id'], $tenant['phone_number_id'] )
		: null;
	$context    = array();

	if ( is_array( $contact ) ) {
		$context = nxtcc_token_build_context_for_contact( $contact_id, $tenant['user_mailid'] );
	}

	$ticket_id = absint( $args['ticket_id'] ?? $args['conversation_id'] ?? 0 );
	$ticket    = $ticket_id > 0 && function_exists( 'nxtcc_get_ticket' )
		? nxtcc_get_ticket( $ticket_id, $tenant )
		: null;
	if ( ! is_array( $ticket ) && $contact_id > 0 && class_exists( 'NXTCC_Conversations' ) ) {
		$ticket = NXTCC_Conversations::instance()->get_for_contact( $contact_id, $tenant );
	}
	if ( is_array( $ticket ) ) {
		$assigned_user_id      = absint( $ticket['assigned_user_id'] ?? 0 );
		$assigned_team         = sanitize_key( (string) ( $ticket['assigned_role'] ?? '' ) );
		$assignment_type       = $assigned_user_id > 0 ? 'user' : ( '' !== $assigned_team ? 'team' : 'unassigned' );
		$context['ticket']     = array(
			'id'                    => absint( $ticket['id'] ?? 0 ),
			'number'                => sanitize_text_field( (string) ( $ticket['ticket_number'] ?? '' ) ),
			'subject'               => sanitize_text_field( (string) ( $ticket['subject'] ?? '' ) ),
			'category'              => sanitize_text_field( (string) ( $ticket['category'] ?? '' ) ),
			'status'                => sanitize_key( (string) ( $ticket['status'] ?? '' ) ),
			'priority'              => sanitize_key( (string) ( $ticket['priority'] ?? '' ) ),
			'source'                => sanitize_key( (string) ( $ticket['origin_source'] ?? 'system' ) ),
			'opened_at'             => sanitize_text_field( (string) ( $ticket['opened_at'] ?? '' ) ),
			'updated_at'            => sanitize_text_field( (string) ( $ticket['updated_at'] ?? '' ) ),
			'first_response_at'     => sanitize_text_field( (string) ( $ticket['first_response_at'] ?? '' ) ),
			'first_response_due_at' => sanitize_text_field( (string) ( $ticket['first_response_due_at'] ?? '' ) ),
			'resolution_due_at'     => sanitize_text_field( (string) ( $ticket['resolution_due_at'] ?? '' ) ),
			'reopen_count'          => absint( $ticket['reopen_count'] ?? 0 ),
			'assignee_name'         => 'user' === $assignment_type ? sanitize_text_field( (string) ( $ticket['assignment_label'] ?? '' ) ) : '',
			'team_name'             => 'team' === $assignment_type ? sanitize_text_field( (string) ( $ticket['assignment_label'] ?? '' ) ) : '',
		);
		$context['assignment'] = array(
			'type'     => $assignment_type,
			'label'    => sanitize_text_field( (string) ( $ticket['assignment_label'] ?? '' ) ),
			'user_id'  => $assigned_user_id,
			'team_key' => $assigned_team,
		);
	}

	$ticket_snapshot = isset( $args['ticket_snapshot'] ) && is_array( $args['ticket_snapshot'] )
		? $args['ticket_snapshot']
		: array();
	if ( isset( $ticket_snapshot['message'] ) && is_scalar( $ticket_snapshot['message'] ) ) {
		$message = sanitize_textarea_field( (string) $ticket_snapshot['message'] );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 4096 ) : substr( $message, 0, 4096 );
		if ( '' !== $message ) {
			if ( ! isset( $context['ticket'] ) || ! is_array( $context['ticket'] ) ) {
				$context['ticket'] = array();
			}
			$context['ticket']['message'] = $message;
		}
	}

	if ( $contact_id > 0 && function_exists( 'nxtcc_get_contact_lifecycle_stage' ) ) {
		$lifecycle = nxtcc_get_contact_lifecycle_stage( $contact_id, $tenant );
		if ( is_array( $lifecycle ) ) {
			$context['lifecycle'] = array(
				'id'         => absint( $lifecycle['stage_id'] ?? $lifecycle['id'] ?? 0 ),
				'name'       => sanitize_text_field( (string) ( $lifecycle['stage_name'] ?? '' ) ),
				'slug'       => sanitize_key( (string) ( $lifecycle['stage_slug'] ?? '' ) ),
				'changed_at' => sanitize_text_field( (string) ( $lifecycle['changed_at'] ?? '' ) ),
			);
		}
	}

	$task_id = absint( $args['task_id'] ?? 0 );
	$task    = $task_id > 0 && function_exists( 'nxtcc_get_crm_task' ) ? nxtcc_get_crm_task( $task_id, $tenant ) : null;
	if ( ! is_array( $task ) && $contact_id > 0 && function_exists( 'nxtcc_list_contact_crm_tasks' ) ) {
		$tasks = nxtcc_list_contact_crm_tasks(
			$contact_id,
			$tenant,
			array(
				'status' => array( 'open' ),
				'limit'  => 1,
			)
		);
		$task  = isset( $tasks[0] ) && is_array( $tasks[0] ) ? $tasks[0] : null;
	}
	if ( is_array( $task ) ) {
		$context['task'] = array(
			'id'            => absint( $task['id'] ?? 0 ),
			'title'         => sanitize_text_field( (string) ( $task['title'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $task['status'] ?? '' ) ),
			'priority'      => sanitize_key( (string) ( $task['priority'] ?? '' ) ),
			'due_at'        => sanitize_text_field( (string) ( $task['due_at'] ?? '' ) ),
			'assignee_name' => sanitize_text_field( (string) ( $task['assignee_label'] ?? '' ) ),
		);
	}

	$deal_id = absint( $args['deal_id'] ?? 0 );
	$deal    = $deal_id > 0 && function_exists( 'nxtcc_get_crm_deal' ) ? nxtcc_get_crm_deal( $deal_id, $tenant ) : null;
	if ( ! is_array( $deal ) && $contact_id > 0 && function_exists( 'nxtcc_list_crm_deals' ) ) {
		$deals = nxtcc_list_crm_deals(
			$tenant,
			array(
				'contact_id' => $contact_id,
				'limit'      => 1,
			)
		);
		$deal  = isset( $deals[0] ) && is_array( $deals[0] ) ? $deals[0] : null;
	}
	if ( is_array( $deal ) ) {
		$context['deal'] = array(
			'id'                => absint( $deal['id'] ?? 0 ),
			'title'             => sanitize_text_field( (string) ( $deal['title'] ?? '' ) ),
			'pipeline_name'     => sanitize_text_field( (string) ( $deal['pipeline_name'] ?? '' ) ),
			'stage_name'        => sanitize_text_field( (string) ( $deal['stage_name'] ?? '' ) ),
			'value'             => (float) ( $deal['deal_value'] ?? 0 ),
			'currency'          => sanitize_text_field( (string) ( $deal['currency'] ?? '' ) ),
			'reason'            => sanitize_text_field( (string) ( $deal['stage_reason'] ?? '' ) ),
			'expected_close_at' => sanitize_text_field( (string) ( $deal['expected_close_at'] ?? '' ) ),
		);
	}

	$event = isset( $args['event_snapshot'] ) && is_array( $args['event_snapshot'] )
		? $args['event_snapshot']
		: ( isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : array() );
	if ( ! empty( $event ) ) {
		$changed_field = $event['changed_field'] ?? '';
		if ( '' === (string) $changed_field && ! empty( $event['changed_fields'] ) && is_array( $event['changed_fields'] ) ) {
			$changed_field = reset( $event['changed_fields'] );
		}
		$context['event'] = nxtcc_token_scalar_context(
			array(
				'type'              => $event['type'] ?? $event['event_type'] ?? '',
				'source'            => $event['source'] ?? '',
				'previous_status'   => $event['previous_status'] ?? '',
				'new_status'        => $event['new_status'] ?? $event['status'] ?? '',
				'previous_priority' => $event['previous_priority'] ?? '',
				'new_priority'      => $event['new_priority'] ?? $event['priority'] ?? '',
				'changed_field'     => $changed_field,
			)
		);
	}

	$providers = apply_filters( 'nxtcc_token_context_providers', array(), $args, $context );
	if ( is_array( $providers ) ) {
		foreach ( $providers as $namespace => $provider ) {
			$namespace = sanitize_key( (string) $namespace );
			if ( '' === $namespace || ! is_callable( $provider ) ) {
				continue;
			}
			try {
				$value = $provider( $args, $context );
				if ( is_array( $value ) ) {
					$context[ $namespace ] = nxtcc_token_scalar_context( $value );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	$context = apply_filters( 'nxtcc_token_context', $context, $args );
	return is_array( $context ) ? nxtcc_token_scalar_context( $context ) : array();
}

/**
 * Sanitize a bounded nested token context to scalar values.
 *
 * @param array<string|int,mixed> $context Raw context.
 * @param int                     $depth Current depth.
 * @return array<string|int,mixed>
 */
function nxtcc_token_scalar_context( array $context, int $depth = 0 ): array {
	if ( $depth >= 5 ) {
		return array();
	}

	$clean = array();
	foreach ( array_slice( $context, 0, 100, true ) as $key => $value ) {
		$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
		if ( '' === (string) $clean_key ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$clean[ $clean_key ] = nxtcc_token_scalar_context( $value, $depth + 1 );
		} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			$clean[ $clean_key ] = $value;
		} elseif ( is_scalar( $value ) ) {
			$is_message_key      = 'message' === (string) $clean_key;
			$text                = $is_message_key ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			$limit               = $is_message_key ? 4096 : 2000;
			$clean[ $clean_key ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
		}
	}

	return $clean;
}
