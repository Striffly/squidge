<?php

/**
 * Service
 *
 * Service os responsible for processing file attachments
 * and executing commands.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Package;

use Exception;
use Squidge\Log\Errors;
use Squidge\Log\Logger;
use Squidge\Types\Mimes;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Service
{

	/**
	 * META_KEY is the meta key for lookup for the
	 * service.
	 */
	const META_KEY = "_squidge_compressed";

	/**
	 * Processes the file attachment.
	 *
	 * @param $attachment
	 * @param $args
	 * @return void
	 * @throws Exception
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function process($attachment, $args)
	{
		// If the attachment is an ID, obtain the metadata.
		if (is_int($attachment)) {
			$attachment = wp_get_attachment_metadata($attachment);
		}
		// Return if the library is not installed.
		if (!self::installed()) {
			return;
		}

		// Check if the file key exists.
		if (!isset($attachment['file'])) {
			throw new Exception("File attachment is not set.");
		}

		// Obtain the file and check if it exists.
		$mainFile = self::get_file_path($attachment['file']);
		if (!$mainFile) {
			return;
		}

		// Check if the attachment has already been compressed.
		$id = attachment_url_to_postid($attachment['file']);
		if (self::has_compressed($id) && !$args['force']) {
			return;
		}
		$args['id'] = $id;
		Errors::clear_attachment($id, static::cmd_name());

		// Convert main image.
		if (!$args['thumbnailsOnly']) static::convert($mainFile, self::get_mime_type($mainFile), $args);

		// Loop over the sizes and convert them.
		foreach ($attachment['sizes'] as $size) {
			if (!isset($size['file'])) {
				continue;
			}
			$basepath = str_replace(basename($attachment['file']), "", $attachment['file']);
			$path = self::get_file_path($basepath . $size['file']);
			if (!$path) {
				continue;
			}
			static::convert($path, self::get_mime_type($path), $args);
		}

		// Update post meta for attachment, unless a file failed
		// so that it is processed again next time.
		if (!Errors::has($id, static::cmd_name())) {
			self::update_meta($id);
		}
	}

	/**
	 * Runs a library command on a file, and records
	 * an error if it exits with a non zero code.
	 *
	 * @param string $command
	 * @param string $filepath
	 * @param array $args
	 * @return bool
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	protected static function run($command, $filepath, $args)
	{
		$output = [];
		exec($command . ' 2>&1', $output, $code);
		if ($code === 0) {
			return true;
		}
		Errors::add(isset($args['id']) ? $args['id'] : 0, static::cmd_name(), $filepath, self::error_message($output, $code, $filepath));
		return false;
	}

	/**
	 * Returns a file the converters can read: the file
	 * itself or, for a CMYK JPG that cwebp and avifenc
	 * cannot read, a temporary RGB copy made with the
	 * WordPress image editor (as for the thumbnails).
	 * The copy is a PNG: lossless, and always RGB.
	 * Remove it with release_source().
	 *
	 * @param string $filepath
	 * @param string $mime
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	protected static function readable_source($filepath, $mime)
	{
		if ($mime != Mimes::JPG) {
			return $filepath;
		}
		$info = @getimagesize($filepath);
		if (!$info || !isset($info['channels']) || $info['channels'] != 4) {
			return $filepath;
		}
		$editor = wp_get_image_editor($filepath);
		if (is_wp_error($editor)) {
			return $filepath;
		}
		$saved = $editor->save(get_temp_dir() . uniqid('squidge-') . '.png', 'image/png');
		if (is_wp_error($saved)) {
			return $filepath;
		}
		Logger::info("Converted CMYK image to RGB before conversion: " . $filepath);
		return $saved['path'];
	}

	/**
	 * Removes the temporary copy made by
	 * readable_source(), if any.
	 *
	 * @param string $source
	 * @param string $filepath
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	protected static function release_source($source, $filepath)
	{
		if ($source !== $filepath && file_exists($source)) {
			unlink($source);
		}
	}

	/**
	 * Returns a short error message from the output
	 * of a failed command: its error lines, or its
	 * last line, without the directory of the file.
	 *
	 * @param array $output
	 * @param int $code
	 * @param string $filepath
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function error_message($output, $code, $filepath)
	{
		$lines = array_values(array_filter(array_map('trim', $output), 'strlen'));
		if (empty($lines)) {
			return sprintf('exited with code %d', $code);
		}
		$errors = array_values(preg_grep('/error|cannot|could not|unsupported|failed|invalid/i', $lines));
		$message = $errors ? implode(' / ', array_slice($errors, 0, 2)) : end($lines);
		return substr(str_replace(dirname($filepath) . DIRECTORY_SEPARATOR, '', $message), 0, 300);
	}

	/**
	 * Deletes all images  with file extensions associated
	 * with the image (if the file exists).
	 *
	 * @param $id
	 * @return void
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function delete($id, $useFileExtension = false)
	{
		// Delete the original file.
		$original = get_attached_file($id);
		if (!$original) return;

		$mime_type = self::get_mime_type($original);
		if ($mime_type != Mimes::PNG && $mime_type != Mimes::JPG) {
			return;
		}

		$originalPath = $original . static::extension();
		if (file_exists($originalPath)) {
			unlink($originalPath);
		}

		// Delete the image sizes.
		$sizes = get_intermediate_image_sizes();
		foreach ($sizes as $size) {
			$fileInfos = image_get_intermediate_size($id, $size);
			if (empty($fileInfos)) {
				continue;
			}
			$path = self::get_file_path($fileInfos['path'] . static::extension());
			if (!$path) {
				continue;
			}
			unlink($path);
		}
	}

	/**
	 * Checks if a command exists.
	 *
	 * shell_exec is required to check to see if the library
	 * is installed on the client's operating system.
	 * Checks to see if the command name is in the allowed
	 * array before continuing.
	 *
	 * @return bool
	 * @since 0.1.2
	 * @date 05/12/2021
	 */
	public static function installed()
	{
		$allowed = [
			'jpegoptim',
			'optipng',
			'cwebp',
			'avifenc',
		];
		if (!in_array(static::cmd_name(), $allowed)) {
			return false;
		}
		$return = shell_exec(sprintf("which %s", escapeshellarg(static::cmd_name())));
		return !empty($return);
	}

	/**
	 * Obtains the absolute filepath including the
	 * upload directory.
	 * If the file does not exist on the file system, the
	 * function will return false.
	 *
	 * @param $path - Path of file inside uploads folder
	 * @return string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private static function get_file_path($path)
	{
		$file = wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . $path;
		if (file_exists($file)) {
			return $file;
		}
		return false;
	}

	/**
	 * Returns the mimetype of a file passed.
	 *
	 * @param $file
	 * @return false|string
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	private static function get_mime_type($file)
	{
		return mime_content_type($file);
	}

	/**
	 * Determines if the attachment has been compressed.
	 *
	 * @param $id
	 * @return bool
	 * @since 0.1.4
	 * @date 21/12/2021
	 */
	public static function has_compressed($id)
	{
		$meta = get_post_meta($id, self::META_KEY . '_' . static::cmd_name());
		return !empty($meta);
	}

	/**
	 * Updates post meta for squidge.
	 *
	 * @param $id
	 * @since 0.1.4
	 * @date 21/12/2021
	 */
	public static function update_meta($id)
	{
		update_post_meta($id, self::META_KEY . '_' . static::cmd_name(), true);
	}
}
