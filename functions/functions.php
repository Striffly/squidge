<?php

/**
 * Functions
 *
 * Helper template functions.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Functions
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use Squidge\Front\Display;
use Squidge\Package\Picture;

if (!function_exists('squidge_image')) {

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
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	function squidge_image($image_id, $class = '', $lazy = false)
	{
		// The Display filter would wrap the image depending on the Display tab:
		// squidge_image() always offers the AVIF and WebP versions.
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
