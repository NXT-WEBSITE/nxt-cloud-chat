<?php
/**
 * Front-end login widget view.
 *
 * Renders the WhatsApp OTP login UI used by the shortcode/widget handler.
 *
 * Expected variables (provided by the shortcode handler):
 * - $session_id        Unique session identifier for the OTP flow.
 * - $terms_url         Terms URL (optional; falls back to site default).
 * - $privacy_url       Privacy policy URL (optional; falls back to site default).
 * - $hide_branding     Whether to hide branding for this widget instance.
 * - $default_country   Two-letter ISO country code (defaults to IN).
 *
 * Security:
 * - All dynamic outputs are escaped.
 * - Generates a per-render nonce for front-end AJAX requests.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize shortcode-provided values to avoid notices and to ensure safe output.
 *
 * These values are used for rendering (data attributes, links, UI defaults) and
 * are not trusted for authorization decisions on their own.
 */
$nxtcc_session_id_raw = isset( $session_id ) ? (string) $session_id : '';
$nxtcc_session_id     = sanitize_text_field( $nxtcc_session_id_raw );

$nxtcc_terms_url   = isset( $terms_url ) ? (string) $terms_url : '';
$nxtcc_privacy_url = isset( $privacy_url ) ? (string) $privacy_url : '';

/**
 * Shortcode can request branding hidden per placement.
 *
 * Any truthy value is treated as "hide".
 */
$nxtcc_hide_branding = ! empty( $hide_branding );

/**
 * Normalize the default country code.
 *
 * Widget expects an ISO 3166-1 alpha-2 code. Fallback to IN if invalid.
 */
$nxtcc_default_country = isset( $default_country ) ? strtoupper( (string) $default_country ) : 'IN';
if ( ! preg_match( '/^[A-Z]{2}$/', $nxtcc_default_country ) ) {
	$nxtcc_default_country = 'IN';
}

/**
 * Provide fallback links when shortcode does not supply values.
 */
if ( '' === $nxtcc_terms_url ) {
	$nxtcc_terms_url = home_url( '/terms/' );
}
if ( '' === $nxtcc_privacy_url ) {
	$nxtcc_privacy_url = home_url( '/privacy-policy/' );
}

/**
 * Read plugin policy/options (fallback-safe).
 *
 * - show_password: whether to show "Use password instead" link.
 * - widget_branding: whether branding is globally enabled.
 */
$nxtcc_policy = function_exists( 'nxtcc_fm_get_options' ) ? (array) nxtcc_fm_get_options() : array();

$nxtcc_show_password = ! empty( $nxtcc_policy['show_password'] );

$nxtcc_widget_branding_on = isset( $nxtcc_policy['widget_branding'] )
	? (int) $nxtcc_policy['widget_branding']
	: 0;

/**
 * If branding is disabled globally, force-hide it regardless of shortcode setting.
 */
if ( 1 !== $nxtcc_widget_branding_on ) {
	$nxtcc_hide_branding = true;
}

$nxtcc_logged_in  = is_user_logged_in();
$nxtcc_logout_url = wp_logout_url( home_url( '/' ) );

/**
 * Create a per-render nonce tied to the session identifier.
 *
 * The frontend JS can send both data-nonce and data-nonce-action for server-side
 * verification during the OTP flow.
 */
$nxtcc_nonce_action = 'nxtcc_login|' . $nxtcc_session_id;
$nxtcc_nonce        = wp_create_nonce( $nxtcc_nonce_action );

/**
 * Generate a unique base ID to avoid collisions when multiple widgets are present.
 */
$nxtcc_uid = wp_unique_id( 'nxtcc-' );

/**
 * Branding content: read Plugin Name and Author URI from the main plugin file.
 *
 * This allows branding to stay consistent even if the plugin name changes.
 */
$nxtcc_plugin_file = dirname( dirname( __DIR__ ) ) . '/nxt-cloud-chat.php';
$nxtcc_brand_name  = 'NXTWEBSITE';
$nxtcc_brand_url   = 'https://nxtwebsite.com';
$nxtcc_plugin_data = array();

if ( function_exists( 'get_file_data' ) && file_exists( $nxtcc_plugin_file ) ) {
	$nxtcc_plugin_data = get_file_data(
		$nxtcc_plugin_file,
		array(
			'Name'      => 'Plugin Name',
			'AuthorURI' => 'Author URI',
		),
		'plugin'
	);

	if ( ! empty( $nxtcc_plugin_data['Name'] ) ) {
		$nxtcc_brand_name = (string) $nxtcc_plugin_data['Name'];
	}

	if ( ! empty( $nxtcc_plugin_data['AuthorURI'] ) ) {
		$nxtcc_brand_url = (string) $nxtcc_plugin_data['AuthorURI'];
	}
}
?>
<div
	class="nxtcc-auth-widget"
	id="<?php echo esc_attr( $nxtcc_uid ); ?>"
	data-session-id="<?php echo esc_attr( $nxtcc_session_id ); ?>"
	data-default-country="<?php echo esc_attr( $nxtcc_default_country ); ?>"
	data-nonce="<?php echo esc_attr( $nxtcc_nonce ); ?>"
	data-nonce-action="<?php echo esc_attr( $nxtcc_nonce_action ); ?>"
>
	<div class="nxtcc-auth-card">
		<div class="nxtcc-auth-head">
			<div class="nxtcc-auth-title">
				<?php esc_html_e( 'Login with WhatsApp', 'nxt-cloud-chat' ); ?>
			</div>
		</div>

		<div class="nxtcc-auth-body">
			<!-- Step 1: Collect phone number and send OTP via WhatsApp. -->
			<div class="nxtcc-step nxtcc-step-phone" aria-labelledby="<?php echo esc_attr( $nxtcc_uid ); ?>-label-phone">
				<label class="nxtcc-label" id="<?php echo esc_attr( $nxtcc_uid ); ?>-label-phone">
					<?php esc_html_e( 'Mobile number', 'nxt-cloud-chat' ); ?>
				</label>

				<div class="nxtcc-row">
					<select class="nxtcc-country" aria-label="<?php echo esc_attr__( 'Country code', 'nxt-cloud-chat' ); ?>">
						<option value="IN" data-dial="+91">+91</option>
					</select>

					<input
						type="tel"
						class="nxtcc-phone"
						placeholder="<?php echo esc_attr__( '98765 43210', 'nxt-cloud-chat' ); ?>"
						inputmode="numeric"
						autocomplete="tel"
						aria-describedby="<?php echo esc_attr( $nxtcc_uid ); ?>-help-phone"
					/>
				</div>

				<p class="nxtcc-help" id="<?php echo esc_attr( $nxtcc_uid ); ?>-help-phone">
					<?php esc_html_e( 'We’ll send a one-time passcode via WhatsApp.', 'nxt-cloud-chat' ); ?>
				</p>

				<div class="nxtcc-actions">
					<button type="button" class="nxtcc-btn-send">
						<?php esc_html_e( 'Send code on WhatsApp', 'nxt-cloud-chat' ); ?>
					</button>

					<?php if ( $nxtcc_logged_in ) : ?>
						<a class="nxtcc-link-alt" href="<?php echo esc_url( $nxtcc_logout_url ); ?>">
							<?php esc_html_e( 'Logout', 'nxt-cloud-chat' ); ?>
						</a>
					<?php elseif ( $nxtcc_show_password ) : ?>
						<a class="nxtcc-link-alt" href="<?php echo esc_url( wp_login_url() ); ?>">
							<?php esc_html_e( 'Use password instead', 'nxt-cloud-chat' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<!-- Phone-step error container updated by JS during validation/sending. -->
				<div class="nxtcc-error nxtcc-error-phone" role="alert" hidden></div>
			</div>

			<!-- Step 2: Enter OTP and verify the session. -->
			<div class="nxtcc-step nxtcc-step-otp" hidden aria-labelledby="<?php echo esc_attr( $nxtcc_uid ); ?>-label-otp">
				<div class="nxtcc-otp-target" id="<?php echo esc_attr( $nxtcc_uid ); ?>-label-otp">
					<?php esc_html_e( 'Enter the code we sent to your WhatsApp.', 'nxt-cloud-chat' ); ?>
				</div>

				<div
					class="nxtcc-otp-inputs"
					aria-label="<?php echo esc_attr__( 'OTP inputs', 'nxt-cloud-chat' ); ?>"
					autocomplete="one-time-code"
				></div>

				<div class="nxtcc-otp-row">
					<span class="nxtcc-expiry">
						<?php esc_html_e( 'Code expires in 5 minutes', 'nxt-cloud-chat' ); ?>
					</span>
					<button type="button" class="nxtcc-btn-resend" disabled>
						<?php esc_html_e( 'Resend code', 'nxt-cloud-chat' ); ?>
					</button>
				</div>

				<div class="nxtcc-actions">
					<button type="button" class="nxtcc-btn-verify">
						<?php esc_html_e( 'Verify & Continue', 'nxt-cloud-chat' ); ?>
					</button>
					<button type="button" class="nxtcc-btn-change">
						<?php esc_html_e( 'Change number', 'nxt-cloud-chat' ); ?>
					</button>

					<?php if ( $nxtcc_logged_in ) : ?>
						<a class="nxtcc-link-alt" href="<?php echo esc_url( $nxtcc_logout_url ); ?>" style="margin-left:auto">
							<?php esc_html_e( 'Logout', 'nxt-cloud-chat' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<!-- OTP-step error container updated by JS during verification/resend. -->
				<div class="nxtcc-error nxtcc-error-otp" role="alert" hidden></div>
			</div>
		</div>

		<div class="nxtcc-auth-foot">
			<div class="nxtcc-legal">
				<?php esc_html_e( 'By continuing, you agree to our', 'nxt-cloud-chat' ); ?>
				<a href="<?php echo esc_url( $nxtcc_terms_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Terms', 'nxt-cloud-chat' ); ?>
				</a>
				<?php esc_html_e( 'and', 'nxt-cloud-chat' ); ?>
				<a href="<?php echo esc_url( $nxtcc_privacy_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Privacy Policy', 'nxt-cloud-chat' ); ?>
				</a>.
			</div>

			<?php if ( ! $nxtcc_hide_branding && ! empty( $nxtcc_brand_name ) ) : ?>
				<div class="nxtcc-branding">
					<?php esc_html_e( 'Powered by', 'nxt-cloud-chat' ); ?>
					<?php if ( ! empty( $nxtcc_brand_url ) ) : ?>
						<a href="<?php echo esc_url( $nxtcc_brand_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $nxtcc_brand_name ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $nxtcc_brand_name ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<noscript>
				<p class="nxtcc-noscript">
					<?php esc_html_e( 'JavaScript is required to use WhatsApp login. Please enable JavaScript and reload this page.', 'nxt-cloud-chat' ); ?>
				</p>
			</noscript>
		</div>
	</div>
</div>
