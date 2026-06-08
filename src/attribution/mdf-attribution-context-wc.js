/**
 * Marques de France WooCommerce Attribution Context
 *
 * Detects attribution signals on page load (UTM parameters, referrer, landing page),
 * persists them in first-party cookies (60-day TTL, SameSite=Lax), and stamps the
 * WooCommerce session via AJAX so checkout can read them without relying on cookies.
 *
 * Runs on every frontend page load. First-touch attribution: existing cookies / session
 * values are never overwritten.
 *
 * Requires: mdfcforwcRuntime.ajaxUrl, mdfcforwcRuntime.nonce (injected via wp_localize_script)
 */
( function () {
	'use strict';

	var COOKIE_TTL_DAYS = 60;
	var MDF_SOURCE      = 'marques-de-france';

	function getRuntime() {
		return window.mdfcforwcRuntime || window.mdfcforwcConfig || null;
	}

	// -------------------------------------------------------------------------
	// Cookie helpers
	// -------------------------------------------------------------------------

	function setCookie( name, value, days ) {
		if ( ! value ) return;
		var expires = new Date( Date.now() + days * 864e5 ).toUTCString();
		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; expires=' + expires +
			'; path=/; SameSite=Lax';
	}

	function getCookie( name ) {
		var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	// -------------------------------------------------------------------------
	// URL helpers
	// -------------------------------------------------------------------------

	function getParam( params, key ) {
		return params.get( key ) || '';
	}

	// -------------------------------------------------------------------------
	// Main attribution logic
	// -------------------------------------------------------------------------

	function init() {
		var runtime = getRuntime();

		// Skip if runtime config is not available.
		if ( ! runtime || ! runtime.ajaxUrl ) {
			return;
		}

		var params      = new URLSearchParams( window.location.search );
		var utmSource   = getParam( params, 'utm_source' );
		var utmMedium   = getParam( params, 'utm_medium' );
		var utmCampaign = getParam( params, 'utm_campaign' );
		var utmContent  = getParam( params, 'utm_content' );
		var utmTerm     = getParam( params, 'utm_term' );
		var clickId     = getParam( params, 'mdf_click_id' );

		// Only attribute if this visit originates from Marques de France.
		// Signal 1 (utm):      utm_source / utm_medium / utm_campaign contains MDF_SOURCE
		// Signal 2 (ref):      ?ref= or ?landing_ref= contains MDF_SOURCE
		// Signal 3 (referrer): document.referrer contains 'marques-de-france.fr'
		var referrerUrl   = document.referrer || '';
		var refParam      = getParam( params, 'ref' ) || getParam( params, 'landing_ref' );
		var isMdfUtm      = ( utmSource.indexOf( MDF_SOURCE ) !== -1 ) ||
		                    ( utmMedium.indexOf( MDF_SOURCE ) !== -1 ) ||
		                    ( utmCampaign.indexOf( MDF_SOURCE ) !== -1 );
		var isMdfRef      = refParam.indexOf( MDF_SOURCE ) !== -1;
		var isMdfReferrer = referrerUrl.indexOf( 'marques-de-france.fr' ) !== -1;
		var hasClickId    = clickId !== '';

		var isAttributed  = isMdfUtm || isMdfRef || isMdfReferrer || hasClickId;

		if ( runtime.debug === 'true' ) {
			console.log( '[MDF Attribution] utm=' + isMdfUtm + ' ref=' + isMdfRef + ' referrer=' + isMdfReferrer + ' click=' + hasClickId + ' attributed=' + isAttributed );
		}

		// -----------------------------------------------------------------------
		// First-touch: don't overwrite existing attribution cookies.
		// If the visitor already has mdf_attributed=1 we honour it silently.
		// -----------------------------------------------------------------------
		var alreadyAttributed = getCookie( 'mdf_attributed' ) === '1';

		if ( isAttributed && ! alreadyAttributed ) {
			// Landing page = full current URL (before any redirect).
			var landingUrl  = window.location.href;

			// Persist in cookies so they survive page refreshes.
			setCookie( 'mdf_attributed',    '1',         COOKIE_TTL_DAYS );
			setCookie( 'mdf_utm_source',    utmSource,   COOKIE_TTL_DAYS );
			setCookie( 'mdf_utm_medium',    utmMedium,   COOKIE_TTL_DAYS );
			setCookie( 'mdf_utm_campaign',  utmCampaign, COOKIE_TTL_DAYS );
			setCookie( 'mdf_utm_content',   utmContent,  COOKIE_TTL_DAYS );
			setCookie( 'mdf_utm_term',      utmTerm,     COOKIE_TTL_DAYS );
			setCookie( 'mdf_landing_site',  landingUrl,  COOKIE_TTL_DAYS );
			setCookie( 'mdf_referring_site',referrerUrl, COOKIE_TTL_DAYS );
			setCookie( 'mdf_landing_ref',   refParam,    COOKIE_TTL_DAYS );
			setCookie( 'mdf_click_id',      clickId,     COOKIE_TTL_DAYS );

			// Stamp the WooCommerce session via AJAX.
			stampSession( runtime, {
				mdf_attributed:    '1',
				mdf_utm_source:    utmSource,
				mdf_utm_medium:    utmMedium,
				mdf_utm_campaign:  utmCampaign,
				mdf_utm_content:   utmContent,
				mdf_utm_term:      utmTerm,
				mdf_landing_site:  landingUrl,
				mdf_referring_site:referrerUrl,
				mdf_landing_ref:   refParam,
				mdf_click_id:      clickId,
			} );
		} else if ( ! isAttributed && alreadyAttributed ) {
			// Visitor returning without UTMs but cookie is still live.
			// Re-stamp the WC session from existing cookies (safety net for new tabs).
			stampSession( runtime, {
				mdf_attributed:    getCookie( 'mdf_attributed' ),
				mdf_utm_source:    getCookie( 'mdf_utm_source' ),
				mdf_utm_medium:    getCookie( 'mdf_utm_medium' ),
				mdf_utm_campaign:  getCookie( 'mdf_utm_campaign' ),
				mdf_utm_content:   getCookie( 'mdf_utm_content' ),
				mdf_utm_term:      getCookie( 'mdf_utm_term' ),
				mdf_landing_site:  getCookie( 'mdf_landing_site' ),
				mdf_referring_site:getCookie( 'mdf_referring_site' ),
				mdf_landing_ref:   getCookie( 'mdf_landing_ref' ),
				mdf_click_id:      getCookie( 'mdf_click_id' ),
			} );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX session stamp
	// -------------------------------------------------------------------------

	function stampSession( runtime, data ) {
		var body = new URLSearchParams();
		body.set( 'action', runtime.action || 'mdfcforwc_apply_session_context' );
		body.set( 'nonce',  runtime.nonce );

		for ( var key in data ) {
			if ( data[ key ] ) {
				body.set( key, data[ key ] );
			}
		}

		fetch( runtime.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString(),
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( runtime.debug === 'true' ) {
				console.log( '[MDF Attribution] Session stamped:', json );
			}
		} )
		.catch( function ( err ) {
			if ( runtime.debug === 'true' ) {
				console.warn( '[MDF Attribution] Stamp error:', err );
			}
		} );
	}

	// Run after DOM is interactive.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
