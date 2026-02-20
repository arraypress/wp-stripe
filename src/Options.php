<?php
/**
 * Stripe Options
 *
 * Provides associative arrays of Stripe enumerable values suitable
 * for use in admin dropdowns, filter selects, and form inputs.
 *
 * Each method returns a key => label array where the key is the
 * Stripe API value and the label is the translated display string.
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
 * Class Options
 *
 * Pre-built option arrays for common Stripe enumerables.
 *
 * @since 1.0.0
 */
class Options {

	/** =========================================================================
	 *  Intervals
	 *  ======================================================================== */

	/**
	 * Get recurring interval options.
	 *
	 * Returns interval key => label pairs suitable for billing period
	 * dropdowns. Optionally prepends a "One-Time" entry for forms that
	 * need to accommodate both recurring and one-time prices.
	 *
	 * @param bool $include_one_time Include a "One-Time" option. Default false.
	 *
	 * @return array Interval key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function intervals( bool $include_one_time = false ): array {
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
	 *  Price Types
	 *  ======================================================================== */

	/**
	 * Get price type options.
	 *
	 * Returns the two Stripe price types suitable for filtering
	 * or segmenting price lists.
	 *
	 * @return array Price type key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function price_types(): array {
		return [
			'one_time'  => __( 'One-Time', 'arraypress' ),
			'recurring' => __( 'Recurring', 'arraypress' ),
		];
	}

	/** =========================================================================
	 *  Coupon Durations
	 *  ======================================================================== */

	/**
	 * Get coupon duration options.
	 *
	 * Returns the three Stripe coupon duration types. 'repeating' applies
	 * for a fixed number of billing periods defined by duration_in_months.
	 *
	 * @return array Duration key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function coupon_durations(): array {
		return [
			'once'      => __( 'Once', 'arraypress' ),
			'repeating' => __( 'Repeating', 'arraypress' ),
			'forever'   => __( 'Forever', 'arraypress' ),
		];
	}

	/** =========================================================================
	 *  Subscription Statuses
	 *  ======================================================================== */

	/**
	 * Get subscription status options.
	 *
	 * Returns all possible Stripe subscription statuses. Useful for
	 * status filter dropdowns in subscription management screens.
	 *
	 * @param bool $include_all Prepend an "All Statuses" option. Default false.
	 *
	 * @return array Status key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function subscription_statuses( bool $include_all = false ): array {
		$options = [];

		if ( $include_all ) {
			$options[''] = __( 'All Statuses', 'arraypress' );
		}

		$options['trialing']           = __( 'Trialing', 'arraypress' );
		$options['active']             = __( 'Active', 'arraypress' );
		$options['past_due']           = __( 'Past Due', 'arraypress' );
		$options['unpaid']             = __( 'Unpaid', 'arraypress' );
		$options['paused']             = __( 'Paused', 'arraypress' );
		$options['canceled']           = __( 'Canceled', 'arraypress' );
		$options['incomplete']         = __( 'Incomplete', 'arraypress' );
		$options['incomplete_expired'] = __( 'Incomplete Expired', 'arraypress' );

		return $options;
	}

	/** =========================================================================
	 *  Invoice Statuses
	 *  ======================================================================== */

	/**
	 * Get invoice status options.
	 *
	 * Returns all possible Stripe invoice statuses. Useful for
	 * status filter dropdowns in invoice management screens.
	 *
	 * @param bool $include_all Prepend an "All Statuses" option. Default false.
	 *
	 * @return array Status key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function invoice_statuses( bool $include_all = false ): array {
		$options = [];

		if ( $include_all ) {
			$options[''] = __( 'All Statuses', 'arraypress' );
		}

		$options['draft']         = __( 'Draft', 'arraypress' );
		$options['open']          = __( 'Open', 'arraypress' );
		$options['paid']          = __( 'Paid', 'arraypress' );
		$options['uncollectible'] = __( 'Uncollectible', 'arraypress' );
		$options['void']          = __( 'Void', 'arraypress' );

		return $options;
	}

	/** =========================================================================
	 *  Charge Statuses
	 *  ======================================================================== */

	/**
	 * Get charge status options.
	 *
	 * Returns all possible Stripe charge statuses. Useful for
	 * status filter dropdowns in payment and order management screens.
	 *
	 * @param bool $include_all Prepend an "All Statuses" option. Default false.
	 *
	 * @return array Status key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function charge_statuses( bool $include_all = false ): array {
		$options = [];

		if ( $include_all ) {
			$options[''] = __( 'All Statuses', 'arraypress' );
		}

		$options['succeeded'] = __( 'Succeeded', 'arraypress' );
		$options['pending']   = __( 'Pending', 'arraypress' );
		$options['failed']    = __( 'Failed', 'arraypress' );

		return $options;
	}

	/** =========================================================================
	 *  Tax Categories
	 *  ======================================================================== */

	/**
	 * Get simplified tax category options for product forms.
	 *
	 * Maps human-readable category labels to the most appropriate Stripe
	 * tax code. Abstracts away the raw Stripe PTC complexity — the stored
	 * value is the Stripe tax code, but the label is what the merchant sees.
	 *
	 * Modelled after Lemon Squeezy's approach: sensible categories that cover
	 * the vast majority of digital product and service businesses without
	 * overwhelming merchants with 200+ raw tax codes.
	 *
	 * @return array Tax code key => human-readable label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function tax_categories(): array {
		return [

			// Catch-alls
			'txcd_00000000' => __( 'Nontaxable', 'arraypress' ),
			'txcd_10000000' => __( 'Digital Goods or Services', 'arraypress' ),
			'txcd_20030000' => __( 'General Services', 'arraypress' ),

			// Software
			'txcd_10103000' => __( 'Software as a Service (SaaS) - Personal Use', 'arraypress' ),
			'txcd_10103001' => __( 'Software as a Service (SaaS) - Business Use', 'arraypress' ),
			'txcd_10202000' => __( 'Software - Downloadable', 'arraypress' ),
			'txcd_10202003' => __( 'Software - Downloadable - Business Use', 'arraypress' ),

			// Audio
			'txcd_10401100' => __( 'Digital Audio - Download', 'arraypress' ),
			'txcd_10401000' => __( 'Digital Audio - Stream', 'arraypress' ),
			'txcd_10401200' => __( 'Digital Audio - Subscription', 'arraypress' ),

			// Video
			'txcd_10402100' => __( 'Digital Video - Download', 'arraypress' ),
			'txcd_10402000' => __( 'Digital Video - Stream', 'arraypress' ),
			'txcd_10402200' => __( 'Digital Video - Subscription', 'arraypress' ),
			'txcd_10402300' => __( 'Digital Video - Live Stream', 'arraypress' ),

			// Books & Publishing
			'txcd_10302000' => __( 'eBook', 'arraypress' ),
			'txcd_10301000' => __( 'Audiobook', 'arraypress' ),
			'txcd_10303000' => __( 'Digital Magazine / Periodical', 'arraypress' ),
			'txcd_10503000' => __( 'Digital Newsletter / Document', 'arraypress' ),

			// Images & Design
			'txcd_10501000' => __( 'Digital Photography / Images', 'arraypress' ),
			'txcd_10505001' => __( 'Digital Artwork / Graphics', 'arraypress' ),

			// Games
			'txcd_10201000' => __( 'Video Game - Download', 'arraypress' ),
			'txcd_10201003' => __( 'Video Game - Stream', 'arraypress' ),
			'txcd_10201002' => __( 'Video Game - Subscription', 'arraypress' ),

			// Courses & Training
			'txcd_20060158' => __( 'Online Course - On Demand (Streamed)', 'arraypress' ),
			'txcd_20060258' => __( 'Online Course - On Demand (Download)', 'arraypress' ),
			'txcd_20060045' => __( 'Online Course - Live Virtual', 'arraypress' ),
			'txcd_20060058' => __( 'Online Course - Self-Study', 'arraypress' ),
			'txcd_20060052' => __( 'Educational Services', 'arraypress' ),

			// Website & Information Services
			'txcd_10701100' => __( 'Website Hosting', 'arraypress' ),
			'txcd_10701200' => __( 'Website Design', 'arraypress' ),
			'txcd_10701300' => __( 'Website Data Processing', 'arraypress' ),
			'txcd_10701410' => __( 'Information Services - Personal Use', 'arraypress' ),
			'txcd_10701400' => __( 'Information Services - Business Use', 'arraypress' ),

			// Professional Services
			'txcd_20060000' => __( 'Professional Services', 'arraypress' ),
			'txcd_20060048' => __( 'Consulting', 'arraypress' ),
			'txcd_20060017' => __( 'Technical Support', 'arraypress' ),
			'txcd_20060001' => __( 'Accounting', 'arraypress' ),
			'txcd_20060054' => __( 'Legal Services', 'arraypress' ),
			'txcd_20060055' => __( 'Marketing Services', 'arraypress' ),

			// Other
			'txcd_10502000' => __( 'Gift Card', 'arraypress' ),
		];
	}

	/**
	 * Get raw Stripe tax codes eligible for Managed Payments.
	 *
	 * Returns only the tax codes Stripe has approved for use with
	 * Managed Payments (their merchant-of-record feature). Use this
	 * instead of tax_categories() when Managed Payments is enabled,
	 * as only these specific codes are accepted.
	 *
	 * @return array Tax code key => label pairs.
	 * @since 1.0.0
	 *
	 */
	public static function managed_payments_tax_codes(): array {
		return [

			// Video Games
			'txcd_10201000' => __( 'Video Games - Downloaded - Non Subscription - Permanent Rights', 'arraypress' ),
			'txcd_10201001' => __( 'Video Games - Downloaded - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10201003' => __( 'Video Games - Streamed - Non Subscription - Limited Rights', 'arraypress' ),

			// Digital Audio Works
			'txcd_10401000' => __( 'Digital Audio Works - Streamed - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10401001' => __( 'Digital Audio Works - Downloaded - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10401100' => __( 'Digital Audio Works - Downloaded - Non Subscription - Permanent Rights', 'arraypress' ),
			'txcd_10401200' => __( 'Digital Audio Works - Streamed - Subscription - Conditional Rights', 'arraypress' ),

			// Digital Audio Visual Works
			'txcd_10402000' => __( 'Digital Audio Visual Works - Streamed - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10402100' => __( 'Digital Audio Visual Works - Downloaded - Non Subscription - Permanent Rights', 'arraypress' ),
			'txcd_10402110' => __( 'Digital Audio Visual Works - Downloaded - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10402200' => __( 'Digital Audio Visual Works - Streamed - Subscription - Conditional Rights', 'arraypress' ),

			// Digital Finished Artwork
			'txcd_10505000' => __( 'Digital Finished Artwork - Downloaded - Non Subscription - Limited Rights', 'arraypress' ),
			'txcd_10505001' => __( 'Digital Finished Artwork - Downloaded - Non Subscription - Permanent Rights', 'arraypress' ),
			'txcd_10505002' => __( 'Digital Finished Artwork - Downloaded - Subscription - Conditional Rights', 'arraypress' ),

			// Information Services
			'txcd_10701000' => __( 'Website Advertising', 'arraypress' ),
			'txcd_10701400' => __( 'Website Information Services - Business Use', 'arraypress' ),
			'txcd_10701401' => __( 'Website Information Services - Personal Use', 'arraypress' ),
			'txcd_10701410' => __( 'Electronically Delivered Information Services - Business Use', 'arraypress' ),
			'txcd_10701411' => __( 'Electronically Delivered Information Services - Personal Use', 'arraypress' ),

			// Bundles
			'txcd_10804001' => __( 'Digital Audio Visual Works - Bundle - Downloaded Permanent + Streamed Subscription', 'arraypress' ),
			'txcd_10804002' => __( 'Digital Audio Visual Works - Bundle - Downloaded Limited + Streamed Non Subscription', 'arraypress' ),
			'txcd_10804003' => __( 'Digital Audio Visual Works - Bundle - Downloaded Permanent + Streamed Non Subscription', 'arraypress' ),
			'txcd_10804010' => __( 'Digital Audio Works - Bundle - Downloaded Permanent + Streamed Subscription', 'arraypress' ),

			// Training
			'txcd_20060045' => __( 'Training Services - Live Virtual', 'arraypress' ),
		];
	}

	/** =========================================================================
	 *  Label Lookups
	 *  ======================================================================== */

	/**
	 * Get the label for a recurring interval key.
	 *
	 * @param string $key     Interval key (e.g., 'month', 'one_time').
	 * @param bool   $include_one_time Whether the one-time option is available.
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_interval_label( string $key, bool $include_one_time = true ): string {
		return self::intervals( $include_one_time )[ $key ] ?? $key;
	}

	/**
	 * Get the label for a price type key.
	 *
	 * @param string $key Price type key (e.g., 'one_time', 'recurring').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_price_type_label( string $key ): string {
		return self::price_types()[ $key ] ?? $key;
	}

	/**
	 * Get the label for a coupon duration key.
	 *
	 * @param string $key Duration key (e.g., 'once', 'repeating', 'forever').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_coupon_duration_label( string $key ): string {
		return self::coupon_durations()[ $key ] ?? $key;
	}

	/**
	 * Get the label for a subscription status key.
	 *
	 * @param string $key Status key (e.g., 'active', 'past_due').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_subscription_status_label( string $key ): string {
		return self::subscription_statuses()[ $key ] ?? $key;
	}

	/**
	 * Get the label for an invoice status key.
	 *
	 * @param string $key Status key (e.g., 'paid', 'void').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_invoice_status_label( string $key ): string {
		return self::invoice_statuses()[ $key ] ?? $key;
	}

	/**
	 * Get the label for a charge status key.
	 *
	 * @param string $key Status key (e.g., 'succeeded', 'failed').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_charge_status_label( string $key ): string {
		return self::charge_statuses()[ $key ] ?? $key;
	}

	/**
	 * Get the label for a tax category code.
	 *
	 * @param string $key Stripe tax code (e.g., 'txcd_10103000').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_tax_category_label( string $key ): string {
		return self::tax_categories()[ $key ] ?? $key;
	}

	/**
	 * Get the label for a managed payments tax code.
	 *
	 * @param string $key Stripe tax code (e.g., 'txcd_10201000').
	 *
	 * @return string Label or the key itself if not found.
	 * @since 1.0.0
	 */
	public static function get_managed_payments_tax_code_label( string $key ): string {
		return self::managed_payments_tax_codes()[ $key ] ?? $key;
	}

}