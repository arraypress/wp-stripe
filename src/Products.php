<?php
/**
 * Stripe Products Helper
 *
 * Provides convenience methods for managing Stripe products with
 * WordPress integration. Handles common operations like archiving,
 * image management from WordPress attachments, and status toggling.
 *
 * For basic CRUD, use the Stripe SDK directly via $client->stripe()->products.
 * These helpers add value where WordPress-specific logic or multi-step
 * operations are involved.
 *
 * @package     ArrayPress\Stripe
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

namespace ArrayPress\Stripe;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

use Exception;
use Stripe\Product;
use WP_Error;

/**
 * Class Products
 *
 * WordPress-aware helpers for Stripe product management.
 *
 * Usage:
 *   $products = new Products( $client );
 *   $products->archive( 'prod_xxx' );
 *   $products->set_image_from_attachment( 'prod_xxx', 42 );
 *
 * @since 1.0.0
 */
class Products {

	/**
	 * Client instance.
	 *
	 * @since 1.0.0
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client The Stripe client instance.
	 *
	 * @since 1.0.0
	 *
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/** =========================================================================
	 *  Retrieval
	 *  ======================================================================== */

	/**
	 * Retrieve a product from Stripe.
	 *
	 * @param string $product_id Stripe product ID.
	 * @param array  $params     Optional expand or other parameters.
	 *
	 * @return Product|WP_Error The Stripe product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $product_id, array $params = [] ): Product|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->products->retrieve( $product_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List products from Stripe with optional filters.
	 *
	 * @param array $params         {
	 *                              Optional. Stripe list parameters.
	 *
	 * @type bool   $active         Filter by active status.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 *                              }
	 *
	 * @return array{items: Product[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = wp_parse_args( $params, [
			'limit' => 100,
		] );

		try {
			$result    = $stripe->products->all( $params );
			$last_item = ! empty( $result->data ) ? end( $result->data ) : null;

			return [
				'items'    => $result->data,
				'has_more' => $result->has_more,
				'cursor'   => $last_item ? $last_item->id : '',
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Creation
	 *  ======================================================================== */

	/**
	 * Create a new product in Stripe.
	 *
	 * Accepts a simplified parameter set and handles the mapping
	 * to Stripe's API format, including marketing features and images.
	 *
	 * @param array   $args        {
	 *                             Product creation arguments.
	 *
	 * @type string   $name        Required. Product name.
	 * @type string   $description Product description.
	 * @type string[] $images      Array of image URLs (max 8).
	 * @type string[] $features    Array of marketing feature strings.
	 * @type bool     $active      Whether the product is active. Default true.
	 * @type array    $metadata    Key/value metadata pairs.
	 *                             }
	 *
	 * @return Product|WP_Error The created product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( array $args ): Product|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $args['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Product name is required.', 'arraypress' ) );
		}

		$params = [
			'name'   => $args['name'],
			'active' => $args['active'] ?? true,
		];

		if ( ! empty( $args['description'] ) ) {
			$params['description'] = $args['description'];
		}

		if ( ! empty( $args['images'] ) ) {
			$params['images'] = array_slice( (array) $args['images'], 0, 8 );
		}

		if ( ! empty( $args['features'] ) ) {
			$params['marketing_features'] = array_map( function ( string $feature ): array {
				return [ 'name' => $feature ];
			}, (array) $args['features'] );
		}

		if ( ! empty( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		try {
			return $stripe->products->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a product in Stripe.
	 *
	 * Only sends fields that are provided, leaving others unchanged.
	 *
	 * @param string $product_id Stripe product ID.
	 * @param array  $args       Same as create() — only provided fields are updated.
	 *
	 * @return Product|WP_Error The updated product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function update( string $product_id, array $args ): Product|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		if ( isset( $args['name'] ) ) {
			$params['name'] = $args['name'];
		}

		if ( isset( $args['description'] ) ) {
			$params['description'] = $args['description'];
		}

		if ( isset( $args['active'] ) ) {
			$params['active'] = (bool) $args['active'];
		}

		if ( isset( $args['images'] ) ) {
			$params['images'] = array_slice( (array) $args['images'], 0, 8 );
		}

		if ( isset( $args['features'] ) ) {
			$params['marketing_features'] = array_map( function ( string $feature ): array {
				return [ 'name' => $feature ];
			}, (array) $args['features'] );
		}

		if ( isset( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		if ( empty( $params ) ) {
			return $this->get( $product_id );
		}

		try {
			return $stripe->products->update( $product_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Status Management
	 *  ======================================================================== */

	/**
	 * Archive a product in Stripe.
	 *
	 * Sets the product's active status to false. Archived products
	 * are hidden from customers but remain accessible via the API.
	 *
	 * @param string $product_id Stripe product ID.
	 *
	 * @return Product|WP_Error The updated product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function archive( string $product_id ): Product|WP_Error {
		return $this->update( $product_id, [ 'active' => false ] );
	}

	/**
	 * Unarchive (activate) a product in Stripe.
	 *
	 * Sets the product's active status to true, making it visible
	 * to customers again.
	 *
	 * @param string $product_id Stripe product ID.
	 *
	 * @return Product|WP_Error The updated product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function unarchive( string $product_id ): Product|WP_Error {
		return $this->update( $product_id, [ 'active' => true ] );
	}

	/**
	 * Delete a product from Stripe.
	 *
	 * Products can only be deleted if they have no associated prices.
	 * Use archive() instead for products with existing prices.
	 *
	 * @param string $product_id Stripe product ID.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function delete( string $product_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->products->delete( $product_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Image Management
	 *  ======================================================================== */

	/**
	 * Set a product image from a WordPress attachment.
	 *
	 * Resolves the attachment ID to its public URL and updates the
	 * product's images in Stripe. Only works with publicly accessible URLs.
	 *
	 * @param string $product_id    Stripe product ID.
	 * @param int    $attachment_id WordPress attachment ID.
	 *
	 * @return Product|WP_Error The updated product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function set_image_from_attachment( string $product_id, int $attachment_id ): Product|WP_Error {
		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Attachment not found or has no URL.', 'arraypress' )
			);
		}

		if ( ! Utilities::is_public_url( $url ) ) {
			return new WP_Error(
				'private_url',
				__( 'Attachment URL is not publicly accessible. Stripe requires public URLs.', 'arraypress' )
			);
		}

		return $this->update( $product_id, [ 'images' => [ $url ] ] );
	}

	/**
	 * Clear all images from a product.
	 *
	 * @param string $product_id Stripe product ID.
	 *
	 * @return Product|WP_Error The updated product or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function clear_images( string $product_id ): Product|WP_Error {
		return $this->update( $product_id, [ 'images' => [] ] );
	}

	/** =========================================================================
	 *  Bulk Retrieval
	 *  ======================================================================== */

	/**
	 * Fetch all products from Stripe, auto-paginating.
	 *
	 * Iterates through all pages of results automatically. Useful for
	 * sync operations that need to process every product.
	 *
	 * @param array $params {
	 *                      Optional. Filter parameters applied to each page.
	 *
	 * @type bool   $active Filter by active status.
	 *                      }
	 *
	 * @return Product[]|WP_Error All matching products or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function all( array $params = [] ): array|WP_Error {
		$all_items = [];
		$cursor    = '';

		do {
			$page_params = array_merge( $params, [ 'limit' => 100 ] );

			if ( $cursor ) {
				$page_params['starting_after'] = $cursor;
			}

			$result = $this->list( $page_params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$all_items = array_merge( $all_items, $result['items'] );
			$cursor    = $result['cursor'];
		} while ( $result['has_more'] );

		return $all_items;
	}

	/**
	 * Fetch products from Stripe in batches via a callback.
	 *
	 * Processes products page-by-page without loading everything into
	 * memory. Ideal for large catalogs and sync operations.
	 *
	 * The callback receives an array of products for each batch.
	 * Return false from the callback to stop iteration early.
	 *
	 * @param callable $callback Function to process each batch. Receives (Product[] $items, int $page).
	 *                           Return false to stop.
	 * @param array    $params   Optional filter parameters.
	 *
	 * @return int|WP_Error Total items processed, or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function each_batch( callable $callback, array $params = [] ): int|WP_Error {
		$cursor = '';
		$total  = 0;
		$page   = 1;

		do {
			$page_params = array_merge( $params, [ 'limit' => 100 ] );

			if ( $cursor ) {
				$page_params['starting_after'] = $cursor;
			}

			$result = $this->list( $page_params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( empty( $result['items'] ) ) {
				break;
			}

			$total += count( $result['items'] );

			$continue = $callback( $result['items'], $page );

			if ( $continue === false ) {
				break;
			}

			$cursor = $result['cursor'];
			$page ++;
		} while ( $result['has_more'] );

		return $total;
	}

	/** =========================================================================
	 *  Search
	 *  ======================================================================== */

	/**
	 * Search for products by name.
	 *
	 * Uses Stripe's search API to find products matching a query string.
	 *
	 * @param string $name  Product name or partial name to search for.
	 * @param int    $limit Maximum results to return. Default 10.
	 *
	 * @return Product[]|WP_Error Array of matching products or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function search_by_name( string $name, int $limit = 10 ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$result = $stripe->products->search( [
				'query' => "name~\"{$name}\"",
				'limit' => (int) min( $limit, 100 ),
			] );

			return $result->data;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}
