<?php
/**
 * Stripe Object Parser
 *
 * Extracts normalized values from Stripe API objects. These helpers
 * encapsulate knowledge of Stripe's data shapes so consumer code
 * doesn't need to know how images, features, or metadata are stored.
 *
 * All methods accept plain stdClass objects (post-serialization) or
 * live Stripe SDK objects interchangeably.
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
 * Static helpers for extracting data from Stripe objects.
 *
 * Usage:
 *   $image    = Parse::product_image( $product );
 *   $features = Parse::product_features_json( $product );
 *   $metadata = Parse::metadata_json( $item );
 *
 * @since 1.0.0
 */
class Parse {

	/** =========================================================================
	 *  Product
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
	 *  Recurring / Pricing
	 *  ======================================================================== */

	/**
	 * Get the recurring interval from a Stripe price object.
	 *
	 * Returns null for one-time prices that have no recurring object.
	 *
	 * @param object $price Stripe price object.
	 *
	 * @return string|null Interval string ('day', 'week', 'month', 'year') or null.
	 * @since 1.0.0
	 */
	public static function price_interval( object $price ): ?string {
		return $price->recurring->interval ?? null;
	}

	/**
	 * Get the recurring interval count from a Stripe price object.
	 *
	 * Returns null for one-time prices.
	 *
	 * @param object $price Stripe price object.
	 *
	 * @return int|null Interval count or null.
	 * @since 1.0.0
	 */
	public static function price_interval_count( object $price ): ?int {
		return isset( $price->recurring->interval_count )
			? (int) $price->recurring->interval_count
			: null;
	}

	/**
	 * Check whether a Stripe price is recurring.
	 *
	 * @param object $price Stripe price object.
	 *
	 * @return bool True if the price has a recurring interval.
	 * @since 1.0.0
	 */
	public static function is_recurring( object $price ): bool {
		return ! empty( $price->recurring->interval );
	}

}