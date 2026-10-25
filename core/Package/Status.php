<?php

/**
 * Status
 *
 * Status tells if the images of the media library
 * are optimised with the current settings, from the
 * meta saved by each library.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Package;

use Squidge\Log\Errors;
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
	 * settings that changed since.
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
	 * Image types Squidge optimises.
	 */
	const MIMES = ['image/jpeg', 'image/png'];

	/**
	 * Returns the meta key and current settings of each
	 * library expected to process the images: enabled
	 * and installed.
	 *
	 * @return array Signature by meta key.
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function expected()
	{
		$expected = [];
		foreach (Optimiser::settings() as $format => $args) {
			$service = Optimiser::SERVICES[$format];
			if ($service::installed()) {
				$expected[$service::meta_key()] = $service::signature($args);
			}
		}
		return $expected;
	}

	/**
	 * Returns the status of an image.
	 *
	 * @param int $id
	 * @return string
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function of($id)
	{
		if (Errors::for_attachment($id)) {
			return self::ERROR;
		}
		$outdated = false;
		foreach (self::expected() as $key => $signature) {
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
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function counts()
	{
		$counts = ['total' => self::count(null)];
		foreach ([self::TO_OPTIMISE, self::OUTDATED, self::ERROR] as $status) {
			$counts[$status] = self::count($status);
		}
		$counts[self::OPTIMISED] = $counts['total'] - $counts[self::TO_OPTIMISE] - $counts[self::OUTDATED] - $counts[self::ERROR];
		return $counts;
	}

	/**
	 * Returns the IDs of the images of a status, all
	 * images with null.
	 *
	 * @param string|null $status
	 * @return int[]
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public static function ids($status)
	{
		$query = new WP_Query(self::query_args($status, ['posts_per_page' => -1, 'no_found_rows' => true]));
		return array_map('intval', $query->posts);
	}

	/**
	 * Returns the number of images of a status, of all
	 * images with null.
	 *
	 * @param string|null $status
	 * @return int
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function count($status)
	{
		$query = new WP_Query(self::query_args($status, ['posts_per_page' => 1]));
		return (int)$query->found_posts;
	}

	/**
	 * Returns the WP_Query args of the images of a
	 * status. The statuses do not overlap: an error
	 * comes first, then a missing library, then an
	 * outdated one.
	 *
	 * @param string|null $status
	 * @param array $args
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	private static function query_args($status, $args)
	{
		$args = array_merge([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => self::MIMES,
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
		$missing = ['relation' => 'OR'];
		$changed = ['relation' => 'OR'];
		foreach ($expected as $key => $signature) {
			$missing[] = ['key' => $key, 'compare' => 'NOT EXISTS'];
			$changed[] = ['key' => $key, 'value' => $signature, 'compare' => '!='];
		}
		if ($status === self::TO_OPTIMISE) {
			$meta_query[] = $missing;
		} else {
			foreach (array_keys($expected) as $key) {
				$meta_query[] = ['key' => $key, 'compare' => 'EXISTS'];
			}
			if ($status === self::OUTDATED) {
				$meta_query[] = $changed;
			} else {
				foreach ($expected as $key => $signature) {
					$meta_query[] = ['key' => $key, 'value' => $signature];
				}
			}
		}
		$args['meta_query'] = $meta_query;
		return $args;
	}
}
