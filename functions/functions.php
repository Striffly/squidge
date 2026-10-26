<?php

/**
 * Functions
 *
 * Helper template functions.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Functions
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use Octopus\Delivery\Display;
use Octopus\Delivery\Picture;

if (!function_exists('octopus_image')) {

	/**
	 * Returns a <picture> element for an image of the media library: AVIF
	 * and WebP <source> elements (when their files exist), then the <img>
	 * with the srcset and sizes computed by WordPress as the fallback.
	 *
	 * If lazy is true, the data-src or data-srcset will be used instead.
	 * If a class is set, the class will be outputted on the <picture> element.
	 *
	 * @param $image_id
	 * @param string $class
	 * @param false $lazy
	 * @return string
	 * @since 1.0.0
	 */
	function octopus_image($image_id, $class = '', $lazy = false)
	{
		// The Display filter would wrap the image depending on the Display tab:
		// octopus_image() always offers the AVIF and WebP versions.
		Display::$bypass = true;
		$html = wp_get_attachment_image($image_id, 'full');
		Display::$bypass = false;
		if (!$html) {
			return '';
		}

		$html = Picture::render($html, (string)$class);
		if ($lazy) {
			$html = preg_replace('/\s(src|srcset)=/', ' data-$1=', $html);
		}
		return $html;
	}
}

if (!function_exists('octopus_image_sources')) {

	/**
	 * Returns the AVIF and WebP srcsets of a srcset or an
	 * image URL of the uploads folder, keyed by MIME type,
	 * best first, for a theme writing its own <source>
	 * elements. A size without a file in a format (e.g.
	 * deleted as heavier) keeps its JPG or PNG in that
	 * srcset, and a format without any file is left out.
	 *
	 * @param string $srcset
	 * @return array
	 * @since 1.0.0
	 */
	function octopus_image_sources($srcset)
	{
		return Picture::sources($srcset);
	}
}
