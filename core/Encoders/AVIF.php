<?php

/**
 * AVIF
 *
 * Avif is responsible for converting JPG and PNG
 * images to avif with the .avif extension using
 * the avifenc library, or the WordPress image
 * editor (GD or Imagick) where it is missing.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Encoders;

use Octopus\Log\Logger;
use Octopus\Media\Files;
use Octopus\Media\Optimiser;
use Octopus\System\Process;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class AVIF extends Encoder
{
	/**
	 * The format of the encoder, see Encoder::FORMAT.
	 */
	const FORMAT = 'avif';

	/**
	 * DEFAULT_QUALITY is the default image quality
	 * when converting to an AVIF image (0 to 100).
	 */
	const DEFAULT_QUALITY = 70;

	/**
	 * DEFAULT_SPEED is the default encoder speed (0
	 * slowest to 10 fastest). With aom, speeds 0 to 6
	 * make files of about the same size (0 is ~40x
	 * slower), 7 to 10 switch to fast modes making
	 * larger files: 6 is the best trade-off.
	 */
	const DEFAULT_SPEED = 6;

	/**
	 * EDITOR_MIME is the format of the image editor,
	 * without avifenc (see Encoder::editor()).
	 */
	const EDITOR_MIME = 'image/avif';

	/**
	 * Makes the AVIF version of a JPG or PNG file with
	 * avifenc, or the image editor without it, from
	 * its original.
	 *
	 * The library runs without shell, see
	 * Process::execute().
	 *
	 * @param $filepath
	 * @param $mime
	 * @param $args
	 * @return void
	 * @since 1.0.0
	 */
	public static function convert($filepath, $mime, $args)
	{
		if ($mime != Files::PNG && $mime != Files::JPG) {
			return;
		}
		$quality = self::int_arg($args, 'quality', 0, 100, self::DEFAULT_QUALITY);
		// Without avifenc, GD and Imagick encode at their own speed (6 for GD,
		// the default of OIO): the speed arg is left out.
		if (!self::installed()) {
			self::convert_with_editor($filepath, $mime, self::editor_quality($quality), $args);
			return;
		}
		$speed = self::int_arg($args, 'speed', 0, 10, self::DEFAULT_SPEED);
		$jobs = self::jobs();

		// libavif 1.0 replaced --min/--max with -q, older
		// versions only take the aom cq-level (0 best, 63 worst),
		// mapped the same way libavif maps -q. tune=ssim has no
		// effect on the still image mode of libavif 1.x.
		if (self::version() >= 1) {
			$options = ['-q', $quality];
		} else {
			$options = ['--min', 0, '--max', 63, '-a', 'end-usage=q', '-a', 'cq-level=' . (int)(((100 - $quality) * 63 + 50) / 100), '-a', 'tune=ssim'];
		}
		$options = array_merge($options, self::metadata_options());

		$original = self::original($filepath);
		$source = ImageEditor::readable($original, $mime);
		$output = $filepath . self::extension();
		if (self::run(array_merge($options, ['--speed', $speed, '--jobs', $jobs, $source, $output]), $filepath, $args)) {
			Logger::info("Successfully converted to AVIF file: " . $output);
			Files::remember(isset($args['id']) ? (int)$args['id'] : 0, $output);
		}
		ImageEditor::release($source, $original);
	}

	/**
	 * Returns the quality of the image editor making
	 * files close to the ones of avifenc at a quality.
	 * Both map it to the same quantizer, but avifenc
	 * gets more detail out of it, and under 90 GD
	 * keeps the colours at half resolution (4:2:0)
	 * where avifenc keeps the ones of the image: half
	 * in the JPGs made by WordPress, full (4:4:4) in a
	 * PNG or a JPG saved so. From 90 both keep them
	 * at full resolution. Measured with GD, sizes in
	 * KB, DSSIM to the source in brackets (lower is
	 * better):
	 *
	 *   image                       avifenc -q 70   GD 70          GD 75
	 *   photo 2800 px, 4:4:4        73.7 (0.0118)   57.5 (0.0125)  74.0 (0.0116)
	 *   scaled by WP 2560 px, 4:2:0 44.3 (0.0081)   45.7 (0.0084)  55.6 (0.0078)
	 *   thumbnail 1024 px, 4:2:0    15.8 (0.0080)   15.4 (0.0086)  17.7 (0.0079)
	 *
	 * 5 more gives the visual quality of avifenc (about
	 * the same at 60 and 80), 10 to 25 % heavier on the
	 * files made by WordPress, as heavy on a full
	 * resolution photo. On saturated details, e.g. a
	 * PNG graphic, GD stays blurrier in colour.
	 * Imagick (libheif) maps the quality as GD, its
	 * files are 1 to 3 % lighter on photos.
	 *
	 * @param int $quality The quality of avifenc, 0 to 100.
	 * @return int
	 * @since 1.0.0
	 */
	private static function editor_quality($quality)
	{
		return $quality < 90 ? min(89, $quality + 5) : $quality;
	}

	/**
	 * Returns the number of threads of avifenc: 4 on
	 * upload (about as fast as all cores on a photo), 2
	 * in background jobs, all in the CLI. The
	 * octopus_avif_jobs filter sets it by context (see
	 * Optimiser::$context).
	 *
	 * @return string A number, or "all".
	 * @since 1.0.0
	 */
	private static function jobs()
	{
		return Process::threads('octopus_avif_jobs', Optimiser::$context);
	}

	/**
	 * Returns the major version of avifenc, or 0 if
	 * it cannot be read (the legacy options work
	 * with every version).
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private static function version()
	{
		return (int)Process::version(self::cmd_name());
	}

	/**
	 * Returns the options leaving out the Exif and XMP
	 * metadata of the source: avifenc copies them by
	 * default, with the GPS position of a photo, while
	 * the JPG, PNG and WebP files are stripped. The
	 * colour profile is kept. Versions without these
	 * options do not copy the metadata.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function metadata_options()
	{
		static $options = null;
		if ($options === null) {
			list(, $help) = Process::execute([Process::binary(self::cmd_name()), '--help']);
			$options = [];
			foreach (['--ignore-exif', '--ignore-xmp'] as $option) {
				if (strpos($help, $option) !== false) {
					$options[] = $option;
				}
			}
		}
		return $options;
	}

	/**
	 * Returns an integer arg clamped between min and
	 * max, or the default if it is missing or not
	 * numeric.
	 *
	 * @param $args
	 * @param string $key
	 * @param int $min
	 * @param int $max
	 * @param int $default
	 * @return int
	 * @since 1.0.0
	 */
	private static function int_arg($args, $key, $min, $max, $default)
	{
		if (!isset($args[$key]) || !is_numeric($args[$key])) {
			return $default;
		}
		return max($min, min($max, (int)$args[$key]));
	}

	/**
	 * Returns the command name of the service.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function cmd_name()
	{
		return "avifenc";
	}

	/**
	 * Returns the extension added to the files made:
	 * photo.jpg.avif.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function extension()
	{
		return ".avif";
	}
}
