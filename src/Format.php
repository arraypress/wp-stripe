<?php
/**
 * Stripe Price Formatting
 *
 * Provides Stripe-aware price formatting by combining currency
 * formatting from the wp-currencies library with interval and
 * currency resolution from Parse. Handles all display patterns
 * needed for Stripe prices: one-time, recurring, and localized.
 *
 * This class owns all formatting logic that requires knowledge of
 * Stripe's billing model. Generic currency formatting lives in
 * ArrayPress\Currencies\Currency. Stripe object data extraction
 * lives in Parse. This class is the assembly layer.
 *
 * @package     ArrayPress\Stripe
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

namespace ArrayPress\Stripe;

defined( 'ABSPATH' ) || exit;

use ArrayPress\Currencies\Currency;

/**
 * Class Formatting
 *
 * Static helpers for displaying Stripe prices with optional
 * recurring interval text.
 *
 * Usage:
 *   // Format from raw values
 *   Format::price( 999, 'USD' );                          // $9.99
 *   Format::price_with_interval( 999, 'USD', 'month' );  // $9.99 per month
 *   Format::price_with_interval( 999, 'USD', 'month', 3 ); // $9.99 every 3 months
 *
 *   // Format directly from a Stripe price object
 *   Format::price_from_object( $price );
 *   Format::price_from_object( $line_item );
 *
 *   // Interval text only
 *   Format::interval_text( 'month' );      // per month
 *   Format::interval_text( 'month', 3 );   // every 3 months
 *
 * @since 1.0.0
 */
class Format {

	/** =========================================================================
	 *  Basic Price Formatting
	 *  ======================================================================== */

	/**
	 * Format a price amount with currency symbol.
	 *
	 * When a locale is provided, uses locale-aware formatting suitable
	 * for storefront display (requires the PHP intl extension, falls back
	 * to standard formatting if unavailable). Omit locale for admin
	 * contexts where simple symbol-prefix formatting is sufficient.
	 *
	 * @param int    $amount   Amount in smallest currency unit (cents, pence, etc).
	 * @param string $currency ISO 4217 currency code.
	 * @param string $locale   Optional locale override (e.g., 'de_DE'). Default ''.
	 *
	 * @return string Formatted amount with symbol (e.g., '$9.99' or '9,99 €').
	 * @since 1.0.0
	 */
	public static function price( int $amount, string $currency, string $locale = '' ): string {
		if ( ! empty( $locale ) ) {
			return Currency::format_localized( $amount, $currency, $locale );
		}

		return Currency::format( $amount, $currency );
	}

	/** =========================================================================
	 *  Price + Interval Formatting
	 *  ======================================================================== */

	/**
	 * Format a price with an optional recurring interval suffix.
	 *
	 * Appends human-readable interval text via Utilities::get_interval_text()
	 * to the formatted price. Returns the price alone when no interval is provided.
	 *
	 * When a locale is provided, uses locale-aware number formatting suitable
	 * for storefront display.
	 *
	 * @param int         $amount         Amount in smallest currency unit.
	 * @param string      $currency       ISO 4217 currency code.
	 * @param string|null $interval       Recurring interval: 'day', 'week', 'month', or 'year'.
	 * @param int         $interval_count Number of intervals between billings. Default 1.
	 * @param string      $locale         Optional locale override (e.g., 'de_DE'). Default ''.
	 *
	 * @return string Formatted price with optional interval (e.g., '$9.99 per month').
	 * @since 1.0.0
	 */
	public static function price_with_interval( int $amount, string $currency, ?string $interval = null, int $interval_count = 1, string $locale = '' ): string {
		$formatted = self::price( $amount, $currency, $locale );

		if ( empty( $interval ) ) {
			return $formatted;
		}

		return $formatted . ' ' . Utilities::get_interval_text( $interval, $interval_count );
	}

	/** =========================================================================
	 *  Object-Based Formatting
	 *  ======================================================================== */

	/**
	 * Format a price directly from a Stripe price or line item object.
	 *
	 * Resolves currency and interval data from the object via Parse,
	 * then formats the amount. Works with any Stripe object that carries
	 * unit_amount, currency, and optional recurring data.
	 *
	 * Compatible with: Stripe\Price, line item objects, subscription item
	 * objects, invoice line objects, and their serialized stdClass equivalents.
	 *
	 * @param object      $price          Stripe price or line item object.
	 * @param string      $currency       Optional currency override.
	 * @param string|null $interval       Optional interval override.
	 * @param int|null    $interval_count Optional interval count override.
	 *
	 * @return string|null Formatted price string, or null if no unit_amount.
	 * @since 1.0.0
	 */
	public static function price_from_object( object $price, string $currency = '', ?string $interval = null, ?int $interval_count = null ): ?string {
		$amount = $price->unit_amount ?? $price->amount ?? null;

		if ( ! is_numeric( $amount ) ) {
			return null;
		}

		if ( empty( $currency ) ) {
			$currency = Parse::currency( $price );
		}

		if ( $interval === null ) {
			$resolved       = Parse::interval_data( $price );
			$interval       = $resolved['interval'];
			$interval_count = $resolved['interval_count'];
		}

		return self::price_with_interval(
			(int) $amount,
			$currency,
			$interval,
			$interval_count ?? 1
		);
	}

	/**
	 * Format a price from a Stripe object as an HTML span.
	 *
	 * Wraps the formatted price in a <span class="price"> element.
	 * Intended for storefront or admin display contexts.
	 *
	 * @param object      $price          Stripe price or line item object.
	 * @param string      $currency       Optional currency override.
	 * @param string|null $interval       Optional interval override.
	 * @param int|null    $interval_count Optional interval count override.
	 *
	 * @return string|null HTML string or null if no unit_amount.
	 * @since 1.0.0
	 */
	public static function price_html( object $price, string $currency = '', ?string $interval = null, ?int $interval_count = null ): ?string {
		$formatted = self::price_from_object( $price, $currency, $interval, $interval_count );

		if ( $formatted === null ) {
			return null;
		}

		return sprintf( '<span class="price">%s</span>', esc_html( $formatted ) );
	}

}