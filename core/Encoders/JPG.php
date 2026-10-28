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

use Octopus\Engines\ImageHeader;
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
	 * The name of the format, see AbstractEncoder::TITLE.
	 */
	const TITLE = 'JPG';

	/**
	 * The types read, see AbstractEncoder::SOURCES.
	 */
	const SOURCES = [Files::JPG];

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
		// The file is optimised in place, its original is kept as a backup.
		// Start again from the original, so a JPG is never compressed twice.
		if (!self::backup($filepath, $args)) {
			return;
		}
		$original = Files::backup_path($filepath);
		if (!copy($original, $filepath)) {
			self::error($args, $filepath, 'cannot write ' . basename($filepath));
			return;
		}
		// The metadata go, as with --strip-all, but the colour profile the colours
		// need (see ImageHeader::profile()): --keep-icc needs jpegoptim 1.4.7.
		$strip = ['--strip-com', '--strip-iptc', '--strip-xmp', '--strip-exif'];
		if (ImageHeader::profile($original) === null) {
			$strip[] = '--strip-icc';
		}
		// Progressive: the browser shows the whole image blurred first, then sharpens it.
		$compressed = yield from self::run(array_merge($strip, ['--all-progressive', '--overwrite', '--max=' . self::quality($args), $filepath]), $filepath, $args);
		if ($compressed && self::keep_orientation($filepath, ImageHeader::orientation($original), $args)) {
			Logger::info("Successfully compressed image JPG file: " . $filepath);
		} else {
			self::put_back($filepath, $args);
		}
	}

	/**
	 * Writes back the orientation of a sideways JPG,
	 * which the browsers turn by it, once jpegoptim
	 * stripped its Exif data: alone, without the GPS
	 * position and the camera (see
	 * ImageHeader::with_orientation()). Written next to
	 * the file, then moved in place at once, as the site
	 * may be serving it.
	 *
	 * @param string $filepath
	 * @param int $orientation See ImageHeader::orientation().
	 * @param array $args
	 * @return bool Whether the file has its orientation.
	 * @since 1.0.0
	 */
	private static function keep_orientation($filepath, $orientation, $args)
	{
		if ($orientation === 1) {
			return true;
		}
		$jpeg = file_get_contents($filepath);
		$temp = Files::temp_file('.jpg');
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Replaces the JPG the site may be serving at once, never half written.
		if (is_string($jpeg) && $temp !== null && file_put_contents($temp, ImageHeader::with_orientation($jpeg, $orientation)) !== false && rename($temp, $filepath)) {
			return true;
		}
		if ($temp !== null && file_exists($temp)) {
			wp_delete_file($temp);
		}
		self::error($args, $filepath, 'cannot write the orientation of the image');
		return false;
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
