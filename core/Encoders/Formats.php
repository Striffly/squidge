<?php

/**
 * Formats
 *
 * The formats OIO makes, each with its encoder: the
 * one list the rest of OIO goes through, so that a
 * format is described in its encoder only (see
 * AbstractEncoder).
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Encoders;

use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Formats
{
	/**
	 * The encoders by format: the JPG and PNG images
	 * compressed in place, then their WebP and AVIF
	 * versions, each kept only when lighter than the
	 * image and the versions before it.
	 *
	 * @var string[]
	 */
	const ENCODERS = [
		'jpg' => JPG::class,
		'png' => PNG::class,
		'webp' => WebP::class,
		'avif' => AVIF::class,
	];

	/**
	 * Returns the encoders making a version of the
	 * images, by format: the WebP, then the AVIF. The
	 * browsers are offered them the other way round,
	 * the lightest first.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function variants()
	{
		return array_filter(self::ENCODERS, function ($encoder) {
			return $encoder::extension() !== '';
		});
	}

	/**
	 * Returns the encoders compressing the images in
	 * place, by format: JPG and PNG.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function compressions()
	{
		return array_diff_key(self::ENCODERS, self::variants());
	}

	/**
	 * Returns the format compressing the images of a
	 * type in place, e.g. png for image/png, null if
	 * none does.
	 *
	 * @param string $mime
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function compression($mime)
	{
		foreach (self::compressions() as $format => $encoder) {
			if (in_array($mime, $encoder::SOURCES, true)) {
				return $format;
			}
		}
		return null;
	}

	/**
	 * Returns the args of each format from the
	 * settings, see AbstractEncoder::args(): the
	 * enabled formats only, or all of them.
	 *
	 * @param bool $enabled_only
	 * @return array
	 * @since 1.0.0
	 */
	public static function settings($enabled_only = true)
	{
		$settings = [];
		foreach (self::ENCODERS as $format => $encoder) {
			if (!$enabled_only || Settings::get($format . '_enable')) {
				$settings[$format] = $encoder::args();
			}
		}
		return $settings;
	}
}
