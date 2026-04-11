=== NXT Cloud Chat ===
Contributors: nxtwebsite
Tags: whatsapp, whatsapp cloud api, chat, notifications, whatsapp login
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WhatsApp Cloud API to WordPress for messaging, notifications, contact tools and WhatsApp-based login.

== Description ==

NXT Cloud Chat connects your WordPress site to the official WhatsApp Cloud API so you can manage contacts, send structured notifications, and handle conversations from the WordPress admin.

The plugin also provides a WhatsApp-based login and authentication widget for WordPress users. Visitors can sign in using their phone number and a one-time code delivered through WhatsApp, while their accounts remain normal WordPress users.

Key goals:

* Keep all sensitive tokens stored on the server (not printed into page HTML or JS).
* Make WhatsApp Cloud API usage more "WordPress-like" via familiar screens.
* Provide a WhatsApp login/authentication flow alongside the existing WordPress login system.
* Offer a clean foundation that can be extended by developers or by an optional add-on.

== External Services ==

This plugin connects to the official WhatsApp Business Platform (WhatsApp Cloud API), provided by Meta Platforms, Inc.  
Using the plugin therefore means data will be sent from your WordPress site to Meta's servers.

What it is used for:

* Sending and receiving WhatsApp messages for 1-to-1 chats.
* Sending one-time passwords (OTP) for WhatsApp login.
* Synchronizing and sending WhatsApp message templates.

What data is sent and when:

* When you configure the plugin, your App ID, App Secret, Business Account ID, Phone Number ID and Access Token are stored in your WordPress database and used to authenticate API calls and webhook signature verification for WhatsApp Cloud API. These values are not exposed in the browser UI.
* When you send or receive messages, the recipient phone number, message content, template name, template parameters and basic delivery metadata are sent to the WhatsApp Cloud API.
* When you use WhatsApp login, the user's phone number and the OTP/template used for authentication are sent to the WhatsApp Cloud API.

Service provider:

* WhatsApp Business Platform / WhatsApp Cloud API by Meta Platforms, Inc.
* Meta Privacy Policy: https://www.facebook.com/privacy/policy/
* WhatsApp Privacy Policy: https://www.whatsapp.com/legal/privacy-policy
* Meta Terms for WhatsApp Business: https://www.whatsapp.com/legal/meta-terms-whatsapp-business
* WhatsApp Business Solution Terms: https://www.whatsapp.com/legal/business-solution-terms
* WhatsApp Business Terms of Service: https://www.whatsapp.com/legal/business-terms
* WhatsApp Business Messaging Policy: https://business.whatsapp.com/policy
* WhatsApp Messaging Guidelines: https://www.whatsapp.com/legal/messaging-guidelines
* WhatsApp Business Data Processing Terms: https://www.whatsapp.com/legal/business-data-processing-terms
* WhatsApp Business Data Transfer Addendum: https://www.whatsapp.com/legal/business-data-transfer-addendum
* WhatsApp Business Data Security Terms: https://www.whatsapp.com/legal/business-data-security-terms
* Intellectual Property Policy: https://www.whatsapp.com/legal/ip-policy
* WhatsApp Business Terms for Service Providers: https://www.whatsapp.com/legal/business-terms-for-service-providers
* WhatsApp Brand Guidelines: https://www.whatsappbrand.com/
* Pricing: https://business.whatsapp.com/products/platform-pricing

You are responsible for ensuring that your own use of this plugin and the WhatsApp Cloud API complies with applicable laws, your privacy policy, and Meta's terms.

== Features ==

= WhatsApp Cloud API Integration =

* Store your WhatsApp access token on the server (no token printed into client-side JS).
* Single-tenant configuration: App ID, Business Account ID, Phone Number ID.
* Connection diagnostics to verify credentials and send a test template message.
* Webhook callback URL helper with copy-ready URL, verify-token generator, and App Secret based webhook signature verification.

= Contact & Group Management =

* Store WhatsApp contacts (phone, country, name, custom fields).
* Track subscription status and unsubscribe reasons.
* Organize contacts into groups for easier targeting (e.g. "VIP Customers", "Leads").
* Schema prepared to support more advanced segmentation in future.

= Chat Window & History =

* Dedicated "Chat Window" screen to browse recent WhatsApp conversations.
* Shows inbound and outbound messages with timestamps and basic contact details.
* Media attachments (images, documents, etc.) can be downloaded via a secure media proxy.
* Message history stored in a dedicated table with status fields and metadata for future reporting.

= WhatsApp Login & Authentication =

* Front-end WhatsApp login widget via `[nxtcc_login_whatsapp]` shortcode.
* OTP-based authentication over WhatsApp using phone numbers (e.g. `+1XXXXXXXXXX`).
* Separate tables for OTP lifecycle and verified bindings to WordPress users.
* Optional force-migration page that can guide users from password login to WhatsApp login.
* Admin screen to configure OTP length, resend cooldown, allowed countries, and branding behavior.

= Admin Experience =

* Dedicated admin pages for:
  * Dashboard
  * Chat Window
  * Contacts
  * Groups
  * History
  * Authentication (WhatsApp login settings)
  * Settings (Cloud API credentials and diagnostics)
  * Upgrade (integration info for optional extensions)
* Admin actions protected with nonces and capability checks.

= Developer Friendly =

* Core code and optional add-ons kept separate.
* Database schema installed via `dbDelta()` with indices.
* DAO and DB helper classes for safe, cached SQL access.
* Filters/actions to extend behavior, such as `nxtcc_get_meta_templates`.

== WhatsApp Login (Authentication) ==

NXT Cloud Chat includes a WhatsApp-based login/authentication flow for WordPress:

* Shortcode: `[nxtcc_login_whatsapp]`
* When placed on a page, this renders a login widget where users:
  1. Choose their country and enter their phone number.
  2. Receive a one-time code over WhatsApp (via your Cloud API account).
  3. Enter the code to verify, creating or binding a WordPress user account.
* Once verified, users are linked to their WhatsApp number and can be logged in without needing to remember a password.
* Admins can configure:
  * OTP length.
  * Resend cooldown.
  * Allowed countries for login.
  * Whether to show or hide branding.

This works alongside the standard WordPress login screen and can also be used on a dedicated "WhatsApp Login" page.

== Installation ==

1. Upload the `nxt-cloud-chat` folder to the `/wp-content/plugins/` directory, or install via the WordPress "Plugins -> Add New" screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **NXT Cloud Chat -> Settings** in the WordPress admin.
4. Enter:
   * **App ID**
   * **App Secret (Webhook Signature)**
   * **Access Token**
   * **Phone Number ID**
   * **Business Account ID**
   * (Optional) Display phone number for reference
   * Webhook (for incoming messages)
5. Click **Save Settings**.

=== WhatsApp Webhook Setup ===

1. In **NXT Cloud Chat -> Settings**, enable your webhook for incoming messages.
2. Copy the **Callback URL** and **Verify Token** (you can generate one from the settings page).
3. In the Meta/WhatsApp Cloud API dashboard:
   * Set the callback URL to the one provided by the plugin.
   * Set the verify token to the one generated by the plugin.
   * Keep the same Meta App Secret in your plugin settings to validate `X-Hub-Signature-256`.
4. Save and verify the webhook.

=== WhatsApp Login (Authentication) ===

1. Go to **NXT Cloud Chat -> Authentication** in the admin.
2. Configure OTP length, resend delay, and any allowed countries.
3. Optionally configure a dedicated **force-migration** URL (for example: `/nxt-whatsapp-login/`).
4. Create a new WordPress page (e.g. "WhatsApp Login") and add the shortcode:
   `[nxtcc_login_whatsapp]`
5. Publish the page and share the URL with your users or link it from your login flow.

== Support & Documentation ==

For setup guides, usage instructions, and troubleshooting help, see:

User Guide & Documentation: https://nxtcloudchat.com/user-guide

For support queries or feature requests, you can also use the plugin support section on WordPress.org.

== Frequently Asked Questions ==

=== Does this plugin send messages directly from my server? ===

Yes. The plugin calls the official WhatsApp Cloud API using your access token, stored in the WordPress database. Sensitive operations are performed server-side; the access token is not printed directly into your public pages.

=== Do I need a Meta (Facebook) developer account? ===

Yes. You must create and configure a WhatsApp Cloud API app in your Meta developer account to obtain:

* App ID
* Business Account ID
* Phone Number ID
* Access Token
* App Secret (for webhook signature verification)

The plugin assumes you already have these values.

=== Is this plugin an official WhatsApp product? ===

No. NXT Cloud Chat is an independent WordPress plugin that integrates with the official WhatsApp Cloud API, but it is not created, maintained, or endorsed by WhatsApp or Meta.

=== Can I use this plugin just for WhatsApp login and not for messaging? ===

Yes. You can focus on the WhatsApp login/authentication features by configuring the WhatsApp Cloud API and placing the `[nxtcc_login_whatsapp]` shortcode on a dedicated page. Use of additional messaging tools is optional.

=== Does the plugin store WhatsApp messages? ===

The plugin stores message metadata and content in custom tables (for history and debugging) within your WordPress database. If you have specific data retention requirements, you can implement your own cleanup policies via custom code or database tools.

=== What happens on uninstall? ===

In **NXT Cloud Chat -> Settings**, there is an option **"Delete all data on uninstall"**.

If enabled, uninstalling the plugin will attempt to delete:

* NXT Cloud Chat database tables (Free + compatible add-on tables).
* Plugin-related options and transients.
* Plugin-specific upload directories.
* Scheduled events related to the plugin.

Use this with care in production environments.

== Screenshots ==

1. Settings screen for WhatsApp Cloud API credentials and diagnostics.
2. Dashboard interface.
3. Chat Window management interface.
4. Contacts management interface.
5. Groups management interface.
6. Message history and conversation overview.
7. Login authentication management interface.
8. WhatsApp login widget embedded on a WordPress page.

== Changelog ==

= 1.0.0 =
* Initial public release of NXT Cloud Chat.
* Core WhatsApp Cloud API integration.
* Chat Window, Contacts, Groups, Message History, and Dashboard.
* Webhook handling with verify-token helper.
* WhatsApp-based login/authentication widget for WordPress users.

