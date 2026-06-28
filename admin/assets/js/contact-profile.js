/**
 * Shared read-only contact profile UI.
 *
 * @package NXTCC
 */

/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	const cfg  = window.NXTCC_ContactProfile || {};
	const text = cfg.strings || {};
	const root = document.getElementById( 'nxtcc-contact-profile-modal' );

	if ( ! root || ! cfg.ajaxurl || ! cfg.nonce ) {
		return;
	}

	const bodyEl      = root.querySelector( '[data-profile-body]' );
	const stateEl     = root.querySelector( '[data-profile-state]' );
	const timelineEl  = root.querySelector( '[data-profile-timeline]' );
	const loadOlderEl = root.querySelector( '[data-profile-load-older]' );
	const taskFormEl   = root.querySelector( '[data-profile-task-form]' );
	const tasksEl      = root.querySelector( '[data-profile-tasks]' );
	let activeContact = 0;
	let beforeId      = 0;
	let loading       = false;
	let seenActivityIds = {};

	function safeText( value, fallback ) {
		const output = value === null || value === undefined ? '' : String( value ).trim();
		return output || fallback || text.empty || '';
	}

	function setText( selector, value, fallback ) {
		const node = root.querySelector( selector );
		if ( node ) {
			node.textContent = safeText( value, fallback );
		}
	}

	function emptyNode( node ) {
		if ( ! node ) {
			return;
		}

		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function resetTimelineState() {
		beforeId        = 0;
		seenActivityIds = {};
	}

	function activityKey( activity ) {
		const id = parseInt( activity && activity.id, 10 ) || 0;
		return id > 0 ? String( id ) : '';
	}

	function removeTimelineNotice() {
		if ( ! timelineEl ) {
			return;
		}

		timelineEl.querySelectorAll( '.nxtcc-profile-timeline-notice' ).forEach( function ( node ) {
			node.remove();
		} );
	}

	function appendTimelineNotice( message ) {
		if ( ! timelineEl ) {
			return;
		}

		removeTimelineNotice();

		const notice       = document.createElement( 'div' );
		notice.className   = 'nxtcc-profile-timeline-notice';
		notice.textContent = safeText( message, text.timeline_end || 'End of history.' );
		timelineEl.appendChild( notice );
	}

	function chip( label, color ) {
		const node       = document.createElement( 'span' );
		node.className   = 'nxtcc-profile-chip';
		node.textContent = safeText( label, text.empty );

		if ( /^#[0-9a-f]{6}$/i.test( String( color || '' ) ) ) {
			node.style.borderColor = color;
			node.style.setProperty( '--nxtcc-profile-chip-color', color );
		}

		return node;
	}

	function renderChips( selector, rows, labelKey, colorKey ) {
		const node = root.querySelector( selector );
		emptyNode( node );

		if ( ! node ) {
			return;
		}

		if ( ! Array.isArray( rows ) || ! rows.length ) {
			node.appendChild( chip( text.none || 'None', '' ) );
			return;
		}

		rows.forEach( function ( row ) {
			node.appendChild( chip( row && row[ labelKey ], row && colorKey ? row[ colorKey ] : '' ) );
		} );
	}

	function appendDefinition( list, label, value ) {
		const row = document.createElement( 'div' );
		const dt  = document.createElement( 'dt' );
		const dd  = document.createElement( 'dd' );

		dt.textContent = safeText( label, text.empty );
		dd.textContent = safeText( value, text.empty );
		row.appendChild( dt );
		row.appendChild( dd );
		list.appendChild( row );
	}

	function flattenCustomFields( fields ) {
		const output = [];

		if ( Array.isArray( fields ) ) {
			fields.forEach( function ( item, index ) {
				if ( ! item || 'object' !== typeof item || Array.isArray( item ) ) {
					return;
				}

				if ( item.label !== undefined || item.value !== undefined ) {
					output.push( {
						label: safeText( item.label, 'Field ' + String( index + 1 ) ),
						value: safeText( item.value, text.empty ),
					} );
				}
			} );

			return output;
		}

		if ( fields && 'object' === typeof fields ) {
			Object.keys( fields ).forEach( function ( key ) {
				const value = fields[ key ];
				output.push( {
					label: key.replace( /_/g, ' ' ),
					value: value && 'object' === typeof value ? JSON.stringify( value ) : safeText( value, text.empty ),
				} );
			} );
		}

		return output;
	}

	function renderCustomFields( fields ) {
		const list = root.querySelector( '[data-profile-custom-fields]' );
		const rows = flattenCustomFields( fields );
		emptyNode( list );

		if ( ! list ) {
			return;
		}

		if ( ! rows.length ) {
			appendDefinition( list, text.none || 'None', text.empty );
			return;
		}

		rows.forEach( function ( row ) {
			appendDefinition( list, row.label, row.value );
		} );
	}

	function renderLifecycle( lifecycle, canManage ) {
		const label  = root.querySelector( '[data-profile-lifecycle-label]' );
		const select = root.querySelector( '[data-profile-lifecycle-select]' );
		const current = lifecycle && lifecycle.current ? lifecycle.current : {};
		const stages  = lifecycle && Array.isArray( lifecycle.stages ) ? lifecycle.stages : [];

		if ( label ) {
			label.textContent = safeText( current.stage_name, text.none || 'None' );
			label.style.setProperty( '--nxtcc-profile-chip-color', /^#[0-9a-f]{6}$/i.test( String( current.color || '' ) ) ? current.color : '' );
		}
		if ( ! select ) {
			return;
		}

		emptyNode( select );
		const emptyOption       = document.createElement( 'option' );
		emptyOption.value       = '0';
		emptyOption.textContent = text.no_stage || 'No lifecycle stage';
		select.appendChild( emptyOption );

		stages.forEach( function ( stage ) {
			const option       = document.createElement( 'option' );
			option.value       = String( stage.id || 0 );
			option.textContent = safeText( stage.stage_name, text.empty );
			select.appendChild( option );
		} );

		select.value  = String( current.stage_id || current.id || 0 );
		select.hidden = ! canManage;
		if ( label ) {
			label.hidden = !! canManage;
		}
	}

	function taskStatusButton( task ) {
		const button       = document.createElement( 'button' );
		button.type        = 'button';
		button.className   = 'nxtcc-profile-task-toggle';
		button.dataset.taskId = String( task.id || 0 );
		button.dataset.status = 'open' === task.status ? 'completed' : 'open';
		button.textContent = 'open' === task.status ? ( text.complete || 'Complete' ) : ( text.reopen || 'Reopen' );
		return button;
	}

	function renderTasks( tasks, canManage ) {
		emptyNode( tasksEl );
		if ( taskFormEl ) {
			taskFormEl.hidden = ! canManage;
		}
		if ( ! tasksEl ) {
			return;
		}
		if ( ! Array.isArray( tasks ) || ! tasks.length ) {
			const empty       = document.createElement( 'div' );
			empty.className   = 'nxtcc-profile-empty';
			empty.textContent = text.no_tasks || 'No follow-up tasks yet.';
			tasksEl.appendChild( empty );
			return;
		}

		tasks.forEach( function ( task ) {
			const item     = document.createElement( 'article' );
			const content  = document.createElement( 'div' );
			const title    = document.createElement( 'strong' );
			const meta     = document.createElement( 'div' );
			const priority = safeText( task.priority, 'normal' );

			item.className      = 'nxtcc-profile-task is-' + safeText( task.status, 'open' ) + ( task.is_overdue ? ' is-overdue' : '' );
			title.textContent   = safeText( task.title, text.empty );
			meta.className      = 'nxtcc-profile-task-meta';
			meta.textContent    = [ priority, task.due_at_display, task.assignee_label ].filter( Boolean ).join( ' | ' );
			content.appendChild( title );
			content.appendChild( meta );
			item.appendChild( content );
			if ( canManage && 'cancelled' !== task.status ) {
				item.appendChild( taskStatusButton( task ) );
			}
			tasksEl.appendChild( item );
		} );
	}

	function renderDeals( deals ) {
		const list = root.querySelector( '[data-profile-deals]' );
		emptyNode( list );
		if ( ! list ) {
			return;
		}
		if ( ! Array.isArray( deals ) || ! deals.length ) {
			const empty       = document.createElement( 'div' );
			empty.className   = 'nxtcc-profile-empty';
			empty.textContent = text.no_deals || 'No linked deals yet.';
			list.appendChild( empty );
			return;
		}

		deals.forEach( function ( deal ) {
			const item   = document.createElement( 'article' );
			const title  = document.createElement( 'strong' );
			const stage  = document.createElement( 'span' );
			const value  = document.createElement( 'div' );
			item.className = 'nxtcc-profile-deal';
			title.textContent = safeText( deal.title, text.empty );
			stage.className   = 'nxtcc-profile-chip';
			stage.textContent = safeText( deal.stage_name, deal.status );
			if ( deal.stage_color ) {
				stage.style.setProperty( '--nxtcc-profile-chip-color', String( deal.stage_color ) );
			}
			value.className   = 'nxtcc-profile-deal-meta';
			value.textContent = [
				safeText( deal.currency, '' ) + ' ' + Number( deal.deal_value || 0 ).toLocaleString(),
				safeText( deal.expected_close_display, '' ),
			].filter( Boolean ).join( ' | ' );
			item.appendChild( title );
			item.appendChild( stage );
			item.appendChild( value );
			list.appendChild( item );
		} );
	}

	function renderDuplicates( rows, canMerge ) {
		const section = root.querySelector( '[data-profile-duplicates-section]' );
		const list    = root.querySelector( '[data-profile-duplicates]' );
		emptyNode( list );

		if ( section ) {
			section.hidden = ! canMerge || ! Array.isArray( rows ) || ! rows.length;
		}
		if ( ! list || ! Array.isArray( rows ) ) {
			return;
		}

		rows.forEach( function ( row ) {
			const item       = document.createElement( 'div' );
			const label      = document.createElement( 'span' );
			const button     = document.createElement( 'button' );
			item.className    = 'nxtcc-profile-duplicate';
			label.textContent = safeText( row.name, text.empty ) + ' (+' + safeText( row.country_code, '' ) + safeText( row.phone_number, '' ) + ')';
			button.type       = 'button';
			button.className  = 'nxtcc-profile-button';
			button.dataset.mergeContactId = String( row.id || 0 );
			button.textContent = text.merge || 'Merge';
			item.appendChild( label );
			item.appendChild( button );
			list.appendChild( item );
		} );
	}

	function activityDescription( activity ) {
		const metadata = activity && activity.metadata && 'object' === typeof activity.metadata ? activity.metadata : {};

		if ( 'subscription_status_changed' === activity.activity_type ) {
			return [ metadata.previous_status, metadata.status ].filter( Boolean ).join( ' to ' );
		}

		if ( 'contact_updated' === activity.activity_type && Array.isArray( metadata.changed_fields ) ) {
			return metadata.changed_fields.map( function ( field ) {
				return String( field ).replace( /_/g, ' ' );
			} ).join( ', ' );
		}

		if ( activity.note_content ) {
			return activity.note_content;
		}

		return '';
	}

	function renderActivities( rows, append ) {
		if ( ! append ) {
			emptyNode( timelineEl );
			seenActivityIds = {};
		}

		if ( ! timelineEl ) {
			return;
		}

		removeTimelineNotice();

		if ( ! Array.isArray( rows ) || ! rows.length ) {
			if ( ! append && ! timelineEl.children.length ) {
				const empty       = document.createElement( 'div' );
				empty.className   = 'nxtcc-profile-empty';
				empty.textContent = text.no_activity || 'No history found.';
				timelineEl.appendChild( empty );
			} else if ( append ) {
				appendTimelineNotice( text.no_older_activity || 'No older history found.' );
			}
			return;
		}

		let appended = 0;

		rows.forEach( function ( activity ) {
			const key = activityKey( activity );
			if ( key && seenActivityIds[ key ] ) {
				return;
			}

			const item        = document.createElement( 'article' );
			const marker      = document.createElement( 'span' );
			const content     = document.createElement( 'div' );
			const heading     = document.createElement( 'div' );
			const description = activityDescription( activity );
			const meta        = document.createElement( 'div' );

			item.className      = 'nxtcc-profile-activity';
			if ( key ) {
				item.dataset.activityId = key;
				seenActivityIds[ key ] = true;
				item.tabIndex = 0;
				item.setAttribute( 'role', 'button' );
				item.setAttribute( 'title', text.open_in_chat || 'Show this activity in the chat timeline' );
			}
			marker.className    = 'nxtcc-profile-activity-marker';
			content.className   = 'nxtcc-profile-activity-content';
			heading.className   = 'nxtcc-profile-activity-heading';
			meta.className      = 'nxtcc-profile-activity-meta';
			heading.textContent = safeText( activity.activity_label, activity.activity_type );
			meta.textContent    = [
				safeText( activity.actor_label, text.system || 'System' ),
				safeText( activity.source, '' ),
				safeText( activity.created_at_display, '' ),
			].filter( Boolean ).join( ' | ' );

			content.appendChild( heading );
			if ( description ) {
				const descriptionEl       = document.createElement( 'p' );
				descriptionEl.textContent = description;
				content.appendChild( descriptionEl );
			}
			content.appendChild( meta );
			item.appendChild( marker );
			item.appendChild( content );
			timelineEl.appendChild( item );
			appended++;
		} );

		if ( append && ! appended ) {
			appendTimelineNotice( text.no_older_activity || 'No older history found.' );
		}
	}

	function renderProfile( data, appendActivities ) {
		const contact    = data.contact || {};
		const assignment = data.assignment || {};
		const context    = data.message_context || {};
		const activities = data.activities || {};
		const fullPhone  = contact.country_code
			? '+' + safeText( contact.country_code, '' ) + ' ' + safeText( contact.phone_number, '' )
			: safeText( contact.phone_number, text.empty );

		setText( '[data-profile-name]', contact.name, 'Contact Profile' );
		setText( '[data-profile-avatar]', safeText( contact.name, '?' ).charAt( 0 ).toUpperCase(), '?' );
		setText( '[data-profile-phone]', fullPhone );
		setText( '[data-profile-subscription]', contact.is_subscribed ? text.subscribed : text.unsubscribed );
		setText( '[data-profile-assignment]', assignment.label, text.unassigned );
		setText( '[data-profile-verified]', contact.is_verified ? text.verified : text.not_verified );
		setText( '[data-profile-created]', contact.created_at_display );
		setText( '[data-profile-updated]', contact.updated_at_display );
		setText( '[data-profile-wp-user]', contact.is_wp_user_linked ? text.linked : text.not_linked );
		setText( '[data-profile-message-total]', context.total_messages, '0' );
		setText( '[data-profile-message-inbound]', context.inbound_messages, '0' );
		setText( '[data-profile-message-outbound]', context.outbound_messages, '0' );
		setText( '[data-profile-message-latest]', context.latest_message_at );
		setText( '[data-profile-inbound-latest]', context.latest_inbound_at );

		root.querySelector( '[data-profile-subscription]' ).className =
			'nxtcc-profile-status ' + ( contact.is_subscribed ? 'is-subscribed' : 'is-unsubscribed' );

		renderChips( '[data-profile-groups]', data.groups, 'group_name', '' );
		renderChips( '[data-profile-tags]', data.tags, 'tag_name', 'color' );
		renderCustomFields( contact.custom_fields );
		renderLifecycle( data.lifecycle || {}, !! ( data.permissions && data.permissions.manage_lifecycle ) );
		renderDeals( data.deals || [] );
		renderTasks( data.tasks || [], !! ( data.permissions && data.permissions.manage_tasks ) );
		renderDuplicates( data.duplicates || [], !! ( data.permissions && data.permissions.merge_contacts ) );

		if ( activities.can_view ) {
			renderActivities( activities.rows, appendActivities );
			if ( appendActivities && ! activities.has_more && timelineEl && timelineEl.querySelector( '.nxtcc-profile-activity' ) ) {
				appendTimelineNotice( text.timeline_end || 'End of history.' );
			}
		} else if ( ! appendActivities ) {
			emptyNode( timelineEl );
			const unavailable       = document.createElement( 'div' );
			unavailable.className   = 'nxtcc-profile-empty';
			unavailable.textContent = text.activity_unavailable || '';
			timelineEl.appendChild( unavailable );
		}

		beforeId           = Number( activities.before_id || 0 );
		loadOlderEl.hidden = ! activities.can_view || ! activities.has_more;
	}

	function showError( message, appendActivities ) {
		stateEl.textContent = safeText( message, text.load_error );
		stateEl.hidden      = false;
		if ( ! appendActivities ) {
			bodyEl.hidden = true;
		}
	}

	function requestProfile( appendActivities ) {
		if ( loading || activeContact <= 0 ) {
			return;
		}

		if ( appendActivities && beforeId <= 0 ) {
			loadOlderEl.hidden = true;
			appendTimelineNotice( text.no_older_activity || 'No older history found.' );
			return;
		}

		loading              = true;
		loadOlderEl.disabled = true;

		if ( ! appendActivities ) {
			stateEl.textContent = text.loading || 'Loading contact profile...';
			stateEl.hidden      = false;
			bodyEl.hidden       = true;
		}

		$.post( cfg.ajaxurl, {
			action: 'nxtcc_contact_profile_get',
			nonce: cfg.nonce,
			contact_id: activeContact,
			before_id: appendActivities ? beforeId : 0,
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					showError( response && response.data ? response.data.message : '', appendActivities );
					return;
				}

				renderProfile( response.data, appendActivities );
				stateEl.hidden = true;
				bodyEl.hidden  = false;
			} )
			.fail( function ( xhr ) {
				const message =
					xhr && xhr.responseJSON && xhr.responseJSON.data
						? xhr.responseJSON.data.message
						: '';
				showError( message, appendActivities );
			} )
			.always( function () {
				loading              = false;
				loadOlderEl.disabled = false;
			} );
	}

	function requestMutation( action, payload, done ) {
		const data = Object.assign( {
			action: action,
			nonce: cfg.nonce,
			contact_id: activeContact,
		}, payload || {} );

		$.post( cfg.ajaxurl, data )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.alert( response && response.data && response.data.message ? response.data.message : text.action_error );
					return;
				}
				if ( 'function' === typeof done ) {
					done( response.data || {} );
				}
				requestProfile( false );
			} )
			.fail( function ( xhr ) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : '';
				window.alert( safeText( message, text.action_error || 'The CRM action could not be completed.' ) );
			} );
	}

	function openProfile( contactId ) {
		const id = parseInt( contactId, 10 ) || 0;
		if ( id <= 0 ) {
			return;
		}

		activeContact = id;
		resetTimelineState();
		root.hidden   = false;
		document.body.classList.add( 'nxtcc-profile-open' );
		requestProfile( false );
	}

	function closeProfile() {
		root.hidden = true;
		document.body.classList.remove( 'nxtcc-profile-open' );
		activeContact = 0;
		resetTimelineState();
	}

	$( document ).on( 'click.nxtccContactProfile', '.nxtcc-contact-profile-trigger', function ( event ) {
		event.preventDefault();
		if ( this.disabled ) {
			return;
		}
		openProfile( this.getAttribute( 'data-contact-id' ) || this.getAttribute( 'data-id' ) );
	} );

	root.querySelectorAll( '[data-nxtcc-profile-close]' ).forEach( function ( node ) {
		node.addEventListener( 'click', closeProfile );
	} );

	loadOlderEl.addEventListener( 'click', function () {
		requestProfile( true );
	} );

	root.querySelector( '[data-profile-lifecycle-select]' ).addEventListener( 'change', function () {
		requestMutation( 'nxtcc_contact_profile_set_lifecycle', {
			stage_id: this.value,
		} );
	} );

	if ( taskFormEl ) {
		taskFormEl.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			const title = root.querySelector( '[data-profile-task-title]' );
			const due   = root.querySelector( '[data-profile-task-due]' );
			const priority = root.querySelector( '[data-profile-task-priority]' );
			if ( ! title || ! String( title.value || '' ).trim() ) {
				return;
			}
			requestMutation( 'nxtcc_contact_profile_create_task', {
				title: title.value,
				due_at: due ? due.value : '',
				priority: priority ? priority.value : 'normal',
			}, function () {
				title.value = '';
				if ( due ) {
					due.value = '';
				}
			} );
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		const activityItem = event.target.closest( '[data-activity-id]' );
		if ( activityItem && root.contains( activityItem ) ) {
			document.dispatchEvent(
				new CustomEvent( 'nxtcc:focus-chat-activity', {
					detail: {
						activityId: parseInt( activityItem.dataset.activityId, 10 ) || 0,
						contactId: activeContact,
					},
				} )
			);
			closeProfile();
			return;
		}

		const taskButton = event.target.closest( '[data-task-id]' );
		if ( taskButton && root.contains( taskButton ) ) {
			requestMutation( 'nxtcc_contact_profile_update_task', {
				task_id: taskButton.dataset.taskId,
				status: taskButton.dataset.status,
			} );
			return;
		}

		const mergeButton = event.target.closest( '[data-merge-contact-id]' );
		if ( mergeButton && root.contains( mergeButton ) && window.confirm( text.merge_confirm || 'Merge this duplicate into the open contact?' ) ) {
			requestMutation( 'nxtcc_contact_profile_merge_duplicate', {
				source_contact_id: mergeButton.dataset.mergeContactId,
			} );
		}
	} );

	root.addEventListener( 'keydown', function ( event ) {
		const activityItem = event.target.closest( '[data-activity-id]' );
		if ( activityItem && ( 'Enter' === event.key || ' ' === event.key ) ) {
			event.preventDefault();
			activityItem.click();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && ! root.hidden ) {
			closeProfile();
		}
	} );

	window.NXTCCContactProfile = {
		open: openProfile,
		close: closeProfile,
	};
} );
