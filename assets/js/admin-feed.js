/* global mdfcforwcFeed */
/**
 * MDF WooCommerce – Product Feed admin page
 *
 * Vanilla JS (no build step). Rendered on the "Product feed" admin page
 * via PHP-generated HTML. Requires the mdfcforwcFeed global set by wp_localize_script.
 */
( function () {
	'use strict';

	const cfg      = window.mdfcforwcFeed;
	const BASE_URL = cfg.restUrl; // e.g. https://example.com/wp-json/mdfcforwc/v1/
	const NONCE    = cfg.nonce;
	const PER_PAGE = 25;

	const __t = ( text ) => {
		if ( window.wp && window.wp.i18n && 'function' === typeof window.wp.i18n.__ ) {
			return window.wp.i18n.__( text, 'marques-de-france-connector-for-woocommerce' );
		}
		return text;
	};

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
			return '<span class="mdf-badge mdf-badge-green">' + esc( __t( 'In stock' ) ) + '</span>';
		}
		if ( 'out of stock' === availability ) {
			return '<span class="mdf-badge mdf-badge-red">' + esc( __t( 'Out of stock' ) ) + '</span>';
		}
		return '<span class="mdf-badge mdf-badge-gray">' + esc( availability ) + '</span>';
	}

	// ── Data loaders ──────────────────────────────────────────────────────────

	async function loadProducts() {
		const root   = document.getElementById( 'mdf-feed-root' );
		root.innerHTML = '<div style="padding:20px">' + spinner() + ' ' + esc( __t( 'Loading…' ) ) + '</div>';

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
			root.innerHTML = '<div class="notice notice-error inline"><p>' + esc( __t( 'Failed to load the products.' ) ) + '</p></div>';
			return;
		}

		renderFeedView();
	}

	async function loadAllProducts() {
		const section    = document.getElementById( 'mdf-manage-section' );
		section.innerHTML = '<div style="padding:20px">' + spinner() + ' ' + esc( __t( 'Loading…' ) ) + '</div>';

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
			section.innerHTML = '<div class="notice notice-error inline"><p>' + esc( __t( 'Failed to load the products.' ) ) + '</p></div>';
			return;
		}

		renderManageView();
	}

	// ── Actions ────────────────────────────────────────────────────────────────

	async function switchMode( newMode ) {
		const root     = document.getElementById( 'mdf-feed-root' );
		root.innerHTML = '<div style="padding:20px">' + spinner() + ' ' + esc( __t( 'Updating…' ) ) + '</div>';

		try {
			await apiFetch( 'PATCH', 'admin/feed-settings', { feedFilterMode: newMode } );
			state.mode          = newMode;
			state.currentPage   = 1;
			state.currentSearch = '';
			await loadProducts();
		} catch ( err ) {
			root.innerHTML = '<div class="notice notice-error inline"><p>' + esc( __t( 'Failed to change the feed mode.' ) ) + '</p></div>';
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
			'<span class="displaying-num">' + total + ' ' + ( 1 === total ? __t( 'product' ) : __t( 'products' ) ) + '</span> ' +
			btns + '</div></div>';
	}

	// ── Renderers ──────────────────────────────────────────────────────────────

	function renderFeedView() {
		const root         = document.getElementById( 'mdf-feed-root' );
		const isServerlist = 'SERVERLIST' === state.mode;
		const count        = state.inFeedCount;

		// ─ Mode selector card ────────────────────────────────────────────────
		const modeLabel = isServerlist
			? __t( 'Via manual selection from this app' )
			: __t( 'Via the product tag field (Recommended)' );

		let modeHtml = '<div class="mdf-card">' +
			'<h2>' + esc( __t( 'Selection method' ) ) + '</h2>' +
			'<p>' + esc( __t( 'Current method:' ) ) + ' <strong>' + esc( modeLabel ) + '</strong></p>';

		if ( isServerlist ) {
			modeHtml += '<p><a href="#" id="mdf-switch-to-tag">' + esc( __t( 'Switch back to tag-based selection' ) ) + '</a></p>';
		} else {
			modeHtml += '<p class="description">' + esc( __t( 'Do your tags appear on your shop?' ) ) + ' ' +
				'<a href="#" id="mdf-switch-to-serverlist">' + esc( __t( 'Use manual selection' ) ) + '</a></p>';
		}
		modeHtml += '</div>';

		// ─ Feed URL card ──────────────────────────────────────────────────────
		const feedUrlHtml = cfg.token
			? '<div class="mdf-card"><h2>' + esc( __t( 'Feed URL' ) ) + '</h2>' +
			  '<p><code>' + esc( cfg.feedUrl + '?token=' + cfg.token ) + '</code></p></div>'
			: '';

		// ─ TAG mode info banner ───────────────────────────────────────────────
		const tagBannerHtml = ! isServerlist
			? '<div class="notice notice-info inline mdf-notice">' +
			  '<p><strong>' + esc( __t( 'How to include products in the feed?' ) ) + '</strong></p>' +
			  '<p>' + esc( __t( 'Add the tag' ) ) + ' <code>marques-de-france</code> ' + esc( __t( 'to the products you want to list on Marques de France. Selected products must be manufactured in France.' ) ) + '</p></div>'
			: '';

		// ─ SERVERLIST active banner ───────────────────────────────────────────
		const serverlistBannerHtml = isServerlist
			? '<div class="notice notice-info inline mdf-notice">' +
			  '<p><strong>' + esc( __t( 'Manual selection is active' ) ) + '</strong> — ' +
			  esc( __t( 'You choose directly in this app which products appear on Marques de France. No WooCommerce tag is required.' ) ) + '</p>' +
			  '<p><button type="button" class="button button-primary" id="mdf-enter-manage">' + esc( __t( 'Modify selection' ) ) + '</button> ' +
			  '<span class="mdf-in-feed-count">' +
			  count + ' ' + ( 1 === count ? esc( __t( 'product' ) ) : esc( __t( 'products' ) ) ) + ' ' + esc( __t( 'selected' ) ) +
			  '</span></p></div>'
			: '';

		// ─ Products table ─────────────────────────────────────────────────────
		let tableHtml;
		if ( 0 === state.products.length ) {
			tableHtml = isServerlist
				? '<div class="mdf-empty"><p><strong>' + esc( __t( 'No products selected' ) ) + '</strong></p>' +
				  '<p>' + esc( __t( 'Click “Modify selection” to add French-made products to the feed.' ) ) + '</p></div>'
				: '<div class="mdf-empty"><p><strong>' + esc( __t( 'No products in the feed' ) ) + '</strong></p>' +
				  '<p>' + esc( __t( 'Add the tag marques-de-france to your French-made products.' ) ) + '</p></div>';
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
				'<th>' + esc( __t( 'Product' ) ) + '</th>' +
				'<th style="width:110px">' + esc( __t( 'Price' ) ) + '</th>' +
				'<th style="width:150px">' + esc( __t( 'Availability' ) ) + '</th>' +
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
			'<h2>' + esc( __t( 'Products selected in the feed' ) ) + countLabel + '</h2>' +
			tableHtml +
			pagination +
			'</div>';

		// ─ Bind events ────────────────────────────────────────────────────────
		const switchToServerlist = document.getElementById( 'mdf-switch-to-serverlist' );
		if ( switchToServerlist ) {
			switchToServerlist.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( window.confirm(
					__t( 'Manual selection removes the requirement to use the marques-de-france tag.\n\nYour already tagged products will be imported automatically.\n\nYou can switch back at any time.\n\nSwitch to manual selection?' )
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
					__t( 'You are switching back to the marques-de-france tag method.\n\nYour manual selection list is kept but is no longer active.\n\nOnly products tagged marques-de-france will appear in the feed.\n\nSwitch back to tag-based selection?' )
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
			tableHtml = '<div class="mdf-empty"><p>' + esc( __t( 'No products found.' ) ) + '</p></div>';
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
				'<th>' + esc( __t( 'Product' ) ) + '</th>' +
				'<th style="width:110px">' + esc( __t( 'Price' ) ) + '</th>' +
				'<th style="width:150px">' + esc( __t( 'Availability' ) ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
				'</table>';
		}

		const pagination = paginationHtml( state.manageTotal, state.manageTotalPages, state.managePage, 'manage' );

		section.innerHTML =
			'<div class="mdf-manage-header">' +
			'<button type="button" class="button" id="mdf-back-btn">← ' + esc( __t( 'Back to feed' ) ) + '</button>' +
			'<h2>' + esc( __t( 'Select products for the feed' ) ) + '</h2>' +
			'<p class="description">' +
			count + ' ' + ( 1 === count ? esc( __t( 'product' ) ) : esc( __t( 'products' ) ) ) + ' ' + esc( __t( 'selected' ) ) +
			'</p></div>' +
			'<div class="notice notice-info inline mdf-notice">' +
			'<p>' + esc( __t( 'Select only French-made products. These are the products that will appear in the Marques de France guide.' ) ) + '</p></div>' +
			'<div class="tablenav top"><div class="alignleft actions">' +
			'<input type="search" id="mdf-manage-search-input" value="' + esc( state.manageSearch ) + '" ' +
			'placeholder="' + esc( __t( 'Search products or brands' ) ) + '" class="regular-text">' +
			' <button type="button" class="button" id="mdf-manage-search-btn">' + esc( __t( 'Search' ) ) + '</button>' +
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
