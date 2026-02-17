<?php
/**
 * Stripe Utilities
 *
 * Static utility methods for common Stripe operations including
 * billing period labels, Dashboard URL generation, image validation,
 * and ID helpers.
 *
 * Currency conversion and formatting is handled by the
 * arraypress/wp-currencies library which provides comprehensive
 * support for all 135 Stripe currencies with locale-aware formatting.
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

use finfo;

/**
 * Class Utilities
 *
 * Static helpers for Stripe billing, URL, and formatting operations.
 *
 * For currency conversion and formatting, use the Currency class directly:
 *
 *   use ArrayPress\Currencies\Currency;
 *
 *   Currency::to_smallest_unit( 9.99, 'USD' );  // 999
 *   Currency::from_smallest_unit( 999, 'USD' );  // 9.99
 *   Currency::format( 999, 'USD' );              // $9.99
 *   Currency::format_localized( 999, 'EUR' );    // 9,99 €
 *
 * @since 1.0.0
 */
class Utilities {

	/** =========================================================================
	 *  Billing Period Labels
	 *  ======================================================================== */

	/**
	 * Get the label for a recurring interval.
	 *
	 * Returns the human-readable label for a Stripe recurring interval.
	 *
	 * @param string $interval       Stripe interval: 'day', 'week', 'month', or 'year'.
	 * @param int    $interval_count Number of intervals between billings.
	 *
	 * @return string Formatted billing period label.
	 * @since 1.0.0
	 *
	 */
	public static function get_billing_label( string $interval, int $interval_count = 1 ): string {
		if ( $interval_count === 1 ) {
			$labels = [
				'day'   => __( 'Daily', 'arraypress' ),
				'week'  => __( 'Weekly', 'arraypress' ),
				'month' => __( 'Monthly', 'arraypress' ),
				'year'  => __( 'Yearly', 'arraypress' ),
			];

			return $labels[ $interval ] ?? ucfirst( $interval );
		}

		$plurals = [
			'day'   => sprintf( __( 'Every %d days', 'arraypress' ), $interval_count ),
			'week'  => sprintf( __( 'Every %d weeks', 'arraypress' ), $interval_count ),
			'month' => sprintf( __( 'Every %d months', 'arraypress' ), $interval_count ),
			'year'  => sprintf( __( 'Every %d years', 'arraypress' ), $interval_count ),
		];

		return $plurals[ $interval ] ?? sprintf(
			__( 'Every %d %ss', 'arraypress' ),
			$interval_count,
			$interval
		);
	}

	/**
	 * Get the short billing suffix for a recurring price.
	 *
	 * Returns abbreviated labels like "/mo", "/yr" for price display.
	 *
	 * @param string $interval       Stripe interval.
	 * @param int    $interval_count Interval count.
	 *
	 * @return string Short billing suffix (e.g., "/mo", "/yr").
	 * @since 1.0.0
	 *
	 */
	public static function get_billing_suffix( string $interval, int $interval_count = 1 ): string {
		if ( $interval_count === 1 ) {
			$suffixes = [
				'day'   => '/day',
				'week'  => '/wk',
				'month' => '/mo',
				'year'  => '/yr',
			];

			return $suffixes[ $interval ] ?? '/' . $interval;
		}

		return sprintf( '/%d %ss', $interval_count, substr( $interval, 0, 2 ) );
	}

	/**
	 * Get recurring interval label options.
	 *
	 * Returns an associative array suitable for dropdowns and filters.
	 *
	 * @param bool $include_one_time Include a "One-Time" option. Default false.
	 *
	 * @return array Interval key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function get_interval_options( bool $include_one_time = false ): array {
		$options = [];

		if ( $include_one_time ) {
			$options['one_time'] = __( 'One-Time', 'arraypress' );
		}

		$options['day']   = __( 'Daily', 'arraypress' );
		$options['week']  = __( 'Weekly', 'arraypress' );
		$options['month'] = __( 'Monthly', 'arraypress' );
		$options['year']  = __( 'Yearly', 'arraypress' );

		return $options;
	}

	/** =========================================================================
	 *  Dashboard URLs
	 *  ======================================================================== */

	/**
	 * Get a Stripe Dashboard URL for a resource.
	 *
	 * Builds a URL to view a specific object in the Stripe Dashboard,
	 * automatically handling the test/live mode path prefix.
	 *
	 * @param string $resource Resource type (e.g., 'prices', 'products', 'customers').
	 * @param string $id       Stripe resource ID.
	 * @param bool   $is_test  Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function dashboard_url( string $resource, string $id, bool $is_test = false ): string {
		$prefix = $is_test ? 'test/' : '';

		return 'https://dashboard.stripe.com/' . $prefix . $resource . '/' . $id;
	}

	/**
	 * Get a product dashboard URL.
	 *
	 * @param string $product_id Stripe product ID.
	 * @param bool   $is_test    Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function product_url( string $product_id, bool $is_test = false ): string {
		return self::dashboard_url( 'products', $product_id, $is_test );
	}

	/**
	 * Get a price dashboard URL.
	 *
	 * @param string $price_id Stripe price ID.
	 * @param bool   $is_test  Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function price_url( string $price_id, bool $is_test = false ): string {
		return self::dashboard_url( 'prices', $price_id, $is_test );
	}

	/**
	 * Get a customer dashboard URL.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param bool   $is_test     Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function customer_url( string $customer_id, bool $is_test = false ): string {
		return self::dashboard_url( 'customers', $customer_id, $is_test );
	}

	/**
	 * Get a payment dashboard URL.
	 *
	 * @param string $payment_id Payment intent ID.
	 * @param bool   $is_test    Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function payment_url( string $payment_id, bool $is_test = false ): string {
		return self::dashboard_url( 'payments', $payment_id, $is_test );
	}

	/**
	 * Get a subscription dashboard URL.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param bool   $is_test         Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function subscription_url( string $subscription_id, bool $is_test = false ): string {
		return self::dashboard_url( 'subscriptions', $subscription_id, $is_test );
	}

	/**
	 * Get an invoice dashboard URL.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 * @param bool   $is_test    Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function invoice_url( string $invoice_id, bool $is_test = false ): string {
		return self::dashboard_url( 'invoices', $invoice_id, $is_test );
	}

	/**
	 * Get a coupon dashboard URL.
	 *
	 * @param string $coupon_id Stripe coupon ID.
	 * @param bool   $is_test   Whether this is a test mode resource.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function coupon_url( string $coupon_id, bool $is_test = false ): string {
		return self::dashboard_url( 'coupons', $coupon_id, $is_test );
	}

	/** =========================================================================
	 *  Image Helpers
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

		// Check for localhost and private ranges
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

		// Check for .local and .test domains
		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine image file extension from an HTTP response.
	 *
	 * Checks the content-type header first, then falls back to
	 * binary inspection of the response body using finfo.
	 *
	 * Useful for sideloading images from Stripe's redirect-based
	 * image URLs where the URL doesn't contain a file extension.
	 *
	 * @param array  $response WP HTTP API response.
	 * @param string $body     Response body bytes.
	 *
	 * @return string File extension (e.g., 'jpg', 'png', 'webp').
	 * @since 1.0.0
	 *
	 */
	public static function get_image_extension( array $response, string $body ): string {
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		$map = [
			'image/jpeg'    => 'jpg',
			'image/jpg'     => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/svg+xml' => 'svg',
		];

		if ( isset( $map[ $content_type ] ) ) {
			return $map[ $content_type ];
		}

		// Fall back to binary inspection
		if ( class_exists( 'finfo' ) ) {
			$finfo = new finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->buffer( $body );

			if ( isset( $map[ $mime ] ) ) {
				return $map[ $mime ];
			}
		}

		return 'jpg';
	}

	/** =========================================================================
	 *  Stripe ID Helpers
	 *  ======================================================================== */

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
		];

		foreach ( $prefixes as $prefix => $type ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return $type;
			}
		}

		return null;
	}

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

}
