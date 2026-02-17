<?php
/**
 * Stripe Events Helper
 *
 * Provides convenience methods for retrieving and listing Stripe
 * events. Useful for manual reprocessing of missed webhooks,
 * debugging, and audit logging.
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
use Stripe\Event;
use WP_Error;

/**
 * Class Events
 *
 * Manages Stripe event retrieval for reprocessing and debugging.
 *
 * Usage:
 *   $events = new Events( $client );
 *
 *   // Retrieve a specific event
 *   $event = $events->get( 'evt_xxx' );
 *
 *   // List recent checkout events
 *   $recent = $events->list( [ 'type' => 'checkout.session.completed' ] );
 *
 *   // Reprocess a missed event through webhook handlers
 *   $events->reprocess( 'evt_xxx', $webhooks );
 *
 * @since 1.0.0
 */
class Events {

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
	 * Retrieve a specific event from Stripe.
	 *
	 * @param string $event_id Stripe event ID.
	 *
	 * @return Event|WP_Error The event or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $event_id ): Event|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->events->retrieve( $event_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List events from Stripe.
	 *
	 * Events are available for up to 30 days from creation.
	 *
	 * @param array $params         {
	 *                              Optional. Stripe list parameters.
	 *
	 * @type string $type           Filter by event type (e.g., 'checkout.session.completed').
	 * @type int    $created        [gte]   Only events after this Unix timestamp.
	 * @type int    $created        [lte]   Only events before this Unix timestamp.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 *                              }
	 *
	 * @return array{items: Event[], has_more: bool, cursor: string}|WP_Error
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
			$result    = $stripe->events->all( $params );
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
	 * List events from the last N hours.
	 *
	 * Useful for checking what happened recently, especially
	 * when debugging missed webhooks.
	 *
	 * @param int    $hours Number of hours to look back. Default 24.
	 * @param string $type  Optional event type filter.
	 *
	 * @return Event[]|WP_Error Array of events or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function list_recent( int $hours = 24, string $type = '' ): array|WP_Error {
		$params = [
			'created' => [
				'gte' => time() - ( $hours * 3600 ),
			],
			'limit'   => 100,
		];

		if ( $type ) {
			$params['type'] = $type;
		}

		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['items'];
	}

	/** =========================================================================
	 *  Reprocessing
	 *  ======================================================================== */

	/**
	 * Reprocess an event through webhook handlers.
	 *
	 * Retrieves the event from Stripe and dispatches it through
	 * the provided Webhooks instance. Clears any replay protection
	 * markers first to allow reprocessing.
	 *
	 * @param string   $event_id Stripe event ID.
	 * @param Webhooks $webhooks Webhooks instance with registered handlers.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function reprocess( string $event_id, Webhooks $webhooks ): true|WP_Error {
		$event = $this->get( $event_id );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		// Clear replay protection so the event can be reprocessed
		$webhooks->clear_processed( $event_id );

		// Mark as processed before dispatch (prevents concurrent duplicates)
		$webhooks->mark_processed( $event_id );

		return $webhooks->dispatch( $event );
	}

}
