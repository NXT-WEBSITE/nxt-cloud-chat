=== NXT Cloud Chat ===
Contributors: nxtwebsite
Tags: whatsapp, whatsapp cloud api, whatsapp chat, whatsapp login, woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress WhatsApp Cloud API plugin with chat, notifications, contacts, OTP login, and Pro features for WooCommerce, bulk messaging, and templates.

== Description ==

NXT Cloud Chat is a WhatsApp Cloud API plugin for WordPress. It helps you connect your WordPress site to the official WhatsApp Business Platform so you can manage WhatsApp chat, send notifications, organize contacts, and review message history from the WordPress admin.

The plugin also includes a WhatsApp login and authentication widget for WordPress users. Visitors can sign in using their phone number and a one-time password delivered through WhatsApp, while their accounts remain standard WordPress users.

If you are looking for a WordPress WhatsApp plugin that combines WhatsApp Cloud API messaging with WhatsApp OTP login, NXT Cloud Chat is designed for that workflow.

For businesses that need more growth and sales features, the optional Pro add-on extends NXT Cloud Chat with WooCommerce automations, workflow-based messaging, bulk WhatsApp campaigns, advanced template messaging, CTA buttons, and customer engagement flows.

Key goals:

* Keep your WhatsApp configuration and authentication data self-hosted in your WordPress database and under your site's control.
* Make WhatsApp Cloud API usage more WordPress-like through familiar admin screens.
* Provide WhatsApp chat, contact management, and template-based notifications in one plugin.
* Provide a WhatsApp login/authentication flow alongside the existing WordPress login system and WooCommerce login experience.
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

* Connect WordPress to the official WhatsApp Cloud API.
* Store your WhatsApp access token on the server (no token printed into client-side JS).
* Configure App ID, App Secret, Business Account ID, Phone Number ID, and Access Token.
* Use connection diagnostics to verify credentials and send a test template message.
* Use the webhook callback URL helper with verify-token generation and App Secret based webhook signature verification.

= Contact & Group Management =

* Store WhatsApp contacts with phone, country, name, and custom fields.
* Track subscription status and unsubscribe reasons.
* Organize contacts into groups for easier targeting, such as leads, members, or customers.
* Use group-based organization to support better WhatsApp messaging workflows inside WordPress.

= Chat Window & History =

* Dedicated Chat Window screen to browse recent WhatsApp conversations.
* Review inbound and outbound WhatsApp messages with timestamps and contact details.
* Download media attachments such as images and documents through a secure media proxy.
* Store message history in dedicated tables with status fields and metadata for reporting and debugging.

= WhatsApp Login & Authentication =

* Front-end WhatsApp login widget via `[nxtcc_login_whatsapp]` shortcode.
* WhatsApp OTP login using phone numbers such as `+1XXXXXXXXXX`.
* Separate tables for OTP lifecycle and verified bindings to WordPress users.
* Optional force-migration page that can guide users from password login to WhatsApp login.
* Optional login buttons on the default WordPress login page and WooCommerce My Account login page.
* Customizable login button text, colors, separator text, and corner style.
* Admin screen to configure OTP length, resend cooldown, allowed countries, branding behavior, login button appearance, and login-page placement.

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
* Installed Plugins screen links for documentation, community support, reviews, and feature suggestions.

= Available with the Optional Pro Add-on =

* WooCommerce WhatsApp notifications for order created, order paid, and order status changes.
* Workflow automation builder for incoming messages, user login events, and WooCommerce triggers.
* Bulk WhatsApp messaging and broadcast campaigns for promotions, updates, and follow-ups.
* Advanced template messaging with image headers, CTA buttons, coupon codes, and dynamic placeholders.
* WhatsApp marketing and utility templates for customer engagement, reminders, and sales flows.
* Order-related template sends for payment reminders, order updates, and commerce messaging.
* Customer segmentation and campaign messaging built on contacts, groups, and templates.
* WhatsApp automation flows for support, lead capture, ecommerce, and post-purchase follow-up.

= Developer Friendly =

* Core code and optional add-ons kept separate.
* Database schema installed via `dbDelta()` with indices.
* DAO and DB helper classes for safe, cached SQL access.
* Filters/actions to extend behavior, such as `nxtcc_get_meta_templates`.

== WhatsApp Login (Authentication) ==

NXT Cloud Chat includes a WhatsApp-based login/authentication flow for WordPress:

* Shortcode: `[nxtcc_login_whatsapp]`
* When placed on a page, this renders a WhatsApp login widget where users:
  1. Choose their country and enter their phone number.
  2. Receive a one-time code over WhatsApp (via your Cloud API account).
  3. Enter the code to verify, creating or binding a WordPress user account.
* Once verified, users are linked to their WhatsApp number and can be logged in without needing to remember a password.
* Admins can configure:
  * OTP length.
  * Resend cooldown.
  * Allowed countries for login.
  * WordPress and WooCommerce login-page button placement.
  * Custom login button text, colors, separator text, and corner style.
  * Whether to show or hide branding.

This works alongside the standard WordPress login screen and can also be used on a dedicated WhatsApp Login page.

== Installation ==

1. Upload the `nxt-cloud-chat` folder to the `/wp-content/plugins/` directory, or install via the WordPress "Plugins -> Add New" screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **NXT Cloud Chat -> Settings** in the WordPress admin.
4. Enter:
   * **App ID**
   * **App Secret**
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
* App Secret
* Business Account ID
* Phone Number ID
* Access Token

The plugin assumes you already have these values.

=== Is this plugin an official WhatsApp product? ===

No. NXT Cloud Chat is an independent WordPress plugin that integrates with the official WhatsApp Cloud API, but it is not created, maintained, or endorsed by WhatsApp or Meta.

=== Can I use this plugin just for WhatsApp login and not for messaging? ===

Yes. You can focus on the WhatsApp login/authentication features by configuring the WhatsApp Cloud API and placing the `[nxtcc_login_whatsapp]` shortcode on a dedicated page. Use of additional messaging tools is optional.

=== Can I use the WhatsApp login widget on normal WordPress pages or page builders? ===

Yes. The recommended method is to create a page and place the `[nxtcc_login_whatsapp]` shortcode on it. That page can then be used as your dedicated WhatsApp login page and linked from your site login flow.

=== Does this support the WooCommerce login page? ===

Yes. You can enable a WhatsApp login button on the WooCommerce My Account login form, and you can also enable the same style of button on the default WordPress login page.

=== Are WooCommerce automations, workflows, template CTA buttons, and bulk messaging available? ===

Yes. Those advanced capabilities are available through the optional `NXT Cloud Chat Pro` add-on. Pro is designed for stores and businesses that need WooCommerce notifications, WhatsApp workflows, advanced template campaigns, and bulk broadcast messaging from WordPress.

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
6. Login authentication management interface.
7. WhatsApp login widget embedded on a WordPress page.
8. WhatsApp login buttons for the default WordPress login page and WooCommerce My Account login page.
9. Bulk WhatsApp messaging and campaign screen for sending messages to multiple contacts (Pro).
10. Advanced workflow automation screen for WooCommerce and event-based WhatsApp messaging (Pro).

== Changelog ==

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
