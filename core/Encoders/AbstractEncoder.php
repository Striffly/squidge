<?php

/**
 * AbstractEncoder
 *
 * The base of the encoders: runs the library of a
 * format, or the image editor, on every file of an
 * image, keeps the originals of the files optimised
 * in place, and saves the settings used.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Encoders;

use Exception;
use Octopus\Engines\ImageEditor;
use Octopus\Engines\Libraries;
use Octopus\Log\Errors;
use Octopus\Log\Logger;
use Octopus\Media\Files;
use Octopus\Media\Lock;
use Octopus\Media\Optimiser;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

abstract class AbstractEncoder
{
	/**
	 * FORMAT is the format the encoder makes or
	 * compresses: webp, avif, jpg or png. It names the
	 * meta of the settings an image was processed with
	 * (_octopus_webp) and its errors.
	 */
	const FORMAT = '';

	/**
	 * TITLE is the name of the format, as shown: JPG,
	 * WebP.
	 */
	const TITLE = '';

	/**
	 * MIME is the type of the files the encoder makes:
	 * the WebP and AVIF versions, which the WordPress
	 * image editor makes too when the library is
	 * missing (see editor()). Empty for the
	 * compressions, GD and Imagick could only encode
	 * the images again.
	 */
	const MIME = '';

	/**
	 * SOURCES are the types of the images the encoder
	 * reads: the others are left as they are.
	 */
	const SOURCES = [];

	/**
	 * OPTIONAL are the other libraries the encoder runs
	 * when they are installed, e.g. pngquant for the
	 * lossy PNG compression.
	 */
	const OPTIONAL = [];

	/**
	 * PREFERRED is the library replacing the one of the
	 * encoder once installed, e.g. oxipng for optipng.
	 */
	const PREFERRED = '';

	/**
	 * Makes the version of a file in the format of the
	 * encoder, or compresses it in place: a job of
	 * Libraries::run_all(), yielding the commands of its
	 * library (see run()).
	 *
	 * @param string $filepath
	 * @param string $mime
	 * @param array $args
	 * @return \Generator
	 * @since 1.0.0
	 */
	abstract public static function convert($filepath, $mime, $args);

	/**
	 * Returns the name of the library of the encoder.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	abstract public static function cmd_name();

	/**
	 * Returns the extension added to the files made
	 * (.webp, .avif), in the folder of the versions
	 * (see Files::variant()), empty for the
	 * compressions in place.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	abstract public static function extension();

	/**
	 * Returns the jobs making the format of the files of
	 * an image, each with its file, for
	 * Libraries::run_all(): then processed() saves the
	 * settings used. Null if the format cannot be made.
	 *
	 * @param array $metadata
	 * @param array $args
	 * @return array|null [file, job] pairs.
	 * @throws Exception
	 * @since 1.0.0
	 */
	public static function jobs($metadata, $args)
	{
		// Neither the library nor the image editor can make the format.
		if (!static::available()) {
			return null;
		}
		if (empty($metadata['file'])) {
			throw new Exception("File attachment is not set.");
		}
		$id = self::id($args);
		$files = Files::of($id, $metadata);
		// The main file is there, and in the uploads folder: not a link out of it.
		if (!file_exists($files[0]) || !Files::contained($files[0])) {
			return null;
		}

		// An attachment already processed is only completed: the files not done
		// yet (e.g. a new thumbnail size), unless forced.
		$complete = self::complete($id, $args);
		$args['id'] = $id;
		Errors::clear_attachment($id, static::FORMAT);

		if (!empty($args['thumbnailsOnly'])) {
			array_shift($files);
		}
		$jobs = [];
		foreach ($files as $file) {
			if (!file_exists($file) || ($complete && static::is_done($file, $id))) {
				continue;
			}
			// The library reads the file or its original and writes its version:
			// never through a link out of the folders, e.g. a thumbnail linked to
			// an image elsewhere on the server, which would be published.
			$output = static::extension() !== '' ? Files::variant($file, static::extension()) : $file;
			if (!Files::contained($file) || !Files::contained($output) || !Files::contained(Files::backup_path($file))) {
				continue;
			}
			if (!Files::make_dir($output)) {
				self::error($args, $file, 'cannot make the folder of the ' . static::FORMAT . ' version');
				continue;
			}
			$jobs[] = [$file, self::job($file, $args)];
		}
		return $jobs;
	}

	/**
	 * Returns the job converting a file, see convert().
	 * An exception is an error of the file: the image
	 * is processed again next time.
	 *
	 * @param string $file
	 * @param array $args
	 * @return \Generator
	 * @since 1.0.0
	 */
	private static function job($file, $args)
	{
		// Still processing the image as the job starts: its lock does not go stale.
		Lock::touch($args['id']);
		$mime = (string)wp_get_image_mime($file);
		if (!in_array($mime, static::SOURCES, true)) {
			return;
		}
		try {
			yield from static::convert($file, $mime, $args);
		} catch (Exception $e) {
			self::error($args, $file, $e->getMessage());
		}
	}

	/**
	 * Saves the settings an image was processed with,
	 * once the jobs of jobs() ran, unless a file failed
	 * so that it is processed again next time. A
	 * completed image keeps the settings it was
	 * processed with.
	 *
	 * @param array $args The args given to jobs().
	 * @since 1.0.0
	 */
	public static function processed($args)
	{
		$id = self::id($args);
		if (!self::complete($id, $args) && !Errors::has($id, static::FORMAT)) {
			self::update_meta($id, $args);
		}
	}

	/**
	 * Determines if an image processed already is only
	 * completed: the files not done yet (e.g. a new
	 * thumbnail size), unless forced.
	 *
	 * @param int $id
	 * @param array $args
	 * @return bool
	 * @since 1.0.0
	 */
	private static function complete($id, $args)
	{
		return self::has_compressed($id) && empty($args['force']);
	}

	/**
	 * Runs the library on a file with the arguments
	 * given, from a job (see convert()), and records an
	 * error if it exits with a non zero code.
	 *
	 * @param string[] $arguments
	 * @param string $filepath
	 * @param array $args
	 * @param int $threads The threads the library takes, see Libraries::threads().
	 * @return \Generator Returning whether it succeeded.
	 * @since 1.0.0
	 */
	protected static function run($arguments, $filepath, $args, $threads = 1)
	{
		list($code, $output) = yield ['command' => self::command(static::library(), $arguments, $args), 'threads' => $threads];
		if ($code === 0) {
			return true;
		}
		$lines = preg_split('/\r?\n/', $output);
		self::error($args, $filepath, self::error_message($lines, $code));
		return false;
	}

	/**
	 * Returns the command running a library with its
	 * arguments, at the priority of the context of the
	 * run (see Libraries::nice()).
	 *
	 * @param string $library
	 * @param array $arguments
	 * @param array $args The args of the run, with its context (see Optimiser::UPLOAD).
	 * @return array
	 * @since 1.0.0
	 */
	protected static function command($library, $arguments, $args)
	{
		$context = isset($args['context']) ? $args['context'] : Optimiser::UPLOAD;
		return array_merge(Libraries::nice($context), [Libraries::binary($library)], $arguments);
	}

	/**
	 * Returns the image of a run, 0 without one.
	 *
	 * @param array $args
	 * @return int
	 * @since 1.0.0
	 */
	protected static function id($args)
	{
		return isset($args['id']) ? (int)$args['id'] : 0;
	}

	/**
	 * Records an error of the format on a file of the
	 * image of a run, see Errors::add().
	 *
	 * @param array $args
	 * @param string $filepath
	 * @param string $message
	 * @since 1.0.0
	 */
	protected static function error($args, $filepath, $message)
	{
		Errors::add(self::id($args), static::FORMAT, $filepath, $message);
	}

	/**
	 * Makes the WebP or AVIF version of a file with the
	 * WordPress image editor, where the library is
	 * missing (see editor()): from the original, at the
	 * same path, remembered as with the library. The
	 * editor reads the CMYK JPGs, as for the
	 * thumbnails: no RGB copy is needed (see
	 * ImageEditor::readable()).
	 *
	 * @param string $filepath
	 * @param string $mime
	 * @param int $quality The quality of the editor, 0 to 100.
	 * @param array $args
	 * @since 1.0.0
	 */
	protected static function convert_with_editor($filepath, $mime, $quality, $args)
	{
		$output = Files::variant($filepath, static::extension());
		$saved = ImageEditor::save(self::original($filepath), $mime, $output, static::MIME, $quality, $filepath);
		if (is_wp_error($saved)) {
			self::error($args, $filepath, static::editor() . ': ' . $saved->get_error_message());
			return;
		}
		Logger::info(sprintf('Successfully converted with %s: %s', static::editor(), $output));
		Files::remember(self::id($args), $filepath);
	}

	/**
	 * Returns the name of the WordPress image editor
	 * making the format of the service when its
	 * library is missing, e.g. GD or Imagick, or null
	 * if none supports it: the format is then not
	 * available.
	 *
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function editor()
	{
		return ImageEditor::name(static::MIME);
	}

	/**
	 * Determines if the service can make its files:
	 * with its library, or with the image editor for
	 * the WebP and AVIF formats.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function available()
	{
		return static::installed() || static::editor() !== null;
	}

	/**
	 * Returns what makes the files of the service: the
	 * library when installed, preferred, otherwise the
	 * image editor, null if none can.
	 *
	 * @return string|null
	 * @since 1.0.0
	 */
	public static function engine()
	{
		return static::installed() ? static::library() : static::editor();
	}

	/**
	 * Returns a short error message from the output
	 * of a failed command: its error lines, or its
	 * last line, with its paths shortened (see
	 * Errors::shorten()).
	 *
	 * @param string[] $output
	 * @param int $code
	 * @return string
	 * @since 1.0.0
	 */
	private static function error_message($output, $code)
	{
		$lines = array_values(array_filter(array_map('trim', $output), 'strlen'));
		if (empty($lines)) {
			return sprintf('exited with code %d', $code);
		}
		$errors = array_values(preg_grep('/error|cannot|could not|unsupported|failed|invalid/i', $lines));
		$message = $errors ? implode(' / ', array_slice($errors, 0, 2)) : end($lines);
		return substr(Errors::shorten($message), 0, 300);
	}

	/**
	 * Determines if this service already processed a
	 * file: its WebP or AVIF version exists, or was
	 * deleted as heavier than the file (see
	 * Optimiser::drop_heavier()), or for a compression,
	 * its original is kept as a backup.
	 *
	 * @param string $filepath
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	protected static function is_done($filepath, $id = 0)
	{
		if (static::extension() !== '') {
			$variant = Files::variant($filepath, static::extension());
			return file_exists($variant) || in_array(Files::relative($variant), Optimiser::heavier($id), true);
		}
		return file_exists(Files::backup_path($filepath));
	}

	/**
	 * Returns the original image: its backup if the
	 * file has been optimised in place, otherwise the
	 * file itself. Every optimisation starts from it.
	 *
	 * @param string $filepath
	 * @return string
	 * @since 1.0.0
	 */
	protected static function original($filepath)
	{
		$backup = Files::backup_path($filepath);
		return file_exists($backup) ? $backup : $filepath;
	}

	/**
	 * Keeps a copy of the original image before it is
	 * optimised in place, once.
	 *
	 * @param string $filepath
	 * @param array $args
	 * @return bool
	 * @since 1.0.0
	 */
	protected static function backup($filepath, $args)
	{
		$backup = Files::backup_path($filepath);
		Files::protect_backup_dir();
		if (file_exists($backup)) {
			return true;
		}
		if (!wp_mkdir_p(dirname($backup)) || !copy($filepath, $backup)) {
			self::error($args, $filepath, 'cannot write the backup of the original image');
			return false;
		}
		Files::remember(self::id($args), $filepath);
		return true;
	}

	/**
	 * Puts the original image back in place of a file
	 * the library failed to compress, without backup:
	 * it is retried next time. If the copy fails (e.g.
	 * a full disk), the backup stays: it is the only
	 * copy of the original left.
	 *
	 * @param string $filepath
	 * @param array $args
	 * @since 1.0.0
	 */
	protected static function put_back($filepath, $args)
	{
		$original = Files::backup_path($filepath);
		if (@copy($original, $filepath)) {
			wp_delete_file($original);
			return;
		}
		self::error($args, $filepath, 'cannot put the original back, it stays in the folder of the originals');
	}

	/**
	 * Checks if the library, or another one by name, is
	 * installed on the server, once per request (the
	 * status of the images asks it often). Only the
	 * supported libraries can be run, and none where
	 * PHP cannot run programs (see can_execute()).
	 *
	 * @param string|null $name
	 * @return bool
	 * @since 1.0.0
	 */
	public static function installed($name = null)
	{
		return Libraries::installed($name === null ? static::cmd_name() : $name);
	}

	/**
	 * Returns the library the service runs: its
	 * command name, unless the service picks one of
	 * several (see PNG::library()).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function library()
	{
		return static::cmd_name();
	}

	/**
	 * Returns the version of the library (e.g. 1.5.0),
	 * or an empty string if it is not installed or does
	 * not tell it, see Libraries::version().
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function version_number()
	{
		return static::installed() ? Libraries::version(static::library()) : '';
	}

	/**
	 * Determines if the attachment has been compressed.
	 *
	 * @param $id
	 * @return bool
	 * @since 1.0.0
	 */
	public static function has_compressed($id)
	{
		$meta = get_post_meta($id, static::meta_key());
		return !empty($meta);
	}

	/**
	 * Returns the meta key marking an attachment as
	 * processed by this service.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function meta_key()
	{
		return '_octopus_' . static::FORMAT;
	}

	/**
	 * Returns the args of the encoder from the values
	 * of the settings (see Settings::FIELDS): its
	 * quality.
	 *
	 * @param array $values By name, see Settings::all().
	 * @return array
	 * @since 1.0.0
	 */
	public static function args($values)
	{
		return ['quality' => $values[static::FORMAT . '_quality']];
	}

	/**
	 * Returns the settings of a run as a string, saved
	 * with the attachment to know if it was optimised
	 * with the current settings.
	 *
	 * @param array $args
	 * @return string
	 * @since 1.0.0
	 */
	public static function signature($args)
	{
		return 'quality:' . static::quality($args);
	}

	/**
	 * Returns the quality of a run: the one given, within
	 * the bounds of the quality setting of the format,
	 * otherwise its default (see Settings::FIELDS).
	 *
	 * @param array $args
	 * @return int
	 * @since 1.0.0
	 */
	protected static function quality($args)
	{
		return Settings::clean(static::FORMAT . '_quality', $args['quality'] ?? null);
	}

	/**
	 * Marks the attachment as processed by this service,
	 * with the settings used.
	 *
	 * @param $id
	 * @param array $args
	 * @since 1.0.0
	 */
	public static function update_meta($id, $args = [])
	{
		update_post_meta($id, static::meta_key(), static::signature($args));
	}
}
