<?php
/**
 * Team Access settings module view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

$nxtcc_team_tenant_ready = ! empty( $nxtcc_primary_tenant['user_mailid'] ) && ! empty( $nxtcc_primary_tenant['business_account_id'] ) && ! empty( $nxtcc_primary_tenant['phone_number_id'] );
$nxtcc_team_total        = count( $nxtcc_team_access_members );
$nxtcc_team_owner_count  = 0;
$nxtcc_team_inactive     = 0;
$nxtcc_team_role_labels  = ( isset( $nxtcc_team_eligible_wp_role_labels ) && is_array( $nxtcc_team_eligible_wp_role_labels ) ) ? $nxtcc_team_eligible_wp_role_labels : array();
$nxtcc_team_role_text    = ! empty( $nxtcc_team_role_labels ) ? implode( ', ', $nxtcc_team_role_labels ) : '';
$nxtcc_team_owner_name   = '';
$nxtcc_team_owner_email  = isset( $nxtcc_primary_tenant['user_mailid'] ) ? (string) $nxtcc_primary_tenant['user_mailid'] : '';

foreach ( $nxtcc_team_access_members as $nxtcc_team_member ) {
	if ( is_array( $nxtcc_team_member ) && ! empty( $nxtcc_team_member['is_owner'] ) ) {
		++$nxtcc_team_owner_count;

		if ( '' === $nxtcc_team_owner_name && ! empty( $nxtcc_team_member['display_name'] ) ) {
			$nxtcc_team_owner_name = (string) $nxtcc_team_member['display_name'];
		}

		if ( '' === $nxtcc_team_owner_email && ! empty( $nxtcc_team_member['user_email'] ) ) {
			$nxtcc_team_owner_email = (string) $nxtcc_team_member['user_email'];
		}
	}

	if ( is_array( $nxtcc_team_member ) && isset( $nxtcc_team_member['wp_role_eligible'] ) && empty( $nxtcc_team_member['wp_role_eligible'] ) ) {
		++$nxtcc_team_inactive;
	}
}

$nxtcc_team_staff_count = max( 0, $nxtcc_team_total - $nxtcc_team_owner_count );
$nxtcc_team_role_filter = array(
	'owner'  => __( 'Tenant Owner', 'nxt-cloud-chat' ),
	'custom' => __( 'Custom', 'nxt-cloud-chat' ),
);

foreach ( $nxtcc_team_role_presets as $nxtcc_team_role_key => $nxtcc_team_role_meta ) {
	if ( ! is_array( $nxtcc_team_role_meta ) || empty( $nxtcc_team_role_meta['label'] ) ) {
		continue;
	}

	$nxtcc_team_role_filter[ $nxtcc_team_role_key ] = (string) $nxtcc_team_role_meta['label'];
}

$nxtcc_team_boot = array(
	'defaultRole' => isset( $nxtcc_team_role_presets['viewer'] ) ? 'viewer' : 'custom',
	'rolePresets' => $nxtcc_team_role_presets,
	'strings'     => array(
		'addTitle'            => __( 'Add Team Member', 'nxt-cloud-chat' ),
		'editTitle'           => __( 'Update Team Access', 'nxt-cloud-chat' ),
		'customRoleLabel'     => __( 'Custom', 'nxt-cloud-chat' ),
		'customRoleDesc'      => __( 'Choose the exact capabilities this member should have inside the current tenant.', 'nxt-cloud-chat' ),
		'noPermissions'       => __( 'No permissions selected yet.', 'nxt-cloud-chat' ),
		'ownerRoleLabel'      => __( 'Tenant Owner', 'nxt-cloud-chat' ),
		'ownerRoleDesc'       => __( 'The owner always keeps every tenant capability.', 'nxt-cloud-chat' ),
		'removeConfirm'       => __( 'Remove this team member from the current tenant?', 'nxt-cloud-chat' ),
		'userRequired'        => __( 'Select a WordPress user before saving access.', 'nxt-cloud-chat' ),
		'ownerOnlyHeading'    => __( 'Owner Only', 'nxt-cloud-chat' ),
		'availableUsersEmpty' => __( 'All current WordPress users are already assigned to this tenant.', 'nxt-cloud-chat' ),
	),
);

$nxtcc_team_boot_json = wp_json_encode( $nxtcc_team_boot );
$nxtcc_team_boot_json = is_string( $nxtcc_team_boot_json ) ? $nxtcc_team_boot_json : '{}';
?>

<div class="nxtcc-team-access-widget">
	<div class="nxtcc-team-access-summary">
		<div class="nxtcc-team-access-summary-item">
			<span class="nxtcc-team-access-summary-label"><?php esc_html_e( 'Tenant Owner', 'nxt-cloud-chat' ); ?></span>
			<strong class="nxtcc-team-access-summary-value">
				<?php echo esc_html( '' !== $nxtcc_team_owner_name ? $nxtcc_team_owner_name : $nxtcc_team_owner_email ); ?>
			</strong>
			<?php if ( '' !== $nxtcc_team_owner_email ) : ?>
				<span class="nxtcc-team-access-summary-meta">
					<?php echo esc_html( $nxtcc_team_owner_email ); ?>
				</span>
			<?php endif; ?>
		</div>
		<div class="nxtcc-team-access-summary-item">
			<span class="nxtcc-team-access-summary-label"><?php esc_html_e( 'Phone Number ID & Business Account ID', 'nxt-cloud-chat' ); ?></span>
			<strong class="nxtcc-team-access-summary-value">
				<?php echo esc_html( isset( $nxtcc_primary_tenant['phone_number_id'] ) ? (string) $nxtcc_primary_tenant['phone_number_id'] : '' ); ?>
			</strong>
			<span class="nxtcc-team-access-summary-meta">
				<?php echo esc_html( isset( $nxtcc_primary_tenant['business_account_id'] ) ? (string) $nxtcc_primary_tenant['business_account_id'] : '' ); ?>
			</span>
		</div>
		<div class="nxtcc-team-access-summary-item">
			<span class="nxtcc-team-access-summary-label"><?php esc_html_e( 'Assigned Members', 'nxt-cloud-chat' ); ?></span>
			<strong class="nxtcc-team-access-summary-value">
				<?php echo esc_html( (string) $nxtcc_team_total ); ?>
			</strong>
			<span class="nxtcc-team-access-summary-meta">
				<?php
				if ( $nxtcc_team_inactive > 0 ) {
					echo esc_html(
						sprintf(
							/* translators: 1: staff count, 2: owner count, 3: inactive member count */
							__( '%1$d staff, %2$d owner, %3$d inactive', 'nxt-cloud-chat' ),
							(int) $nxtcc_team_staff_count,
							(int) $nxtcc_team_owner_count,
							(int) $nxtcc_team_inactive
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: staff count, 2: owner count */
							__( '%1$d staff, %2$d owner', 'nxt-cloud-chat' ),
							(int) $nxtcc_team_staff_count,
							(int) $nxtcc_team_owner_count
						)
					);
				}
				?>
			</span>
		</div>
	</div>

	<div class="nxtcc-team-access-toolbar">
		<div>
			<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Team Access', 'nxt-cloud-chat' ); ?></h3>
			<p class="nxtcc-team-access-subtitle">
				<?php esc_html_e( 'Grant WordPress users tenant-specific access without sharing connection credentials.', 'nxt-cloud-chat' ); ?>
			</p>
		</div>

		<div class="nxtcc-team-access-toolbar-controls">
			<div class="nxtcc-team-access-filter-row">
				<input
					type="search"
					id="nxtcc-team-access-search"
					class="nxtcc-team-access-filter-input nxtcc-team-access-filter-search"
					placeholder="<?php echo esc_attr__( 'Search user, email, or permission', 'nxt-cloud-chat' ); ?>"
				/>

				<select id="nxtcc-team-access-role-filter" class="nxtcc-team-access-filter-input nxtcc-team-access-filter-role">
					<option value=""><?php esc_html_e( 'All Roles', 'nxt-cloud-chat' ); ?></option>
					<?php foreach ( $nxtcc_team_role_filter as $nxtcc_team_role_key => $nxtcc_team_role_label ) : ?>
						<option value="<?php echo esc_attr( (string) $nxtcc_team_role_key ); ?>">
							<?php echo esc_html( (string) $nxtcc_team_role_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<button
				type="button"
				id="nxtcc-team-access-add-member"
				class="nxtcc-button nxtcc-button-success nxtcc-size-sm"
				<?php disabled( ! $nxtcc_team_tenant_ready ); ?>
			>
				<?php esc_html_e( '+ Add Member', 'nxt-cloud-chat' ); ?>
			</button>
		</div>
	</div>

	<?php if ( '' !== $nxtcc_team_role_text ) : ?>
		<div class="nxtcc-team-access-inline-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: list of eligible WordPress roles */
					__( 'Only these WordPress roles can be assigned to tenant access: %s. Additional site administrators are not granted NXT Cloud Chat access until you assign them here.', 'nxt-cloud-chat' ),
					$nxtcc_team_role_text
				)
			);
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! $nxtcc_team_tenant_ready ) : ?>
		<div class="nxtcc-alert nxtcc-alert-warning">
			<?php esc_html_e( 'Save the tenant connection first. Team Access becomes available after the primary tenant is configured.', 'nxt-cloud-chat' ); ?>
		</div>
	<?php else : ?>
		<?php if ( empty( $nxtcc_team_available_users ) ) : ?>
			<div class="nxtcc-team-access-inline-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: list of eligible WordPress roles */
						__( 'Only these WordPress roles can be assigned: %s. All eligible users are already assigned or no eligible staff users exist yet.', 'nxt-cloud-chat' ),
						$nxtcc_team_role_text
					)
				);
				?>
			</div>
		<?php endif; ?>

		<div class="nxtcc-team-access-table-wrap">
			<table class="nxtcc-team-access-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'WordPress Role', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Access Role', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $nxtcc_team_access_members ) ) : ?>
						<tr class="nxtcc-team-access-empty-row">
							<td colspan="6"><?php esc_html_e( 'No users have tenant access yet.', 'nxt-cloud-chat' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $nxtcc_team_access_members as $nxtcc_team_member ) : ?>
							<?php
							$nxtcc_team_member_payload = array(
								'user_id'             => isset( $nxtcc_team_member['user_id'] ) ? (int) $nxtcc_team_member['user_id'] : 0,
								'display_name'        => isset( $nxtcc_team_member['display_name'] ) ? (string) $nxtcc_team_member['display_name'] : '',
								'user_email'          => isset( $nxtcc_team_member['user_email'] ) ? (string) $nxtcc_team_member['user_email'] : '',
								'user_login'          => isset( $nxtcc_team_member['user_login'] ) ? (string) $nxtcc_team_member['user_login'] : '',
								'roles_display'       => isset( $nxtcc_team_member['roles_display'] ) ? (string) $nxtcc_team_member['roles_display'] : '',
								'role_key'            => isset( $nxtcc_team_member['role_key'] ) ? (string) $nxtcc_team_member['role_key'] : 'custom',
								'capabilities'        => isset( $nxtcc_team_member['capabilities'] ) && is_array( $nxtcc_team_member['capabilities'] ) ? array_values( $nxtcc_team_member['capabilities'] ) : array(),
								'is_owner'            => ! empty( $nxtcc_team_member['is_owner'] ),
								'wp_role_eligible'    => ! empty( $nxtcc_team_member['wp_role_eligible'] ),
								'wp_role_status_note' => isset( $nxtcc_team_member['wp_role_status_note'] ) ? (string) $nxtcc_team_member['wp_role_status_note'] : '',
							);
							$nxtcc_team_payload_json   = wp_json_encode( $nxtcc_team_member_payload );
							$nxtcc_team_payload_json   = is_string( $nxtcc_team_payload_json ) ? $nxtcc_team_payload_json : '{}';
							?>
							<tr
								class="<?php echo empty( $nxtcc_team_member['wp_role_eligible'] ) ? 'is-inactive' : ''; ?>"
								data-team-role="<?php echo esc_attr( isset( $nxtcc_team_member['role_key'] ) ? (string) $nxtcc_team_member['role_key'] : 'custom' ); ?>"
								data-team-search="<?php echo esc_attr( isset( $nxtcc_team_member['search_text'] ) ? (string) $nxtcc_team_member['search_text'] : '' ); ?>"
							>
								<td>
									<div class="nxtcc-team-access-user">
										<strong><?php echo esc_html( isset( $nxtcc_team_member['display_name'] ) ? (string) $nxtcc_team_member['display_name'] : '' ); ?></strong>
										<span><?php echo esc_html( isset( $nxtcc_team_member['user_email'] ) ? (string) $nxtcc_team_member['user_email'] : '' ); ?></span>
										<?php if ( ! empty( $nxtcc_team_member['user_login'] ) ) : ?>
											<code><?php echo esc_html( (string) $nxtcc_team_member['user_login'] ); ?></code>
										<?php endif; ?>
									</div>
								</td>
								<td>
									<span class="nxtcc-team-access-inline-text">
										<?php echo esc_html( isset( $nxtcc_team_member['roles_display'] ) ? (string) $nxtcc_team_member['roles_display'] : '' ); ?>
									</span>
									<?php if ( ! empty( $nxtcc_team_member['wp_role_status_note'] ) ) : ?>
										<span class="nxtcc-team-access-role-warning">
											<?php echo esc_html( (string) $nxtcc_team_member['wp_role_status_note'] ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<div class="nxtcc-team-access-role-inline">
										<span class="nxtcc-team-access-role-pill<?php echo ! empty( $nxtcc_team_member['is_owner'] ) ? ' is-owner' : ''; ?>">
											<?php echo esc_html( isset( $nxtcc_team_member['role_label'] ) ? (string) $nxtcc_team_member['role_label'] : '' ); ?>
										</span>
										<span class="nxtcc-team-access-role-note">
											<?php echo esc_html( isset( $nxtcc_team_member['role_note'] ) ? (string) $nxtcc_team_member['role_note'] : '' ); ?>
										</span>
									</div>
								</td>
								<td>
									<div class="nxtcc-team-access-permissions">
										<?php foreach ( ( isset( $nxtcc_team_member['capability_preview'] ) && is_array( $nxtcc_team_member['capability_preview'] ) ) ? $nxtcc_team_member['capability_preview'] : array() as $nxtcc_team_cap_label ) : ?>
											<span class="nxtcc-team-access-chip"><?php echo esc_html( (string) $nxtcc_team_cap_label ); ?></span>
										<?php endforeach; ?>
										<?php if ( ! empty( $nxtcc_team_member['extra_capability_count'] ) ) : ?>
											<span class="nxtcc-team-access-chip is-muted">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: extra capabilities count */
														__( '+%d more', 'nxt-cloud-chat' ),
														(int) $nxtcc_team_member['extra_capability_count']
													)
												);
												?>
											</span>
										<?php endif; ?>
									</div>
								</td>
								<td>
									<?php echo esc_html( isset( $nxtcc_team_member['updated_at_display'] ) ? (string) $nxtcc_team_member['updated_at_display'] : '' ); ?>
								</td>
								<td>
									<div class="nxtcc-team-access-actions">
										<?php if ( ! empty( $nxtcc_team_member['is_owner'] ) ) : ?>
											<span class="nxtcc-team-access-owner-note"><?php esc_html_e( 'Owner access is locked', 'nxt-cloud-chat' ); ?></span>
										<?php else : ?>
											<button
												type="button"
												class="nxtcc-button nxtcc-button-light nxtcc-size-sm nxtcc-team-access-edit"
												data-member="<?php echo esc_attr( $nxtcc_team_payload_json ); ?>"
											>
												<?php esc_html_e( 'Edit', 'nxt-cloud-chat' ); ?>
											</button>

											<form method="post" action="" class="nxtcc-team-access-remove-form">
												<?php wp_nonce_field( 'nxtcc_team_access_save', 'nxtcc_team_access_nonce' ); ?>
												<input type="hidden" name="nxtcc_settings_active_tab" value="team-access">
												<input type="hidden" name="nxtcc_team_access_action" value="remove">
												<input type="hidden" name="nxtcc_team_user_id" value="<?php echo esc_attr( (string) $nxtcc_team_member['user_id'] ); ?>">
												<button type="submit" class="nxtcc-button-warning nxtcc-size-sm">
													<?php esc_html_e( 'Remove', 'nxt-cloud-chat' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>

					<tr class="nxtcc-team-access-empty-row is-filtered" hidden>
						<td colspan="6"><?php esc_html_e( 'No team members match the current filters.', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<?php if ( $nxtcc_team_tenant_ready ) : ?>
	<div id="nxtcc-team-access-modal" class="nxtcc-team-access-modal" hidden>
		<div class="nxtcc-team-access-modal-inner" role="dialog" aria-modal="true" aria-labelledby="nxtcc-team-access-modal-title">
			<div class="nxtcc-team-access-modal-form">
				<button type="button" class="nxtcc-team-access-modal-close" id="nxtcc-team-access-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'nxt-cloud-chat' ); ?>">
					&times;
				</button>

				<h3 class="nxtcc-heading-title" id="nxtcc-team-access-modal-title"><?php esc_html_e( 'Add Team Member', 'nxt-cloud-chat' ); ?></h3>

				<form method="post" action="" id="nxtcc-team-access-form">
					<?php wp_nonce_field( 'nxtcc_team_access_save', 'nxtcc_team_access_nonce' ); ?>
					<input type="hidden" name="nxtcc_settings_active_tab" value="team-access">
					<input type="hidden" name="nxtcc_team_access_action" id="nxtcc_team_access_action" value="add">
					<input type="hidden" name="nxtcc_team_role_key" id="nxtcc_team_role_key" value="custom">

					<div class="nxtcc-team-access-field-group" id="nxtcc-team-access-user-picker-group">
						<label for="nxtcc_team_user_id_modal"><?php esc_html_e( 'WordPress User', 'nxt-cloud-chat' ); ?></label>
						<select id="nxtcc_team_user_id_modal" name="nxtcc_team_user_id" class="nxtcc-team-access-field">
							<option value=""><?php esc_html_e( 'Select a user', 'nxt-cloud-chat' ); ?></option>
							<?php foreach ( $nxtcc_team_available_users as $nxtcc_team_user ) : ?>
								<option value="<?php echo esc_attr( (string) $nxtcc_team_user['ID'] ); ?>">
									<?php
									echo esc_html(
										sprintf(
											'%1$s (%2$s) - %3$s',
											(string) $nxtcc_team_user['display_name'],
											(string) $nxtcc_team_user['user_email'],
											isset( $nxtcc_team_user['roles'] ) && is_array( $nxtcc_team_user['roles'] ) && ! empty( $nxtcc_team_user['roles'] )
												? implode( ', ', $nxtcc_team_user['roles'] )
												: __( 'No WordPress role', 'nxt-cloud-chat' )
										)
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p
							class="nxtcc-team-access-field-note"
							id="nxtcc-team-access-user-picker-note"
							<?php echo empty( $nxtcc_team_available_users ) ? '' : 'hidden'; ?>
						>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: list of eligible WordPress roles */
									__( 'Only these WordPress roles can be assigned: %s. Create or update an eligible WordPress user if the list is empty.', 'nxt-cloud-chat' ),
									$nxtcc_team_role_text
								)
							);
							?>
						</p>
					</div>

					<div class="nxtcc-team-access-field-group" id="nxtcc-team-access-user-summary-group" hidden>
						<label><?php esc_html_e( 'Team Member', 'nxt-cloud-chat' ); ?></label>
						<div class="nxtcc-team-access-user-preview">
							<strong id="nxtcc-team-access-user-preview-name"></strong>
							<span id="nxtcc-team-access-user-preview-email"></span>
							<small id="nxtcc-team-access-user-preview-roles"></small>
							<small id="nxtcc-team-access-user-preview-note" class="nxtcc-team-access-role-warning" hidden></small>
						</div>
					</div>

					<div class="nxtcc-team-access-field-group">
						<label for="nxtcc_team_role_preset"><?php esc_html_e( 'Access Role', 'nxt-cloud-chat' ); ?></label>
						<select id="nxtcc_team_role_preset" class="nxtcc-team-access-field">
							<?php foreach ( $nxtcc_team_role_presets as $nxtcc_team_role_key => $nxtcc_team_role_meta ) : ?>
								<option value="<?php echo esc_attr( (string) $nxtcc_team_role_key ); ?>">
									<?php echo esc_html( isset( $nxtcc_team_role_meta['label'] ) ? (string) $nxtcc_team_role_meta['label'] : (string) $nxtcc_team_role_key ); ?>
								</option>
							<?php endforeach; ?>
							<option value="custom"><?php esc_html_e( 'Custom', 'nxt-cloud-chat' ); ?></option>
						</select>
						<p class="nxtcc-team-access-field-note" id="nxtcc-team-access-role-description"></p>
					</div>

					<div class="nxtcc-team-access-field-group">
						<label><?php esc_html_e( 'Permissions', 'nxt-cloud-chat' ); ?></label>
						<p class="nxtcc-team-access-field-note" id="nxtcc-team-access-capability-note">
							<?php esc_html_e( 'Select Custom to edit the exact permissions for this tenant. Preset roles keep the mapped permissions locked.', 'nxt-cloud-chat' ); ?>
						</p>
						<div class="nxtcc-team-access-capabilities" id="nxtcc-team-access-capabilities">
							<table class="nxtcc-team-access-capability-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Allow', 'nxt-cloud-chat' ); ?></th>
										<th><?php esc_html_e( 'Permission', 'nxt-cloud-chat' ); ?></th>
										<th><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $nxtcc_team_cap_sections as $nxtcc_team_section ) : ?>
										<?php
										if ( ! is_array( $nxtcc_team_section ) || empty( $nxtcc_team_section['capabilities'] ) ) {
											continue;
										}
										?>
										<tr class="nxtcc-team-access-capability-group-row">
											<td colspan="3">
												<?php echo esc_html( isset( $nxtcc_team_section['label'] ) ? (string) $nxtcc_team_section['label'] : '' ); ?>
											</td>
										</tr>
										<?php foreach ( $nxtcc_team_section['capabilities'] as $nxtcc_team_capability_key => $nxtcc_team_capability_meta ) : ?>
											<tr>
												<td class="nxtcc-team-access-capability-check">
													<input
														type="checkbox"
														name="nxtcc_team_caps[]"
														value="<?php echo esc_attr( (string) $nxtcc_team_capability_key ); ?>"
														data-cap-label="<?php echo esc_attr( isset( $nxtcc_team_capability_meta['label'] ) ? (string) $nxtcc_team_capability_meta['label'] : (string) $nxtcc_team_capability_key ); ?>"
													>
												</td>
												<td>
													<strong><?php echo esc_html( isset( $nxtcc_team_capability_meta['label'] ) ? (string) $nxtcc_team_capability_meta['label'] : (string) $nxtcc_team_capability_key ); ?></strong>
												</td>
												<td>
													<?php echo esc_html( isset( $nxtcc_team_capability_meta['description'] ) ? (string) $nxtcc_team_capability_meta['description'] : '' ); ?>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<div class="nxtcc-button-wrapper">
						<button type="button" class="nxtcc-button-warning" id="nxtcc-team-access-cancel">
							<?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?>
						</button>
						<button type="submit" class="nxtcc-button nxtcc-button-success" id="nxtcc-team-access-submit">
							<?php esc_html_e( 'Save Access', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</form>
			</div>

			<div class="nxtcc-team-access-modal-side">
				<div class="nxtcc-team-access-side-card">
					<span class="nxtcc-team-access-side-label"><?php esc_html_e( 'Access Summary', 'nxt-cloud-chat' ); ?></span>
					<strong class="nxtcc-team-access-side-title" id="nxtcc-team-access-summary-role"><?php esc_html_e( 'Viewer', 'nxt-cloud-chat' ); ?></strong>
					<p class="nxtcc-team-access-side-copy" id="nxtcc-team-access-summary-copy"></p>
					<div class="nxtcc-team-access-side-chips" id="nxtcc-team-access-summary-chips"></div>
				</div>

				<div class="nxtcc-team-access-side-card">
					<span class="nxtcc-team-access-side-label"><?php esc_html_e( 'Owner Only', 'nxt-cloud-chat' ); ?></span>
					<ul class="nxtcc-team-access-owner-list">
						<?php foreach ( $nxtcc_team_owner_caps as $nxtcc_team_owner_cap_meta ) : ?>
							<?php if ( ! is_array( $nxtcc_team_owner_cap_meta ) || empty( $nxtcc_team_owner_cap_meta['label'] ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<li>
								<strong><?php echo esc_html( (string) $nxtcc_team_owner_cap_meta['label'] ); ?></strong>
								<span><?php echo esc_html( isset( $nxtcc_team_owner_cap_meta['description'] ) ? (string) $nxtcc_team_owner_cap_meta['description'] : '' ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>
	</div>

	<script type="application/json" id="nxtcc-team-access-boot"><?php echo wp_kses( $nxtcc_team_boot_json, array() ); ?></script>
<?php endif; ?>
