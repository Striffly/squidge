<?php

/**
 * Plugin Name:     Squidge
 * Plugin URI:      https://github.com/ainsleyclark/squidge
 * Description:     A image optimisation plugin to compress and convert images using cwebp, jpegoptim, optipng and libavif.
 * Author:          Ainsley Clark
 * Author URI:      https://github.com/ainsleyclark
 * Text Domain:     squidge
 * Version:         0.1.4
 * Requires at least: 6.0
 * Requires PHP:    7.4
 * License:         GPLv3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package         Squidge
 * @repo            https://github.com/ainsleyclark/squidge
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Autoload Squidge classes (Squidge\Foo\Bar => core/Foo/Bar.php).
spl_autoload_register(function ($class) {
	$prefix = 'Squidge\\';
	if (strpos($class, $prefix) !== 0) {
		return;
	}
	$file = __DIR__ . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (file_exists($file)) {
		require $file;
	}
});

// Boot Squidge (Once).
Squidge\Squidge::boot();

// Require CLI.
if (file_exists(__DIR__ . '/cli/Commands.php')) {
	require(__DIR__ . '/cli/Commands.php');
}

// Require Functions.
if (file_exists(__DIR__ . '/functions/functions.php')) {
	require(__DIR__ . '/functions/functions.php');
}

if (!function_exists('squidge_add_settings_link')) {

	/**
	 * Add Settings Link.
	 *
	 * @param $links
	 * @return mixed
	 * @since 0.1.0
	 * @date 24/11/2021
	 */
	function squidge_add_settings_link($links)
	{
		$links[] = '<a href="' .
			Squidge\Admin\Fields::page_url() .
			'">' . __('Settings') . '</a>';
		return $links;
	}
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'squidge_add_settings_link');
}
