<?php
/**
 * Stripe Refunds Helper
 *
 * Provides convenience methods for creating and managing refunds.
 * Handles the common confusion between payment intents and charges
 * by accepting either as input.
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
use Stripe\Refund;
use WP_Error;

/**
 * Class Refunds
 *
 * Manages Stripe refund creation and retrieval.
 *
 * Usage:
 *   $refunds = new Refunds( $client );
 *
 *   // Full refund
 *   $refunds->create( 'pi_xxx' );
 *
 *   // Partial refund ($5.00)
 *   $refunds->create( 'pi_xxx', [
 *       'amount'   => 5.00,
 *       'currency' => 'USD',
 *   ] );
 *
 * @since 1.0.0
 */
class Refunds {

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
	 * Create a refund.
	 *
	 * Accepts either a payment intent ID (pi_xxx) or charge ID (ch_xxx).
	 * Omit 'amount' for a full refund, or pass it in major currency
	 * units (e.g., 5.00 for $5.00) for a partial refund.
	 *
	 * @param string $payment_id Payment intent ID or charge ID.
	 * @param array  $args       {
	 *                           Optional refund arguments.
	 *
	 * @type float   $amount     Refund amount in major units. Omit for full refund.
	 * @type string  $currency   ISO 4217 currency code. Default 'USD'. Only needed for partial.
	 * @type string  $reason     'duplicate', 'fraudulent', or 'requested_by_customer'.
	 * @type array   $metadata   Key/value metadata pairs.
	 *                           }
	 *
	 * @return Refund|WP_Error The refund or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( string $payment_id, array $args = [] ): Refund|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		// Detect payment intent vs charge
		if ( str_starts_with( $payment_id, 'pi_' ) ) {
			$params['payment_intent'] = $payment_id;
		} elseif ( str_starts_with( $payment_id, 'ch_' ) ) {
			$params['charge'] = $payment_id;
		} else {
			return new WP_Error(
				'invalid_payment_id',
				__( 'Payment ID must be a payment intent (pi_xxx) or charge (ch_xxx).', 'arraypress' )
			);
		}

		// Amount (omit for full refund)
		$amount = (float) ( $args['amount'] ?? 0 );
		if ( $amount > 0 ) {
			$currency         = strtolower( trim( $args['currency'] ?? 'USD' ) );
			$params['amount'] = Currency::to_smallest_unit( $amount, $currency );
		}

		// Reason
		$valid_reasons = [ 'duplicate', 'fraudulent', 'requested_by_customer' ];
		if ( ! empty( $args['reason'] ) && in_array( $args['reason'], $valid_reasons, true ) ) {
			$params['reason'] = $args['reason'];
		}

		// Metadata
		if ( ! empty( $args['metadata'] ) ) {
			$params['metadata'] = $args['metadata'];
		}

		try {
			return $stripe->refunds->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Webhook Data Extraction
	 *  ======================================================================== */

	/**
	 * Get all data needed to process a charge.refunded event.
	 *
	 * Extracts the payment intent ID, refunded amount, total refunded,
	 * refund reason, and whether it's a full or partial refund.
	 *
	 * Designed for use inside a charge.refunded webhook handler.
	 *
	 * @param \Stripe\Event $event             The charge.refunded Stripe event.
	 *
	 * @return array {
	 *     Refund data for order processing.
	 *
	 * @type string         $charge_id         Stripe charge ID.
	 * @type string         $payment_intent_id Stripe payment intent ID.
	 * @type int            $amount_refunded   Total amount refunded in smallest unit.
	 * @type int            $amount_captured   Original captured amount.
	 * @type string         $currency          ISO 4217 currency code (lowercase).
	 * @type bool           $fully_refunded    Whether the charge is fully refunded.
	 * @type string         $reason            Refund reason from the latest refund.
	 * @type string         $refund_id         ID of the latest refund.
	 * @type int            $latest_amount     Amount of the latest refund.
	 * @type string         $status            Charge status.
	 *                                         }
	 * @since 1.0.0
	 *
	 */
	public function get_refund_data( \Stripe\Event $event ): array {
		$charge = $event->data->object;

		// Get the latest refund details
		$refunds       = $charge->refunds->data ?? [];
		$latest_refund = ! empty( $refunds ) ? $refunds[0] : null;

		return [
			'charge_id'         => $charge->id ?? '',
			'payment_intent_id' => $charge->payment_intent ?? '',
			'amount_refunded'   => $charge->amount_refunded ?? 0,
			'amount_captured'   => $charge->amount_captured ?? $charge->amount ?? 0,
			'currency'          => strtolower( $charge->currency ?? 'usd' ),
			'fully_refunded'    => (bool) ( $charge->refunded ?? false ),
			'reason'            => $latest_refund->reason ?? '',
			'refund_id'         => $latest_refund->id ?? '',
			'latest_amount'     => $latest_refund->amount ?? 0,
			'status'            => $charge->status ?? '',
		];
	}

	/** =========================================================================
	 *  Retrieval
	 *  ======================================================================== */

	/**
	 * Retrieve a refund.
	 *
	 * @param string $refund_id Stripe refund ID.
	 *
	 * @return Refund|WP_Error The refund or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $refund_id ): Refund|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->refunds->retrieve( $refund_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List refunds for a payment.
	 *
	 * @param string $payment_id Payment intent ID or charge ID.
	 * @param int    $limit      Maximum results. Default 100.
	 *
	 * @return Refund[]|WP_Error Array of refunds or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function list_by_payment( string $payment_id, int $limit = 100 ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [ 'limit' => min( $limit, 100 ) ];

		if ( str_starts_with( $payment_id, 'pi_' ) ) {
			$params['payment_intent'] = $payment_id;
		} elseif ( str_starts_with( $payment_id, 'ch_' ) ) {
			$params['charge'] = $payment_id;
		}

		try {
			$result = $stripe->refunds->all( $params );

			return $result->data;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List refunds for a payment, returning plain stdClass objects.
	 *
	 * Identical to list_by_payment() but strips Stripe SDK internals from
	 * each item via JSON round-trip. Use when results will be passed to a
	 * REST endpoint, stored in a transient, or handed to any system
	 * expecting plain serializable objects (e.g., wp-inline-sync batch
	 * callbacks).
	 *
	 * Accepts either a payment intent ID (pi_xxx) or charge ID (ch_xxx).
	 *
	 * @param string $payment_id Payment intent ID or charge ID.
	 * @param int    $limit      Maximum results. Default 100.
	 *
	 * @return \stdClass[]|WP_Error Array of plain refund objects or WP_Error on failure.
	 *
	 * @since 1.0.0
	 *
	 * @see   list_by_payment()
	 */
	public function list_by_payment_serialized( string $payment_id, int $limit = 100 ): array|WP_Error {
		$result = $this->list_by_payment( $payment_id, $limit );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_map(
			fn( $item ) => json_decode( json_encode( $item ) ),
			$result
		);
	}

}
