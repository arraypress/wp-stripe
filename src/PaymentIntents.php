<?php
/**
 * Stripe Payment Intents Helper
 *
 * Provides convenience methods for retrieving and managing Stripe
 * payment intents. Primarily used for post-checkout processing
 * in webhook handlers to extract payment details like card brand
 * and last four digits.
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
use Stripe\PaymentIntent;
use WP_Error;

/**
 * Class PaymentIntents
 *
 * Manages Stripe payment intent operations.
 *
 * Usage:
 *   $intents = new PaymentIntents( $client );
 *
 *   $intent = $intents->get_expanded( 'pi_xxx' );
 *   $details = $intents->get_payment_details( 'pi_xxx' );
 *   // [ 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, ... ]
 *
 * @since 1.0.0
 */
class PaymentIntents {

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
	 * Retrieve a payment intent from Stripe.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 * @param array  $params            Optional parameters (e.g., expand).
	 *
	 * @return PaymentIntent|WP_Error The payment intent or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $payment_intent_id, array $params = [] ): PaymentIntent|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->paymentIntents->retrieve( $payment_intent_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a payment intent with expanded charge and payment method.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 *
	 * @return PaymentIntent|WP_Error The expanded payment intent or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_expanded( string $payment_intent_id ): PaymentIntent|WP_Error {
		return $this->get( $payment_intent_id, [
			'expand' => [
				'latest_charge',
				'payment_method',
				'customer',
			],
		] );
	}

	/** =========================================================================
	 *  Payment Details
	 *  ======================================================================== */

	/**
	 * Get payment card details from a payment intent.
	 *
	 * Extracts the card brand, last four digits, expiration, and
	 * country from the payment method used. Useful for storing
	 * in order records (payment_brand, payment_last4).
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 *
	 * @return array{brand: string, last4: string, exp_month: int, exp_year: int, country: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function get_payment_details( string $payment_intent_id ): array|WP_Error {
		$intent = $this->get( $payment_intent_id, [
			'expand' => [ 'payment_method' ],
		] );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$default = [
			'brand'     => '',
			'last4'     => '',
			'exp_month' => 0,
			'exp_year'  => 0,
			'country'   => '',
			'type'      => '',
		];

		$pm = $intent->payment_method ?? null;

		if ( ! $pm ) {
			return $default;
		}

		$default['type'] = $pm->type ?? '';

		// Card payments
		if ( isset( $pm->card ) ) {
			return [
				'brand'     => $pm->card->brand ?? '',
				'last4'     => $pm->card->last4 ?? '',
				'exp_month' => $pm->card->exp_month ?? 0,
				'exp_year'  => $pm->card->exp_year ?? 0,
				'country'   => $pm->card->country ?? '',
				'type'      => 'card',
			];
		}

		return $default;
	}

	/**
	 * Get the customer's country from a payment intent.
	 *
	 * Checks the charge's billing details first, then falls back
	 * to the payment method's card country.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 *
	 * @return string|WP_Error Two-letter country code or empty string.
	 * @since 1.0.0
	 *
	 */
	public function get_country( string $payment_intent_id ): string|WP_Error {
		$intent = $this->get( $payment_intent_id, [
			'expand' => [ 'latest_charge', 'payment_method' ],
		] );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		// Check billing address on charge
		$charge_country = $intent->latest_charge->billing_details->address->country ?? '';
		if ( $charge_country ) {
			return $charge_country;
		}

		// Fall back to card country
		return $intent->payment_method->card->country ?? '';
	}

	/** =========================================================================
	 *  Management
	 *  ======================================================================== */

	/**
	 * Update metadata on a payment intent.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 * @param array  $metadata          Key/value pairs. Null values remove the key.
	 *
	 * @return PaymentIntent|WP_Error The updated payment intent or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function update_metadata( string $payment_intent_id, array $metadata ): PaymentIntent|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->paymentIntents->update( $payment_intent_id, [
				'metadata' => $metadata,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Cancel a payment intent.
	 *
	 * Only works on intents that haven't been captured yet.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 * @param string $reason            Cancellation reason: 'duplicate', 'fraudulent',
	 *                                  'requested_by_customer', or 'abandoned'.
	 *
	 * @return PaymentIntent|WP_Error The canceled intent or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function cancel( string $payment_intent_id, string $reason = 'requested_by_customer' ): PaymentIntent|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		$valid_reasons = [ 'duplicate', 'fraudulent', 'requested_by_customer', 'abandoned' ];
		if ( in_array( $reason, $valid_reasons, true ) ) {
			$params['cancellation_reason'] = $reason;
		}

		try {
			return $stripe->paymentIntents->cancel( $payment_intent_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Capture a payment intent.
	 *
	 * For intents created with capture_method='manual', this captures
	 * the authorized funds. Must be called within 7 days of authorization.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID.
	 * @param array  $args              {
	 *                                  Optional capture arguments.
	 *
	 * @type int     $amount_to_capture Amount to capture in smallest currency unit.
	 *                                  Defaults to full authorized amount.
	 *                                  }
	 *
	 * @return PaymentIntent|WP_Error The captured intent or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function capture( string $payment_intent_id, array $args = [] ): PaymentIntent|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		if ( ! empty( $args['amount_to_capture'] ) ) {
			$params['amount_to_capture'] = absint( $args['amount_to_capture'] );
		}

		try {
			return $stripe->paymentIntents->capture( $payment_intent_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}