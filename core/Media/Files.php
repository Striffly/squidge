<?php

/**
 * Files
 *
 * The files of the images: which they are, where
 * their WebP and AVIF versions and their originals
 * are kept, which OIO made, and the folders OIO may
 * touch. OIO reads, writes and deletes the files
 * through it only.
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
	 * The folder of OIO, in the uploads folder, and in
	 * it the folder of the WebP and AVIF versions, with
	 * the same tree as the uploads folder (see
	 * variant()). WordPress never writes there: no
	 * image added to the media library takes the place
	 * of a version, which the server would serve for
	 * the image.
	 */
	const DIR = 'octopus';
	const FORMATS_DIR = 'formats';

	/**
	 * The folder, in the folder of OIO, of the files a
	 * library or the image editor writes before they
	 * are moved in place, or removed (see temp_file()).
	 */
	const TEMP_DIR = 'temp';

	/**
	 * The folder, in the folder of OIO, where the
	 * original images are kept before being optimised
	 * in place, with the same tree as the uploads
	 * folder (see backup_dir()): originals-, then a
	 * key of the site, drawn once and kept in an
	 * option. Its URL cannot be guessed from the one of
	 * an image, where the server does not read the
	 * .htaccess denying it (nginx).
	 */
	const BACKUP_DIR = 'originals-';
	const KEY_OPTION = 'octopus_originals_key';

	/**
	 * The meta listing the files of an image OIO
	 * processed, of its current and former sizes,
	 * relative to the uploads folder: what OIO made
	 * from them (WebP, AVIF, originals) is found from
	 * them, and deleted with the image even once its
	 * sizes changed, wherever the folder of the
	 * originals is.
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
	 * Returns the folder of OIO.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function dir()
	{
		return self::uploads_dir() . DIRECTORY_SEPARATOR . self::DIR;
	}

	/**
	 * Returns the folder of the WebP and AVIF versions.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function formats_dir()
	{
		return self::dir() . DIRECTORY_SEPARATOR . self::FORMATS_DIR;
	}

	/**
	 * Returns the path of the WebP or AVIF version of
	 * an image of the uploads folder: the same path, in
	 * the folder of the versions, with the extension
	 * added (photo.jpg.webp).
	 *
	 * @param string $path
	 * @param string $extension .webp or .avif.
	 * @return string
	 * @since 1.0.0
	 */
	public static function variant($path, $extension)
	{
		return self::formats_dir() . DIRECTORY_SEPARATOR . self::relative($path) . $extension;
	}

	/**
	 * Returns the URL of a file of the uploads folder.
	 *
	 * @param string $path
	 * @return string
	 * @since 1.0.0
	 */
	public static function url($path)
	{
		return wp_get_upload_dir()['baseurl'] . '/' . self::relative($path);
	}

	/**
	 * Makes the folder of a file OIO writes, e.g. a
	 * WebP version: the libraries do not. The folder
	 * of OIO gets an index.php: where the server lists
	 * the folders, it would show the name of the one
	 * of the originals.
	 *
	 * @param string $path
	 * @return bool
	 * @since 1.0.0
	 */
	public static function make_dir($path)
	{
		if (!wp_mkdir_p(dirname($path))) {
			return false;
		}
		$index = self::dir() . DIRECTORY_SEPARATOR . 'index.php';
		if (is_dir(self::dir()) && !file_exists($index)) {
			file_put_contents($index, "<?php\n// Silence is golden.\n");
		}
		return true;
	}

	/**
	 * Returns the folder of the temporary files.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function temp_dir()
	{
		return self::dir() . DIRECTORY_SEPARATOR . self::TEMP_DIR;
	}

	/**
	 * Creates an empty temporary file with a random
	 * name, for a library or the image editor to write:
	 * created by this request only, so that no link set
	 * there before is written through. In the folder of
	 * OIO, not the temporary folder the users of a
	 * shared server share, and in the uploads folder as
	 * the versions, so that one moves in place at once.
	 * Denied to the web as the originals. The files a
	 * stopped request left go after an hour.
	 *
	 * @param string $suffix E.g. .png.
	 * @return string|null Its path, null if it could not be created.
	 * @since 1.0.0
	 */
	public static function temp_file($suffix)
	{
		$dir = self::temp_dir();
		if (!self::protect_temp_dir()) {
			return null;
		}
		foreach (glob($dir . DIRECTORY_SEPARATOR . 'octopus-*') ?: [] as $left) {
			if (@filemtime($left) < time() - HOUR_IN_SECONDS) {
				wp_delete_file($left);
			}
		}
		$path = $dir . DIRECTORY_SEPARATOR . 'octopus-' . strtolower(wp_generate_password(12, false)) . $suffix;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation (x), which WP_Filesystem does not offer.
		$handle = @fopen($path, 'x');
		if ($handle === false) {
			return null;
		}
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
		return $path;
	}

	/**
	 * Returns the folder of the original images:
	 * uploads/octopus/originals-{key} by default. The
	 * octopus_backup_dir filter moves it, e.g. out of
	 * the web root: the originals keep their metadata.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function backup_dir()
	{
		$default = self::dir() . DIRECTORY_SEPARATOR . self::BACKUP_DIR . self::key();
		return untrailingslashit((string)apply_filters('octopus_backup_dir', $default));
	}

	/**
	 * Returns the key of the folder of the originals:
	 * the one kept, else the one of a folder already
	 * there (e.g. OIO deleted then installed again, its
	 * files kept), else a new one, 16 letters and
	 * digits.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function key()
	{
		$key = get_option(self::KEY_OPTION);
		if (is_string($key) && preg_match('/^[a-z0-9]{16}$/', $key)) {
			return $key;
		}
		$found = glob(self::dir() . DIRECTORY_SEPARATOR . self::BACKUP_DIR . '*', GLOB_ONLYDIR);
		$found = preg_grep('/' . preg_quote(self::BACKUP_DIR, '/') . '[a-z0-9]{16}$/', $found ?: []);
		$key = $found ? substr(reset($found), -16) : strtolower(wp_generate_password(16, false));
		update_option(self::KEY_OPTION, $key);
		return $key;
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
	 * README), the name of the folder cannot be guessed
	 * meanwhile.
	 *
	 * @since 1.0.0
	 */
	public static function protect_backup_dir()
	{
		self::protect(self::backup_dir(), 'original images');
	}

	/**
	 * Denies the web access to the folder of the
	 * temporary files, e.g. the RGB copy of a CMYK
	 * image.
	 *
	 * @return bool Whether the folder is there.
	 * @since 1.0.0
	 */
	public static function protect_temp_dir()
	{
		return self::protect(self::temp_dir(), 'temporary files');
	}

	/**
	 * Makes a folder of OIO the web must not reach,
	 * with a .htaccess denying it, and an index.php
	 * hiding its files where the folders are listed.
	 *
	 * @param string $dir
	 * @param string $what What it holds, e.g. original images.
	 * @return bool Whether the folder is there.
	 * @since 1.0.0
	 */
	private static function protect($dir, $what)
	{
		if (!self::make_dir($dir . DIRECTORY_SEPARATOR . 'index.php')) {
			return false;
		}
		$files = [
			'.htaccess' => "# Written by OIO: the " . $what . " are not served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		];
		foreach ($files as $name => $content) {
			$path = $dir . DIRECTORY_SEPARATOR . $name;
			if (!file_exists($path)) {
				file_put_contents($path, $content);
			}
		}
		return true;
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
	 * Adds a file of an image OIO processed (made its
	 * WebP or AVIF version, or kept its original) to
	 * the list of the image.
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
	 * Returns the absolute paths of the files of an
	 * image OIO processed, of current and former
	 * sizes.
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
