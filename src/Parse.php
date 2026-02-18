<?php
/**
 * Stripe Object Parser
 *
 * Extracts and resolves normalised values from Stripe API objects. These helpers
 * encapsulate knowledge of Stripe's data shapes so consumer code doesn't need
 * to know how images, features, metadata, currencies, or intervals are stored.
 *
 * All methods accept plain stdClass objects (post-serialization) or live Stripe
 * SDK objects interchangeably.
 *
 * @package     ArrayPress\Stripe
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

namespace ArrayPress\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Class Parse
 *
 * Static helpers for extracting and resolving data from Stripe objects.
 *
 * Usage:
 *   $image    = Parse::product_image( $product );
 *   $features = Parse::product_features_json( $product );
 *   $metadata = Parse::metadata_json( $item );
 *   $currency = Parse::currency( $price );
 *   $interval = Parse::interval( $price );
 *
 * @since 1.0.0
 */
class Parse {

	/** =========================================================================
	 *  Images
	 *  ======================================================================== */

	/**
	 * Get the first image URL from a Stripe product object.
	 *
	 * Stripe products store images as an array of URLs. This returns
	 * the first entry, which is the primary display image. Returns an
	 * empty string if no images are present.
	 *
	 * @param object $product Stripe product object.
	 *
	 * @return string Image URL or empty string.
	 * @since 1.0.0
	 */
	public static function product_image( object $product ): string {
		if ( ! empty( $product->images ) && is_array( $product->images ) ) {
			return $product->images[0];
		}

		return '';
	}

	/**
	 * Get all image URLs from a Stripe product object.
	 *
	 * @param object $product Stripe product object.
	 *
	 * @return string[] Array of image URLs, empty array if none.
	 * @since 1.0.0
	 */
	public static function product_images( object $product ): array {
		if ( ! empty( $product->images ) && is_array( $product->images ) ) {
			return $product->images;
		}

		return [];
	}

	/** =========================================================================
	 *  Features
	 *  ======================================================================== */

	/**
	 * Extract marketing feature names from a Stripe product object.
	 *
	 * Returns a flat array of feature name strings from Stripe's
	 * marketing_features array (each entry is an object with a 'name' key).
	 *
	 * @param object $product Stripe product object.
	 *
	 * @return string[] Array of feature name strings, empty array if none.
	 * @since 1.0.0
	 */
	public static function product_features( object $product ): array {
		if ( empty( $product->marketing_features ) ) {
			return [];
		}

		return array_map( fn( $f ) => $f->name, $product->marketing_features );
	}

	/**
	 * Extract marketing feature names as a JSON string.
	 *
	 * Returns null if the product has no marketing features, making
	 * it safe to store directly in a nullable database column.
	 *
	 * @param object $product Stripe product object.
	 *
	 * @return string|null JSON-encoded array of feature names, or null.
	 * @since 1.0.0
	 */
	public static function product_features_json( object $product ): ?string {
		$features = self::product_features( $product );

		if ( empty( $features ) ) {
			return null;
		}

		return wp_json_encode( $features ) ?: null;
	}

	/** =========================================================================
	 *  Metadata
	 *  ======================================================================== */

	/**
	 * Extract metadata from a Stripe object as an associative array.
	 *
	 * Stripe metadata is returned as an object or array depending on
	 * how it was serialized. This normalizes it to a plain PHP array.
	 * Returns an empty array if metadata is absent or empty.
	 *
	 * @param object $item Any Stripe object with a metadata property.
	 *
	 * @return array Associative array of metadata key/value pairs.
	 * @since 1.0.0
	 */
	public static function metadata( object $item ): array {
		if ( empty( $item->metadata ) ) {
			return [];
		}

		return (array) $item->metadata;
	}

	/**
	 * Extract metadata from a Stripe object as a JSON string.
	 *
	 * Returns null if metadata is absent or empty, making it safe
	 * to store directly in a nullable database column.
	 *
	 * @param object $item Any Stripe object with a metadata property.
	 *
	 * @return string|null JSON-encoded metadata, or null.
	 * @since 1.0.0
	 */
	public static function metadata_json( object $item ): ?string {
		$metadata = self::metadata( $item );

		if ( empty( $metadata ) ) {
			return null;
		}

		return wp_json_encode( $metadata ) ?: null;
	}

	/** =========================================================================
	 *  Currency
	 *  ======================================================================== */

	/**
	 * Resolve the currency code from a Stripe API object or local DB row object.
	 *
	 * Checks multiple locations in priority order to cover all object types:
	 *
	 * - Flat DB row objects: direct `currency` property (prices table, orders table)
	 * - Stripe API prices/charges/invoices/sessions: direct `currency` property
	 * - Stripe API line items / subscription items: nested `price->currency`
	 * - Stripe API inline checkout line items: nested `price_data->currency`
	 *
	 * Falls back to the provided default if currency cannot be resolved.
	 *
	 * @param object $item    Stripe API object or local DB row object.
	 * @param string $default Default currency code if not found. Default 'USD'.
	 *
	 * @return string Uppercase ISO 4217 currency code.
	 * @since 1.0.0
	 */
	public static function currency( object $item, string $default = 'USD' ): string {
		// Direct currency property — covers both flat DB rows and most Stripe API objects
		if ( ! empty( $item->currency ) ) {
			return strtoupper( $item->currency );
		}

		// Nested price object (Stripe API line items, subscription items)
		if ( isset( $item->price->currency ) && ! empty( $item->price->currency ) ) {
			return strtoupper( $item->price->currency );
		}

		// Inline price_data (Stripe API checkout session line items built on the fly)
		if ( isset( $item->price_data->currency ) && ! empty( $item->price_data->currency ) ) {
			return strtoupper( $item->price_data->currency );
		}

		return strtoupper( $default );
	}

	/** =========================================================================
	 *  Recurring / Interval
	 *  ======================================================================== */

	/**
	 * Get the recurring interval from a Stripe API price object.
	 *
	 * For flat DB row objects use interval_data() which handles both shapes.
	 * Returns null for one-time prices that have no recurring object.
	 *
	 * @param object $price Stripe API price object.
	 *
	 * @return string|null Interval string ('day', 'week', 'month', 'year') or null.
	 * @since 1.0.0
	 */
	public static function interval( object $price ): ?string {
		if ( property_exists( $price, 'recurring_interval' ) ) {
			return $price->recurring_interval ?: null;
		}

		// Stripe API price object
		return $price->recurring->interval ?? null;
	}

	/**
	 * Get the recurring interval count from a Stripe API price object.
	 *
	 * For flat DB row objects use interval_data() which handles both shapes.
	 * Returns null for one-time prices.
	 *
	 * @param object $price Stripe API price object or flat DB row.
	 *
	 * @return int|null Interval count or null.
	 * @since 1.0.0
	 */
	public static function interval_count( object $price ): ?int {
		if ( property_exists( $price, 'recurring_interval_count' ) ) {
			return $price->recurring_interval_count !== null
				? (int) $price->recurring_interval_count
				: null;
		}

		// Stripe API price object
		return isset( $price->recurring->interval_count )
			? (int) $price->recurring->interval_count
			: null;
	}

	/**
	 * Resolve both interval and interval_count from any object.
	 *
	 * Handles all three data shapes in priority order:
	 *
	 * 1. Flat DB row objects (prices table):
	 *    `recurring_interval`, `recurring_interval_count`
	 *
	 * 2. Stripe API objects with a nested price (line items, subscription items):
	 *    `price->recurring->interval`, `price->recurring->interval_count`
	 *
	 * 3. Stripe API objects with inline price_data (checkout session line items):
	 *    `price_data->recurring->interval`, `price_data->recurring->interval_count`
	 *
	 * 4. Stripe API price objects directly:
	 *    `recurring->interval`, `recurring->interval_count`
	 *
	 * @param object $item Stripe API object or flat DB row object.
	 *
	 * @return array{interval: string|null, interval_count: int} Interval data.
	 * @since 1.0.0
	 */
	public static function interval_data( object $item ): array {
		// Flat DB row — prices table uses recurring_interval / recurring_interval_count columns
		if ( property_exists( $item, 'recurring_interval' ) ) {
			$interval = $item->recurring_interval ?: null;

			return [
				'interval'       => $interval,
				'interval_count' => $interval ? (int) ( $item->recurring_interval_count ?? 1 ) : 1,
			];
		}

		// Stripe API: nested price object (line items, subscription items)
		if ( isset( $item->price->recurring->interval ) ) {
			return [
				'interval'       => $item->price->recurring->interval,
				'interval_count' => (int) ( $item->price->recurring->interval_count ?? 1 ),
			];
		}

		// Stripe API: inline price_data (checkout session line items)
		if ( isset( $item->price_data->recurring->interval ) ) {
			return [
				'interval'       => $item->price_data->recurring->interval,
				'interval_count' => (int) ( $item->price_data->recurring->interval_count ?? 1 ),
			];
		}

		// Stripe API: price object directly
		if ( isset( $item->recurring->interval ) ) {
			return [
				'interval'       => $item->recurring->interval,
				'interval_count' => (int) ( $item->recurring->interval_count ?? 1 ),
			];
		}

		return [ 'interval' => null, 'interval_count' => 1 ];
	}

	/**
	 * Check whether an object represents a recurring price.
	 *
	 * Handles both flat DB row objects and Stripe API price objects.
	 *
	 * @param object $price Stripe API price object or flat DB row.
	 *
	 * @return bool True if the price has a recurring interval.
	 * @since 1.0.0
	 */
	public static function is_recurring( object $price ): bool {
		return ! empty( self::interval( $price ) );
	}

}