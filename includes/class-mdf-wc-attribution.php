<?php
/**
 * Attribution
 *
 * Reads attribution signals from WC session (and cookie fallbacks) at checkout,
 * attaches them as order meta, and records the sale in the local DB.
 *
 * Signal priority:
 *  1. mdf_attributed  → WC session || $_COOKIE['mdf_attributed']
 *  2. utm             → WC session || $_COOKIE['mdf_utm_*']
 *  3. mdf_landing_ref → WC session || $_COOKIE['mdf_landing_ref']
 *  4. landing_site    → WC session || $_COOKIE['mdf_landing_site']
 *  5. referring_site  → WC session || $_COOKIE['mdf_referring_site'] || $_SERVER['HTTP_REFERER']
 *
 * Attribution source is determined in order:
 *   'mdf_ref'      if landing_ref is set
 *   'utm'          if utm_source is set
 *   'mdf_referral' if referring_site matches marques-de-france.fr
 *   'referral'     otherwise
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Attribution {

	// Order meta keys
	const META_ATTRIBUTED      = '_mdf_attributed';
	const META_SOURCE          = '_mdf_source';
	const META_UTM_SOURCE      = '_mdf_utm_source';
	const META_UTM_MEDIUM      = '_mdf_utm_medium';
	const META_UTM_CAMPAIGN    = '_mdf_utm_campaign';
	const META_UTM_CONTENT     = '_mdf_utm_content';
	const META_UTM_TERM        = '_mdf_utm_term';
	const META_LANDING_SITE    = '_mdf_landing_site';
	const META_REFERRING_SITE  = '_mdf_referring_site';
	const META_LANDING_REF     = '_mdf_landing_ref';
	const META_SIGNALS_JSON    = '_mdf_signals_json';

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
		// Attach attribution data to order at creation
		add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_to_order' ], 10, 2 );

		// Record sale in local DB after order is saved
		add_action( 'woocommerce_checkout_order_created', [ $this, 'record_local_sale' ], 20 );
	}

	// ---------------------------------------------------------------------------
	// Read a signal: WC session first, cookie as fallback
	// ---------------------------------------------------------------------------

	private function read_signal( string $session_key, string $cookie_name ): string {
		$tracker = MDFCFORWC_Tracker::get_instance();

		// WC session (most reliable — set by AJAX stamp)
		$value = $tracker->get_session( $session_key );
		if ( '' !== $value ) {
			return $value;
		}

		// Cookie fallback (survives session expiry / new tabs)
		if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		}

		return '';
	}

	/**
	 * Collect all attribution signals.
	 * Returns an array with all keys, empty strings when a signal is absent.
	 */
	public function collect_signals(): array {
		$attributed    = $this->read_signal( MDFCFORWC_Tracker::KEY_ATTRIBUTED,   'mdf_attributed' );
		$utm_source    = $this->read_signal( MDFCFORWC_Tracker::KEY_UTM_SOURCE,    'mdf_utm_source' );
		$utm_medium    = $this->read_signal( MDFCFORWC_Tracker::KEY_UTM_MEDIUM,    'mdf_utm_medium' );
		$utm_campaign  = $this->read_signal( MDFCFORWC_Tracker::KEY_UTM_CAMPAIGN,  'mdf_utm_campaign' );
		$utm_content   = $this->read_signal( MDFCFORWC_Tracker::KEY_UTM_CONTENT,   'mdf_utm_content' );
		$utm_term      = $this->read_signal( MDFCFORWC_Tracker::KEY_UTM_TERM,      'mdf_utm_term' );
		$landing_site  = $this->read_signal( MDFCFORWC_Tracker::KEY_LANDING_SITE,  'mdf_landing_site' );
		$landing_ref   = $this->read_signal( MDFCFORWC_Tracker::KEY_LANDING_REF,   'mdf_landing_ref' );

		// Signal 5 (referring site): session → cookie → server-side HTTP_REFERER
		$referring = $this->read_signal( MDFCFORWC_Tracker::KEY_REFERRING, 'mdf_referring_site' );
		if ( '' === $referring && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$raw_referer   = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$referer_host  = wp_parse_url( $raw_referer, PHP_URL_HOST );
			$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $referer_host && $referer_host !== $site_host ) {
				$referring = $raw_referer;
			}
		}

		// Determine attribution source — each signal is checked independently,
		// mirroring the Shopify tracker (attributedViaRef || utm || referrer).
		// $attributed is NOT required as a gate: mdf_* cookies/session values are
		// only ever written by the MDF tracker, which already verified the signal.
		$source = '';
		if ( $landing_ref ) {
			$source = 'mdf_ref';
		} elseif (
			( $utm_source   && strpos( $utm_source,   'marques-de-france' ) !== false ) ||
			( $utm_medium   && strpos( $utm_medium,   'marques-de-france' ) !== false ) ||
			( $utm_campaign && strpos( $utm_campaign, 'marques-de-france' ) !== false )
		) {
			$source = 'utm';
		} elseif ( $referring && strpos( $referring, 'marques-de-france.fr' ) !== false ) {
			// Referrer alone is sufficient — no $attributed cookie required.
			// This covers: AJAX stamp failed, cookies blocked by adblocker, etc.
			$source = 'mdf_referral';
		} elseif ( $referring ) {
			$source = 'referral';
		}

		return [
			'attributed'     => $attributed,
			'source'         => $source,
			'utm_source'     => $utm_source,
			'utm_medium'     => $utm_medium,
			'utm_campaign'   => $utm_campaign,
			'utm_content'    => $utm_content,
			'utm_term'       => $utm_term,
			'landing_site'   => $landing_site,
			'referring_site' => $referring,
			'landing_ref'    => $landing_ref,
		];
	}

	/**
	 * Returns true if the collected signals represent an MDF attribution.
	 */
	public function is_mdf_attributed( array $signals ): bool {
		return in_array( $signals['source'], [ 'mdf_ref', 'utm', 'mdf_referral' ], true );
	}

	// ---------------------------------------------------------------------------
	// Hook: attach attribution to order meta
	// ---------------------------------------------------------------------------

	public function attach_to_order( WC_Order $order, array $data ) {
		$signals = $this->collect_signals();

		// Only store attribution meta for MDF-attributed orders
		if ( ! $this->is_mdf_attributed( $signals ) ) {
			return;
		}

		$order->update_meta_data( self::META_ATTRIBUTED,    '1' );
		$order->update_meta_data( self::META_SOURCE,         $signals['source'] );
		$order->update_meta_data( self::META_UTM_SOURCE,     $signals['utm_source'] );
		$order->update_meta_data( self::META_UTM_MEDIUM,     $signals['utm_medium'] );
		$order->update_meta_data( self::META_UTM_CAMPAIGN,   $signals['utm_campaign'] );
		$order->update_meta_data( self::META_UTM_CONTENT,    $signals['utm_content'] );
		$order->update_meta_data( self::META_UTM_TERM,       $signals['utm_term'] );
		$order->update_meta_data( self::META_LANDING_SITE,   $signals['landing_site'] );
		$order->update_meta_data( self::META_REFERRING_SITE, $signals['referring_site'] );
		$order->update_meta_data( self::META_LANDING_REF,    $signals['landing_ref'] );
		$order->update_meta_data( self::META_SIGNALS_JSON,   wp_json_encode( $signals ) );
	}

	// ---------------------------------------------------------------------------
	// Hook: record local sale in wp_mdfcforwc_sales
	// ---------------------------------------------------------------------------

	public function record_local_sale( WC_Order $order ) {
		$signals = $this->collect_signals();

		if ( ! $this->is_mdf_attributed( $signals ) ) {
			return; // Not an MDF-attributed order — don't record
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );

		// Idempotency: check if this order is already recorded
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %s LIMIT 1", (string) $order->get_id() )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			[
				'order_id'          => (string) $order->get_id(),
				'order_number'      => $order->get_order_number(),
				'amount'            => (float) $order->get_total(),
				'currency'          => $order->get_currency(),
				'attribution_source' => $signals['source'],
				'signals_json'      => wp_json_encode( $signals ),
				'utm_source'        => $signals['utm_source'],
				'utm_medium'        => $signals['utm_medium'],
				'utm_campaign'      => $signals['utm_campaign'],
				'utm_content'       => $signals['utm_content'],
				'utm_term'          => $signals['utm_term'],
				'landing_site'      => $signals['landing_site'],
				'referring_site'    => $signals['referring_site'],
				'landing_ref'       => $signals['landing_ref'],
				'status'            => 'confirmed',
				'hub_synced'        => 0,
			],
			[ '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		// Trigger Hub sync
		if ( $wpdb->insert_id ) {
			$hub_client = new MDFCFORWC_Hub_Client();
			$hub_client->sync_sale( $order );
		}
	}

	// ---------------------------------------------------------------------------
	// Static helper: get attribution meta from an existing order (for metabox)
	// ---------------------------------------------------------------------------

	public static function get_order_attribution( WC_Order $order ): array {
		return [
			'attributed'     => $order->get_meta( self::META_ATTRIBUTED ),
			'source'         => $order->get_meta( self::META_SOURCE ),
			'utm_source'     => $order->get_meta( self::META_UTM_SOURCE ),
			'utm_medium'     => $order->get_meta( self::META_UTM_MEDIUM ),
			'utm_campaign'   => $order->get_meta( self::META_UTM_CAMPAIGN ),
			'utm_content'    => $order->get_meta( self::META_UTM_CONTENT ),
			'utm_term'       => $order->get_meta( self::META_UTM_TERM ),
			'landing_site'   => $order->get_meta( self::META_LANDING_SITE ),
			'referring_site' => $order->get_meta( self::META_REFERRING_SITE ),
			'landing_ref'    => $order->get_meta( self::META_LANDING_REF ),
		];
	}
}
