<?php
/**
 * Stripe Radar Value Lists Helper
 *
 * Provides convenience methods for managing Stripe Radar block and
 * allow lists. Wraps Stripe's Value List and Value List Item APIs
 * into a simple interface for blocking problematic customers.
 *
 * Stripe Radar uses "value lists" to group items (emails, IPs,
 * card fingerprints, etc.) that can be referenced in fraud rules.
 * Stripe provides default block/allow lists, and you can create
 * custom lists for your own rules.
 *
 * Note: Requires Stripe Radar (included with Stripe, or Radar for
 * Fraud Teams for custom lists and advanced rules).
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
use Stripe\Radar\ValueList;
use Stripe\Radar\ValueListItem;
use WP_Error;

/**
 * Class Radar
 *
 * Manages Stripe Radar value lists for blocking and allowing payments.
 *
 * Usage:
 *   $radar = new Radar( $client );
 *
 *   // Block an email address
 *   $radar->block_email( 'fraud@example.com' );
 *
 *   // Block an IP address
 *   $radar->block_ip( '192.168.1.1' );
 *
 *   // Block a customer
 *   $radar->block_customer( 'cus_xxx' );
 *
 *   // Add to any list by alias
 *   $radar->add_item( 'custom_blocklist', 'some_value' );
 *
 * @since 1.0.0
 */
class Radar {

	/**
	 * Default Stripe block list aliases.
	 *
	 * These lists are pre-created by Stripe and available to all accounts.
	 *
	 * @since 1.0.0
	 */
	const LIST_BLOCK_EMAIL = 'block_list_email';
	const LIST_BLOCK_IP = 'block_list_ip_address';
	const LIST_BLOCK_CARD = 'block_list_card_fingerprint';
	const LIST_BLOCK_CARD_BIN = 'block_list_card_bin';
	const LIST_BLOCK_CUSTOMER = 'block_list_customer_id';
	const LIST_BLOCK_CARD_COUNTRY = 'block_list_card_country';
	const LIST_BLOCK_CLIENT_COUNTRY = 'block_list_ip_country';
	const LIST_BLOCK_CHARGE_DESC = 'block_list_charge_description';
	const LIST_ALLOW_EMAIL = 'allow_list_email';
	const LIST_ALLOW_IP = 'allow_list_ip_address';
	const LIST_ALLOW_CARD = 'allow_list_card_fingerprint';
	const LIST_ALLOW_CUSTOMER = 'allow_list_customer_id';

	/**
	 * Valid item types for custom value lists.
	 *
	 * @since 1.0.0
	 */
	const array VALID_ITEM_TYPES = [
		'card_fingerprint',
		'card_bin',
		'email',
		'ip_address',
		'country',
		'string',
		'case_sensitive_string',
		'customer_id',
		'sepa_debit_fingerprint',
		'us_bank_account_fingerprint',
	];

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
	 *  Quick Block/Allow Methods
	 *  ======================================================================== */

	/**
	 * Block an email address.
	 *
	 * Adds the email to Stripe's default email block list.
	 *
	 * @param string $email Email address to block.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function block_email( string $email ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_BLOCK_EMAIL, strtolower( trim( $email ) ) );
	}

	/**
	 * Block an IP address.
	 *
	 * Adds the IP to Stripe's default IP block list.
	 *
	 * @param string $ip IP address to block.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function block_ip( string $ip ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_BLOCK_IP, trim( $ip ) );
	}

	/**
	 * Block a customer.
	 *
	 * Adds the customer ID to Stripe's default customer block list.
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function block_customer( string $customer_id ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_BLOCK_CUSTOMER, $customer_id );
	}

	/**
	 * Block a card fingerprint.
	 *
	 * Adds the fingerprint to Stripe's default card block list.
	 *
	 * @param string $fingerprint Card fingerprint string.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function block_card( string $fingerprint ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_BLOCK_CARD, $fingerprint );
	}

	/**
	 * Block a country.
	 *
	 * Adds the country code to Stripe's default card country block list.
	 *
	 * @param string $country_code Two-letter ISO country code.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function block_country( string $country_code ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_BLOCK_CARD_COUNTRY, strtoupper( trim( $country_code ) ) );
	}

	/**
	 * Allow an email address.
	 *
	 * Adds the email to Stripe's default email allow list.
	 *
	 * @param string $email Email address to allow.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function allow_email( string $email ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_ALLOW_EMAIL, strtolower( trim( $email ) ) );
	}

	/**
	 * Allow a customer.
	 *
	 * Adds the customer ID to Stripe's default customer allow list.
	 *
	 * @param string $customer_id Stripe customer ID (cus_xxx).
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function allow_customer( string $customer_id ): ValueListItem|WP_Error {
		return $this->add_item( self::LIST_ALLOW_CUSTOMER, $customer_id );
	}

	/** =========================================================================
	 *  List Item Management
	 *  ======================================================================== */

	/**
	 * Add a value to a list.
	 *
	 * Works with both default Stripe lists (by alias) and custom
	 * lists (by ID). Use the LIST_* constants for default lists.
	 *
	 * @param string $list_id_or_alias Value list ID (rsl_xxx) or alias.
	 * @param string $value            The value to add.
	 *
	 * @return ValueListItem|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function add_item( string $list_id_or_alias, string $value ): ValueListItem|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( empty( trim( $value ) ) ) {
			return new WP_Error( 'empty_value', __( 'Value cannot be empty.', 'arraypress' ) );
		}

		// Resolve alias to list ID if needed
		$list_id = $this->resolve_list_id( $list_id_or_alias );

		if ( is_wp_error( $list_id ) ) {
			return $list_id;
		}

		try {
			return $stripe->radar->valueListItems->create( [
				'value_list' => $list_id,
				'value'      => $value,
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Remove a value list item by its ID.
	 *
	 * @param string $item_id Value list item ID (rsli_xxx).
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function remove_item( string $item_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->radar->valueListItems->delete( $item_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List items in a value list.
	 *
	 * @param string $list_id_or_alias Value list ID (rsl_xxx) or alias.
	 * @param int    $limit            Maximum results. Default 100.
	 *
	 * @return array{items: ValueListItem[], has_more: bool}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list_items( string $list_id_or_alias, int $limit = 100 ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$list_id = $this->resolve_list_id( $list_id_or_alias );

		if ( is_wp_error( $list_id ) ) {
			return $list_id;
		}

		try {
			$result = $stripe->radar->valueListItems->all( [
				'value_list' => $list_id,
				'limit'      => min( $limit, 100 ),
			] );

			return [
				'items'    => $result->data,
				'has_more' => $result->has_more,
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Custom List Management
	 *  ======================================================================== */

	/**
	 * Create a custom value list.
	 *
	 * Custom lists can be referenced in Radar rules using the alias
	 * prefixed with @ (e.g., Block if :email: in @my_custom_list).
	 *
	 * @param string $alias     Alias for use in rules (e.g., 'vip_customers').
	 * @param string $name      Human-readable name.
	 * @param string $item_type Item type. Default 'email'. One of: card_fingerprint,
	 *                          card_bin, email, ip_address, country, string,
	 *                          case_sensitive_string, customer_id,
	 *                          sepa_debit_fingerprint, us_bank_account_fingerprint.
	 * @param array  $metadata  Optional metadata.
	 *
	 * @return ValueList|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function create_list( string $alias, string $name, string $item_type = 'email', array $metadata = [] ): ValueList|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		if ( ! in_array( $item_type, self::VALID_ITEM_TYPES, true ) ) {
			return new WP_Error(
				'invalid_item_type',
				sprintf( __( 'Item type must be one of: %s', 'arraypress' ), implode( ', ', self::VALID_ITEM_TYPES ) )
			);
		}

		$params = [
			'alias'     => $alias,
			'name'      => $name,
			'item_type' => $item_type,
		];

		if ( ! empty( $metadata ) ) {
			$params['metadata'] = $metadata;
		}

		try {
			return $stripe->radar->valueLists->create( $params );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve a value list.
	 *
	 * @param string $list_id Value list ID (rsl_xxx).
	 *
	 * @return ValueList|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function get_list( string $list_id ): ValueList|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			return $stripe->radar->valueLists->retrieve( $list_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * List all value lists.
	 *
	 * @param array $params Optional list parameters (alias, limit, etc.).
	 *
	 * @return array{items: ValueList[], has_more: bool}|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function list_all( array $params = [] ): array|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		$params = wp_parse_args( $params, [ 'limit' => 100 ] );

		try {
			$result = $stripe->radar->valueLists->all( $params );

			return [
				'items'    => $result->data,
				'has_more' => $result->has_more,
			];
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Delete a custom value list.
	 *
	 * Cannot delete default Stripe lists. The list must not be
	 * referenced in any active rules.
	 *
	 * @param string $list_id Value list ID (rsl_xxx).
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 *
	 */
	public function delete_list( string $list_id ): true|WP_Error {
		$stripe = $this->client->stripe();

		if ( ! $stripe ) {
			return new WP_Error( 'not_configured', __( 'Stripe client is not configured.', 'arraypress' ) );
		}

		try {
			$stripe->radar->valueLists->delete( $list_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/** =========================================================================
	 *  Internal
	 *  ======================================================================== */

	/**
	 * Resolve a list alias to a list ID.
	 *
	 * If the value starts with 'rsl_', it's already a list ID.
	 * Otherwise, search by alias to find the corresponding list.
	 *
	 * @param string $list_id_or_alias Value list ID or alias.
	 *
	 * @return string|WP_Error The list ID or WP_Error.
	 * @since  1.0.0
	 * @access private
	 *
	 */
	private function resolve_list_id( string $list_id_or_alias ): string|WP_Error {
		// Already a list ID
		if ( str_starts_with( $list_id_or_alias, 'rsl_' ) ) {
			return $list_id_or_alias;
		}

		// Search by alias
		$stripe = $this->client->stripe();

		try {
			$result = $stripe->radar->valueLists->all( [
				'alias' => $list_id_or_alias,
				'limit' => 1,
			] );

			if ( empty( $result->data ) ) {
				return new WP_Error(
					'list_not_found',
					sprintf( __( 'Value list with alias "%s" not found.', 'arraypress' ), $list_id_or_alias )
				);
			}

			return $result->data[0]->id;
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

}
