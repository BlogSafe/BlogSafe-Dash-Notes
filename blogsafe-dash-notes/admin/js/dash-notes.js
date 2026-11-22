/**
 * BlogSafe Dash Notes: the sticky-note board on the WordPress dashboard.
 *
 * The note body (`note.html`) is HTML that the server built and filtered
 * with wp_kses_post plus core embeds and media shortcodes, the same way
 * WordPress builds a post. It is the only HTML inserted. Everything else
 * (titles, names, dates) goes in with textContent or DOM attribute setters.
 */
( function () {
	'use strict';

	var cfg = window.blogsafeDashNotes;
	if ( ! cfg || ! document.getElementById( 'wpbody-content' ) ) {
		return;
	}
	var t = cfg.i18n;
	var notes = {}; // id -> { data: object, el: HTMLElement }
	var topZ = 0;
	var board, toggleBtn;
	var pending = {}; // id -> state waiting to be saved
	var timers = {};
	var tempCount = 0;
	var editorCount = 0;
	var DEFAULT_W = 260;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function iconButton( icon, label, className ) {
		var b = el( 'button', 'bsdn-icon-btn ' + ( className || '' ) );
		b.type = 'button';
		b.setAttribute( 'aria-label', label );
		b.title = label;
		b.appendChild( el( 'span', 'dashicons dashicons-' + icon ) );
		return b;
	}

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	/**
	 * The REST base on this page's own origin. WordPress builds it from the
	 * Site Address; if the admin is open on another form of it (www or not,
	 * http or https) the browser would block the request ("Failed to
	 * fetch"). Same path, this page's scheme and host.
	 */
	var restBase = ( function () {
		try {
			var u = new URL( cfg.rest, window.location.href );
			return window.location.origin + u.pathname + u.search;
		} catch ( err ) {
			return cfg.rest;
		}
	}() );

	/** Random id that marks one create/reply, so a repeat of it is ignored. */
	function oneTimeToken() {
		var bytes = new Uint8Array( 16 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function url( path, query ) {
		var u = restBase.replace( /\/$/, '' ) + path;
		if ( query ) {
			u += ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
		}
		return u;
	}

	function api( method, path, body, query ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		};
		// A request that gets no answer at all is tried once more after a
		// moment. The usual cause is a kept-alive connection the server
		// closed while the page sat idle: browsers retry those for page
		// loads but not for saves. Repeating is safe: edits and state are
		// idempotent, and new notes and replies carry a one-time token the
		// server uses to ignore a duplicate.
		return window.fetch( url( path, query ), options ).catch( function () {
			return new Promise( function ( resolve ) {
				setTimeout( resolve, 700 );
			} ).then( function () {
				return window.fetch( url( path, query ), options );
			} );
		} ).catch( function () {
			// Still no answer: offline, server down, or the connection cut.
			throw new Error( t.networkError );
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { json: json, isJson: true };
			}, function () {
				return { json: {}, isJson: false };
			} ).then( function ( r ) {
				if ( ! res.ok ) {
					if ( r.json && r.json.message ) {
						throw new Error( r.json.message ); // WordPress said why
					}
					// Not a WordPress answer: something in front of it refused.
					throw new Error( fmt( r.isJson ? t.serverError : t.blockedError, res.status ) );
				}
				return r.json;
			} );
		} );
	}

	function textColor( hex ) {
		var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( hex || '' );
		if ( ! m ) {
			return '#1d2327';
		}
		var lum = ( 0.299 * parseInt( m[ 1 ], 16 ) + 0.587 * parseInt( m[ 2 ], 16 ) + 0.114 * parseInt( m[ 3 ], 16 ) ) / 255;
		return lum < 0.55 ? '#ffffff' : '#1d2327';
	}

	function formatDate( ymd ) {
		var p = ( ymd || '' ).split( '-' );
		if ( p.length !== 3 ) {
			return ymd;
		}
		var d = new Date( parseInt( p[ 0 ], 10 ), parseInt( p[ 1 ], 10 ) - 1, parseInt( p[ 2 ], 10 ) );
		return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
	}

	function getSetting( key ) {
		return typeof window.getUserSetting === 'function' ? window.getUserSetting( key ) : '';
	}

	function setSetting( key, value ) {
		if ( typeof window.setUserSetting === 'function' ) {
			window.setUserSetting( key, value );
		}
	}

	var EDIT_MIN_W = 480;

	function hasEditor() {
		return !! ( window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function' );
	}

	/** Start the audio/video players in a freshly rendered note body. */
	function initPlayers( container ) {
		if ( ! window.jQuery || ! window.jQuery.fn.mediaelementplayer || ! window._wpmejsSettings ) {
			return;
		}
		window.jQuery( container ).find( '.wp-audio-shortcode, .wp-video-shortcode' )
			.not( '.mejs-container' )
			.filter( function () {
				return ! window.jQuery( this ).parent().hasClass( 'mejs-mediaelement' );
			} )
			.mediaelementplayer( window._wpmejsSettings );
	}

	/**
	 * Lowest top (px, board coordinates) that keeps the note's bottom within
	 * the page content, or within the window when the content is shorter.
	 * The board is absolutely positioned, so the host's own height is the
	 * height of the real page content.
	 */
	function maxTop( entry ) {
		if ( board.classList.contains( 'is-fixed' ) ) {
			return Math.max( 0, window.innerHeight - board.getBoundingClientRect().top - 40 );
		}
		var host = board.parentNode;
		var boardTop = board.getBoundingClientRect().top + window.scrollY;
		var bottom = Math.max( host.offsetHeight, window.innerHeight - boardTop );
		return Math.max( 0, bottom - entry.el.offsetHeight );
	}

	function boardWidth() {
		// #wpbody-content can reach under the vertical scrollbar; use the
		// part of the board that is actually visible.
		var visible = document.documentElement.clientWidth - board.getBoundingClientRect().left - 8;
		return Math.max( 320, Math.min( board.clientWidth, visible ) );
	}

	/* ------------------------------------------------------------------ */
	/* Saving per-user state (position, size, order, minimised)            */
	/* ------------------------------------------------------------------ */

	function queueState( id, state ) {
		if ( ! id ) {
			return;
		}
		pending[ id ] = Object.assign( pending[ id ] || {}, state );
		clearTimeout( timers[ id ] );
		timers[ id ] = setTimeout( function () {
			var body = pending[ id ];
			delete pending[ id ];
			api( 'POST', '/notes/' + id + '/state', body ).catch( function () {} );
		}, 500 );
	}

	function bringToFront( entry ) {
		var z = entry.data.state.z || 0;
		if ( z >= topZ && z > 0 ) {
			return;
		}
		topZ += 1;
		entry.data.state.z = topZ;
		entry.el.style.zIndex = String( topZ );
		queueState( entry.data.id, { z: topZ } );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	function defaultPosition( index ) {
		var step = ( index % 8 ) * 28;
		return {
			x: Math.max( 0, boardWidth() - DEFAULT_W - 24 - step ),
			y: 48 + step
		};
	}

	function applyBox( entry ) {
		var s = entry.data.state;
		var node = entry.el;
		var width = entry.editing ? Math.min( Math.max( s.w || DEFAULT_W, EDIT_MIN_W ), boardWidth() ) : ( s.w || DEFAULT_W );
		// Keep the whole note on screen; pages differ in width (admin menu,
		// block editor), so this is display only and the saved position stays.
		var maxX = Math.max( 0, boardWidth() - width );
		node.style.left = Math.min( s.x, maxX ) + 'px';
		node.style.width = width + 'px';
		node.style.height = s.minimized || entry.editing || ! s.h ? 'auto' : s.h + 'px';
		node.style.zIndex = String( s.z || 0 );
		node.style.background = entry.data.color;
		node.style.color = textColor( entry.data.color );
		node.classList.toggle( 'is-minimized', !! s.minimized && ! entry.editing );

		// Pinned to a page element: sit beside it (display only; the saved
		// position is kept for pages where the element is missing).
		node.classList.remove( 'is-anchored' );
		var target = entry.editing ? null : anchorEl( entry.data );
		if ( target ) {
			var tr = target.getBoundingClientRect();
			var br = board.getBoundingClientRect();
			var nw = node.offsetWidth;
			var ax = tr.right - br.left + 8;
			var ay = tr.top - br.top;
			if ( ax + nw > boardWidth() ) {
				ax = tr.left - br.left - nw - 8; // no room on the right: left side
			}
			if ( ax < 0 ) {
				ax = tr.left - br.left; // no room either side: below it
				ay = tr.bottom - br.top + 8;
			}
			node.style.left = Math.max( 0, Math.min( ax, boardWidth() - nw ) ) + 'px';
			node.style.top = Math.max( 0, ay ) + 'px';
			node.classList.add( 'is-anchored' );
			return;
		}

		// Vertical position, once the size is known.
		var y = s.y;
		if ( board.classList.contains( 'is-fixed' ) ) {
			// A fixed layer can't scroll: keep the note inside the window
			// and let its contents scroll instead (display only).
			var avail = window.innerHeight - board.getBoundingClientRect().top;
			y = Math.min( s.y, Math.max( 0, avail - 160 ) );
			node.style.maxHeight = Math.max( 120, avail - y - 8 ) + 'px';
		} else {
			// The page may have got shorter since the note was placed (a
			// long list hidden, a panel closed): pull the note up so it
			// never hangs below the page content (display only).
			y = Math.min( s.y, maxTop( entry ) );
		}
		node.style.top = y + 'px';
	}

	/** Whole days from today (site date) to a Y-m-d date; negative = past. */
	function daysUntil( ymd ) {
		var a = ( cfg.today || '' ).split( '-' ).map( Number );
		var b = ( ymd || '' ).split( '-' ).map( Number );
		if ( a.length !== 3 || b.length !== 3 ) {
			return null;
		}
		return Math.round( ( Date.UTC( b[ 0 ], b[ 1 ] - 1, b[ 2 ] ) - Date.UTC( a[ 0 ], a[ 1 ] - 1, a[ 2 ] ) ) / 86400000 );
	}

	/** Deadline today, tomorrow or past, and not done: the note is nudged. */
	function isDueSoon( note ) {
		var d = note.deadline ? daysUntil( note.deadline ) : null;
		return d !== null && d <= 1 && ! note.done;
	}

	function roleNames( keys ) {
		return ( keys || [] ).map( function ( k ) {
			var r = ( cfg.roles || [] ).filter( function ( x ) {
				return x.key === k;
			} )[ 0 ];
			return r ? r.name : k;
		} ).join( ', ' );
	}

	function metaLine( note, entry ) {
		var meta = el( 'div', 'bsdn-meta' );
		if ( note.id && entry ) {
			var count = ( note.replies || [] ).length;
			var rb = el( 'button', 'bsdn-chip bsdn-reply-toggle' + ( entry.showReplies ? ' is-open' : '' ) );
			rb.type = 'button';
			rb.setAttribute( 'aria-expanded', entry.showReplies ? 'true' : 'false' );
			rb.appendChild( el( 'span', 'dashicons dashicons-format-chat' ) );
			rb.appendChild( document.createTextNode( count ? ' ' + count : ' ' + t.reply ) );
			rb.title = count === 1 ? t.oneReply : ( count ? fmt( t.repliesCount, count ) : t.reply );
			// This button only ever opens: the first click shows the thread
			// (or, with no replies yet, the reply box); a click while the
			// thread is open opens the reply box. Closing is done with the
			// panel's × or the box's Cancel / Esc, never by clicking again.
			rb.addEventListener( 'click', function () {
				if ( entry.replyFormOpen ) {
					focusReplyBox( entry ); // keep whatever is being typed
					return;
				}
				entry.replyFormOpen = entry.showReplies || ! count;
				entry.showReplies = true;
				render( entry );
				if ( entry.replyFormOpen ) {
					focusReplyBox( entry );
				}
			} );
			meta.appendChild( rb );
		}
		if ( note.deadline ) {
			var days = daysUntil( note.deadline );
			var label = fmt( t.due, formatDate( note.deadline ) );
			var cls = 'bsdn-chip';
			if ( ! note.done && days !== null ) {
				if ( days < 0 ) {
					label = fmt( t.overdue, formatDate( note.deadline ) );
					cls += ' is-overdue';
				} else if ( days === 0 ) {
					label = t.dueToday;
					cls += ' is-overdue';
				} else if ( days === 1 ) {
					label = t.dueTomorrow;
					cls += ' is-soon';
				}
			}
			meta.appendChild( el( 'span', cls, label ) );
		}
		if ( note.done ) {
			meta.appendChild( el( 'span', 'bsdn-chip is-done-chip', t.doneLabel ) );
		}
		if ( note.networkCopy || note.audience === 'network' ) {
			var net = el( 'span', 'bsdn-chip is-network', t.networkNote );
			net.title = t.networkHint;
			meta.appendChild( net );
		}
		if ( ! note.isAuthor ) {
			meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.from, note.author.name ) ) );
		}
		if ( note.isAuthor ) {
			var status = note.audienceStatus || [];
			var read = status.filter( function ( r ) {
				return r.read;
			} ).length;
			var doneCount = status.filter( function ( r ) {
				return r.done;
			} ).length;
			if ( note.audience === 'users' ) {
				var names = status.map( function ( r ) {
					return r.name;
				} );
				var shown = names.slice( 0, 3 ).join( ', ' ) + ( names.length > 3 ? ' +' + ( names.length - 3 ) : '' );
				meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.toUsers, shown ) ) );
				meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.readBy, read, status.length ) ) );
				if ( doneCount ) {
					meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.doneBy, doneCount, status.length ) ) );
				}
			} else if ( note.audience === 'all' || note.audience === 'roles' || note.audience === 'network' ) {
				var toLabel = note.audience === 'network' || note.networkCopy ? t.toNetwork : ( note.audience === 'all' ? t.toAll : fmt( t.toRoles, roleNames( note.roles ) ) );
				meta.appendChild( el( 'span', 'bsdn-chip', toLabel ) );
				meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.readByAll, read ) ) );
				if ( doneCount ) {
					meta.appendChild( el( 'span', 'bsdn-chip', fmt( t.doneByAll, doneCount ) ) );
				}
			}
		}
		return meta;
	}

	function closeMenus( except ) {
		board.querySelectorAll( '.bsdn-menu' ).forEach( function ( m ) {
			if ( m !== except ) {
				m.hidden = true;
			}
		} );
	}

	function buildMenu( entry ) {
		var note = entry.data;
		if ( ! note.id ) {
			return null; // Not saved yet: nothing to edit, delete or dismiss.
		}
		var menu = el( 'div', 'bsdn-menu' );
		menu.hidden = true;
		menu.setAttribute( 'role', 'menu' );

		function item( label, handler, danger ) {
			var b = el( 'button', 'bsdn-menu-item' + ( danger ? ' is-danger' : '' ), label );
			b.type = 'button';
			b.setAttribute( 'role', 'menuitem' );
			b.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				menu.hidden = true;
				handler();
			} );
			menu.appendChild( b );
			return b;
		}

		item( note.done ? t.markNotDone : t.markDone, function () {
			toggleDone( entry );
		} );
		item( note.state.locked ? t.unlock : t.lock, function () {
			toggleLock( entry );
		} );
		item( t.snoozeTomorrow, function () {
			snoozeNote( entry, 'tomorrow' );
		} );
		item( t.snoozeWeek, function () {
			snoozeNote( entry, 'week' );
		} );
		// "Snooze until...": a date field right in the menu.
		var pickRow = el( 'div', 'bsdn-menu-pick' );
		pickRow.hidden = true;
		var pickDate = el( 'input', 'bsdn-input' );
		pickDate.type = 'date';
		pickDate.setAttribute( 'aria-label', t.snoozePick );
		var pickGo = el( 'button', 'button button-small', t.snoozeGo );
		pickGo.type = 'button';
		pickGo.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( pickDate.value ) {
				menu.hidden = true;
				snoozeNote( entry, pickDate.value );
			}
		} );
		pickRow.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
		pickRow.appendChild( pickDate );
		pickRow.appendChild( pickGo );
		var pickBtn = el( 'button', 'bsdn-menu-item', t.snoozePick );
		pickBtn.type = 'button';
		pickBtn.setAttribute( 'role', 'menuitem' );
		pickBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			pickRow.hidden = false; // opens only; the menu closes on its own
			pickDate.focus();
		} );
		menu.appendChild( pickBtn );
		menu.appendChild( pickRow );
		menu.appendChild( el( 'hr', 'bsdn-menu-sep' ) );

		if ( cfg.canCreate ) {
			item( t.saveTemplate, function () {
				saveAsTemplate( entry );
			} );
		}
		if ( note.canEdit ) {
			if ( note.anchor ) {
				item( t.unpinElement, function () {
					setAnchor( entry, '' );
				} );
			} else {
				item( t.pinElement, function () {
					startPick( entry );
				} );
			}
			item( t.history, function () {
				entry.showHistory = true;
				entry.history = null;
				render( entry );
				loadHistory( entry );
			} );
			item( t.edit, function () {
				openEditor( entry );
			} );
			item( t.delete, function () {
				deleteNote( entry );
			}, true );
		}
		if ( ! note.isAuthor ) {
			item( t.dismiss, function () {
				dismissNote( entry );
			} );
		}
		return menu.childNodes.length ? menu : null;
	}

	function render( entry ) {
		var note = entry.data;
		var node = entry.el;
		node.textContent = '';
		node.className = 'bsdn-note bsdn-priority-' + note.priority + ( note.done ? ' is-done' : '' ) + ( note.state.locked ? ' is-locked' : '' );
		if ( note.id ) {
			node.id = 'bsdn-note-' + note.id;
		}

		var bar = el( 'div', 'bsdn-bar' );
		bar.tabIndex = 0;
		if ( note.priority > 0 ) {
			var flag = el( 'span', 'bsdn-flag dashicons dashicons-flag' );
			flag.title = t.priorities[ note.priority ];
			flag.setAttribute( 'aria-label', t.priorities[ note.priority ] );
			bar.appendChild( flag );
		}
		bar.appendChild( el( 'span', 'bsdn-title', note.title || t.untitled ) );
		if ( note.items && note.items.length ) {
			var ticked = note.items.filter( function ( i ) {
				return i.done;
			} ).length;
			var prog = el( 'span', 'bsdn-progress' + ( ticked === note.items.length ? ' is-complete' : '' ), ticked + '/' + note.items.length );
			prog.title = fmt( t.progress, ticked, note.items.length );
			bar.appendChild( prog );
		}
		if ( note.isNew ) {
			bar.appendChild( el( 'span', 'bsdn-new', t.new ) );
		}
		if ( note.anchor ) {
			var found = !! anchorEl( note );
			// Clicking the pin unpins (editors); for others it shows what it
			// is pinned to.
			var canUnpin = note.canEdit && note.id && ! entry.editing;
			var pin = iconButton( 'location', canUnpin ? t.unpinElement : ( found ? t.pinned : t.pinMissing ), 'bsdn-pin' + ( found ? '' : ' is-missing' ) );
			pin.addEventListener( 'click', function () {
				if ( canUnpin ) {
					setAnchor( entry, '' );
					return;
				}
				var target = anchorEl( note );
				if ( target ) {
					target.classList.add( 'bsdn-anchor-target' );
					setTimeout( function () {
						target.classList.remove( 'bsdn-anchor-target' );
					}, 1500 );
				}
			} );
			bar.appendChild( pin );
		}
		if ( note.recur && t.recurs[ note.recur ] ) {
			var rec = el( 'span', 'bsdn-recur dashicons dashicons-update' );
			rec.title = fmt( t.recurTitle, t.recurs[ note.recur ] );
			rec.setAttribute( 'aria-label', rec.title );
			bar.appendChild( rec );
		}
		if ( note.state.locked ) {
			// Clicking the lock unlocks the note.
			var lockBtn = iconButton( 'lock', t.unlockHint, 'bsdn-lock' );
			if ( note.id && ! entry.editing ) {
				lockBtn.addEventListener( 'click', function () {
					toggleLock( entry );
				} );
			} else {
				lockBtn.disabled = true;
			}
			bar.appendChild( lockBtn );
		}
		if ( note.id && ! entry.editing ) {
			var doneBtn = iconButton( note.done ? 'yes-alt' : 'yes', note.done ? t.markNotDone : t.markDone, 'bsdn-done-btn' + ( note.done ? ' is-on' : '' ) );
			doneBtn.setAttribute( 'aria-pressed', note.done ? 'true' : 'false' );
			doneBtn.addEventListener( 'click', function () {
				toggleDone( entry );
			} );
			bar.appendChild( doneBtn );
		}

		// While editing, the note must not re-render: moving the editor's
		// iframe in the DOM would wipe it. So no minimise or menu then.
		if ( ! entry.editing ) {
			var minimized = !! note.state.minimized;
			var minBtn = iconButton( minimized ? 'plus-alt2' : 'minus', minimized ? t.restore : t.minimize, 'bsdn-min' );
			minBtn.addEventListener( 'click', function () {
				note.state.minimized = ! note.state.minimized;
				queueState( note.id, { minimized: note.state.minimized } );
				render( entry );
			} );
			bar.appendChild( minBtn );
		}

		var menu = entry.editing ? null : buildMenu( entry );
		if ( menu ) {
			var menuBtn = iconButton( 'ellipsis', t.moreActions, 'bsdn-more' );
			menuBtn.setAttribute( 'aria-haspopup', 'true' );
			menuBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				closeMenus( menu );
				menu.hidden = ! menu.hidden;
				// A reopened menu starts clean (no leftover "Snooze until" date row).
				var pickRowOpen = menu.querySelector( '.bsdn-menu-pick' );
				if ( pickRowOpen && ! menu.hidden ) {
					pickRowOpen.hidden = true;
				}
			} );
			bar.appendChild( menuBtn );
		}
		// Minimised: a grab handle at the right end of the title bar
		// changes the width (the corner handle is hidden then).
		if ( ! entry.editing && note.state.minimized && ! note.state.locked ) {
			var grip = el( 'span', 'bsdn-resize-x' );
			grip.tabIndex = 0;
			grip.setAttribute( 'role', 'separator' );
			grip.setAttribute( 'aria-orientation', 'vertical' );
			grip.setAttribute( 'aria-label', t.resizeWidth );
			grip.title = t.resizeWidth;
			enableResize( entry, grip, true );
			grip.addEventListener( 'keydown', function ( e ) {
				var dir = { ArrowLeft: -1, ArrowRight: 1 }[ e.key ];
				if ( ! dir ) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				var s = entry.data.state;
				s.w = Math.max( 160, Math.min( maxWidth( entry ), entry.el.offsetWidth + dir * ( e.shiftKey ? 50 : 10 ) ) );
				entry.el.style.width = s.w + 'px';
				queueState( note.id, { w: s.w } );
			} );
			bar.appendChild( grip );
		}
		node.appendChild( bar );
		if ( menu ) {
			node.appendChild( menu );
		}

		if ( entry.editing ) {
			node.appendChild( entry.editing.form );
		} else {
			var scroll = el( 'div', 'bsdn-scroll' );
			var body = el( 'div', 'bsdn-body' );
			body.innerHTML = note.html || ''; // server-filtered, see top of file
			// No double-click-to-edit: double-click is how people select a
			// word to copy. Edit is in the note's menu.
			scroll.appendChild( body );
			if ( note.items && note.items.length ) {
				scroll.appendChild( buildChecklist( entry ) );
			}
			if ( entry.showHistory && note.id ) {
				scroll.appendChild( buildHistory( entry ) );
			}
			if ( entry.showReplies && note.id ) {
				scroll.appendChild( buildReplies( entry ) );
			}
			node.appendChild( scroll );
			node.appendChild( metaLine( note, entry ) );
			if ( ! note.state.locked ) {
				var handle = el( 'span', 'bsdn-resize' );
				handle.setAttribute( 'aria-hidden', 'true' );
				handle.title = t.resize;
				node.appendChild( handle );
				enableResize( entry, handle );
			}
		}

		enableDrag( entry, bar );
		applyBox( entry );
		if ( ! entry.editing ) {
			initPlayers( node );
		}
	}

	function addEntry( note, index ) {
		var s = note.state;
		if ( s.x === null || s.y === null ) {
			var pos = defaultPosition( index );
			s.x = pos.x;
			s.y = pos.y;
		}
		s.w = s.w || DEFAULT_W;
		topZ = Math.max( topZ, s.z || 0 );
		var entry = { data: note, el: el( 'div' ), editing: null, showReplies: false, replyFormOpen: false };
		entry.el.addEventListener( 'pointerdown', function () {
			bringToFront( entry );
		} );
		entry.el.addEventListener( 'mouseenter', function () {
			var target = anchorEl( entry.data );
			if ( target ) {
				target.classList.add( 'bsdn-anchor-target' );
			}
		} );
		entry.el.addEventListener( 'mouseleave', function () {
			document.querySelectorAll( '.bsdn-anchor-target' ).forEach( function ( n ) {
				n.classList.remove( 'bsdn-anchor-target' );
			} );
		} );
		notes[ note.id || ( 'tmp' + ( ++tempCount ) ) ] = entry;
		entry.key = note.id || ( 'tmp' + tempCount );
		board.appendChild( entry.el );
		render( entry );
		return entry;
	}

	function removeEntry( entry ) {
		if ( entry.editing && entry.editing.close ) {
			entry.editing.close();
		}
		entry.el.remove();
		delete notes[ entry.key ];
		updateToggle();
	}

	/* ------------------------------------------------------------------ */
	/* Drag, resize, keyboard move                                        */
	/* ------------------------------------------------------------------ */

	function enableDrag( entry, bar ) {
		bar.addEventListener( 'pointerdown', function ( e ) {
			if ( e.button !== 0 || e.target.closest( 'button' ) || e.target.closest( '.bsdn-resize-x' ) ) {
				return;
			}
			if ( entry.data.state.locked || entry.el.classList.contains( 'is-anchored' ) ) {
				explainStuck( entry, e );
				return;
			}
			e.preventDefault();
			var s = entry.data.state;
			var fixed = board.classList.contains( 'is-fixed' );
			var startX = e.clientX;
			var startY = e.clientY;
			var lastX = startX;
			var lastY = startY;
			var startScroll = window.scrollY;
			// Start from where the note is shown, which may be clamped.
			var origX = parseFloat( entry.el.style.left ) || 0;
			var origY = parseFloat( entry.el.style.top ) || 0;
			var moved = false;
			var raf = 0;
			try {
				bar.setPointerCapture( e.pointerId );
			} catch ( err ) {} // Some pointers (pen hover, synthetic) cannot be captured.
			entry.el.classList.add( 'is-dragging' );

			// Note position = start + pointer movement + page scroll since
			// the drag began, so the note stays under the pointer when the
			// wheel (or auto-scroll) moves the page.
			function place() {
				var scrolled = fixed ? 0 : window.scrollY - startScroll;
				s.x = Math.max( 0, Math.min( Math.max( 0, boardWidth() - entry.el.offsetWidth ), origX + lastX - startX ) );
				s.y = Math.max( 0, Math.min( maxTop( entry ), origY + lastY - startY + scrolled ) );
				entry.el.style.left = s.x + 'px';
				entry.el.style.top = s.y + 'px';
				moved = true;
			}
			function move( ev ) {
				lastX = ev.clientX;
				lastY = ev.clientY;
				place();
			}
			function onScroll() {
				place();
			}
			// Scroll the page while the pointer is held near the top or
			// bottom edge of the window.
			function edgeScroll() {
				raf = window.requestAnimationFrame( edgeScroll );
				if ( fixed ) {
					return;
				}
				var top = ( document.getElementById( 'wpadminbar' ) || { offsetHeight: 0 } ).offsetHeight;
				var zone = 40;
				if ( lastY > window.innerHeight - zone ) {
					window.scrollBy( 0, Math.ceil( ( lastY - ( window.innerHeight - zone ) ) / 3 ) );
				} else if ( lastY < top + zone && window.scrollY > 0 ) {
					window.scrollBy( 0, -Math.ceil( ( top + zone - lastY ) / 3 ) );
				}
			}
			function up() {
				bar.removeEventListener( 'pointermove', move );
				bar.removeEventListener( 'pointerup', up );
				bar.removeEventListener( 'pointercancel', up );
				window.removeEventListener( 'scroll', onScroll );
				window.cancelAnimationFrame( raf );
				entry.el.classList.remove( 'is-dragging' );
				if ( moved ) {
					queueState( entry.data.id, { x: s.x, y: s.y } );
				}
			}
			bar.addEventListener( 'pointermove', move );
			bar.addEventListener( 'pointerup', up );
			bar.addEventListener( 'pointercancel', up );
			window.addEventListener( 'scroll', onScroll, { passive: true } );
			raf = window.requestAnimationFrame( edgeScroll );
		} );

		bar.addEventListener( 'keydown', function ( e ) {
			var keys = { ArrowLeft: [ -1, 0 ], ArrowRight: [ 1, 0 ], ArrowUp: [ 0, -1 ], ArrowDown: [ 0, 1 ] };
			if ( ! keys[ e.key ] || e.target !== bar || entry.data.state.locked || entry.el.classList.contains( 'is-anchored' ) ) {
				return;
			}
			e.preventDefault();
			var step = e.shiftKey ? 50 : 10;
			var s = entry.data.state;
			s.x = Math.max( 0, Math.min( Math.max( 0, boardWidth() - entry.el.offsetWidth ), s.x + keys[ e.key ][ 0 ] * step ) );
			var shownY = parseFloat( entry.el.style.top ) || 0;
			s.y = Math.max( 0, Math.min( maxTop( entry ), shownY + keys[ e.key ][ 1 ] * step ) );
			applyBox( entry );
			queueState( entry.data.id, { x: s.x, y: s.y } );
		} );
	}

	/** Widest a note can be without running past the right edge. */
	function maxWidth( entry ) {
		var left = parseFloat( entry.el.style.left ) || 0;
		return Math.max( 160, Math.min( 900, boardWidth() - left ) );
	}

	/**
	 * Resize from a handle. widthOnly: the grab handle on a minimised
	 * note's title bar, which changes the width and leaves the height.
	 */
	function enableResize( entry, handle, widthOnly ) {
		handle.addEventListener( 'pointerdown', function ( e ) {
			if ( e.button !== 0 ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation(); // not a title-bar drag
			bringToFront( entry );
			var s = entry.data.state;
			var startX = e.clientX;
			var startY = e.clientY;
			var origW = entry.el.offsetWidth;
			var origH = entry.el.offsetHeight;
			var limitW = maxWidth( entry );
			try {
				handle.setPointerCapture( e.pointerId );
			} catch ( err ) {}
			entry.el.classList.add( 'is-resizing' );

			function move( ev ) {
				s.w = Math.max( 160, Math.min( limitW, origW + ev.clientX - startX ) );
				entry.el.style.width = s.w + 'px';
				if ( ! widthOnly ) {
					s.h = Math.max( 80, Math.min( 1200, origH + ev.clientY - startY ) );
					entry.el.style.height = s.h + 'px';
				}
			}
			function up() {
				handle.removeEventListener( 'pointermove', move );
				handle.removeEventListener( 'pointerup', up );
				handle.removeEventListener( 'pointercancel', up );
				entry.el.classList.remove( 'is-resizing' );
				queueState( entry.data.id, widthOnly ? { w: s.w } : { w: s.w, h: s.h } );
			}
			handle.addEventListener( 'pointermove', move );
			handle.addEventListener( 'pointerup', up );
			handle.addEventListener( 'pointercancel', up );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Checklist and replies                                              */
	/* ------------------------------------------------------------------ */

	function buildChecklist( entry ) {
		var note = entry.data;
		var wrap = el( 'div', 'bsdn-checklist-wrap' );
		var ticked = note.items.filter( function ( i ) {
			return i.done;
		} ).length;
		var total = note.items.length;
		var complete = ticked === total;
		// Slim progress bar with a count; full and green when everything is ticked.
		var head = el( 'div', 'bsdn-check-head' + ( complete ? ' is-complete' : '' ) );
		var bar = el( 'div', 'bsdn-check-bar' );
		bar.setAttribute( 'role', 'progressbar' );
		bar.setAttribute( 'aria-valuemin', '0' );
		bar.setAttribute( 'aria-valuemax', String( total ) );
		bar.setAttribute( 'aria-valuenow', String( ticked ) );
		bar.setAttribute( 'aria-label', fmt( t.progress, ticked, total ) );
		var fill = el( 'span', 'bsdn-check-fill' );
		fill.style.width = ( total ? Math.round( ticked / total * 100 ) : 0 ) + '%';
		bar.appendChild( fill );
		head.appendChild( bar );
		head.appendChild( el( 'span', 'bsdn-check-count', complete ? t.allDone : fmt( t.progressShort, ticked, total ) ) );
		wrap.appendChild( head );
		var list = el( 'ul', 'bsdn-checklist' );
		wrap.appendChild( list );
		note.items.forEach( function ( item ) {
			var li = el( 'li', item.done ? 'is-checked' : '' );
			var lab = el( 'label' );
			var box = el( 'input' );
			box.type = 'checkbox';
			box.checked = !! item.done;
			box.disabled = ! note.id;
			if ( item.done && item.doneBy ) {
				lab.title = fmt( t.tickedBy, item.doneBy );
			}
			box.addEventListener( 'change', function () {
				tickItem( entry, item, box.checked );
			} );
			lab.appendChild( box );
			lab.appendChild( el( 'span', 'bsdn-item-text', item.text ) );
			li.appendChild( lab );
			list.appendChild( li );
		} );
		return wrap;
	}

	function tickItem( entry, item, done ) {
		item.done = done;
		render( entry );
		api( 'POST', '/notes/' + entry.data.id + '/items/' + item.id, { done: done } ).then( function ( items ) {
			entry.data.items = items;
			render( entry );
		} ).catch( function ( err ) {
			item.done = ! done;
			render( entry );
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	function formatWhen( iso ) {
		var d = new Date( iso );
		return isNaN( d.getTime() ) ? '' : d.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
	}

	function focusReplyBox( entry ) {
		var box = entry.el.querySelector( '.bsdn-reply-input' );
		if ( box ) {
			box.focus();
		}
	}

	function buildReplies( entry ) {
		var note = entry.data;
		var wrap = el( 'div', 'bsdn-replies' );
		// Header with its own close button: the only way to fold the thread away.
		var head = el( 'div', 'bsdn-replies-head' );
		head.appendChild( el( 'strong', '', t.repliesTitle ) );
		var shut = iconButton( 'no-alt', t.closeReplies, 'bsdn-replies-close' );
		shut.addEventListener( 'click', function () {
			entry.showReplies = false;
			entry.replyFormOpen = false;
			render( entry );
		} );
		head.appendChild( shut );
		wrap.appendChild( head );
		var list = el( 'ul', 'bsdn-reply-list' );
		( note.replies || [] ).forEach( function ( r ) {
			var li = el( 'li', 'bsdn-reply' );
			var head = el( 'div', 'bsdn-reply-head' );
			head.appendChild( el( 'strong', '', r.name ) );
			head.appendChild( el( 'span', 'bsdn-reply-when', formatWhen( r.at ) ) );
			if ( r.canDelete ) {
				var del = iconButton( 'no-alt', t.deleteReply, 'bsdn-reply-del' );
				del.addEventListener( 'click', function () {
					if ( ! window.confirm( t.confirmDeleteReply ) ) {
						return;
					}
					api( 'DELETE', '/notes/' + note.id + '/replies/' + r.id ).then( function ( replies ) {
						note.replies = replies;
						render( entry );
					} ).catch( function ( err ) {
						window.alert( fmt( t.saveFailed, err.message ) );
					} );
				} );
				head.appendChild( del );
			}
			li.appendChild( head );
			li.appendChild( el( 'p', 'bsdn-reply-text', r.text ) );
			list.appendChild( li );
		} );
		wrap.appendChild( list );

		// The reply box is open only while writing. After sending (or
		// Cancel / Esc) the thread stays, with a small "Reply" button.
		if ( ! entry.replyFormOpen ) {
			var again = el( 'button', 'button button-small bsdn-reply-new', t.reply );
			again.type = 'button';
			again.addEventListener( 'click', function () {
				entry.replyFormOpen = true;
				render( entry );
				focusReplyBox( entry );
			} );
			wrap.appendChild( again );
			return wrap;
		}

		var form = el( 'form', 'bsdn-reply-form' );
		form.noValidate = true;
		var box = el( 'textarea', 'bsdn-input bsdn-reply-input' );
		box.rows = 2;
		box.maxLength = 2000;
		box.placeholder = t.writeReply;
		box.setAttribute( 'aria-label', t.writeReply );
		var buttons = el( 'div', 'bsdn-reply-buttons' );
		var send = el( 'button', 'button button-small button-primary', t.send );
		send.type = 'submit';
		var cancel = el( 'button', 'button button-small', t.cancel );
		cancel.type = 'button';
		buttons.appendChild( send );
		buttons.appendChild( cancel );
		form.appendChild( box );
		form.appendChild( buttons );

		function closeForm() {
			entry.replyFormOpen = false;
			// With nothing in the thread, close the whole reply panel.
			if ( ! ( note.replies || [] ).length ) {
				entry.showReplies = false;
			}
			render( entry );
		}
		cancel.addEventListener( 'click', closeForm );
		box.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				e.stopPropagation();
				closeForm();
				return;
			}
			if ( e.key === 'Enter' && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : send.click();
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var text = box.value.trim();
			if ( ! text ) {
				return;
			}
			send.disabled = true;
			entry.replyToken = entry.replyToken || oneTimeToken();
			api( 'POST', '/notes/' + note.id + '/replies', { text: text, token: entry.replyToken } ).then( function ( replies ) {
				entry.replyToken = null; // the next reply is a new one
				note.replies = replies;
				entry.replyFormOpen = false; // sent: back to the thread
				render( entry );
			} ).catch( function ( err ) {
				send.disabled = false;
				window.alert( fmt( t.saveFailed, err.message ) );
			} );
		} );
		wrap.appendChild( form );
		return wrap;
	}

	/* Pin to a page element */

	/** The element a note is pinned to on this page, or null. */
	function anchorEl( note ) {
		if ( ! note || ! note.anchor ) {
			return null;
		}
		var found = null;
		try {
			found = document.querySelector( note.anchor );
		} catch ( err ) {
			return null; // not a valid selector here
		}
		if ( ! found || ( board && board.contains( found ) ) ) {
			return null;
		}
		var r = found.getBoundingClientRect();
		return r.width || r.height ? found : null; // hidden elements don't count
	}

	/**
	 * A selector that finds this element again: its id, or a name for form
	 * fields, else a short path of nth-of-type steps from the nearest
	 * ancestor with an id.
	 */
	function selectorFor( target ) {
		var esc = window.CSS && window.CSS.escape ? window.CSS.escape : function ( v ) {
			return String( v ).replace( /[^a-zA-Z0-9_-]/g, '\\$&' );
		};
		function unique( sel ) {
			try {
				return document.querySelectorAll( sel ).length === 1 && document.querySelector( sel ) === target;
			} catch ( err ) {
				return false;
			}
		}
		if ( target.id && ! /^\d|\d{4,}/.test( target.id ) && unique( '#' + esc( target.id ) ) ) {
			return '#' + esc( target.id );
		}
		var name = target.getAttribute && target.getAttribute( 'name' );
		if ( name && unique( target.tagName.toLowerCase() + '[name="' + name.replace( /"/g, '\\"' ) + '"]' ) ) {
			return target.tagName.toLowerCase() + '[name="' + name.replace( /"/g, '\\"' ) + '"]';
		}
		var parts = [];
		var node = target;
		while ( node && node.nodeType === 1 && node !== document.body && parts.length < 10 ) {
			if ( node !== target && node.id && ! /^\d|\d{4,}/.test( node.id ) ) {
				parts.unshift( '#' + esc( node.id ) );
				break;
			}
			var tag = node.tagName.toLowerCase();
			var nth = 1;
			for ( var sib = node.previousElementSibling; sib; sib = sib.previousElementSibling ) {
				if ( sib.tagName === node.tagName ) {
					nth++;
				}
			}
			parts.unshift( tag + ':nth-of-type(' + nth + ')' );
			node = node.parentElement;
		}
		var sel = parts.join( ' > ' );
		return unique( sel ) ? sel : '';
	}

	function setAnchor( entry, selector ) {
		api( 'POST', '/notes/' + entry.data.id, { anchor: selector } ).then( function ( saved ) {
			saved.state = entry.data.state;
			entry.data = saved;
			render( entry );
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	/** Pick mode: outline what is under the pointer; a click pins the note to it. */
	function startPick( entry ) {
		var glow = el( 'div', 'bsdn-pick-glow' );
		document.body.appendChild( glow );
		document.body.classList.add( 'bsdn-picking' );
		toast( t.pickHint );
		var current = null;

		function skip( node ) {
			return ! node || node === document.body || node === document.documentElement ||
				( board && board.contains( node ) ) || node.closest( '#wpadminbar, #adminmenumain, .bsdn-toast, .bsdn-pick-glow' );
		}
		function move( e ) {
			var node = e.target;
			if ( skip( node ) ) {
				glow.style.display = 'none';
				current = null;
				return;
			}
			current = node;
			var r = node.getBoundingClientRect();
			glow.style.display = 'block';
			glow.style.left = r.left + 'px';
			glow.style.top = r.top + 'px';
			glow.style.width = r.width + 'px';
			glow.style.height = r.height + 'px';
		}
		// Picking must not focus a field or start a text selection.
		function down( e ) {
			if ( ! skip( e.target ) ) {
				e.preventDefault();
			}
		}
		function stop() {
			document.removeEventListener( 'mousemove', move, true );
			document.removeEventListener( 'mousedown', down, true );
			document.removeEventListener( 'click', click, true );
			document.removeEventListener( 'keydown', key, true );
			document.body.classList.remove( 'bsdn-picking' );
			glow.remove();
			var tst = document.getElementById( 'bsdn-toast' );
			if ( tst ) {
				tst.hidden = true;
			}
		}
		function click( e ) {
			if ( skip( e.target ) ) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			var sel = selectorFor( current || e.target );
			stop();
			if ( sel ) {
				setAnchor( entry, sel );
			}
		}
		function key( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				stop();
			}
		}
		document.addEventListener( 'mousemove', move, true );
		document.addEventListener( 'mousedown', down, true );
		document.addEventListener( 'click', click, true );
		document.addEventListener( 'keydown', key, true );
	}

	/* History and templates */

	function loadHistory( entry ) {
		api( 'GET', '/notes/' + entry.data.id + '/revisions' ).then( function ( list ) {
			entry.history = list;
			render( entry );
		} ).catch( function ( err ) {
			entry.showHistory = false;
			render( entry );
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	function buildHistory( entry ) {
		var wrap = el( 'div', 'bsdn-history' );
		var head = el( 'div', 'bsdn-history-head' );
		head.appendChild( el( 'strong', '', t.historyTitle ) );
		var close = iconButton( 'no-alt', t.close, 'bsdn-history-close' );
		close.addEventListener( 'click', function () {
			entry.showHistory = false;
			render( entry );
		} );
		head.appendChild( close );
		wrap.appendChild( head );
		if ( ! entry.history ) {
			wrap.appendChild( el( 'span', 'spinner is-active bsdn-spinner' ) );
			return wrap;
		}
		if ( ! entry.history.length ) {
			wrap.appendChild( el( 'p', 'bsdn-history-empty', t.noHistory ) );
			return wrap;
		}
		var list = el( 'ul', 'bsdn-history-list' );
		entry.history.forEach( function ( v ) {
			var li = el( 'li' );
			li.appendChild( el( 'div', 'bsdn-history-when', fmt( t.versionBy, formatWhen( v.at ), v.by ) ) );
			li.appendChild( el( 'div', 'bsdn-history-title', v.title || t.untitled ) );
			if ( v.excerpt ) {
				li.appendChild( el( 'div', 'bsdn-history-excerpt', v.excerpt ) );
			}
			if ( v.items ) {
				li.appendChild( el( 'div', 'bsdn-history-excerpt', fmt( t.versionItems, v.items ) ) );
			}
			var btn = el( 'button', 'button button-small', t.restoreVersion );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmRestore ) ) {
					return;
				}
				btn.disabled = true;
				api( 'POST', '/notes/' + entry.data.id + '/revisions/' + v.id + '/restore' ).then( function ( saved ) {
					saved.state = entry.data.state;
					entry.data = saved;
					entry.showHistory = false;
					render( entry );
				} ).catch( function ( err ) {
					btn.disabled = false;
					window.alert( fmt( t.saveFailed, err.message ) );
				} );
			} );
			li.appendChild( btn );
			list.appendChild( li );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	function saveAsTemplate( entry ) {
		api( 'POST', '/templates', { note: entry.data.id } ).then( function ( tpl ) {
			cfg.templates = ( cfg.templates || [] ).concat( [ tpl ] );
			toast( t.templateSaved );
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	/** Checklist editor inside the note editor. */
	function buildItemsEditor( note ) {
		var node = el( 'fieldset', 'bsdn-where bsdn-items-editor' );
		node.appendChild( el( 'legend', 'bsdn-label', t.checklist ) );
		var list = el( 'ul', 'bsdn-items-list' );
		node.appendChild( list );

		function addRow( item, after ) {
			var li = el( 'li', 'bsdn-item-row' );
			li.dataset.id = item.id ? String( item.id ) : '';
			var input = el( 'input', 'bsdn-input' );
			input.type = 'text';
			input.maxLength = 500;
			input.placeholder = t.itemPlaceholder;
			input.setAttribute( 'aria-label', t.itemPlaceholder );
			input.value = item.text || '';
			var x = iconButton( 'no-alt', t.removeItem, 'bsdn-item-del' );
			x.addEventListener( 'click', function () {
				li.remove();
			} );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					e.stopPropagation();
					addRow( { id: 0, text: '' }, li ).querySelector( 'input' ).focus();
				}
			} );
			li.appendChild( input );
			li.appendChild( x );
			if ( after && after.nextSibling ) {
				list.insertBefore( li, after.nextSibling );
			} else {
				list.appendChild( li );
			}
			return li;
		}

		( note.items || [] ).forEach( function ( i ) {
			addRow( i );
		} );
		var add = el( 'button', 'button button-small bsdn-item-add', t.addItem );
		add.type = 'button';
		add.addEventListener( 'click', function () {
			addRow( { id: 0, text: '' } ).querySelector( 'input' ).focus();
		} );
		node.appendChild( add );

		return {
			node: node,
			set: function ( items ) {
				list.textContent = '';
				( items || [] ).forEach( function ( i ) {
					addRow( { id: 0, text: i.text } );
				} );
			},
			value: function () {
				return [].slice.call( list.querySelectorAll( '.bsdn-item-row' ) ).map( function ( li ) {
					return { id: parseInt( li.dataset.id || '0', 10 ) || 0, text: li.querySelector( 'input' ).value.trim() };
				} ).filter( function ( i ) {
					return i.text !== '';
				} );
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* Editor                                                             */
	/* ------------------------------------------------------------------ */

	function field( labelText, input ) {
		var wrap = el( 'label', 'bsdn-field' );
		wrap.appendChild( el( 'span', 'bsdn-label', labelText ) );
		wrap.appendChild( input );
		return wrap;
	}

	function openEditor( entry ) {
		if ( entry.editing ) {
			return;
		}
		closeMenus();
		var note = entry.data;
		var draft = {
			color: note.color,
			recipients: note.audience === 'users' ? ( note.audienceStatus || [] ).map( function ( r ) {
				return { id: r.id, name: r.name };
			} ) : []
		};

		var form = el( 'form', 'bsdn-editor' );
		form.noValidate = true;

		var title = el( 'input', 'bsdn-input' );
		title.type = 'text';
		title.maxLength = 200;
		title.placeholder = t.title;
		title.setAttribute( 'aria-label', t.title );
		title.value = note.title;
		form.appendChild( title );

		var editorId = 'bsdn-editor-' + ( ++editorCount );
		var editorOn = false;
		var content = el( 'textarea', 'bsdn-input bsdn-textarea' );
		content.id = editorId;
		content.rows = 8;
		content.placeholder = t.text;
		content.setAttribute( 'aria-label', t.text );
		content.value = note.content;
		var editorWrap = el( 'div', 'bsdn-editor-wrap' );
		editorWrap.appendChild( content );
		form.appendChild( editorWrap );
		form.appendChild( el( 'p', 'bsdn-hint', t.embedHint ) );
		var itemsEditor = buildItemsEditor( note );
		form.appendChild( itemsEditor.node );

		function getText() {
			return editorOn ? window.wp.editor.getContent( editorId ) : content.value;
		}
		function closeEditor() {
			if ( editorOn ) {
				window.wp.editor.remove( editorId );
				editorOn = false;
			}
		}

		// Colours.
		var colors = el( 'div', 'bsdn-colors' );
		colors.setAttribute( 'role', 'group' );
		colors.setAttribute( 'aria-label', t.color );
		var custom = el( 'input', 'bsdn-custom-color' );
		custom.type = 'color';
		custom.title = t.customColor;
		custom.setAttribute( 'aria-label', t.customColor );
		custom.value = draft.color;
		function pick( c ) {
			draft.color = c;
			custom.value = c;
			entry.el.style.background = c;
			entry.el.style.color = textColor( c );
			colors.querySelectorAll( '.bsdn-swatch-btn' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b.dataset.color === c ? 'true' : 'false' );
			} );
		}
		cfg.colors.forEach( function ( c ) {
			var b = el( 'button', 'bsdn-swatch-btn' );
			b.type = 'button';
			b.dataset.color = c;
			b.style.background = c;
			b.setAttribute( 'aria-label', c );
			b.addEventListener( 'click', function () {
				pick( c );
			} );
			colors.appendChild( b );
		} );
		custom.addEventListener( 'input', function () {
			pick( custom.value );
		} );
		colors.appendChild( custom );
		form.appendChild( colors );

		// Priority + deadline.
		var row = el( 'div', 'bsdn-row' );
		var priority = el( 'select', 'bsdn-input' );
		t.priorities.forEach( function ( label, i ) {
			var o = el( 'option', '', label );
			o.value = String( i );
			priority.appendChild( o );
		} );
		priority.value = String( note.priority );
		row.appendChild( field( t.priority, priority ) );
		var deadline = el( 'input', 'bsdn-input' );
		deadline.type = 'date';
		deadline.value = note.deadline || '';
		row.appendChild( field( t.deadline, deadline ) );
		var recur = el( 'select', 'bsdn-input' );
		Object.keys( t.recurs ).forEach( function ( key ) {
			var o = el( 'option', '', t.recurs[ key ] );
			o.value = key;
			recur.appendChild( o );
		} );
		recur.value = note.recur || '';
		row.appendChild( field( t.repeats, recur ) );
		form.appendChild( row );

		// Audience.
		var audience = el( 'select', 'bsdn-input' );
		[ 'private', 'users', 'roles', 'all', 'network' ].filter( function ( key ) {
			return key !== 'network' || cfg.canNetwork || note.audience === 'network';
		} ).forEach( function ( key ) {
			var o = el( 'option', '', t.audiences[ key ] );
			o.value = key;
			audience.appendChild( o );
		} );
		audience.value = note.audience;
		form.appendChild( field( t.audience, audience ) );

		var picker = buildPicker( draft );
		form.appendChild( picker.node );

		// Roles to send to (shown for "Users with a role").
		var rolesBox = el( 'fieldset', 'bsdn-where bsdn-roles' );
		rolesBox.appendChild( el( 'legend', 'bsdn-label', t.pickRoles ) );
		var roleBoxes = [];
		( cfg.roles || [] ).forEach( function ( r ) {
			var lab = el( 'label', 'bsdn-check' );
			var box = el( 'input' );
			box.type = 'checkbox';
			box.value = r.key;
			box.checked = ( note.roles || [] ).indexOf( r.key ) !== -1;
			lab.appendChild( box );
			lab.appendChild( document.createTextNode( ' ' + r.name ) );
			rolesBox.appendChild( lab );
			roleBoxes.push( box );
		} );
		form.appendChild( rolesBox );
		function pickedRoles() {
			return roleBoxes.filter( function ( b ) {
				return b.checked;
			} ).map( function ( b ) {
				return b.value;
			} );
		}

		function syncPicker() {
			picker.node.hidden = audience.value !== 'users';
			rolesBox.hidden = audience.value !== 'roles';
		}
		audience.addEventListener( 'change', syncPicker );
		syncPicker();

		// Where it shows.
		var where = buildWhere( note );
		form.appendChild( where.node );

		var error = el( 'p', 'bsdn-error' );
		error.setAttribute( 'role', 'alert' );
		error.hidden = true;
		form.appendChild( error );

		// Buttons.
		var actions = el( 'div', 'bsdn-actions' );
		var save = el( 'button', 'button button-primary button-small', t.save );
		save.type = 'submit';
		var cancel = el( 'button', 'button button-small', t.cancel );
		cancel.type = 'button';
		actions.appendChild( save );
		actions.appendChild( cancel );
		if ( note.id ) {
			var del = el( 'button', 'button-link bsdn-delete', t.delete );
			del.type = 'button';
			del.addEventListener( 'click', function () {
				deleteNote( entry );
			} );
			actions.appendChild( del );
		}
		form.appendChild( actions );

		cancel.addEventListener( 'click', function () {
			// Never throw away typing without asking.
			if ( dirty && ! window.confirm( t.discardChanges ) ) {
				return;
			}
			if ( ! note.id ) {
				removeEntry( entry );
				return;
			}
			closeEditor();
			entry.editing = null;
			render( entry );
		} );

		form.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				cancel.click();
			}
			if ( e.key === 'Enter' && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : save.click();
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			error.hidden = true;
			var text = getText();
			var hasMedia = /<(img|video|audio|iframe)\b|\[(audio|video|playlist|gallery|embed)\b/i.test( text );
			var hasWords = text.replace( /<[^>]*>|&nbsp;|\s/g, '' ) !== '';
			if ( ! title.value.trim() && ! hasWords && ! hasMedia && ! itemsEditor.value().length ) {
				error.textContent = t.needsText;
				error.hidden = false;
				return;
			}
			var screens = where.value();
			if ( ! screens.length ) {
				error.textContent = t.needsWhere;
				error.hidden = false;
				return;
			}
			if ( audience.value === 'users' && ! draft.recipients.length ) {
				error.textContent = t.needsRecipient;
				error.hidden = false;
				return;
			}
			if ( audience.value === 'roles' && ! pickedRoles().length ) {
				error.textContent = t.needsRoles;
				error.hidden = false;
				return;
			}
			var payload = {
				title: title.value,
				content: text,
				color: draft.color,
				priority: parseInt( priority.value, 10 ),
				deadline: deadline.value,
				audience: audience.value,
				roles: pickedRoles(),
				screens: screens,
				items: itemsEditor.value(),
				recur: recur.value,
				recipients: draft.recipients.map( function ( r ) {
					return r.id;
				} )
			};
			save.disabled = true;
			var request;
			if ( note.id ) {
				request = api( 'POST', '/notes/' + note.id, payload );
			} else {
				payload.state = { x: note.state.x, y: note.state.y, w: note.state.w, z: note.state.z };
				// Same token for every Save click of this new note: a repeat
				// (automatic or by hand) can never create it twice.
				entry.createToken = entry.createToken || oneTimeToken();
				payload.token = entry.createToken;
				request = api( 'POST', '/notes', payload );
			}
			request.then( function ( saved ) {
				var keepState = note.state;
				var wasNew = ! note.id;
				entry.data = saved;
				entry.data.state = keepState;
				closeEditor();
				entry.editing = null;
				if ( wasNew ) {
					delete notes[ entry.key ];
					entry.key = saved.id;
					notes[ saved.id ] = entry;
				}
				// Moved off this page? Take it off the board and say where it went.
				var keys = hereKeys();
				var stays = ( saved.screens || [] ).some( function ( s ) {
					return keys.indexOf( s.key ) !== -1;
				} );
				if ( ! stays ) {
					removeEntry( entry );
					toast( fmt( t.movedAway, ( saved.screens || [] ).map( screenLabel ).join( ', ' ) ) );
					return;
				}
				render( entry );
				updateToggle();
			} ).catch( function ( err ) {
				save.disabled = false;
				error.textContent = fmt( t.saveFailed, err.message );
				error.hidden = false;
			} );
		} );

		if ( ! note.id && cfg.templates && cfg.templates.length ) {
			var tplSelect = el( 'select', 'bsdn-input bsdn-template-pick' );
			tplSelect.setAttribute( 'aria-label', t.fromTemplate );
			var first = el( 'option', '', t.fromTemplate );
			first.value = '';
			tplSelect.appendChild( first );
			cfg.templates.forEach( function ( tpl ) {
				var o = el( 'option', '', tpl.title || t.untitled );
				o.value = String( tpl.id );
				tplSelect.appendChild( o );
			} );
			tplSelect.addEventListener( 'change', function () {
				var tpl = cfg.templates.filter( function ( x ) {
					return String( x.id ) === tplSelect.value;
				} )[ 0 ];
				if ( ! tpl ) {
					return;
				}
				title.value = tpl.title;
				if ( editorOn && window.tinymce && window.tinymce.get( editorId ) ) {
					window.tinymce.get( editorId ).setContent( tpl.content );
				}
				content.value = tpl.content;
				pick( tpl.color );
				priority.value = String( tpl.priority );
				recur.value = tpl.recur || '';
				itemsEditor.set( tpl.items );
			} );
			form.insertBefore( tplSelect, form.firstChild );
		}

		// Track unsaved changes so Cancel / Esc can ask before discarding.
		var dirty = false;
		function markDirty() {
			dirty = true;
		}
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.bsdn-swatch-btn, .bsdn-item-del, .bsdn-item-add, .bsdn-chip-x, .bsdn-suggest-item' ) ) {
				dirty = true;
			}
		} );

		entry.editing = { form: form, close: closeEditor };
		render( entry );
		pick( draft.color );

		// The WordPress editor (Visual/Text, Add Media) once the textarea is
		// in the page. Without it the plain textarea still works.
		if ( hasEditor() ) {
			window.wp.editor.initialize( editorId, {
				tinymce: {
					wpautop: true,
					height: 180,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,link,unlink',
					toolbar2: 'strikethrough,forecolor,hr,removeformat,charmap,undo,redo',
					// Wrap long pasted lines (logs, paths) inside the editor too.
					content_style: 'body{overflow-wrap:anywhere;word-break:break-word}' +
						'pre,code{white-space:pre-wrap !important;overflow-wrap:anywhere}' +
						'table{width:100% !important;table-layout:fixed}td,th{white-space:normal !important}' +
						'*{max-width:100%}'
				},
				quicktags: true,
				mediaButtons: !! cfg.canUpload
			} );
			editorOn = true;
			// Typing in the Visual tab happens inside TinyMCE's iframe.
			setTimeout( function () {
				var ed = window.tinymce && window.tinymce.get( editorId );
				if ( ed ) {
					ed.on( 'input keyup change undo redo ExecCommand', markDirty );
				}
			}, 0 );
		}
		title.focus();
	}

	function screenLabel( s ) {
		if ( s.key === '*' ) {
			return t.whereAll;
		}
		if ( s.key === 'dashboard' ) {
			return t.whereDash;
		}
		return s.label || s.key;
	}

	/** "Where it shows" checkboxes: Dashboard, this page, this item, everywhere, and any page the note already uses. */
	function buildWhere( note ) {
		var node = el( 'fieldset', 'bsdn-where' );
		node.appendChild( el( 'legend', 'bsdn-label', t.where ) );
		var current = {};
		( note.screens || [] ).forEach( function ( s ) {
			current[ s.key ] = s;
		} );

		var options = [];
		var seen = {};
		function add( s, label ) {
			if ( ! s || seen[ s.key ] ) {
				return;
			}
			seen[ s.key ] = true;
			options.push( { screen: s, label: label } );
		}
		add( { key: 'dashboard', label: t.whereDash, url: 'index.php' }, t.whereDash );
		if ( cfg.here.key !== 'dashboard' ) {
			add( cfg.here, fmt( t.whereHere, cfg.here.label ) );
		}
		if ( cfg.herePost ) {
			add( cfg.herePost, fmt( t.wherePost, cfg.herePost.label ) );
		}
		( note.screens || [] ).forEach( function ( s ) {
			if ( s.key !== '*' ) {
				add( s, screenLabel( s ) );
			}
		} );
		add( { key: '*', label: '', url: '' }, t.whereAll );

		var boxes = [];
		options.forEach( function ( o ) {
			var label = el( 'label', 'bsdn-check' );
			var box = el( 'input' );
			box.type = 'checkbox';
			box.checked = !! current[ o.screen.key ];
			label.appendChild( box );
			label.appendChild( document.createTextNode( ' ' + o.label ) );
			node.appendChild( label );
			boxes.push( { box: box, screen: o.screen } );
		} );

		return {
			node: node,
			value: function () {
				return boxes.filter( function ( b ) {
					return b.box.checked;
				} ).map( function ( b ) {
					return { key: b.screen.key, label: b.screen.label, url: b.screen.url };
				} );
			}
		};
	}

	function buildPicker( draft ) {
		var node = el( 'div', 'bsdn-picker' );
		var chips = el( 'div', 'bsdn-chips' );
		var input = el( 'input', 'bsdn-input' );
		input.type = 'search';
		input.placeholder = t.findUsers;
		input.setAttribute( 'aria-label', t.findUsers );
		input.autocomplete = 'off';
		var list = el( 'ul', 'bsdn-suggest' );
		list.hidden = true;
		var timer;
		var quietFocus = false;

		function drawChips() {
			chips.textContent = '';
			draft.recipients.forEach( function ( r ) {
				var chip = el( 'span', 'bsdn-user-chip', r.name );
				var x = iconButton( 'no-alt', fmt( t.removeUser, r.name ), 'bsdn-chip-x' );
				x.addEventListener( 'click', function () {
					draft.recipients = draft.recipients.filter( function ( o ) {
						return o.id !== r.id;
					} );
					drawChips();
				} );
				chip.appendChild( x );
				chips.appendChild( chip );
			} );
		}

		function search() {
			var q = input.value.trim();
			api( 'GET', '/users', null, 'search=' + encodeURIComponent( q ) ).then( function ( users ) {
				list.textContent = '';
				var chosen = draft.recipients.map( function ( r ) {
					return r.id;
				} );
				users = users.filter( function ( u ) {
					return chosen.indexOf( u.id ) === -1;
				} );
				if ( ! users.length ) {
					list.appendChild( el( 'li', 'bsdn-suggest-empty', t.noUsers ) );
				}
				users.forEach( function ( u ) {
					var li = el( 'li' );
					var b = el( 'button', 'bsdn-suggest-item', u.name );
					b.type = 'button';
					b.addEventListener( 'click', function () {
						draft.recipients.push( { id: u.id, name: u.name } );
						drawChips();
						input.value = '';
						list.hidden = true;
						// Refocus without reopening the list over the Save button.
						quietFocus = true;
						input.focus();
						quietFocus = false;
					} );
					li.appendChild( b );
					list.appendChild( li );
				} );
				list.hidden = false;
			} ).catch( function () {
				list.hidden = true;
			} );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( search, 250 );
		} );
		input.addEventListener( 'focus', function () {
			if ( ! input.value && ! quietFocus ) {
				search();
			}
		} );
		node.addEventListener( 'focusout', function ( e ) {
			if ( ! e.relatedTarget || ! node.contains( e.relatedTarget ) ) {
				list.hidden = true;
			}
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! list.hidden ) {
				e.preventDefault();
				e.stopPropagation();
				list.hidden = true;
				return;
			}
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				var first = list.querySelector( '.bsdn-suggest-item' );
				if ( first ) {
					first.click();
				}
			}
		} );

		node.appendChild( chips );
		node.appendChild( input );
		node.appendChild( list );
		drawChips();
		return { node: node };
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	function newNote() {
		if ( ! cfg.canCreate ) {
			return;
		}
		showBoard( true );
		var count = Object.keys( notes ).length;
		var pos = defaultPosition( count );
		pos.y += window.scrollY > 100 ? window.scrollY : 0;
		topZ += 1;
		var entry = addEntry( {
			id: 0,
			title: '',
			content: '',
			color: cfg.colors[ 0 ],
			priority: 0,
			deadline: '',
			audience: 'private',
			author: { id: cfg.userId, name: '' },
			isAuthor: true,
			canEdit: true,
			isNew: false,
			audienceStatus: [],
			items: [],
			replies: [],
			recur: '',
			screens: [ cfg.here ],
			state: { x: pos.x, y: pos.y, w: DEFAULT_W, h: null, z: topZ, minimized: false }
		}, count );
		openEditor( entry );
	}

	function deleteNote( entry ) {
		if ( ! window.confirm( t.confirmDelete ) ) {
			return;
		}
		api( 'DELETE', '/notes/' + entry.data.id ).then( function () {
			removeEntry( entry );
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	/** Undo a snooze or "remove from my dashboard": put the note back. */
	function bringBack( entry, state ) {
		api( 'POST', '/notes/' + entry.data.id + '/state', state ).then( function () {
			addEntry( entry.data, Object.keys( notes ).length );
			updateToggle();
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	/** Someone tries to drag a locked or pinned note: say why it won't move, once. */
	function explainStuck( entry, down ) {
		var sx = down.clientX;
		var sy = down.clientY;
		function move( ev ) {
			if ( Math.abs( ev.clientX - sx ) + Math.abs( ev.clientY - sy ) > 6 ) {
				done();
				if ( ! entry.hinted ) {
					entry.hinted = true;
					toast( entry.data.state.locked ? t.lockedHint : t.pinnedHint );
				}
			}
		}
		function done() {
			document.removeEventListener( 'pointermove', move );
			document.removeEventListener( 'pointerup', done );
		}
		document.addEventListener( 'pointermove', move );
		document.addEventListener( 'pointerup', done );
	}

	function toggleDone( entry ) {
		var note = entry.data;
		note.done = ! note.done;
		render( entry );
		api( 'POST', '/notes/' + note.id + '/state', { done: note.done } ).catch( function ( err ) {
			note.done = ! note.done;
			render( entry );
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	function toggleLock( entry ) {
		var s = entry.data.state;
		s.locked = ! s.locked;
		queueState( entry.data.id, { locked: s.locked } );
		render( entry );
	}

	/** Snooze: "tomorrow", "week" or a Y-m-d date. The note leaves the board until then. */
	function snoozeNote( entry, value ) {
		var base = ( cfg.today || '' ).split( '-' ).map( Number );
		var until = value;
		if ( value === 'tomorrow' || value === 'week' ) {
			var d = new Date( Date.UTC( base[ 0 ], base[ 1 ] - 1, base[ 2 ] + ( value === 'week' ? 7 : 1 ) ) );
			until = d.toISOString().slice( 0, 10 );
		}
		api( 'POST', '/notes/' + entry.data.id + '/state', { snooze: value } ).then( function () {
			removeEntry( entry );
			toast( fmt( t.snoozedUntil, formatDate( until ) ), t.undo, function () {
				bringBack( entry, { snooze: '' } );
			} );
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	function dismissNote( entry ) {
		api( 'POST', '/notes/' + entry.data.id + '/state', { dismissed: true } ).then( function () {
			removeEntry( entry );
			toast( t.dismissedToast, t.undo, function () {
				bringBack( entry, { dismissed: false } );
			} );
		} ).catch( function ( err ) {
			window.alert( fmt( t.saveFailed, err.message ) );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Toolbar and start-up                                               */
	/* ------------------------------------------------------------------ */

	function updateToggle() {
		var hidden = board.hidden;
		var label = hidden ? fmt( t.showNotes, Object.keys( notes ).length ) : t.hideNotes;
		if ( toggleBtn ) {
			toggleBtn.textContent = label;
			toggleBtn.setAttribute( 'aria-pressed', hidden ? 'true' : 'false' );
		}
		var abToggle = document.querySelector( '#wp-admin-bar-bsdn-toggle > .ab-item' );
		if ( abToggle ) {
			abToggle.textContent = label;
		}
		// Live count of saved notes on this page in the admin bar.
		var abLabel = document.querySelector( '#wp-admin-bar-bsdn > .ab-item .ab-label' );
		if ( abLabel ) {
			var count = Object.keys( notes ).filter( function ( k ) {
				return !! notes[ k ].data.id;
			} ).length;
			var badge = abLabel.querySelector( '.bsdn-ab-count' );
			if ( count && ! badge ) {
				badge = el( 'span', 'bsdn-ab-count' );
				abLabel.appendChild( document.createTextNode( ' ' ) );
				abLabel.appendChild( badge );
			}
			if ( badge ) {
				badge.textContent = String( count );
				badge.hidden = ! count;
			}
		}
	}

	/* Admin bar "Notes" menu (every admin page). */
	function wireAdminBar() {
		var add = document.querySelector( '#wp-admin-bar-bsdn-add > .ab-item' );
		var toggle = document.querySelector( '#wp-admin-bar-bsdn-toggle > .ab-item' );
		if ( add ) {
			add.setAttribute( 'role', 'button' );
			add.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				newNote();
			} );
		}
		if ( toggle ) {
			toggle.setAttribute( 'role', 'button' );
			toggle.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				showBoard( board.hidden );
			} );
		}
	}

	/** Keys that match the page we are on. */
	function hereKeys() {
		var keys = [ '*', cfg.here.key ];
		if ( cfg.herePost ) {
			keys.push( cfg.herePost.key );
		}
		return keys;
	}

	var toastTimer;
	/** A short message at the bottom of the screen, optionally with one action (Undo). */
	function toast( message, actionLabel, action ) {
		var node = document.getElementById( 'bsdn-toast' );
		if ( ! node ) {
			node = el( 'div', 'bsdn-toast' );
			node.id = 'bsdn-toast';
			node.setAttribute( 'role', 'status' );
			document.body.appendChild( node );
		}
		node.textContent = '';
		node.appendChild( el( 'span', '', message ) );
		if ( actionLabel && action ) {
			var btn = el( 'button', 'bsdn-toast-action', actionLabel );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				node.hidden = true;
				action();
			} );
			node.appendChild( btn );
		}
		node.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			node.hidden = true;
		}, actionLabel ? 8000 : 5000 );
	}

	function showBoard( show ) {
		board.hidden = ! show;
		setSetting( 'bsdn_hidden', show ? '0' : '1' );
		updateToggle();
	}

	function buildToolbar() {
		var heading = document.querySelector( '#wpbody-content > .wrap > h1' );
		var bar = el( 'span', 'bsdn-toolbar' );
		if ( cfg.canCreate ) {
			var add = el( 'button', 'page-title-action bsdn-add', t.addNote );
			add.type = 'button';
			add.addEventListener( 'click', newNote );
			bar.appendChild( add );
		}
		toggleBtn = el( 'button', 'page-title-action bsdn-toggle' );
		toggleBtn.type = 'button';
		toggleBtn.addEventListener( 'click', function () {
			showBoard( board.hidden );
		} );
		bar.appendChild( toggleBtn );
		if ( heading ) {
			heading.insertAdjacentElement( 'afterend', bar );
		} else {
			board.parentNode.insertBefore( bar, board );
		}
	}

	function focusFromHash() {
		var m = /^#bsdn-note-(\d+)$/.exec( window.location.hash );
		if ( ! m || ! notes[ m[ 1 ] ] ) {
			return;
		}
		var entry = notes[ m[ 1 ] ];
		showBoard( true );
		if ( entry.data.state.minimized ) {
			entry.data.state.minimized = false;
			queueState( entry.data.id, { minimized: false } );
			render( entry );
		}
		bringToFront( entry );
		entry.el.scrollIntoView( { block: 'center' } );
		entry.el.classList.add( 'is-flash' );
		setTimeout( function () {
			entry.el.classList.remove( 'is-flash' );
		}, 1600 );
	}

	function init() {
		// The block editor hides everything else in #wpbody-content and
		// fills the window, so there the board is a fixed layer on <body>.
		var blockEditor = document.body.classList.contains( 'block-editor-page' );
		var host = blockEditor ? document.body : document.getElementById( 'wpbody-content' );
		host.classList.add( 'bsdn-host' );
		board = el( 'div', 'bsdn-board' + ( blockEditor ? ' is-fixed' : '' ) );
		board.id = 'bsdn-board';
		host.appendChild( board );
		board.hidden = getSetting( 'bsdn_hidden' ) === '1';

		if ( cfg.isDash ) {
			buildToolbar();
		}
		wireAdminBar();

		// Alt+Shift+N adds a note on this page (not while typing).
		document.addEventListener( 'keydown', function ( e ) {
			if ( ! e.altKey || ! e.shiftKey || e.ctrlKey || e.metaKey || e.code !== 'KeyN' ) {
				return;
			}
			var tgt = e.target;
			if ( tgt && ( tgt.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( tgt.tagName ) ) ) {
				return;
			}
			e.preventDefault();
			newNote();
		} );
		( cfg.notes || [] ).forEach( function ( note, i ) {
			addEntry( note, i );
		} );
		// Deadline nudge: notes due today, tomorrow or overdue (and not done)
		// come to the front for this visit and pulse once. Not saved.
		Object.keys( notes ).forEach( function ( k ) {
			var entry = notes[ k ];
			if ( isDueSoon( entry.data ) ) {
				entry.el.style.zIndex = String( topZ + 1 );
				entry.el.classList.add( 'is-nudge' );
				setTimeout( function () {
					entry.el.classList.remove( 'is-nudge' );
				}, 2600 );
			}
		} );
		updateToggle();

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && board.querySelector( '.bsdn-menu:not([hidden])' ) ) {
				closeMenus();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '.bsdn-menu' ) && ! e.target.closest( '.bsdn-more' ) ) {
				closeMenus();
			}
			if ( ! e.target.closest( '.bsdn-picker' ) ) {
				board.querySelectorAll( '.bsdn-suggest' ).forEach( function ( l ) {
					l.hidden = true;
				} );
			}
		} );
		// Refit notes when the page width changes, including when a
		// vertical scrollbar appears because a note made the page taller.
		var refit = function () {
			Object.keys( notes ).forEach( function ( k ) {
				if ( ! notes[ k ].el.classList.contains( 'is-dragging' ) ) {
					applyBox( notes[ k ] );
				}
			} );
		};
		window.addEventListener( 'resize', refit );
		if ( typeof window.ResizeObserver === 'function' ) {
			var lastWidth = 0;
			var lastHeight = 0;
			var observer = new window.ResizeObserver( function () {
				var w = document.documentElement.clientWidth;
				var h = blockEditor ? 0 : host.offsetHeight;
				if ( w !== lastWidth || h !== lastHeight ) {
					lastWidth = w;
					lastHeight = h;
					refit();
				}
			} );
			observer.observe( document.body );
			if ( ! blockEditor ) {
				// Page content grew or shrank (e.g. a long list hidden).
				observer.observe( host );
			}
		}
		window.addEventListener( 'hashchange', focusFromHash );
		focusFromHash();

		if ( /[?&]bsdn_new=1(&|$)/.test( window.location.search ) ) {
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', window.location.pathname );
			}
			newNote();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
