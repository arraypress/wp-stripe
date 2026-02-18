<?php
/**
 * Stripe Validate
 *
 * Boolean validation helpers for Stripe IDs, API keys, and URLs.
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

/**
 * Class Validate
 *
 * Static boolean checks for Stripe data.
 *
 * @since 1.0.0
 */
class Validate {

	/** =========================================================================
	 *  Stripe IDs
	 *  ======================================================================== */

	/**
	 * Check if a string looks like a valid Stripe ID.
	 *
	 * Validates format only — does not verify the ID exists in Stripe.
	 *
	 * @param string $id     Stripe ID to validate.
	 * @param string $prefix Expected prefix (e.g., 'prod_', 'price_'). Optional.
	 *
	 * @return bool True if the ID matches expected Stripe ID format.
	 * @since 1.0.0
	 *
	 */
	public static function is_valid_id( string $id, string $prefix = '' ): bool {
		if ( empty( $id ) ) {
			return false;
		}

		if ( $prefix && ! str_starts_with( $id, $prefix ) ) {
			return false;
		}

		// Stripe IDs are alphanumeric with underscores, typically 20-30 chars
		return (bool) preg_match( '/^[a-zA-Z]+_[a-zA-Z0-9]{14,}$/', $id );
	}

	/**
	 * Determine the type of a Stripe ID from its prefix.
	 *
	 * @param string $id Stripe ID string.
	 *
	 * @return string|null Resource type or null if unrecognized.
	 * @since 1.0.0
	 *
	 */
	public static function get_id_type( string $id ): ?string {
		$prefixes = [
			'prod_'  => 'product',
			'price_' => 'price',
			'cus_'   => 'customer',
			'sub_'   => 'subscription',
			'si_'    => 'subscription_item',
			'pi_'    => 'payment_intent',
			'ch_'    => 'charge',
			'in_'    => 'invoice',
			'ii_'    => 'invoice_item',
			'cs_'    => 'checkout_session',
			're_'    => 'refund',
			'evt_'   => 'event',
			'pm_'    => 'payment_method',
			'seti_'  => 'setup_intent',
			'promo_' => 'promotion_code',
			'bps_'   => 'portal_session',
			'dp_'    => 'dispute',
			'acct_'  => 'account',
			'plink_' => 'payment_link',
		];

		foreach ( $prefixes as $prefix => $type ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return $type;
			}
		}

		return null;
	}

	/** =========================================================================
	 *  API Keys
	 *  ======================================================================== */

	/**
	 * Check if a string looks like a Stripe test mode API key.
	 *
	 * @param string $key API key to check.
	 *
	 * @return bool True if the key has a test mode prefix.
	 * @since 1.0.0
	 *
	 */
	public static function is_test_key( string $key ): bool {
		return str_starts_with( $key, 'sk_test_' ) || str_starts_with( $key, 'pk_test_' );
	}

	/**
	 * Check if a string looks like a Stripe live mode API key.
	 *
	 * @param string $key API key to check.
	 *
	 * @return bool True if the key has a live mode prefix.
	 * @since 1.0.0
	 *
	 */
	public static function is_live_key( string $key ): bool {
		return str_starts_with( $key, 'sk_live_' ) || str_starts_with( $key, 'pk_live_' );
	}

	/** =========================================================================
	 *  URLs
	 *  ======================================================================== */

	/**
	 * Check if a URL is publicly accessible.
	 *
	 * Stripe requires public URLs for product images. This checks
	 * that the URL doesn't point to localhost or private networks.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool True if the URL is publicly accessible.
	 * @since 1.0.0
	 *
	 */
	public static function is_public_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		$private_patterns = [
			'localhost',
			'127.0.0.1',
			'::1',
			'10.',
			'172.16.',
			'172.17.',
			'172.18.',
			'172.19.',
			'172.20.',
			'172.21.',
			'172.22.',
			'172.23.',
			'172.24.',
			'172.25.',
			'172.26.',
			'172.27.',
			'172.28.',
			'172.29.',
			'172.30.',
			'172.31.',
			'192.168.',
		];

		foreach ( $private_patterns as $pattern ) {
			if ( str_starts_with( $host, $pattern ) || $host === $pattern ) {
				return false;
			}
		}

		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
			return false;
		}

		return true;
	}

}