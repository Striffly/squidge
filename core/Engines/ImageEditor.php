<?php

/**
 * ImageEditor
 *
 * The WordPress image editor (GD or Imagick) making
 * the WebP and AVIF files where cwebp or avifenc is
 * missing, and the copies of the JPGs the libraries
 * cannot read as the browsers show them: CMYK, or
 * sideways.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Engines;

use Imagick;
use Octopus\Log\Logger;
use Octopus\Media\Files;
use Throwable;
use WP_Error;
use WP_Image_Editor_Imagick;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class ImageEditor
{

	/**
	 * BYTES_PER_PIXEL is the PHP memory GD needs
	 * to make a WebP or AVIF file, by type of image:
	 * measured 8.5 to 9 bytes per pixel for a JPG (the
	 * pixels, then a copy for libwebp), 9 to 13 for a
	 * PNG (a palette is turned to true colour), plus a
	 * margin.
	 */
	const BYTES_PER_PIXEL = [Files::JPG => 10, Files::PNG => 14];

	/**
	 * The image editor classes making each format, by
	 * mime type, once looked up in this request (see
	 * editor_class()).
	 *
	 * @var array
	 */
	private static $classes = [];

	/**
	 * The RGB copies of the CMYK JPGs, by the file they
	 * copy, kept until release_all(): cwebp and avifenc
	 * read the same copy of a file.
	 *
	 * @var string[]
	 */
	private static $copies = [];

	/**
	 * Returns a file the converters can read as the
	 * browsers show it: the file itself or, for a JPG
	 * that cwebp and avifenc cannot read (CMYK) or
	 * would leave sideways (see orient()), a temporary
	 * copy made with the WordPress image editor (as for
	 * the thumbnails), once for both. The copy is a
	 * PNG: lossless, and always RGB. Remove it with
	 * release(), or all of them with release_all().
	 *
	 * @param string $filepath
	 * @param string $mime
	 * @return string
	 * @since 1.0.0
	 */
	public static function readable($filepath, $mime)
	{
		if ($mime != Files::JPG) {
			return $filepath;
		}
		if (isset(self::$copies[$filepath]) && file_exists(self::$copies[$filepath])) {
			return self::$copies[$filepath];
		}
		$info = @getimagesize($filepath);
		$cmyk = $info && isset($info['channels']) && $info['channels'] == 4;
		$orientation = ImageHeader::orientation($filepath);
		if (!$cmyk && $orientation === 1) {
			return $filepath;
		}
		$editor = wp_get_image_editor($filepath);
		if (is_wp_error($editor)) {
			return $filepath;
		}
		self::orient($editor, $orientation);
		// Private, unlike the temporary folder of the server (see Files::temp_file()).
		$temp = Files::temp_file('.png');
		if ($temp === null) {
			return $filepath;
		}
		$saved = $editor->save($temp, 'image/png');
		if (is_wp_error($saved) || $saved['path'] !== $temp) {
			wp_delete_file($temp);
			return $filepath;
		}
		Logger::info("Copied the image in RGB, the right way up, before conversion: " . $filepath);
		self::$copies[$filepath] = $saved['path'];
		return $saved['path'];
	}

	/**
	 * Removes the temporary copy made by
	 * readable(), if any.
	 *
	 * @param string $source
	 * @param string $filepath
	 * @since 1.0.0
	 */
	public static function release($source, $filepath)
	{
		if ($source !== $filepath && file_exists($source)) {
			wp_delete_file($source);
		}
		unset(self::$copies[$filepath]);
	}

	/**
	 * Removes the copies made by readable(), once the
	 * formats of an image are made.
	 *
	 * @since 1.0.0
	 */
	public static function release_all()
	{
		foreach (self::$copies as $filepath => $copy) {
			self::release($copy, $filepath);
		}
	}

	/**
	 * Saves an image in another format with the image
	 * editor, the right way up (see orient()): true, or
	 * the error. The editor
	 * works in the PHP memory: the limit is raised as
	 * WordPress does, and an image too big for it gets
	 * an error instead of stopping PHP, with the whole
	 * job (see fits_in_memory()).
	 *
	 * @param string $source
	 * @param string $mime The mime type of the source.
	 * @param string $output
	 * @param string $format The mime type of the output.
	 * @param int $quality
	 * @param string $filepath The file of the attachment, never deleted.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public static function save($source, $mime, $output, $format, $quality, $filepath)
	{
		$class = self::editor_class($format);
		wp_raise_memory_limit('image');
		if (!self::fits_in_memory($source, $mime, $class)) {
			return new WP_Error('octopus_editor_memory', 'not enough PHP memory to read the image');
		}

		// The editor of the format, even if WordPress picks another one for the
		// thumbnails. And the quality given: saving in another format than the
		// image sets the default quality of the format again.
		$editors = function () use ($class) {
			return [$class];
		};
		$set_quality = function () use ($quality) {
			return $quality;
		};
		add_filter('wp_image_editors', $editors, PHP_INT_MAX);
		add_filter('wp_editor_set_quality', $set_quality, PHP_INT_MAX);
		try {
			$saved = wp_get_image_editor($source, ['mime_type' => $mime]);
			if (!is_wp_error($saved)) {
				$editor = $saved;
				self::orient($editor, ImageHeader::orientation($source));
				self::strip_metadata($editor);
				$saved = $editor->save($output, $format);
			}
		} catch (Throwable $e) {
			$saved = new WP_Error('octopus_editor_error', $e->getMessage());
		} finally {
			remove_filter('wp_image_editors', $editors, PHP_INT_MAX);
			remove_filter('wp_editor_set_quality', $set_quality, PHP_INT_MAX);
		}

		// A filter of the output formats (image_editor_output_format) made
		// another format, with another extension.
		if (!is_wp_error($saved) && wp_normalize_path($saved['path']) !== wp_normalize_path($output)) {
			if (file_exists($saved['path']) && !in_array($saved['path'], [$source, $filepath], true) && Files::contained($saved['path'])) {
				wp_delete_file($saved['path']);
			}
			$saved = new WP_Error('octopus_editor_error', sprintf('saved as %s instead of %s', $saved['mime-type'], $format));
		}
		return is_wp_error($saved) ? $saved : true;
	}

	/**
	 * Determines if GD has enough PHP memory to make
	 * the WebP or AVIF file of an image: past the
	 * limit, PHP stops with a fatal error, that no
	 * error handler catches. Imagick works out of the
	 * PHP memory, with its own limits.
	 *
	 * @param string $source
	 * @param string $mime
	 * @param string $class The class of the editor.
	 * @return bool
	 * @since 1.0.0
	 */
	private static function fits_in_memory($source, $mime, $class)
	{
		if (!is_a($class, 'WP_Image_Editor_GD', true)) {
			return true;
		}
		$limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
		$size = @getimagesize($source);
		if ($limit <= 0 || !$size) {
			return true;
		}
		$bytes = isset(self::BYTES_PER_PIXEL[$mime]) ? self::BYTES_PER_PIXEL[$mime] : max(self::BYTES_PER_PIXEL);
		return memory_get_usage() + $size[0] * $size[1] * $bytes < $limit;
	}

	/**
	 * Turns the image loaded by an editor the right way
	 * up: the browsers turn a JPG from the orientation
	 * of its Exif data, which the WebP and AVIF files
	 * lose. WordPress turns the files it makes on
	 * upload, but not always: images uploaded before
	 * WordPress 5.3, PHP without its exif extension, or
	 * a filter of wp_image_maybe_exif_rotate. The
	 * orientation read by OIO is given to
	 * WP_Image_Editor::maybe_exif_rotate(), for the
	 * same turns. If they fail, the image stays as
	 * stored.
	 *
	 * @param object $editor A WP_Image_Editor.
	 * @param int $orientation See ImageHeader::orientation().
	 * @since 1.0.0
	 */
	private static function orient($editor, $orientation)
	{
		if ($orientation === 1) {
			return;
		}
		$given = function () use ($orientation) {
			return $orientation;
		};
		add_filter('wp_image_maybe_exif_rotate', $given, PHP_INT_MAX);
		try {
			$editor->maybe_exif_rotate();
		} finally {
			remove_filter('wp_image_maybe_exif_rotate', $given, PHP_INT_MAX);
		}
	}

	/**
	 * Removes the metadata of the image loaded by an
	 * Imagick editor but its colour profile: Exif (GPS
	 * position, camera), XMP and IPTC, which
	 * ImageMagick writes in the WebP and AVIF files.
	 * WP_Image_Editor_Imagick::strip_meta() keeps them,
	 * and only runs on resize. A CMYK image loses its
	 * profile too: ImageMagick turns it to RGB on
	 * saving, the profile would not match. GD keeps no
	 * metadata.
	 *
	 * @param object $editor A WP_Image_Editor.
	 * @since 1.0.0
	 */
	private static function strip_metadata($editor)
	{
		if (!$editor instanceof WP_Image_Editor_Imagick) {
			return;
		}
		// The Imagick object of the editor is protected: run in its scope.
		$strip = function () {
			$keep = $this->image->getImageColorspace() === Imagick::COLORSPACE_CMYK ? [] : ['icc', 'icm'];
			foreach ($this->image->getImageProfiles('*', false) as $name) {
				if (!in_array(strtolower($name), $keep, true)) {
					$this->image->removeImageProfile($name);
				}
			}
		};
		$strip->call($editor);
	}

	/**
	 * Returns the class of the WordPress image editor
	 * making a format, once per request: the first one
	 * of the wp_image_editors filter supporting it
	 * (Imagick, then GD by default), as
	 * wp_image_editor_supports() tells. Null if none
	 * does.
	 *
	 * @param string $format The mime type.
	 * @return string|null
	 * @since 1.0.0
	 */
	private static function editor_class($format)
	{
		if ($format === '') {
			return null;
		}
		if (!array_key_exists($format, self::$classes)) {
			$class = _wp_image_editor_choose(['mime_type' => $format]);
			self::$classes[$format] = $class ? $class : null;
		}
		return self::$classes[$format];
	}

	/**
	 * Returns the name of the WordPress image editor
	 * making a format, e.g. GD or Imagick, or null if
	 * none supports it.
	 *
	 * @param string $format The mime type.
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function name($format)
	{
		$class = self::editor_class($format);
		return $class === null ? null : preg_replace('/^WP_Image_Editor_/', '', $class);
	}
}
