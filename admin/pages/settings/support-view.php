<?php
/**
 * Support settings module view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

$nxtcc_support_badge_enabled = 1 === (int) get_option( 'nxtcc_support_badge_enabled', 1 );
$nxtcc_support_url           = 'https://nxtwebsite.com/support-portal/';

if ( function_exists( 'nxtcc_support_badge_url' ) ) {
	$nxtcc_support_url = nxtcc_support_badge_url(
		array(
			'domain'       => function_exists( 'nxtcc_support_badge_source_domain' ) ? nxtcc_support_badge_source_domain() : '',
			'product_slug' => 'nxt-cloud-chat',
			'page_slug'    => 'nxtcc-settings',
			'version'      => defined( 'NXTCC_VERSION' ) ? (string) NXTCC_VERSION : '',
		)
	);
}
?>

<div class="nxtcc-settings-connection nxtcc-settings-support">
	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Support', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Our support team can help with connection setup, technical issues, order-related questions, and plugin configuration.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="" class="nxtcc-settings-card-form">
			<?php wp_nonce_field( 'nxtcc_support_settings_save', 'nxtcc_support_settings_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_support_settings">
			<input type="hidden" name="nxtcc_settings_active_tab" value="support">

			<div class="nxtcc-settings-field nxtcc-settings-checkbox-field">
				<label class="nxtcc-settings-checkbox-label" for="nxtcc_support_badge_enabled">
					<input
						type="checkbox"
						id="nxtcc_support_badge_enabled"
						name="nxtcc_support_badge_enabled"
						value="1"
						<?php checked( $nxtcc_support_badge_enabled ); ?>
					/>
					<span><?php esc_html_e( 'Show the support badge on NXT Cloud Chat admin pages', 'nxt-cloud-chat' ); ?></span>
				</label>
				<p class="nxtcc-settings-field-note">
					<?php esc_html_e( 'Turn this off to hide the floating support shortcut from NXT Cloud Chat admin screens.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-settings-actions">
				<a
					class="nxtcc-button nxtcc-button-light"
					href="<?php echo esc_url( $nxtcc_support_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Contact Support', 'nxt-cloud-chat' ); ?>
				</a>
				<button type="submit" class="nxtcc-button nxtcc-button-primary" name="nxtcc_save_support_settings">
					<?php esc_html_e( 'Save Support Settings', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</form>
	</section>
</div>
