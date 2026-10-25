<?php

/**
 * JPG
 *
 * JPG is responsible for compressing JPG images
 * with the JPG mime type using the jpegoptim
 * library.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Services;

use OIO\Log\Errors;
use OIO\Log\Logger;
use OIO\Package\Convertor;
use OIO\Package\Service;
use OIO\Types\Mimes;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class JPG extends Service implements Convertor
{

	/**
	 * DEFAULT_QUALITY is the default image quality
	 * when processing a PNG image.
	 */
	const DEFAULT_QUALITY = 80;

	/**
	 * Compresses all image sizes have a png mime type
	 * with the given file path.
	 *
	 * The library runs without shell, see
	 * Service::execute().
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
		if ($mime != Mimes::JPG) {
			return;
		}
		if (!isset($args['quality'])) {
			$args['quality'] = self::DEFAULT_QUALITY;
		}
		// The file is optimised in place, its original is kept as a backup.
		// Start again from the original, so a JPG is never compressed twice.
		if (!self::backup($filepath, $args)) {
			return;
		}
		$original = self::backup_path($filepath);
		if (!copy($original, $filepath)) {
			Errors::add(isset($args['id']) ? $args['id'] : 0, self::cmd_name(), $filepath, 'cannot write ' . basename($filepath));
			return;
		}
		if (self::run(['--strip-all', '--overwrite', '--max=' . (int)$args['quality'], $filepath], $filepath, $args)) {
			Logger::info("Successfully compressed image JPG file: " . $filepath);
		} else {
			// Back to the original, without backup: it is retried next time.
			copy($original, $filepath);
			unlink($original);
		}
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
		return "jpegoptim";
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
