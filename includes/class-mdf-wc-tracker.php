<?php
/**
 * Tracker
 *
 * Enqueues the MDF JS tracker on the frontend and handles AJAX calls
 * that stamp the WooCommerce session with attribution signals.
 *
 * Signal persistence strategy:
 *   1. JS tracker detects attribution on page load (querystring, referrer).
 *   2. JS tracker writes 9 cookies (30-day TTL, SameSite=Lax).
 *   3. On every page load, `template_redirect` captures HTTP_REFERER server-side.
 *   4. AJAX action `mdfcforwc_stamp_session` copies the attribution into the WC session
 *      so checkout can read it without relying solely on cookies.
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Tracker {

	// WC session keys
	const KEY_ATTRIBUTED    = 'mdf_attributed';
	const KEY_UTM_SOURCE    = 'mdf_utm_source';
	const KEY_UTM_MEDIUM    = 'mdf_utm_medium';
	const KEY_UTM_CAMPAIGN  = 'mdf_utm_campaign';
	const KEY_UTM_CONTENT   = 'mdf_utm_content';
	const KEY_UTM_TERM      = 'mdf_utm_term';
	const KEY_LANDING_SITE  = 'mdf_landing_site';
	const KEY_REFERRING     = 'mdf_referring_site';
	const KEY_LANDING_REF   = 'mdf_landing_ref';
	const KEY_CLICK_ID      = 'mdf_click_id';

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init() {
		// Enqueue tracker script on all frontend pages
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker' ] );

		// Server-side referer capture (zero-JS reliability)
		add_action( 'template_redirect', [ $this, 'capture_referer_server_side' ] );

		// AJAX: authenticated + non-authenticated users (works for guests)
		add_action( 'wp_ajax_mdfcforwc_stamp_session',        [ $this, 'ajax_stamp_session' ] );
		add_action( 'wp_ajax_nopriv_mdfcforwc_stamp_session', [ $this, 'ajax_stamp_session' ] );
		add_action( 'wp_ajax_mdfcforwc_apply_session_context',        [ $this, 'ajax_stamp_session' ] );
		add_action( 'wp_ajax_nopriv_mdfcforwc_apply_session_context', [ $this, 'ajax_stamp_session' ] );

		// Re-stamp from cookies on checkout init (safety net if AJAX stamp was missed)
		add_action( 'woocommerce_before_checkout_form', [ $this, 'resync_session_from_cookies' ] );
		add_action( 'woocommerce_before_pay_action',    [ $this, 'resync_session_from_cookies' ] );
	}

	// ---------------------------------------------------------------------------
	// Script enqueue
	// ---------------------------------------------------------------------------

	public function enqueue_tracker() {
		$settings = MDFCFORWC_Settings::get_instance();
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		wp_enqueue_script(
			'mdfcforwc-attribution-context',
			MDFCFORWC_PLUGIN_URL . 'src/attribution/mdf-attribution-context-wc.js',
			[],
			MDFCFORWC_VERSION,
			true
		);

		// Inject runtime config for the JS attribution context script
		wp_localize_script(
			'mdfcforwc-attribution-context',
			'mdfcforwcRuntime',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mdfcforwc_stamp_nonce' ),
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false',
				'action'  => 'mdfcforwc_apply_session_context',
			]
		);

		// Backward compatibility for integrations still reading mdfcforwcConfig.
		wp_add_inline_script(
			'mdfcforwc-attribution-context',
			'window.mdfcforwcConfig = window.mdfcforwcConfig || window.mdfcforwcRuntime;',
			'before'
		);
	}

	// ---------------------------------------------------------------------------
	// Server-side HTTP_REFERER capture
	// Runs before any output — grabs the referrer even if JS hasn't fired yet.
	// Only writes to WC session if we don't already have a referring site stored.
	// ---------------------------------------------------------------------------

	public function capture_referer_server_side() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$query_params = [];
		$query_string = filter_input( INPUT_SERVER, 'QUERY_STRING', FILTER_UNSAFE_RAW );
		if ( null !== $query_string ) {
			$query_string = sanitize_text_field( wp_unslash( $query_string ) );
			wp_parse_str( $query_string, $query_params );
		}

		$utm_source   = isset( $query_params['utm_source'] ) ? sanitize_text_field( $query_params['utm_source'] ) : '';
		$utm_medium   = isset( $query_params['utm_medium'] ) ? sanitize_text_field( $query_params['utm_medium'] ) : '';
		$utm_campaign = isset( $query_params['utm_campaign'] ) ? sanitize_text_field( $query_params['utm_campaign'] ) : '';
		$utm_content  = isset( $query_params['utm_content'] ) ? sanitize_text_field( $query_params['utm_content'] ) : '';
		$utm_term     = isset( $query_params['utm_term'] ) ? sanitize_text_field( $query_params['utm_term'] ) : '';
		$landing_ref  = isset( $query_params['ref'] ) ? sanitize_text_field( $query_params['ref'] ) : '';
		$click_id     = isset( $query_params['mdf_click_id'] ) ? sanitize_text_field( $query_params['mdf_click_id'] ) : '';
		if ( '' === $landing_ref ) {
			$landing_ref = isset( $query_params['landing_ref'] ) ? sanitize_text_field( $query_params['landing_ref'] ) : '';
		}

		$is_mdf_utm = ( '' !== $utm_source && false !== strpos( $utm_source, 'marques-de-france' ) )
			|| ( '' !== $utm_medium && false !== strpos( $utm_medium, 'marques-de-france' ) )
			|| ( '' !== $utm_campaign && false !== strpos( $utm_campaign, 'marques-de-france' ) );
		$is_mdf_ref = '' !== $landing_ref && false !== strpos( $landing_ref, 'marques-de-france' );

		if ( ( $is_mdf_utm || $is_mdf_ref || '' !== $click_id ) && ! $this->get_session( self::KEY_ATTRIBUTED ) ) {
			$this->set_session( self::KEY_ATTRIBUTED, '1' );
		}

		if ( '' !== $utm_source && ! $this->get_session( self::KEY_UTM_SOURCE ) ) {
			$this->set_session( self::KEY_UTM_SOURCE, $utm_source );
		}
		if ( '' !== $utm_medium && ! $this->get_session( self::KEY_UTM_MEDIUM ) ) {
			$this->set_session( self::KEY_UTM_MEDIUM, $utm_medium );
		}
		if ( '' !== $utm_campaign && ! $this->get_session( self::KEY_UTM_CAMPAIGN ) ) {
			$this->set_session( self::KEY_UTM_CAMPAIGN, $utm_campaign );
		}
		if ( '' !== $utm_content && ! $this->get_session( self::KEY_UTM_CONTENT ) ) {
			$this->set_session( self::KEY_UTM_CONTENT, $utm_content );
		}
		if ( '' !== $utm_term && ! $this->get_session( self::KEY_UTM_TERM ) ) {
			$this->set_session( self::KEY_UTM_TERM, $utm_term );
		}
		if ( '' !== $landing_ref && ! $this->get_session( self::KEY_LANDING_REF ) ) {
			$this->set_session( self::KEY_LANDING_REF, $landing_ref );
		}
		if ( '' !== $click_id && ! $this->get_session( self::KEY_CLICK_ID ) ) {
			$this->set_session( self::KEY_CLICK_ID, $click_id );
		}
		if ( ! $this->get_session( self::KEY_LANDING_SITE ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$current_url = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->set_session( self::KEY_LANDING_SITE, esc_url_raw( substr( $current_url, 0, 2048 ) ) );
		}

		// Only run once per session (don't overwrite JS-captured signals)
		if ( $this->get_session( self::KEY_REFERRING ) ) {
			return;
		}

		$referer = '';

		// Priority 1: WP built-in referer (filtered through wp_check_referer_field when available)
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 2048 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! $referer ) {
			return;
		}

		// Don't record self-referrals
		$site_host    = wp_parse_url( MDFCFORWC_Settings::get_site_url(), PHP_URL_HOST );
		$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
		if ( $site_host && $referer_host && $site_host === $referer_host ) {
			return;
		}

		$this->set_session( self::KEY_REFERRING, esc_url_raw( $referer ) );

		// If the referrer is marques-de-france.fr, mark this session as attributed
		// so checkout can detect referral-based attribution even without UTM params.
		$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
		if ( $referer_host && strpos( $referer_host, 'marques-de-france.fr' ) !== false ) {
			$this->set_session( self::KEY_ATTRIBUTED, '1' );
		}
	}

	// ---------------------------------------------------------------------------
	// AJAX: stamp WC session with attribution signals sent from the JS tracker
	// ---------------------------------------------------------------------------

	public function ajax_stamp_session() {
		// Verify nonce — protects against CSRF from cross-origin requests
		if ( ! check_ajax_referer( 'mdfcforwc_stamp_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
			return;
		}

		$allowed_keys = [
			self::KEY_ATTRIBUTED,
			self::KEY_UTM_SOURCE,
			self::KEY_UTM_MEDIUM,
			self::KEY_UTM_CAMPAIGN,
			self::KEY_UTM_CONTENT,
			self::KEY_UTM_TERM,
			self::KEY_LANDING_SITE,
			self::KEY_REFERRING,
			self::KEY_LANDING_REF,
			self::KEY_CLICK_ID,
		];

		$stamped = 0;
		foreach ( $allowed_keys as $key ) {
			$raw_key  = str_replace( 'mdf_', '', $key ); // keys in POST: "attributed", "utm_source", …
			$post_key = 'mdf_' . $raw_key;

			if ( ! isset( $_POST[ $post_key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );

			// Don't overwrite an existing session value (first touch wins)
			if ( $this->get_session( $key ) ) {
				continue;
			}

			if ( '' !== $value ) {
				$this->set_session( $key, $value );
				$stamped++;
			}
		}

		wp_send_json_success( [ 'stamped' => $stamped ] );
	}

	// ---------------------------------------------------------------------------
	// Safety net: re-sync WC session from cookies before checkout renders.
	// Ensures attribution survives page refreshes / new browser tabs.
	// ---------------------------------------------------------------------------

	public function resync_session_from_cookies() {
		$cookie_map = [
			self::KEY_ATTRIBUTED   => 'mdf_attributed',
			self::KEY_UTM_SOURCE   => 'mdf_utm_source',
			self::KEY_UTM_MEDIUM   => 'mdf_utm_medium',
			self::KEY_UTM_CAMPAIGN => 'mdf_utm_campaign',
			self::KEY_UTM_CONTENT  => 'mdf_utm_content',
			self::KEY_UTM_TERM     => 'mdf_utm_term',
			self::KEY_LANDING_SITE => 'mdf_landing_site',
			self::KEY_REFERRING    => 'mdf_referring_site',
			self::KEY_LANDING_REF  => 'mdf_landing_ref',
			self::KEY_CLICK_ID     => 'mdf_click_id',
		];

		foreach ( $cookie_map as $session_key => $cookie_name ) {
			if ( $this->get_session( $session_key ) ) {
				continue; // already have it — don't overwrite
			}

			if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
				$this->set_session( $session_key, sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) );
			}
		}
	}

	// ---------------------------------------------------------------------------
	// WC Session helpers — safe to call before WC is fully loaded in some contexts
	// ---------------------------------------------------------------------------

	public function set_session( string $key, string $value ) {
		if ( ! $this->wc_session_available() ) {
			return;
		}
		WC()->session->set( $key, $value );
	}

	public function get_session( string $key ): string {
		if ( ! $this->wc_session_available() ) {
			return '';
		}
		return (string) ( WC()->session->get( $key ) ?? '' );
	}

	private function wc_session_available(): bool {
		return function_exists( 'WC' ) && WC()->session instanceof WC_Session;
	}
}
