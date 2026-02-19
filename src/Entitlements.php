<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\Entitlements\ActiveEntitlement;
use WP_Error;

/**
 * Entitlements
 *
 * Queries active entitlements for Stripe customers. Entitlements are managed
 * entirely by Stripe — they are created automatically when a customer purchases
 * a product with features attached, and removed at the end of the billing period
 * when a subscription lapses or a feature is detached from a product.
 *
 * This class is read-only. To manage features or product attachments, use Features.
 *
 * Performance note: Stripe recommends persisting active entitlements in your own
 * database rather than querying this API on every request. Sync them via the
 * customer.entitlement.active_entitlement_summary.updated webhook event, then
 * use get_lookup_keys() to store the resulting array against your user record.
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class Entitlements {

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

	/**
	 * List all active entitlements for a customer.
	 *
	 * Returns every feature the customer currently has access to based on
	 * their active subscriptions and purchases.
	 *
	 * Performance note: Avoid calling this on every request. Persist the results
	 * in your database and refresh via the active_entitlement_summary webhook.
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 * @param array  $params      Optional: limit (int), expand (array).
	 *
	 * @return array|WP_Error [ 'items' => ActiveEntitlement[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_customer( string $customer_id, array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->entitlements->activeEntitlements->all(
				array_merge( $params, [ 'customer' => $customer_id ] )
			);

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
	 * Get all lookup keys for a customer's active entitlements.
	 *
	 * Returns a flat array of lookup key strings. This is the value you want
	 * to persist in your database — it's all you need to gate access in
	 * application code without further API calls.
	 *
	 * Example DB storage: [ 'api_access', 'priority_support', 'unlimited_seats' ]
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 *
	 * @return string[]|WP_Error Array of lookup key strings, e.g. [ 'api_access', 'priority_support' ]
	 * @since 1.0.0
	 */
	public function get_lookup_keys( string $customer_id ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$all    = [];
			$cursor = null;

			do {
				$query = [ 'customer' => $customer_id, 'limit' => 100 ];

				if ( $cursor ) {
					$query['starting_after'] = $cursor;
				}

				$response = $stripe->entitlements->activeEntitlements->all( $query );

				foreach ( $response->data as $entitlement ) {
					$all[] = $entitlement->lookup_key;
				}

				$cursor = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $all;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Check if a customer has access to a specific feature.
	 *
	 * Fetches all active entitlements for the customer and checks for the
	 * given lookup key. For high-frequency checks, use your persisted lookup
	 * keys instead of calling this method directly.
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 * @param string $lookup_key  Feature lookup key to check (e.g. 'api_access').
	 *
	 * @return bool|WP_Error True if entitled, false if not, WP_Error on API failure.
	 * @since 1.0.0
	 */
	public function has_feature( string $customer_id, string $lookup_key ): bool|WP_Error {
		$keys = $this->get_lookup_keys( $customer_id );

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		return in_array( $lookup_key, $keys, true );
	}

	/**
	 * Check if a customer has access to all of the given features.
	 *
	 * @param string   $customer_id  Stripe customer ID (cus_xxx).
	 * @param string[] $lookup_keys  Feature lookup keys to check.
	 *
	 * @return bool|WP_Error True only if entitled to every key, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function has_all_features( string $customer_id, array $lookup_keys ): bool|WP_Error {
		$keys = $this->get_lookup_keys( $customer_id );

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		foreach ( $lookup_keys as $key ) {
			if ( ! in_array( $key, $keys, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if a customer has access to any of the given features.
	 *
	 * @param string   $customer_id  Stripe customer ID (cus_xxx).
	 * @param string[] $lookup_keys  Feature lookup keys to check.
	 *
	 * @return bool|WP_Error True if entitled to at least one key, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function has_any_feature( string $customer_id, array $lookup_keys ): bool|WP_Error {
		$keys = $this->get_lookup_keys( $customer_id );

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		foreach ( $lookup_keys as $key ) {
			if ( in_array( $key, $keys, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve a single active entitlement by ID.
	 *
	 * @param string $entitlement_id Stripe active entitlement ID (ent_xxx).
	 *
	 * @return ActiveEntitlement|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $entitlement_id ): ActiveEntitlement|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->entitlements->activeEntitlements->retrieve( $entitlement_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get entitlement summary data for storing in your database.
	 *
	 * Returns a minimal array suitable for persisting against a user record.
	 * Call this when processing the active_entitlement_summary.updated webhook.
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 *
	 * @return array|WP_Error {
	 *     @type string   $customer_id  The Stripe customer ID.
	 *     @type string[] $lookup_keys  All active feature lookup keys.
	 *     @type int      $count        Number of active entitlements.
	 *     @type int      $updated_at   Unix timestamp of when this was fetched.
	 * }
	 * @since 1.0.0
	 */
	public function get_summary( string $customer_id ): array|WP_Error {
		$keys = $this->get_lookup_keys( $customer_id );

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		return [
			'customer_id' => $customer_id,
			'lookup_keys' => $keys,
			'count'       => count( $keys ),
			'updated_at'  => time(),
		];
	}

}