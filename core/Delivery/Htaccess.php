<?php

/**
 * Htaccess
 *
 * The delivery of the WebP and AVIF files by the
 * server: rewrite rules answering photo.jpg with
 * octopus/formats/photo.jpg.avif or .webp when the
 * browser accepts them, without changing the HTML. Writes the Apache
 * rules in uploads/.htaccess when the option is on,
 * and gives the nginx ones to copy.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Admin
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Delivery;

use Octopus\Media\Files;
use Octopus\Settings;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Htaccess
{
	/**
	 * Marker of the rules in uploads/.htaccess.
	 *
	 * @var string
	 */
	const MARKER = 'Octopus Image Optimizer';

	/**
	 * Writes or removes the rules when the option
	 * changes.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('add_option_' . Settings::OPTION, [self::class, 'sync'], 10, 0);
		add_action('update_option_' . Settings::OPTION, [self::class, 'sync'], 10, 0);
	}

	/**
	 * Returns the web server: apache (Apache and
	 * LiteSpeed, which read .htaccess), nginx or other.
	 *
	 * @return string
	 * @since 1.0.0
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
	 */
	public static function htaccess()
	{
		return Files::uploads_dir() . DIRECTORY_SEPARATOR . '.htaccess';
	}

	/**
	 * Returns the Apache rules: the AVIF, then the WebP
	 * version of a JPG or PNG, when it exists and the
	 * browser accepts it. The folder of the versions is
	 * found from the file asked: its path relative to
	 * the uploads folder ($1) ends its full path, the
	 * rest is the uploads folder (%1). No path of the
	 * server is written, the rules work wherever the
	 * site moves. Vary: Accept keeps the caches from
	 * serving an AVIF to a browser that cannot read it
	 * (needs mod_headers).
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function apache_rules()
	{
		$formats = Files::DIR . '/' . Files::FORMATS_DIR;
		return [
			'<IfModule mod_mime.c>',
			'	AddType image/avif .avif',
			'	AddType image/webp .webp',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'	RewriteEngine On',
			'	RewriteCond %{HTTP_ACCEPT} image/avif',
			'	RewriteCond %{REQUEST_FILENAME}::$1 ^(.*?/)(.+)::\2$',
			'	RewriteCond %1' . $formats . '/$1.avif -f',
			'	RewriteRule ^(.+\.(?:jpe?g|png))$ ' . $formats . '/$1.avif [NC,T=image/avif,L]',
			'	RewriteCond %{HTTP_ACCEPT} image/webp',
			'	RewriteCond %{REQUEST_FILENAME}::$1 ^(.*?/)(.+)::\2$',
			'	RewriteCond %1' . $formats . '/$1.webp -f',
			'	RewriteRule ^(.+\.(?:jpe?g|png))$ ' . $formats . '/$1.webp [NC,T=image/webp,L]',
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
	 * block of the site: the maps go in the http
	 * block (e.g. at the top of the site's file). The
	 * AVIF, then the WebP version, if the browser
	 * accepts it: an empty suffix tries the JPG or PNG
	 * in the folder of the versions, never there. The
	 * path is a named capture: the regexes of the maps
	 * would replace $1. The originals are denied first.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function nginx_rules()
	{
		$uploads = wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH);
		$formats = $uploads . '/' . Files::DIR . '/' . Files::FORMATS_DIR;
		return "# In the http block (e.g. at the top of the site's file):\n"
			. "map \$http_accept \$octopus_avif {\n"
			. "\tdefault \"\";\n"
			. "\t\"~image/avif\" \".avif\";\n"
			. "}\n"
			. "map \$http_accept \$octopus_webp {\n"
			. "\tdefault \"\";\n"
			. "\t\"~image/webp\" \".webp\";\n"
			. "}\n\n"
			. "# In the server block:\n"
			. self::nginx_deny() . "\n"
			. "location ~* ^" . $uploads . "/(?<octopus_file>.+\\.(?:jpe?g|png))$ {\n"
			. "\tadd_header Vary Accept;\n"
			. "\ttry_files " . $formats . "/\$octopus_file\$octopus_avif " . $formats . "/\$octopus_file\$octopus_webp \$uri =404;\n"
			. "}";
	}

	/**
	 * Returns the nginx rule denying the originals,
	 * whatever the key of their folder: ^~ goes before
	 * the rule of the images.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function nginx_deny()
	{
		$uploads = wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH);
		return 'location ^~ ' . $uploads . '/' . Files::DIR . '/' . Files::BACKUP_DIR . ' { deny all; }';
	}

	/**
	 * Writes the Apache rules in uploads/.htaccess when
	 * the option is on, removes them when it is off.
	 * WordPress keeps the rest of the file. On nginx,
	 * the option only tells that the rules were added
	 * by hand.
	 *
	 * @since 1.0.0
	 */
	public static function sync()
	{
		// nginx does not read .htaccess: its rules are added by hand. WP-CLI does
		// not know the server, the rules are written (a .htaccess not read does nothing).
		$on = Settings::get('serve_rewrite') && self::server() !== 'nginx';
		if (!$on) {
			self::remove_rules(self::htaccess());
			if (self::rules_written()) {
				add_settings_error(Settings::OPTION, 'octopus_rewrite_rules', __('OIO could not remove its rules from uploads/.htaccess: remove them by hand, between the lines with Octopus Image Optimizer.', 'octopus-image-optimizer'));
			}
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$written = self::rules_written();
		if (!insert_with_markers(self::htaccess(), self::MARKER, self::apache_rules())) {
			add_settings_error(Settings::OPTION, 'octopus_rewrite_rules', __('OIO could not write the rules in uploads/.htaccess: copy them by hand from the Delivery tab.', 'octopus-image-optimizer'));
			return;
		}
		// Just written: a server refusing them answers every file of the uploads
		// folder with an error, images included. They go, and the option with them,
		// or the options page would write them again.
		if (!$written && self::server() === 'apache' && self::breaks_uploads()) {
			Settings::set(['serve_rewrite' => false]);
			add_settings_error(Settings::OPTION, 'octopus_rewrite_rules', __('The server refuses the rules of uploads/.htaccess (error 500 on every image): OIO removed them. Ask your host to allow them (AllowOverride FileInfo), or serve the WebP and AVIF files in the HTML.', 'octopus-image-optimizer'));
		}
	}

	/**
	 * Determines if the uploads folder answers with a
	 * server error, as Apache does for every file of a
	 * folder whose .htaccess it refuses: a request to
	 * the site itself, for a file that does not exist.
	 * Not reached (e.g. a password on the site) counts
	 * as working.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function breaks_uploads()
	{
		$response = wp_remote_head(Files::url(Files::dir() . '/check-' . strtolower(wp_generate_password(8, false)) . '.txt'), [
			'timeout' => 5,
			'redirection' => 0,
			// As WordPress for its own loopback requests.
			'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A filter of WordPress.
		]);
		return !is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 500;
	}

	/**
	 * Removes the rules of OIO from a .htaccess, with
	 * their markers: insert_with_markers() would leave
	 * them empty. The rest of the file stays, without
	 * the blank lines insert_with_markers() put before
	 * them, and the file goes if it only held the
	 * rules. Locked while it is read and written, as
	 * insert_with_markers() does: two saves in a row
	 * would otherwise write over each other.
	 *
	 * @param string $path
	 * @since 1.0.0
	 */
	public static function remove_rules($path)
	{
		if (!is_file($path) || !wp_is_writable($path)) {
			return;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- A lock, which WP_Filesystem does not offer, as insert_with_markers().
		$handle = fopen($path, 'r+');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			return;
		}
		$content = (string)stream_get_contents($handle);
		$cleaned = preg_replace('/\n*# BEGIN ' . self::MARKER . '\n.*?# END ' . self::MARKER . '\n?/s', "\n", $content, -1, $count);
		if ($count) {
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, trim($cleaned) === '' ? '' : ltrim($cleaned, "\n"));
			fflush($handle);
			// Still locked: nothing is written between the check and the deletion.
			// Windows keeps an open file: it stays, empty.
			if (trim($cleaned) === '') {
				wp_delete_file($path);
			}
		}
		flock($handle, LOCK_UN);
		fclose($handle);
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	/**
	 * Puts back what went from the uploads folder
	 * without OIO knowing (another plugin writing the
	 * .htaccess, a deployment, a move of the site): the
	 * rules while the option is on, and the .htaccess
	 * and index.php of the folders of the originals and
	 * of the temporary files. On
	 * the options page, before it shows the errors of
	 * the settings, as when saving.
	 *
	 * @since 1.0.0
	 */
	public static function repair()
	{
		if (Settings::get('serve_rewrite') && self::server() !== 'nginx' && !self::rules_written()) {
			self::sync();
		}
		if (is_dir(Files::backup_dir())) {
			Files::protect_backup_dir();
		}
		if (is_dir(Files::temp_dir())) {
			Files::protect_temp_dir();
		}
	}

	/**
	 * Determines if the Apache rules are written.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function rules_written()
	{
		if (!file_exists(self::htaccess())) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		return !empty(extract_from_markers(self::htaccess(), self::MARKER));
	}
}
