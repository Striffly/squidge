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
	 * Runs a library command on a file, and records
	 * an error if it exits with a non zero code.
	 *
	 * @param string $command
	 * @param string $filepath
	 * @param array $args
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	protected static function run($command, $filepath, $args)
	{
		$output = [];
		exec(self::nice() . $command . ' 2>&1', $output, $code);
		if ($code === 0) {
			return true;
		}
		Errors::add(isset($args['id']) ? $args['id'] : 0, static::cmd_name(), $filepath, self::error_message($output, $code, $filepath));
		return false;
	}

	/**
	 * Returns the nice prefix lowering the priority of
	 * the commands: in background jobs by default, the
	 * requests of the site go first. The oio_nice
	 * filter sets the level, from 0 (normal) to 19
	 * (lowest), by context (see Optimiser::$context).
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function nice()
	{
		$default = Optimiser::$context === 'background' ? 19 : 0;
		$level = (int)apply_filters('oio_nice', $default, Optimiser::$context);
		if ($level <= 0) {
			return '';
		}
		static $available = null;
		if ($available === null) {
			$available = !empty(shell_exec('which nice'));
		}
		return $available ? sprintf('nice -n %d ', min(19, $level)) : '';
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
		$saved = $editor->save(get_temp_dir() . uniqid('oio-') . '.png', 'image/png');
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
	 * @param array $output
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
			if (file_exists($path)) {
				unlink($path);
			}
		}
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
		// Checked once per request, the status of the images asks it often.
		static $installed = [];
		if (!isset($installed[static::cmd_name()])) {
			$return = shell_exec(sprintf("which %s", escapeshellarg(static::cmd_name())));
			$installed[static::cmd_name()] = !empty($return);
		}
		return $installed[static::cmd_name()];
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
