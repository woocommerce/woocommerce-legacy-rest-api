<?php
/**
 * WooCommerce API Products Class
 *
 * Handles requests to the /products endpoint
 *
 * @author      WooThemes
 * @category    API
 * @package     WooCommerce\RestApi
 * @since       2.1
 * @version     3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_API_Products extends WC_API_Resource {

	/** @var string $base the route base */
	protected $base = '/products';

	/**
	 * Register the routes for this class
	 *
	 * GET /products
	 * GET /products/count
	 * GET /products/<id>
	 * GET /products/<id>/reviews
	 *
	 * @since 2.1
	 * @param array $routes
	 * @return array
	 */
	public function register_routes( $routes ) {

		# GET /products
		$routes[ $this->base ] = array(
			array( array( $this, 'get_products' ),     WC_API_Server::READABLE ),
		);

		# GET /products/count
		$routes[ $this->base . '/count' ] = array(
			array( array( $this, 'get_products_count' ), WC_API_Server::READABLE ),
		);

		# GET /products/<id>
		$routes[ $this->base . '/(?P<id>\d+)' ] = array(
			array( array( $this, 'get_product' ),  WC_API_Server::READABLE ),
		);

		# GET /products/<id>/reviews
		$routes[ $this->base . '/(?P<id>\d+)/reviews' ] = array(
			array( array( $this, 'get_product_reviews' ), WC_API_Server::READABLE ),
		);

		return $routes;
	}

	/**
	 * Get all products
	 *
	 * @since 2.1
	 * @param string $fields
	 * @param string $type
	 * @param array $filter
	 * @param int $page
	 * @return array
	 */
	public function get_products( $fields = null, $type = null, $filter = array(), $page = 1 ) {

		if ( ! empty( $type ) ) {
			$filter['type'] = $type;
		}

		$filter['page'] = $page;

		$query = $this->query_products( $filter );

		$products = array();

		foreach ( $query->posts as $product_id ) {

			if ( ! $this->is_readable( $product_id ) ) {
				continue;
			}

			$products[] = current( $this->get_product( $product_id, $fields ) );
		}

		$this->server->add_pagination_headers( $query );

		return array( 'products' => $products );
	}

	/**
	 * Get the product for the given ID
	 *
	 * @since 2.1
	 * @param int $id the product ID
	 * @param string $fields
	 * @return array|WP_Error
	 */
	public function get_product( $id, $fields = null ) {

		$id = $this->validate_request( $id, 'product', 'read' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$product = wc_get_product( $id );

		// add data that applies to every product type
		$product_data = $this->get_product_data( $product );

		// add variations to variable products
		if ( $product->is_type( 'variable' ) && $product->has_child() ) {
			$product_data['variations'] = $this->get_variation_data( $product );
		}

		// add the parent product data to an individual variation
		if ( $product->is_type( 'variation' ) ) {
			$product_data['parent'] = $this->get_product_data( $product->get_parent_id() );
		}

		return array( 'product' => apply_filters( 'woocommerce_api_product_response', $product_data, $product, $fields, $this->server ) );
	}

	/**
	 * Get the total number of orders
	 *
	 * @since 2.1
	 *
	 * @param string $type
	 * @param array $filter
	 *
	 * @return array|WP_Error
	 */
	public function get_products_count( $type = null, $filter = array() ) {

		if ( ! empty( $type ) ) {
			$filter['type'] = $type;
		}

		if ( ! current_user_can( 'read_private_products' ) ) {
			return new WP_Error( 'woocommerce_api_user_cannot_read_products_count', __( 'You do not have permission to read the products count', 'woocommerce-legacy-rest-api' ), array( 'status' => 401 ) );
		}

		$query = $this->query_products( $filter );

		return array( 'count' => (int) $query->found_posts );
	}

	/**
	 * Edit a product
	 *
	 * @param int $id the product ID
	 * @param array $data
	 * @return array|WP_Error
	 */
	public function edit_product( $id, $data ) {

		$id = $this->validate_request( $id, 'product', 'edit' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->get_product( $id );
	}

	/**
	 * Delete a product
	 *
	 * @param int $id the product ID
	 * @param bool $force true to permanently delete order, false to move to trash
	 * @return array|WP_Error
	 */
	public function delete_product( $id, $force = false ) {

		$id = $this->validate_request( $id, 'product', 'delete' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->delete( $id, 'product', ( 'true' === $force ) );
	}

	/**
	 * Get the reviews for a product
	 *
	 * @since 2.1
	 * @param int $id the product ID to get reviews for
	 * @param string $fields fields to include in response
	 * @return array|WP_Error
	 */
	public function get_product_reviews( $id, $fields = null ) {

		$id = $this->validate_request( $id, 'product', 'read' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$args = array(
			'post_id' => $id,
			'approve' => 'approve',
		);

		$comments = get_comments( $args );

		$reviews = array();

		foreach ( $comments as $comment ) {

			$reviews[] = array(
				'id'             => $comment->comment_ID,
				'created_at'     => $this->server->format_datetime( $comment->comment_date_gmt ),
				'review'         => $comment->comment_content,
				'rating'         => get_comment_meta( $comment->comment_ID, 'rating', true ),
				'reviewer_name'  => $comment->comment_author,
				'reviewer_email' => $comment->comment_author_email,
				'verified'       => wc_review_is_from_verified_owner( $comment->comment_ID ),
			);
		}

		return array( 'product_reviews' => apply_filters( 'woocommerce_api_product_reviews_response', $reviews, $id, $fields, $comments, $this->server ) );
	}

	/**
	 * Helper method to get product post objects
	 *
	 * @since 2.1
	 * @param array $args request arguments for filtering query
	 * @return WP_Query
	 */
	private function query_products( $args ) {

		// set base query arguments
		$query_args = array(
			'fields'      => 'ids',
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta_query'  => array(),
		);

		if ( ! empty( $args['type'] ) ) {

			$types = explode( ',', $args['type'] );

			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $types,
				),
			);

			unset( $args['type'] );
		}

		$query_args = $this->merge_query_args( $query_args, $args );

		return new WP_Query( $query_args );
	}

	/**
	 * Get standard product data that applies to every product type
	 *
	 * @since 2.1
	 * @param WC_Product|int $product
	 * @return array
	 */
	private function get_product_data( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return array();
		}

		return array(
			'title'              => $product->get_name(),
			'id'                 => $product->get_id(),
			'created_at'         => $this->server->format_datetime( $product->get_date_created(), false, true ),
			'updated_at'         => $this->server->format_datetime( $product->get_date_modified(), false, true ),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'downloadable'       => $product->is_downloadable(),
			'virtual'            => $product->is_virtual(),
			'permalink'          => $product->get_permalink(),
			'sku'                => $product->get_sku(),
			'price'              => wc_format_decimal( $product->get_price(), 2 ),
			'regular_price'      => wc_format_decimal( $product->get_regular_price(), 2 ),
			'sale_price'         => $product->get_sale_price() ? wc_format_decimal( $product->get_sale_price(), 2 ) : null,
			'price_html'         => $product->get_price_html(),
			'taxable'            => $product->is_taxable(),
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'managing_stock'     => $product->managing_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'in_stock'           => $product->is_in_stock(),
			'backorders_allowed' => $product->backorders_allowed(),
			'backordered'        => $product->is_on_backorder(),
			'sold_individually'  => $product->is_sold_individually(),
			'purchaseable'       => $product->is_purchasable(),
			'featured'           => $product->is_featured(),
			'visible'            => $product->is_visible(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'on_sale'            => $product->is_on_sale(),
			'weight'             => $product->get_weight() ? wc_format_decimal( $product->get_weight(), 2 ) : null,
			'dimensions'         => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
				'unit'   => get_option( 'woocommerce_dimension_unit' ),
			),
			'shipping_required'  => $product->needs_shipping(),
			'shipping_taxable'   => $product->is_shipping_taxable(),
			'shipping_class'     => $product->get_shipping_class(),
			'shipping_class_id'  => ( 0 !== $product->get_shipping_class_id() ) ? $product->get_shipping_class_id() : null,
			'description'        => apply_filters( 'the_content', $product->get_description() ),
			'short_description'  => apply_filters( 'woocommerce_short_description', $product->get_short_description() ),
			'reviews_allowed'    => $product->get_reviews_allowed(),
			'average_rating'     => wc_format_decimal( $product->get_average_rating(), 2 ),
			'rating_count'       => $product->get_rating_count(),
			'related_ids'        => array_map( 'absint', array_values( wc_get_related_products( $product->get_id() ) ) ),
			'upsell_ids'         => array_map( 'absint', $product->get_upsell_ids() ),
			'cross_sell_ids'     => array_map( 'absint', $product->get_cross_sell_ids() ),
			'categories'         => wc_get_object_terms( $product->get_id(), 'product_cat', 'name' ),
			'tags'               => wc_get_object_terms( $product->get_id(), 'product_tag', 'name' ),
			'images'             => $this->get_images( $product ),
			'featured_src'       => wp_get_attachment_url( get_post_thumbnail_id( $product->get_id() ) ),
			'attributes'         => $this->get_attributes( $product ),
			'downloads'          => $this->get_downloads( $product ),
			'download_limit'     => $product->get_download_limit(),
			'download_expiry'    => $product->get_download_expiry(),
			'download_type'      => 'standard',
			'purchase_note'      => apply_filters( 'the_content', $product->get_purchase_note() ),
			'total_sales'        => $product->get_total_sales(),
			'variations'         => array(),
			'parent'             => array(),
		);
	}

	/**
	 * Get an individual variation's data
	 *
	 * @since 2.1
	 * @param WC_Product $product
	 * @return array
	 */
	private function get_variation_data( $product ) {
		$variations = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation || ! $variation->exists() ) {
				continue;
			}

			$variations[] = array(
				'id'                => $variation->get_id(),
				'created_at'        => $this->server->format_datetime( $variation->get_date_created(), false, true ),
				'updated_at'        => $this->server->format_datetime( $variation->get_date_modified(), false, true ),
				'downloadable'      => $variation->is_downloadable(),
				'virtual'           => $variation->is_virtual(),
				'permalink'         => $variation->get_permalink(),
				'sku'               => $variation->get_sku(),
				'price'             => wc_format_decimal( $variation->get_price(), 2 ),
				'regular_price'     => wc_format_decimal( $variation->get_regular_price(), 2 ),
				'sale_price'        => $variation->get_sale_price() ? wc_format_decimal( $variation->get_sale_price(), 2 ) : null,
				'taxable'           => $variation->is_taxable(),
				'tax_status'        => $variation->get_tax_status(),
				'tax_class'         => $variation->get_tax_class(),
				'stock_quantity'    => (int) $variation->get_stock_quantity(),
				'in_stock'          => $variation->is_in_stock(),
				'backordered'       => $variation->is_on_backorder(),
				'purchaseable'      => $variation->is_purchasable(),
				'visible'           => $variation->variation_is_visible(),
				'on_sale'           => $variation->is_on_sale(),
				'weight'            => $variation->get_weight() ? wc_format_decimal( $variation->get_weight(), 2 ) : null,
				'dimensions'        => array(
					'length' => $variation->get_length(),
					'width'  => $variation->get_width(),
					'height' => $variation->get_height(),
					'unit'   => get_option( 'woocommerce_dimension_unit' ),
				),
				'shipping_class'    => $variation->get_shipping_class(),
				'shipping_class_id' => ( 0 !== $variation->get_shipping_class_id() ) ? $variation->get_shipping_class_id() : null,
				'image'             => $this->get_images( $variation ),
				'attributes'        => $this->get_attributes( $variation ),
				'downloads'         => $this->get_downloads( $variation ),
				'download_limit'    => (int) $product->get_download_limit(),
				'download_expiry'   => (int) $product->get_download_expiry(),
			);
		}

		return $variations;
	}

	/**
	 * Get the images for a product or product variation
	 *
	 * @since 2.1
	 * @param WC_Product|WC_Product_Variation $product
	 * @return array
	 */
	private function get_images( $product ) {
		$images        = $attachment_ids = array();
		$product_image = $product->get_image_id();

		// Add featured image.
		if ( ! empty( $product_image ) ) {
			$attachment_ids[] = $product_image;
		}

		// add gallery images.
		$attachment_ids = array_merge( $attachment_ids, $product->get_gallery_image_ids() );

		// Build image data.
		foreach ( $attachment_ids as $position => $attachment_id ) {

			$attachment_post = get_post( $attachment_id );

			if ( is_null( $attachment_post ) ) {
				continue;
			}

			$attachment = wp_get_attachment_image_src( $attachment_id, 'full' );

			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$images[] = array(
				'id'         => (int) $attachment_id,
				'created_at' => $this->server->format_datetime( $attachment_post->post_date_gmt ),
				'updated_at' => $this->server->format_datetime( $attachment_post->post_modified_gmt ),
				'src'        => current( $attachment ),
				'title'      => get_the_title( $attachment_id ),
				'alt'        => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'position'   => $position,
			);
		}

		// Set a placeholder image if the product has no images set.
		if ( empty( $images ) ) {

			$images[] = array(
				'id'         => 0,
				'created_at' => $this->server->format_datetime( time() ), // default to now
				'updated_at' => $this->server->format_datetime( time() ),
				'src'        => wc_placeholder_img_src(),
				'title'      => __( 'Placeholder', 'woocommerce-legacy-rest-api' ),
				'alt'        => __( 'Placeholder', 'woocommerce-legacy-rest-api' ),
				'position'   => 0,
			);
		}

		return $images;
	}

	/**
	 * Get attribute options.
	 *
	 * @param int $product_id
	 * @param array $attribute
	 * @return array
	 */
	protected function get_attribute_options( $product_id, $attribute ) {
		if ( isset( $attribute['is_taxonomy'] ) && $attribute['is_taxonomy'] ) {
			return wc_get_product_terms( $product_id, $attribute['name'], array( 'fields' => 'names' ) );
		} elseif ( isset( $attribute['value'] ) ) {
			return array_map( 'trim', explode( '|', $attribute['value'] ) );
		}

		return array();
	}

	/**
	 * Get the attributes for a product or product variation
	 *
	 * @since 2.1
	 * @param WC_Product|WC_Product_Variation $product
	 * @return array
	 */
	private function get_attributes( $product ) {

		$attributes = array();

		if ( $product->is_type( 'variation' ) ) {

			// variation attributes
			foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {

				// taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
				$attributes[] = array(
					'name'   => ucwords( str_replace( 'attribute_', '', wc_attribute_taxonomy_slug( $attribute_name ) ) ),
					'option' => $attribute,
				);
			}
		} else {

			foreach ( $product->get_attributes() as $attribute ) {
				$attributes[] = array(
					'name'      => ucwords( wc_attribute_taxonomy_slug( $attribute['name'] ) ),
					'position'  => $attribute['position'],
					'visible'   => (bool) $attribute['is_visible'],
					'variation' => (bool) $attribute['is_variation'],
					'options'   => $this->get_attribute_options( $product->get_id(), $attribute ),
				);
			}
		}

		return $attributes;
	}

	/**
	 * Get the downloads for a product or product variation
	 *
	 * @since 2.1
	 * @param WC_Product|WC_Product_Variation $product
	 * @return array
	 */
	private function get_downloads( $product ) {

		$downloads = array();

		if ( $product->is_downloadable() ) {

			foreach ( $product->get_downloads() as $file_id => $file ) {

				$downloads[] = array(
					'id'   => $file_id, // do not cast as int as this is a hash
					'name' => $file['name'],
					'file' => $file['file'],
				);
			}
		}

		return $downloads;
	}
}
