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

use Octopus\Admin\JobsAjax;
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
	 * @date 25/10/2026
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
	 * @date 25/10/2026
	 */
	public function test()
	{
		check_ajax_referer(JobsAjax::NONCE);
		if (!current_user_can('manage_options')) {
			wp_send_json_error(null, 403);
		}
		$sample = self::sample();
		if (!$sample) {
			wp_send_json_error(['message' => 'Optimise at least one image with its WebP or AVIF version first.']);
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
				'label' => sprintf('The %s files are served as %s', $name, $type),
				'detail' => self::describe($response),
				'advice' => $ok ? '' : sprintf('The browsers ignore a file sent with another type. Apache: <code>AddType %1$s .%2$s</code>. nginx: add <code>%1$s %2$s;</code> to mime.types.', $type, $format),
			];
		}

		// The rewrite answers with the AVIF if it exists, the WebP otherwise.
		$expected = !empty($sample['avif']) ? 'image/avif' : 'image/webp';
		$rewrite = self::head($sample['image'], 'image/avif,image/webp,image/*,*/*;q=0.8');
		$rewritten = $rewrite['type'] === $expected;
		$wanted = Settings::get('serve_rewrite') || Htaccess::server() === 'nginx';
		$checks[] = [
			'state' => $rewritten ? 'ok' : ($wanted ? 'fail' : 'info'),
			'label' => $rewritten ? 'The server answers the image with its ' . ($expected === 'image/avif' ? 'AVIF' : 'WebP') . ' version' : 'The server answers the image with the JPG or PNG',
			'detail' => self::describe($rewrite),
			'advice' => $rewritten ? '' : ($wanted ? 'The rewrite rules are not applied: check that they are in the configuration of the server, and that mod_rewrite is on (Apache).' : 'Expected while the server rewrite is off.'),
		];
		if ($rewritten) {
			$vary = stripos($rewrite['vary'], 'accept') !== false;
			$checks[] = [
				'state' => $vary ? 'ok' : 'fail',
				'label' => 'Caches know the image depends on the browser (Vary: Accept)',
				'detail' => $vary ? 'Vary: ' . $rewrite['vary'] : 'No Vary: Accept header.',
				'advice' => $vary ? '' : 'Without it, a cache or a CDN can serve an AVIF to a browser that cannot read it. Apache: enable mod_headers (<code>a2enmod headers</code>).',
			];
		}

		$checks[] = self::check_originals($sample);

		wp_send_json_success([
			'sample' => $sample['name'],
			'checks' => $checks,
		]);
	}

	/**
	 * Checks that the original of the sample image
	 * cannot be downloaded: it keeps its metadata, e.g.
	 * the GPS position of a photo.
	 *
	 * @param array $sample
	 * @return array
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function check_originals($sample)
	{
		$label = 'The original images are not public';
		$uploads = wp_get_upload_dir();
		$backup = wp_normalize_path(Files::backup_path($sample['file']));
		$base = trailingslashit(wp_normalize_path($uploads['basedir']));
		if (!file_exists($backup)) {
			return ['state' => 'info', 'label' => $label, 'detail' => 'The sample image has no original kept.', 'advice' => ''];
		}
		if (strpos($backup, $base) !== 0) {
			return ['state' => 'ok', 'label' => $label, 'detail' => 'They are kept out of the uploads folder.', 'advice' => ''];
		}
		$response = self::head(trailingslashit($uploads['baseurl']) . substr($backup, strlen($base)));
		$public = $response['status'] >= 200 && $response['status'] < 300;
		return [
			'state' => $public ? 'fail' : 'ok',
			'label' => $label,
			'detail' => self::describe($response),
			'advice' => $public ? 'Anyone can download them, with their metadata (GPS position of the photos). nginx: add <code>location ^~ ' . esc_html(wp_parse_url($uploads['baseurl'], PHP_URL_PATH)) . '/' . esc_html(Files::BACKUP_DIR) . '/ { deny all; }</code> to the server block, or move them with the <code>octopus_backup_dir</code> filter.' : '',
		];
	}

	/**
	 * Returns an optimised image whose main file has a
	 * WebP or an AVIF version: its file, URLs and name.
	 *
	 * @return array|null
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	private static function sample()
	{
		$ids = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => ['image/jpeg', 'image/png'],
			'posts_per_page' => 50,
			'fields' => 'ids',
			'meta_query' => [
				'relation' => 'OR',
				['key' => WebP::meta_key(), 'compare' => 'EXISTS'],
				['key' => AVIF::meta_key(), 'compare' => 'EXISTS'],
			],
		]);
		foreach ($ids as $id) {
			$file = get_attached_file($id);
			$webp = $file && file_exists($file . WebP::extension());
			$avif = $file && file_exists($file . AVIF::extension());
			if (!$webp && !$avif) {
				continue;
			}
			$url = wp_get_attachment_url($id);
			return [
				'file' => $file,
				'name' => wp_basename($file),
				'image' => $url,
				'webp' => $webp ? $url . WebP::extension() : '',
				'avif' => $avif ? $url . AVIF::extension() : '',
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
	 * @date 25/10/2026
	 */
	private static function head($url, $accept = '')
	{
		$response = wp_remote_head($url, [
			'timeout' => 10,
			'redirection' => 3,
			'headers' => $accept ? ['Accept' => $accept] : [],
			// As WordPress for its own loopback requests.
			'sslverify' => apply_filters('https_local_ssl_verify', false),
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
	 * @date 25/10/2026
	 */
	private static function describe($response)
	{
		if ($response['error']) {
			return 'The site could not be reached: ' . $response['error'];
		}
		return 'HTTP ' . $response['status'] . ($response['type'] ? ', ' . $response['type'] : '');
	}
}
