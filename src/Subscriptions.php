<?php
/**
 * Stripe Subscriptions Helper
 *
 * Provides convenience methods for managing Stripe subscriptions
 * including cancellation, pausing, resuming, price changes, and
 * common retrieval patterns.
 *
 * Subscriptions should be created via Checkout Sessions rather than
 * directly — this class handles post-creation management.
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
use Stripe\Subscription;
use WP_Error;

/**
 * Class Subscriptions
 *
 * Manages Stripe subscription lifecycle operations.
 *
 * Usage:
 *   $subscriptions = new Subscriptions( $client );
 *
 *   // Cancel at period end
 *   $subscriptions->cancel( 'sub_xxx' );
 *
 *   // Cancel immediately
 *   $subscriptions->cancel_immediately( 'sub_xxx' );
 *
 *   // Change price
 *   $subscriptions->change_price( 'sub_xxx', 'price_new' );
 *
 * @since 1.0.0
 */
class Subscriptions {

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
	 * Retrieve a subscription from Stripe.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param array  $params          Optional parameters (e.g., expand).
	 *
	 * @return Subscription|WP_Error The subscription or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $subscription_id, array $params = [] ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->subscriptions->retrieve( $subscription_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a subscription with expanded data.
	 *
	 * Expands the default payment method, latest invoice, and
	 * product data for each item — covers most post-retrieval needs.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @return Subscription|WP_Error The expanded subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_expanded( string $subscription_id ): Subscription|WP_Error {
		return $this->get( $subscription_id, [
			'expand' => [
				'default_payment_method',
				'latest_invoice',
				'items.data.price.product',
			],
		] );
	}

	/**
	 * List subscriptions for a customer.
	 *
	 * @param string $customer_id    Stripe customer ID.
	 * @param array  $params         {
	 *                               Optional. Stripe list parameters.
	 *
	 * @type string  $status         Filter by status: 'active', 'past_due', 'canceled', etc.
	 * @type int     $limit          Number of results (1-100). Default 100.
	 * @type string  $starting_after Cursor for pagination.
	 *                               }
	 *
	 * @return array{items: Subscription[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list_by_customer( string $customer_id, array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = wp_parse_args( $params, [
			'customer' => $customer_id,
			'limit'    => 100,
		] );

		try {
			$result    = $stripe->subscriptions->all( $params );
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
	 * List subscriptions for a customer, returning plain stdClass objects.
	 *
	 * Identical to list_by_customer() but strips Stripe SDK internals from
	 * each item via JSON round-trip. Use when results will be passed to a
	 * REST endpoint, stored in a transient, or handed to any system
	 * expecting plain serializable objects (e.g., wp-inline-sync batch
	 * callbacks).
	 *
	 * @param string $customer_id    Stripe customer ID.
	 * @param array  $params         {
	 *                               Optional. Same parameters as list_by_customer().
	 *
	 * @type string  $status         Filter by status: 'active', 'past_due', 'canceled', etc.
	 * @type int     $limit          Number of results (1-100). Default 100.
	 * @type string  $starting_after Cursor for pagination.
	 *                               }
	 *
	 * @return array{items: \stdClass[], has_more: bool, cursor: string}|WP_Error
	 *
	 * @since 1.0.0
	 *
	 * @see   list_by_customer()
	 */
	public function list_by_customer_serialized( string $customer_id, array $params = [] ): array|WP_Error {
		$result = $this->list_by_customer( $customer_id, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->serialize_result( $result );
	}

	/**
	 * List subscriptions with optional filters.
	 *
	 * Returns a paginated list of subscriptions across all customers.
	 * Useful for admin listing pages and reporting.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Optional. Filter and pagination parameters.
	 *
	 *     @type string $status         Filter by status: 'active', 'past_due', 'canceled', 'unpaid',
	 *                                  'incomplete', 'incomplete_expired', 'trialing', 'paused', 'all'.
	 *     @type string $price          Filter by price ID.
	 *     @type string $customer       Filter by customer ID.
	 *     @type array  $created        Filter by creation date (e.g., ['gte' => timestamp]).
	 *     @type int    $limit          Number of results per page (default 25, max 100).
	 *     @type string $starting_after Cursor for pagination.
	 *     @type array  $expand         Fields to expand.
	 * }
	 *
	 * @return array{items: Subscription[], has_more: bool, cursor: string}|WP_Error
	 */
	public function list( array $params = [] ): array|WP_Error {
		$client = $this->client->stripe();

		if ( ! $client ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$args = [];

			if ( isset( $params['status'] ) ) {
				$args['status'] = $params['status'];
			}

			if ( isset( $params['price'] ) ) {
				$args['price'] = $params['price'];
			}

			if ( isset( $params['customer'] ) ) {
				$args['customer'] = $params['customer'];
			}

			if ( isset( $params['created'] ) ) {
				$args['created'] = $params['created'];
			}

			if ( isset( $params['expand'] ) ) {
				$args['expand'] = $params['expand'];
			}

			$args['limit'] = min( $params['limit'] ?? 25, 100 );

			if ( ! empty( $params['starting_after'] ) ) {
				$args['starting_after'] = $params['starting_after'];
			}

			$result = $client->subscriptions->all( $args );

			$items  = $result->data;
			$cursor = ! empty( $items ) ? end( $items )->id : '';

			return [
				'items'    => $items,
				'has_more' => $result->has_more,
				'cursor'   => $cursor,
			];
		} catch ( \Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Webhook Data Extraction
	 *  ======================================================================== */

	/**
	 * Get normalized data from a subscription webhook event.
	 *
	 * Extracts a consistent dataset from any subscription lifecycle
	 * event (created, updated, deleted, paused, resumed). Returns
	 * everything needed to upsert a local subscription record.
	 *
	 * @param \Stripe\Event $event                  A customer.subscription.* Stripe event.
	 *
	 * @return array {
	 *     Normalized subscription data.
	 *
	 * @type string         $subscription_id        Stripe subscription ID.
	 * @type string         $customer_id            Stripe customer ID.
	 * @type string         $status                 Subscription status.
	 * @type string         $price_id               Current price ID (from first item).
	 * @type string         $product_id             Current product ID (from first item).
	 * @type int            $quantity               Current quantity.
	 * @type string|null    $current_period_end     Period end as 'Y-m-d H:i:s' or null (from SubscriptionItem).
	 * @type string|null    $current_period_start   Period start as 'Y-m-d H:i:s' or null (from SubscriptionItem).
	 * @type bool           $cancel_at_period_end   Whether cancellation is scheduled.
	 * @type string|null    $canceled_at            Cancellation timestamp as 'Y-m-d H:i:s' or null.
	 * @type string|null    $ended_at               End timestamp as 'Y-m-d H:i:s' or null.
	 * @type string         $currency               ISO 4217 currency code (lowercase).
	 * @type int            $amount                 Price amount in smallest unit.
	 * @type string|null    $interval               Billing interval or null.
	 * @type int|null       $interval_count         Interval count or null.
	 * @type bool           $is_test                Whether test mode.
	 * @type array          $metadata               Subscription metadata.
	 * @type string         $event_type             The specific event type for reference.
	 * @type string         $latest_invoice         Latest invoice ID (for payment status lookup).
	 * @type string         $default_payment_method Default payment method ID or empty.
	 *                                              }
	 * @since 1.0.0
	 *
	 */
	public function get_event_data( \Stripe\Event $event ): array {
		$sub = $event->data->object;

		// Extract price from first item
		$items = $sub->items->data ?? [];
		$item  = ! empty( $items ) ? $items[0] : null;
		$price = $item->price ?? null;

		$price_id       = $price->id ?? '';
		$product_id     = $price->product ?? '';
		$amount         = $price->unit_amount ?? 0;
		$currency       = strtolower( $price->currency ?? $sub->currency ?? 'usd' );
		$interval       = $price->recurring->interval ?? null;
		$interval_count = $price->recurring->interval_count ?? null;

		return [
			'subscription_id'        => $sub->id ?? '',
			'customer_id'            => $sub->customer ?? '',
			'status'                 => $sub->status ?? '',
			'price_id'               => $price_id,
			'product_id'             => $product_id,
			'quantity'               => $item->quantity ?? 1,
			'current_period_end'     => isset( $item->current_period_end )
				? gmdate( 'Y-m-d H:i:s', $item->current_period_end )
				: null,
			'current_period_start'   => isset( $item->current_period_start )
				? gmdate( 'Y-m-d H:i:s', $item->current_period_start )
				: null,
			'cancel_at_period_end'   => (bool) ( $sub->cancel_at_period_end ?? false ),
			'canceled_at'            => isset( $sub->canceled_at )
				? gmdate( 'Y-m-d H:i:s', $sub->canceled_at )
				: null,
			'ended_at'               => isset( $sub->ended_at )
				? gmdate( 'Y-m-d H:i:s', $sub->ended_at )
				: null,
			'currency'               => $currency,
			'amount'                 => $amount,
			'interval'               => $interval,
			'interval_count'         => $interval_count,
			'is_test'                => $this->client->is_test_mode(),
			'metadata'               => (array) ( $sub->metadata ?? [] ),
			'event_type'             => $event->type ?? '',
			'latest_invoice'         => $sub->latest_invoice ?? '',
			'default_payment_method' => $sub->default_payment_method ?? '',
		];
	}

	/** =========================================================================
	 *  Cancellation
	 *  ======================================================================== */

	/**
	 * Cancel a subscription at the end of the current billing period.
	 *
	 * The subscription remains active until the period ends, then
	 * transitions to 'canceled'. This is the recommended approach
	 * for user-initiated cancellations.
	 *
	 * @param string $subscription_id      Stripe subscription ID.
	 * @param array  $args                 {
	 *                                     Optional cancellation arguments.
	 *
	 * @type array   $cancellation_details Cancellation feedback.
	 * @type array   $metadata             Additional metadata.
	 *                                     }
	 *
	 * @return Subscription|WP_Error The updated subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function cancel( string $subscription_id, array $args = [] ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [ 'cancel_at_period_end' => true ];

		if ( ! empty( $args['cancellation_details'] ) ) {
			$params['cancellation_details'] = $args['cancellation_details'];
		}

		if ( ! empty( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Cancel a subscription immediately.
	 *
	 * The subscription is canceled right away and no further invoices
	 * will be generated. Use with caution — prefer cancel() for a
	 * better customer experience.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param array  $args            {
	 *                                Optional cancellation arguments.
	 *
	 * @type bool    $prorate         Create prorated credit for unused time. Default false.
	 * @type bool    $invoice         Generate a final invoice immediately. Default false.
	 * @type array   $metadata        Additional metadata.
	 *                                }
	 *
	 * @return Subscription|WP_Error The canceled subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function cancel_immediately( string $subscription_id, array $args = [] ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		if ( ! empty( $args['prorate'] ) ) {
			$params['proration_behavior'] = 'create_prorations';
		}

		if ( ! empty( $args['invoice'] ) ) {
			$params['invoice_now'] = true;
		}

		try {
			return $stripe->subscriptions->cancel( $subscription_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Reactivate a subscription that was set to cancel at period end.
	 *
	 * Removes the cancel_at_period_end flag so the subscription
	 * continues as normal.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @return Subscription|WP_Error The reactivated subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function reactivate( string $subscription_id ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, [
				'cancel_at_period_end' => false,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Pause / Resume
	 *  ======================================================================== */

	/**
	 * Pause a subscription's payment collection.
	 *
	 * The subscription remains active but invoices are not collected.
	 * Useful for temporary holds without full cancellation.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param string $behavior        Pause behavior: 'mark_uncollectible', 'keep_as_draft', or 'void'.
	 *                                Default 'mark_uncollectible'.
	 * @param string $resumes_at      Optional. Unix timestamp when to automatically resume.
	 *
	 * @return Subscription|WP_Error The paused subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function pause( string $subscription_id, string $behavior = 'mark_uncollectible', string $resumes_at = '' ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$valid_behaviors = [ 'mark_uncollectible', 'keep_as_draft', 'void' ];
		if ( ! in_array( $behavior, $valid_behaviors, true ) ) {
			return new WP_Error(
				'invalid_behavior',
				sprintf(
				/* translators: %s: comma-separated list of valid behaviors */
					__( 'Pause behavior must be one of: %s', 'arraypress' ),
					implode( ', ', $valid_behaviors )
				)
			);
		}

		$params = [
			'pause_collection' => [
				'behavior' => $behavior,
			],
		];

		if ( ! empty( $resumes_at ) ) {
			$params['pause_collection']['resumes_at'] = (int) $resumes_at;
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Resume a paused subscription.
	 *
	 * Clears the pause_collection setting, resuming normal
	 * payment collection.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @return Subscription|WP_Error The resumed subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function resume( string $subscription_id ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, [
				'pause_collection' => '',
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Price Changes
	 *  ======================================================================== */

	/**
	 * Change the price on a subscription.
	 *
	 * Replaces the current price with a new one. Handles the
	 * subscription item lookup automatically — you just provide
	 * the new price ID.
	 *
	 * @param string $subscription_id    Stripe subscription ID.
	 * @param string $new_price_id       New Stripe price ID.
	 * @param array  $args               {
	 *                                   Optional price change arguments.
	 *
	 * @type string  $proration_behavior 'create_prorations', 'none', or 'always_invoice'. Default 'create_prorations'.
	 * @type int     $quantity           New quantity. Default keeps current quantity.
	 *                                   }
	 *
	 * @return Subscription|WP_Error The updated subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function change_price( string $subscription_id, string $new_price_id, array $args = [] ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		// Retrieve current subscription to find the item
		$subscription = $this->get( $subscription_id );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		if ( empty( $subscription->items->data ) ) {
			return new WP_Error( 'no_items', __( 'Subscription has no items.', 'arraypress' ) );
		}

		// Use the first item (most subscriptions have one)
		$item = $subscription->items->data[0];

		$params = [
			'items'              => [
				[
					'id'    => $item->id,
					'price' => $new_price_id,
				],
			],
			'proration_behavior' => $args['proration_behavior'] ?? 'create_prorations',
		];

		if ( isset( $args['quantity'] ) ) {
			$params['items'][0]['quantity'] = max( 1, (int) $args['quantity'] );
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Updates
	 *  ======================================================================== */

	/**
	 * Update subscription metadata.
	 *
	 * Merges with existing metadata. Pass null for a key to remove it.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param array  $metadata        Key/value pairs. Null values remove the key.
	 *
	 * @return Subscription|WP_Error The updated subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function update_metadata( string $subscription_id, array $metadata ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, [
				'metadata' => $metadata,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update the default payment method for a subscription.
	 *
	 * @param string $subscription_id   Stripe subscription ID.
	 * @param string $payment_method_id Stripe payment method ID.
	 *
	 * @return Subscription|WP_Error The updated subscription or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function update_payment_method( string $subscription_id, string $payment_method_id ): Subscription|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->subscriptions->update( $subscription_id, [
				'default_payment_method' => $payment_method_id,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Bulk Retrieval
	 *  ======================================================================== */

	/**
	 * Fetch all subscriptions for a customer, auto-paginating.
	 *
	 * Iterates through all pages of results automatically.
	 * Use with caution for customers with many subscriptions.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param array  $params      Optional filter parameters (e.g., status).
	 *
	 * @return Subscription[]|WP_Error All matching subscriptions or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_all_for_customer( string $customer_id, array $params = [] ): array|WP_Error {
		$all_items = [];
		$cursor    = '';

		do {
			$page_params = array_merge( $params, [ 'limit' => 100 ] );

			if ( $cursor ) {
				$page_params['starting_after'] = $cursor;
			}

			$result = $this->list_by_customer( $customer_id, $page_params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$all_items = array_merge( $all_items, $result['items'] );
			$cursor    = $result['cursor'];
		} while ( $result['has_more'] );

		return $all_items;
	}

}
