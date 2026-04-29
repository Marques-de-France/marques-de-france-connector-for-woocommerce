<?php
/**
 * Plugin Activator
 *
 * Creates the wp_mdf_cforwc_sales table on activation.
 *
 * @package MDF_CFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDF_CFORWC_Activator {

	public static function activate() {
		self::create_tables();
		self::schedule_actions();
		self::register_with_hub();
		flush_rewrite_rules();
	}

	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mdf_cforwc_sales';
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
		update_option( 'mdf_cforwc_db_version', MDF_CFORWC_DB_VERSION );
	}

	private static function schedule_actions() {
		// Schedule the hourly flush of unsynced sales via Action Scheduler
		if ( function_exists( 'as_has_scheduled_action' ) &&
			! as_has_scheduled_action( 'mdf_cforwc_flush_unsynced_sales', [], 'mdf-wc' ) ) {
			as_schedule_recurring_action(
				time() + HOUR_IN_SECONDS,
				HOUR_IN_SECONDS,
				'mdf_cforwc_flush_unsynced_sales',
				[],
				'mdf-wc'
			);
		}
	}

	/**
	 * Run schema migrations without a full deactivation/reactivation cycle.
	 * Called from mdf_cforwc_init() whenever the stored DB version differs from MDF_CFORWC_DB_VERSION.
	 *
	 * dbDelta() handles new tables well but is unreliable for adding columns to existing tables.
	 * We use explicit ALTER TABLE … ADD COLUMN IF NOT EXISTS for new columns instead.
	 */
	public static function maybe_upgrade() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdf_cforwc_sales' );

		// Add hub_sync_attempts column (introduced in DB version 1.1.0).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$col = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'hub_sync_attempts'" );
		if ( ! $col ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN hub_sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER hub_synced" );
		}
		// phpcs:enable

		update_option( 'mdf_cforwc_db_version', MDF_CFORWC_DB_VERSION );
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
		$hub_url  = rtrim( MDF_CFORWC_HUB_URL, '/' );
		$site_url = home_url();

		$response = wp_remote_post(
			$hub_url . '/api/wc/self-register',
			[
				'timeout'   => 10,
				'sslverify' => ( strpos( MDF_CFORWC_HUB_URL, 'flux.marques-de-france.fr' ) !== false ),
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
		if ( ! empty( $body['secureToken'] ) && '' === get_option( 'mdf_cforwc_secure_token', '' ) ) {
			update_option( 'mdf_cforwc_secure_token', sanitize_text_field( $body['secureToken'] ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] Secure token restored from Hub on activation.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MDF-WC] Hub self-registration: HTTP ' . $code . ' — ' . ( $body['message'] ?? 'unexpected response' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
