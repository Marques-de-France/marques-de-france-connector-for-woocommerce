<?php
/**
 * Plugin Name: Marques de France
 * Plugin URI:  https://github.com/Marques-de-France/marques-de-france-connector-for-woocommerce
 * Description: Connect your WooCommerce store to the Marques de France guide. Track attributed sales, generate a product feed, and automatically sync data to the MDF platform.
 * Version:     1.1.0
 * Author:      Marques de France
 * Author URI:  https://www.marques-de-france.fr
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: marques-de-france-connector-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'MDFCFORWC_VERSION',     '1.2.0' );
define( 'MDFCFORWC_DB_VERSION',  '1.2.0' );
define( 'MDFCFORWC_PLUGIN_FILE', __FILE__ );
define( 'MDFCFORWC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MDFCFORWC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MDFCFORWC_PLUGIN_SLUG', 'marques-de-france-connector-for-woocommerce' );

// Hub URL: production default. Can be overridden via MDFCFORWC_HUB_URL in wp-config.php.
if ( ! defined( 'MDFCFORWC_HUB_URL' ) ) {
	define( 'MDFCFORWC_HUB_URL', 'https://flux.marques-de-france.fr' );
}

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'mdfcforwc_activate' );
register_deactivation_hook( __FILE__, 'mdfcforwc_deactivate' );

function mdfcforwc_activate() {
	require_once MDFCFORWC_PLUGIN_DIR . 'includes/class-mdf-wc-activator.php';
	MDFCFORWC_Activator::activate();
}

function mdfcforwc_deactivate() {
	// Cancel scheduled Action Scheduler actions on deactivation
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'mdfcforwc_flush_unsynced_sales', [], 'mdf-wc' );
	}
	// Reset backfill flag so it re-runs on next activation (handles reinstalls).
	delete_option( 'mdfcforwc_backfill_done' );
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'mdfcforwc_init', 20 );

function mdfcforwc_init() {
	// Defensive check — Requires Plugins header handles UI, but load order is not guaranteed.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'mdfcforwc_missing_woocommerce_notice' );
		return;
	}

	// Autoload plugin classes
	spl_autoload_register( 'mdfcforwc_autoloader' );

	// Run DB schema migrations if needed (e.g. new columns added in updates)
	if ( get_option( 'mdfcforwc_db_version' ) !== MDFCFORWC_DB_VERSION ) {
		MDFCFORWC_Activator::maybe_upgrade();
	}

	// Bootstrap all feature classes
	MDFCFORWC_Settings::get_instance();
	MDFCFORWC_Tracker::get_instance();
	MDFCFORWC_Attribution::get_instance();
	MDFCFORWC_Feed::get_instance();
	MDFCFORWC_Admin::get_instance();
}

/**
 * PSR-4 style autoloader for MDFCFORWC_* classes.
 */
function mdfcforwc_autoloader( $class_name ) {
	if ( strpos( $class_name, 'MDFCFORWC_' ) !== 0 ) {
		return;
	}

	$map = [
		'MDFCFORWC_Activator'   => 'includes/class-mdf-wc-activator.php',
		'MDFCFORWC_Settings'    => 'includes/class-mdf-wc-settings.php',
		'MDFCFORWC_Tracker'     => 'includes/class-mdf-wc-tracker.php',
		'MDFCFORWC_Attribution' => 'includes/class-mdf-wc-attribution.php',
		'MDFCFORWC_Hub_Client'  => 'includes/class-mdf-wc-hub-client.php',
		'MDFCFORWC_Feed'          => 'includes/class-mdf-wc-feed.php',
		'MDFCFORWC_Feed_Products' => 'includes/class-mdf-wc-feed-products.php',
		'MDFCFORWC_Admin'         => 'admin/class-mdf-wc-admin.php',
	];

	if ( isset( $map[ $class_name ] ) ) {
		require_once MDFCFORWC_PLUGIN_DIR . $map[ $class_name ];
	}
}

function mdfcforwc_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Marques de France requires WooCommerce to be installed and active.', 'marques-de-france-connector-for-woocommerce' );
	echo '</p></div>';
}
