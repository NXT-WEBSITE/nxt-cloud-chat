<?php
/**
 * Contacts admin page view.
 *
 * Renders the Contacts UI and bootstraps instance data for the JS layer.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_contacts', 'nxtcc_manage_contacts' ) ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nxt-cloud-chat' ) );
}

global $wpdb;

if ( defined( 'NXTCC_PLUGIN_DIR' ) ) {
	require_once NXTCC_PLUGIN_DIR . 'admin/model/class-nxtcc-user-settings-repository.php';
} else {
	require_once dirname( __DIR__ ) . '/model/class-nxtcc-user-settings-repository.php';
}

$nxtcc_active_tenant = NXTCC_Access_Control::get_current_tenant_context();
$nxtcc_user_mailid   = isset( $nxtcc_active_tenant['user_mailid'] ) ? sanitize_email( (string) $nxtcc_active_tenant['user_mailid'] ) : '';

$nxtcc_settings_repo = new NXTCC_User_Settings_Repository( $wpdb );
$nxtcc_settings      = NXTCC_Access_Control::get_settings_row_for_tenant( $nxtcc_active_tenant );

if ( ! is_object( $nxtcc_settings ) ) {
	$nxtcc_settings = $nxtcc_settings_repo->get_latest_by_email( $nxtcc_user_mailid );
}

$nxtcc_has_connection = (
	$nxtcc_settings
	&& ! empty( $nxtcc_settings->business_account_id )
	&& ! empty( $nxtcc_settings->phone_number_id )
);

$nxtcc_instance_id = uniqid( 'nxtcc_contacts_', true );
$nxtcc_nonce       = wp_create_nonce( 'nxtcc_contacts_' . $nxtcc_instance_id );
?>

<div class="wrap nxtcc-contacts-screen">
	<div
		class="nxtcc-contacts-widget"
		id="nxtcc-contacts-widget-<?php echo esc_attr( $nxtcc_instance_id ); ?>"
		data-instance="<?php echo esc_attr( $nxtcc_instance_id ); ?>"
		data-nonce="<?php echo esc_attr( $nxtcc_nonce ); ?>"
		data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
		data-current-user="<?php echo esc_attr( $nxtcc_user_mailid ); ?>"
	>
		<?php if ( ! $nxtcc_has_connection ) : ?>
			<div class="nxtcc-contacts-alert nxtcc-contacts-alert-warning">
				<strong><?php esc_html_e( 'Action required:', 'nxt-cloud-chat' ); ?></strong>
				<span>
					<?php esc_html_e( 'Connect your WhatsApp Business account and phone number before managing tenant contacts.', 'nxt-cloud-chat' ); ?>
				</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-settings' ) ); ?>">
					<?php esc_html_e( 'Open Connection Settings', 'nxt-cloud-chat' ); ?>
				</a>
			</div>
		<?php endif; ?>

		<div class="nxtcc-contacts-summary">
			<div class="nxtcc-contacts-summary-card">
				<span class="nxtcc-contacts-summary-label"><?php esc_html_e( 'Filtered Contacts', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-contacts-summary-total" class="nxtcc-contacts-summary-value">0</strong>
				<span class="nxtcc-contacts-summary-meta"><?php esc_html_e( 'Matches the current filters', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-contacts-summary-card">
				<span class="nxtcc-contacts-summary-label"><?php esc_html_e( 'Loaded Rows', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-contacts-summary-loaded" class="nxtcc-contacts-summary-value">0</strong>
				<span class="nxtcc-contacts-summary-meta"><?php esc_html_e( 'Currently rendered in the table', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-contacts-summary-card">
				<span class="nxtcc-contacts-summary-label"><?php esc_html_e( 'Subscribed', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-contacts-summary-subscribed" class="nxtcc-contacts-summary-value">0</strong>
				<span class="nxtcc-contacts-summary-meta"><?php esc_html_e( 'Loaded contacts ready for messaging', 'nxt-cloud-chat' ); ?></span>
			</div>

			<div class="nxtcc-contacts-summary-card">
				<span class="nxtcc-contacts-summary-label"><?php esc_html_e( 'Verified Group', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-contacts-summary-verified" class="nxtcc-contacts-summary-value">0</strong>
				<span class="nxtcc-contacts-summary-meta"><?php esc_html_e( 'Protected contacts that cannot be deleted', 'nxt-cloud-chat' ); ?></span>
			</div>
		</div>

		<div class="nxtcc-contacts-toolbar">
			<div class="nxtcc-contacts-toolbar-copy">
				<h1 class="nxtcc-page-title"><?php esc_html_e( 'Contacts', 'nxt-cloud-chat' ); ?></h1>
				<p class="nxtcc-contacts-subtitle">
					<?php esc_html_e( 'Manage tenant contacts, group membership, and subscription status in one compact workspace.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-contacts-toolbar-actions">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-contacts-btn" <?php disabled( ! $nxtcc_has_connection ); ?>>
					<?php esc_html_e( 'Import', 'nxt-cloud-chat' ); ?>
				</button>

				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-add-contact-btn" <?php disabled( ! $nxtcc_has_connection ); ?>>
					<?php esc_html_e( 'Add Contact', 'nxt-cloud-chat' ); ?>
				</button>
			</div>

			<div class="nxtcc-contacts-toolbar-secondary">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-show-hide-columns">
					<?php esc_html_e( 'Show/Hide Columns', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</div>

		<div class="nxtcc-contacts-filterbar">
			<div class="nxtcc-contacts-filter-field nxtcc-contacts-filter-field-search">
				<label class="screen-reader-text" for="nxtcc-filter-name"><?php esc_html_e( 'Search contacts', 'nxt-cloud-chat' ); ?></label>
				<input type="search" id="nxtcc-filter-name" class="nxtcc-filter-input" placeholder="<?php echo esc_attr__( 'Search name or phone number', 'nxt-cloud-chat' ); ?>">
			</div>

			<div class="nxtcc-contacts-filter-field">
				<label class="screen-reader-text" for="nxtcc-filter-group"><?php esc_html_e( 'Filter by group', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-filter-group" class="nxtcc-filter-dropdown" title="<?php echo esc_attr__( 'Filter by group', 'nxt-cloud-chat' ); ?>">
					<option value=""><?php esc_html_e( 'All Groups', 'nxt-cloud-chat' ); ?></option>
				</select>
			</div>

			<div class="nxtcc-contacts-filter-field">
				<label class="screen-reader-text" for="nxtcc-filter-country"><?php esc_html_e( 'Filter by country code', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-filter-country" class="nxtcc-filter-dropdown" title="<?php echo esc_attr__( 'Filter by country code', 'nxt-cloud-chat' ); ?>">
					<option value=""><?php esc_html_e( 'All Country Codes', 'nxt-cloud-chat' ); ?></option>
				</select>
			</div>

			<div class="nxtcc-contacts-filter-field">
				<label class="screen-reader-text" for="nxtcc-filter-subscription"><?php esc_html_e( 'Filter by subscription status', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-filter-subscription" class="nxtcc-filter-dropdown" title="<?php echo esc_attr__( 'Filter by subscription status', 'nxt-cloud-chat' ); ?>">
					<option value=""><?php esc_html_e( 'All Subscriptions', 'nxt-cloud-chat' ); ?></option>
					<option value="1"><?php esc_html_e( 'Subscribed', 'nxt-cloud-chat' ); ?></option>
					<option value="0"><?php esc_html_e( 'Unsubscribed', 'nxt-cloud-chat' ); ?></option>
				</select>
			</div>

			<div class="nxtcc-contacts-filter-field">
				<label class="screen-reader-text" for="nxtcc-filter-created-by"><?php esc_html_e( 'Filter by creator', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-filter-created-by" class="nxtcc-filter-dropdown" title="<?php echo esc_attr__( 'Filter by creator', 'nxt-cloud-chat' ); ?>">
					<option value=""><?php esc_html_e( 'All Creators', 'nxt-cloud-chat' ); ?></option>
				</select>
			</div>

			<div class="nxtcc-contacts-filter-field nxtcc-contacts-filter-dates">
				<label class="screen-reader-text" for="nxtcc-filter-created-from"><?php esc_html_e( 'Created from', 'nxt-cloud-chat' ); ?></label>
				<input type="date" id="nxtcc-filter-created-from" class="nxtcc-filter-input" title="<?php echo esc_attr__( 'Created from', 'nxt-cloud-chat' ); ?>">

				<span class="nxtcc-contacts-date-separator" aria-hidden="true"><?php esc_html_e( 'to', 'nxt-cloud-chat' ); ?></span>

				<label class="screen-reader-text" for="nxtcc-filter-created-to"><?php esc_html_e( 'Created to', 'nxt-cloud-chat' ); ?></label>
				<input type="date" id="nxtcc-filter-created-to" class="nxtcc-filter-input" title="<?php echo esc_attr__( 'Created to', 'nxt-cloud-chat' ); ?>">
			</div>
		</div>

		<div class="nxtcc-contacts-inline-note">
			<span class="nxtcc-contacts-inline-note-badge"><?php esc_html_e( 'Verified', 'nxt-cloud-chat' ); ?></span>
			<span><?php esc_html_e( 'Contacts inside a verified group keep that group assigned and cannot be deleted from the Contacts list.', 'nxt-cloud-chat' ); ?></span>
		</div>

		<div id="nxtcc-bulk-toolbar" class="nxtcc-bulk-toolbar" style="display:none;">
			<span id="nxtcc-bulk-selected-count"></span>
			<button type="button" id="nxtcc-bulk-delete" class="nxtcc-btn nxtcc-btn-danger"><?php esc_html_e( 'Delete', 'nxt-cloud-chat' ); ?></button>
			<button type="button" id="nxtcc-bulk-edit-groups" class="nxtcc-btn nxtcc-btn-outline"><?php esc_html_e( 'Edit Groups', 'nxt-cloud-chat' ); ?></button>
			<button type="button" id="nxtcc-bulk-edit-subscription" class="nxtcc-btn nxtcc-btn-outline"><?php esc_html_e( 'Edit Subscription', 'nxt-cloud-chat' ); ?></button>
			<button type="button" id="nxtcc-bulk-export" class="nxtcc-btn nxtcc-btn-outline"><?php esc_html_e( 'Export', 'nxt-cloud-chat' ); ?></button>
		</div>

		<div class="nxtcc-contacts-table-wrap">
			<table class="nxtcc-contacts-table" id="nxtcc-contacts-table">
				<thead>
					<tr>
						<th data-col="checkbox" scope="col">
							<input type="checkbox" id="nxtcc-contacts-select-all" aria-label="<?php echo esc_attr__( 'Select all contacts', 'nxt-cloud-chat' ); ?>">
						</th>
						<th data-col="name" scope="col"><?php esc_html_e( 'Name', 'nxt-cloud-chat' ); ?></th>
						<th data-col="country_code" scope="col"><?php esc_html_e( 'Country Code', 'nxt-cloud-chat' ); ?></th>
						<th data-col="phone_number" scope="col"><?php esc_html_e( 'Phone Number', 'nxt-cloud-chat' ); ?></th>
						<th data-col="groups" scope="col"><?php esc_html_e( 'Groups', 'nxt-cloud-chat' ); ?></th>
						<th data-col="subscribed" scope="col"><?php esc_html_e( 'Subscription', 'nxt-cloud-chat' ); ?></th>
						<th data-col="created_at" scope="col"><?php esc_html_e( 'Created At', 'nxt-cloud-chat' ); ?></th>
						<th data-col="actions" class="actions-col" scope="col"><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="nxtcc-contacts-state-row">
						<td colspan="99" class="nxtcc-contacts-state-cell"><?php esc_html_e( 'Loading contacts...', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tbody>
			</table>

			<div id="nxtcc-load-more-wrap" class="nxtcc-contacts-load-more" style="display:none;">
				<button type="button" id="nxtcc-load-more" class="nxtcc-btn nxtcc-btn-outline"><?php esc_html_e( 'Load More', 'nxt-cloud-chat' ); ?></button>
			</div>
		</div>

		<div class="nxtcc-modal" id="nxtcc-contact-modal" style="display:none;">
			<div class="nxtcc-modal-overlay"></div>
			<div class="nxtcc-modal-content nxtcc-modal-content-wide" role="dialog" aria-modal="true" aria-labelledby="nxtcc-contact-modal-title">
				<div class="nxtcc-modal-header">
					<div class="nxtcc-modal-copy">
						<h2 id="nxtcc-contact-modal-title"><?php esc_html_e( 'Add Contact', 'nxt-cloud-chat' ); ?></h2>
						<p class="nxtcc-modal-subtitle"><?php esc_html_e( 'Save a tenant contact with reusable groups and custom fields.', 'nxt-cloud-chat' ); ?></p>
					</div>
					<button type="button" class="nxtcc-modal-close" id="nxtcc-contact-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
				</div>

				<form id="nxtcc-contact-form" autocomplete="off">
					<input type="hidden" name="contact_id" id="nxtcc-contact-id" value="">

					<div class="nxtcc-form-grid nxtcc-form-grid-two">
						<div class="nxtcc-form-row">
							<label for="nxtcc-contact-name"><?php esc_html_e( 'Name', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span></label>
							<input type="text" id="nxtcc-contact-name" name="name" required placeholder="<?php echo esc_attr__( 'Contact name', 'nxt-cloud-chat' ); ?>">
						</div>

						<div class="nxtcc-form-row">
							<label for="nxtcc-country-code"><?php esc_html_e( 'Country Code', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span></label>
							<input type="text" id="nxtcc-country-code" name="country_code" required placeholder="<?php echo esc_attr__( '91', 'nxt-cloud-chat' ); ?>" list="nxtcc-country-codes-list">
							<datalist id="nxtcc-country-codes-list"></datalist>
						</div>
					</div>

					<div class="nxtcc-form-grid nxtcc-form-grid-two">
						<div class="nxtcc-form-row">
							<label for="nxtcc-phone-number"><?php esc_html_e( 'Phone Number', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span></label>
							<input type="text" id="nxtcc-phone-number" name="phone_number" required placeholder="<?php echo esc_attr__( 'Phone number without country code', 'nxt-cloud-chat' ); ?>">
						</div>

						<div class="nxtcc-form-row nxtcc-form-row-checkbox">
							<label for="nxtcc-contact-subscribed"><?php esc_html_e( 'Subscription', 'nxt-cloud-chat' ); ?></label>
							<label class="nxtcc-checkbox-label">
								<input type="checkbox" id="nxtcc-contact-subscribed" name="subscribed" value="1">
								<span><?php esc_html_e( 'Subscribed', 'nxt-cloud-chat' ); ?></span>
							</label>
						</div>
					</div>

					<div class="nxtcc-form-row">
						<label for="nxtcc-contact-groups"><?php esc_html_e( 'Groups', 'nxt-cloud-chat' ); ?></label>
						<div class="nxtcc-input-with-inline-link">
							<select id="nxtcc-contact-groups" name="groups[]" multiple></select>
							<a href="#" id="nxtcc-open-create-group" class="nxtcc-inline-add-link" title="<?php echo esc_attr__( 'Create new group', 'nxt-cloud-chat' ); ?>">
								<?php esc_html_e( 'Add Group', 'nxt-cloud-chat' ); ?>
							</a>
						</div>
						<div id="nxtcc-verified-contact-hint" class="nxtcc-form-hint nxtcc-form-hint-warning" style="display:none;">
							<?php esc_html_e( 'This contact belongs to a protected verified group. That group stays assigned and the contact cannot be deleted here. Linked WordPress contacts also keep phone fields locked.', 'nxt-cloud-chat' ); ?>
						</div>
					</div>

					<div id="nxtcc-dynamic-custom-fields-list"></div>

					<div id="nxtcc-custom-fields-add-section" class="nxtcc-form-row nxtcc-custom-fields-add-section">
						<label><?php esc_html_e( 'Custom Fields', 'nxt-cloud-chat' ); ?></label>
						<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-add-custom-field-btn"><?php esc_html_e( 'Add Field', 'nxt-cloud-chat' ); ?></button>
					</div>

					<div id="nxtcc-custom-fields-list"></div>

					<div class="nxtcc-modal-footer">
						<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-cancel-contact-modal"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
						<button type="submit" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-save-contact-btn"><?php esc_html_e( 'Save Contact', 'nxt-cloud-chat' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<div id="nxtcc-contacts-columns-popover" class="nxtcc-popover" style="display:none;"></div>
	</div>

	<div class="nxtcc-modal" id="nxtcc-bulk-group-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content nxtcc-modal-content-compact" role="dialog" aria-modal="true">
			<div class="nxtcc-modal-header">
				<div class="nxtcc-modal-copy">
					<h2><?php esc_html_e( 'Edit Groups', 'nxt-cloud-chat' ); ?></h2>
					<p class="nxtcc-modal-subtitle"><?php esc_html_e( 'Replace the selected contacts with the groups you choose here.', 'nxt-cloud-chat' ); ?></p>
				</div>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-bulk-group-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc-bulk-group-select"><?php esc_html_e( 'Groups', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-bulk-group-select" multiple></select>
				<p class="nxtcc-form-hint"><?php esc_html_e( 'Verified groups stay assigned automatically for protected contacts and cannot be bulk-assigned from this modal.', 'nxt-cloud-chat' ); ?></p>
			</div>

			<div class="nxtcc-modal-footer">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-bulk-group-cancel"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-bulk-group-apply"><?php esc_html_e( 'Apply to All', 'nxt-cloud-chat' ); ?></button>
			</div>
		</div>
	</div>

	<div class="nxtcc-modal" id="nxtcc-bulk-subscription-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content nxtcc-modal-content-compact" role="dialog" aria-modal="true">
			<div class="nxtcc-modal-header">
				<div class="nxtcc-modal-copy">
					<h2><?php esc_html_e( 'Edit Subscription', 'nxt-cloud-chat' ); ?></h2>
					<p class="nxtcc-modal-subtitle"><?php esc_html_e( 'Update the subscription flag for every selected contact.', 'nxt-cloud-chat' ); ?></p>
				</div>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-bulk-subscription-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
			</div>

			<div class="nxtcc-form-row">
				<label><?php esc_html_e( 'Subscription status', 'nxt-cloud-chat' ); ?></label>
				<div class="nxtcc-radio-grid">
					<label class="nxtcc-radio-label">
						<input type="radio" name="nxtcc-bulk-subscription-choice" value="1" checked>
						<span><?php esc_html_e( 'Subscribed', 'nxt-cloud-chat' ); ?></span>
					</label>

					<label class="nxtcc-radio-label">
						<input type="radio" name="nxtcc-bulk-subscription-choice" value="0">
						<span><?php esc_html_e( 'Unsubscribed', 'nxt-cloud-chat' ); ?></span>
					</label>
				</div>
				<p id="nxtcc-bulk-subscription-hint" class="nxtcc-form-hint"><?php esc_html_e( 'This updates the is_subscribed flag for all selected contacts.', 'nxt-cloud-chat' ); ?></p>
			</div>

			<div class="nxtcc-modal-footer">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-bulk-subscription-cancel"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-bulk-subscription-apply"><?php esc_html_e( 'Apply', 'nxt-cloud-chat' ); ?></button>
			</div>
		</div>
	</div>

	<div class="nxtcc-modal" id="nxtcc-export-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content nxtcc-modal-content-compact" role="dialog" aria-modal="true">
			<div class="nxtcc-modal-header">
				<div class="nxtcc-modal-copy">
					<h2><?php esc_html_e( 'Export Contacts', 'nxt-cloud-chat' ); ?></h2>
					<p class="nxtcc-modal-subtitle"><?php esc_html_e( 'Choose whether to export the selected contacts or the full filtered result.', 'nxt-cloud-chat' ); ?></p>
				</div>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-export-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
			</div>

			<div class="nxtcc-form-row">
				<label><?php esc_html_e( 'Export scope', 'nxt-cloud-chat' ); ?></label>
				<div id="nxtcc-export-summary" class="nxtcc-form-hint"></div>
			</div>

			<div class="nxtcc-modal-footer">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-export-cancel"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-export-all"><?php esc_html_e( 'All Filtered', 'nxt-cloud-chat' ); ?></button>
				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-export-selected"><?php esc_html_e( 'Selected', 'nxt-cloud-chat' ); ?></button>
			</div>
		</div>
	</div>

	<div class="nxtcc-modal" id="nxtcc-import-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content nxtcc-modal-content-import" role="dialog" aria-modal="true" aria-labelledby="nxtcc-import-title">
			<div class="nxtcc-modal-header">
				<div class="nxtcc-modal-copy">
					<h2 id="nxtcc-import-title"><?php esc_html_e( 'Import Contacts', 'nxt-cloud-chat' ); ?></h2>
					<p class="nxtcc-modal-subtitle"><?php esc_html_e( 'Upload a CSV, map fields, validate, and import tenant-safe contact data.', 'nxt-cloud-chat' ); ?></p>
				</div>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-import-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
			</div>

			<div class="nxtcc-import-step" data-step="1">
				<div class="nxtcc-form-row">
					<label for="nxtcc-import-file"><?php esc_html_e( 'Upload CSV', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span></label>
					<input type="file" id="nxtcc-import-file" accept=".csv,text/csv">
					<p class="nxtcc-form-hint"><?php esc_html_e( 'CSV only. Header row recommended. Delimiter can be auto-detected or chosen manually below.', 'nxt-cloud-chat' ); ?></p>
				</div>

				<div class="nxtcc-form-grid nxtcc-form-grid-two">
					<div class="nxtcc-form-row">
						<label><?php esc_html_e( 'Import options', 'nxt-cloud-chat' ); ?></label>
						<div class="nxtcc-checkbox-stack">
							<label class="nxtcc-checkbox-label">
								<input type="checkbox" id="nxtcc-import-has-header" checked>
								<span><?php esc_html_e( 'File includes a header row', 'nxt-cloud-chat' ); ?></span>
							</label>

							<label class="nxtcc-checkbox-label">
								<input type="checkbox" id="nxtcc-import-default-subscribed" checked>
								<span><?php esc_html_e( 'Set imported contacts as subscribed by default', 'nxt-cloud-chat' ); ?></span>
							</label>
						</div>
					</div>

					<div class="nxtcc-form-row">
						<label for="nxtcc-import-delimiter"><?php esc_html_e( 'Delimiter', 'nxt-cloud-chat' ); ?></label>
						<select id="nxtcc-import-delimiter">
							<option value="auto" selected><?php esc_html_e( 'Auto-detect', 'nxt-cloud-chat' ); ?></option>
							<option value=","><?php esc_html_e( 'Comma (,)', 'nxt-cloud-chat' ); ?></option>
							<option value=";"><?php esc_html_e( 'Semicolon (;)', 'nxt-cloud-chat' ); ?></option>
							<option value="\t"><?php esc_html_e( 'Tab (\\t)', 'nxt-cloud-chat' ); ?></option>
							<option value="|"><?php esc_html_e( 'Pipe (|)', 'nxt-cloud-chat' ); ?></option>
						</select>
					</div>
				</div>

				<div class="nxtcc-form-row">
					<label for="nxtcc-import-default-groups"><?php esc_html_e( 'Default Groups', 'nxt-cloud-chat' ); ?></label>
					<select id="nxtcc-import-default-groups" multiple></select>
					<p class="nxtcc-form-hint"><?php esc_html_e( 'Verified groups are protected and cannot be selected as import defaults. Existing verified-group contacts keep that group during upsert.', 'nxt-cloud-chat' ); ?></p>
				</div>

				<div class="nxtcc-modal-footer">
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-download-sample-csv"><?php esc_html_e( 'Download Sample CSV', 'nxt-cloud-chat' ); ?></button>
					<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-next-1"><?php esc_html_e( 'Next', 'nxt-cloud-chat' ); ?></button>
				</div>
			</div>

			<div class="nxtcc-import-step" data-step="2" style="display:none;">
				<div class="nxtcc-form-row">
					<label><?php esc_html_e( 'Map Fields', 'nxt-cloud-chat' ); ?></label>
					<div id="nxtcc-import-mapping-grid" class="nxtcc-import-mapping-grid"></div>
					<p class="nxtcc-form-hint"><?php esc_html_e( 'Required fields are Name, Country Code, and Phone Number. You can also map existing custom fields or add new ones.', 'nxt-cloud-chat' ); ?></p>
				</div>

				<div class="nxtcc-modal-footer">
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-back-2"><?php esc_html_e( 'Back', 'nxt-cloud-chat' ); ?></button>
					<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-next-2"><?php esc_html_e( 'Validate and Continue', 'nxt-cloud-chat' ); ?></button>
				</div>
			</div>

			<div class="nxtcc-import-step" data-step="3" style="display:none;">
				<div class="nxtcc-form-row">
					<label><?php esc_html_e( 'Validation Summary', 'nxt-cloud-chat' ); ?></label>
					<div id="nxtcc-import-validation-cards" class="nxtcc-import-cards"></div>
				</div>

				<div class="nxtcc-form-row">
					<label><?php esc_html_e( 'Conflict strategy', 'nxt-cloud-chat' ); ?></label>
					<div class="nxtcc-radio-grid">
						<label class="nxtcc-radio-label">
							<input type="radio" name="nxtcc-import-conflict" value="skip" checked>
							<span><?php esc_html_e( 'Skip existing', 'nxt-cloud-chat' ); ?></span>
						</label>

						<label class="nxtcc-radio-label">
							<input type="radio" name="nxtcc-import-conflict" value="upsert">
							<span><?php esc_html_e( 'Update existing', 'nxt-cloud-chat' ); ?></span>
						</label>
					</div>
				</div>

				<div class="nxtcc-modal-footer">
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-back-3"><?php esc_html_e( 'Back', 'nxt-cloud-chat' ); ?></button>
					<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-start"><?php esc_html_e( 'Start Import', 'nxt-cloud-chat' ); ?></button>
				</div>
			</div>

			<div class="nxtcc-import-step" data-step="4" style="display:none;">
				<div class="nxtcc-form-row">
					<label><?php esc_html_e( 'Progress', 'nxt-cloud-chat' ); ?></label>
					<div class="nxtcc-import-progress">
						<div id="nxtcc-import-progress-bar" class="nxtcc-import-progress-bar" style="width:0%;"></div>
					</div>
					<div id="nxtcc-import-progress-text" class="nxtcc-import-progress-text"><?php esc_html_e( 'Waiting...', 'nxt-cloud-chat' ); ?></div>
				</div>

				<div class="nxtcc-form-row">
					<label><?php esc_html_e( 'Warnings and Errors', 'nxt-cloud-chat' ); ?></label>
					<div id="nxtcc-import-log" class="nxtcc-import-log"></div>
					<div class="nxtcc-import-actions">
						<a id="nxtcc-import-download-errors" class="nxtcc-btn nxtcc-btn-outline" style="display:none;" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Download Error Report', 'nxt-cloud-chat' ); ?></a>
						<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-done" style="display:none;"><?php esc_html_e( 'Done - View Contacts', 'nxt-cloud-chat' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
