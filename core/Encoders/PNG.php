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

use Octopus\Engines\Libraries;
use Octopus\Log\Logger;
use Octopus\Media\Files;
use Octopus\Media\Optimiser;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class PNG extends AbstractEncoder
{
	/**
	 * The format of the encoder, see AbstractEncoder::FORMAT.
	 */
	const FORMAT = 'png';

	/**
	 * Compresses a PNG file in place with oxipng or
	 * optipng, after pngquant for the lossy
	 * compression, from its original kept as a
	 * backup.
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
		if ($mime != Files::PNG) {
			return;
		}
		// The file is optimised in place from its original, kept as a backup.
		if (!self::backup($filepath, $args)) {
			return;
		}
		$original = Files::backup_path($filepath);
		$quantized = yield from self::quantize($original, $args);
		$source = $quantized ?: $original;
		// optipng keeps a .bak of an output file it overwrites: remove it first.
		wp_delete_file($filepath);
		$level = self::optimization_level($args);
		// optipng runs on one thread.
		$threads = 1;
		if (self::library() === 'oxipng') {
			// oxipng goes up to -o 6. --force writes the file even if not lighter, as optipng.
			$threads = Libraries::threads();
			$arguments = ['-o', min(6, $level), '--strip', 'safe', '--force', '--threads', $threads, '--out', $filepath, $source];
		} else {
			$arguments = ['-clobber', '-strip', 'all', '-o', $level, '-out', $filepath, $source];
		}
		$compressed = yield from self::run($arguments, $filepath, $args, $threads);
		if ($quantized) {
			wp_delete_file($quantized);
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
	 * @return \Generator Returning the file, or null.
	 * @since 1.0.0
	 */
	private static function quantize($original, $args)
	{
		$quality = self::lossy_quality($args);
		if ($quality === null || !self::installed('pngquant')) {
			return null;
		}
		// Private, unlike the temporary folder of the server (see Files::temp_file()).
		$temp = Files::temp_file('.png');
		if ($temp === null) {
			return null;
		}
		// The quality aimed at, the minimum 15 below.
		$range = max(0, $quality - 15) . '-' . $quality;
		list($code, $output) = yield ['command' => array_merge(Libraries::nice(Optimiser::$context), [Libraries::binary('pngquant'), '--quality=' . $range, '--strip', '--skip-if-larger', '--force', '--output', $temp, '--', $original]), 'threads' => 1];
		clearstatcache();
		if ($code === 0 && filesize($temp) > 0) {
			return $temp;
		}
		wp_delete_file($temp);
		// 98: not lighter, 99: under the minimum quality. Otherwise, pngquant failed.
		if ($code !== 98 && $code !== 99) {
			Logger::error('pngquant failed on ' . $original . ': ' . trim($output));
		}
		return null;
	}

	/**
	 * Returns the quality pngquant aims at, or null if
	 * the lossy compression is disabled, see
	 * Settings::clean().
	 *
	 * @param array $args
	 * @return int|null
	 * @since 1.0.0
	 */
	private static function lossy_quality($args)
	{
		return empty($args['lossy']) ? null : Settings::clean('png_lossy_quality', $args['lossy_quality'] ?? null);
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
	 * Returns the optipng level, "o3" (WP-CLI) or "3"
	 * (settings), see Settings::clean(). oxipng gets 6
	 * for 7.
	 *
	 * @param $args
	 * @return int
	 * @since 1.0.0
	 */
	private static function optimization_level($args)
	{
		return Settings::clean('png_level', ltrim((string)($args['optimization'] ?? ''), 'oO'));
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
