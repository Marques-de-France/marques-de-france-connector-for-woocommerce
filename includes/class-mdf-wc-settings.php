<?php
/**
 * Plugin Settings
 *
 * Registers and manages settings stored in wp_options:
 *  - mdfcforwc_secure_token — secureToken issued by the MDF Hub (the only field merchants fill in)
 *
 * Listing ID is set directly in the Hub DB by MDF after registration.
 * Hub URL is resolved from the MDFCFORWC_HUB_URL PHP constant (defined in the main plugin file).
 * Developers can override it for local testing in wp-config.php.
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Settings {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ---------------------------------------------------------------------------
	// Option accessors
	// ---------------------------------------------------------------------------

	public static function get_secure_token(): string {
		return (string) get_option( 'mdfcforwc_secure_token', '' );
	}

	/**
	 * Hub URL: always resolved from the MDFCFORWC_HUB_URL constant.
	 * Default: https://flux.marques-de-france.fr
	 */
	public static function get_hub_url(): string {
		return rtrim( MDFCFORWC_HUB_URL, '/' );
	}

	public static function get_site_url(): string {
		global $wpdb;

		$site_url = '';

		// Use the raw option values from the database instead of home_url()/site_url().
		// Under WP-CLI activation, WordPress core can resolve those helpers as 'http:'
		// because $_SERVER['HTTP_HOST'] is not available in the CLI runtime.
		$raw_home = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s LIMIT 1", 'home' ) );
		$raw_site = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s LIMIT 1", 'siteurl' ) );

		$site_url = is_string( $raw_home ) && '' !== trim( $raw_home ) ? trim( $raw_home ) : '';
		$site_url = '' !== $site_url ? $site_url : ( is_string( $raw_site ) && '' !== trim( $raw_site ) ? trim( $raw_site ) : '' );

		// Final safety net: if the DB options are somehow unavailable, fall back to the
		// stored WordPress option values rather than to home_url()/site_url().
		if ( '' === $site_url ) {
			$site_url = (string) get_option( 'home', '' );
		}
		if ( '' === $site_url ) {
			$site_url = (string) get_option( 'siteurl', '' );
		}

		return rtrim( $site_url, '/' );
	}

	public static function is_configured(): bool {
		return '' !== self::get_secure_token();
	}

	/**
	 * Returns the current feed filter mode: 'TAG' (default) or 'SERVERLIST'.
	 */
	public static function get_feed_filter_mode(): string {
		return (string) get_option( 'mdfcforwc_feed_filter_mode', 'TAG' );
	}

	/**
	 * Persists the feed filter mode. Only 'TAG' and 'SERVERLIST' are accepted.
	 *
	 * @param string $mode 'TAG' | 'SERVERLIST'.
	 */
	public static function set_feed_filter_mode( string $mode ): void {
		if ( ! in_array( $mode, [ 'TAG', 'SERVERLIST' ], true ) ) {
			return;
		}
		update_option( 'mdfcforwc_feed_filter_mode', $mode );
	}



	// ---------------------------------------------------------------------------
	// WP Settings API registration (token only)
	// ---------------------------------------------------------------------------

	public function register() {
		// Must use 'init' (not 'admin_init') so settings are registered in the REST API
		// context too — otherwise /wp/v2/settings silently ignores reads and writes.
		add_action( 'init', [ $this, 'register_settings' ] );
	}

	public function register_settings() {
		register_setting(
			'mdfcforwc_settings_group',
			'mdfcforwc_secure_token',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			'show_in_rest'      => false, // Never expose token via WP REST /wp/v2/settings
			]
		);
	}
}
