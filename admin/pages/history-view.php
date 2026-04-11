<?php
/**
 * History page view (Admin).
 *
 * Outputs the History table scaffold and modal (filled by JS).
 * All runtime bootstrap (ajaxurl, nonce, limit) is injected at enqueue time.
 * This file is view-only (no DB calls, no inline script injection).
 *
 * @package NXTCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap nxtcc-history-widget">
	<h1 class="nxtcc-page-title">History</h1>

	<!-- Toolbar -->
	<div class="nxtcc-history-toolbar">
		<div class="nxtcc-history-toolbar-left">
			<select id="nxtcc-history-bulk-action" class="nxtcc-select">
				<option value=""><?php echo esc_html( 'Bulk actions' ); ?></option>
				<option value="delete"><?php echo esc_html( 'Delete Selected' ); ?></option>
				<option value="export"><?php echo esc_html( 'Export (CSV)' ); ?></option>
			</select>
			<button id="nxtcc-history-apply" class="nxtcc-btn"><?php echo esc_html( 'Apply' ); ?></button>
		</div>

		<div class="nxtcc-history-toolbar-right">
			<input
				id="nxtcc-history-search"
				type="text"
				class="nxtcc-search-input"
				placeholder="<?php echo esc_attr( '🔍 Search name, number, template, message…' ); ?>"
			/>
			<select id="nxtcc-history-status" class="nxtcc-select">
				<option value=""><?php echo esc_html( 'All Status' ); ?></option>
				<option value="sent"><?php echo esc_html( 'Sent' ); ?></option>
				<option value="delivered"><?php echo esc_html( 'Delivered' ); ?></option>
				<option value="read"><?php echo esc_html( 'Read' ); ?></option>
				<option value="failed"><?php echo esc_html( 'Failed' ); ?></option>
				<option value="sending"><?php echo esc_html( 'Sending' ); ?></option>
				<option value="pending"><?php echo esc_html( 'Pending' ); ?></option>
				<option value="scheduled"><?php echo esc_html( 'Scheduled' ); ?></option>
				<option value="received"><?php echo esc_html( 'Received' ); ?></option>
			</select>
			<button id="nxtcc-history-refresh" class="nxtcc-btn-outline"><?php echo esc_html( 'Refresh' ); ?></button>
		</div>
	</div>

	<!-- Table -->
	<div class="nxtcc-history-table-wrap">
		<table class="nxtcc-history-table">
			<thead>
				<tr>
					<th style="width:36px;">
						<input type="checkbox" id="nxtcc-history-select-all" />
					</th>
					<th><?php echo esc_html( 'Contact Name' ); ?></th>
					<th><?php echo esc_html( 'Contact Number' ); ?></th>
					<th><?php echo esc_html( 'Template Name' ); ?></th>
					<th><?php echo esc_html( 'Message Content' ); ?></th>
					<th><?php echo esc_html( 'Status' ); ?></th>
					<th><?php echo esc_html( 'Sent At' ); ?></th>
					<th><?php echo esc_html( 'Delivered At' ); ?></th>
					<th><?php echo esc_html( 'Read At' ); ?></th>
					<th><?php echo esc_html( 'Scheduled At' ); ?></th>
					<th><?php echo esc_html( 'Created At' ); ?></th>
					<th><?php echo esc_html( 'Created By' ); ?></th>
				</tr>
			</thead>
			<tbody id="nxtcc-history-tbody">
				<!-- rows appended by JS -->
			</tbody>
			<tfoot>
				<tr id="nxtcc-history-loading-row" style="display:none;">
					<td colspan="12" style="text-align:center;color:#777;"><?php echo esc_html( 'Loading…' ); ?></td>
				</tr>
				<tr id="nxtcc-history-end-row" style="display:none;">
					<td colspan="12" style="text-align:center;color:#999;"><?php echo esc_html( 'No more records.' ); ?></td>
				</tr>
			</tfoot>
		</table>
	</div>
</div>

<!-- Modal for full message -->
<div id="nxtcc-history-modal" class="nxtcc-modal" style="display:none;">
	<div class="nxtcc-modal-overlay"></div>
	<div class="nxtcc-modal-content">
		<div class="nxtcc-modal-header">
			<span id="nxtcc-history-modal-title"><?php echo esc_html( 'Message Details' ); ?></span>
			<button class="nxtcc-modal-close" id="nxtcc-history-modal-close" aria-label="<?php echo esc_attr( 'Close' ); ?>">×</button>
		</div>
		<div class="nxtcc-modal-body" id="nxtcc-history-modal-body" style="padding:14px 18px;">
			<!-- Filled by JS -->
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Contact:' ); ?></strong> <span id="kv-contact"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Number:' ); ?></strong> <span id="kv-number"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Template:' ); ?></strong> <span id="kv-template"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Status:' ); ?></strong> <span id="kv-status"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Meta ID:' ); ?></strong> <span id="kv-meta-id"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Sent:' ); ?></strong> <span id="kv-sent"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Delivered:' ); ?></strong> <span id="kv-delivered"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Read:' ); ?></strong> <span id="kv-read"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Created At:' ); ?></strong> <span id="kv-created-at"></span></div>
			<div class="nxtcc-kv"><strong><?php echo esc_html( 'Created By:' ); ?></strong> <span id="kv-created-by"></span></div>

			<hr style="margin:12px 0;">
			<div style="font-weight:600;margin-bottom:6px;"><?php echo esc_html( 'Message' ); ?></div>
			<div id="kv-message" style="white-space:pre-wrap;word-break:break-word;"></div>

			<div id="kv-media-wrap" style="margin-top:12px;display:none;">
				<div style="font-weight:600;margin-bottom:6px;"><?php echo esc_html( 'Media' ); ?></div>
				<div id="kv-media-preview"></div>
			</div>
		</div>
		<div class="nxtcc-modal-footer">
			<button class="nxtcc-btn" id="nxtcc-history-modal-close-2"><?php echo esc_html( 'Close' ); ?></button>
		</div>
	</div>
</div>
