<?php

/**
 * Optimiser
 *
 * Optimiser runs the libraries on an attachment, the
 * same way on upload, from the CLI and in the
 * background tasks.
 *
 * @package     OIO
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace OIO\Package;

use Exception;
use OIO\Admin\Fields;
use OIO\Log\Logger;
use OIO\Services\AVIF;
use OIO\Services\JPG;
use OIO\Services\PNG;
use OIO\Services\WebP;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Optimiser
{
	/**
	 * The libraries by format, in the order they run:
	 * WebP and AVIF are made from the original before
	 * the JPG and PNG files are compressed in place.
	 *
	 * @var array
	 */
	const SERVICES = [
		'webp' => WebP::class,
		'avif' => AVIF::class,
		'jpg' => JPG::class,
		'png' => PNG::class,
	];

	/**
	 * Set while WordPress regenerates the thumbnails of
	 * an image, so that the upload hook leaves it to
	 * regenerate().
	 *
	 * @var bool
	 */
	public static $regenerating = false;

	/**
	 * Where the images are optimised: "upload" during a
	 * request of the admin (upload, media library
	 * action), "background" in a background job, "cli"
	 * in WP-CLI. Passed to the oio_nice and
	 * oio_avif_jobs filters.
	 *
	 * @var string
	 */
	public static $context = 'upload';

	/**
	 * Returns the args of each format from the options
	 * page: the enabled formats only, or all of them.
	 *
	 * @param bool $enabled_only
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function settings($enabled_only = true)
	{
		$settings = [
			'webp' => ['quality' => Fields::get('oio_webp_quality')],
			'avif' => ['quality' => Fields::get('oio_avif_quality')],
			'jpg' => ['quality' => Fields::get('oio_jpg_quality')],
			'png' => ['optimization' => Fields::get('oio_png_quality')],
		];
		foreach (array_keys($settings) as $format) {
			if ($enabled_only && !Fields::get('oio_' . $format . '_enable')) {
				unset($settings[$format]);
			}
		}
		return $settings;
	}

	/**
	 * Optimises an attachment with each format of the
	 * settings.
	 *
	 * @param int $id
	 * @param array|null $metadata The attachment metadata, when it is not saved yet (on upload).
	 * @param array $settings Args by format, see settings().
	 * @param bool $force Optimise again the formats already done.
	 * @param bool $thumbnails_only
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function attachment($id, $metadata, $settings, $force = false, $thumbnails_only = false)
	{
		$attachment = $metadata ?: (int)$id;
		foreach (self::SERVICES as $format => $service) {
			if (!isset($settings[$format])) {
				continue;
			}
			try {
				$service::process($attachment, array_merge($settings[$format], [
					'id' => (int)$id,
					'force' => $force,
					'thumbnailsOnly' => $thumbnails_only,
				]));
			} catch (Exception $e) {
				Logger::error($e->getMessage());
			}
		}
		Status::stamp_sizes($id, $metadata ?: wp_get_attachment_metadata($id));
	}

	/**
	 * Brings an optimised image up to date with the
	 * current settings. If its thumbnails do not match
	 * the registered sizes (dimensions, or the sizes
	 * changed since it was optimised, e.g. a crop), or
	 * with $regenerate, they are made again from the
	 * original and every format is done again. Otherwise
	 * only the formats done with other settings are
	 * done again.
	 *
	 * @param int $id
	 * @param bool $regenerate
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function update($id, $regenerate = false)
	{
		$settings = self::settings();
		$metadata = wp_get_attachment_metadata($id);
		$sizes = get_post_meta($id, Status::SIZES_KEY, true);
		if ($regenerate || !Status::sizes_current($metadata) || ($sizes !== '' && $sizes !== Status::sizes_signature())) {
			$metadata = self::regenerate($id);
			if ($metadata) {
				self::attachment($id, $metadata, $settings, true);
			}
			return;
		}
		$outdated = [];
		foreach ($settings as $format => $args) {
			$service = self::SERVICES[$format];
			if (get_post_meta($id, $service::meta_key(), true) !== $service::signature($args)) {
				$outdated[$format] = $args;
			}
		}
		if ($outdated) {
			self::attachment($id, $metadata, $outdated, true);
		} else {
			Status::stamp_sizes($id, $metadata);
		}
	}

	/**
	 * Makes the thumbnails of an image again from its
	 * original: the original is put back in place,
	 * then WordPress makes the thumbnails of the
	 * registered sizes from it. They are not compressed
	 * yet, and the backups of their files are removed
	 * as they may be thumbnails of an older crop.
	 *
	 * @param int $id
	 * @return array|null The new metadata.
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function regenerate($id)
	{
		$file = get_attached_file($id);
		if (!$file || !file_exists($file)) {
			return null;
		}
		$backup = Service::backup_path($file);
		if (file_exists($backup)) {
			copy($backup, $file);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::$regenerating = true;
		$metadata = wp_generate_attachment_metadata($id, $file);
		self::$regenerating = false;
		if (empty($metadata) || is_wp_error($metadata)) {
			return null;
		}
		wp_update_attachment_metadata($id, $metadata);

		$sizes = isset($metadata['sizes']) ? $metadata['sizes'] : [];
		foreach ($sizes as $size) {
			$size_backup = Service::backup_path(dirname($file) . DIRECTORY_SEPARATOR . $size['file']);
			if (file_exists($size_backup)) {
				unlink($size_backup);
			}
		}
		return $metadata;
	}
}
