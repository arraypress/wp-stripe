<?php
/**
 * Stripe Client
 *
 * Manages the Stripe API connection with support for test/live mode
 * switching, lazy key resolution via callbacks, and WordPress integration.
 *
 * The client acts as the central access point for all Stripe operations.
 * Keys can be provided as static strings or as callables that resolve
 * at runtime, allowing integration with any settings system.
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
use Stripe\StripeClient;

/**
 * Class Client
 *
 * Provides a configured Stripe API client with mode-aware key management.
 *
 * Usage:
 *   $client = new Client( [
 *       'secret_key'      => fn() => get_option( 'stripe_secret' ),
 *       'publishable_key' => fn() => get_option( 'stripe_pub' ),
 *       'webhook_secret'  => fn() => get_option( 'stripe_wh' ),
 *       'mode'            => 'test',
 *   ] );
 *
 *   // With a custom API version (e.g. for Managed Payments):
 *   $client = new Client( [
 *       'secret_key'  => fn() => get_option( 'stripe_secret' ),
 *       'api_version' => '2026-01-28.clover; managed_payments_preview=v1',
 *   ] );
 *
 *   $client->stripe()->products->create( [...] );
 *
 * @since 1.0.0
 */
class Client {

	/**
	 * Stripe SDK client instance.
	 *
	 * Initialized lazily on first access via stripe().
	 *
	 * @since 1.0.0
	 * @var StripeClient|null
	 */
	private ?StripeClient $client = null;

	/**
	 * Configuration values.
	 *
	 * Each value may be a string or a callable that returns a string.
	 * Supported keys: secret_key, publishable_key, webhook_secret, mode, api_version.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $config;

	/**
	 * Resolved mode cache.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private ?string $resolved_mode = null;

	/**
	 * Constructor.
	 *
	 * Accepts configuration as an associative array. Values may be
	 * strings or callables (closures/functions) that resolve at runtime.
	 *
	 * @param array          $config          {
	 *                                        Configuration options.
	 *
	 * @type string|callable $secret_key      Stripe secret key or resolver.
	 * @type string|callable $publishable_key Stripe publishable key or resolver.
	 * @type string|callable $webhook_secret  Webhook signing secret or resolver.
	 * @type string|callable $mode            'test' or 'live', or resolver. Default 'test'.
	 * @type string|callable $api_version     Stripe API version string or resolver. Optional.
	 *                                        Required for features like Managed Payments
	 *                                        (e.g. '2026-01-28.clover; managed_payments_preview=v1').
	 *                                        }
	 * @since 1.0.0
	 *
	 */
	public function __construct( array $config = [] ) {
		$this->config = wp_parse_args( $config, [
			'secret_key'      => '',
			'publishable_key' => '',
			'webhook_secret'  => '',
			'mode'            => 'test',
			'api_version'     => '',
		] );
	}

	/** =========================================================================
	 *  Stripe Client Access
	 *  ======================================================================== */

	/**
	 * Get the Stripe SDK client.
	 *
	 * Initializes the client on first call using the resolved secret key.
	 * If an api_version is configured, it is passed to the client to
	 * override the SDK default — required for preview features such as
	 * Managed Payments.
	 *
	 * Returns null if the secret key is empty or initialization fails.
	 *
	 * @return StripeClient|null The Stripe client or null if not configured.
	 * @since 1.0.0
	 *
	 */
	public function stripe(): ?StripeClient {
		if ( $this->client !== null ) {
			return $this->client;
		}

		$key = $this->get_secret_key();

		if ( empty( $key ) ) {
			return null;
		}

		try {
			$options = [ 'api_key' => $key ];

			$api_version = $this->get_api_version();
			if ( ! empty( $api_version ) ) {
				$options['stripe_version'] = $api_version;
			}

			$this->client = new StripeClient( $options );
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[ArrayPress\Stripe] Client init error: ' . $e->getMessage() );
			}

			return null;
		}

		return $this->client;
	}

	/** =========================================================================
	 *  Keys
	 *  ======================================================================== */

	/**
	 * Get the secret key for the current mode.
	 *
	 * @return string Secret key or empty string.
	 * @since 1.0.0
	 *
	 */
	public function get_secret_key(): string {
		return (string) $this->resolve( 'secret_key' );
	}

	/**
	 * Get the publishable key for the current mode.
	 *
	 * @return string Publishable key or empty string.
	 * @since 1.0.0
	 *
	 */
	public function get_publishable_key(): string {
		return (string) $this->resolve( 'publishable_key' );
	}

	/**
	 * Get the webhook signing secret for the current mode.
	 *
	 * @return string Webhook secret or empty string.
	 * @since 1.0.0
	 *
	 */
	public function get_webhook_secret(): string {
		return (string) $this->resolve( 'webhook_secret' );
	}

	/** =========================================================================
	 *  API Version
	 *  ======================================================================== */

	/**
	 * Get the configured API version string.
	 *
	 * Returns empty string if not set, in which case the Stripe SDK
	 * default version is used. Set a custom version for preview features
	 * such as Managed Payments.
	 *
	 * @return string API version string or empty string.
	 * @since 1.0.0
	 *
	 */
	public function get_api_version(): string {
		return (string) $this->resolve( 'api_version' );
	}

	/** =========================================================================
	 *  Mode
	 *  ======================================================================== */

	/**
	 * Get the current mode.
	 *
	 * @return string 'test' or 'live'.
	 * @since 1.0.0
	 *
	 */
	public function get_mode(): string {
		if ( $this->resolved_mode === null ) {
			$mode                = (string) $this->resolve( 'mode' );
			$this->resolved_mode = in_array( $mode, [ 'test', 'live' ], true ) ? $mode : 'test';
		}

		return $this->resolved_mode;
	}

	/**
	 * Check if currently in test mode.
	 *
	 * @return bool True if in test mode.
	 * @since 1.0.0
	 *
	 */
	public function is_test_mode(): bool {
		return $this->get_mode() === 'test';
	}

	/**
	 * Check if currently in live mode.
	 *
	 * @return bool True if in live mode.
	 * @since 1.0.0
	 *
	 */
	public function is_live_mode(): bool {
		return $this->get_mode() === 'live';
	}

	/** =========================================================================
	 *  State
	 *  ======================================================================== */

	/**
	 * Check if the client is properly configured.
	 *
	 * Verifies that both the secret and publishable keys are present
	 * and that the Stripe client can be initialized.
	 *
	 * @return bool True if configured and ready for API calls.
	 * @since 1.0.0
	 *
	 */
	public function is_configured(): bool {
		return $this->stripe() !== null
		       && $this->get_secret_key() !== ''
		       && $this->get_publishable_key() !== '';
	}

	/**
	 * Test the connection to Stripe.
	 *
	 * Hits the /v1/balance endpoint which validates the secret key
	 * and confirms connectivity. Returns a structured result with
	 * success status, message, and detected mode.
	 *
	 * @return array{success: bool, mode: string, message: string}
	 * @since 1.0.0
	 *
	 */
	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [
				'success' => false,
				'mode'    => '',
				'message' => __( 'Stripe is not configured. Please enter your API keys first.', 'arraypress' ),
			];
		}

		$stripe = $this->stripe();

		if ( ! $stripe ) {
			return [
				'success' => false,
				'mode'    => '',
				'message' => __( 'Stripe client could not be initialized.', 'arraypress' ),
			];
		}

		try {
			$balance = $stripe->balance->retrieve();
			$mode    = $balance->livemode ? 'live' : 'test';

			return [
				'success' => true,
				'mode'    => $mode,
				'message' => sprintf(
				/* translators: %s: 'live' or 'test' */
					__( 'Connected to Stripe successfully (%s mode).', 'arraypress' ),
					$mode
				),
			];
		} catch ( Exception $e ) {
			return [
				'success' => false,
				'mode'    => '',
				'message' => sprintf(
				/* translators: %s: error message */
					__( 'Connection failed: %s', 'arraypress' ),
					$e->getMessage()
				),
			];
		}
	}

	/**
	 * Reset the client instance.
	 *
	 * Forces re-initialization on the next stripe() call. Useful when
	 * keys or mode have changed at runtime.
	 *
	 * @since 1.0.0
	 */
	public function reset(): void {
		$this->client        = null;
		$this->resolved_mode = null;
	}

	/** =========================================================================
	 *  Internal
	 *  ======================================================================== */

	/**
	 * Resolve a configuration value.
	 *
	 * If the value is callable, invokes it and returns the result.
	 * Otherwise returns the value directly.
	 *
	 * @param string $key Configuration key.
	 *
	 * @return mixed Resolved value.
	 * @since  1.0.0
	 * @access private
	 *
	 */
	private function resolve( string $key ): mixed {
		$value = $this->config[ $key ] ?? '';

		return is_callable( $value ) ? $value() : $value;
	}

}