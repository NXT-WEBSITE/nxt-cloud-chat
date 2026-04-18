<?php
/**
 * Connection settings module view.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

$nxtcc_delete_on_uninstall = (int) get_option( 'nxtcc_delete_data_on_uninstall' );
?>

<div class="nxtcc-settings-connection">
	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Connection Settings', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Connect the active tenant with your WhatsApp Cloud API credentials.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="" autocomplete="off" novalidate class="nxtcc-settings-card-form">
			<?php wp_nonce_field( 'nxtcc_settings_save', 'nxtcc_settings_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_connection_settings">
			<input type="hidden" name="nxtcc_settings_active_tab" value="connection">

			<div class="nxtcc-settings-grid">
				<div class="nxtcc-settings-field">
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

				<div class="nxtcc-settings-field">
					<label for="nxtcc_app_secret">
						<?php echo esc_html__( 'App Secret', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
					</label>
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

				<div class="nxtcc-settings-field nxtcc-settings-field-span-2">
					<label for="nxtcc_access_token">
						<?php echo esc_html__( 'Access Token', 'nxt-cloud-chat' ); ?> <span class="nxtcc-required">*</span>
					</label>
					<div class="nxtcc-settings-inline-control">
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
							class="nxtcc-button nxtcc-button-light"
							id="nxtcc_toggle_token_visibility"
							aria-controls="nxtcc_access_token"
							aria-label="<?php echo esc_attr__( 'Show or hide access token', 'nxt-cloud-chat' ); ?>"
						>
							<?php echo esc_html__( 'Show', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
					<p class="nxtcc-settings-field-note">
						<?php echo esc_html__( 'Access tokens are never prefilled and are encrypted before saving.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<div class="nxtcc-settings-field">
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

				<div class="nxtcc-settings-field">
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

				<div class="nxtcc-settings-field nxtcc-settings-field-span-2">
					<label for="nxtcc_phone_number"><?php echo esc_html__( 'Phone Number', 'nxt-cloud-chat' ); ?></label>
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

				<div class="nxtcc-settings-field nxtcc-settings-field-span-2 nxtcc-settings-checkbox-field">
					<label class="nxtcc-settings-checkbox-label" for="nxtcc_meta_webhook_subscribed">
						<input
							type="checkbox"
							id="nxtcc_meta_webhook_subscribed"
							name="nxtcc_meta_webhook_subscribed"
							value="1"
							<?php checked( $nxtcc_meta_webhook_sub, 1 ); ?>
						/>
						<span><?php echo esc_html__( 'Webhook (Incoming)', 'nxt-cloud-chat' ); ?></span>
					</label>
					<p class="nxtcc-settings-field-note">
						<?php echo esc_html__( 'Enable webhook verification and incoming event handling for the active tenant.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<div class="nxtcc-settings-field nxtcc-settings-field-span-2">
					<label for="nxtcc-callback-url-input"><?php echo esc_html__( 'Callback URL', 'nxt-cloud-chat' ); ?></label>
					<div class="nxtcc-settings-inline-control">
						<input
							type="text"
							id="nxtcc-callback-url-input"
							class="nxtcc-settings-readonly-field"
							value="<?php echo esc_attr( $nxtcc_callback_url ); ?>"
							readonly
						/>
						<button type="button" class="nxtcc-button nxtcc-button-light" id="nxtcc_copy_callback_url">
							<?php echo esc_html__( 'Copy', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
				</div>

				<div class="nxtcc-settings-field nxtcc-settings-field-span-2">
					<label for="nxtcc_meta_webhook_verify_token"><?php echo esc_html__( 'Verify Token', 'nxt-cloud-chat' ); ?></label>
					<div class="nxtcc-settings-inline-control nxtcc-settings-inline-control-multi">
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
							class="nxtcc-button nxtcc-button-light"
							id="nxtcc_generate_token"
							<?php echo $nxtcc_meta_webhook_sub ? '' : 'disabled'; ?>
						>
							<?php echo esc_html__( 'Generate', 'nxt-cloud-chat' ); ?>
						</button>
						<button
							type="button"
							class="nxtcc-button nxtcc-button-light"
							id="nxtcc_copy_verify_token"
							<?php echo $nxtcc_meta_webhook_sub ? '' : 'disabled'; ?>
						>
							<?php echo esc_html__( 'Copy', 'nxt-cloud-chat' ); ?>
						</button>
					</div>
					<p class="nxtcc-settings-field-note">
						<?php echo esc_html__( 'Required when webhook verification is enabled. Generate a token here and paste it into Meta.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>
			</div>

			<div class="nxtcc-settings-actions">
				<button type="submit" class="nxtcc-button nxtcc-button-primary" name="nxtcc_save_settings">
					<?php echo esc_html__( 'Save Connection Settings', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</form>
	</section>

	<section class="nxtcc-settings-section">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Connection Diagnostics', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Run the existing connection checks without re-entering encrypted credentials.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<div class="nxtcc-settings-diagnostics-grid">
			<div class="nxtcc-settings-field">
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

			<div class="nxtcc-settings-field">
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

			<div class="nxtcc-settings-field">
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
		</div>

		<div class="nxtcc-settings-actions">
			<button type="button" id="nxtcc-check-connections" class="nxtcc-button nxtcc-button-primary">
				<?php echo esc_html__( 'Check All Connections', 'nxt-cloud-chat' ); ?>
			</button>
		</div>

		<div id="nxtcc-connection-results" class="nxtcc-connection-results" aria-live="polite">
			<?php if ( ! empty( $nxtcc_connection_results ) ) : ?>
				<div class="nxtcc-connection-results-panel">
					<table class="nxtcc-connection-results-table">
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
									<td>
										<span class="nxtcc-settings-status-pill<?php echo esc_attr( $nxtcc_ok ? ' is-success' : ' is-fail' ); ?>">
											<?php echo esc_html( $nxtcc_ok ? __( 'Pass', 'nxt-cloud-chat' ) : __( 'Fail', 'nxt-cloud-chat' ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $nxtcc_detail ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="nxtcc-settings-section nxtcc-settings-section-compact">
		<div class="nxtcc-settings-section-header">
			<div>
				<h3 class="nxtcc-heading-title"><?php esc_html_e( 'Delete on Uninstall', 'nxt-cloud-chat' ); ?></h3>
				<p class="nxtcc-settings-section-description">
					<?php esc_html_e( 'Control whether NXT Cloud Chat data should be removed when the Free plugin is uninstalled.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="" class="nxtcc-settings-card-form">
			<?php wp_nonce_field( 'nxtcc_settings_uninstall_save', 'nxtcc_settings_uninstall_nonce' ); ?>
			<input type="hidden" name="nxtcc_settings_action" value="save_uninstall_settings">
			<input type="hidden" name="nxtcc_settings_active_tab" value="connection">

			<div class="nxtcc-settings-field nxtcc-settings-checkbox-field">
				<label class="nxtcc-settings-checkbox-label" for="nxtcc_delete_data_on_uninstall">
					<input
						type="checkbox"
						id="nxtcc_delete_data_on_uninstall"
						name="nxtcc_delete_data_on_uninstall"
						value="1"
						<?php checked( $nxtcc_delete_on_uninstall, 1 ); ?>
					/>
					<span><?php echo esc_html__( 'Delete all data on uninstall', 'nxt-cloud-chat' ); ?></span>
				</label>
				<p class="nxtcc-settings-field-note">
					<?php esc_html_e( 'This applies only when the Free plugin is uninstalled from the site.', 'nxt-cloud-chat' ); ?>
				</p>
			</div>

			<div class="nxtcc-settings-actions">
				<button type="submit" class="nxtcc-button nxtcc-button-light" name="nxtcc_save_uninstall_settings">
					<?php echo esc_html__( 'Save Uninstall Preference', 'nxt-cloud-chat' ); ?>
				</button>
			</div>
		</form>
	</section>
</div>
