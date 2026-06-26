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

$nxtcc_active_tenant   = NXTCC_Access_Control::get_current_tenant_context();
$nxtcc_user_mailid     = isset( $nxtcc_active_tenant['user_mailid'] ) ? sanitize_email( (string) $nxtcc_active_tenant['user_mailid'] ) : '';
$nxtcc_crm_policy      = NXTCC_CRM_Access_Policy::get_policy( 0, $nxtcc_active_tenant, 'nxtcc_access_chat' );
$nxtcc_can_manage_chat = NXTCC_CRM_Access_Policy::can_manage( $nxtcc_crm_policy );

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
	class="nxtcc-whatsapp-widget<?php echo $nxtcc_can_manage_chat ? '' : ' is-view-only'; ?>"
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
			<select class="nxtcc-ticket-view" aria-label="<?php echo esc_attr__( 'Saved ticket view', 'nxt-cloud-chat' ); ?>">
				<option value="all"><?php esc_html_e( 'All Tickets', 'nxt-cloud-chat' ); ?></option>
				<option value="mine"><?php esc_html_e( 'My Tickets', 'nxt-cloud-chat' ); ?></option>
				<option value="team"><?php esc_html_e( 'Team Queue', 'nxt-cloud-chat' ); ?></option>
				<option value="unassigned"><?php esc_html_e( 'Unassigned', 'nxt-cloud-chat' ); ?></option>
				<option value="overdue"><?php esc_html_e( 'Overdue', 'nxt-cloud-chat' ); ?></option>
				<option value="resolved"><?php esc_html_e( 'Recently Resolved', 'nxt-cloud-chat' ); ?></option>
			</select>
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

			<button
				type="button"
				class="nxtcc-chat-profile-btn nxtcc-contact-profile-trigger"
				title="<?php echo esc_attr__( 'Open contact profile', 'nxt-cloud-chat' ); ?>"
				aria-label="<?php echo esc_attr__( 'Open contact profile', 'nxt-cloud-chat' ); ?>"
				disabled
			>
				<i class="fa-solid fa-address-card" aria-hidden="true"></i>
				<span><?php esc_html_e( 'Profile', 'nxt-cloud-chat' ); ?></span>
			</button>

			<button
				type="button"
				class="nxtcc-ticket-toggle"
				title="<?php echo esc_attr__( 'Open ticket details', 'nxt-cloud-chat' ); ?>"
				aria-label="<?php echo esc_attr__( 'Open ticket details', 'nxt-cloud-chat' ); ?>"
				disabled
			>
				<i class="fa-solid fa-ticket" aria-hidden="true"></i>
			</button>

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

	<aside class="nxtcc-ticket-panel" aria-label="<?php echo esc_attr__( 'Conversation ticket', 'nxt-cloud-chat' ); ?>">
		<div class="nxtcc-ticket-panel-header">
			<div class="nxtcc-ticket-selector-wrap">
				<label class="screen-reader-text" for="nxtcc-ticket-selector-<?php echo esc_attr( $nxtcc_instance_id ); ?>"><?php esc_html_e( 'Selected ticket', 'nxt-cloud-chat' ); ?></label>
				<select id="nxtcc-ticket-selector-<?php echo esc_attr( $nxtcc_instance_id ); ?>" class="nxtcc-ticket-selector" disabled>
					<option value=""><?php esc_html_e( 'No ticket selected', 'nxt-cloud-chat' ); ?></option>
				</select>
				<div class="nxtcc-ticket-updated"></div>
			</div>
			<div class="nxtcc-ticket-header-actions">
				<button type="button" class="nxtcc-ticket-watch" title="<?php echo esc_attr__( 'Follow ticket', 'nxt-cloud-chat' ); ?>" disabled>
					<i class="fa-regular fa-eye" aria-hidden="true"></i>
				</button>
				<button type="button" class="nxtcc-ticket-new" title="<?php echo esc_attr__( 'Create new ticket', 'nxt-cloud-chat' ); ?>">
					<i class="fa-solid fa-plus" aria-hidden="true"></i>
				</button>
				<button type="button" class="nxtcc-ticket-close" title="<?php echo esc_attr__( 'Close ticket details', 'nxt-cloud-chat' ); ?>">
					<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
				</button>
			</div>
		</div>

		<div class="nxtcc-ticket-panel-body">
			<label class="nxtcc-ticket-field">
				<span><?php esc_html_e( 'Subject', 'nxt-cloud-chat' ); ?></span>
				<input type="text" class="nxtcc-ticket-subject" maxlength="191">
			</label>
			<label class="nxtcc-ticket-field">
				<span><?php esc_html_e( 'Category', 'nxt-cloud-chat' ); ?></span>
				<select class="nxtcc-ticket-category"></select>
			</label>
			<button type="button" class="nxtcc-ticket-manage-categories"><?php esc_html_e( 'Manage categories', 'nxt-cloud-chat' ); ?></button>
			<label class="nxtcc-ticket-field nxtcc-ticket-message-field">
				<span><?php esc_html_e( 'Message', 'nxt-cloud-chat' ); ?></span>
				<textarea class="nxtcc-ticket-message" rows="4" maxlength="4096" placeholder="<?php echo esc_attr__( 'Write a message to the contact', 'nxt-cloud-chat' ); ?>"></textarea>
			</label>
			<div class="nxtcc-ticket-field-row">
				<label class="nxtcc-ticket-field">
					<span><?php esc_html_e( 'Status', 'nxt-cloud-chat' ); ?></span>
					<select class="nxtcc-ticket-status"></select>
				</label>
				<label class="nxtcc-ticket-field">
					<span><?php esc_html_e( 'Priority', 'nxt-cloud-chat' ); ?></span>
					<select class="nxtcc-ticket-priority"></select>
				</label>
			</div>
			<label class="nxtcc-ticket-field nxtcc-ticket-snooze-field">
				<span><?php esc_html_e( 'Snoozed Until', 'nxt-cloud-chat' ); ?></span>
				<input type="datetime-local" class="nxtcc-ticket-snoozed-until">
			</label>
			<div class="nxtcc-ticket-sla">
				<div>
					<span><?php esc_html_e( 'First response due', 'nxt-cloud-chat' ); ?></span>
					<strong class="nxtcc-ticket-first-response-due"></strong>
					<input type="datetime-local" class="nxtcc-ticket-first-response-input">
				</div>
				<div>
					<span><?php esc_html_e( 'Resolution due', 'nxt-cloud-chat' ); ?></span>
					<strong class="nxtcc-ticket-resolution-due"></strong>
					<input type="datetime-local" class="nxtcc-ticket-resolution-input">
				</div>
				<button type="button" class="nxtcc-ticket-sla-edit" title="<?php echo esc_attr__( 'Edit SLA dates', 'nxt-cloud-chat' ); ?>">
					<i class="fa-solid fa-pen" aria-hidden="true"></i>
				</button>
			</div>

			<div class="nxtcc-ticket-section nxtcc-ticket-assignment-section">
				<h4><?php esc_html_e( 'Assignment & Handoff', 'nxt-cloud-chat' ); ?></h4>
				<select class="nxtcc-ticket-assignment">
					<option value=""><?php esc_html_e( 'Choose a member or team', 'nxt-cloud-chat' ); ?></option>
				</select>
				<textarea class="nxtcc-ticket-handoff-note" rows="3" placeholder="<?php echo esc_attr__( 'Required when handing off an assigned ticket', 'nxt-cloud-chat' ); ?>"></textarea>
				<input type="text" class="nxtcc-ticket-handoff-reason" maxlength="191" placeholder="<?php echo esc_attr__( 'Reason (optional)', 'nxt-cloud-chat' ); ?>">
			</div>

			<div class="nxtcc-ticket-section nxtcc-ticket-note-section">
				<h4><?php esc_html_e( 'Internal Note', 'nxt-cloud-chat' ); ?></h4>
				<textarea class="nxtcc-ticket-note" rows="3" placeholder="<?php echo esc_attr__( 'Visible only to the team', 'nxt-cloud-chat' ); ?>"></textarea>
			</div>

			<div class="nxtcc-ticket-save-actions">
				<button type="button" class="nxtcc-ticket-save"><?php esc_html_e( 'Save Ticket', 'nxt-cloud-chat' ); ?></button>
				<button type="button" class="nxtcc-ticket-save-send"><?php esc_html_e( 'Save & Send', 'nxt-cloud-chat' ); ?></button>
			</div>

			<div class="nxtcc-ticket-section">
				<h4><?php esc_html_e( 'Activity', 'nxt-cloud-chat' ); ?></h4>
				<div class="nxtcc-ticket-activity"></div>
			</div>
		</div>
	</aside>
</div>

<div class="nxtcc-ticket-category-modal" hidden>
	<div class="nxtcc-ticket-category-backdrop"></div>
	<div class="nxtcc-ticket-category-dialog" role="dialog" aria-modal="true" aria-labelledby="nxtcc-ticket-category-title-<?php echo esc_attr( $nxtcc_instance_id ); ?>">
		<div class="nxtcc-ticket-category-header">
			<h3 id="nxtcc-ticket-category-title-<?php echo esc_attr( $nxtcc_instance_id ); ?>"><?php esc_html_e( 'Ticket Categories', 'nxt-cloud-chat' ); ?></h3>
			<button type="button" class="nxtcc-ticket-category-close" title="<?php echo esc_attr__( 'Close categories', 'nxt-cloud-chat' ); ?>">
				<i class="fa-solid fa-xmark" aria-hidden="true"></i>
			</button>
		</div>
		<div class="nxtcc-ticket-category-body">
			<div class="nxtcc-ticket-category-list"></div>
			<form class="nxtcc-ticket-category-form">
				<input type="hidden" class="nxtcc-ticket-category-id" value="0">
				<label>
					<span><?php esc_html_e( 'Category name', 'nxt-cloud-chat' ); ?></span>
					<input type="text" class="nxtcc-ticket-category-name" maxlength="100" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Color', 'nxt-cloud-chat' ); ?></span>
					<input type="color" class="nxtcc-ticket-category-color" value="#2271b1">
				</label>
				<label>
					<span><?php esc_html_e( 'Description', 'nxt-cloud-chat' ); ?></span>
					<input type="text" class="nxtcc-ticket-category-description" maxlength="500">
				</label>
				<div class="nxtcc-ticket-category-form-actions">
					<button type="button" class="nxtcc-ticket-category-reset"><?php esc_html_e( 'New', 'nxt-cloud-chat' ); ?></button>
					<button type="submit" class="nxtcc-ticket-category-save"><?php esc_html_e( 'Save Category', 'nxt-cloud-chat' ); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>

<?php nxtcc_render_contact_profile_modal(); ?>
