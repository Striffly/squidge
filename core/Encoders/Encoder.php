<?php

/**
 * Service
 *
 * Service is responsible for processing file attachments
 * and executing commands.
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
use Imagick;
use Octopus\Log\Errors;
use Octopus\Log\Logger;
use Octopus\Media\Files;
use Octopus\Media\Optimiser;
use Throwable;
use WP_Error;
use WP_Image_Editor_Imagick;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Encoder
{

	/**
	 * FORMAT is the format the encoder makes or
	 * compresses: webp, avif, jpg or png. It names the
	 * meta of the settings an image was processed with
	 * (_octopus_webp) and its errors.
	 */
	const FORMAT = '';

	/**
	 * VERSIONS_TRANSIENT caches the versions of the
	 * libraries, shown on the settings page.
	 */
	const VERSIONS_TRANSIENT = 'octopus_versions';

	/**
	 * VERSION_OPTIONS is the option printing the version
	 * of the libraries not using --version.
	 */
	const VERSION_OPTIONS = ['cwebp' => '-version'];

	/**
	 * EDITOR_MIME is the mime type of the files the
	 * WordPress image editor makes when the library is
	 * missing (see editor()): the WebP and AVIF
	 * formats. Empty for the compressions, GD and
	 * Imagick could only encode the images again.
	 */
	const EDITOR_MIME = '';

	/**
	 * EDITOR_BYTES_PER_PIXEL is the PHP memory GD needs
	 * to make a WebP or AVIF file, by type of image:
	 * measured 8.5 to 9 bytes per pixel for a JPG (the
	 * pixels, then a copy for libwebp), 9 to 13 for a
	 * PNG (a palette is turned to true colour), plus a
	 * margin.
	 */
	const EDITOR_BYTES_PER_PIXEL = [Files::JPG => 10, Files::PNG => 14];

	/**
	 * The versions of the libraries, by name, with
	 * their path, once read in this request.
	 *
	 * @var array|null
	 */
	private static $versions = null;

	/**
	 * The paths of the programs, by name, once looked
	 * up in this request (see binary()).
	 *
	 * @var array
	 */
	private static $binaries = [];

	/**
	 * The image editor classes making each format, by
	 * mime type, once looked up in this request (see
	 * editor_class()).
	 *
	 * @var array
	 */
	private static $editors = [];

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
		// Return if neither the library nor the image editor can make the format.
		if (!static::available()) {
			return;
		}
		$metadata = is_int($attachment) ? wp_get_attachment_metadata($attachment) : $attachment;
		if (empty($metadata['file'])) {
			throw new Exception("File attachment is not set.");
		}
		$id = isset($args['id']) ? (int)$args['id'] : 0;
		$files = Files::of($id, $metadata);
		// The main file is there, and in the uploads folder: not a link out of it.
		if (!file_exists($files[0]) || !Files::contained($files[0])) {
			return;
		}

		// An attachment already processed is only completed: the files not done
		// yet (e.g. a new thumbnail size), unless forced.
		$complete = self::has_compressed($id) && empty($args['force']);
		$args['id'] = $id;
		Errors::clear_attachment($id, static::FORMAT);

		if (!empty($args['thumbnailsOnly'])) {
			array_shift($files);
		}
		foreach ($files as $file) {
			if (!file_exists($file) || ($complete && static::is_done($file, $id))) {
				continue;
			}
			// The library reads the original and writes the version of the file:
			// never through a link out of the folders.
			if (!Files::contained($file . static::extension()) || !Files::contained(Files::backup_path($file))) {
				continue;
			}
			static::convert($file, (string)wp_get_image_mime($file), $args);
		}

		// Save the settings used, unless a file failed so that it is processed
		// again next time. A completed attachment keeps the settings it was
		// processed with.
		if (!$complete && !Errors::has($id, static::FORMAT)) {
			self::update_meta($id, $args);
		}
	}

	/**
	 * Runs the library on a file with the arguments
	 * given, and records an error if it exits with a
	 * non zero code.
	 *
	 * @param string[] $arguments
	 * @param string $filepath
	 * @param array $args
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function run($arguments, $filepath, $args)
	{
		$command = array_merge(self::nice(), [self::binary(static::library())], $arguments);
		list($code, $output) = self::execute($command);
		if ($code === 0) {
			return true;
		}
		$lines = preg_split('/\r?\n/', $output);
		Errors::add(isset($args['id']) ? $args['id'] : 0, static::FORMAT, $filepath, self::error_message($lines, $code, $filepath));
		return false;
	}

	/**
	 * Executes a command without shell: each argument
	 * reaches the program as given, nothing is
	 * interpreted. Falls back on exec(), with escaped
	 * arguments, where proc_open() is disabled. On
	 * Windows, exec() runs cmd.exe /s /c "command",
	 * and escapeshellarg() quotes each argument for it
	 * (and blanks out its % ! " characters).
	 *
	 * @param string[] $command The program, then its arguments.
	 * @return array The exit code and the output (stdout and stderr).
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function execute($command)
	{
		$command = array_map('strval', $command);
		if (function_exists('proc_open')) {
			$null = self::is_windows() ? 'NUL' : '/dev/null';
			$process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
			if (is_resource($process)) {
				$output = stream_get_contents($pipes[1]);
				fclose($pipes[1]);
				return [proc_close($process), (string)$output];
			}
		}
		if (function_exists('exec')) {
			exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);
			return [$code, implode("\n", $lines)];
		}
		return [127, 'cannot run ' . basename($command[0])];
	}

	/**
	 * Returns the absolute path of a program, or null
	 * if it is not installed, once per request. The
	 * octopus_binary_path filter sets it by hand, e.g. for
	 * a library out of the folders looked up (see
	 * find_binary()).
	 *
	 * @param string $name
	 * @return string|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function binary($name)
	{
		if (!array_key_exists($name, self::$binaries)) {
			$path = apply_filters('octopus_binary_path', self::find_binary($name), $name);
			self::$binaries[$name] = is_string($path) && $path !== '' ? $path : null;
		}
		return self::$binaries[$name];
	}

	/**
	 * Looks a program up in the folders of the PATH,
	 * then in the usual folders of the libraries:
	 * PHP-FPM often passes an empty PATH. On Windows,
	 * with the extensions of PATHEXT (cwebp.exe),
	 * except .bat and .cmd: cmd.exe would read the
	 * arguments of these scripts. No shell runs, so
	 * this works where there is none (Windows), but
	 * where open_basedir hides the folders from PHP:
	 * the shell finds the program there, as it is
	 * not bound by it, with the name as its argument.
	 *
	 * @param string $name
	 * @return string|false
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function find_binary($name)
	{
		$folders = explode(PATH_SEPARATOR, (string)getenv('PATH'));
		$extensions = [''];
		if (self::is_windows()) {
			$pathext = getenv('PATHEXT') ?: '.COM;.EXE';
			$extensions = array_diff(array_map('strtolower', explode(';', $pathext)), ['', '.bat', '.cmd']);
		} else {
			$folders = array_merge($folders, ['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin', '/opt/local/bin']);
		}
		foreach (array_unique($folders) as $folder) {
			$folder = trim($folder, '"');
			// A relative folder, e.g. ".", would depend on the current folder. Not
			// path_is_absolute(): its realpath() warns of the folders out of open_basedir.
			if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $folder)) {
				continue;
			}
			foreach ($extensions as $extension) {
				$path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
				if (self::is_program($path)) {
					return $path;
				}
			}
		}
		if (!self::is_windows() && ini_get('open_basedir')) {
			list($code, $output) = self::execute(['/bin/sh', '-c', 'command -v "$1"', 'sh', $name]);
			$path = trim($output);
			return $code === 0 && strpos($path, '/') === 0 ? $path : false;
		}
		return false;
	}

	/**
	 * Determines if a path is a program PHP can run.
	 * On Windows, is_executable() checks its extension.
	 *
	 * @param string $path
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function is_program($path)
	{
		return $path !== '' && @is_file($path) && @is_executable($path);
	}

	/**
	 * Determines if the server runs Windows.
	 *
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function is_windows()
	{
		return PHP_OS_FAMILY === 'Windows';
	}

	/**
	 * Returns the nice command lowering the priority
	 * of the libraries: in background jobs by default,
	 * the requests of the site go first. The octopus_nice
	 * filter sets the level, from 0 (normal) to 19
	 * (lowest), by context (see Optimiser::$context).
	 * Windows has no nice.
	 *
	 * @return string[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function nice()
	{
		if (self::is_windows()) {
			return [];
		}
		$default = Optimiser::$context === 'background' ? 19 : 0;
		$level = (int)apply_filters('octopus_nice', $default, Optimiser::$context);
		$nice = $level > 0 ? self::binary('nice') : null;
		return $nice ? [$nice, '-n', (string)min(19, $level)] : [];
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
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function readable_source($filepath, $mime)
	{
		if ($mime != Files::JPG) {
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
		// A random name: in a temporary folder shared by the users of the server,
		// a predictable one could be a link to a file of the site, overwritten.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam('octopus.png');
		$saved = $editor->save($temp, 'image/png');
		// The editor saves it with the .png extension, next to the empty .tmp.
		if (file_exists($temp) && (is_wp_error($saved) || $saved['path'] !== $temp)) {
			unlink($temp);
		}
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
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function release_source($source, $filepath)
	{
		if ($source !== $filepath && file_exists($source)) {
			unlink($source);
		}
	}

	/**
	 * Makes the WebP or AVIF version of a file with the
	 * WordPress image editor, where the library is
	 * missing (see editor()): from the original, at the
	 * same path, remembered as with the library. The
	 * editor reads the CMYK JPGs, as for the
	 * thumbnails: no RGB copy is needed (see
	 * readable_source()).
	 *
	 * @param string $filepath
	 * @param string $mime
	 * @param int $quality The quality of the editor, 0 to 100.
	 * @param array $args
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function convert_with_editor($filepath, $mime, $quality, $args)
	{
		$output = $filepath . static::extension();
		if (self::save_with_editor(self::original($filepath), $mime, $output, $quality, $filepath, $args)) {
			Logger::info(sprintf('Successfully converted with %s: %s', static::editor(), $output));
			Files::remember(isset($args['id']) ? (int)$args['id'] : 0, $output);
		}
	}

	/**
	 * Saves an image in the format of the service with
	 * the image editor, and records an error if it
	 * fails, as run() does for a library. The editor
	 * works in the PHP memory: the limit is raised as
	 * WordPress does, and an image too big for it gets
	 * an error instead of stopping PHP, with the whole
	 * job (see fits_in_memory()).
	 *
	 * @param string $source
	 * @param string $mime The mime type of the source.
	 * @param string $output
	 * @param int $quality
	 * @param string $filepath The file of the attachment, for the errors.
	 * @param array $args
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function save_with_editor($source, $mime, $output, $quality, $filepath, $args)
	{
		$id = isset($args['id']) ? $args['id'] : 0;
		$class = self::editor_class();
		wp_raise_memory_limit('image');
		if (!self::fits_in_memory($source, $mime, $class)) {
			Errors::add($id, static::FORMAT, $filepath, sprintf('not enough PHP memory for %s to read the image', static::editor()));
			return false;
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
				self::strip_metadata($editor);
				$saved = $editor->save($output, static::EDITOR_MIME);
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
				unlink($saved['path']);
			}
			$saved = new WP_Error('octopus_editor_error', sprintf('saved as %s instead of %s', $saved['mime-type'], static::EDITOR_MIME));
		}
		if (is_wp_error($saved)) {
			Errors::add($id, static::FORMAT, $filepath, static::editor() . ': ' . $saved->get_error_message());
			return false;
		}
		return true;
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
	 * @date 25/10/2026
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
		$bytes = isset(self::EDITOR_BYTES_PER_PIXEL[$mime]) ? self::EDITOR_BYTES_PER_PIXEL[$mime] : max(self::EDITOR_BYTES_PER_PIXEL);
		return memory_get_usage() + $size[0] * $size[1] * $bytes < $limit;
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
	 * @date 25/10/2026
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
	 * making the format of the service, once per
	 * request: the first one of the wp_image_editors
	 * filter supporting it (Imagick, then GD by
	 * default), as wp_image_editor_supports() tells.
	 * Null if none does, or for the compressions.
	 *
	 * @return string|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function editor_class()
	{
		$mime = static::EDITOR_MIME;
		if ($mime === '') {
			return null;
		}
		if (!array_key_exists($mime, self::$editors)) {
			$class = _wp_image_editor_choose(['mime_type' => $mime]);
			self::$editors[$mime] = $class ? $class : null;
		}
		return self::$editors[$mime];
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
	 * @date 25/10/2026
	 */
	public static function editor()
	{
		$class = self::editor_class();
		return $class === null ? null : preg_replace('/^WP_Image_Editor_/', '', $class);
	}

	/**
	 * Determines if the service can make its files:
	 * with its library, or with the image editor for
	 * the WebP and AVIF formats.
	 *
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function available()
	{
		return static::installed() || self::editor_class() !== null;
	}

	/**
	 * Returns what makes the files of the service: the
	 * library when installed, preferred, otherwise the
	 * image editor, null if none can.
	 *
	 * @return string|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function engine()
	{
		return static::installed() ? static::library() : static::editor();
	}

	/**
	 * Returns a short error message from the output
	 * of a failed command: its error lines, or its
	 * last line, without the directory of the file.
	 *
	 * @param string[] $output
	 * @param int $code
	 * @param string $filepath
	 * @return string
	 * @since 1.0.0
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
	 * @date 25/10/2026
	 */
	protected static function is_done($filepath, $id = 0)
	{
		if (static::extension() !== '') {
			$variant = $filepath . static::extension();
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
	 */
	protected static function backup($filepath, $args)
	{
		$backup = Files::backup_path($filepath);
		Files::protect_backup_dir();
		if (file_exists($backup)) {
			return true;
		}
		if (!wp_mkdir_p(dirname($backup)) || !copy($filepath, $backup)) {
			Errors::add(isset($args['id']) ? $args['id'] : 0, static::FORMAT, $filepath, 'cannot write the backup of the original image');
			return false;
		}
		Files::remember(isset($args['id']) ? (int)$args['id'] : 0, $backup);
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
	 * @date 26/10/2026
	 */
	protected static function put_back($filepath, $args)
	{
		$original = Files::backup_path($filepath);
		if (@copy($original, $filepath)) {
			unlink($original);
			return;
		}
		Errors::add(isset($args['id']) ? $args['id'] : 0, static::FORMAT, $filepath, 'cannot put the original back, it stays in ' . Files::BACKUP_DIR);
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
	 * @since 0.1.2
	 * @date 05/12/2021
	 */
	public static function installed($name = null)
	{
		$allowed = [
			'jpegoptim',
			'optipng',
			'oxipng',
			'pngquant',
			'cwebp',
			'avifenc',
		];
		$name = $name === null ? static::cmd_name() : $name;
		if (!in_array($name, $allowed, true) || !self::can_execute()) {
			return false;
		}
		return self::binary($name) !== null;
	}

	/**
	 * Determines if PHP can run programs: proc_open()
	 * or exec(), see execute(). Shared hosts often
	 * disable both, with disable_functions: the
	 * libraries are then of no use, even installed.
	 *
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function can_execute()
	{
		return function_exists('proc_open') || function_exists('exec');
	}

	/**
	 * Returns the library the service runs: its
	 * command name, unless the service picks one of
	 * several (see PNG::library()).
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function library()
	{
		return static::cmd_name();
	}

	/**
	 * Returns the version of the library (e.g. 1.5.0),
	 * or an empty string if it is not installed or does
	 * not tell it. The settings page shows it on every
	 * load: it is cached for 12 hours, and read again
	 * when the library moves or the settings are saved
	 * (see forget_versions()).
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function version_number()
	{
		if (!static::installed()) {
			return '';
		}
		$name = static::library();
		$path = self::binary($name);
		if (self::$versions === null) {
			$cached = get_transient(self::VERSIONS_TRANSIENT);
			self::$versions = is_array($cached) ? $cached : [];
		}
		if (!isset(self::$versions[$name]['path']) || self::$versions[$name]['path'] !== $path) {
			$option = array_key_exists($name, self::VERSION_OPTIONS) ? self::VERSION_OPTIONS[$name] : '--version';
			list(, $output) = self::execute([$path, $option]);
			// avifenc tells it on its Version: line, the others on their first line.
			$line = preg_match('/^Version:.*$/mi', $output, $matches) ? $matches[0] : strtok(ltrim($output), "\n");
			$version = preg_match('/\d+(\.\d+)+/', (string)$line, $matches) ? $matches[0] : '';
			self::$versions[$name] = ['path' => $path, 'version' => $version];
			set_transient(self::VERSIONS_TRANSIENT, self::$versions, 12 * HOUR_IN_SECONDS);
		}
		return self::$versions[$name]['version'];
	}

	/**
	 * Forgets the versions of the libraries, read again
	 * next time, e.g. after an upgrade.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function forget_versions()
	{
		self::$versions = null;
		delete_transient(self::VERSIONS_TRANSIENT);
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
		$meta = get_post_meta($id, static::meta_key());
		return !empty($meta);
	}

	/**
	 * Returns the meta key marking an attachment as
	 * processed by this service.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function meta_key()
	{
		return '_octopus_' . static::FORMAT;
	}

	/**
	 * Returns the settings of a run as a string, saved
	 * with the attachment to know if it was optimised
	 * with the current settings.
	 *
	 * @param array $args
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function signature($args)
	{
		$quality = isset($args['quality']) && is_numeric($args['quality']) ? (int)$args['quality'] : static::DEFAULT_QUALITY;
		return 'quality:' . $quality;
	}

	/**
	 * Marks the attachment as processed by this service,
	 * with the settings used.
	 *
	 * @param $id
	 * @param array $args
	 * @since 0.1.4
	 * @date 21/12/2021
	 */
	public static function update_meta($id, $args = [])
	{
		update_post_meta($id, static::meta_key(), static::signature($args));
	}
}
