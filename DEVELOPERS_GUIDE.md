# NXT Cloud Chat Developer Integration Guide

**Applies to:** NXT Cloud Chat 1.1.0 and NXT Cloud Chat Pro 1.1.0  
**Audience:** WordPress plugin, theme, agency, integration, AI, and automation developers  
**Integration style:** Server-side PHP wrappers and WordPress hooks

This guide explains how another WordPress plugin can integrate safely with NXT
Cloud Chat Free and NXT Cloud Chat Pro. It is written for developers who need to
sync contacts, update consent, assign CRM records, work with conversations,
create deals, query segments, dispatch workflow events, create broadcasts, or
read automation data.

This Markdown guide is the easier-to-navigate companion for Free and Pro 1.1.0.

## Table of Contents

1. [Architecture](#architecture)
2. [Quick Start](#quick-start)
3. [Tenant Context](#tenant-context)
4. [Runtime Discovery](#runtime-discovery)
5. [Security Requirements](#security-requirements)
6. [Result and Error Conventions](#result-and-error-conventions)
7. [Contacts and Consent](#contacts-and-consent)
8. [Tags, Groups, and Assignments](#tags-groups-and-assignments)
9. [Access Teams and Record Permissions](#access-teams-and-record-permissions)
10. [Conversations and Tickets](#conversations-and-tickets)
11. [CRM Activities, Lifecycle Stages, Tasks, and Saved Views](#crm-activities-lifecycle-stages-tasks-and-saved-views)
12. [Pipelines, Deals, and Line Items](#pipelines-deals-and-line-items)
13. [Contact Queries and Dynamic Segments](#contact-queries-and-dynamic-segments)
14. [Messaging, History, and Health](#messaging-history-and-health)
15. [Hooks and Token Providers](#hooks-and-token-providers)
16. [Pro Runtime Discovery](#pro-runtime-discovery)
17. [Pro Workflow Events](#pro-workflow-events)
18. [Pro Broadcasts, Abandoned Carts, and Analytics](#pro-broadcasts-abandoned-carts-and-analytics)
19. [REST and Remote Integrations](#rest-and-remote-integrations)
20. [Free API Index](#free-api-index)
21. [Pro API Index](#pro-api-index)
22. [Performance and Reliability](#performance-and-reliability)
23. [Testing Checklist](#testing-checklist)
24. [Final Integration Rules](#final-integration-rules)

## Architecture

NXT Cloud Chat uses a Free-owned global runtime contract. The Free plugin owns
shared CRM data and the stable integration wrappers. Pro publishes its licensed
extension through that same Free contract.

The important source files are:

- `includes/nxtcc-runtime-contract.php` in the Free plugin.
- `includes/nxtcc-pro-runtime-contract.php` in the Pro plugin.

Use these global wrappers instead of calling internal classes or querying NXT
Cloud Chat tables directly.

### Supported integration boundary

Use:

- Global functions published in the Free contract `wrappers` list and this API
  index.
- Licensed Pro functions published in the Pro contract and this API index.
- Hooks published in the runtime contract.
- Capability discovery before optional operations.

Avoid:

- Reading or writing `nxtcc_*` database tables directly.
- Calling private or module-internal classes.
- Copying access tokens into your plugin settings.
- Sending credentials to browser JavaScript.
- Assuming a Pro function exists merely because Pro files are installed.
- Using hidden buttons or UI state as server-side authorization.

Not every global function found in a contract source file is public. Quoting,
cache-key, payload-sanitizing, extension-registration, and similar helpers are
implementation details. Do not call them unless they are later published in a
contract `wrappers` list.

### In-process API, not a public remote API

The runtime contract is an in-process PHP API for code running in the same
WordPress installation. It is not an unauthenticated remote API. If an off-site
service needs access, create a small authenticated REST bridge in your plugin
and call the wrappers from that bridge.

## Quick Start

Load after plugins are available and check the capability you need.

```php
<?php
add_action( 'plugins_loaded', 'example_nxtcc_boot', 20 );

function example_nxtcc_boot(): void {
	if ( ! function_exists( 'nxtcc_get_runtime_contract' ) ) {
		return;
	}

	if (
		! function_exists( 'nxtcc_has_runtime_capability' )
		|| ! nxtcc_has_runtime_capability( 'contact_integration_writer' )
	) {
		return;
	}

	add_action( 'example_customer_saved', 'example_sync_customer_to_nxtcc', 10, 2 );
}
```

This example assumes `example_customer_saved` supplies both the customer data
and a trusted tenant tuple.

Do not include NXT Cloud Chat PHP files manually. WordPress plugin loading owns
their lifecycle.

## Tenant Context

Every tenant-scoped operation uses this complete tuple:

```php
$tenant = array(
	'user_mailid'         => 'owner@example.com',
	'business_account_id' => '123456789012345',
	'phone_number_id'     => '987654321098765',
);
```

All three values are required together. A contact ID, deal ID, segment ID, cart
ID, workflow ID, or run ID is not globally authoritative without the tenant.

### Where the tenant should come from

Use one of these trusted sources:

- A tenant tuple delivered by an NXT Cloud Chat hook.
- A tenant selected by an authorized administrator and stored by your plugin.
- A server-side mapping between your integration account and an authorized NXT
  Cloud Chat tenant.

Do not trust tenant IDs submitted by a browser or remote caller without checking
that the current user is authorized for that tenant.

### Normalize a tenant in your plugin

```php
function example_normalize_nxtcc_tenant( array $tenant ): array {
	return array(
		'user_mailid'         => sanitize_email( (string) ( $tenant['user_mailid'] ?? '' ) ),
		'business_account_id' => sanitize_text_field( (string) ( $tenant['business_account_id'] ?? '' ) ),
		'phone_number_id'     => sanitize_text_field( (string) ( $tenant['phone_number_id'] ?? '' ) ),
	);
}

function example_nxtcc_tenant_is_complete( array $tenant ): bool {
	return ! in_array( '', example_normalize_nxtcc_tenant( $tenant ), true );
}
```

## Runtime Discovery

Read the Free contract:

```php
$contract = nxtcc_get_runtime_contract();
```

The main shape is:

```php
array(
	'contract_version' => '1.1.0',
	'plugin'           => array(
		'slug'         => 'nxt-cloud-chat',
		'version'      => '1.1.0',
		'distribution' => 'FREE',
	),
	'capabilities'     => array(),
	'hooks'            => array(),
	'wrappers'         => array(),
	'extensions'       => array(), // Present when extensions publish themselves.
);
```

Check one feature:

```php
if ( nxtcc_has_runtime_capability( 'contact_tag_writer' ) ) {
	// nxtcc_update_contact_tags() is available.
}
```

Checking both `function_exists()` and the capability is recommended when your
plugin supports several NXT Cloud Chat versions.

### Important Free capability groups

| Domain | Capability keys |
|---|---|
| Contacts | `contact_reader`, `contact_phone_reader`, `contact_wp_user_reader`, `contact_integration_writer`, `contact_subscription_writer` |
| Tags and groups | `contact_group_reader`, `contact_tag_reader`, `contact_tag_writer`, `contact_tag_definition_writer` |
| Assignment | `contact_assignment_reader`, `contact_assignment_writer`, `contact_auto_assignment_writer`, `contact_assignment_targets_reader` |
| Access | `crm_access_policy_reader`, `crm_contact_access_checker`, `access_teams_reader` |
| Conversations | `conversation_reader`, `conversation_writer`, `conversation_assignment_writer`, `conversation_auto_assignment_writer`, `conversation_note_writer`, `conversation_watcher_writer`, `conversation_activity_reader`, `conversation_access_checker` |
| CRM | `crm_activity_reader`, `crm_activity_writer`, `lifecycle_stage_reader`, `lifecycle_stage_writer`, `crm_task_reader`, `crm_task_writer`, `crm_saved_view_reader`, `crm_saved_view_writer` |
| Sales | `crm_pipeline_reader`, `crm_pipeline_writer`, `crm_deal_reader`, `crm_deal_writer`, `crm_deal_lifecycle_writer`, `crm_deal_access_checker`, `crm_analytics_reader` |
| Queries | `contact_query_reader`, `contact_query_provider_reader` |
| Messaging | `session_reply_sender`, `background_session_reply_sender`, `message_history_reader`, `message_history_wamid_reader`, `latest_inbound_reader` |
| Connection | `tenant_credentials_wrapper`, `meta_health_status_reader` |

Additional published discovery keys:

| Purpose | Capability keys |
|---|---|
| Contract and hooks | `compat_contract`, `inbound_message_persisted_hook`, `message_history_status_updated_hook`, `auth_lifecycle_hooks` |
| Conversation SLA | `conversation_sla_reader`, `conversation_sla_candidate_reader` |
| Pipeline lifecycle | `crm_pipeline_overview_reader`, `crm_pipeline_lifecycle_writer` |
| Deal item providers | `crm_deal_item_provider_reader`, `crm_deal_item_searcher`, `crm_deal_item_resolver` |
| Contact duplicate handling | `contact_duplicate_reader`, `contact_merge_writer` |
| Authentication lookup | `verified_phone_reader` |

## Security Requirements

The wrappers validate and sanitize their own domain inputs, but they do not
replace authorization in your plugin.

### User-driven requests

For admin forms and AJAX:

- Check a nonce with `check_admin_referer()` or `check_ajax_referer()`.
- Check a relevant capability with `current_user_can()`.
- Verify the user is authorized for the selected tenant.
- Apply NXT Cloud Chat record-access wrappers before reads and writes.
- Sanitize input and escape output.

For REST:

- Always use a real `permission_callback`.
- Use WordPress cookie authentication, Application Passwords, or another
  reviewed authentication mechanism.
- Add rate limiting to remotely callable write endpoints.
- Do not accept access tokens, App Secrets, or raw SQL.

For cron, Action Scheduler, or queue workers:

- Pass a trusted complete tenant tuple.
- Use stable idempotency or deduplication keys.
- Re-read current consent before marketing or recovery sends.
- Log identifiers and error codes, not secrets or full message payloads.

### Credentials

`nxtcc_get_tenant_api_credentials()` is published for trusted server-side
integrations. Prefer higher-level message and health wrappers whenever possible
so your plugin never handles an access token.

Never expose these values in HTML, JavaScript, REST output, logs, exceptions, or
analytics:

- Access Token
- App Secret
- OTP values
- Webhook verification secrets
- Unnecessary message or cart payloads

## Result and Error Conventions

Most writers return an array containing `success`.

```php
$result = nxtcc_update_contact_subscription_status( $args );

if ( empty( $result['success'] ) ) {
	$error = sanitize_key( (string) ( $result['error'] ?? 'unknown_error' ) );
	error_log( 'NXTCC operation failed: ' . $error );
	return;
}
```

Common shapes:

```php
array(
	'success' => false,
	'error'   => 'missing_tenant',
);

array(
	'success'    => true,
	'contact_id' => 123,
	'created'    => true,
	'updated'    => false,
	'contact'    => array(),
);
```

Readers commonly return an array, `null`, an integer, a string, or a bounded
list. Never assume a record exists. Cast IDs with `absint()` and validate every
field before use.

## Contacts and Consent

### Upsert a contact from another plugin

Use a stable `source` and `external_id` so your systems can correlate records.

```php
function example_sync_customer_to_nxtcc( array $customer, array $tenant ): int {
	if (
		! function_exists( 'nxtcc_upsert_contact_for_integration' )
		|| ! nxtcc_has_runtime_capability( 'contact_integration_writer' )
	) {
		return 0;
	}

	$tenant = example_normalize_nxtcc_tenant( $tenant );
	if ( ! example_nxtcc_tenant_is_complete( $tenant ) ) {
		return 0;
	}

	$result = nxtcc_upsert_contact_for_integration(
		array_merge(
			$tenant,
			array(
				'phone_number'     => sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
				'country_code'     => sanitize_text_field( (string) ( $customer['country_code'] ?? '' ) ),
				'wp_user_id'       => absint( $customer['wp_user_id'] ?? 0 ),
				'name'             => sanitize_text_field( (string) ( $customer['name'] ?? '' ) ),
				'email'            => sanitize_email( (string) ( $customer['email'] ?? '' ) ),
				'source'           => 'example_plugin',
				'external_id'      => sanitize_text_field( (string) ( $customer['id'] ?? '' ) ),
				'metadata'         => array(
					'plan' => sanitize_text_field( (string) ( $customer['plan'] ?? '' ) ),
				),
				'is_subscribed'    => ! empty( $customer['message_opt_in'] ) ? 1 : 0,
				'allow_resubscribe' => false,
			)
		)
	);

	return ! empty( $result['success'] ) ? absint( $result['contact_id'] ?? 0 ) : 0;
}
```

Set `allow_resubscribe` to `true` only after your plugin captures a clear new
opt-in. Never overwrite an unsubscribe merely because a customer record was
imported again.

When `is_subscribed` is omitted, a newly created contact defaults to subscribed
in the 1.1.0 implementation. Consent-sensitive integrations should therefore
pass an explicit value derived from their own verified business rule.

### Read a contact

```php
$contact = nxtcc_get_contact_by_phone(
	'+919876543210',
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);

$contact = nxtcc_get_contact_by_wp_user(
	get_current_user_id(),
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);

$contact = nxtcc_get_contact_by_id(
	$contact_id,
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);
```

Always pass tenant values when you have them.

### Update subscription status

```php
$result = nxtcc_update_contact_subscription_status(
	array_merge(
		$tenant,
		array(
			'contact_id' => $contact_id,
			'status'     => 'unsubscribed', // subscribed or unsubscribed.
			'reason'     => 'example_preference_center',
		)
	)
);
```

For a new explicit opt-in, use `status => subscribed` with a clear audit reason.

### Decide consent at the business-flow level

Marketing, recovery, reminders, and lead-nurturing sends should require current
subscription. OTP, authentication, required transactional, and user-requested
support flows may follow different rules. Do not add one global block that
silently changes every message type.

```php
function example_contact_allows_marketing( int $contact_id, array $tenant ): bool {
	$contact = nxtcc_get_contact_by_id(
		$contact_id,
		$tenant['user_mailid'],
		$tenant['business_account_id'],
		$tenant['phone_number_id']
	);

	return is_array( $contact ) && ! empty( $contact['is_subscribed'] );
}
```

## Tags, Groups, and Assignments

### List and create tags

```php
$tags = nxtcc_list_contact_tags(
	$tenant,
	array(
		'search'     => 'vip',
		'limit'      => 100,
		'with_count' => true,
	)
);

$tag = nxtcc_upsert_contact_tag(
	array_merge(
		$tenant,
		array(
			'tag_name'    => 'VIP Customer',
			'color'       => '#2271b1',
			'description' => 'Created by Example Plugin.',
			'actor_id'    => get_current_user_id(),
		)
	)
);
```

Tag list arguments are `search`, `limit`, `offset`, and `with_count`. The limit
is capped at 1,000; use a smaller page size for interactive requests.

### Add, remove, or replace contact tags

```php
$result = nxtcc_update_contact_tags(
	array_merge(
		$tenant,
		array(
			'contact_id' => $contact_id,
			'tag_ids'    => array( absint( $tag['tag_id'] ?? 0 ) ),
			'operation'  => 'add', // add, remove, or replace.
			'source'     => 'integration',
			'actor_id'   => get_current_user_id(),
		)
	)
);
```

You may pass `tags => array( 'Webinar Lead', 'Needs Follow-up' )`; missing tag
definitions are created by the tenant-safe service.

Read current tags or groups:

```php
$tags = nxtcc_get_contact_tags_by_id(
	$contact_id,
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);

$groups = nxtcc_get_contact_groups_by_id(
	$contact_id,
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);
```

### Discover assignment targets

```php
$targets = nxtcc_list_contact_assignment_targets( $tenant );
```

The response contains eligible users and Access Team queues. A legacy `roles`
key remains for compatibility. Only submit targets returned by this wrapper.

### Assign contact ownership

Assign a user:

```php
$result = nxtcc_update_contact_assignment(
	array_merge(
		$tenant,
		array(
			'contact_id'       => $contact_id,
			'target_type'      => 'user',
			'assigned_user_id' => 42,
			'source'           => 'integration',
			'actor_id'         => get_current_user_id(),
		)
	)
);
```

Assign an Access Team queue. The `role` terminology is retained in the API for
backward compatibility:

```php
$result = nxtcc_update_contact_assignment(
	array_merge(
		$tenant,
		array(
			'contact_id'   => $contact_id,
			'target_type'  => 'role',
			'assigned_role' => 'sales_support',
			'source'       => 'integration',
		)
	)
);
```

Clear ownership with `target_type => unassigned`.

### Auto-assign a contact

```php
$result = nxtcc_auto_assign_contact(
	array_merge(
		$tenant,
		array(
			'contact_id' => $contact_id,
			'role_key'   => 'sales_support',
			'route_key'  => 'example_new_leads',
			'overwrite'  => false,
			'source'     => 'integration',
		)
	)
);
```

Use a stable route key for each independent round-robin queue. Existing owners
are preserved unless `overwrite` is explicitly true.

## Access Teams and Record Permissions

Access Teams define module permissions, action level, data scope, and assignment
eligibility.

Action levels:

- `view_only`: read permitted records but do not mutate them.
- `manage`: read and mutate permitted records.

Data scopes:

- `assigned`: records directly assigned to the user.
- `team`: directly assigned, assigned to the user's Access Team queue, and
  unassigned records.
- `all`: all records in the tenant.

Read a capability-specific policy:

```php
$policy = nxtcc_get_crm_access_policy(
	get_current_user_id(),
	$tenant,
	'nxtcc_view_contacts'
);
```

Enforce record access on the server:

```php
if ( ! nxtcc_user_can_view_contact( $contact_id, $tenant ) ) {
	wp_die( esc_html__( 'You cannot view this contact.', 'example-plugin' ), 403 );
}

if ( ! nxtcc_user_can_manage_contact( $contact_id, $tenant ) ) {
	wp_die( esc_html__( 'You cannot update this contact.', 'example-plugin' ), 403 );
}
```

Filter submitted IDs before a bulk operation:

```php
$manageable_ids = nxtcc_filter_contact_ids_by_access(
	array_map( 'absint', $submitted_contact_ids ),
	$tenant,
	true,
	get_current_user_id()
);
```

Read Access Teams with `nxtcc_get_access_teams( $tenant )`. Use
`nxtcc_list_contact_assignment_targets()` for assignment selectors because it
already excludes ineligible users and non-queue teams. Version 1.1.0 publishes
team readers and assignment writers, but no external Access Team definition
writer; create and manage teams through the authorized NXT Cloud Chat admin UI.

## Conversations and Tickets

Conversation tickets represent active Inbox work. Conversation assignment is
separate from long-term contact ownership.

### Get or create a conversation

```php
$conversation = nxtcc_get_or_create_conversation(
	$contact_id,
	$tenant,
	array(
		'subject'  => 'Checkout support',
		'priority' => 'high',
		'actor_id' => get_current_user_id(),
	)
);
```

Before a user-facing read or mutation:

```php
$conversation_id = absint( $conversation['id'] ?? 0 );

if ( ! nxtcc_user_can_view_conversation( $conversation_id, $tenant ) ) {
	wp_die( esc_html__( 'You cannot view this conversation.', 'example-plugin' ), 403 );
}

if ( ! nxtcc_user_can_manage_conversation( $conversation_id, $tenant ) ) {
	wp_die( esc_html__( 'You cannot update this conversation.', 'example-plugin' ), 403 );
}
```

### Update ticket details

```php
$result = nxtcc_update_conversation(
	array_merge(
		$tenant,
		array(
			'conversation_id' => $conversation_id,
			'status'          => 'pending',
			'priority'        => 'high',
			'subject'         => 'Checkout support',
			'category'        => 'billing',
			'actor_id'       => get_current_user_id(),
		)
	)
);
```

Statuses: `unassigned`, `open`, `pending`, `snoozed`, `resolved`, `closed`.  
Priorities: `low`, `normal`, `high`, `urgent`.

For `snoozed`, pass `snoozed_until` as a UTC `Y-m-d H:i:s` value.

### Assign or hand off a ticket

Use compact targets returned by `nxtcc_list_contact_assignment_targets()`:

- `user:42`
- `role:sales_support` (an Access Team queue)

```php
$result = nxtcc_assign_conversation(
	array_merge(
		$tenant,
		array(
			'conversation_id'  => $conversation_id,
			'assignment_target' => 'role:sales_support',
			'note'              => 'Customer needs a billing specialist.',
			'reason'            => 'Billing escalation',
			'source'            => 'integration',
			'actor_id'          => get_current_user_id(),
		)
	)
);
```

A non-empty internal note is required when an existing assignment changes,
including reassignment and unassignment.

### Auto-route a ticket

```php
$result = nxtcc_auto_assign_conversation(
	array_merge(
		$tenant,
		array(
			'conversation_id' => $conversation_id,
			'strategy'        => 'least_busy', // least_busy or round_robin.
			'role_key'       => 'sales_support',
			'route_key'      => 'example_support_queue',
			'overwrite'      => false,
			'note'           => 'Automatically routed by support intake.',
			'reason'         => 'Support intake routing',
			'source'         => 'integration',
			'actor_id'       => get_current_user_id(),
		)
	)
);
```

### Internal notes, watchers, activities, and SLA

```php
$note = nxtcc_add_conversation_note(
	array_merge(
		$tenant,
		array(
			'conversation_id' => $conversation_id,
			'note'            => 'Refund receipt requested from finance.',
			'source'          => 'integration',
			'actor_id'        => get_current_user_id(),
		)
	)
);

$watcher = nxtcc_set_conversation_watcher(
	array_merge(
		$tenant,
		array(
			'conversation_id' => $conversation_id,
			'wp_user_id'      => get_current_user_id(),
			'watch'           => true,
			'actor_id'        => get_current_user_id(),
		)
	)
);

$activities = nxtcc_get_conversation_activities( $conversation_id, $tenant, 50 );
$sla_targets = nxtcc_get_conversation_sla_targets();
$candidates = nxtcc_list_conversation_sla_candidates( $tenant, 0, 100 );
```

Watchers must already have tenant access. Watching does not grant access and
does not change the primary assignee.

Customize SLA targets through the published filter:

```php
add_filter(
	'nxtcc_conversation_sla_targets',
	static function ( array $targets ): array {
		$targets['urgent']['first_response'] = 10;
		$targets['urgent']['resolution']     = 120;
		return $targets;
	}
);
```

## CRM Activities, Lifecycle Stages, Tasks, and Saved Views

### Record an integration activity

The activity writer accepts only registered activity types. Register an
integration-specific type first:

```php
add_filter(
	'nxtcc_crm_activity_types',
	static function ( array $types ): array {
		$types['example_booking_confirmed'] = 'Booking confirmed';
		return $types;
	}
);

$result = nxtcc_record_crm_activity(
	array_merge(
		$tenant,
		array(
			'contact_id'    => $contact_id,
			'activity_type' => 'example_booking_confirmed',
			'note_content'  => 'Booking 8842 was confirmed by Example Plugin.',
			'metadata'      => array(
				'booking_id' => 8842,
				'source_ref' => 'booking:8842',
			),
			'source'        => 'integration',
			'actor_id'      => get_current_user_id(),
		)
	)
);

$types = nxtcc_get_crm_activity_types();
$timeline = nxtcc_get_contact_crm_activities(
	$contact_id,
	$tenant,
	array(
		'limit' => 50,
	)
);
```

Built-in activity types are returned by `nxtcc_get_crm_activity_types()`. The
writer accepts `contact_id`, optional `conversation_id`, `task_id`, or `deal_id`,
`activity_type`, `source`, `actor_user_id`/`actor_id`, `note_content`, and a
bounded `metadata` array. Keep source references stable inside metadata and do
not store secrets there.

### Lifecycle stages

```php
$stages = nxtcc_list_lifecycle_stages( $tenant );
$current = nxtcc_get_contact_lifecycle_stage( $contact_id, $tenant );

if ( ! empty( $stages[0]['id'] ) ) {
	$result = nxtcc_set_contact_lifecycle_stage(
		array_merge(
			$tenant,
			array(
				'contact_id' => $contact_id,
				'stage_id'   => absint( $stages[0]['id'] ),
				'source'     => 'integration',
				'actor_id'   => get_current_user_id(),
			)
		)
	);
}
```

Pass `stage_id => 0` to clear a contact's lifecycle stage. Use
`nxtcc_upsert_lifecycle_stage()` only in an explicitly authorized CRM
configuration screen.

### Follow-up tasks

```php
$created = nxtcc_create_crm_task(
	array_merge(
		$tenant,
		array(
			'contact_id'  => $contact_id,
			'title'       => 'Follow up about pricing',
			'description' => 'Confirm the preferred plan.',
			'priority'    => 'high',
			'due_at'      => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'source'      => 'integration',
			'actor_id'    => get_current_user_id(),
		)
	)
);

$tasks = nxtcc_list_contact_crm_tasks(
	$contact_id,
	$tenant,
	array(
		'status' => array( 'open' ),
		'limit'  => 30,
	)
);

$task = nxtcc_get_crm_task( absint( $created['task']['id'] ?? 0 ), $tenant );

if ( is_array( $task ) ) {
	nxtcc_update_crm_task(
		array_merge(
			$tenant,
			array(
				'task_id'  => absint( $task['id'] ),
				'status'   => 'completed',
				'source'   => 'integration',
				'actor_id' => get_current_user_id(),
			)
		)
	);
}
```

Activity readers accept `before_id`, `source`, `activity_types`, and a `limit`
of up to 200. Task readers accept `before_id`, `status`, and a `limit` of up to
100. Task status values are `open`, `completed`, and `cancelled`; priorities are
`low`, `normal`, `high`, and `urgent`.

### Personal saved views

Saved views store filters; they do not grant access to matching contacts.

```php
$result = nxtcc_upsert_crm_saved_view(
	array_merge(
		$tenant,
		array(
			'owner_user_id' => get_current_user_id(),
			'view_name'     => 'My Unassigned Leads',
			'is_default'    => true,
			'filters'       => array(
				'filter_assignment'   => 'unassigned',
				'filter_subscription' => '1',
				'filter_tags'         => array( 12, 18 ),
				'search'              => '',
			),
		)
	)
);

$views = nxtcc_list_crm_saved_views( $tenant, get_current_user_id() );
$view = ! empty( $views[0]['id'] )
	? nxtcc_get_crm_saved_view( absint( $views[0]['id'] ), $tenant, get_current_user_id() )
	: null;
```

Use `nxtcc_delete_crm_saved_view()` with the complete tenant, owner user ID, and
view ID. Never expose another user's view definitions without authorization.
Supported saved-view fields are `filter_group`, `filter_tags`,
`filter_country`, `filter_created_by`, `filter_created_from`,
`filter_created_to`, `filter_subscription`, `filter_assignment`, and `search`.
The service validates referenced groups, tags, owners, and assignment targets
against the tenant.

### Contact duplicate handling

```php
$candidates = nxtcc_find_contact_duplicate_candidates( $contact_id, $tenant, 20 );

// Merge only after an authorized user explicitly confirms both IDs.
$result = nxtcc_merge_contacts(
	array_merge(
		$tenant,
		array(
			'target_contact_id' => $contact_id,
			'source_contact_id' => $confirmed_source_id,
			'source'            => 'integration',
			'actor_id'          => get_current_user_id(),
		)
	)
);
```

The target contact's phone identity and consent remain authoritative. Do not
automate merges without a reviewed business rule.

## Pipelines, Deals, and Line Items

### Read and manage pipelines

```php
$pipelines = nxtcc_list_crm_pipelines( $tenant );
$overview = nxtcc_get_crm_pipeline_overview( $tenant, true );

foreach ( $pipelines as $pipeline ) {
	$stages = nxtcc_list_crm_pipeline_stages( absint( $pipeline['id'] ), $tenant );
}
```

Authorized configuration integrations can use:

```php
$pipeline = nxtcc_upsert_crm_pipeline(
	array_merge(
		$tenant,
		array(
			'pipeline_name' => 'Partner Sales',
			'currency'      => 'USD',
			'actor_id'      => get_current_user_id(),
		)
	)
);

$stage = nxtcc_upsert_crm_pipeline_stage(
	array_merge(
		$tenant,
		array(
			'pipeline_id'       => absint( $pipeline['pipeline']['id'] ?? 0 ),
			'stage_name'        => 'Qualified',
			'stage_type'        => 'open',
			'probability'       => 40,
			'color'             => '#2271b1',
			'reason_requirement' => 'none',
			'actor_id'          => get_current_user_id(),
		)
	)
);
```

Pipeline stage types are `open`, `won`, and `lost`. Reason requirements are
`none`, `optional`, `enter`, `leave`, and `both`. Probability is clamped to
0-100. Use `pipeline_id`/`stage_id` when updating existing definitions.

Use duplicate, reorder, and delete wrappers rather than modifying pipeline
tables. Referenced stages and pipelines may need to be archived instead of
permanently deleted.

### Create a deal

```php
$result = nxtcc_create_crm_deal(
	array_merge(
		$tenant,
		array(
			'contact_id'        => 42,
			'pipeline_id'       => 3,
			'stage_id'          => 11,
			'title'             => 'Annual support renewal',
			'deal_value'        => 2500,
			'value_mode'        => 'calculated',
			'currency'          => 'USD',
			'expected_close_at' => '2026-07-31 00:00:00',
			'products'          => array(
				array(
					'source_key'     => 'manual',
					'source_item_id' => '',
					'product_name'   => 'Annual support plan',
					'quantity_type'  => 'unit',
					'quantity'       => 1,
					'unit_price'     => 2500,
					'currency'       => 'USD',
				),
			),
			'source'            => 'integration',
			'actor_id'          => get_current_user_id(),
		)
	)
);
```

The `products` key is retained for compatibility; the CRM UI presents those
rows as line items. `value_mode => manual` uses `deal_value`; `calculated`
sums normalized line-item totals. A deal accepts at most 100 line items, and
each quantity is normalized to an integer of at least 1.

### Update, read, and delete deals

```php
$result = nxtcc_update_crm_deal(
	array_merge(
		$tenant,
		array(
			'deal_id'      => 77,
			'pipeline_id'  => 3,
			'stage_id'     => 15,
			'title'        => 'Annual support renewal',
			'stage_reason' => 'Renewal approved',
			'source'       => 'integration',
			'actor_id'     => get_current_user_id(),
		)
	)
);

$deal = nxtcc_get_crm_deal( 77, $tenant );
$deals = nxtcc_list_crm_deals( $tenant, array( 'status' => 'open', 'limit' => 20 ) );
$contact_deals = nxtcc_list_contact_crm_deals( 42, $tenant, array( 'limit' => 20 ) );
$open_count = nxtcc_count_crm_deals( $tenant, array( 'status' => 'open' ) );

if ( nxtcc_user_can_manage_crm_deal( 77, $tenant ) ) {
	$deleted = nxtcc_delete_crm_deal(
		array_merge( $tenant, array( 'deal_id' => 77, 'source' => 'integration' ) )
	);
}
```

Deal list/count filters are `pipeline_id`, `stage_id`, `contact_id`, `status`,
and `search`. List pagination uses `limit` and `offset`; the limit is capped at
500. Deal statuses are `open`, `won`, and `lost`.

Use `nxtcc_user_can_view_crm_deal()` and
`nxtcc_user_can_manage_crm_deal()` for user-facing requests.

### Read aggregate CRM analytics

```php
$analytics = nxtcc_get_crm_analytics(
	$tenant,
	array(
		'date_from'    => '2026-06-01 00:00:00',
		'date_to'      => '2026-06-30 23:59:59',
		'force_refresh' => false,
	)
);
```

The result contains `contacts`, `conversations`, `tasks`, `lifecycle`, `tags`,
and `deals` aggregates. The default range is 30 days, the maximum range is 366
days, and results are cached for five minutes unless `force_refresh` is true.
This is a tenant-wide aggregate reader; it does not grant access to individual
records and must be protected by your own reporting permission check.

### Register an external line-item provider

```php
add_filter( 'nxtcc_crm_deal_item_providers', 'example_register_deal_provider' );

function example_register_deal_provider( array $providers ): array {
	$providers['example_catalog'] = array(
		'label'            => 'Example Catalog',
		'available'        => true,
		'searchable'       => true,
		'search_callback'  => 'example_search_deal_items',
		'resolve_callback' => 'example_resolve_deal_item',
		'quantity_types'   => array(
			'unit'   => 'Unit',
			'packet' => 'Packet',
			'box'    => 'Box',
		),
	);

	return $providers;
}

function example_search_deal_items( string $search, array $tenant, int $limit ): array {
	unset( $tenant );

	$items = array(
		array(
			'id'             => 'plan-basic',
			'label'          => 'Basic Support Plan',
			'secondary_text' => 'Annual plan',
			'unit_value'     => 499,
			'currency'       => 'USD',
			'quantity_type'  => 'unit',
			'metadata'       => array( 'sku' => 'SUPPORT-BASIC' ),
		),
	);

	return array_slice(
		array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $search ): bool {
					return '' === $search || false !== stripos( $item['label'], $search );
				}
			)
		),
		0,
		$limit
	);
}

function example_resolve_deal_item( string $item_id, array $tenant ): ?array {
	$items = example_search_deal_items( '', $tenant, 20 );
	foreach ( $items as $item ) {
		if ( $item_id === $item['id'] ) {
			return $item;
		}
	}

	return null;
}
```

Search callbacks must return bounded, sanitized result rows and enforce tenant
ownership. Their signature is `( string $search, array $tenant, int $limit )`.
Resolve callbacks receive `( string $item_id, array $tenant )`. Item rows use
`id`, `label`, optional `secondary_text`, `unit_value`, `currency`,
`quantity_type`, and bounded `metadata`.

The registry accepts at most 30 providers and 30 quantity types per provider.
Search results and wrapper limits are capped at 20 rows. Resolve the selected
item again immediately before storing it; do not trust browser-supplied labels
or values.

Use the stable wrappers to consume providers:

```php
$providers = nxtcc_get_crm_deal_item_providers();
$items = nxtcc_search_crm_deal_items( 'example_catalog', 'support', $tenant, 20 );
$item = nxtcc_resolve_crm_deal_item( 'example_catalog', 'plan-42', $tenant );
```

## Contact Queries and Dynamic Segments

The Free query runtime accepts only allowlisted filters. Normalize a definition
before storing or evaluating it.

```php
$filters = nxtcc_normalize_contact_query_filters(
	array(
		'match_mode'           => 'all',
		'subscription'         => 'subscribed',
		'tag_ids'              => array( 14, 22 ),
		'tag_match'            => 'all',
		'conversation_statuses' => array( 'open', 'pending' ),
		'task_mode'            => 'overdue',
	)
);
```

Supported core filters include:

- `match_mode`
- `subscription`
- `search`
- `tag_ids` and `tag_match`
- `group_ids` and `group_match`
- `lifecycle_stage_ids`
- `assignment_target`
- `created_from` and `created_to`
- `last_contacted_from` and `last_contacted_to`
- `conversation_statuses`
- `task_mode`
- Validated `provider_filters`

Assignment targets can be `assigned`, `unassigned`, `user:<id>`, or
`role:<access-team-key>`. Tag/group modes can be `any`, `all`, or `none`.

### Cursor pagination

```php
$after_id = 0;

do {
	$contact_ids = nxtcc_query_contact_ids(
		$tenant,
		$filters,
		array(
			'after_id'           => $after_id,
			'limit'              => 200,
			'require_subscribed' => true,
		)
	);

	foreach ( $contact_ids as $contact_id ) {
		// Perform a bounded, authorized, idempotent operation.
	}

	$after_id = empty( $contact_ids ) ? 0 : max( $contact_ids );
} while ( count( $contact_ids ) === 200 );
```

The per-call limit is capped at 500.

Tag, group, and lifecycle-stage ID filter lists are normalized to at most 100
unique positive IDs.

```php
$count = nxtcc_count_contacts_by_query( $tenant, $filters );
$matches = nxtcc_contact_matches_query( $contact_id, $tenant, $filters );
$providers = nxtcc_get_contact_query_providers();
$groups = nxtcc_list_contact_groups( $tenant );
```

### Pro dynamic segments

Segments store validated rules, not copied membership. Evaluate them against
current CRM data when needed.

```php
$segments = nxtcc_pro_list_segments(
	$tenant,
	array(
		'status' => 'active',
		'page'   => 1,
		'limit'  => 20,
	)
);

if ( ! empty( $segments['rows'][0]['id'] ) ) {
	$preview = nxtcc_pro_evaluate_segment(
		absint( $segments['rows'][0]['id'] ),
		$tenant,
		array(
			'limit'              => 20,
			'include_contacts'   => false,
			'require_subscribed' => true,
		)
	);
}
```

Use the evaluation result for current membership. A stored `last_count` is a
snapshot and must not be treated as consent or send authorization.

Create or update a segment only after an authorized administrator confirms the
definition:

```php
$result = nxtcc_pro_save_segment(
	array_merge(
		$tenant,
		array(
			'name'          => 'Open VIP follow-ups',
			'description'   => 'Subscribed VIP contacts with open tasks.',
			'status'        => 'active',
			'filters'       => array(
				'match_mode'  => 'all',
				'subscription' => 'subscribed',
				'tag_ids'     => array( $vip_tag_id ),
				'tag_match'   => 'all',
				'task_mode'   => 'open',
			),
			'source'        => 'example_plugin',
			'actor_user_id' => get_current_user_id(),
		)
	)
);
```

Use `nxtcc_pro_get_segment()`, `nxtcc_pro_contact_matches_segment()`,
`nxtcc_pro_delete_segment()`, `nxtcc_pro_get_segment_filter_catalog()`, and
`nxtcc_pro_refresh_commerce_index()` as needed after capability checks.

Commerce index refresh starts a bounded background backfill. Trigger it after
relevant catalog/integration changes, not on page loads or inside segment
evaluation loops.

### Register an external contact-query provider

An external plugin may add indexed filter properties. Never accept SQL, table
names, column names, or callbacks from a request.

```php
add_filter(
	'nxtcc_contact_query_providers',
	static function ( array $providers ): array {
		$providers['example_crm'] = array(
			'label'      => 'Example CRM',
			'available'  => true,
			'properties' => array(
				'lead_score' => array(
					'label'      => 'Lead Score',
					'value_type' => 'number',
					'operators'  => array( 'greater_or_equal' ),
				),
			),
			'normalize_callback' => 'example_normalize_lead_score_rule',
			'criterion_callback' => 'example_build_lead_score_criterion',
		);

		return $providers;
	}
);

function example_normalize_lead_score_rule( array $rule ): array {
	if (
		'lead_score' !== sanitize_key( (string) ( $rule['property'] ?? '' ) )
		|| 'greater_or_equal' !== sanitize_key( (string) ( $rule['operator'] ?? '' ) )
		|| ! is_numeric( $rule['value'] ?? null )
	) {
		return array();
	}

	return array(
		'property' => 'lead_score',
		'operator' => 'greater_or_equal',
		'value'    => (float) $rule['value'],
	);
}

function example_build_lead_score_criterion( array $rule, array $tenant ): array {
	global $wpdb;

	if ( in_array( '', $tenant, true ) ) {
		return array();
	}

	$table = $wpdb->prefix . 'example_contact_scores';

	return array(
		'sql'  => "EXISTS (
			SELECT 1 FROM `{$table}` x
			WHERE x.contact_id = c.id
			  AND x.user_mailid = c.user_mailid
			  AND x.business_account_id = c.business_account_id
			  AND x.phone_number_id = c.phone_number_id
			  AND x.lead_score >= %f
		)",
		'args' => array( $rule['value'] ),
	);
}
```

The table name must be controlled by your plugin. The criterion must return SQL
with placeholders and a separate arguments array. Keep provider summary tables
tenant-scoped and indexed; build them asynchronously instead of scanning source
records during an interactive preview. The runtime accepts at most 25 registered
providers and 25 provider rules in one normalized filter. A criterion SQL
fragment is limited to 8,000 bytes and its argument list to 100 values.

## Messaging, History, and Health

### Session replies

Free exposes text replies for an active customer-service session.

```php
$result = nxtcc_send_session_reply(
	array_merge(
		$tenant,
		array(
			'contact_id'      => $contact_id,
			'message_content' => 'Thanks. We received your request.',
			'origin_type'     => 'chat_user',
			'origin_ref'      => 'example-ticket-123',
		)
	)
);
```

Optional reply fields include `reply_to_message_id`, `reply_to_history_id`,
`origin_type`, `origin_user_id`, and `origin_ref`.

Use the background sender only in trusted server-side jobs:

```php
$result = nxtcc_send_background_session_reply(
	array_merge(
		$tenant,
		array(
			'contact_id'      => $contact_id,
			'message_content' => 'Your booking request was received.',
			'origin_type'     => 'system',
			'origin_ref'      => 'booking:456',
		)
	)
);
```

`nxtcc_send_session_reply()` preserves current-user access checks.
`nxtcc_send_background_session_reply()` is for trusted jobs that have already
validated the tenant and business rule.

### Check the latest inbound timestamp

```php
$latest = nxtcc_get_latest_inbound_at(
	$contact_id,
	$tenant['user_mailid'],
	$tenant['business_account_id'],
	$tenant['phone_number_id']
);

$inside_window = is_string( $latest )
	&& '' !== $latest
	&& ( time() - strtotime( $latest . ' UTC' ) ) <= DAY_IN_SECONDS;
```

Do not use a session reply outside the allowed service window. Use an approved
template through Pro workflows or broadcasts when appropriate.

### Message history

```php
$rows = nxtcc_get_message_history_after_id(
	$after_id,
	100,
	array_merge(
		$tenant,
		array( 'status' => 'received' )
	)
);

$history_id = nxtcc_get_message_history_id_by_wamid( $wamid );
```

Prefer hooks for real-time work. Use the cursor reader for recovery, migration,
or integrations that cannot listen to hooks. The reader is capped at 500 rows.

### Meta health status

```php
$health = nxtcc_get_meta_health_status(
	$tenant,
	array(
		'force_refresh' => false,
	)
);

if ( ! empty( $health['success'] ) ) {
	$can_send = ! empty( $health['can_send_message'] );
	$entities = $health['entities'] ?? array();
	$errors   = $health['errors'] ?? array();
}
```

Optional arguments:

- `node_id`: defaults to the tenant phone number ID.
- `graph_version`: optional supported Graph API version override.
- `force_refresh`: bypass the cached health response.

The wrapper returns health entities, additional information, errors, possible
solutions, and safe future fields. Call it server-side.

### Template helpers

Free contains template sync/cache/component helpers, but they are not all
published as capability-discovered runtime wrappers. For new third-party
integrations, prefer licensed Pro workflows or broadcasts for template sends.
Do not build a direct Meta sender with copied credentials when a supported
wrapper can own the operation.

## Hooks and Token Providers

### Inbound message hook

```php
add_action( 'nxtcc_inbound_message_persisted', 'example_receive_nxtcc_message', 10, 1 );

function example_receive_nxtcc_message( array $event ): void {
	$contact_id = absint( $event['contact_id'] ?? 0 );
	$message    = sanitize_textarea_field( (string) ( $event['message_content'] ?? '' ) );

	if ( $contact_id <= 0 || '' === $message ) {
		return;
	}

	// Create or update a tenant-scoped record in your plugin.
}
```

### Message status hook

```php
add_action(
	'nxtcc_message_history_status_updated',
	static function ( array $event ): void {
		$status = sanitize_key( (string) ( $event['status'] ?? '' ) );
		$wamid  = sanitize_text_field( (string) ( $event['meta_message_id'] ?? '' ) );

		if ( '' === $status || '' === $wamid ) {
			return;
		}

		// Update your integration's matching delivery record.
	},
	10,
	1
);
```

### Published Free hooks

Two integration write-completion hooks are implemented in the Free runtime:

```php
add_action(
	'nxtcc_contact_upserted_for_integration',
	static function ( array $result, array $args ): void {
		if ( empty( $result['success'] ) ) {
			return;
		}

		// Store only the contact ID needed by your integration.
	},
	10,
	2
);

add_action(
	'nxtcc_contact_subscription_status_updated',
	static function ( array $result, array $original_contact, array $args ): void {
		if ( empty( $result['success'] ) ) {
			return;
		}

		// Synchronize your local consent audit state without repeating the write.
	},
	10,
	3
);
```

These hooks are integration surfaces in the implementation even though they are
not currently included in the Free contract's `hooks` discovery array.

Messaging and authentication:

- `nxtcc_inbound_message_persisted`
- `nxtcc_message_history_status_updated`
- `nxtcc_auth_otp_requested`
- `nxtcc_auth_otp_sent`
- `nxtcc_auth_otp_failed`
- `nxtcc_auth_login_succeeded`
- `nxtcc_auth_login_failed`
- `nxtcc_otp_verified`
- `nxtcc_wp_login`

Contacts and CRM:

- `nxtcc_contact_upserted_for_integration`
- `nxtcc_contact_subscription_status_updated`
- `nxtcc_contact_tag_created`
- `nxtcc_contact_tag_updated`
- `nxtcc_contact_tag_deleted`
- `nxtcc_contact_tags_updated`
- `nxtcc_contact_assignment_updated`
- `nxtcc_crm_activity_recorded`
- `nxtcc_lifecycle_stage_saved`
- `nxtcc_contact_lifecycle_stage_changed`
- `nxtcc_crm_task_created`
- `nxtcc_crm_task_updated`
- `nxtcc_crm_saved_view_created`
- `nxtcc_crm_saved_view_updated`
- `nxtcc_crm_saved_view_deleted`
- `nxtcc_crm_pipeline_saved`
- `nxtcc_crm_pipeline_stage_saved`
- `nxtcc_crm_deal_created`
- `nxtcc_crm_deal_updated`
- `nxtcc_crm_deal_deleted`
- `nxtcc_contacts_merged`

Conversations:

- `nxtcc_conversation_created`
- `nxtcc_conversation_status_changed`
- `nxtcc_conversation_priority_changed`
- `nxtcc_conversation_details_changed`
- `nxtcc_conversation_assignment_updated`
- `nxtcc_conversation_internal_note_added`
- `nxtcc_conversation_watcher_updated`
- `nxtcc_conversation_reopened`

Hook listeners must remain tenant-aware and idempotent. Do not repeat the same
write from its completion hook without a recursion/deduplication guard.

### Token provider

External plugins can publish a bounded token namespace:

```php
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'nxtcc_token_register_provider' ) ) {
			return;
		}

		nxtcc_token_register_provider(
			'booking',
			static function ( int $contact_id, string $user_mailid ): array {
				unset( $user_mailid );

				$booking_id = example_find_booking_id_for_contact( $contact_id );
				if ( $booking_id <= 0 ) {
					return array();
				}

				return array(
					'id'     => $booking_id,
					'status' => 'confirmed',
				);
			}
		);
	},
	20
);
```

The callback receives the NXT Cloud Chat contact ID and the tenant email hint.
Return an associative array that becomes the namespace value. For example,
`booking.status` resolves from the returned `status` key. Built-in namespaces
are `contact.*`, `wp.*`, and `wc.*` when WooCommerce is active.

Use a plugin-specific namespace and do not replace a built-in namespace. The
email argument is only a hint, not a complete tenant tuple; anchor external data
to the contact ID or resolve and verify the full tenant before a tenant-specific
lookup.

Keep provider output scalar, sanitized, bounded, and free of credentials. Do not
perform slow remote calls during token rendering. Provider exceptions are
isolated by the token runtime so an optional integration cannot interrupt
message sending, but providers should still return an empty array when no data
is available.

### Advanced extension filters

The following implemented filters are useful to compatible plugins. Use them
additively and preserve all security checks and existing entries.

| Filter | Purpose |
|---|---|
| `nxtcc_runtime_contract` | Add discoverable extension metadata to the Free contract. |
| `nxtcc_crm_activity_types` | Register an allowlisted CRM activity type and label. |
| `nxtcc_contact_query_providers` | Register normalized, prepared contact-query providers. |
| `nxtcc_crm_deal_item_providers` | Register external deal line-item catalogs. |
| `nxtcc_token_providers` | Add token providers; prefer `nxtcc_token_register_provider()` when possible. |
| `nxtcc_conversation_sla_targets` | Adjust bounded priority-based SLA minute targets. |
| `nxtcc_eligible_staff_roles` | Add WordPress roles eligible for tenant team access. |
| `nxtcc_registered_capabilities` | Extend the NXT Cloud Chat capability catalog without removing required capabilities. |
| `nxtcc_registered_role_presets` | Extend access presets without weakening owner/security behavior. |
| `nxtcc_meta_graph_version` | Override the Graph version only with a tested supported version. |
| `nxtcc_meta_health_graph_version` | Override the health lookup Graph version only when tested. |
| `nxtcc_data_cleanup_targets` | Register an external module's tenant-scoped cleanup target. |
| `nxtcc_chat_max_upload_bytes` | Reduce or safely customize the chat upload limit. |
| `nxtcc_chat_upload_mimes` | Extend allowed chat MIME types only after validating server-side handling. |

Authentication maintenance filters also exist:

- `nxtcc_auth_otp_pruning_enabled`
- `nxtcc_auth_otp_retention_days`
- `nxtcc_auth_otp_batch_limit`
- `nxtcc_auth_otp_cron_time`
- Action: `nxtcc_auth_otp_purged`

Keep OTP retention short, batch sizes bounded, and never log OTP values.

Pro exposes these advanced extension filters:

| Filter | Purpose |
|---|---|
| `nxtcc_pro_runtime_contract` | Add compatible metadata to the licensed Pro contract. |
| `nxtcc_pro_segment_max_resolved_contacts` | Adjust the segment resolution ceiling within the hard 500-100,000 bounds. |
| `nxtcc_pro_abandoned_cart_recovery_tenant` | Resolve the tenant used by abandoned-cart recovery. |
| `nxtcc_pro_workflow_cod_payment_methods` | Add normalized COD payment method IDs for workflow events. |
| `nxtcc_pro_worker_kick_cooldown_seconds` | Adjust the bounded broadcast worker kick cooldown. |
| `nxtcc_pro_worker_kick_sslverify` | Control SSL verification only for a reviewed local environment; production should verify TLS. |

## Pro Runtime Discovery

Pro is optional and commercial. A file existing is not proof that licensed
runtime operations are available.

Discover Pro through the Free contract:

```php
$contract = nxtcc_get_runtime_contract();
$pro      = $contract['extensions']['nxt-cloud-chat-pro'] ?? array();
$pro_caps = is_array( $pro['capabilities'] ?? null ) ? $pro['capabilities'] : array();

if ( empty( $pro['plugin']['licensed'] ) ) {
	return;
}
```

Or use the direct discovery wrappers:

```php
$pro_contract = function_exists( 'nxtcc_pro_get_runtime_contract' )
	? nxtcc_pro_get_runtime_contract()
	: array();

$pro_caps = function_exists( 'nxtcc_pro_get_runtime_capabilities' )
	? nxtcc_pro_get_runtime_capabilities()
	: array();

$can_read_workflows = function_exists( 'nxtcc_pro_has_runtime_capability' )
	&& nxtcc_pro_has_runtime_capability( 'pro_workflow_reader' );
```

Important Pro capability keys:

- `pro_runtime_contract`
- `workflow_trigger_registrar`
- `workflow_event_writer`
- `pro_workflow_event_dispatcher`
- `pro_workflow_reader`
- `pro_workflow_run_reader`
- `conversation_workflow_events`
- `crm_productivity_workflow_events`
- `pro_segment_reader`
- `pro_segment_membership_reader`
- `pro_segment_writer`
- `pro_segment_filter_catalog_reader`
- `pro_commerce_index_writer`
- `pro_broadcast_run_writer`
- `pro_broadcast_run_reader`
- `pro_abandoned_cart_reader`
- `pro_automation_analytics_reader`

### Pro reader arguments and bounds

| Wrapper | Supported arguments and limits |
|---|---|
| `nxtcc_pro_list_workflows()` | `status`, `page`, `limit`; limit 1-100. |
| `nxtcc_pro_list_workflow_runs()` | `workflow_id`, `status`, `date_from`, `date_to`, `page`, `limit`; limit 1-100. |
| `nxtcc_pro_list_segments()` | `status` (`active`/`inactive`), `search`, `refresh_counts`, `page`, `limit`; limit 1-100. |
| `nxtcc_pro_evaluate_segment()` | `after_id`, `limit`, `include_contacts`, `require_subscribed`; limit 1-500. |
| `nxtcc_pro_list_broadcast_runs()` | `limit`; limit 1-200. |
| `nxtcc_pro_list_abandoned_carts()` | `status`, `search`, `page`, `limit`; limit 1-100. |
| `nxtcc_pro_get_automation_analytics()` | `date_from`, `date_to`, `force_refresh`; maximum range 366 days. |

Abandoned-cart statuses are `active`, `abandoned`, `recovered`, `ordered`, and
`expired`. Cart search matches customer name, email, or phone.

`nxtcc_pro_get_segment_filter_catalog()` returns external provider definitions
registered through the Free contact-query runtime. Segment resolution defaults
to 50,000 contacts and can be adjusted with
`nxtcc_pro_segment_max_resolved_contacts` only within the hard 500-100,000
range. Keep custom providers selective and indexed.

`refresh_counts` evaluates every segment returned on that page and writes a new
count snapshot. Leave it false for ordinary list requests; use it deliberately
on small pages or in a background refresh.

## Pro Workflow Events

Register your connector event during initialization:

```php
add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'nxtcc_pro_register_workflow_trigger' ) ) {
			return;
		}

		nxtcc_pro_register_workflow_trigger(
			array(
				'connector_id'    => 'example-plugin',
				'connector_label' => 'Example Plugin',
				'event_type'      => 'booking_created',
				'trigger_id'      => 'example_booking_created',
				'trigger_label'   => 'Booking Created',
			)
		);
	},
	5
);
```

Dispatch a tenant-scoped event:

```php
$result = nxtcc_pro_dispatch_workflow_event(
	array_merge(
		$tenant,
		array(
			'connector_id' => 'example-plugin',
			'event_type'   => 'booking_created',
			'source_ref'   => 'booking:1234',
			'dedupe_key'   => 'booking-created:1234',
			'payload'      => array(
				'booking_id' => 1234,
				'contact_id' => $contact_id,
			),
		)
	)
);
```

The optional `occurred_at` must be a UTC `Y-m-d H:i:s` value. Payloads are
sanitized, limited to six nesting levels and 200 entries per array level, and
must encode to no more than 65,535 bytes. Never put access tokens, OTP values,
or unnecessary personal data in workflow payloads.

Use a stable dedupe key for the same source event. New integrations should use
`nxtcc_pro_dispatch_workflow_event()`; `nxtcc_pro_emit_workflow_event()` performs
the same lower-level operation.

Read workflows and runs:

```php
$workflows = nxtcc_pro_list_workflows(
	$tenant,
	array( 'status' => 'published', 'page' => 1, 'limit' => 20 )
);

$runs = nxtcc_pro_list_workflow_runs(
	$tenant,
	array(
		'workflow_id' => 42,
		'status'      => 'completed',
		'date_from'   => '2026-06-01 00:00:00',
		'date_to'     => '2026-06-30 23:59:59',
		'page'        => 1,
		'limit'       => 20,
	)
);

$run = nxtcc_pro_get_workflow_run( 91, $tenant );
```

Workflow run context and step traces are private CRM/automation data.

## Pro Broadcasts, Abandoned Carts, and Analytics

### Create an idempotent broadcast

```php
$result = nxtcc_pro_create_broadcast_run(
	array_merge(
		$tenant,
		array(
			'source'          => 'example_plugin',
			'idempotency_key' => 'renewal-reminder-order-8842',
			'actor_user_id'   => get_current_user_id(),
			'template_name'   => 'renewal_reminder',
			'contact_ids'     => array( 101, 205 ),
			'params'          => array(
				'body_1' => 'Annual Support Plan',
			),
		)
	)
);
```

Provide exactly one audience:

- `contact_ids`
- `group_ids`
- `segment_id`

The writer requires an approved template, rechecks current subscription,
validates tenant ownership, rejects partial/truncated audiences, and creates a
stable broadcast ID from the tenant, source, and idempotency key.

The resolved audience is limited to 1,000 subscribed contacts. `priority` is an
integer from 1 through 10 and defaults to 5. Template `params` are sanitized and
limited to 100 entries. A request that resolves beyond the audience ceiling is
rejected rather than silently sending an incomplete campaign.

For a scheduled run, pass a future UTC MySQL datetime:

```php
'scheduled_at' => '2026-06-30 09:30:00'
```

### Read broadcasts and abandoned carts

```php
$broadcasts = nxtcc_pro_list_broadcast_runs( $tenant, array( 'limit' => 30 ) );
$broadcast  = nxtcc_pro_get_broadcast_run( 'b_20260612090000_example', $tenant );

$carts = nxtcc_pro_list_abandoned_carts(
	$tenant,
	array( 'status' => 'abandoned', 'page' => 1, 'limit' => 20 )
);
$cart = nxtcc_pro_get_abandoned_cart( 55, $tenant );
```

Cart contents and customer data are private. Apply your own permission checks
before displaying them.

### Read automation analytics

```php
$analytics = nxtcc_pro_get_automation_analytics(
	$tenant,
	array(
		'date_from' => '2026-06-01 00:00:00',
		'date_to'   => '2026-06-30 23:59:59',
	)
);
```

Date ranges are bounded to 366 days. The result contains grouped workflow,
broadcast, event, and abandoned-cart totals. CRM aggregates may be included
under `crm` when the Free analytics reader is available.

Published Pro hooks:

| Hook | Arguments |
|---|---|
| `nxtcc_pro_workflow_external_event_emitted` | `$result`, `$event_data` |
| `nxtcc_pro_segment_saved` | `$segment`, `$args` |
| `nxtcc_pro_segment_deleted` | `$segment` |
| `nxtcc_pro_abandoned_cart_changed` | `$cart` |
| `nxtcc_pro_broadcast_run_created` | `$result`, `$context`, `$tenant` |

Register the matching accepted-argument count with `add_action()`. Treat all
rows and contexts received by these hooks as tenant-scoped data.

## REST and Remote Integrations

NXT Cloud Chat registers its own webhook/authentication routes and Pro workflow
admin routes. They are not a generic remote CRM integration API.

Use local PHP wrappers for plugins running on the same site. If remote access is
required, create your own narrow REST endpoint:

```php
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'example/v1',
			'/contacts/(?P<id>\d+)/unsubscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'example_can_manage_nxtcc_contact',
				'callback'            => 'example_unsubscribe_nxtcc_contact',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}
);

function example_can_manage_nxtcc_contact( WP_REST_Request $request ): bool {
	if (
		! current_user_can( 'nxtcc_manage_contacts' )
		|| ! function_exists( 'nxtcc_user_can_manage_contact' )
	) {
		return false;
	}

	$tenant = example_get_authorized_tenant_for_user( get_current_user_id() );
	return example_nxtcc_tenant_is_complete( $tenant )
		&& nxtcc_user_can_manage_contact( absint( $request['id'] ), $tenant );
}

function example_unsubscribe_nxtcc_contact( WP_REST_Request $request ) {
	if ( ! function_exists( 'nxtcc_update_contact_subscription_status' ) ) {
		return new WP_Error( 'nxtcc_unavailable', 'NXT Cloud Chat is unavailable.', array( 'status' => 503 ) );
	}

	$tenant = example_get_authorized_tenant_for_user( get_current_user_id() );
	$result = nxtcc_update_contact_subscription_status(
		array_merge(
			$tenant,
			array(
				'contact_id' => absint( $request['id'] ),
				'status'     => 'unsubscribed',
				'reason'     => 'example_rest_preference',
			)
		)
	);

	return ! empty( $result['success'] )
		? rest_ensure_response( $result )
		: new WP_Error( 'nxtcc_update_failed', 'Unable to update consent.', array( 'status' => 400 ) );
}
```

`example_get_authorized_tenant_for_user()` is intentionally application-specific.
It must return only a tenant the authenticated user is allowed to access.

For off-site callers, add authentication, throttling, replay protection for
writes, and audit logging. Do not make a proxy that exposes every runtime
wrapper through one endpoint.

## Free API Index

These are the Free-owned stable runtime functions published in 1.1.0. Array
writers are documented by domain in the examples above; always include the
complete tenant tuple in tenant-scoped argument arrays.

### Runtime, credentials, messaging, and health

```php
nxtcc_get_runtime_contract(): array
nxtcc_get_runtime_capabilities(): array
nxtcc_has_runtime_capability( string $capability ): bool

nxtcc_get_tenant_api_credentials(
	string $user_mailid,
	string $business_account_id,
	string $phone_number_id
): array|false

nxtcc_send_session_reply( array $args ): array
nxtcc_send_background_session_reply( array $args ): array

nxtcc_get_message_history_after_id(
	int $after_id = 0,
	int $limit = 100,
	array $filters = array()
): array

nxtcc_get_message_history_id_by_wamid( string $wamid ): int

nxtcc_get_latest_inbound_at(
	int $contact_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): ?string

nxtcc_get_latest_verified_phone_for_user( int $user_id ): string
nxtcc_get_meta_health_status( array $tenant = array(), array $args = array() ): array
```

### Contacts, tags, and groups

```php
nxtcc_get_contact_by_id(
	int $contact_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): ?array

nxtcc_get_contact_by_phone(
	string $phone_number,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): ?array

nxtcc_get_contact_by_wp_user(
	int $user_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): ?array

nxtcc_upsert_contact_for_integration( array $args ): array
nxtcc_update_contact_subscription_status( array $args ): array

nxtcc_get_contact_groups_by_id(
	int $contact_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): array

nxtcc_list_contact_groups( array $tenant ): array
nxtcc_list_contact_tags( array $tenant, array $args = array() ): array

nxtcc_get_contact_tags_by_id(
	int $contact_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): array

nxtcc_upsert_contact_tag( array $args ): array
nxtcc_update_contact_tags( array $args ): array
nxtcc_find_contact_duplicate_candidates( int $contact_id, array $tenant, int $limit = 20 ): array
nxtcc_merge_contacts( array $args ): array
```

### Assignments, Access Teams, and policy

```php
nxtcc_list_contact_assignment_targets( array $tenant ): array

nxtcc_get_contact_assignment(
	int $contact_id,
	string $user_mailid = '',
	string $business_account_id = '',
	string $phone_number_id = ''
): ?array

nxtcc_update_contact_assignment( array $args ): array
nxtcc_auto_assign_contact( array $args ): array

nxtcc_get_crm_access_policy(
	int $user_id = 0,
	array $tenant = array(),
	string $capability = ''
): array

nxtcc_user_can_view_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool
nxtcc_user_can_manage_contact( int $contact_id, array $tenant = array(), int $user_id = 0 ): bool

nxtcc_filter_contact_ids_by_access(
	array $contact_ids,
	array $tenant = array(),
	bool $manage = false,
	int $user_id = 0
): array

nxtcc_get_access_teams( array $tenant = array() ): array
```

### Conversations

```php
nxtcc_get_conversation( int $conversation_id, array $tenant ): ?array
nxtcc_get_or_create_conversation( int $contact_id, array $tenant, array $args = array() ): ?array
nxtcc_update_conversation( array $args ): array
nxtcc_assign_conversation( array $args ): array
nxtcc_auto_assign_conversation( array $args ): array
nxtcc_add_conversation_note( array $args ): array
nxtcc_set_conversation_watcher( array $args ): array
nxtcc_get_conversation_activities( int $conversation_id, array $tenant, int $limit = 100 ): array
nxtcc_get_conversation_sla_targets(): array
nxtcc_list_conversation_sla_candidates( array $tenant, int $after_id = 0, int $limit = 100 ): array
nxtcc_user_can_view_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool
nxtcc_user_can_manage_conversation( int $conversation_id, array $tenant = array(), int $user_id = 0 ): bool
```

### CRM activities, lifecycle, tasks, and saved views

```php
nxtcc_get_crm_activity_types(): array
nxtcc_get_contact_crm_activities( int $contact_id, array $tenant, array $args = array() ): array
nxtcc_record_crm_activity( array $args ): array

nxtcc_list_lifecycle_stages( array $tenant, bool $active_only = true ): array
nxtcc_upsert_lifecycle_stage( array $args ): array
nxtcc_get_contact_lifecycle_stage( int $contact_id, array $tenant ): ?array
nxtcc_set_contact_lifecycle_stage( array $args ): array

nxtcc_list_contact_crm_tasks( int $contact_id, array $tenant, array $args = array() ): array
nxtcc_get_crm_task( int $task_id, array $tenant ): ?array
nxtcc_create_crm_task( array $args ): array
nxtcc_update_crm_task( array $args ): array

nxtcc_list_crm_saved_views( array $tenant, int $owner_user_id = 0 ): array
nxtcc_get_crm_saved_view( int $view_id, array $tenant, int $owner_user_id = 0 ): ?array
nxtcc_upsert_crm_saved_view( array $args ): array
nxtcc_delete_crm_saved_view( array $args ): array
```

### Pipelines, deals, line items, and analytics

```php
nxtcc_list_crm_pipelines( array $tenant, bool $active_only = true ): array
nxtcc_upsert_crm_pipeline( array $args ): array
nxtcc_list_crm_pipeline_stages( int $pipeline_id, array $tenant, bool $active_only = true ): array
nxtcc_upsert_crm_pipeline_stage( array $args ): array
nxtcc_get_crm_pipeline_overview( array $tenant, bool $include_inactive = true ): array
nxtcc_duplicate_crm_pipeline( array $args ): array
nxtcc_delete_crm_pipeline( array $args ): array
nxtcc_delete_crm_pipeline_stage( array $args ): array
nxtcc_reorder_crm_pipeline_stages( array $args ): array

nxtcc_get_crm_deal_item_providers(): array
nxtcc_search_crm_deal_items( string $provider_id, string $search, array $tenant, int $limit = 20 ): array
nxtcc_resolve_crm_deal_item( string $provider_id, string $item_id, array $tenant ): ?array

nxtcc_get_crm_analytics( array $tenant, array $args = array() ): array
nxtcc_list_crm_deals( array $tenant, array $args = array() ): array
nxtcc_count_crm_deals( array $tenant, array $args = array() ): int
nxtcc_get_crm_deal( int $deal_id, array $tenant ): ?array
nxtcc_list_contact_crm_deals( int $contact_id, array $tenant, array $args = array() ): array
nxtcc_create_crm_deal( array $args ): array
nxtcc_update_crm_deal( array $args ): array
nxtcc_delete_crm_deal( array $args ): array
nxtcc_user_can_view_crm_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool
nxtcc_user_can_manage_crm_deal( int $deal_id, array $tenant = array(), int $user_id = 0 ): bool
```

### Allowlisted contact queries

```php
nxtcc_normalize_contact_query_filters( array $filters ): array
nxtcc_get_contact_query_providers(): array
nxtcc_query_contact_ids( array $tenant, array $filters = array(), array $args = array() ): array
nxtcc_count_contacts_by_query( array $tenant, array $filters = array(), array $args = array() ): int
nxtcc_contact_matches_query( int $contact_id, array $tenant, array $filters = array(), array $args = array() ): bool
```

## Pro API Index

All operational Pro wrappers require an active license and the Free plugin.

### Discovery and readiness

```php
nxtcc_pro_get_runtime_contract(): array
nxtcc_pro_get_runtime_capabilities(): array
nxtcc_pro_has_runtime_capability( string $capability ): bool
nxtcc_pro_runtime_integration_ready(): bool
nxtcc_pro_segment_runtime_ready(): bool
```

### Workflows

```php
nxtcc_pro_register_workflow_trigger( array $args ): array
nxtcc_pro_emit_workflow_event( array $args ): array
nxtcc_pro_dispatch_workflow_event( array $args ): array
nxtcc_pro_list_workflows( array $tenant, array $args = array() ): array
nxtcc_pro_list_workflow_runs( array $tenant, array $args = array() ): array
nxtcc_pro_get_workflow_run( int $run_id, array $tenant ): ?array
```

### Dynamic segments and commerce index

```php
nxtcc_pro_list_segments( array $tenant, array $args = array() ): array
nxtcc_pro_get_segment( int $segment_id, array $tenant ): ?array
nxtcc_pro_evaluate_segment( int $segment_id, array $tenant, array $args = array() ): array
nxtcc_pro_contact_matches_segment( int $segment_id, int $contact_id, array $tenant ): bool
nxtcc_pro_save_segment( array $args ): array
nxtcc_pro_delete_segment( int $segment_id, array $tenant ): array
nxtcc_pro_get_segment_filter_catalog(): array
nxtcc_pro_refresh_commerce_index(): array
```

### Broadcasts, abandoned carts, and analytics

```php
nxtcc_pro_create_broadcast_run( array $args ): array
nxtcc_pro_list_broadcast_runs( array $tenant, array $args = array() ): array
nxtcc_pro_get_broadcast_run( string $broadcast_id, array $tenant ): ?array
nxtcc_pro_list_abandoned_carts( array $tenant, array $args = array() ): array
nxtcc_pro_get_abandoned_cart( int $cart_id, array $tenant ): ?array
nxtcc_pro_get_automation_analytics( array $tenant, array $args = array() ): array
```

## Performance and Reliability

### Bound every list

- Use documented `limit`, `page`, `after_id`, and date-range arguments.
- Prefer cursor pagination for background contact processing.
- Never load an entire tenant into memory.
- Do not call remote APIs inside interactive token, segment, or line-item loops.

### Use current data at execution time

- Re-read subscription before marketing sends.
- Evaluate dynamic segment membership when executing the operation.
- Re-read assignment before a handoff or overwrite.
- Re-read a workflow/broadcast run when displaying current status.

### Use idempotency

Use stable keys for:

- Workflow events: `dedupe_key`.
- Broadcast runs: `source` plus `idempotency_key`.
- Contact sync: stable `source` plus `external_id`.
- Round-robin routing: stable `route_key`.
- Hook listeners: store the source object/event ID already processed.

### Cache safely

If your plugin caches integration data:

- Include the complete tenant tuple in the cache key.
- Use short expirations for health, analytics, or list summaries.
- Do not cache access decisions longer than the current request.
- Do not cache credentials in public object caches unless encrypted and
  explicitly reviewed.

### Time handling

Runtime storage timestamps are generally UTC. Compare and persist raw UTC
values. Use `wp_date()` with the site timezone for display. Pro and Free admin
interfaces may also return explicit display fields; do not use formatted values
for sorting or scheduling.

### Logging

Log safe error codes and local identifiers. Avoid full payloads. A useful log
entry contains:

- Integration source key.
- Tenant-safe hash or internal tenant ID, not credentials.
- Contact/deal/run/event ID.
- Wrapper error code.
- Retry count.

## Testing Checklist

Before releasing an integration, verify all of the following.

### Compatibility

- Free missing: your plugin fails closed without fatal errors.
- Free active: required capability keys are discovered.
- Pro missing: all Pro features remain disabled without errors.
- Pro installed but unlicensed: Pro writes fail closed.
- Pro licensed: extension discovery and required capabilities succeed.

### Tenant isolation

- Every wrapper call contains the complete tenant tuple.
- A record ID from another tenant cannot be read or changed.
- Browser/REST tenant values are mapped to an authorized server-side tenant.
- Cache keys include all tenant identifiers.

### Authorization

- Admin forms and AJAX handlers verify nonces.
- REST routes have strict permission callbacks.
- View and manage checks are tested separately.
- Assigned, team, and all scopes are tested.
- Bulk operations filter IDs through the current access policy.

### Consent and messaging

- Unsubscribed contacts are excluded from promotional sends.
- A contact is not resubscribed by an ordinary data sync.
- Explicit opt-in can resubscribe with an audit reason.
- Session replies are attempted only inside the allowed service window.
- Background send failures are handled without exposing credentials.

### CRM behavior

- Tag updates are idempotent.
- Existing assignments are preserved unless overwrite is intended.
- Handoffs require and record an internal note.
- Deal stage reasons satisfy stage requirements.
- Referenced pipeline stages are archived instead of blindly deleted.
- Contact merges require explicit confirmation.

### Automation

- Workflow trigger registration runs after plugins are loaded.
- Workflow event dedupe keys remain stable on retries.
- Event payloads are sanitized and bounded.
- Broadcast retries reuse the same idempotency key.
- Segment membership and subscription are rechecked at execution time.

### Quality

- Run PHP syntax checks on integration files.
- Run PHPCS with the WordPress Coding Standards.
- Test with `WP_DEBUG` and database error logging enabled.
- Test multisite if your plugin supports multisite.
- Test cleanup without deleting NXT Cloud Chat-owned data.

## Final Integration Rules

1. Discover capabilities before calling optional wrappers.
2. Pass the complete tenant tuple.
3. Enforce WordPress authorization and NXT Cloud Chat record access.
4. Keep consent decisions close to the message's business purpose.
5. Use bounded queries, cursor pagination, and idempotency keys.
6. Use hooks for events and wrappers for reads/writes.
7. Never expose credentials or private CRM payloads.
8. Never write NXT Cloud Chat Free or Pro tables directly.

When a required operation is not present in the runtime contract, request a
new stable wrapper rather than binding an integration to internal implementation
details.
