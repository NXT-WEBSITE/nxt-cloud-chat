/**
 * Conversation ticket sidebar for the admin Inbox.
 *
 * @package NXTCC
 */

/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	const Chat = window.NXTCCChat;
	if ( ! Chat || ! Chat.util ) {
		return;
	}

	const U = Chat.util;
	Chat.tickets = Chat.tickets || {};

	Chat.tickets.start = function ( ctx ) {
		if ( ! ctx || ! ctx.$widget ) {
			return;
		}

		const $widget         = ctx.$widget;
		const $panel          = $widget.find( '.nxtcc-ticket-panel' );
		const $toggle         = $widget.find( '.nxtcc-ticket-toggle' );
		const toggleLabel     = $toggle.attr( 'aria-label' ) || 'Open ticket details';
		const $selector       = $panel.find( '.nxtcc-ticket-selector' );
		const $watch          = $panel.find( '.nxtcc-ticket-watch' );
		const $newTicket      = $panel.find( '.nxtcc-ticket-new' );
		const $subject        = $panel.find( '.nxtcc-ticket-subject' );
		const $issue          = $panel.find( '.nxtcc-ticket-issue' );
		const $category       = $panel.find( '.nxtcc-ticket-category' );
		const $message        = $panel.find( '.nxtcc-ticket-message' );
		const $status         = $panel.find( '.nxtcc-ticket-status' );
		const $priority       = $panel.find( '.nxtcc-ticket-priority' );
		const $snoozedUntil   = $panel.find( '.nxtcc-ticket-snoozed-until' );
		const $firstDue       = $panel.find( '.nxtcc-ticket-first-response-input' );
		const $resolutionDue  = $panel.find( '.nxtcc-ticket-resolution-input' );
		const $assignment     = $panel.find( '.nxtcc-ticket-assignment' );
		const $handoffNote    = $panel.find( '.nxtcc-ticket-handoff-note' );
		const $handoffReason  = $panel.find( '.nxtcc-ticket-handoff-reason' );
		const $note           = $panel.find( '.nxtcc-ticket-note' );
		const $save           = $panel.find( '.nxtcc-ticket-save' );
		const $saveSend       = $panel.find( '.nxtcc-ticket-save-send' );
		const $categoryModal  = $( '.nxtcc-ticket-category-modal' ).first();
		const ns              = '.nxtccTickets' + U.toStr( ctx.instanceId || '' );
		let saving = false;
		let loadingTicket = false;
		let replyExpires = 0;
		let replyTimer = null;
		let ticketRequest = 0;
		let privateNoteOriginal = '';
		let countContactId = 0;
		const textFields = [
			{ input: $issue, limit: 5000, label: 'Issue', original: function () { return ctx.state.conversation && ctx.state.conversation.issue; } },
			{ input: $note, limit: 5000, label: 'Private note', original: function () { return privateNoteOriginal; } },
			{ input: $handoffNote, limit: 2000, label: 'Handoff note' },
			{ input: $handoffReason, limit: 191, label: 'Reason' },
			{ input: $message, limit: 4096, label: 'Message' }
		];
		textFields.forEach( function ( field, index ) {
			const id = 'nxtcc-ticket-field-count-' + U.toStr( ctx.instanceId || '' ) + '-' + index;
			field.input.next( '.nxtcc-ticket-field-count' ).attr( 'id', id );
			field.input.attr( 'aria-describedby', id );
		} );

		function normalizedText( value ) {
			return U.toStr( value ).replace( /\r\n?/g, '\n' );
		}

		function textLimitError() {
			let error = '';
			textFields.forEach( function ( field ) {
				const value = normalizedText( field.input.val() );
				const length = Array.from( value ).length;
				const unchanged = field.original && value.trim() === normalizedText( field.original() ).trim();
				const invalid = length > field.limit && !unchanged;
				field.input.attr( 'aria-invalid', invalid ? 'true' : 'false' );
				field.input.next( '.nxtcc-ticket-field-count' )
					.text( length.toLocaleString() + ' / ' + field.limit.toLocaleString() )
					.toggleClass( 'is-over-limit', invalid );
				if ( invalid && !error ) {
					error = field.label + ' must be ' + field.limit.toLocaleString() + ' characters or fewer.';
				}
			} );
			return error;
		}

		function updateTicketCount( value ) {
			const count = Number.isFinite( Number( value ) ) ? Math.max( 0, Math.floor( Number( value ) ) ) : 0;
			countContactId = Number( ctx.state.chatContactId || 0 );
			$toggle.find( '.nxtcc-ticket-toggle-count' ).text( count > 0 ? String( count ) : '' ).prop( 'hidden', count === 0 );
			$toggle.attr( { 'aria-label': count > 0 ? toggleLabel + ' (' + count + ')' : toggleLabel, title: count > 0 ? toggleLabel + ' (' + count + ')' : toggleLabel } );
		}

		ctx.state                   = ctx.state || {};
		ctx.api                     = ctx.api || {};
		ctx.api.tickets             = ctx.api.tickets || {};
		ctx.state.ticketPermissions = {};
		ctx.state.ticketCategories  = [];
		ctx.state.contactTickets    = [];
		ctx.state.isNewTicket       = false;

		function errorMessage( resp, fallback ) {
			return resp && resp.data && resp.data.message ? U.toStr( resp.data.message ) : fallback;
		}

		function post( action, data ) {
			return $.post(
				Chat.cfg.ajaxurl,
				Object.assign(
					{
						action: action,
						nonce: ctx.nonce,
					},
					data || {}
				)
			);
		}

		function setSelectOptions( $select, options, selected ) {
			const select = $select.get( 0 );
			if ( ! select ) {
				return;
			}

			U.safeEmpty( select );
			Object.keys( options || {} ).forEach( function ( value ) {
				U.safeAppend( select, U.el( 'option', { value: value }, U.toStr( options[ value ] ) ) );
			} );
			select.value = U.toStr( selected || '' );
		}

		function renderAssignmentOptions( targets, selected ) {
			const select = $assignment.get( 0 );
			if ( ! select ) {
				return;
			}

			U.safeEmpty( select );
			U.safeAppend( select, U.el( 'option', { value: '' }, 'Choose a member or team' ) );
			const users = targets && targets.users ? targets.users : [];
			const teams = targets && targets.teams ? targets.teams : ( targets && targets.roles ? targets.roles : [] );

			if ( users.length ) {
				const usersGroup = U.el( 'optgroup', { label: 'Members' } );
				users.forEach( function ( user ) {
					U.safeAppend(
						usersGroup,
						U.el( 'option', { value: 'user:' + U.toStr( user.id ) }, U.toStr( user.label || user.email || user.id ) )
					);
				} );
				U.safeAppend( select, usersGroup );
			}

			if ( teams.length ) {
				const teamsGroup = U.el( 'optgroup', { label: 'Teams' } );
				teams.forEach( function ( team ) {
					U.safeAppend(
						teamsGroup,
						U.el( 'option', { value: 'role:' + U.toStr( team.key ) }, U.toStr( team.label || team.key ) )
					);
				} );
				U.safeAppend( select, teamsGroup );
			}
			select.value = U.toStr( selected || '' );
		}

		function renderTicketOptions( tickets, selectedId, isNew ) {
			const select = $selector.get( 0 );
			if ( ! select ) {
				return;
			}

			U.safeEmpty( select );
			if ( isNew ) {
				U.safeAppend( select, U.el( 'option', { value: 'new' }, 'New ticket' ) );
			}
			( tickets || [] ).forEach( function ( ticket ) {
				const label = U.toStr( ticket.ticket_number || 'Ticket' ) + ' - ' + U.toStr( ticket.status || 'open' );
				U.safeAppend( select, U.el( 'option', {
					value: U.toStr( ticket.id ),
					class: isWatching( ticket ) ? 'is-watching' : '',
				}, label ) );
			} );
			if ( ! isNew && ! ( tickets || [] ).length ) {
				U.safeAppend( select, U.el( 'option', { value: '' }, 'No ticket selected' ) );
			}
			select.value = isNew ? 'new' : U.toStr( selectedId || '' );
			$selector.toggleClass( 'is-watching', ! isNew && $( select ).find( 'option:selected' ).hasClass( 'is-watching' ) );
			$selector.prop( 'disabled', ! ( tickets || [] ).length && ! isNew );
		}

		function renderCategoryOptions( categories, selectedId, legacyName ) {
			const select = $category.get( 0 );
			if ( ! select ) {
				return;
			}

			U.safeEmpty( select );
			U.safeAppend( select, U.el( 'option', { value: '' }, 'Choose a category' ) );
			( categories || [] ).forEach( function ( item ) {
				if ( ! item.is_active && Number( item.id ) !== Number( selectedId ) ) {
					return;
				}
				const suffix = item.is_active ? '' : ' (Archived)';
				U.safeAppend( select, U.el( 'option', { value: U.toStr( item.id ) }, U.toStr( item.category_name ) + suffix ) );
			} );
			if ( Number( selectedId ) <= 0 && U.toStr( legacyName ).trim() ) {
				U.safeAppend( select, U.el( 'option', { value: '' }, U.toStr( legacyName ) + ' (Create or select a category)' ) );
			}
			select.value = Number( selectedId ) > 0 ? U.toStr( selectedId ) : '';
		}

		function activityLabel( activity ) {
			const labels = {
				conversation_assigned: 'Assignment updated',
				conversation_details_changed: 'Ticket details changed',
				conversation_first_response_recorded: 'First response recorded',
				conversation_priority_changed: 'Priority changed',
				conversation_status_changed: 'Status changed',
				internal_note_added: 'Private note',
				conversation_automation_note_added: 'Automated team note',
			};
			const type = U.toStr( activity && activity.activity_type ? activity.activity_type : '' );
			return labels[ type ] || type.replace( /_/g, ' ' );
		}

		function renderActivity( rows ) {
			const root = $panel.find( '.nxtcc-ticket-activity' ).get( 0 );
			if ( ! root ) {
				return;
			}

			U.safeEmpty( root );
			if ( ! rows || ! rows.length ) {
				U.safeAppend( root, U.el( 'div', { class: 'nxtcc-ticket-empty' }, 'No ticket activity yet.' ) );
				return;
			}

			rows.forEach( function ( activity ) {
				const item = U.el(
					'button',
					{
						type: 'button',
						class: 'nxtcc-ticket-activity-item',
						'data-activity-id': U.toStr( activity.id || '' ),
						title: 'Show this activity in the chat timeline',
					}
				);
				const head = U.el( 'div', { class: 'nxtcc-ticket-activity-head' } );
				U.safeAppend( head, U.el( 'strong', {}, activityLabel( activity ) ) );
				U.safeAppend( head, U.el( 'span', {}, U.toStr( activity.created_at_display || '' ) ) );
				U.safeAppend( item, head );
				U.safeAppend( item, U.el( 'div', { class: 'nxtcc-ticket-activity-actor' }, U.toStr( activity.actor_label || 'Unknown actor' ) ) );
				if ( activity.note_content ) {
					U.safeAppend( item, U.el( 'div', { class: 'nxtcc-ticket-activity-note' }, U.toStr( activity.note_content ) ) );
				}
				U.safeAppend( root, item );
			} );
		}

		function isWatching( conversation ) {
			return ( conversation && Array.isArray( conversation.watchers ) ? conversation.watchers : [] ).some( function ( watcher ) {
				return Boolean( watcher && watcher.is_current_user );
			} );
		}

		function applyPermissions() {
			const permissions = ctx.state.ticketPermissions || {};
			const canManage    = Boolean( permissions.can_manage && permissions.can_resolve );
			const canReassign  = Boolean( permissions.can_manage && permissions.can_reassign );
			const canNote      = Boolean( permissions.can_manage && permissions.can_note );
			const canSave      = canManage && canReassign;

			$subject.prop( 'disabled', ! canManage );
			$category.prop( 'disabled', ! canManage );
			$message.prop( 'disabled', ! canSave );
			$status.prop( 'disabled', ! canManage );
			$priority.prop( 'disabled', ! canManage );
			$snoozedUntil.prop( 'disabled', ! canManage );
			$firstDue.prop( 'disabled', ! canManage );
			$resolutionDue.prop( 'disabled', ! canManage );
			$panel.find( '.nxtcc-ticket-sla-edit' ).prop( 'disabled', ! canManage );
			$assignment.prop( 'disabled', ! canReassign );
			$handoffNote.prop( 'disabled', ! canReassign );
			$handoffReason.prop( 'disabled', ! canReassign );
			$note.prop( 'disabled', ! canNote );
			$issue.prop( 'disabled', ! canManage );
			updateSaveButtons();
			$newTicket.prop( 'disabled', ! canSave );
			$panel.find( '.nxtcc-ticket-manage-categories' ).prop( 'disabled', ! canManage );
		}

		function sendDisabledReason( limitError ) {
			const permissions = ctx.state.ticketPermissions || {};
			const current = ctx.state.conversation;
			if ( saving ) { return 'Saving ticket...'; }
			if ( loadingTicket ) { return 'Loading ticket details...'; }
			if ( !permissions.can_manage || !permissions.can_resolve || !permissions.can_reassign ) { return 'You do not have permission to save and send.'; }
			if ( limitError ) { return limitError; }
			if ( !U.toStr( $subject.val() ).trim() || !Number( $category.val() ) || !$assignment.val() ) { return 'Complete the subject, category, and assignment.'; }
			if ( ctx.state.isNewTicket && !U.toStr( $issue.val() ).trim() ) { return 'Describe the issue before creating the ticket.'; }
			if ( current && current.assignment_target && current.assignment_target !== $assignment.val() && !U.toStr( $handoffNote.val() ).trim() ) { return 'Enter a handoff note for the assignment change.'; }
			if ( $status.val() === 'snoozed' && !$snoozedUntil.val() ) { return 'Enter the snooze date and time.'; }
			if ( !U.toStr( $message.val() ).trim() ) { return 'Enter a message to send to the contact.'; }
			if ( replyExpires <= Date.now() ) { return 'The 24-hour reply window is closed or has not been confirmed.'; }
			return '';
		}

		function updateSaveButtons() {
			const limitError = textLimitError();
			const permissions = ctx.state.ticketPermissions || {};
			$save.prop( 'disabled', !!limitError || saving || loadingTicket || !permissions.can_manage || !permissions.can_resolve || !permissions.can_reassign );
			const reason = sendDisabledReason( limitError );
			const $wrap = $panel.find( '.nxtcc-ticket-send-wrap' );
			const $tip = $wrap.find( '.nxtcc-ticket-send-tooltip' );
			$saveSend.prop( 'disabled', !!reason );
			$tip.text( reason );
			$wrap.toggleClass( 'is-disabled', !!reason );
			if ( reason ) {
				$wrap.attr( { tabindex: '0', 'aria-describedby': $tip.attr( 'id' ), 'aria-label': 'Save & Send' } );
			} else {
				$wrap.removeAttr( 'tabindex aria-describedby aria-label' );
			}
		}

		function setReplyWindow( data ) {
			clearTimeout( replyTimer );
			replyExpires = data.can_reply_24hr === true ? Number( data.reply_window_expires_at || 0 ) * 1000 : 0;
			if ( replyExpires > Date.now() ) {
				replyTimer = setTimeout( updateSaveButtons, Math.min( replyExpires - Date.now() + 20, 86400000 ) );
			}
			updateSaveButtons();
		}

		function clearTransientFields() {
			$message.val( '' );
			$handoffNote.val( '' );
			$handoffReason.val( '' );
		}

		function renderConversation( conversation ) {
			ctx.state.conversation = conversation && 'object' === typeof conversation ? conversation : null;
			const current          = ctx.state.conversation;
			const isNew            = Boolean( ctx.state.isNewTicket );

			$toggle.prop( 'disabled', ! current && ! isNew );
			$watch.prop( 'disabled', ! current );
			$save.show();
			$saveSend.text( 'Save & Send' );
			$panel.toggleClass( 'is-new-ticket', isNew );
			$panel.find( '.nxtcc-ticket-updated' ).text( isNew ? 'Complete the required fields' : '' );
			$panel.find( '.nxtcc-ticket-activity' ).closest( '.nxtcc-ticket-section' ).toggle( ! isNew );

			if ( ! current ) {
				privateNoteOriginal = '';
				$note.val( '' );
				$issue.val( '' );
				$subject.val( '' );
				renderCategoryOptions( ctx.state.ticketCategories, 0, '' );
				$status.val( 'open' );
				$priority.val( 'normal' );
				$snoozedUntil.val( '' );
				$firstDue.val( '' );
				$resolutionDue.val( '' );
				$panel.find( '.nxtcc-ticket-first-response-due, .nxtcc-ticket-resolution-due' ).text( 'Set automatically' );
				$assignment.val( '' );
				$watch.removeClass( 'is-watching' );
				$selector.removeClass( 'is-watching' );
				clearTransientFields();
				return;
			}

			$panel.find( '.nxtcc-ticket-updated' ).text( current.updated_at_display ? 'Updated ' + U.toStr( current.updated_at_display ) : '' );
			$issue.val( U.toStr( current.issue || '' ) );
			$subject.val( U.toStr( current.subject || '' ) );
			renderCategoryOptions( ctx.state.ticketCategories, current.category_id, current.category );
			$status.val( 'unassigned' === U.toStr( current.status || '' ) ? 'open' : U.toStr( current.status || 'open' ) );
			$priority.val( U.toStr( current.priority || '' ) );
			$snoozedUntil.val( U.toStr( current.snoozed_until_local_input || '' ) );
			$firstDue.val( U.toStr( current.first_response_due_at_local_input || '' ) );
			$resolutionDue.val( U.toStr( current.resolution_due_at_local_input || '' ) );
			$panel.find( '.nxtcc-ticket-snooze-field' ).toggle( 'snoozed' === U.toStr( current.status || '' ) );
			$assignment.val( U.toStr( current.assignment_target || '' ) );
			$panel.find( '.nxtcc-ticket-first-response-due' )
				.text( U.toStr( current.first_response_due_at_display || 'Not set' ) )
				.toggleClass( 'is-overdue', Boolean( current.first_response_overdue ) );
			$panel.find( '.nxtcc-ticket-resolution-due' )
				.text( U.toStr( current.resolution_due_at_display || 'Not set' ) )
				.toggleClass( 'is-overdue', Boolean( current.resolution_overdue ) );
			$watch.toggleClass( 'is-watching', isWatching( current ) );
			$selector.toggleClass( 'is-watching', isWatching( current ) );
			$watch.attr( 'title', isWatching( current ) ? 'Stop following ticket' : 'Follow ticket' );
			clearTransientFields();
		}

		function renderPayload( data ) {
			const payload = data && 'object' === typeof data ? data : {};
			setReplyWindow( payload );
			ctx.state.ticketPermissions = payload.permissions || {};
			ctx.state.ticketCategories  = payload.categories || [];
			ctx.state.contactTickets    = payload.tickets || [];
			updateTicketCount( payload.ticket_count || 0 );
			ctx.state.isNewTicket       = false;
			const statuses = Object.assign( {}, payload.statuses || {} );
			delete statuses.unassigned;
			const selectedStatus = payload.conversation && 'unassigned' !== U.toStr( payload.conversation.status )
				? payload.conversation.status
				: 'open';
			setSelectOptions( $status, statuses, selectedStatus );
			setSelectOptions( $priority, payload.priorities || {}, payload.conversation && payload.conversation.priority );
			renderAssignmentOptions( payload.assignment_targets || {}, payload.conversation && payload.conversation.assignment_target );
			renderTicketOptions( ctx.state.contactTickets, payload.conversation && payload.conversation.id, false );
			renderConversation( payload.conversation || null );
			privateNoteOriginal = U.toStr( payload.private_note && payload.private_note.note_content || '' );
			$note.val( privateNoteOriginal );
			renderActivity( payload.activity || [] );
			applyPermissions();
			renderCategoryList();
		}

		function loadTicket( contactId, conversationId ) {
			privateNoteOriginal = '';
			$note.val( '' );
			const request = ++ticketRequest;
			setReplyWindow( {} );
			const id = parseInt( contactId || ctx.state.chatContactId, 10 ) || 0;
			if ( id !== countContactId ) { updateTicketCount( 0 ); }
			if ( id <= 0 ) {
				ctx.state.isNewTicket = false;
				renderConversation( null );
				return;
			}
			loadingTicket = true;
			applyPermissions();

			const data = { contact_id: id };
			if ( Number( conversationId ) > 0 ) {
				data.conversation_id = Number( conversationId );
			}
			$panel.addClass( 'is-loading' );
			post( 'nxtcc_get_conversation_ticket', data )
				.done( function ( resp ) {
					if ( request !== ticketRequest || id !== Number( ctx.state.chatContactId ) ) { return; }
					if ( resp && resp.success ) {
						renderPayload( resp.data );
						return;
					}
					window.alert( errorMessage( resp, 'Unable to load ticket details.' ) );
				} )
				.fail( function () {
					window.alert( 'Unable to load ticket details.' );
				} )
				.always( function () {
					if ( request !== ticketRequest ) { return; }
					loadingTicket = false;
					applyPermissions();
					$panel.removeClass( 'is-loading' );
				} );
		}

		function startNewTicket() {
			if ( saving || loadingTicket ) { return; }
			ctx.state.isNewTicket = true;
			renderTicketOptions( ctx.state.contactTickets, 0, true );
			renderConversation( null );
			renderActivity( [] );
			$panel.find( '.nxtcc-ticket-panel-body' ).scrollTop( 0 );
			$subject.trigger( 'focus' );
			applyPermissions();
		}

		function refreshInbox() {
			if ( ctx.api.inbox && ctx.api.inbox.refresh ) {
				ctx.api.inbox.refresh();
			}
		}

		function saveTicket( sendMessage ) {
			if ( saving || loadingTicket ) { return; }
			const limitError = textLimitError();
			if ( sendMessage && sendDisabledReason( limitError ) ) { return; }
			if ( limitError ) {
				window.alert( limitError );
				return;
			}
			const request      = ticketRequest;
			const contactId    = ctx.state.chatContactId;
			const conversation = ctx.state.conversation;
			const isNew        = Boolean( ctx.state.isNewTicket );
			const subject      = U.toStr( $subject.val() ).trim();
			const categoryId   = parseInt( $category.val(), 10 ) || 0;
			const message      = U.toStr( $message.val() ).trim();
			const target       = U.toStr( $assignment.val() );

			if ( ! subject || categoryId <= 0 || ! target ) {
				window.alert( 'Subject, category, and assignment are required.' );
				return;
			}
			if ( isNew && !U.toStr( $issue.val() ).trim() ) {
				window.alert( 'Describe the issue before creating the ticket.' );
				return;
			}

			saving = true;
			applyPermissions();
			$panel.addClass( 'is-loading' );
			post( 'nxtcc_save_conversation_ticket', {
				conversation_id: conversation ? conversation.id : 0,
				contact_id: ctx.state.chatContactId,
				subject: subject,
				issue: U.toStr( $issue.val() ),
				category_id: categoryId,
				message: message,
				send_message: sendMessage ? '1' : '0',
				status: U.toStr( $status.val() ),
				priority: U.toStr( $priority.val() ),
				snoozed_until: U.toStr( $snoozedUntil.val() ),
				first_response_due_at: U.toStr( $firstDue.val() ),
				resolution_due_at: U.toStr( $resolutionDue.val() ),
				assignment_target: target,
				handoff_note: U.toStr( $handoffNote.val() ),
				handoff_reason: U.toStr( $handoffReason.val() ),
				internal_note: U.toStr( $note.val() ).trim() === privateNoteOriginal.trim() ? '' : U.toStr( $note.val() ),
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					window.alert( errorMessage( resp, 'Unable to save the ticket.' ) );
					return;
				}
				if ( request !== ticketRequest || contactId !== ctx.state.chatContactId ) {
					refreshInbox();
					return;
				}

				const saved = resp.data && resp.data.conversation ? resp.data.conversation : null;
				if ( saved ) {
					ctx.state.conversation = saved;
					ctx.state.isNewTicket = false;
				}
				if ( resp.data && resp.data.message_error ) {
					window.alert( U.toStr( resp.data.message_error ) );
				}
				loadTicket( ctx.state.chatContactId, saved && saved.id ? saved.id : 0 );
				refreshInbox();
				if ( resp.data && resp.data.message_sent && ctx.api.thread && ctx.api.thread.reloadChatThread ) {
					ctx.api.thread.reloadChatThread();
				}
			} ).fail( function () {
				window.alert( 'Unable to save the ticket.' );
			} ).always( function () {
				saving = false;
				applyPermissions();
				$panel.removeClass( 'is-loading' );
			} );
		}

		function resetCategoryForm() {
			$categoryModal.find( '.nxtcc-ticket-category-id' ).val( '0' );
			$categoryModal.find( '.nxtcc-ticket-category-name' ).val( '' );
			$categoryModal.find( '.nxtcc-ticket-category-color' ).val( '#2271b1' );
			$categoryModal.find( '.nxtcc-ticket-category-description' ).val( '' );
		}

		function renderCategoryList() {
			const root = $categoryModal.find( '.nxtcc-ticket-category-list' ).get( 0 );
			if ( ! root ) {
				return;
			}
			U.safeEmpty( root );
			if ( ! ctx.state.ticketCategories.length ) {
				U.safeAppend( root, U.el( 'div', { class: 'nxtcc-ticket-empty' }, 'No categories yet.' ) );
				return;
			}

			ctx.state.ticketCategories.forEach( function ( item ) {
				const row = U.el( 'div', { class: 'nxtcc-ticket-category-row' + ( item.is_active ? '' : ' is-archived' ) } );
				const name = U.el( 'button', {
					type: 'button',
					class: 'nxtcc-ticket-category-edit',
					'data-category-id': U.toStr( item.id ),
				} );
				U.safeAppend( name, U.el( 'span', { class: 'nxtcc-ticket-category-swatch', style: 'background:' + U.toStr( item.color || '#2271b1' ) } ) );
				U.safeAppend( name, U.el( 'span', {}, U.toStr( item.category_name ) + ( item.is_active ? '' : ' (Archived)' ) ) );
				U.safeAppend( row, name );
				U.safeAppend( row, U.el( 'button', {
					type: 'button',
					class: 'nxtcc-ticket-category-delete',
					'data-category-id': U.toStr( item.id ),
					title: item.is_active ? 'Delete or archive category' : 'Delete category',
				}, 'Delete' ) );
				U.safeAppend( root, row );
			} );
		}

		function openCategoryModal() {
			renderCategoryList();
			resetCategoryForm();
			$categoryModal.prop( 'hidden', false );
			$categoryModal.find( '.nxtcc-ticket-category-name' ).trigger( 'focus' );
		}

		function closeCategoryModal() {
			$categoryModal.prop( 'hidden', true );
		}

		$toggle.off( 'click' + ns ).on( 'click' + ns, function () {
			$widget.toggleClass( 'is-ticket-open' );
			if ( $widget.hasClass( 'is-ticket-open' ) ) {
				loadTicket();
			}
		} );

		$panel.find( '.nxtcc-ticket-close' ).off( 'click' + ns ).on( 'click' + ns, function () {
			$widget.removeClass( 'is-ticket-open' );
		} );

		$newTicket.off( 'click' + ns ).on( 'click' + ns, startNewTicket );
		$selector.off( 'change' + ns ).on( 'change' + ns, function () {
			if ( 'new' === U.toStr( this.value ) ) {
				startNewTicket();
				return;
			}
			loadTicket( ctx.state.chatContactId, parseInt( this.value, 10 ) || 0 );
		} );

		$save.off( 'click' + ns ).on( 'click' + ns, function () {
			saveTicket( false );
		} );
		$saveSend.off( 'click' + ns ).on( 'click' + ns, function () {
			saveTicket( true );
		} );

		$status.off( 'change' + ns ).on( 'change' + ns, function () {
			$panel.find( '.nxtcc-ticket-snooze-field' ).toggle( 'snoozed' === U.toStr( $status.val() ) );
		} );

		$panel.find( '.nxtcc-ticket-sla-edit' ).off( 'click' + ns ).on( 'click' + ns, function () {
			$panel.find( '.nxtcc-ticket-sla' ).toggleClass( 'is-editing' );
		} );
		$panel.find( '.nxtcc-ticket-sla' ).off( 'dblclick' + ns ).on( 'dblclick' + ns, function () {
			$( this ).addClass( 'is-editing' );
		} );

		$watch.off( 'click' + ns ).on( 'click' + ns, function () {
			const conversation = ctx.state.conversation;
			if ( ! conversation ) {
				return;
			}

			post( 'nxtcc_set_conversation_watcher', {
				conversation_id: conversation.id,
				watch: isWatching( conversation ) ? '0' : '1',
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					conversation.watchers = resp.data.watchers || [];
					ctx.state.contactTickets.forEach( function ( ticket ) {
						if ( Number( ticket.id ) === Number( conversation.id ) ) {
							ticket.watchers = conversation.watchers;
						}
					} );
					renderTicketOptions( ctx.state.contactTickets, conversation.id, false );
					renderConversation( conversation );
					refreshInbox();
					return;
				}
				window.alert( errorMessage( resp, 'Unable to update ticket followers.' ) );
			} );
		} );

		$panel.off( 'click' + ns, '.nxtcc-ticket-activity-item' ).on( 'click' + ns, '.nxtcc-ticket-activity-item', function () {
			const activityId = parseInt( this.getAttribute( 'data-activity-id' ), 10 ) || 0;
			if ( activityId > 0 && ctx.api.thread && ctx.api.thread.focusActivity ) {
				ctx.api.thread.focusActivity( activityId, ctx.state.chatContactId );
			}
		} );

		$panel.find( '.nxtcc-ticket-manage-categories' ).off( 'click' + ns ).on( 'click' + ns, openCategoryModal );
		$categoryModal.find( '.nxtcc-ticket-category-close, .nxtcc-ticket-category-backdrop' ).off( 'click' + ns ).on( 'click' + ns, closeCategoryModal );
		$categoryModal.find( '.nxtcc-ticket-category-reset' ).off( 'click' + ns ).on( 'click' + ns, resetCategoryForm );
		$( document ).off( 'keydown' + ns ).on( 'keydown' + ns, function ( event ) {
			if ( 'Escape' === event.key && ! $categoryModal.prop( 'hidden' ) ) {
				closeCategoryModal();
			}
		} );

		$categoryModal.off( 'click' + ns, '.nxtcc-ticket-category-edit' ).on( 'click' + ns, '.nxtcc-ticket-category-edit', function () {
			const id   = parseInt( this.getAttribute( 'data-category-id' ), 10 ) || 0;
			const item = ctx.state.ticketCategories.find( function ( categoryItem ) {
				return Number( categoryItem.id ) === id;
			} );
			if ( ! item ) {
				return;
			}
			$categoryModal.find( '.nxtcc-ticket-category-id' ).val( U.toStr( item.id ) );
			$categoryModal.find( '.nxtcc-ticket-category-name' ).val( U.toStr( item.category_name ) );
			$categoryModal.find( '.nxtcc-ticket-category-color' ).val( U.toStr( item.color || '#2271b1' ) );
			$categoryModal.find( '.nxtcc-ticket-category-description' ).val( U.toStr( item.description || '' ) );
		} );

		$categoryModal.off( 'click' + ns, '.nxtcc-ticket-category-delete' ).on( 'click' + ns, '.nxtcc-ticket-category-delete', function () {
			const id = parseInt( this.getAttribute( 'data-category-id' ), 10 ) || 0;
			if ( id <= 0 || ! window.confirm( 'Delete this category? Categories used by tickets will be archived.' ) ) {
				return;
			}
			post( 'nxtcc_delete_ticket_category', { category_id: id } ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					window.alert( errorMessage( resp, 'Unable to delete the category.' ) );
					return;
				}
				if ( resp.data && resp.data.archived ) {
					ctx.state.ticketCategories = ctx.state.ticketCategories.map( function ( item ) {
						if ( Number( item.id ) === id ) {
							item.is_active = false;
						}
						return item;
					} );
				} else {
					ctx.state.ticketCategories = ctx.state.ticketCategories.filter( function ( item ) {
						return Number( item.id ) !== id;
					} );
				}
				renderCategoryList();
				renderCategoryOptions(
					ctx.state.ticketCategories,
					ctx.state.conversation ? ctx.state.conversation.category_id : $category.val(),
					ctx.state.conversation ? ctx.state.conversation.category : ''
				);
			} );
		} );

		$categoryModal.find( '.nxtcc-ticket-category-form' ).off( 'submit' + ns ).on( 'submit' + ns, function ( event ) {
			event.preventDefault();
			const name = U.toStr( $categoryModal.find( '.nxtcc-ticket-category-name' ).val() ).trim();
			if ( ! name ) {
				return;
			}
			post( 'nxtcc_save_ticket_category', {
				category_id: parseInt( $categoryModal.find( '.nxtcc-ticket-category-id' ).val(), 10 ) || 0,
				category_name: name,
				color: U.toStr( $categoryModal.find( '.nxtcc-ticket-category-color' ).val() ),
				description: U.toStr( $categoryModal.find( '.nxtcc-ticket-category-description' ).val() ),
				is_active: '1',
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					window.alert( errorMessage( resp, 'Unable to save the category.' ) );
					return;
				}
				const saved = resp.data && resp.data.category ? resp.data.category : null;
				if ( saved ) {
					const existingIndex = ctx.state.ticketCategories.findIndex( function ( item ) {
						return Number( item.id ) === Number( saved.id );
					} );
					if ( existingIndex >= 0 ) {
						ctx.state.ticketCategories[ existingIndex ] = saved;
					} else {
						ctx.state.ticketCategories.push( saved );
					}
					ctx.state.ticketCategories.sort( function ( left, right ) {
						return U.toStr( left.category_name ).localeCompare( U.toStr( right.category_name ) );
					} );
					renderCategoryList();
					renderCategoryOptions( ctx.state.ticketCategories, saved.id, saved.category_name );
					$category.val( U.toStr( saved.id ) );
				}
				resetCategoryForm();
			} );
		} );

		$panel.off( 'input' + ns + ' change' + ns, 'input, textarea, select' )
			.on( 'input' + ns + ' change' + ns, 'input, textarea, select', updateSaveButtons );
		ctx.api.tickets.setReplyWindow = setReplyWindow;
		ctx.api.tickets.updateCount = updateTicketCount;
		ctx.api.tickets.load = loadTicket;
		ctx.api.tickets.clear = function () {
			updateTicketCount( 0 );
			++ticketRequest;
			loadingTicket = false;
			setReplyWindow( {} );
			ctx.state.ticketPermissions = {};
			ctx.state.ticketCategories  = [];
			ctx.state.contactTickets    = [];
			ctx.state.isNewTicket       = false;
			renderConversation( null );
			$widget.removeClass( 'is-ticket-open' );
		};
	};
} );
