/* =========================================================================
 * AI Internal Linking — admin dashboard (dependency-free vanilla JS)
 * ========================================================================= */
( function () {
	'use strict';

	var CFG = window.AIL || {};

	/* ---------------------------------------------------------------- *
	 *  Tiny helpers
	 * ---------------------------------------------------------------- */
	function esc( s ) {
		s = ( s === null || s === undefined ) ? '' : String( s );
		return s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	}
	function attr( s ) { return esc( s ); }
	function num( n ) { n = parseInt( n || 0, 10 ); return n.toLocaleString(); }
	function qs( sel, root ) { return ( root || document ).querySelector( sel ); }
	function qsa( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }
	function regEscape( s ) { return String( s ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ); }

	function timeAgo( mysql ) {
		if ( ! mysql ) { return '—'; }
		var t = Date.parse( mysql.replace( ' ', 'T' ) );
		if ( isNaN( t ) ) { return esc( mysql ); }
		var s = Math.max( 1, Math.floor( ( Date.now() - t ) / 1000 ) );
		var units = [ [ 31536000, 'y' ], [ 2592000, 'mo' ], [ 86400, 'd' ], [ 3600, 'h' ], [ 60, 'm' ] ];
		for ( var i = 0; i < units.length; i++ ) {
			if ( s >= units[ i ][ 0 ] ) { return Math.floor( s / units[ i ][ 0 ] ) + units[ i ][ 1 ] + ' ago'; }
		}
		return s + 's ago';
	}

	function api( path, opts ) {
		opts = opts || {};
		var args = {
			method: opts.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin'
		};
		if ( opts.body ) { args.body = JSON.stringify( opts.body ); }
		return fetch( CFG.root + path, args ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! res.ok ) {
					var msg = ( data && ( data.message || data.error ) ) || ( 'Request failed (' + res.status + ')' );
					var e = new Error( msg ); e.data = data; throw e;
				}
				return data;
			} );
		} );
	}

	/* ---------------------------------------------------------------- *
	 *  Icons (inline SVG)
	 * ---------------------------------------------------------------- */
	var I = {
		grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
		pages: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/></svg>',
		link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',
		audit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
		settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
		tools: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.1 2.1-2.4-2.4z"/></svg>',
		search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>',
		sparkle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.8L19 9.5l-4.8 1.9L12 16l-1.9-4.6L5 9.5l5.1-1.7z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/></svg>',
		sync: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-9 9 9 9 0 0 1-7.5-4"/><path d="M3 12a9 9 0 0 1 9-9 9 9 0 0 1 7.5 4"/><path d="M21 3v5h-5M3 21v-5h5"/></svg>',
		x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
		check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
		edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
		ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg>',
		trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>',
		alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
		info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
		unlink: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.8 11.3 21 9a5 5 0 0 0-7-7l-2.3 2.2M5.2 12.7 3 15a5 5 0 0 0 7 7l2.3-2.2M2 2l20 20"/></svg>',
		brand: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M5.172 6.586a1 1 0 0 1 0 1.414L3.757 9.414a2 2 0 1 0 2.829 2.829L8 10.828a1 1 0 0 1 1.414 1.415L8 13.657A4 4 0 1 1 2.343 8l1.414-1.414a1 1 0 0 1 1.415 0m5.364-1.122a1 1 0 0 1 0 1.415l-3.657 3.657A1 1 0 0 1 5.464 9.12l3.657-3.657a1 1 0 0 1 1.415 0m3.12-3.12a4 4 0 0 1 0 5.656l-1.413 1.414A1 1 0 1 1 10.828 8l1.415-1.414a2 2 0 0 0-2.829-2.829L8 5.172a1 1 0 0 1-1.414-1.415L8 2.343a4 4 0 0 1 5.657 0"/></svg>'
	};

	/* ---------------------------------------------------------------- *
	 *  Toasts + confirm modal
	 * ---------------------------------------------------------------- */
	function toast( msg, type, title ) {
		var box = qs( '#ail-toasts' );
		if ( ! box ) { return; }
		var icons = { ok: I.check, err: I.alert, warn: I.alert, info: I.info };
		var el = document.createElement( 'div' );
		el.className = 'ail-toast ' + ( type || 'info' );
		el.innerHTML = '<span class="ail-toast-ico">' + ( icons[ type ] || I.info ) + '</span><div class="ail-toast-body">' +
			( title ? '<strong>' + esc( title ) + '</strong>' : '' ) + esc( msg ) + '</div>';
		box.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-out' );
			setTimeout( function () { el.remove(); }, 250 );
		}, type === 'err' ? 6000 : 3600 );
	}

	function confirmModal( opts ) {
		return new Promise( function ( resolve ) {
			var back = document.createElement( 'div' );
			back.className = 'ail-modal-back';
			back.innerHTML = '<div class="ail-modal"><h3>' + esc( opts.title ) + '</h3><p>' + esc( opts.message ) + '</p>' +
				'<div class="ail-modal-actions"><button class="ail-btn ail-btn-ghost" data-no>' + esc( opts.cancel || 'Cancel' ) + '</button>' +
				'<button class="ail-btn ' + ( opts.danger ? 'ail-btn-danger' : 'ail-btn-primary' ) + '" data-yes>' + esc( opts.confirm || 'Confirm' ) + '</button></div></div>';
			document.body.appendChild( back );
			function done( v ) { back.remove(); resolve( v ); }
			back.addEventListener( 'click', function ( e ) {
				if ( e.target === back || e.target.hasAttribute( 'data-no' ) ) { done( false ); }
				if ( e.target.hasAttribute( 'data-yes' ) ) { done( true ); }
			} );
		} );
	}

	/* ---------------------------------------------------------------- *
	 *  App state + shell
	 * ---------------------------------------------------------------- */
	var App = {
		root: null,
		view: null,
		route: 'dashboard',
		postTypes: [],
		pagesState: { search: '', status: '', type: '', orderby: 'updated_at', order: 'DESC', page: 1, per_page: 20 },
		siteScan: null,
		drawer: null
	};

	var NAV = [
		{ id: 'dashboard', label: 'Dashboard', icon: I.grid },
		{ id: 'pages', label: 'Pages', icon: I.pages },
		{ id: 'opportunities', label: 'Opportunities', icon: I.sparkle },
		{ id: 'links', label: 'Applied Links', icon: I.link },
		{ id: 'audit', label: 'Audit', icon: I.audit },
		{ id: 'settings', label: 'Settings', icon: I.settings },
		{ id: 'tools', label: 'Tools', icon: I.tools }
	];

	function renderShell() {
		var nav = NAV.map( function ( n ) {
			return '<button data-route="' + n.id + '" class="' + ( App.route === n.id ? 'is-active' : '' ) + '">' +
				n.icon + '<span class="label">' + esc( n.label ) + '</span></button>';
		} ).join( '' );

		App.root.innerHTML =
			'<div class="ail-shell">' +
				'<aside class="ail-side">' +
					'<div class="ail-brand"><span class="ail-logo">' + I.brand + '</span><div><h1>AI Internal Linking</h1><small>by RankerMind</small></div></div>' +
					'<nav class="ail-nav">' + nav + '</nav>' +
					'<div class="ail-side-foot">v' + esc( CFG.version || '1.0.0' ) + ' · <a href="' + attr( CFG.docsUrl ) + '" target="_blank" rel="noopener">rankermind.com</a></div>' +
				'</aside>' +
				'<main class="ail-main" id="ail-view"></main>' +
			'</div>' +
			'<div class="ail-toasts" id="ail-toasts"></div>' +
			'<div class="ail-overlay" id="ail-overlay"></div>' +
			'<div class="ail-drawer" id="ail-drawer" role="dialog" aria-modal="true"></div>';

		App.view = qs( '#ail-view' );

		qsa( '.ail-nav button' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { go( b.getAttribute( 'data-route' ) ); } );
		} );
		qs( '#ail-overlay' ).addEventListener( 'click', closeDrawer );
	}

	function setActiveNav() {
		qsa( '.ail-nav button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-route' ) === App.route );
		} );
	}

	function go( route ) {
		App.route = route;
		if ( location.hash.replace( '#/', '' ) !== route ) { location.hash = '#/' + route; }
		setActiveNav();
		render();
	}

	function pageHead( title, sub, actionsHtml ) {
		return '<div class="ail-pagehead"><div><h2>' + esc( title ) + '</h2><p>' + esc( sub ) + '</p></div>' +
			'<div class="ail-actions">' + ( actionsHtml || '' ) + '</div></div>';
	}

	function loadingBlock() {
		var rows = '';
		for ( var i = 0; i < 6; i++ ) { rows += '<div class="ail-skel" style="margin:14px 16px;width:' + ( 60 + ( i * 6 ) % 35 ) + '%"></div>'; }
		return '<div class="ail-table-wrap">' + rows + '</div>';
	}

	function render() {
		var r = App.route;
		if ( r === 'dashboard' ) { return viewDashboard(); }
		if ( r === 'pages' ) { return viewPages(); }
		if ( r === 'opportunities' ) { return viewAllOpportunities(); }
		if ( r === 'links' ) { return viewLinks(); }
		if ( r === 'audit' ) { return viewAudit(); }
		if ( r === 'settings' ) { return viewSettings(); }
		if ( r === 'tools' ) { return viewTools(); }
		viewDashboard();
	}

	/* ================================================================ *
	 *  DASHBOARD
	 * ================================================================ */
	function viewDashboard() {
		App.view.innerHTML = pageHead( 'Dashboard', 'Overview of your indexed content and internal-linking health.',
			'<button class="ail-btn" id="d-sync">' + I.sync + 'Sync now</button>' +
			'<button class="ail-btn ail-btn-primary" id="d-audit">' + I.audit + 'Run audit</button>' ) +
			'<div id="d-body">' + loadingBlock() + '</div>';

		qs( '#d-sync' ).addEventListener( 'click', runFullSync );
		qs( '#d-audit' ).addEventListener( 'click', function () { go( 'audit' ); setTimeout( runAudit, 60 ); } );

		api( '/stats' ).then( function ( s ) {
			App.postTypes = s.post_types || [];
			var stat = function ( ico, cls, val, lbl, sub ) {
				return '<div class="ail-stat"><div class="ail-stat-ico ' + cls + '">' + ico + '</div>' +
					'<div class="ail-stat-val">' + val + '</div><div class="ail-stat-lbl">' + esc( lbl ) + '</div>' +
					( sub ? '<div class="ail-stat-sub">' + sub + '</div>' : '' ) + '</div>';
			};
			var scoreTxt = ( s.audit_score === null || s.audit_score === undefined ) ? '—' : s.audit_score;
			var stats = '<div class="ail-stats">' +
				stat( I.pages, 'ico-violet', num( s.indexed_pages ), 'Indexed pages', num( s.total_words ) + ' words tracked' ) +
				stat( I.sparkle, 'ico-blue', num( s.opportunities ), 'Open opportunities', 'across all pages' ) +
				stat( I.link, 'ico-green', num( s.links_applied ), 'Links applied', 'avg ' + ( s.avg_outbound || 0 ) + ' out / page' ) +
				stat( I.audit, ( s.orphans > 0 ? 'ico-amber' : 'ico-green' ), num( s.orphans ), 'Orphan pages', 'no inbound links' ) +
				'</div>';

			var feed = ( s.activity && s.activity.length ) ? s.activity.map( function ( a ) {
				var color = a.level === 'error' ? 'dot-red' : a.level === 'warning' ? 'dot-amber' : a.level === 'success' ? 'dot-green' : 'dot-gray';
				return '<div class="ail-feed-item"><span class="ail-feed-dot ' + color + '"></span><div><div class="ail-feed-msg">' + esc( a.message ) + '</div><div class="ail-feed-time">' + esc( a.ago ) + ' ago · ' + esc( a.context ) + '</div></div></div>';
			} ).join( '' ) : '<p class="ail-muted" style="font-size:13px">No activity yet. Run a sync to get started.</p>';

			var configWarn = s.configured ? '' :
				'<div class="ail-card ail-card-pad" style="border-color:var(--ail-amber);background:var(--ail-amber-soft);margin-bottom:16px">' +
				'<div class="ail-flex" style="align-items:flex-start"><span style="color:var(--ail-amber)">' + I.alert + '</span><div><strong style="font-size:13.5px">Connect your n8n workflow</strong>' +
				'<p style="margin:4px 0 0;font-size:12.5px;color:var(--ail-text-soft)">Add your n8n webhook URLs in <a href="#/settings">Settings</a> to enable AI opportunity finding and audits.</p></div></div></div>';

			var scoreCard = '<div class="ail-card"><div class="ail-card-head"><h3>Internal linking score</h3></div>' +
				'<div class="ail-card-pad" style="text-align:center">' + scoreRing( s.audit_score ) +
				'<p class="ail-muted" style="font-size:12px;margin:10px 0 0">' + ( s.audit_at ? 'Last audit ' + timeAgo( s.audit_at ) : 'No audit run yet' ) + '</p>' +
				'<button class="ail-btn ail-btn-sm ail-mt-16" id="d-goaudit" style="margin-top:14px">View audit</button></div></div>';

			qs( '#d-body' ).innerHTML = configWarn + stats +
				'<div class="ail-grid-2">' +
					'<div class="ail-card"><div class="ail-card-head"><h3>Recent activity</h3><p>Sync, AI and linking events</p></div><div class="ail-card-pad"><div class="ail-feed">' + feed + '</div></div></div>' +
					scoreCard +
				'</div>';
			var ga = qs( '#d-goaudit' ); if ( ga ) { ga.addEventListener( 'click', function () { go( 'audit' ); } ); }
		} ).catch( function ( e ) { qs( '#d-body' ).innerHTML = errorBlock( e ); } );
	}

	function scoreRing( score ) {
		if ( score === null || score === undefined ) {
			return '<svg class="ail-ring" viewBox="0 0 120 120"><circle cx="60" cy="60" r="52" fill="none" stroke="var(--ail-border)" stroke-width="12"/><text class="val" x="60" y="64" text-anchor="middle">—</text><text class="lbl" x="60" y="82" text-anchor="middle">NO DATA</text></svg>';
		}
		var r = 52, c = 2 * Math.PI * r, off = c * ( 1 - score / 100 );
		var col = score >= 80 ? 'var(--ail-green)' : score >= 55 ? 'var(--ail-amber)' : 'var(--ail-red)';
		return '<svg class="ail-ring" viewBox="0 0 120 120">' +
			'<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="var(--ail-border)" stroke-width="12"/>' +
			'<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="' + col + '" stroke-width="12" stroke-linecap="round" stroke-dasharray="' + c + '" stroke-dashoffset="' + off + '" transform="rotate(-90 60 60)"/>' +
			'<text class="val" x="60" y="64" text-anchor="middle">' + score + '</text>' +
			'<text class="lbl" x="60" y="82" text-anchor="middle">/ 100</text></svg>';
	}

	function errorBlock( e ) {
		return '<div class="ail-card ail-card-pad"><div class="ail-flex" style="color:var(--ail-red)">' + I.alert + '<strong>' + esc( e.message || 'Something went wrong' ) + '</strong></div></div>';
	}

	function runFullSync() {
		var btn = qs( '#d-sync' );
		if ( btn ) { btn.disabled = true; btn.innerHTML = I.sync + 'Syncing…'; }
		toast( 'Indexing your content…', 'info' );
		api( '/sync', { method: 'POST', body: { force: false } } ).then( function ( r ) {
			toast( ( r.stats.indexed ) + ' indexed, ' + r.stats.unchanged + ' unchanged, ' + r.stats.removed + ' removed.', 'ok', 'Sync complete' );
			if ( App.route === 'dashboard' ) { viewDashboard(); } else if ( App.route === 'pages' ) { loadPages(); }
		} ).catch( function ( e ) { toast( e.message, 'err', 'Sync failed' ); } )
		.finally( function () { if ( btn ) { btn.disabled = false; btn.innerHTML = I.sync + 'Sync now'; } } );
	}

	/* ================================================================ *
	 *  PAGES
	 * ================================================================ */
	function viewPages() {
		var typeOpts = '<option value="">All types</option>' + App.postTypes.map( function ( t ) {
			return '<option value="' + attr( t.name ) + '"' + ( App.pagesState.type === t.name ? ' selected' : '' ) + '>' + esc( t.label ) + '</option>';
		} ).join( '' );

		App.view.innerHTML = pageHead( 'Pages', 'Every indexed page. Click "Find opportunities" to let the AI suggest contextual internal links.',
			'<button class="ail-btn" id="p-sync">' + I.sync + 'Sync now</button><button class="ail-btn ail-btn-primary" id="p-scan-all">' + I.sparkle + 'Scan all pages</button>' ) +
			'<div class="ail-toolbar">' +
				'<div class="ail-search">' + I.search + '<input class="ail-input" id="p-search" placeholder="Search pages…" value="' + attr( App.pagesState.search ) + '"></div>' +
				'<select class="ail-select" id="p-type">' + typeOpts + '</select>' +
				'<select class="ail-select" id="p-status">' +
					'<option value="">All statuses</option>' +
					'<option value="synced"' + ( App.pagesState.status === 'synced' ? ' selected' : '' ) + '>Synced</option>' +
					'<option value="stale"' + ( App.pagesState.status === 'stale' ? ' selected' : '' ) + '>Stale</option>' +
				'</select>' +
			'</div>' +
			'<div id="p-body">' + loadingBlock() + '</div>';

		qs( '#p-sync' ).addEventListener( 'click', runFullSync );
		qs( '#p-scan-all' ).addEventListener( 'click', function () { go( 'opportunities' ); setTimeout( startSiteScan, 50 ); } );
		var deb;
		qs( '#p-search' ).addEventListener( 'input', function ( e ) {
			clearTimeout( deb ); var v = e.target.value;
			deb = setTimeout( function () { App.pagesState.search = v; App.pagesState.page = 1; loadPages(); }, 300 );
		} );
		qs( '#p-type' ).addEventListener( 'change', function ( e ) { App.pagesState.type = e.target.value; App.pagesState.page = 1; loadPages(); } );
		qs( '#p-status' ).addEventListener( 'change', function ( e ) { App.pagesState.status = e.target.value; App.pagesState.page = 1; loadPages(); } );

		if ( ! App.postTypes.length ) {
			api( '/stats' ).then( function ( s ) { App.postTypes = s.post_types || []; viewPages(); } );
			return;
		}
		loadPages();
	}

	/* ================================================================ *
	 *  SITE-WIDE OPPORTUNITIES
	 * ================================================================ */
	function viewAllOpportunities() {
		App.view.innerHTML = pageHead( 'Opportunities', 'Scan every page once, then review links from and to pages in one queue.',
			'<button class="ail-btn ail-btn-primary" id="o-scan-all">' + I.sparkle + 'Scan all pages</button>' ) +
			'<div id="o-progress"></div><div id="o-body">' + loadingBlock() + '</div>';
		qs( '#o-scan-all' ).addEventListener( 'click', startSiteScan );
		renderSiteScanProgress();
		loadAllOpportunities();
	}

	function startSiteScan() {
		if ( App.siteScan && App.siteScan.running ) { return; }
		api( '/pages/scan-targets' ).then( function ( res ) {
			if ( ! res.rows.length ) { toast( 'Sync your pages before running a site scan.', 'warn' ); return; }
			App.siteScan = { running: true, cancelled: false, rows: res.rows, index: 0, found: 0, failed: [] };
			renderSiteScanProgress();
			runNextSiteScan();
		} ).catch( function ( e ) { toast( e.message, 'err', 'Scan could not start' ); } );
	}

	function runNextSiteScan() {
		var s = App.siteScan;
		if ( ! s || s.cancelled || s.index >= s.rows.length ) {
			if ( s ) { s.running = false; }
			renderSiteScanProgress(); loadAllOpportunities();
			if ( s && ! s.cancelled ) { toast( s.found + ' opportunities found across ' + s.rows.length + ' pages.', 'ok', 'Site scan complete' ); }
			return;
		}
		var page = s.rows[ s.index ];
		renderSiteScanProgress();
		api( '/opportunities/find', { method: 'POST', body: { post_id: parseInt( page.post_id, 10 ), direction: 'outbound' } } )
			.then( function ( res ) { s.found += parseInt( res.count || 0, 10 ); } )
			.catch( function ( e ) { s.failed.push( { title: page.title, message: e.message } ); } )
			.finally( function () { s.index++; renderSiteScanProgress(); loadAllOpportunities(); runNextSiteScan(); } );
	}

	function renderSiteScanProgress() {
		var box = qs( '#o-progress' );
		if ( ! box ) { return; }
		var s = App.siteScan;
		if ( ! s ) { box.innerHTML = ''; return; }
		var total = s.rows.length, done = Math.min( s.index, total ), pct = total ? Math.round( done / total * 100 ) : 0;
		var current = s.running && s.rows[ s.index ] ? 'Scanning “' + esc( s.rows[ s.index ].title ) + '”' : ( s.cancelled ? 'Scan stopped' : 'Scan complete' );
		box.innerHTML = '<div class="ail-scan-panel"><div class="ail-spread"><div><strong>' + current + '</strong><p>' + done + ' of ' + total + ' pages · ' + s.found + ' opportunities · ' + s.failed.length + ' failed</p></div>' +
			( s.running ? '<button class="ail-btn ail-btn-sm" id="o-cancel">Stop</button>' : '' ) + '</div><div class="ail-scan-track"><span style="width:' + pct + '%"></span></div></div>';
		var cancel = qs( '#o-cancel' ); if ( cancel ) { cancel.addEventListener( 'click', function () { s.cancelled = true; s.running = false; renderSiteScanProgress(); } ); }
	}

	function loadAllOpportunities() {
		var body = qs( '#o-body' ); if ( ! body ) { return; }
		api( '/opportunities/all' ).then( function ( res ) {
			if ( ! res.rows.length ) { body.innerHTML = emptyState( I.sparkle, 'No open opportunities', 'Run a site scan to analyse all indexed pages.' ); return; }
			body.innerHTML = '<div class="ail-bulk-head"><label class="ail-flex"><input type="checkbox" id="o-all"> Select all</label><span id="o-count">0 selected</span><button class="ail-btn ail-btn-primary" id="o-apply" disabled>' + I.link + 'Apply links</button></div><div class="ail-global-opps">' + res.rows.map( function ( o ) {
				return '<div class="ail-opp" data-oid="' + o.id + '"><div class="ail-opp-head"><input type="checkbox" class="ail-check" data-o-check><div class="ail-opp-main"><div class="ail-opp-anchor"><span class="ail-anchor-chip">' + esc( o.anchor_text ) + '</span><span class="ail-badge badge-gray">from: ' + esc( o.source_title ) + '</span></div><div class="ail-opp-context">…' + highlight( o.context_sentence, o.anchor_text ) + '…</div><div class="ail-cands"><div class="ail-cand is-chosen"><div class="ail-cand-body"><div class="ail-cand-title">' + esc( o.target_title || o.target_url ) + '<span class="ail-score" style="' + scoreColor( o.score ) + '">' + Math.round( o.score * 100 ) + '%</span></div><div class="ail-cand-url">' + esc( ( o.target_url || '' ).replace( /^https?:\/\//, '' ) ) + '</div>' + ( o.reason ? '<div class="ail-cand-reason">' + esc( o.reason ) + '</div>' : '' ) + '</div></div></div><label class="ail-global-edit">Linked phrase<input class="ail-input" data-o-anchor value="' + attr( o.anchor_text ) + '"></label></div></div></div>';
			} ).join( '' ) + '</div>';
			bindAllOpportunities( res.rows );
		} ).catch( function ( e ) { body.innerHTML = errorBlock( e ); } );
	}

	function bindAllOpportunities( rows ) {
		var body = qs( '#o-body' ), checks = qsa( '[data-o-check]', body );
		function update() { var n = checks.filter( function ( c ) { return c.checked; } ).length; qs( '#o-count' ).textContent = n + ' selected'; qs( '#o-apply' ).disabled = ! n; }
		checks.forEach( function ( c ) { c.addEventListener( 'change', function () { c.closest( '.ail-opp' ).classList.toggle( 'is-selected', c.checked ); update(); } ); } );
		qs( '#o-all' ).addEventListener( 'change', function ( e ) { checks.forEach( function ( c ) { c.checked = e.target.checked; c.closest( '.ail-opp' ).classList.toggle( 'is-selected', c.checked ); } ); update(); } );
		qs( '#o-apply' ).addEventListener( 'click', function () {
			var selected = [];
			checks.forEach( function ( c, i ) { if ( c.checked ) { var o = rows[ i ]; selected.push( { opportunity_id: o.id, anchor_text: qs( '[data-o-anchor]', c.closest( '.ail-opp' ) ).value.trim(), original_anchor: o.anchor_text, target_url: o.target_url, target_post_id: o.target_post_id, source_post_id: o.source_post_id } ); } } );
			var btn = qs( '#o-apply' ); btn.disabled = true; btn.textContent = 'Applying…';
			api( '/opportunities/apply', { method: 'POST', body: { post_id: selected[ 0 ].source_post_id, direction: 'outbound', items: selected } } ).then( function ( r ) { toast( r.applied + ' links applied.', 'ok' ); if ( r.failed.length ) { r.failed.forEach( function ( f ) { toast( f, 'warn' ); } ); } loadAllOpportunities(); } ).catch( function ( e ) { toast( e.message, 'err', 'Apply failed' ); loadAllOpportunities(); } );
		} );
	}

	function sortTh( key, label ) {
		var st = App.pagesState, arrow = '';
		if ( st.orderby === key ) { arrow = st.order === 'ASC' ? ' ↑' : ' ↓'; }
		return '<th class="sortable" data-sort="' + key + '">' + esc( label ) + arrow + '</th>';
	}

	function loadPages() {
		var st = App.pagesState;
		var q = '?page=' + st.page + '&per_page=' + st.per_page + '&orderby=' + encodeURIComponent( st.orderby ) + '&order=' + st.order +
			'&search=' + encodeURIComponent( st.search ) + '&type=' + encodeURIComponent( st.type ) + '&status=' + encodeURIComponent( st.status );
		var body = qs( '#p-body' );
		api( '/pages' + q ).then( function ( res ) {
			if ( ! res.rows.length ) {
				body.innerHTML = emptyState( I.pages, 'No pages indexed yet', 'Run a sync to index your published content, then come back to find linking opportunities.', '<button class="ail-btn ail-btn-primary" id="p-empty-sync">' + I.sync + 'Run first sync</button>' );
				var b = qs( '#p-empty-sync' ); if ( b ) { b.addEventListener( 'click', runFullSync ); }
				return;
			}
			var rows = res.rows.map( function ( p ) {
				var statusBadge = p.sync_status === 'synced' ? '<span class="ail-badge badge-green"><span class="ail-dot dot-green"></span>Synced</span>'
					: '<span class="ail-badge badge-amber"><span class="ail-dot dot-amber"></span>' + esc( p.sync_status ) + '</span>';
				var readyCount = ( p.suggested_count || 0 ) + ( p.inbound_suggested_count || 0 );
				var oppBadge   = readyCount > 0 ? '<button class="ail-btn ail-btn-sm" data-view="' + p.post_id + '">' + I.pages + 'View ' + readyCount + '</button>' : '';
				return '<tr data-pid="' + p.post_id + '" data-title="' + attr( p.title ) + '">' +
					'<td><span class="ail-page-title">' + esc( p.title ) + '</span><span class="ail-page-url">' + esc( ( p.url || '' ).replace( /^https?:\/\//, '' ) ) + '</span></td>' +
					'<td><span class="ail-badge badge-gray">' + esc( p.post_type ) + '</span></td>' +
					'<td class="ail-num">' + num( p.word_count ) + '</td>' +
					'<td class="ail-num">' + num( p.inbound_count ) + ' / ' + num( p.outbound_count ) + '</td>' +
					'<td>' + statusBadge + '</td>' +
					'<td class="ail-row-actions">' + oppBadge +
						'<button class="ail-btn ail-btn-sm ail-btn-primary" data-find="' + p.post_id + '">' + I.sparkle + 'Find opportunities</button>' +
						( p.edit_link ? '<a class="ail-btn ail-btn-sm ail-btn-ghost" href="' + attr( p.edit_link ) + '" target="_blank" rel="noopener" title="Edit page">' + I.ext + '</a>' : '' ) +
					'</td></tr>';
			} ).join( '' );

			var totalPages = Math.max( 1, Math.ceil( res.total / st.per_page ) );
			body.innerHTML = '<div class="ail-table-wrap"><table class="ail-table"><thead><tr>' +
				sortTh( 'title', 'Page' ) +
				'<th>Type</th>' + sortTh( 'word_count', 'Words' ) +
				'<th>In / Out</th><th>Status</th><th style="text-align:right">Actions</th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table>' +
				'<div class="ail-pager"><span>' + num( res.total ) + ' pages · page ' + st.page + ' of ' + totalPages + '</span>' +
				'<span class="ail-pager-btns"><button class="ail-btn ail-btn-sm" id="pg-prev"' + ( st.page <= 1 ? ' disabled' : '' ) + '>Prev</button>' +
				'<button class="ail-btn ail-btn-sm" id="pg-next"' + ( st.page >= totalPages ? ' disabled' : '' ) + '>Next</button></span></div></div>';

			qsa( '[data-find]', body ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var tr = b.closest( 'tr' );
					openOppDrawer( parseInt( b.getAttribute( 'data-find' ), 10 ), tr.getAttribute( 'data-title' ), { run: true, direction: 'outbound' } );
				} );
			} );
			qsa( '[data-view]', body ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var tr = b.closest( 'tr' );
					openOppDrawer( parseInt( b.getAttribute( 'data-view' ), 10 ), tr.getAttribute( 'data-title' ), { run: false, direction: 'outbound' } );
				} );
			} );
			qsa( 'th.sortable', body ).forEach( function ( th ) {
				th.addEventListener( 'click', function () {
					var k = th.getAttribute( 'data-sort' );
					if ( st.orderby === k ) { st.order = st.order === 'ASC' ? 'DESC' : 'ASC'; } else { st.orderby = k; st.order = 'ASC'; }
					loadPages();
				} );
			} );
			var pv = qs( '#pg-prev' ), nx = qs( '#pg-next' );
			if ( pv ) { pv.addEventListener( 'click', function () { if ( st.page > 1 ) { st.page--; loadPages(); } } ); }
			if ( nx ) { nx.addEventListener( 'click', function () { st.page++; loadPages(); } ); }
		} ).catch( function ( e ) { body.innerHTML = errorBlock( e ); } );
	}

	function emptyState( ico, title, msg, action ) {
		return '<div class="ail-table-wrap"><div class="ail-empty"><div class="ail-empty-ico">' + ico + '</div><h4>' + esc( title ) + '</h4><p>' + esc( msg ) + '</p>' + ( action || '' ) + '</div></div>';
	}

	/* ================================================================ *
	 *  FIND OPPORTUNITIES DRAWER
	 * ================================================================ */
	function openDrawer() { qs( '#ail-overlay' ).classList.add( 'is-open' ); qs( '#ail-drawer' ).classList.add( 'is-open' ); }
	function closeDrawer() { qs( '#ail-overlay' ).classList.remove( 'is-open' ); qs( '#ail-drawer' ).classList.remove( 'is-open' ); }

	function openOppDrawer( postId, title, opts ) {
		opts = opts || {};
		App.drawer = { postId: postId, title: title, direction: opts.direction || 'outbound', groups: [], state: {} };
		var d = qs( '#ail-drawer' );
		d.innerHTML =
			'<div class="ail-drawer-head"><div class="ail-eyebrow">AI Internal Linking</div>' +
			'<h3>' + esc( title ) + '</h3><div class="ail-sub" id="dr-sub"></div>' +
			'<div class="ail-dir-tabs" id="dr-tabs">' +
				'<button data-dir="outbound">Links from this page</button>' +
				'<button data-dir="inbound">Links to this page</button>' +
			'</div>' +
			'<button class="ail-drawer-close" id="dr-close">' + I.x + '</button>' +
			'<div class="ail-progress ail-hide"><span></span></div></div>' +
			'<div class="ail-drawer-body" id="dr-body"></div>';
		qs( '#dr-close' ).addEventListener( 'click', closeDrawer );
		qsa( '#dr-tabs button', d ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				if ( b.getAttribute( 'data-dir' ) === App.drawer.direction ) { return; }
				App.drawer.direction = b.getAttribute( 'data-dir' );
				syncDirTabs();
				loadStoredOpportunities();
			} );
		} );
		syncDirTabs();
		openDrawer();

		if ( opts.run ) {
			runOppScan();
		} else {
			loadStoredOpportunities();
		}
	}

	function syncDirTabs() {
		qsa( '#dr-tabs button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-dir' ) === App.drawer.direction );
		} );
	}

	function drawerLoading( on ) {
		var p = qs( '#ail-drawer .ail-progress' );
		if ( p ) { p.classList.toggle( 'ail-hide', ! on ); }
	}

	function loadStoredOpportunities() {
		var dr = App.drawer;
		drawerLoading( true );
		qs( '#dr-body' ).innerHTML = '<div class="ail-loadstate"><div class="ail-orbit"></div><p>Loading saved suggestions…</p></div>';
		api( '/opportunities?post_id=' + dr.postId + '&direction=' + dr.direction )
			.then( function ( res ) { renderOpportunities( res, { stored: true } ); } )
			.catch( function ( e ) { drawerError( e ); } )
			.finally( function () { drawerLoading( false ); } );
	}

	function runOppScan() {
		var dr = App.drawer;
		if ( dr._timer ) { clearInterval( dr._timer ); }
		drawerLoading( true );
		qs( '#dr-sub' ).textContent = 'Finding contextual opportunities…';
		qs( '#dr-body' ).innerHTML = loadStateHtml( dr.direction );
		animateLoadSteps();
		api( '/opportunities/find', { method: 'POST', body: { post_id: dr.postId, direction: dr.direction } } )
			.then( function ( res ) { renderOpportunities( res, { stored: false } ); } )
			.catch( function ( e ) { drawerError( e ); } )
			.finally( function () { drawerLoading( false ); } );
	}

	function drawerError( e ) {
		if ( App.drawer && App.drawer._timer ) { clearInterval( App.drawer._timer ); }
		qs( '#dr-body' ).innerHTML = '<div class="ail-empty"><div class="ail-empty-ico" style="background:var(--ail-red-soft);color:var(--ail-red)">' + I.alert + '</div><h4>Couldn\'t load opportunities</h4><p>' + esc( e.message ) + '</p><button class="ail-btn" id="dr-retry">Try again</button></div>';
		var rt = qs( '#dr-retry' ); if ( rt ) { rt.addEventListener( 'click', runOppScan ); }
	}

	function loadStateHtml( direction ) {
		var steps = ( 'inbound' === direction
			? [ 'Reading this page\'s topic', 'Scanning other pages\' content', 'Ollama matching phrases to this page', 'Ranking & de-duplicating' ]
			: [ 'Reading page content', 'Sending to n8n workflow', 'Ollama analysing context', 'Ranking & de-duplicating' ]
		).map( function ( s, i ) {
			return '<div class="ail-loadstep' + ( i === 0 ? ' is-active' : '' ) + '" data-step="' + i + '"><span class="ail-ls-ico">' + ( i === 0 ? '<span class="ail-spinner" style="width:14px;height:14px"></span>' : I.info ) + '</span>' + esc( s ) + '</div>';
		} ).join( '' );
		var blurb = 'inbound' === direction
			? 'The AI is scanning your other pages for phrases that should link here.'
			: 'The AI is reviewing this page against your whole site.';
		return '<div class="ail-loadstate"><div class="ail-orbit"></div><h4>Analysing internal linking opportunities</h4><p>' + blurb + '</p><div class="ail-loadsteps">' + steps + '</div></div>';
	}
	function animateLoadSteps() {
		var i = 0;
		App.drawer._timer = setInterval( function () {
			var steps = qsa( '.ail-loadstep' );
			if ( ! steps.length ) { clearInterval( App.drawer._timer ); return; }
			if ( i < steps.length ) {
				if ( i > 0 ) { steps[ i - 1 ].classList.remove( 'is-active' ); steps[ i - 1 ].classList.add( 'is-done' ); steps[ i - 1 ].querySelector( '.ail-ls-ico' ).innerHTML = I.check; }
				if ( steps[ i ] ) { steps[ i ].classList.add( 'is-active' ); steps[ i ].querySelector( '.ail-ls-ico' ).innerHTML = '<span class="ail-spinner" style="width:14px;height:14px"></span>'; }
				i++;
			}
		}, 1400 );
	}

	function highlight( text, anchor ) {
		if ( ! text ) { return ''; }
		var safe = esc( text );
		if ( anchor ) {
			try {
				var re = new RegExp( '(' + regEscape( esc( anchor ) ) + ')', 'i' );
				safe = safe.replace( re, '<mark>$1</mark>' );
			} catch ( e ) {}
		}
		return safe;
	}

	function renderOpportunities( res, opts ) {
		opts = opts || {};
		if ( App.drawer && App.drawer._timer ) { clearInterval( App.drawer._timer ); }
		var inbound = 'inbound' === App.drawer.direction;
		qs( '#dr-sub' ).textContent = res.count + ' ' + ( inbound ? ( res.count === 1 ? 'inbound suggestion' : 'inbound suggestions' ) : ( res.count === 1 ? 'opportunity' : 'opportunities' ) ) + ( opts.stored ? ' saved' : ' found' );

		App.drawer.groups = res.groups || [];
		App.drawer.state = {};
		App.drawer.groups.forEach( function ( g, gi ) {
			App.drawer.state[ gi ] = { selected: false, chosen: g.candidates[ 0 ] ? g.candidates[ 0 ].id : 0, anchor: g.anchor_text, editing: false };
		} );

		if ( ! App.drawer.groups.length ) {
			var emptyCopy = opts.stored
				? 'No saved suggestions for this direction yet. Run an AI scan to look for some.'
				: ( inbound
					? 'The AI didn\'t find phrases on other pages that should link here right now.'
					: 'The AI didn\'t find contextual internal links to add here right now. This often means the page is already well linked.' );
			qs( '#dr-body' ).innerHTML = '<div class="ail-empty"><div class="ail-empty-ico">' + ( opts.stored ? I.sparkle : I.check ) + '</div><h4>' + ( opts.stored ? 'Nothing saved yet' : 'No new opportunities' ) + '</h4><p>' + emptyCopy + '</p><button class="ail-btn ail-btn-primary" id="dr-scan">' + I.sparkle + 'Run AI scan</button></div>';
			var sb = qs( '#dr-scan' ); if ( sb ) { sb.addEventListener( 'click', runOppScan ); }
			ensureFooter();
			updateFooter();
			return;
		}

		qs( '#dr-body' ).innerHTML =
			'<div class="ail-spread" style="margin-bottom:12px"><span class="ail-muted" style="font-size:12px">' +
				( inbound ? 'Each suggestion adds a link on another page, pointing here.' : 'Each suggestion adds a link in this page\'s content.' ) +
			'</span><button class="ail-btn ail-btn-sm" id="dr-rescan">' + I.sparkle + 'Re-scan</button></div>' +
			App.drawer.groups.map( renderGroup ).join( '' );
		var rb = qs( '#dr-rescan' ); if ( rb ) { rb.addEventListener( 'click', runOppScan ); }
		ensureFooter();
		var all = qs( '#dr-all' ); if ( all ) { all.checked = false; } // stale select-all from a previous render
		bindGroupEvents();
		updateFooter();
	}

	function scoreColor( s ) {
		if ( s >= 0.8 ) { return 'background:var(--ail-green-soft);color:var(--ail-green)'; }
		if ( s >= 0.6 ) { return 'background:var(--ail-blue-soft);color:var(--ail-blue)'; }
		return 'background:var(--ail-amber-soft);color:var(--ail-amber)';
	}

	function renderGroup( g, gi ) {
		var st = App.drawer.state[ gi ];
		var multi = g.candidates.length > 1;
		var ctx = g.candidates[ 0 ] ? g.candidates[ 0 ].context_sentence : '';

		var cands = g.candidates.map( function ( c ) {
			var chosen = st.chosen === c.id;
			return '<label class="ail-cand' + ( chosen ? ' is-chosen' : '' ) + '" data-gi="' + gi + '" data-cid="' + c.id + '">' +
				'<input type="radio" class="ail-radio" name="cand-' + gi + '"' + ( chosen ? ' checked' : '' ) + '>' +
				'<div class="ail-cand-body"><div class="ail-cand-title">' + esc( c.target_title || c.target_url ) +
				'<span class="ail-score" style="' + scoreColor( c.score ) + '">' + Math.round( c.score * 100 ) + '%</span></div>' +
				'<div class="ail-cand-url">' + esc( ( c.target_url || '' ).replace( /^https?:\/\//, '' ) ) + '</div>' +
				( c.reason ? '<div class="ail-cand-reason">' + esc( c.reason ) + '</div>' : '' ) +
				'</div></label>';
		} ).join( '' );

		return '<div class="ail-opp' + ( st.selected ? ' is-selected' : '' ) + '" data-gi="' + gi + '">' +
			'<div class="ail-opp-head">' +
				'<input type="checkbox" class="ail-check" data-check="' + gi + '"' + ( st.selected ? ' checked' : '' ) + '>' +
				'<div class="ail-opp-main">' +
					'<div class="ail-opp-anchor"><span class="ail-anchor-chip">' + esc( g.anchor_text ) + '</span>' +
						( g.source_title ? '<span class="ail-badge badge-gray">on: ' + esc( g.source_title ) + '</span>' : '' ) +
						( multi ? '<span class="ail-badge badge-blue ail-multi">' + g.candidates.length + ' possible targets — pick one</span>' : '' ) +
					'</div>' +
					( ctx ? '<div class="ail-opp-context">…' + highlight( ctx, g.anchor_text ) + '…</div>' : '' ) +
					'<div class="ail-opp-edit" data-edit="' + gi + '"><label>Edit the linked phrase</label><input class="ail-input" data-anchor-input="' + gi + '" value="' + attr( g.anchor_text ) + '"></div>' +
					'<div class="ail-cands">' + cands + '</div>' +
					'<div class="ail-opp-tools">' +
						'<button class="ail-link-btn" data-toggle-edit="' + gi + '">' + I.edit + 'Edit phrase</button>' +
						'<button class="ail-link-btn danger" data-dismiss="' + gi + '">' + I.x + 'Dismiss</button>' +
					'</div>' +
				'</div>' +
			'</div></div>';
	}

	function bindGroupEvents() {
		var body = qs( '#dr-body' );
		qsa( '[data-check]', body ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var gi = cb.getAttribute( 'data-check' );
				App.drawer.state[ gi ].selected = cb.checked;
				cb.closest( '.ail-opp' ).classList.toggle( 'is-selected', cb.checked );
				updateFooter();
			} );
		} );
		qsa( '.ail-cand', body ).forEach( function ( c ) {
			c.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var gi = c.getAttribute( 'data-gi' ), cid = parseInt( c.getAttribute( 'data-cid' ), 10 );
				App.drawer.state[ gi ].chosen = cid;
				qsa( '.ail-cand[data-gi="' + gi + '"]', body ).forEach( function ( x ) {
					var on = x.getAttribute( 'data-cid' ) == cid;
					x.classList.toggle( 'is-chosen', on );
					var radio = x.querySelector( '.ail-radio' ); if ( radio ) { radio.checked = on; }
				} );
			} );
		} );
		qsa( '[data-toggle-edit]', body ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var gi = b.getAttribute( 'data-toggle-edit' );
				var box = qs( '[data-edit="' + gi + '"]', body );
				box.classList.toggle( 'is-open' );
			} );
		} );
		qsa( '[data-anchor-input]', body ).forEach( function ( inp ) {
			inp.addEventListener( 'input', function () {
				App.drawer.state[ inp.getAttribute( 'data-anchor-input' ) ].anchor = inp.value;
			} );
		} );
		qsa( '[data-dismiss]', body ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var gi = b.getAttribute( 'data-dismiss' );
				var g = App.drawer.groups[ gi ];
				( g.candidates || [] ).forEach( function ( c ) { api( '/opportunities/status', { method: 'POST', body: { id: c.id, status: 'ignored' } } ); } );
				var card = b.closest( '.ail-opp' ); if ( card ) { card.style.display = 'none'; }
				App.drawer.state[ gi ].selected = false; App.drawer.state[ gi ].dismissed = true;
				updateFooter();
			} );
		} );
	}

	function ensureFooter() {
		var d = qs( '#ail-drawer' );
		if ( qs( '.ail-drawer-foot', d ) ) { return; }
		var foot = document.createElement( 'div' );
		foot.className = 'ail-drawer-foot';
		foot.innerHTML = '<label class="ail-flex" style="font-size:12.5px;cursor:pointer"><input type="checkbox" id="dr-all"> Select all</label>' +
			'<div class="ail-flex"><span class="ail-sel-info" id="dr-info">0 selected</span>' +
			'<button class="ail-btn ail-btn-primary" id="dr-apply" disabled>' + I.link + 'Apply links</button></div>';
		d.appendChild( foot );
		qs( '#dr-all' ).addEventListener( 'change', function ( e ) {
			App.drawer.groups.forEach( function ( g, gi ) {
				if ( App.drawer.state[ gi ].dismissed ) { return; }
				App.drawer.state[ gi ].selected = e.target.checked;
			} );
			qsa( '[data-check]', d ).forEach( function ( cb ) {
				if ( cb.closest( '.ail-opp' ).style.display === 'none' ) { return; }
				cb.checked = e.target.checked;
				cb.closest( '.ail-opp' ).classList.toggle( 'is-selected', e.target.checked );
			} );
			updateFooter();
		} );
		qs( '#dr-apply' ).addEventListener( 'click', applySelected );
	}

	function selectedIndexes() {
		var out = [];
		App.drawer.groups.forEach( function ( g, gi ) {
			if ( App.drawer.state[ gi ] && App.drawer.state[ gi ].selected && ! App.drawer.state[ gi ].dismissed ) { out.push( gi ); }
		} );
		return out;
	}

	function updateFooter() {
		var sel = selectedIndexes();
		var info = qs( '#dr-info' ), apply = qs( '#dr-apply' );
		if ( info ) { info.textContent = sel.length + ' selected'; }
		if ( apply ) { apply.disabled = sel.length === 0; apply.innerHTML = I.link + ( sel.length > 1 ? 'Apply ' + sel.length + ' links' : 'Apply link' ); }
	}

	function applySelected() {
		var sel = selectedIndexes();
		if ( ! sel.length ) { return; }
		var items = sel.map( function ( gi ) {
			var g = App.drawer.groups[ gi ], st = App.drawer.state[ gi ];
			var cand = null;
			g.candidates.forEach( function ( c ) { if ( c.id === st.chosen ) { cand = c; } } );
			if ( ! cand ) { cand = g.candidates[ 0 ]; }
			return {
				opportunity_id: cand.id,
				anchor_text: ( st.anchor || g.anchor_text ).trim(),
				original_anchor: g.anchor_text,
				target_url: cand.target_url,
				target_post_id: cand.target_post_id,
				source_post_id: cand.source_post_id || 0
			};
		} );

		var apply = qs( '#dr-apply' );
		apply.disabled = true; apply.innerHTML = '<span class="ail-spinner" style="width:14px;height:14px;border-top-color:#fff;border-color:rgba(255,255,255,.4);border-top-color:#fff"></span> Applying…';

		api( '/opportunities/apply', { method: 'POST', body: { post_id: App.drawer.postId, direction: App.drawer.direction, items: items } } ).then( function ( r ) {
			if ( r.applied > 0 ) { toast( r.applied + ' internal link' + ( r.applied > 1 ? 's' : '' ) + ' applied.', 'ok', 'Links applied' ); }
			if ( r.failed && r.failed.length ) { r.failed.forEach( function ( f ) { toast( f, 'warn' ); } ); }
			renderOpportunities( r.remaining, { stored: true } );
			if ( App.route === 'pages' ) { loadPages(); }
		} ).catch( function ( e ) {
			toast( e.message, 'err', 'Apply failed' );
			updateFooter();
		} );
	}

	/* ================================================================ *
	 *  APPLIED LINKS
	 * ================================================================ */
	function viewLinks() {
		App.view.innerHTML = pageHead( 'Applied Links', 'Every internal link this plugin has added. Remove one to unwrap it from the page.', '' ) +
			'<div id="l-body">' + loadingBlock() + '</div>';
		api( '/links' ).then( function ( res ) {
			if ( ! res.rows.length ) {
				qs( '#l-body' ).innerHTML = emptyState( I.link, 'No links applied yet', 'When you apply opportunities from the Pages tab, they\'ll be logged here so you can review or undo them.' );
				return;
			}
			var rows = res.rows.map( function ( l ) {
				var active = l.status === 'active';
				return '<tr>' +
					'<td><span class="ail-page-title">' + esc( l.source_title || ( '#' + l.source_post_id ) ) + '</span></td>' +
					'<td><span class="ail-anchor-chip" style="font-size:12px">' + esc( l.anchor_text ) + '</span></td>' +
					'<td><a href="' + attr( l.target_url ) + '" target="_blank" rel="noopener" class="ail-page-url" style="max-width:280px">' + esc( ( l.target_url || '' ).replace( /^https?:\/\//, '' ) ) + '</a></td>' +
					'<td>' + ( active ? '<span class="ail-badge badge-green">Active</span>' : '<span class="ail-badge badge-gray">Removed</span>' ) + '</td>' +
					'<td class="ail-muted" style="font-size:12px">' + timeAgo( l.applied_at ) + '</td>' +
					'<td class="ail-row-actions">' + ( active ? '<button class="ail-btn ail-btn-sm ail-btn-danger" data-unlink="' + l.id + '">' + I.unlink + 'Remove</button>' : '' ) + '</td>' +
					'</tr>';
			} ).join( '' );
			qs( '#l-body' ).innerHTML = '<div class="ail-table-wrap"><table class="ail-table"><thead><tr><th>From page</th><th>Anchor</th><th>Links to</th><th>Status</th><th>Applied</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>';
			qsa( '[data-unlink]' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					confirmModal( { title: 'Remove this link?', message: 'The anchor will be unwrapped and the page updated. The text stays; only the link is removed.', confirm: 'Remove link', danger: true } ).then( function ( ok ) {
						if ( ! ok ) { return; }
						api( '/links/remove', { method: 'POST', body: { id: parseInt( b.getAttribute( 'data-unlink' ), 10 ) } } ).then( function () {
							toast( 'Link removed.', 'ok' ); viewLinks();
						} ).catch( function ( e ) { toast( e.message, 'err' ); } );
					} );
				} );
			} );
		} ).catch( function ( e ) { qs( '#l-body' ).innerHTML = errorBlock( e ); } );
	}

	/* ================================================================ *
	 *  AUDIT
	 * ================================================================ */
	var auditFilter = 'all';
	function viewAudit() {
		App.view.innerHTML = pageHead( 'Internal Linking Audit', 'AI-assisted analysis of your internal link structure against best practices.',
			'<button class="ail-btn ail-btn-primary" id="a-run">' + I.audit + 'Run audit</button>' ) +
			'<div id="a-body">' + loadingBlock() + '</div>';
		qs( '#a-run' ).addEventListener( 'click', runAudit );
		loadAudit();
	}

	function loadAudit() {
		api( '/audit' ).then( function ( res ) { renderAudit( res ); } ).catch( function ( e ) { qs( '#a-body' ).innerHTML = errorBlock( e ); } );
	}

	function runAudit() {
		var btn = qs( '#a-run' );
		if ( btn ) { btn.disabled = true; btn.innerHTML = '<span class="ail-spinner" style="width:14px;height:14px"></span> Auditing…'; }
		qs( '#a-body' ).innerHTML = '<div class="ail-card ail-card-pad"><div class="ail-loadstate"><div class="ail-orbit"></div><h4>Auditing your internal links</h4><p>Building the link graph and asking the AI for guidance…</p></div></div>';
		api( '/audit/run', { method: 'POST', body: { with_ai: true } } ).then( function ( res ) {
			toast( 'Audit complete — score ' + res.score + '/100.', 'ok' );
			loadAudit();
		} ).catch( function ( e ) { toast( e.message, 'err', 'Audit failed' ); loadAudit(); } )
		.finally( function () { if ( btn ) { btn.disabled = false; btn.innerHTML = I.audit + 'Run audit'; } } );
	}

	function sevMeta( sev ) {
		if ( sev === 'critical' ) { return { cls: 'ico-red', icon: I.alert, badge: 'badge-red' }; }
		if ( sev === 'warning' ) { return { cls: 'ico-amber', icon: I.alert, badge: 'badge-amber' }; }
		return { cls: 'ico-blue', icon: I.info, badge: 'badge-blue' };
	}

	function renderAudit( res ) {
		var body = qs( '#a-body' );
		if ( ! res.run_id || ! res.issues ) {
			body.innerHTML = emptyState( I.audit, 'No audit yet', 'Run an audit to scan your whole site for orphan pages, anchor dilution, over-optimisation and more.', '<button class="ail-btn ail-btn-primary" id="a-empty-run">' + I.audit + 'Run first audit</button>' );
			var b = qs( '#a-empty-run' ); if ( b ) { b.addEventListener( 'click', runAudit ); }
			return;
		}
		var c = res.counts || { critical: 0, warning: 0, info: 0, total: res.issues.length };
		var narrative = res.narrative ? '<div class="ail-card"><div class="ail-card-head"><h3>' + I.sparkle + ' AI summary</h3></div><div class="ail-card-pad"><div class="ail-narrative">' + esc( res.narrative ) + '</div></div></div>'
			: '<div class="ail-card"><div class="ail-card-head"><h3>Findings</h3><p>Deterministic checks across your link graph</p></div><div class="ail-card-pad ail-muted" style="font-size:13px">Connect the n8n audit webhook in Settings to get an AI-written summary and prioritised recommendations.</div></div>';

		var top = '<div class="ail-audit-top"><div class="ail-card ail-score-card">' + scoreRing( res.score ) +
			'<div style="font-size:12.5px;color:var(--ail-text-soft);margin-top:8px">' + ( res.at ? 'Audited ' + timeAgo( res.at ) : '' ) + '</div></div>' + narrative + '</div>';

		var tabs = '<div class="ail-sev-tabs">' +
			tabBtn( 'all', 'All', c.total ) +
			tabBtn( 'critical', 'Critical', c.critical || 0 ) +
			tabBtn( 'warning', 'Warnings', c.warning || 0 ) +
			tabBtn( 'info', 'Suggestions', c.info || 0 ) + '</div>';

		var list = res.issues.filter( function ( i ) { return auditFilter === 'all' || i.severity === auditFilter; } ).map( function ( i ) {
			var m = sevMeta( i.severity );
			var data = i.data || {}, action = data.action || 'review';
			var labels = { add_inbound: 'Add inbound links', add_outbound: 'Add outbound links', remove: 'Remove', replace: 'Replace', edit: 'Edit', review: 'Review', reconcile: 'Reconcile' };
			var actions = '<span class="ail-badge badge-gray">' + esc( labels[ action ] || 'Review' ) + '</span>';
			if ( ( action === 'add_inbound' || action === 'add_outbound' ) && i.post_id ) {
				actions += '<button class="ail-btn ail-btn-sm" data-a-find="' + i.post_id + '" data-a-dir="' + ( action === 'add_inbound' ? 'inbound' : 'outbound' ) + '" data-a-title="' + attr( i.post_title || '' ) + '">' + I.sparkle + 'Find links</button>';
			}
			if ( action === 'remove' && data.managed && data.link_id ) {
				actions += '<button class="ail-btn ail-btn-sm ail-btn-danger" data-a-remove="' + data.link_id + '">' + I.unlink + 'Remove</button>';
			}
			if ( i.edit_link ) { actions += '<a class="ail-btn ail-btn-sm" href="' + attr( i.edit_link ) + '" target="_blank" rel="noopener">' + I.ext + 'Edit page</a>'; }
			return '<div class="ail-issue"><div class="ail-issue-ico ' + m.cls + '">' + m.icon + '</div>' +
				'<div class="ail-issue-body"><h5>' + esc( i.title ) + '</h5><p>' + esc( i.message ) + '</p>' +
				'<div class="ail-issue-meta ail-muted"><span class="ail-badge ' + m.badge + '">' + esc( i.issue_type.replace( /_/g, ' ' ) ) + '</span>' + actions + '</div></div></div>';
		} ).join( '' );

		if ( ! list ) { list = '<div class="ail-empty" style="padding:40px"><div class="ail-empty-ico">' + I.check + '</div><h4>Nothing here</h4><p>No ' + esc( auditFilter ) + ' issues found.</p></div>'; }

		body.innerHTML = top + tabs + '<div class="ail-table-wrap">' + list + '</div>';
		qsa( '.ail-sev-tab' ).forEach( function ( t ) {
			t.addEventListener( 'click', function () { auditFilter = t.getAttribute( 'data-sev' ); renderAudit( res ); } );
		} );
		qsa( '[data-a-find]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { openOppDrawer( parseInt( b.getAttribute( 'data-a-find' ), 10 ), b.getAttribute( 'data-a-title' ), { run: true, direction: b.getAttribute( 'data-a-dir' ) } ); } );
		} );
		qsa( '[data-a-remove]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { confirmModal( { title: 'Remove this link?', message: 'The text will remain and only the plugin-applied link will be removed.', confirm: 'Remove link', danger: true } ).then( function ( ok ) { if ( ! ok ) { return; } api( '/links/remove', { method: 'POST', body: { id: parseInt( b.getAttribute( 'data-a-remove' ), 10 ) } } ).then( function () { toast( 'Link removed.', 'ok' ); runAudit(); } ).catch( function ( e ) { toast( e.message, 'err' ); } ); } ); } );
		} );
	}
	function tabBtn( sev, label, count ) {
		return '<button class="ail-sev-tab' + ( auditFilter === sev ? ' is-active' : '' ) + '" data-sev="' + sev + '">' + esc( label ) + ' <span class="ail-badge badge-gray">' + count + '</span></button>';
	}

	/* ================================================================ *
	 *  SETTINGS
	 * ================================================================ */
	function viewSettings() {
		App.view.innerHTML = pageHead( 'Settings', 'Connect your n8n + Ollama workflow and tune how links are generated.', '' ) +
			'<div id="s-body">' + loadingBlock() + '</div>';
		api( '/settings' ).then( function ( res ) { renderSettings( res.settings, res.post_types ); } ).catch( function ( e ) { qs( '#s-body' ).innerHTML = errorBlock( e ); } );
	}

	function renderSettings( s, types ) {
		var typeChecks = types.map( function ( t ) {
			var on = ( s.post_types || [] ).indexOf( t.name ) > -1;
			return '<label class="ail-chip-check' + ( on ? ' is-on' : '' ) + '"><input type="checkbox" value="' + attr( t.name ) + '"' + ( on ? ' checked' : '' ) + ' data-pt> ' + esc( t.label ) + '</label>';
		} ).join( '' );

		var downloadHref = CFG.downloadWorkflowUrl + '&_wpnonce=' + encodeURIComponent( CFG.downloadWorkflowNonce );

		qs( '#s-body' ).innerHTML =
			'<form class="ail-form" id="s-form">' +
			'<div class="ail-card"><div class="ail-card-head"><h3>n8n workflow</h3><p>Where the plugin sends content for AI analysis</p></div><div class="ail-card-pad">' +
				field( 'Find opportunities webhook URL', '<input class="ail-input" name="n8n_find_url" value="' + attr( s.n8n_find_url ) + '" placeholder="https://your-n8n-domain/webhook/ai-internal-linking">', 'The production webhook URL of the "AIL Webhook" node in the n8n workflow (both routes share one URL).' ) +
				'<div class="ail-inline-test"><button type="button" class="ail-btn ail-btn-sm" id="s-test">Test connection</button><span id="s-test-res" class="ail-muted" style="font-size:12px"></span></div>' +
				field( 'Audit webhook URL', '<input class="ail-input" name="n8n_audit_url" value="' + attr( s.n8n_audit_url ) + '" placeholder="https://your-n8n-domain/webhook/ai-internal-linking">', 'Optional. Same webhook URL as above — enables an AI-written narrative on the Audit page.' ) +
				field( 'Shared secret', '<input class="ail-input" name="shared_secret" value="' + attr( s.shared_secret ) + '" placeholder="Used to sign requests (HMAC)">', 'Sent as an HMAC signature in the X-AIL-Signature header so n8n can verify requests. Leave the masked value to keep the current secret.' ) +
				'<div class="ail-field"><label>n8n workflow file</label><a class="ail-btn ail-btn-sm" href="' + attr( downloadHref ) + '">' + I.sync + 'Download workflow JSON</a>' +
				'<div class="ail-help">Import this into n8n (Workflows → Import from File), point the Ollama node at your Ollama credential, and activate it. See the bundled n8n/README.md for step-by-step setup.</div></div>' +
			'</div></div>' +

			'<div class="ail-card ail-mt-16"><div class="ail-card-head"><h3>Model</h3><p>The exact Ollama model name sent to n8n</p></div><div class="ail-card-pad">' +
				field( 'Ollama model', '<input class="ail-input" name="ollama_model" value="' + attr( s.ollama_model ) + '" placeholder="e.g. llama3.1:8b or deepseek-v4-pro:cloud">', 'Type the exact model name your n8n Ollama credential can run. For Ollama Cloud, include the :cloud tag yourself, for example deepseek-v4-pro:cloud.' ) +
			'</div></div>' +

			'<div class="ail-card ail-mt-16"><div class="ail-card-head"><h3>Indexing</h3><p>What content the AI sees</p></div><div class="ail-card-pad">' +
				field( 'Post types to index', '<div class="ail-checks">' + typeChecks + '</div>', 'Only published items of these types are indexed and considered as link targets.' ) +
			'</div></div>' +

			'<div class="ail-card ail-mt-16"><div class="ail-card-head"><h3>Linking rules</h3><p>Guardrails applied to every suggestion</p></div><div class="ail-card-pad">' +
				'<div class="ail-field-row">' +
					'<div>' + field( 'Max links per page', '<input class="ail-input" type="number" min="1" max="50" name="max_links" value="' + attr( s.max_links ) + '">', '' ) + '</div>' +
					'<div>' + field( 'Minimum relevance score', '<input class="ail-input" type="number" min="0" max="1" step="0.05" name="min_score" value="' + attr( s.min_score ) + '">', '0–1. Higher = stricter.' ) + '</div>' +
				'</div>' +
				'<div class="ail-field-row">' +
					'<div>' + field( 'Open links in', '<select class="ail-select" name="link_target" style="width:100%"><option value="">Same tab</option><option value="_blank"' + ( s.link_target === '_blank' ? ' selected' : '' ) + '>New tab</option></select>', '' ) + '</div>' +
					'<div>' + field( 'Link rel attribute', '<input class="ail-input" name="link_rel" value="' + attr( s.link_rel ) + '" placeholder="(none)">', 'e.g. leave blank for internal links.' ) + '</div>' +
				'</div>' +
				field( 'Request timeout (seconds)', '<input class="ail-input" type="number" min="10" max="300" name="request_timeout" value="' + attr( s.request_timeout ) + '">', 'How long to wait for n8n/Ollama before giving up.' ) +
			'</div></div>' +

			'<div class="ail-card ail-mt-16"><div class="ail-card-head"><h3>Uninstall behavior</h3><p>Controls what happens if the plugin is deleted from WordPress</p></div><div class="ail-card-pad">' +
				'<label class="ail-chip-check' + ( s.delete_on_uninstall ? ' is-on' : '' ) + '"><input type="checkbox" name="delete_on_uninstall" value="1"' + ( s.delete_on_uninstall ? ' checked' : '' ) + '> Delete all plugin tables and settings on uninstall</label>' +
				'<div class="ail-help ail-mt-8">Leave this unchecked if you might delete/reinstall the plugin during updates or testing. When checked, WordPress uninstall will permanently remove the content index, opportunities, applied-link records, audit results, activity log, and plugin settings.</div>' +
			'</div></div>' +

			'<div class="ail-flex ail-mt-16"><button type="submit" class="ail-btn ail-btn-primary">' + I.check + 'Save settings</button></div>' +
			'</form>';

		qsa( '.ail-chip-check input' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () { cb.closest( '.ail-chip-check' ).classList.toggle( 'is-on', cb.checked ); } );
		} );
		qs( '#s-test' ).addEventListener( 'click', function () {
			var url = qs( '[name="n8n_find_url"]' ).value;
			var out = qs( '#s-test-res' ); out.textContent = 'Testing…'; out.style.color = '';
			api( '/test-connection', { method: 'POST', body: { url: url } } ).then( function ( r ) {
				out.textContent = r.ok ? '✓ ' + r.message : '✗ ' + r.message;
				out.style.color = r.ok ? 'var(--ail-green)' : 'var(--ail-red)';
			} ).catch( function ( e ) { out.textContent = '✗ ' + e.message; out.style.color = 'var(--ail-red)'; } );
		} );

		qs( '#s-form' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var f = e.target;
			var body = {
				n8n_find_url: f.n8n_find_url.value,
				n8n_audit_url: f.n8n_audit_url.value,
				ollama_model: f.ollama_model.value.trim(),
				max_links: f.max_links.value,
				min_score: f.min_score.value,
				request_timeout: f.request_timeout.value,
				link_target: f.link_target.value,
				link_rel: f.link_rel.value,
				delete_on_uninstall: f.delete_on_uninstall.checked ? 1 : 0,
				post_types: qsa( '.ail-chip-check input[data-pt]' ).filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } )
			};
			if ( f.shared_secret.value.indexOf( '•' ) === -1 ) { body.shared_secret = f.shared_secret.value; }
			api( '/settings', { method: 'POST', body: body } ).then( function () { toast( 'Settings saved.', 'ok' ); } ).catch( function ( er ) { toast( er.message, 'err' ); } );
		} );
	}

	function field( label, control, help ) {
		return '<div class="ail-field"><label>' + esc( label ) + '</label>' + control + ( help ? '<div class="ail-help">' + esc( help ) + '</div>' : '' ) + '</div>';
	}

	/* ================================================================ *
	 *  TOOLS / MAINTENANCE
	 * ================================================================ */
	function viewTools() {
		App.view.innerHTML = pageHead( 'Tools', 'Keep the plugin\'s data tidy. Nothing here touches your posts unless stated.', '' ) +
			'<div class="ail-card"><div class="ail-card-head"><h3>Maintenance</h3><p>Housekeeping for the plugin\'s own tables</p></div>' +
				tool( 'prune_orphans', 'Reconcile index', 'Re-check every page, update link counts and remove rows for deleted or unpublished posts.', 'Reconcile', false ) +
				tool( 'clear_opportunities', 'Clear stored opportunities', 'Delete all unapplied AI suggestions. Applied links are kept.', 'Clear', false ) +
				tool( 'clear_audit', 'Clear audit results', 'Remove the latest audit run and its findings.', 'Clear', false ) +
				tool( 'clear_log', 'Clear activity log', 'Empty the activity feed.', 'Clear', false ) +
			'</div>' +
			'<div class="ail-card ail-danger-zone ail-mt-16"><div class="ail-card-head"><h3>Danger zone</h3><p>Destructive — use with care</p></div>' +
				tool( 'clear_index', 'Clear content index', 'Remove every indexed page. Your posts are untouched; run a full sync to rebuild.', 'Clear index', true ) +
				tool( 'reset_all', 'Reset all plugin data', 'Drop and recreate every plugin table (index, opportunities, links, audit, log). Your posts and applied links inside content are untouched, but plugin records are wiped.', 'Reset everything', true ) +
			'</div>';

		qsa( '[data-tool]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var action = b.getAttribute( 'data-tool' );
				var danger = b.getAttribute( 'data-danger' ) === '1';
				confirmModal( { title: 'Are you sure?', message: b.getAttribute( 'data-confirm-msg' ), confirm: b.textContent, danger: danger } ).then( function ( ok ) {
					if ( ! ok ) { return; }
					b.disabled = true;
					api( '/maintenance', { method: 'POST', body: { action: action } } ).then( function ( r ) {
						toast( r.message, 'ok' );
					} ).catch( function ( e ) { toast( e.message, 'err' ); } ).finally( function () { b.disabled = false; } );
				} );
			} );
		} );
	}
	function tool( action, title, desc, btn, danger ) {
		return '<div class="ail-tool"><div class="ail-tool-info"><h4>' + esc( title ) + '</h4><p>' + esc( desc ) + '</p></div>' +
			'<button class="ail-btn ' + ( danger ? 'ail-btn-danger' : '' ) + '" data-tool="' + action + '" data-danger="' + ( danger ? 1 : 0 ) + '" data-confirm-msg="' + attr( desc ) + '">' + esc( btn ) + '</button></div>';
	}

	/* ================================================================ *
	 *  Boot
	 * ================================================================ */
	function boot() {
		App.root = qs( '#ail-app' );
		if ( ! App.root ) { return; }
		var hash = ( location.hash || '' ).replace( '#/', '' );
		if ( hash && NAV.some( function ( n ) { return n.id === hash; } ) ) { App.route = hash; }
		renderShell();
		render();
		window.addEventListener( 'hashchange', function () {
			var h = ( location.hash || '' ).replace( '#/', '' );
			if ( h && h !== App.route && NAV.some( function ( n ) { return n.id === h; } ) ) { App.route = h; setActiveNav(); render(); }
		} );
	}

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
} )();
