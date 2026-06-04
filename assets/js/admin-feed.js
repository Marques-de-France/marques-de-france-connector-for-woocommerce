/* global mdfcforwcFeed */
/**
 * MDF WooCommerce – Product Feed admin page
 *
 * Vanilla JS (no build step). Rendered on the "Flux de produits" admin page
 * via PHP-generated HTML. Requires the mdfcforwcFeed global set by wp_localize_script.
 */
( function () {
	'use strict';

	const cfg      = window.mdfcforwcFeed;
	const BASE_URL = cfg.restUrl; // e.g. https://example.com/wp-json/mdfcforwc/v1/
	const NONCE    = cfg.nonce;
	const PER_PAGE = 25;

	// ── State ──────────────────────────────────────────────────────────────────

	const state = {
		mode:            cfg.filterMode, // 'TAG' | 'SERVERLIST'
		products:        [],
		total:           0,
		totalPages:      1,
		currentPage:     1,
		currentSearch:   '',
		inFeedCount:     0,

		// Manage mode (SERVERLIST product selection)
		manageMode:       false,
		allProducts:      [],
		manageTotal:      0,
		manageTotalPages: 1,
		managePage:       1,
		manageSearch:     '',
	};

	// ── Helpers ────────────────────────────────────────────────────────────────

	/** Minimal HTML escaping to prevent XSS. */
	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Performs an authenticated REST API request.
	 *
	 * @param {string}  method  HTTP method.
	 * @param {string}  path    Path relative to BASE_URL (e.g. 'admin/products').
	 * @param {Object=} body    Optional request body (JSON-serialised).
	 * @returns {Promise<Object>}
	 */
	async function apiFetch( method, path, body ) {
		const opts = {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   NONCE,
			},
		};
		if ( body !== undefined ) {
			opts.body = JSON.stringify( body );
		}
		const res = await fetch( BASE_URL + path, opts );
		if ( ! res.ok ) {
			const err    = new Error( 'HTTP ' + res.status );
			err.status   = res.status;
			throw err;
		}
		return res.json();
	}

	function spinner() {
		return '<span class="spinner is-active" style="float:none;margin:0 8px -4px 0"></span>';
	}

	function availabilityBadge( availability ) {
		if ( 'in stock' === availability ) {
			return '<span class="mdf-badge mdf-badge-green">En stock</span>';
		}
		if ( 'out of stock' === availability ) {
			return '<span class="mdf-badge mdf-badge-red">Rupture de stock</span>';
		}
		return '<span class="mdf-badge mdf-badge-gray">' + esc( availability ) + '</span>';
	}

	// ── Data loaders ──────────────────────────────────────────────────────────

	async function loadProducts() {
		const root   = document.getElementById( 'mdf-feed-root' );
		root.innerHTML = '<div style="padding:20px">' + spinner() + ' Chargement&hellip;</div>';

		const params = new URLSearchParams( {
			page:     state.currentPage,
			per_page: PER_PAGE,
		} );
		if ( state.currentSearch ) {
			params.set( 'search', state.currentSearch );
		}

		try {
			const data       = await apiFetch( 'GET', 'admin/products?' + params );
			state.products   = data.products   || [];
			state.total      = data.total       || 0;
			state.totalPages = data.total_pages || 1;
			// inFeedCount is returned in SERVERLIST mode; fall back to total for TAG mode
			state.inFeedCount = data.inFeedCount !== undefined ? data.inFeedCount : state.total;
		} catch ( err ) {
			root.innerHTML = '<div class="notice notice-error inline"><p>Erreur lors du chargement des produits.</p></div>';
			return;
		}

		renderFeedView();
	}

	async function loadAllProducts() {
		const section    = document.getElementById( 'mdf-manage-section' );
		section.innerHTML = '<div style="padding:20px">' + spinner() + ' Chargement&hellip;</div>';

		const params = new URLSearchParams( {
			page:     state.managePage,
			per_page: PER_PAGE,
		} );
		if ( state.manageSearch ) {
			params.set( 'search', state.manageSearch );
		}

		try {
			const data            = await apiFetch( 'GET', 'admin/all-products?' + params );
			state.allProducts      = data.products    || [];
			state.manageTotal      = data.total        || 0;
			state.manageTotalPages = data.total_pages  || 1;
			state.inFeedCount      = data.inFeedCount  || 0;
		} catch ( err ) {
			section.innerHTML = '<div class="notice notice-error inline"><p>Erreur lors du chargement des produits.</p></div>';
			return;
		}

		renderManageView();
	}

	// ── Actions ────────────────────────────────────────────────────────────────

	async function switchMode( newMode ) {
		const root     = document.getElementById( 'mdf-feed-root' );
		root.innerHTML = '<div style="padding:20px">' + spinner() + ' Mise &agrave; jour&hellip;</div>';

		try {
			await apiFetch( 'PATCH', 'admin/feed-settings', { feedFilterMode: newMode } );
			state.mode          = newMode;
			state.currentPage   = 1;
			state.currentSearch = '';
			await loadProducts();
		} catch ( err ) {
			root.innerHTML = '<div class="notice notice-error inline"><p>Erreur lors du changement de mode.</p></div>';
		}
	}

	async function toggleFeedProduct( productId, currentlyInFeed ) {
		// Optimistic update
		const product = state.allProducts.find( function ( p ) { return p.id === productId; } );
		if ( product ) {
			product.inFeed    = ! currentlyInFeed;
			state.inFeedCount += currentlyInFeed ? -1 : 1;
		}
		renderManageView();

		try {
			if ( currentlyInFeed ) {
				await apiFetch( 'DELETE', 'admin/feed-products/' + productId );
			} else {
				await apiFetch( 'POST', 'admin/feed-products', { productId: productId } );
			}
		} catch ( err ) {
			// Revert optimistic update on failure
			if ( product ) {
				product.inFeed    = currentlyInFeed;
				state.inFeedCount += currentlyInFeed ? 1 : -1;
			}
			renderManageView();
		}
	}

	function enterManageMode() {
		state.manageMode   = true;
		state.managePage   = 1;
		state.manageSearch = '';
		document.getElementById( 'mdf-feed-root' ).style.display     = 'none';
		document.getElementById( 'mdf-manage-section' ).style.display = '';
		loadAllProducts();
	}

	function exitManageMode() {
		state.manageMode = false;
		document.getElementById( 'mdf-manage-section' ).style.display = 'none';
		document.getElementById( 'mdf-feed-root' ).style.display      = '';
		state.currentPage   = 1;
		state.currentSearch = '';
		loadProducts();
	}

	// ── Pagination helper ──────────────────────────────────────────────────────

	function paginationHtml( total, totalPages, currentPage, scope ) {
		if ( totalPages <= 1 ) {
			return '';
		}
		let btns = '';
		for ( let i = 1; i <= totalPages; i++ ) {
			btns += '<button type="button" class="button mdf-page-btn' +
				( i === currentPage ? ' button-primary' : '' ) +
				'" data-page="' + i + '" data-scope="' + scope + '">' + i + '</button> ';
		}
		return '<div class="tablenav bottom"><div class="tablenav-pages">' +
			'<span class="displaying-num">' + total + ' produit' + ( total > 1 ? 's' : '' ) + '</span> ' +
			btns + '</div></div>';
	}

	// ── Renderers ──────────────────────────────────────────────────────────────

	function renderFeedView() {
		const root         = document.getElementById( 'mdf-feed-root' );
		const isServerlist = 'SERVERLIST' === state.mode;
		const count        = state.inFeedCount;

		// ─ Mode selector card ────────────────────────────────────────────────
		const modeLabel = isServerlist
			? 'Via une s\u00e9lection manuelle depuis cette application'
			: 'Via le champ \u00ab\u00a0\u00c9tiquette\u00a0\u00bb des produits (recommand\u00e9)';

		let modeHtml = '<div class="mdf-card">' +
			'<h2>M\u00e9thode de s\u00e9lection</h2>' +
			'<p>M\u00e9thode actuelle\u00a0: <strong>' + esc( modeLabel ) + '</strong></p>';

		if ( isServerlist ) {
			modeHtml += '<p><a href="#" id="mdf-switch-to-tag">Revenir \u00e0 la s\u00e9lection par \u00e9tiquette</a></p>';
		} else {
			modeHtml += '<p class="description">Les \u00e9tiquettes apparaissent sur votre boutique\u00a0? ' +
				'<a href="#" id="mdf-switch-to-serverlist">Utiliser la s\u00e9lection manuelle</a></p>';
		}
		modeHtml += '</div>';

		// ─ Feed URL card ──────────────────────────────────────────────────────
		const feedUrlHtml = cfg.token
			? '<div class="mdf-card"><h2>URL du flux</h2>' +
			  '<p><code>' + esc( cfg.feedUrl + '?token=' + cfg.token ) + '</code></p></div>'
			: '';

		// ─ TAG mode info banner ───────────────────────────────────────────────
		const tagBannerHtml = ! isServerlist
			? '<div class="notice notice-info inline mdf-notice">' +
			  '<p><strong>Comment inclure des produits dans le flux\u00a0?</strong></p>' +
			  '<p>Ajoutez l\u2019\u00e9tiquette <code>marques-de-france</code> aux produits que vous souhaitez int\u00e9grer sur Marques de France. ' +
			  'Les produits s\u00e9lectionn\u00e9s doivent \u00eatre fabriqu\u00e9s en France.</p></div>'
			: '';

		// ─ SERVERLIST active banner ───────────────────────────────────────────
		const serverlistBannerHtml = isServerlist
			? '<div class="notice notice-info inline mdf-notice">' +
			  '<p><strong>S\u00e9lection manuelle active</strong> \u2014 ' +
			  'Vous choisissez directement dans cette application quels produits apparaissent sur Marques de France. ' +
			  'Aucune \u00e9tiquette WooCommerce n\u00e9cessaire.</p>' +
			  '<p><button type="button" class="button button-primary" id="mdf-enter-manage">Modifier la s\u00e9lection</button> ' +
			  '<span class="mdf-in-feed-count">' +
			  count + ' produit' + ( 1 !== count ? 's' : '' ) + ' s\u00e9lectionn\u00e9' + ( 1 !== count ? 's' : '' ) +
			  '</span></p></div>'
			: '';

		// ─ Products table ─────────────────────────────────────────────────────
		let tableHtml;
		if ( 0 === state.products.length ) {
			tableHtml = isServerlist
				? '<div class="mdf-empty"><p><strong>Aucun produit s\u00e9lectionn\u00e9</strong></p>' +
				  '<p>Cliquez sur \u00ab\u00a0Modifier la s\u00e9lection\u00a0\u00bb pour ajouter des produits fabriqu\u00e9s en France au flux.</p></div>'
				: '<div class="mdf-empty"><p><strong>Aucun produit dans le flux</strong></p>' +
				  '<p>Ajoutez l\u2019\u00e9tiquette <code>marques-de-france</code> \u00e0 vos produits fabriqu\u00e9s en France.</p></div>';
		} else {
			const rows = state.products.map( function ( p ) {
				return '<tr>' +
					'<td style="width:50px"><img src="' + esc( p.image ) + '" width="36" height="36" alt="" style="object-fit:cover;border-radius:3px;vertical-align:middle"></td>' +
					'<td><strong><a href="' + esc( p.edit_url ) + '" target="_blank">' + esc( p.name ) + '</a></strong>' +
					( p.brand ? '<br><span class="mdf-brand">' + esc( p.brand ) + '</span>' : '' ) + '</td>' +
					'<td style="width:110px">' + esc( p.price_html || String( p.price ) ) + '</td>' +
					'<td style="width:150px">' + availabilityBadge( p.availability ) + '</td>' +
					'</tr>';
			} ).join( '' );

			tableHtml =
				'<table class="wp-list-table widefat fixed striped posts">' +
				'<thead><tr>' +
				'<th style="width:50px"></th>' +
				'<th>Produit</th>' +
				'<th style="width:110px">Prix</th>' +
				'<th style="width:150px">Disponibilit\u00e9</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
				'</table>';
		}

		const pagination = paginationHtml( state.total, state.totalPages, state.currentPage, 'feed' );

		const countLabel = state.total > 0
			? ' <span class="mdf-count">(' + state.total + ')</span>'
			: '';

		root.innerHTML =
			modeHtml +
			feedUrlHtml +
			tagBannerHtml +
			serverlistBannerHtml +
			'<div class="mdf-card">' +
			'<h2>Produits s\u00e9lectionn\u00e9s dans le flux' + countLabel + '</h2>' +
			tableHtml +
			pagination +
			'</div>';

		// ─ Bind events ────────────────────────────────────────────────────────
		const switchToServerlist = document.getElementById( 'mdf-switch-to-serverlist' );
		if ( switchToServerlist ) {
			switchToServerlist.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( window.confirm(
					'La s\u00e9lection manuelle supprime l\u2019obligation d\u2019utiliser l\u2019\u00e9tiquette \u00ab\u00a0marques-de-france\u00a0\u00bb.\n\n' +
					'Vos produits d\u00e9j\u00e0 balis\u00e9s seront import\u00e9s automatiquement.\n\n' +
					'Vous pouvez revenir en arri\u00e8re \u00e0 tout moment.\n\nPasser en s\u00e9lection manuelle ?'
				) ) {
					switchMode( 'SERVERLIST' );
				}
			} );
		}

		const switchToTag = document.getElementById( 'mdf-switch-to-tag' );
		if ( switchToTag ) {
			switchToTag.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( window.confirm(
					'Vous repassez \u00e0 la m\u00e9thode par \u00e9tiquette \u00ab\u00a0marques-de-france\u00a0\u00bb.\n\n' +
					'Votre liste de s\u00e9lection manuelle est conserv\u00e9e mais n\u2019est plus active.\n\n' +
					'Seuls les produits portant l\u2019\u00e9tiquette \u00ab\u00a0marques-de-france\u00a0\u00bb appara\u00eetront dans le flux.\n\nRevenir \u00e0 la s\u00e9lection par \u00e9tiquette ?'
				) ) {
					switchMode( 'TAG' );
				}
			} );
		}

		const enterManageBtn = document.getElementById( 'mdf-enter-manage' );
		if ( enterManageBtn ) {
			enterManageBtn.addEventListener( 'click', enterManageMode );
		}

		root.querySelectorAll( '.mdf-page-btn[data-scope="feed"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				state.currentPage = parseInt( btn.dataset.page, 10 );
				loadProducts();
			} );
		} );
	}

	function renderManageView() {
		const section = document.getElementById( 'mdf-manage-section' );
		const count   = state.inFeedCount;

		let tableHtml;
		if ( 0 === state.allProducts.length ) {
			tableHtml = '<div class="mdf-empty"><p>Aucun produit trouv\u00e9.</p></div>';
		} else {
			const rows = state.allProducts.map( function ( p ) {
				return '<tr' + ( p.inFeed ? ' class="mdf-row-in-feed"' : '' ) + '>' +
					'<td class="check-column">' +
					'<input type="checkbox" class="mdf-toggle-product"' +
					' data-id="' + p.id + '"' +
					' data-in-feed="' + ( p.inFeed ? '1' : '0' ) + '"' +
					( p.inFeed ? ' checked' : '' ) + '></td>' +
					'<td style="width:50px"><img src="' + esc( p.image ) + '" width="36" height="36" alt="" style="object-fit:cover;border-radius:3px;vertical-align:middle"></td>' +
					'<td><strong><a href="' + esc( p.edit_url ) + '" target="_blank">' + esc( p.name ) + '</a></strong>' +
					( p.brand ? '<br><span class="mdf-brand">' + esc( p.brand ) + '</span>' : '' ) + '</td>' +
					'<td style="width:110px">' + esc( p.price_html || String( p.price ) ) + '</td>' +
					'<td style="width:150px">' + availabilityBadge( p.availability ) + '</td>' +
					'</tr>';
			} ).join( '' );

			tableHtml =
				'<table class="wp-list-table widefat fixed striped posts">' +
				'<thead><tr>' +
				'<th class="check-column" style="width:36px"></th>' +
				'<th style="width:50px"></th>' +
				'<th>Produit</th>' +
				'<th style="width:110px">Prix</th>' +
				'<th style="width:150px">Disponibilit\u00e9</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
				'</table>';
		}

		const pagination = paginationHtml( state.manageTotal, state.manageTotalPages, state.managePage, 'manage' );

		section.innerHTML =
			'<div class="mdf-manage-header">' +
			'<button type="button" class="button" id="mdf-back-btn">\u2190 Retour au flux</button>' +
			'<h2>S\u00e9lectionner des produits pour le flux</h2>' +
			'<p class="description">' +
			count + ' produit' + ( 1 !== count ? 's' : '' ) + ' s\u00e9lectionn\u00e9' + ( 1 !== count ? 's' : '' ) +
			'</p></div>' +
			'<div class="notice notice-info inline mdf-notice">' +
			'<p>S\u00e9lectionnez uniquement les produits fabriqu\u00e9s en France. ' +
			'Ce sont ces produits qui appara\u00eetront dans le guide Marques de France.</p></div>' +
			'<div class="tablenav top"><div class="alignleft actions">' +
			'<input type="search" id="mdf-manage-search-input" value="' + esc( state.manageSearch ) + '" ' +
			'placeholder="Rechercher par produit ou marque" class="regular-text">' +
			' <button type="button" class="button" id="mdf-manage-search-btn">Rechercher</button>' +
			'</div></div>' +
			tableHtml +
			pagination;

		// ─ Bind events ────────────────────────────────────────────────────────
		document.getElementById( 'mdf-back-btn' ).addEventListener( 'click', exitManageMode );

		document.getElementById( 'mdf-manage-search-btn' ).addEventListener( 'click', function () {
			state.manageSearch = document.getElementById( 'mdf-manage-search-input' ).value.trim();
			state.managePage   = 1;
			loadAllProducts();
		} );

		document.getElementById( 'mdf-manage-search-input' ).addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				state.manageSearch = e.target.value.trim();
				state.managePage   = 1;
				loadAllProducts();
			}
		} );

		section.querySelectorAll( '.mdf-toggle-product' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				const id     = parseInt( cb.dataset.id, 10 );
				const inFeed = '1' === cb.dataset.inFeed;
				toggleFeedProduct( id, inFeed );
			} );
		} );

		section.querySelectorAll( '.mdf-page-btn[data-scope="manage"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				state.managePage = parseInt( btn.dataset.page, 10 );
				loadAllProducts();
			} );
		} );
	}

	// ── Init ──────────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function () {
		loadProducts();
	} );

} )();
