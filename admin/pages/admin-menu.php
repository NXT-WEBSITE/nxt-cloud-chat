<?php
/**
 * Admin menu registration for NXT Cloud Chat.
 *
 * Registers the top-level "NXT Cloud Chat" menu and its submenus, and defines
 * simple render callbacks that load the corresponding admin view templates.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the NXT Cloud Chat admin menu and submenus.
 *
 * @return void
 */
function nxtcc_register_admin_menu(): void {
	// Custom SVG icon used for the top-level admin menu.
	$icon_url = NXTCC_PLUGIN_URL . 'admin/assets/vendor/images/nxt-cloud-chat.svg';

	// All plugin admin pages require administrator-level access.
	$capability = 'manage_options';

	// Parent slug for the top-level menu (Dashboard).
	$parent_slug = 'nxt-cloud-chat';

	// Top-level menu (opens Dashboard).
	add_menu_page(
		__( 'NXT Cloud Chat', 'nxt-cloud-chat' ),
		__( 'NXT Cloud Chat', 'nxt-cloud-chat' ),
		$capability,
		$parent_slug,
		'nxtcc_render_dashboard_page',
		$icon_url,
		56
	);

	// Dashboard submenu (same slug as parent).
	add_submenu_page(
		$parent_slug,
		__( 'Dashboard', 'nxt-cloud-chat' ),
		__( 'Dashboard', 'nxt-cloud-chat' ),
		$capability,
		$parent_slug,
		'nxtcc_render_dashboard_page'
	);

	// Chat Window page (received messages UI).
	add_submenu_page(
		$parent_slug,
		__( 'Chat Window', 'nxt-cloud-chat' ),
		__( 'Chat Window', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-chat-window',
		static function (): void {
			require_once NXTCC_PLUGIN_DIR . 'admin/pages/received-messages-view.php';
		}
	);

	// Contacts management.
	add_submenu_page(
		$parent_slug,
		__( 'Contacts', 'nxt-cloud-chat' ),
		__( 'Contacts', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-contacts',
		'nxtcc_render_contacts_page'
	);

	// Groups management.
	add_submenu_page(
		$parent_slug,
		__( 'Groups', 'nxt-cloud-chat' ),
		__( 'Groups', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-groups',
		'nxtcc_render_groups_page'
	);

	// Message history screen.
	add_submenu_page(
		$parent_slug,
		__( 'History', 'nxt-cloud-chat' ),
		__( 'History', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-history',
		'nxtcc_render_history_page'
	);

	// Authentication / connect flow.
	add_submenu_page(
		$parent_slug,
		__( 'Authentication', 'nxt-cloud-chat' ),
		__( 'Authentication', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-authentication',
		'nxtcc_render_authentication_page'
	);

	// Settings page (class-based renderer).
	add_submenu_page(
		$parent_slug,
		__( 'Settings', 'nxt-cloud-chat' ),
		__( 'Settings', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-settings',
		array( 'NXTCC_Admin_Settings', 'settings_page_html' )
	);

	// Upgrade page (marketing / app selection screen).
	add_submenu_page(
		$parent_slug,
		__( 'Upgrade', 'nxt-cloud-chat' ),
		__( 'Upgrade', 'nxt-cloud-chat' ),
		$capability,
		'nxtcc-upgrade',
		'nxtcc_render_upgrade_page'
	);
}
add_action( 'admin_menu', 'nxtcc_register_admin_menu' );

/**
 * Render the Dashboard admin page.
 *
 * @return void
 */
function nxtcc_render_dashboard_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/dashboard-view.php';
}

/**
 * Render the Contacts admin page.
 *
 * @return void
 */
function nxtcc_render_contacts_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/contacts-view.php';
}

/**
 * Render the Groups admin page.
 *
 * @return void
 */
function nxtcc_render_groups_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/groups-view.php';
}

/**
 * Render the History admin page.
 *
 * @return void
 */
function nxtcc_render_history_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/history-view.php';
}

/**
 * Render the Authentication admin page.
 *
 * @return void
 */
function nxtcc_render_authentication_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/authentication-view.php';
}

/**
 * Render the Upgrade admin page.
 *
 * @return void
 */
function nxtcc_render_upgrade_page(): void {
	require_once NXTCC_PLUGIN_DIR . 'admin/pages/apps-view.php';
}

/**
 * Output a simple placeholder admin page.
 *
 * @param string $title Page title.
 * @return void
 */
function nxtcc_placeholder_page( string $title ): void {
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<p>' . esc_html__( 'Interface coming soon.', 'nxt-cloud-chat' ) . '</p>';
	echo '</div>';
}
