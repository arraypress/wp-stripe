<?php
/**
 * Stripe Webhook Handler
 *
 * Manages webhook event registration, signature verification, replay
 * protection, and event dispatch. Integrates with WordPress REST API
 * to provide a clean endpoint for receiving Stripe webhook events.
 *
 * Replay protection uses WordPress transients by default, preventing
 * the same event from being processed more than once within a
 * configurable time window.
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
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Webhooks
 *
 * Provides webhook event handling with signature verification and
 * replay protection for Stripe integrations.
 *
 * Usage:
 *   $webhooks = new Webhooks( $client, [
 *       'namespace'    => 'myplugin/v1',
 *       'route'        => '/stripe/webhook',
 *       'tolerance'    => 300,
 *       'replay_ttl'   => DAY_IN_SECONDS,
 *       'log_callback' => fn( $message, $level ) => error_log( $message ),
 *   ] );
 *
 *   $webhooks->on( 'checkout.session.completed', function( Event $event ) {
 *       $session = $event->data->object;
 *       // Handle completed checkout...
 *   } );
 *
 *   $webhooks->on( 'invoice.payment_failed', function( Event $event ) {
 *       $invoice = $event->data->object;
 *       // Handle failed payment...
 *   } );
 *
 *   $webhooks->register();
 *
 * @since 1.0.0
 */
class Webhooks {

	/**
	 * Client instance for key access.
	 *
	 * @since 1.0.0
	 * @var Client
	 */
	private Client $client;

	/**
	 * Configuration options.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $config;

	/**
	 * Registered event handlers.
	 *
	 * Keyed by event type (e.g., 'checkout.session.completed').
	 * Each entry is an array of callables.
	 *
	 * @since 1.0.0
	 * @var array<string, callable[]>
	 */
	private array $handlers = [];

	/**
	 * Constructor.
	 *
	 * @param Client  $client        The Stripe client instance.
	 * @param array   $config        {
	 *                               Configuration options.
	 *
	 * @type string   $namespace     REST API namespace. Default 'arraypress-stripe/v1'.
	 * @type string   $route         REST API route. Default '/webhook'.
	 * @type int      $tolerance     Signature timestamp tolerance in seconds. Default 300.
	 * @type int      $replay_ttl    Replay protection TTL in seconds. Default DAY_IN_SECONDS.
	 * @type string   $replay_prefix Transient prefix for replay protection. Default 'stripe_evt_'.
	 * @type callable $log_callback  Optional logging callback. Receives (string $message, string $level).
	 *                               }
	 * @since 1.0.0
	 *
	 */
	public function __construct( Client $client, array $config = [] ) {
		$this->client = $client;
		$this->config = wp_parse_args( $config, [
			'namespace'     => 'arraypress-stripe/v1',
			'route'         => '/webhook',
			'tolerance'     => 300,
			'replay_ttl'    => DAY_IN_SECONDS,
			'replay_prefix' => 'stripe_evt_',
			'log_callback'  => null,
		] );
	}

	/** =========================================================================
	 *  Handler Registration
	 *  ======================================================================== */

	/**
	 * Register a handler for a Stripe event type.
	 *
	 * Multiple handlers can be registered for the same event type.
	 * Handlers are called in the order they were registered.
	 *
	 * @param string   $event_type Stripe event type (e.g., 'checkout.session.completed').
	 * @param callable $callback   Handler function. Receives the Stripe Event object.
	 *
	 * @return self For method chaining.
	 * @since 1.0.0
	 *
	 */
	public function on( string $event_type, callable $callback ): self {
		$this->handlers[ $event_type ][] = $callback;

		return $this;
	}

	/**
	 * Remove all handlers for a specific event type.
	 *
	 * @param string $event_type Stripe event type to clear.
	 *
	 * @return self For method chaining.
	 * @since 1.0.0
	 *
	 */
	public function off( string $event_type ): self {
		unset( $this->handlers[ $event_type ] );

		return $this;
	}

	/**
	 * Get all registered event types.
	 *
	 * @return string[] Array of registered event type strings.
	 * @since 1.0.0
	 *
	 */
	public function get_registered_events(): array {
		return array_keys( $this->handlers );
	}

	/**
	 * Check if a handler is registered for an event type.
	 *
	 * @param string $event_type Stripe event type.
	 *
	 * @return bool True if at least one handler is registered.
	 * @since 1.0.0
	 *
	 */
	public function has_handler( string $event_type ): bool {
		return ! empty( $this->handlers[ $event_type ] );
	}

	/** =========================================================================
	 *  REST API Registration
	 *  ======================================================================== */

	/**
	 * Register the webhook REST API endpoint.
	 *
	 * Should be called during plugin initialization. Hooks into
	 * 'rest_api_init' to register the endpoint.
	 *
	 * @return self For method chaining.
	 * @since 1.0.0
	 *
	 */
	public function register(): self {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );

		return $this;
	}

	/**
	 * Register the REST API route.
	 *
	 * Called by WordPress during rest_api_init. Should not be
	 * called directly — use register() instead.
	 *
	 * @since 1.0.0
	 * @internal
	 */
	public function register_route(): void {
		register_rest_route( $this->config['namespace'], $this->config['route'], [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_request' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Get the full webhook endpoint URL.
	 *
	 * Useful for displaying in admin settings or configuring
	 * the webhook endpoint in the Stripe Dashboard.
	 *
	 * @return string Full REST API URL for the webhook endpoint.
	 * @since 1.0.0
	 *
	 */
	public function get_endpoint_url(): string {
		return rest_url( $this->config['namespace'] . $this->config['route'] );
	}

	/** =========================================================================
	 *  Request Handling
	 *  ======================================================================== */

	/**
	 * Handle an incoming webhook request.
	 *
	 * Verifies the signature, checks for replay attacks, dispatches
	 * the event to registered handlers, and returns an appropriate
	 * HTTP response.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Response to send back to Stripe.
	 * @since    1.0.0
	 * @internal Called by WordPress REST API.
	 *
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe-signature' );

		// Verify signature
		$event = $this->verify_signature( $payload, $signature );

		if ( is_wp_error( $event ) ) {
			$this->log( 'Webhook signature verification failed: ' . $event->get_error_message(), 'error' );

			return new WP_REST_Response( [
				'error' => $event->get_error_message(),
			], 400 );
		}

		// Check replay protection
		if ( $this->is_replay( $event->id ) ) {
			$this->log( "Duplicate event ignored: {$event->id} ({$event->type})", 'info' );

			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		// Mark as processed (before handling, to prevent concurrent duplicates)
		$this->mark_processed( $event->id );

		// Dispatch to handlers
		$result = $this->dispatch( $event );

		if ( is_wp_error( $result ) ) {
			$this->log( "Webhook handler error for {$event->type}: " . $result->get_error_message(), 'error' );

			return new WP_REST_Response( [
				'error' => $result->get_error_message(),
			], 500 );
		}

		$this->log( "Webhook processed: {$event->id} ({$event->type})", 'info' );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/** =========================================================================
	 *  Signature Verification
	 *  ======================================================================== */

	/**
	 * Verify a webhook signature and construct the event.
	 *
	 * Uses the Stripe SDK's built-in signature verification to ensure
	 * the payload was sent by Stripe and hasn't been tampered with.
	 *
	 * @param string      $payload   Raw request body.
	 * @param string|null $signature Stripe-Signature header value.
	 *
	 * @return Event|WP_Error Verified Stripe Event or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function verify_signature( string $payload, ?string $signature ): Event|WP_Error {
		if ( empty( $signature ) ) {
			return new WP_Error(
				'missing_signature',
				__( 'Missing Stripe-Signature header.', 'arraypress' )
			);
		}

		$secret = $this->client->get_webhook_secret();

		if ( empty( $secret ) ) {
			return new WP_Error(
				'missing_secret',
				__( 'Webhook signing secret is not configured.', 'arraypress' )
			);
		}

		try {
			return Webhook::constructEvent(
				$payload,
				$signature,
				$secret,
				$this->config['tolerance']
			);
		} catch ( SignatureVerificationException $e ) {
			return new WP_Error( 'invalid_signature', $e->getMessage() );
		} catch ( \UnexpectedValueException $e ) {
			return new WP_Error( 'invalid_payload', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Event Dispatch
	 *  ======================================================================== */

	/**
	 * Dispatch an event to registered handlers.
	 *
	 * Calls all handlers registered for the event type. If any handler
	 * returns a WP_Error, dispatch stops and the error is returned.
	 *
	 * Also fires a WordPress action hook for each event, allowing
	 * external code to handle events without direct registration.
	 *
	 * @param Event $event The verified Stripe event.
	 *
	 * @return true|WP_Error True on success, WP_Error if a handler fails.
	 * @since 1.0.0
	 *
	 */
	public function dispatch( Event $event ): true|WP_Error {
		$type = $event->type;

		// Call registered handlers
		if ( isset( $this->handlers[ $type ] ) ) {
			foreach ( $this->handlers[ $type ] as $handler ) {
				try {
					$result = $handler( $event );

					if ( $result instanceof WP_Error ) {
						return $result;
					}
				} catch ( Exception $e ) {
					return new WP_Error(
						'handler_exception',
						sprintf(
						/* translators: 1: event type, 2: error message */
							__( 'Handler exception for %1$s: %2$s', 'arraypress' ),
							$type,
							$e->getMessage()
						)
					);
				}
			}
		}

		// Fire WordPress action for external handling
		$action_name = 'arraypress_stripe_webhook_' . str_replace( '.', '_', $type );

		/**
		 * Fires when a specific Stripe webhook event is received.
		 *
		 * Dynamic action name based on event type. Dots are replaced
		 * with underscores (e.g., 'checkout.session.completed' becomes
		 * 'arraypress_stripe_webhook_checkout_session_completed').
		 *
		 * @param Event  $event  The verified Stripe event.
		 * @param Client $client The Stripe client instance.
		 *
		 * @since 1.0.0
		 *
		 */
		do_action( $action_name, $event, $this->client );

		/**
		 * Fires for all Stripe webhook events.
		 *
		 * @param Event  $event  The verified Stripe event.
		 * @param string $type   The event type string.
		 * @param Client $client The Stripe client instance.
		 *
		 * @since 1.0.0
		 *
		 */
		do_action( 'arraypress_stripe_webhook', $event, $type, $this->client );

		return true;
	}

	/** =========================================================================
	 *  Replay Protection
	 *  ======================================================================== */

	/**
	 * Check if an event has already been processed.
	 *
	 * Uses WordPress transients for lightweight, TTL-based replay
	 * protection. Events are considered duplicates within the
	 * configured replay_ttl window.
	 *
	 * @param string $event_id Stripe event ID.
	 *
	 * @return bool True if this event has already been processed.
	 * @since 1.0.0
	 *
	 */
	public function is_replay( string $event_id ): bool {
		$key = $this->get_replay_key( $event_id );

		return get_transient( $key ) !== false;
	}

	/**
	 * Mark an event as processed.
	 *
	 * Stores the event ID in a transient to prevent reprocessing
	 * within the replay TTL window.
	 *
	 * @param string $event_id Stripe event ID.
	 *
	 * @since 1.0.0
	 *
	 */
	public function mark_processed( string $event_id ): void {
		$key = $this->get_replay_key( $event_id );

		set_transient( $key, time(), $this->config['replay_ttl'] );
	}

	/**
	 * Clear a processed event marker.
	 *
	 * Useful for allowing an event to be reprocessed manually.
	 *
	 * @param string $event_id Stripe event ID.
	 *
	 * @since 1.0.0
	 *
	 */
	public function clear_processed( string $event_id ): void {
		delete_transient( $this->get_replay_key( $event_id ) );
	}

	/**
	 * Get the transient key for replay protection.
	 *
	 * @param string $event_id Stripe event ID.
	 *
	 * @return string Transient key.
	 * @since  1.0.0
	 * @access private
	 *
	 */
	private function get_replay_key( string $event_id ): string {
		return $this->config['replay_prefix'] . md5( $event_id );
	}

	/** =========================================================================
	 *  Logging
	 *  ======================================================================== */

	/**
	 * Log a webhook message.
	 *
	 * Uses the configured log callback if provided, otherwise falls
	 * back to error_log when WP_DEBUG is enabled.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level: 'info', 'error', 'warning'. Default 'info'.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( is_callable( $this->config['log_callback'] ) ) {
			( $this->config['log_callback'] )( $message, $level );

			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[ArrayPress\Stripe\Webhooks] [{$level}] {$message}" );
		}
	}

}
