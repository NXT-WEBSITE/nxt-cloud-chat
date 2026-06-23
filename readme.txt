=== NXT Cloud Chat – CRM, Inbox & OTP Login ===
Contributors: nxtwebsite
Tags: whatsapp, whatsapp business, crm, woocommerce, login
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WhatsApp Cloud API plugin for WordPress with CRM inbox, sales pipelines, team access, broadcasts, automation, abandoned cart recovery & OTP login.

== Description ==

**[NXT Cloud Chat](https://nxtcloudchat.com/?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-free "NXT Cloud Chat")** connects WordPress directly to Meta's official WhatsApp Cloud API. Manage customer conversations, CRM contacts, sales pipelines, team access, and OTP login from your WordPress admin while keeping CRM data in your own database.

It is designed for stores, sales teams, support teams, membership sites, agencies, and developers that need a self-hosted WhatsApp CRM without a separate inbox SaaS.

Everything runs from the familiar WordPress admin area, so your team can manage conversations, contacts, sales follow-ups, login verification, and customer records in one connected workspace.

= Free Plugin =

* Shared inbox with conversation tickets, priorities, assignments, internal notes, saved views, and SLA indicators.
* Contacts, groups, tags, lifecycle stages, follow-up tasks, ownership, duplicate detection, and activity history.
* Sales pipelines, configurable stages, deals, line items, values, reasons, and stage history.
* Access Teams with module permissions and assigned, team, or all-record scopes.
* WhatsApp OTP login for WordPress and WooCommerce My Account.
* Message history, delivery status, incoming webhooks, connection health, and secure media access.
* Stable PHP wrappers, hooks, runtime capability discovery, and developer documentation for integrations.

= Pro Add-on =

NXT Cloud Chat Pro adds approved template sync and sending, bulk broadcasts, dynamic segments, WooCommerce order communication, abandoned cart recovery, visual workflows, automation logs, retries, analytics, and CSV exports.

= Setup and Privacy =

Connect your own Meta app, business account, phone number ID, and access token. NXT Cloud Chat communicates with Meta only for the messaging, webhook, template, media, and health operations you configure. Credentials are encrypted at rest when supported, and contacts, messages, deals, and CRM records remain in your WordPress database.

* [Step-by-step setup guide](https://nxtcloudchat.com/user-guide?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-docs "NXT Cloud Chat Documentation")
* [Plugin website](https://nxtcloudchat.com/?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-free "NXT Cloud Chat")
* [Upgrade to NXT Cloud Chat Pro](https://nxtwebsite.com/wordpress/nxt-cloud-chat/?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-upgrade "Upgrade to NXT Cloud Chat Pro")

== External Services ==

This plugin connects to the official **WhatsApp Business Platform / WhatsApp Cloud API** provided by Meta Platforms, Inc. Using this plugin means data will be transmitted from your WordPress site to Meta's servers as described below.

= What It Is Used For =

* **1-to-1 WhatsApp messaging** – send and receive WhatsApp messages between your business number and your contacts from the WordPress Chat Window.
* **WhatsApp OTP login** – send one-time passwords to users' WhatsApp numbers for passwordless WordPress or WooCommerce login.
* **Template messaging** – sync approved WhatsApp message templates from your Meta Business account and send them for notifications, authentication, broadcasts, and automation (Pro).
* **Broadcast campaigns** – send bulk WhatsApp campaigns to selected contacts using approved templates (Pro).
* **Webhook event handling** – receive inbound messages, delivery status updates, read receipts, and message events from the WhatsApp Cloud API.
* **WooCommerce notifications** – send order and customer communication messages via WhatsApp (Pro).
* **COD verification workflows** – use WhatsApp login or automated WhatsApp confirmation messages to help verify COD orders and reduce fake orders or failed deliveries (Pro).
* **Abandoned cart recovery** – send WhatsApp reminders for abandoned WooCommerce carts (Pro).
* **Workflow-triggered messages** – send WhatsApp messages based on user login, WooCommerce events, CRM events, deal changes, conversation events, or custom triggers (Pro).
* **Connection diagnostics** – verify your API credentials and send test template messages to confirm that your WhatsApp Cloud API setup is working correctly.

= What Data Is Sent and When =

* **During setup** – your App ID, App Secret, Business Account ID, Phone Number ID, and Access Token are stored in your WordPress database and used server-side to authenticate API calls and verify incoming webhook signatures (`X-Hub-Signature-256`). These values are not printed into public pages or client-side JavaScript.
* **When sending or receiving WhatsApp messages** – the recipient phone number, message content, template name, template parameters, media attachments, and delivery metadata are sent to or received from the WhatsApp Cloud API.
* **During WhatsApp OTP login** – the user's phone number and OTP message template data are sent to the WhatsApp Cloud API.
* **During template sync** – your Business Account ID and Phone Number ID are used to fetch and synchronise approved templates from your Meta account.
* **During webhook delivery** – Meta sends inbound messages, delivery receipts, read events, and status payloads to your WordPress webhook endpoint. These are validated server-side using your App Secret before being stored.

= Service Provider =

WhatsApp Business Platform / WhatsApp Cloud API by Meta Platforms, Inc.

* [Meta Privacy Policy](https://www.facebook.com/privacy/policy/)
* [WhatsApp Privacy Policy](https://www.whatsapp.com/legal/privacy-policy)
* [Meta Terms for WhatsApp Business](https://www.whatsapp.com/legal/meta-terms-whatsapp-business)
* [WhatsApp Business Solution Terms](https://www.whatsapp.com/legal/business-solution-terms)
* [WhatsApp Business Terms of Service](https://www.whatsapp.com/legal/business-terms)
* [WhatsApp Business Messaging Policy](https://business.whatsapp.com/policy)
* [WhatsApp Messaging Guidelines](https://www.whatsapp.com/legal/messaging-guidelines)
* [WhatsApp Cloud API Pricing](https://business.whatsapp.com/products/platform-pricing)

You are responsible for ensuring that your use of this plugin and the WhatsApp Cloud API complies with applicable laws, your privacy policy, Meta's platform rules, and WhatsApp Business policies.

== Features ==

= Dashboard =

View the health of your WhatsApp connection, CRM activity, and automation status from one central dashboard.

* Connection Health, Messaging Health, and Calling Health status cards.
* Live status indicators for WhatsApp Cloud API credentials and webhook configuration.
* Setup help links and support badge for guided onboarding.
* CRM, SLA, lifecycle, tag, deal, automation, broadcast, and abandoned cart reporting (Pro).
* Secure selected-range CSV exports with permission checks (Pro).

= Deals – Sales Pipelines and Deal Tracking =

Manage your WhatsApp leads and sales opportunities directly inside WordPress.

* Create pipelines with custom stages, colours, ordering, and required stage reasons.
* Manage deals with owners, priorities, values, expected close dates, and line items.
* Track stage-transition history and review deal activity in the contact timeline.
* Duplicate, archive, or delete deals using guarded confirmation flows.
* Use stable pipeline, deal, lifecycle, and line-item wrappers for integrations.

= Chat Window – WhatsApp Inbox =

A shared WhatsApp inbox for sales, support, and customer communication.

* Browse, filter, and reply to WhatsApp conversations from WordPress.
* Manage tickets with status, priority, category, followers, notes, and handoff notes.
* Assign tickets to users, teams, or queues with manual or automated routing.
* Use saved inbox views: All, Mine, Team Queue, Unassigned, Overdue, and Recently Resolved.
* Track SLA targets for first response and resolution.
* Store message history with timestamps, delivery status, reply context, reactions, and secure media access.

= Contacts – CRM Contact Management =

A WordPress-native CRM contacts system built around WhatsApp customer communication.

* Store phone number, country, name, custom fields, subscription status, and unsubscribe reasons.
* Track lifecycle stages such as new lead, active prospect, customer, or closed customer.
* Create follow-up tasks, assign owners, and use saved contact views.
* Detect and merge duplicate contacts with guarded confirmation flows.
* Review contact profiles and CRM activity timelines from Contacts or Chat Window.
* Use stable contact upsert wrappers for safe integrations.

= Groups =

Organise contacts into groups for better targeting and messaging.

* Create groups such as Leads, Members, VIP Customers, COD Customers, or Repeat Buyers.
* Assign contacts manually or in bulk.
* Use groups for broadcast targeting and workflow logic.
* Verified-group protections help prevent accidental modification of important groups.

= Tags =

Label and segment contacts using flexible CRM tags.

* Create colour-coded CRM tags with descriptions.
* Assign tags manually, in bulk, during import, or through compatible integrations.
* Filter contacts by any, all, or none of selected tags.
* Preserve tag names in exports and use stable tag wrappers for integrations.

= Templates (Pro) =

Manage approved WhatsApp message templates from WordPress.

* Create and manage templates directly from WordPress, and sync with Meta.
* Browse templates by category such as marketing, utility, and authentication.
* Use image headers, CTA buttons, quick replies, coupon codes, and dynamic placeholders.
* Send individual template messages from Chat Window or Contacts.
* Use templates in broadcasts, workflows, WooCommerce notifications, and abandoned cart recovery.

= Send Broadcasts (Pro) =

Send official WhatsApp bulk campaigns using approved message templates.

* Build campaigns for selected groups or Dynamic Segments.
* Use CTA buttons and approved template content.
* Personalise messages with placeholders and target subscribed contacts.
* Schedule campaigns or send immediately, then track delivery and read status.

= Abandoned Carts (Pro) =

Recover WooCommerce carts with WhatsApp reminders.

* Track stale WooCommerce carts in a dedicated Abandoned Carts screen.
* Review cart value, time abandoned, recovery status, and customer context.
* Send WhatsApp recovery messages through workflows.
* Configure stale-cart thresholds, retention periods, and cleanup schedules.

= COD Order Verification =

NXT Cloud Chat can help WooCommerce stores verify COD customers and reduce avoidable returns.

* Use WhatsApp OTP login so customers verify their WhatsApp number before account access or checkout flows.
* Sync verified WordPress users into CRM Contacts.
* Use tags, lifecycle stages, or groups to identify verified COD customers.
* Use Pro workflows to send COD confirmation templates after order placement.
* Add follow-up tasks for unconfirmed or high-risk COD orders.
* Help reduce fake orders, unreachable customers, failed delivery attempts, and return-to-origin losses.

= Segments (Pro) =

Build dynamic contact audiences for campaigns and automation.

* Create tenant-scoped Dynamic Segments with a live rule builder.
* Segment by subscription status, tags, groups, lifecycle stages, ownership, conversation state, tasks, and dates.
* Add WooCommerce filters such as order value, order count, products, categories, coupons, refunds, statuses, and abandoned carts.
* Preview audience size before sending.

= Workflows (Pro) =

Automate WhatsApp messaging and CRM actions without coding.

* Trigger workflows from incoming messages, user logins, WooCommerce orders, abandoned carts, conversation changes, deal changes, contact lifecycle changes, tasks, or compatible plugin events.
* Add conditions for tags, groups, lifecycle stages, ownership, segments, tasks, conversation state, SLA status, deal stage, order value, and cart context.
* Send approved WhatsApp template messages.
* Update contacts, tags, assignments, lifecycle stages, follow-up tasks, deals, line items, conversations, priorities, notes, and escalations.

= Workflow Runs (Pro) =

Monitor every automation execution.

* View workflow logs with trigger event, contact, status, and timestamps.
* Drill into individual runs to see step-by-step execution.
* Review or retry failed runs and track broadcast or abandoned cart automation status.

= History =

Keep a complete record of WhatsApp messages.

* Review inbound and outbound messages.
* Filter by contact, direction, date range, or status.
* View delivery status, read receipts, timestamps, template names, and contact details.
* Use history for reporting, debugging, compliance review, and customer context.

= Authentication – WhatsApp OTP Login =

Offer passwordless login using WhatsApp OTP.

* Shortcode: `[nxtcc_login_whatsapp]`
* Users choose their country, enter their phone number, receive an OTP via WhatsApp, and verify.
* Verified users are linked to their WhatsApp number for future login.
* Optional WhatsApp login buttons for the WordPress login page and WooCommerce My Account page.
* Optional force-migration page for moving existing users to WhatsApp login.
* Verified WordPress users can be synced into CRM Contacts.
* Admin controls for OTP length, cooldown, countries, button text, colours, corner style, and branding.

= Settings – WhatsApp Cloud API Configuration =

Configure WhatsApp Cloud API securely from WordPress.

* Save App ID, App Secret, Access Token, Phone Number ID, Business Account ID, and display phone number.
* Run connection diagnostics and test template sends.
* Generate Verify Token and copy Callback URL for Meta webhook setup.
* Verify webhook signatures with App Secret-based `X-Hub-Signature-256` validation.
* Manage cleanup, retention, uninstall data removal, Team Access permissions, and record scopes.

= Team Access and Permissions =

Give users the right level of access for their role.

* Create tenant Access Teams for WordPress users.
* Set view-only or manage permission per module.
* Control access for Dashboard, Deals, Chat Window, Contacts, Groups, Tags, Templates, Broadcasts, Abandoned Carts, Segments, Workflows, History, Authentication, and Settings.
* Set record scopes such as assigned records, team queue, or all records.
* Apply CRM access-policy enforcement across inbox, contacts, imports, exports, media downloads, and forwarding.

= User-Friendly and Developer-Friendly =

NXT Cloud Chat is designed for everyday WordPress users, business teams, agencies, and developers.

**For users and teams:**

* Clean WordPress admin screens.
* Guided setup links and support badge.
* Contact, group, tag, deal, inbox, CRM, sales pipeline, and OTP login tools in one place.
* Team access controls without custom coding.
* Useful for support, sales, ecommerce, COD order verification, and customer follow-up.

**For developers and agencies:**

* Free plugin and Pro add-on are separate and extensible.
* Database schema, DAO helpers, filters, actions, runtime discovery, and stable wrappers for compatible integrations.
* Documented integration contracts for extension development.

== Installation ==

1. Install NXT Cloud Chat from **Plugins <span aria-hidden="true" class="wp-exclude-emoji">→</span> Add New**, or upload it to `/wp-content/plugins/`, then activate it.
2. Open **NXT Cloud Chat <span aria-hidden="true" class="wp-exclude-emoji">→</span> Settings**.
3. Enter the App ID, App Secret, Access Token, Phone Number ID, Business Account ID, and optional display number.
4. Save and run the connection diagnostic.

=== WhatsApp Webhook Setup ===

1. Enable incoming messages in the Settings webhook section.
2. Copy its **Callback URL** and **Verify Token**.
3. In Meta **WhatsApp <span aria-hidden="true" class="wp-exclude-emoji">→</span> Configuration**, add both values and subscribe to `messages`.
4. Complete verification. Keep the matching App Secret saved for `X-Hub-Signature-256` validation.

=== WhatsApp Login Setup ===

1. Configure OTP and country options under **NXT Cloud Chat <span aria-hidden="true" class="wp-exclude-emoji">→</span> Authentication**.
2. Publish a page containing `[nxtcc_login_whatsapp]`.
3. Optionally enable login-page/My Account buttons and an existing-user migration URL.

== Frequently Asked Questions ==

= Is NXT Cloud Chat an official WhatsApp Cloud API plugin for WordPress? =

NXT Cloud Chat connects WordPress with the official WhatsApp Business Platform / WhatsApp Cloud API provided by Meta.

= Do I need a Meta developer account? =

Yes. You need a Meta developer account and a WhatsApp Cloud API app to get your App ID, App Secret, Business Account ID, Phone Number ID, and Access Token. The documentation explains the setup process.

= Do I need to pay NXT Cloud Chat per WhatsApp message? =

No. NXT Cloud Chat does not charge per message. WhatsApp Cloud API conversation or messaging charges, if applicable, are charged by Meta according to Meta's pricing policy.

= Is my data stored on a third-party SaaS server? =

No. Your plugin data, contacts, messages, CRM records, deals, tags, groups, settings, and activity history are stored inside your own WordPress database. WhatsApp messages are still sent through Meta's official WhatsApp Cloud API because that is required to deliver WhatsApp messages.

= Can I use NXT Cloud Chat for small, medium, and large businesses? =

Yes. NXT Cloud Chat is suitable for small businesses, growing teams, medium businesses, agencies, and larger organisations that want a WordPress-native WhatsApp CRM, shared inbox, team access, and automation system.

= Can WhatsApp verification help reduce returns or RTO? =

Yes, it can help reduce avoidable returns and return-to-origin risk by verifying the customer's WhatsApp number, improving customer reachability, and enabling COD confirmation messages. Results depend on your store process, shipping workflow, and customer communication policy.

= Can I use only WhatsApp OTP login without using the CRM inbox? =

Yes. You can configure WhatsApp Cloud API credentials, create a login page with `[nxtcc_login_whatsapp]`, and use WhatsApp OTP login without actively using the inbox, deals, or CRM modules.

= Does the plugin support WooCommerce My Account login? =

Yes. You can enable a WhatsApp login button on the WooCommerce My Account login page from the Authentication settings screen.

= Can customers log in without a password? =

Yes. Customers can log in using a WhatsApp one-time password. After verification, their WordPress user account is linked to their WhatsApp number.

= Can I manage WhatsApp messages from WordPress admin? =

Yes. The Chat Window works as a WordPress admin WhatsApp inbox where you can view conversations, reply to messages, manage tickets, assign conversations, view contact profiles, and check message history.

= Does NXT Cloud Chat include a CRM? =

Yes. It includes CRM contacts, lifecycle stages, tags, groups, contact ownership, follow-up tasks, duplicate detection, contact profile, activity timeline, deals, pipelines, and team access controls.

= Can I track sales pipelines and deals in the free plugin? =

Yes. Sales pipelines, deal stages, deal management, line items, deal values, stage-transition history, and CRM activity history are included in the free plugin. Some advanced reporting and external line-item sources may require Pro or compatible integrations.

= Can I send bulk WhatsApp messages? =

Yes, with Pro broadcasts using approved WhatsApp templates. You must follow Meta's WhatsApp Business policies and only message contacts where you have proper permission or opt-in.

= Can I use WhatsApp templates with buttons and placeholders? =

Yes. Pro supports approved WhatsApp templates with dynamic placeholders, CTA buttons, quick replies, image headers, and coupon codes where supported by Meta template rules.

= Does NXT Cloud Chat support team members? =

Yes. Team Access lets you assign module permissions, action levels, record scopes, and assignment eligibility for different WordPress users.

= Can I assign chats and contacts to team members? =

Yes. Contacts and conversation tickets can be assigned manually or through routing systems such as round-robin and least-busy assignment.

= Does the plugin support SLA tracking? =

Yes. Conversation tickets can track priority-based first-response and resolution SLA targets, and overdue tickets can be filtered in the inbox.

= Can developers extend NXT Cloud Chat? =

Yes. The plugin includes hooks, filters, DAO helpers, runtime wrappers, integration contracts, and extension points for developers and compatible add-ons.

= Will my WhatsApp access token be exposed to visitors? =

No. API credentials are used server-side and are not printed into public JavaScript or public pages.

= Does the plugin verify webhook signatures? =

Yes. Incoming webhook payloads can be verified using App Secret-based `X-Hub-Signature-256` validation.

= Where are WhatsApp messages stored? =

Inbound and outbound WhatsApp messages, statuses, reactions, reply context, and related history are stored in custom database tables inside your WordPress installation.

= What happens when I uninstall the plugin? =

If you enable **Delete all data on uninstall** before uninstalling, the plugin removes its custom database tables, options, transients, scheduled events, and upload directories. This action is irreversible, so use it carefully.

= Where can I upgrade to Pro? =

You can upgrade from the plugin website: [Upgrade to NXT Cloud Chat Pro](https://nxtwebsite.com/wordpress/nxt-cloud-chat/?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-upgrade "Upgrade to NXT Cloud Chat Pro").

= Where can I find documentation? =

The full documentation is available at [NXT Cloud Chat User Guide](https://nxtcloudchat.com/user-guide?utm_source=wp&utm_medium=readme&utm_campaign=nxt-cloud-chat-docs "NXT Cloud Chat Documentation").

== Screenshots ==

1. **Chat Window** – Shared WhatsApp inbox with tickets, assignees, inbox filters, SLA indicators, contact profile, and full message history.
2. **Deals** – Sales pipeline board with custom stages, deal cards, expected close dates, stage reasons, stage-transition history, and deal modals.
3. **Dashboard** – WhatsApp connection health, messaging and calling status cards, CRM workload summary, and setup help links.
4. **Contacts** – CRM contacts list with lifecycle stages, tags, ownership, follow-up tasks, saved views, duplicate detection, and bulk actions.
5. **Groups** – Contact group management for leads, members, customers, COD buyers, and custom audiences.
6. **Tags** – CRM tag management with colours, descriptions, bulk assignment, contact filtering, and export support.
7. **Templates** – WhatsApp template list with sync, category badges, CTA buttons, image headers, and per-contact send actions (Pro).
8. **Send Broadcasts** – Bulk WhatsApp campaign builder for approved template messages and segmented audiences (Pro).
9. **Abandoned Carts** – WooCommerce abandoned cart recovery table with cart value, time abandoned, and recovery status (Pro).
10. **Segments** – Dynamic segment builder with rule conditions, live membership preview, and WooCommerce filters (Pro).
11. **Workflows** – Visual WhatsApp automation builder with CRM, deal, conversation, login, WooCommerce, and abandoned cart triggers (Pro).
12. **Workflow Runs** – Workflow execution log with trigger events, matched contacts, step results, status, and retry controls (Pro).
13. **History** – WhatsApp message history with delivery status, read receipts, timestamps, template names, and contact details.
14. **Authentication** – WhatsApp OTP login settings with OTP length, cooldown, allowed countries, force-migration URL, and login button appearance.
15. **WhatsApp login widget** – WhatsApp login widget embedded on a WordPress page.
16. **WhatsApp login buttons** – WhatsApp login buttons for the default WordPress login page and WooCommerce My Account login page.
17. **Settings** – WhatsApp Cloud API credentials, connection diagnostics, webhook URL, verify token, team access, and retention tools.

== Changelog ==

= 1.1.1 =
* Added credential-free public wrappers for listing configured tenant profiles and resolving an exact tenant profile.
* Added `nxtcc_get_primary_display_phone_number()` for safely reading the primary connection's digits-only display phone number.
* Published runtime capability discovery for the new connection-profile readers.
* Fixed connection-profile and credential caches remaining stale after connection settings were updated.

= 1.1.0 =
* Added a secure Pro Dashboard aggregate CRM and automation CSV export powered by the shared Free-owned analytics contract.
* Added personal tenant-scoped saved Contact views with a compact selector, default views, secure allowlisted filters, and stable integration wrappers.
* Added a Free-owned aggregate CRM analytics contract for contact health, current workload, tasks, response and resolution time, SLA compliance, lifecycle and tag distributions, and currency-safe deal reporting.
* Redesigned Deals with route-backed Pipelines and Deals views, compact scrollable management modals, live external-source line-item search, integer quantities, deal deletion, stage preview/reordering, duplication, and guarded archive/delete behavior.
* Added configurable per-stage reason requirements and authoritative deal stage-transition history.
* Added manual and calculated deal values plus extensible line items with Manual, WooCommerce, Houzez, and external provider support.
* Added a bounded Free-owned line-item provider contract with stable discovery, search, resolve, pipeline lifecycle, and management wrappers.
* Added Free-owned sales pipelines, stages, manual deal management, contact and line-item links, ownership, expected close dates, stage reasons, and deal activity history.
* Added stable tenant-scoped pipeline/deal wrappers, hooks, capabilities, and CRM deal access-policy checks for Pro and external integrations.
* Added a shared allowlisted, tenant-scoped contact query runtime for secure dynamic segment and external integration use.
* Added a locked Segments Pro menu entry and documented the shared contact-query and Pro segment integration contracts.
* Added a bounded contact-query provider contract so Pro and compatible external plugins can register validated, indexed segment properties.
* Added tenant-scoped CRM lifecycle stages, follow-up tasks, strong duplicate suggestions, and guarded contact merging.
* Added stable lifecycle, task, duplicate-reader, and contact-merge runtime wrappers for Pro and external integrations.
* Expanded the shared Contact Profile with lifecycle controls, follow-up tasks, and confirmed duplicate merging.
* Added tenant-scoped contact tags with a dedicated Tags screen, contact filters, manual and bulk assignment, import defaults, and export support.
* Added stable Free runtime wrappers, capabilities, and hooks for secure external contact-tag integrations.
* Added tenant-scoped contact and chat ownership assignments with manual Contacts and Inbox controls, filtering, bulk updates, and audit history.
* Added stable assignment wrappers and capability discovery for compatible add-ons and external plugin integrations.
* Added concurrency-safe round-robin contact assignment with tenant-role pools, stable route keys, and existing-owner protection.
* Added a tenant-scoped CRM activity timeline foundation with bounded readers, secure writers, automatic contact audit entries, and retention cleanup controls.
* Added a tenant-safe read-only Contact Profile available from Contacts and the Chat Window, including contact details, ownership, message context, and permission-checked CRM activity history.
* Added tenant-editable Access Teams with view-only/manage action levels, assigned/team/all record scopes, and assignment eligibility.
* Added centralized CRM access-policy enforcement across Contacts, Inbox, bulk actions, imports, exports, forwarding, and secure media access.
* Added stable CRM access-policy wrappers and Access Teams discovery for Pro modules and external plugin integrations.
* Added independent conversation ticket management with saved Inbox views, status, priority, subject, category, snoozing, followers, internal notes, and required-note handoffs.
* Added tenant-scoped conversation, assignment-history, and watcher tables with bulk Inbox loading and permanent-contact cleanup.
* Added stable conversation ticket wrappers, activity readers, access checks, lifecycle hooks, and detailed secure integration documentation.
* Added a stable conversation auto-assignment wrapper with tenant-safe round-robin and least-busy routing through existing assignment pools.
* Added a bounded tenant-scoped SLA candidate reader for efficient Pro deadline catch-up scheduling.
* Added priority-based first-response and resolution SLA targets with WordPress-timezone due dates, overdue Inbox filtering, and a developer customization filter.
* Fixed Deals stage-history timestamps and release-facing admin timestamp fallbacks to display in the WordPress site timezone.
* Confirmed the uninstall cleanup catalog covers all Free and Pro 1.1.0 database tables.

= 1.0.9 =
* Redesigned the Free dashboard with compact Pro-style cards for Connection, Messaging and Calling Health Status, and setup help links.
* Added dashboard setup-help links from the existing support badge helper.
* Improved the Messaging and Calling Health Status UI by hiding internal Meta node IDs while preserving the backend health payload.
* Cleaned unused dashboard response fields and UI code after the dashboard redesign.

= 1.0.8 =
* Added a shared contact upsert runtime wrapper for compatible integrations and add-ons.
* Added the contact integration writer capability to the Free runtime contract.
* Added Pro feature menu entries with compact Pro badges that open the existing upgrade page when Pro is not installed or the Pro license is inactive.
* Updated Free plugin documentation for Pro abandoned cart recovery, workflow subscription-status checks, and Pro upgrade entry points.

= 1.0.7 =
* Added a shared runtime wrapper so compatible add-ons can update contact subscription status safely.
* Routed contact bulk subscribe and unsubscribe actions through the shared tenant-scoped subscription runtime.

= 1.0.6 =
* Officially tested and validated compatibility with WordPress 7.0.

= 1.0.5 =
* Added a floating Support Portal badge across eligible NXT Cloud Chat admin pages in Free and Pro contexts.
* Improved the support badge UX with expandable and icon-only collapsed states.

= 1.0.4 =
* Improved WordPress 6.7+ compatibility by refining translation-loading timing and cleanup catalog text handling.
* Cleaned up i18n and PCP-related warnings in the Free plugin codebase for a more release-ready build.
* Updated the dashboard upgrade call-to-action to point directly to the NXT Cloud Chat product page.

= 1.0.3 =
* Added Team Access management for tenant-owner controlled staff permissions inside the plugin.
* Redesigned the Connection settings tab with a more compact branded layout while keeping the existing functionality intact.
* Added cleanup and retention controls for operational data from the plugin.
* Refined Contacts, Groups, History, and Chat Window behavior, including verified-group protections and improved live chat handling.
* Removed development-only debug traces from Contacts admin scripts for a cleaner production build.

= 1.0.2 =
* Added the stable runtime compatibility contract and bridge wrappers used by the Pro workflow engine.
* Added additive inbound-message, message-status, and authentication lifecycle hooks for internal integrations.
* Improved release compatibility for the Pro add-on workflow runtime.

= 1.0.1 =
* Added WhatsApp login button support for the default WordPress login page and WooCommerce login page.
* Added customizable login button appearance controls.
* Improved dedicated login page and login widget behavior.
* Refined readme content and listing metadata.

= 1.0.0 =
* Initial public release of NXT Cloud Chat.
* Core WhatsApp Cloud API integration.
* Chat Window, Contacts, Groups, Message History, and Dashboard.
* Webhook handling with verify-token helper.
* WhatsApp-based login/authentication widget for WordPress users.

== Upgrade Notice ==

= 1.1.1 =
Adds public connection-profile wrappers, primary display-phone access, runtime capability discovery, and refreshed connection caches.
