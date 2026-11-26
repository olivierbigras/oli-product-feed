/**
 * Oli Product Feed feed editor and light wizard (plugin screen only, no jQuery).
 * Sections one at a time (stacked without JavaScript), attribute mapping, schedule, channel formats,
 * match count and the suggested feed name of the wizard.
 */
( function () {
	'use strict';

	var cfg  = window.oliprfeAdmin || {};
	var i18n = Object.assign( {
		badName: 'Use lowercase letters, digits and underscores.',
		exists: 'This attribute is already in the list.',
		counting: 'Counting…',
		countError: 'The count failed.'
	}, cfg.i18n || {} );

	function speak( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
		}
	}

	/* ---- Sections: one at a time, #section in the URL, a single form (nothing is lost) ---- */
	var sections = [];
	function sectionOf( el ) {
		return el && el.closest ? el.closest( '[data-oliprfe-section]' ) : null;
	}
	function show( id, focus ) {
		if ( ! sections.length ) {
			return;
		}
		var current = sections.filter( function ( s ) { return s.id === id; } )[0] || sections[0];
		sections.forEach( function ( s ) { s.hidden = s !== current; } );
		document.querySelectorAll( '.oliprfe-steps a' ).forEach( function ( a ) {
			if ( a.getAttribute( 'href' ) === '#' + current.id ) {
				a.setAttribute( 'aria-current', 'step' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
		var field = document.querySelector( 'input[name="oliprfe_section"]' );
		if ( field ) {
			field.value = current.id;
		}
		if ( ( focus || window.location.hash ) && window.location.hash !== '#' + current.id && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + current.id );
		}
		if ( focus ) {
			current.querySelector( 'h2' ).focus();
		}
	}
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '.oliprfe-steps a, [data-oliprfe-go], .oliprfe-error-summary a' ) : null;
		if ( ! link || ! sections.length ) {
			return;
		}
		var id     = ( link.getAttribute( 'href' ) || '' ).replace( /^#/, '' );
		var target = document.getElementById( id );
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		if ( target.hasAttribute( 'data-oliprfe-section' ) ) {
			show( id, true );
			return;
		}
		var section = sectionOf( target );
		if ( section ) {
			show( section.id, false );
		}
		target.focus();
	} );
	window.addEventListener( 'hashchange', function () {
		var id = window.location.hash.replace( /^#/, '' );
		if ( sections.some( function ( s ) { return s.id === id; } ) ) {
			show( id, true );
		}
	} );

	/* ---- Wizard: suggested name "{Channel} — {Language} ({Country})" until the name is edited ---- */
	function suggest() {
		var name    = document.querySelector( '.oliprfe-wizard #oliprfe-name' );
		var channel = document.querySelector( '.oliprfe-wizard input[name="feed[channel]"]:checked' );
		if ( ! name || 'auto' !== name.getAttribute( 'data-short' ) || ! channel ) {
			return;
		}
		var lang    = document.getElementById( 'oliprfe-language' );
		var country = document.getElementById( 'oliprfe-country' );
		var label   = '';
		if ( lang && 'SELECT' === lang.tagName ) {
			var names = {};
			try {
				names = JSON.parse( lang.getAttribute( 'data-short' ) || '{}' );
			} catch ( e ) {
				names = {};
			}
			label = names[ lang.value ] || lang.value;
		} else if ( lang ) {
			label = lang.getAttribute( 'data-short' ) || '';
		}
		var value = channel.getAttribute( 'data-short' ) + ' — ' + label;
		var suffix = country && country.value ? ' (' + country.options[ country.selectedIndex ].text + ')' : '';
		if ( suffix && value.slice( -suffix.length ) !== suffix ) {
			value += suffix;
		}
		name.value = value;
	}
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.closest && event.target.closest( '.oliprfe-wizard' ) && 'oliprfe-name' !== event.target.id ) {
			suggest();
		}
	} );
	document.addEventListener( 'input', function ( event ) {
		if ( 'oliprfe-name' === event.target.id && event.target.closest( '.oliprfe-wizard' ) ) {
			event.target.setAttribute( 'data-short', '' === event.target.value.trim() ? 'auto' : 'edited' );
		}
	} );

	/* ---- Mapping: the source type shows the matching control (others hidden + disabled) ---- */
	document.addEventListener( 'change', function ( event ) {
		var select = event.target;
		if ( ! select.classList || ! select.classList.contains( 'oliprfe-src-type' ) ) {
			return;
		}
		var row = select.closest( 'tr' );
		row.querySelectorAll( '[data-src]' ).forEach( function ( control ) {
			var on = control.getAttribute( 'data-src' ) === select.value;
			control.hidden   = ! on;
			control.disabled = ! on;
		} );
	} );

	/* ---- Schedule: the start hour only applies to daily and weekly feeds ---- */
	function syncHour() {
		var checked = document.querySelector( 'input[name="feed[frequency]"]:checked' );
		var hour    = document.getElementById( 'oliprfe-start_hour' );
		if ( checked && hour ) {
			hour.disabled = [ 'daily', 'weekly' ].indexOf( checked.value ) < 0;
		}
	}
	document.addEventListener( 'change', function ( event ) {
		if ( 'feed[frequency]' === event.target.name ) {
			syncHour();
		}
	} );

	/* ---- Custom mapping rows: add from the <template>, remove ---- */
	function addRow() {
		var input    = document.getElementById( 'oliprfe-add-attr' );
		var template = document.getElementById( 'oliprfe-row-template' );
		var body     = document.querySelector( '.oliprfe-custom-rows' );
		if ( ! input || ! template || ! body ) {
			return;
		}
		var name = input.value.trim().toLowerCase();
		if ( ! /^[a-z0-9_]+$/.test( name ) ) {
			speak( i18n.badName );
			input.setAttribute( 'aria-invalid', 'true' );
			input.focus();
			return;
		}
		if ( document.querySelector( '[name="feed[mapping][' + name + '][type]"]' ) ) {
			speak( i18n.exists );
			input.setAttribute( 'aria-invalid', 'true' );
			input.focus();
			return;
		}
		input.removeAttribute( 'aria-invalid' );
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = template.innerHTML.split( '__FIELD__' ).join( name );
		var row = holder.querySelector( 'tr' );
		body.appendChild( row );
		input.value = '';
		var first = row.querySelector( 'select, input' );
		if ( first ) {
			first.focus();
		}
	}
	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target.closest( '.oliprfe-add-row, .oliprfe-remove-row' ) : null;
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		if ( target.classList.contains( 'oliprfe-add-row' ) ) {
			addRow();
			return;
		}
		var row = target.closest( 'tr' );
		if ( row ) {
			row.parentNode.removeChild( row );
			var add = document.getElementById( 'oliprfe-add-attr' );
			if ( add ) {
				add.focus();
			}
		}
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'oliprfe-add-attr' === event.target.id && 'Enter' === event.key ) {
			event.preventDefault();
			addRow();
		}
	} );


	/* ---- Channel: formats of the channel only, generic settings ---- */
	function syncChannel() {
		var radio  = document.querySelector( 'input[name="feed[channel]"]:checked' );
		var select = document.getElementById( 'oliprfe-format' );
		if ( ! radio || ! select ) {
			return;
		}
		var formats = ( radio.getAttribute( 'data-formats' ) || '' ).split( ',' );
		Array.prototype.forEach.call( select.options, function ( option ) {
			option.disabled = formats.indexOf( option.value ) === -1;
		} );
		if ( select.selectedOptions.length && select.selectedOptions[0].disabled ) {
			select.value = formats[0];
		}
		document.querySelectorAll( '.oliprfe-for-generic' ).forEach( function ( row ) {
			row.hidden = radio.value !== 'generic';
		} );
	}
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.name === 'feed[channel]' ) {
			syncChannel();
		}
	} );

	/* ---- Backorder lead time: extra fields only when the option is on ---- */
	function syncBackorder() {
		var on = document.querySelector( 'input[name="feed[backorder_lead_enabled]"]' );
		document.querySelectorAll( '.oliprfe-for-backorder' ).forEach( function ( row ) {
			row.hidden = ! on || ! on.checked;
		} );
	}
	document.addEventListener( 'change', function ( event ) {
		if ( 'feed[backorder_lead_enabled]' === event.target.name ) {
			syncBackorder();
		}
	} );

	/* ---- Number of products matching the filters (unsaved form) ---- */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.oliprfe-match-refresh' );
		if ( ! button || ! cfg.ajaxUrl ) {
			return;
		}
		var form = button.closest( 'form' );
		var text = form.querySelector( '.oliprfe-match-text' );
		var data = new FormData( form );
		data.set( 'action', 'oliprfe_match_count' );
		data.set( '_ajax_nonce', cfg.nonce || '' );
		data.delete( '_wpnonce' );
		button.disabled  = true;
		text.textContent = i18n.counting;
		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				text.textContent = json && json.success ? json.data.text : i18n.countError;
			} )
			.catch( function () { text.textContent = i18n.countError; } )
			.then( function () { button.disabled = false; } );
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		sections = Array.prototype.slice.call( document.querySelectorAll( '[data-oliprfe-section]' ) );
		var summary = document.querySelector( '.oliprfe-error-summary' );
		var first   = summary ? summary.querySelector( 'a' ) : null;
		var field   = first ? document.getElementById( ( first.getAttribute( 'href' ) || '' ).replace( /^#/, '' ) ) : null;
		var section = sectionOf( field );
		show( section ? section.id : window.location.hash.replace( /^#/, '' ), false );
		syncHour();
		syncChannel();
		syncBackorder();
	} );
}() );
