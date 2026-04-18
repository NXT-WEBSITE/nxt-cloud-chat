<?php
/**
 * Tools settings module view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

$nxtcc_cleanup_targets         = isset( $nxtcc_cleanup_targets ) && is_array( $nxtcc_cleanup_targets ) ? $nxtcc_cleanup_targets : array();
$nxtcc_cleanup_settings        = isset( $nxtcc_cleanup_settings ) && is_array( $nxtcc_cleanup_settings ) ? $nxtcc_cleanup_settings : array();
$nxtcc_cleanup_last_run        = isset( $nxtcc_cleanup_last_run ) && is_array( $nxtcc_cleanup_last_run ) ? $nxtcc_cleanup_last_run : array();
$nxtcc_cleanup_next_run_label  = isset( $nxtcc_cleanup_next_run_label ) ? (string) $nxtcc_cleanup_next_run_label : '';
$nxtcc_cleanup_can_manage      = ! empty( $nxtcc_cleanup_can_manage );
$nxtcc_cleanup_manage_disabled = ! $nxtcc_cleanup_can_manage;
$nxtcc_cleanup_auto_enabled    = ! empty( $nxtcc_cleanup_settings['auto_enabled'] ) ? 1 : 0;
$nxtcc_cleanup_run_time        = isset( $nxtcc_cleanup_settings['run_time'] ) ? (string) $nxtcc_cleanup_settings['run_time'] : '03:15';
$nxtcc_cleanup_keep_favorites  = ! empty( $nxtcc_cleanup_settings['preserve_favorites'] ) ? 1 : 0;
$nxtcc_cleanup_last_has_run    = ! empty( $nxtcc_cleanup_last_run['has_run'] );
?>

<div class="nxtcc-settings-tools">
	<?php if ( $nxtcc_cleanup_manage_disabled ) : ?>
		<div class="nxtcc-settings-tools-notice">
			<p>
				<?php esc_html_e( 'Cleanup tools can only be managed by the tenant owner. You can review the current rules here, but changes and cleanup actions are disabled for your account.', 'nxt-cloud-chat' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Automatic Cleanup', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Choose whether older activity should be cleaned up automatically and pick the daily run time.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="" class="nxtcc-settings-card-form">
			<?php wp_nonce_field( 'nxtcc_cleanup_schedule_save', 'nxtcc_cleanup_schedule_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_cleanup_schedule_settings">
			<input type="hidden" name="nxtcc_settings_active_tab" value="tools">

			<fieldset class="nxtcc-settings-tools-fieldset" <?php disabled( $nxtcc_cleanup_manage_disabled ); ?>>
				<div class="nxtcc-settings-tools-grid">
					<div class="nxtcc-settings-field nxtcc-settings-checkbox-field">
						<label class="nxtcc-settings-checkbox-label" for="nxtcc_cleanup_auto_enabled">
							<input
								type="checkbox"
								id="nxtcc_cleanup_auto_enabled"
								name="nxtcc_cleanup_auto_enabled"
								value="1"
								<?php checked( $nxtcc_cleanup_auto_enabled, 1 ); ?>
							/>
							<span><?php esc_html_e( 'Run cleanup automatically every day', 'nxt-cloud-chat' ); ?></span>
						</label>
						<p class="nxtcc-settings-field-note">
							<?php echo esc_html( $nxtcc_cleanup_next_run_label ); ?>
						</p>
					</div>

					<div class="nxtcc-settings-field">
						<label for="nxtcc_cleanup_run_time"><?php esc_html_e( 'Daily run time', 'nxt-cloud-chat' ); ?></label>
						<input
							type="time"
							id="nxtcc_cleanup_run_time"
							name="nxtcc_cleanup_run_time"
							value="<?php echo esc_attr( $nxtcc_cleanup_run_time ); ?>"
							step="60"
						/>
						<p class="nxtcc-settings-field-note">
							<?php esc_html_e( 'Uses your site timezone.', 'nxt-cloud-chat' ); ?>
						</p>
					</div>
				</div>

				<div class="nxtcc-settings-actions">
					<button type="submit" class="nxtcc-button nxtcc-button-primary" name="nxtcc_save_cleanup_schedule">
						<?php esc_html_e( 'Save Cleanup Schedule', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
			</fieldset>
		</form>
	</section>

	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'How Long To Keep Data', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Choose what older activity can be removed and how long to keep each kind of data.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="" class="nxtcc-settings-card-form">
			<?php wp_nonce_field( 'nxtcc_cleanup_retention_save', 'nxtcc_cleanup_retention_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_cleanup_retention_settings">
			<input type="hidden" name="nxtcc_settings_active_tab" value="tools">

			<fieldset class="nxtcc-settings-tools-fieldset" <?php disabled( $nxtcc_cleanup_manage_disabled ); ?>>
				<div class="nxtcc-settings-tools-table-wrap">
					<table class="nxtcc-settings-tools-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Data type', 'nxt-cloud-chat' ); ?></th>
								<th><?php esc_html_e( 'What it includes', 'nxt-cloud-chat' ); ?></th>
								<th><?php esc_html_e( 'Include in cleanup', 'nxt-cloud-chat' ); ?></th>
								<th><?php esc_html_e( 'Keep for', 'nxt-cloud-chat' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $nxtcc_cleanup_targets as $nxtcc_target ) : ?>
								<?php
								$nxtcc_target_id          = isset( $nxtcc_target['id'] ) ? sanitize_key( (string) $nxtcc_target['id'] ) : '';
								$nxtcc_target_label       = isset( $nxtcc_target['label'] ) ? (string) $nxtcc_target['label'] : '';
								$nxtcc_target_description = isset( $nxtcc_target['description'] ) ? (string) $nxtcc_target['description'] : '';
								$nxtcc_target_enabled     = ! empty( $nxtcc_target['enabled'] );
								$nxtcc_target_days        = isset( $nxtcc_target['days'] ) ? (int) $nxtcc_target['days'] : 90;
								$nxtcc_target_min_days    = isset( $nxtcc_target['min_days'] ) ? (int) $nxtcc_target['min_days'] : 1;
								$nxtcc_target_max_days    = isset( $nxtcc_target['max_days'] ) ? (int) $nxtcc_target['max_days'] : 3650;
								$nxtcc_target_manual_only = ! empty( $nxtcc_target['manual_only'] );
								?>
								<tr>
									<td>
										<div class="nxtcc-settings-tools-label-cell">
											<span class="nxtcc-settings-tools-label"><?php echo esc_html( $nxtcc_target_label ); ?></span>
											<?php if ( $nxtcc_target_manual_only ) : ?>
												<span class="nxtcc-settings-tools-badge"><?php esc_html_e( 'Manual only', 'nxt-cloud-chat' ); ?></span>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<p class="nxtcc-settings-tools-table-copy"><?php echo esc_html( $nxtcc_target_description ); ?></p>
									</td>
									<td>
										<label class="nxtcc-settings-checkbox-label nxtcc-settings-tools-inline-check" for="nxtcc_cleanup_enabled_<?php echo esc_attr( $nxtcc_target_id ); ?>">
											<input
												type="checkbox"
												id="nxtcc_cleanup_enabled_<?php echo esc_attr( $nxtcc_target_id ); ?>"
												name="nxtcc_cleanup_enabled[<?php echo esc_attr( $nxtcc_target_id ); ?>]"
												value="1"
												<?php checked( $nxtcc_target_enabled ); ?>
											/>
											<span><?php esc_html_e( 'Turn on', 'nxt-cloud-chat' ); ?></span>
										</label>
									</td>
									<td>
										<div class="nxtcc-settings-tools-days-field">
											<input
												type="number"
												class="small-text"
												name="nxtcc_cleanup_days[<?php echo esc_attr( $nxtcc_target_id ); ?>]"
												value="<?php echo esc_attr( (string) $nxtcc_target_days ); ?>"
												min="<?php echo esc_attr( (string) $nxtcc_target_min_days ); ?>"
												max="<?php echo esc_attr( (string) $nxtcc_target_max_days ); ?>"
												step="1"
											/>
											<span><?php esc_html_e( 'days', 'nxt-cloud-chat' ); ?></span>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="nxtcc-settings-field nxtcc-settings-checkbox-field nxtcc-settings-tools-extra-rule">
					<label class="nxtcc-settings-checkbox-label" for="nxtcc_cleanup_preserve_favorites">
						<input
							type="checkbox"
							id="nxtcc_cleanup_preserve_favorites"
							name="nxtcc_cleanup_preserve_favorites"
							value="1"
							<?php checked( $nxtcc_cleanup_keep_favorites, 1 ); ?>
						/>
						<span><?php esc_html_e( 'Keep starred messages even when they are older than the selected time', 'nxt-cloud-chat' ); ?></span>
					</label>
				</div>

				<p class="nxtcc-settings-tools-note">
					<?php esc_html_e( 'Current workflow copies, waiting workflows, pending items, scheduled deliveries, and active campaigns are always kept.', 'nxt-cloud-chat' ); ?>
				</p>

				<div class="nxtcc-settings-actions nxtcc-settings-tools-actions-row">
					<div class="nxtcc-settings-tools-actions-group">
						<button type="submit" class="nxtcc-button nxtcc-button-primary" name="nxtcc_save_cleanup_retention">
							<?php esc_html_e( 'Save Cleanup Rules', 'nxt-cloud-chat' ); ?>
						</button>
						<button type="submit" class="nxtcc-button nxtcc-button-light" name="nxtcc_reset_cleanup_retention" value="1">
							<?php esc_html_e( 'Reset to Defaults', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</div>
			</fieldset>
		</form>
	</section>

	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Clean Up Now', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Preview what can be removed right now using the saved rules, then run cleanup when you are ready.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<fieldset class="nxtcc-settings-tools-fieldset" <?php disabled( $nxtcc_cleanup_manage_disabled ); ?>>
			<div class="nxtcc-settings-tools-manual-card">
				<div class="nxtcc-settings-tools-preview" id="nxtcc-cleanup-preview" aria-live="polite">
					<p class="nxtcc-settings-tools-placeholder">
						<?php esc_html_e( 'Use the preview button to see how much older activity can be removed right now.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<div class="nxtcc-settings-field nxtcc-settings-checkbox-field">
					<label class="nxtcc-settings-checkbox-label" for="nxtcc_cleanup_confirm">
						<input type="checkbox" id="nxtcc_cleanup_confirm" value="1" />
						<span><?php esc_html_e( 'I understand this permanently removes older activity based on the saved rules.', 'nxt-cloud-chat' ); ?></span>
					</label>
				</div>

				<div class="nxtcc-settings-actions nxtcc-settings-tools-actions-row">
					<div class="nxtcc-settings-tools-actions-group">
						<button type="button" class="nxtcc-button nxtcc-button-light" id="nxtcc_cleanup_preview_button">
							<?php esc_html_e( 'Preview Cleanup', 'nxt-cloud-chat' ); ?>
						</button>
						<button type="button" class="nxtcc-button nxtcc-button-primary" id="nxtcc_cleanup_run_button">
							<?php esc_html_e( 'Clean Up Now', 'nxt-cloud-chat' ); ?>
						</button>
					</div>

					<div class="nxtcc-settings-tools-actions-group nxtcc-settings-tools-actions-group-danger">
						<button type="button" class="nxtcc-button nxtcc-button-danger" id="nxtcc_cleanup_everything_button">
							<?php esc_html_e( 'Clean Everything', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</div>

				<div class="nxtcc-settings-tools-danger-panel" id="nxtcc-cleanup-everything-panel" hidden>
					<p class="nxtcc-settings-tools-danger-copy" id="nxtcc-cleanup-everything-message">
						<?php esc_html_e( 'To continue, solve this quick check.', 'nxt-cloud-chat' ); ?>
					</p>

					<div class="nxtcc-settings-tools-danger-row">
						<div class="nxtcc-settings-tools-danger-question">
							<span class="nxtcc-settings-tools-danger-label"><?php esc_html_e( 'Check', 'nxt-cloud-chat' ); ?></span>
							<strong id="nxtcc_cleanup_math_question">12 + 34</strong>
						</div>

						<label class="nxtcc-settings-tools-danger-answer" for="nxtcc_cleanup_math_answer">
							<span class="screen-reader-text"><?php esc_html_e( 'Answer', 'nxt-cloud-chat' ); ?></span>
							<input
								type="number"
								id="nxtcc_cleanup_math_answer"
								inputmode="numeric"
								step="1"
								min="0"
								placeholder="<?php esc_attr_e( 'Type the answer', 'nxt-cloud-chat' ); ?>"
							/>
						</label>

						<input type="hidden" id="nxtcc_cleanup_math_token" value="">

						<button type="button" class="nxtcc-button nxtcc-button-danger" id="nxtcc_cleanup_math_confirm">
							<?php esc_html_e( 'Clean Everything', 'nxt-cloud-chat' ); ?>
						</button>
						<button type="button" class="nxtcc-button nxtcc-button-light" id="nxtcc_cleanup_math_cancel">
							<?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</div>
			</div>
		</fieldset>
	</section>

	<section class="nxtcc-settings-section nxtcc-settings-section-compact">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Cleanup History', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'See the most recent cleanup result and what it removed.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<div class="nxtcc-settings-tools-history" id="nxtcc-cleanup-history">
			<div class="nxtcc-settings-tools-history-meta">
				<div class="nxtcc-settings-tools-history-chip">
					<span class="nxtcc-settings-tools-history-label"><?php esc_html_e( 'Last run', 'nxt-cloud-chat' ); ?></span>
					<strong><?php echo esc_html( isset( $nxtcc_cleanup_last_run['started_at_display'] ) ? (string) $nxtcc_cleanup_last_run['started_at_display'] : __( 'Never', 'nxt-cloud-chat' ) ); ?></strong>
				</div>
				<div class="nxtcc-settings-tools-history-chip">
					<span class="nxtcc-settings-tools-history-label"><?php esc_html_e( 'Run type', 'nxt-cloud-chat' ); ?></span>
					<strong><?php echo esc_html( isset( $nxtcc_cleanup_last_run['trigger_label'] ) ? (string) $nxtcc_cleanup_last_run['trigger_label'] : __( 'Not run yet', 'nxt-cloud-chat' ) ); ?></strong>
				</div>
				<div class="nxtcc-settings-tools-history-chip">
					<span class="nxtcc-settings-tools-history-label"><?php esc_html_e( 'Removed', 'nxt-cloud-chat' ); ?></span>
					<strong><?php echo esc_html( isset( $nxtcc_cleanup_last_run['total_deleted_display'] ) ? (string) $nxtcc_cleanup_last_run['total_deleted_display'] : '0' ); ?></strong>
				</div>
				<div class="nxtcc-settings-tools-history-chip">
					<span class="nxtcc-settings-tools-history-label"><?php esc_html_e( 'Status', 'nxt-cloud-chat' ); ?></span>
					<strong class="nxtcc-settings-status-pill <?php echo esc_attr( isset( $nxtcc_cleanup_last_run['status_class'] ) ? (string) $nxtcc_cleanup_last_run['status_class'] : '' ); ?>">
						<?php echo esc_html( isset( $nxtcc_cleanup_last_run['status_label'] ) ? (string) $nxtcc_cleanup_last_run['status_label'] : __( 'Idle', 'nxt-cloud-chat' ) ); ?>
					</strong>
				</div>
			</div>

			<p class="nxtcc-settings-tools-history-summary">
				<?php echo esc_html( isset( $nxtcc_cleanup_last_run['summary'] ) ? (string) $nxtcc_cleanup_last_run['summary'] : __( 'No cleanup has run yet on this site.', 'nxt-cloud-chat' ) ); ?>
			</p>

			<?php if ( $nxtcc_cleanup_last_has_run && ! empty( $nxtcc_cleanup_last_run['items'] ) && is_array( $nxtcc_cleanup_last_run['items'] ) ) : ?>
				<ul class="nxtcc-settings-tools-history-list">
					<?php foreach ( $nxtcc_cleanup_last_run['items'] as $nxtcc_history_item ) : ?>
						<li class="nxtcc-settings-tools-history-item">
							<span class="nxtcc-settings-tools-history-item-label"><?php echo esc_html( isset( $nxtcc_history_item['label'] ) ? (string) $nxtcc_history_item['label'] : '' ); ?></span>
							<span class="nxtcc-settings-tools-history-item-count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: deleted count, 2: remaining count */
										__( '%1$s removed, %2$s remaining', 'nxt-cloud-chat' ),
										isset( $nxtcc_history_item['deleted_display'] ) ? (string) $nxtcc_history_item['deleted_display'] : '0',
										isset( $nxtcc_history_item['remaining_display'] ) ? (string) $nxtcc_history_item['remaining_display'] : '0'
									)
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>
</div>
