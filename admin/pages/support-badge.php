<?php
/**
 * Floating support badge view.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div
	id="nxtcc-support-badge"
	class="nxtcc-support-badge"
	data-storage-key="nxtccSupportBadgeCollapsed"
	data-collapsed-label="<?php echo esc_attr__( 'Get Support', 'nxt-cloud-chat' ); ?>"
>
	<a
		class="nxtcc-support-badge__link"
		href="<?php echo esc_url( $support_url ); ?>"
		target="_blank"
		rel="noopener noreferrer"
	>
		<span class="dashicons dashicons-sos" aria-hidden="true"></span>
		<span class="nxtcc-support-badge__text"><?php esc_html_e( 'Get Support', 'nxt-cloud-chat' ); ?></span>
	</a>
	<button
		type="button"
		class="nxtcc-support-badge__toggle"
		aria-label="<?php echo esc_attr__( 'Collapse support badge', 'nxt-cloud-chat' ); ?>"
		aria-expanded="true"
	>
		<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
	</button>
</div>
