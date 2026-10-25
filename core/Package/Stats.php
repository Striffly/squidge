<?php

/**
 * Stats
 *
 * Stats measures the bytes saved on each image, from
 * the sizes of its files, and adds them up for the
 * media library.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Package;

use OIO\Admin\Delivery;
use OIO\Admin\Fields;
use OIO\Services\AVIF;
use OIO\Services\WebP;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Stats
{
	/**
	 * The meta keys of the sizes of an image, in bytes,
	 * summed over its main file and every thumbnail.
	 * Separate numbers, so that SQL can sum them:
	 * - original: the originals, the backups of the
	 *   files compressed in place, the files otherwise;
	 * - compressed: the JPG and PNG files as they are;
	 * - modern: what a browser supporting AVIF gets when
	 *   the formats are served, the AVIF, the WebP or the
	 *   JPG or PNG, the first that exists (see Picture);
	 * - extra: the WebP and AVIF files, on the disk on
	 *   top of the images and their originals.
	 *
	 * @var array
	 */
	const KEYS = [
		'original' => '_oio_bytes_original',
		'compressed' => '_oio_bytes_compressed',
		'modern' => '_oio_bytes_modern',
		'extra' => '_oio_bytes_extra',
	];

	/**
	 * The totals of the media library, once queried in
	 * this request.
	 *
	 * @var array|null
	 */
	private static $totals = null;

	/**
	 * Returns the sizes of the files of an image, see
	 * KEYS, or null if none of its files exists.
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved yet.
	 * @return array|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function measure($id, $metadata = null)
	{
		$metadata = $metadata ?: wp_get_attachment_metadata($id);
		if (empty($metadata['file'])) {
			return null;
		}
		$main = wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'];
		$files = [$main];
		$sizes = isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
		foreach ($sizes as $size) {
			if (!empty($size['file'])) {
				$files[] = dirname($main) . DIRECTORY_SEPARATOR . $size['file'];
			}
		}
		$bytes = array_fill_keys(array_keys(self::KEYS), 0);
		$found = false;
		clearstatcache();
		foreach (array_unique($files) as $file) {
			if (!file_exists($file)) {
				continue;
			}
			$found = true;
			$size = (int)filesize($file);
			$backup = Service::backup_path($file);
			$bytes['original'] += file_exists($backup) ? (int)filesize($backup) : $size;
			$bytes['compressed'] += $size;
			$served = $size;
			// The order of the <source> elements: the AVIF first, then the WebP.
			foreach ([WebP::extension(), AVIF::extension()] as $extension) {
				if (file_exists($file . $extension)) {
					$served = (int)filesize($file . $extension);
					$bytes['extra'] += $served;
				}
			}
			$bytes['modern'] += $served;
		}
		return $found ? $bytes : null;
	}

	/**
	 * Saves the sizes of the files of an image in its
	 * metas. An image no library processed has none:
	 * it would count as processed (see Cleaner::ids()).
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved yet.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function save($id, $metadata = null)
	{
		$bytes = self::processed($id) ? self::measure($id, $metadata) : null;
		foreach (self::KEYS as $name => $key) {
			if ($bytes === null) {
				delete_post_meta($id, $key);
			} else {
				update_post_meta($id, $key, $bytes[$name]);
			}
		}
	}

	/**
	 * Determines if a library processed an image.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function processed($id)
	{
		foreach (Optimiser::SERVICES as $service) {
			if ($service::has_compressed($id)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the sizes saved for an image, see KEYS, or
	 * null if it has none (not processed, or processed
	 * before OIO measured them).
	 *
	 * @param int $id
	 * @return array|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function of($id)
	{
		$bytes = [];
		foreach (self::KEYS as $name => $key) {
			$value = get_post_meta($id, $key, true);
			if ($value === '') {
				return null;
			}
			$bytes[$name] = (int)$value;
		}
		return $bytes;
	}

	/**
	 * Returns the sizes of the media library, see KEYS,
	 * summed over the JPG and PNG images, and the number
	 * of images measured. One query, once per request,
	 * or again with $refresh: a SUM by key, as a GROUP
	 * BY sorts the rows in a temporary table. On 50,000
	 * images (600,000 meta rows), about 0.4 s.
	 *
	 * @param bool $refresh
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function totals($refresh = false)
	{
		global $wpdb;
		if (self::$totals !== null && !$refresh) {
			return self::$totals;
		}
		$sums = [];
		foreach (self::KEYS as $name => $key) {
			$sums[] = "SUM(IF(m.meta_key = %s, CAST(m.meta_value AS UNSIGNED), 0)) AS {$name}";
		}
		$keys = array_values(self::KEYS);
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT " . implode(', ', $sums) . ", SUM(m.meta_key = %s) AS images
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key IN (" . implode(', ', array_fill(0, count($keys), '%s')) . ")
			AND p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND p.post_mime_type IN (" . implode(', ', array_fill(0, count(Status::MIMES), '%s')) . ")",
			array_merge($keys, [self::KEYS['original']], $keys, Status::MIMES)
		), ARRAY_A);
		$totals = [];
		foreach (array_merge(array_keys(self::KEYS), ['images']) as $name) {
			$totals[$name] = isset($row[$name]) ? (int)$row[$name] : 0;
		}
		self::$totals = $totals;
		return $totals;
	}

	/**
	 * Returns the size the site serves: modern when it
	 * serves the WebP and AVIF, in <picture> (see
	 * Display) or from the server (see Delivery),
	 * compressed otherwise.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function served()
	{
		return Fields::get('oio_display_formats') || Fields::get(Delivery::OPTION) ? 'modern' : 'compressed';
	}

	/**
	 * Returns a size in bytes as shown, e.g. "1.2 GB",
	 * "0 B" rather than "0.0 B".
	 *
	 * @param int $bytes
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function size($bytes)
	{
		return (string)size_format($bytes, $bytes >= KB_IN_BYTES ? 1 : 0);
	}

	/**
	 * Returns the reduction of a size from the
	 * originals, e.g. "−48 %", or null without
	 * originals.
	 *
	 * @param array $bytes Sizes, see KEYS.
	 * @param string $name compressed or modern.
	 * @return string|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function reduction($bytes, $name)
	{
		if (empty($bytes['original'])) {
			return null;
		}
		$percent = (int)round((1 - $bytes[$name] / $bytes['original']) * 100);
		$sign = $percent > 0 ? "\u{2212}" : ($percent < 0 ? '+' : '');
		return $sign . number_format_i18n(abs($percent)) . "\u{00a0}%";
	}
}
