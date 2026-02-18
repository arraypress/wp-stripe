<?php
/**
 * Stripe Labels
 *
 * Human-readable display strings for Stripe billing intervals
 * and recurring period descriptions.
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
 * Class Labels
 *
 * Static helpers that return translated display strings for
 * Stripe billing intervals in various formats and contexts.
 *
 * @since 1.0.0
 */
class Labels {

	/** =========================================================================
	 *  Billing Period Labels
	 *  ======================================================================== */

	/**
	 * Get the label for a recurring interval.
	 *
	 * Returns standalone human-readable labels suitable for admin UI
	 * dropdowns, status displays, and filter labels. For suffix-style
	 * text to append to a formatted price, use get_interval_text().
	 *
	 * @param string $interval       Stripe interval: 'day', 'week', 'month', or 'year'.
	 * @param int    $interval_count Number of intervals between billings. Default 1.
	 *
	 * @return string Formatted billing period label (e.g., 'Monthly', 'Every 3 months').
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
	 * Returns abbreviated labels like "/mo", "/yr" suitable for
	 * compact price displays (e.g., '$9.99/mo').
	 *
	 * @param string $interval       Stripe interval: 'day', 'week', 'month', or 'year'.
	 * @param int    $interval_count Interval count. Default 1.
	 *
	 * @return string Short billing suffix (e.g., '/mo', '/yr').
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
	 * Get human-readable interval text for appending to a price.
	 *
	 * Returns suffix-style text designed to follow a formatted price:
	 * '$9.99 per month', '$99.99 per year', '$4.99 every 3 months'.
	 *
	 * Distinct from get_billing_label() which returns standalone labels
	 * like 'Monthly' suitable for dropdowns and status displays.
	 *
	 * @param string $interval       Stripe interval: 'day', 'week', 'month', or 'year'.
	 * @param int    $interval_count Number of intervals between billings. Default 1.
	 *
	 * @return string Formatted interval text, or empty string for unknown intervals.
	 * @since 1.0.0
	 *
	 */
	public static function get_interval_text( string $interval, int $interval_count = 1 ): string {
		if ( $interval_count === 1 ) {
			return match ( $interval ) {
				'day'   => _x( 'per day', 'recurring interval', 'arraypress' ),
				'week'  => _x( 'per week', 'recurring interval', 'arraypress' ),
				'month' => _x( 'per month', 'recurring interval', 'arraypress' ),
				'year'  => _x( 'per year', 'recurring interval', 'arraypress' ),
				default => '',
			};
		}

		return match ( $interval ) {
			'day'   => sprintf( _n( 'every %d day', 'every %d days', $interval_count, 'arraypress' ), $interval_count ),
			'week'  => sprintf( _n( 'every %d week', 'every %d weeks', $interval_count, 'arraypress' ), $interval_count ),
			'month' => sprintf( _n( 'every %d month', 'every %d months', $interval_count, 'arraypress' ), $interval_count ),
			'year'  => sprintf( _n( 'every %d year', 'every %d years', $interval_count, 'arraypress' ), $interval_count ),
			default => '',
		};
	}

}