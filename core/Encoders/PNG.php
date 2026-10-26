<?php

/**
 * PNG
 *
 * PNG is responsible for compressing PNG images
 * with the PNG mime type using the oxipng library,
 * or optipng, after the pngquant library if the
 * lossy compression is enabled.
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

class PNG extends Encoder
{
	/**
	 * The format of the encoder, see Encoder::FORMAT.
	 */
	const FORMAT = 'png';

	/**
	 * DEFAULT_OPTIMIZATION is the default optipng
	 * optimisation level (-o2) when processing a
	 * PNG image.
	 */
	const DEFAULT_OPTIMIZATION = 2;

	/**
	 * DEFAULT_LOSSY_QUALITY is the default quality of
	 * the lossy compression with pngquant (0 to 100):
	 * the quality aimed at, the minimum is 15 below.
	 */
	const DEFAULT_LOSSY_QUALITY = 80;

	/**
	 * Compresses a PNG file in place with oxipng or
	 * optipng, after pngquant for the lossy
	 * compression, from its original kept as a
	 * backup.
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
		if ($mime != Files::PNG) {
			return;
		}
		// The file is optimised in place from its original, kept as a backup.
		if (!self::backup($filepath, $args)) {
			return;
		}
		$original = Files::backup_path($filepath);
		$quantized = self::quantize($original, $args);
		$source = $quantized ?: $original;
		// optipng keeps a .bak of an output file it overwrites: remove it first.
		unlink($filepath);
		$level = self::optimization_level($args);
		if (self::library() === 'oxipng') {
			// oxipng goes up to -o 6. --force writes the file even if not lighter, as optipng.
			$arguments = ['-o', min(6, $level), '--strip', 'safe', '--force'];
			$threads = self::threads();
			if ($threads !== 'all') {
				array_push($arguments, '--threads', $threads);
			}
			$arguments = array_merge($arguments, ['--out', $filepath, $source]);
		} else {
			$arguments = ['-clobber', '-strip', 'all', '-o', $level, '-out', $filepath, $source];
		}
		$compressed = self::run($arguments, $filepath, $args);
		if ($quantized) {
			unlink($quantized);
		}
		if ($compressed) {
			Logger::info("Successfully compressed image PNG file: " . $filepath);
		} else {
			self::put_back($filepath, $args);
		}
	}

	/**
	 * Returns the settings of a run as a string.
	 *
	 * @param array $args
	 * @return string
	 * @since 1.0.0
	 */
	public static function signature($args)
	{
		$signature = 'level:' . self::optimization_level($args);
		$quality = self::lossy_quality($args);
		if ($quality !== null && self::installed('pngquant')) {
			$signature .= ',lossy:' . $quality;
		}
		return $signature;
	}

	/**
	 * Reduces the colours of the original with pngquant,
	 * if the lossy compression is enabled: often 60 to
	 * 80 % lighter. Returns the temporary file, which
	 * the lossless library compresses then, or null if
	 * pngquant is not installed or could not keep the
	 * minimum quality (e.g. on a photo): the image is
	 * then only compressed lossless.
	 *
	 * @param string $original
	 * @param array $args
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function quantize($original, $args)
	{
		$quality = self::lossy_quality($args);
		if ($quality === null || !self::installed('pngquant')) {
			return null;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam('octopus.png');
		$range = max(0, $quality - 15) . '-' . $quality;
		list($code, $output) = Process::execute(array_merge(Process::nice(Optimiser::$context), [Process::binary('pngquant'), '--quality=' . $range, '--strip', '--skip-if-larger', '--force', '--output', $temp, '--', $original]));
		clearstatcache();
		if ($code === 0 && filesize($temp) > 0) {
			return $temp;
		}
		unlink($temp);
		// 98: not lighter, 99: under the minimum quality. Otherwise, pngquant failed.
		if ($code !== 98 && $code !== 99) {
			Logger::error('pngquant failed on ' . $original . ': ' . trim($output));
		}
		return null;
	}

	/**
	 * Returns the quality of the lossy compression, or
	 * null if it is disabled: 80 by default.
	 *
	 * @param array $args
	 * @return int|null
	 * @since 1.0.0
	 */
	private static function lossy_quality($args)
	{
		if (empty($args['lossy'])) {
			return null;
		}
		if (!isset($args['lossy_quality']) || !is_numeric($args['lossy_quality'])) {
			return self::DEFAULT_LOSSY_QUALITY;
		}
		return max(0, min(100, (int)$args['lossy_quality']));
	}

	/**
	 * Returns the library compressing the PNG images:
	 * oxipng, faster (several threads) and a bit better,
	 * if installed, otherwise optipng.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function library()
	{
		return parent::installed('oxipng') ? 'oxipng' : 'optipng';
	}

	/**
	 * Checks if a library compressing the PNG images is
	 * installed, or another one by name.
	 *
	 * @param string|null $name
	 * @return bool
	 * @since 1.0.0
	 */
	public static function installed($name = null)
	{
		return parent::installed($name === null ? self::library() : $name);
	}

	/**
	 * Returns the number of threads of oxipng: 4 on
	 * upload, 2 in background jobs, all in the CLI, as
	 * avifenc. The octopus_oxipng_threads filter sets it
	 * by context (see Optimiser::$context).
	 *
	 * @return string A number, or "all".
	 * @since 1.0.0
	 */
	private static function threads()
	{
		return Process::threads('octopus_oxipng_threads', Optimiser::$context);
	}

	/**
	 * Returns the optipng level (0 to 7), "o3" (WP-CLI)
	 * or "3" (settings), 2 by default. oxipng gets 6
	 * for 7.
	 *
	 * @param $args
	 * @return int
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	public static function cmd_name()
	{
		return "optipng";
	}

	/**
	 * Returns the extension of the files made: none,
	 * the PNG files are compressed in place.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function extension()
	{
		return "";
	}
}
