/**
 * Oli Product Feed admin script (plugin screens only, no jQuery).
 * Copy URL, confirmations, attribute mapping, schedule, channel formats, match count, error summary and generation progress.
 * Strings come from wp_localize_script( 'oliprfe-admin', 'oliprfeAdmin', { i18n, ajaxUrl, nonce } ).
 */
( function () {
	'use strict';

	var cfg  = window.oliprfeAdmin || {};
	var i18n = Object.assign( {
		copy: 'Copy',
		copied: 'Copied',
		copiedLong: 'Feed URL copied to clipboard.',
		copyFailed: 'Press Ctrl+C (Cmd+C on a Mac) to copy the selected URL.',
		generated: 'Feed generated.',
		failed: 'Feed generation failed.',
		badName: 'Use lowercase letters, digits and underscores.',
		exists: 'This attribute is already in the list.',
		counting: 'Counting…',
		countError: 'The count failed.'
	}, cfg.i18n || {} );

	/* Screen reader announcements: wp.a11y.speak(), else a plugin live region. */
	var live;
	function speak( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, 'polite' );
			return;
		}
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		window.setTimeout( function () { live.textContent = message; }, 100 );
	}

	/* ---- Copy the feed URL ---- */
	function legacyCopy( input ) {
		var wasHidden = input.hidden;
		var ok        = false;
		try {
			input.hidden = false; // A hidden field cannot be selected.
			input.focus();
			input.select();
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		input.hidden = wasHidden && ok;
		return ok;
	}

	function copied( button ) {
		var label = button.querySelector( '.oliprfe-copy-label' );
		var icon  = button.querySelector( '.dashicons' );
		button.focus(); // Give the focus back to the button.
		button.classList.add( 'is-copied' );
		if ( label ) { label.textContent = i18n.copied; }
		if ( icon ) { icon.className = 'dashicons dashicons-yes'; }
		speak( i18n.copiedLong );
		window.clearTimeout( button.oliprfeTimer );
		button.oliprfeTimer = window.setTimeout( function () {
			button.classList.remove( 'is-copied' );
			if ( label ) { label.textContent = i18n.copy; }
			if ( icon ) { icon.className = 'dashicons dashicons-admin-page'; }
		}, 2500 );
	}

	function failed( button, input ) {
		var scope = button.closest( 'td, .misc-pub-section' ) || document;
		var hint  = scope.querySelector( '.oliprfe-copy-hint' );
		input.hidden = false; // Show the full field for a manual copy.
		input.focus();
		input.select();
		if ( hint ) {
			hint.textContent = i18n.copyFailed;
			hint.hidden = false;
		}
		speak( i18n.copyFailed );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.oliprfe-copy' ) : null;
		if ( ! button ) {
			return;
		}
		var input = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! input ) {
			return;
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then(
				function () { copied( button ); },
				function () { ( legacyCopy( input ) ? copied : failed )( button, input ); }
			);
		} else if ( legacyCopy( input ) ) {
			copied( button );
		} else {
			failed( button, input );
		}
	} );

	/* Focusing the field selects the whole URL. */
	document.addEventListener( 'focusin', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'oliprfe-url-input' ) ) {
			event.target.select();
		}
	} );

	/* ---- Confirmations ---- */
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '.oliprfe-confirm' ) : null;
		if ( link && link.getAttribute( 'data-confirm' ) && ! window.confirm( link.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
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

	/* ---- Generation progress (polls every 3 s while a feed is generating) ---- */
	function poll() {
		var rows = document.querySelectorAll( '[data-oliprfe-generating]' );
		if ( ! rows.length || ! cfg.ajaxUrl ) {
			return;
		}
		var ids  = Array.prototype.map.call( rows, function ( el ) { return el.getAttribute( 'data-oliprfe-generating' ); } );
		var body = new URLSearchParams( { action: 'oliprfe_feed_status', _ajax_nonce: cfg.nonce, ids: ids.join( ',' ) } );
		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				var done = false;
				( json && json.success ? json.data : [] ).forEach( function ( feed ) {
					var el = document.querySelector( '[data-oliprfe-generating="' + feed.id + '"]' );
					if ( ! el ) { return; }
					var bar  = el.querySelector( 'progress' );
					var text = el.querySelector( '.oliprfe-progress-text' );
					if ( bar ) { bar.value = feed.percent; }
					if ( text ) { text.textContent = feed.progressText; }
					if ( 'generating' !== feed.status ) {
						done = true;
						speak( ( 'error' === feed.status ? i18n.failed : i18n.generated ) + ' ' + feed.name );
						el.removeAttribute( 'data-oliprfe-generating' );
					}
				} );
				if ( done ) {
					window.location.reload(); // Reload to show the new values.
				} else {
					window.setTimeout( poll, 3000 );
				}
			} )
			.catch( function () { window.setTimeout( poll, 10000 ); } );
	}

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
		live = document.createElement( 'div' );
		live.className = 'screen-reader-text';
		live.setAttribute( 'aria-live', 'polite' );
		live.setAttribute( 'aria-atomic', 'true' );
		document.body.appendChild( live );

		/* The error summary gets the focus first. */
		var summary = document.querySelector( '.oliprfe-error-summary' );
		if ( summary ) {
			summary.focus();
		}
		syncHour();
		syncChannel();
		syncBackorder();
		window.setTimeout( poll, 3000 );
	} );
}() );
