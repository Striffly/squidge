<?php

/**
 * Files
 *
 * The files of the images: which they are, where
 * their originals are kept, which OIO made, and the
 * folders OIO may touch. OIO reads, writes and
 * deletes the files through it only.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Media;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Files
{
	/**
	 * The types of the images OIO optimises.
	 */
	const JPG = 'image/jpeg';
	const PNG = 'image/png';
	const MIMES = [self::JPG, self::PNG];

	/**
	 * The folder, in the uploads folder, where the
	 * original images are kept before being optimised
	 * in place, with the same tree as the uploads
	 * folder (see backup_dir()).
	 */
	const BACKUP_DIR = 'octopus-originals';

	/**
	 * The meta listing the files OIO made for an image
	 * (WebP, AVIF and backups), relative to the uploads
	 * folder: they are deleted with it, even once its
	 * sizes changed.
	 */
	const LIST_KEY = '_octopus_files';

	/**
	 * Returns the files of an image: its main file,
	 * then each size, from its metadata, or from the
	 * attached file without metadata.
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved yet.
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function of($id, $metadata = null)
	{
		$metadata = $metadata ?: wp_get_attachment_metadata($id);
		$main = !empty($metadata['file']) ? self::uploads_dir() . DIRECTORY_SEPARATOR . $metadata['file'] : get_attached_file($id);
		if (!$main) {
			return [];
		}
		$files = [$main];
		$sizes = isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
		foreach ($sizes as $size) {
			if (!empty($size['file'])) {
				$files[] = dirname($main) . DIRECTORY_SEPARATOR . $size['file'];
			}
		}
		return array_values(array_unique($files));
	}

	/**
	 * Returns the uploads folder.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function uploads_dir()
	{
		return wp_get_upload_dir()['basedir'];
	}

	/**
	 * Returns the path of a file relative to the
	 * uploads folder, as saved in the metas. On
	 * Windows, with / only: the paths of the
	 * thumbnails join the folder of the metadata
	 * (2026/10) with \, and must match whatever
	 * their separators.
	 *
	 * @param string $path
	 * @return string
	 * @since 1.0.0
	 */
	public static function relative($path)
	{
		$base = self::uploads_dir();
		if (strpos($path, $base) === 0) {
			$path = substr($path, strlen($base));
		}
		if (PHP_OS_FAMILY === 'Windows') {
			$path = str_replace('\\', '/', $path);
		}
		return ltrim($path, '/\\');
	}

	/**
	 * Returns the folder of the original images:
	 * uploads/octopus-originals by default. The
	 * octopus_backup_dir filter moves it, e.g. out of
	 * the web root: the originals keep their metadata.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function backup_dir()
	{
		$default = self::uploads_dir() . DIRECTORY_SEPARATOR . self::BACKUP_DIR;
		return untrailingslashit((string)apply_filters('octopus_backup_dir', $default));
	}

	/**
	 * Returns the path of the backup of an image of
	 * the uploads folder: the same path, in the
	 * folder of the original images.
	 *
	 * @param string $path
	 * @return string
	 * @since 1.0.0
	 */
	public static function backup_path($path)
	{
		return self::backup_dir() . DIRECTORY_SEPARATOR . self::relative($path);
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
	 */
	public static function protect_backup_dir()
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
	 * Deletes a file, if it exists and OIO may touch
	 * it (see contained()).
	 *
	 * @param string $path
	 * @since 1.0.0
	 */
	public static function delete($path)
	{
		if (file_exists($path) && self::contained($path)) {
			wp_delete_file($path);
		}
	}

	/**
	 * Adds a file made by OIO to the list of the files
	 * of an image.
	 *
	 * @param int $id
	 * @param string $path
	 * @since 1.0.0
	 */
	public static function remember($id, $path)
	{
		if (!$id) {
			return;
		}
		$listed = get_post_meta($id, self::LIST_KEY, true);
		$listed = is_array($listed) ? $listed : [];
		$relative = self::relative($path);
		if (!in_array($relative, $listed, true)) {
			$listed[] = $relative;
			update_post_meta($id, self::LIST_KEY, $listed);
		}
	}

	/**
	 * Returns the absolute paths of the files listed for
	 * an image (WebP, AVIF and backups, of current and
	 * former sizes).
	 *
	 * @param int $id
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function listed($id)
	{
		$listed = get_post_meta($id, self::LIST_KEY, true);
		if (!is_array($listed)) {
			return [];
		}
		$base = self::uploads_dir();
		return array_map(function ($relative) use ($base) {
			return $base . DIRECTORY_SEPARATOR . $relative;
		}, $listed);
	}

	/**
	 * Saves the list of the files of an image, from
	 * absolute paths.
	 *
	 * @param int $id
	 * @param string[] $paths
	 * @since 1.0.0
	 */
	public static function save_listed($id, $paths)
	{
		$relative = array_values(array_map([self::class, 'relative'], $paths));
		if (empty($relative)) {
			delete_post_meta($id, self::LIST_KEY);
		} else {
			update_post_meta($id, self::LIST_KEY, $relative);
		}
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
}
