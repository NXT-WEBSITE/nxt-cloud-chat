<?php
/**
 * Plugin Name:       NXT Cloud Chat
 * Plugin URI:        https://nxtcloudchat.com/
 * Description:       Integrates WhatsApp Cloud API with WordPress to enable real-time messaging, automated notifications, customer communication, contact management, and secure WhatsApp-based user authentication and login.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NXTWEBSITE
 * Author URI:        https://nxtwebsite.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nxt-cloud-chat
 * Domain Path:       /languages
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
if ( ! defined( 'NXTCC_VERSION' ) ) {
	define( 'NXTCC_VERSION', '1.0.0' );
}

/**
 * Main plugin file path.
 */
if ( ! defined( 'NXTCC_PLUGIN_FILE' ) ) {
	define( 'NXTCC_PLUGIN_FILE', __FILE__ );
}

/**
 * Distribution marker (free/pro awareness).
 */
if ( ! defined( 'NXTCC_DISTRIBUTION' ) ) {
	define( 'NXTCC_DISTRIBUTION', 'FREE' );
}

/**
 * Absolute plugin directory path.
 */
if ( ! defined( 'NXTCC_PLUGIN_DIR' ) ) {
	define( 'NXTCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Plugin URL.
 */
if ( ! defined( 'NXTCC_PLUGIN_URL' ) ) {
	define( 'NXTCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Plugin basename.
 */
if ( ! defined( 'NXTCC_PLUGIN_BASENAME' ) ) {
	define( 'NXTCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Detect whether the Pro add-on is active.
 *
 * This is informational only and does not change free plugin behavior by itself.
 *
 * @return bool True when the Pro add-on plugin is active, otherwise false.
 */
function nxtcc_is_pro_active(): bool {
	$pro_plugin = 'nxt-cloud-chat-pro/nxt-cloud-chat-pro.php';

	$active_plugins = get_option( 'active_plugins', array() );
	$active_plugins = is_array( $active_plugins ) ? $active_plugins : array();

	if ( in_array( $pro_plugin, $active_plugins, true ) ) {
		return true;
	}

	if ( is_multisite() ) {
		$network_active = get_site_option( 'active_sitewide_plugins', array() );
		if ( is_array( $network_active ) && isset( $network_active[ $pro_plugin ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Run plugin activation tasks (database schema install).
 *
 * @return void
 */
function nxtcc_activate_plugin(): void {
	$schema_file = NXTCC_PLUGIN_DIR . 'includes/db-schema.php';

	if ( file_exists( $schema_file ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/db-schema.php';

		if ( function_exists( 'nxtcc_install_db_schema' ) ) {
			nxtcc_install_db_schema();
		}
	}
}
register_activation_hook( __FILE__, 'nxtcc_activate_plugin' );

/**
 * Load core plugin modules.
 *
 * Keep this section limited to require statements to avoid side effects during load.
 */
require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-user-settings-bootstrap.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-user-settings-repo.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-api-connection.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-helpers-functions.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-dao.php';

require_once NXTCC_PLUGIN_DIR . '/includes/pages-dao/class-nxtcc-pages-dao.php';

/**
 * Authentication logic must be loaded before routes.
 */
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-auth-db.php';
require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-auth-handler.php';

require_once NXTCC_PLUGIN_DIR . 'includes/routes.php';
require_once NXTCC_PLUGIN_DIR . 'includes/nxtcc-send-message.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-message-history-repo.php';
require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-unread.php';

require_once NXTCC_PLUGIN_DIR . 'includes/admin-settings.php';
require_once NXTCC_PLUGIN_DIR . 'admin/pages/admin-menu.php';

require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-contacts-handler.php';
require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-groups-handler.php';
require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-chat-handler.php';
require_once NXTCC_PLUGIN_DIR . 'admin/model/nxtcc-history-handler.php';

require_once NXTCC_PLUGIN_DIR . 'admin/model/class-nxtcc-dashboard-repo.php';
require_once NXTCC_PLUGIN_DIR . 'admin/model/class-nxtcc-dashboard-handler.php';

require_once NXTCC_PLUGIN_DIR . 'includes/queue-runner.php';

require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/options.php';
require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/policy.php';
require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/gate.php';
require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/page-default.php';
require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/banner.php';

require_once NXTCC_PLUGIN_DIR . 'includes/profile-whatsapp-field.php';
require_once NXTCC_PLUGIN_DIR . 'includes/auth-otp-pruner.php';

require_once NXTCC_PLUGIN_DIR . 'includes/widgets/class-nxtcc-login-whatsapp-widget.php';
require_once NXTCC_PLUGIN_DIR . 'blocks/register-whatsapp-login-block.php';


/**
 * Register the classic widget.
 *
 * @return void
 */
function nxtcc_register_widgets(): void {
	if ( class_exists( 'NXTCC_Login_WhatsApp_Widget' ) ) {
		register_widget( 'NXTCC_Login_WhatsApp_Widget' );
	}
}
add_action( 'widgets_init', 'nxtcc_register_widgets' );

/**
 * Enqueue Font Awesome from the plugin bundle (no external CDN).
 *
 * @return void
 */
function nxtcc_enqueue_fontawesome(): void {
	if ( wp_style_is( 'nxtcc-fontawesome', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_style(
		'nxtcc-fontawesome',
		NXTCC_PLUGIN_URL . 'admin/assets/vendor/fontawesome/css/all.min.css',
		array(),
		'7.1.0'
	);
}

/**
 * Enqueue admin-wide assets used by the plugin menu and specific pages.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function nxtcc_admin_global_assets( string $hook ): void {
	wp_enqueue_style(
		'nxtcc-admin-menu',
		NXTCC_PLUGIN_URL . 'admin/assets/css/admin-menu.css',
		array(),
		NXTCC_VERSION
	);

	if ( false !== strpos( $hook, 'nxtcc-upgrade' ) ) {
		wp_enqueue_style(
			'nxtcc-apps',
			NXTCC_PLUGIN_URL . 'admin/assets/css/apps.css',
			array(),
			NXTCC_VERSION
		);

		wp_enqueue_script(
			'nxtcc-apps',
			NXTCC_PLUGIN_URL . 'admin/assets/js/apps.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);
	}
}
add_action( 'admin_enqueue_scripts', 'nxtcc_admin_global_assets' );

/**
 * Contacts screen assets.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		if ( 'nxt-cloud-chat_page_nxtcc-contacts' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nxtcc-contacts',
			NXTCC_PLUGIN_URL . 'admin/assets/css/contacts.css',
			array(),
			NXTCC_VERSION
		);

		wp_enqueue_script(
			'nxtcc-contacts-runtime',
			NXTCC_PLUGIN_URL . 'admin/assets/js/contacts/contacts-runtime.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);

		$tz = (string) get_option( 'timezone_string' );

		$gmt_offset = get_option( 'gmt_offset' );
		$gmt_offset = is_numeric( $gmt_offset ) ? (float) $gmt_offset : 0.0;

		wp_localize_script(
			'nxtcc-contacts-runtime',
			'NXTCC_ContactsData',
			array(
				'ajaxurl'            => admin_url( 'admin-ajax.php' ),
				'site_tz'            => $tz,
				'site_tz_offset_min' => (int) ( $gmt_offset * 60 ),
				'nonce'              => wp_create_nonce( 'nxtcc_contacts_nonce' ),
				'current_user'       => wp_get_current_user()->user_email,
			)
		);

		wp_enqueue_script(
			'nxtcc-contacts-table',
			NXTCC_PLUGIN_URL . 'admin/assets/js/contacts/contacts-table.js',
			array( 'jquery', 'nxtcc-contacts-runtime' ),
			NXTCC_VERSION,
			true
		);

		wp_enqueue_script(
			'nxtcc-contacts-actions',
			NXTCC_PLUGIN_URL . 'admin/assets/js/contacts/contacts-actions.js',
			array( 'jquery', 'nxtcc-contacts-runtime', 'nxtcc-contacts-table' ),
			NXTCC_VERSION,
			true
		);

		wp_enqueue_script(
			'nxtcc-contacts-modal',
			NXTCC_PLUGIN_URL . 'admin/assets/js/contacts/contacts-modal.js',
			array( 'jquery', 'nxtcc-contacts-runtime', 'nxtcc-contacts-table', 'nxtcc-contacts-actions' ),
			NXTCC_VERSION,
			true
		);

		wp_enqueue_script(
			'nxtcc-contacts-import-export',
			NXTCC_PLUGIN_URL . 'admin/assets/js/contacts/contacts-import-export.js',
			array( 'jquery', 'nxtcc-contacts-runtime', 'nxtcc-contacts-table', 'nxtcc-contacts-actions', 'nxtcc-contacts-modal' ),
			NXTCC_VERSION,
			true
		);
	}
);

/**
 * Groups screen assets.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function nxtcc_enqueue_groups_assets( string $hook ): void {
	if ( 'nxt-cloud-chat_page_nxtcc-groups' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'nxtcc-groups',
		NXTCC_PLUGIN_URL . 'admin/assets/css/groups.css',
		array(),
		NXTCC_VERSION
	);

	wp_enqueue_script(
		'nxtcc-groups',
		NXTCC_PLUGIN_URL . 'admin/assets/js/groups.js',
		array( 'jquery', 'wp-i18n' ),
		NXTCC_VERSION,
		true
	);

	wp_localize_script(
		'nxtcc-groups',
		'NXTCC_GroupsData',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			// Nonce action for groups AJAX routes.
			'nonce'   => wp_create_nonce( 'nxtcc_groups' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'nxtcc_enqueue_groups_assets' );


/**
 * Settings screen assets (detected via screen ID and URL).
 */
add_action(
	'admin_enqueue_scripts',
	function (): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = ( $screen && isset( $screen->id ) ) ? (string) $screen->id : '';

		$page_param_raw = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$page_param     = $page_param_raw ? sanitize_key( $page_param_raw ) : '';

		$is_settings_screen = (
			( '' !== $screen_id && false !== strpos( $screen_id, 'nxtcc-settings' ) ) ||
			( 'nxtcc-settings' === $page_param )
		);

		if ( ! $is_settings_screen ) {
			return;
		}

		wp_enqueue_style(
			'nxtcc-settings',
			NXTCC_PLUGIN_URL . 'admin/assets/css/settings.css',
			array(),
			NXTCC_VERSION
		);

		wp_enqueue_script(
			'nxtcc-settings',
			NXTCC_PLUGIN_URL . 'admin/assets/js/settings.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);

		wp_localize_script(
			'nxtcc-settings',
			'NXTCC_ADMIN',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'nxtcc_admin_ajax' ),
				'callback_url' => set_url_scheme( site_url( '/wp-json/nxtcc/v1/webhook/' ), 'https' ),
			)
		);
	}
);

/**
 * History screen assets.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		if ( 'nxt-cloud-chat_page_nxtcc-history' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nxtcc-history-css',
			NXTCC_PLUGIN_URL . 'admin/assets/css/history.css',
			array(),
			NXTCC_VERSION
		);

		wp_enqueue_script(
			'nxtcc-history-js',
			NXTCC_PLUGIN_URL . 'admin/assets/js/history.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);

		wp_add_inline_script(
			'nxtcc-history-js',
			'window.NXTCC_History = ' . wp_json_encode(
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'nxtcc_history_nonce' ),
					'limit'   => 30,
				)
			) . ';',
			'before'
		);
	}
);

/**
 * Chat window screen assets.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		$page_raw = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$page     = $page_raw ? sanitize_key( (string) $page_raw ) : '';

		$target_hook = 'nxt-cloud-chat_page_nxtcc-chat-window';

		if ( $hook !== $target_hook && 'nxtcc-chat-window' !== $page ) {
			return;
		}

		wp_enqueue_style(
			'nxtcc-chat-css',
			NXTCC_PLUGIN_URL . 'admin/assets/css/received-messages.css',
			array(),
			NXTCC_VERSION
		);

		nxtcc_enqueue_fontawesome();

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		// 1) Runtime FIRST.
		wp_enqueue_script(
			'nxtcc-chat-runtime',
			NXTCC_PLUGIN_URL . 'admin/assets/js/chat/chat-runtime.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);

		// Localize on runtime so all modules can read it.
		wp_localize_script(
			'nxtcc-chat-runtime',
			'NXTCC_ReceivedMessages',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);

		// 2) Actions SECOND (shared handlers/utilities used by thread).
		wp_enqueue_script(
			'nxtcc-chat-actions',
			NXTCC_PLUGIN_URL . 'admin/assets/js/chat/chat-actions.js',
			array( 'jquery', 'nxtcc-chat-runtime' ),
			NXTCC_VERSION,
			true
		);

		// 3) Inbox THIRD.
		wp_enqueue_script(
			'nxtcc-chat-inbox',
			NXTCC_PLUGIN_URL . 'admin/assets/js/chat/chat-inbox.js',
			array( 'jquery', 'nxtcc-chat-runtime' ),
			NXTCC_VERSION,
			true
		);

		// 4) Thread FOURTH (depends on runtime + actions).
		wp_enqueue_script(
			'nxtcc-chat-thread',
			NXTCC_PLUGIN_URL . 'admin/assets/js/chat/chat-thread.js',
			array( 'jquery', 'nxtcc-chat-runtime', 'nxtcc-chat-actions' ),
			NXTCC_VERSION,
			true
		);

		// 5) Boot LAST (depends on everything).
		wp_enqueue_script(
			'nxtcc-chat-boot',
			NXTCC_PLUGIN_URL . 'admin/assets/js/chat/chat-boot.js',
			array(
				'jquery',
				'nxtcc-chat-runtime',
				'nxtcc-chat-actions',
				'nxtcc-chat-inbox',
				'nxtcc-chat-thread',
			),
			NXTCC_VERSION,
			true
		);
	}
);


/**
 * Authentication admin page assets (options/policy hydration).
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		if ( 'nxt-cloud-chat_page_nxtcc-authentication' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nxtcc-settings',
			NXTCC_PLUGIN_URL . 'admin/assets/css/settings.css',
			array(),
			NXTCC_VERSION
		);

		wp_enqueue_style(
			'nxtcc-authentication',
			NXTCC_PLUGIN_URL . 'admin/assets/css/authentication.css',
			array( 'nxtcc-settings' ),
			NXTCC_VERSION
		);

		wp_enqueue_script(
			'nxtcc-auth-allowed-countries',
			NXTCC_PLUGIN_URL . 'admin/assets/js/auth-allowed-countries.js',
			array( 'jquery' ),
			NXTCC_VERSION,
			true
		);

		wp_localize_script(
			'nxtcc-auth-allowed-countries',
			'NXTCC_AUTH_ADMIN',
			array(
				'countryJson' => NXTCC_PLUGIN_URL . 'languages/nxtcc-country-codes.json',
			)
		);

		wp_enqueue_script(
			'nxtcc-authentication',
			NXTCC_PLUGIN_URL . 'admin/assets/js/authentication.js',
			array( 'jquery', 'nxtcc-auth-allowed-countries' ),
			NXTCC_VERSION,
			true
		);

		$raw_opts = get_option( 'nxtcc_auth_options', array() );
		if ( ! is_array( $raw_opts ) ) {
			$raw_opts = array();
		}

		$opts = array(
			'otp_len'            => isset( $raw_opts['otp_len'] ) ? (int) $raw_opts['otp_len'] : 6,
			'resend_cooldown'    => isset( $raw_opts['resend_cooldown'] ) ? (int) $raw_opts['resend_cooldown'] : 30,
			'terms_url'          => isset( $raw_opts['terms_url'] ) ? (string) $raw_opts['terms_url'] : '',
			'privacy_url'        => isset( $raw_opts['privacy_url'] ) ? (string) $raw_opts['privacy_url'] : '',
			'auto_sync'          => ! empty( $raw_opts['auto_sync'] ) ? 1 : 0,
			'auth_template'      => isset( $raw_opts['auth_template'] ) ? (string) $raw_opts['auth_template'] : '',
			'default_tenant_key' => isset( $raw_opts['default_tenant_key'] ) ? (string) $raw_opts['default_tenant_key'] : '',
		);

		$raw_policy = array();
		if ( function_exists( 'nxtcc_fm_get_options' ) ) {
			$raw_policy = nxtcc_fm_get_options();
		} else {
			$raw_policy = get_option( 'nxtcc_auth_policy', array() );
		}

		if ( ! is_array( $raw_policy ) ) {
			$raw_policy = array();
		}

		$force_path = isset( $raw_policy['force_path'] ) ? (string) $raw_policy['force_path'] : '/nxt-whatsapp-login/';

		$raw_allowed = array();
		if ( isset( $raw_policy['allowed_countries'] ) ) {
			$raw_allowed = (array) $raw_policy['allowed_countries'];
		}

		$policy = array(
			'show_password'     => ! empty( $raw_policy['show_password'] ) ? 1 : 0,
			'force_migrate'     => ! empty( $raw_policy['force_migrate'] ) ? 1 : 0,
			'force_path'        => $force_path,
			'grace_enabled'     => ! empty( $raw_policy['grace_enabled'] ) ? 1 : 0,
			'grace_days'        => isset( $raw_policy['grace_days'] ) ? max( 1, min( 90, (int) $raw_policy['grace_days'] ) ) : 7,
			'widget_branding'   => ! empty( $raw_policy['widget_branding'] ) ? 1 : 0,
			'allowed_countries' => array_values(
				array_unique(
					array_map(
						'strtoupper',
						$raw_allowed
					)
				)
			),
		);

		wp_localize_script(
			'nxtcc-authentication',
			'NXTCC_AUTH_ADMIN',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'nxtcc_auth_admin' ),
				'countryJson'   => NXTCC_PLUGIN_URL . 'languages/nxtcc-country-codes.json',
				'savedTemplate' => $opts['auth_template'],
				'opts'          => $opts,
				'policy'        => $policy,
			)
		);
	}
);

/**
 * Render the WhatsApp login widget via shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function nxtcc_render_login_whatsapp( array $atts = array() ): string {
	if ( is_user_logged_in() ) {
		$user_id = (int) get_current_user_id();

		if ( NXTCC_Auth_DB::i()->user_has_verified_binding( $user_id ) ) {
			$logout_url = wp_logout_url( home_url( '/' ) );

			ob_start();
			?>
			<div class="nxtcc-auth-widget">
				<div class="nxtcc-auth-card">
					<div class="nxtcc-auth-head">
						<div class="nxtcc-auth-title">
							<?php esc_html_e( "You're logged in with WhatsApp", 'nxt-cloud-chat' ); ?>
						</div>
					</div>
					<div class="nxtcc-auth-body">
						<a class="nxtcc-link-alt" href="<?php echo esc_url( $logout_url ); ?>">
							<?php esc_html_e( 'Logout', 'nxt-cloud-chat' ); ?>
						</a>
					</div>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}
	}

	$opts = get_option( 'nxtcc_auth_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}

	$terms_url   = isset( $opts['terms_url'] ) ? (string) $opts['terms_url'] : '';
	$privacy_url = isset( $opts['privacy_url'] ) ? (string) $opts['privacy_url'] : '';

	// Front-end attribution must be explicit opt-in.
	$show_branding = false;
	if ( function_exists( 'nxtcc_should_show_widget_branding' ) ) {
		$show_branding = (bool) nxtcc_should_show_widget_branding();
	}
	$hide_branding = ! $show_branding;

	if ( ! function_exists( 'nxtcc_detect_visitor_country' ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/class-nxtcc-helpers.php';
	}

	$raw_policy_full = get_option( 'nxtcc_auth_policy', array() );
	if ( ! is_array( $raw_policy_full ) ) {
		$raw_policy_full = array();
	}

	$raw_allowed = array();
	if ( isset( $raw_policy_full['allowed_countries'] ) ) {
		$raw_allowed = (array) $raw_policy_full['allowed_countries'];
	}

	$allowed_countries = array_values(
		array_unique(
			array_map(
				'strtoupper',
				$raw_allowed
			)
		)
	);

	$detected = (string) nxtcc_detect_visitor_country();

	$legacy_default = isset( $opts['default_country'] ) ? strtoupper( (string) $opts['default_country'] ) : 'IN';
	if ( ! preg_match( '/^[A-Z]{2}$/', $legacy_default ) ) {
		$legacy_default = 'IN';
	}

	$default_country = preg_match( '/^[A-Z]{2}$/', $detected ) ? $detected : $legacy_default;

	if ( ! empty( $allowed_countries ) && ! in_array( $default_country, $allowed_countries, true ) ) {
		$default_country = (string) $allowed_countries[0];
	}

	if ( function_exists( 'nxtcc_generate_token' ) ) {
		$session_id = (string) nxtcc_generate_token( 32 );
	} else {
		$session_id = bin2hex( random_bytes( 16 ) );
	}

	$session_id = (string) apply_filters( 'nxtcc_auth_session_id', $session_id, $atts );

	ob_start();
	include NXTCC_PLUGIN_DIR . 'frontend/views/login-widget.php';
	return (string) ob_get_clean();
}
add_shortcode( 'nxtcc_login_whatsapp', 'nxtcc_render_login_whatsapp' );

/**
 * Enqueue the login widget assets when the shortcode is present on a page.
 */
add_filter(
	'the_posts',
	function ( $posts ) {
		if ( is_admin() || empty( $posts ) ) {
			return $posts;
		}

		foreach ( $posts as $post ) {
			if ( ! empty( $post->post_content ) && false !== strpos( $post->post_content, '[nxtcc_login_whatsapp]' ) ) {
				nxtcc_auth_enqueue_login_widget_assets();
				break;
			}
		}

		return $posts;
	},
	10,
	1
);

/**
 * Enqueue front-end login widget CSS/JS and localize configuration for the script.
 *
 * @return void
 */
function nxtcc_auth_enqueue_login_widget_assets(): void {
	static $done = false;

	if ( $done ) {
		return;
	}
	$done = true;

	wp_enqueue_style(
		'nxtcc-login-widget',
		NXTCC_PLUGIN_URL . 'frontend/css/login-widget.css',
		array(),
		NXTCC_VERSION
	);

	wp_enqueue_script(
		'nxtcc-login-widget',
		NXTCC_PLUGIN_URL . 'frontend/js/login-widget.js',
		array( 'jquery' ),
		NXTCC_VERSION,
		true
	);

	$ui_opts = get_option( 'nxtcc_auth_options', array() );
	if ( ! is_array( $ui_opts ) ) {
		$ui_opts = array();
	}

	if ( ! function_exists( 'nxtcc_fm_get_options' ) ) {
		require_once NXTCC_PLUGIN_DIR . 'includes/force-migration/options.php';
	}

	$policy = array();
	if ( function_exists( 'nxtcc_fm_get_options' ) ) {
		$policy = nxtcc_fm_get_options();
	} else {
		$policy = get_option( 'nxtcc_auth_policy', array() );
	}

	if ( ! is_array( $policy ) ) {
		$policy = array();
	}

	$raw_policy_full = get_option( 'nxtcc_auth_policy', array() );
	if ( ! is_array( $raw_policy_full ) ) {
		$raw_policy_full = array();
	}

	$raw_allowed = array();
	if ( isset( $raw_policy_full['allowed_countries'] ) ) {
		$raw_allowed = (array) $raw_policy_full['allowed_countries'];
	}

	$allowed_countries = array_values(
		array_unique(
			array_map(
				'strtoupper',
				$raw_allowed
			)
		)
	);

	$otp_len         = isset( $ui_opts['otp_len'] ) ? (int) $ui_opts['otp_len'] : 6;
	$resend_cooldown = isset( $ui_opts['resend_cooldown'] ) ? (int) $ui_opts['resend_cooldown'] : 30;

	$policy_otp_len         = isset( $policy['otp_len'] ) ? (int) $policy['otp_len'] : $otp_len;
	$policy_resend_cooldown = isset( $policy['resend_cooldown'] ) ? (int) $policy['resend_cooldown'] : $resend_cooldown;
	$policy_show_password   = ! empty( $policy['show_password'] ) ? 1 : 0;

	wp_localize_script(
		'nxtcc-login-widget',
		'NXTCC_AUTH',
		array(
			'rest'        => array(
				'url' => esc_url_raw( rest_url( 'nxtcc/v1' ) ),
			),
			'opts'        => array(
				'otp_len'         => $otp_len,
				'resend_cooldown' => $resend_cooldown,
			),
			'policy'      => array(
				'otpLen'           => $policy_otp_len,
				'resendCooldown'   => $policy_resend_cooldown,
				'showPassword'     => $policy_show_password,
				'allowedCountries' => $allowed_countries,
			),
			'countryJson' => NXTCC_PLUGIN_URL . 'languages/nxtcc-country-codes.json',
			'loggedIn'    => is_user_logged_in() ? 1 : 0,
			'logoutUrl'   => wp_logout_url( home_url( '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
		)
	);
}

/**
 * Ensure widget assets also load on the virtual force-migration page path.
 */
add_action(
	'wp',
	function (): void {
		if ( ! function_exists( 'nxtcc_fm_get_options' ) ) {
			return;
		}

		$opts = nxtcc_fm_get_options();
		if ( ! is_array( $opts ) || empty( $opts['force_path'] ) ) {
			return;
		}

		$raw_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $raw_uri ) {
			$raw_uri = '/';
		}

		$raw_uri = esc_url_raw( wp_unslash( (string) $raw_uri ) );
		$path    = wp_parse_url( $raw_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		if ( trailingslashit( $path ) === trailingslashit( (string) $opts['force_path'] ) ) {
			nxtcc_auth_enqueue_login_widget_assets();
		}
	}
);

/**
 * Remove WhatsApp auth bindings when a WordPress user is deleted.
 *
 * @param int $user_id WordPress user ID.
 * @return void
 */
function nxtcc_auth_delete_bindings_on_user_delete( int $user_id ): void {
	NXTCC_Auth_DB::i()->delete_bindings_for_user( $user_id );
}
add_action( 'delete_user', 'nxtcc_auth_delete_bindings_on_user_delete', 10, 1 );
add_action( 'wpmu_delete_user', 'nxtcc_auth_delete_bindings_on_user_delete', 10, 1 );
add_action( 'deleted_user', 'nxtcc_auth_delete_bindings_on_user_delete', 10, 1 );

/**
 * Provide approved templates through a filter (used by other modules).
 */
add_filter(
	'nxtcc_get_meta_templates',
	function ( $templates, $user_mailid, $phone_number_id ) {
		$user_mailid     = (string) $user_mailid;
		$phone_number_id = (string) $phone_number_id;

		$rows = NXTCC_Auth_DB::i()->get_approved_templates( $user_mailid, $phone_number_id );

		if ( empty( $rows ) ) {
			return array();
		}

		return $rows;
	},
	10,
	3
);

/**
 * Dashboard assets (top-level + first submenu).
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function nxtcc_enqueue_dashboard_assets( string $hook ): void {
	if (
		'toplevel_page_nxt-cloud-chat' !== $hook
		&& 'nxt-cloud-chat_page_nxt-cloud-chat' !== $hook
	) {
		return;
	}

	wp_enqueue_style(
		'nxtcc-dashboard-css',
		NXTCC_PLUGIN_URL . 'admin/assets/css/dashboard.css',
		array(),
		NXTCC_VERSION
	);

	wp_enqueue_script(
		'nxtcc-dashboard-js',
		NXTCC_PLUGIN_URL . 'admin/assets/js/dashboard.js',
		array( 'jquery' ),
		NXTCC_VERSION,
		true
	);

	nxtcc_enqueue_fontawesome();
}
add_action( 'admin_enqueue_scripts', 'nxtcc_enqueue_dashboard_assets' );
