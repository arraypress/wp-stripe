<?php
/**
 * Stripe Customers Helper
 *
 * Provides convenience methods for creating, retrieving, updating,
 * and searching Stripe customers with WordPress integration.
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
use Stripe\Customer;
use WP_Error;

/**
 * Class Customers
 *
 * Manages Stripe customer operations.
 *
 * Usage:
 *   $customers = new Customers( $client );
 *
 *   $customer = $customers->create( [
 *       'email' => 'user@example.com',
 *       'name'  => 'Jane Doe',
 *   ] );
 *
 *   $customers->add_note( 'cus_xxx', 'VIP customer, handle with care.' );
 *
 * @since 1.0.0
 */
class Customers {

	use Traits\Serializable;

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
	 * Retrieve a customer from Stripe.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param array  $params      Optional parameters (e.g., expand).
	 *
	 * @return Customer|WP_Error The customer or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function get( string $customer_id, array $params = [] ): Customer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->customers->retrieve( $customer_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a customer with expanded payment and subscription data.
	 *
	 * @param string $customer_id Stripe customer ID.
	 *
	 * @return Customer|WP_Error The expanded customer or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function get_expanded( string $customer_id ): Customer|WP_Error {
		return $this->get( $customer_id, [
			'expand' => [
				'default_source',
				'invoice_settings.default_payment_method',
			],
		] );
	}

	/**
	 * Search for a customer by email address.
	 *
	 * Returns the first matching customer. Stripe allows multiple
	 * customers with the same email, so this returns the most recent.
	 *
	 * @param string $email Customer email address.
	 *
	 * @return Customer|null|WP_Error The customer, null if not found, or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function find_by_email( string $email ): Customer|null|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$result = $stripe->customers->search( [
				'query' => "email:'{$email}'",
				'limit' => 1,
			] );

			return ! empty( $result->data ) ? $result->data[0] : null;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Search for customers by name or email.
	 *
	 * Uses the Stripe Search API to find customers matching a query string.
	 * Searches across name and email fields.
	 *
	 * @since 1.0.0
	 *
	 * @param string $query Search term to match against customer name or email.
	 * @param int    $limit Maximum number of results to return (default 10, max 100).
	 *
	 * @return Customer[]|WP_Error Array of matching customers or WP_Error on failure.
	 */
	public function search_by_name( string $query, int $limit = 10 ): array|WP_Error {
		$client = $this->client->stripe();

		if ( ! $client ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$query = trim( $query );

		if ( empty( $query ) ) {
			return new WP_Error( 'missing_param', __( 'Search query is required.', 'arraypress' ) );
		}

		try {
			$result = $client->customers->search( [
				'query' => "name~\"{$query}\" OR email~\"{$query}\"",
				'limit' => (int) min( $limit, 100 ),
			] );

			return $result->data;
		} catch ( \Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List customers from Stripe.
	 *
	 * @param array $params         {
	 *                              Optional. Stripe list parameters.
	 *
	 * @type string $email          Filter by email address.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 *                              }
	 *
	 * @return array{items: Customer[], has_more: bool, cursor: string}|WP_Error
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
			$result    = $stripe->customers->all( $params );
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
	 * List customers from Stripe, returning plain stdClass objects.
	 *
	 * Identical to list() but strips Stripe SDK internals from each item
	 * via JSON round-trip. Use when results will be passed to a REST
	 * endpoint, stored in a transient, or handed to any system expecting
	 * plain serializable objects (e.g., wp-inline-sync batch callbacks).
	 *
	 * @param array $params         {
	 *                              Optional. Same parameters as list().
	 *
	 * @type string $email          Filter by email address.
	 * @type int    $limit          Number of results (1-100). Default 100.
	 * @type string $starting_after Cursor for pagination.
	 *                              }
	 *
	 * @return array{items: \stdClass[], has_more: bool, cursor: string}|WP_Error
	 *
	 * @since 1.0.0
	 *
	 * @see   list()
	 */
	public function list_serialized( array $params = [] ): array|WP_Error {
		$result = $this->list( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->serialize_result( $result );
	}

	/** =========================================================================
	 *  Creation & Updates
	 *  ======================================================================== */

	/**
	 * Create a customer in Stripe.
	 *
	 * @param array $args           {
	 *                              Customer creation arguments.
	 *
	 * @type string $email          Customer email address.
	 * @type string $name           Customer full name.
	 * @type string $phone          Customer phone number.
	 * @type string $description    Internal description/notes.
	 * @type array  $metadata       Key/value metadata pairs.
	 * @type array  $address        Address fields: line1, line2, city, state, postal_code, country.
	 * @type string $payment_method Default payment method ID to attach.
	 *                              }
	 *
	 * @return Customer|WP_Error The created customer or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function create( array $args ): Customer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		$direct_fields = [ 'email', 'name', 'phone', 'description', 'metadata', 'address' ];
		foreach ( $direct_fields as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$params[ $field ] = $args[ $field ];
			}
		}

		if ( ! empty( $args['payment_method'] ) ) {
			$params['payment_method']   = $args['payment_method'];
			$params['invoice_settings'] = [
				'default_payment_method' => $args['payment_method'],
			];
		}

		try {
			return $stripe->customers->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update a customer in Stripe.
	 *
	 * Only sends provided fields, leaving others unchanged.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param array  $args        Same fields as create(). Only provided fields are updated.
	 *
	 * @return Customer|WP_Error The updated customer or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function update( string $customer_id, array $args ): Customer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = [];

		$direct_fields = [ 'email', 'name', 'phone', 'description', 'metadata', 'address' ];
		foreach ( $direct_fields as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$params[ $field ] = $args[ $field ];
			}
		}

		if ( empty( $params ) ) {
			return $this->get( $customer_id );
		}

		try {
			return $stripe->customers->update( $customer_id, $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Create or update a customer by email.
	 *
	 * Searches for an existing customer with the given email. If found,
	 * updates their details. If not, creates a new customer. This is the
	 * typical pattern for checkout flows.
	 *
	 * @param string $email Customer email address.
	 * @param array  $args  Customer fields (same as create()).
	 *
	 * @return array{customer: Customer, created: bool}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function upsert_by_email( string $email, array $args = [] ): array|WP_Error {
		$existing = $this->find_by_email( $email );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$args['email'] = $email;

		if ( $existing ) {
			$customer = $this->update( $existing->id, $args );

			if ( is_wp_error( $customer ) ) {
				return $customer;
			}

			return [ 'customer' => $customer, 'created' => false ];
		}

		$customer = $this->create( $args );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		return [ 'customer' => $customer, 'created' => true ];
	}

	/**
	 * Delete a customer from Stripe.
	 *
	 * Permanently deletes the customer and cancels all active subscriptions.
	 *
	 * @param string $customer_id Stripe customer ID.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function delete( string $customer_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->customers->delete( $customer_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Notes / Description
	 *  ======================================================================== */

	/**
	 * Add a note to a customer.
	 *
	 * Stripe customers have a `description` field that's commonly
	 * used for internal notes. This method appends to the existing
	 * description with a timestamp.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param string $note        Note text to add.
	 *
	 * @return Customer|WP_Error The updated customer or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function add_note( string $customer_id, string $note ): Customer|WP_Error {
		$customer = $this->get( $customer_id );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$existing    = $customer->description ?? '';
		$timestamp   = gmdate( 'Y-m-d H:i' );
		$new_note    = "[{$timestamp}] {$note}";
		$description = $existing ? $existing . "\n" . $new_note : $new_note;

		return $this->update( $customer_id, [ 'description' => $description ] );
	}

	/** =========================================================================
	 *  Payment Methods
	 *  ======================================================================== */

	/**
	 * List payment methods for a customer.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param string $type        Payment method type. Default 'card'.
	 *
	 * @return array|WP_Error Array of payment methods or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function list_payment_methods( string $customer_id, string $type = 'card' ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$result = $stripe->customers->allPaymentMethods( $customer_id, [
				'type' => $type,
			] );

			return $result->data;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Set the default payment method for a customer.
	 *
	 * @param string $customer_id       Stripe customer ID.
	 * @param string $payment_method_id Stripe payment method ID.
	 *
	 * @return Customer|WP_Error The updated customer or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function set_default_payment_method( string $customer_id, string $payment_method_id ): Customer|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->customers->update( $customer_id, [
				'invoice_settings' => [
					'default_payment_method' => $payment_method_id,
				],
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Bulk Retrieval
	 *  ======================================================================== */

	/**
	 * Fetch all customers from Stripe, auto-paginating.
	 *
	 * @param array $params Optional filter parameters.
	 *
	 * @return Customer[]|WP_Error All matching customers or WP_Error.
	 * @since 1.0.0
	 *
	 */
	public function all( array $params = [] ): array|WP_Error {
		$all_items = [];
		$cursor    = '';

		do {
			$page_params = array_merge( $params, [ 'limit' => 100 ] );

			if ( $cursor ) {
				$page_params['starting_after'] = $cursor;
			}

			$result = $this->list( $page_params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$all_items = array_merge( $all_items, $result['items'] );
			$cursor    = $result['cursor'];
		} while ( $result['has_more'] );

		return $all_items;
	}

	/**
	 * Fetch customers in batches via a callback.
	 *
	 * @param callable $callback Function to process each batch. Receives (Customer[] $items, int $page).
	 *                           Return false to stop.
	 * @param array    $params   Optional filter parameters.
	 *
	 * @return int|WP_Error Total items processed, or WP_Error on failure.
	 * @since 1.0.0
	 *
	 */
	public function each_batch( callable $callback, array $params = [] ): int|WP_Error {
		$cursor = '';
		$total  = 0;
		$page   = 1;

		do {
			$page_params = array_merge( $params, [ 'limit' => 100 ] );

			if ( $cursor ) {
				$page_params['starting_after'] = $cursor;
			}

			$result = $this->list( $page_params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( empty( $result['items'] ) ) {
				break;
			}

			$total += count( $result['items'] );

			if ( $callback( $result['items'], $page ) === false ) {
				break;
			}

			$cursor = $result['cursor'];
			$page ++;
		} while ( $result['has_more'] );

		return $total;
	}

}
