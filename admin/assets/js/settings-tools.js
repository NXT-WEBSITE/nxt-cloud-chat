/**
 * Tools settings UI.
 *
 * Handles manual cleanup preview, cleanup runs, and the guarded
 * clean-everything flow.
 *
 * @package NXTCC
 */

/* global jQuery, NXTCC_CLEANUP_TOOLS */

jQuery( function ( $ ) {
	'use strict';

	const config = window.NXTCC_CLEANUP_TOOLS || {};
	const strings = config.strings || {};
	const canManage = !! config.canManage;
	const $tools = $( '.nxtcc-settings-tools' );
	const $preview = $( '#nxtcc-cleanup-preview' );
	const $history = $( '#nxtcc-cleanup-history' );
	const $previewButton = $( '#nxtcc_cleanup_preview_button' );
	const $runButton = $( '#nxtcc_cleanup_run_button' );
	const $everythingButton = $( '#nxtcc_cleanup_everything_button' );
	const $confirm = $( '#nxtcc_cleanup_confirm' );
	const $dangerPanel = $( '#nxtcc-cleanup-everything-panel' );
	const $dangerMessage = $( '#nxtcc-cleanup-everything-message' );
	const $mathQuestion = $( '#nxtcc_cleanup_math_question' );
	const $mathAnswer = $( '#nxtcc_cleanup_math_answer' );
	const $mathToken = $( '#nxtcc_cleanup_math_token' );
	const $mathConfirm = $( '#nxtcc_cleanup_math_confirm' );
	const $mathCancel = $( '#nxtcc_cleanup_math_cancel' );

	if ( ! $tools.length || ! $preview.length || ! $previewButton.length || ! $runButton.length ) {
		return;
	}

	function esc( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function showPreviewMessage( message ) {
		$preview.html(
			'<p class="nxtcc-settings-tools-preview-message">' +
			esc( message || strings.requestFailed || '' ) +
			'</p>'
		);
	}

	function setDangerMessage( message ) {
		if ( ! $dangerMessage.length ) {
			return;
		}

		$dangerMessage.text( message || strings.challengeIntro || 'To continue, solve this quick check.' );
	}

	function hideDangerPanel() {
		if ( ! $dangerPanel.length ) {
			return;
		}

		$dangerPanel.prop( 'hidden', true );
		setDangerMessage( strings.challengeIntro || 'To continue, solve this quick check.' );
		$mathQuestion.text( '12 + 34' );
		$mathAnswer.val( '' );
		$mathToken.val( '' );
	}

	function showDangerPanel( question, token, message ) {
		if ( ! $dangerPanel.length ) {
			return;
		}

		$dangerPanel.prop( 'hidden', false );
		setDangerMessage( message || strings.challengeIntro || 'To continue, solve this quick check.' );
		$mathQuestion.text( question || '12 + 34' );
		$mathToken.val( token || '' );
		$mathAnswer.val( '' ).trigger( 'focus' );
	}

	function setBusy( isBusy, mode ) {
		const previewText = strings.previewing || 'Checking older data...';
		const cleanText = strings.cleaning || 'Cleaning older data...';
		const challengeText = strings.challengeLoading || 'Preparing the confirmation check...';
		const everythingText = strings.cleaningEverything || 'Cleaning everything that can be safely removed...';

		$previewButton.prop( 'disabled', isBusy || ! canManage );
		$runButton.prop( 'disabled', isBusy || ! canManage );
		$everythingButton.prop( 'disabled', isBusy || ! canManage );
		$confirm.prop( 'disabled', isBusy || ! canManage );
		$mathAnswer.prop( 'disabled', isBusy || ! canManage );
		$mathConfirm.prop( 'disabled', isBusy || ! canManage );
		$mathCancel.prop( 'disabled', isBusy || ! canManage );

		if ( ! isBusy ) {
			$previewButton.text( strings.previewButton || 'Preview Cleanup' );
			$runButton.text( strings.cleanupButton || 'Clean Up Now' );
			$everythingButton.text( strings.everythingButton || 'Clean Everything' );
			$mathConfirm.text( strings.everythingButton || 'Clean Everything' );
			return;
		}

		if ( 'preview' === mode ) {
			$previewButton.text( previewText );
		}

		if ( 'cleanup' === mode ) {
			$runButton.text( cleanText );
		}

		if ( 'challenge' === mode ) {
			$everythingButton.text( challengeText );
		}

		if ( 'everything' === mode ) {
			$everythingButton.text( everythingText );
			$mathConfirm.text( everythingText );
		}
	}

	function renderPreview( payload, headingText ) {
		const items = Array.isArray( payload.items ) ? payload.items : [];
		const total = payload.total_display || '0';
		const heading = headingText || strings.previewHeading || 'What can be cleared now';

		if ( ! items.length ) {
			showPreviewMessage(
				strings.previewEmpty || 'Nothing is ready to clear right now based on the saved rules.'
			);
			return;
		}

		let html = '';

		html += '<h4 class="nxtcc-settings-tools-preview-title">' + esc( heading ) + '</h4>';
		html += '<p class="nxtcc-settings-tools-preview-message">' + esc( total ) + ' ' + esc( strings.itemsLabel || 'older items' ) + '</p>';
		html += '<ul class="nxtcc-settings-tools-preview-list">';

		items.forEach( function ( item ) {
			const label = item.label || '';
			const count = typeof item.count !== 'undefined' ? item.count : item.deleted;
			const note = item.message || '';

			html += '<li class="nxtcc-settings-tools-preview-item">';
			html += '<div class="nxtcc-settings-tools-preview-item-main">';
			html += '<span class="nxtcc-settings-tools-preview-item-label">' + esc( label ) + '</span>';

			if ( note ) {
				html += '<span class="nxtcc-settings-tools-preview-item-note">' + esc( note ) + '</span>';
			}

			html += '</div>';
			html += '<span class="nxtcc-settings-tools-preview-count">' + esc( count ) + '</span>';
			html += '</li>';
		} );

		html += '</ul>';

		$preview.html( html );
	}

	function renderCleanupResult( payload, headingText ) {
		const items = Array.isArray( payload.items ) ? payload.items : [];
		const heading = headingText || strings.cleanupHeading || 'Cleanup result';
		const total = payload.total_deleted_display || '0';
		const summary = payload.summary || '';

		if ( ! items.length ) {
			showPreviewMessage( summary || strings.previewEmpty || '' );
			return;
		}

		let html = '';

		html += '<h4 class="nxtcc-settings-tools-preview-title">' + esc( heading ) + '</h4>';
		html += '<p class="nxtcc-settings-tools-preview-message">' + esc( summary || ( total + ' ' + ( strings.itemsLabel || 'older items' ) ) ) + '</p>';
		html += '<ul class="nxtcc-settings-tools-preview-list">';

		items.forEach( function ( item ) {
			const label = item.label || '';
			const deleted = typeof item.deleted !== 'undefined' ? item.deleted : 0;
			const remaining = typeof item.remaining !== 'undefined' ? item.remaining : 0;
			const note = item.message || '';

			html += '<li class="nxtcc-settings-tools-preview-item">';
			html += '<div class="nxtcc-settings-tools-preview-item-main">';
			html += '<span class="nxtcc-settings-tools-preview-item-label">' + esc( label ) + '</span>';
			html += '<span class="nxtcc-settings-tools-preview-item-note">' + esc( deleted ) + ' ' + esc( strings.itemsLabel || 'older items' );

			if ( remaining > 0 ) {
				html += ' | ' + esc( remaining ) + ' ' + esc( strings.remainingLabel || 'still waiting' );
			}

			html += '</span>';

			if ( note ) {
				html += '<span class="nxtcc-settings-tools-preview-item-note">' + esc( note ) + '</span>';
			}

			html += '</div>';
			html += '<span class="nxtcc-settings-tools-preview-count">' + esc( deleted ) + '</span>';
			html += '</li>';
		} );

		html += '</ul>';

		$preview.html( html );
	}

	function updateHistory( lastRun ) {
		if ( ! $history.length || ! lastRun || 'object' !== typeof lastRun ) {
			return;
		}

		const items = Array.isArray( lastRun.items ) ? lastRun.items : [];
		const statusClass = lastRun.status_class ? ' ' + lastRun.status_class : '';
		let listHtml = '';

		if ( items.length ) {
			listHtml += '<ul class="nxtcc-settings-tools-history-list">';

			items.forEach( function ( item ) {
				listHtml += '<li class="nxtcc-settings-tools-history-item">';
				listHtml += '<span class="nxtcc-settings-tools-history-item-label">' + esc( item.label || '' ) + '</span>';
				listHtml += '<span class="nxtcc-settings-tools-history-item-count">';
				listHtml += esc( item.deleted_display || item.deleted || '0' ) + ' removed, ';
				listHtml += esc( item.remaining_display || item.remaining || '0' ) + ' remaining';
				listHtml += '</span>';
				listHtml += '</li>';
			} );

			listHtml += '</ul>';
		}

		const html =
			'<div class="nxtcc-settings-tools-history-meta">' +
				'<div class="nxtcc-settings-tools-history-chip">' +
					'<span class="nxtcc-settings-tools-history-label">Last run</span>' +
					'<strong>' + esc( lastRun.started_at_display || 'Never' ) + '</strong>' +
				'</div>' +
				'<div class="nxtcc-settings-tools-history-chip">' +
					'<span class="nxtcc-settings-tools-history-label">Run type</span>' +
					'<strong>' + esc( lastRun.trigger_label || 'Not run yet' ) + '</strong>' +
				'</div>' +
				'<div class="nxtcc-settings-tools-history-chip">' +
					'<span class="nxtcc-settings-tools-history-label">Removed</span>' +
					'<strong>' + esc( lastRun.total_deleted_display || '0' ) + '</strong>' +
				'</div>' +
				'<div class="nxtcc-settings-tools-history-chip">' +
					'<span class="nxtcc-settings-tools-history-label">Status</span>' +
					'<strong class="nxtcc-settings-status-pill' + statusClass + '">' + esc( lastRun.status_label || 'Idle' ) + '</strong>' +
				'</div>' +
			'</div>' +
			'<p class="nxtcc-settings-tools-history-summary">' + esc( lastRun.summary || '' ) + '</p>' +
			listHtml;

		$history.html( html );
	}

	function ajaxRequest( actionName, extraData ) {
		return $.ajax( {
			url: config.ajaxUrl || ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: $.extend(
				{
					action: actionName,
					nonce: config.nonce || ''
				},
				extraData || {}
			)
		} );
	}

	function requestEverythingChallenge( message ) {
		if ( ! canManage ) {
			showPreviewMessage( strings.ownerLocked || 'Cleanup tools can only be managed by the tenant owner.' );
			return;
		}

		setBusy( true, 'challenge' );

		ajaxRequest( 'nxtcc_cleanup_everything_challenge' )
			.done( function ( response ) {
				const data = response && response.data ? response.data : {};

				if ( response && response.success ) {
					showDangerPanel(
						data.question || '12 + 34',
						data.token || '',
						message || strings.challengeIntro || 'To continue, solve this quick check.'
					);
					return;
				}

				showPreviewMessage( data.message || strings.requestFailed || '' );
			} )
			.fail( function ( xhr ) {
				const errorMessage = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ( strings.requestFailed || 'The cleanup request could not be completed. Please try again.' );
				showPreviewMessage( errorMessage );
			} )
			.always( function () {
				setBusy( false );
			} );
	}

	$previewButton.on( 'click', function () {
		if ( ! canManage ) {
			showPreviewMessage( strings.ownerLocked || 'Cleanup tools can only be managed by the tenant owner.' );
			return;
		}

		setBusy( true, 'preview' );

		ajaxRequest( 'nxtcc_cleanup_preview' )
			.done( function ( response ) {
				const data = response && response.data ? response.data : {};

				if ( response && response.success ) {
					renderPreview( data );
					return;
				}

				renderPreview( { items: [] }, strings.previewHeading || 'What can be cleared now' );
				if ( data.message ) {
					showPreviewMessage( data.message );
				}
			} )
			.fail( function ( xhr ) {
				const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ( strings.requestFailed || 'The cleanup request could not be completed. Please try again.' );
				showPreviewMessage( message );
			} )
			.always( function () {
				setBusy( false );
			} );
	} );

	$runButton.on( 'click', function () {
		if ( ! canManage ) {
			showPreviewMessage( strings.ownerLocked || 'Cleanup tools can only be managed by the tenant owner.' );
			return;
		}

		if ( ! $confirm.is( ':checked' ) ) {
			showPreviewMessage(
				strings.confirmRequired || 'Please confirm that you want to permanently remove older activity first.'
			);
			return;
		}

		hideDangerPanel();
		setBusy( true, 'cleanup' );

		ajaxRequest( 'nxtcc_cleanup_run' )
			.done( function ( response ) {
				const data = response && response.data ? response.data : {};

				if ( response && response.success ) {
					renderCleanupResult( data );
					updateHistory( data.last_run || {} );
					return;
				}

				showPreviewMessage( data.message || strings.requestFailed || '' );
			} )
			.fail( function ( xhr ) {
				const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ( strings.requestFailed || 'The cleanup request could not be completed. Please try again.' );
				showPreviewMessage( message );
			} )
			.always( function () {
				setBusy( false );
			} );
	} );

	$everythingButton.on( 'click', function () {
		requestEverythingChallenge();
	} );

	$mathCancel.on( 'click', function () {
		hideDangerPanel();
	} );

	$mathConfirm.on( 'click', function () {
		const token = $.trim( $mathToken.val() );
		const answer = $.trim( $mathAnswer.val() );
		let shouldRefreshChallenge = false;

		if ( ! token || ! answer ) {
			setDangerMessage( strings.challengeEmpty || 'Enter the answer before continuing.' );
			$mathAnswer.trigger( 'focus' );
			return;
		}

		setBusy( true, 'everything' );

		ajaxRequest(
			'nxtcc_cleanup_everything',
			{
				challenge_token: token,
				challenge_answer: answer
			}
		)
			.done( function ( response ) {
				const data = response && response.data ? response.data : {};

				if ( response && response.success ) {
					hideDangerPanel();
					renderCleanupResult(
						data,
						strings.cleanEverythingHeading || 'Clean everything result'
					);
					updateHistory( data.last_run || {} );
					return;
				}

				showPreviewMessage( data.message || strings.requestFailed || '' );
			} )
			.fail( function ( xhr ) {
				const data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
				const message = data.message || strings.requestFailed || 'The cleanup request could not be completed. Please try again.';

				if ( data.refresh_challenge ) {
					shouldRefreshChallenge = true;
					requestEverythingChallenge( message );
					return;
				}

				showPreviewMessage( message );
			} )
			.always( function () {
				if ( ! shouldRefreshChallenge ) {
					setBusy( false );
				}
			} );
	} );

	$mathAnswer.on( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			$mathConfirm.trigger( 'click' );
		}
	} );
} );
