<?php
/**
 * Plugin Name: Marques de France
 * Plugin URI:  https://www.marques-de-france.fr
 * Description: Connect your WooCommerce store to the Marques de France guide. Track attributed sales, generate a product feed, and automatically sync data to the MDF platform.
 * Version:     1.0.0
 * Author:      Marques de France
 * Author URI:  https://www.marques-de-france.fr
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: marques-de-france-connector-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package MDF_WC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'MDF_WC_VERSION',     '1.0.0' );
define( 'MDF_WC_DB_VERSION',  '1.1.0' );
define( 'MDF_WC_PLUGIN_FILE', __FILE__ );
define( 'MDF_WC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MDF_WC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MDF_WC_PLUGIN_SLUG', 'marques-de-france-connector-for-woocommerce' );

// Hub URL: production by default.
// Developers can override for local testing by adding to wp-config.php:
//   define( 'MDF_WC_HUB_URL', 'https://your-tunnel.trycloudflare.com' );
if ( ! defined( 'MDF_WC_HUB_URL' ) ) {
	define( 'MDF_WC_HUB_URL', 'https://flux.marques-de-france.fr' );
}

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'mdf_wc_activate' );
register_deactivation_hook( __FILE__, 'mdf_wc_deactivate' );

function mdf_wc_activate() {
	require_once MDF_WC_PLUGIN_DIR . 'includes/class-mdf-wc-activator.php';
	MDF_WC_Activator::activate();
}

function mdf_wc_deactivate() {
	// Cancel scheduled Action Scheduler actions on deactivation
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'mdf_wc_flush_unsynced_sales', [], 'mdf-wc' );
	}
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'mdf_wc_init', 20 );

function mdf_wc_init() {
	// Defensive check — Requires Plugins header handles UI, but load order is not guaranteed.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'mdf_wc_missing_woocommerce_notice' );
		return;
	}

	// Autoload plugin classes
	spl_autoload_register( 'mdf_wc_autoloader' );

	// Run DB schema migrations if needed (e.g. new columns added in updates)
	if ( get_option( 'mdf_wc_db_version' ) !== MDF_WC_DB_VERSION ) {
		MDF_WC_Activator::maybe_upgrade();
	}

	// Bootstrap all feature classes
	MDF_WC_Settings::get_instance();
	MDF_WC_Tracker::get_instance();
	MDF_WC_Attribution::get_instance();
	MDF_WC_Feed::get_instance();
	MDF_WC_Admin::get_instance();
}

/**
 * PSR-4 style autoloader for MDF_WC_* classes.
 */
function mdf_wc_autoloader( $class_name ) {
	if ( strpos( $class_name, 'MDF_WC_' ) !== 0 ) {
		return;
	}

	$map = [
		'MDF_WC_Activator'   => 'includes/class-mdf-wc-activator.php',
		'MDF_WC_Settings'    => 'includes/class-mdf-wc-settings.php',
		'MDF_WC_Tracker'     => 'includes/class-mdf-wc-tracker.php',
		'MDF_WC_Attribution' => 'includes/class-mdf-wc-attribution.php',
		'MDF_WC_Hub_Client'  => 'includes/class-mdf-wc-hub-client.php',
		'MDF_WC_Feed'        => 'includes/class-mdf-wc-feed.php',
		'MDF_WC_Admin'       => 'admin/class-mdf-wc-admin.php',
	];

	if ( isset( $map[ $class_name ] ) ) {
		require_once MDF_WC_PLUGIN_DIR . $map[ $class_name ];
	}
}

function mdf_wc_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Marques de France requires WooCommerce to be installed and active.', 'marques-de-france-connector-for-woocommerce' );
	echo '</p></div>';
}
