<?php
/**
 * Admin dashboard view for NXT Cloud Chat.
 *
 * Renders the plugin's dashboard cards inside the WordPress admin area:
 * - Connection status card (details loaded via AJAX).
 * - Upgrade card with feature highlights and an external upgrade link.
 *
 * This file is a view template and does not process requests directly.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce used by dashboard JavaScript for AJAX requests on this screen.
 *
 * @var string
 */
$nxtcc_nonce = wp_create_nonce( 'nxtcc_dashboard' );

/**
 * Admin AJAX endpoint used by dashboard JavaScript.
 *
 * @var string
 */
$nxtcc_ajaxurl = admin_url( 'admin-ajax.php' );

/**
 * Default upgrade URL used when the plugin header does not provide Author URI.
 *
 * @var string
 */
$nxtcc_default_author = 'https://nxtwebsite.com';

/**
 * Absolute path to the main plugin file. Used to read header metadata.
 *
 * @var string
 */
$nxtcc_plugin_file = trailingslashit( NXTCC_PLUGIN_DIR ) . 'nxt-cloud-chat.php';

/**
 * Resolve the upgrade URL from plugin header data (Author URI).
 * Falls back to the default author URL if metadata is not available.
 *
 * @var string
 */
$nxtcc_upgrade_url = $nxtcc_default_author;
$nxtcc_data        = array();

if ( function_exists( 'get_plugin_data' ) && file_exists( $nxtcc_plugin_file ) ) {
	$nxtcc_data = get_plugin_data( $nxtcc_plugin_file, false, false );

	if ( ! empty( $nxtcc_data['AuthorURI'] ) ) {
		$nxtcc_upgrade_url = esc_url_raw( $nxtcc_data['AuthorURI'] );
	}
}
?>
<div
	class="nxtcc-dashboard-widget"
	data-nonce="<?php echo esc_attr( $nxtcc_nonce ); ?>"
	data-ajax="<?php echo esc_url( $nxtcc_ajaxurl ); ?>"
>

	<div class="nxtcc-dashboard-cards">

		<div class="nxtcc-card" id="nxtcc-card-connection">
			<div class="nxtcc-card-head">
				<h2><?php esc_html_e( 'Connection', 'nxt-cloud-chat' ); ?></h2>

				<span class="nxtcc-badge nxtcc-badge-neutral" data-role="badge">—</span>

				<div class="nxtcc-card-actions">
					<button
						type="button"
						id="nxtcc-connection-refresh"
						class="nxtcc-icon-btn"
						title="<?php esc_attr_e( 'Refresh', 'nxt-cloud-chat' ); ?>"
					>
						<i class="fa fa-refresh" aria-hidden="true"></i>
						<span class="screen-reader-text">
							<?php esc_html_e( 'Refresh', 'nxt-cloud-chat' ); ?>
						</span>
					</button>
				</div>
			</div>

			<div class="nxtcc-card-body">
				<ul class="nxtcc-list" data-role="connection-details"></ul>
			</div>
		</div>

		<div class="nxtcc-card nxtcc-card-upgrade" id="nxtcc-card-upgrade">
			<div class="nxtcc-card-head">
				<h2><?php esc_html_e( 'Upgrade to NXT Cloud Chat Pro', 'nxt-cloud-chat' ); ?></h2>
			</div>

			<div class="nxtcc-card-body">
				<p class="nxtcc-upgrade-intro">
					<?php esc_html_e( 'Unlock more powerful WhatsApp tools for your WordPress site:', 'nxt-cloud-chat' ); ?>
				</p>

				<ul class="nxtcc-list nxtcc-upgrade-features">
					<li><?php esc_html_e( 'Bulk WhatsApp messaging', 'nxt-cloud-chat' ); ?></li>
					<li><?php esc_html_e( 'Templates management', 'nxt-cloud-chat' ); ?></li>
					<li><?php esc_html_e( 'WooCommerce integration', 'nxt-cloud-chat' ); ?></li>
					<li><?php esc_html_e( 'Advanced dashboard & reports', 'nxt-cloud-chat' ); ?></li>
				</ul>

				<p class="nxtcc-upgrade-cta-wrap">
					<a
						class="button button-primary nxtcc-upgrade-btn"
						href="<?php echo esc_url( $nxtcc_upgrade_url ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php esc_html_e( 'Upgrade', 'nxt-cloud-chat' ); ?>
					</a>
				</p>
			</div>
		</div>

	</div>

</div>
