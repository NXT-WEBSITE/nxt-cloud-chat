<?php
/**
 * Admin settings view.
 *
 * Renders the shared settings shell for NXT Cloud Chat.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( NXTCC_Access_Control::access_settings_capability() ) ) {
	wp_die( esc_html__( 'You do not have permission to access these settings.', 'nxt-cloud-chat' ) );
}

$nxtcc_app_id               = isset( $app_id ) ? (string) $app_id : '';
$nxtcc_phone_number_id      = isset( $phone_number_id ) ? (string) $phone_number_id : '';
$nxtcc_business_account_id  = isset( $business_account_id ) ? (string) $business_account_id : '';
$nxtcc_phone_number         = isset( $phone_number ) ? (string) $phone_number : '';
$nxtcc_meta_webhook_sub     = ! empty( $meta_webhook_subscribed ) ? 1 : 0;
$nxtcc_callback_url         = isset( $callback_url ) ? (string) $callback_url : '';
$nxtcc_connection_results   = ( isset( $connection_results ) && is_array( $connection_results ) ) ? $connection_results : array();
$nxtcc_can_manage_settings  = ! empty( $nxtcc_can_manage_settings );
$nxtcc_can_manage_team      = ! empty( $nxtcc_can_manage_team_access );
$nxtcc_active_tab_key       = isset( $nxtcc_active_tab ) ? sanitize_key( (string) $nxtcc_active_tab ) : '';
$nxtcc_primary_tenant       = ( isset( $nxtcc_primary_tenant ) && is_array( $nxtcc_primary_tenant ) ) ? $nxtcc_primary_tenant : array();
$nxtcc_team_caps_catalog    = ( isset( $nxtcc_team_capabilities ) && is_array( $nxtcc_team_capabilities ) ) ? $nxtcc_team_capabilities : array();
$nxtcc_team_cap_sections    = ( isset( $nxtcc_team_capability_sections ) && is_array( $nxtcc_team_capability_sections ) ) ? $nxtcc_team_capability_sections : array();
$nxtcc_team_owner_caps      = ( isset( $nxtcc_team_owner_only_capabilities ) && is_array( $nxtcc_team_owner_only_capabilities ) ) ? $nxtcc_team_owner_only_capabilities : array();
$nxtcc_team_role_presets    = ( isset( $nxtcc_team_role_presets ) && is_array( $nxtcc_team_role_presets ) ) ? $nxtcc_team_role_presets : array();
$nxtcc_team_access_rows     = ( isset( $nxtcc_team_access_rows ) && is_array( $nxtcc_team_access_rows ) ) ? $nxtcc_team_access_rows : array();
$nxtcc_team_access_users    = ( isset( $nxtcc_team_access_users ) && is_array( $nxtcc_team_access_users ) ) ? $nxtcc_team_access_users : array();
$nxtcc_team_available_users = ( isset( $nxtcc_team_available_users ) && is_array( $nxtcc_team_available_users ) ) ? $nxtcc_team_available_users : array();
$nxtcc_team_access_members  = ( isset( $nxtcc_team_access_members ) && is_array( $nxtcc_team_access_members ) ) ? $nxtcc_team_access_members : array();

$nxtcc_settings_tabs = array();

if ( $nxtcc_can_manage_settings ) {
	$nxtcc_settings_tabs['connection'] = __( 'Connection', 'nxt-cloud-chat' );
	$nxtcc_settings_tabs['tools']      = __( 'Tools', 'nxt-cloud-chat' );
}

if ( $nxtcc_can_manage_team ) {
	$nxtcc_settings_tabs['team-access'] = __( 'Team Access', 'nxt-cloud-chat' );
}

if ( '' === $nxtcc_active_tab_key || ! isset( $nxtcc_settings_tabs[ $nxtcc_active_tab_key ] ) ) {
	$nxtcc_active_tab_key = ! empty( $nxtcc_settings_tabs ) ? (string) array_key_first( $nxtcc_settings_tabs ) : 'connection';
}

$nxtcc_ajax_nonce = wp_create_nonce( 'nxtcc_admin_ajax' );
?>

<div class="nxtcc-settings-widget">
	<?php settings_errors( 'nxtcc_settings' ); ?>
	<input type="hidden" id="nxtcc_admin_ajax_nonce" value="<?php echo esc_attr( $nxtcc_ajax_nonce ); ?>">

	<div class="nxtcc-settings-header">
		<div class="nxtcc-settings-heading">
			<h2 class="nxtcc-settings-title"><?php esc_html_e( 'Settings', 'nxt-cloud-chat' ); ?></h2>
			<p class="nxtcc-settings-subtitle">
				<?php esc_html_e( 'Manage the active tenant connection and tenant access from one place.', 'nxt-cloud-chat' ); ?>
			</p>
		</div>

		<a href="https://nxtcloudchat.com/user-guide" target="_blank" rel="noreferrer noopener" class="nxtcc-settings-help-link">
			<?php echo esc_html__( 'User Guide', 'nxt-cloud-chat' ); ?>
		</a>
	</div>

	<div class="nxtcc-settings-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'NXTCC Settings Tabs', 'nxt-cloud-chat' ); ?>">
		<?php foreach ( $nxtcc_settings_tabs as $nxtcc_tab_key => $nxtcc_tab_label ) : ?>
			<button
				class="nxtcc-settings-tab<?php echo $nxtcc_active_tab_key === $nxtcc_tab_key ? ' active' : ''; ?>"
				id="tab-<?php echo esc_attr( $nxtcc_tab_key ); ?>"
				data-tab="<?php echo esc_attr( $nxtcc_tab_key ); ?>"
				role="tab"
				aria-selected="<?php echo esc_attr( $nxtcc_active_tab_key === $nxtcc_tab_key ? 'true' : 'false' ); ?>"
				aria-controls="panel-<?php echo esc_attr( $nxtcc_tab_key ); ?>"
			>
				<?php echo esc_html( $nxtcc_tab_label ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<?php if ( $nxtcc_can_manage_settings ) : ?>
		<div
			class="nxtcc-settings-tab-content"
			id="panel-connection"
			role="tabpanel"
			aria-labelledby="tab-connection"
			data-tab="connection"
			style="<?php echo esc_attr( 'connection' === $nxtcc_active_tab_key ? 'display:block' : 'display:none' ); ?>"
		>
			<?php include __DIR__ . '/settings/connection-view.php'; ?>
		</div>
	<?php endif; ?>

	<?php if ( $nxtcc_can_manage_settings ) : ?>
		<div
			class="nxtcc-settings-tab-content"
			id="panel-tools"
			role="tabpanel"
			aria-labelledby="tab-tools"
			data-tab="tools"
			style="<?php echo esc_attr( 'tools' === $nxtcc_active_tab_key ? 'display:block' : 'display:none' ); ?>"
		>
			<?php include __DIR__ . '/settings/tools-view.php'; ?>
		</div>
	<?php endif; ?>

	<?php if ( $nxtcc_can_manage_team ) : ?>
		<div
			class="nxtcc-settings-tab-content"
			id="panel-team-access"
			role="tabpanel"
			aria-labelledby="tab-team-access"
			data-tab="team-access"
			style="<?php echo esc_attr( 'team-access' === $nxtcc_active_tab_key ? 'display:block' : 'display:none' ); ?>"
		>
			<?php include __DIR__ . '/settings/team-access-view.php'; ?>
		</div>
	<?php endif; ?>
</div>
