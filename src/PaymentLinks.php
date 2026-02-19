<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\PaymentLink;
use WP_Error;

/**
 * Payment Links
 *
 * Manages Stripe Payment Links — shareable URLs that let customers complete
 * a purchase without requiring a server-side session. Unlike Checkout sessions
 * (which are created per-customer on demand), payment links are created once
 * and can be shared indefinitely via email, social media, QR codes, or invoices.
 *
 * Key differences from Checkout:
 *   - Created once, reused many times (no per-customer session needed)
 *   - Mode must be specified explicitly (no auto-detection)
 *   - URL is permanent until deactivated or deleted
 *   - No customer pre-population (email, name, etc.)
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class PaymentLinks {

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
	// Creation & Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Create a new payment link.
	 *
	 * Line items follow the same format as Checkout: each item needs a price ID
	 * and quantity. The mode is inferred from the price type (one-time vs recurring)
	 * but can be specified explicitly.
	 *
	 * @param array $line_items                  Line items to sell. Each item: [ 'price' => 'price_xxx', 'quantity' =>
	 *                                           1 ] Up to 20 line items supported.
	 * @param array $args                        Optional parameters: {
	 *
	 * @type bool   $active                      Whether the link is active on creation. Default true.
	 * @type string $after_completion            Action after payment: 'redirect', 'hosted_confirmation'. Default
	 *       'hosted_confirmation'.
	 * @type array  $after_completion_data       For redirect: [ 'url' => 'https://...' ]. For hosted_confirmation: [
	 *       'custom_message' => '...' ]
	 * @type bool   $allow_promotion_codes       Allow customers to enter promotion codes. Default false.
	 * @type array  $automatic_tax               [ 'enabled' => true ]
	 * @type string $billing_address_collection  'auto' or 'required'. Default 'auto'.
	 * @type array  $consent_collection          Consent/terms collection options.
	 * @type array  $custom_fields               Up to 3 custom fields for the checkout form.
	 * @type array  $custom_text                 Custom display text blocks.
	 * @type array  $invoice_creation            Invoice settings: [ 'enabled' => true ]
	 * @type string $payment_method_collection   'always' or 'if_required'.
	 * @type array  $payment_method_types        Explicit payment method types: ['card', 'klarna', etc.]
	 * @type array  $phone_number_collection     [ 'enabled' => true ]
	 * @type array  $restrictions                Purchase restrictions: [ 'completed_sessions' => [ 'limit' => 100 ] ]
	 * @type array  $shipping_address_collection Shipping address: [ 'allowed_countries' => ['US', 'GB'] ]
	 * @type array  $shipping_options            Shipping rate IDs: [ [ 'shipping_rate' => 'shr_xxx' ] ]
	 * @type array  $subscription_data           Subscription settings (subscription mode only).
	 * @type array  $tax_id_collection           [ 'enabled' => true ]
	 * @type string $transfer_data               Connect: [ 'destination' => 'acct_xxx' ]
	 * @type array  $metadata                    Arbitrary key/value metadata.
	 *                                           }
	 *
	 * @return PaymentLink|WP_Error
	 * @since 1.0.0
	 */
	public function create( array $line_items, array $args = [] ): PaymentLink|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'missing_line_items', __( 'At least one line item is required.', 'arraypress' ) );
		}

		try {
			$params = [ 'line_items' => $line_items ];

			// Handle after_completion separately since it has nested structure
			if ( isset( $args['after_completion'] ) ) {
				$type                       = $args['after_completion'];
				$data                       = $args['after_completion_data'] ?? [];
				$params['after_completion'] = [ 'type' => $type ];

				if ( $type === 'redirect' && ! empty( $data['url'] ) ) {
					$params['after_completion']['redirect'] = [ 'url' => $data['url'] ];
				} elseif ( $type === 'hosted_confirmation' && ! empty( $data['custom_message'] ) ) {
					$params['after_completion']['hosted_confirmation'] = [ 'custom_message' => $data['custom_message'] ];
				}
			}

			$passthrough = [
				'active',
				'allow_promotion_codes',
				'automatic_tax',
				'billing_address_collection',
				'consent_collection',
				'custom_fields',
				'custom_text',
				'invoice_creation',
				'payment_method_collection',
				'payment_method_types',
				'phone_number_collection',
				'restrictions',
				'shipping_address_collection',
				'shipping_options',
				'subscription_data',
				'tax_id_collection',
				'transfer_data',
				'metadata',
			];

			foreach ( $passthrough as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->paymentLinks->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a single payment link by ID.
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 *
	 * @return PaymentLink|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $payment_link_id ): PaymentLink|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->paymentLinks->retrieve( $payment_link_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a payment link.
	 *
	 * Line items can be updated by passing the full new set. Active status,
	 * metadata, and most settings can be changed after creation.
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 * @param array  $args            Fields to update. Same options as create() args.
	 *
	 * @return PaymentLink|WP_Error
	 * @since 1.0.0
	 */
	public function update( string $payment_link_id, array $args ): PaymentLink|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			if ( isset( $args['line_items'] ) ) {
				$params['line_items'] = $args['line_items'];
			}

			if ( isset( $args['after_completion'] ) ) {
				$type                       = $args['after_completion'];
				$data                       = $args['after_completion_data'] ?? [];
				$params['after_completion'] = [ 'type' => $type ];

				if ( $type === 'redirect' && ! empty( $data['url'] ) ) {
					$params['after_completion']['redirect'] = [ 'url' => $data['url'] ];
				} elseif ( $type === 'hosted_confirmation' && ! empty( $data['custom_message'] ) ) {
					$params['after_completion']['hosted_confirmation'] = [ 'custom_message' => $data['custom_message'] ];
				}
			}

			$passthrough = [
				'active',
				'allow_promotion_codes',
				'automatic_tax',
				'billing_address_collection',
				'custom_fields',
				'custom_text',
				'invoice_creation',
				'payment_method_collection',
				'payment_method_types',
				'phone_number_collection',
				'restrictions',
				'shipping_address_collection',
				'shipping_options',
				'subscription_data',
				'tax_id_collection',
				'metadata',
			];

			foreach ( $passthrough as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->paymentLinks->update( $payment_link_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Listing
	// -------------------------------------------------------------------------

	/**
	 * List payment links with optional filters.
	 *
	 * @param array $params Optional filters:
	 *                      - active (bool) Only return active or inactive links.
	 *                      - limit  (int)  Max results per page (default 10, max 100).
	 *
	 * @return array|WP_Error [ 'items' => PaymentLink[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->paymentLinks->all( $params );

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
	 * List active payment links as a key/value array for admin dropdowns.
	 *
	 * Returns [ 'plink_xxx' => 'https://buy.stripe.com/...' ].
	 *
	 * @return array|WP_Error [ 'plink_xxx' => 'https://buy.stripe.com/...' ]
	 * @since 1.0.0
	 */
	public function get_options(): array|WP_Error {
		$result = $this->list( [ 'active' => true ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$options = [];

		foreach ( $result['items'] as $link ) {
			$options[ $link->id ] = $link->url;
		}

		return $options;
	}

	/**
	 * List all payment links that include a specific price ID in their line items.
	 *
	 * Fetches all links and filters client-side, since Stripe doesn't support
	 * filtering by price on the list endpoint.
	 *
	 * @param string $price_id Stripe price ID (price_xxx).
	 * @param bool   $active   If true, only return active links. Default true.
	 *
	 * @return PaymentLink[]|WP_Error
	 * @since 1.0.0
	 */
	public function list_by_price( string $price_id, bool $active = true ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$matched = [];
			$cursor  = null;
			$params  = [ 'limit' => 100 ];

			if ( $active ) {
				$params['active'] = true;
			}

			do {
				$query = $params;

				if ( $cursor ) {
					$query['starting_after'] = $cursor;
				}

				$response = $stripe->paymentLinks->all( $query );

				foreach ( $response->data as $link ) {
					// Fetch line items for each link to check price IDs
					$line_items = $stripe->paymentLinks->allLineItems( $link->id );

					foreach ( $line_items->data as $item ) {
						if ( ( $item->price->id ?? '' ) === $price_id ) {
							$matched[] = $link;
							break;
						}
					}
				}

				$cursor = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $matched;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get line items for a payment link.
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 *
	 * @return array|WP_Error [ 'items' => LineItem[], 'has_more' => bool ]
	 * @since 1.0.0
	 */
	public function get_line_items( string $payment_link_id ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->paymentLinks->allLineItems( $payment_link_id );

			return [
				'items'    => $response->data,
				'has_more' => $response->has_more,
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Status Management
	// -------------------------------------------------------------------------

	/**
	 * Activate a payment link (set active = true).
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 *
	 * @return PaymentLink|WP_Error
	 * @since 1.0.0
	 */
	public function activate( string $payment_link_id ): PaymentLink|WP_Error {
		return $this->update( $payment_link_id, [ 'active' => true ] );
	}

	/**
	 * Deactivate a payment link (set active = false).
	 *
	 * Customers visiting the URL will see a deactivated page. The link
	 * can be reactivated at any time with activate().
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 *
	 * @return PaymentLink|WP_Error
	 * @since 1.0.0
	 */
	public function deactivate( string $payment_link_id ): PaymentLink|WP_Error {
		return $this->update( $payment_link_id, [ 'active' => false ] );
	}

	// -------------------------------------------------------------------------
	// Bulk Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Fetch ALL payment links, auto-paginating through all pages.
	 *
	 * @param array $params Optional filters (same as list()).
	 *
	 * @return PaymentLink[]|WP_Error
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

				$response = $stripe->paymentLinks->all( $query );
				$all      = array_merge( $all, $response->data );
				$cursor   = ! empty( $response->data ) ? end( $response->data )->id : null;
			} while ( $response->has_more );

			return $all;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}