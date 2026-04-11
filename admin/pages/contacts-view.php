<?php
/**
 * Contacts admin page view.
 *
 * Renders the Contacts UI and bootstraps instance data for the JS layer.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

/*
 * Load repository (keep the path consistent with your plugin constants).
 * Prefer a plugin constant like NXTCC_PLUGIN_DIR that points to the plugin root.
 */
if ( defined( 'NXTCC_PLUGIN_DIR' ) ) {
	require_once NXTCC_PLUGIN_DIR . 'admin/model/class-nxtcc-user-settings-repository.php';
} else {
	// Fallback (in case the constant isn't defined in your setup).
	require_once dirname( __DIR__ ) . '/model/class-nxtcc-user-settings-repository.php';
}

// Current user retrieval; sanitize to be safe.
$nxtcc_current_user = wp_get_current_user();
$nxtcc_user_mailid  = isset( $nxtcc_current_user->user_email ) ? $nxtcc_current_user->user_email : '';
$nxtcc_user_mailid  = sanitize_email( $nxtcc_user_mailid );

// Data access via repository (encapsulated).
$nxtcc_settings_repo = new NXTCC_User_Settings_Repository( $wpdb );

/**
 * Settings row for current user.
 *
 * @var object|null
 */
$nxtcc_settings = $nxtcc_settings_repo->get_latest_by_email( $nxtcc_user_mailid );

// Preserve original boolean semantics.
$nxtcc_has_connection = (
	$nxtcc_settings
	&& ! empty( $nxtcc_settings->business_account_id )
	&& ! empty( $nxtcc_settings->phone_number_id )
);

// UI instance + nonce for client actions (verify server-side).
$nxtcc_instance_id = uniqid( 'nxtcc_contacts_', true );
$nxtcc_nonce       = wp_create_nonce( 'nxtcc_contacts_' . $nxtcc_instance_id );
?>
<div
	class="nxtcc-contacts-widget"
	id="nxtcc-contacts-widget-<?php echo esc_attr( $nxtcc_instance_id ); ?>"
	data-instance="<?php echo esc_attr( $nxtcc_instance_id ); ?>"
	data-nonce="<?php echo esc_attr( $nxtcc_nonce ); ?>"
	data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
	data-current-user="<?php echo esc_attr( $nxtcc_user_mailid ); ?>"
>
	<?php if ( ! $nxtcc_has_connection ) : ?>
		<div class="nxtcc-banner-warning" style="background:#ffe1e1;color:#b30000;padding:12px 18px;margin-bottom:15px;border-radius:7px;font-size:16px;">
			<b>Connect WhatsApp Cloud API</b> to add or manage contacts. Please set up your
			<b>Business Account ID</b> and <b>Phone Number ID</b> in
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-settings' ) ); ?>" style="color:#075E54;text-decoration:underline;">Connection Settings</a>.
		</div>
	<?php endif; ?>

	<div class="nxtcc-contacts-toolbar" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
		<div class="nxtcc-contacts-toolbar-left" style="display:flex;gap:8px;align-items:center;">
			<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-add-contact-btn" <?php disabled( ! $nxtcc_has_connection ); ?>>Add New</button>
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-contacts-btn" <?php disabled( ! $nxtcc_has_connection ); ?>>Import</button>
		</div>
		<div class="nxtcc-contacts-toolbar-right" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-show-hide-columns">Show/Hide Columns</button>
		</div>
	</div>

	<div class="nxtcc-contacts-filterbar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
		<input type="text" id="nxtcc-filter-name" class="nxtcc-filter-input" placeholder="Search" style="min-width:180px;">

		<select id="nxtcc-filter-group" class="nxtcc-filter-dropdown" title="Filter by group (server-side)">
			<option value="">All Groups</option>
		</select>

		<select id="nxtcc-filter-country" class="nxtcc-filter-dropdown" title="Filter by country code (server-side)">
			<option value="">All Country Codes</option>
		</select>

		<select id="nxtcc-filter-subscription" class="nxtcc-filter-dropdown" title="Filter by subscription status (server-side)">
			<option value="">All Subscriptions</option>
			<option value="1">Subscribed</option>
			<option value="0">Unsubscribed</option>
		</select>

		<select id="nxtcc-filter-created-by" class="nxtcc-filter-dropdown" title="Filter by creator (server-side)">
			<option value="">All Creators</option>
		</select>

		<div class="nxtcc-filter-daterange" style="display:flex;gap:6px;align-items:center;">
			<label for="nxtcc-filter-created-from" class="screen-reader-text">Created from</label>
			<input type="date" id="nxtcc-filter-created-from" class="nxtcc-filter-input" title="From (server-side)">
			<span style="font-size:12.5px;color:#666;">→</span>
			<label for="nxtcc-filter-created-to" class="screen-reader-text">Created to</label>
			<input type="date" id="nxtcc-filter-created-to" class="nxtcc-filter-input" title="To (server-side)">
		</div>
	</div>

	<div class="nxtcc-contacts-table-wrap" style="margin-top:10px;">
		<div id="nxtcc-bulk-toolbar" class="nxtcc-bulk-toolbar" style="display:none;margin-bottom:10px;">
			<span id="nxtcc-bulk-selected-count"></span>
			<button type="button" id="nxtcc-bulk-delete" class="nxtcc-btn nxtcc-btn-danger">Delete</button>
			<button type="button" id="nxtcc-bulk-edit-groups" class="nxtcc-btn nxtcc-btn-outline">Edit Groups</button>
			<button type="button" id="nxtcc-bulk-edit-subscription" class="nxtcc-btn nxtcc-btn-outline">Edit Subscription</button>
			<button type="button" id="nxtcc-bulk-export" class="nxtcc-btn nxtcc-btn-outline">Export</button>
		</div>

		<table class="nxtcc-contacts-table" id="nxtcc-contacts-table">
			<thead>
				<tr>
					<th data-col="checkbox"><input type="checkbox" id="nxtcc-contacts-select-all" /></th>
					<th data-col="name">Name</th>
					<th data-col="country_code">Country Code</th>
					<th data-col="phone_number">Phone Number</th>
					<th data-col="groups">Groups</th>
					<th data-col="created_at">Created At</th>
					<th data-col="created_by">Created By</th>
					<th data-col="actions" class="actions-col">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td colspan="99" style="text-align:center;color:#999;">Loading contacts…</td>
				</tr>
			</tbody>
		</table>

		<div id="nxtcc-load-more-wrap" style="display:none;text-align:center;padding:10px 8px 4px;">
			<button type="button" id="nxtcc-load-more" class="nxtcc-btn nxtcc-btn-outline">Load more</button>
		</div>
	</div>

	<!-- (Everything below can stay as-is; if PHPCS still flags HTML comments, remove them.) -->

	<div class="nxtcc-modal" id="nxtcc-contact-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content" style="max-height:90vh;">
			<div class="nxtcc-modal-header">
				<span id="nxtcc-contact-modal-title">Add Contact</span>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-contact-modal-close" aria-label="Close">&times;</button>
			</div>

			<form id="nxtcc-contact-form" autocomplete="off">
				<input type="hidden" name="contact_id" id="nxtcc-contact-id" value="">
				<div class="nxtcc-form-row">
					<label for="nxtcc-contact-name">Name <span class="nxtcc-required">*</span></label>
					<input type="text" id="nxtcc-contact-name" name="name" required placeholder="Contact name">
				</div>

				<div class="nxtcc-form-row">
					<label for="nxtcc-country-code">Country Code <span class="nxtcc-required">*</span></label>
					<input type="text" id="nxtcc-country-code" name="country_code" required placeholder="Country code (e.g. 91)" list="nxtcc-country-codes-list">
					<datalist id="nxtcc-country-codes-list"></datalist>
				</div>

				<div class="nxtcc-form-row">
					<label for="nxtcc-phone-number">Phone Number <span class="nxtcc-required">*</span></label>
					<input type="text" id="nxtcc-phone-number" name="phone_number" required placeholder="Phone number (without country code)">
				</div>

				<div class="nxtcc-form-row nxtcc-custom-field-row" style="margin-top:4px;">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
						<input type="checkbox" id="nxtcc-contact-subscribed" name="subscribed" value="1">
						Subscribed
					</label>
				</div>

				<div class="nxtcc-form-row">
					<label for="nxtcc-contact-groups">Groups</label>
					<div class="nxtcc-input-with-inline-link">
						<select id="nxtcc-contact-groups" name="groups[]" multiple style="min-width:60%;"></select>
						<a href="#" id="nxtcc-open-create-group" class="nxtcc-inline-add-link" title="Create new group">➕ Add Group</a>
					</div>
					<div id="nxtcc-verified-contact-hint" style="display:none;margin-top:6px;font-size:12.5px;color:#b00020;">
						This contact is verified (linked to a WP user). Some fields and the originally assigned verified group cannot be changed here.
					</div>
				</div>

				<div id="nxtcc-dynamic-custom-fields-list"></div>

				<div id="nxtcc-custom-fields-add-section" class="nxtcc-form-row" style="margin-bottom:0;">
					<label>Custom Fields</label>
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-add-custom-field-btn" style="margin-left:0;">Add Field</button>
				</div>

				<div id="nxtcc-custom-fields-list"></div>

				<div class="nxtcc-modal-footer">
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-cancel-contact-modal">Cancel</button>
					<button type="submit" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-save-contact-btn">Save Contact</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Mini Modal: Create New Group -->
	<div class="nxtcc-modal" id="nxtcc-new-group-modal" style="display:none;">
		<div class="nxtcc-modal-overlay"></div>
		<div class="nxtcc-modal-content" style="max-width:420px;">
			<div class="nxtcc-modal-header">
				<span>Create New Group</span>
				<button type="button" class="nxtcc-modal-close" id="nxtcc-new-group-close" aria-label="Close">&times;</button>
			</div>
			<form id="nxtcc-new-group-form" autocomplete="off">
				<div class="nxtcc-form-row">
					<label for="nxtcc-new-group-name">Group Name <span class="nxtcc-required">*</span></label>
					<input type="text" id="nxtcc-new-group-name" name="group_name" placeholder="e.g. VIP Customers" maxlength="64" required>
					<div id="nxtcc-new-group-error" style="display:none;color:#b30000;font-size:12px;margin-top:6px;"></div>
				</div>
				<div class="nxtcc-modal-footer">
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-new-group-cancel">Cancel</button>
					<button type="submit" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-new-group-create">Create</button>
				</div>
			</form>
		</div>
	</div>

	<div class="nxtcc-tooltip" id="nxtcc-group-tooltip" style="display:none;"></div>
</div>

<!-- Bulk Group Modal -->
<div class="nxtcc-modal" id="nxtcc-bulk-group-modal" style="display:none;">
	<div class="nxtcc-modal-overlay"></div>
	<div class="nxtcc-modal-content" style="max-width:400px;">
		<div class="nxtcc-modal-header">
			<span>Edit Groups for Selected Contacts</span>
			<button type="button" class="nxtcc-modal-close" id="nxtcc-bulk-group-close" aria-label="Close">&times;</button>
		</div>
		<div class="nxtcc-form-row">
			<label for="nxtcc-bulk-group-select">Groups</label>
			<select id="nxtcc-bulk-group-select" multiple style="min-width:220px;"></select>
		</div>
		<div class="nxtcc-modal-footer">
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-bulk-group-cancel">Cancel</button>
			<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-bulk-group-apply">Apply to All</button>
		</div>
	</div>
</div>

<!-- Bulk Subscription Modal -->
<div class="nxtcc-modal" id="nxtcc-bulk-subscription-modal" style="display:none;">
	<div class="nxtcc-modal-overlay"></div>
	<div class="nxtcc-modal-content" style="max-width:420px;">
		<div class="nxtcc-modal-header">
			<span>Edit Subscription for Selected Contacts</span>
			<button type="button" class="nxtcc-modal-close" id="nxtcc-bulk-subscription-close" aria-label="Close">&times;</button>
		</div>

		<div class="nxtcc-form-row">
			<label>Subscription status</label>
			<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
				<label style="display:flex;align-items:center;gap:8px;">
					<input type="radio" name="nxtcc-bulk-subscription-choice" value="1" checked>
					Subscribed
				</label>
				<label style="display:flex;align-items:center;gap:8px;">
					<input type="radio" name="nxtcc-bulk-subscription-choice" value="0">
					Unsubscribed
				</label>
			</div>
			<div id="nxtcc-bulk-subscription-hint" style="margin-top:6px;font-size:12.5px;color:#666;">
				This will update the <b>is_subscribed</b> flag for all selected contacts.
			</div>
		</div>

		<div class="nxtcc-modal-footer">
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-bulk-subscription-cancel">Cancel</button>
			<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-bulk-subscription-apply">Apply</button>
		</div>
	</div>
</div>

<!-- Export Choice Modal -->
<div class="nxtcc-modal" id="nxtcc-export-modal" style="display:none;">
	<div class="nxtcc-modal-overlay"></div>
	<div class="nxtcc-modal-content" style="max-width:420px;">
		<div class="nxtcc-modal-header">
			<span>Export Contacts</span>
			<button type="button" class="nxtcc-modal-close" id="nxtcc-export-close" aria-label="Close">&times;</button>
		</div>
		<div class="nxtcc-form-row">
			<label>Choose what to export</label>
			<div id="nxtcc-export-summary" style="font-size:13.5px;color:#333;"></div>
		</div>
		<div class="nxtcc-modal-footer">
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-export-cancel">Cancel</button>
			<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-export-all">All (filtered)</button>
			<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-export-selected">Selected</button>
		</div>
	</div>
</div>

<!-- Import Contacts Wizard -->
<div class="nxtcc-modal" id="nxtcc-import-modal" style="display:none;">
	<div class="nxtcc-modal-overlay"></div>
	<div class="nxtcc-modal-content" style="max-width:880px;">
		<div class="nxtcc-modal-header">
			<span id="nxtcc-import-title">Import Contacts</span>
			<button type="button" class="nxtcc-modal-close" id="nxtcc-import-close" aria-label="Close">&times;</button>
		</div>

		<!-- Step 1: Upload -->
		<div class="nxtcc-import-step" data-step="1">
			<div class="nxtcc-form-row">
				<label for="nxtcc-import-file">Upload CSV <span class="nxtcc-required">*</span></label>
				<input type="file" id="nxtcc-import-file" accept=".csv,text/csv">
				<div style="font-size:12.5px;color:#666;margin-top:4px;">
					CSV only. Max ~10MB. Header row recommended. Delimiter auto-detect; you may override below.
				</div>
			</div>

			<div class="nxtcc-form-row">
				<label>Options</label>
				<div style="display:flex;gap:12px;flex-wrap:wrap;width:100%;">
					<label style="display:flex;align-items:center;gap:8px;">
						<input type="checkbox" id="nxtcc-import-has-header" checked> Has header row
					</label>
					<div style="display:flex;align-items:center;gap:6px;">
						<span style="color:#075E54;font-size:14px;">Delimiter:</span>
						<select id="nxtcc-import-delimiter" style="min-width:120px;">
							<option value="auto" selected>Auto-detect</option>
							<option value=",">Comma (,)</option>
							<option value=";">Semicolon (;)</option>
							<option value="\t">Tab (\t)</option>
							<option value="|">Pipe (|)</option>
						</select>
					</div>

					<!-- Default "Subscribed" for all imported rows -->
					<label style="display:flex;align-items:center;gap:8px;">
						<input type="checkbox" id="nxtcc-import-default-subscribed" checked>
						Set “Subscribed” for all imported contacts
					</label>
				</div>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc-import-default-groups">Default Groups (optional)</label>
				<select id="nxtcc-import-default-groups" multiple style="min-width:220px;"></select>
			</div>

			<div class="nxtcc-form-row" style="display:flex;justify-content:space-between;align-items:center;">
				<div>
					<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-download-sample-csv">Download Sample (CSV)</button>
				</div>
				<div>
					<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-next-1">Next</button>
				</div>
			</div>
		</div>

		<!-- Step 2: Mapping -->
		<div class="nxtcc-import-step" data-step="2" style="display:none;">
			<div class="nxtcc-form-row">
				<label>Map your CSV columns</label>
				<div id="nxtcc-import-mapping-grid" class="nxtcc-import-mapping-grid"></div>
				<div style="font-size:12.5px;color:#666;margin-top:6px;">
					Required fields: <b>Name</b>, <b>Country Code</b>, <b>Phone Number</b>. You can add custom fields using <i>➕ Add New field</i>.
				</div>
			</div>
			<div class="nxtcc-form-row" style="display:flex;justify-content:space-between;align-items:center;">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-back-2">Back</button>
				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-next-2">Validate &amp; Continue</button>
			</div>
		</div>

		<!-- Step 3: Validation -->
		<div class="nxtcc-import-step" data-step="3" style="display:none;">
			<div class="nxtcc-form-row">
				<label>Validation Summary</label>
				<div id="nxtcc-import-validation-cards" class="nxtcc-import-cards"></div>
			</div>
			<div class="nxtcc-form-row">
				<label>Conflict strategy</label>
				<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
					<label style="display:flex;align-items:center;gap:8px;">
						<input type="radio" name="nxtcc-import-conflict" value="skip" checked> Skip existing
					</label>
					<label style="display:flex;align-items:center;gap:8px;">
						<input type="radio" name="nxtcc-import-conflict" value="upsert"> Update existing (upsert)
					</label>
				</div>
			</div>
			<div class="nxtcc-form-row" style="display:flex;justify-content:space-between;align-items:center%;">
				<button type="button" class="nxtcc-btn nxtcc-btn-outline" id="nxtcc-import-back-3">Back</button>
				<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-start">Start Import</button>
			</div>
		</div>

		<!-- Step 4: Progress -->
		<div class="nxtcc-import-step" data-step="4" style="display:none;">
			<div class="nxtcc-form-row">
				<label>Progress</label>
				<div class="nxtcc-import-progress">
					<div id="nxtcc-import-progress-bar" class="nxtcc-import-progress-bar" style="width:0%;"></div>
				</div>
				<div id="nxtcc-import-progress-text" style="margin-top:6px;font-size:13.5px;color:#075E54;">Waiting…</div>
			</div>
			<div class="nxtcc-form-row">
				<label>Warnings / Errors</label>
				<div id="nxtcc-import-log" class="nxtcc-import-log"></div>
				<div style="margin-top:8px;display:flex;gap:10px;justify-content:flex-end;">
					<a id="nxtcc-import-download-errors" class="nxtcc-btn nxtcc-btn-outline" style="display:none;" href="#" target="_blank" rel="noopener">Download error report</a>
					<button type="button" class="nxtcc-btn nxtcc-btn-green" id="nxtcc-import-done" style="display:none;">Done → View Contacts</button>
				</div>
			</div>
		</div>
	</div>
</div>
