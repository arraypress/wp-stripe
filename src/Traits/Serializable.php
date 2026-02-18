<?php
/**
 * Serializable Trait
 *
 * Provides a list_serialized() method to any class that implements
 * a list() method returning the standard paginated result shape.
 *
 * Stripe SDK objects carry internal state — most notably RequestOptions —
 * that causes json_encode to fail or produce unexpected output. This
 * happens because the Stripe PHP SDK extends StripeObject, which stores
 * internal fields alongside the API data.
 *
 * The standard list() methods on all resource classes return raw SDK
 * objects. This is correct for direct API usage, but breaks down the
 * moment those objects cross a serialization boundary: REST responses,
 * transients, Action Scheduler payloads, or batch sync callbacks
 * (e.g., wp-inline-sync) all rely on json_encode producing clean output.
 *
 * This trait adds a list_serialized() companion to list() that strips
 * SDK internals via a JSON round-trip, returning plain stdClass objects
 * with the same data shape. It is intentionally kept as a private
 * helper so that each consuming class exposes list_serialized() as
 * a clean public method with its own typed docblock.
 *
 * Classes that use this trait:
 *   - Prices
 *   - Products
 *   - Customers
 *   - Subscriptions
 *   - Coupons
 *
 * Usage within a class:
 *
 *   use Traits\Serializable;
 *
 *   public function list_serialized( array $params = [] ): array|WP_Error {
 *       $result = $this->list( $params );
 *
 *       if ( is_wp_error( $result ) ) {
 *           return $result;
 *       }
 *
 *       return $this->serialize_result( $result );
 *   }
 *
 * @package     ArrayPress\Stripe\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

namespace ArrayPress\Stripe\Traits;

// Exit if accessed directly
use stdClass;
use Stripe\ApiResource;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Serializable
 *
 * Strips Stripe SDK internals from a paginated list result, returning
 * plain stdClass objects safe for JSON transport and storage.
 *
 * @since 1.0.0
 */
trait Serializable {

	/**
	 * Strip Stripe SDK internals from a paginated list result.
	 *
	 * Stripe SDK objects extend StripeObject and carry internal fields
	 * (RequestOptions, _opts, etc.) that break json_encode. This method
	 * normalizes each item in the result's 'items' array to a plain
	 * stdClass by round-tripping through JSON.
	 *
	 * The input array must follow the standard paginated result shape
	 * returned by all list() methods in this library:
	 *
	 *   [
	 *       'items'    => Stripe\ApiResource[],
	 *       'has_more' => bool,
	 *       'cursor'   => string,
	 *   ]
	 *
	 * The returned array has the same shape, with 'items' replaced by
	 * plain stdClass objects containing the same API data.
	 *
	 * Note: This is a lossy operation in that SDK-specific methods and
	 * type information are discarded. The resulting objects are read-only
	 * data bags and should not be passed back to Stripe API methods.
	 *
	 * @param array        $result           {
	 *                                       Paginated list result from any list() method.
	 *
	 * @type ApiResource[] $items            Array of Stripe SDK objects.
	 * @type bool          $has_more         Whether more pages exist.
	 * @type string        $cursor           Cursor ID for the next page.
	 *                                       }
	 *
	 * @return array {
	 *     Same structure with items as plain stdClass objects.
	 *
	 * @type stdClass[]    $items            Serialized plain objects.
	 * @type bool          $has_more         Whether more pages exist.
	 * @type string        $cursor           Cursor ID for the next page.
	 *                                       }
	 * @since  1.0.0
	 * @access private
	 *
	 */
	private function serialize_result( array $result ): array {
		$result['items'] = array_map(
			fn( $item ) => json_decode( json_encode( $item ) ),
			$result['items']
		);

		return $result;
	}

}