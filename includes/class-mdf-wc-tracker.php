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
			'mdfcforwc-tracker',
			MDFCFORWC_PLUGIN_URL . 'src/tracker/mdf-tracker-wc.js',
			[],
			MDFCFORWC_VERSION,
			true
		);

		// Inject runtime config for the JS tracker
		wp_localize_script(
			'mdfcforwc-tracker',
			'mdfcforwcConfig',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mdfcforwc_stamp_nonce' ),
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false',
			]
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

		// Only run once per session (don't overwrite JS-captured signals)
		if ( $this->get_session( self::KEY_REFERRING ) ) {
			return;
		}

		$referer = '';

		// Priority 1: WP built-in referer (filtered through wp_check_referer_field when available)
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! $referer ) {
			return;
		}

		// Don't record self-referrals
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
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
