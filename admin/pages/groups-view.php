<?php
/**
 * Groups view (admin UI).
 *
 * Renders the Groups management screen markup used by the admin script.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_groups', 'nxtcc_manage_groups' ) ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nxt-cloud-chat' ) );
}

$nxtcc_groups_nonce   = wp_create_nonce( 'nxtcc_groups' );
$nxtcc_has_connection = false;

if ( class_exists( 'NXTCC_Contacts_Handler_Repo' ) && is_user_logged_in() ) {
	list( , $nxtcc_baid, $nxtcc_pnid ) = NXTCC_Contacts_Handler_Repo::instance()->get_current_tenant_for_user( get_current_user_id() );

	if ( ! empty( $nxtcc_baid ) && ! empty( $nxtcc_pnid ) ) {
		$nxtcc_has_connection = true;
	}
}
?>

<div class="wrap nxtcc-groups-screen">
	<div
		class="nxtcc-groups-widget"
		data-nonce="<?php echo esc_attr( $nxtcc_groups_nonce ); ?>"
		data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
	>
		<?php if ( ! $nxtcc_has_connection ) : ?>
			<div class="nxtcc-groups-alert nxtcc-groups-alert-warning">
				<strong><?php esc_html_e( 'Action required:', 'nxt-cloud-chat' ); ?></strong>
				<span>
					<?php esc_html_e( 'Connect your WhatsApp Business account and phone number before creating or editing groups.', 'nxt-cloud-chat' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="nxtcc-groups-summary">
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'All Groups', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-groups-summary-total" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Visible in the current tenant', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Verified Groups', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-groups-summary-verified" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Locked from rename and delete', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Contacts in Groups', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-groups-summary-contacts" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Total contacts across the listed groups', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Connection', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-groups-summary-connection" class="nxtcc-groups-summary-value">
					<?php echo esc_html( $nxtcc_has_connection ? __( 'Ready', 'nxt-cloud-chat' ) : __( 'Action Required', 'nxt-cloud-chat' ) ); ?>
				</strong>
				<span class="nxtcc-groups-summary-meta">
					<?php echo esc_html( $nxtcc_has_connection ? __( 'Groups are ready for tenant management.', 'nxt-cloud-chat' ) : __( 'Save tenant connection settings to unlock group actions.', 'nxt-cloud-chat' ) ); ?>
				</span>
			</div>
		</div>

		<div class="nxtcc-groups-toolbar">
			<div class="nxtcc-groups-toolbar-copy">
				<h1 class="nxtcc-page-title"><?php esc_html_e( 'Groups', 'nxt-cloud-chat' ); ?></h1>
				</div>

			<div class="nxtcc-groups-toolbar-controls">
				<input
					type="search"
					id="nxtcc-groups-search"
					class="nxtcc-groups-search-input"
					placeholder="<?php echo esc_attr__( 'Search group name', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php echo esc_attr__( 'Search groups', 'nxt-cloud-chat' ); ?>"
				>

				<button
					id="nxtcc-add-group-btn"
					class="nxtcc-groups-btn nxtcc-groups-btn-green"
					type="button"
					<?php disabled( ! $nxtcc_has_connection ); ?>
					<?php if ( ! $nxtcc_has_connection ) : ?>
						aria-disabled="true"
						title="<?php echo esc_attr__( 'Connect WhatsApp first to create groups.', 'nxt-cloud-chat' ); ?>"
					<?php endif; ?>
				>
					<span class="nxtcc-groups-btn-icon" aria-hidden="true">+</span>
					<?php esc_html_e( 'Add Group', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</div>

		<div class="nxtcc-groups-inline-note">
			<span class="nxtcc-groups-inline-note-badge"><?php esc_html_e( 'Verified', 'nxt-cloud-chat' ); ?></span>
			<span><?php esc_html_e( 'Verified groups cannot be renamed or deleted. They are also excluded from bulk delete.', 'nxt-cloud-chat' ); ?></span>
		</div>

		<div id="nxtcc-groups-bulk-actions" class="nxtcc-groups-bulk-wrap" style="display:none;">
			<label for="nxtcc-groups-bulk-select" class="screen-reader-text">
				<?php esc_html_e( 'Bulk action', 'nxt-cloud-chat' ); ?>
			</label>

			<select id="nxtcc-groups-bulk-select" class="nxtcc-groups-select">
				<option value=""><?php esc_html_e( 'Bulk actions', 'nxt-cloud-chat' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete selected', 'nxt-cloud-chat' ); ?></option>
			</select>

			<button
				id="nxtcc-groups-bulk-apply"
				class="nxtcc-groups-btn nxtcc-groups-btn-secondary"
				type="button"
				<?php disabled( ! $nxtcc_has_connection ); ?>
				<?php if ( ! $nxtcc_has_connection ) : ?>
					aria-disabled="true"
					title="<?php echo esc_attr__( 'Connect WhatsApp first to run bulk actions.', 'nxt-cloud-chat' ); ?>"
				<?php endif; ?>
			>
				<?php esc_html_e( 'Apply', 'nxt-cloud-chat' ); ?>
			</button>
		</div>

		<div class="nxtcc-groups-table-wrap">
			<table class="nxtcc-groups-table" aria-describedby="nxtcc-groups-help">
				<thead>
					<tr>
						<th class="checkbox-col" scope="col">
							<input
								type="checkbox"
								id="nxtcc-groups-select-all"
								aria-label="<?php echo esc_attr__( 'Select all groups', 'nxt-cloud-chat' ); ?>"
							>
						</th>
						<th class="sortable" data-key="group_name" scope="col">
							<?php esc_html_e( 'Group Name', 'nxt-cloud-chat' ); ?>
						</th>
						<th class="sortable" data-key="count" scope="col">
							<?php esc_html_e( 'Contacts', 'nxt-cloud-chat' ); ?>
						</th>
						<th class="sortable" data-key="created_by" scope="col">
							<?php esc_html_e( 'Created By', 'nxt-cloud-chat' ); ?>
						</th>
						<th class="sortable" data-key="updated_by" scope="col">
							<?php esc_html_e( 'Updated By', 'nxt-cloud-chat' ); ?>
						</th>
						<th class="actions-col" scope="col">
							<?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?>
						</th>
					</tr>
				</thead>

				<tbody id="nxtcc-groups-tbody">
					<tr class="nxtcc-groups-state-row">
						<td colspan="99" class="nxtcc-groups-state-cell">
							<?php esc_html_e( 'Loading groups...', 'nxt-cloud-chat' ); ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p id="nxtcc-groups-help" class="screen-reader-text">
				<?php esc_html_e( 'Use search, sorting, and bulk actions to manage your groups. Verified groups are locked.', 'nxt-cloud-chat' ); ?>
			</p>
		</div>

		<div id="nxtcc-group-modal" class="nxtcc-groups-modal" style="display:none;">
			<div
				class="nxtcc-groups-modal-overlay"
				role="button"
				aria-label="<?php echo esc_attr__( 'Close modal', 'nxt-cloud-chat' ); ?>"
			></div>

			<div
				class="nxtcc-groups-modal-content"
				role="dialog"
				aria-modal="true"
				aria-labelledby="nxtcc-group-modal-title"
			>
				<div class="nxtcc-groups-modal-header">
					<div class="nxtcc-groups-modal-copy">
						<h2 id="nxtcc-group-modal-title"><?php esc_html_e( 'Add Group', 'nxt-cloud-chat' ); ?></h2>
						<p class="nxtcc-groups-modal-subtitle">
							<?php esc_html_e( 'Create a tenant group for reusable contact segmentation.', 'nxt-cloud-chat' ); ?>
						</p>
					</div>
					<button
						class="nxtcc-groups-modal-close-icon nxtcc-groups-modal-dismiss"
						type="button"
						title="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>"
						aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>"
					>&times;</button>
				</div>

				<form id="nxtcc-group-form" autocomplete="off" class="nxtcc-groups-modal-form">
					<input type="hidden" name="group_id" id="nxtcc-group-id" value="">

					<div class="nxtcc-groups-form-row">
						<label for="nxtcc-group-name">
							<?php esc_html_e( 'Group Name', 'nxt-cloud-chat' ); ?>
							<span class="nxtcc-groups-required" aria-hidden="true">*</span>
						</label>

						<input type="text" id="nxtcc-group-name" name="group_name" required>
					</div>

					<p class="nxtcc-verified-hint">
						<?php esc_html_e( 'This is a verified group and cannot be renamed.', 'nxt-cloud-chat' ); ?>
					</p>

					<div class="nxtcc-groups-modal-footer">
						<button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-groups-modal-dismiss">
							<?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?>
						</button>
						<button type="submit" class="nxtcc-groups-btn nxtcc-groups-btn-green">
							<?php esc_html_e( 'Save Group', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
