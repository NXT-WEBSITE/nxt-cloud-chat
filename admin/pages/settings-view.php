<?php
/**
 * Admin settings view.
 *
 * Renders the Settings screen UI for NXT Cloud Chat.
 * This file is a view template and expects variables to be prepared by the
 * corresponding controller before being included.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

// Capability guard (defense-in-depth; the menu should already enforce this).
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access these settings.', 'nxt-cloud-chat' ) );
}

// Ensure vars exist to avoid notices (set by controller before including this view).
$nxtcc_app_id              = isset( $app_id ) ? (string) $app_id : '';
$nxtcc_phone_number_id     = isset( $phone_number_id ) ? (string) $phone_number_id : '';
$nxtcc_business_account_id = isset( $business_account_id ) ? (string) $business_account_id : '';
$nxtcc_phone_number        = isset( $phone_number ) ? (string) $phone_number : '';
$nxtcc_meta_webhook_sub    = ! empty( $meta_webhook_subscribed ) ? 1 : 0;
$nxtcc_callback_url        = isset( $callback_url ) ? (string) $callback_url : '';
$nxtcc_connection_results  = ( isset( $connection_results ) && is_array( $connection_results ) ) ? $connection_results : array();

// Nonce for AJAX (read by JS).
$nxtcc_ajax_nonce = wp_create_nonce( 'nxtcc_admin_ajax' );
?>

<div class="nxtcc-settings-widget">
	<?php settings_errors( 'nxtcc_settings' ); ?>
	<input type="hidden" id="nxtcc_admin_ajax_nonce" value="<?php echo esc_attr( $nxtcc_ajax_nonce ); ?>">

	<div class="nxtcc-settings-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'NXTCC Settings Tabs', 'nxt-cloud-chat' ); ?>">
		<button
			class="nxtcc-settings-tab active"
			id="tab-connection"
			data-tab="connection"
			role="tab"
			aria-selected="true"
			aria-controls="panel-connection"
		>
			<?php echo esc_html__( 'Connection', 'nxt-cloud-chat' ); ?>
		</button>
		<button
			class="nxtcc-settings-tab"
			id="tab-tools"
			data-tab="tools"
			role="tab"
			aria-selected="false"
			aria-controls="panel-tools"
		>
			<?php echo esc_html__( 'Tools', 'nxt-cloud-chat' ); ?>
		</button>
	</div>

	<!-- User Guide Link -->
	<p style="margin: 1px 25px;">
		<a href="https://nxtcloudchat.com/user-guide" target="_blank" class="nxtcc-user-guide-link">
			<?php echo esc_html__( 'User Guide: Setting Up & Connecting', 'nxt-cloud-chat' ); ?>
		</a>
	</p>

	<!-- Connection tab -->
	<div
		class="nxtcc-settings-tab-content"
		id="panel-connection"
		role="tabpanel"
		aria-labelledby="tab-connection"
		data-tab="connection"
		style="display:block"
	>
		<form method="post" action="" autocomplete="off" novalidate>
			<?php // CSRF. ?>
			<?php wp_nonce_field( 'nxtcc_settings_save', 'nxtcc_settings_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_connection_settings">

			<div class="nxtcc-form-row">
				<label for="nxtcc_app_id">
					<?php echo esc_html__( 'App ID', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
				</label>
				<input
					type="text"
					id="nxtcc_app_id"
					name="nxtcc_app_id"
					value="<?php echo esc_attr( $nxtcc_app_id ); ?>"
					required
					inputmode="numeric"
					pattern="[0-9]{6,}"
					maxlength="64"
					autocomplete="off"
				/>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_app_secret">
					<?php echo esc_html__( 'App Secret', 'nxt-cloud-chat' ); ?>
				</label>
				<div class="nxtcc-inline-row nxtcc-token-inline">
					<input
						type="password"
						id="nxtcc_app_secret"
						name="nxtcc_app_secret"
						value=""
						placeholder="<?php echo esc_attr__( 'Paste Meta App Secret for webhook verification', 'nxt-cloud-chat' ); ?>"
						autocomplete="new-password"
						spellcheck="false"
					/>
				</div>
				<p class="description">
					<?php echo esc_html__( 'Required when Webhook (Incoming) is enabled. Used to verify X-Hub-Signature-256 on webhook POST payloads. Stored encrypted and never prefilled.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_access_token">
					<?php echo esc_html__( 'Access Token', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
				</label>
				<div class="nxtcc-inline-row nxtcc-token-inline">
					<input
						type="password"
						id="nxtcc_access_token"
						name="nxtcc_access_token"
						value=""
						placeholder="<?php echo esc_attr__( 'Paste a new or refreshed token', 'nxt-cloud-chat' ); ?>"
						required
						autocomplete="new-password"
						spellcheck="false"
					/>
					<button
						type="button"
						class="nxtcc-btn-outline"
						id="nxtcc_toggle_token_visibility"
						aria-controls="nxtcc_access_token"
						aria-label="<?php echo esc_attr__( 'Show/Hide token', 'nxt-cloud-chat' ); ?>"
					>
						<?php echo esc_html__( 'Show', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
				<p class="description">
					<?php echo esc_html__( 'For security, the token field is never prefilled. It is encrypted before saving.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_phone_number_id">
					<?php echo esc_html__( 'Phone Number ID', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
				</label>
				<input
					type="text"
					id="nxtcc_phone_number_id"
					name="nxtcc_phone_number_id"
					value="<?php echo esc_attr( $nxtcc_phone_number_id ); ?>"
					required
					maxlength="255"
					autocomplete="off"
				/>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_whatsapp_business_account_id">
					<?php echo esc_html__( 'Business Account ID', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
				</label>
				<input
					type="text"
					id="nxtcc_whatsapp_business_account_id"
					name="nxtcc_whatsapp_business_account_id"
					value="<?php echo esc_attr( $nxtcc_business_account_id ); ?>"
					required
					maxlength="255"
					autocomplete="off"
				/>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_phone_number">
					<?php echo esc_html__( 'Phone Number (display only)', 'nxt-cloud-chat' ); ?>
				</label>
				<input
					type="text"
					id="nxtcc_phone_number"
					name="nxtcc_phone_number"
					value="<?php echo esc_attr( $nxtcc_phone_number ); ?>"
					inputmode="tel"
					maxlength="30"
					placeholder="+1XXXXXXXXXX"
					autocomplete="off"
					pattern="^\+?[0-9][0-9\s\-()]{4,}$"
				/>
			</div>

			<div class="nxtcc-form-row">
				<label>
					<input
						type="checkbox"
						id="nxtcc_meta_webhook_subscribed"
						name="nxtcc_meta_webhook_subscribed"
						value="1"
						<?php checked( $nxtcc_meta_webhook_sub, 1 ); ?>
					/>
					<?php echo esc_html__( 'Webhook (Incoming)', 'nxt-cloud-chat' ); ?>
				</label>

				<?php if ( $nxtcc_meta_webhook_sub ) : ?>
					<div class="nxtcc-description" id="nxtcc-meta-callback-desc">
						<br>
						<label><?php echo esc_html__( 'Callback URL:', 'nxt-cloud-chat' ); ?></label>

						<div>
							<input
								type="text"
								id="nxtcc-callback-url-input"
								class="nxtcc-text-field"
								value="<?php echo esc_attr( $nxtcc_callback_url ); ?>"
								readonly
								style="width: 720px; max-width: 450px; margin-top: 4px;"
							/>

							<button
								type="button"
								class="nxtcc-btn-outline"
								id="nxtcc_copy_callback_url"
							>
								<?php echo esc_html__( 'Copy', 'nxt-cloud-chat' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="nxtcc-form-row">
				<label for="nxtcc_meta_webhook_verify_token">
					<?php echo esc_html__( 'Verify Token', 'nxt-cloud-chat' ); ?>
				</label>
				<div class="nxtcc-inline-row nxtcc-token-inline">
					<input
						type="text"
						id="nxtcc_meta_webhook_verify_token"
						name="nxtcc_meta_webhook_verify_token"
						value=""
						placeholder="<?php echo esc_attr__( 'Set or update verify token', 'nxt-cloud-chat' ); ?>"
						<?php disabled( ! $nxtcc_meta_webhook_sub ); ?>
						autocomplete="off"
						spellcheck="false"
					/>
					<button
						type="button"
						class="nxtcc-btn-outline"
						id="nxtcc_generate_token"
						<?php echo $nxtcc_meta_webhook_sub ? '' : 'disabled'; ?>
					>
						<?php echo esc_html__( 'Generate', 'nxt-cloud-chat' ); ?>
					</button>
					<button
						type="button"
						class="nxtcc-btn-outline"
						id="nxtcc_copy_verify_token"
						<?php echo $nxtcc_meta_webhook_sub ? '' : 'disabled'; ?>
					>
						<?php echo esc_html__( 'Copy', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
				<p class="description">
					<?php echo esc_html__( 'This is a random token for webhook configuration. Copy and paste it into the WhatsApp webhook configuration.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-form-row">
				<label>
					<input
						type="checkbox"
						id="nxtcc_delete_data_on_uninstall"
						name="nxtcc_delete_data_on_uninstall"
						value="1"
						<?php checked( (int) get_option( 'nxtcc_delete_data_on_uninstall' ), 1 ); ?>
					/>
					<?php echo esc_html__( 'Delete all data on uninstall', 'nxt-cloud-chat' ); ?>
				</label>
			</div>

			<div class="nxtcc-modal-footer">
				<button type="submit" class="nxtcc-btn-green" name="nxtcc_save_settings">
					<?php echo esc_html__( 'Save Settings', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</form>

		<hr>
		<h3><?php echo esc_html__( 'Connection Diagnostics', 'nxt-cloud-chat' ); ?></h3>

		<div class="nxtcc-form-row">
			<label for="nxtcc_test_number"><?php echo esc_html__( 'Test Number', 'nxt-cloud-chat' ); ?></label>
			<input
				type="text"
				id="nxtcc_test_number"
				name="nxtcc_test_number"
				placeholder="919xxxxxxxxx"
				inputmode="tel"
				maxlength="20"
				autocomplete="off"
			/>
		</div>

		<div class="nxtcc-form-row">
			<label for="nxtcc_test_template"><?php echo esc_html__( 'Template Name', 'nxt-cloud-chat' ); ?></label>
			<input
				type="text"
				id="nxtcc_test_template"
				name="nxtcc_test_template"
				placeholder="hello_world"
				maxlength="128"
				autocomplete="off"
			/>
		</div>

		<div class="nxtcc-form-row">
			<label for="nxtcc_test_language"><?php echo esc_html__( 'Template Language', 'nxt-cloud-chat' ); ?></label>
			<input
				type="text"
				id="nxtcc_test_language"
				name="nxtcc_test_language"
				value="en_US"
				maxlength="10"
				autocomplete="off"
			/>
		</div>

		<div class="nxtcc-modal-footer">
			<button type="button" id="nxtcc-check-connections" class="nxtcc-btn">
				<?php echo esc_html__( 'Check All Connections', 'nxt-cloud-chat' ); ?>
			</button>
		</div>

		<div id="nxtcc-connection-results" class="nxtcc-connection-results"></div>

		<?php if ( ! empty( $nxtcc_connection_results ) ) : ?>
			<h3><?php echo esc_html__( 'Connection Status:', 'nxt-cloud-chat' ); ?></h3>
			<div class="nxtcc-connection-results">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Check', 'nxt-cloud-chat' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'nxt-cloud-chat' ); ?></th>
							<th><?php echo esc_html__( 'Details', 'nxt-cloud-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $nxtcc_connection_results as $nxtcc_label => $nxtcc_info ) : ?>
							<?php
							$nxtcc_ok     = ! empty( $nxtcc_info['success'] );
							$nxtcc_detail = isset( $nxtcc_info['error'] )
								? (string) $nxtcc_info['error']
								: ( $nxtcc_ok ? esc_html__( 'OK', 'nxt-cloud-chat' ) : '' );
							?>
							<tr>
								<td><?php echo esc_html( $nxtcc_label ); ?></td>
								<td class="<?php echo esc_attr( $nxtcc_ok ? 'nxtcc-status-ok' : 'nxtcc-status-fail' ); ?>">
									<?php echo esc_html( $nxtcc_ok ? '✅' : '❌' ); ?>
								</td>
								<td><?php echo esc_html( $nxtcc_detail ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

	</div>

	<!-- Tools tab -->
	<div
		class="nxtcc-settings-tab-content"
		id="panel-tools"
		role="tabpanel"
		aria-labelledby="tab-tools"
		data-tab="tools"
		style="display:none"
	>
		<p><?php echo esc_html__( 'Coming soon...', 'nxt-cloud-chat' ); ?></p>
	</div>
</div>
