<?php
/**
 * Plugin Activator
 *
 * Creates the wp_mdfcforwc_sales table on activation.
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Activator {

	public static function activate() {
		// The autoloader is registered in mdfcforwc_init() (plugins_loaded, priority 20),
		// which has NOT fired yet at activation time. Require dependencies explicitly.
		require_once MDFCFORWC_PLUGIN_DIR . 'includes/class-mdf-wc-settings.php';

		self::create_tables();
		self::schedule_actions();
		self::register_with_hub();
		// Truncate and refill from Hub on every activation.
		// This guarantees a clean state after reinstalls (stale rows from previous
		// installs are wiped). Only runs if the plugin is already configured.
		if ( MDFCFORWC_Settings::is_configured() ) {
			$count = self::backfill_from_hub( true ); // truncate_first = true
			if ( $count >= 0 ) {
				update_option( 'mdfcforwc_backfill_done', '1' );
			}
		}
		flush_rewrite_rules();
	}

	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mdfcforwc_sales';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id        VARCHAR(64)     NOT NULL,
			order_number    VARCHAR(64)     DEFAULT NULL,
			amount          DECIMAL(10,2)   NOT NULL,
			currency        VARCHAR(10)     NOT NULL DEFAULT 'EUR',
			attribution_source VARCHAR(64)  DEFAULT NULL,
			signals_json    TEXT            DEFAULT NULL,
			utm_source      VARCHAR(255)    DEFAULT NULL,
			utm_medium      VARCHAR(255)    DEFAULT NULL,
			utm_campaign    VARCHAR(255)    DEFAULT NULL,
			utm_content     VARCHAR(255)    DEFAULT NULL,
			utm_term        VARCHAR(255)    DEFAULT NULL,
			landing_site    TEXT            DEFAULT NULL,
			referring_site  TEXT            DEFAULT NULL,
			landing_ref     VARCHAR(255)    DEFAULT NULL,
			status          VARCHAR(32)     NOT NULL DEFAULT 'confirmed',
			hub_synced      TINYINT(1)      NOT NULL DEFAULT 0,
			hub_sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY order_id (order_id),
			KEY status (status),
			KEY hub_synced (hub_synced),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Create feed_products table for SERVERLIST mode.
		self::create_feed_products_table( $charset_collate );

		// Store the DB version for future migrations
		update_option( 'mdfcforwc_db_version', MDFCFORWC_DB_VERSION );
	}

	/**
	 * Creates (or verifies existence of) the feed_products table.
	 * Safe to call multiple times — uses CREATE TABLE IF NOT EXISTS via dbDelta().
	 *
	 * @param string $charset_collate Optional. Falls back to $wpdb->get_charset_collate().
	 */
	private static function create_feed_products_table( string $charset_collate = '' ): void {
		global $wpdb;

		if ( '' === $charset_collate ) {
			$charset_collate = $wpdb->get_charset_collate();
		}

		$table = $wpdb->prefix . 'mdfcforwc_feed_products';
		$sql   = "CREATE TABLE IF NOT EXISTS {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			added_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY product_id (product_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function schedule_actions() {
		// Schedule the hourly flush of unsynced sales via Action Scheduler
		if ( function_exists( 'as_has_scheduled_action' ) &&
			! as_has_scheduled_action( 'mdfcforwc_flush_unsynced_sales', [], 'mdf-wc' ) ) {
			as_schedule_recurring_action(
				time() + HOUR_IN_SECONDS,
				HOUR_IN_SECONDS,
				'mdfcforwc_flush_unsynced_sales',
				[],
				'mdf-wc'
			);
		}
	}

	/**
	 * Run schema migrations without a full deactivation/reactivation cycle.
	 * Called from mdfcforwc_init() whenever the stored DB version differs from MDFCFORWC_DB_VERSION.
	 *
	 * dbDelta() handles new tables well but is unreliable for adding columns to existing tables.
	 * We use explicit ALTER TABLE … ADD COLUMN IF NOT EXISTS for new columns instead.
	 */
	public static function maybe_upgrade() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );

		// Ensure the feed_products table exists (introduced in DB version 1.2.0).
		self::create_feed_products_table();

		// Add hub_sync_attempts column (introduced in DB version 1.1.0).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$col = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'hub_sync_attempts'" );
		if ( ! $col ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN hub_sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER hub_synced" );
		}
		// phpcs:enable

		update_option( 'mdfcforwc_db_version', MDFCFORWC_DB_VERSION );
	}

	/**
	 * Self-register this store with the MDF Hub.
	 *
	 * Sends the site URL to /api/wc/self-register so MDF sees the new
	 * installation in the Hub database. MDF then retrieves the secureToken
	 * and forwards it to the merchant, who enters it in the Settings page.
	 *
	 * This runs on every plugin activation (including re-activation) so that
	 * deleted Hub entries are recreated automatically.
	 */
	private static function register_with_hub() {
		$hub_url  = rtrim( MDFCFORWC_HUB_URL, '/' );
		$site_url = MDFCFORWC_Settings::get_site_url();

		$response = wp_remote_post(
			$hub_url . '/api/wc/self-register',
			[
				'timeout'   => 10,
				'sslverify' => ! ( defined( 'MDFCFORWC_DISABLE_SSL_VERIFY' ) && MDFCFORWC_DISABLE_SSL_VERIFY ),
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [ 'siteUrl' => $site_url ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Hub self-registration failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// If the hub returned the existing token and the local option is empty
		// (e.g. after a plugin delete + reinstall), restore it automatically.
		if ( ! empty( $body['secureToken'] ) && '' === get_option( 'mdfcforwc_secure_token', '' ) ) {
			update_option( 'mdfcforwc_secure_token', sanitize_text_field( $body['secureToken'] ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Secure token restored from Hub on activation.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MDF-WC] Hub self-registration: HTTP ' . $code . ' — ' . ( $body['message'] ?? 'unexpected response' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Backfill wp_mdfcforwc_sales from the MDF Hub API.
	 *
	 * The Hub is the authoritative source of truth: it holds only sales that were
	 * genuinely attributed to Marques de France for THIS store.
	 *
	 * @param bool $truncate_first  When true, clears the local table before importing
	 *                              (used by the admin "Restore" button to wipe bad data).
	 *                              When false (default, activation), the insert is
	 *                              idempotent — already-present orders are skipped.
	 * @return int  Number of rows inserted, or -1 on Hub communication error.
	 */
	public static function backfill_from_hub( bool $truncate_first = false ): int {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return -1;
		}

		$hub_url  = rtrim( MDFCFORWC_Settings::get_hub_url(), '/' );
		$token    = MDFCFORWC_Settings::get_secure_token();
		$site_url = MDFCFORWC_Settings::get_site_url();

		// ---------------------------------------------------------------------------
		// Fetch all sales pages from the Hub before touching the local DB.
		// ---------------------------------------------------------------------------
		$all_sales = [];
		$page      = 1;
		$limit     = 100;

		do {
			$url = add_query_arg(
				[ 'page' => $page, 'limit' => $limit, 'sortField' => 'createdAt', 'sortDir' => 'asc' ],
				$hub_url . '/api/wc/sales'
			);

			$response = wp_remote_get(
				$url,
				[
					'timeout'   => 20,
					'sslverify' => ( strpos( MDFCFORWC_HUB_URL, 'flux.marques-de-france.fr' ) !== false ),
					'headers'   => [
						'X-MDF-Token' => $token,
						'X-MDF-Shop'  => $site_url,
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[MDF-WC] backfill_from_hub error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return -1;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[MDF-WC] backfill_from_hub HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return -1;
			}

			$data  = json_decode( wp_remote_retrieve_body( $response ), true );
			$batch = $data['sales'] ?? [];
			$total = (int) ( $data['total'] ?? 0 );

			$all_sales = array_merge( $all_sales, $batch );
			$page++;
		} while ( count( $batch ) === $limit && count( $all_sales ) < $total );

		// ---------------------------------------------------------------------------
		// Write to the local DB.
		// ---------------------------------------------------------------------------
		global $wpdb;
		$table    = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );
		$inserted = 0;

		if ( $truncate_first ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		foreach ( $all_sales as $sale ) {
			$order_id = (string) ( $sale['orderId'] ?? '' );
			if ( ! $order_id ) {
				continue;
			}

			if ( ! $truncate_first ) {
				// Idempotency check — skip if already present.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %s LIMIT 1", $order_id )
				);
				// phpcs:enable
				if ( $existing ) {
					continue;
				}
			}

			$hub_status = $sale['status'] ?? 'confirmed';
			if ( in_array( $hub_status, [ 'cancelled', 'failed' ], true ) ) {
				$local_status = 'cancelled';
			} elseif ( 'refunded' === $hub_status ) {
				$local_status = 'refunded';
			} else {
				$local_status = 'confirmed';
			}

			// Convert ISO 8601 timestamp to MySQL datetime.
			$created_at = current_time( 'mysql' );
			if ( ! empty( $sale['createdAt'] ) ) {
				$dt = date_create( $sale['createdAt'] );
				if ( $dt ) {
					$created_at = $dt->format( 'Y-m-d H:i:s' );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$table,
				[
					'order_id'           => $order_id,
					'order_number'       => sanitize_text_field( $sale['orderName'] ?? $order_id ),
					'amount'             => (float) ( $sale['amount'] ?? 0 ),
					'currency'           => sanitize_text_field( $sale['currency'] ?? 'EUR' ),
					'attribution_source' => sanitize_text_field( $sale['attributionSource'] ?? '' ),
					'status'             => $local_status,
					'hub_synced'         => 1,
					'created_at'         => $created_at,
				],
				[ '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( false !== $result ) {
				$inserted++;
			}
		}

		return $inserted;
	}

	/**
	 * @deprecated Use backfill_from_hub() instead.
	 * Kept as an alias so any external callers do not break.
	 */
	public static function backfill_from_orders(): int {
		return self::backfill_from_hub( false );
	}
}
