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

namespace Octopus\Media;

use Octopus\Encoders\AVIF;
use Octopus\Encoders\JPG;
use Octopus\Encoders\PNG;
use Octopus\Encoders\WebP;
use Octopus\Log\Errors;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Cleaner
{
	/**
	 * The prefix of the metas OIO writes on the images:
	 * the settings used, the files made, the errors and
	 * the bytes measured.
	 *
	 * @var string
	 */
	const META_PREFIX = '_octopus_';

	/**
	 * Returns the IDs of the images OIO processed:
	 * with an OIO meta. One query on the meta keys,
	 * meta queries joining postmeta for each key are slow.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	public static function ids()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API lists the posts by meta key prefix; read live, while a job changes them.
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY post_id",
			$wpdb->esc_like(self::META_PREFIX) . '%'
		));
		return array_map('intval', $ids);
	}

	/**
	 * Determines if OIO left something on an image:
	 * formats made, files, errors, bytes measured.
	 * Restoring it then removes them, see restore().
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	public static function has_data($id)
	{
		return self::meta_keys($id) !== [];
	}

	/**
	 * Returns the keys of the metas OIO wrote on an
	 * image.
	 *
	 * @param int $id
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function meta_keys($id)
	{
		return array_values(array_filter(array_keys(get_post_meta($id)), function ($key) {
			return strpos($key, self::META_PREFIX) === 0;
		}));
	}

	/**
	 * Deletes the metas OIO wrote on an image.
	 *
	 * @param int $id
	 * @since 1.0.0
	 */
	private static function delete_metas($id)
	{
		foreach (self::meta_keys($id) as $key) {
			delete_post_meta($id, $key);
		}
	}

	/**
	 * Removes the WebP and AVIF files of an image, of
	 * the original and every size, and their metas.
	 * The compressed JPG and PNG files stay.
	 *
	 * @param int $id
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 */
	public static function remove_formats($id)
	{
		return Lock::run($id, function () use ($id) {
			foreach (self::files($id) as $file) {
				Files::delete_variants($file);
			}
			// Listed while they keep their original.
			Files::save_listed($id, array_filter(Files::listed($id), function ($file) {
				return file_exists(Files::backup_path($file));
			}));
			delete_post_meta($id, Optimiser::HEAVIER_KEY);
			foreach ([WebP::class, AVIF::class] as $service) {
				delete_post_meta($id, $service::meta_key());
				Errors::clear_attachment($id, $service::FORMAT);
			}
			Stats::save($id);
			return true;
		});
	}

	/**
	 * Deletes the files OIO made for an image, when it
	 * is deleted from the media library: the WebP and
	 * AVIF files and the backups of the original and of
	 * every size, and the other files listed for it
	 * (e.g. of sizes that no longer exist).
	 *
	 * @param int $id
	 * @since 1.0.0
	 */
	public static function delete_files($id)
	{
		if (!in_array(get_post_mime_type($id), Files::MIMES, true)) {
			return;
		}
		self::delete_made($id, null);
	}

	/**
	 * Determines if an image was deleted, e.g. from the
	 * media library while a job processed it: read
	 * again, not from the cache of the request.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	public static function deleted($id)
	{
		wp_cache_delete($id, 'posts');
		return get_post($id) === null;
	}

	/**
	 * Deletes what OIO made for an image deleted while
	 * it processed it: the files made after the media
	 * library deleted them (WebP, AVIF, originals kept
	 * with their GPS position), from the metadata known
	 * then, and the metas written since, left without
	 * their image.
	 *
	 * @param int $id
	 * @param array $metadata
	 * @since 1.0.0
	 */
	public static function forget($id, $metadata)
	{
		self::delete_made($id, $metadata);
		self::delete_metas($id);
	}

	/**
	 * Returns the files of an image: of its current
	 * sizes, and the ones listed (e.g. of sizes that
	 * no longer exist).
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved any more.
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function files($id, $metadata = null)
	{
		return array_unique(array_merge(Files::of($id, $metadata), Files::listed($id)));
	}

	/**
	 * Deletes the files OIO made for an image: the
	 * versions and originals of its files, and of the
	 * ones listed (e.g. of sizes that no longer exist).
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved any more.
	 * @since 1.0.0
	 */
	private static function delete_made($id, $metadata)
	{
		foreach (self::files($id, $metadata) as $file) {
			Files::delete_variants($file);
			Files::delete(Files::backup_path($file));
		}
	}

	/**
	 * Restores the original images of an image: the
	 * backups are put back in place, then every OIO
	 * file (WebP, AVIF, backups) and meta is removed,
	 * the bytes saved too (see Stats). The metadata
	 * gets the sizes of the originals.
	 *
	 * @param int $id
	 * @return bool|null False if the original of a compressed
	 *                   image is missing: it stays compressed.
	 *                   Null if another request is processing
	 *                   the image.
	 * @since 1.0.0
	 */
	public static function restore($id)
	{
		return Lock::run($id, function () use ($id) {
			$restored = true;
			$compressed = get_post_meta($id, JPG::meta_key(), true) !== '' || get_post_meta($id, PNG::meta_key(), true) !== '';
			foreach (Files::of($id) as $index => $file) {
				$backup = Files::backup_path($file);
				if (file_exists($backup) && Files::contained($file) && Files::contained($backup)) {
					copy($backup, $file);
					wp_delete_file($backup);
				} elseif ($index === 0 && $compressed && in_array(get_post_mime_type($id), Files::MIMES, true)) {
					$restored = false;
				}
				Files::delete_variants($file);
			}
			// The files of former sizes too. Without its original, e.g. the folder
			// of the originals moved, the image stays listed: its originals are
			// restored once the folder is back.
			$listed = Files::listed($id);
			foreach ($listed as $file) {
				Files::delete_variants($file);
				if ($restored) {
					Files::delete(Files::backup_path($file));
				}
			}
			self::delete_metas($id);
			Files::save_listed($id, $restored ? [] : $listed);
			Optimiser::save_file_sizes($id);
			return $restored;
		});
	}
}
