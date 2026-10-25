<?php

/**
 * PNG
 *
 * PNG is responsible for compressing PNG images
 * with the PNG mime type using the optipng
 * library.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Services;

use Squidge\Log\Logger;
use Squidge\Package\Convertor;
use Squidge\Package\Service;
use Squidge\Types\Mimes;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class PNG extends Service implements Convertor
{

	/**
	 * DEFAULT_OPTIMIZATION is the default optipng
	 * optimisation level (-o2) when processing a
	 * PNG image.
	 */
	const DEFAULT_OPTIMIZATION = 2;

	/**
	 * Compresses all image sizes have a jpg mime type
	 * with the given file path.
	 *
	 * exec is required to compress the WP PNG image.
	 *
	 * @param $filepath
	 * @param $mime
	 * @param $args
	 * @return void
	 * @since 0.1.2
	 * @date 05/12/2021
	 */
	public static function convert($filepath, $mime, $args)
	{
		if ($mime != Mimes::PNG) {
			return;
		}
		// The file is optimised in place from its original, kept as a backup.
		if (!self::backup($filepath, $args)) {
			return;
		}
		$original = self::backup_path($filepath);
		// optipng keeps a .bak of an output file it overwrites: remove it first.
		unlink($filepath);
		if (self::run(sprintf('%s -clobber -strip all -o %d -out %s %s', escapeshellarg(self::cmd_name()), self::optimization_level($args), escapeshellarg($filepath), escapeshellarg($original)), $filepath, $args)) {
			Logger::info("Successfully compressed image PNG file: " . $filepath);
		} else {
			// Back to the original, without backup: it is retried next time.
			copy($original, $filepath);
			unlink($original);
		}
	}

	/**
	 * Returns the settings of a run as a string.
	 *
	 * @param array $args
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function signature($args)
	{
		return 'level:' . self::optimization_level($args);
	}

	/**
	 * Returns the optipng level (0 to 7) from the
	 * optimization arg, which can be "o3" (CLI)
	 * or "3" (options page).
	 *
	 * @param $args
	 * @return int
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function optimization_level($args)
	{
		$level = isset($args['optimization']) ? ltrim((string)$args['optimization'], 'oO') : '';
		if (!ctype_digit($level)) {
			return self::DEFAULT_OPTIMIZATION;
		}
		return min(7, (int)$level);
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
		return "optipng";
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
		return "";
	}
}
