<?php
/**
 * Registers the WhatsApp Login Gutenberg block.
 *
 * Loads editor assets, registers the block via block.json,
 * and provides a dynamic render callback for frontend output.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {

		// Editor assets (editor-only).
		$handle_js  = 'nxtcc-whatsapp-login-block-editor';
		$handle_css = 'nxtcc-whatsapp-login-block-editor-css';

		// Register editor script.
		wp_register_script(
			$handle_js,
			plugins_url( 'blocks/whatsapp-login/editor.js', NXTCC_PLUGIN_FILE ),
			array(
				'wp-blocks',
				'wp-element',
				'wp-i18n',
				'wp-components',
				// Prefer modern handle; keep legacy for older WordPress versions.
				'wp-block-editor',
				'wp-editor',
			),
			NXTCC_VERSION,
			true
		);

		// Pass raw SVG markup to JavaScript for the block icon.
		$svg_path = NXTCC_PLUGIN_DIR . 'admin/assets/vendor/images/widget-icon.svg';
		$svg_icon = '';

		if ( file_exists( $svg_path ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();

			global $wp_filesystem;
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$raw_svg = $wp_filesystem->get_contents( $svg_path );
				if ( is_string( $raw_svg ) ) {
					$svg_icon = $raw_svg;
				}
			}
		}

		wp_localize_script(
			$handle_js,
			'NXTCC_BLOCKS',
			array(
				'whatsappIcon' => $svg_icon,
			)
		);

		// Register optional editor styles if available.
		if ( file_exists( NXTCC_PLUGIN_DIR . 'blocks/whatsapp-login/editor.css' ) ) {
			wp_register_style(
				$handle_css,
				plugins_url( 'blocks/whatsapp-login/editor.css', NXTCC_PLUGIN_FILE ),
				array( 'wp-edit-blocks' ),
				NXTCC_VERSION
			);
		}

		// Register block via block.json with editor handles and dynamic rendering.
		$block_json = NXTCC_PLUGIN_DIR . 'blocks/whatsapp-login/block.json';

		if ( file_exists( $block_json ) ) {
			register_block_type(
				$block_json,
				array(
					'editor_script'   => $handle_js,
					'editor_style'    => wp_style_is( $handle_css, 'registered' ) ? $handle_css : null,

					/**
					 * Render callback for the WhatsApp login block.
					 *
					 * @param array  $attributes Block attributes.
					 * @param string $content    Block inner content (unused).
					 * @return string Rendered HTML.
					 */
					'render_callback' => function ( array $attributes, string $content ): string {

						unset( $content );

						// Ensure front-end assets are loaded.
						if ( function_exists( 'nxtcc_auth_enqueue_login_widget_assets' ) ) {
							nxtcc_auth_enqueue_login_widget_assets();
						}

						// Render using existing shortcode-compatible renderer.
						if ( function_exists( 'nxtcc_render_login_whatsapp' ) ) {
							return (string) nxtcc_render_login_whatsapp( $attributes );
						}

						return '';
					},
				)
			);
		}
	}
);
