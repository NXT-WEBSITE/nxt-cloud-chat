<?php
/**
 * Sales deals management view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_view_deals', 'nxtcc_manage_deals' ) ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nxt-cloud-chat' ) );
}

$nxtcc_deals_nonce     = wp_create_nonce( 'nxtcc_deals' );
$nxtcc_can_manage      = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_deals' ) );
$nxtcc_manage_pipeline = NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_manage_pipelines' ) );
$nxtcc_active_tenant   = NXTCC_Access_Control::get_current_tenant_context();
$nxtcc_has_connection  = ! in_array( '', NXTCC_Access_Control::normalize_tenant_context( $nxtcc_active_tenant ), true );
$nxtcc_requested_view  = filter_input( INPUT_GET, 'view', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
$nxtcc_deals_view      = 'pipelines' === sanitize_key( is_string( $nxtcc_requested_view ) ? $nxtcc_requested_view : '' ) ? 'pipelines' : 'deals';
?>

<div class="wrap nxtcc-groups-screen nxtcc-deals-screen">
	<div
		class="nxtcc-groups-widget nxtcc-deals-widget"
		data-nonce="<?php echo esc_attr( $nxtcc_deals_nonce ); ?>"
		data-can-manage="<?php echo esc_attr( $nxtcc_can_manage ? '1' : '0' ); ?>"
		data-manage-pipelines="<?php echo esc_attr( $nxtcc_manage_pipeline ? '1' : '0' ); ?>"
		data-has-connection="<?php echo esc_attr( $nxtcc_has_connection ? '1' : '0' ); ?>"
		data-active-view="<?php echo esc_attr( $nxtcc_deals_view ); ?>"
	>
		<?php if ( ! $nxtcc_has_connection ) : ?>
			<div class="nxtcc-groups-alert nxtcc-groups-alert-warning">
				<strong><?php esc_html_e( 'Action required:', 'nxt-cloud-chat' ); ?></strong>
				<span><?php esc_html_e( 'Connect an account and phone number before managing tenant deals.', 'nxt-cloud-chat' ); ?></span>
			</div>
		<?php endif; ?>

		<nav class="nxtcc-deals-tabs" aria-label="<?php echo esc_attr__( 'Deals views', 'nxt-cloud-chat' ); ?>">
			<a class="<?php echo esc_attr( 'pipelines' === $nxtcc_deals_view ? 'is-active' : '' ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-deals&view=pipelines' ) ); ?>"><?php esc_html_e( 'Pipelines', 'nxt-cloud-chat' ); ?></a>
			<a class="<?php echo esc_attr( 'deals' === $nxtcc_deals_view ? 'is-active' : '' ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-deals&view=deals' ) ); ?>"><?php esc_html_e( 'Deals', 'nxt-cloud-chat' ); ?></a>
		</nav>

		<?php if ( 'deals' === $nxtcc_deals_view ) : ?>
			<div class="nxtcc-groups-summary">
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'All Deals', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-deals-summary-total" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Current tenant opportunities', 'nxt-cloud-chat' ); ?></span>
			</div>
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Open', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-deals-summary-open" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Active opportunities', 'nxt-cloud-chat' ); ?></span>
			</div>
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Won', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-deals-summary-won" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Closed successfully', 'nxt-cloud-chat' ); ?></span>
			</div>
			<div class="nxtcc-groups-summary-card">
				<span class="nxtcc-groups-summary-label"><?php esc_html_e( 'Open Value', 'nxt-cloud-chat' ); ?></span>
				<strong id="nxtcc-deals-summary-value" class="nxtcc-groups-summary-value">0</strong>
				<span class="nxtcc-groups-summary-meta"><?php esc_html_e( 'Unweighted pipeline value', 'nxt-cloud-chat' ); ?></span>
			</div>
			</div>

			<div class="nxtcc-groups-toolbar">
			<div class="nxtcc-groups-toolbar-copy">
				<h1 class="nxtcc-page-title"><?php esc_html_e( 'Deals', 'nxt-cloud-chat' ); ?></h1>
			</div>
			<div class="nxtcc-groups-toolbar-controls">
				<button id="nxtcc-deals-refresh" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button">
					<?php esc_html_e( 'Refresh', 'nxt-cloud-chat' ); ?>
				</button>
				<?php if ( $nxtcc_can_manage ) : ?>
					<button id="nxtcc-deals-add" class="nxtcc-groups-btn nxtcc-groups-btn-green" type="button" <?php disabled( ! $nxtcc_has_connection ); ?>>
						<span class="nxtcc-groups-btn-icon" aria-hidden="true">+</span>
						<?php esc_html_e( 'New Deal', 'nxt-cloud-chat' ); ?>
					</button>
				<?php endif; ?>
			</div>
			</div>

			<div class="nxtcc-deals-filters">
			<input id="nxtcc-deals-search" class="nxtcc-ui-filter-control" type="search" placeholder="<?php echo esc_attr__( 'Search deals or contacts', 'nxt-cloud-chat' ); ?>">
			<select id="nxtcc-deals-filter-pipeline" class="nxtcc-ui-filter-control" aria-label="<?php echo esc_attr__( 'Filter by pipeline', 'nxt-cloud-chat' ); ?>"></select>
			<select id="nxtcc-deals-filter-stage" class="nxtcc-ui-filter-control" aria-label="<?php echo esc_attr__( 'Filter by stage', 'nxt-cloud-chat' ); ?>"></select>
			<select id="nxtcc-deals-filter-status" class="nxtcc-ui-filter-control" aria-label="<?php echo esc_attr__( 'Filter by status', 'nxt-cloud-chat' ); ?>">
				<option value=""><?php esc_html_e( 'All Statuses', 'nxt-cloud-chat' ); ?></option>
				<option value="open"><?php esc_html_e( 'Open', 'nxt-cloud-chat' ); ?></option>
				<option value="won"><?php esc_html_e( 'Won', 'nxt-cloud-chat' ); ?></option>
				<option value="lost"><?php esc_html_e( 'Lost', 'nxt-cloud-chat' ); ?></option>
			</select>
			</div>

			<div class="nxtcc-groups-table-wrap nxtcc-deals-table-wrap">
			<table class="nxtcc-groups-table nxtcc-deals-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Deal', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Contact', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pipeline / Stage', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Value', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Owner', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expected Close', 'nxt-cloud-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'nxt-cloud-chat' ); ?></th>
						<?php if ( $nxtcc_can_manage ) : ?>
							<th class="actions-col" scope="col"><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody id="nxtcc-deals-tbody">
					<tr class="nxtcc-groups-state-row">
						<td colspan="99" class="nxtcc-groups-state-cell"><?php esc_html_e( 'Loading deals...', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tbody>
			</table>
			<div class="nxtcc-tags-pagination">
				<button id="nxtcc-deals-prev" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button" disabled><?php esc_html_e( 'Previous', 'nxt-cloud-chat' ); ?></button>
				<span id="nxtcc-deals-page-label"></span>
				<button id="nxtcc-deals-next" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button" disabled><?php esc_html_e( 'Next', 'nxt-cloud-chat' ); ?></button>
			</div>
			</div>
		<?php else : ?>
			<div class="nxtcc-groups-toolbar nxtcc-pipelines-toolbar">
				<div class="nxtcc-groups-toolbar-copy">
					<h1 class="nxtcc-page-title"><?php esc_html_e( 'Pipelines', 'nxt-cloud-chat' ); ?></h1>
				</div>
				<div class="nxtcc-groups-toolbar-controls">
					<button id="nxtcc-pipelines-refresh" class="nxtcc-groups-btn nxtcc-groups-btn-secondary" type="button"><?php esc_html_e( 'Refresh', 'nxt-cloud-chat' ); ?></button>
					<?php if ( $nxtcc_manage_pipeline ) : ?>
						<button id="nxtcc-pipeline-add" class="nxtcc-groups-btn nxtcc-groups-btn-green" type="button" <?php disabled( ! $nxtcc_has_connection ); ?>>
							<span class="nxtcc-groups-btn-icon" aria-hidden="true">+</span>
							<?php esc_html_e( 'Add Pipeline', 'nxt-cloud-chat' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
			<div class="nxtcc-groups-table-wrap nxtcc-pipelines-table-wrap">
				<table class="nxtcc-groups-table nxtcc-pipelines-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Pipeline', 'nxt-cloud-chat' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Currency', 'nxt-cloud-chat' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stages', 'nxt-cloud-chat' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Deals', 'nxt-cloud-chat' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'nxt-cloud-chat' ); ?></th>
							<th class="actions-col" scope="col"><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
						</tr>
					</thead>
					<tbody id="nxtcc-pipelines-tbody">
						<tr class="nxtcc-groups-state-row"><td colspan="99" class="nxtcc-groups-state-cell"><?php esc_html_e( 'Loading pipelines...', 'nxt-cloud-chat' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ( $nxtcc_can_manage ) : ?>
			<div id="nxtcc-deal-modal" class="nxtcc-groups-modal" hidden>
				<div class="nxtcc-groups-modal-overlay nxtcc-deal-dismiss"></div>
				<div class="nxtcc-groups-modal-content nxtcc-deal-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-deal-modal-title">
					<div class="nxtcc-groups-modal-header">
						<div class="nxtcc-groups-modal-copy">
							<h2 id="nxtcc-deal-modal-title"><?php esc_html_e( 'New Deal', 'nxt-cloud-chat' ); ?></h2>
							<p class="nxtcc-groups-modal-subtitle"><?php esc_html_e( 'Track an opportunity from first contact through close.', 'nxt-cloud-chat' ); ?></p>
						</div>
						<button class="nxtcc-groups-modal-close-icon nxtcc-deal-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
					</div>
					<form id="nxtcc-deal-form" class="nxtcc-groups-modal-form">
						<input id="nxtcc-deal-id" type="hidden">
						<div class="nxtcc-deal-form-grid">
							<label class="nxtcc-deal-field nxtcc-deal-field-wide">
								<span><?php esc_html_e( 'Deal Title', 'nxt-cloud-chat' ); ?></span>
								<input id="nxtcc-deal-title" type="text" maxlength="191" required>
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Pipeline', 'nxt-cloud-chat' ); ?></span>
								<select id="nxtcc-deal-pipeline" required></select>
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Stage', 'nxt-cloud-chat' ); ?></span>
								<select id="nxtcc-deal-stage" required></select>
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Value', 'nxt-cloud-chat' ); ?></span>
								<input id="nxtcc-deal-value" type="number" min="0" step="0.01" value="0">
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Value Mode', 'nxt-cloud-chat' ); ?></span>
								<select id="nxtcc-deal-value-mode">
									<option value="manual"><?php esc_html_e( 'Manual value', 'nxt-cloud-chat' ); ?></option>
									<option value="calculated"><?php esc_html_e( 'Calculated from line items', 'nxt-cloud-chat' ); ?></option>
								</select>
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Currency', 'nxt-cloud-chat' ); ?></span>
								<input id="nxtcc-deal-currency" type="text" maxlength="3" value="USD">
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Expected Close', 'nxt-cloud-chat' ); ?></span>
								<input id="nxtcc-deal-close" type="date">
							</label>
							<label class="nxtcc-deal-field">
								<span><?php esc_html_e( 'Owner', 'nxt-cloud-chat' ); ?></span>
								<select id="nxtcc-deal-owner"></select>
							</label>
							<label class="nxtcc-deal-field nxtcc-deal-field-wide">
								<span><?php esc_html_e( 'Primary Contact', 'nxt-cloud-chat' ); ?></span>
								<select id="nxtcc-deal-contact"></select>
							</label>
							<label id="nxtcc-deal-reason-field" class="nxtcc-deal-field nxtcc-deal-field-wide">
								<span><?php esc_html_e( 'Reason', 'nxt-cloud-chat' ); ?></span>
								<input id="nxtcc-deal-reason" type="text" maxlength="500">
							</label>
							<label class="nxtcc-deal-field nxtcc-deal-field-wide">
								<span><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></span>
								<textarea id="nxtcc-deal-description" rows="3" maxlength="5000"></textarea>
							</label>
						</div>
						<div class="nxtcc-deal-products-header">
							<strong><?php esc_html_e( 'Line Items', 'nxt-cloud-chat' ); ?></strong>
							<button id="nxtcc-deal-product-add" type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary"><?php esc_html_e( 'Add Line Item', 'nxt-cloud-chat' ); ?></button>
						</div>
						<div id="nxtcc-deal-products" class="nxtcc-deal-products"></div>
						<div id="nxtcc-deal-stage-history-wrap" class="nxtcc-deal-stage-history-wrap" hidden>
							<strong><?php esc_html_e( 'Stage History', 'nxt-cloud-chat' ); ?></strong>
							<div id="nxtcc-deal-stage-history" class="nxtcc-deal-stage-history"></div>
						</div>
						<div class="nxtcc-groups-modal-footer">
							<button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-deal-dismiss"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
							<button type="submit" class="nxtcc-groups-btn nxtcc-groups-btn-green"><?php esc_html_e( 'Save Deal', 'nxt-cloud-chat' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $nxtcc_manage_pipeline ) : ?>
			<div id="nxtcc-pipeline-form-modal" class="nxtcc-groups-modal" hidden>
				<div class="nxtcc-groups-modal-overlay nxtcc-pipeline-dismiss"></div>
				<div class="nxtcc-groups-modal-content nxtcc-pipeline-form-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-pipeline-modal-title">
					<div class="nxtcc-groups-modal-header">
						<div class="nxtcc-groups-modal-copy">
							<h2 id="nxtcc-pipeline-modal-title"><?php esc_html_e( 'Add Pipeline', 'nxt-cloud-chat' ); ?></h2>
							<p class="nxtcc-groups-modal-subtitle"><?php esc_html_e( 'Create a reusable sales process for the active tenant.', 'nxt-cloud-chat' ); ?></p>
						</div>
						<button class="nxtcc-groups-modal-close-icon nxtcc-pipeline-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button>
					</div>
					<form id="nxtcc-pipeline-form" class="nxtcc-groups-modal-form">
						<input id="nxtcc-pipeline-id" type="hidden">
						<div class="nxtcc-groups-form-row"><label for="nxtcc-pipeline-name"><?php esc_html_e( 'Pipeline Name', 'nxt-cloud-chat' ); ?></label><input id="nxtcc-pipeline-name" type="text" maxlength="120" required placeholder="<?php echo esc_attr__( 'Example: Property Sales', 'nxt-cloud-chat' ); ?>"></div>
						<div class="nxtcc-groups-form-row"><label for="nxtcc-pipeline-currency"><?php esc_html_e( 'Currency', 'nxt-cloud-chat' ); ?></label><input id="nxtcc-pipeline-currency" type="text" maxlength="3" value="USD" required placeholder="<?php echo esc_attr__( 'Example: USD', 'nxt-cloud-chat' ); ?>"></div>
						<div class="nxtcc-deal-check-row"><label><input id="nxtcc-pipeline-default" type="checkbox"> <?php esc_html_e( 'Default pipeline', 'nxt-cloud-chat' ); ?></label><label><input id="nxtcc-pipeline-active" type="checkbox" checked> <?php esc_html_e( 'Active', 'nxt-cloud-chat' ); ?></label></div>
						<div id="nxtcc-pipeline-form-stages-section" class="nxtcc-pipeline-form-stages" hidden>
							<div class="nxtcc-pipeline-form-stages-head">
								<div>
									<strong><?php esc_html_e( 'Pipeline Stages', 'nxt-cloud-chat' ); ?></strong>
									<p><?php esc_html_e( 'Edit, archive, restore, or delete stages in this pipeline.', 'nxt-cloud-chat' ); ?></p>
								</div>
								<button id="nxtcc-pipeline-form-stage-add" class="nxtcc-groups-btn nxtcc-groups-btn-green" type="button"><?php esc_html_e( 'Add Stage', 'nxt-cloud-chat' ); ?></button>
							</div>
							<div class="nxtcc-pipeline-stage-table-wrap">
								<table class="nxtcc-pipeline-stage-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'Stage Name', 'nxt-cloud-chat' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Stage Type', 'nxt-cloud-chat' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Probability', 'nxt-cloud-chat' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Color', 'nxt-cloud-chat' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Reason Requirement', 'nxt-cloud-chat' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Active Stage', 'nxt-cloud-chat' ); ?></th>
											<th class="actions-col" scope="col"><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
										</tr>
									</thead>
									<tbody id="nxtcc-pipeline-form-stages-tbody"></tbody>
								</table>
							</div>
						</div>
						<div id="nxtcc-pipeline-danger" class="nxtcc-deal-danger" hidden><button id="nxtcc-pipeline-delete" class="nxtcc-groups-btn nxtcc-groups-btn-danger" type="button"><?php esc_html_e( 'Delete Pipeline', 'nxt-cloud-chat' ); ?></button></div>
						<div class="nxtcc-groups-modal-footer"><button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-pipeline-dismiss"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button><button class="nxtcc-groups-btn nxtcc-groups-btn-green" type="submit"><?php esc_html_e( 'Save Pipeline', 'nxt-cloud-chat' ); ?></button></div>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<div id="nxtcc-stages-modal" class="nxtcc-groups-modal" hidden>
			<div class="nxtcc-groups-modal-overlay nxtcc-stages-dismiss"></div>
			<div class="nxtcc-groups-modal-content nxtcc-stages-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-stages-title">
				<div class="nxtcc-groups-modal-header"><div class="nxtcc-groups-modal-copy"><h2 id="nxtcc-stages-title"><?php esc_html_e( 'Pipeline Stages', 'nxt-cloud-chat' ); ?></h2><p class="nxtcc-groups-modal-subtitle"><?php esc_html_e( 'Review the stages deals move through.', 'nxt-cloud-chat' ); ?></p></div><button class="nxtcc-groups-modal-close-icon nxtcc-stages-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button></div>
				<div class="nxtcc-groups-modal-form">
					<div class="nxtcc-deal-products-header">
						<strong id="nxtcc-stages-pipeline-name"></strong>
						<?php if ( $nxtcc_manage_pipeline ) : ?>
							<button id="nxtcc-stage-add" class="nxtcc-groups-btn nxtcc-groups-btn-green" type="button"><?php esc_html_e( 'Add Stage', 'nxt-cloud-chat' ); ?></button>
						<?php endif; ?>
					</div>
					<div id="nxtcc-stage-list" class="nxtcc-stage-list"></div>
				</div>
			</div>
		</div>

		<?php if ( $nxtcc_manage_pipeline ) : ?>
			<div id="nxtcc-stage-form-modal" class="nxtcc-groups-modal" hidden>
				<div class="nxtcc-groups-modal-overlay nxtcc-stage-dismiss"></div>
				<div class="nxtcc-groups-modal-content nxtcc-stage-form-modal-content" role="dialog" aria-modal="true" aria-labelledby="nxtcc-stage-modal-title">
					<div class="nxtcc-groups-modal-header"><div class="nxtcc-groups-modal-copy"><h2 id="nxtcc-stage-modal-title"><?php esc_html_e( 'Add Stage', 'nxt-cloud-chat' ); ?></h2><p id="nxtcc-stage-pipeline-label" class="nxtcc-groups-modal-subtitle"></p></div><button class="nxtcc-groups-modal-close-icon nxtcc-stage-dismiss" type="button" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">&times;</button></div>
					<form id="nxtcc-stage-form" class="nxtcc-groups-modal-form">
						<input id="nxtcc-stage-id" type="hidden"><input id="nxtcc-stage-pipeline" type="hidden">
						<div class="nxtcc-groups-form-row"><label for="nxtcc-stage-name"><?php esc_html_e( 'Stage Name', 'nxt-cloud-chat' ); ?></label><input id="nxtcc-stage-name" type="text" maxlength="120" required placeholder="<?php echo esc_attr__( 'Example: Site Visit Scheduled', 'nxt-cloud-chat' ); ?>"></div>
						<div class="nxtcc-deal-form-grid"><label class="nxtcc-deal-field"><span><?php esc_html_e( 'Stage Type', 'nxt-cloud-chat' ); ?></span><select id="nxtcc-stage-type"><option value="open"><?php esc_html_e( 'Open', 'nxt-cloud-chat' ); ?></option><option value="won"><?php esc_html_e( 'Won', 'nxt-cloud-chat' ); ?></option><option value="lost"><?php esc_html_e( 'Lost', 'nxt-cloud-chat' ); ?></option></select></label><label class="nxtcc-deal-field"><span><?php esc_html_e( 'Probability', 'nxt-cloud-chat' ); ?></span><input id="nxtcc-stage-probability" type="number" min="0" max="100" value="0"></label><label class="nxtcc-deal-field"><span><?php esc_html_e( 'Color', 'nxt-cloud-chat' ); ?></span><input id="nxtcc-stage-color" type="color" value="#2271b1"></label><label class="nxtcc-deal-field"><span><?php esc_html_e( 'Reason Requirement', 'nxt-cloud-chat' ); ?></span><select id="nxtcc-stage-reason-requirement"><option value="none"><?php esc_html_e( 'Not requested', 'nxt-cloud-chat' ); ?></option><option value="optional"><?php esc_html_e( 'Optional', 'nxt-cloud-chat' ); ?></option><option value="enter"><?php esc_html_e( 'Required when entering', 'nxt-cloud-chat' ); ?></option><option value="leave"><?php esc_html_e( 'Required when leaving', 'nxt-cloud-chat' ); ?></option><option value="both"><?php esc_html_e( 'Required entering or leaving', 'nxt-cloud-chat' ); ?></option></select></label></div>
						<label class="nxtcc-deal-check-row"><input id="nxtcc-stage-active" type="checkbox" checked> <?php esc_html_e( 'Active stage', 'nxt-cloud-chat' ); ?></label>
						<div id="nxtcc-stage-danger" class="nxtcc-deal-danger" hidden><button id="nxtcc-stage-delete" class="nxtcc-groups-btn nxtcc-groups-btn-danger" type="button"><?php esc_html_e( 'Delete Stage', 'nxt-cloud-chat' ); ?></button></div>
						<div class="nxtcc-groups-modal-footer"><button type="button" class="nxtcc-groups-btn nxtcc-groups-btn-secondary nxtcc-stage-dismiss"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button><button class="nxtcc-groups-btn nxtcc-groups-btn-green" type="submit"><?php esc_html_e( 'Save Stage', 'nxt-cloud-chat' ); ?></button></div>
					</form>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
