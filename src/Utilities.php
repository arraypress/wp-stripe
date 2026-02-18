<?php
/**
 * Stripe Utilities
 *
 * Miscellaneous static helpers that don't belong to a more specific class.
 *
 * For billing labels use Labels, for Dashboard URLs use Dashboard,
 * for boolean checks use Validate, and for option arrays use Options.
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
 * @since 1.0.0
 */
class Utilities {

	/** =========================================================================
	 *  Image Helpers
	 *  ======================================================================== */

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

}