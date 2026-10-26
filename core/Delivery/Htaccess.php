<?php

/**
 * Htaccess
 *
 * The delivery of the WebP and AVIF files by the
 * server: rewrite rules answering photo.jpg with
 * photo.jpg.avif or .webp when the browser accepts
 * them, without changing the HTML. Writes the Apache
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

use Octopus\Admin\Fields;
use Octopus\Media\Files;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Htaccess
{
	/**
	 * Option writing the Apache rules, without the
	 * prefix of Fields.
	 *
	 * @var string
	 */
	const OPTION = 'octopus_rewrite_rules';

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
	 * @date 25/10/2026
	 */
	public function __construct()
	{
		$option = Fields::OPTION_PREFIX . self::OPTION;
		add_action('add_option_' . $option, [self::class, 'sync'], 10, 0);
		add_action('update_option_' . $option, [self::class, 'sync'], 10, 0);
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
		return Files::uploads_dir() . DIRECTORY_SEPARATOR . '.htaccess';
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
			'	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [NC,T=image/avif,E=octopus_accept:1,L]',
			'	RewriteCond %{HTTP_ACCEPT} image/webp',
			'	RewriteCond %{REQUEST_FILENAME}.webp -f',
			'	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,E=octopus_accept:1,L]',
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
			. "map \$http_accept \$octopus_suffix {\n"
			. "\tdefault \"\";\n"
			. "\t\"~image/avif\" \".avif\";\n"
			. "\t\"~image/webp\" \".webp\";\n"
			. "}\n\n"
			. "# In the server block:\n"
			. "location ~* ^" . $uploads . "/.+\\.(jpe?g|png)$ {\n"
			. "\tadd_header Vary Accept;\n"
			. "\ttry_files \$uri\$octopus_suffix \$uri =404;\n"
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
			add_settings_error(Fields::PAGE, 'octopus_rewrite_rules', 'OIO could not write the rules in uploads/.htaccess: copy them by hand from the Delivery tab.');
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
}
