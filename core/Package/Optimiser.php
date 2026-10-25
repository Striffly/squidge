<?php

/**
 * Optimiser
 *
 * Optimiser runs the libraries on an attachment, the
 * same way on upload, from the CLI and in the
 * background tasks.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Class
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Package;

use Exception;
use Squidge\Admin\Fields;
use Squidge\Log\Logger;
use Squidge\Services\AVIF;
use Squidge\Services\JPG;
use Squidge\Services\PNG;
use Squidge\Services\WebP;

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
			'webp' => ['quality' => Fields::get('squidge_webp_quality')],
			'avif' => ['quality' => Fields::get('squidge_avif_quality')],
			'jpg' => ['quality' => Fields::get('squidge_jpg_quality')],
			'png' => ['optimization' => Fields::get('squidge_png_quality')],
		];
		foreach (array_keys($settings) as $format) {
			if ($enabled_only && !Fields::get('squidge_' . $format . '_enable')) {
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
}
