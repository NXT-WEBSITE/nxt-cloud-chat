/**
 * Auth allowed countries selector UI.
 *
 * Builds a searchable list of countries and manages a selected set of ISO2 codes.
 *
 * @package NXTCC
 */

( function ( w, $ ) {
	if ( ! w.NXTCCAuthAllowedCountries ) {
		w.NXTCCAuthAllowedCountries = {};
	}

	const API = w.NXTCCAuthAllowedCountries;

	// Internal state.
	let ROOT       = null,
		LIST       = null,
		SEARCH     = null,
		COUNT      = null,
		onChangeCb = function () {};

	let ALL      = []; // [{ iso2, dial, name }].
	let FILTERED = []; // Computed by search.
	let SELECTED = new Set();

	const COUNTRY_JSON_URL =
		( w.NXTCC_AUTH_ADMIN && w.NXTCC_AUTH_ADMIN.countryJson ) || null;

	function renderCount() {
		if ( ! COUNT ) {
			return;
		}

		COUNT.textContent = 'Selected: ' + SELECTED.size;
	}

	function applyFilter() {
		const q = ( ( SEARCH && SEARCH.value ) || '' ).trim().toLowerCase();

		if ( ! q ) {
			FILTERED = ALL.slice();
		} else {
			FILTERED = ALL.filter( ( c ) => {
				return (
					( c.dial && c.dial.toLowerCase().includes( q ) ) ||
					( c.name && c.name.toLowerCase().includes( q ) )
				);
			} );
		}

		renderList();
	}

	function renderList() {
		if ( ! LIST ) {
			return;
		}

		LIST.innerHTML = '';

		FILTERED.forEach( ( c ) => {
			const li     = document.createElement( 'label' );
			li.className = 'nxtcc-ac-item';
			li.setAttribute( 'role', 'option' );

			const cb   = document.createElement( 'input' );
			cb.type    = 'checkbox';
			cb.value   = c.iso2;
			cb.checked = SELECTED.has( c.iso2 );

			cb.addEventListener( 'change', () => {
				if ( cb.checked ) {
					SELECTED.add( c.iso2 );
				} else {
					SELECTED.delete( c.iso2 );
				}

				renderCount();
				onChangeCb( Array.from( SELECTED ) );
			} );

			const dial       = document.createElement( 'span' );
			dial.className   = 'nxtcc-ac-dial';
			dial.textContent = c.dial || '';

			const name       = document.createElement( 'span' );
			name.className   = 'nxtcc-ac-name';
			name.textContent = c.name || c.iso2;

			li.appendChild( cb );
			li.appendChild( dial );
			li.appendChild( name );

			LIST.appendChild( li );
		} );
	}

	function normalizeJson( arr ) {
		const out = [];

		( arr || [] ).forEach( ( row ) => {
			const iso = String( row.iso2 || row.alpha2 || row.code || '' )
				.trim()
				.toUpperCase();

			const dial = String(
				row.dial_code || row.dial || row.phone_code || ''
			).trim();

			const name = String( row.country_name || row.name || iso ).trim();

		if ( ! iso || ! dial ) {
			return;
		}

			const label = dial.startsWith( '+' ) ? dial : '+' + dial;
			out.push( { iso2: iso, dial: label, name } );
		} );

		// Sort by name asc for stable UI.
		out.sort( ( a, b ) => a.name.localeCompare( b.name ) );

		return out;
	}

	function fetchCountries() {
		return new Promise( ( resolve ) => {
			if ( ! COUNTRY_JSON_URL ) {
				resolve( [] );
				return;
			}

			fetch( COUNTRY_JSON_URL, { credentials: 'same-origin' } )
				.then( ( r ) => ( r.ok ? r.json() : [] ) )
				.then( resolve )
				.catch( () => resolve( [] ) );
		} );
	}

	API.mount = function ( el, opts ) {
		ROOT = el;

		if ( ! ROOT ) {
			return;
		}

		LIST   = ROOT.querySelector( '#nxtcc-ac-list' );
		SEARCH = ROOT.querySelector( '#nxtcc-ac-search' );
		COUNT  = ROOT.querySelector( '#nxtcc-ac-count' );

		SELECTED = new Set(
			Array.isArray( opts && opts.selected )
				? opts.selected.map( ( s ) => String( s || '' ).toUpperCase() )
				: []
		);

		onChangeCb =
			opts && typeof opts.onChange === 'function'
				? opts.onChange
				: function () {};

		// Controls.
		const $selectAll = $( ROOT ).find( '#nxtcc-ac-select-all' );
		const $clearAll  = $( ROOT ).find( '#nxtcc-ac-clear-all' );

		$selectAll.on( 'click', function ( e ) {
			e.preventDefault();

			FILTERED.forEach( ( c ) => SELECTED.add( c.iso2 ) );

			renderList();
			renderCount();
			onChangeCb( Array.from( SELECTED ) );
		} );

		$clearAll.on( 'click', function ( e ) {
			e.preventDefault();

			FILTERED.forEach( ( c ) => SELECTED.delete( c.iso2 ) );

			renderList();
			renderCount();
			onChangeCb( Array.from( SELECTED ) );
		} );

		// Search.
		let t = null;

		$( SEARCH ).on( 'input', function () {
			clearTimeout( t );
			t = setTimeout( applyFilter, 150 );
		} );

		// Load data.
		fetchCountries().then( ( json ) => {
			ALL      = normalizeJson( json );
			FILTERED = ALL.slice();

			renderList();
			renderCount();
		} );
	};

	API.getSelected = function () {
		return Array.from( SELECTED );
	};

	API.setSelected = function ( arr ) {
		SELECTED = new Set(
			Array.isArray( arr )
				? arr.map( ( s ) => String( s || '' ).toUpperCase() )
				: []
		);

		renderList();
		renderCount();
	};

	API.destroy = function () {
		ROOT       = LIST = SEARCH = COUNT = null;
		ALL        = FILTERED = [];
		SELECTED   = new Set();
		onChangeCb = function () {};
	};
} )( window, jQuery );



