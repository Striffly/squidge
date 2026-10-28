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

namespace Octopus\Encoders;

use Octopus\Log\Errors;
use Octopus\Log\Logger;
use Octopus\Media\Files;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class JPG extends AbstractEncoder
{
	/**
	 * The format of the encoder, see AbstractEncoder::FORMAT.
	 */
	const FORMAT = 'jpg';

	/**
	 * DEFAULT_QUALITY is the default maximum quality
	 * of the compressed JPG images.
	 */
	const DEFAULT_QUALITY = 80;

	/**
	 * Compresses a JPG file in place with jpegoptim,
	 * from its original kept as a backup.
	 *
	 * The library runs without shell, see
	 * Libraries::execute().
	 *
	 * @param $filepath
	 * @param $mime
	 * @param $args
	 * @return \Generator
	 * @since 1.0.0
	 */
	public static function convert($filepath, $mime, $args)
	{
		if ($mime != Files::JPG) {
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
		$original = Files::backup_path($filepath);
		if (!copy($original, $filepath)) {
			Errors::add(isset($args['id']) ? $args['id'] : 0, self::FORMAT, $filepath, 'cannot write ' . basename($filepath));
			return;
		}
		// Progressive: the browser shows the whole image blurred first, then sharpens it.
		if (yield from self::run(['--strip-all', '--all-progressive', '--overwrite', '--max=' . (int)$args['quality'], $filepath], $filepath, $args)) {
			Logger::info("Successfully compressed image JPG file: " . $filepath);
		} else {
			self::put_back($filepath, $args);
		}
	}

	/**
	 * Returns the command name of the service.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function cmd_name()
	{
		return "jpegoptim";
	}

	/**
	 * Returns the extension of the files made: none,
	 * the JPG files are compressed in place.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function extension()
	{
		return "";
	}
}
