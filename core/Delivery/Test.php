<?php

/**
 * Test
 *
 * Tests what the server actually sends: the WebP and
 * AVIF files with their type, the image rewritten to
 * them, and the originals kept private. From the
 * Delivery tab.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Delivery;

use Octopus\Admin\Ajax;
use Octopus\Encoders\AVIF;
use Octopus\Encoders\WebP;
use Octopus\Media\Files;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Test
{
	/**
	 * AJAX action of the test.
	 *
	 * @var string
	 */
	const ACTION = 'octopus_delivery_test';

	/**
	 * Answers the test.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('wp_ajax_' . self::ACTION, [$this, 'test']);
	}

	/**
	 * Tests the delivery on an optimised image, with
	 * requests to the site itself: the WebP and AVIF
	 * files are served with their type, the server
	 * rewrite answers the image with them, and the
	 * originals are not public.
	 *
	 * @since 1.0.0
	 */
	public function test()
	{
		check_ajax_referer(Ajax::NONCE);
		Ajax::authorize();
		$sample = self::sample();
		if (!$sample) {
			wp_send_json_error(['message' => __('Optimise at least one image with its WebP or AVIF version first.', 'octopus-image-optimizer')]);
		}
		$checks = [];

		foreach (['webp' => 'WebP', 'avif' => 'AVIF'] as $format => $name) {
			if (empty($sample[$format])) {
				continue;
			}
			$type = 'image/' . $format;
			$response = self::head($sample[$format]);
			$ok = $response['status'] === 200 && $response['type'] === $type;
			$checks[] = [
				'state' => $ok ? 'ok' : 'fail',
				/* translators: 1: a format, e.g. WebP, 2: a type, e.g. image/webp. */
				'label' => sprintf(__('The %1$s files are served as %2$s', 'octopus-image-optimizer'), $name, $type),
				'detail' => self::describe($response),
				'advice' => $ok ? '' : sprintf(
					/* translators: 1: the Apache directive, 2: the nginx line. */
					esc_html__('The browsers ignore a file sent with another type. Apache: %1$s. nginx: add %2$s to mime.types.', 'octopus-image-optimizer'),
					'<code>AddType ' . $type . ' .' . $format . '</code>',
					'<code>' . $type . ' ' . $format . ';</code>'
				),
			];
		}

		// The rewrite answers with the AVIF if it exists, the WebP otherwise.
		$expected = !empty($sample['avif']) ? 'image/avif' : 'image/webp';
		$rewrite = self::head($sample['image'], 'image/avif,image/webp,image/*,*/*;q=0.8');
		$rewritten = $rewrite['type'] === $expected;
		$wanted = Settings::get('serve_rewrite') || Htaccess::server() === 'nginx';
		$checks[] = [
			'state' => $rewritten ? 'ok' : ($wanted || $rewrite['status'] >= 500 ? 'fail' : 'info'),
			/* translators: %s: AVIF or WebP. */
			'label' => $rewritten ? sprintf(__('The server answers the image with its %s version', 'octopus-image-optimizer'), $expected === 'image/avif' ? 'AVIF' : 'WebP') : __('The server answers the image with the JPG or PNG', 'octopus-image-optimizer'),
			'detail' => self::describe($rewrite),
			'advice' => $rewritten ? '' : self::rewrite_advice($rewrite, $wanted),
		];
		if ($rewritten) {
			$vary = stripos($rewrite['vary'], 'accept') !== false;
			$checks[] = [
				'state' => $vary ? 'ok' : 'fail',
				'label' => __('Caches know the image depends on the browser (Vary: Accept)', 'octopus-image-optimizer'),
				'detail' => $vary ? 'Vary: ' . $rewrite['vary'] : __('No Vary: Accept header.', 'octopus-image-optimizer'),
				/* translators: %s: a2enmod headers */
				'advice' => $vary ? '' : sprintf(esc_html__('Without it, a cache or a CDN can serve an AVIF to a browser that cannot read it. Apache: enable mod_headers (%s).', 'octopus-image-optimizer'), '<code>a2enmod headers</code>'),
			];
		}

		$checks[] = self::check_originals($sample);

		wp_send_json_success([
			'sample' => $sample['name'],
			'checks' => $checks,
		]);
	}

	/**
	 * Tells why the server answers the image with the
	 * JPG or PNG, or with an error: a server error is
	 * the one of a .htaccess Apache refuses, for every
	 * file of the uploads folder.
	 *
	 * @param array $response
	 * @param bool $wanted Whether the server rewrite is on.
	 * @return string
	 * @since 1.0.0
	 */
	private static function rewrite_advice($response, $wanted)
	{
		if ($response['status'] >= 500) {
			return esc_html__('The server answers with an error: it refuses the rules of uploads/.htaccess, and serves no image of the uploads folder. Untick the server option, then ask your host to allow them (AllowOverride FileInfo).', 'octopus-image-optimizer');
		}
		if ($wanted) {
			return esc_html__('The rewrite rules are not applied: check that they are in the configuration of the server, and that mod_rewrite is on (Apache).', 'octopus-image-optimizer');
		}
		return esc_html__('Expected while the server rewrite is off.', 'octopus-image-optimizer');
	}

	/**
	 * Checks that the original of the sample image
	 * cannot be downloaded: it keeps its metadata, e.g.
	 * the GPS position of a photo.
	 *
	 * @param array $sample
	 * @return array
	 * @since 1.0.0
	 */
	private static function check_originals($sample)
	{
		$label = __('The original images are not public', 'octopus-image-optimizer');
		$uploads = wp_get_upload_dir();
		$backup = wp_normalize_path(Files::backup_path($sample['file']));
		$base = trailingslashit(wp_normalize_path($uploads['basedir']));
		if (!file_exists($backup)) {
			return ['state' => 'info', 'label' => $label, 'detail' => __('The sample image has no original kept.', 'octopus-image-optimizer'), 'advice' => ''];
		}
		if (strpos($backup, $base) !== 0) {
			return ['state' => 'ok', 'label' => $label, 'detail' => __('They are kept out of the uploads folder.', 'octopus-image-optimizer'), 'advice' => ''];
		}
		$response = self::head(trailingslashit($uploads['baseurl']) . substr($backup, strlen($base)));
		$public = $response['status'] >= 200 && $response['status'] < 300;
		return [
			'state' => $public ? 'fail' : 'ok',
			'label' => $label,
			'detail' => self::describe($response),
			'advice' => $public ? sprintf(
				/* translators: 1: the nginx rule, 2: octopus_backup_dir */
				esc_html__('Anyone can download them, with their metadata (GPS position of the photos). nginx: add %1$s to the server block, or move them with the %2$s filter.', 'octopus-image-optimizer'),
				'<code>' . esc_html(Htaccess::nginx_deny()) . '</code>',
				'<code>octopus_backup_dir</code>'
			) : '',
		];
	}

	/**
	 * Returns an optimised image whose main file has a
	 * WebP or an AVIF version: its file, URLs and name.
	 *
	 * @return array|null
	 * @since 1.0.0
	 */
	private static function sample()
	{
		$ids = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => Files::MIMES,
			'posts_per_page' => 50,
			'fields' => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- On the meta keys of OIO, indexed; the media library is counted and listed once per page.
			'meta_query' => [
				'relation' => 'OR',
				['key' => WebP::meta_key(), 'compare' => 'EXISTS'],
				['key' => AVIF::meta_key(), 'compare' => 'EXISTS'],
			],
		]);
		foreach ($ids as $id) {
			$file = get_attached_file($id);
			if (!$file) {
				continue;
			}
			$variants = array_filter(Files::variants($file), 'file_exists');
			if (!$variants) {
				continue;
			}
			return [
				'file' => $file,
				'name' => wp_basename($file),
				'image' => wp_get_attachment_url($id),
				'webp' => isset($variants[WebP::extension()]) ? Files::url($variants[WebP::extension()]) : '',
				'avif' => isset($variants[AVIF::extension()]) ? Files::url($variants[AVIF::extension()]) : '',
			];
		}
		return null;
	}

	/**
	 * Requests a URL of the site with HEAD: its status,
	 * type and Vary header.
	 *
	 * @param string $url
	 * @param string $accept The Accept header, if any.
	 * @return array
	 * @since 1.0.0
	 */
	private static function head($url, $accept = '')
	{
		$response = wp_remote_head($url, [
			'timeout' => 10,
			'redirection' => 3,
			'headers' => $accept ? ['Accept' => $accept] : [],
			// As WordPress for its own loopback requests.
			'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A filter of WordPress.
		]);
		if (is_wp_error($response)) {
			return ['status' => 0, 'type' => '', 'vary' => '', 'error' => $response->get_error_message()];
		}
		$type = (string)wp_remote_retrieve_header($response, 'content-type');
		return [
			'status' => (int)wp_remote_retrieve_response_code($response),
			'type' => strtolower(trim(strtok($type, ';'))),
			'vary' => (string)wp_remote_retrieve_header($response, 'vary'),
			'error' => '',
		];
	}

	/**
	 * Describes a response, e.g. "HTTP 200, image/avif".
	 *
	 * @param array $response
	 * @return string
	 * @since 1.0.0
	 */
	private static function describe($response)
	{
		if ($response['error']) {
			/* translators: %s: the error. */
			return sprintf(__('The site could not be reached: %s', 'octopus-image-optimizer'), $response['error']);
		}
		return 'HTTP ' . $response['status'] . ($response['type'] ? ', ' . $response['type'] : '');
	}
}
