<?php

namespace ArrayPress\Stripe;

use ArrayPress\Currencies\Currency;
use Exception;
use Stripe\ShippingRate;
use WP_Error;

/**
 * Shipping Rates
 *
 * Manages Stripe shipping rates for presenting shipping options to customers
 * at checkout. Shipping rates are passed via the shipping_options parameter
 * when creating a Checkout Session.
 *
 * Note: A shipping rate's amount is immutable after creation. Use archive()
 * and create() to replace a rate with updated pricing.
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class ShippingRates {

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
	// Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a single shipping rate by ID.
	 *
	 * @param string $shipping_rate_id Stripe shipping rate ID (shr_xxx).
	 *
	 * @return ShippingRate|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $shipping_rate_id ): ShippingRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->shippingRates->retrieve( $shipping_rate_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List shipping rates with optional filters.
	 *
	 * @param array $params Optional filters:
	 *                      - active   (bool)   Only return active/inactive rates.
	 *                      - currency (string) Filter by currency (e.g. 'usd').
	 *                      - limit    (int)    Max results per page (default 10, max 100).
	 *
	 * @return array|WP_Error [ 'items' => ShippingRate[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->shippingRates->all( $params );

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
	 * List shipping rates as plain stdClass objects.
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
			fn( $rate ) => json_decode( json_encode( $rate->toArray() ) ),
			$result['items']
		);

		return $result;
	}

	/**
	 * List active shipping rates as a key/value array for admin dropdowns.
	 *
	 * Format: 'shr_xxx' => 'Display Name ($X.XX)'
	 * Free shipping is shown as 'Display Name (Free)'.
	 *
	 * @param string $currency Currency code for amount formatting (e.g. 'USD'). Default 'USD'.
	 * @param array  $params   Additional filters.
	 *
	 * @return array|WP_Error [ 'shr_xxx' => 'Standard Shipping ($5.99)', ... ]
	 * @since 1.0.0
	 */
	public function get_options( string $currency = 'USD', array $params = [] ): array|WP_Error {
		$params['active'] = true;
		$result           = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$options = [];

		foreach ( $result['items'] as $rate ) {
			$amount = $rate->fixed_amount->amount ?? 0;

			if ( $amount === 0 ) {
				$price_label = __( 'Free', 'arraypress' );
			} else {
				$rate_currency = strtoupper( $rate->fixed_amount->currency ?? $currency );
				$price_label   = Format::price( $amount, $rate_currency );
			}

			$options[ $rate->id ] = sprintf( '%s (%s)', $rate->display_name, $price_label );
		}

		return $options;
	}

	// -------------------------------------------------------------------------
	// Creation & Updates
	// -------------------------------------------------------------------------

	/**
	 * Create a new shipping rate.
	 *
	 * Amount is in major currency units (e.g. 5.99 for $5.99) and is
	 * auto-converted to the smallest unit. Pass amount = 0 for free shipping.
	 *
	 * Note: Amount and currency are immutable after creation. Archive and
	 * recreate to change pricing.
	 *
	 * @param string $display_name Name shown to customers at checkout (e.g. 'Standard Shipping').
	 * @param float  $amount       Shipping cost in major units. 0 for free shipping.
	 * @param string $currency     Three-letter ISO currency code. Default 'USD'.
	 * @param array  $args         Optional: {
	 *     @type array  $delivery_estimate {
	 *         @type array  $minimum { @type string $unit (hour|day|business_day|week|month), @type int $value }
	 *         @type array  $maximum { @type string $unit, @type int $value }
	 *     }
	 *     @type array  $tax_behavior      'inclusive', 'exclusive', or 'unspecified'.
	 *     @type string $tax_code          Stripe tax code for this shipping rate.
	 *     @type array  $metadata          Arbitrary key/value metadata.
	 *     @type bool   $active            Whether active on creation. Default true.
	 * }
	 *
	 * @return ShippingRate|WP_Error
	 * @since 1.0.0
	 */
	public function create( string $display_name, float $amount, string $currency = 'USD', array $args = [] ): ShippingRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [
				'display_name' => $display_name,
				'type'         => 'fixed_amount',
				'fixed_amount' => [
					'amount'   => Currency::to_smallest_unit( $amount, $currency ),
					'currency' => strtolower( $currency ),
				],
				'active'       => $args['active'] ?? true,
			];

			foreach ( [ 'delivery_estimate', 'tax_behavior', 'tax_code', 'metadata' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->shippingRates->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a shipping rate.
	 *
	 * Only display_name, active, metadata, and tax_behavior can be changed
	 * after creation. Amount and currency are immutable.
	 *
	 * @param string $shipping_rate_id Stripe shipping rate ID (shr_xxx).
	 * @param array  $args             Fields to update: display_name, active, metadata, tax_behavior.
	 *
	 * @return ShippingRate|WP_Error
	 * @since 1.0.0
	 */
	public function update( string $shipping_rate_id, array $args ): ShippingRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			foreach ( [ 'display_name', 'active', 'metadata', 'tax_behavior' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->shippingRates->update( $shipping_rate_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Status Management
	// -------------------------------------------------------------------------

	/**
	 * Archive a shipping rate (set active = false).
	 *
	 * Archived rates cannot be added to new checkout sessions.
	 * Existing sessions with this rate are unaffected.
	 *
	 * @param string $shipping_rate_id Stripe shipping rate ID (shr_xxx).
	 *
	 * @return ShippingRate|WP_Error
	 * @since 1.0.0
	 */
	public function archive( string $shipping_rate_id ): ShippingRate|WP_Error {
		return $this->update( $shipping_rate_id, [ 'active' => false ] );
	}

	/**
	 * Unarchive a shipping rate (set active = true).
	 *
	 * @param string $shipping_rate_id Stripe shipping rate ID (shr_xxx).
	 *
	 * @return ShippingRate|WP_Error
	 * @since 1.0.0
	 */
	public function unarchive( string $shipping_rate_id ): ShippingRate|WP_Error {
		return $this->update( $shipping_rate_id, [ 'active' => true ] );
	}

	// -------------------------------------------------------------------------
	// Bulk Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Fetch ALL shipping rates, auto-paginating through all pages.
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return ShippingRate[]|WP_Error
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

				$response = $stripe->shippingRates->all( $query );
				$all      = array_merge( $all, $response->data );
				$cursor   = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $all;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}