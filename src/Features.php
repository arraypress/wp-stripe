<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\Entitlements\Feature;
use Stripe\ProductFeature;
use WP_Error;

/**
 * Features
 *
 * Manages Stripe Entitlement Features — account-level feature definitions that
 * can be attached to products. When a customer purchases a product with features
 * attached, Stripe automatically creates active entitlements for those features.
 *
 * Two concepts managed here:
 *   - Features:         Account-level definitions (feat_xxx). Created once, reused across products.
 *   - Product Features: The attachment between a feature and a product (pf_xxx).
 *
 * Note: A feature's lookup_key is immutable after creation. It is your system
 * identifier — use it consistently throughout your codebase to check access.
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class Features {

	/**
	 * The Stripe client instance.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client The Stripe client instance.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	// -------------------------------------------------------------------------
	// Feature Management
	// -------------------------------------------------------------------------

	/**
	 * Create a new feature.
	 *
	 * The lookup_key is your internal system identifier and is immutable after
	 * creation. Use lowercase slugs (e.g. 'api_access', 'priority_support').
	 *
	 * @param string $name       Display name for your own reference (not shown to customers).
	 * @param string $lookup_key Immutable unique key for this feature (e.g. 'api_access').
	 * @param array  $args       Optional: metadata (array).
	 *
	 * @return Feature|WP_Error
	 * @since 1.0.0
	 */
	public function create( string $name, string $lookup_key, array $args = [] ): Feature|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [
				'name'       => $name,
				'lookup_key' => $lookup_key,
			];

			if ( ! empty( $args['metadata'] ) ) {
				$params['metadata'] = $args['metadata'];
			}

			return $stripe->entitlements->features->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a single feature by ID.
	 *
	 * @param string $feature_id Stripe feature ID (feat_xxx).
	 *
	 * @return Feature|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $feature_id ): Feature|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->entitlements->features->retrieve( $feature_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List features with optional filters.
	 *
	 * @param array $params Optional filters:
	 *                      - lookup_key (string) Filter by exact lookup key.
	 *                      - archived   (bool)   Include archived features. Default false.
	 *                      - limit      (int)    Max results per page (default 10, max 100).
	 *
	 * @return array|WP_Error [ 'items' => Feature[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->entitlements->features->all( $params );

			return [
				'items'    => $response->data,
				'has_more' => $response->has_more,
				'cursor'   => ! empty( $response->data ) ? end( $response->data )->id : '',
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List features as plain stdClass objects.
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return array|WP_Error [ 'items' => stdClass[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_serialized( array $params = [] ): array|WP_Error {
		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['items'] = array_map(
			fn( $feature ) => json_decode( json_encode( $feature->toArray() ) ),
			$result['items']
		);

		return $result;
	}

	/**
	 * List features as a key/value array for admin dropdowns.
	 *
	 * Returns [ 'feat_xxx' => 'Feature Name (lookup_key)' ].
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return array|WP_Error [ 'feat_xxx' => 'API Access (api_access)', ... ]
	 * @since 1.0.0
	 */
	public function get_options( array $params = [] ): array|WP_Error {
		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$options = [];

		foreach ( $result['items'] as $feature ) {
			$options[ $feature->id ] = sprintf( '%s (%s)', $feature->name, $feature->lookup_key );
		}

		return $options;
	}

	/**
	 * Update a feature's name or metadata.
	 *
	 * Note: lookup_key is immutable and cannot be changed after creation.
	 * To archive a feature, use archive() instead of passing active = false here.
	 *
	 * @param string $feature_id Stripe feature ID (feat_xxx).
	 * @param array  $args       Fields to update: name (string), metadata (array).
	 *
	 * @return Feature|WP_Error
	 * @since 1.0.0
	 */
	public function update( string $feature_id, array $args ): Feature|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			foreach ( [ 'name', 'metadata' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->entitlements->features->update( $feature_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Archive a feature.
	 *
	 * Archived features cannot be edited or attached to new products.
	 * Existing product attachments and active customer entitlements are unaffected.
	 *
	 * @param string $feature_id Stripe feature ID (feat_xxx).
	 *
	 * @return Feature|WP_Error
	 * @since 1.0.0
	 */
	public function archive( string $feature_id ): Feature|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->entitlements->features->update( $feature_id, [ 'active' => false ] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Bulk Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Fetch ALL features, auto-paginating through all pages.
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return Feature[]|WP_Error
	 * @since 1.0.0
	 */
	public function all( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$all    = [];
			$cursor = null;

			do {
				$query = array_merge( $params, [ 'limit' => 100 ] );

				if ( $cursor ) {
					$query['starting_after'] = $cursor;
				}

				$response = $stripe->entitlements->features->all( $query );
				$all      = array_merge( $all, $response->data );
				$cursor   = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $all;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Process all features in batches via a callback.
	 *
	 * Return false from the callback to stop early.
	 *
	 * @param callable $callback Receives ( Feature[] $items, int $page ).
	 * @param array    $params   Optional filters (same as list()).
	 *
	 * @return int|WP_Error Total number of features processed, or WP_Error on failure.
	 * @since 1.0.0
	 */
	public function each_batch( callable $callback, array $params = [] ): int|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$total  = 0;
			$page   = 1;
			$cursor = null;

			do {
				$query = array_merge( $params, [ 'limit' => 100 ] );

				if ( $cursor ) {
					$query['starting_after'] = $cursor;
				}

				$response = $stripe->entitlements->features->all( $query );

				if ( empty( $response->data ) ) {
					break;
				}

				$result = $callback( $response->data, $page );

				if ( $result === false ) {
					break;
				}

				$total  += count( $response->data );
				$cursor = end( $response->data )->id;
				$page ++;
			} while ( $response->has_more );

			return $total;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Product Attachment
	// -------------------------------------------------------------------------

	/**
	 * Attach a feature to a product.
	 *
	 * When a customer purchases this product, Stripe will automatically
	 * create an active entitlement to the feature for that customer.
	 *
	 * @param string $product_id Stripe product ID (prod_xxx).
	 * @param string $feature_id Stripe feature ID (feat_xxx).
	 *
	 * @return ProductFeature|WP_Error The product feature attachment or WP_Error on failure.
	 * @since 1.0.0
	 */
	public function attach_to_product( string $product_id, string $feature_id ): ProductFeature|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->products->createFeature( $product_id, [
				'entitlement_feature' => $feature_id,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Detach a feature from a product.
	 *
	 * Removes the product feature attachment. Existing customer entitlements
	 * that were already granted are not immediately revoked — they will be
	 * removed at the start of the customer's next billing period.
	 *
	 * @param string $product_id         Stripe product ID (prod_xxx).
	 * @param string $product_feature_id Stripe product feature attachment ID (pf_xxx).
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function detach_from_product( string $product_id, string $product_feature_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->products->deleteFeature( $product_id, $product_feature_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List all features attached to a product.
	 *
	 * @param string $product_id Stripe product ID (prod_xxx).
	 * @param array  $params     Optional: limit (int).
	 *
	 * @return array|WP_Error [ 'items' => ProductFeature[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_product( string $product_id, array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->products->allFeatures( $product_id, $params );

			return [
				'items'    => $response->data,
				'has_more' => $response->has_more,
				'cursor'   => ! empty( $response->data ) ? end( $response->data )->id : '',
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}