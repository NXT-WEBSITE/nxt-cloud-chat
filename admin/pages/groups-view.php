<?php
/**
 * Groups view (admin UI).
 *
 * Renders the Groups management screen markup used by the admin script.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
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

<div
	class="nxtcc-groups-widget"
	data-nonce="<?php echo esc_attr( $nxtcc_groups_nonce ); ?>"
	data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
>
	<?php if ( ! $nxtcc_has_connection ) : ?>
		<div class="nxtcc-banner-warning">
			<strong><?php echo esc_html__( 'Action required:', 'nxt-cloud-chat' ); ?></strong>
			<span>
				<?php echo esc_html__( 'Connect your WhatsApp Business account and phone number before creating or editing groups.', 'nxt-cloud-chat' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<div class="nxtcc-groups-note">
		<strong><?php echo esc_html__( 'Heads up:', 'nxt-cloud-chat' ); ?></strong>
		<span class="nxtcc-lock" aria-hidden="true">🔒</span>
		<em><?php echo esc_html__( 'Verified', 'nxt-cloud-chat' ); ?></em>
		<span>
			<?php echo esc_html__( 'groups can’t be renamed or deleted. They’ll also be excluded from bulk delete.', 'nxt-cloud-chat' ); ?>
		</span>
	</div>

	<div class="nxtcc-groups-toolbar">
		<div class="nxtcc-groups-toolbar-left">
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
				<?php echo esc_html__( 'Add New', 'nxt-cloud-chat' ); ?>
			</button>
		</div>

		<div class="nxtcc-groups-toolbar-right">
			<input
				type="text"
				id="nxtcc-groups-search"
				class="nxtcc-groups-search-input"
				placeholder="<?php echo esc_attr__( 'Search Groups…', 'nxt-cloud-chat' ); ?>"
				aria-label="<?php echo esc_attr__( 'Search Groups', 'nxt-cloud-chat' ); ?>"
			>
		</div>
	</div>

	<div id="nxtcc-groups-bulk-actions" class="nxtcc-groups-bulk-wrap" style="display:none;">
		<label for="nxtcc-groups-bulk-select" class="screen-reader-text">
			<?php echo esc_html__( 'Bulk action', 'nxt-cloud-chat' ); ?>
		</label>

		<select id="nxtcc-groups-bulk-select" class="nxtcc-groups-select">
			<option value=""><?php echo esc_html__( 'Bulk action…', 'nxt-cloud-chat' ); ?></option>
			<option value="delete"><?php echo esc_html__( 'Delete selected', 'nxt-cloud-chat' ); ?></option>
		</select>

		<button
			id="nxtcc-groups-bulk-apply"
			class="nxtcc-groups-btn"
			type="button"
			<?php disabled( ! $nxtcc_has_connection ); ?>
			<?php if ( ! $nxtcc_has_connection ) : ?>
				aria-disabled="true"
				title="<?php echo esc_attr__( 'Connect WhatsApp first to run bulk actions.', 'nxt-cloud-chat' ); ?>"
			<?php endif; ?>
		>
			<?php echo esc_html__( 'Apply', 'nxt-cloud-chat' ); ?>
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
						<?php echo esc_html__( 'Group Name', 'nxt-cloud-chat' ); ?>
					</th>
					<th class="sortable" data-key="count" scope="col">
						<?php echo esc_html__( 'Number of Contacts', 'nxt-cloud-chat' ); ?>
					</th>
					<th class="sortable" data-key="created_by" scope="col">
						<?php echo esc_html__( 'Created By', 'nxt-cloud-chat' ); ?>
					</th>
					<th class="actions-col" scope="col">
						<?php echo esc_html__( 'Actions', 'nxt-cloud-chat' ); ?>
					</th>
				</tr>
			</thead>

			<tbody id="nxtcc-groups-tbody">
				<tr>
					<td colspan="99" class="nxtcc-groups-loading-cell">
						<?php echo esc_html__( 'Loading groups…', 'nxt-cloud-chat' ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p id="nxtcc-groups-help" class="screen-reader-text">
			<?php echo esc_html__( 'Use the search, sorting, and bulk actions to manage your groups. Verified groups are locked.', 'nxt-cloud-chat' ); ?>
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
				<h2 id="nxtcc-group-modal-title"><?php echo esc_html__( 'Add Group', 'nxt-cloud-chat' ); ?></h2>
				<button
					class="nxtcc-groups-modal-close"
					type="button"
					title="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>"
				>&times;</button>
			</div>

			<form id="nxtcc-group-form" autocomplete="off">
				<input type="hidden" name="group_id" id="nxtcc-group-id" value="">

				<div class="nxtcc-groups-form-row">
					<label for="nxtcc-group-name">
						<?php echo esc_html__( 'Group Name', 'nxt-cloud-chat' ); ?>
						<span class="nxtcc-groups-required" aria-hidden="true">*</span>
					</label>

					<input type="text" id="nxtcc-group-name" name="group_name" required>

					<p class="nxtcc-verified-hint">
						<?php echo esc_html__( 'This is a verified group and cannot be renamed.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<div class="nxtcc-groups-modal-footer">
					<button type="button" class="nxtcc-groups-btn nxtcc-groups-modal-close">
						<?php echo esc_html__( 'Cancel', 'nxt-cloud-chat' ); ?>
					</button>
					<button type="submit" class="nxtcc-groups-btn nxtcc-groups-btn-green">
						<?php echo esc_html__( 'Save Group', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
