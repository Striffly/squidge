<?php

/**
 * Cleaner
 *
 * Cleaner undoes what OIO did to an image: it
 * removes the WebP and AVIF files, or restores the
 * original images and removes every OIO file
 * and meta.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Package;

use OIO\Log\Errors;
use OIO\Services\AVIF;
use OIO\Services\JPG;
use OIO\Services\PNG;
use OIO\Services\WebP;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Cleaner
{
	/**
	 * Returns the IDs of the images OIO processed:
	 * with an OIO meta. One query on the meta keys,
	 * meta queries joining postmeta for each key are slow.
	 *
	 * @return int[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function ids()
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY post_id",
			$wpdb->esc_like('_oio_') . '%'
		));
		return array_map('intval', $ids);
	}

	/**
	 * Removes the WebP and AVIF files of an image, of
	 * the original and every size, and their metas.
	 * The compressed JPG and PNG files stay.
	 *
	 * @param int $id
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function remove_formats($id)
	{
		return Lock::run($id, function () use ($id) {
			foreach (self::files($id) as $file) {
				self::unlink($file . WebP::extension());
				self::unlink($file . AVIF::extension());
			}
			$listed = self::listed($id);
			foreach ($listed as $index => $path) {
				if (preg_match('/\.(webp|avif)$/', $path)) {
					self::unlink($path);
					unset($listed[$index]);
				}
			}
			self::save_listed($id, $listed);
			foreach ([WebP::class, AVIF::class] as $service) {
				delete_post_meta($id, $service::meta_key());
				Errors::clear_attachment($id, $service::cmd_name());
			}
			return true;
		});
	}

	/**
	 * Restores the original images of an image: the
	 * backups are put back in place, then every OIO
	 * file (WebP, AVIF, backups) and meta is removed.
	 *
	 * @param int $id
	 * @return bool|null False if the original of a compressed
	 *                   image is missing: it stays compressed.
	 *                   Null if another request is processing
	 *                   the image.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function restore($id)
	{
		return Lock::run($id, function () use ($id) {
			$restored = true;
			$compressed = get_post_meta($id, JPG::meta_key(), true) !== '' || get_post_meta($id, PNG::meta_key(), true) !== '';
			$original = get_attached_file($id);
			foreach (self::files($id) as $file) {
				$backup = Service::backup_path($file);
				if (file_exists($backup) && Service::contained($file)) {
					copy($backup, $file);
					unlink($backup);
				} elseif ($file === $original && $compressed && in_array(get_post_mime_type($id), Status::MIMES, true)) {
					$restored = false;
				}
				self::unlink($file . WebP::extension());
				self::unlink($file . AVIF::extension());
			}
			// Without its original, e.g. the folder of the originals moved, the
			// files kept elsewhere stay listed: they are restored once it is back.
			$kept = [];
			foreach (self::listed($id) as $path) {
				if (!$restored && !preg_match('/\.(webp|avif)$/', $path)) {
					$kept[] = $path;
					continue;
				}
				self::unlink($path);
			}
			foreach (array_keys(get_post_meta($id)) as $key) {
				if (strpos($key, '_oio_') === 0) {
					delete_post_meta($id, $key);
				}
			}
			self::save_listed($id, $kept);
			return $restored;
		});
	}

	/**
	 * Returns the files of an image: the original and
	 * every size.
	 *
	 * @param int $id
	 * @return string[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function files($id)
	{
		$original = get_attached_file($id);
		if (!$original) {
			return [];
		}
		$files = [$original];
		$metadata = wp_get_attachment_metadata($id);
		if (!empty($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size) {
				if (!empty($size['file'])) {
					$files[] = dirname($original) . DIRECTORY_SEPARATOR . $size['file'];
				}
			}
		}
		return array_unique($files);
	}

	/**
	 * Returns the absolute paths of the files listed for
	 * an image (WebP, AVIF and backups, of current and
	 * former sizes).
	 *
	 * @param int $id
	 * @return string[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function listed($id)
	{
		$listed = get_post_meta($id, Service::FILES_KEY, true);
		if (!is_array($listed)) {
			return [];
		}
		$base = wp_get_upload_dir()['basedir'];
		return array_map(function ($relative) use ($base) {
			return $base . DIRECTORY_SEPARATOR . $relative;
		}, $listed);
	}

	/**
	 * Saves the list of files of an image, from absolute
	 * paths.
	 *
	 * @param int $id
	 * @param string[] $paths
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function save_listed($id, $paths)
	{
		$base = wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR;
		$relative = array_values(array_map(function ($path) use ($base) {
			return substr($path, strlen($base));
		}, $paths));
		if (empty($relative)) {
			delete_post_meta($id, Service::FILES_KEY);
		} else {
			update_post_meta($id, Service::FILES_KEY, $relative);
		}
	}

	/**
	 * Deletes a file if it exists.
	 *
	 * @param string $path
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function unlink($path)
	{
		if (file_exists($path) && Service::contained($path)) {
			unlink($path);
		}
	}
}
