<?php

/**
 * Service
 *
 * Service os responsible for processing file attachments
 * and executing commands.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Package;

use Exception;
use OIO\Log\Errors;
use OIO\Log\Logger;
use OIO\Types\Mimes;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Service
{

	/**
	 * META_KEY is the meta key for lookup for the
	 * service.
	 */
	const META_KEY = "_oio_compressed";

	/**
	 * BACKUP_DIR is the folder, in the uploads folder,
	 * where the original images are kept before being
	 * optimised in place, with the same tree as the
	 * uploads folder (see backup_dir()).
	 */
	const BACKUP_DIR = 'oio-originals';

	/**
	 * FILES_KEY is the meta key listing the files made
	 * by OIO for an attachment (WebP, AVIF and
	 * backups), relative to the uploads folder.
	 */
	const FILES_KEY = '_oio_files';

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

		// An attachment already processed is only completed: the files not done
		// yet (e.g. a new thumbnail size), unless forced.
		$id = !empty($args['id']) ? (int)$args['id'] : attachment_url_to_postid($attachment['file']);
		$complete = self::has_compressed($id) && empty($args['force']);
		$args['id'] = $id;
		Errors::clear_attachment($id, static::cmd_name());

		$files = [];
		if (empty($args['thumbnailsOnly'])) {
			$files[] = $mainFile;
		}
		$sizes = isset($attachment['sizes']) ? $attachment['sizes'] : [];
		$basepath = str_replace(basename($attachment['file']), "", $attachment['file']);
		foreach ($sizes as $size) {
			$path = isset($size['file']) ? self::get_file_path($basepath . $size['file']) : false;
			if ($path) {
				$files[] = $path;
			}
		}
		foreach ($files as $file) {
			if ($complete && static::is_done($file)) {
				continue;
			}
			// The library reads the original and writes the version of the file:
			// never through a link out of the folders.
			if (!self::contained($file . static::extension()) || !self::contained(self::backup_path($file))) {
				continue;
			}
			static::convert($file, self::get_mime_type($file), $args);
		}

		// Save the settings used, unless a file failed so that it is processed
		// again next time. A completed attachment keeps the settings it was
		// processed with.
		if (!$complete && !Errors::has($id, static::cmd_name())) {
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
		$command = array_merge(self::nice(), [self::binary(static::cmd_name())], $arguments);
		list($code, $output) = self::execute($command);
		if ($code === 0) {
			return true;
		}
		$lines = preg_split('/\r?\n/', $output);
		Errors::add(isset($args['id']) ? $args['id'] : 0, static::cmd_name(), $filepath, self::error_message($lines, $code, $filepath));
		return false;
	}

	/**
	 * Executes a command without shell: each argument
	 * reaches the program as given, nothing is
	 * interpreted. Falls back on exec(), with escaped
	 * arguments, where proc_open() is disabled.
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
			$process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
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
	 * if it is not installed. The shell finds it with
	 * its default PATH, which PHP-FPM may not pass on;
	 * the name is an argument of the shell script, not
	 * a part of it.
	 *
	 * @param string $name
	 * @return string|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function binary($name)
	{
		static $paths = [];
		if (!array_key_exists($name, $paths)) {
			list($code, $output) = self::execute(['/bin/sh', '-c', 'command -v "$1"', 'sh', $name]);
			$path = trim($output);
			$paths[$name] = $code === 0 && strpos($path, '/') === 0 ? $path : null;
		}
		return $paths[$name];
	}

	/**
	 * Returns the nice command lowering the priority
	 * of the libraries: in background jobs by default,
	 * the requests of the site go first. The oio_nice
	 * filter sets the level, from 0 (normal) to 19
	 * (lowest), by context (see Optimiser::$context).
	 *
	 * @return string[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function nice()
	{
		$default = Optimiser::$context === 'background' ? 19 : 0;
		$level = (int)apply_filters('oio_nice', $default, Optimiser::$context);
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
		// A random name: in a temporary folder shared by the users of the server,
		// a predictable one could be a link to a file of the site, overwritten.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam('oio.png');
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
	 * file: its WebP or AVIF version exists, or for a
	 * compression, its original is kept as a backup.
	 *
	 * @param string $filepath
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function is_done($filepath)
	{
		if (static::extension() !== '') {
			return file_exists($filepath . static::extension());
		}
		return file_exists(self::backup_path($filepath));
	}

	/**
	 * Returns the folder of the original images:
	 * uploads/oio-originals by default. The
	 * oio_backup_dir filter moves it, e.g. out of the
	 * web root: the originals keep their metadata.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function backup_dir()
	{
		$default = self::uploads_dir() . DIRECTORY_SEPARATOR . self::BACKUP_DIR;
		return untrailingslashit((string)apply_filters('oio_backup_dir', $default));
	}

	/**
	 * Returns the path of the backup of an image of
	 * the uploads folder: the same path, in the
	 * folder of the original images.
	 *
	 * @param string $filepath
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function backup_path($filepath)
	{
		return self::backup_dir() . DIRECTORY_SEPARATOR . self::relative_path($filepath);
	}

	/**
	 * Denies the web access to the folder of the
	 * original images: they keep the metadata stripped
	 * from the optimised images (GPS position, camera),
	 * and nothing serves them. The .htaccess works with
	 * Apache and LiteSpeed, nginx needs a rule (see the
	 * README). The index.php hides the files where the
	 * folders are listed.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function protect_backup_dir()
	{
		$dir = self::backup_dir();
		if (!wp_mkdir_p($dir)) {
			return;
		}
		$files = [
			'.htaccess' => "# Written by OIO: the original images are not served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		];
		foreach ($files as $name => $content) {
			$path = $dir . DIRECTORY_SEPARATOR . $name;
			if (!file_exists($path)) {
				file_put_contents($path, $content);
			}
		}
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
		$backup = self::backup_path($filepath);
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
		$backup = self::backup_path($filepath);
		self::protect_backup_dir();
		if (file_exists($backup)) {
			return true;
		}
		if (!wp_mkdir_p(dirname($backup)) || !copy($filepath, $backup)) {
			Errors::add(isset($args['id']) ? $args['id'] : 0, static::cmd_name(), $filepath, 'cannot write the backup of the original image');
			return false;
		}
		self::remember($args, $backup);
		return true;
	}

	/**
	 * Adds a file made by OIO to the list of files
	 * of the attachment, used to delete them.
	 *
	 * @param array $args
	 * @param string $path
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function remember($args, $path)
	{
		if (empty($args['id'])) {
			return;
		}
		$files = get_post_meta($args['id'], self::FILES_KEY, true);
		$files = is_array($files) ? $files : [];
		$relative = self::relative_path($path);
		if (!in_array($relative, $files, true)) {
			$files[] = $relative;
			update_post_meta($args['id'], self::FILES_KEY, $files);
		}
	}

	/**
	 * Deletes the files OIO made for an attachment:
	 * the WebP and AVIF files and the backups of the
	 * original and of every size, plus any other file
	 * listed for the attachment (e.g. of sizes that no
	 * longer exist).
	 *
	 * @param $id
	 * @return void
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public static function delete($id)
	{
		$original = get_attached_file($id);
		if (!$original || !in_array(get_post_mime_type($id), [Mimes::PNG, Mimes::JPG], true)) {
			return;
		}

		$files = [$original];
		$meta = wp_get_attachment_metadata($id);
		if (!empty($meta['sizes'])) {
			foreach ($meta['sizes'] as $size) {
				if (!empty($size['file'])) {
					$files[] = dirname($original) . DIRECTORY_SEPARATOR . $size['file'];
				}
			}
		}

		$paths = [];
		foreach (array_unique($files) as $file) {
			$paths[] = $file . '.webp';
			$paths[] = $file . '.avif';
			$paths[] = self::backup_path($file);
		}
		$listed = get_post_meta($id, self::FILES_KEY, true);
		if (is_array($listed)) {
			foreach ($listed as $relative) {
				$paths[] = self::uploads_dir() . DIRECTORY_SEPARATOR . $relative;
			}
		}

		foreach (array_unique($paths) as $path) {
			if (file_exists($path) && self::contained($path)) {
				unlink($path);
			}
		}
	}

	/**
	 * Determines if a path is in the uploads folder or
	 * in the folder of the originals: the paths come
	 * from the metadata of the attachments, OIO never
	 * reads, writes or deletes a file elsewhere (as
	 * WordPress with wp_delete_file_from_directory()).
	 * The links are followed: a link in the uploads
	 * folder to a file out of it is out of it, an
	 * uploads folder that is a link is not.
	 *
	 * @param string $path
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function contained($path)
	{
		$path = wp_normalize_path($path);
		if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
			return false;
		}
		$real = self::real_path($path);
		if ($real === false) {
			return false;
		}
		foreach ([self::uploads_dir(), self::backup_dir()] as $dir) {
			$dir = self::real_path(wp_normalize_path($dir));
			if ($dir !== false && strpos($real, trailingslashit($dir)) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the path of a file with its links
	 * resolved. A file to create (e.g. a WebP version)
	 * is resolved from its closest existing folder.
	 * False for a link to nothing: writing it would
	 * create its target, wherever it is. The streams
	 * (e.g. s3://) have no links, as for WordPress.
	 *
	 * @param string $path
	 * @return string|false
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function real_path($path)
	{
		if (wp_is_stream($path)) {
			return $path;
		}
		$missing = '';
		while (($real = realpath($path)) === false) {
			$parent = dirname($path);
			if (is_link($path) || $parent === $path) {
				return false;
			}
			$missing = '/' . basename($path) . $missing;
			$path = $parent;
		}
		return untrailingslashit(wp_normalize_path($real)) . $missing;
	}

	/**
	 * Returns the uploads folder.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function uploads_dir()
	{
		return wp_get_upload_dir()['basedir'];
	}

	/**
	 * Returns the path of a file relative to the
	 * uploads folder.
	 *
	 * @param string $path
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function relative_path($path)
	{
		$base = self::uploads_dir();
		if (strpos($path, $base) === 0) {
			$path = substr($path, strlen($base));
		}
		return ltrim($path, '/\\');
	}

	/**
	 * Checks if the library is installed on the
	 * server, once per request (the status of the
	 * images asks it often). Only the supported
	 * libraries can be run.
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
		if (!in_array(static::cmd_name(), $allowed, true)) {
			return false;
		}
		return self::binary(static::cmd_name()) !== null;
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
		if (file_exists($file) && self::contained($file)) {
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
		return self::META_KEY . '_' . static::cmd_name();
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
