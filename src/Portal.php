<?php
/**
 * Stripe Customer Portal Helper
 *
 * Provides convenience methods for creating Stripe Billing Portal
 * sessions, allowing customers to manage their subscriptions, payment
 * methods, and billing details.
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
use Stripe\BillingPortal\Session;
use WP_Error;

/**
 * Class Portal
 *
 * Manages Stripe Billing Portal session creation.
 *
 * Usage:
 *   $portal = new Portal( $client );
 *
 *   $session = $portal->create( 'cus_xxx', home_url( '/account/' ) );
 *   wp_redirect( $session->url );
 *
 * @since 1.0.0
 */
class Portal {

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

	/**
	 * Create a billing portal session.
	 *
	 * Generates a short-lived URL where the customer can manage their
	 * subscriptions, payment methods, and billing information.
	 *
	 * @param string $customer_id   Stripe customer ID.
	 * @param string $return_url    URL to return to after the portal. Default home_url().
	 * @param array  $args          {
	 *                              Optional portal session arguments.
	 *
	 * @type string  $configuration Billing portal configuration ID.
	 * @type string  $locale        Portal locale (e.g., 'en', 'fr').
	 * @type array   $flow_data     Flow-specific data for portal actions.
	 *                              }
	 *
	 * @return Session|WP_Error The portal session or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( string $customer_id, string $return_url = '', array $args = [] ): Session|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( $customer_id ) ) {
			return new WP_Error( 'missing_customer', __( 'Customer ID is required.', 'arraypress' ) );
		}

		$params = [
			'customer'   => $customer_id,
			'return_url' => $return_url ?: home_url(),
		];

		if ( ! empty( $args['configuration'] ) ) {
			$params['configuration'] = $args['configuration'];
		}

		if ( ! empty( $args['locale'] ) ) {
			$params['locale'] = $args['locale'];
		}

		if ( ! empty( $args['flow_data'] ) ) {
			$params['flow_data'] = $args['flow_data'];
		}

		try {
			return $stripe->billingPortal->sessions->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get the portal URL for a customer.
	 *
	 * Creates a session and returns just the URL string. Convenience
	 * method for redirect-based flows.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param string $return_url  URL to return to after the portal.
	 * @param array  $args        Same as create().
	 *
	 * @return string|WP_Error Portal URL or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get_url( string $customer_id, string $return_url = '', array $args = [] ): string|WP_Error {
		$session = $this->create( $customer_id, $return_url, $args );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return $session->url;
	}

}
