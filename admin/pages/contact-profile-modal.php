<?php
/**
 * Shared contact profile modal.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="nxtcc-profile-modal" id="nxtcc-contact-profile-modal" hidden>
	<div class="nxtcc-profile-overlay" data-nxtcc-profile-close></div>
	<div class="nxtcc-profile-dialog" role="dialog" aria-modal="true" aria-labelledby="nxtcc-contact-profile-title">
		<header class="nxtcc-profile-header">
			<div class="nxtcc-profile-avatar" data-profile-avatar aria-hidden="true">?</div>
			<div class="nxtcc-profile-identity">
				<div class="nxtcc-profile-title-row">
					<h2 id="nxtcc-contact-profile-title" data-profile-name><?php esc_html_e( 'Contact Profile', 'nxt-cloud-chat' ); ?></h2>
					<span class="nxtcc-profile-status" data-profile-subscription></span>
				</div>
				<div class="nxtcc-profile-phone" data-profile-phone></div>
				<div class="nxtcc-profile-header-meta">
					<span data-profile-assignment></span>
					<span data-profile-verified></span>
				</div>
			</div>
			<button type="button" class="nxtcc-profile-close" data-nxtcc-profile-close aria-label="<?php echo esc_attr__( 'Close contact profile', 'nxt-cloud-chat' ); ?>">&times;</button>
		</header>

		<div class="nxtcc-profile-state" data-profile-state><?php esc_html_e( 'Loading contact profile...', 'nxt-cloud-chat' ); ?></div>

		<div class="nxtcc-profile-body" data-profile-body hidden>
			<div class="nxtcc-profile-details">
				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Contact Basics', 'nxt-cloud-chat' ); ?></h3>
					<dl class="nxtcc-profile-definition-list">
						<div><dt><?php esc_html_e( 'Created', 'nxt-cloud-chat' ); ?></dt><dd data-profile-created></dd></div>
						<div><dt><?php esc_html_e( 'Updated', 'nxt-cloud-chat' ); ?></dt><dd data-profile-updated></dd></div>
						<div><dt><?php esc_html_e( 'WordPress User', 'nxt-cloud-chat' ); ?></dt><dd data-profile-wp-user></dd></div>
					</dl>
				</section>

				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Groups', 'nxt-cloud-chat' ); ?></h3>
					<div class="nxtcc-profile-chips" data-profile-groups></div>
				</section>

				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Tags', 'nxt-cloud-chat' ); ?></h3>
					<div class="nxtcc-profile-chips" data-profile-tags></div>
				</section>

				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Lifecycle Stage', 'nxt-cloud-chat' ); ?></h3>
					<div class="nxtcc-profile-lifecycle">
						<span class="nxtcc-profile-chip" data-profile-lifecycle-label></span>
						<select data-profile-lifecycle-select hidden aria-label="<?php echo esc_attr__( 'Contact lifecycle stage', 'nxt-cloud-chat' ); ?>"></select>
					</div>
				</section>

				<?php if ( NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) ) ) : ?>
					<section class="nxtcc-profile-section">
						<div class="nxtcc-profile-section-heading">
							<h3><?php esc_html_e( 'Deals', 'nxt-cloud-chat' ); ?></h3>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-deals' ) ); ?>"><?php esc_html_e( 'Open Deals', 'nxt-cloud-chat' ); ?></a>
						</div>
						<div class="nxtcc-profile-deals" data-profile-deals></div>
					</section>
				<?php endif; ?>

				<section class="nxtcc-profile-section" data-profile-duplicates-section hidden>
					<h3><?php esc_html_e( 'Possible Duplicates', 'nxt-cloud-chat' ); ?></h3>
					<div class="nxtcc-profile-duplicates" data-profile-duplicates></div>
				</section>

				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Message Context', 'nxt-cloud-chat' ); ?></h3>
					<dl class="nxtcc-profile-definition-list">
						<div><dt><?php esc_html_e( 'Total', 'nxt-cloud-chat' ); ?></dt><dd data-profile-message-total></dd></div>
						<div><dt><?php esc_html_e( 'Inbound', 'nxt-cloud-chat' ); ?></dt><dd data-profile-message-inbound></dd></div>
						<div><dt><?php esc_html_e( 'Outbound', 'nxt-cloud-chat' ); ?></dt><dd data-profile-message-outbound></dd></div>
						<div><dt><?php esc_html_e( 'Latest Message', 'nxt-cloud-chat' ); ?></dt><dd data-profile-message-latest></dd></div>
						<div><dt><?php esc_html_e( 'Latest Inbound', 'nxt-cloud-chat' ); ?></dt><dd data-profile-inbound-latest></dd></div>
					</dl>
				</section>

				<section class="nxtcc-profile-section">
					<h3><?php esc_html_e( 'Custom Fields', 'nxt-cloud-chat' ); ?></h3>
					<dl class="nxtcc-profile-definition-list" data-profile-custom-fields></dl>
				</section>
			</div>

			<section class="nxtcc-profile-timeline">
				<div class="nxtcc-profile-task-panel">
					<div class="nxtcc-profile-timeline-header">
						<div>
							<h3><?php esc_html_e( 'Tasks & Follow-ups', 'nxt-cloud-chat' ); ?></h3>
							<p><?php esc_html_e( 'Open reminders connected to this contact.', 'nxt-cloud-chat' ); ?></p>
						</div>
					</div>
					<form class="nxtcc-profile-task-form" data-profile-task-form hidden>
						<input type="text" maxlength="191" required data-profile-task-title placeholder="<?php echo esc_attr__( 'Follow-up task', 'nxt-cloud-chat' ); ?>">
						<input type="datetime-local" data-profile-task-due aria-label="<?php echo esc_attr__( 'Task due date', 'nxt-cloud-chat' ); ?>">
						<select data-profile-task-priority aria-label="<?php echo esc_attr__( 'Task priority', 'nxt-cloud-chat' ); ?>">
							<option value="normal"><?php esc_html_e( 'Normal', 'nxt-cloud-chat' ); ?></option>
							<option value="low"><?php esc_html_e( 'Low', 'nxt-cloud-chat' ); ?></option>
							<option value="high"><?php esc_html_e( 'High', 'nxt-cloud-chat' ); ?></option>
							<option value="urgent"><?php esc_html_e( 'Urgent', 'nxt-cloud-chat' ); ?></option>
						</select>
						<button type="submit" class="nxtcc-profile-button"><?php esc_html_e( 'Add Task', 'nxt-cloud-chat' ); ?></button>
					</form>
					<div class="nxtcc-profile-task-list" data-profile-tasks></div>
				</div>

				<div class="nxtcc-profile-timeline-header">
					<div>
						<h3><?php esc_html_e( 'Activity Timeline', 'nxt-cloud-chat' ); ?></h3>
						<p><?php esc_html_e( 'Recent CRM activity for this contact.', 'nxt-cloud-chat' ); ?></p>
					</div>
				</div>
				<div class="nxtcc-profile-timeline-list" data-profile-timeline></div>
				<div class="nxtcc-profile-timeline-footer">
					<button type="button" class="nxtcc-profile-button" data-profile-load-older hidden><?php esc_html_e( 'Load Older', 'nxt-cloud-chat' ); ?></button>
				</div>
			</section>
		</div>
	</div>
</div>
