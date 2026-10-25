<?php

/**
 * AVIF
 *
 * Avif is responsible for converting JPG and PNG
 * images to avif with the .avif extension using
 * the avifenc library.
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

class AVIF extends Service implements Convertor
{

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
	 * Compresses all image sizes have a png mime type
	 * with the given file path.
	 *
	 * exec is required to convert the WP image into
	 * a AVIF file.
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
		if ($mime != Mimes::PNG && $mime != Mimes::JPG) {
			return;
		}
		$quality = self::int_arg($args, 'quality', 0, 100, self::DEFAULT_QUALITY);
		$speed = self::int_arg($args, 'speed', 0, 10, self::DEFAULT_SPEED);
		$jobs = self::jobs();

		// libavif 1.0 replaced --min/--max with -q, older
		// versions only take the aom cq-level (0 best, 63 worst),
		// mapped the same way libavif maps -q. tune=ssim has no
		// effect on the still image mode of libavif 1.x.
		if (self::version() >= 1) {
			$options = sprintf('-q %d', $quality);
		} else {
			$options = sprintf('--min 0 --max 63 -a end-usage=q -a cq-level=%d -a tune=ssim', (int)(((100 - $quality) * 63 + 50) / 100));
		}
		$options .= self::metadata_options();

		$original = self::original($filepath);
		$source = self::readable_source($original, $mime);
		$output = $filepath . self::extension();
		if (self::run(sprintf('%s %s --speed %d --jobs %s %s %s', escapeshellarg(self::cmd_name()), $options, $speed, $jobs, escapeshellarg($source), escapeshellarg($output)), $filepath, $args)) {
			Logger::info("Successfully converted to AVIF file: " . $output);
			self::remember($args, $output);
		}
		self::release_source($source, $original);
	}

	/**
	 * Returns the number of threads of avifenc, the only
	 * library using several cores: 4 on upload (about
	 * as fast as all cores on a photo), 2 in background
	 * jobs, all in the CLI. The oio_avif_jobs filter
	 * sets it by context (see Optimiser::$context).
	 *
	 * @return string A number, or "all".
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function jobs()
	{
		$defaults = ['upload' => 4, 'background' => 2, 'cli' => 'all'];
		$default = isset($defaults[Optimiser::$context]) ? $defaults[Optimiser::$context] : 4;
		$jobs = apply_filters('oio_avif_jobs', $default, Optimiser::$context);
		return $jobs === 'all' ? 'all' : (string)max(1, (int)$jobs);
	}

	/**
	 * Returns the major version of avifenc, or 0 if
	 * it cannot be read (the legacy options work
	 * with every version).
	 *
	 * @return int
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function version()
	{
		static $major = null;
		if ($major === null) {
			exec(escapeshellarg(self::cmd_name()) . ' --version 2>&1', $output);
			$major = preg_match('/Version:\s*(\d+)\./', implode("\n", $output), $matches) ? (int)$matches[1] : 0;
		}
		return $major;
	}

	/**
	 * Returns the options leaving out the Exif and XMP
	 * metadata of the source: avifenc copies them by
	 * default, with the GPS position of a photo, while
	 * the JPG, PNG and WebP files are stripped. The
	 * colour profile is kept. Versions without these
	 * options do not copy the metadata.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function metadata_options()
	{
		static $options = null;
		if ($options === null) {
			exec(escapeshellarg(self::cmd_name()) . ' --help 2>&1', $output);
			$help = implode("\n", $output);
			$options = '';
			foreach (['--ignore-exif', '--ignore-xmp'] as $option) {
				if (strpos($help, $option) !== false) {
					$options .= ' ' . $option;
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
	 * @date 25/10/2026
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
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function cmd_name()
	{
		return "avifenc";
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
		return ".avif";
	}
}
