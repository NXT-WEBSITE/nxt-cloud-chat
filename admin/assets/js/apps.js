/**
 * Apps admin screen interactions.
 *
 * Handles app card clicks and opens the primary CTA in a new tab.
 *
 * @package NXTCC
 */

jQuery(
	function ( $ ) {
		// Card click -> open primary CTA in new tab.
		$( '.nxtcc-app-card' ).on(
			'click',
			function ( e ) {
				if ( $( e.target ).closest( 'a, button' ).length ) {
					return; // Let normal links work.
				}

				const $primary = $( this )
					.find( '.nxtcc-app-cta-primary' )
					.first();

				if ( $primary.length ) {
					const href = $primary.attr( 'href' );

					if ( href && href !== '#' ) {
						window.open(
							href,
							$primary.attr( 'target' ) || '_blank'
						);
					}
				}
			}
		);
	}
);



