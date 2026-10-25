<?php

/**
 * Display
 *
 * Display offers the AVIF and WebP versions made by
 * Squidge, if enabled in the Display tab: for the
 * images of the post content and the ones rendered
 * by wp_get_attachment_image().
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Front
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Front;

use Squidge\Admin\Fields;
use Squidge\Package\Picture;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Display
{
	/**
	 * Skips the filters, for squidge_image() which
	 * rewrites the image itself.
	 *
	 * @var bool
	 */
	public static $bypass = false;

	/**
	 * Adds the image filters.
	 *
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		add_filter('wp_content_img_tag', [$this, 'filter'], 10, 1);
		add_filter('wp_get_attachment_image', [$this, 'filter'], 10, 1);
	}

	/**
	 * Wraps an <img> in a <picture> with its AVIF and
	 * WebP versions, on the site only (not in the
	 * admin, except AJAX requests which may render
	 * content).
	 *
	 * @param string $html
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function filter($html)
	{
		if (self::$bypass || (is_admin() && !wp_doing_ajax()) || !Fields::get('squidge_display_formats')) {
			return $html;
		}
		return Picture::render($html);
	}
}
