<?php

/**
 * WebP
 *
 * WebP is responsible for converting JPG and PNG
 * images to webp with the .webp extension using
 * the cwebp library, or the WordPress image editor
 * (GD or Imagick) where it is missing.
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
use Octopus\Log\Logger;
use Octopus\Media\Files;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class WebP extends AbstractEncoder
{
	/**
	 * The format of the encoder, see AbstractEncoder::FORMAT.
	 */
	const FORMAT = 'webp';

	/**
	 * DEFAULT_QUALITY is the default image quality
	 * when converting to a WebP image.
	 */
	const DEFAULT_QUALITY = 80;

	/**
	 * NEAR_LOSSLESS is the near-lossless level of the
	 * PNG images (0 to 100, 100 is lossless): the
	 * pixels change too little to be seen.
	 */
	const NEAR_LOSSLESS = 60;

	/**
	 * EDITOR_MIME is the format of the image editor,
	 * without cwebp (see AbstractEncoder::editor()).
	 */
	const EDITOR_MIME = 'image/webp';

	/**
	 * Compress all image sizes if they are jpg or png
	 * to webp with the given file path.
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
		if ($mime != Files::PNG && $mime != Files::JPG) {
			return;
		}
		if (!isset($args['quality'])) {
			$args['quality'] = self::DEFAULT_QUALITY;
		}
		// Without cwebp, GD and Imagick encode with libwebp too, at the same
		// quality: the files are alike, but the PNGs are not tried in
		// near-lossless mode.
		if (!self::installed()) {
			self::convert_with_editor($filepath, $mime, (int)$args['quality'], $args);
			return;
		}
		$original = self::original($filepath);
		$source = ImageEditor::readable($original, $mime);
		$output = Files::variant($filepath, self::extension());
		// One thread: -mt makes cwebp no faster, in lossy or near-lossless mode.
		$options = ['-q', (int)$args['quality']];
		if (yield from self::run(array_merge($options, [$source, '-o', $output]), $filepath, $args)) {
			if ($mime == Files::PNG) {
				yield from self::near_lossless($options, $source, $output, $filepath, $args);
			}
			Logger::info("Successfully converted to WebP file: " . $output);
			Files::remember(isset($args['id']) ? (int)$args['id'] : 0, $filepath);
		}
	}

	/**
	 * Encodes a PNG image again in near-lossless mode,
	 * kept if lighter than the lossy WebP: graphics,
	 * screenshots and text get much lighter, without
	 * the blur of the lossy mode around sharp edges.
	 * A lossy WebP under a quarter of the PNG is a
	 * photo, much heavier in near-lossless: not tried.
	 *
	 * @param array $options
	 * @param string $source
	 * @param string $output
	 * @param string $filepath
	 * @param array $args
	 * @return \Generator
	 * @since 1.0.0
	 */
	private static function near_lossless($options, $source, $output, $filepath, $args)
	{
		clearstatcache();
		$lossy = filesize($output);
		if ($lossy < filesize($source) / 4) {
			return;
		}
		$temp = Files::temp_file('.webp');
		if ($temp === null) {
			return;
		}
		if ((yield from self::run(array_merge($options, ['-near_lossless', self::NEAR_LOSSLESS, $source, '-o', $temp]), $filepath, $args)) && filesize($temp) < $lossy) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Replaces the WebP the site may be serving at once, never half written.
			rename($temp, $output);
		} elseif (file_exists($temp)) {
			wp_delete_file($temp);
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
		return "cwebp";
	}

	/**
	 * Returns the extension added to the files made:
	 * photo.jpg.webp.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function extension()
	{
		return ".webp";
	}
}
