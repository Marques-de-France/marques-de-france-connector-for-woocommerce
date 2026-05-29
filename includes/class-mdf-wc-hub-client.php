<?php
/**
 * Hub Client
 *
 * Sends attributed WooCommerce sales to the MDF Hub at flux.marques-de-france.fr.
 *
 * Sync strategy:
 *   1. Primary: immediate wp_remote_post() at `woocommerce_thankyou` (timeout 5s).
 *   2. Fallback: if the HTTP request fails, schedule an async Action Scheduler retry.
 *   3. Safety-net: `mdfcforwc_flush_unsynced_sales` AS recurring action (hourly) catches
 *      any sales that slipped through (e.g. server restart, Hub downtime).
 *
 * Status sync:
 *   When an order is cancelled or refunded, send a status update to the Hub
 *   so the MDF dashboard reflects the correct revenue.
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Hub_Client {

	const SYNC_TIMEOUT = 5; // seconds for immediate sync

	private string $hub_url;
	private string $site_url;
	private string $token;

	public function __construct() {
		$this->hub_url  = MDFCFORWC_Settings::get_hub_url();
		$this->site_url = home_url();
		$this->token    = MDFCFORWC_Settings::get_secure_token();

		$this->register_action_scheduler_hooks();
	}

	// ---------------------------------------------------------------------------
	// Action Scheduler hook registration
	// ---------------------------------------------------------------------------

	private function register_action_scheduler_hooks() {
		// Retry single sale
		add_action( 'mdfcforwc_retry_hub_sync', [ $this, 'as_retry_sale' ] );

		// Hourly flush of all unsynced sales
		add_action( 'mdfcforwc_flush_unsynced_sales', [ $this, 'flush_unsynced_sales' ] );

		// Order status transitions → Hub status update
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_cancelled' ] );
		add_action( 'woocommerce_order_status_refunded',  [ $this, 'on_order_refunded' ] );
	}

	// ---------------------------------------------------------------------------
	// Immediate sync (called from MDFCFORWC_Attribution after recording local sale)
	// ---------------------------------------------------------------------------

	/**
	 * Attempt to sync a WC order to the Hub.
	 * On failure, schedules an AS retry.
	 */
	public function sync_sale( WC_Order $order ) {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		$attribution = MDFCFORWC_Attribution::get_order_attribution( $order );

		$payload = [
			'shopUrl'           => $this->site_url,
			'orderId'           => (string) $order->get_id(),
			'orderName'         => $order->get_order_number(),
			'amount'            => (float) $order->get_total(),
			'currency'          => $order->get_currency(),
			'attributionSource' => $attribution['source'] ?? '',
			'utmSource'         => $attribution['utm_source'] ?? '',
			'utmMedium'         => $attribution['utm_medium'] ?? '',
			'utmCampaign'       => $attribution['utm_campaign'] ?? '',
			'utmContent'        => $attribution['utm_content'] ?? '',
			'utmTerm'           => $attribution['utm_term'] ?? '',
			'landingSite'       => $attribution['landing_site'] ?? '',
			'referringSite'     => $attribution['referring_site'] ?? '',
			'landingSiteRef'    => $attribution['landing_ref'] ?? '',
		];

		$response = $this->post( '/api/wc/sales', $payload, self::SYNC_TIMEOUT );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Hub sync failed for order ' . $order->get_id() . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$this->schedule_retry( $order->get_id() );
		} else {
			$this->mark_synced( (string) $order->get_id() );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Hub sync successful for order ' . $order->get_id() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Action Scheduler callbacks
	// ---------------------------------------------------------------------------

	/**
	 * AS retry: called with order_id as argument.
	 */
	public function as_retry_sale( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}

		$this->sync_sale( $order );
	}

	/**
	 * Hourly flush: find all unsynced orders and attempt sync.
	 * Processes up to 50 per run to avoid timeouts.
	 * Skips rows that have reached the max attempt threshold (dead letter).
	 */
	public function flush_unsynced_sales() {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Exclude dead-lettered rows (>= 5 failed attempts).
		$rows = $wpdb->get_results(
			"SELECT order_id FROM `{$table}` WHERE hub_synced = 0 AND status = 'confirmed' AND hub_sync_attempts < 5 ORDER BY created_at ASC LIMIT 50"
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$order = wc_get_order( absint( $row->order_id ) );
			if ( ! $order ) {
				continue;
			}

			$this->sync_sale( $order );

			// If still unsynced after this attempt, increment the counter.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$synced = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT hub_synced FROM `{$table}` WHERE order_id = %s",
					$row->order_id
				)
			);

			if ( ! $synced ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET hub_sync_attempts = hub_sync_attempts + 1 WHERE order_id = %s",
						$row->order_id
					)
				);
			}
			// phpcs:enable
		}
	}

	// ---------------------------------------------------------------------------
	// Order status change: notify Hub of cancellation or refund
	// ---------------------------------------------------------------------------

	public function on_order_cancelled( $order_id ) {
		$this->update_sale_status( $order_id, 'cancelled' );
	}

	public function on_order_refunded( $order_id ) {
		$this->update_sale_status( $order_id, 'refunded' );
	}

	private function update_sale_status( $order_id, string $status ) {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		// Only update if we have a local record (non-attributed orders are not in the Hub)
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$local = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %s LIMIT 1", (string) $order_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $local ) {
			return;
		}

		$response = $this->post(
			'/api/wc/sales/' . rawurlencode( (string) $order_id ) . '/status',
			[ 'shopUrl' => $this->site_url, 'status' => $status ],
			self::SYNC_TIMEOUT
		);

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 400 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[ 'status' => $status ],
				[ 'order_id' => (string) $order_id ],
				[ '%s' ],
				[ '%s' ]
			);
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Failed to update Hub status for order ' . $order_id . ' to ' . $status ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Internal helpers
	// ---------------------------------------------------------------------------

	private function post( string $endpoint, array $body, int $timeout = 10 ) {
		$url = $this->hub_url . $endpoint;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MDF-WC] POST ' . $url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[MDF-WC]   X-MDF-Shop  : ' . $this->site_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[MDF-WC]   X-MDF-Token : ' . substr( $this->token, 0, 8 ) . '…' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$response = wp_remote_post( $url, [
			'timeout'     => $timeout,
			'sslverify'   => ( strpos( MDFCFORWC_HUB_URL, 'flux.marques-de-france.fr' ) !== false ),
			'headers'     => [
				'Content-Type'     => 'application/json',
				'X-MDF-Token'      => $this->token,
				'X-MDF-Shop'       => $this->site_url,
				'X-Plugin-Version' => MDFCFORWC_VERSION,
			],
			'body'        => wp_json_encode( $body ),
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( is_wp_error( $response ) ) {
				error_log( '[MDF-WC]   ERROR: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				$code      = wp_remote_retrieve_response_code( $response );
				$resp_body = wp_remote_retrieve_body( $response );
				error_log( '[MDF-WC]   HTTP ' . $code . ' — ' . $resp_body ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return $response;
	}

	private function schedule_retry( $order_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// Check if a retry is already scheduled for this order
		if ( as_has_scheduled_action( 'mdfcforwc_retry_hub_sync', [ (int) $order_id ], 'mdf-wc' ) ) {
			return;
		}

		as_enqueue_async_action( 'mdfcforwc_retry_hub_sync', [ (int) $order_id ], 'mdf-wc' );
	}

	private function mark_synced( string $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mdfcforwc_sales',
			[ 'hub_synced' => 1 ],
			[ 'order_id' => $order_id ],
			[ '%d' ],
			[ '%s' ]
		);
		// Invalidate the admin notice cache so the notice disappears immediately.
		delete_transient( 'mdfcforwc_unsynced_notice_count' );
	}
}
