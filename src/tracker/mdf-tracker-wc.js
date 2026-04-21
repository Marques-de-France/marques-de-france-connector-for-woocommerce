/**
 * Marques de France WooCommerce Tracker
 *
 * Detects attribution signals on page load (UTM parameters, referrer, landing page),
 * persists them in first-party cookies (30-day TTL, SameSite=Lax), and stamps the
 * WooCommerce session via AJAX so checkout can read them without relying on cookies.
 *
 * Runs on every frontend page load. First-touch attribution: existing cookies / session
 * values are never overwritten.
 *
 * Requires: mdfWcConfig.ajaxUrl, mdfWcConfig.nonce (injected via wp_localize_script)
 */
( function () {
	'use strict';

	var COOKIE_TTL_DAYS = 30;
	var UTM_PARAMS      = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ];
	var MDF_SOURCE      = 'marques-de-france';

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
		// Skip if WC config is not available (should not happen, but be safe).
		if ( typeof mdfWcConfig === 'undefined' || ! mdfWcConfig.ajaxUrl ) {
			return;
		}

		var params      = new URLSearchParams( window.location.search );
		var utmSource   = getParam( params, 'utm_source' );
		var utmMedium   = getParam( params, 'utm_medium' );
		var utmCampaign = getParam( params, 'utm_campaign' );
		var utmContent  = getParam( params, 'utm_content' );
		var utmTerm     = getParam( params, 'utm_term' );

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

		var isAttributed  = isMdfUtm || isMdfRef || isMdfReferrer;

		if ( mdfWcConfig.debug === 'true' ) {
			console.log( '[MDF Tracker] utm=' + isMdfUtm + ' ref=' + isMdfRef + ' referrer=' + isMdfReferrer + ' attributed=' + isAttributed );
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
			setCookie( 'mdf_landing_site',  landingUrl,            COOKIE_TTL_DAYS );
			setCookie( 'mdf_referring_site',referrerUrl,           COOKIE_TTL_DAYS );
			setCookie( 'mdf_landing_ref',   refParam || utmSource, COOKIE_TTL_DAYS );

			// Stamp the WooCommerce session via AJAX.
			stampSession( {
				mdf_attributed:    '1',
				mdf_utm_source:    utmSource,
				mdf_utm_medium:    utmMedium,
				mdf_utm_campaign:  utmCampaign,
				mdf_utm_content:   utmContent,
				mdf_utm_term:      utmTerm,
				mdf_landing_site:  landingUrl,
				mdf_referring_site:referrerUrl,
				mdf_landing_ref:   refParam || utmSource,
			} );
		} else if ( ! isAttributed && alreadyAttributed ) {
			// Visitor returning without UTMs but cookie is still live.
			// Re-stamp the WC session from existing cookies (safety net for new tabs).
			stampSession( {
				mdf_attributed:    getCookie( 'mdf_attributed' ),
				mdf_utm_source:    getCookie( 'mdf_utm_source' ),
				mdf_utm_medium:    getCookie( 'mdf_utm_medium' ),
				mdf_utm_campaign:  getCookie( 'mdf_utm_campaign' ),
				mdf_utm_content:   getCookie( 'mdf_utm_content' ),
				mdf_utm_term:      getCookie( 'mdf_utm_term' ),
				mdf_landing_site:  getCookie( 'mdf_landing_site' ),
				mdf_referring_site:getCookie( 'mdf_referring_site' ),
				mdf_landing_ref:   getCookie( 'mdf_landing_ref' ),
			} );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX session stamp
	// -------------------------------------------------------------------------

	function stampSession( data ) {
		var body = new URLSearchParams();
		body.set( 'action', 'mdf_stamp_session' );
		body.set( 'nonce',  mdfWcConfig.nonce );

		for ( var key in data ) {
			if ( data[ key ] ) {
				body.set( key, data[ key ] );
			}
		}

		fetch( mdfWcConfig.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString(),
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( mdfWcConfig.debug === 'true' ) {
				console.log( '[MDF Tracker] Session stamped:', json );
			}
		} )
		.catch( function ( err ) {
			if ( mdfWcConfig.debug === 'true' ) {
				console.warn( '[MDF Tracker] Stamp error:', err );
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
