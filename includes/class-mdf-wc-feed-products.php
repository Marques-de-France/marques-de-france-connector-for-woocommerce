<?php
/**
 * Feed Products CRUD
 *
 * Static helper for the wp_mdfcforwc_feed_products table, which stores the
 * product IDs explicitly selected for the Marques de France product feed
 * when the SERVERLIST filter mode is active.
 *
 * @package MDFCFORWC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDFCFORWC_Feed_Products {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mdfcforwc_feed_products';
	}

	/**
	 * Returns all product IDs currently selected for the feed, ordered by most-recently added.
	 *
	 * @return int[]
	 */
	public static function get_selected_product_ids(): array {
		global $wpdb;
		$table = esc_sql( self::table() ); // table name comes from $wpdb->prefix, not user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only schema query; table name is escaped.
		$rows = $wpdb->get_col( "SELECT product_id FROM `{$table}` ORDER BY added_at DESC" );
		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Adds a product to the feed (idempotent — replaces if already present).
	 *
	 * @param int $product_id WooCommerce product post ID.
	 * @return bool True on success.
	 */
	public static function add_product( int $product_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; caching is not applicable.
		$result = $wpdb->replace(
			self::table(),
			[ 'product_id' => $product_id ],
			[ '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Removes a product from the feed.
	 *
	 * @param int $product_id WooCommerce product post ID.
	 * @return bool True on success.
	 */
	public static function remove_product( int $product_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; caching is not applicable.
		$result = $wpdb->delete(
			self::table(),
			[ 'product_id' => $product_id ],
			[ '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Checks whether a given product is in the feed.
	 *
	 * @param int $product_id WooCommerce product post ID.
	 * @return bool
	 */
	public static function is_in_feed( int $product_id ): bool {
		global $wpdb;
		$table = esc_sql( self::table() ); // table name from $wpdb->prefix, not user input
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql(); product_id is an int placeholder.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE product_id = %d", $product_id )
		);
		// phpcs:enable
		return $count > 0;
	}

	/**
	 * Returns the total number of products in the feed.
	 *
	 * @return int
	 */
	public static function get_count(): int {
		global $wpdb;
		$table = esc_sql( self::table() ); // table name from $wpdb->prefix, not user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only COUNT; table name is escaped.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Imports all products currently tagged with 'marques-de-france' into the feed.
	 * Used when switching from TAG mode to SERVERLIST to pre-populate the selection.
	 * Safe to call multiple times — INSERT IGNORE prevents duplicates.
	 */
	public static function import_tagged_products(): void {
		$tagged_posts = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => 'marques-de-france',
				],
			],
		] );

		if ( empty( $tagged_posts ) ) {
			return;
		}

		global $wpdb;
		$table        = self::table();
		$now          = current_time( 'mysql' );
		$values       = [];
		$placeholders = [];

		foreach ( $tagged_posts as $post_id ) {
			$values[]       = (int) $post_id;
			$values[]       = $now;
			$placeholders[] = '(%d, %s)';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table name is from $wpdb->prefix (not user input). Placeholders are built programmatically from the $tagged_posts array; values are spread as positional args.
		$sql = $wpdb->prepare(
			'INSERT IGNORE INTO `' . $table . '` (product_id, added_at) VALUES ' . implode( ', ', $placeholders ),
			...$values
		);
		$wpdb->query( $sql );
		// phpcs:enable
	}
}
