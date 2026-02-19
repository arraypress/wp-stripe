<?php

namespace ArrayPress\Stripe;

use Exception;
use Stripe\Transfer;
use Stripe\TransferReversal;
use WP_Error;

/**
 * Transfers
 *
 * Manages Stripe Connect transfers for sending commission payments to connected
 * accounts (affiliates, sellers). Transfers move funds from your platform
 * balance to a connected account's Stripe balance, where Stripe then pays
 * out to their bank on your configured payout schedule.
 *
 * Typical monthly payout flow:
 *   1. Calculate commissions externally (your own logic)
 *   2. create() for each affiliate with their acct_xxx ID and amount
 *   3. Stripe automatically pays out to their bank
 *
 * Note: The connected account must be fully onboarded (is_ready() = true)
 * before you can transfer to them. Use the Accounts class to verify.
 *
 * @package ArrayPress\Stripe
 * @since   1.0.0
 */
class Transfers {

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
	 * Transfer funds to a connected account.
	 *
	 * Amount is in major currency units (e.g. 49.99 for $49.99) and is
	 * auto-converted to the smallest unit internally.
	 *
	 * @param string $account_id         Stripe connected account ID (acct_xxx).
	 * @param float  $amount             Amount to transfer in major units (e.g. 49.99).
	 * @param string $currency           Three-letter ISO currency code. Default 'USD'.
	 * @param array  $args               Optional: {
	 *
	 * @type string  $description        Internal description (not shown to recipient).
	 * @type string  $transfer_group     Arbitrary string to group related transfers.
	 * @type string  $source_transaction Charge ID to transfer funds from a specific payment.
	 * @type array   $metadata           Arbitrary key/value metadata.
	 *                                   }
	 *
	 * @return Transfer|WP_Error
	 * @since 1.0.0
	 */
	public function create( string $account_id, float $amount, string $currency = 'USD', array $args = [] ): Transfer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Transfer amount must be greater than zero.', 'arraypress' ) );
		}

		try {
			$smallest_unit = function_exists( 'ArrayPress\\Currencies\\Currency::to_smallest_unit' )
				? \ArrayPress\Currencies\Currency::to_smallest_unit( $amount, $currency )
				: (int) round( $amount * 100 );

			$params = [
				'amount'      => $smallest_unit,
				'currency'    => strtolower( $currency ),
				'destination' => $account_id,
			];

			foreach ( [ 'description', 'transfer_group', 'source_transaction', 'metadata' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->transfers->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a single transfer by ID.
	 *
	 * @param string $transfer_id Stripe transfer ID (tr_xxx).
	 *
	 * @return Transfer|WP_Error
	 * @since 1.0.0
	 */
	public function get( string $transfer_id ): Transfer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->transfers->retrieve( $transfer_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a transfer's metadata or description.
	 *
	 * @param string $transfer_id Stripe transfer ID (tr_xxx).
	 * @param array  $args        Fields to update: description (string), metadata (array).
	 *
	 * @return Transfer|WP_Error
	 * @since 1.0.0
	 */
	public function update( string $transfer_id, array $args ): Transfer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			foreach ( [ 'description', 'metadata' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->transfers->update( $transfer_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Listing
	// -------------------------------------------------------------------------

	/**
	 * List transfers with optional filters.
	 *
	 * @param array $params Optional filters:
	 *                      - destination    (string) Filter by connected account ID (acct_xxx).
	 *                      - transfer_group (string) Filter by transfer group.
	 *                      - created        (array)  Date range: gte, lte, gt, lt (Unix timestamps).
	 *                      - limit          (int)    Max results per page (default 10, max 100).
	 *
	 * @return array|WP_Error [ 'items' => Transfer[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$response = $stripe->transfers->all( $params );

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
	 * List all transfers to a specific connected account.
	 *
	 * @param string $account_id Stripe connected account ID (acct_xxx).
	 * @param array  $params     Optional filters (same as list(), except destination).
	 *
	 * @return array|WP_Error [ 'items' => Transfer[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_account( string $account_id, array $params = [] ): array|WP_Error {
		return $this->list( array_merge( $params, [ 'destination' => $account_id ] ) );
	}

	/**
	 * List all transfers in a transfer group.
	 *
	 * Useful for grouping a monthly payout run: set transfer_group to
	 * something like 'commissions_2026_01' and retrieve all transfers in that batch.
	 *
	 * @param string $transfer_group Transfer group identifier.
	 * @param array  $params         Optional additional filters.
	 *
	 * @return array|WP_Error [ 'items' => Transfer[], 'has_more' => bool, 'cursor' => string ]
	 * @since 1.0.0
	 */
	public function list_by_group( string $transfer_group, array $params = [] ): array|WP_Error {
		return $this->list( array_merge( $params, [ 'transfer_group' => $transfer_group ] ) );
	}

	// -------------------------------------------------------------------------
	// Reversals
	// -------------------------------------------------------------------------

	/**
	 * Reverse a transfer (pull back funds from a connected account).
	 *
	 * Reverses all or part of a transfer. The connected account must have
	 * sufficient balance to cover the reversal.
	 *
	 * Note: Reversals that exceed the connected account's available balance
	 * will fail. If the account has already paid out, reversal may not be
	 * possible — check the account balance first.
	 *
	 * @param string     $transfer_id Stripe transfer ID (tr_xxx).
	 * @param float|null $amount      Amount to reverse in major units. Null reverses the full transfer.
	 * @param string     $currency    Currency for partial reversal. Default 'USD'.
	 * @param array      $args        Optional: description (string), metadata (array), refund_application_fee (bool).
	 *
	 * @return TransferReversal|WP_Error
	 * @since 1.0.0
	 */
	public function reverse( string $transfer_id, ?float $amount = null, string $currency = 'USD', array $args = [] ): TransferReversal|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$params = [];

			if ( $amount !== null ) {
				$smallest_unit = function_exists( 'ArrayPress\\Currencies\\Currency::to_smallest_unit' )
					? \ArrayPress\Currencies\Currency::to_smallest_unit( $amount, $currency )
					: (int) round( $amount * 100 );

				$params['amount'] = $smallest_unit;
			}

			foreach ( [ 'description', 'metadata', 'refund_application_fee' ] as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$params[ $field ] = $args[ $field ];
				}
			}

			return $stripe->transfers->createReversal( $transfer_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Bulk Payout Helpers
	// -------------------------------------------------------------------------

	/**
	 * Send commission payouts to multiple connected accounts in one call.
	 *
	 * Iterates an array of payouts and creates a transfer for each. All
	 * transfers in the same batch are assigned the same transfer_group for
	 * easy retrieval later.
	 *
	 * @param array     $payouts        Array of payout items: [ 'account_id' => 'acct_xxx', 'amount' => 49.99,
	 *                                  'metadata' => [] ]
	 * @param string    $currency       Currency for all transfers. Default 'USD'.
	 * @param string    $transfer_group Optional group identifier (e.g. 'commissions_2026_02'). Auto-generated if
	 *                                  empty.
	 *
	 * @return array {
	 * @type string     $transfer_group The group identifier used for this batch.
	 * @type Transfer[] $succeeded      Transfers that completed successfully.
	 * @type array[]    $failed         Failed items: [ 'account_id', 'amount', 'error' (WP_Error) ]
	 * @type int        $total_sent     Total amount transferred in smallest unit.
	 *                                  }
	 * @since 1.0.0
	 */
	public function bulk_payout( array $payouts, string $currency = 'USD', string $transfer_group = '' ): array {
		if ( empty( $transfer_group ) ) {
			$transfer_group = 'commissions_' . gmdate( 'Y_m_d_His' );
		}

		$succeeded  = [];
		$failed     = [];
		$total_sent = 0;

		foreach ( $payouts as $payout ) {
			$account_id = $payout['account_id'] ?? '';
			$amount     = $payout['amount'] ?? 0;
			$metadata   = $payout['metadata'] ?? [];

			if ( empty( $account_id ) || $amount <= 0 ) {
				$failed[] = [
					'account_id' => $account_id,
					'amount'     => $amount,
					'error'      => new WP_Error( 'invalid_payout', __( 'Missing account_id or invalid amount.', 'arraypress' ) ),
				];
				continue;
			}

			$transfer = $this->create( $account_id, $amount, $currency, [
				'transfer_group' => $transfer_group,
				'metadata'       => $metadata,
			] );

			if ( is_wp_error( $transfer ) ) {
				$failed[] = [
					'account_id' => $account_id,
					'amount'     => $amount,
					'error'      => $transfer,
				];
			} else {
				$succeeded[] = $transfer;
				$total_sent  += $transfer->amount;
			}
		}

		return [
			'transfer_group' => $transfer_group,
			'succeeded'      => $succeeded,
			'failed'         => $failed,
			'total_sent'     => $total_sent,
		];
	}

}