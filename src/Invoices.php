<?php
/**
 * Stripe Invoices Helper
 *
 * Provides convenience methods for retrieving and managing Stripe
 * invoices. Essential for subscription renewal tracking and
 * billing history.
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
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
use WP_Error;

/**
 * Class Invoices
 *
 * Manages Stripe invoice operations.
 *
 * Usage:
 *   $invoices = new Invoices( $client );
 *
 *   $invoice = $invoices->get_expanded( 'in_xxx' );
 *   $history = $invoices->list_by_customer( 'cus_xxx' );
 *
 * @since 1.0.0
 */
class Invoices {

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
	 * Retrieve an invoice from Stripe.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 * @param array  $params     Optional parameters (e.g., expand).
	 *
	 * @return Invoice|WP_Error The invoice or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $invoice_id, array $params = [] ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->retrieve( $invoice_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve an invoice with expanded data.
	 *
	 * Expands payment intent (with payment method and charge),
	 * subscription, and customer data for complete invoice processing.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 *
	 * @return Invoice|WP_Error The expanded invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_expanded( string $invoice_id ): Invoice|WP_Error {
		return $this->get( $invoice_id, [
			'expand' => [
				'payment_intent.payment_method',
				'payment_intent.latest_charge',
				'subscription',
				'customer',
			],
		] );
	}

	/**
	 * List invoices for a customer.
	 *
	 * @param string $customer_id    Stripe customer ID.
	 * @param array  $params         {
	 *                               Optional. Stripe list parameters.
	 *
	 * @type string  $status         Filter by status: 'draft', 'open', 'paid', 'void', 'uncollectible'.
	 * @type string  $subscription   Filter by subscription ID.
	 * @type int     $limit          Number of results (1-100). Default 100.
	 * @type string  $starting_after Cursor for pagination.
	 *                               }
	 *
	 * @return array{items: Invoice[], has_more: bool, cursor: string}|WP_Error
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
			$result    = $stripe->invoices->all( $params );
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
	 * List invoices for a subscription.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param int    $limit           Maximum results. Default 100.
	 *
	 * @return Invoice[]|WP_Error Array of invoices or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function list_by_subscription( string $subscription_id, int $limit = 100 ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$result = $stripe->invoices->all( [
				'subscription' => $subscription_id,
				'limit'        => (int) min( $limit, 100 ),
			] );

			return $result->data;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get the upcoming invoice preview for a customer or subscription.
	 *
	 * Returns a preview of the next invoice that will be generated.
	 * Useful for showing customers what their next charge will be.
	 *
	 * Uses the Create Preview API which replaced the deprecated
	 * Upcoming Invoice API in stripe-php v17+ (API 2025-03-31.basil).
	 *
	 * @param string $customer_id     Stripe customer ID.
	 * @param string $subscription_id Optional. Specific subscription ID.
	 *
	 * @return Invoice|null|WP_Error The preview invoice, null if none, or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_upcoming( string $customer_id, string $subscription_id = '' ): Invoice|null|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [ 'customer' => $customer_id ];

		if ( $subscription_id ) {
			$params['subscription'] = $subscription_id;
		}

		try {
			return $stripe->invoices->createPreview( $params );
		} catch ( InvalidRequestException $e ) {
			// "No upcoming invoices" is not an error condition
			if ( str_contains( $e->getMessage(), 'No upcoming invoices' ) ) {
				return null;
			}

			return new WP_Error( 'stripe_error', $e->getMessage() );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Webhook Data Extraction
	 *  ======================================================================== */

	/**
	 * Get all data needed to process a paid invoice (subscription renewal).
	 *
	 * Performs multiple API calls to assemble the complete dataset
	 * needed for creating a renewal order: invoice details, line items,
	 * payment card details, and customer info.
	 *
	 * Designed for use inside an invoice.paid webhook handler. Returns
	 * null for initial subscription invoices (billing_reason = subscription_create)
	 * since those are handled by checkout.session.completed.
	 *
	 * @param string $invoice_id        Stripe invoice ID.
	 * @param bool   $skip_initial      Skip initial subscription invoices. Default true.
	 *
	 * @return array|null|WP_Error {
	 *     Renewal data, null if skipped, or WP_Error on failure.
	 *
	 * @type string  $invoice_id        Stripe invoice ID.
	 * @type string  $payment_intent_id Stripe payment intent ID.
	 * @type string  $subscription_id   Stripe subscription ID.
	 * @type string  $customer_id       Stripe customer ID.
	 * @type string  $customer_email    Customer email.
	 * @type string  $customer_name     Customer name.
	 * @type int     $total             Total in smallest currency unit.
	 * @type int     $subtotal          Subtotal before discounts/tax.
	 * @type int     $tax               Tax amount.
	 * @type string  $currency          ISO 4217 currency code (lowercase).
	 * @type string  $country           Two-letter country code.
	 * @type string  $payment_brand     Card brand.
	 * @type string  $payment_last4     Last four digits.
	 * @type string  $payment_type      Payment method type.
	 * @type string  $billing_reason    Why this invoice was created.
	 * @type string  $status            Invoice status.
	 * @type int     $period_start      Billing period start (Unix timestamp).
	 * @type int     $period_end        Billing period end (Unix timestamp).
	 * @type bool    $is_test           Whether test mode.
	 * @type array   $line_items        Array of line item data.
	 *                                  }
	 * @since 1.0.0
	 *
	 */
	public function get_renewal_data( string $invoice_id, bool $skip_initial = true ): array|null|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		// 1. Get expanded invoice
		$invoice = $this->get( $invoice_id, [
			'expand' => [
				'payment_intent.payment_method',
				'payment_intent.latest_charge',
				'subscription',
				'customer',
			],
		] );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		// Skip initial subscription invoices
		if ( $skip_initial && ( $invoice->billing_reason ?? '' ) === 'subscription_create' ) {
			return null;
		}

		// 2. Extract payment details
		$payment_brand = '';
		$payment_last4 = '';
		$payment_type  = '';
		$country       = '';

		$pi = $invoice->payment_intent ?? null;

		if ( is_object( $pi ) ) {
			$pm = $pi->payment_method ?? null;

			if ( $pm && isset( $pm->card ) ) {
				$payment_brand = $pm->card->brand ?? '';
				$payment_last4 = $pm->card->last4 ?? '';
				$payment_type  = 'card';
				$country       = $pm->card->country ?? '';
			} elseif ( $pm ) {
				$payment_type = $pm->type ?? '';
			}
		}

		// Fallback: billing address on latest charge (via payment intent)
		if ( empty( $country ) && is_object( $pi ) ) {
			$charge  = $pi->latest_charge ?? null;
			$country = is_object( $charge )
				? ( $charge->billing_details->address->country ?? '' )
				: '';
		}

		// Fallback: customer address
		if ( empty( $country ) ) {
			$customer = $invoice->customer ?? null;
			$country  = is_object( $customer )
				? ( $customer->address->country ?? '' )
				: '';
		}

		// 3. Extract customer info
		$customer    = $invoice->customer ?? null;
		$customer_id = is_object( $customer ) ? $customer->id : ( $invoice->customer ?? '' );

		// 4. Extract line items
		$line_items = [];

		$raw_lines = $invoice->lines->data ?? [];
		foreach ( $raw_lines as $line ) {
			$price = $line->price ?? null;

			$line_items[] = [
				'stripe_price_id'   => $price->id ?? '',
				'stripe_product_id' => $price->product ?? '',
				'product_name'      => $line->description ?? '',
				'quantity'          => $line->quantity ?? 1,
				'total'             => $line->amount ?? 0,
				'unit_amount'       => $price->unit_amount ?? 0,
				'currency'          => strtolower( $line->currency ?? '' ),
				'interval'          => $price->recurring->interval ?? null,
				'interval_count'    => $price->recurring->interval_count ?? null,
				'period_start'      => $line->period->start ?? 0,
				'period_end'        => $line->period->end ?? 0,
			];
		}

		// 5. Subscription details
		$sub          = $invoice->subscription ?? null;
		$period_start = 0;
		$period_end   = 0;

		if ( is_object( $sub ) ) {
			$period_start = $sub->current_period_start ?? 0;
			$period_end   = $sub->current_period_end ?? 0;
		}

		// 6. Assemble
		return [
			'invoice_id'        => $invoice->id,
			'payment_intent_id' => is_object( $pi ) ? $pi->id : ( $invoice->payment_intent ?? '' ),
			'subscription_id'   => is_object( $sub ) ? $sub->id : ( $invoice->subscription ?? '' ),
			'customer_id'       => $customer_id,
			'customer_email'    => $invoice->customer_email ?? '',
			'customer_name'     => $invoice->customer_name ?? '',
			'total'             => $invoice->amount_paid ?? $invoice->total ?? 0,
			'subtotal'          => $invoice->subtotal ?? 0,
			'tax'               => $this->calculate_tax( $invoice ),
			'currency'          => strtolower( $invoice->currency ?? 'usd' ),
			'country'           => $country,
			'payment_brand'     => $payment_brand,
			'payment_last4'     => $payment_last4,
			'payment_type'      => $payment_type,
			'billing_reason'    => $invoice->billing_reason ?? '',
			'status'            => $invoice->status ?? '',
			'period_start'      => $period_start,
			'period_end'        => $period_end,
			'is_test'           => $this->client->is_test_mode(),
			'line_items'        => $line_items,
		];
	}

	/** =========================================================================
	 *  Management
	 *  ======================================================================== */

	/**
	 * Finalize a draft invoice.
	 *
	 * Once finalized, the invoice is ready for payment. It can no
	 * longer be edited.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 *
	 * @return Invoice|WP_Error The finalized invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function finalize( string $invoice_id ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->finalizeInvoice( $invoice_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Send an invoice to the customer.
	 *
	 * Sends the invoice email to the customer. The invoice must
	 * be finalized first.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 *
	 * @return Invoice|WP_Error The sent invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function send( string $invoice_id ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->sendInvoice( $invoice_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Pay an open invoice.
	 *
	 * Attempts to collect payment using the customer's default
	 * payment method or a specified one.
	 *
	 * @param string $invoice_id     Stripe invoice ID.
	 * @param string $payment_method Optional. Payment method ID to use.
	 *
	 * @return Invoice|WP_Error The paid invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function pay( string $invoice_id, string $payment_method = '' ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		if ( $payment_method ) {
			$params['payment_method'] = $payment_method;
		}

		try {
			return $stripe->invoices->pay( $invoice_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Void an invoice.
	 *
	 * Marks the invoice as void. Voided invoices are no longer
	 * payable and are removed from the customer's billing history.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 *
	 * @return Invoice|WP_Error The voided invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function void( string $invoice_id ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->voidInvoice( $invoice_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Mark an invoice as uncollectible.
	 *
	 * Used when you've exhausted collection attempts. The invoice
	 * remains on record but is no longer expected to be paid.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 *
	 * @return Invoice|WP_Error The updated invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function mark_uncollectible( string $invoice_id ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->markUncollectible( $invoice_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Memo / Notes
	 *  ======================================================================== */

	/**
	 * Update an invoice's memo (customer-visible note).
	 *
	 * The memo appears on the invoice PDF and in the customer portal.
	 * Only works on draft invoices.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 * @param string $memo       Memo text.
	 *
	 * @return Invoice|WP_Error The updated invoice or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function set_memo( string $invoice_id, string $memo ): Invoice|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->invoices->update( $invoice_id, [
				'description' => $memo,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Internal Helpers
	 *  ======================================================================== */

	/**
	 * Calculate total tax from an invoice.
	 *
	 * In API 2025-03-31.basil (stripe-php v17+), Invoice.tax was removed.
	 * Tax is now available via Invoice.total_taxes array, or can be
	 * derived from total - subtotal.
	 *
	 * @param Invoice $invoice Stripe invoice object.
	 *
	 * @return int Tax amount in smallest currency unit.
	 * @since 1.0.0
	 *
	 */
	private function calculate_tax( Invoice $invoice ): int {
		// Try total_taxes array (v17+)
		$total_taxes = $invoice->total_taxes ?? [];
		if ( ! empty( $total_taxes ) ) {
			$tax = 0;
			foreach ( $total_taxes as $tax_entry ) {
				$tax += $tax_entry->amount ?? 0;
			}

			return $tax;
		}

		// Fallback: derive from total and subtotal
		$total    = $invoice->total ?? 0;
		$subtotal = $invoice->subtotal ?? 0;

		return max( 0, $total - $subtotal );
	}

}
