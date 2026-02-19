<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\Dispute;
use WP_Error;

/**
 * Disputes
 *
 * Manages Stripe disputes (chargebacks). Provides retrieval, evidence submission,
 * and dispute closing. Evidence can be staged (saved without submitting) or
 * submitted immediately to the card network.
 *
 * Dispute statuses:
 *   warning_needs_response  — Early fraud warning, action recommended
 *   warning_under_review    — Under review by Stripe
 *   warning_closed          — Warning closed
 *   needs_response          — Requires evidence submission by due_by date
 *   under_review            — Submitted, under review by card network
 *   charge_refunded         — Merchant issued refund before dispute resolved
 *   won                     — Dispute resolved in merchant's favour
 *   lost                    — Dispute resolved in customer's favour
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class Disputes {

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
	 * Retrieve a single dispute by ID.
	 *
	 * @param string $dispute_id Stripe dispute ID (dp_xxx or du_xxx).
	 * @param array  $params     Optional: expand (array).
	 *
	 * @return Dispute|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $dispute_id, array $params = [] ): Dispute|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->disputes->retrieve( $dispute_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List disputes with optional filters.
	 *
	 * @param array $params Optional filters:
	 *                      - charge          (string) Filter by charge ID.
	 *                      - payment_intent  (string) Filter by payment intent ID.
	 *                      - created         (array)  Date range filters: gte, lte, gt, lt (Unix timestamps).
	 *                      - limit           (int)    Max results per page (default 10, max 100).
	 *
	 * @return array|WP_Error [ 'items' => Dispute[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->disputes->all( $params );

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
	 * List all disputes for a specific charge.
	 *
	 * @param string $charge_id Stripe charge ID (ch_xxx).
	 * @param int    $limit     Max results to return. Default 10.
	 *
	 * @return array|WP_Error [ 'items' => Dispute[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_charge( string $charge_id, int $limit = 10 ): array|WP_Error {
		return $this->list( [ 'charge' => $charge_id, 'limit' => $limit ] );
	}

	/**
	 * List all disputes for a specific payment intent.
	 *
	 * @param string $payment_intent_id Stripe payment intent ID (pi_xxx).
	 * @param int    $limit             Max results to return. Default 10.
	 *
	 * @return array|WP_Error [ 'items' => Dispute[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_payment_intent( string $payment_intent_id, int $limit = 10 ): array|WP_Error {
		return $this->list( [ 'payment_intent' => $payment_intent_id, 'limit' => $limit ] );
	}

	/**
	 * List disputes that need a response (action required).
	 *
	 * Convenience method for building admin notifications or dispute dashboards.
	 *
	 * @param int $limit Max results to return. Default 25.
	 *
	 * @return array|WP_Error [ 'items' => Dispute[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_needs_response( int $limit = 25 ): array|WP_Error {
		return $this->list( [ 'limit' => $limit ] );
	}

	// -------------------------------------------------------------------------
	// Evidence & Response
	// -------------------------------------------------------------------------

	/**
	 * Submit evidence for a dispute.
	 *
	 * By default, evidence is submitted immediately to the card network.
	 * Pass submit = false to stage evidence without submitting — useful for
	 * building up evidence over multiple calls before final submission.
	 *
	 * Evidence fields (all optional, provide as many as relevant):
	 *   customer_name, customer_email_address, customer_purchase_ip,
	 *   billing_address, product_description, customer_communication,
	 *   customer_signature, receipt, service_documentation,
	 *   shipping_address, shipping_carrier, shipping_date,
	 *   shipping_documentation, shipping_tracking_number,
	 *   cancellation_policy, cancellation_policy_disclosure,
	 *   cancellation_rebuttal, refund_policy, refund_policy_disclosure,
	 *   refund_refusal_explanation, service_date, duplicate_charge_id,
	 *   duplicate_charge_documentation, duplicate_charge_explanation,
	 *   access_activity_log, uncategorized_text, uncategorized_file
	 *
	 * File fields (uncategorized_file, receipt, etc.) accept a Stripe File ID
	 * from a prior file upload. Use the Files API to upload documents first.
	 *
	 * @param string $dispute_id Stripe dispute ID (dp_xxx or du_xxx).
	 * @param array  $evidence   Evidence fields to submit.
	 * @param bool   $submit     Whether to immediately submit to card network. Default true.
	 *
	 * @return Dispute|WP_Error
	 * @since 1.0.0
	 */
	public function submit_evidence( string $dispute_id, array $evidence, bool $submit = true ): Dispute|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->disputes->update( $dispute_id, [
				'evidence' => $evidence,
				'submit'   => $submit,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Stage evidence without submitting to the card network.
	 *
	 * Convenience wrapper for submit_evidence( $id, $evidence, false ).
	 * Staged evidence is visible in the API and Dashboard but not sent to
	 * the bank until you call submit_evidence() with submit = true.
	 *
	 * @param string $dispute_id Stripe dispute ID.
	 * @param array  $evidence   Evidence fields to stage.
	 *
	 * @return Dispute|WP_Error
	 * @since 1.0.0
	 */
	public function stage_evidence( string $dispute_id, array $evidence ): Dispute|WP_Error {
		return $this->submit_evidence( $dispute_id, $evidence, false );
	}

	/**
	 * Close a dispute, conceding it as lost.
	 *
	 * Use this when you have no evidence to submit and want to acknowledge
	 * the dispute as lost. This is irreversible — the status changes from
	 * needs_response to lost and cannot be reopened.
	 *
	 * @param string $dispute_id Stripe dispute ID (dp_xxx or du_xxx).
	 *
	 * @return Dispute|WP_Error
	 * @since 1.0.0
	 */
	public function close( string $dispute_id ): Dispute|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->disputes->close( $dispute_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get structured dispute summary data from a charge.disputed webhook event.
	 *
	 * @param object $event Stripe webhook event object.
	 *
	 * @return array|WP_Error {
	 *     @type string $dispute_id      Stripe dispute ID.
	 *     @type string $charge_id       Associated charge ID.
	 *     @type string $payment_intent  Associated payment intent ID (if available).
	 *     @type int    $amount          Disputed amount in smallest currency unit.
	 *     @type string $currency        Currency code.
	 *     @type string $reason          Dispute reason from cardholder.
	 *     @type string $status          Current dispute status.
	 *     @type string $due_by          Evidence due date formatted as 'Y-m-d H:i:s'.
	 *     @type int    $due_by_ts       Evidence due date as Unix timestamp.
	 *     @type bool   $is_test         Whether this is a test mode dispute.
	 * }
	 * @since 1.0.0
	 */
	public function get_event_data( object $event ): array|WP_Error {
		try {
			$dispute = $event->data->object;

			return [
				'dispute_id'     => $dispute->id,
				'charge_id'      => $dispute->charge,
				'payment_intent' => $dispute->payment_intent ?? '',
				'amount'         => $dispute->amount,
				'currency'       => strtoupper( $dispute->currency ),
				'reason'         => $dispute->reason,
				'status'         => $dispute->status,
				'due_by'         => isset( $dispute->evidence_details->due_by )
					? gmdate( 'Y-m-d H:i:s', $dispute->evidence_details->due_by )
					: '',
				'due_by_ts'      => $dispute->evidence_details->due_by ?? 0,
				'is_test'        => ! $dispute->livemode,
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'parse_error', $e->getMessage() );
		}
	}

}