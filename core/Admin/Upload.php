<?php

/**
 * Upload
 *
 * Upload is responsible for adding filters
 * and actions for the WordPress media
 * library.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Admin;

use Octopus\Package\Optimiser;
use Octopus\Package\Service;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Upload
{

	/**
	 * Optimises the images on upload, deletes their
	 * files with the attachment, and sets the size
	 * WordPress scales the big images down to.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function __construct()
	{
		add_filter('wp_generate_attachment_metadata', [$this, 'process'], 20, 2);
		add_filter('delete_attachment', [$this, 'delete_files'], 20, 1);
		add_filter('big_image_size_threshold', [$this, 'threshold']);
	}

	/**
	 * Returns the maximum image size of the settings
	 * (longest side), false for no limit: WordPress
	 * scales a bigger image down to it and keeps the
	 * image uploaded as its original.
	 *
	 * @return int|false
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function threshold()
	{
		$size = (int)Fields::get('octopus_max_size');
		return $size > 0 ? $size : false;
	}

	/**
	 * Optimises an uploaded image with the enabled
	 * formats. Its metadata is not saved yet, so it
	 * is passed along, and returned with the sizes of
	 * the compressed files.
	 *
	 * @param array $metadata
	 * @param int $attachment_id
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function process($metadata, $attachment_id)
	{
		if (Optimiser::$regenerating) {
			return $metadata;
		}
		Optimiser::attachment($attachment_id, $metadata, Optimiser::settings());
		return Optimiser::file_sizes($metadata);
	}

	/**
	 * Deletes the WebP, AVIF and backup files when
	 * the attachment is deleted from the media
	 * library.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function delete_files($attachment)
	{
		Service::delete($attachment);
		return $attachment;
	}
}
