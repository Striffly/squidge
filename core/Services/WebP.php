<?php

/**
 * WebP
 *
 * WebP is responsible for converting JPG and PNG
 * images to webp with the .webp extension using
 * the cwebp library.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Services;

use OIO\Log\Logger;
use OIO\Package\Convertor;
use OIO\Package\Optimiser;
use OIO\Package\Service;
use OIO\Types\Mimes;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class WebP extends Service implements Convertor
{

	/**
	 * DEFAULT_QUALITY is the default image quality
	 * when converting to a WebP image.
	 */
	const DEFAULT_QUALITY = 80;

	/**
	 * Compress all image sizes if they are jpg or png
	 * to webp with the given file path.
	 *
	 * The library runs without shell, see
	 * Service::execute().
	 *
	 * @param $filepath
	 * @param $mime
	 * @param $args
	 * @return void
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function convert($filepath, $mime, $args)
	{
		if ($mime != Mimes::PNG && $mime != Mimes::JPG) {
			return;
		}
		if (!isset($args['quality'])) {
			$args['quality'] = self::DEFAULT_QUALITY;
		}
		$original = self::original($filepath);
		$source = self::readable_source($original, $mime);
		$output = $filepath . self::extension();
		$options = ['-q', (int)$args['quality']];
		if (self::multithreading()) {
			$options[] = '-mt';
		}
		if (self::run(array_merge($options, [$source, '-o', $output]), $filepath, $args)) {
			Logger::info("Successfully converted to WebP file: " . $output);
			self::remember($args, $output);
		}
		self::release_source($source, $original);
	}

	/**
	 * Determines if cwebp uses a second thread: on
	 * upload and in the CLI, not in background jobs,
	 * where the requests of the site go first. The
	 * oio_webp_mt filter sets it by context (see
	 * Optimiser::$context).
	 *
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function multithreading()
	{
		return (bool)apply_filters('oio_webp_mt', Optimiser::$context !== 'background', Optimiser::$context);
	}

	/**
	 * Returns the command name of the service.
	 *
	 * @return string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function cmd_name()
	{
		return "cwebp";
	}

	/**
	 * Returns the extension to convert too.
	 *
	 * @return string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function extension()
	{
		return ".webp";
	}
}
