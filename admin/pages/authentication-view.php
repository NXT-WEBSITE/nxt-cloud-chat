<?php
/**
 * Authentication Admin View.
 *
 * Renders the Authentication settings page in WP Admin.
 *
 * This view is UI-only (template). It fetches data using a filter-backed DAO
 * to avoid mixing raw DB access inside markup.
 *
 * Notes:
 * - No direct $wpdb calls inside HTML output blocks.
 * - Latest settings are fetched via a filter-backed DAO.
 * - Encrypted token fields are treated as the source of truth.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * DAO (Filter-backed): Get latest WhatsApp API settings for the given admin email.
 *
 * This filter returns the most recent row from `{$wpdb->prefix}nxtcc_user_settings`
 * for a given `user_mailid`.
 *
 * @param mixed  $nxtcc_unused      Unused first parameter (filter convention).
 * @param string $nxtcc_user_mailid Admin email.
 * @return array|null Latest settings row or null.
 */
if ( ! has_filter( 'nxtcc_db_latest_settings_for_user' ) ) {
	add_filter(
		'nxtcc_db_latest_settings_for_user',
		function ( $nxtcc_unused, $nxtcc_user_mailid ) {
			$nxtcc_user_mailid = sanitize_email( (string) $nxtcc_user_mailid );
			if ( '' === $nxtcc_user_mailid ) {
				return null;
			}

			if ( ! class_exists( 'NXTCC_Settings_DAO' ) ) {
				return null;
			}

			$nxtcc_row = NXTCC_Settings_DAO::get_latest_for_user( $nxtcc_user_mailid );
			if ( ! is_object( $nxtcc_row ) ) {
				return null;
			}

			$nxtcc_row_array = get_object_vars( $nxtcc_row );
			return is_array( $nxtcc_row_array ) ? $nxtcc_row_array : null;
		},
		10,
		2
	);
}

/*
------------------------------------------------------------------------- *
 * Current admin + saved auth policy
 * -------------------------------------------------------------------------
 */

$nxtcc_current_user = wp_get_current_user();
$nxtcc_user_mailid  = $nxtcc_current_user instanceof WP_User ? (string) ( $nxtcc_current_user->user_email ?? '' ) : '';
$nxtcc_user_mailid  = sanitize_email( $nxtcc_user_mailid );

/**
 * Latest saved settings for this admin (via DAO filter).
 *
 * @var array|null
 */
$nxtcc_settings = apply_filters( 'nxtcc_db_latest_settings_for_user', null, $nxtcc_user_mailid );

/**
 * Eligibility: require connection + webhook.
 *
 * Uses encrypted token fields as the source of truth.
 *
 * @var bool
 */
$nxtcc_has_connection = is_array( $nxtcc_settings )
	&& ! empty( $nxtcc_settings['app_id'] )
	&& ! empty( $nxtcc_settings['business_account_id'] )
	&& ! empty( $nxtcc_settings['phone_number_id'] )
	&& ! empty( $nxtcc_settings['meta_webhook_subscribed'] )
	&& ! empty( $nxtcc_settings['access_token_ct'] )
	&& ! empty( $nxtcc_settings['access_token_nonce'] );

/**
 * Load authentication policy settings (fallback defaults).
 *
 * @var array
 */
$nxtcc_policy = get_option( 'nxtcc_auth_policy', array() );

$nxtcc_show_password  = isset( $nxtcc_policy['show_password'] ) ? (int) $nxtcc_policy['show_password'] : 1;
$nxtcc_force_migrate  = ! empty( $nxtcc_policy['force_migrate'] );
$nxtcc_grace_enabled  = ! empty( $nxtcc_policy['grace_enabled'] );
$nxtcc_grace_days_raw = isset( $nxtcc_policy['grace_days'] ) ? (int) $nxtcc_policy['grace_days'] : 7;
$nxtcc_grace_days     = max( 1, min( 90, $nxtcc_grace_days_raw ) );

$nxtcc_force_path_in = ( isset( $nxtcc_policy['force_path'] ) && is_string( $nxtcc_policy['force_path'] ) ) ? $nxtcc_policy['force_path'] : '';
$nxtcc_force_path    = '' !== $nxtcc_force_path_in ? $nxtcc_force_path_in : '/nxt-whatsapp-login/';

/**
 * Widget branding is controlled by policy settings only.
 *
 * @var int
 */
$nxtcc_widget_branding = isset( $nxtcc_policy['widget_branding'] ) ? (int) $nxtcc_policy['widget_branding'] : 0;

?>
<div class="wrap nxtcc-auth-wrap">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Authentication', 'nxt-cloud-chat' ); ?>
	</h1>
	<hr class="wp-header-end" />

	<?php if ( ! $nxtcc_has_connection ) : ?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong>
					<?php esc_html_e( 'WhatsApp API isn’t connected.', 'nxt-cloud-chat' ); ?>
				</strong>
				<?php esc_html_e( 'Please add your credentials and enable the webhook in', 'nxt-cloud-chat' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nxtcc-settings' ) ); ?>">
					<?php esc_html_e( 'Settings', 'nxt-cloud-chat' ); ?>
				</a>.
			</p>
		</div>
	<?php endif; ?>

	<!-- Card 1: WhatsApp OTP Template -->
	<div class="nxtcc-auth-card">
		<div class="nxtcc-auth-head">
			<div class="nxtcc-auth-title">
				<?php esc_html_e( 'WhatsApp OTP — Template', 'nxt-cloud-chat' ); ?>
			</div>
		</div>
		<div class="nxtcc-auth-body">

			<!-- Default profile (owner) selector -->
			<div class="nxtcc-field">
				<label for="nxtcc-auth-owner" class="nxtcc-label">
					<?php esc_html_e( 'Default profile for authentication', 'nxt-cloud-chat' ); ?>
				</label>
				<div class="nxtcc-row">
					<select id="nxtcc-auth-owner" class="nxtcc-select">
						<option value="">
							<?php esc_html_e( '— Select profile —', 'nxt-cloud-chat' ); ?>
						</option>
					</select>
				</div>
				<p class="nxtcc-help">
					<?php
					echo wp_kses_post(
						sprintf(
						/* translators: %s: table name nxtcc_user_settings (wrapped in <code>) */
							__( 'Pick which profile from %s to use for OTP templates and sending.', 'nxt-cloud-chat' ),
							'<code>' . esc_html( 'nxtcc_user_settings' ) . '</code>'
						)
					);
					?>
				</p>
			</div>

			<div class="nxtcc-field">
				<label for="nxtcc-auth-template" class="nxtcc-label">
					<?php esc_html_e( 'Authentication template', 'nxt-cloud-chat' ); ?>
				</label>
				<div class="nxtcc-row">
					<select id="nxtcc-auth-template" class="nxtcc-select">
						<option value="">
							<?php esc_html_e( '— Select template —', 'nxt-cloud-chat' ); ?>
						</option>
					</select>
					<button id="nxtcc-generate-default" class="button button-primary" type="button">
						<?php esc_html_e( 'Generate', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
				<p id="nxtcc-auth-msg" class="nxtcc-help" aria-live="polite"></p>
			</div>

			<p class="nxtcc-help">
				<?php
				echo wp_kses_post(
					sprintf(
					/* translators: 1: AUTHENTICATION (wrapped in <strong>), 2: Generate (wrapped in <strong>) */
						__( 'Choose an approved %1$s template. If you don’t have one yet, click %2$s to submit a default OTP template.', 'nxt-cloud-chat' ),
						'<strong>' . esc_html__( 'AUTHENTICATION', 'nxt-cloud-chat' ) . '</strong>',
						'<strong>' . esc_html__( 'Generate', 'nxt-cloud-chat' ) . '</strong>'
					)
				);

				?>
			</p>

		</div>
	</div>

	<!-- Card 2: Widget & Migration Settings -->
	<div class="nxtcc-auth-card">
		<div class="nxtcc-auth-head">
			<div class="nxtcc-auth-title">
				<?php esc_html_e( 'Widget & migration settings', 'nxt-cloud-chat' ); ?>
			</div>
		</div>
		<div class="nxtcc-auth-body">

			<div class="nxtcc-grid">
				<!-- Force migration -->
				<div class="nxtcc-field">
					<label class="nxtcc-check">
						<input
							type="checkbox"
							id="nxtcc-force-migrate"
							<?php checked( $nxtcc_force_migrate, true ); ?>
						/>
						<span>
							<?php esc_html_e( 'Force migration from WordPress password to WhatsApp login', 'nxt-cloud-chat' ); ?>
						</span>
					</label>
					<p class="nxtcc-help">
						<?php esc_html_e( 'When enabled, password logins are gated until WhatsApp verification succeeds.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<!-- Widget branding -->
				<div class="nxtcc-field">
					<label class="nxtcc-check">
						<input
							type="checkbox"
							id="nxtcc-widget-branding"
							<?php checked( $nxtcc_widget_branding, 1 ); ?>
						/>
						<span>
							<?php esc_html_e( 'Show footer branding on the login widget', 'nxt-cloud-chat' ); ?>
						</span>
					</label>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Displays a small “Powered by NXT Cloud Chat” label on the login widget.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>
			</div>

			<div class="nxtcc-grid">
				<!-- OTP controls -->
				<div class="nxtcc-field">
					<label for="nxtcc-otp-len" class="nxtcc-label">
						<?php esc_html_e( 'OTP length', 'nxt-cloud-chat' ); ?>
					</label>
					<input
						id="nxtcc-otp-len"
						type="number"
						min="4"
						max="8"
						class="nxtcc-input"
						placeholder="6"
						inputmode="numeric"
						pattern="[0-9]*"
					/>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Digits in the one-time code (recommended 6).', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

				<div class="nxtcc-field">
					<label for="nxtcc-resend-cooldown" class="nxtcc-label">
						<?php esc_html_e( 'Resend cooldown (seconds)', 'nxt-cloud-chat' ); ?>
					</label>
					<input
						id="nxtcc-resend-cooldown"
						type="number"
						min="10"
						max="300"
						class="nxtcc-input"
						placeholder="30"
						inputmode="numeric"
						pattern="[0-9]*"
					/>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Minimum wait before "Resend code" becomes active.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>
			</div>

			<!-- Force path + Grace -->
			<div class="nxtcc-grid">
				<div class="nxtcc-field">
					<label for="nxtcc-force-path" class="nxtcc-label">
						<?php esc_html_e( 'Force-migration login page (path)', 'nxt-cloud-chat' ); ?>
					</label>
					<input
						id="nxtcc-force-path"
						type="text"
						class="nxtcc-input"
						value="<?php echo esc_attr( $nxtcc_force_path ); ?>"
						placeholder="/nxt-whatsapp-login/"
					/>
					<p class="nxtcc-help">
						<?php
						echo wp_kses_post(
							sprintf(
							/* translators: %s: recommended path (wrapped in <code>) */
								__( 'Recommended path: %s', 'nxt-cloud-chat' ),
								'<code>' . esc_html( '/nxt-whatsapp-login/' ) . '</code>'
							)
						);

						?>
					</p>
				</div>

				<div class="nxtcc-field">
					<label class="nxtcc-label">
						<?php esc_html_e( 'Grace period', 'nxt-cloud-chat' ); ?>
					</label>
					<div class="nxtcc-row">
						<label class="nxtcc-switch">
							<input
								type="checkbox"
								id="nxtcc-grace-enabled"
								<?php checked( $nxtcc_grace_enabled, true ); ?>
							/>
							<span class="nxtcc-slider"></span>
						</label>
						<input
							id="nxtcc-grace-days"
							type="number"
							min="1"
							max="90"
							class="nxtcc-input"
							style="max-width:120px;margin-left:.5rem"
							value="<?php echo esc_attr( $nxtcc_grace_days ); ?>"
							inputmode="numeric"
							pattern="[0-9]*"
						/>
						<span class="nxtcc-help" style="align-self:center;margin-left:.25rem">
							<?php esc_html_e( 'days', 'nxt-cloud-chat' ); ?>
						</span>
					</div>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Allow a limited window before enforcement starts for first-time password logins.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>
			</div>

			<!-- Footer links + password toggle + contacts -->
			<div class="nxtcc-grid">
				<div class="nxtcc-field">
					<label for="nxtcc-terms-url" class="nxtcc-label">
						<?php esc_html_e( 'Terms URL', 'nxt-cloud-chat' ); ?>
					</label>
					<input
						id="nxtcc-terms-url"
						type="text"
						class="nxtcc-input"
						placeholder="/terms"
					/>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Shown as "Terms". Accepts a full URL or a site path.', 'nxt-cloud-chat' ); ?>
					</p>

					<label for="nxtcc-privacy-url" class="nxtcc-label" style="margin-top:.75rem;">
						<?php esc_html_e( 'Privacy URL', 'nxt-cloud-chat' ); ?>
					</label>
					<input
						id="nxtcc-privacy-url"
						type="text"
						class="nxtcc-input"
						placeholder="/privacy-policy"
					/>
					<p class="nxtcc-help">
						<?php esc_html_e( 'Shown as "Privacy Policy". Accepts a full URL or a site path.', 'nxt-cloud-chat' ); ?>
					</p>

					<div class="nxtcc-field">
						<label class="nxtcc-check">
							<input
								type="checkbox"
								id="nxtcc-show-password"
								<?php checked( $nxtcc_show_password, 1 ); ?>
							/>
							<span>
								<?php esc_html_e( 'Show “Use password instead” link on the widget', 'nxt-cloud-chat' ); ?>
							</span>
						</label>
						<p class="nxtcc-help">
							<?php esc_html_e( 'Uncheck to hide the password option and nudge users toward WhatsApp login.', 'nxt-cloud-chat' ); ?>
						</p>
					</div>

					<!-- Auto-sync contacts -->
					<div class="nxtcc-field" style="margin-top:1rem">
						<label class="nxtcc-check">
							<input type="checkbox" id="nxtcc-auto-sync-contacts" />
							<span>
								<?php esc_html_e( 'Add verified users to Contacts', 'nxt-cloud-chat' ); ?>
							</span>
						</label>
						<p class="nxtcc-help">
							<?php esc_html_e( 'When a user verifies via WhatsApp, automatically create a contact in your tenant. You can also backfill existing verified users.', 'nxt-cloud-chat' ); ?>
						</p>

						<div class="nxtcc-row" style="margin-top:.5rem">
							<button id="nxtcc-sync-verified" class="button" type="button">
								<?php esc_html_e( 'Sync verified users now', 'nxt-cloud-chat' ); ?>
							</button>
							<span
								id="nxtcc-sync-msg"
								class="nxtcc-help"
								style="margin-left:.5rem"
								aria-live="polite"
							></span>
						</div>
					</div>
				</div>

				<!-- Allowed Countries -->
				<div class="nxtcc-field" id="nxtcc-allowed-countries">
					<label class="nxtcc-label">
						<?php esc_html_e( 'Allowed countries for WhatsApp verification', 'nxt-cloud-chat' ); ?>
					</label>

					<div class="nxtcc-row nxtcc-ac-utils">
						<a
							href="#"
							id="nxtcc-ac-select-all"
							class="nxtcc-ac-link"
							role="button"
							aria-controls="nxtcc-ac-list"
						>
							<?php esc_html_e( 'Select all', 'nxt-cloud-chat' ); ?>
						</a>
						<span class="nxtcc-ac-sep">•</span>
						<a
							href="#"
							id="nxtcc-ac-clear-all"
							class="nxtcc-ac-link"
							role="button"
							aria-controls="nxtcc-ac-list"
						>
							<?php esc_html_e( 'Clear all', 'nxt-cloud-chat' ); ?>
						</a>
						<span
							class="nxtcc-ac-count"
							id="nxtcc-ac-count"
							aria-live="polite"
							aria-atomic="true"
						>
							<?php esc_html_e( 'Selected: 0', 'nxt-cloud-chat' ); ?>
						</span>
					</div>

					<input
						type="text"
						id="nxtcc-ac-search"
						class="nxtcc-input"
						placeholder="<?php echo esc_attr__( 'Search by dial code or country', 'nxt-cloud-chat' ); ?>"
						aria-label="<?php echo esc_attr__( 'Filter countries', 'nxt-cloud-chat' ); ?>"
					/>

					<div
						id="nxtcc-ac-list"
						class="nxtcc-ac-list"
						role="listbox"
						aria-label="<?php echo esc_attr__( 'Allowed countries', 'nxt-cloud-chat' ); ?>"
					></div>

					<p class="nxtcc-help" style="margin-top:.4rem">
						<?php esc_html_e( 'If none are selected, all countries are allowed. The default is the visitor’s geo-detected country.', 'nxt-cloud-chat' ); ?>
					</p>
				</div>

			</div>

		</div>
	</div>

	<!-- Save -->
	<div class="nxtcc-actions">
		<button id="nxtcc-auth-save" class="button button-primary" type="button">
			<?php esc_html_e( 'Save Changes', 'nxt-cloud-chat' ); ?>
		</button>
		<span
			id="nxtcc-save-msg"
			class="nxtcc-help"
			aria-live="polite"
		></span>
	</div>
</div>
