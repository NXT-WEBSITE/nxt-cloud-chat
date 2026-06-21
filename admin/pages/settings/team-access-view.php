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

$nxtcc_team_scope_labels = array(
	'assigned' => __( 'Assigned only', 'nxt-cloud-chat' ),
	'team'     => __( 'Assigned and team queue', 'nxt-cloud-chat' ),
	'all'      => __( 'All tenant records', 'nxt-cloud-chat' ),
);

$nxtcc_team_permission_label_overrides = array(
	'dashboard'              => __( 'Dashboard', 'nxt-cloud-chat' ),
	'chat_window'            => __( 'Chat Window', 'nxt-cloud-chat' ),
	'assignments'            => __( 'Assignments', 'nxt-cloud-chat' ),
	'reassign_conversations' => __( 'Reassign Conversations', 'nxt-cloud-chat' ),
	'resolve_conversations'  => __( 'Resolve Conversations', 'nxt-cloud-chat' ),
	'crm_notes'              => __( 'Internal Notes', 'nxt-cloud-chat' ),
	'crm_activity'           => __( 'CRM Activity', 'nxt-cloud-chat' ),
	'crm_tasks'              => __( 'CRM Tasks', 'nxt-cloud-chat' ),
	'lifecycle_stages'       => __( 'Lifecycle Stages', 'nxt-cloud-chat' ),
	'pipelines'              => __( 'Sales Pipelines', 'nxt-cloud-chat' ),
);

$nxtcc_team_permission_order = array_flip(
	array(
		'dashboard',
		'chat_window',
		'assignments',
		'reassign_conversations',
		'resolve_conversations',
		'crm_notes',
		'contacts',
		'groups',
		'tags',
		'crm_activity',
		'crm_tasks',
		'lifecycle_stages',
		'deals',
		'pipelines',
		'history',
		'authentication',
		'templates',
		'broadcasts',
		'segments',
		'abandoned_carts',
		'workflows',
		'workflow_runs',
	)
);

$nxtcc_team_permission_rows         = array();
$nxtcc_team_permission_seen_caps    = array();
$nxtcc_team_permission_row_index    = 0;
$nxtcc_team_capability_to_matrix    = static function ( $nxtcc_capability_key ) {
	$nxtcc_capability_key = (string) $nxtcc_capability_key;

	if ( 0 === strpos( $nxtcc_capability_key, 'nxtcc_view_' ) ) {
		return array( substr( $nxtcc_capability_key, strlen( 'nxtcc_view_' ) ), 'view' );
	}

	if ( 0 === strpos( $nxtcc_capability_key, 'nxtcc_manage_' ) ) {
		return array( substr( $nxtcc_capability_key, strlen( 'nxtcc_manage_' ) ), 'manage' );
	}

	$nxtcc_direct_map = array(
		'nxtcc_access_dashboard'       => array( 'dashboard', 'view' ),
		'nxtcc_access_chat'            => array( 'chat_window', 'view' ),
		'nxtcc_reassign_conversations' => array( 'reassign_conversations', 'manage' ),
		'nxtcc_resolve_conversations'  => array( 'resolve_conversations', 'manage' ),
	);

	return isset( $nxtcc_direct_map[ $nxtcc_capability_key ] )
		? $nxtcc_direct_map[ $nxtcc_capability_key ]
		: array( $nxtcc_capability_key, 'manage' );
};
$nxtcc_team_format_permission_label = static function ( $nxtcc_row_key, $nxtcc_fallback_label ) use ( $nxtcc_team_permission_label_overrides ) {
	$nxtcc_row_key = (string) $nxtcc_row_key;

	if ( isset( $nxtcc_team_permission_label_overrides[ $nxtcc_row_key ] ) ) {
		return $nxtcc_team_permission_label_overrides[ $nxtcc_row_key ];
	}

	$nxtcc_label = preg_replace( '/^(View|Manage)\s+/i', '', (string) $nxtcc_fallback_label );
	$nxtcc_label = is_string( $nxtcc_label ) ? trim( $nxtcc_label ) : '';

	return '' !== $nxtcc_label ? $nxtcc_label : ucwords( str_replace( '_', ' ', $nxtcc_row_key ) );
};
$nxtcc_team_add_permission_cap      = static function ( $nxtcc_capability_key, $nxtcc_capability_meta, $nxtcc_section_label = '' ) use ( &$nxtcc_team_permission_rows, &$nxtcc_team_permission_seen_caps, &$nxtcc_team_permission_row_index, $nxtcc_team_capability_to_matrix, $nxtcc_team_format_permission_label ) {
	$nxtcc_capability_key = (string) $nxtcc_capability_key;

	if ( isset( $nxtcc_team_permission_seen_caps[ $nxtcc_capability_key ] ) ) {
		return;
	}

	if ( ! is_array( $nxtcc_capability_meta ) || ! empty( $nxtcc_capability_meta['owner_only'] ) ) {
		return;
	}

	list( $nxtcc_row_key, $nxtcc_column ) = $nxtcc_team_capability_to_matrix( $nxtcc_capability_key );

	$nxtcc_label       = isset( $nxtcc_capability_meta['label'] ) ? (string) $nxtcc_capability_meta['label'] : $nxtcc_capability_key;
	$nxtcc_description = isset( $nxtcc_capability_meta['description'] ) ? (string) $nxtcc_capability_meta['description'] : '';

	if ( ! isset( $nxtcc_team_permission_rows[ $nxtcc_row_key ] ) ) {
		$nxtcc_team_permission_rows[ $nxtcc_row_key ] = array(
			'key'          => $nxtcc_row_key,
			'label'        => $nxtcc_team_format_permission_label( $nxtcc_row_key, $nxtcc_label ),
			'description'  => $nxtcc_description,
			'section'      => (string) $nxtcc_section_label,
			'view_cap'     => '',
			'manage_cap'   => '',
			'view_label'   => '',
			'manage_label' => '',
			'order'        => $nxtcc_team_permission_row_index,
		);
		++$nxtcc_team_permission_row_index;
	}

	$nxtcc_capability_label_key = 'manage' === $nxtcc_column ? 'manage_label' : 'view_label';
	$nxtcc_capability_cap_key   = 'manage' === $nxtcc_column ? 'manage_cap' : 'view_cap';

	$nxtcc_team_permission_rows[ $nxtcc_row_key ][ $nxtcc_capability_cap_key ]   = $nxtcc_capability_key;
	$nxtcc_team_permission_rows[ $nxtcc_row_key ][ $nxtcc_capability_label_key ] = $nxtcc_label;

	if ( 'manage' === $nxtcc_column || '' === (string) $nxtcc_team_permission_rows[ $nxtcc_row_key ]['description'] ) {
		$nxtcc_team_permission_rows[ $nxtcc_row_key ]['description'] = $nxtcc_description;
	}

	$nxtcc_team_permission_seen_caps[ $nxtcc_capability_key ] = true;
};

foreach ( $nxtcc_team_cap_sections as $nxtcc_team_section ) {
	if ( ! is_array( $nxtcc_team_section ) || empty( $nxtcc_team_section['capabilities'] ) || ! is_array( $nxtcc_team_section['capabilities'] ) ) {
		continue;
	}

	$nxtcc_team_section_label = isset( $nxtcc_team_section['label'] ) ? (string) $nxtcc_team_section['label'] : '';

	foreach ( $nxtcc_team_section['capabilities'] as $nxtcc_capability_key => $nxtcc_capability_meta ) {
		$nxtcc_team_add_permission_cap( $nxtcc_capability_key, $nxtcc_capability_meta, $nxtcc_team_section_label );
	}
}

foreach ( $nxtcc_team_capabilities as $nxtcc_capability_key => $nxtcc_capability_meta ) {
	$nxtcc_team_add_permission_cap( $nxtcc_capability_key, $nxtcc_capability_meta );
}

$nxtcc_team_permission_rows = array_values( $nxtcc_team_permission_rows );
usort(
	$nxtcc_team_permission_rows,
	static function ( $nxtcc_left, $nxtcc_right ) use ( $nxtcc_team_permission_order ) {
		$nxtcc_left_key  = isset( $nxtcc_left['key'] ) ? (string) $nxtcc_left['key'] : '';
		$nxtcc_right_key = isset( $nxtcc_right['key'] ) ? (string) $nxtcc_right['key'] : '';
		$nxtcc_left_pos  = isset( $nxtcc_team_permission_order[ $nxtcc_left_key ] ) ? (int) $nxtcc_team_permission_order[ $nxtcc_left_key ] : 999;
		$nxtcc_right_pos = isset( $nxtcc_team_permission_order[ $nxtcc_right_key ] ) ? (int) $nxtcc_team_permission_order[ $nxtcc_right_key ] : 999;

		if ( $nxtcc_left_pos === $nxtcc_right_pos ) {
			return (int) ( $nxtcc_left['order'] ?? 0 ) <=> (int) ( $nxtcc_right['order'] ?? 0 );
		}

		return $nxtcc_left_pos <=> $nxtcc_right_pos;
	}
);

$nxtcc_render_permission_matrix = static function ( $nxtcc_args ) use ( $nxtcc_team_permission_rows, $nxtcc_team_scope_labels ) {
	$nxtcc_args          = is_array( $nxtcc_args ) ? $nxtcc_args : array();
	$nxtcc_name          = isset( $nxtcc_args['name'] ) ? (string) $nxtcc_args['name'] : 'nxtcc_team_caps[]';
	$nxtcc_scope_name    = isset( $nxtcc_args['scope_name'] ) ? (string) $nxtcc_args['scope_name'] : '';
	$nxtcc_id_prefix     = isset( $nxtcc_args['id_prefix'] ) ? sanitize_key( (string) $nxtcc_args['id_prefix'] ) : 'nxtcc_perm';
	$nxtcc_selected_caps = isset( $nxtcc_args['selected'] ) && is_array( $nxtcc_args['selected'] ) ? array_map( 'strval', $nxtcc_args['selected'] ) : array();
	$nxtcc_scope         = isset( $nxtcc_args['scope'] ) ? (string) $nxtcc_args['scope'] : 'all';
	$nxtcc_scope_map     = isset( $nxtcc_args['scope_map'] ) && is_array( $nxtcc_args['scope_map'] )
		? NXTCC_Access_Teams::sanitize_capability_scopes( $nxtcc_args['scope_map'], $nxtcc_selected_caps, $nxtcc_scope )
		: array();
	$nxtcc_action_level  = isset( $nxtcc_args['action_level'] ) ? (string) $nxtcc_args['action_level'] : 'manage';
	$nxtcc_manage_active = 'manage' === $nxtcc_action_level;
	$nxtcc_readonly      = ! empty( $nxtcc_args['readonly'] );
	$nxtcc_row_scope_for = static function ( $nxtcc_view_cap, $nxtcc_manage_cap ) use ( $nxtcc_selected_caps, $nxtcc_scope_map, $nxtcc_scope, $nxtcc_manage_active ) {
		$nxtcc_view_cap   = (string) $nxtcc_view_cap;
		$nxtcc_manage_cap = (string) $nxtcc_manage_cap;

		if ( $nxtcc_manage_active && '' !== $nxtcc_manage_cap && in_array( $nxtcc_manage_cap, $nxtcc_selected_caps, true ) && isset( $nxtcc_scope_map[ $nxtcc_manage_cap ] ) ) {
			return $nxtcc_scope_map[ $nxtcc_manage_cap ];
		}

		if ( '' !== $nxtcc_view_cap && in_array( $nxtcc_view_cap, $nxtcc_selected_caps, true ) && isset( $nxtcc_scope_map[ $nxtcc_view_cap ] ) ) {
			return $nxtcc_scope_map[ $nxtcc_view_cap ];
		}

		if ( '' !== $nxtcc_manage_cap && isset( $nxtcc_scope_map[ $nxtcc_manage_cap ] ) ) {
			return $nxtcc_scope_map[ $nxtcc_manage_cap ];
		}

		if ( '' !== $nxtcc_view_cap && isset( $nxtcc_scope_map[ $nxtcc_view_cap ] ) ) {
			return $nxtcc_scope_map[ $nxtcc_view_cap ];
		}

		return $nxtcc_scope;
	};
	?>
	<div class="nxtcc-team-access-permission-matrix">
		<table class="nxtcc-team-access-permission-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Permission', 'nxt-cloud-chat' ); ?></th>
					<th class="nxtcc-team-access-check-col"><?php esc_html_e( 'View', 'nxt-cloud-chat' ); ?></th>
					<th class="nxtcc-team-access-check-col"><?php esc_html_e( 'Manage', 'nxt-cloud-chat' ); ?></th>
					<th><?php esc_html_e( 'Data Scope', 'nxt-cloud-chat' ); ?></th>
					<th><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $nxtcc_team_permission_rows as $nxtcc_row ) : ?>
					<?php
					$nxtcc_row_key    = isset( $nxtcc_row['key'] ) ? (string) $nxtcc_row['key'] : '';
					$nxtcc_view_cap   = isset( $nxtcc_row['view_cap'] ) ? (string) $nxtcc_row['view_cap'] : '';
					$nxtcc_manage_cap = isset( $nxtcc_row['manage_cap'] ) ? (string) $nxtcc_row['manage_cap'] : '';
					$nxtcc_view_id    = $nxtcc_id_prefix . '_' . sanitize_key( $nxtcc_row_key ) . '_view';
					$nxtcc_manage_id  = $nxtcc_id_prefix . '_' . sanitize_key( $nxtcc_row_key ) . '_manage';
					$nxtcc_row_scope  = NXTCC_Access_Teams::sanitize_data_scope( (string) $nxtcc_row_scope_for( $nxtcc_view_cap, $nxtcc_manage_cap ) );
					/* translators: %s: permission label */
					$nxtcc_view_aria = sprintf( __( 'View %s', 'nxt-cloud-chat' ), (string) $nxtcc_row['label'] );
					/* translators: %s: permission label */
					$nxtcc_manage_aria = sprintf( __( 'Manage %s', 'nxt-cloud-chat' ), (string) $nxtcc_row['label'] );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( isset( $nxtcc_row['label'] ) ? (string) $nxtcc_row['label'] : $nxtcc_row_key ); ?></strong>
							<?php if ( ! empty( $nxtcc_row['section'] ) ) : ?>
								<span><?php echo esc_html( (string) $nxtcc_row['section'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="nxtcc-team-access-permission-check">
							<?php if ( '' !== $nxtcc_view_cap ) : ?>
								<input
									id="<?php echo esc_attr( $nxtcc_view_id ); ?>"
									type="checkbox"
									class="nxtcc-team-access-permission-input nxtcc-team-access-permission-view"
									name="<?php echo esc_attr( $nxtcc_name ); ?>"
									value="<?php echo esc_attr( $nxtcc_view_cap ); ?>"
									data-cap-label="<?php echo esc_attr( isset( $nxtcc_row['view_label'] ) && '' !== (string) $nxtcc_row['view_label'] ? (string) $nxtcc_row['view_label'] : (string) $nxtcc_row['label'] ); ?>"
									data-manage-cap="<?php echo esc_attr( $nxtcc_manage_cap ); ?>"
									aria-label="<?php echo esc_attr( $nxtcc_view_aria ); ?>"
									<?php checked( in_array( $nxtcc_view_cap, $nxtcc_selected_caps, true ) ); ?>
									<?php disabled( $nxtcc_readonly ); ?>
								>
							<?php else : ?>
								<span class="nxtcc-team-access-empty-permission">-</span>
							<?php endif; ?>
						</td>
						<td class="nxtcc-team-access-permission-check">
							<?php if ( '' !== $nxtcc_manage_cap ) : ?>
								<input
									id="<?php echo esc_attr( $nxtcc_manage_id ); ?>"
									type="checkbox"
									class="nxtcc-team-access-permission-input nxtcc-team-access-permission-manage"
									name="<?php echo esc_attr( $nxtcc_name ); ?>"
									value="<?php echo esc_attr( $nxtcc_manage_cap ); ?>"
									data-cap-label="<?php echo esc_attr( isset( $nxtcc_row['manage_label'] ) && '' !== (string) $nxtcc_row['manage_label'] ? (string) $nxtcc_row['manage_label'] : (string) $nxtcc_row['label'] ); ?>"
									data-view-cap="<?php echo esc_attr( $nxtcc_view_cap ); ?>"
									aria-label="<?php echo esc_attr( $nxtcc_manage_aria ); ?>"
									<?php checked( $nxtcc_manage_active && in_array( $nxtcc_manage_cap, $nxtcc_selected_caps, true ) ); ?>
									<?php disabled( $nxtcc_readonly ); ?>
								>
							<?php else : ?>
								<span class="nxtcc-team-access-empty-permission">-</span>
							<?php endif; ?>
						</td>
						<td>
							<select
								class="nxtcc-team-access-field nxtcc-team-access-row-scope"
								data-view-cap="<?php echo esc_attr( $nxtcc_view_cap ); ?>"
								data-manage-cap="<?php echo esc_attr( $nxtcc_manage_cap ); ?>"
								<?php disabled( $nxtcc_readonly ); ?>
							>
								<?php foreach ( $nxtcc_team_scope_labels as $nxtcc_scope_key => $nxtcc_scope_label ) : ?>
									<option value="<?php echo esc_attr( (string) $nxtcc_scope_key ); ?>" <?php selected( $nxtcc_row_scope, (string) $nxtcc_scope_key ); ?>>
										<?php echo esc_html( (string) $nxtcc_scope_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( '' !== $nxtcc_scope_name && '' !== $nxtcc_view_cap ) : ?>
								<input
									type="hidden"
									class="nxtcc-team-access-row-scope-input"
									name="<?php echo esc_attr( $nxtcc_scope_name . '[' . $nxtcc_view_cap . ']' ); ?>"
									value="<?php echo esc_attr( $nxtcc_row_scope ); ?>"
									data-capability="<?php echo esc_attr( $nxtcc_view_cap ); ?>"
								>
							<?php endif; ?>
							<?php if ( '' !== $nxtcc_scope_name && '' !== $nxtcc_manage_cap ) : ?>
								<input
									type="hidden"
									class="nxtcc-team-access-row-scope-input"
									name="<?php echo esc_attr( $nxtcc_scope_name . '[' . $nxtcc_manage_cap . ']' ); ?>"
									value="<?php echo esc_attr( $nxtcc_row_scope ); ?>"
									data-capability="<?php echo esc_attr( $nxtcc_manage_cap ); ?>"
								>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $nxtcc_row['description'] ) ? (string) $nxtcc_row['description'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
};

$nxtcc_team_boot = array(
	'defaultRole' => 'custom',
	'rolePresets' => $nxtcc_team_role_presets,
	'strings'     => array(
		'addTitle'       => __( 'Add Team Member', 'nxt-cloud-chat' ),
		'editTitle'      => __( 'Update Team Access', 'nxt-cloud-chat' ),
		'customRoleDesc' => __( 'Choose the exact capabilities this member should have inside the current tenant.', 'nxt-cloud-chat' ),
		'removeConfirm'  => __( 'Remove this team member from the current tenant?', 'nxt-cloud-chat' ),
		/* translators: %s: access team name */
		'deleteConfirm'  => __( 'Delete access team "%s"? This cannot be undone.', 'nxt-cloud-chat' ),
		'userRequired'   => __( 'Select a WordPress user before saving access.', 'nxt-cloud-chat' ),
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
					class="nxtcc-team-access-filter-input nxtcc-team-access-filter-search nxtcc-ui-filter-control"
					placeholder="<?php echo esc_attr__( 'Search user, email, or permission', 'nxt-cloud-chat' ); ?>"
				/>

				<span class="nxtcc-ui-filter-select-wrap nxtcc-team-access-filter-role">
					<select id="nxtcc-team-access-role-filter" class="nxtcc-team-access-filter-input nxtcc-ui-filter-control">
						<option value=""><?php esc_html_e( 'All Teams', 'nxt-cloud-chat' ); ?></option>
						<?php foreach ( $nxtcc_team_role_filter as $nxtcc_team_role_key => $nxtcc_team_role_label ) : ?>
							<option value="<?php echo esc_attr( (string) $nxtcc_team_role_key ); ?>">
								<?php echo esc_html( (string) $nxtcc_team_role_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</span>
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
						<th><?php esc_html_e( 'Record Access', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'WordPress Role', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Team', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'nxt-cloud-chat' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nxt-cloud-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $nxtcc_team_access_members ) ) : ?>
						<tr class="nxtcc-team-access-empty-row">
							<td colspan="7"><?php esc_html_e( 'No users have tenant access yet.', 'nxt-cloud-chat' ); ?></td>
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
								'action_level'        => isset( $nxtcc_team_member['action_level'] ) ? (string) $nxtcc_team_member['action_level'] : 'manage',
								'data_scope'          => isset( $nxtcc_team_member['data_scope'] ) ? (string) $nxtcc_team_member['data_scope'] : 'all',
								'capability_scopes'   => isset( $nxtcc_team_member['capability_scopes'] ) && is_array( $nxtcc_team_member['capability_scopes'] ) ? $nxtcc_team_member['capability_scopes'] : array(),
								'assignment_eligible' => ! empty( $nxtcc_team_member['assignment_eligible'] ),
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
									<div class="nxtcc-team-access-permissions">
										<span class="nxtcc-team-access-chip">
											<?php echo esc_html( 'manage' === (string) $nxtcc_team_member['action_level'] ? __( 'Manage', 'nxt-cloud-chat' ) : __( 'View only', 'nxt-cloud-chat' ) ); ?>
										</span>
										<span class="nxtcc-team-access-chip is-muted">
											<?php
											$nxtcc_team_scope_summary = isset( $nxtcc_team_member['scope_summary'] ) ? (string) $nxtcc_team_member['scope_summary'] : (string) ( $nxtcc_team_member['data_scope'] ?? 'assigned' );
											echo esc_html(
												'mixed' === $nxtcc_team_scope_summary
													? __( 'Mixed per permission', 'nxt-cloud-chat' )
													: ( $nxtcc_team_scope_labels[ $nxtcc_team_scope_summary ] ?? __( 'Assigned only', 'nxt-cloud-chat' ) )
											);
											?>
										</span>
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
						<td colspan="7"><?php esc_html_e( 'No team members match the current filters.', 'nxt-cloud-chat' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<?php if ( $nxtcc_team_tenant_ready ) : ?>
	<div class="nxtcc-team-access-widget nxtcc-team-access-teams-widget">
		<div class="nxtcc-team-access-toolbar">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Teams', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-team-access-subtitle">
					<?php esc_html_e( 'Edit the default record scope and permissions inherited by members using each access team.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
			<button type="button" id="nxtcc-team-access-new-team" class="nxtcc-button nxtcc-button-success nxtcc-size-sm">
				<?php esc_html_e( '+ New Team', 'nxt-cloud-chat' ); ?>
			</button>
		</div>

		<div class="nxtcc-team-access-team-list">
			<?php
			$nxtcc_new_access_team_template = array(
				'action_level'        => 'view_only',
				'data_scope'          => 'assigned',
				'capability_scopes'   => array(),
				'assignment_eligible' => true,
				'capabilities'        => array(),
			);
			?>
			<div class="nxtcc-team-access-team-create" id="nxtcc-team-access-team-create" hidden>
				<form method="post" action="" class="nxtcc-team-access-team-form">
					<?php wp_nonce_field( 'nxtcc_team_access_save', 'nxtcc_team_access_nonce' ); ?>
					<input type="hidden" name="nxtcc_settings_active_tab" value="team-access">
					<input type="hidden" name="nxtcc_team_access_action" value="access_team_create">
					<input type="hidden" class="nxtcc-team-access-action-field" id="nxtcc_access_team_action_new" name="nxtcc_access_team_action_level" value="<?php echo esc_attr( (string) ( $nxtcc_new_access_team_template['action_level'] ?? 'view_only' ) ); ?>">
					<input type="hidden" class="nxtcc-team-access-master-scope" id="nxtcc_access_team_scope_new" name="nxtcc_access_team_data_scope" value="<?php echo esc_attr( (string) ( $nxtcc_new_access_team_template['data_scope'] ?? 'assigned' ) ); ?>">

					<div class="nxtcc-team-access-field-group">
						<label for="nxtcc_access_team_label_new"><?php esc_html_e( 'Team Name', 'nxt-cloud-chat' ); ?></label>
						<input class="nxtcc-team-access-field" id="nxtcc_access_team_label_new" name="nxtcc_access_team_label" placeholder="<?php echo esc_attr__( 'Example: Sales Support', 'nxt-cloud-chat' ); ?>" required>
					</div>

					<div class="nxtcc-team-access-field-group">
						<label for="nxtcc_access_team_description_new"><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></label>
						<input class="nxtcc-team-access-field" id="nxtcc_access_team_description_new" name="nxtcc_access_team_description" placeholder="<?php echo esc_attr__( 'Short note shown when selecting this access team', 'nxt-cloud-chat' ); ?>">
					</div>

					<label class="nxtcc-team-access-toggle">
						<input type="checkbox" name="nxtcc_access_team_assignment_eligible" value="1" <?php checked( ! empty( $nxtcc_new_access_team_template['assignment_eligible'] ) ); ?>>
						<span><?php esc_html_e( 'Members using this access team can receive contact and chat assignments', 'nxt-cloud-chat' ); ?></span>
					</label>

					<?php
					$nxtcc_render_permission_matrix(
						array(
							'name'         => 'nxtcc_access_team_caps[]',
							'scope_name'   => 'nxtcc_access_team_capability_scopes',
							'id_prefix'    => 'nxtcc_access_team_new',
							'selected'     => isset( $nxtcc_new_access_team_template['capabilities'] ) && is_array( $nxtcc_new_access_team_template['capabilities'] ) ? $nxtcc_new_access_team_template['capabilities'] : array(),
							'scope'        => isset( $nxtcc_new_access_team_template['data_scope'] ) ? (string) $nxtcc_new_access_team_template['data_scope'] : 'assigned',
							'scope_map'    => isset( $nxtcc_new_access_team_template['capability_scopes'] ) && is_array( $nxtcc_new_access_team_template['capability_scopes'] ) ? $nxtcc_new_access_team_template['capability_scopes'] : array(),
							'action_level' => isset( $nxtcc_new_access_team_template['action_level'] ) ? (string) $nxtcc_new_access_team_template['action_level'] : 'view_only',
						)
					);
					?>

					<div class="nxtcc-button-wrapper">
						<button type="button" class="nxtcc-button-warning nxtcc-size-sm" id="nxtcc-team-access-team-create-cancel"><?php esc_html_e( 'Cancel', 'nxt-cloud-chat' ); ?></button>
						<button type="submit" class="nxtcc-button nxtcc-button-success nxtcc-size-sm"><?php esc_html_e( 'Create Team', 'nxt-cloud-chat' ); ?></button>
					</div>
				</form>
			</div>

			<?php foreach ( $nxtcc_team_role_presets as $nxtcc_access_team_key => $nxtcc_access_team ) : ?>
				<details class="nxtcc-team-access-team">
					<summary>
						<strong><?php echo esc_html( (string) ( $nxtcc_access_team['label'] ?? $nxtcc_access_team_key ) ); ?></strong>
						<span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: action level, 2: data scope */
									__( '%1$s, %2$s scope', 'nxt-cloud-chat' ),
									'manage' === (string) ( $nxtcc_access_team['action_level'] ?? '' ) ? __( 'Manage', 'nxt-cloud-chat' ) : __( 'View only', 'nxt-cloud-chat' ),
									(string) ( $nxtcc_access_team['data_scope'] ?? 'all' )
								)
							);
							?>
						</span>
					</summary>

					<form method="post" action="" class="nxtcc-team-access-team-form">
						<?php wp_nonce_field( 'nxtcc_team_access_save', 'nxtcc_team_access_nonce' ); ?>
						<input type="hidden" name="nxtcc_settings_active_tab" value="team-access">
						<input type="hidden" name="nxtcc_team_access_action" value="access_team_update">
						<input type="hidden" name="nxtcc_access_team_key" value="<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>">
						<input type="hidden" class="nxtcc-team-access-action-field" id="nxtcc_access_team_action_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>" name="nxtcc_access_team_action_level" value="<?php echo esc_attr( (string) ( $nxtcc_access_team['action_level'] ?? 'manage' ) ); ?>">
						<input type="hidden" class="nxtcc-team-access-master-scope" id="nxtcc_access_team_scope_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>" name="nxtcc_access_team_data_scope" value="<?php echo esc_attr( (string) ( $nxtcc_access_team['data_scope'] ?? 'all' ) ); ?>">

						<div class="nxtcc-team-access-field-group">
							<label for="nxtcc_access_team_label_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>"><?php esc_html_e( 'Team Name', 'nxt-cloud-chat' ); ?></label>
							<input class="nxtcc-team-access-field" id="nxtcc_access_team_label_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>" name="nxtcc_access_team_label" value="<?php echo esc_attr( (string) ( $nxtcc_access_team['label'] ?? '' ) ); ?>" required>
						</div>

						<div class="nxtcc-team-access-field-group">
							<label for="nxtcc_access_team_description_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>"><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></label>
							<input class="nxtcc-team-access-field" id="nxtcc_access_team_description_<?php echo esc_attr( (string) $nxtcc_access_team_key ); ?>" name="nxtcc_access_team_description" value="<?php echo esc_attr( (string) ( $nxtcc_access_team['description'] ?? '' ) ); ?>">
						</div>

						<label class="nxtcc-team-access-toggle">
							<input type="checkbox" name="nxtcc_access_team_assignment_eligible" value="1" <?php checked( ! empty( $nxtcc_access_team['assignment_eligible'] ) ); ?>>
							<span><?php esc_html_e( 'Members using this access team can receive contact and chat assignments', 'nxt-cloud-chat' ); ?></span>
						</label>

						<?php
						$nxtcc_render_permission_matrix(
							array(
								'name'         => 'nxtcc_access_team_caps[]',
								'scope_name'   => 'nxtcc_access_team_capability_scopes',
								'id_prefix'    => 'nxtcc_access_team_' . (string) $nxtcc_access_team_key,
								'selected'     => isset( $nxtcc_access_team['capabilities'] ) && is_array( $nxtcc_access_team['capabilities'] ) ? $nxtcc_access_team['capabilities'] : array(),
								'scope'        => isset( $nxtcc_access_team['data_scope'] ) ? (string) $nxtcc_access_team['data_scope'] : 'all',
								'scope_map'    => isset( $nxtcc_access_team['capability_scopes'] ) && is_array( $nxtcc_access_team['capability_scopes'] ) ? $nxtcc_access_team['capability_scopes'] : array(),
								'action_level' => isset( $nxtcc_access_team['action_level'] ) ? (string) $nxtcc_access_team['action_level'] : 'manage',
							)
						);
						?>

						<div class="nxtcc-button-wrapper">
							<button type="submit" class="nxtcc-button nxtcc-button-success"><?php esc_html_e( 'Save Team', 'nxt-cloud-chat' ); ?></button>
							<button
								type="submit"
								name="nxtcc_delete_access_team"
								value="1"
								class="nxtcc-button nxtcc-button-danger nxtcc-team-access-team-delete"
								data-team-name="<?php echo esc_attr( (string) ( $nxtcc_access_team['label'] ?? $nxtcc_access_team_key ) ); ?>"
							>
								<?php esc_html_e( 'Delete Team', 'nxt-cloud-chat' ); ?>
							</button>
						</div>
					</form>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

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
					<input type="hidden" class="nxtcc-team-access-action-field" id="nxtcc_team_action_level" name="nxtcc_team_action_level" value="manage">
					<input type="hidden" class="nxtcc-team-access-master-scope" id="nxtcc_team_data_scope" name="nxtcc_team_data_scope" value="all">

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
						<label for="nxtcc_team_role_preset"><?php esc_html_e( 'Access Team', 'nxt-cloud-chat' ); ?></label>
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
							<?php
							$nxtcc_render_permission_matrix(
								array(
									'name'       => 'nxtcc_team_caps[]',
									'scope_name' => 'nxtcc_team_capability_scopes',
									'id_prefix'  => 'nxtcc_team_member',
									'selected'   => array(),
									'scope'      => 'all',
								)
							);
							?>
						</div>
					</div>

					<label class="nxtcc-team-access-toggle">
						<input type="checkbox" id="nxtcc_team_assignment_eligible" name="nxtcc_team_assignment_eligible" value="1">
						<span><?php esc_html_e( 'This member can receive contact and chat assignments', 'nxt-cloud-chat' ); ?></span>
					</label>

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
		</div>
	</div>

	<script type="application/json" id="nxtcc-team-access-boot"><?php echo wp_kses( $nxtcc_team_boot_json, array() ); ?></script>
<?php endif; ?>
