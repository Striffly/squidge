<?php

/**
 * Upload
 *
 * Upload is responsible for adding filters
 * and actions for the WordPress media
 * library.
 *
 * @package     Squidge
 * @version     0.1.0
 * @category    Admin
 * @repo        https://github.com/ainsleyclark/wp-squidge
 *
 */

namespace Squidge\Admin;

use Squidge\Container\Container;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Upload
{
	/**
	 * @var Container
	 */
	private $service;

	/**
	 * Adds filters to process web and jpg
	 * images.
	 *
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function __construct()
	{
		$this->service = new Container();
		add_filter('big_image_size_threshold', '__return_false');
		add_filter("wp_generate_attachment_metadata", [$this, 'process_webp'], 20, 1);
		add_filter("wp_generate_attachment_metadata", [$this, 'process_avif'], 30, 1);
		add_filter("wp_generate_attachment_metadata", [$this, 'process_jpg'], 40, 1);
		add_filter("wp_generate_attachment_metadata", [$this, 'process_png'], 50, 1);
		// TODO: SVG
		add_filter("delete_attachment", [$this, 'delete_webp'], 20, 1);
		add_filter("delete_attachment", [$this, 'delete_avif'], 20, 1);
	}

	/**
	 * Compress a JPG file on upload.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function process_jpg($attachment)
	{
		$this->service->JPG->Quality = carbon_get_theme_option('wp_squidge_jpg_quality');
		if (carbon_get_theme_option('wp_squidge_jpg_enable')) {
			$this->service->JPG->process($attachment);
		}
		return $attachment;
	}

	/**
	 * Compress a PNG file on upload.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function process_png($attachment)
	{
		$this->service->PNG->Quality = carbon_get_theme_option('wp_squidge_png_quality');
		if (carbon_get_theme_option('wp_squidge_png_enable')) {
			$this->service->PNG->process($attachment);
		}
		return $attachment;
	}

	/**
	 * Process a JPG/PNG upload and converts to
	 * a WebP file with the .webp extension.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function process_webp($attachment)
	{
		$this->service->WebP->Quality = carbon_get_theme_option('wp_squidge_webp_quality');
		if (carbon_get_theme_option('wp_squidge_webp_enable')) {
			$this->service->WebP->process($attachment);
		}
		return $attachment;
	}

	/**
	 * Process a JPG/PNG upload and converts to
	 * a WebP file with the .avif extension.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function process_avif($attachment)
	{
		if (carbon_get_theme_option('wp_squidge_avif_enable')) {
			$this->service->AVIF->process($attachment);
		}
		return $attachment;
	}

	/**
	 * Deletes .webp files when the attachment is
	 * deleted from the media library.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function delete_webp($attachment)
	{
		$this->service->WebP->delete($attachment);
		return $attachment;
	}

	/**
	 * Deletes .avif files when the attachment is
	 * deleted from the media library.
	 *
	 * @param $attachment
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	public function delete_avif($attachment)
	{
		$this->service->AVIF->delete($attachment);
		return $attachment;
	}
}