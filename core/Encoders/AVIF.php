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

use Octopus\Engines\ImageEditor;
use Octopus\Engines\ImageHeader;
use Octopus\Engines\Libraries;
use Octopus\Log\Logger;
use Octopus\Media\Files;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class AVIF extends AbstractEncoder
{
	/**
	 * The format of the encoder, see AbstractEncoder::FORMAT.
	 */
	const FORMAT = 'avif';

	/**
	 * The name of the format, see AbstractEncoder::TITLE.
	 */
	const TITLE = 'AVIF';

	/**
	 * The type of the files made, see AbstractEncoder::MIME.
	 */
	const MIME = 'image/avif';

	/**
	 * The types read, see AbstractEncoder::SOURCES.
	 */
	const SOURCES = Files::MIMES;


	/**
	 * Makes the AVIF version of a JPG or PNG file with
	 * avifenc, or the image editor without it, from
	 * its original.
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
		$quality = self::quality($args);
		// Without avifenc, GD and Imagick encode at their own speed (6 for GD,
		// the default of OIO): the speed arg is left out.
		if (!self::installed()) {
			self::convert_with_editor($filepath, $mime, self::editor_quality($quality), $args);
			return;
		}
		$speed = Settings::clean('avif_speed', $args['speed'] ?? null);
		$jobs = Libraries::threads();

		// libavif 1.0 replaced --min/--max with -q, older
		// versions only take the aom cq-level (0 best, 63 worst),
		// mapped the same way libavif maps -q. tune=ssim has no
		// effect on the still image mode of libavif 1.x.
		if (self::version() >= 1) {
			$options = ['-q', $quality];
		} else {
			$options = ['--min', 0, '--max', 63, '-a', 'end-usage=q', '-a', 'cq-level=' . (int)(((100 - $quality) * 63 + 50) / 100), '-a', 'tune=ssim'];
		}

		$original = self::original($filepath);
		$source = ImageEditor::readable($original, $mime);
		$output = Files::variant($filepath, self::extension());
		$options = array_merge($options, self::metadata_options(ImageHeader::profile($source) === 'RGB'));
		if (yield from self::run(array_merge($options, ['--speed', $speed, '--jobs', $jobs, $source, $output]), $filepath, $args, $jobs)) {
			Logger::info("Successfully converted to AVIF file: " . $output);
			Files::remember(self::id($args), $filepath);
		}
	}

	/**
	 * Returns the args of the encoder from the values
	 * of the settings: its quality and speed.
	 *
	 * @param array $values By name, see Settings::all().
	 * @return array
	 * @since 1.0.0
	 */
	public static function args($values)
	{
		return array_merge(parent::args($values), ['speed' => $values['avif_speed']]);
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
	 * Returns the major version of avifenc, or 0 if
	 * it cannot be read (the legacy options work
	 * with every version).
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private static function version()
	{
		return (int)Libraries::version(self::cmd_name());
	}

	/**
	 * Returns the options leaving out the metadata of
	 * the source: avifenc copies them by default. The
	 * Exif and XMP, with the GPS position of a photo,
	 * while the JPG, PNG and WebP files are stripped.
	 * The colour profile, unless the colours need it
	 * (see ImageHeader::profile()): an sRGB one weighs
	 * 3 KB for nothing. Versions without these options
	 * do not copy the Exif and XMP, and keep the
	 * profile.
	 *
	 * @param bool $profile Whether the colour profile is kept.
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function metadata_options($profile)
	{
		static $help = null;
		if ($help === null) {
			list(, $help) = Libraries::execute([Libraries::binary(self::cmd_name()), '--help']);
			$help = (string)$help;
		}
		$options = [];
		foreach (array_merge(['--ignore-exif', '--ignore-xmp'], $profile ? [] : ['--ignore-icc']) as $option) {
			if (strpos($help, $option) !== false) {
				$options[] = $option;
			}
		}
		return $options;
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
