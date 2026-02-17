<?php
/**
 * Stripe Checkout Sessions Helper
 *
 * Provides convenience methods for creating and managing Stripe
 * Checkout sessions. Handles common patterns like success/cancel
 * URL formatting and session expiration.
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
use Stripe\Checkout\Session;
use WP_Error;

/**
 * Class Checkout
 *
 * Manages Stripe Checkout session creation and retrieval.
 *
 * Usage:
 *   $checkout = new Checkout( $client );
 *
 *   // Auto-detect mode (payment vs subscription)
 *   $session = $checkout->create_session( [
 *       [ 'price' => 'price_xxx', 'quantity' => 1 ],
 *   ], [
 *       'success_url' => home_url( '/thank-you/' ),
 *       'cancel_url'  => home_url( '/cart/' ),
 *   ] );
 *
 *   // Explicit mode
 *   $session = $checkout->create( 'payment', $line_items, $args );
 *
 * @since 1.0.0
 */
class Checkout {

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
	 *  Session Creation
	 *  ======================================================================== */

	/**
	 * Create a checkout session with automatic mode detection.
	 *
	 * Determines whether to create a 'payment' or 'subscription' session
	 * based on line items. Mode can be explicitly set via $args['mode'] to
	 * skip detection.
	 *
	 * Detection logic:
	 * - If any line item has price_data.recurring, mode is 'subscription'.
	 * - If $args['recurring_price_ids'] is provided and any line item price
	 *   matches, mode is 'subscription'.
	 * - Otherwise defaults to 'payment'.
	 *
	 * This avoids extra Stripe API calls — the caller provides the recurring
	 * price IDs they already know about (e.g., from a local database).
	 *
	 * @param array   $line_items              Array of line item arrays with 'price' and 'quantity' keys.
	 * @param array   $args                    {
	 *                                         Session arguments. Same as create() args plus:
	 *
	 * @type string   $mode                    Explicit mode override ('payment', 'subscription', 'setup').
	 * @type string[] $recurring_price_ids     Array of Stripe price IDs known to be recurring.
	 *                                         Used for auto-detection without extra API calls.
	 *                                         }
	 *
	 * @return Session|WP_Error The checkout session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create_session( array $line_items, array $args = [] ): Session|WP_Error {
		// Explicit mode takes priority
		if ( ! empty( $args['mode'] ) ) {
			$mode = $args['mode'];
			unset( $args['mode'] );

			return $this->create( $mode, $line_items, $args );
		}

		// Auto-detect from line items
		$mode = $this->detect_mode( $line_items, $args['recurring_price_ids'] ?? [] );
		unset( $args['recurring_price_ids'] );

		return $this->create( $mode, $line_items, $args );
	}

	/**
	 * Create a checkout session.
	 *
	 * Core creation method. Merges provided arguments with sensible defaults.
	 * Merges provided arguments with sensible defaults.
	 *
	 * @param string $mode       'payment' or 'subscription'.
	 * @param array  $line_items Array of line item arrays.
	 * @param array  $args       Session arguments.
	 *
	 * @return Session|WP_Error The checkout session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( string $mode, array $line_items, array $args = [] ): Session|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'no_items', __( 'At least one line item is required.', 'arraypress' ) );
		}

		$valid_modes = [ 'payment', 'subscription', 'setup' ];
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			return new WP_Error(
				'invalid_mode',
				sprintf(
				/* translators: %s: comma-separated list of valid modes */
					__( 'Checkout mode must be one of: %s', 'arraypress' ),
					implode( ', ', $valid_modes )
				)
			);
		}

		// Build session parameters
		$params = [
			'mode'        => $mode,
			'line_items'  => $line_items,
			'success_url' => $args['success_url'] ?? home_url( '/' ),
			'cancel_url'  => $args['cancel_url'] ?? home_url( '/' ),
		];

		// Customer identification
		if ( ! empty( $args['customer'] ) ) {
			$params['customer'] = $args['customer'];
		} elseif ( ! empty( $args['customer_email'] ) ) {
			$params['customer_email'] = $args['customer_email'];
		}

		// Metadata
		if ( ! empty( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		// Promotion codes
		if ( ! empty( $args['allow_promotion_codes'] ) ) {
			$params['allow_promotion_codes'] = true;
		}

		// Billing address
		if ( ! empty( $args['billing_address_collection'] ) ) {
			$params['billing_address_collection'] = $args['billing_address_collection'];
		}

		// Phone collection
		if ( ! empty( $args['phone_number_collection'] ) ) {
			$params['phone_number_collection'] = $args['phone_number_collection'];
		}

		// Tax
		if ( ! empty( $args['automatic_tax'] ) ) {
			$params['automatic_tax'] = $args['automatic_tax'];
		}

		if ( ! empty( $args['tax_id_collection'] ) ) {
			$params['tax_id_collection'] = $args['tax_id_collection'];
		}

		// Custom fields (max 3)
		if ( ! empty( $args['custom_fields'] ) ) {
			$params['custom_fields'] = array_slice( $args['custom_fields'], 0, 3 );
		}

		// Custom text
		if ( ! empty( $args['custom_text'] ) ) {
			$params['custom_text'] = $args['custom_text'];
		}

		// Consent collection
		if ( ! empty( $args['consent_collection'] ) ) {
			$params['consent_collection'] = $args['consent_collection'];
		}

		// Subscription-specific data
		if ( $mode === 'subscription' && ! empty( $args['subscription_data'] ) ) {
			$params['subscription_data'] = $args['subscription_data'];
		}

		// Managed payments
		if ( ! empty( $args['managed_payments'] ) ) {
			$params['managed_payments'] = $args['managed_payments'];
		}

		// Allow any additional raw parameters
		$passthrough = [
			'locale',
			'payment_method_types',
			'payment_intent_data',
			'invoice_creation',
			'after_expiration',
			'expires_at',
			'shipping_address_collection',
			'shipping_options',
		];

		foreach ( $passthrough as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}

		try {
			return $stripe->checkout->sessions->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Retrieval
	 *  ======================================================================== */

	/**
	 * Retrieve a checkout session.
	 *
	 * @param string $session_id Stripe checkout session ID.
	 * @param array  $params     Optional parameters (e.g., expand).
	 *
	 * @return Session|WP_Error The session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $session_id, array $params = [] ): Session|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->checkout->sessions->retrieve( $session_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a checkout session with common expansions.
	 *
	 * Expands line items, payment intent, and customer data
	 * for post-checkout processing.
	 *
	 * @param string $session_id Stripe checkout session ID.
	 *
	 * @return Session|WP_Error The expanded session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get_expanded( string $session_id ): Session|WP_Error {
		return $this->get( $session_id, [
			'expand' => [
				'line_items',
				'payment_intent',
				'customer',
			],
		] );
	}

	/**
	 * Get line items from a checkout session.
	 *
	 * @param string $session_id Stripe checkout session ID.
	 *
	 * @return array|WP_Error Array of line items or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get_line_items( string $session_id ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$result = $stripe->checkout->sessions->allLineItems( $session_id );

			return $result->data;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Management
	 *  ======================================================================== */

	/**
	 * Expire a checkout session.
	 *
	 * Forces a session to expire immediately, preventing the customer
	 * from completing payment. Only works on sessions in 'open' status.
	 *
	 * @param string $session_id Stripe checkout session ID.
	 *
	 * @return Session|WP_Error The expired session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function expire( string $session_id ): Session|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->checkout->sessions->expire( $session_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Webhook Data Extraction
	 *  ======================================================================== */

	/**
	 * Get all data needed to process a completed checkout session.
	 *
	 * Performs multiple API calls to assemble the complete dataset
	 * needed for order creation: session details, line items, payment
	 * card details, and customer country.
	 *
	 * This is designed for use inside a checkout.session.completed
	 * webhook handler and eliminates the need to juggle multiple
	 * API calls in your handler code.
	 *
	 * @param string $session_id        Stripe checkout session ID.
	 *
	 * @return array|WP_Error {
	 *     Complete checkout data or WP_Error on failure.
	 *
	 * @type string  $session_id        Stripe checkout session ID.
	 * @type string  $payment_intent_id Stripe payment intent ID.
	 * @type string  $subscription_id   Stripe subscription ID (empty if one-time).
	 * @type string  $customer_id       Stripe customer ID.
	 * @type string  $customer_email    Customer email address.
	 * @type string  $customer_name     Customer name from billing details.
	 * @type int     $total             Total amount in smallest currency unit.
	 * @type string  $currency          ISO 4217 currency code (lowercase).
	 * @type string  $country           Two-letter country code from billing or card.
	 * @type string  $payment_brand     Card brand (e.g., 'visa', 'mastercard').
	 * @type string  $payment_last4     Last four digits of the card.
	 * @type string  $payment_type      Payment method type (e.g., 'card').
	 * @type string  $mode              'payment' or 'subscription'.
	 * @type string  $status            Session status.
	 * @type string  $payment_status    Payment status.
	 * @type array   $metadata          Session metadata.
	 * @type bool    $is_test           Whether this was a test mode transaction.
	 * @type array   $line_items        Array of line item data arrays.
	 * @type array   $discount          Discount data (code, coupon_id, amount_off, percent_off).
	 *                                  }
	 * @since 1.0.0
	 *
	 */
	public function get_completed_data( string $session_id ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		// 1. Get expanded session
		$session = $this->get( $session_id, [
			'expand' => [
				'line_items.data.price.product',
				'payment_intent.payment_method',
				'customer',
				'total_details.breakdown',
			],
		] );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// 2. Extract payment details from expanded payment intent
		$payment_brand = '';
		$payment_last4 = '';
		$payment_type  = '';
		$country       = '';

		$pi = $session->payment_intent ?? null;

		if ( $pi ) {
			$pm = $pi->payment_method ?? null;

			if ( $pm && isset( $pm->card ) ) {
				$payment_brand = $pm->card->brand ?? '';
				$payment_last4 = $pm->card->last4 ?? '';
				$payment_type  = 'card';
				$country       = $pm->card->country ?? '';
			} elseif ( $pm ) {
				$payment_type = $pm->type ?? '';
			}

			// Try billing address for country if card country unavailable
			if ( empty( $country ) && isset( $pi->latest_charge->billing_details->address->country ) ) {
				$country = $pi->latest_charge->billing_details->address->country;
			}
		}

		// Fallback country from customer address
		if ( empty( $country ) && isset( $session->customer_details->address->country ) ) {
			$country = $session->customer_details->address->country;
		}

		// 3. Extract customer info
		$customer_email = $session->customer_details->email ?? $session->customer_email ?? '';
		$customer_name  = $session->customer_details->name ?? '';

		if ( empty( $customer_name ) && $session->customer ) {
			$customer_name = is_object( $session->customer )
				? ( $session->customer->name ?? '' )
				: '';
		}

		// 4. Extract line items
		$line_items = [];

		$raw_items = $session->line_items->data ?? [];
		foreach ( $raw_items as $item ) {
			$price   = $item->price ?? null;
			$product = $price->product ?? null;

			$line_items[] = [
				'stripe_price_id'   => $price->id ?? '',
				'stripe_product_id' => is_object( $product ) ? $product->id : ( $price->product ?? '' ),
				'product_name'      => is_object( $product ) ? ( $product->name ?? '' ) : ( $item->description ?? '' ),
				'quantity'          => $item->quantity ?? 1,
				'total'             => $item->amount_total ?? 0,
				'unit_amount'       => $price->unit_amount ?? 0,
				'currency'          => $price->currency ?? $session->currency ?? '',
				'interval'          => $price->recurring->interval ?? null,
				'interval_count'    => $price->recurring->interval_count ?? null,
			];
		}

		// 5. Extract discount info
		$discount = [
			'code'        => '',
			'coupon_id'   => '',
			'amount_off'  => 0,
			'percent_off' => 0,
		];

		$discounts = $session->total_details->breakdown->discounts ?? [];
		if ( ! empty( $discounts ) ) {
			$first_discount = $discounts[0];
			$coupon         = $first_discount->discount->coupon ?? null;

			$discount['amount_off'] = $first_discount->amount ?? 0;

			if ( $coupon ) {
				$discount['coupon_id']   = $coupon->id ?? '';
				$discount['percent_off'] = $coupon->percent_off ?? 0;
			}

			$promo = $first_discount->discount->promotion_code ?? null;
			if ( is_object( $promo ) ) {
				$discount['code'] = $promo->code ?? '';
			} elseif ( is_string( $promo ) ) {
				$discount['code'] = $promo;
			}
		}

		// 6. Detect test mode from key prefix
		$is_test = $this->client->is_test_mode();

		// 7. Assemble the complete dataset
		return [
			'session_id'        => $session->id,
			'payment_intent_id' => is_object( $pi ) ? $pi->id : ( $session->payment_intent ?? '' ),
			'subscription_id'   => $session->subscription ?? '',
			'customer_id'       => is_object( $session->customer ) ? $session->customer->id : ( $session->customer ?? '' ),
			'customer_email'    => $customer_email,
			'customer_name'     => $customer_name,
			'total'             => $session->amount_total ?? 0,
			'currency'          => strtolower( $session->currency ?? 'usd' ),
			'country'           => $country,
			'payment_brand'     => $payment_brand,
			'payment_last4'     => $payment_last4,
			'payment_type'      => $payment_type,
			'mode'              => $session->mode ?? 'payment',
			'status'            => $session->status ?? '',
			'payment_status'    => $session->payment_status ?? '',
			'metadata'          => (array) ( $session->metadata ?? [] ),
			'is_test'           => $is_test,
			'line_items'        => $line_items,
			'discount'          => $discount,
		];
	}

	/** =========================================================================
	 *  URL Helpers
	 *  ======================================================================== */

	/**
	 * Build a success URL with the session ID placeholder.
	 *
	 * Stripe replaces {CHECKOUT_SESSION_ID} with the actual session ID
	 * after successful payment. This helper appends it as a query parameter.
	 *
	 * @param string $base_url Base URL for the success page.
	 * @param string $param    Query parameter name. Default 'session_id'.
	 *
	 * @return string URL with session ID placeholder.
	 * @since 1.0.0
	 *
	 */
	public static function success_url( string $base_url, string $param = 'session_id' ): string {
		return add_query_arg( $param, '{CHECKOUT_SESSION_ID}', $base_url );
	}

	/** =========================================================================
	 *  Internal Helpers
	 *  ======================================================================== */

	/**
	 * Detect checkout mode from line items.
	 *
	 * Checks for recurring indicators without making any Stripe API calls:
	 *
	 * 1. Inline price_data with a 'recurring' key → subscription
	 * 2. Price ID found in $recurring_price_ids → subscription
	 * 3. Otherwise → payment
	 *
	 * @param array    $line_items          Line items array.
	 * @param string[] $recurring_price_ids Known recurring price IDs from local data.
	 *
	 * @return string 'payment' or 'subscription'.
	 * @since 1.0.0
	 *
	 */
	private function detect_mode( array $line_items, array $recurring_price_ids = [] ): string {
		foreach ( $line_items as $item ) {
			// Check inline price_data for recurring
			if ( ! empty( $item['price_data']['recurring'] ) ) {
				return 'subscription';
			}

			// Check price ID against known recurring IDs
			if ( ! empty( $item['price'] ) && ! empty( $recurring_price_ids ) ) {
				if ( in_array( $item['price'], $recurring_price_ids, true ) ) {
					return 'subscription';
				}
			}
		}

		return 'payment';
	}

}
