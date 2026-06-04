<?php
/**
 * Admin
 *
 * Registers:
 *  - A root-level WP admin menu "Marques de France" (position 56)
 *    with 3 submenus: Dashboard, Sales, Settings
 *  - A WooCommerce order detail metabox showing attribution details
 *  - WP REST API endpoints consumed by the React dashboard
 *  - Asset enqueueing for the React dashboard (built with @wordpress/scripts)
 *
 * Menu slug: marques-de-france-connector-for-woocommerce
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Admin {

	const MENU_SLUG         = 'marques-de-france-connector-for-woocommerce';
	const CAPABILITY        = 'manage_options';
	const REST_NAMESPACE    = 'mdfcforwc/v1';

	private static ?self $instance = null;

	/** Holds the current product search term during a REST request, used by the posts_search filter. */
	private string $product_search_term = '';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init() {
		add_action( 'admin_menu',    [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_dashboard' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_notices', [ $this, 'render_unsynced_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_backfill_notice' ] );
		add_action( 'admin_post_mdfcforwc_backfill', [ $this, 'handle_backfill' ] );

		// WooCommerce order metabox — support both HPOS and legacy meta boxes
		add_action( 'add_meta_boxes', [ $this, 'register_order_metabox' ] );
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_order_metabox' ] );

		// Settings page: delegate to MDFCFORWC_Settings for WP Settings API
		$settings = MDFCFORWC_Settings::get_instance();
		$settings->register();
	}

	private function user_can_access(): bool {
		return current_user_can( self::CAPABILITY ) || current_user_can( 'manage_woocommerce' );
	}

	private function sync_sales_from_hub_if_needed(): void {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		$cache_key = 'mdfcforwc_sales_sync_from_hub';
		if ( false !== get_transient( $cache_key ) ) {
			return;
		}

		require_once MDFCFORWC_PLUGIN_DIR . 'includes/class-mdf-wc-activator.php';
		MDFCFORWC_Activator::backfill_from_hub( false );
		set_transient( $cache_key, 1, 5 * MINUTE_IN_SECONDS );
	}

	// ---------------------------------------------------------------------------
	// Admin Menu
	// ---------------------------------------------------------------------------

	public function add_menu() {
		// Root-level menu
		add_menu_page(
			__( 'Marques de France', 'marques-de-france-connector-for-woocommerce' ),
			__( 'Marques de France', 'marques-de-france-connector-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page_dashboard' ],
			MDFCFORWC_PLUGIN_URL . 'admin/images/marques-de-france.ico',
			56
		);

		// Submenu 1: Dashboard (duplicate of root to override default WP label)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'marques-de-france-connector-for-woocommerce' ),
			__( 'Dashboard', 'marques-de-france-connector-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page_dashboard' ]
		);

		// Submenu 2: Product feed
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Product feed', 'marques-de-france-connector-for-woocommerce' ),
			__( 'Flux de produits', 'marques-de-france-connector-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-feed',
			[ $this, 'render_page_feed' ]
		);

		// Submenu 3: Sales
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Sales tracking', 'marques-de-france-connector-for-woocommerce' ),
			__( 'Suivi des ventes', 'marques-de-france-connector-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-sales',
			[ $this, 'render_page_sales' ]
		);

		// Submenu 4: Settings
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'marques-de-france-connector-for-woocommerce' ),
			__( 'Settings', 'marques-de-france-connector-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-settings',
			[ $this, 'render_page_settings' ]
		);
	}

	// ---------------------------------------------------------------------------
	// Page render callbacks — all mount the React app in a div
	// ---------------------------------------------------------------------------

	public function render_page_dashboard() {
		echo '<div id="mdf-wc-admin" data-page="dashboard"></div>';
	}

	public function render_page_feed() {
		echo '<div id="mdf-wc-admin" data-page="feed"></div>';
	}

	public function render_page_sales() {
		echo '<div id="mdf-wc-admin" data-page="sales"></div>';
	}

	public function render_page_settings() {
		echo '<div id="mdf-wc-admin" data-page="settings"></div>';
	}

	// ---------------------------------------------------------------------------
	// Enqueue React dashboard assets
	// ---------------------------------------------------------------------------

	public function enqueue_dashboard( string $hook ) {
		$admin_pages = [
			'toplevel_page_' . self::MENU_SLUG,
			'marques-de-france_page_' . self::MENU_SLUG . '-feed',
			'marques-de-france_page_' . self::MENU_SLUG . '-sales',
			'marques-de-france_page_' . self::MENU_SLUG . '-settings',
		];

		if ( ! in_array( $hook, $admin_pages, true ) ) {
			return;
		}

		$asset_file = MDFCFORWC_PLUGIN_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		$script_version = file_exists( MDFCFORWC_PLUGIN_DIR . 'build/index.js' )
			? filemtime( MDFCFORWC_PLUGIN_DIR . 'build/index.js' )
			: $asset['version'];

		$style_version = file_exists( MDFCFORWC_PLUGIN_DIR . 'build/style-index.css' )
			? filemtime( MDFCFORWC_PLUGIN_DIR . 'build/style-index.css' )
			: $asset['version'];

		wp_enqueue_script(
			'mdfcforwc-admin',
			MDFCFORWC_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$script_version,
			true
		);

		wp_enqueue_style(
			'mdfcforwc-admin',
			MDFCFORWC_PLUGIN_URL . 'build/style-index.css',
			[ 'wp-components' ],
			$style_version
		);

		// Pass data to JS
		wp_localize_script(
			'mdfcforwc-admin',
			'mdfcforwcAdmin',
			[
				'restUrl'      => esc_url_raw( rest_url( self::REST_NAMESPACE . '/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'feedUrl'      => esc_url_raw( rest_url( 'mdfcforwc/v1/feed' ) ),
				'token'        => MDFCFORWC_Settings::get_secure_token(),
				'feedFilterMode' => MDFCFORWC_Settings::get_feed_filter_mode(),
				'configured'   => MDFCFORWC_Settings::is_configured(),
				'siteUrl'      => MDFCFORWC_Settings::get_site_url(),
				'pluginUrl'    => MDFCFORWC_PLUGIN_URL,
				'settingsUrl'  => esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ),
				'feedAdminUrl' => esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-feed' ) ),
			]
		);

		// Enable JS translations (loads languages/mdfcforwc-admin-{locale}-{hash}.json)
		wp_set_script_translations(
			'mdfcforwc-admin',
			'marques-de-france-connector-for-woocommerce',
			MDFCFORWC_PLUGIN_DIR . 'languages/'
		);
	}

	// ---------------------------------------------------------------------------
	// WP REST: Admin data endpoints (for the React dashboard)
	// ---------------------------------------------------------------------------

	public function register_rest_routes() {
		// GET /wp-json/mdfcforwc/v1/admin/stats
		register_rest_route( self::REST_NAMESPACE, '/admin/stats', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_stats' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
		] );

		// GET /wp-json/mdfcforwc/v1/admin/analytics?dateFrom=&dateTo=&granularity=
		register_rest_route( self::REST_NAMESPACE, '/admin/analytics', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_analytics' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'dateFrom'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'dateTo'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'granularity' => [ 'type' => 'string', 'default' => 'day', 'enum' => [ 'day', 'month' ] ],
			],
		] );

		// GET /wp-json/mdfcforwc/v1/admin/sales?page=&per_page=&status=&search=
		register_rest_route( self::REST_NAMESPACE, '/admin/sales', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_sales' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 25 ],
				'status'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'search'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'dateFrom' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'dateTo'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'sortField'=> [ 'type' => 'string', 'default' => 'created_at' ],
				'sortDir'  => [ 'type' => 'string', 'default' => 'desc', 'enum' => [ 'asc', 'desc' ] ],
			],
		] );

		// GET /wp-json/mdfcforwc/v1/admin/hub-status (ping Hub)
		register_rest_route( self::REST_NAMESPACE, '/admin/hub-status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_hub_status' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
		] );

		// GET /wp-json/mdfcforwc/v1/admin/products
		register_rest_route( self::REST_NAMESPACE, '/admin/products', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_products' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'search'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'sort'     => [ 'type' => 'string',  'default' => 'name-asc' ],
				'page'     => [ 'type' => 'integer', 'default' => 1,  'sanitize_callback' => 'absint' ],
				'per_page' => [ 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ],
			],
		] );

		// POST /wp-json/mdfcforwc/v1/admin/settings — save secure token
		register_rest_route( self::REST_NAMESPACE, '/admin/settings', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'mdfcforwc_secure_token' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
			'callback' => [ $this, 'rest_save_settings' ],
		] );

		// GET + PATCH /wp-json/mdfcforwc/v1/admin/feed-settings
		register_rest_route( self::REST_NAMESPACE, '/admin/feed-settings', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_feed_settings' ],
				'permission_callback' => function () {
					return $this->user_can_access();
				},
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'rest_update_feed_settings' ],
				'permission_callback' => function () {
					return $this->user_can_access();
				},
				'args' => [
					'feedFilterMode' => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => [ 'TAG', 'SERVERLIST' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		] );

		// GET /wp-json/mdfcforwc/v1/admin/all-products
		register_rest_route( self::REST_NAMESPACE, '/admin/all-products', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_all_products' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'search'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'page'     => [ 'type' => 'integer', 'default' => 1,  'sanitize_callback' => 'absint' ],
				'per_page' => [ 'type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint' ],
			],
		] );

		// POST /wp-json/mdfcforwc/v1/admin/feed-products
		register_rest_route( self::REST_NAMESPACE, '/admin/feed-products', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_add_feed_product' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
			'args' => [
				'productId' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// DELETE /wp-json/mdfcforwc/v1/admin/feed-products/(?P<productId>[0-9]+)
		register_rest_route( self::REST_NAMESPACE, '/admin/feed-products/(?P<productId>[0-9]+)', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'rest_remove_feed_product' ],
			'permission_callback' => function () {
				return $this->user_can_access();
			},
		] );
	}

	public function rest_save_settings( WP_REST_Request $request ): WP_REST_Response {
		$token = $request->get_param( 'mdfcforwc_secure_token' );
		update_option( 'mdfcforwc_secure_token', $token );
		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	public function rest_get_feed_settings(): WP_REST_Response {
		return new WP_REST_Response(
			[ 'feedFilterMode' => MDFCFORWC_Settings::get_feed_filter_mode() ],
			200
		);
	}

	public function rest_update_feed_settings( WP_REST_Request $request ): WP_REST_Response {
		$new_mode     = $request->get_param( 'feedFilterMode' );
		$current_mode = MDFCFORWC_Settings::get_feed_filter_mode();

		// Auto-import tagged products when switching TAG → SERVERLIST.
		if ( 'SERVERLIST' === $new_mode && 'TAG' === $current_mode ) {
			MDFCFORWC_Feed_Products::import_tagged_products();
		}

		MDFCFORWC_Settings::set_feed_filter_mode( $new_mode );
		return new WP_REST_Response( [ 'feedFilterMode' => $new_mode ], 200 );
	}

	public function rest_all_products( WP_REST_Request $request ): WP_REST_Response {
		$search   = $request->get_param( 'search' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$wp_args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( $search ) {
			$wp_args['s']              = $search;
			$this->product_search_term = $search;
			add_filter( 'posts_search', [ $this, 'extend_product_search_with_sku' ], 10, 2 );
		}

		$wp_query = new WP_Query( $wp_args );

		if ( $search ) {
			remove_filter( 'posts_search', [ $this, 'extend_product_search_with_sku' ], 10 );
			$this->product_search_term = '';
		}

		$total       = (int) $wp_query->found_posts;
		$total_pages = (int) ceil( $total / $per_page );

		// Pre-fetch all feed product IDs for O(1) lookup in the loop.
		$feed_ids     = MDFCFORWC_Feed_Products::get_selected_product_ids();
		$feed_ids_set = array_flip( $feed_ids );

		$items = [];

		foreach ( $wp_query->posts as $post ) {
			$product = wc_get_product( $post->ID );

			if ( ! $product ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'thumbnail' ) : false;
			$image     = $image_src ? $image_src[0] : wc_placeholder_img_src();

			$brand_post = function_exists( 'get_field' ) ? get_field( 'product_listing', $product->get_id() ) : null;
			$brand      = ( $brand_post && isset( $brand_post->post_title ) ) ? $brand_post->post_title : '';

			$items[] = [
				'id'           => $product->get_id(),
				'name'         => $product->get_name(),
				'image'        => $image,
				'price'        => (float) $product->get_price(),
				'price_html'   => $product->get_price_html(),
				'brand'        => $brand,
				'status'       => get_post_status( $product->get_id() ),
				'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
				'inFeed'       => isset( $feed_ids_set[ $product->get_id() ] ),
				'edit_url'     => get_edit_post_link( $product->get_id(), 'raw' ),
			];
		}

		return rest_ensure_response( [
			'products'    => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'currency'    => get_woocommerce_currency(),
			'inFeedCount' => MDFCFORWC_Feed_Products::get_count(),
		] );
	}

	public function rest_add_feed_product( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'productId' );

		if ( ! get_post( $product_id ) ) {
			return new WP_REST_Response( [ 'error' => 'Product not found' ], 404 );
		}

		$success = MDFCFORWC_Feed_Products::add_product( $product_id );
		return new WP_REST_Response(
			[ 'success' => $success, 'inFeedCount' => MDFCFORWC_Feed_Products::get_count() ],
			$success ? 200 : 500
		);
	}

	public function rest_remove_feed_product( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'productId' );
		$success    = MDFCFORWC_Feed_Products::remove_product( $product_id );
		return new WP_REST_Response(
			[ 'success' => $success, 'inFeedCount' => MDFCFORWC_Feed_Products::get_count() ],
			200
		);
	}

	// ── REST Callbacks ──────────────────────────────────────────────────────────

	/**
	 * Admin notice shown when one or more attributed sales have been waiting
	 * to sync to the MDF Hub for more than 24 hours.
	 *
	 * Result is cached in a transient (10 min) to avoid a DB query on every
	 * admin page load. The transient is cleared by MDFCFORWC_Hub_Client::mark_synced()
	 * so the notice disappears as soon as all rows are resolved.
	 */
	public function render_unsynced_notice() {
		if ( ! $this->user_can_access() ) {
			return;
		}

		$count = get_transient( 'mdfcforwc_unsynced_notice_count' );

		if ( false === $count ) {
			global $wpdb;
			$table     = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );
			$threshold = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE hub_synced = 0 AND status = 'confirmed' AND created_at <= %s",
					$threshold
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			set_transient( 'mdfcforwc_unsynced_notice_count', $count, 10 * MINUTE_IN_SECONDS );
		}

		if ( ! $count ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );

		echo '<div class="notice notice-warning">';
		echo '<p>';
		/* translators: 1: number of unsynced sales, 2: URL of the plugin settings page */
		$notice = _n(
			'<strong>Marques de France</strong>: %1$d sale has not been synced to the MDF Hub for more than 24\u00a0hours. <a href="%2$s">Check your connection settings</a>.',
			'<strong>Marques de France</strong>: %1$d sales have not been synced to the MDF Hub for more than 24\u00a0hours. <a href="%2$s">Check your connection settings</a>.',
			$count,
			'marques-de-france-connector-for-woocommerce'
		);
		printf(
			wp_kses( $notice, [ 'strong' => [], 'a' => [ 'href' => [] ] ] ),
			absint( $count ),
			esc_url( $settings_url )
		);
		echo '</p></div>';
	}

	/**
	 * Show a one-time admin notice (on MDF admin pages) when the sales table may
	 * be missing historical data — e.g. after an uninstall / reinstall cycle.
	 * The notice disappears once the backfill has been run.
	 */
	public function render_backfill_notice() {
		if ( ! $this->user_can_access() ) {
			return;
		}

		// Notice only on MDF plugin pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$mdf_pages = [
			'toplevel_page_' . self::MENU_SLUG,
			'marques-de-france_page_' . self::MENU_SLUG . '-feed',
			'marques-de-france_page_' . self::MENU_SLUG . '-sales',
			'marques-de-france_page_' . self::MENU_SLUG . '-settings',
		];

		if ( ! in_array( $screen->id, $mdf_pages, true ) ) {
			return;
		}

		// Show success/error banner after a completed backfill.
		$backfill_result = get_transient( 'mdfcforwc_backfill_result' );
		if ( false !== $backfill_result ) {
			delete_transient( 'mdfcforwc_backfill_result' );
			if ( 'error' === $backfill_result ) {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo '<strong>' . esc_html__( 'Marques de France', 'marques-de-france-connector-for-woocommerce' ) . '</strong> — ';
				echo esc_html__( 'Could not reach the MDF Hub. Check your connection settings and try again.', 'marques-de-france-connector-for-woocommerce' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %d: number of sales restored */
					esc_html( _n(
						'Marques de France: %d sale restored from the MDF Hub.',
						'Marques de France: %d sales restored from the MDF Hub.',
						(int) $backfill_result,
						'marques-de-france-connector-for-woocommerce'
					) ),
					absint( $backfill_result )
				);
				echo '</p></div>';
			}
			return;
		}

		// Show the restore button if:
		//  - Plugin is configured (Hub token known), AND
		//  - The backfill has not been explicitly run yet.
		// We do NOT check WC order meta here — that had HPOS compatibility issues and
		// is no longer the source of truth. The Hub is.
		if ( get_option( 'mdfcforwc_backfill_done' ) || ! MDFCFORWC_Settings::is_configured() ) {
			return;
		}

		echo '<div class="notice notice-warning">';
		echo '<p><strong>' . esc_html__( 'Marques de France', 'marques-de-france-connector-for-woocommerce' ) . '</strong> — ';
		echo esc_html__( 'Sales data may be out of sync (e.g. after a plugin reinstall). Click below to restore from the MDF Hub — this will replace the local sales table with the Hub\'s records.', 'marques-de-france-connector-for-woocommerce' );
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:10px">';
		echo '<input type="hidden" name="action" value="mdfcforwc_backfill" />';
		wp_nonce_field( 'mdfcforwc_backfill', 'mdfcforwc_backfill_nonce' );
		echo '<button type="submit" class="button button-primary">';
		echo esc_html__( 'Restore sales from Hub', 'marques-de-france-connector-for-woocommerce' );
		echo '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle the "Restore historical sales" form submission.
	 */
	public function handle_backfill() {
		check_admin_referer( 'mdfcforwc_backfill', 'mdfcforwc_backfill_nonce' );

		if ( ! $this->user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'marques-de-france-connector-for-woocommerce' ) );
		}

		require_once MDFCFORWC_PLUGIN_DIR . 'includes/class-mdf-wc-activator.php';
		// truncate_first = true: wipes the local table then repopulates from Hub.
		$count = MDFCFORWC_Activator::backfill_from_hub( true );

		if ( $count >= 0 ) {
			update_option( 'mdfcforwc_backfill_done', '1' );
			set_transient( 'mdfcforwc_backfill_result', $count, MINUTE_IN_SECONDS );
		} else {
			set_transient( 'mdfcforwc_backfill_result', 'error', MINUTE_IN_SECONDS );
		}

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::MENU_SLUG . '-sales' );
		wp_safe_redirect( esc_url_raw( $redirect ) );
		exit;
	}

	// ── REST Callbacks ──────────────────────────────────────────────────────────

	public function rest_stats() {
		$this->sync_sales_from_hub_if_needed();
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$confirmed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'confirmed'" );
		$revenue   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM `{$table}` WHERE status = 'confirmed'" );
		$unsynced  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE hub_synced = 0 AND status = 'confirmed'" );

		// This-month stats
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$month_rev   = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM `{$table}` WHERE status='confirmed' AND created_at >= %s",
				$month_start
			)
		);
		$month_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status='confirmed' AND created_at >= %s",
				$month_start
			)
		);
		// phpcs:enable

		return rest_ensure_response( [
			'totalSales'      => $total,
			'confirmedSales'  => $confirmed,
			'totalRevenue'    => $revenue,
			'unsyncedSales'   => $unsynced,
			'currency'        => get_woocommerce_currency(),
			'monthRevenue'    => $month_rev,
			'monthSales'      => $month_count,
			'configured'      => MDFCFORWC_Settings::is_configured(),
		] );
	}

	public function rest_analytics( WP_REST_Request $request ) {
		$this->sync_sales_from_hub_if_needed();
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'mdfcforwc_sales' );

		$date_from   = $request->get_param( 'dateFrom' ) ?: gmdate( 'Y-m-01', strtotime( '-11 months' ) );
		$date_to     = $request->get_param( 'dateTo' )   ?: gmdate( 'Y-m-t' ); // end of current month
		$granularity = in_array( $request->get_param( 'granularity' ), [ 'day', 'month' ], true )
			? $request->get_param( 'granularity' )
			: 'month';

		$from_dt = $date_from . ' 00:00:00';
		$to_dt   = $date_to   . ' 23:59:59';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, amount FROM `{$table}` WHERE status = 'confirmed' AND created_at BETWEEN %s AND %s ORDER BY created_at ASC",
				$from_dt,
				$to_dt
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Aggregate by granularity
		$map = [];
		foreach ( $rows as $row ) {
			$dt  = new DateTime( $row->created_at, new DateTimeZone( 'UTC' ) );
			$key = $granularity === 'month' ? $dt->format( 'Y-m' ) : $dt->format( 'Y-m-d' );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = [ 'date' => $key, 'revenue' => 0, 'conversions' => 0 ];
			}
			$map[ $key ]['revenue']     += (float) $row->amount;
			$map[ $key ]['conversions'] += 1;
		}

		// Fill gaps.
		// For monthly granularity, align the loop start to the 1st of the month.
		// Without this, a date_from of e.g. 2026-03-23 would jump to 2026-04-23,
		// skipping April entirely when date_to is 2026-04-20.
		$fill_from = $granularity === 'month'
			? gmdate( 'Y-m-01', strtotime( $date_from ) )
			: $date_from;

		$data    = [];
		$current = new DateTime( $fill_from, new DateTimeZone( 'UTC' ) );
		$end     = new DateTime( $date_to, new DateTimeZone( 'UTC' ) );
		$seen    = [];

		while ( $current <= $end ) {
			$key = $granularity === 'month' ? $current->format( 'Y-m' ) : $current->format( 'Y-m-d' );
			if ( ! in_array( $key, $seen, true ) ) {
				$seen[]  = $key;
				$data[]  = $map[ $key ] ?? [ 'date' => $key, 'revenue' => 0, 'conversions' => 0 ];
			}
			$granularity === 'month' ? $current->modify( '+1 month' ) : $current->modify( '+1 day' );
		}

		return rest_ensure_response( [ 'data' => $data, 'currency' => get_woocommerce_currency() ] );
	}

	public function rest_sales( WP_REST_Request $request ) {
		$this->sync_sales_from_hub_if_needed();
		global $wpdb;
		// Raw table name — %i placeholder (WP 6.2+) backtick-quotes it safely.
		$table_name = $wpdb->prefix . 'mdfcforwc_sales';

		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset    = ( $page - 1 ) * $per_page;
		$status    = $request->get_param( 'status' );
		$search    = $request->get_param( 'search' );
		$date_from = $request->get_param( 'dateFrom' );
		$date_to   = $request->get_param( 'dateTo' );
		$sort_f    = $request->get_param( 'sortField' );
		$sort_d    = $request->get_param( 'sortDir' );

		$valid_fields = [ 'created_at', 'amount', 'order_id', 'status' ];
		$sort_col     = in_array( $sort_f, $valid_fields, true ) ? $sort_f : 'created_at';
		$sort_asc     = ( $sort_d === 'asc' );

		$status_active    = ( $status && in_array( $status, [ 'confirmed', 'cancelled', 'refunded', 'pending' ], true ) ) ? 1 : 0;
		$status_val       = $status_active ? sanitize_text_field( $status ) : '';
		$search_active    = ! empty( $search ) ? 1 : 0;
		$like_val         = $search_active ? '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%' : '';
		$date_from_active = ! empty( $date_from ) ? 1 : 0;
		$date_from_val    = $date_from_active ? sanitize_text_field( $date_from ) . ' 00:00:00' : '';
		$date_to_active   = ! empty( $date_to ) ? 1 : 0;
		$date_to_val      = $date_to_active ? sanitize_text_field( $date_to ) . ' 23:59:59' : '';

		$conditions = [];
		$params     = [];

		if ( $status_active ) {
			$conditions[] = 'status = %s';
			$params[]     = $status_val;
		}

		if ( $search_active ) {
			$conditions[] = '( order_id LIKE %s OR order_number LIKE %s )';
			$params[]     = $like_val;
			$params[]     = $like_val;
		}

		if ( $date_from_active ) {
			$conditions[] = 'created_at >= %s';
			$params[]     = $date_from_val;
		}

		if ( $date_to_active ) {
			$conditions[] = 'created_at <= %s';
			$params[]     = $date_to_val;
		}

		$table_name_quoted = '`' . esc_sql( $table_name ) . '`';
		$sort_col_quoted   = '`' . esc_sql( $sort_col ) . '`';
		$where_sql         = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_sql = 'SELECT COUNT(*) FROM ' . $table_name_quoted . $where_sql;
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) )
			: $wpdb->get_var( $total_sql )
		);

		$rows_sql = 'SELECT * FROM ' . $table_name_quoted . $where_sql . ' ORDER BY ' . $sort_col_quoted . ( $sort_asc ? ' ASC' : ' DESC' ) . ' LIMIT %d OFFSET %d';
		$rows     = $wpdb->get_results(
			$params
				? $wpdb->prepare( $rows_sql, ...array_merge( $params, [ $per_page, $offset ] ) )
				: $wpdb->prepare( 'SELECT * FROM ' . $table_name_quoted . $where_sql . ' ORDER BY ' . $sort_col_quoted . ( $sort_asc ? ' ASC' : ' DESC' ) . ' LIMIT %d OFFSET %d', $per_page, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		return rest_ensure_response( [
			'sales'    => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		] );
	}

	public function rest_hub_status() {
		if ( ! MDFCFORWC_Settings::is_configured() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] hub-status: not configured (token is empty)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return rest_ensure_response( [ 'connected' => false, 'reason' => 'not_configured' ] );
		}

		$hub_url  = MDFCFORWC_Settings::get_hub_url();
		$token    = MDFCFORWC_Settings::get_secure_token();
		$site_url = MDFCFORWC_Settings::get_site_url();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MDF-WC] hub-status ping — hub_url: ' . $hub_url . ', shop: ' . $site_url . ', token: ' . substr( $token, 0, 8 ) . '…' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$response = wp_remote_get( $hub_url . '/api/wc/status', [
			'timeout'   => 8,
			'sslverify' => ( strpos( MDFCFORWC_HUB_URL, 'flux.marques-de-france.fr' ) !== false ),
			'headers'   => [
				'X-MDF-Token'      => $token,
				'X-MDF-Shop'       => $site_url,
				'X-Plugin-Version' => MDFCFORWC_VERSION,
			],
		] );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MDF-WC] hub-status ERROR: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return rest_ensure_response( [
				'connected' => false,
				'reason'    => $response->get_error_message(),
			] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MDF-WC] hub-status response: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$result = [
			'connected'  => $code === 200,
			'httpStatus' => $code,
			'reason'     => $body['error'] ?? null, // Hub returns 'error' key; expose as 'reason'
		];

		// Merge any extra fields the Hub returns (store info, totals)
		if ( is_array( $body ) ) {
			unset( $body['error'] ); // already mapped to 'reason'
			$result = array_merge( $result, $body );
		}

		return rest_ensure_response( $result );
	}

	public function rest_products( WP_REST_Request $request ) {
		$search   = $request->get_param( 'search' );
		$sort     = $request->get_param( 'sort' ) ?: 'name-asc';
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$mode     = MDFCFORWC_Settings::get_feed_filter_mode();

		// Map sort option to WP_Query args (WC-native 'price' orderby is unavailable
		// when using WP_Query directly, so price sorts use meta_value_num + _price).
		$sort_map = [
			'name-asc'   => [ 'orderby' => 'title',          'order' => 'ASC'  ],
			'name-desc'  => [ 'orderby' => 'title',          'order' => 'DESC' ],
			'price-asc'  => [ 'orderby' => 'meta_value_num', 'order' => 'ASC',  'meta_key' => '_price' ],
			'price-desc' => [ 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_key' => '_price' ],
			'brand-asc'  => [ 'orderby' => 'title',          'order' => 'ASC'  ],
		];
		$order_args = $sort_map[ $sort ] ?? $sort_map['name-asc'];

		// Use WP_Query directly: wc_get_products() in WC 10.7+ auto-injects a
		// product_type tax_query restricted to types registered in wc_get_product_types(),
		// which silently excludes composite products and other third-party types.
		$wp_args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $order_args['orderby'],
			'order'          => $order_args['order'],
		];

		if ( 'SERVERLIST' === $mode ) {
			// SERVERLIST mode: show only products explicitly selected for the feed.
			$feed_ids = MDFCFORWC_Feed_Products::get_selected_product_ids();
			if ( empty( $feed_ids ) ) {
				return rest_ensure_response( [
					'products'    => [],
					'total'       => 0,
					'total_pages' => 1,
					'page'        => $page,
					'per_page'    => $per_page,
					'currency'    => get_woocommerce_currency(),
					'inFeedCount' => 0,
				] );
			}
			$wp_args['post__in'] = $feed_ids;
		} else {
			// TAG mode: products with the 'marques-de-france' tag, in-stock, non-virtual.
			$wp_args['tax_query'] = [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => 'marques-de-france',
				],
			];
			$wp_args['meta_query'] = [
				'relation' => 'AND',
				[
					'key'     => '_virtual',
					'value'   => 'yes',
					'compare' => '!=',
				],
				[
					'key'   => '_stock_status',
					'value' => 'instock',
				],
			];
		}

		if ( isset( $order_args['meta_key'] ) ) {
			$wp_args['meta_key'] = $order_args['meta_key'];
		}

		if ( $search ) {
			$wp_args['s']              = $search;
			$this->product_search_term = $search;
			add_filter( 'posts_search', [ $this, 'extend_product_search_with_sku' ], 10, 2 );
		}

		$wp_query = new WP_Query( $wp_args );

		if ( $search ) {
			remove_filter( 'posts_search', [ $this, 'extend_product_search_with_sku' ], 10 );
			$this->product_search_term = '';
		}

		$raw_items = [];

		foreach ( $wp_query->posts as $post ) {
			$product = wc_get_product( $post->ID );

			if ( ! $product ) {
				continue;
			}

			if ( $product->is_virtual() ) {
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				continue;
			}

			$price = (float) $product->get_price();
			if ( $price <= 0 ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'thumbnail' ) : false;
			$image     = $image_src ? $image_src[0] : wc_placeholder_img_src();

			$total_variants     = 0;
			$available_variants = 0;

			if ( $product->is_type( 'variable' ) ) {
				$variation_ids  = $product->get_children();
				$total_variants = count( $variation_ids );
				foreach ( $variation_ids as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v && $v->is_in_stock() && $v->is_purchasable() ) {
						$available_variants++;
					}
				}

				if ( 0 === $available_variants ) {
					continue;
				}
			}

			$brand_post = function_exists( 'get_field' ) ? get_field( 'product_listing', $product->get_id() ) : null;
			$brand      = ( $brand_post && isset( $brand_post->post_title ) ) ? $brand_post->post_title : '';

			$raw_items[] = [
				'id'                 => $product->get_id(),
				'name'               => $product->get_name(),
				'image'              => $image,
				'price'              => $price,
				'price_html'         => $product->get_price_html(),
				'currency'           => get_woocommerce_currency(),
				'brand'              => $brand,
				'status'             => get_post_status( $product->get_id() ),
				'availability'       => $product->is_in_stock() ? 'in stock' : 'out of stock',
				'has_mdf_tag'        => 'SERVERLIST' === $mode
				? has_term( 'marques-de-france', 'product_tag', $product->get_id() )
				: true,
				'type'               => $product->get_type(),
				'total_variants'     => $total_variants,
				'available_variants' => $available_variants,
				'edit_url'           => get_edit_post_link( $product->get_id(), 'raw' ),
			];
		}

		$total       = count( $raw_items );
		$total_pages = (int) ceil( $total / $per_page );
		$offset      = ( $page - 1 ) * $per_page;
		$items       = array_slice( $raw_items, $offset, $per_page );

		$response = [
			'products'    => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'currency'    => get_woocommerce_currency(),
		];

		if ( 'SERVERLIST' === $mode ) {
			$response['inFeedCount'] = MDFCFORWC_Feed_Products::get_count();
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Extends the WP_Query posts_search clause to also match products by SKU (_sku meta).
	 * Hooked temporarily during rest_products() only — never active outside that context.
	 *
	 * @param string   $search  The current SQL search clause (e.g. " AND ((post_title LIKE '%x%')…))").
	 * @param WP_Query $query   The current WP_Query instance.
	 * @return string Modified search clause.
	 */
	public function extend_product_search_with_sku( string $search, \WP_Query $query ): string {
		global $wpdb;

		if ( empty( $search ) || ! $this->product_search_term ) {
			return $search;
		}

		$sku_sql = $wpdb->prepare(
			"{$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s)",
			'%' . $wpdb->esc_like( $this->product_search_term ) . '%'
		);

		// The $search clause ends with one or more closing parens.
		// Inject our OR condition just before the last one.
		$last = strrpos( $search, ')' );
		if ( false !== $last ) {
			$search = substr( $search, 0, $last ) . " OR {$sku_sql}" . substr( $search, $last );
		}

		return $search;
	}

	// ---------------------------------------------------------------------------
	// WooCommerce Order Metabox
	// ---------------------------------------------------------------------------

	public function register_order_metabox() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'mdfcforwc_attribution',
			__( 'Marques de France – Attribution', 'marques-de-france-connector-for-woocommerce' ),
			[ $this, 'render_order_metabox' ],
			$screen,
			'side',
			'default'
		);
	}

	public function render_order_metabox( $post_or_order ) {
		// Support both HPOS (WC_Order) and legacy (WP_Post) contexts
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$attr = MDFCFORWC_Attribution::get_order_attribution( $order );

		// Only render for MDF-attributed orders
		if ( $attr['attributed'] !== '1' || empty( $attr['source'] ) ) {
			echo '<p style="color:#888;font-size:12px;margin:8px 0;">' . esc_html__( 'No attribution detected for this order.', 'marques-de-france-connector-for-woocommerce' ) . '</p>';
			return;
		}

		$rows = [
			__( 'Source', 'marques-de-france-connector-for-woocommerce' )       => $attr['source'],
			__( 'UTM Source', 'marques-de-france-connector-for-woocommerce' )   => $attr['utm_source'],
			__( 'UTM Medium', 'marques-de-france-connector-for-woocommerce' )   => $attr['utm_medium'],
			__( 'UTM Campaign', 'marques-de-france-connector-for-woocommerce' ) => $attr['utm_campaign'],
			__( 'UTM Content', 'marques-de-france-connector-for-woocommerce' )  => $attr['utm_content'],
			__( 'UTM Term', 'marques-de-france-connector-for-woocommerce' )     => $attr['utm_term'],
			__( 'Landing Site', 'marques-de-france-connector-for-woocommerce' ) => $attr['landing_site'],
			__( 'Referring Site', 'marques-de-france-connector-for-woocommerce' ) => $attr['referring_site'],
			__( 'Ref Param', 'marques-de-france-connector-for-woocommerce' )    => $attr['landing_ref'],
		];

		echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value || null === $value ) continue;
			printf(
				'<tr><th style="text-align:left;padding:3px 4px;color:#555;font-weight:600;white-space:nowrap;vertical-align:top;">%s</th><td style="padding:3px 4px;word-break:break-all;">%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}
		echo '</table>';
	}

	public function save_order_metabox( $order_id ) {
		// Meta is set during checkout, not editable from metabox
	}
}
