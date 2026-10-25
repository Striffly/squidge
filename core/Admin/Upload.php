<?php

/**
 * Upload
 *
 * Upload is responsible for adding filters
 * and actions for the WordPress media
 * library.
 *
 * @package     Squidge
 * @version     0.1.4
 * @author      Ainsley Clark
 * @category    Admin
 * @repo        https://github.com/ainsleyclark/squidge
 *
 */

namespace Squidge\Admin;

use Squidge\Package\Optimiser;
use Squidge\Package\Service;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Upload
{

	/**
	 * Optimises the images on upload, and deletes their
	 * files with the attachment.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function __construct()
	{
		add_filter('big_image_size_threshold', '__return_false');
		add_filter('wp_generate_attachment_metadata', [$this, 'process'], 20, 2);
		add_filter('delete_attachment', [$this, 'delete_files'], 20, 1);
	}

	/**
	 * Optimises an uploaded image with the enabled
	 * formats. Its metadata is not saved yet, so it
	 * is passed along.
	 *
	 * @param array $metadata
	 * @param int $attachment_id
	 * @return array
	 * @since 0.1.5
	 * @date 25/10/2026
	 */
	public function process($metadata, $attachment_id)
	{
		Optimiser::attachment($attachment_id, $metadata, Optimiser::settings());
		return $metadata;
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
