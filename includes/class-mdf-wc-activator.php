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
		self::create_tables();
		self::schedule_actions();
		self::register_with_hub();
		self::backfill_from_orders(); // Restore historical sales after (re-)activation.
		update_option( 'mdfcforwc_backfill_done', '1' );
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

		// Store the DB version for future migrations
		update_option( 'mdfcforwc_db_version', MDFCFORWC_DB_VERSION );
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
		$site_url = home_url();

		$response = wp_remote_post(
			$hub_url . '/api/wc/self-register',
			[
				'timeout'   => 10,
				'sslverify' => ( strpos( MDFCFORWC_HUB_URL, 'flux.marques-de-france.fr' ) !== false ),
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
	 * Backfill wp_mdfcforwc_sales from existing WooCommerce order meta.
	 *
	 * Runs on every (re-)activation to restore historical sales that were recorded
	 * as WC order meta (_mdf_source, _mdf_signals_json, etc.) but may be missing
	 * from the local sales table (e.g. after an uninstall/reinstall cycle).
	 *
	 * The insert is idempotent: orders already present in the table are skipped.
	 * Orders already synced to the Hub are marked hub_synced = 1 to avoid re-sending.
	 *
	 * @return int Number of rows newly inserted.
	 */
	public static function backfill_from_orders(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mdfcforwc_sales';

		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'objects',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_mdf_source',
						'value'   => [ 'mdf_ref', 'utm', 'mdf_referral' ],
						'compare' => 'IN',
					],
				],
			]
		);

		$inserted = 0;

		foreach ( $orders as $order ) {
			$order_id = (string) $order->get_id();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %s LIMIT 1", $order_id )
			);
			// phpcs:enable

			if ( $existing ) {
				continue; // Already recorded — skip.
			}

			$wc_status = $order->get_status();
			if ( in_array( $wc_status, [ 'cancelled', 'failed' ], true ) ) {
				$status = 'cancelled';
			} elseif ( 'refunded' === $wc_status ) {
				$status = 'refunded';
			} else {
				$status = 'confirmed';
			}

			$date_created = $order->get_date_created();
			$created_at   = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$table,
				[
					'order_id'           => $order_id,
					'order_number'       => $order->get_order_number(),
					'amount'             => (float) $order->get_total(),
					'currency'           => $order->get_currency(),
					'attribution_source' => $order->get_meta( '_mdf_source' ),
					'signals_json'       => $order->get_meta( '_mdf_signals_json' ) ?: null,
					'utm_source'         => $order->get_meta( '_mdf_utm_source' ),
					'utm_medium'         => $order->get_meta( '_mdf_utm_medium' ),
					'utm_campaign'       => $order->get_meta( '_mdf_utm_campaign' ),
					'utm_content'        => $order->get_meta( '_mdf_utm_content' ),
					'utm_term'           => $order->get_meta( '_mdf_utm_term' ),
					'landing_site'       => $order->get_meta( '_mdf_landing_site' ),
					'referring_site'     => $order->get_meta( '_mdf_referring_site' ),
					'landing_ref'        => $order->get_meta( '_mdf_landing_ref' ),
					'status'             => $status,
					'hub_synced'         => 1, // Already in Hub DB — do not re-sync.
					'created_at'         => $created_at,
				],
				[ '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( false !== $result ) {
				$inserted++;
			}
		}

		return $inserted;
	}
}
