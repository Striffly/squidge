<?php

/**
 * Delivery
 *
 * Delivery serves the WebP and AVIF files from the
 * server: rewrite rules answering photo.jpg with
 * photo.jpg.avif or .webp when the browser accepts
 * them, without changing the HTML. It writes the
 * Apache rules in uploads/.htaccess when the option is
 * on, gives the nginx ones to copy, and tests what the
 * server actually sends.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace OIO\Admin;

use OIO\Package\Service;
use OIO\Services\AVIF;
use OIO\Services\WebP;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Delivery
{
	/**
	 * Option writing the Apache rules, without the
	 * prefix of Fields.
	 *
	 * @var string
	 */
	const OPTION = 'oio_rewrite_rules';

	/**
	 * Marker of the rules in uploads/.htaccess.
	 *
	 * @var string
	 */
	const MARKER = 'OIO';

	/**
	 * AJAX action of the test.
	 *
	 * @var string
	 */
	const TEST = 'oio_delivery_test';

	/**
	 * Writes or removes the rules when the option
	 * changes, and answers the test.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		$option = Fields::OPTION_PREFIX . self::OPTION;
		add_action('add_option_' . $option, [self::class, 'sync'], 10, 0);
		add_action('update_option_' . $option, [self::class, 'sync'], 10, 0);
		add_action('wp_ajax_' . self::TEST, [$this, 'test']);
	}

	/**
	 * Returns the web server: apache (Apache and
	 * LiteSpeed, which read .htaccess), nginx or other.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function server()
	{
		$software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
		if (strpos($software, 'apache') !== false || strpos($software, 'litespeed') !== false) {
			return 'apache';
		}
		return strpos($software, 'nginx') !== false ? 'nginx' : 'other';
	}

	/**
	 * Returns the path of the .htaccess of the uploads
	 * folder.
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function htaccess()
	{
		return wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . '.htaccess';
	}

	/**
	 * Returns the Apache rules: the AVIF, then the WebP
	 * version of a JPG or PNG, when it exists and the
	 * browser accepts it. Vary: Accept keeps the caches
	 * from serving an AVIF to a browser that cannot
	 * read it (needs mod_headers).
	 *
	 * @return string[]
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function apache_rules()
	{
		return [
			'<IfModule mod_mime.c>',
			'	AddType image/avif .avif',
			'	AddType image/webp .webp',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'	RewriteEngine On',
			'	RewriteCond %{HTTP_ACCEPT} image/avif',
			'	RewriteCond %{REQUEST_FILENAME}.avif -f',
			'	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [NC,T=image/avif,E=oio_accept:1,L]',
			'	RewriteCond %{HTTP_ACCEPT} image/webp',
			'	RewriteCond %{REQUEST_FILENAME}.webp -f',
			'	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,E=oio_accept:1,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'	<FilesMatch "\.(jpe?g|png|webp|avif)$">',
			'		Header append Vary Accept',
			'	</FilesMatch>',
			'</IfModule>',
		];
	}

	/**
	 * Returns the nginx rules, to add to the server
	 * block of the site: the map goes in the http
	 * block (e.g. at the top of the site's file).
	 *
	 * @return string
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function nginx_rules()
	{
		$uploads = wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH);
		return "# In the http block (e.g. at the top of the site's file):\n"
			. "map \$http_accept \$oio_suffix {\n"
			. "\tdefault \"\";\n"
			. "\t\"~image/avif\" \".avif\";\n"
			. "\t\"~image/webp\" \".webp\";\n"
			. "}\n\n"
			. "# In the server block:\n"
			. "location ~* ^" . $uploads . "/.+\\.(jpe?g|png)$ {\n"
			. "\tadd_header Vary Accept;\n"
			. "\ttry_files \$uri\$oio_suffix \$uri =404;\n"
			. "}";
	}

	/**
	 * Writes the Apache rules in uploads/.htaccess when
	 * the option is on, removes them when it is off.
	 * WordPress keeps the rest of the file. On nginx,
	 * the option only tells that the rules were added
	 * by hand.
	 *
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function sync()
	{
		// nginx does not read .htaccess: its rules are added by hand. WP-CLI does
		// not know the server, the rules are written (a .htaccess not read does nothing).
		$on = Fields::get(self::OPTION) && self::server() !== 'nginx';
		if (!$on) {
			self::remove_rules(self::htaccess());
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if (!insert_with_markers(self::htaccess(), self::MARKER, self::apache_rules())) {
			add_settings_error(Fields::PAGE, 'oio_rewrite_rules', 'OIO could not write the rules in uploads/.htaccess: copy them by hand from the Delivery tab.');
		}
	}

	/**
	 * Removes the rules of OIO from a .htaccess, with
	 * their markers: insert_with_markers() would leave
	 * them empty. The rest of the file stays, without
	 * the blank lines insert_with_markers() put before
	 * them, and the file goes if it only held the
	 * rules. uninstall.php does the same, without the
	 * plugin loaded.
	 *
	 * @param string $path
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function remove_rules($path)
	{
		if (!is_file($path) || !is_writable($path)) {
			return;
		}
		$content = (string)file_get_contents($path);
		$cleaned = preg_replace('/\n*# BEGIN ' . self::MARKER . '\n.*?# END ' . self::MARKER . '\n?/s', "\n", $content, -1, $count);
		if (!$count) {
			return;
		}
		if (trim($cleaned) === '') {
			unlink($path);
			return;
		}
		file_put_contents($path, ltrim($cleaned, "\n"));
	}

	/**
	 * Determines if the Apache rules are written.
	 *
	 * @return bool
	 * @since 1.0.0
	 * @date 25/10/2026
	 */
	public static function rules_written()
	{
		if (!file_exists(self::htaccess())) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		return !empty(extract_from_markers(self::htaccess(), self::MARKER));
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
		check_ajax_referer(Tools::NONCE);
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
		$wanted = Fields::get(self::OPTION) || self::server() === 'nginx';
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
		$backup = wp_normalize_path(Service::backup_path($sample['file']));
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
			'advice' => $public ? 'Anyone can download them, with their metadata (GPS position of the photos). nginx: add <code>location ^~ ' . esc_html(wp_parse_url($uploads['baseurl'], PHP_URL_PATH)) . '/' . esc_html(Service::BACKUP_DIR) . '/ { deny all; }</code> to the server block, or move them with the <code>oio_backup_dir</code> filter.' : '',
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
