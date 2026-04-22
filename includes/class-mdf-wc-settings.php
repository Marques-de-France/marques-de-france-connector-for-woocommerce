<?php
/**
 * Plugin Settings
 *
 * Registers and manages settings stored in wp_options:
 *  - mdf_wc_secure_token — secureToken issued by the MDF Hub (the only field merchants fill in)
 *
 * Listing ID is set directly in the Hub DB by MDF after registration.
 * Hub URL is resolved from the MDF_WC_HUB_URL PHP constant (defined in the main plugin file).
 * Developers can override it for local testing in wp-config.php.
 *
 * @package MDF_WC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDF_WC_Settings {

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
		return (string) get_option( 'mdf_wc_secure_token', '' );
	}

	/**
	 * Hub URL: always resolved from the MDF_WC_HUB_URL constant.
	 * Production : https://flux.marques-de-france.fr  (default)
	 * Development: define( 'MDF_WC_HUB_URL', 'https://your-tunnel.trycloudflare.com' ) in wp-config.php
	 */
	public static function get_hub_url(): string {
		return rtrim( MDF_WC_HUB_URL, '/' );
	}

	public static function is_configured(): bool {
		return '' !== self::get_secure_token();
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
			'mdf_wc_settings_group',
			'mdf_wc_secure_token',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			'show_in_rest'      => false, // Never expose token via WP REST /wp/v2/settings
			]
		);
	}
}
