<?php
/**
 * Stripe Dashboard
 *
 * Generates Stripe Dashboard URLs for viewing resources in both
 * test and live modes.
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
 * Class Dashboard
 *
 * Static helpers for building Stripe Dashboard URLs.
 *
 * @since 1.0.0
 */
class Dashboard {

	/** =========================================================================
	 *  URL Builder
	 *  ======================================================================== */

	/**
	 * Get a Stripe Dashboard URL for a resource.
	 *
	 * Builds a URL to view a specific object in the Stripe Dashboard,
	 * automatically handling the test/live mode path prefix.
	 *
	 * @param string $resource Resource type (e.g., 'prices', 'products', 'customers').
	 * @param string $id       Stripe resource ID.
	 * @param bool   $is_test  Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function url( string $resource, string $id, bool $is_test = false ): string {
		$prefix = $is_test ? 'test/' : '';

		return 'https://dashboard.stripe.com/' . $prefix . $resource . '/' . $id;
	}

	/** =========================================================================
	 *  Resource URLs
	 *  ======================================================================== */

	/**
	 * Get a product dashboard URL.
	 *
	 * @param string $product_id Stripe product ID.
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function product( string $product_id, bool $is_test = false ): string {
		return self::url( 'products', $product_id, $is_test );
	}

	/**
	 * Get a price dashboard URL.
	 *
	 * @param string $price_id Stripe price ID.
	 * @param bool   $is_test  Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function price( string $price_id, bool $is_test = false ): string {
		return self::url( 'prices', $price_id, $is_test );
	}

	/**
	 * Get a customer dashboard URL.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param bool   $is_test     Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function customer( string $customer_id, bool $is_test = false ): string {
		return self::url( 'customers', $customer_id, $is_test );
	}

	/**
	 * Get a payment dashboard URL.
	 *
	 * @param string $payment_id Payment intent ID.
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function payment( string $payment_id, bool $is_test = false ): string {
		return self::url( 'payments', $payment_id, $is_test );
	}

	/**
	 * Get a subscription dashboard URL.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param bool   $is_test         Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function subscription( string $subscription_id, bool $is_test = false ): string {
		return self::url( 'subscriptions', $subscription_id, $is_test );
	}

	/**
	 * Get an invoice dashboard URL.
	 *
	 * @param string $invoice_id Stripe invoice ID.
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function invoice( string $invoice_id, bool $is_test = false ): string {
		return self::url( 'invoices', $invoice_id, $is_test );
	}

	/**
	 * Get a coupon dashboard URL.
	 *
	 * @param string $coupon_id Stripe coupon ID.
	 * @param bool   $is_test   Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 *
	 */
	public static function coupon( string $coupon_id, bool $is_test = false ): string {
		return self::url( 'coupons', $coupon_id, $is_test );
	}

	/**
	 * Get the Stripe Dashboard URL for a tax rate.
	 *
	 * @param string $tax_rate_id Stripe tax rate ID (txr_xxx).
	 * @param bool   $is_test     Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function tax_rate( string $tax_rate_id, bool $is_test = false ): string {
		return self::url( 'tax-rates', $tax_rate_id, $is_test );
	}

	/**
	 * Get a shipping rate dashboard URL.
	 *
	 * @param string $shipping_rate_id Stripe shipping rate ID (shr_xxx).
	 * @param bool   $is_test          Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function shipping_rate( string $shipping_rate_id, bool $is_test = false ): string {
		return self::url( 'shipping-rates', $shipping_rate_id, $is_test );
	}

	/**
	 * Get a feature dashboard URL.
	 *
	 * @param string $feature_id Stripe feature ID (feat_xxx).
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function feature( string $feature_id, bool $is_test = false ): string {
		return self::url( 'features', $feature_id, $is_test );
	}

	/**
	 * Get a dispute dashboard URL.
	 *
	 * @param string $dispute_id Stripe dispute ID (dp_xxx).
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function dispute( string $dispute_id, bool $is_test = false ): string {
		return self::url( 'disputes', $dispute_id, $is_test );
	}

	/**
	 * Get a payment link dashboard URL.
	 *
	 * @param string $payment_link_id Stripe payment link ID (plink_xxx).
	 * @param bool   $is_test         Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function payment_link( string $payment_link_id, bool $is_test = false ): string {
		return self::url( 'payment-links', $payment_link_id, $is_test );
	}

	/**
	 * Get a transfer dashboard URL.
	 *
	 * @param string $transfer_id Stripe transfer ID (tr_xxx).
	 * @param bool   $is_test     Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function transfer( string $transfer_id, bool $is_test = false ): string {
		return self::url( 'transfers', $transfer_id, $is_test );
	}

	/**
	 * Get a Connect account dashboard URL.
	 *
	 * @param string $account_id Stripe connected account ID (acct_xxx).
	 * @param bool   $is_test    Whether this is a test mode resource. Default false.
	 *
	 * @return string Stripe Dashboard URL.
	 * @since 1.0.0
	 */
	public static function connect_account( string $account_id, bool $is_test = false ): string {
		return self::url( 'connect/accounts', $account_id, $is_test );
	}

}