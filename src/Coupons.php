<?php
/**
 * Stripe Coupons & Promotion Codes Helper
 *
 * Provides a simple interface for creating and managing Stripe discount codes.
 *
 * In Stripe's model, a "coupon" defines the discount rules and a "promotion code"
 * is the customer-facing code (e.g., "SUMMER25"). This class treats them as one
 * concept: create() always produces both objects in a single call.
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
use Stripe\Coupon;
use Stripe\PromotionCode;
use WP_Error;

/**
 * Class Coupons
 *
 * Manages Stripe coupons and promotion codes.
 *
 * Usage:
 *   $coupons = new Coupons( $client );
 *
 *   // Create a 25% off code
 *   $result = $coupons->create( 'SUMMER25', [
 *       'percent_off' => 25,
 *   ] );
 *
 *   // Create a $10 off code
 *   $result = $coupons->create( 'TENOFF', [
 *       'amount_off' => 10.00,
 *       'currency'   => 'USD',
 *   ] );
 *
 *   // $result['coupon']         → Stripe\Coupon
 *   // $result['promotion_code'] → Stripe\PromotionCode
 *
 * @since 1.0.0
 */
class Coupons {

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
	 *  Creation
	 *  ======================================================================== */

	/**
	 * Create a discount code (coupon + promotion code).
	 *
	 * Always creates both the Stripe coupon (discount rules) and
	 * a customer-facing promotion code in a single call.
	 *
	 * For percentage discounts, pass 'percent_off'. For fixed amounts,
	 * pass 'amount_off' and 'currency'. The amount_off value is in
	 * major currency units (e.g., 10.00 for $10) and is automatically
	 * converted to Stripe's smallest unit.
	 *
	 * @param string $code             Customer-facing code (e.g., 'SUMMER25').
	 * @param array  $args             {
	 *                                 Discount arguments. Pass either percent_off OR amount_off + currency.
	 *
	 * @type float   $percent_off      Percentage discount (1-100).
	 * @type float   $amount_off       Fixed discount in major units (e.g., 10.00).
	 * @type string  $currency         Currency for fixed discounts. Default 'USD'.
	 * @type string  $duration         'once', 'repeating', or 'forever'. Default 'once'.
	 * @type int     $duration_months  Required when duration is 'repeating'.
	 * @type string  $name             Display name for the coupon.
	 * @type array   $metadata         Key/value metadata pairs.
	 * @type int     $max_redemptions  Maximum code redemptions. Null for unlimited.
	 * @type int     $expires_at       Unix timestamp for code expiration.
	 * @type bool    $first_time_only  Restrict to first-time customers. Default false.
	 * @type float   $minimum_amount   Minimum order amount in major units.
	 * @type string  $minimum_currency Currency for minimum amount. Default 'USD'.
	 *                                 }
	 *
	 * @return array{coupon: Coupon, promotion_code: PromotionCode}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function create( string $code, array $args = [] ): array|WP_Error {
		$args = wp_parse_args( $args, [
			'percent_off'      => 0,
			'amount_off'       => 0,
			'currency'         => 'USD',
			'duration'         => 'once',
			'duration_months'  => 0,
			'name'             => '',
			'metadata'         => [],
			'max_redemptions'  => null,
			'expires_at'       => null,
			'first_time_only'  => false,
			'minimum_amount'   => null,
			'minimum_currency' => 'USD',
		] );

		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( trim( $code ) ) ) {
			return new WP_Error( 'missing_code', __( 'Promotion code is required.', 'arraypress' ) );
		}

		// Determine discount type
		$percent = (float) $args['percent_off'];
		$amount  = (float) $args['amount_off'];

		if ( $percent <= 0 && $amount <= 0 ) {
			return new WP_Error( 'no_discount', __( 'Either percent_off or amount_off is required.', 'arraypress' ) );
		}

		if ( $percent > 0 && $amount > 0 ) {
			return new WP_Error( 'ambiguous_discount', __( 'Provide either percent_off or amount_off, not both.', 'arraypress' ) );
		}

		// Validate percent
		if ( $percent > 0 && ( $percent < 0.01 || $percent > 100 ) ) {
			return new WP_Error( 'invalid_percent', __( 'Percentage must be between 0.01 and 100.', 'arraypress' ) );
		}

		// Validate duration
		$valid_durations = [ 'once', 'repeating', 'forever' ];
		if ( ! in_array( $args['duration'], $valid_durations, true ) ) {
			return new WP_Error(
				'invalid_duration',
				sprintf( __( 'Duration must be one of: %s', 'arraypress' ), implode( ', ', $valid_durations ) )
			);
		}

		if ( $args['duration'] === 'repeating' && (int) $args['duration_months'] <= 0 ) {
			return new WP_Error( 'missing_duration_months', __( 'Duration in months is required for repeating discounts.', 'arraypress' ) );
		}

		// Build coupon params
		$coupon_params = [
			'duration' => $args['duration'],
		];

		if ( $percent > 0 ) {
			$coupon_params['percent_off'] = $percent;
		} else {
			$currency                    = strtolower( trim( $args['currency'] ) );
			$coupon_params['amount_off'] = Currency::to_smallest_unit( $amount, $currency );
			$coupon_params['currency']   = $currency;
		}

		if ( $args['duration'] === 'repeating' ) {
			$coupon_params['duration_in_months'] = (int) $args['duration_months'];
		}

		if ( ! empty( $args['name'] ) ) {
			$coupon_params['name'] = $args['name'];
		}

		if ( ! empty( $args['metadata'] ) ) {
			$coupon_params['metadata'] = $args['metadata'];
		}

		// Create the coupon
		try {
			$coupon = $stripe->coupons->create( $coupon_params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}

		// Build promotion code params
		$promo_params = [
			'coupon' => $coupon->id,
			'code'   => strtoupper( trim( $code ) ),
			'active' => true,
		];

		if ( $args['max_redemptions'] !== null ) {
			$promo_params['max_redemptions'] = (int) $args['max_redemptions'];
		}

		if ( $args['expires_at'] !== null ) {
			$promo_params['expires_at'] = (int) $args['expires_at'];
		}

		if ( $args['first_time_only'] ) {
			$promo_params['restrictions']['first_time_transaction'] = true;
		}

		if ( $args['minimum_amount'] !== null ) {
			$min_currency                                            = strtolower( trim( $args['minimum_currency'] ) );
			$promo_params['restrictions']['minimum_amount']          = Currency::to_smallest_unit( (float) $args['minimum_amount'], $min_currency );
			$promo_params['restrictions']['minimum_amount_currency'] = $min_currency;
		}

		// Create the promotion code
		try {
			$promotion_code = $stripe->promotionCodes->create( $promo_params );
		} catch ( Exception $e ) {
			// Clean up the coupon if promo code creation fails
			try {
				$stripe->coupons->delete( $coupon->id );
			} catch ( Exception ) {
				// Ignore cleanup errors
			}

			return new WP_Error( 'stripe_error', $e->getMessage() );
		}

		return [
			'coupon'         => $coupon,
			'promotion_code' => $promotion_code,
		];
	}

	/** =========================================================================
	 *  Retrieval
	 *  ======================================================================== */

	/**
	 * Retrieve a coupon from Stripe.
	 *
	 * @param string $coupon_id Stripe coupon ID.
	 *
	 * @return Coupon|WP_Error The coupon or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $coupon_id ): Coupon|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->coupons->retrieve( $coupon_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List coupons from Stripe.
	 *
	 * @param array $params Stripe list parameters.
	 *
	 * @return array{items: Coupon[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = wp_parse_args( $params, [ 'limit' => 100 ] );

		try {
			$result    = $stripe->coupons->all( $params );
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
	 *  Management
	 *  ======================================================================== */

	/**
	 * Delete a coupon from Stripe.
	 *
	 * Deleting a coupon does not affect existing customers or
	 * subscriptions that already have the discount applied.
	 *
	 * @param string $coupon_id Stripe coupon ID.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function delete( string $coupon_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->coupons->delete( $coupon_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Deactivate a promotion code.
	 *
	 * The underlying coupon remains active. Only the specific
	 * promotion code is deactivated.
	 *
	 * @param string $promo_code_id Stripe promotion code ID.
	 *
	 * @return PromotionCode|WP_Error The updated code or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function deactivate_code( string $promo_code_id ): PromotionCode|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->promotionCodes->update( $promo_code_id, [ 'active' => false ] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}
