<?php
/**
 * Balance Transactions Class
 *
 * Query your Stripe balance and transaction history for reconciliation
 * and reporting. Balance transactions are created automatically by
 * Stripe for every movement of funds — charges, refunds, transfers,
 * payouts, adjustments, and fees.
 *
 * @package     ArrayPress\Stripe
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\Stripe;

use Exception;
use Stripe\BalanceTransaction;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class BalanceTransactions
 *
 * Provides methods for querying Stripe balance and transaction history.
 *
 * @since 1.0.0
 */
class BalanceTransactions {

	/**
	 * Stripe client instance.
	 *
	 * @since 1.0.0
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client Configured Stripe client instance.
	 *
	 * @since 1.0.0
	 *
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/** =========================================================================
	 *  Balance
	 *  ======================================================================== */

	/**
	 * Retrieve the current account balance.
	 *
	 * Returns available and pending amounts for each currency
	 * on the account.
	 *
	 * @return \Stripe\Balance|WP_Error The balance object or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get_balance(): \Stripe\Balance|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->balance->retrieve();
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get the available balance for a specific currency.
	 *
	 * Returns the amount in the smallest currency unit (e.g. cents).
	 * Returns 0 if the currency is not found in the balance.
	 *
	 * @param string $currency Three-letter ISO currency code (e.g. 'usd').
	 *
	 * @return int|WP_Error Available amount in smallest unit, or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_available( string $currency = 'usd' ): int|WP_Error {
		$balance = $this->get_balance();

		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		$currency = strtolower( $currency );

		foreach ( $balance->available as $entry ) {
			if ( $entry->currency === $currency ) {
				return $entry->amount;
			}
		}

		return 0;
	}

	/**
	 * Get the pending balance for a specific currency.
	 *
	 * Returns the amount in the smallest currency unit.
	 * Returns 0 if the currency is not found.
	 *
	 * @param string $currency Three-letter ISO currency code (e.g. 'usd').
	 *
	 * @return int|WP_Error Pending amount in smallest unit, or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_pending( string $currency = 'usd' ): int|WP_Error {
		$balance = $this->get_balance();

		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		$currency = strtolower( $currency );

		foreach ( $balance->pending as $entry ) {
			if ( $entry->currency === $currency ) {
				return $entry->amount;
			}
		}

		return 0;
	}

	/** =========================================================================
	 *  Transactions
	 *  ======================================================================== */

	/**
	 * Retrieve a single balance transaction.
	 *
	 * @param string $transaction_id Balance transaction ID (txn_xxx).
	 * @param array  $params         Optional parameters (e.g. expand).
	 *
	 * @return BalanceTransaction|WP_Error The transaction or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get( string $transaction_id, array $params = [] ): BalanceTransaction|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->balanceTransactions->retrieve( $transaction_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List balance transactions with optional filters.
	 *
	 * @param array $params             {
	 *                                  Optional. Filter and pagination parameters.
	 *
	 * @type string $type               Filter by type: 'charge', 'refund', 'transfer',
	 *                                  'payout', 'adjustment', 'stripe_fee', etc.
	 * @type string $currency           Filter by currency (e.g. 'usd').
	 * @type string $source             Filter by source ID (e.g. charge or refund ID).
	 * @type array  $created            Filter by creation date (e.g. ['gte' => timestamp]).
	 * @type int    $limit              Number of results per page (default 25, max 100).
	 * @type string $starting_after     Cursor for pagination.
	 * @type array  $expand             Fields to expand.
	 *                                  }
	 *
	 * @return array{items: BalanceTransaction[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$args = [];

			foreach ( [ 'type', 'currency', 'source', 'created', 'expand' ] as $key ) {
				if ( isset( $params[ $key ] ) ) {
					$args[ $key ] = $params[ $key ];
				}
			}

			$args['limit'] = min( $params['limit'] ?? 25, 100 );

			if ( ! empty( $params['starting_after'] ) ) {
				$args['starting_after'] = $params['starting_after'];
			}

			$result = $stripe->balanceTransactions->all( $args );

			$items  = $result->data;
			$cursor = ! empty( $items ) ? end( $items )->id : '';

			return [
				'items'    => $items,
				'has_more' => $result->has_more,
				'cursor'   => $cursor,
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List balance transactions by type.
	 *
	 * Convenience wrapper for list() filtered by transaction type.
	 *
	 * @param string $type   Transaction type: 'charge', 'refund', 'transfer',
	 *                       'payout', 'adjustment', 'stripe_fee', etc.
	 * @param array  $params Optional additional filter parameters.
	 *
	 * @return array{items: BalanceTransaction[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list_by_type( string $type, array $params = [] ): array|WP_Error {
		return $this->list( array_merge( $params, [ 'type' => $type ] ) );
	}

	/**
	 * List balance transactions for a specific source.
	 *
	 * Returns all transactions related to a charge, refund, transfer, etc.
	 *
	 * @param string $source_id Source ID (e.g. ch_xxx, re_xxx, tr_xxx).
	 * @param array  $params    Optional additional filter parameters.
	 *
	 * @return array{items: BalanceTransaction[], has_more: bool, cursor: string}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list_by_source( string $source_id, array $params = [] ): array|WP_Error {
		return $this->list( array_merge( $params, [ 'source' => $source_id ] ) );
	}

	/**
	 * Get a transaction summary for a date range.
	 *
	 * Fetches all transactions in the period and summarizes
	 * totals by type. Useful for reconciliation reports.
	 *
	 * @param int    $from      Unix timestamp for the start of the range.
	 * @param int    $to        Unix timestamp for the end of the range.
	 * @param string $currency  Three-letter ISO currency code (default 'usd').
	 *
	 * @return array|WP_Error {
	 *     Summary of transactions in the period.
	 *
	 * @type int     $gross     Total gross amount (charges) in smallest unit.
	 * @type int     $refunds   Total refunded in smallest unit.
	 * @type int     $fees      Total Stripe fees in smallest unit.
	 * @type int     $net       Total net amount in smallest unit.
	 * @type int     $transfers Total transferred to connected accounts.
	 * @type int     $count     Number of transactions.
	 * @type array   $by_type   Breakdown of net amounts by transaction type.
	 *                          }
	 * @since 1.0.0
	 *
	 */
	public function get_summary( int $from, int $to, string $currency = 'usd' ): array|WP_Error {
		$summary = [
			'gross'     => 0,
			'refunds'   => 0,
			'fees'      => 0,
			'net'       => 0,
			'transfers' => 0,
			'count'     => 0,
			'by_type'   => [],
		];

		$cursor = '';

		do {
			$params = [
				'created'  => [ 'gte' => $from, 'lte' => $to ],
				'currency' => strtolower( $currency ),
				'limit'    => 100,
			];

			if ( $cursor ) {
				$params['starting_after'] = $cursor;
			}

			$result = $this->list( $params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result['items'] as $txn ) {
				$summary['count'] ++;
				$summary['net']  += $txn->net;
				$summary['fees'] += $txn->fee;

				$type = $txn->type;

				if ( ! isset( $summary['by_type'][ $type ] ) ) {
					$summary['by_type'][ $type ] = 0;
				}

				$summary['by_type'][ $type ] += $txn->net;

				switch ( $type ) {
					case 'charge':
					case 'payment':
						$summary['gross'] += $txn->amount;
						break;
					case 'refund':
						$summary['refunds'] += abs( $txn->amount );
						break;
					case 'transfer':
						$summary['transfers'] += abs( $txn->amount );
						break;
				}
			}

			$cursor = $result['cursor'];
		} while ( $result['has_more'] );

		return $summary;
	}

}