<?php

/**
 * Optimiser
 *
 * Optimiser runs the libraries on an attachment, the
 * same way on upload, from the CLI and in the
 * background tasks.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Package;

use Exception;
use OIO\Admin\Fields;
use OIO\Log\Errors;
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
	 * The tool of the errors of the images WordPress
	 * cannot read, as the libraries for their failures.
	 *
	 * @var string
	 */
	const READER = 'wordpress';

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
	 * in WP-CLI. Passed to the oio_nice, oio_avif_jobs
	 * and oio_webp_mt filters.
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
	 * @since 1.0.0
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
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function attachment($id, $metadata, $settings, $force = false, $thumbnails_only = false)
	{
		return Lock::run($id, function () use ($id, $metadata, $settings, $force, $thumbnails_only) {
			if (!self::readable($id, $metadata)) {
				return true;
			}
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
			// On upload, the metadata is saved once the filter returns it, see
			// Upload::process(): saving it now would be overwritten.
			if (!doing_filter('wp_generate_attachment_metadata')) {
				self::save_file_sizes($id);
			}
			return true;
		});
	}

	/**
	 * Determines if WordPress could read an image: its
	 * metadata has its file. A JPG or PNG it could not
	 * read (damaged or incomplete) gets an error, it
	 * would stay to optimise forever. Other attachments
	 * without file (e.g. a PDF) have nothing to optimise.
	 *
	 * @param int $id
	 * @param array|null $metadata The metadata, when it is not saved yet.
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function readable($id, $metadata)
	{
		Errors::clear_attachment($id, self::READER);
		$metadata = $metadata ?: wp_get_attachment_metadata($id);
		if (!empty($metadata['file'])) {
			return true;
		}
		if (in_array(get_post_mime_type($id), Status::MIMES, true)) {
			Errors::add($id, self::READER, (string)get_attached_file($id), 'cannot read the image, its metadata has no file');
		}
		return false;
	}

	/**
	 * Returns the metadata of an image with the sizes
	 * of its files on the disk: WordPress saves them
	 * when it makes the files, OIO compresses or
	 * restores the files after. Only the sizes saved
	 * are updated, WordPress reads the file otherwise.
	 *
	 * @param array $metadata
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function file_sizes($metadata)
	{
		if (!is_array($metadata) || empty($metadata['file'])) {
			return $metadata;
		}
		// PHP keeps the size read before a library changed the file.
		clearstatcache();
		$file = wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'];
		if (isset($metadata['filesize']) && file_exists($file)) {
			$metadata['filesize'] = wp_filesize($file);
		}
		$sizes = isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
		foreach ($sizes as $name => $size) {
			$path = isset($size['file']) ? dirname($file) . DIRECTORY_SEPARATOR . $size['file'] : '';
			if (isset($size['filesize']) && $path && file_exists($path)) {
				$metadata['sizes'][$name]['filesize'] = wp_filesize($path);
			}
		}
		return $metadata;
	}

	/**
	 * Saves the sizes of the files of an image in its
	 * metadata, if they changed. OIO does not hook the
	 * saving of the metadata: it cannot loop.
	 *
	 * @param int $id
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function save_file_sizes($id)
	{
		$metadata = wp_get_attachment_metadata($id, true);
		$updated = self::file_sizes($metadata);
		if ($updated !== $metadata) {
			wp_update_attachment_metadata($id, $updated);
		}
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
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function update($id, $regenerate = false)
	{
		return Lock::run($id, function () use ($id, $regenerate) {
			$settings = self::settings();
			$metadata = wp_get_attachment_metadata($id);
			$sizes = get_post_meta($id, Status::SIZES_KEY, true);
			if ($regenerate || !Status::sizes_current($metadata) || ($sizes !== '' && $sizes !== Status::sizes_signature())) {
				$metadata = self::regenerate($id);
				if ($metadata) {
					self::attachment($id, $metadata, $settings, true);
				}
				return true;
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
			return true;
		});
	}

	/**
	 * Makes the thumbnails of an image again from its
	 * original: the original is put back in place,
	 * then WordPress makes the thumbnails of the
	 * registered sizes from it. They are not compressed
	 * yet, and the backups of their files are removed
	 * as they may be thumbnails of an older crop. A
	 * big image is scaled again from the image
	 * uploaded, as WordPress does on upload.
	 *
	 * @param int $id
	 * @return array|null The new metadata, null if it failed or
	 *                    another request is processing the image.
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function regenerate($id)
	{
		return Lock::run($id, function () use ($id) {
			$file = get_attached_file($id);
			if (!$file || !file_exists($file)) {
				return null;
			}
			// WordPress scaled a big image down: it is scaled again from the image
			// uploaded, which OIO does not optimise, and its thumbnails keep their names.
			$source = wp_get_original_image_path($id);
			if (!$source || $source === $file || !file_exists($source)) {
				$source = $file;
				$backup = Service::backup_path($file);
				if (file_exists($backup) && Service::contained($file) && Service::contained($backup)) {
					copy($backup, $file);
				}
			}

			$previous = wp_get_attachment_metadata($id);
			require_once ABSPATH . 'wp-admin/includes/image.php';
			self::$regenerating = true;
			$metadata = wp_generate_attachment_metadata($id, $source);
			self::$regenerating = false;
			if (empty($metadata) || is_wp_error($metadata)) {
				return null;
			}
			if (!empty($previous['original_image']) && empty($metadata['original_image'])) {
				$metadata['original_image'] = $previous['original_image'];
			}
			wp_update_attachment_metadata($id, $metadata);

			// The files made again are the new originals of the next optimisation.
			$file = get_attached_file($id);
			$made = $source === $file ? [] : [$file];
			$sizes = isset($metadata['sizes']) ? $metadata['sizes'] : [];
			foreach ($sizes as $size) {
				$made[] = dirname($file) . DIRECTORY_SEPARATOR . $size['file'];
			}
			foreach ($made as $path) {
				$backup = Service::backup_path($path);
				if (file_exists($backup) && Service::contained($backup)) {
					unlink($backup);
				}
			}
			return $metadata;
		});
	}
}
