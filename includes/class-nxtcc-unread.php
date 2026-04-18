<?php
/**
 * Admin unread badge + AJAX for NXT Cloud Chat.
 *
 * Adds a live unread counter badge in the WP admin menu and exposes
 * an AJAX endpoint for polling the unread message count.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin unread badge + AJAX.
 */
final class NXTCC_Unread {

	/**
	 * AJAX action name.
	 */
	private const AJAX_ACTION = 'nxtcc_unread_count';

	/**
	 * Nonce action name.
	 */
	private const NONCE_ACTION = 'nxtcc_unread_nonce';

	/**
	 * Menu slug where the badge should appear.
	 *
	 * @var string
	 */
	private static string $menu_slug = 'nxtcc-chat-window';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_unread_count' ) );
	}

	/**
	 * Override the default menu slug used for badge placement.
	 *
	 * @param string $slug Menu slug.
	 * @return void
	 */
	public static function set_menu_slug( string $slug ): void {
		$slug = sanitize_key( $slug );
		if ( '' !== $slug ) {
			self::$menu_slug = $slug;
		}
	}

	/**
	 * Enqueue assets used to render and update the badge.
	 *
	 * We do not modify $menu/$submenu because VIP disallows overriding WP globals.
	 * Instead, JS injects a badge element into the matching admin menu item.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		unset( $hook );

		wp_enqueue_style(
			'nxtcc-menu-badge-css',
			plugins_url( '../admin/assets/css/menu-badge.css', __FILE__ ),
			array(),
			'1.0'
		);

		wp_enqueue_script(
			'nxtcc-menu-badge-js',
			plugins_url( '../admin/assets/js/menu-badge.js', __FILE__ ),
			array( 'jquery' ),
			'1.0',
			true
		);

		wp_localize_script(
			'nxtcc-menu-badge-js',
			'NXTCCUnread',
			array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'interval'  => 30000,
				'menu_slug' => self::$menu_slug,
			)
		);
	}

	/**
	 * AJAX handler: return unread count for the logged-in user email.
	 *
	 * @return void
	 */
	public static function ajax_unread_count(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$nonce = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $nonce ) {
			$nonce = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}
		$nonce = is_string( $nonce ) ? sanitize_text_field( wp_unslash( $nonce ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'Bad nonce' ), 403 );
		}

		$tenant = NXTCC_Access_Control::get_current_tenant_context();
		$mail   = isset( $tenant['user_mailid'] ) ? (string) $tenant['user_mailid'] : '';

		$count = 0;
		if ( '' !== $mail ) {
			$count = NXTCC_Message_History_Repo::instance()->count_unread_for_mail( $mail );
		}

		$display = ( 99 < $count ) ? '99+' : (string) $count;

		wp_send_json_success(
			array(
				'count'   => (int) $count,
				'display' => $display,
			)
		);
	}
}

NXTCC_Unread::init();
