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
$nxtcc_default_icon_path       = trailingslashit( NXTCC_PLUGIN_DIR ) . 'admin/assets/vendor/images/nxt-cloud-chat.png';
$nxtcc_default_icon_url        = file_exists( $nxtcc_default_icon_path )
	? trailingslashit( NXTCC_PLUGIN_URL ) . 'admin/assets/vendor/images/nxt-cloud-chat.png'
	: '';
$nxtcc_floating_chat_icon_path = trailingslashit( NXTCC_PLUGIN_DIR ) . 'admin/assets/vendor/images/nxt-floating-chat.png';
$nxtcc_floating_chat_icon_url  = file_exists( $nxtcc_floating_chat_icon_path )
	? trailingslashit( NXTCC_PLUGIN_URL ) . 'admin/assets/vendor/images/nxt-floating-chat.png'
	: $nxtcc_default_icon_url;

/**
 * My Account URL for the header link (derived from Author URI).
 */
$nxtcc_my_account_url = trailingslashit( $nxtcc_author_uri ) . 'my-account/';

/**
 * Preferred product page for upgrade / learn-more CTAs.
 *
 * Falls back to Author URI when the preferred URL is unavailable.
 */
$nxtcc_product_url = 'https://nxtwebsite.com/wordpress/nxt-cloud-chat/';
if ( '' === esc_url_raw( $nxtcc_product_url ) ) {
	$nxtcc_product_url = $nxtcc_author_uri;
}

$nxtcc_floating_chat_plugin_basename = 'nxt-floating-chat-widget/nxt-floating-chat-widget.php';
$nxtcc_floating_chat_plugin_file     = trailingslashit( WP_PLUGIN_DIR ) . $nxtcc_floating_chat_plugin_basename;
$nxtcc_floating_chat_url             = 'https://nxtwebsite.com/wordpress/nxt-floating-chat/';
$nxtcc_floating_chat_name            = __( 'NXT Floating Chat', 'nxt-cloud-chat' );
$nxtcc_floating_chat_description     = __( 'Add a lightweight floating chat button to your site, connect it with a selected NXT Cloud Chat tenant profile, and track clicks without storing visitor data.', 'nxt-cloud-chat' );
$nxtcc_floating_chat_active          = function_exists( 'is_plugin_active' ) && is_plugin_active( $nxtcc_floating_chat_plugin_basename );

if ( function_exists( 'get_plugin_data' ) && file_exists( $nxtcc_floating_chat_plugin_file ) ) {
	$nxtcc_floating_chat_data = get_plugin_data( $nxtcc_floating_chat_plugin_file, false, false );
	if ( ! empty( $nxtcc_floating_chat_data['Name'] ) ) {
		$nxtcc_floating_chat_name = (string) $nxtcc_floating_chat_data['Name'];
	}
	if ( ! empty( $nxtcc_floating_chat_data['Description'] ) ) {
		$nxtcc_floating_chat_description = (string) $nxtcc_floating_chat_data['Description'];
	}
	if ( ! empty( $nxtcc_floating_chat_data['PluginURI'] ) ) {
		$nxtcc_floating_chat_url = (string) $nxtcc_floating_chat_data['PluginURI'];
	}
}

if ( $nxtcc_floating_chat_active ) {
	$nxtcc_floating_chat_primary = array(
		'label'    => __( 'Activated', 'nxt-cloud-chat' ),
		'url'      => '',
		'external' => false,
		'disabled' => true,
	);
} elseif ( file_exists( $nxtcc_floating_chat_plugin_file ) ) {
	$nxtcc_floating_chat_primary = array(
		'label'    => __( 'Activate', 'nxt-cloud-chat' ),
		'url'      => wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'activate',
					'plugin' => $nxtcc_floating_chat_plugin_basename,
				),
				self_admin_url( 'plugins.php' )
			),
			'activate-plugin_' . $nxtcc_floating_chat_plugin_basename
		),
		'external' => false,
	);
} else {
	$nxtcc_floating_chat_primary = array(
		'label'    => __( 'Install', 'nxt-cloud-chat' ),
		'url'      => wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'install-plugin',
					'plugin' => 'nxt-floating-chat-widget',
				),
				self_admin_url( 'update.php' )
			),
			'install-plugin_nxt-floating-chat-widget'
		),
		'external' => false,
	);
}

/**
 * Add-ons list.
 *
 * Keys are internal identifiers. Each item may define:
 * - slug, name, badge, icon_url, description
 * - primary CTA { label, url }
 * - secondary CTA { label, url }
 */
$nxtcc_addons = array(
	'nxtcc-pro'         => array(
		'slug'        => 'nxtcc-pro',
		'name'        => __( 'NXT Cloud Chat Pro', 'nxt-cloud-chat' ),
		'badge'       => 'pro',
		'icon_url'    => $nxtcc_default_icon_url,
		'description' => __( 'Unlock templates, Bulk messaging, WooCommerce automation, Abandoned cart recovery, and priority support.', 'nxt-cloud-chat' ),
		'primary'     => array(
			'label' => __( 'Upgrade', 'nxt-cloud-chat' ),
			'url'   => $nxtcc_product_url,
		),
		'secondary'   => array(
			'label' => __( 'Learn More', 'nxt-cloud-chat' ),
			'url'   => $nxtcc_product_url,
		),
	),
	'nxt-floating-chat' => array(
		'slug'        => 'nxt-floating-chat',
		'name'        => $nxtcc_floating_chat_name,
		'badge'       => 'free',
		'badge_label' => __( 'FREE', 'nxt-cloud-chat' ),
		'icon_url'    => $nxtcc_floating_chat_icon_url,
		'description' => $nxtcc_floating_chat_description,
		'primary'     => $nxtcc_floating_chat_primary,
		'secondary'   => array(
			'label'    => __( 'Learn More', 'nxt-cloud-chat' ),
			'url'      => $nxtcc_floating_chat_url,
			'external' => true,
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
					src="<?php echo esc_url( trailingslashit( NXTCC_PLUGIN_URL ) . 'admin/assets/vendor/images/nxtwebsite.png' ); ?>"
					alt="<?php esc_attr_e( 'NXTWEBSITE', 'nxt-cloud-chat' ); ?>"
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
			$nxtcc_badge       = isset( $nxtcc_addon['badge'] ) ? (string) $nxtcc_addon['badge'] : '';
			$nxtcc_badge_label = isset( $nxtcc_addon['badge_label'] ) ? (string) $nxtcc_addon['badge_label'] : '';
			$nxtcc_icon_url    = isset( $nxtcc_addon['icon_url'] ) ? (string) $nxtcc_addon['icon_url'] : '';
			if ( '' === $nxtcc_badge_label && '' !== $nxtcc_badge ) {
				$nxtcc_badge_label = ( 'pro' === $nxtcc_badge ) ? __( 'PRO', 'nxt-cloud-chat' ) : __( 'New', 'nxt-cloud-chat' );
			}
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
								<?php echo esc_html( $nxtcc_badge_label ); ?>
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
							<?php $nxtcc_secondary_external = ! isset( $nxtcc_addon['secondary']['external'] ) || (bool) $nxtcc_addon['secondary']['external']; ?>
							<a
								href="<?php echo esc_url( $nxtcc_addon['secondary']['url'] ); ?>"
								class="nxtcc-app-cta-secondary"
								<?php echo $nxtcc_secondary_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
							>
								<?php echo esc_html( $nxtcc_addon['secondary']['label'] ); ?>
							</a>
						<?php endif; ?>

						<?php if ( ! empty( $nxtcc_addon['primary']['label'] ) ) : ?>
							<?php $nxtcc_primary_external = ! isset( $nxtcc_addon['primary']['external'] ) || (bool) $nxtcc_addon['primary']['external']; ?>
							<?php $nxtcc_primary_disabled = ! empty( $nxtcc_addon['primary']['disabled'] ); ?>
							<?php if ( $nxtcc_primary_disabled ) : ?>
								<span class="button nxtcc-app-cta-primary is-disabled" aria-disabled="true">
									<?php echo esc_html( $nxtcc_addon['primary']['label'] ); ?>
								</span>
							<?php elseif ( ! empty( $nxtcc_addon['primary']['url'] ) ) : ?>
								<a
									href="<?php echo esc_url( $nxtcc_addon['primary']['url'] ); ?>"
									class="button nxtcc-app-cta-primary"
									<?php echo $nxtcc_primary_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
								>
									<?php echo esc_html( $nxtcc_addon['primary']['label'] ); ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</div>
