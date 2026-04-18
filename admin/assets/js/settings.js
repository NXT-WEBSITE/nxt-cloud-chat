/**
 * Settings shell UI.
 *
 * Handles shared tab switching only.
 *
 * @package NXTCC
 */

/* global jQuery */

jQuery( function ( $ ) {
	'use strict';

	const $widget = $( '.nxtcc-settings-widget' );

	if ( ! $widget.length ) {
		return;
	}

	$widget.on( 'click', '.nxtcc-settings-tab', function () {
		const tab = $( this ).data( 'tab' );

		if ( ! tab ) {
			return;
		}

		$widget
			.find( '.nxtcc-settings-tab' )
			.removeClass( 'active' )
			.attr( 'aria-selected', 'false' );

		$widget.find( '.nxtcc-settings-tab-content' ).hide();

		$( this ).addClass( 'active' ).attr( 'aria-selected', 'true' );

		$widget
			.find( '.nxtcc-settings-tab-content[data-tab="' + tab + '"]' )
			.show();
	} );
} );
