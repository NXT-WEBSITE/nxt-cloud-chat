<?php
/**
 * Admin dashboard view for NXT Cloud Chat.
 *
 * Renders the plugin's dashboard cards inside the WordPress admin area.
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
 * Connection settings URL used by dashboard JavaScript.
 *
 * @var string
 */
$nxtcc_settings_url = admin_url( 'admin.php?page=nxtcc-settings' );

/**
 * UTM source for dashboard setup/help links.
 *
 * @var string
 */
$nxtcc_setup_utm_source = function_exists( 'nxtcc_support_badge_source_domain' ) ? nxtcc_support_badge_source_domain() : '';

if ( '' === $nxtcc_setup_utm_source ) {
	$nxtcc_setup_utm_source = 'wordpress_plugin';
}

/**
 * Setup guide URL shown in the Need Help card.
 *
 * @var string
 */
$nxtcc_setup_guide_url = add_query_arg(
	array(
		'utm_source'   => $nxtcc_setup_utm_source,
		'utm_medium'   => 'dashboard_help_card',
		'utm_campaign' => 'nxt_cloud_chat_setup',
	),
	'https://nxtcloudchat.com/user-guide/'
);

/**
 * Setup support URL shown in the Need Help card.
 *
 * @var string
 */
$nxtcc_setup_help_url = add_query_arg(
	array(
		'utm_source'   => $nxtcc_setup_utm_source,
		'utm_medium'   => 'dashboard_help_card',
		'utm_campaign' => 'nxt_cloud_chat_setup_help',
	),
	'https://nxtwebsite.com/support-portal/'
);
?>
<div
	class="nxtcc-dashboard-widget"
	data-nonce="<?php echo esc_attr( $nxtcc_nonce ); ?>"
	data-ajax="<?php echo esc_url( $nxtcc_ajaxurl ); ?>"
	data-settings-url="<?php echo esc_url( $nxtcc_settings_url ); ?>"
>

	<div class="nxtcc-dashboard-cards">

		<div class="nxtcc-card" id="nxtcc-card-connection">
			<div class="nxtcc-card-head">
				<h2><?php esc_html_e( 'Connection', 'nxt-cloud-chat' ); ?></h2>

				<span class="nxtcc-badge nxtcc-badge-neutral" data-role="connection-badge">-</span>

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
				<div class="nxtcc-status-list" data-role="connection-basics"></div>
			</div>

			<div class="nxtcc-card-footer">
				<a
					href="<?php echo esc_url( $nxtcc_settings_url ); ?>"
					class="nxtcc-card-footer-link"
				>
					<?php echo esc_html__( 'WhatsApp Connection Settings', 'nxt-cloud-chat' ); ?>
				</a>
			</div>
		</div>

		<div class="nxtcc-card" id="nxtcc-card-health">
			<div class="nxtcc-card-head">
				<h2><?php esc_html_e( 'Messaging and Calling Health Status', 'nxt-cloud-chat' ); ?></h2>

				<span class="nxtcc-badge nxtcc-badge-neutral" data-role="health-badge">-</span>

				<div class="nxtcc-card-actions">
					<button
						type="button"
						id="nxtcc-health-refresh"
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
				<div class="nxtcc-health-summary" data-role="health-summary"></div>
				<div class="nxtcc-health-entities" data-role="health-entities"></div>
				<div class="nxtcc-small" data-role="health-checked-at"></div>
			</div>
		</div>

		<div class="nxtcc-card" id="nxtcc-card-help">
			<div class="nxtcc-card-head">
				<h2><?php esc_html_e( 'Need Help?', 'nxt-cloud-chat' ); ?></h2>
			</div>

			<div class="nxtcc-card-body">
				<ul class="nxtcc-list nxtcc-help-list">
					<li>
						<a
							href="<?php echo esc_url( $nxtcc_setup_guide_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php echo esc_html__( 'Step-by-step setup guide', 'nxt-cloud-chat' ); ?>
						</a>
					</li>
					<li>
						<a
							href="<?php echo esc_url( $nxtcc_setup_help_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php echo esc_html__( 'Get free setup assistance', 'nxt-cloud-chat' ); ?>
						</a>
					</li>
				</ul>
			</div>
		</div>

	</div>

</div>
