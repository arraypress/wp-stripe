<?php
/**
 * Stripe Prices Helper
 *
 * Provides convenience methods for managing Stripe prices with
 * amount normalization, zero-decimal currency handling, and
 * simplified creation of one-time and recurring prices.
 *
 * Stripe prices are immutable once created — they cannot be changed,
 * only deactivated. To "update" a price, create a new one and
 * deactivate the old one.
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

use ArrayPress\Currencies\Currency;
use Exception;
use Stripe\Price;
use WP_Error;

/**
 * Class Prices
 *
 * WordPress-aware helpers for Stripe price management.
 *
 * Usage:
 *   $prices = new Prices( $client );
 *
 *   // One-time price
 *   $prices->create( [ 'product' => 'prod_xxx', 'amount' => 9.99 ] );
 *
 *   // Recurring price
 *   $prices->create( [
 *       'product'  => 'prod_xxx',
 *       'amount'   => 19.99,
 *       'interval' => 'month',
 *   ] );
 *
 *   // Deactivate old price
 *   $prices->deactivate( 'price_old' );
 *
 * @since 1.0.0
 */
class Prices {

	use Traits\Serializable;

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
	 * Retrieve a price from Stripe.
	 *
	 * @param string $price_id Stripe price ID.
	 * @param array  $params   Optional parameters (e.g., expand).
	 *
	 * @return Price|WP_Error The Stripe price or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $price_id, array $params = [] ): Price|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->prices->retrieve( $price_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List prices from Stripe with optional filters.
	 *
	 * @param array $params         {
	 *                              Optional. Stripe list parameters.
	 *
	 * @type string $product        Filter by product ID.
	 * @type bool   $active         Filter by active status.
	 * @type string $type           Filter by type: 'one_time' or 'recurring'.
	 * @type string $currency       Filter by currency code.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 *                              }
	 *
	 * @return array{items: Price[], has_more: bool, cursor: string}|WP_Error
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
			$result    = $stripe->prices->all( $params );
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

	/**
	 * List prices from Stripe, returning plain stdClass objects.
	 *
	 * Identical to list() but strips Stripe SDK internals from each item
	 * via JSON round-trip. Use when results will be passed to a REST
	 * endpoint, stored in a transient, or handed to any system expecting
	 * plain serializable objects (e.g., wp-inline-sync batch callbacks).
	 *
	 * @param array $params         {
	 *                              Optional. Same parameters as list().
	 *
	 * @type string $product        Filter by product ID.
	 * @type bool   $active         Filter by active status.
	 * @type string $type           Filter by type: 'one_time' or 'recurring'.
	 * @type string $currency       Filter by currency code.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 * @type array  $expand         Fields to expand (e.g., ['data.product']).
	 *                              }
	 *
	 * @return array{items: \stdClass[], has_more: bool, cursor: string}|WP_Error
	 *
	 * @since 1.0.0
	 *
	 * @see   list()
	 */
	public function list_serialized( array $params = [] ): array|WP_Error {
		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->serialize_result( $result );
	}


	/**
	 * List all prices for a specific product.
	 *
	 * Convenience wrapper that filters by product ID.
	 *
	 * @param string $product_id Stripe product ID.
	 * @param bool   $active     Only return active prices. Default true.
	 *
	 * @return Price[]|WP_Error Array of prices or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function list_by_product( string $product_id, bool $active = true ): array|WP_Error {
		$params = [ 'product' => $product_id ];

		if ( $active ) {
			$params['active'] = true;
		}

		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['items'];
	}

	/**
	 * List all prices for a specific product, returning plain stdClass objects.
	 *
	 * Identical to list_by_product() but strips Stripe SDK internals from
	 * each item via JSON round-trip. Use when results will be passed to a
	 * REST endpoint, stored in a transient, or handed to any system
	 * expecting plain serializable objects (e.g., wp-inline-sync batch
	 * callbacks).
	 *
	 * @param string $product_id Stripe product ID.
	 * @param bool   $active     Only return active prices. Default true.
	 *
	 * @return \stdClass[]|WP_Error Array of plain price objects or WP_Error on failure.
	 *
	 * @since 1.0.0
	 *
	 * @see   list_by_product()
	 */
	public function list_by_product_serialized( string $product_id, bool $active = true ): array|WP_Error {
		$params = [ 'product' => $product_id ];

		if ( $active ) {
			$params['active'] = true;
		}

		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_map(
			fn( $item ) => json_decode( json_encode( $item ) ),
			$result['items']
		);
	}

	/** =========================================================================
	 *  Creation
	 *  ======================================================================== */

	/**
	 * Create a price in Stripe.
	 *
	 * Handles amount normalization and parameter assembly. Pass
	 * 'interval' to create a recurring price, omit for one-time.
	 *
	 * @param array $args           {
	 *                              Price creation arguments.
	 *
	 * @type string $product        Required. Stripe product ID.
	 * @type float  $amount         Required. Amount in major currency units (e.g., 9.99).
	 * @type string $currency       ISO 4217 currency code. Default 'USD'.
	 * @type string $interval       Billing interval: 'day', 'week', 'month', or 'year'.
	 * @type int    $interval_count Intervals between billings. Default 1.
	 * @type string $nickname       Price nickname.
	 * @type array  $metadata       Key/value metadata pairs.
	 * @type bool   $active         Whether the price is active. Default true.
	 *                              }
	 *
	 * @return Price|WP_Error The created price or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( array $args ): Price|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $args['product'] ) ) {
			return new WP_Error( 'missing_product', __( 'Product ID is required.', 'arraypress' ) );
		}

		$currency = strtolower( $args['currency'] ?? 'usd' );
		$amount   = Currency::to_smallest_unit( (float) ( $args['amount'] ?? 0 ), $currency );

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Price amount must be greater than zero.', 'arraypress' ) );
		}

		$params = [
			'product'     => $args['product'],
			'currency'    => $currency,
			'unit_amount' => $amount,
			'active'      => $args['active'] ?? true,
		];

		if ( ! empty( $args['interval'] ) ) {
			$valid_intervals = [ 'day', 'week', 'month', 'year' ];
			if ( ! in_array( $args['interval'], $valid_intervals, true ) ) {
				return new WP_Error(
					'invalid_interval',
					sprintf( __( 'Billing interval must be one of: %s', 'arraypress' ), implode( ', ', $valid_intervals ) )
				);
			}

			$params['recurring'] = [
				'interval'       => $args['interval'],
				'interval_count' => max( 1, (int) ( $args['interval_count'] ?? 1 ) ),
			];
		}

		if ( ! empty( $args['nickname'] ) ) {
			$params['nickname'] = $args['nickname'];
		}

		if ( ! empty( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		try {
			return $stripe->prices->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Bulk Retrieval
	 *  ======================================================================== */

	/**
	 * Fetch all prices from Stripe, auto-paginating.
	 *
	 * Iterates through all pages of results automatically. Useful for
	 * sync operations that need to process every price.
	 *
	 * @param array $params  {
	 *                       Optional. Filter parameters applied to each page.
	 *
	 * @type string $product Filter by product ID.
	 * @type bool   $active  Filter by active status.
	 * @type string $type    Filter by type: 'one_time' or 'recurring'.
	 * @type array  $expand  Fields to expand (e.g., ['data.product']).
	 *                       }
	 *
	 * @return Price[]|WP_Error All matching prices or WP_Error on failure.
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
	 * Fetch prices from Stripe in batches via a callback.
	 *
	 * Processes prices page-by-page without loading everything into
	 * memory. Ideal for sync operations with large catalogs.
	 *
	 * The callback receives an array of prices for each batch.
	 * Return false from the callback to stop iteration early.
	 *
	 * @param callable $callback Function to process each batch. Receives (Price[] $items, int $page).
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
	 *  Status Management
	 *  ======================================================================== */

	/**
	 * Deactivate a price in Stripe.
	 *
	 * Deactivated prices cannot be used for new purchases but
	 * existing subscriptions using this price are unaffected.
	 *
	 * @param string $price_id Stripe price ID.
	 *
	 * @return Price|WP_Error The updated price or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function deactivate( string $price_id ): Price|WP_Error {
		return $this->update( $price_id, [ 'active' => false ] );
	}

	/**
	 * Activate a price in Stripe.
	 *
	 * @param string $price_id Stripe price ID.
	 *
	 * @return Price|WP_Error The updated price or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function activate( string $price_id ): Price|WP_Error {
		return $this->update( $price_id, [ 'active' => true ] );
	}

	/**
	 * Update a price in Stripe.
	 *
	 * Stripe prices are mostly immutable — only active, nickname,
	 * and metadata can be changed. To change the amount, use replace().
	 *
	 * @param string $price_id Stripe price ID.
	 * @param array  $params   Fields to update (active, nickname, metadata).
	 *
	 * @return Price|WP_Error The updated price or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function update( string $price_id, array $params ): Price|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->prices->update( $price_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Price Replacement
	 *  ======================================================================== */

	/**
	 * Replace a price by creating a new one and deactivating the old.
	 *
	 * Since Stripe prices are immutable, changing a price's amount
	 * requires creating a new price and deactivating the old one.
	 * This method handles both steps atomically.
	 *
	 * @param string $old_price_id The price ID to replace.
	 * @param array  $new_args     Arguments for the new price (same as create()).
	 *
	 * @return array{new_price: Price, old_price: Price}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function replace( string $old_price_id, array $new_args ): array|WP_Error {
		// Create new price first
		$new_price = $this->create( $new_args );

		if ( is_wp_error( $new_price ) ) {
			return $new_price;
		}

		// Deactivate old price
		$old_price = $this->deactivate( $old_price_id );

		if ( is_wp_error( $old_price ) ) {
			// New price was created but old one couldn't be deactivated.
			// Return both so the caller can handle it.
			return new WP_Error(
				'partial_replace',
				sprintf(
				/* translators: 1: new price ID, 2: error message */
					__( 'New price %1$s created but old price could not be deactivated: %2$s', 'arraypress' ),
					$new_price->id,
					$old_price->get_error_message()
				)
			);
		}

		return [
			'new_price' => $new_price,
			'old_price' => $old_price,
		];
	}

}
