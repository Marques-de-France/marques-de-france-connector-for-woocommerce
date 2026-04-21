<?php
/**
 * Product Feed
 *
 * Exposes a Google Merchant-compatible RSS 2.0 product feed via a WP REST endpoint.
 *
 * Endpoint: GET /wp-json/mdf-wc/v1/feed?token=<secureToken>
 *
 * Feed format:
 *   RSS 2.0 with Google Merchant Center (g:) namespace extensions.
 *   One <item> per WC product or variation with availability, price, image, etc.
 *   Paginated: ?per_page=200&page=1 (default: 200 items per page).
 *
 * Authentication:
 *   ?token=<secureToken>  — same token stored in plugin settings.
 *   No user auth required — the token itself gates access.
 *
 * @package MDF_WC_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MDF_WC_Feed {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// ---------------------------------------------------------------------------
	// REST route registration
	// ---------------------------------------------------------------------------

	public function register_routes() {
		register_rest_route(
			'mdf-wc/v1',
			'/feed',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'serve_feed' ],
				'permission_callback' => '__return_true', // token-gated inside callback
				'args'                => [
					'token'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'per_page' => [
						'default'           => 200,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'page'     => [
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	// ---------------------------------------------------------------------------
	// Feed callback
	// ---------------------------------------------------------------------------

	public function serve_feed( WP_REST_Request $request ) {
		$token    = $request->get_param( 'token' );
		$per_page = min( 500, max( 1, $request->get_param( 'per_page' ) ) );
		$page     = max( 1, $request->get_param( 'page' ) );

		// Token gate — constant-time comparison
		$stored_token = MDF_WC_Settings::get_secure_token();
		if ( '' === $stored_token ) {
			return new WP_Error( 'not_configured', 'Plugin not configured.', [ 'status' => 503 ] );
		}

		$token_a = hash( 'sha256', $token );
		$token_b = hash( 'sha256', $stored_token );
		if ( ! hash_equals( $token_b, $token_a ) ) {
			return new WP_Error( 'forbidden', 'Invalid token.', [ 'status' => 403 ] );
		}

		// Query products
		$products = $this->get_feed_products( $per_page, $page );

		// Generate RSS XML
		$xml = $this->build_rss( $products );

		// Clear any output WordPress has buffered, then serve raw XML.
		// This is required because WP REST API sets Content-Type: application/json
		// before our callback runs; flushing all ob levels lets us override it.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	// ---------------------------------------------------------------------------
	// Product query
	// ---------------------------------------------------------------------------

	private function get_feed_products( int $per_page, int $page ): array {
		$args = [
			'status'         => 'publish',
			'virtual'        => false,
			'type'           => [ 'simple', 'variable', 'grouped' ],
			'limit'          => $per_page,
			'page'           => $page,
			'paginate'       => false,
			'stock_status'   => 'instock',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tax_query'      => [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => 'marques-de-france',
				],
			],
		];

		$products = wc_get_products( $args );

		$items = [];

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				// Collect all in-stock purchasable variations first
				$valid_variations = [];
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation && $variation->is_in_stock() && $variation->is_purchasable() ) {
						$valid_variations[] = $variation;
					}
				}

				// Determine cheapest variant price across the group
				$prices    = array_filter( array_map( fn( $v ) => (float) $v->get_price(), $valid_variations ) );
				$min_price = $prices ? min( $prices ) : null;

				foreach ( $valid_variations as $variation ) {
					$is_cheapest = $min_price !== null && (float) $variation->get_price() === $min_price;
					$items[]     = $this->normalise_variation( $variation, $product, $is_cheapest );
				}
			} else {
				$items[] = $this->normalise_product( $product );
			}
		}

		return $items;
	}

	// ---------------------------------------------------------------------------
	// Normalisation helpers
	// ---------------------------------------------------------------------------

	private function normalise_product( WC_Product $product ): array {
		$image_id  = $product->get_image_id();
		$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
		$image_url = $image_src ? $image_src[0] : wc_placeholder_img_src();

		$category = '';
		$terms    = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$category = implode( ' > ', array_map( fn( $t ) => $t->name, $terms ) );
		}

		$gallery_ids       = $product->get_gallery_image_ids();
		$additional_images = array_values( array_filter( array_map( function( $id ) {
			$src = wp_get_attachment_image_src( $id, 'large' );
			return $src ? $src[0] : '';
		}, $gallery_ids ) ) );

		$tag_terms = get_the_terms( $product->get_id(), 'product_tag' );
		$tags      = ( $tag_terms && ! is_wp_error( $tag_terms ) ) ? array_map( fn( $t ) => $t->name, $tag_terms ) : [];

		$link  = get_permalink( $product->get_id() );
		$title = $product->get_name();
		$gtin  = (string) $product->get_meta( '_wc_gtin' );
		$mpn   = $product->get_sku();

		return [
			'id'                  => (string) $product->get_id(),
			'title'               => $title,
			'parent_title'        => $title,
			'parent_link'         => $link,
			'description'         => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'short_description'   => wp_strip_all_tags( $product->get_short_description() ),
			'link'                => $link,
			'image'               => $image_url,
			'additional_images'   => $additional_images,
			'price'               => $product->get_price(),
			'regular_price'       => $product->get_regular_price(),
			'sale_price'          => $product->get_sale_price(),
			'currency'            => get_woocommerce_currency(),
			'sku'                 => $product->get_sku(),
			'availability'        => $product->is_in_stock() ? 'in stock' : 'out of stock',
			'condition'           => 'new',
			'category'            => $category,
			'brand'               => $this->get_product_brand( $product->get_id() ),
			'gtin'                => $gtin,
			'mpn'                 => $mpn,
			'tags'                => $tags,
			'color'               => '',
			'identifier_exists'   => ! empty( $gtin ) || ! empty( $mpn ),
			'has_variants'        => false,
			'is_cheapest_variant' => true,
			'woo_product_type'    => $product->get_type(),
			'attributes'          => $this->get_product_attributes( $product ),
			'item_group_id'       => (string) $product->get_id(),
			'shipping_weight'     => $product->get_weight() ? ( $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' ) ) : '',
			'shipping_length'     => $product->get_length() ? ( $product->get_length() . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_width'      => $product->get_width()  ? ( $product->get_width()  . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_height'     => $product->get_height() ? ( $product->get_height() . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_label'      => $product->get_shipping_class(),
			'add_to_cart_url'     => add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() ),
		];
	}

	private function normalise_variation( WC_Product_Variation $variation, WC_Product $parent, bool $is_cheapest = false ): array {
		$image_id  = $variation->get_image_id() ?: $parent->get_image_id();
		$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
		$image_url = $image_src ? $image_src[0] : wc_placeholder_img_src();

		// Additional images from parent gallery (variation image is already the main image)
		$parent_gallery_ids = $parent->get_gallery_image_ids();
		$additional_images  = array_values( array_filter( array_map( function( $id ) {
			$src = wp_get_attachment_image_src( $id, 'large' );
			return $src ? $src[0] : '';
		}, $parent_gallery_ids ) ) );

		// Build human-readable title from parent title + variation attributes
		$attr_values = array_filter( array_values( $variation->get_variation_attributes() ) );
		$title       = $parent->get_name();
		if ( $attr_values ) {
			$title .= ' – ' . implode( ', ', $attr_values );
		}

		$category = '';
		$terms    = get_the_terms( $parent->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$category = implode( ' > ', array_map( fn( $t ) => $t->name, $terms ) );
		}

		$tag_terms = get_the_terms( $parent->get_id(), 'product_tag' );
		$tags      = ( $tag_terms && ! is_wp_error( $tag_terms ) ) ? array_map( fn( $t ) => $t->name, $tag_terms ) : [];

		$link = get_permalink( $parent->get_id() );
		$gtin = (string) $variation->get_meta( '_wc_gtin' );
		$mpn  = $variation->get_sku() ?: $parent->get_sku();

		return [
			'id'                  => $parent->get_id() . '_' . $variation->get_id(),
			'title'               => $title,
			'parent_title'        => $parent->get_name(),
			'parent_link'         => $link,
			'description'         => wp_strip_all_tags( $parent->get_description() ?: $parent->get_short_description() ),
			'short_description'   => wp_strip_all_tags( $parent->get_short_description() ),
			'link'                => $link,
			'image'               => $image_url,
			'additional_images'   => $additional_images,
			'price'               => $variation->get_price(),
			'regular_price'       => $variation->get_regular_price(),
			'sale_price'          => $variation->get_sale_price(),
			'currency'            => get_woocommerce_currency(),
			'sku'                 => $variation->get_sku() ?: ( $parent->get_sku() . '-' . $variation->get_id() ),
			'availability'        => $variation->is_in_stock() ? 'in stock' : 'out of stock',
			'condition'           => 'new',
			'category'            => $category,
			'brand'               => $this->get_product_brand( $parent->get_id() ),
			'gtin'                => $gtin,
			'mpn'                 => $mpn,
			'tags'                => $tags,
			'color'               => $this->get_variation_color( $variation ),
			'identifier_exists'   => ! empty( $gtin ) || ! empty( $mpn ),
			'has_variants'        => true,
			'is_cheapest_variant' => $is_cheapest,
			'woo_product_type'    => 'variable',
			'attributes'          => $this->get_variation_attributes_list( $variation ),
			'item_group_id'       => (string) $parent->get_id(),
			'shipping_weight'     => ( $variation->get_weight() ?: $parent->get_weight() ) ? ( ( $variation->get_weight() ?: $parent->get_weight() ) . ' ' . get_option( 'woocommerce_weight_unit' ) ) : '',
			'shipping_length'     => ( $variation->get_length() ?: $parent->get_length() ) ? ( ( $variation->get_length() ?: $parent->get_length() ) . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_width'      => ( $variation->get_width()  ?: $parent->get_width()  ) ? ( ( $variation->get_width()  ?: $parent->get_width()  ) . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_height'     => ( $variation->get_height() ?: $parent->get_height() ) ? ( ( $variation->get_height() ?: $parent->get_height() ) . ' ' . get_option( 'woocommerce_dimension_unit' ) ) : '',
			'shipping_label'      => $variation->get_shipping_class() ?: $parent->get_shipping_class(),
			'add_to_cart_url'     => add_query_arg( 'add-to-cart', $variation->get_id(), wc_get_cart_url() ),
		];
	}

	// ---------------------------------------------------------------------------
	// RSS 2.0 builder
	// ---------------------------------------------------------------------------

	private function build_rss( array $products ): string {
		$shop_name = get_bloginfo( 'name' );
		$shop_url  = esc_url( home_url() );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		$xml .= '  <channel>' . "\n";
		$xml .= '    <title><![CDATA[Marques de France Product Feed – ' . $shop_name . ']]></title>' . "\n";
		$xml .= '    <link>' . $shop_url . '</link>' . "\n";
		$xml .= '    <description><![CDATA[Product feed for Marques de France guide]]></description>' . "\n\n";

		foreach ( $products as $p ) {
			$price         = $p['price'] !== '' ? wc_format_decimal( $p['price'], 2 ) . ' ' . $p['currency'] : '';
			$regular_price = $p['regular_price'] !== '' ? wc_format_decimal( $p['regular_price'], 2 ) . ' ' . $p['currency'] : $price;
			$sale_price    = $p['sale_price'] !== '' ? wc_format_decimal( $p['sale_price'], 2 ) . ' ' . $p['currency'] : '';

			// Skip zero-price items — Google rejects them
			$effective_price = $p['regular_price'] !== '' ? (float) $p['regular_price'] : (float) $p['price'];
			if ( $effective_price <= 0 ) {
				continue;
			}

			$xml .= '    <item>' . "\n";
			$xml .= '      <g:id>'                  . esc_xml( $p['id'] )                                          . '</g:id>' . "\n";
			$xml .= '      <g:item_group_id>'       . esc_xml( $p['item_group_id'] )                              . '</g:item_group_id>' . "\n";
			$xml .= '      <g:title><![CDATA['            . $p['title']                                           . ']]></g:title>' . "\n";
			$xml .= '      <parent_title><![CDATA['       . ( $p['parent_title'] ?? $p['title'] )                 . ']]></parent_title>' . "\n";
			$xml .= '      <parent_link>'                 . esc_url( $p['parent_link'] ?? $p['link'] )            . '</parent_link>' . "\n";
			$xml .= '      <g:description><![CDATA['      . $p['description']                                     . ']]></g:description>' . "\n";
			if ( $p['short_description'] ?? '' ) {
				$xml .= '      <short_description><![CDATA[' . $p['short_description']                               . ']]></short_description>' . "\n";
			}
			if ( $p['gtin'] ?? '' ) {
				$xml .= '      <g:gtin>'                  . esc_xml( $p['gtin'] )                                 . '</g:gtin>' . "\n";
			}
			if ( $p['mpn'] ?? '' ) {
				$xml .= '      <g:mpn>'                   . esc_xml( $p['mpn'] )                                  . '</g:mpn>' . "\n";
			}
			$xml .= '      <g:price>'                     . esc_xml( $regular_price )                             . '</g:price>' . "\n";
			if ( $sale_price ) {
				$xml .= '      <g:sale_price>'            . esc_xml( $sale_price )                                . '</g:sale_price>' . "\n";
			}
			$xml .= '      <g:link>'                      . esc_url( $p['link'] )                                 . '</g:link>' . "\n";
			$xml .= '      <g:image_link>'                . esc_url( $p['image'] )                                . '</g:image_link>' . "\n";
			foreach ( array_slice( $p['additional_images'] ?? [], 0, 10 ) as $extra_img ) {
				$xml .= '      <g:additional_image_link>' . esc_url( $extra_img )                                 . '</g:additional_image_link>' . "\n";
			}
			if ( $p['brand'] ?? '' ) {
				$xml .= '      <g:brand><![CDATA['        . $p['brand']                                           . ']]></g:brand>' . "\n";
			}
			if ( $p['category'] ?? '' ) {
				$xml .= '      <g:product_type><![CDATA[' . $p['category']                                        . ']]></g:product_type>' . "\n";
			}
			$xml .= '      <g:availability>'              . esc_xml( $p['availability'] )                         . '</g:availability>' . "\n";
			if ( $p['color'] ?? '' ) {
				$xml .= '      <g:color>'                 . esc_xml( $p['color'] )                                . '</g:color>' . "\n";
			}
			if ( ! empty( $p['tags'] ) ) {
				$xml .= '      <tags>'                    . esc_xml( implode( ', ', $p['tags'] ) )                . '</tags>' . "\n";
			}
			$xml .= '      <g:identifier_exists>'         . ( ( $p['identifier_exists'] ?? false ) ? 'yes' : 'no' ) . '</g:identifier_exists>' . "\n";
			$xml .= '      <is_cheapest_variant>'         . ( ( $p['is_cheapest_variant'] ?? false ) ? '1' : '0' ) . '</is_cheapest_variant>' . "\n";
			$xml .= '      <has_variants>'                . ( ( $p['has_variants'] ?? false ) ? '1' : '0' )       . '</has_variants>' . "\n";
			$xml .= '      <woo_product_type>'            . esc_xml( $p['woo_product_type'] ?? '' )               . '</woo_product_type>' . "\n";
			$xml .= '      <g:condition>'                 . esc_xml( $p['condition'] )                            . '</g:condition>' . "\n";
			if ( $p['shipping_weight'] ?? '' ) {
				$xml .= '      <g:shipping_weight>'           . esc_xml( $p['shipping_weight'] )                    . '</g:shipping_weight>' . "\n";
			}
			if ( $p['shipping_length'] ?? '' ) {
				$xml .= '      <g:shipping_length>'           . esc_xml( $p['shipping_length'] )                    . '</g:shipping_length>' . "\n";
			}
			if ( $p['shipping_width'] ?? '' ) {
				$xml .= '      <g:shipping_width>'            . esc_xml( $p['shipping_width'] )                     . '</g:shipping_width>' . "\n";
			}
			if ( $p['shipping_height'] ?? '' ) {
				$xml .= '      <g:shipping_height>'           . esc_xml( $p['shipping_height'] )                    . '</g:shipping_height>' . "\n";
			}
			if ( $p['shipping_label'] ?? '' ) {
				$xml .= '      <g:shipping_label>'            . esc_xml( $p['shipping_label'] )                     . '</g:shipping_label>' . "\n";
			}
			if ( $p['add_to_cart_url'] ?? '' ) {
				$xml .= '      <add_to_cart_url>'             . esc_url( $p['add_to_cart_url'] )                    . '</add_to_cart_url>' . "\n";
			}
			foreach ( array_slice( $p['attributes'] ?? [], 0, 3 ) as $i => $attr ) {
				$n    = $i + 1;
				$xml .= '      <custom_attribute_' . $n . '_name>'  . esc_xml( $attr['name'] )  . '</custom_attribute_' . $n . '_name>' . "\n";
				$xml .= '      <custom_attribute_' . $n . '_value>' . esc_xml( $attr['value'] ) . '</custom_attribute_' . $n . '_value>' . "\n";
			}
			$xml .= '    </item>' . "\n\n";
		}

		$xml .= '  </channel>' . "\n";
		$xml .= '</rss>' . "\n";

		return $xml;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Returns the brand name from the product_brand taxonomy (first term),
	 * falling back to the site name when the taxonomy is absent or empty.
	 */
	private function get_product_brand( int $product_id ): string {
		$terms = get_the_terms( $product_id, 'product_brand' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
		return get_bloginfo( 'name' );
	}

	/**
	 * Extracts the color value for a variation by inspecting its attributes
	 * for any key containing "color", "colour", or "couleur".
	 * Resolves taxonomy slugs to human-readable term names.
	 */
	private function get_variation_color( WC_Product_Variation $variation ): string {
		foreach ( $variation->get_variation_attributes() as $raw_key => $value ) {
			if ( ! $value ) {
				continue;
			}
			$key = strtolower( str_replace( 'attribute_', '', $raw_key ) );
			if ( strpos( $key, 'color' ) !== false || strpos( $key, 'colour' ) !== false || strpos( $key, 'couleur' ) !== false ) {
				$taxonomy = str_replace( 'attribute_', '', $raw_key );
				if ( taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'slug', $value, $taxonomy );
					return $term ? $term->name : $value;
				}
				return $value;
			}
		}
		return '';
	}

	/**
	 * Returns up to 3 attributes for a simple product as
	 * [['name' => '...', 'value' => '...'], ...].
	 */
	private function get_product_attributes( WC_Product $product ): array {
		$attrs = [];
		foreach ( $product->get_attributes() as $attribute ) {
			if ( count( $attrs ) >= 3 ) {
				break;
			}
			$name = wc_attribute_label( $attribute->get_name() );
			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
				$value = implode( ', ', $terms );
			} else {
				$value = implode( ', ', $attribute->get_options() );
			}
			if ( $name && $value ) {
				$attrs[] = [ 'name' => $name, 'value' => $value ];
			}
		}
		return $attrs;
	}

	/**
	 * Returns up to 3 variation attributes as [['name' => '...', 'value' => '...'], ...],
	 * resolving taxonomy slugs to human-readable term names.
	 */
	private function get_variation_attributes_list( WC_Product_Variation $variation ): array {
		$attrs = [];
		foreach ( $variation->get_variation_attributes() as $raw_key => $value ) {
			if ( ! $value || count( $attrs ) >= 3 ) {
				continue;
			}
			$taxonomy = str_replace( 'attribute_', '', $raw_key );
			$label    = wc_attribute_label( $taxonomy );
			$disp_val = $value;
			if ( taxonomy_exists( $taxonomy ) ) {
				$term     = get_term_by( 'slug', $value, $taxonomy );
				$disp_val = $term ? $term->name : $value;
			}
			$attrs[] = [ 'name' => $label ?: $taxonomy, 'value' => $disp_val ];
		}
		return $attrs;
	}
}

