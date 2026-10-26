<?php

/**
 * Status
 *
 * Status tells if the images of the media library
 * are optimised with the current settings, from the
 * meta saved by each library.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Media;

use Octopus\Log\Errors;
use Octopus\Settings;
use WP_Query;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Status
{
	/**
	 * Not processed by every enabled library yet.
	 */
	const TO_OPTIMISE = 'to-optimise';

	/**
	 * Processed by every enabled library, but some with
	 * settings that changed since, or with thumbnails
	 * that do not match the registered sizes.
	 */
	const OUTDATED = 'outdated';

	/**
	 * Processed by every enabled library with the
	 * current settings.
	 */
	const OPTIMISED = 'optimised';

	/**
	 * A library failed on the image.
	 */
	const ERROR = 'error';

	/**
	 * Processed by every enabled library, without error,
	 * whatever the settings: optimised or outdated. Only
	 * used to query, see ids().
	 */
	const PROCESSED = 'processed';

	/**
	 * Meta key saving the thumbnail sizes an image was
	 * optimised with, when its thumbnails match them.
	 */
	const SIZES_KEY = '_octopus_sizes';

	/**
	 * Returns a signature of the thumbnail sizes
	 * registered by WordPress, the theme and plugins,
	 * and of the maximum image size.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function sizes_signature()
	{
		return md5(wp_json_encode([wp_get_registered_image_subsizes(), Settings::get('max_size')]));
	}

	/**
	 * Determines if the thumbnails of an image match the
	 * registered sizes: each size exists with the right
	 * dimensions, unless the image is too small for it
	 * (the same computation as WordPress). The
	 * thumbnails of a scaled image are made from the
	 * image uploaded, rounded differently: 1 pixel off.
	 *
	 * @param array $metadata
	 * @return bool
	 * @since 1.0.0
	 */
	public static function sizes_current($metadata)
	{
		if (empty($metadata['width']) || empty($metadata['height'])) {
			return true;
		}
		$sizes = isset($metadata['sizes']) ? $metadata['sizes'] : [];
		foreach (wp_get_registered_image_subsizes() as $name => $size) {
			$expected = image_resize_dimensions($metadata['width'], $metadata['height'], $size['width'], $size['height'], $size['crop']);
			if (!$expected) {
				continue;
			}
			if (!isset($sizes[$name]) || abs((int)$sizes[$name]['width'] - (int)$expected[4]) > 1 || abs((int)$sizes[$name]['height'] - (int)$expected[5]) > 1) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Saves the signature of the sizes on an image if
	 * its thumbnails match them, removes it otherwise.
	 *
	 * @param int $id
	 * @param array $metadata
	 * @since 1.0.0
	 */
	public static function stamp_sizes($id, $metadata)
	{
		if (is_array($metadata) && self::sizes_current($metadata)) {
			update_post_meta($id, self::SIZES_KEY, self::sizes_signature());
		} else {
			delete_post_meta($id, self::SIZES_KEY);
		}
	}

	/**
	 * Returns the meta key and current settings of each
	 * library expected to process the images: enabled
	 * and available (installed, or the image editor
	 * for WebP and AVIF).
	 *
	 * @return array Signature by meta key.
	 * @since 1.0.0
	 */
	public static function expected()
	{
		$expected = [];
		foreach (Optimiser::settings() as $format => $args) {
			$service = Optimiser::SERVICES[$format];
			if ($service::available()) {
				$expected[$service::meta_key()] = $service::signature($args);
			}
		}
		return $expected;
	}

	/**
	 * Determines if an image has been processed: a
	 * library (or the image editor) made at least one
	 * of its formats.
	 *
	 * @param int $id
	 * @return bool
	 * @since 1.0.0
	 */
	public static function processed($id)
	{
		foreach (Optimiser::SERVICES as $service) {
			if ($service::has_compressed($id)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the status of an image.
	 *
	 * @param int $id
	 * @return string
	 * @since 1.0.0
	 */
	public static function of($id)
	{
		if (Errors::for_attachment($id)) {
			return self::ERROR;
		}
		$expected = self::expected();
		$outdated = $expected && get_post_meta($id, self::SIZES_KEY, true) !== self::sizes_signature();
		foreach ($expected as $key => $signature) {
			$value = get_post_meta($id, $key, true);
			if ($value === '') {
				return self::TO_OPTIMISE;
			}
			if ($value !== $signature) {
				$outdated = true;
			}
		}
		return $outdated ? self::OUTDATED : self::OPTIMISED;
	}

	/**
	 * Returns the number of images of each status, and
	 * their total.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function counts()
	{
		$counts = ['total' => self::count(null)];
		foreach ([self::TO_OPTIMISE, self::OPTIMISED, self::ERROR] as $status) {
			$counts[$status] = self::count($status);
		}
		$counts[self::OUTDATED] = $counts['total'] - $counts[self::TO_OPTIMISE] - $counts[self::OPTIMISED] - $counts[self::ERROR];
		return $counts;
	}

	/**
	 * Returns the IDs of the images of a status, all
	 * images with null.
	 *
	 * @param string|null $status
	 * @return int[]
	 * @since 1.0.0
	 */
	public static function ids($status)
	{
		// Outdated: processed by every library, but not with the current settings.
		if ($status === self::OUTDATED) {
			return array_values(array_diff(self::ids(self::PROCESSED), self::ids(self::OPTIMISED)));
		}
		$query = new WP_Query(self::query_args($status, ['posts_per_page' => -1, 'no_found_rows' => true]));
		return array_map('intval', $query->posts);
	}

	/**
	 * Returns the number of images of a status, of all
	 * images with null.
	 *
	 * @param string|null $status
	 * @return int
	 * @since 1.0.0
	 */
	private static function count($status)
	{
		$query = new WP_Query(self::query_args($status, ['posts_per_page' => 1]));
		return (int)$query->found_posts;
	}

	/**
	 * Returns the WP_Query args of the images of a
	 * status. The statuses do not overlap: an error
	 * comes first, then a missing library. There is no
	 * query for the outdated images: an OR of "!="
	 * meta queries joins postmeta so many times that it
	 * takes seconds, they are the processed images that
	 * are not optimised.
	 *
	 * @param string|null $status
	 * @param array $args
	 * @return array
	 * @since 1.0.0
	 */
	private static function query_args($status, $args)
	{
		$args = array_merge([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => Files::MIMES,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
		], $args);
		if ($status === null) {
			return $args;
		}
		if ($status === self::ERROR) {
			$args['meta_query'] = [['key' => Errors::META_KEY, 'compare' => 'EXISTS']];
			return $args;
		}

		$expected = self::expected();
		if (empty($expected)) {
			// Nothing to do: no image is to optimise or outdated.
			$args['post__in'] = [0];
			return $args;
		}
		$meta_query = [
			'relation' => 'AND',
			['key' => Errors::META_KEY, 'compare' => 'NOT EXISTS'],
		];
		if ($status === self::TO_OPTIMISE) {
			$missing = ['relation' => 'OR'];
			foreach (array_keys($expected) as $key) {
				$missing[] = ['key' => $key, 'compare' => 'NOT EXISTS'];
			}
			$meta_query[] = $missing;
		} elseif ($status === self::PROCESSED) {
			foreach (array_keys($expected) as $key) {
				$meta_query[] = ['key' => $key, 'compare' => 'EXISTS'];
			}
		} else {
			// Optimised: done by every library with the current settings and sizes.
			foreach ($expected as $key => $signature) {
				$meta_query[] = ['key' => $key, 'value' => $signature];
			}
			$meta_query[] = ['key' => self::SIZES_KEY, 'value' => self::sizes_signature()];
		}
		$args['meta_query'] = $meta_query;
		return $args;
	}
}
