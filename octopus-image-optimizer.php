<?php

/**
 * Plugin Name:     OIO – Octopus Image Optimizer
 * Plugin URI:      https://github.com/Striffly/squidge
 * Description:     Compresses JPG and PNG images and converts them to WebP and AVIF on your own server, with cwebp, avifenc, jpegoptim and optipng. Free, unlimited, no API key.
 * Author:          Clément Gayot
 * Author URI:      https://github.com/Striffly
 * Text Domain:     octopus-image-optimizer
 * Version:         0.1.4
 * Requires at least: 6.0
 * Requires PHP:    7.4
 * License:         GPLv3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:      https://github.com/Striffly/squidge
 *
 * @package         OIO
 * @repo            https://github.com/Striffly/squidge
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Autoload OIO classes (OIO\Foo\Bar => core/Foo/Bar.php).
spl_autoload_register(function ($class) {
	$prefix = 'OIO\\';
	if (strpos($class, $prefix) !== 0) {
		return;
	}
	$file = __DIR__ . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (file_exists($file)) {
		require $file;
	}
});

// Autoload the dependencies, prefixed by Strauss (composer install).
if (file_exists(__DIR__ . '/vendor-prefixed/autoload.php')) {
	require __DIR__ . '/vendor-prefixed/autoload.php';
} else {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>OIO</strong>: its dependencies are missing, run <code>composer install</code> in the plugin folder.</p></div>';
	});
}

// Boot OIO (Once).
OIO\OIO::boot();

// Require CLI.
if (file_exists(__DIR__ . '/cli/Commands.php')) {
	require(__DIR__ . '/cli/Commands.php');
}

// Require Functions.
if (file_exists(__DIR__ . '/functions/functions.php')) {
	require(__DIR__ . '/functions/functions.php');
}

if (!function_exists('oio_add_settings_link')) {

	/**
	 * Add Settings Link.
	 *
	 * @param $links
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	function oio_add_settings_link($links)
	{
		$links[] = '<a href="' .
			OIO\Admin\Fields::page_url() .
			'">' . __('Settings') . '</a>';
		return $links;
	}
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'oio_add_settings_link');
}
