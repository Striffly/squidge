<?php

/**
 * Plugin Name:     OIO – Octopus Image Optimizer
 * Plugin URI:      https://github.com/Striffly/octopus-image-optimizer
 * Description:     Compresses JPG and PNG images and converts them to WebP and AVIF on your own server, with cwebp, avifenc, jpegoptim and optipng. Free, unlimited, no API key.
 * Author:          Clément Gayot
 * Author URI:      https://github.com/Striffly
 * Text Domain:     octopus-image-optimizer
 * Version:         0.1.4
 * Requires at least: 6.0
 * Requires PHP:    8.2
 * License:         GPLv3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:      https://github.com/Striffly/octopus-image-optimizer
 *
 * @package         OIO
 * @repo            https://github.com/Striffly/octopus-image-optimizer
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// The version of OIO, set by npm run release with the header above.
define('OCTOPUS_VERSION', '0.1.4');
define('OCTOPUS_FILE', __FILE__);

require_once __DIR__ . '/autoload.php';

// The dependencies, prefixed by Strauss, are made by composer install.
if (!file_exists(__DIR__ . '/vendor-prefixed/autoload.php')) {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>OIO – Octopus Image Optimizer</strong>: its dependencies are missing, run <code>composer install</code> in the plugin folder.</p></div>';
	});
}

// Boots OIO once the plugins are loaded, so that other plugins can hook in first.
add_action('plugins_loaded', ['Octopus\\Plugin', 'boot']);

register_activation_hook(__FILE__, ['Octopus\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['Octopus\\Lifecycle', 'deactivate']);

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('octopus', Octopus\Cli\Commands::class);
}

// octopus_image(), for the themes.
require __DIR__ . '/functions/functions.php';
