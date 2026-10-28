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

namespace Octopus\Media;

use Exception;
use Octopus\Encoders\AVIF;
use Octopus\Encoders\JPG;
use Octopus\Encoders\PNG;
use Octopus\Encoders\WebP;
use Octopus\Engines\ImageEditor;
use Octopus\Log\Errors;
use Octopus\Log\Logger;
use Octopus\Settings;

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
	 * How to optimise an image: the new images only
	 * (and the files missing, e.g. of a new size), the
	 * images with older settings brought up to date,
	 * or everything again from the originals,
	 * thumbnails included.
	 */
	const MODE_NEW = 'new';
	const MODE_OUTDATED = 'outdated';
	const MODE_ALL = 'all';

	/**
	 * The key of the errors of the images WordPress
	 * cannot read, next to the formats of the
	 * libraries (see Errors).
	 *
	 * @var string
	 */
	const READER = 'read';

	/**
	 * HEAVIER_KEY is the meta key listing the WebP and
	 * AVIF files deleted as they were not lighter than
	 * the JPG or PNG, relative to the uploads folder:
	 * they count as done.
	 */
	const HEAVIER_KEY = '_octopus_heavier';

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
	 * in WP-CLI. Passed to the octopus_nice, octopus_avif_jobs
	 * and octopus_webp_mt filters.
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
	 */
	public static function settings($enabled_only = true)
	{
		$settings = [
			'webp' => ['quality' => Settings::get('webp_quality')],
			'avif' => ['quality' => Settings::get('avif_quality')],
			'jpg' => ['quality' => Settings::get('jpg_quality')],
			'png' => [
				'lossy' => Settings::get('png_compression') === 'lossy',
				'optimization' => Settings::get('png_level'),
				'lossy_quality' => Settings::get('png_lossy_quality'),
			],
		];
		foreach (array_keys($settings) as $format) {
			if ($enabled_only && !Settings::get($format . '_enable')) {
				unset($settings[$format]);
			}
		}
		return $settings;
	}

	/**
	 * Optimises an image with the settings, in a mode:
	 * the one place deciding how, for the media
	 * library and the background jobs. Without mode,
	 * from its status: a new image is optimised, an
	 * outdated one brought up to date, an optimised
	 * one made again from its original.
	 *
	 * @param int $id
	 * @param string|null $mode See the MODE_ constants.
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 */
	public static function optimise($id, $mode = null)
	{
		if ($mode === null) {
			$modes = [Status::OPTIMISED => self::MODE_ALL, Status::OUTDATED => self::MODE_OUTDATED];
			$status = Status::of($id);
			$mode = isset($modes[$status]) ? $modes[$status] : self::MODE_NEW;
		}
		if ($mode === self::MODE_NEW) {
			return self::attachment($id, null, self::settings());
		}
		return self::update($id, $mode === self::MODE_ALL);
	}

	/**
	 * Optimises an attachment with each format of the
	 * settings, then measures the bytes saved.
	 *
	 * @param int $id
	 * @param array|null $metadata The attachment metadata, when it is not saved yet (on upload).
	 * @param array $settings Args by format, see settings().
	 * @param bool $force Optimise again the formats already done.
	 * @param bool $thumbnails_only
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
	 */
	public static function attachment($id, $metadata, $settings, $force = false, $thumbnails_only = false)
	{
		return Lock::run($id, function () use ($id, $metadata, $settings, $force, $thumbnails_only) {
			if (!self::readable($id, $metadata)) {
				return true;
			}
			$metadata = $metadata ?: wp_get_attachment_metadata($id);
			try {
				foreach (self::SERVICES as $format => $service) {
					if (!isset($settings[$format])) {
						continue;
					}
					try {
						$service::process($metadata, array_merge($settings[$format], [
							'id' => (int)$id,
							'force' => $force,
							'thumbnailsOnly' => $thumbnails_only,
						]));
					} catch (Exception $e) {
						Logger::error($e->getMessage());
					}
				}
			} finally {
				// The RGB copies of the CMYK JPGs, read by the WebP and AVIF formats.
				ImageEditor::release_all();
			}
			// Deleted meanwhile: what was made since goes with it, nothing is saved.
			if (Cleaner::deleted($id)) {
				Cleaner::forget($id, $metadata);
				return true;
			}
			self::drop_heavier($id, $metadata);
			Status::stamp_sizes($id, $metadata);
			// On upload, the metadata is saved once the filter returns it, see
			// UploadHooks::process(): saving it now would be overwritten.
			if (!doing_filter('wp_generate_attachment_metadata')) {
				self::save_file_sizes($id);
			}
			Stats::save($id, $metadata);
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
	 */
	private static function readable($id, $metadata)
	{
		Errors::clear_attachment($id, self::READER);
		$metadata = $metadata ?: wp_get_attachment_metadata($id);
		if (!empty($metadata['file'])) {
			return true;
		}
		if (in_array(get_post_mime_type($id), Files::MIMES, true)) {
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
	 */
	public static function file_sizes($metadata)
	{
		if (!is_array($metadata) || empty($metadata['file'])) {
			return $metadata;
		}
		// PHP keeps the size read before a library changed the file.
		clearstatcache();
		$file = Files::uploads_dir() . DIRECTORY_SEPARATOR . $metadata['file'];
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
	 * Deletes the WebP and AVIF files of an image that
	 * are not lighter than the JPG or PNG they replace,
	 * as compressed: on small graphics and thumbnails,
	 * serving them would slow the page down. An AVIF
	 * not lighter than its WebP goes too: browsers take
	 * the AVIF first. They are listed, so that they
	 * count as done.
	 *
	 * @param int $id
	 * @param array $metadata
	 * @since 1.0.0
	 */
	private static function drop_heavier($id, $metadata)
	{
		if (empty($metadata['file'])) {
			return;
		}
		$heavier = self::heavier($id);
		clearstatcache();
		foreach (Files::of($id, $metadata) as $file) {
			foreach ([WebP::extension(), AVIF::extension()] as $extension) {
				$variant = Files::variant($file, $extension);
				if (!file_exists($variant) || !file_exists($file) || !Files::contained($variant)) {
					continue;
				}
				$relative = Files::relative($variant);
				$limit = filesize($file);
				// WebP comes first: the AVIF is compared to the WebP kept.
				$webp = Files::variant($file, WebP::extension());
				if ($extension === AVIF::extension() && file_exists($webp)) {
					$limit = min($limit, filesize($webp));
				}
				if (filesize($variant) < $limit) {
					$heavier = array_diff($heavier, [$relative]);
					continue;
				}
				Files::delete($variant);
				$heavier[] = $relative;
				Logger::info("Deleted, not lighter than the image or its WebP: " . $variant);
			}
		}
		$heavier = array_values(array_unique($heavier));
		if ($heavier) {
			update_post_meta($id, self::HEAVIER_KEY, $heavier);
		} else {
			delete_post_meta($id, self::HEAVIER_KEY);
		}
	}

	/**
	 * Returns the WebP and AVIF files of an image
	 * deleted as heavier than the JPG or PNG, relative
	 * to the uploads folder.
	 *
	 * @param int $id
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function heavier($id)
	{
		$heavier = $id ? get_post_meta($id, self::HEAVIER_KEY, true) : [];
		return is_array($heavier) ? $heavier : [];
	}

	/**
	 * Brings an optimised image up to date with the
	 * current settings. If its thumbnails do not match
	 * the registered sizes (dimensions, or the sizes
	 * changed since it was optimised, e.g. a crop), or
	 * with $regenerate, they are made again from the
	 * original and every format is done again. Otherwise
	 * only the formats done with other settings are
	 * done again, and the WebP and AVIF deleted as
	 * heavier if the JPG or PNG is.
	 *
	 * @param int $id
	 * @param bool $regenerate
	 * @return true|null Null if another request is processing the image.
	 * @since 1.0.0
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
			// The JPG or PNG changes: the WebP and AVIF deleted as heavier may be lighter now.
			$compression = get_post_mime_type($id) === 'image/png' ? 'png' : 'jpg';
			if (isset($outdated[$compression]) && self::heavier($id)) {
				$outdated = array_merge($outdated, array_intersect_key($settings, ['webp' => true, 'avif' => true]));
			}
			if ($outdated) {
				self::attachment($id, $metadata, $outdated, true);
			} else {
				Status::stamp_sizes($id, $metadata);
				// Nothing to encode: the sizes of the files, e.g. of an image optimised
				// before OIO measured them.
				Stats::save($id, $metadata);
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
	 * uploaded, as WordPress does on upload, with the
	 * maximum size of the settings: an image scaled
	 * before may not be any more, and the other way
	 * round.
	 *
	 * @param int $id
	 * @return array|null The new metadata, null if it failed or
	 *                    another request is processing the image.
	 * @since 1.0.0
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
			if (!$source || $source === $file || !file_exists($source) || !Files::contained($source)) {
				$source = $file;
				$backup = Files::backup_path($file);
				if (file_exists($backup) && Files::contained($file) && Files::contained($backup)) {
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
			// Deleted meanwhile: the thumbnails made again go with it.
			if (Cleaner::deleted($id)) {
				foreach (Files::of($id, $metadata) as $path) {
					Files::delete($path);
				}
				Cleaner::forget($id, $metadata);
				return null;
			}
			$main = Files::of($id, $metadata)[0];
			if ($main !== $file) {
				// The maximum size changed: the image is scaled now (WordPress made it
				// the main file), or the image uploaded is the main file again.
				update_attached_file($id, $main);
				self::release_main($file, $source);
			} elseif (!empty($previous['original_image']) && empty($metadata['original_image'])) {
				$metadata['original_image'] = $previous['original_image'];
			}
			wp_update_attachment_metadata($id, $metadata);

			// The files made again are the new originals of the next optimisation.
			$made = Files::of($id, $metadata);
			if ($made[0] === $source) {
				array_shift($made);
			}
			foreach ($made as $path) {
				Files::delete(Files::backup_path($path));
			}
			return $metadata;
		});
	}

	/**
	 * Deletes the files of a former main file of an
	 * image: its WebP and AVIF versions and its backup,
	 * and the file itself if it was a scaled copy. The
	 * image uploaded stays, as its original.
	 *
	 * @param string $file The former main file.
	 * @param string $uploaded The image uploaded.
	 * @since 1.0.0
	 */
	private static function release_main($file, $uploaded)
	{
		$paths = [Files::variant($file, WebP::extension()), Files::variant($file, AVIF::extension()), Files::backup_path($file)];
		if ($file !== $uploaded) {
			$paths[] = $file;
		}
		foreach ($paths as $path) {
			Files::delete($path);
		}
	}
}
