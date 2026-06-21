<?php
/**
 * Tags management view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_tags', 'nxtcc_manage_tags' ) ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nxt-cloud-chat' ) );
}

$nxtcc_tags_nonce     = wp_create_nonce( 'nxtcc_tags' );
$nxtcc_can_manage     = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_tags' ) );
$nxtcc_active_tenant  = NXTCC_Access_Control::get_current_tenant_context();
$nxtcc_has_connection = (
	! empty( $nxtcc_active_tenant['user_mailid'] )
	&& ! empty( $nxtcc_active_tenant['business_account_id'] )
	&& ! empty( $nxtcc_active_tenant['phone_number_id'] )
);
?>

<div class="wrap nxtcc-groups-screen nxtcc-tags-screen">
	<div
		class="nxtcc-groups-widget nxtcc-tags-widget"
		data-nonce="<?php echo esc_attr( $nxtcc_tags_nonce ); ?>"
		data-can-manage="<?php echo esc_attr( $nxtcc_can_manage ? '1' : '0' ); ?>"
		data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
	>
		<?php if ( ! $nxtcc_has_connection ) : ?>
			<div class="nxtcc-groups-alert nxtcc-groups-alert-warning">
				<strong><?php esc_html_e( 'Action required:', 'nxt-cloud-chat' ); ?></strong>
				<span><?php esc_html_e( 'Connect an account and phone number before managing tenant tags.', 'nxt-cloud-chat' ); ?></span>
			</div>
		<?php endif; ?>

		<div class="nxtcc-groups-summary">
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'All Tags', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-tags-summary-total" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Available in the current tenant', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Tagged Contacts', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-tags-summary-contacts" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Contacts with at least one tag', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Assignments', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-tags-summary-assignments" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Total contact-tag relationships', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Connection', 'nxt-cloud-chat' ); ?></span>
				<strong class="nxtcc-groups-summary-value">
					<?php echo esc_html( $nxtcc_has_connection ? __( 'Ready', 'nxt-cloud-chat' ) : __( 'Action Required', 'nxt-cloud-chat' ) ); ?>
				</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Tags remain scoped to the active tenant.', 'nxt-cloud-chat' ); ?></span>
			</div>
		</div>

		<div class="nxtcc-groups-toolbar">
			<div class="nxtcc-groups-toolbar-copy">
				<h1 class="nxtcc-page-title"><?php esc_html_e( 'Tags', 'nxt-cloud-chat' ); ?></h1>
			</div>

			<div class="nxtcc-groups-toolbar-controls">
				<input
					type="search"
					id="nxtcc-tags-search"
					class="nxtcc-groups-search-input nxtcc-ui-filter-control"
					placeholder="<?php echo esc_attr__( 'Search tags', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php echo esc_attr__( 'Search tags', 'nxt-cloud-chat' ); ?>"
				>

				<?php if ( $nxtcc_can_manage ) : ?>
					<button
						id="nxtcc-add-tag-btn"
						class="nxtcc-groups-btn nxtcc-groups-btn-green"
						type="button"
						<?php disabled( ! $nxtcc_has_connection ); ?>
					>
						<span class="nxtcc-groups-btn-icon" aria-hidden="true">+</span>
						<?php esc_html_e( 'Add Tag', 'nxt-cloud-chat' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="nxtcc-groups-inline-note">
			<span class="nxtcc-tags-note-badge"><?php esc_html_e( 'CRM', 'nxt-cloud-chat' ); ?></span>
			<span><?php esc_html_e( 'Tags are flexible contact attributes. Deleting or merging tags never deletes contacts.', 'nxt-cloud-chat' ); ?></span>
		</div>

		<?php if ( $nxtcc_can_manage ) : ?>
			<div id="nxtcc-tags-bulk-actions" class="nxtcc-groups-bulk-wrap" style="display:none;">
				<span id="nxtcc-tags-selected-count"></span>
				<button id="nxtcc-tags-merge-selected" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button">
					<?php esc_html_e( 'Merge Selected', 'nxt-cloud-chat' ); ?>
				</button>
				<button id="nxtcc-tags-delete-selected" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button">
					<?php esc_html_e( 'Delete Selected', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		<?php endif; ?>

		<div class="nxtcc-groups-table-wrap">
			<table class="nxtcc-groups-table nxtcc-tags-table">
				<thead>
					<tr>
						<?php if ( $nxtcc_can_manage ) : ?>
							<th class="checkbox-col" scope="col">
								<input type="checkbox" id="nxtcc-tags-select-all" aria-label="<?php echo esc_attr__( 'Select all tags', 'nxt-cloud-chat' ); ?>">
							</th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Tag', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Contacts', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'nxt-cloud-chat' ); ?></th>
						<?php if ( $nxtcc_can_manage ) : ?>
							<th class="actions-col" scope="col"><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody id="nxtcc-tags-tbody">
					<tr class="nxtcc-groups-state-row">
						<td colspan="99" class="nxtcc-groups-state-cell"><?php esc_html_e( 'Loading tags...', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tbody>
			</table>

			<div class="nxtcc-tags-pagination">
				<button id="nxtcc-tags-prev" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button" disabled>
					<?php esc_html_e( 'Previous', 'nxt-cloud-chat' ); ?>
				</button>
				<span id="nxtcc-tags-page-label"></span>
				<button id="nxtcc-tags-next" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button" disabled>
					<?php esc_html_e( 'Next', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</div>

		<?php if ( $nxtcc_can_manage ) : ?>
			<div id="nxtcc-tag-modal" class="nxtcc-groups-modal" style="display:none;">
				<div class="nxtcc-groups-modal-overlay nxtcc-tag-modal-dismiss"></div>
				<div class="nxtcc-groups-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-tag-modal-title">
					<div class="nxtcc-groups-modal-header">
						<div class="nxtcc-groups-modal-copy">
							<h2 id="nxtcc-tag-modal-title"><?php esc_html_e( 'Add Tag', 'nxt-cloud-chat' ); ?></h2>
							<p class="nxtcc-groups-modal-subtitle"><?php esc_html_e( 'Create a reusable contact label for the active tenant.', 'nxt-cloud-chat' ); ?></p>
						</div>
						<button class="nxtcc-groups-modal-close-icon nxtcc-tag-modal-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
					</div>

					<form id="nxtcc-tag-form" class="nxtcc-groups-modal-form" autocomplete="off">
						<input type="hidden" id="nxtcc-tag-id" value="">

						<div class="nxtcc-groups-form-row">
							<label for="nxtcc-tag-name"><?php esc_html_e( 'Tag Name', 'nxt-cloud-chat' ); ?></label>
							<input type="text" id="nxtcc-tag-name" maxlength="191" required>
						</div>

						<div class="nxtcc-groups-form-row">
							<label for="nxtcc-tag-color"><?php esc_html_e( 'Color', 'nxt-cloud-chat' ); ?></label>
							<input type="color" id="nxtcc-tag-color" value="#2271b1">
						</div>

						<div class="nxtcc-groups-form-row">
							<label for="nxtcc-tag-description"><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></label>
							<textarea id="nxtcc-tag-description" rows="3" maxlength="500"></textarea>
						</div>

						<div class="nxtcc-groups-modal-footer">
							<button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-tag-modal-dismiss"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
							<button type="submit" class="nxtcc-groups-btn nxtcc-groups-btn-green"><?php esc_html_e( 'Save Tag', 'nxt-cloud-chat' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div id="nxtcc-tag-merge-modal" class="nxtcc-groups-modal" style="display:none;">
				<div class="nxtcc-groups-modal-overlay nxtcc-tag-merge-dismiss"></div>
				<div class="nxtcc-groups-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-tag-merge-title">
					<div class="nxtcc-groups-modal-header">
						<div class="nxtcc-groups-modal-copy">
							<h2 id="nxtcc-tag-merge-title"><?php esc_html_e( 'Merge Tags', 'nxt-cloud-chat' ); ?></h2>
							<p class="nxtcc-groups-modal-subtitle"><?php esc_html_e( 'Assignments move to the target tag; source tags are deleted.', 'nxt-cloud-chat' ); ?></p>
						</div>
						<button class="nxtcc-groups-modal-close-icon nxtcc-tag-merge-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
					</div>

					<div class="nxtcc-groups-modal-form">
						<div class="nxtcc-groups-form-row">
							<label for="nxtcc-tag-merge-target"><?php esc_html_e( 'Keep this tag', 'nxt-cloud-chat' ); ?></label>
							<select id="nxtcc-tag-merge-target"></select>
						</div>

						<div class="nxtcc-groups-modal-footer">
							<button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-tag-merge-dismiss"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
							<button id="nxtcc-tag-merge-apply" type="button" class="nxtcc-groups-btn nxtcc-groups-btn-green"><?php esc_html_e( 'Merge Tags', 'nxt-cloud-chat' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
