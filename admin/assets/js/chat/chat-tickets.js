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

		const $widget       = ctx.$widget;
		const $panel        = $widget.find( '.nxtcc-ticket-panel' );
		const $toggle       = $widget.find( '.nxtcc-ticket-toggle' );
		const $watch        = $panel.find( '.nxtcc-ticket-watch' );
		const $subject      = $panel.find( '.nxtcc-ticket-subject' );
		const $category     = $panel.find( '.nxtcc-ticket-category' );
		const $status       = $panel.find( '.nxtcc-ticket-status' );
		const $priority     = $panel.find( '.nxtcc-ticket-priority' );
		const $snoozedUntil = $panel.find( '.nxtcc-ticket-snoozed-until' );
		const $assignment   = $panel.find( '.nxtcc-ticket-assignment' );
		const $handoffNote  = $panel.find( '.nxtcc-ticket-handoff-note' );
		const $handoffReason = $panel.find( '.nxtcc-ticket-handoff-reason' );
		const $note         = $panel.find( '.nxtcc-ticket-note' );
		const ns            = '.nxtccTickets' + U.toStr( ctx.instanceId || '' );

		ctx.state         = ctx.state || {};
		ctx.api           = ctx.api || {};
		ctx.api.tickets   = ctx.api.tickets || {};
		ctx.state.ticketPermissions = {};

		function errorMessage( resp, fallback ) {
			return resp && resp.data && resp.data.message
				? U.toStr( resp.data.message )
				: fallback;
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
			U.safeAppend( select, U.el( 'option', { value: '' }, 'Unassigned' ) );
			const users = targets && targets.users ? targets.users : [];
			const teams = targets && targets.teams ? targets.teams : ( targets && targets.roles ? targets.roles : [] );

			if ( users.length ) {
				const usersGroup = U.el( 'optgroup', { label: 'Users' } );
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
				teams.forEach( function ( role ) {
					U.safeAppend(
						teamsGroup,
						U.el( 'option', { value: 'role:' + U.toStr( role.key ) }, U.toStr( role.label || role.key ) )
					);
				} );
				U.safeAppend( select, teamsGroup );
			}
			select.value = U.toStr( selected || '' );
		}

		function activityLabel( activity ) {
			const labels = {
				conversation_assigned: 'Assignment updated',
				conversation_details_changed: 'Ticket details changed',
				conversation_priority_changed: 'Priority changed',
				conversation_status_changed: 'Status changed',
				internal_note_added: 'Internal note',
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
				const item = U.el( 'div', { class: 'nxtcc-ticket-activity-item' } );
				const head = U.el( 'div', { class: 'nxtcc-ticket-activity-head' } );
				U.safeAppend( head, U.el( 'strong', {}, activityLabel( activity ) ) );
				U.safeAppend( head, U.el( 'span', {}, U.toStr( activity.created_at_display || '' ) ) );
				U.safeAppend( item, head );
				U.safeAppend( item, U.el( 'div', { class: 'nxtcc-ticket-activity-actor' }, U.toStr( activity.actor_label || 'System' ) ) );
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

			$subject.prop( 'disabled', ! canManage );
			$category.prop( 'disabled', ! canManage );
			$status.prop( 'disabled', ! canManage );
			$priority.prop( 'disabled', ! canManage );
			$snoozedUntil.prop( 'disabled', ! canManage );
			$panel.find( '.nxtcc-ticket-save' ).prop( 'disabled', ! canManage );
			$assignment.prop( 'disabled', ! canReassign );
			$handoffNote.prop( 'disabled', ! canReassign );
			$handoffReason.prop( 'disabled', ! canReassign );
			$panel.find( '.nxtcc-ticket-assign' ).prop( 'disabled', ! canReassign );
			$note.prop( 'disabled', ! canNote );
			$panel.find( '.nxtcc-ticket-add-note' ).prop( 'disabled', ! canNote );
		}

		function renderConversation( conversation ) {
			ctx.state.conversation = conversation && 'object' === typeof conversation ? conversation : null;
			const current          = ctx.state.conversation;

			$toggle.prop( 'disabled', ! current );
			$watch.prop( 'disabled', ! current );
			if ( ! current ) {
				$panel.find( '.nxtcc-ticket-number' ).text( 'No ticket selected' );
				$panel.find( '.nxtcc-ticket-updated' ).text( '' );
				return;
			}

			$panel.find( '.nxtcc-ticket-number' ).text( U.toStr( current.ticket_number || 'Ticket' ) );
			$panel.find( '.nxtcc-ticket-updated' ).text( current.updated_at_display ? 'Updated ' + U.toStr( current.updated_at_display ) : '' );
			$subject.val( U.toStr( current.subject || '' ) );
			$category.val( U.toStr( current.category || '' ) );
			$status.val( U.toStr( current.status || '' ) );
			$priority.val( U.toStr( current.priority || '' ) );
			$snoozedUntil.val( U.toStr( current.snoozed_until_local_input || '' ) );
			$panel.find( '.nxtcc-ticket-snooze-field' ).toggle( 'snoozed' === U.toStr( current.status || '' ) || 'snoozed' === U.toStr( $status.val() ) );
			$assignment.val( U.toStr( current.assignment_target || '' ) );
			$panel.find( '.nxtcc-ticket-first-response-due' )
				.text( U.toStr( current.first_response_due_at_display || 'Not set' ) )
				.toggleClass( 'is-overdue', Boolean( current.first_response_overdue ) );
			$panel.find( '.nxtcc-ticket-resolution-due' )
				.text( U.toStr( current.resolution_due_at_display || 'Not set' ) )
				.toggleClass( 'is-overdue', Boolean( current.resolution_overdue ) );
			$watch.toggleClass( 'is-watching', isWatching( current ) );
			$watch.attr( 'title', isWatching( current ) ? 'Stop following ticket' : 'Follow ticket' );

			if ( ctx.api.inbox && ctx.api.inbox.syncAssignment ) {
				ctx.api.inbox.syncAssignment( U.toStr( current.assignment_target || '' ) );
			}
		}

		function renderPayload( data ) {
			const payload = data && 'object' === typeof data ? data : {};
			ctx.state.ticketPermissions = payload.permissions || {};
			setSelectOptions( $status, payload.statuses || {}, payload.conversation && payload.conversation.status );
			setSelectOptions( $priority, payload.priorities || {}, payload.conversation && payload.conversation.priority );
			renderAssignmentOptions( payload.assignment_targets || {}, payload.conversation && payload.conversation.assignment_target );
			renderConversation( payload.conversation || null );
			renderActivity( payload.activity || [] );
			applyPermissions();
		}

		function loadTicket( contactId ) {
			const id = parseInt( contactId || ctx.state.chatContactId, 10 ) || 0;
			if ( id <= 0 ) {
				renderConversation( null );
				return;
			}

			$panel.addClass( 'is-loading' );
			post( 'nxtcc_get_conversation_ticket', { contact_id: id } )
				.done( function ( resp ) {
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
					$panel.removeClass( 'is-loading' );
				} );
		}

		function refreshInbox() {
			if ( ctx.api.inbox && ctx.api.inbox.refresh ) {
				ctx.api.inbox.refresh();
			}
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

		$panel.find( '.nxtcc-ticket-save' ).off( 'click' + ns ).on( 'click' + ns, function () {
			const conversation = ctx.state.conversation;
			if ( ! conversation ) {
				return;
			}

			post( 'nxtcc_update_conversation_ticket', {
				conversation_id: conversation.id,
				subject: U.toStr( $subject.val() ),
				category: U.toStr( $category.val() ),
				status: U.toStr( $status.val() ),
				priority: U.toStr( $priority.val() ),
				snoozed_until: U.toStr( $snoozedUntil.val() ),
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					loadTicket();
					refreshInbox();
					return;
				}
				window.alert( errorMessage( resp, 'Unable to save ticket details.' ) );
			} );
		} );

		$status.off( 'change' + ns ).on( 'change' + ns, function () {
			$panel.find( '.nxtcc-ticket-snooze-field' ).toggle( 'snoozed' === U.toStr( $status.val() ) );
		} );

		$panel.find( '.nxtcc-ticket-assign' ).off( 'click' + ns ).on( 'click' + ns, function () {
			const conversation = ctx.state.conversation;
			if ( ! conversation ) {
				return;
			}

			post( 'nxtcc_assign_conversation_ticket', {
				conversation_id: conversation.id,
				assignment_target: U.toStr( $assignment.val() ),
				note: U.toStr( $handoffNote.val() ),
				reason: U.toStr( $handoffReason.val() ),
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$handoffNote.val( '' );
					$handoffReason.val( '' );
					loadTicket();
					refreshInbox();
					return;
				}
				window.alert( errorMessage( resp, 'Unable to update the assignment.' ) );
			} );
		} );

		$panel.find( '.nxtcc-ticket-add-note' ).off( 'click' + ns ).on( 'click' + ns, function () {
			const conversation = ctx.state.conversation;
			if ( ! conversation || ! U.toStr( $note.val() ).trim() ) {
				return;
			}

			post( 'nxtcc_add_conversation_note', {
				conversation_id: conversation.id,
				note: U.toStr( $note.val() ),
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$note.val( '' );
					loadTicket();
					return;
				}
				window.alert( errorMessage( resp, 'Unable to add the internal note.' ) );
			} );
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
					renderConversation( conversation );
					return;
				}
				window.alert( errorMessage( resp, 'Unable to update ticket followers.' ) );
			} );
		} );

		ctx.api.tickets.load               = loadTicket;
		ctx.api.tickets.renderConversation = renderConversation;
		ctx.api.tickets.clear              = function () {
			ctx.state.ticketPermissions = {};
			renderConversation( null );
			$widget.removeClass( 'is-ticket-open' );
		};
	};
} );
