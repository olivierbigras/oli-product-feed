/**
 * Oli Product Feed admin script (plugin screens only, no jQuery).
 * Copy URL, delete dialog, error summary and generation progress (the feed editor has its own editor.js).
 * Strings come from wp_localize_script( 'oliprfe-admin', 'oliprfeAdmin', { i18n, plural, ajaxUrl, nonce } ).
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
		selectOne: 'Select at least one feed.'
	}, cfg.i18n || {} );
	var plural = cfg.plural || { title: {}, button: {} };

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

	/* ---- Delete dialog (row, editor and bulk): replaces the browser confirmation; focus on Cancel, Escape closes ---- */
	var dialog, opener, onDelete, onDeactivate;
	function openDialog( feeds, del, deactivate, from ) {
		dialog = dialog || document.getElementById( 'oliprfe-delete-dialog' );
		if ( ! dialog || ! dialog.showModal ) {
			return false; // No <dialog> support: the link or the form works as is.
		}
		var n    = feeds.length;
		var list = dialog.querySelector( '.oliprfe-delete-list' );
		dialog.querySelector( 'h2' ).textContent = plural.title[ n ] || '';
		dialog.querySelector( '.oliprfe-dialog-delete' ).textContent = plural.button[ n ] || '';
		if ( plural.desc && plural.desc[ n ] ) {
			dialog.querySelector( '#oliprfe-delete-desc' ).textContent = plural.desc[ n ];
		}
		list.textContent = '';
		feeds.forEach( function ( feed ) {
			var li = document.createElement( 'li' );
			li.textContent = feed.name + ( feed.file ? ' ' : '' );
			if ( feed.file ) {
				var code = document.createElement( 'code' );
				code.textContent = feed.file;
				li.appendChild( code );
			}
			list.appendChild( li );
		} );
		opener       = from;
		onDelete     = del;
		onDeactivate = deactivate;
		dialog.showModal();
		dialog.querySelector( '.oliprfe-dialog-cancel' ).focus();
		return true;
	}
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-oliprfe-delete]' ) : null;
		if ( link ) {
			var ok = openDialog(
				[ { name: link.getAttribute( 'data-name' ), file: link.getAttribute( 'data-file' ) } ],
				function () { window.location.href = link.href + '&confirm=1'; }, // Confirmed here: no confirmation page.
				function () { window.location.href = link.getAttribute( 'data-deactivate' ); },
				link
			);
			if ( ok ) {
				event.preventDefault();
			}
			return;
		}
		if ( ! dialog || ! event.target.closest || ! event.target.closest( '#oliprfe-delete-dialog' ) ) {
			return;
		}
		if ( event.target.closest( '.oliprfe-dialog-cancel' ) ) {
			dialog.close();
		} else if ( event.target.closest( '.oliprfe-dialog-delete' ) ) {
			onDelete();
		} else if ( event.target.closest( '.oliprfe-dialog-deactivate' ) ) {
			onDeactivate();
		}
	} );
	document.addEventListener( 'close', function ( event ) {
		if ( event.target === dialog && opener && document.contains( opener ) ) {
			opener.focus(); // Focus back to what opened the dialog.
		}
	}, true );

	/* Bulk Delete opens the same dialog; "Deactivate instead" switches the bulk action. */
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( 'oliprfe-feeds-form' !== form.id || form.oliprfeConfirmed ) {
			return;
		}
		var submitter = event.submitter || document.activeElement;
		var which     = submitter && 'doaction2' === submitter.id ? 'action2' : 'action';
		var select    = form.querySelector( 'select[name="' + which + '"]' );
		if ( ! select || 'delete' !== select.value || ( submitter && 'filter_action' === submitter.name ) ) {
			return;
		}
		var boxes = form.querySelectorAll( 'input[name="feed_ids[]"]:checked' );
		if ( ! boxes.length ) {
			event.preventDefault();
			speak( i18n.selectOne );
			return;
		}
		var feeds = Array.prototype.map.call( boxes, function ( box ) {
			return { name: box.getAttribute( 'data-name' ), file: '' };
		} );
		function send( action ) {
			form.querySelectorAll( 'select[name="action"], select[name="action2"]' ).forEach( function ( s ) { s.value = action; } );
			var confirmed = document.createElement( 'input' );
			confirmed.type  = 'hidden';
			confirmed.name  = 'confirm';
			confirmed.value = '1';
			form.appendChild( confirmed );
			form.oliprfeConfirmed = true;
			form.submit();
		}
		if ( openDialog( feeds, function () { send( 'delete' ); }, function () { send( 'deactivate' ); }, submitter ) ) {
			event.preventDefault();
		}
	} );

	/* ---- Multilingual notice: its dismiss button hides it for this user too ---- */
	document.addEventListener( 'click', function ( event ) {
		var notice = event.target.closest ? event.target.closest( '[data-oliprfe-hide] .notice-dismiss' ) : null;
		if ( notice && window.fetch ) {
			window.fetch( notice.parentNode.getAttribute( 'data-oliprfe-hide' ) + '&ajax=1', { credentials: 'same-origin' } );
		}
	} );

	/* ---- Generation progress (polls every 3 s while a feed is generating, no reload) ---- */
	var milestones = {};
	var done = {};
	function finish( feed ) {
		if ( done[ feed.id ] ) {
			return;
		}
		done[ feed.id ] = true;
		var row = document.querySelector( '[data-oliprfe-row="' + feed.id + '"]' );
		if ( row && feed.cells ) {
			Object.keys( feed.cells ).forEach( function ( col ) {
				var cell = row.querySelector( 'td.column-' + col );
				if ( cell ) {
					// Server-escaped HTML; keep the "Show more details" toggle of small screens.
					var toggle = cell.querySelector( '.toggle-row' );
					cell.innerHTML = feed.cells[ col ];
					if ( toggle ) { cell.appendChild( toggle ); }
				}
			} );
		}
		var notice = document.querySelector( '[data-oliprfe-notice="' + feed.id + '"]' );
		if ( feed.notice ) {
			var holder = document.createElement( 'div' );
			holder.innerHTML = feed.notice;
			if ( notice ) {
				notice.parentNode.replaceChild( holder.firstChild, notice );
			} else {
				var box = document.querySelector( '.oliprfe-run-notices' );
				if ( box ) { box.appendChild( holder.firstChild ); }
			}
		}
		speak( feed.speak || ( ( 'error' === feed.status ? i18n.failed : i18n.generated ) + ' ' + feed.name ) );
	}
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
				( json && json.success ? json.data : [] ).forEach( function ( feed ) {
					var el = document.querySelector( '[data-oliprfe-generating="' + feed.id + '"]' );
					if ( ! el ) { return; }
					if ( 'generating' !== feed.status ) {
						el.removeAttribute( 'data-oliprfe-generating' );
						finish( feed );
						return;
					}
					var bar  = el.querySelector( 'progress' );
					var text = el.querySelector( '.oliprfe-progress-text' );
					var note = document.querySelector( '[data-oliprfe-notice="' + feed.id + '"] .oliprfe-run-text' );
					if ( bar ) { bar.value = feed.percent; }
					if ( text ) { text.textContent = feed.progressText; }
					if ( note ) { note.textContent = feed.noticeText; }
					/* Spoken once at the start (first refresh), at 25, 50 and 75 %, and at the end: wp.a11y.speak() only. */
					if ( ! ( feed.id in milestones ) ) {
						milestones[ feed.id ] = [ 75, 50, 25 ].filter( function ( step ) { return feed.percent >= step; } )[0] || 0;
						speak( feed.noticeText );
						return;
					}
					[ 75, 50, 25 ].some( function ( step ) {
						if ( feed.percent >= step && ( milestones[ feed.id ] || 0 ) < step ) {
							milestones[ feed.id ] = step;
							speak( feed.noticeText );
							return true;
						}
						return false;
					} );
				} );
				window.setTimeout( poll, 3000 );
			} )
			.catch( function () { window.setTimeout( poll, 10000 ); } );
	}


	document.addEventListener( 'DOMContentLoaded', function () {
		live = document.createElement( 'div' );
		live.className = 'screen-reader-text';
		live.setAttribute( 'aria-live', 'polite' );
		live.setAttribute( 'aria-atomic', 'true' );
		document.body.appendChild( live );

		window.setTimeout( poll, 3000 );
	} );
	/* The error summary gets the focus first (after WordPress has moved the notices). */
	window.addEventListener( 'load', function () {
		var summary = document.querySelector( '.oliprfe-error-summary' );
		if ( summary ) {
			summary.focus();
		}
	} );
}() );
