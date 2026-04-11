/**
 * Block editor registration for the WhatsApp Login block.
 *
 * @package NXTCC
 */

/* global wp, jQuery, NXTCC_BLOCKS */
(function ($, wp) {
	const registerBlockType = wp.blocks.registerBlockType;
	const el                = wp.element.createElement;
	const __                = wp.i18n.__;
	const useBlockProps     =
		(wp.blockEditor && wp.blockEditor.useBlockProps) ||
		(wp.editor && wp.editor.useBlockProps);

	// Use the localized SVG markup as the icon.
	const Icon =
		NXTCC_BLOCKS && NXTCC_BLOCKS.whatsappIcon
			? el( wp.element.RawHTML, null, NXTCC_BLOCKS.whatsappIcon )
			: 'smiley'; // Fallback dashicon if the SVG is missing.

	function BlockPreview() {
		const props = useBlockProps
			? useBlockProps(
				{
					className: 'nxtcc-whatsapp-login-block',
					style: {
						minHeight: '80px',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						border: '1px dashed #ccc',
						padding: '12px',
					},
				}
			)
			: { className: 'nxtcc-whatsapp-login-block' };

		return el(
			'div',
			props,
			el(
				'div',
				{ style: { textAlign: 'center' } },
				el( 'strong', null, __( 'NXTCC: WhatsApp Login', 'nxt-cloud-chat' ) ),
				el(
					'div',
					{ style: { marginTop: '4px', opacity: 0.8 } },
					__(
						'This block renders the WhatsApp Login widget on the front end.',
						'nxt-cloud-chat'
					)
				)
			)
		);
	}

	registerBlockType(
		'nxtcc/whatsapp-login',
		{
			title: __( 'NXTCC: WhatsApp Login', 'nxt-cloud-chat' ),
			icon: Icon, // Uses icon.svg.
			category: 'widgets',
			edit: function Edit() {
				return el( BlockPreview );
			},
			save: function Save() {
				return null;
			}, // Dynamic via PHP.
		}
	);
})( jQuery, window.wp );

