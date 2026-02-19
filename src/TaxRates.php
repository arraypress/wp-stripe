<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\TaxRate;
use WP_Error;

/**
 * Tax Rates
 *
 * Manages Stripe tax rates for manual tax collection. Supports both inclusive
 * and exclusive rates with full lifecycle management (create, archive, list).
 *
 * Note: Tax rate percentages and inclusive/exclusive type are immutable after
 * creation. Use archive() and create() to replace a rate with corrected values.
 *
 * For fully automatic tax collection, use Stripe Tax instead by passing
 * automatic_tax: [ 'enabled' => true ] in your Checkout session — no tax rates
 * needed in that flow.
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class TaxRates {

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
	 * Retrieve a single tax rate by ID.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID (txr_xxx).
	 * @param array  $params      Optional extra params (e.g. expand).
	 *
	 * @return TaxRate|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $tax_rate_id, array $params = [] ): TaxRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->taxRates->retrieve( $tax_rate_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List tax rates with optional filters.
	 *
	 * Returns a paginated result with 'items', 'has_more', and 'cursor' keys.
	 *
	 * @param array $params Optional filters:
	 *                      - active    (bool)   Only return active/inactive rates.
	 *                      - inclusive (bool)   Filter by inclusive/exclusive type.
	 *                      - limit     (int)    Max results per page (default 10, max 100).
	 *                      - starting_after (string) Cursor for forward pagination.
	 *                      - ending_before  (string) Cursor for backward pagination.
	 *
	 * @return array|WP_Error [ 'items' => TaxRate[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->taxRates->all( $params );

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
	 * List tax rates as plain stdClass objects (safe for REST, transients, JSON).
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
	 * List only active tax rates.
	 *
	 * Shorthand for list( [ 'active' => true ] ) with optional inclusive filter.
	 *
	 * @param bool|null $inclusive null = all, true = inclusive only, false = exclusive only.
	 * @param array     $params    Additional filters.
	 *
	 * @return array|WP_Error [ 'items' => TaxRate[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function get_active( ?bool $inclusive = null, array $params = [] ): array|WP_Error {
		$params['active'] = true;

		if ( $inclusive !== null ) {
			$params['inclusive'] = $inclusive;
		}

		return $this->list( $params );
	}

	/**
	 * List active tax rates as plain stdClass objects.
	 *
	 * Useful for building admin dropdowns without triggering Stripe SDK object overhead.
	 *
	 * @param bool|null $inclusive null = all, true = inclusive only, false = exclusive only.
	 * @param array     $params    Additional filters.
	 *
	 * @return array|WP_Error [ 'items' => stdClass[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function get_active_serialized( ?bool $inclusive = null, array $params = [] ): array|WP_Error {
		$result = $this->get_active( $inclusive, $params );

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
	 * List tax rates as a key/value array for use in admin dropdowns.
	 *
	 * Returns an array of [ 'txr_xxx' => 'Display Name (X%)' ] or, if the rate
	 * has a jurisdiction set, [ 'txr_xxx' => 'Display Name (X%) — Jurisdiction' ].
	 *
	 * @param bool|null $inclusive null = all, true = inclusive only, false = exclusive only.
	 * @param array     $params    Additional filters (e.g. active, limit).
	 *
	 * @return array|WP_Error [ 'txr_xxx' => 'VAT (20%)', ... ]
	 * @since 1.0.0
	 */
	public function list_options( ?bool $inclusive = null, array $params = [] ): array|WP_Error {
		if ( $inclusive !== null ) {
			$params['inclusive'] = $inclusive;
		}

		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$options = [];

		foreach ( $result['items'] as $rate ) {
			$label = sprintf( '%s (%.4g%%)', $rate->display_name, $rate->percentage );

			if ( ! empty( $rate->jurisdiction ) ) {
				$label .= ' — ' . $rate->jurisdiction;
			}

			$options[ $rate->id ] = $label;
		}

		return $options;
	}

	/**
	 * List active tax rates as a key/value array for use in admin dropdowns.
	 *
	 * This is the method you'll use most often when building settings pages or
	 * checkout configuration UI.
	 *
	 * @param bool|null $inclusive null = all, true = inclusive only, false = exclusive only.
	 *
	 * @return array|WP_Error [ 'txr_xxx' => 'VAT (20%)', ... ]
	 * @since 1.0.0
	 */
	public function get_options( ?bool $inclusive = null ): array|WP_Error {
		return $this->list_options( $inclusive, [ 'active' => true ] );
	}

	// -------------------------------------------------------------------------
	// Bulk Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Fetch ALL tax rates, auto-paginating through all pages.
	 *
	 * Use with care — this fetches every rate in the account.
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return TaxRate[]|WP_Error
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

				$response = $stripe->taxRates->all( $query );
				$all      = array_merge( $all, $response->data );
				$cursor   = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $all;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Process all tax rates in batches via a callback.
	 *
	 * Return false from the callback to stop early.
	 *
	 * @param callable $callback Receives ( TaxRate[] $items, int $page ).
	 * @param array    $params   Optional filters (same as list()).
	 *
	 * @return int|WP_Error Total number of rates processed, or WP_Error on failure.
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

				$response = $stripe->taxRates->all( $query );

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
	// Creation & Updates
	// -------------------------------------------------------------------------

	/**
	 * Create a new tax rate.
	 *
	 * Once created, the percentage and inclusive flag are immutable.
	 * If you need to correct either, archive this rate and create a new one,
	 * or use replace() as a convenience wrapper for that pattern.
	 *
	 * @param array $args {
	 *     Required and optional arguments.
	 *
	 *     @type string $display_name        Required. Name shown to customers (e.g. 'VAT', 'GST', 'Sales Tax').
	 *     @type float  $percentage          Required. Tax percentage, e.g. 20.0 for 20%.
	 *     @type bool   $inclusive           Required. true = tax included in price, false = added on top.
	 *     @type string $country             Optional. Two-letter ISO country code, e.g. 'GB', 'DE'.
	 *     @type string $state               Optional. US state code or regional equivalent, e.g. 'CA'.
	 *     @type string $jurisdiction        Optional. Human-readable jurisdiction name, e.g. 'EU VAT'.
	 *     @type string $description         Optional. Internal description (not shown to customers).
	 *     @type array  $metadata            Optional. Arbitrary key/value metadata.
	 *     @type bool   $active              Optional. Whether the rate is active on creation. Default true.
	 *     @type string $tax_type            Optional. Type of tax: 'vat', 'gst', 'hst', 'qst', 'rst',
	 *                                       'sales_tax', 'jct', 'igst', 'cgst', 'sgst', 'cess',
	 *                                       'lease_tax', 'amusement_tax', 'communications_tax'.
	 * }
	 *
	 * @return TaxRate|WP_Error
	 * @since 1.0.0
	 */
	public function create( array $args ): TaxRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $args['display_name'] ) || ! isset( $args['percentage'] ) || ! isset( $args['inclusive'] ) ) {
			return new WP_Error(
				'missing_required',
				__( 'display_name, percentage, and inclusive are required to create a tax rate.', 'arraypress' )
			);
		}

		try {
			$params = [
				'display_name' => $args['display_name'],
				'percentage'   => (float) $args['percentage'],
				'inclusive'    => (bool) $args['inclusive'],
				'active'       => $args['active'] ?? true,
			];

			foreach ( [ 'country', 'state', 'jurisdiction', 'description', 'metadata', 'tax_type' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->taxRates->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a tax rate.
	 *
	 * Only display_name, jurisdiction, description, metadata, and active
	 * can be changed after creation. Percentage and inclusive are immutable.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID (txr_xxx).
	 * @param array  $args        Fields to update. Accepts: display_name, jurisdiction,
	 *                            description, metadata, active.
	 *
	 * @return TaxRate|WP_Error
	 * @since 1.0.0
	 */
	public function update( string $tax_rate_id, array $args ): TaxRate|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			foreach ( [ 'display_name', 'jurisdiction', 'description', 'metadata', 'active' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->taxRates->update( $tax_rate_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Status Management
	// -------------------------------------------------------------------------

	/**
	 * Archive a tax rate (set active = false).
	 *
	 * Archived rates can no longer be applied to new transactions. Existing
	 * transactions with the rate applied are not affected.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID (txr_xxx).
	 *
	 * @return TaxRate|WP_Error
	 * @since 1.0.0
	 */
	public function archive( string $tax_rate_id ): TaxRate|WP_Error {
		return $this->update( $tax_rate_id, [ 'active' => false ] );
	}

	/**
	 * Unarchive a tax rate (set active = true).
	 *
	 * Makes a previously archived rate active and available for new transactions.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID (txr_xxx).
	 *
	 * @return TaxRate|WP_Error
	 * @since 1.0.0
	 */
	public function unarchive( string $tax_rate_id ): TaxRate|WP_Error {
		return $this->update( $tax_rate_id, [ 'active' => true ] );
	}

	/**
	 * Replace a tax rate by archiving the old one and creating a corrected copy.
	 *
	 * Since percentage and inclusive are immutable after creation, this is the
	 * correct pattern when you need to fix either value.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID of the rate to replace (txr_xxx).
	 * @param array  $new_args    Arguments for the new rate (same shape as create()).
	 *                            If a field is omitted, it is copied from the old rate.
	 *
	 * @return array|WP_Error {
	 *     @type TaxRate $new_rate The newly created tax rate.
	 *     @type TaxRate $old_rate The archived old tax rate.
	 * }
	 * @since 1.0.0
	 */
	public function replace( string $tax_rate_id, array $new_args ): array|WP_Error {
		$old_rate = $this->get( $tax_rate_id );

		if ( is_wp_error( $old_rate ) ) {
			return $old_rate;
		}

		// Fill in omitted fields from the old rate
		$defaults = [
			'display_name' => $old_rate->display_name,
			'percentage'   => $old_rate->percentage,
			'inclusive'    => $old_rate->inclusive,
		];

		foreach ( [ 'country', 'state', 'jurisdiction', 'description', 'metadata', 'tax_type' ] as $field ) {
			if ( ! empty( $old_rate->$field ) ) {
				$defaults[ $field ] = $old_rate->$field;
			}
		}

		$merged   = array_merge( $defaults, $new_args );
		$new_rate = $this->create( $merged );

		if ( is_wp_error( $new_rate ) ) {
			return $new_rate;
		}

		$archived = $this->archive( $tax_rate_id );

		if ( is_wp_error( $archived ) ) {
			return new WP_Error(
				'partial_replace',
				__( 'New tax rate created but old rate could not be archived.', 'arraypress' ),
				[ 'new_rate' => $new_rate, 'old_rate_id' => $tax_rate_id ]
			);
		}

		return [
			'new_rate' => $new_rate,
			'old_rate' => $archived,
		];
	}

}