<?php
/**
 * Admin Apps / Add-ons view.
 *
 * Renders the "Apps" (upgrade/add-ons) screen in the WordPress admin for
 * NXT Cloud Chat. This page displays available add-ons and links to learn more
 * or upgrade.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect the main plugin file path for reading metadata.
 */
if ( defined( 'NXTCC_PLUGIN_FILE' ) ) {
	$nxtcc_plugin_file = NXTCC_PLUGIN_FILE;
} else {
	$nxtcc_plugin_file = trailingslashit( NXTCC_PLUGIN_DIR ) . 'nxt-cloud-chat.php';
}

/**
 * Author/marketing URL fallback.
 *
 * If the plugin header contains AuthorURI, it will be used instead.
 */
$nxtcc_author_uri = 'https://nxtwebsite.com';

$nxtcc_plugin_data = array();

if ( function_exists( 'get_plugin_data' ) && file_exists( $nxtcc_plugin_file ) ) {
	$nxtcc_plugin_data = get_plugin_data( $nxtcc_plugin_file, false, false );
	if ( ! empty( $nxtcc_plugin_data['AuthorURI'] ) ) {
		$nxtcc_author_uri = (string) $nxtcc_plugin_data['AuthorURI'];
	}
}

/**
 * Resolve a default icon for add-ons.
 *
 * If the image file does not exist, the UI will fall back to a letter icon.
 */
$nxtcc_default_icon_path = trailingslashit( NXTCC_PLUGIN_DIR ) . 'admin/assets/vendor/images/nxt-cloud-chat.png';
$nxtcc_default_icon_url  = file_exists( $nxtcc_default_icon_path )
	? trailingslashit( NXTCC_PLUGIN_URL ) . 'admin/assets/vendor/images/nxt-cloud-chat.png'
	: '';

/**
 * My Account URL for the header link (derived from Author URI).
 */
$nxtcc_my_account_url = trailingslashit( $nxtcc_author_uri ) . 'my-account/';

/**
 * Add-ons list.
 *
 * Keys are internal identifiers. Each item may define:
 * - slug, name, badge, icon_url, description
 * - primary CTA { label, url }
 * - secondary CTA { label, url }
 */
$nxtcc_addons = array(
	'nxtcc-pro' => array(
		'slug'        => 'nxtcc-pro',
		'name'        => __( 'NXT Cloud Chat Pro', 'nxt-cloud-chat' ),
		'badge'       => 'pro',
		'icon_url'    => $nxtcc_default_icon_url,
		'description' => __( 'Unlock templates, Bulk messaging, WooCommerce automation, and priority support.', 'nxt-cloud-chat' ),
		'primary'     => array(
			'label' => __( 'Upgrade', 'nxt-cloud-chat' ),
			'url'   => $nxtcc_author_uri,
		),
		'secondary'   => array(
			'label' => __( 'Learn More', 'nxt-cloud-chat' ),
			'url'   => $nxtcc_author_uri,
		),
	),
);

?>
<div class="wrap nxtcc-apps-page">

	<div class="nxtcc-apps-topbar">
		<div class="nxtcc-apps-topbar-left">
			<div class="nxtcc-apps-topbar-logo">
				<?php if ( $nxtcc_default_icon_url ) : ?>
					<img
						src="<?php echo esc_url( $nxtcc_default_icon_url ); ?>"
						alt="<?php esc_attr_e( 'NXT Cloud Chat', 'nxt-cloud-chat' ); ?>"
					/>
				<?php else : ?>
					<span class="nxtcc-apps-topbar-logo-letter">N</span>
				<?php endif; ?>
			</div>

			<div class="nxtcc-apps-topbar-title">
				<?php esc_html_e( 'NXTWEBSITE', 'nxt-cloud-chat' ); ?>
			</div>
		</div>

		<div class="nxtcc-apps-topbar-right">
			<a
				href="<?php echo esc_url( $nxtcc_my_account_url ); ?>"
				target="_blank"
				rel="noopener noreferrer"
				class="nxtcc-topbar-link"
			>
				<?php esc_html_e( 'My Account', 'nxt-cloud-chat' ); ?>
			</a>
		</div>
	</div>

	<h1 class="nxtcc-apps-title">
		<?php esc_html_e( 'Popular Add-ons, New Possibilities.', 'nxt-cloud-chat' ); ?>
	</h1>

	<p class="nxtcc-apps-subtitle">
		<?php
		esc_html_e(
			'Extend NXT Cloud Chat with add-ons for bulk messaging, automation, analytics, and more—so you can do more from WordPress with WhatsApp Cloud API.',
			'nxt-cloud-chat'
		);
		?>
	</p>

	<div class="nxtcc-apps-grid">
		<?php foreach ( $nxtcc_addons as $nxtcc_addon ) : ?>
			<?php
			$nxtcc_badge    = isset( $nxtcc_addon['badge'] ) ? (string) $nxtcc_addon['badge'] : '';
			$nxtcc_icon_url = isset( $nxtcc_addon['icon_url'] ) ? (string) $nxtcc_addon['icon_url'] : '';
			?>
			<article
				class="nxtcc-app-card<?php echo ( 'pro' === $nxtcc_badge ) ? ' is-pro' : ''; ?>"
				data-addon="<?php echo esc_attr( $nxtcc_addon['slug'] ); ?>"
			>
				<div class="nxtcc-app-card-inner">
					<div class="nxtcc-app-icon-wrap">
						<div class="nxtcc-app-icon">
							<?php if ( $nxtcc_icon_url ) : ?>
								<img
									src="<?php echo esc_url( $nxtcc_icon_url ); ?>"
									alt="<?php echo esc_attr( $nxtcc_addon['name'] ); ?>"
									class="nxtcc-app-icon-img"
								/>
							<?php else : ?>
								<span class="nxtcc-app-icon-letter">
									<?php echo esc_html( mb_substr( $nxtcc_addon['name'], 0, 1 ) ); ?>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( $nxtcc_badge ) : ?>
							<span class="nxtcc-app-badge nxtcc-app-badge-<?php echo esc_attr( $nxtcc_badge ); ?>">
								<?php echo ( 'pro' === $nxtcc_badge ) ? esc_html__( 'PRO', 'nxt-cloud-chat' ) : esc_html__( 'New', 'nxt-cloud-chat' ); ?>
							</span>
						<?php endif; ?>
					</div>

					<div class="nxtcc-app-content">
						<h2 class="nxtcc-app-name">
							<?php echo esc_html( $nxtcc_addon['name'] ); ?>
						</h2>

						<div class="nxtcc-app-author">
							<?php esc_html_e( 'By', 'nxt-cloud-chat' ); ?>
							&nbsp;
							<a
								href="<?php echo esc_url( $nxtcc_author_uri ); ?>"
								target="_blank"
								rel="noopener noreferrer"
							>
								<?php echo esc_html( 'NXTWEBSITE' ); ?>
							</a>
						</div>

						<p class="nxtcc-app-desc">
							<?php echo esc_html( $nxtcc_addon['description'] ); ?>
						</p>
					</div>

					<div class="nxtcc-app-footer">
						<?php if ( ! empty( $nxtcc_addon['secondary']['label'] ) && ! empty( $nxtcc_addon['secondary']['url'] ) ) : ?>
							<a
								href="<?php echo esc_url( $nxtcc_addon['secondary']['url'] ); ?>"
								class="nxtcc-app-cta-secondary"
								target="_blank"
								rel="noopener noreferrer"
							>
								<?php echo esc_html( $nxtcc_addon['secondary']['label'] ); ?>
							</a>
						<?php endif; ?>

						<?php if ( ! empty( $nxtcc_addon['primary']['label'] ) && ! empty( $nxtcc_addon['primary']['url'] ) ) : ?>
							<a
								href="<?php echo esc_url( $nxtcc_addon['primary']['url'] ); ?>"
								class="button nxtcc-app-cta-primary"
								target="_blank"
								rel="noopener noreferrer"
							>
								<?php echo esc_html( $nxtcc_addon['primary']['label'] ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</div>
