<?php
/**
 * History page view (Admin).
 *
 * Outputs the History table scaffold and modal. Runtime data is injected at
 * enqueue time and row rendering is handled by JavaScript.
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap nxtcc-history-screen">
	<div class="nxtcc-history-widget">
		<div class="nxtcc-history-header">
			<div>
				<h1 class="nxtcc-page-title"><?php esc_html_e( 'History', 'nxt-cloud-chat' ); ?></h1>
				<p class="nxtcc-history-subtitle">
					<?php esc_html_e( 'Review tenant message activity across individual sends and campaign deliveries in one place.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

		</div>

		<div class="nxtcc-history-toolbar">
			<div class="nxtcc-history-toolbar-right">
				<input
					id="nxtcc-history-search"
					type="search"
					class="nxtcc-history-search-input"
					placeholder="<?php echo esc_attr__( 'Search name, number, template, message, or broadcast ID', 'nxt-cloud-chat' ); ?>"
				/>

				<select id="nxtcc-history-message-type" class="nxtcc-history-select">
					<option value=""><?php esc_html_e( 'All Messages', 'nxt-cloud-chat' ); ?></option>
					<option value="broadcast"><?php esc_html_e( 'Campaign Messages', 'nxt-cloud-chat' ); ?></option>
					<option value="individual"><?php esc_html_e( 'Individual Messages', 'nxt-cloud-chat' ); ?></option>
				</select>

				<select id="nxtcc-history-status" class="nxtcc-history-select">
					<option value=""><?php esc_html_e( 'All Statuses', 'nxt-cloud-chat' ); ?></option>
					<option value="sent"><?php esc_html_e( 'Sent', 'nxt-cloud-chat' ); ?></option>
					<option value="delivered"><?php esc_html_e( 'Delivered', 'nxt-cloud-chat' ); ?></option>
					<option value="read"><?php esc_html_e( 'Read', 'nxt-cloud-chat' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'nxt-cloud-chat' ); ?></option>
					<option value="sending"><?php esc_html_e( 'Sending', 'nxt-cloud-chat' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'nxt-cloud-chat' ); ?></option>
					<option value="scheduled"><?php esc_html_e( 'Scheduled', 'nxt-cloud-chat' ); ?></option>
					<option value="received"><?php esc_html_e( 'Received', 'nxt-cloud-chat' ); ?></option>
				</select>

				<button id="nxtcc-history-refresh" type="button" class="nxtcc-history-button">
					<?php esc_html_e( 'Search', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</div>

		<div class="nxtcc-history-toolbar nxtcc-history-toolbar-bulk">
			<div class="nxtcc-history-toolbar-left">
				<select id="nxtcc-history-bulk-action" class="nxtcc-history-select">
					<option value=""><?php esc_html_e( 'Bulk actions', 'nxt-cloud-chat' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete Selected', 'nxt-cloud-chat' ); ?></option>
					<option value="export"><?php esc_html_e( 'Export (CSV)', 'nxt-cloud-chat' ); ?></option>
				</select>

				<button id="nxtcc-history-apply" type="button" class="nxtcc-history-button nxtcc-history-button-secondary">
					<?php esc_html_e( 'Apply', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</div>

		<div class="nxtcc-history-table-wrap">
			<table class="nxtcc-history-table">
				<thead>
					<tr>
						<th class="nxtcc-history-checkbox-col">
							<input type="checkbox" id="nxtcc-history-select-all" />
						</th>
						<th><?php esc_html_e( 'Contact Name', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Contact Number', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Template Name', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Message Content', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Sent At', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Delivered At', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Read At', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Scheduled At', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Created At', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Created By', 'nxt-cloud-chat' ); ?></th>
					</tr>
				</thead>
				<tbody id="nxtcc-history-tbody"></tbody>
				<tfoot>
					<tr id="nxtcc-history-loading-row" class="nxtcc-history-state-row" hidden>
						<td colspan="12"><?php esc_html_e( 'Loading...', 'nxt-cloud-chat' ); ?></td>
					</tr>
					<tr id="nxtcc-history-end-row" class="nxtcc-history-state-row" hidden>
						<td colspan="12"><?php esc_html_e( 'No more records.', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</div>

<div id="nxtcc-history-modal" class="nxtcc-history-modal" hidden aria-hidden="true">
	<div class="nxtcc-history-modal-overlay"></div>
	<div class="nxtcc-history-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-history-modal-title">
		<div class="nxtcc-history-modal-header">
			<span id="nxtcc-history-modal-title"><?php esc_html_e( 'Message Details', 'nxt-cloud-chat' ); ?></span>
			<button type="button" class="nxtcc-history-modal-close" id="nxtcc-history-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">
				&times;
			</button>
		</div>

		<div class="nxtcc-history-modal-body" id="nxtcc-history-modal-body">
			<div class="nxtcc-history-detail-lines">
				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Contact', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-contact" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Number', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-number" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line" id="kv-broadcast-row" hidden>
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Broadcast ID', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-broadcast-id" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Template', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-template" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Status', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-status" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line" id="kv-last-error-row" hidden>
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Reason', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-last-error" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Meta ID', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-meta-id" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Sent', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-sent" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Delivered', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-delivered" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Read', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-read" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Created At', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-created-at" class="nxtcc-history-detail-value"></span>
				</div>

				<div class="nxtcc-history-detail-line">
					<span class="nxtcc-history-detail-label"><?php esc_html_e( 'Created By', 'nxt-cloud-chat' ); ?></span>
					<span id="kv-created-by" class="nxtcc-history-detail-value"></span>
				</div>
			</div>

			<div class="nxtcc-history-message-panel">
				<div class="nxtcc-history-message-label"><?php esc_html_e( 'Message', 'nxt-cloud-chat' ); ?></div>
				<div id="kv-message" class="nxtcc-history-message-value"></div>
			</div>

			<div id="kv-media-wrap" class="nxtcc-history-media-panel" hidden>
				<div class="nxtcc-history-message-label"><?php esc_html_e( 'Media', 'nxt-cloud-chat' ); ?></div>
				<div id="kv-media-preview"></div>
			</div>
		</div>

		<div class="nxtcc-history-modal-footer">
			<button type="button" class="nxtcc-history-button" id="nxtcc-history-modal-close-2">
				<?php esc_html_e( 'Close', 'nxt-cloud-chat' ); ?>
			</button>
		</div>
	</div>
</div>
