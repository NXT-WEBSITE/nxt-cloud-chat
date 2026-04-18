<?php
/**
 * Admin WhatsApp Inbox + Chat view (Received Messages).
 *
 * This template renders the DOM skeleton for the admin chat UI:
 * - Left panel: chat inbox list + search input
 * - Right panel: chat header, message thread, composer, and forward/reply UI
 *
 * Data used by the JavaScript:
 * - Current admin user's WhatsApp connection settings (business_account_id, phone_number_id)
 * - A nonce used by admin AJAX endpoints for chat operations
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

if ( ! NXTCC_Access_Control::current_user_can_any( array( 'nxtcc_access_chat' ) ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nxt-cloud-chat' ) );
}

if ( ! class_exists( 'NXTCC_Pages_DAO' ) ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/pages-dao/class-nxtcc-pages-dao.php';
}

/*
 * Ensure WordPress Media Library scripts are available.
 * The admin JS can use wp.media to pick existing attachments to send.
 */
if ( function_exists( 'wp_enqueue_media' ) ) {
	wp_enqueue_media();
}

$nxtcc_active_tenant = NXTCC_Access_Control::get_current_tenant_context();
$nxtcc_user_mailid   = isset( $nxtcc_active_tenant['user_mailid'] ) ? sanitize_email( (string) $nxtcc_active_tenant['user_mailid'] ) : '';

/*
 * Load the latest connection settings row for this admin.
 * DAO handles SQL + caching so templates stay clean.
 */
$nxtcc_row = NXTCC_Access_Control::get_settings_row_for_tenant( $nxtcc_active_tenant );

if ( ! is_object( $nxtcc_row ) ) {
	$nxtcc_row = NXTCC_Pages_DAO::get_latest_settings_row_for_user( $nxtcc_user_mailid );
}

/*
 * Pass connection identifiers to the widget root element for JS boot.
 * Keep empty strings when not configured to avoid undefined attributes.
 */
$nxtcc_business_account_id = ( $nxtcc_row && isset( $nxtcc_row->business_account_id ) ) ? (string) $nxtcc_row->business_account_id : '';
$nxtcc_phone_number_id     = ( $nxtcc_row && isset( $nxtcc_row->phone_number_id ) ) ? (string) $nxtcc_row->phone_number_id : '';
$nxtcc_instance_id         = isset( $instance_id ) ? (string) $instance_id : 'adminchat';
?>

<div
	class="nxtcc-whatsapp-widget"
	data-instance="<?php echo esc_attr( $nxtcc_instance_id ); ?>"
	data-business-account-id="<?php echo esc_attr( $nxtcc_business_account_id ); ?>"
	data-phone-number-id="<?php echo esc_attr( $nxtcc_phone_number_id ); ?>"
>

	<div class="nxtcc-inbox-panel">
		<div class="nxtcc-inbox-header">
			<div class="nxtcc-inbox-title">
				<?php esc_html_e( 'Chats', 'nxt-cloud-chat' ); ?>
			</div>

			<input
				type="text"
				class="nxtcc-inbox-search"
				placeholder="<?php esc_attr_e( 'Search contacts...', 'nxt-cloud-chat' ); ?>"
			/>
		</div>

		<div class="nxtcc-chat-list">
			<?php
			/*
			 * Inbox rows (chat heads) are rendered by admin JS after polling.
			 */
			?>
		</div>

		<input
			type="hidden"
			class="nxtcc-inbox-nonce"
			value="<?php echo esc_attr( wp_create_nonce( 'nxtcc_received_messages' ) ); ?>"
		/>
	</div>

	<div class="nxtcc-chat-panel">
		<div class="nxtcc-chat-header">

			<button
				type="button"
				class="nxtcc-chat-back-btn"
				title="<?php esc_attr_e( 'Back to Inbox', 'nxt-cloud-chat' ); ?>"
			>
				&#8592;
			</button>

			<div class="nxtcc-chat-contact-info">
				<div class="nxtcc-chat-contact-name">
					<?php esc_html_e( 'Select a contact', 'nxt-cloud-chat' ); ?>
				</div>
				<div class="nxtcc-chat-contact-number"></div>
			</div>

			<div class="nxtcc-chat-actions" style="display:none;">
				<span class="nxtcc-selected-count">0</span>

				<button
					type="button"
					class="nxtcc-act-reply"
					title="<?php esc_attr_e( 'Reply', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Reply', 'nxt-cloud-chat' ); ?>"
				>
					&#11178;
				</button>

				<button
					type="button"
					class="nxtcc-act-forward"
					title="<?php esc_attr_e( 'Forward', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Forward', 'nxt-cloud-chat' ); ?>"
				>
					&#10150;
				</button>

				<button
					type="button"
					class="nxtcc-act-favorite"
					title="<?php echo esc_attr__( 'Favorite', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php echo esc_attr__( 'Favorite', 'nxt-cloud-chat' ); ?>"
				>
					<span class="nxtcc-act-favorite-icon" aria-hidden="true">☆</span>
				</button>

				<button
					type="button"
					class="nxtcc-act-delete"
					title="<?php esc_attr_e( 'Delete', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Delete', 'nxt-cloud-chat' ); ?>"
				>
					<i class="fa-solid fa-trash" aria-hidden="true"></i>
				</button>

				<button
					type="button"
					class="nxtcc-act-close"
					title="<?php esc_attr_e( 'Cancel selection', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Cancel selection', 'nxt-cloud-chat' ); ?>"
				>
					✕
				</button>
			</div>
		</div>

		<div class="nxtcc-chat-thread">
			<?php
			/*
			 * Chat bubbles are rendered by the thread module after loading a contact.
			 */
			?>
		</div>

		<button
			type="button"
			class="nxtcc-scroll-bottom"
			title="<?php esc_attr_e( 'Jump to latest', 'nxt-cloud-chat' ); ?>"
		>
			⤓
		</button>

		<div class="nxtcc-reply-strip" style="display:none;">
			<div class="nxtcc-reply-preview">
				<div class="nxtcc-reply-label">
					<?php esc_html_e( 'Replying to:', 'nxt-cloud-chat' ); ?>
				</div>
				<div class="nxtcc-reply-snippet"></div>
			</div>

			<button
				type="button"
				class="nxtcc-reply-cancel"
				title="<?php esc_attr_e( 'Cancel', 'nxt-cloud-chat' ); ?>"
				aria-label="<?php esc_attr_e( 'Cancel', 'nxt-cloud-chat' ); ?>"
			>
				✕
			</button>
		</div>

		<div class="nxtcc-chat-input-wrapper" style="display:none;">
			<div class="nxtcc-chat-input-bar">
				<button
					type="button"
					class="nxtcc-upload-btn"
					title="<?php esc_attr_e( 'Attach', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Attach', 'nxt-cloud-chat' ); ?>"
				>
					<i class="fa-solid fa-paperclip" aria-hidden="true"></i>
				</button>

				<input
					type="file"
					class="nxtcc-file-input"
					style="display:none"
					accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
					multiple
				/>

				<textarea
					class="nxtcc-chat-textarea"
					rows="1"
					placeholder="<?php esc_attr_e( 'Type a message or caption...', 'nxt-cloud-chat' ); ?>"
				></textarea>

				<button
					type="button"
					class="nxtcc-send-msg-btn"
					title="<?php esc_attr_e( 'Send', 'nxt-cloud-chat' ); ?>"
					aria-label="<?php esc_attr_e( 'Send', 'nxt-cloud-chat' ); ?>"
				>
					&#9658;
				</button>
			</div>
		</div>

		<div class="nxtcc-forward-modal" style="display:none;">
			<div class="nxtcc-forward-backdrop"></div>

			<div class="nxtcc-forward-dialog">
				<div class="nxtcc-forward-header">
					<div class="nxtcc-forward-title">
						<?php esc_html_e( 'Forward to (within 24h)', 'nxt-cloud-chat' ); ?>
					</div>

					<button
						type="button"
						class="nxtcc-forward-close"
						aria-label="<?php esc_attr_e( 'Close', 'nxt-cloud-chat' ); ?>"
					>
						✕
					</button>
				</div>

				<div class="nxtcc-forward-body">
					<input
						type="text"
						class="nxtcc-forward-search"
						placeholder="<?php esc_attr_e( 'Search contacts...', 'nxt-cloud-chat' ); ?>"
					/>

					<div class="nxtcc-forward-list"></div>

					<div class="nxtcc-forward-empty" style="display:none;">
						<?php esc_html_e( 'No contacts in the last 24 hours.', 'nxt-cloud-chat' ); ?>
					</div>
				</div>

				<div class="nxtcc-forward-footer">
					<span class="nxtcc-forward-selected-count">
						0 <?php esc_html_e( 'selected', 'nxt-cloud-chat' ); ?>
					</span>

					<button type="button" class="nxtcc-forward-send" disabled>
						<?php esc_html_e( 'Send', 'nxt-cloud-chat' ); ?>
					</button>
				</div>
			</div>
		</div>

	</div>
</div>
