<?php
/**
 * Force-migration login page router for the configured "force_path".
 *
 * Behavior:
 * - If the request is NOT the force path, do nothing.
 * - If the request is AJAX or REST, do nothing.
 * - If the user is logged in AND WhatsApp verified, redirect to home.
 * - Otherwise, render a bare public login/verification page (no theme header/footer).
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap the force-migration router.
 *
 * @return void
 */
function nxtcc_fm_page_router_init(): void {
	add_action( 'template_redirect', 'nxtcc_fm_page_router_maybe_route', 99 );
}
add_action( 'init', 'nxtcc_fm_page_router_init', 1 );

/**
 * Enqueue bare page styles (inline via WP APIs).
 *
 * Registered as an "empty" handle so wp_add_inline_style() is valid.
 *
 * @return void
 */
function nxtcc_fm_page_enqueue_bare_styles(): void {
	wp_register_style( 'nxtcc-fm-bare', false, array(), NXTCC_VERSION );
	wp_enqueue_style( 'nxtcc-fm-bare' );

	$css = '
		html,body{height:100%;}
		body.nxtcc-bare{
			margin:0;background:#f4f6f8;color:#111827;
			display:flex;align-items:center;justify-content:center;
			font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Helvetica,Arial,sans-serif;
		}
		.nxtcc-migration-page{
			display:flex;justify-content:center;align-items:center;
			min-height:80vh;padding:2rem;
		}
		.nxtcc-migration-card{
			max-width:560px;width:100%;text-align:center;
			background:#fff;border-radius:14px;
			box-shadow:0 10px 30px rgba(7,94,84,0.10);padding:28px;
		}
		.nxtcc-migration-card h2{font-weight:600;color:#0A7C66;margin-bottom:1rem;}
		.nxtcc-migration-card p{margin-bottom:1.5rem;color:#555;}
		/* Ensure any theme header/footer remnants are hidden. */
		.site-header,header,.site-footer,footer{display:none!important;}
	';

	wp_add_inline_style( 'nxtcc-fm-bare', $css );
}

/**
 * Main router for the force migration page.
 *
 * @return void
 */
function nxtcc_fm_page_router_maybe_route(): void {
	/*
	 * Resolve configured force path.
	 *
	 * The force path is stored under the force-migration policy options and is expected
	 * to be a site-relative path like "/nxt-whatsapp-login/".
	 */
	$opts       = function_exists( 'nxtcc_fm_get_options' ) ? (array) nxtcc_fm_get_options() : array();
	$force_path = isset( $opts['force_path'] ) ? (string) $opts['force_path'] : '/nxt-whatsapp-login/';
	$force_path = nxtcc_fm_page_normalize_path( $force_path );
	if ( function_exists( 'nxtcc_fm_with_home_prefix' ) ) {
		$force_path = nxtcc_fm_with_home_prefix( $force_path );
	}

	/*
	 * Determine the current request path for strict comparison.
	 */
	$req_path = nxtcc_fm_page_get_current_request_path();

	/*
	 * Only handle the exact path owned by this router.
	 */
	if ( $req_path !== $force_path ) {
		return;
	}

	/*
	 * Never interfere with AJAX or REST requests.
	 */
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	/*
	 * Compute redirect target.
	 */
	$default_redirect = home_url( '/' );
	$filtered         = apply_filters( 'nxtcc_fm_verified_redirect', $default_redirect );
	$redirect_url     = wp_validate_redirect( $filtered, $default_redirect );

	/*
	 * Logged-in users who are already verified do not need this screen.
	 */
	if ( is_user_logged_in() ) {
		$is_verified = false;
		if ( function_exists( 'nxtcc_is_user_whatsapp_verified' ) ) {
			$is_verified = (bool) nxtcc_is_user_whatsapp_verified( get_current_user_id() );
		}
		if ( $is_verified ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/*
	 * For anonymous visitors and logged-in-but-unverified users, render the
	 * WhatsApp login page directly.
	 */
	status_header( 200 );
	nocache_headers();

	/*
	 * Enqueue login widget assets if the function exists.
	 */
	if ( function_exists( 'nxtcc_auth_enqueue_login_widget_assets' ) ) {
		nxtcc_auth_enqueue_login_widget_assets();
	}

	/*
	 * Add our bare CSS via wp_enqueue + wp_add_inline_style.
	 *
	 */
	nxtcc_fm_page_enqueue_bare_styles();

	nxtcc_fm_page_render_bare();
	exit;
}

/**
 * Safely fetch the current request path for strict comparison.
 *
 * @return string Normalized request path.
 */
function nxtcc_fm_page_get_current_request_path(): string {
	$raw = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( null === $raw || '' === $raw ) {
		$raw = '/';
	}

	$raw = sanitize_text_field( wp_unslash( (string) $raw ) );

	$parsed = wp_parse_url( $raw );
	$path   = ( is_array( $parsed ) && isset( $parsed['path'] ) && is_string( $parsed['path'] ) ) ? (string) $parsed['path'] : '/';

	return nxtcc_fm_page_normalize_path( $path );
}

/**
 * Normalize a URL path to always have a single leading and trailing slash.
 *
 * @param string $path Raw path or URL.
 * @return string Normalized path.
 */
function nxtcc_fm_page_normalize_path( string $path ): string {
	$path = trim( $path );

	$maybe_url = wp_parse_url( $path );
	if ( is_array( $maybe_url ) && isset( $maybe_url['path'] ) && is_string( $maybe_url['path'] ) ) {
		$path = (string) $maybe_url['path'];
	}

	$path = '/' . ltrim( $path, "/ \t\n\r\0\x0B" );
	$path = rtrim( $path, "/ \t\n\r\0\x0B" ) . '/';

	return $path;
}

/**
 * Render a bare document (no theme header/footer).
 *
 * @return void
 */
function nxtcc_fm_page_render_bare(): void {
	?>
	<!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width,initial-scale=1" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class( 'nxtcc-bare' ); ?>>
	<?php wp_body_open(); ?>

	<main class="nxtcc-migration-page">
		<div class="nxtcc-migration-card">
			<h2><?php echo esc_html__( 'Complete WhatsApp Verification', 'nxt-cloud-chat' ); ?></h2>
			<p><?php echo esc_html__( 'To continue using your account, please verify your WhatsApp number below.', 'nxt-cloud-chat' ); ?></p>
			<?php
			/*
			 * Shortcode output is expected to contain HTML.
			 *
			 * do_shortcode() returns HTML markup for the login widget, so it should not
			 * be wrapped in an escaping function here.
			 */
			echo do_shortcode( '[nxtcc_login_whatsapp]' );
			?>
		</div>
	</main>

	<?php wp_footer(); ?>
	</body>
	</html>
	<?php
}
